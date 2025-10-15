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
        $email    = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );

        if ( '' === $password || ( '' === $username && '' === $email ) ) {
            return new WP_Error(
                'jwt_auth_missing_credentials',
                __( 'A username or email address and password are required.', 'tcnapp-connector' ),
                array( 'status' => 400 )
            );
        }

        $login_identifier = '' !== $username ? $username : $email;

        $user = wp_authenticate( $login_identifier, $password );
        if ( is_wp_error( $user ) ) {
            do_action( 'jwt_auth_failed_auth', $user, $request );

            return new WP_Error(
                'jwt_auth_invalid_credentials',
                __( 'Invalid username, email, or password.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        if ( ! $this->credentials_match_user( $user, $username, $email ) ) {
            $error = new WP_Error(
                'jwt_auth_invalid_credentials',
                __( 'Invalid username, email, or password.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );

            do_action( 'jwt_auth_failed_auth', $error, $request );

            return $error;
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

        $payload = JwtTokenService::decode_token( $token, true );
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

        $payload = JwtTokenService::decode_token( $token );
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
        $token = JwtTokenService::generate_token( $user );
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

    protected function credentials_match_user( WP_User $user, string $expected_username, string $expected_email ): bool {
        $expected_username = trim( $expected_username );
        $expected_email    = trim( $expected_email );

        $login_matches = '' === $expected_username ? true : 0 === strcasecmp( $user->user_login, $expected_username );
        $email_matches = '' === $expected_email ? true : 0 === strcasecmp( $user->user_email, $expected_email );

        return $login_matches && $email_matches;
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
}
