<?php
namespace TCN\Platform\Admin;

use function admin_url;
use function check_admin_referer;
use function current_time;
use function esc_html;
use function esc_html_e;
use function get_current_user_id;
use function get_user_meta;
use function hash_equals;
use function is_array;
use function password_verify;
use function update_user_meta;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;

class RestrictedAccess {
    private const PASSWORD_HASH = '$2y$12$Kzw1Fft2LS/UNVNebCRL2.7gkjrRVL5j6ObyqsEsOTtH6KRoY1uqO';

    private const META_KEY = '_tcn_platform_restricted_access';

    private const NONCE_ACTION = 'tcn_platform_unlock';

    private const NONCE_NAME = 'tcn_platform_unlock_nonce';

    private const EXPIRATION_SECONDS = 86400; // 24 hours.

    public static function require_access( string $page_slug, string $page_title ): bool {
        if ( self::has_access( $page_slug ) ) {
            return true;
        }

        $error = false;

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST[ self::NONCE_NAME ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

            $password = isset( $_POST['tcn_platform_password'] ) ? (string) wp_unslash( $_POST['tcn_platform_password'] ) : '';

            if ( self::verify_password( $password ) ) {
                self::grant_access( $page_slug );
                wp_safe_redirect( admin_url( 'admin.php?page=' . $page_slug ) );
                exit;
            }

            $error = true;
        }

        self::render_lock_screen( $page_title, $error );

        return false;
    }

    public static function has_access( string $page_slug ): bool {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return false;
        }

        $tokens = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $tokens ) ) {
            return false;
        }

        $token = self::tokenize( $page_slug );
        if ( ! isset( $tokens[ $token ] ) ) {
            return false;
        }

        $expires = isset( $tokens[ $token ]['expires'] ) ? (int) $tokens[ $token ]['expires'] : 0;

        if ( $expires < current_time( 'timestamp', true ) ) {
            unset( $tokens[ $token ] );
            update_user_meta( $user_id, self::META_KEY, $tokens );
            return false;
        }

        return hash_equals( $token, $tokens[ $token ]['token'] ?? '' );
    }

    private static function grant_access( string $page_slug ): void {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return;
        }

        $tokens = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $tokens ) ) {
            $tokens = array();
        }

        $token = self::tokenize( $page_slug );

        $tokens[ $token ] = array(
            'token'   => $token,
            'expires' => current_time( 'timestamp', true ) + self::EXPIRATION_SECONDS,
        );

        update_user_meta( $user_id, self::META_KEY, $tokens );
    }

    private static function verify_password( string $password ): bool {
        if ( '' === $password ) {
            return false;
        }

        return password_verify( $password, self::PASSWORD_HASH );
    }

    private static function render_lock_screen( string $page_title, bool $error ): void {
        ?>
        <div class="wrap tcn-platform-locked">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <p class="description"><?php esc_html_e( 'This area is restricted. Provide the access password to continue.', 'tcnapp-connector' ); ?></p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'Incorrect password. Please try again.', 'tcnapp-connector' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                <label for="tcn-platform-password" class="screen-reader-text"><?php esc_html_e( 'Access password', 'tcnapp-connector' ); ?></label>
                <input type="password" id="tcn-platform-password" name="tcn_platform_password" class="regular-text" autocomplete="off" />
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Unlock', 'tcnapp-connector' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    private static function tokenize( string $page_slug ): string {
        return hash( 'sha256', $page_slug . '|' . self::PASSWORD_HASH );
    }
}
