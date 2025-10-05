<?php
namespace TCN\Platform\Auth;

use WP_Error;
use WP_REST_Request;

class TokenAuthenticator {
    /**
     * Attempt to authenticate a REST request using the password login bearer token.
     *
     * @return int|WP_Error Returns the authenticated user ID on success, 0 when no token is present, or WP_Error for invalid tokens.
     */
    public function authenticate_request( WP_REST_Request $request ) {
        $raw_header = $request->get_header( 'authorization' );
        $header     = $this->resolve_authorization_header( $raw_header );

        $this->log_debug(
            'authenticate_request invoked',
            array(
                'has_header'             => is_string( $raw_header ) && '' !== trim( $raw_header ),
                'server_header_present'  => isset( $_SERVER['HTTP_AUTHORIZATION'] ),
                'redirect_header_present' => isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
                'alt_header_present'      => isset( $_SERVER['AUTHORIZATION'] ),
            )
        );

        if ( '' === $header ) {
            $this->log_debug( 'authenticate_request missing authorization header' );

            return 0;
        }

        return $this->authenticate_with_header( $header );
    }

    /**
     * Hook into WordPress authentication and honour bearer tokens for REST requests.
     *
     * @param int|false $user_id Current user ID resolved by WordPress.
     * @return int|false
     */
    public function determine_current_user( $user_id ) {
        if ( $user_id ) {
            return $user_id;
        }

        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return $user_id;
        }

        $header = $this->resolve_authorization_header( '' );
        if ( '' === $header ) {
            return $user_id;
        }

        $token = $this->extract_token_from_header( $header );
        if ( is_wp_error( $token ) ) {
            return $user_id;
        }

        $result = $this->authenticate_with_token( $token );

        if ( is_wp_error( $result ) || $result <= 0 ) {
            return $user_id;
        }

        $this->log_debug(
            'determine_current_user authenticated via bearer token',
            array(
                'user_id'    => $result,
                'token_hash' => md5( $token ),
            )
        );

