<?php
namespace TCN\Platform\Auth;

use WP_Error;
use WP_REST_Request;

/**
 * Provides bearer token authentication for REST endpoints and login hand-offs.
 *
 * This class centralises the logic for extracting tokens from multiple sources (headers,
 * parameters, transients) and applies consistent logging to aid in debugging hosting-specific
 * quirks such as stripped Authorization headers.
 */
class TokenAuthenticator {
    /**
     * Attempt to authenticate a REST request using the password login bearer token.
     *
     * @return int|WP_Error Returns the authenticated user ID on success, 0 when no token is
     *                      present, or WP_Error for invalid tokens.
     */
    public function authenticate_request( WP_REST_Request $request ) {
        $raw_header = $request->get_header( 'authorization' );
        if ( ! is_string( $raw_header ) || '' === trim( $raw_header ) ) {
            // Some hosts strip Authorization for multipart/form-data; allow a fallback header.
            $raw_header = $request->get_header( 'x-authorization' );
        }
        // Normalise the header by falling back to PHP globals when WordPress does not capture the
        // value (common when Apache strips headers in proxied environments).
        $header = $this->resolve_authorization_header( $raw_header );

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
            // Final fallback to request parameters (multipart-friendly)
            $param_token = $request->get_param( 'token' );
            if ( ! $param_token ) {
                $param_token = $request->get_param( 'api_token' );
            }
            if ( ! $param_token ) {
                $param_token = $request->get_param( 'authorization' );
            }

            if ( is_string( $param_token ) && '' !== trim( $param_token ) ) {
                $token = trim( (string) $param_token );
                if ( 0 === stripos( $token, 'Bearer ' ) ) {
                    $token = trim( substr( $token, 7 ) );
                }
                // Multipart requests often include the raw token value; we log the fallback so
                // integrators know why headers were ignored.
                $this->log_debug( 'authenticate_request using token parameter fallback' );
                return $this->authenticate_with_token( $token );
            }

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
            // When another authentication mechanism has already populated the user ID we respect
            // that result and bail out early.
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

        // At this point the request has successfully authenticated with a bearer token. We log the
        // masked details to help diagnose unexpected account switches without leaking secrets.
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
        // The default WordPress priority for determine_current_user is 20. Matching it ensures we
        // run after core but before most plugins, giving bearer tokens an opportunity to override
        // cookie-based sessions when appropriate.
        add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 20 );
    }

    /**
     * Retrieve the payload for a login hand-off token.
     *
     * @return array<string, mixed>|false
     */
    protected function get_login_token_payload( string $token_hash ) {
        // One-time login tokens are stored as transients keyed by a hashed token value.
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
            // When WordPress has already provided a clean header we simply normalise whitespace.
            return trim( (string) $header );
        }

        foreach ( $this->get_server_header_keys() as $server_key ) {
            if ( isset( $_SERVER[ $server_key ] ) && '' !== trim( (string) $_SERVER[ $server_key ] ) ) {
                // Some hosting providers expose the header under different names. We unslash to
                // counteract WordPress's automatic escaping of $_SERVER values.
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
        $token = $this->maybe_extract_token_from_supported_header( $header );

        if ( null === $token ) {
            $token = $this->maybe_extract_token_from_raw_header( $header );
        }

        if ( null === $token ) {
            if ( ! $this->should_log_non_bearer_header( $header ) ) {
                return $this->create_auth_error(
                    'tcn_rest_invalid_token',
                    __( 'The provided authentication token is invalid.', 'tcnapp-connector' )
                );
            }

            $this->log_debug(
                'authenticate_request header missing bearer token prefix',
                array( 'raw_header_preview' => $this->mask_string( $header ) )
            );

            return $this->create_auth_error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' )
            );
        }

        if ( '' === $token ) {
            $this->log_debug( 'authenticate_request extracted empty token' );

            return $this->create_auth_error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' )
            );
        }

        return $token;
    }

    /**
     * Attempt to extract a token from known Authorization schemes.
     */
    protected function maybe_extract_token_from_supported_header( string $header ): ?string {
        $schemes = array( 'Bearer', 'Token' );

        foreach ( $schemes as $scheme ) {
            if ( preg_match( '/^' . preg_quote( $scheme, '/' ) . '\s+(.*)$/i', $header, $matches ) ) {
                // preg_match returns the portion of the header following the scheme keyword. We
                // trim it to remove any trailing whitespace or newline characters.
                return trim( (string) $matches[1] );
            }
        }

        return null;
    }

    /**
     * Allow raw Authorization headers that only contain the token value.
     */
    protected function maybe_extract_token_from_raw_header( string $header ): ?string {
        $trimmed = trim( $header );

        if ( '' === $trimmed ) {
            return null;
        }

        if ( false !== strpos( $trimmed, ' ' ) || false !== strpos( $trimmed, '\t' ) ) {
            return null;
        }

        $this->log_debug(
            'authenticate_request using raw authorization header as token',
            array( 'raw_header_preview' => $this->mask_string( $trimmed ) )
        );

        return $trimmed;
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
        // Basic and Digest authentication are commonly used by reverse proxies; logging them would
        // create noise without providing actionable insight.
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

            return $this->create_auth_error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' )
            );
        }

        // Hash the token to generate a consistent key for transient lookups without storing the raw
        // secret in memory for longer than necessary.
        $token_hash = md5( $token );
        $token_type = 'login';

        $payload = $this->get_login_token_payload( $token_hash );
        if ( false === $payload ) {
            // If no one-time login token exists we fall back to long-lived API tokens.
            $token_type = 'api';
            $payload    = $this->get_api_token_payload( $token_hash );

            if ( false === $payload ) {
                return $this->create_auth_error(
                    'tcn_rest_token_expired',
                    __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' )
                );
            }
        }

        $user_id = (int) $payload['user_id'];
        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            // Log sufficient context for debugging without revealing the original token.
            $this->log_debug(
                'authenticate_request token payload user invalid',
                array(
                    'token_hash' => $token_hash,
                    'user_id'    => $user_id,
                    'token_type' => $token_type,
                )
            );

            return $this->create_auth_error(
                'tcn_rest_token_expired',
                __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' )
            );
        }

        // Update the global user context so downstream hooks see the authenticated user.
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
            // Standard PHP environment variable when Apache passes through the header.
            'HTTP_AUTHORIZATION',
            // Some hosts rewrite the header to this key; WordPress honours it if present.
            'REDIRECT_HTTP_AUTHORIZATION',
            // Generic fallback used by certain CGI setups.
            'AUTHORIZATION',
            // Custom header used by some load balancers and proxies.
            'HTTP_X_AUTHORIZATION',
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

            // Expired API tokens are purged immediately to avoid repeatedly performing the same
            // expiration checks on subsequent requests.
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
            // Honour WordPress' debugging flag so production environments are not spammed with
            // token-related noise.
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
                // Recursively process nested arrays so no sensitive data leaks when logging.
                $context[ $key ] = $this->sanitize_context( $value );
                continue;
            }

            if ( is_object( $value ) ) {
                // Replace objects with their class name; dumping entire objects could trigger fatal
                // errors or expose unintended properties.
                $context[ $key ] = get_class( $value );
                continue;
            }

            if ( is_string( $value ) ) {
                if ( false !== stripos( $key, 'token' ) || false !== stripos( $key, 'authorization' ) ) {
                    // Mask anything that looks like a secret while retaining enough information to
                    // correlate logs.
                    $context[ $key ] = $this->mask_string( $value );
                    continue;
                }

                if ( strlen( $value ) > 180 ) {
                    // Long strings (e.g. payloads) are truncated to keep log lines readable.
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

        // For longer strings we expose the first/last characters to aid debugging while keeping the
        // majority of the token obscured.
        return substr( $value, 0, 4 ) . '...' . substr( $value, -4 );
    }

    /**
     * Create a REST authentication error without triggering recursive status lookups.
     */
    protected function create_auth_error( string $code, string $message ): WP_Error {
        return new WP_Error(
            $code,
            $message,
            array( 'status' => $this->get_authorization_error_status() )
        );
    }

    /**
     * Resolve the authorization error status code with recursion protection.
     */
    protected function get_authorization_error_status(): int {
        static $cached_status = null;

        if ( function_exists( 'doing_filter' ) && doing_filter( 'determine_current_user' ) ) {
            // Avoid re-entering rest_authorization_required_code() while the filter is running,
            // which could lead to recursion. Reuse the cached value if available, otherwise fall
            // back to the standard 401 code.
            return null !== $cached_status ? $cached_status : 401;
        }

        if ( null === $cached_status ) {
            $status = 401;

            if ( function_exists( 'rest_authorization_required_code' ) ) {
                $status = (int) rest_authorization_required_code();
            }

            if ( $status < 400 ) {
                $status = 401;
            }

            $cached_status = $status;
        }

        return null !== $cached_status ? $cached_status : 401;
    }
}
