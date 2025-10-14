<?php
namespace TCN\Platform\Support;

use WP_Error;

/**
 * Centralised error codes for REST responses and service errors.
 */
class ErrorCodes {
    public const AUTH_PASSWORD_LOGIN_FAILED        = 'tcn_auth_password_login_failed';
    public const AUTH_LOGIN_MISSING_CREDENTIALS   = 'tcn_auth_login_missing_credentials';
    public const AUTH_LOGIN_RATE_LIMITED          = 'tcn_auth_login_rate_limited';
    public const AUTH_WORDPRESS_CREDENTIALS       = 'tcn_auth_wordpress_credentials';
    public const AUTH_ACCOUNT_SUSPENDED           = 'tcn_auth_account_suspended';
    public const AUTH_VENDOR_PENDING              = 'tcn_auth_vendor_pending';
    public const AUTH_VENDOR_REJECTED             = 'tcn_auth_vendor_rejected';
    public const AUTH_VENDOR_SUSPENDED            = 'tcn_auth_vendor_suspended';
    public const AUTH_REGISTER_ACCOUNT_FAILED     = 'tcn_auth_register_account_failed';
    public const REGISTER_VENDOR_TIER_FETCH_FAILED = 'tcn_register_vendor_tier_fetch_failed';
    public const AUTH_PASSWORD_RESET_EMAIL_FAILED = 'tcn_auth_password_reset_email_failed';
    public const AUTH_RESET_PASSWORD_FAILED       = 'tcn_auth_reset_password_failed';
    public const AUTH_CHANGE_PASSWORD_FAILED      = 'tcn_auth_change_password_failed';
    public const SESSION_TOKEN_UNAVAILABLE        = 'tcn_session_token_unavailable';
    public const MEMBERSHIP_PAYMENT_SESSION_FAILED = 'tcn_membership_payment_session_failed';
    public const MEMBERSHIP_CONFIRM_FAILED        = 'tcn_membership_confirm_failed';
    public const MEMBERSHIP_CHECKOUT_FAILED       = 'tcn_membership_checkout_failed';

    /**
     * Convert an error code to a WP_Error with consistent metadata.
     */
    public static function to_wp_error(
        string $code,
        string $message,
        int $status = 500,
        array $data = array()
    ): WP_Error {
        if ( ! isset( $data['status'] ) ) {
            $data['status'] = $status;
        }

        return new WP_Error( $code, $message, $data );
    }
}