        return $result;
    }

    /**
     * Register WordPress hooks for automatic bearer token authentication.
     */
    public function register_hooks(): void {
        add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 20 );
    }

    /**
     * Retrieve the payload for a login hand-off token.
     *
     * @return array<string, mixed>|false
     */
    protected function get_login_token_payload( string $token_hash ) {
        $payload = get_transient( PasswordLoginService::TOKEN_PREFIX . $token_hash );

        if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
            $this->log_debug(
                'authenticate_request login token payload missing or expired',
                array( 'token_hash' => $token_hash )
            );

            return false;
        }

        return $payload;
    }

    /**
     * Resolve an authorization header from the REST request or PHP globals.
     */
    protected function resolve_authorization_header( $header ): string {
        if ( is_string( $header ) && '' !== trim( $header ) ) {
            return trim( (string) $header );
        }

        foreach ( $this->get_server_header_keys() as $server_key ) {
            if ( isset( $_SERVER[ $server_key ] ) && '' !== trim( (string) $_SERVER[ $server_key ] ) ) {
                return trim( (string) wp_unslash( $_SERVER[ $server_key ] ) );
            }
        }

        return '';
    }

    /**
     * Attempt to authenticate a request using a raw Authorization header value.
     *
     * @return int|WP_Error
     */
    protected function authenticate_with_header( string $header ) {
        $token = $this->extract_token_from_header( $header );

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return $this->authenticate_with_token( $token );
    }

    /**
     * Extract the bearer token from a header string.
     *
     * @param string $header Authorization header value.
     * @return string|WP_Error
     */
    protected function extract_token_from_header( string $header ) {
        if ( ! preg_match( '/Bearer\s+(.*)$/i', $header, $matches ) ) {
            if ( ! $this->should_log_non_bearer_header( $header ) ) {
                return new WP_Error(
                    'tcn_rest_invalid_token',
                    __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $this->log_debug(
                'authenticate_request header missing bearer token prefix',
                array( 'raw_header_preview' => $this->mask_string( $header ) )
            );

            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $token = trim( (string) $matches[1] );
        if ( '' === $token ) {
            $this->log_debug( 'authenticate_request extracted empty token' );

            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return $token;
    }

    /**
     * Determine whether a non-bearer Authorization header should be logged.
     */
    protected function should_log_non_bearer_header( string $header ): bool {
        if ( '' === trim( $header ) ) {
            return false;
        }

        if ( ! preg_match( '/^([A-Za-z]+)\s+/', $header, $matches ) ) {
            return true;
        }

        $scheme = strtolower( (string) $matches[1] );

        return ! in_array( $scheme, $this->get_suppressed_authorization_schemes(), true );
    }

    /**
     * Return a list of Authorization schemes that should not trigger debug logs when missing a bearer token.
     *
     * @return array<int, string>
     */
    protected function get_suppressed_authorization_schemes(): array {
        return array( 'basic', 'digest' );
    }

    /**
     * Authenticate the resolved bearer token.
     *
     * @param string $token
     * @return int|WP_Error
     */
    protected function authenticate_with_token( string $token ) {
        if ( ! class_exists( PasswordLoginService::class ) ) {
            $this->log_debug( 'authenticate_request PasswordLoginService class missing' );

            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $token_hash = md5( $token );
        $token_type = 'login';

        $payload = $this->get_login_token_payload( $token_hash );
        if ( false === $payload ) {
            $token_type = 'api';
            $payload    = $this->get_api_token_payload( $token_hash );

            if ( false === $payload ) {
                return new WP_Error(
                    'tcn_rest_token_expired',
                    __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }
        }

        $user_id = (int) $payload['user_id'];
        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            $this->log_debug(
                'authenticate_request token payload user invalid',
                array(
                    'token_hash' => $token_hash,
                    'user_id'    => $user_id,
                    'token_type' => $token_type,
                )
            );

            return new WP_Error(
                'tcn_rest_token_expired',
                __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        wp_set_current_user( $user_id );

        $this->log_debug(
            'authenticate_request succeeded',
            array(
                'user_id'    => $user_id,
                'token_hash' => $token_hash,
                'token_type' => $token_type,
            )
        );

        return $user_id;
    }

    /**
     * Return the list of server header keys to inspect for authorization values.
     *
     * @return array<int, string>
     */
    protected function get_server_header_keys(): array {
        return array(
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'AUTHORIZATION',
        );
    }

    /**
     * Retrieve the payload for a long-lived API bearer token.
     *
     * @return array<string, mixed>|false
     */
    protected function get_api_token_payload( string $token_hash ) {
        $payload = get_transient( PasswordLoginService::API_TOKEN_PREFIX . $token_hash );

        if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
            $this->log_debug(
                'authenticate_request api token payload missing',
                array( 'token_hash' => $token_hash )
            );

            return false;
        }

        $expires = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
        if ( $expires && time() > $expires ) {
            $this->log_debug(
                'authenticate_request api token payload expired',
                array(
                    'token_hash' => $token_hash,
                    'expired_at' => $expires,
                )
            );

            delete_transient( PasswordLoginService::API_TOKEN_PREFIX . $token_hash );

            return false;
        }

        return $payload;
    }

    /**
     * Write a debug message to the error log when WP_DEBUG is enabled.
     */
    protected function log_debug( string $message, array $context = array() ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        if ( ! empty( $context ) ) {
            $context = $this->sanitize_context( $context );
            $encoded = wp_json_encode( $context );
            if ( false !== $encoded ) {
                $message .= ' ' . $encoded;
            }
        }

        error_log( '[TCN TokenAuthenticator] ' . $message );
    }

    /**
     * Sanitize context values before logging.
     */
    protected function sanitize_context( array $context ): array {
        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $context[ $key ] = $this->sanitize_context( $value );
                continue;
            }

            if ( is_object( $value ) ) {
                $context[ $key ] = get_class( $value );
                continue;
            }

            if ( is_string( $value ) ) {
                if ( false !== stripos( $key, 'token' ) || false !== stripos( $key, 'authorization' ) ) {
                    $context[ $key ] = $this->mask_string( $value );
                    continue;
                }

                if ( strlen( $value ) > 180 ) {
                    $context[ $key ] = substr( $value, 0, 177 ) . '...';
                }
            }
        }

        return $context;
    }

    /**
     * Mask sensitive values prior to logging.
     */
    protected function mask_string( string $value ): string {
        if ( strlen( $value ) <= 8 ) {
            return str_repeat( '*', strlen( $value ) );
        }

        return substr( $value, 0, 4 ) . '...' . substr( $value, -4 );
    }
}
