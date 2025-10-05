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
        $header = $request->get_header( 'authorization' );

        if ( empty( $header ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
        }

        if ( ! is_string( $header ) || '' === trim( $header ) ) {
            return 0;
        }

        if ( ! preg_match( '/Bearer\s+(.*)$/i', $header, $matches ) ) {
            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $token = trim( (string) $matches[1] );
        if ( '' === $token ) {
            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        if ( ! class_exists( PasswordLoginService::class ) ) {
            return new WP_Error(
                'tcn_rest_invalid_token',
                __( 'The provided authentication token is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $payload = get_transient( PasswordLoginService::TOKEN_PREFIX . md5( $token ) );
        if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
            return new WP_Error(
                'tcn_rest_token_expired',
                __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $user_id = (int) $payload['user_id'];
        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            return new WP_Error(
                'tcn_rest_token_expired',
                __( 'The authentication token has expired or is invalid.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        wp_set_current_user( $user_id );

        return $user_id;
    }
}
