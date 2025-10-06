<?php
namespace TCN\Platform\Auth;

use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * Provide backwards-compatible JWT authentication endpoints.
 *
 * Mirrors the functionality of the upstream "JWT Authentication for WP REST API" plugin
 * so third-party clients can rely on the documented endpoints while delegating to the
 * TCN Platform authentication layer.
 */
class JwtAuthEndpoints {
    /**
     * REST namespace.
     */
    const REST_NAMESPACE = 'jwt-auth/v1';

    /**
     * Register REST routes.
     */
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Declare jwt-auth routes for issuing, refreshing, and validating tokens.
     */
    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/token',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_token_request' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/token/refresh',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_refresh_request' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/token/validate',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_validate_request' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle POST /jwt-auth/v1/token
     */
    public function handle_token_request( WP_REST_Request $request ) {
        do_action( 'jwt_auth_before_auth', $request );

        $username = (string) $request->get_param( 'username' );
        $password = (string) $request->get_param( 'password' );

        if ( '' === $username || '' === $password ) {
            return new WP_Error(
                'jwt_auth_missing_credentials',
                __( 'Username and password are required.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            do_action( 'jwt_auth_failed_auth', $user, $request );

            return new WP_Error(
                'jwt_auth_invalid_credentials',
                __( 'Invalid username or password.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        do_action( 'jwt_auth_after_auth', $user, $request );

        return $this->prepare_token_response( $user );
    }

    /**
     * Handle POST /jwt-auth/v1/token/refresh
     */
    public function handle_refresh_request( WP_REST_Request $request ) {
        $token = $this->extract_token_from_request( $request );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = $this->decode_token( $token, true );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $user = $this->get_user_from_payload( $payload );
        if ( ! $user ) {
            return new WP_Error(
                'jwt_auth_invalid_user',
                __( 'The token user no longer exists.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        return $this->prepare_token_response( $user );
    }

    /**
     * Handle POST /jwt-auth/v1/token/validate
     */
    public function handle_validate_request( WP_REST_Request $request ) {
        $token = $this->extract_token_from_request( $request );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = $this->decode_token( $token );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $response = array(
            'code'    => 'jwt_auth_valid_token',
            'message' => __( 'Token is valid.', 'tcnapp-connector' ),
            'data'    => array( 'status' => 200 ),
        );

        return apply_filters( 'jwt_auth_validate_token_response', $response, $payload, $request );
    }

    /**
     * Build a standard JWT response array.
     */
    protected function prepare_token_response( WP_User $user ) {
        $token = $this->generate_token( $user );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = $token['payload'];
        $jwt     = $token['token'];

        $response = array(
            'token'             => $jwt,
            'user_email'        => $user->user_email,
            'user_nicename'     => $user->user_nicename,
            'user_display_name' => $user->display_name,
        );

        if ( isset( $payload['exp'] ) ) {
            $response['expires_in'] = max( 0, (int) $payload['exp'] - time() );
        }

        $response = apply_filters( 'jwt_auth_token_before_dispatch', $response, $user, $payload );

        return apply_filters( 'jwt_auth_token_response', $response, $user, $payload );
    }

    /**
     * Generate a signed JWT for a user.
     */
    protected function generate_token( WP_User $user ) {
        $issued_at  = time();
        $not_before = apply_filters( 'jwt_auth_not_before', $issued_at, $user );
        $expire     = apply_filters( 'jwt_auth_expire', $issued_at + DAY_IN_SECONDS, $issued_at, $user );

        $payload = array(
            'iss'  => apply_filters( 'jwt_auth_iss', get_bloginfo( 'url' ), $user ),
            'iat'  => $issued_at,
            'nbf'  => $not_before,
            'exp'  => $expire,
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                ),
            ),
        );

        $payload = apply_filters( 'jwt_auth_token_payload', $payload, $user );

        $token = $this->encode_jwt( $payload );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return array(
            'token'   => $token,
            'payload' => $payload,
        );
    }

    /**
     * Encode payload as JWT.
     */
    protected function encode_jwt( array $payload ) {
        $secret = $this->get_secret_key();
        if ( empty( $secret ) ) {
            return new WP_Error(
                'jwt_auth_bad_config',
                __( 'JWT secret key is not configured.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $algo = strtoupper( (string) apply_filters( 'jwt_auth_alg', 'HS256', $payload ) );
        if ( 'HS256' !== $algo ) {
            return new WP_Error(
                'jwt_auth_unsupported_alg',
                __( 'Only the HS256 signing algorithm is supported.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $header = array(
            'alg' => $algo,
            'typ' => 'JWT',
        );

        $segments   = array();
        $segments[] = $this->base64url_encode( wp_json_encode( $header ) );
        $segments[] = $this->base64url_encode( wp_json_encode( $payload ) );

        $signature = hash_hmac( 'sha256', implode( '.', $segments ), $secret, true );
        $segments[] = $this->base64url_encode( $signature );

        return implode( '.', $segments );
    }

    /**
     * Decode and validate a JWT.
     */
    protected function decode_token( string $token, bool $allow_expired = false ) {
        $parts = explode( '.', $token );
        if ( 3 !== count( $parts ) ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token structure is invalid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        list( $header64, $payload64, $signature64 ) = $parts;

        $header_json = $this->base64url_decode( $header64 );
        if ( false === $header_json ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token header could not be decoded.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $header = json_decode( $header_json, true );
        if ( ! is_array( $header ) ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token header is invalid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $algo = strtoupper( (string) ( $header['alg'] ?? 'HS256' ) );
        if ( 'HS256' !== $algo ) {
            return new WP_Error(
                'jwt_auth_unsupported_alg',
                __( 'Only the HS256 signing algorithm is supported.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $secret = $this->get_secret_key();
        if ( empty( $secret ) ) {
            return new WP_Error(
                'jwt_auth_bad_config',
                __( 'JWT secret key is not configured.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $signature = $this->base64url_decode( $signature64 );
        if ( false === $signature ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token signature could not be decoded.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $expected = hash_hmac( 'sha256', $header64 . '.' . $payload64, $secret, true );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token signature is invalid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $payload_json = $this->base64url_decode( $payload64 );
        if ( false === $payload_json ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token payload could not be decoded.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $payload = json_decode( $payload_json, true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token payload is invalid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        $now    = time();
        $leeway = apply_filters( 'jwt_auth_leeway', 0, $payload );

        if ( isset( $payload['nbf'] ) && ( $payload['nbf'] - $leeway ) > $now ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token is not yet valid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        if ( ! $allow_expired && isset( $payload['exp'] ) && ( $now - $leeway ) >= $payload['exp'] ) {
            return new WP_Error(
                'jwt_auth_expired_token',
                __( 'The token has expired.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        return apply_filters( 'jwt_auth_decoded_payload', $payload, $token );
    }

    /**
     * Resolve WP_User from JWT payload.
     */
    protected function get_user_from_payload( array $payload ) {
        $user_id = $payload['data']['user']['id'] ?? 0;
        if ( ! $user_id ) {
            return null;
        }

        $user = get_user_by( 'id', (int) $user_id );
        if ( ! $user instanceof WP_User ) {
            return null;
        }

        return $user;
    }

    /**
     * Extract bearer token from Authorization header or request parameter.
     */
    protected function extract_token_from_request( WP_REST_Request $request ) {
        $auth = $request->get_header( 'authorization' );
        if ( $auth && 0 === stripos( $auth, 'bearer ' ) ) {
            $token = trim( substr( $auth, 7 ) );
            if ( '' !== $token ) {
                return $token;
            }
        }

        $token = (string) $request->get_param( 'token' );
        if ( '' !== $token ) {
            return $token;
        }

        return new WP_Error(
            'jwt_auth_missing_token',
            __( 'Authorization header or token parameter is required.', 'tcnapp-connector' ),
            array( 'status' => 401 )
        );
    }

    /**
     * Retrieve the signing secret key.
     */
    protected function get_secret_key(): string {
        $secret = defined( 'JWT_AUTH_SECRET_KEY' ) ? constant( 'JWT_AUTH_SECRET_KEY' ) : '';
        $secret = apply_filters( 'jwt_auth_secret_key', $secret );

        if ( empty( $secret ) ) {
            $secret = wp_salt( 'auth' );
        }

        return (string) $secret;
    }

    /**
     * Base64 URL-safe encode helper.
     */
    protected function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' );
    }

    /**
     * Base64 URL-safe decode helper.
     */
    protected function base64url_decode( string $data ) {
        $remainder = strlen( $data ) % 4;
        if ( $remainder ) {
            $data .= str_repeat( '=', 4 - $remainder );
        }

        $decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
        if ( false === $decoded ) {
            return false;
        }

        return $decoded;
    }
}
