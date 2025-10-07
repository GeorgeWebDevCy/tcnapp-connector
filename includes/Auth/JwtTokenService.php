<?php
namespace TCN\Platform\Auth;

use WP_Error;
use WP_User;

/**
 * Shared helpers for issuing and validating JWT tokens.
 */
class JwtTokenService {
    /**
     * Generate a signed JWT for a user.
     *
     * @param WP_User                $user      User to include in the token payload.
     * @param array<string, mixed>   $overrides Optional overrides such as `issued_at`, `not_before`, `expire`, or `payload`.
     *
     * @return array{token: string, payload: array<string, mixed>}|WP_Error
     */
    public static function generate_token( WP_User $user, array $overrides = array() ) {
        $issued_at = isset( $overrides['issued_at'] ) ? (int) $overrides['issued_at'] : time();

        if ( array_key_exists( 'not_before', $overrides ) ) {
            $not_before = (int) $overrides['not_before'];
        } else {
            $not_before = apply_filters( 'jwt_auth_not_before', $issued_at, $user );
        }

        $default_expire = $issued_at + DAY_IN_SECONDS;
        $expire         = array_key_exists( 'expire', $overrides ) ? (int) $overrides['expire'] : $default_expire;
        $expire         = apply_filters( 'jwt_auth_expire', $expire, $issued_at, $user );

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

        if ( isset( $overrides['payload'] ) && is_array( $overrides['payload'] ) ) {
            $payload = array_replace_recursive( $payload, $overrides['payload'] );
        }

        $payload = apply_filters( 'jwt_auth_token_payload', $payload, $user );

        $token = self::encode_jwt( $payload );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return array(
            'token'   => $token,
            'payload' => $payload,
        );
    }

    /**
     * Decode and validate a JWT token.
     *
     * @param string $token         Encoded JWT string.
     * @param bool   $allow_expired Allow expired tokens.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function decode_token( string $token, bool $allow_expired = false ) {
        $parts = explode( '.', $token );
        if ( 3 !== count( $parts ) ) {
            return new WP_Error(
                'jwt_auth_invalid_token',
                __( 'The token structure is invalid.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        list( $header64, $payload64, $signature64 ) = $parts;

        $header_json = self::base64url_decode( $header64 );
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

        $secret = self::get_secret_key();
        if ( empty( $secret ) ) {
            return new WP_Error(
                'jwt_auth_bad_config',
                __( 'JWT secret key is not configured.', 'tcnapp-connector' ),
                array( 'status' => 500 )
            );
        }

        $signature = self::base64url_decode( $signature64 );
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

        $payload_json = self::base64url_decode( $payload64 );
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
     * Encode payload as a JWT string.
     *
     * @param array<string, mixed> $payload Token payload.
     *
     * @return string|WP_Error
     */
    public static function encode_jwt( array $payload ) {
        $secret = self::get_secret_key();
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
        $segments[] = self::base64url_encode( wp_json_encode( $header ) );
        $segments[] = self::base64url_encode( wp_json_encode( $payload ) );

        $signature = hash_hmac( 'sha256', implode( '.', $segments ), $secret, true );
        $segments[] = self::base64url_encode( $signature );

        return implode( '.', $segments );
    }

    /**
     * Retrieve the signing secret key.
     */
    public static function get_secret_key(): string {
        $secret = defined( 'JWT_AUTH_SECRET_KEY' ) ? constant( 'JWT_AUTH_SECRET_KEY' ) : '';
        $secret = apply_filters( 'jwt_auth_secret_key', $secret );

        if ( empty( $secret ) ) {
            $secret = wp_salt( 'auth' );
        }

        return (string) $secret;
    }

    /**
     * Base64 URL-safe encode helper.
     *
     * @param string $data Raw data to encode.
     */
    protected static function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' );
    }

    /**
     * Base64 URL-safe decode helper.
     *
     * @param string $data Encoded data.
     *
     * @return string|false
     */
    protected static function base64url_decode( string $data ) {
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
