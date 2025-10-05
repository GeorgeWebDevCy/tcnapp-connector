<?php
namespace TCN\Platform\Support;

use WP_Role;
use WP_User;

class Roles {
    const APP_USER_ROLE = 'tcn_app_user';

    /**
     * Ensure custom roles exist with the expected capabilities.
     */
    public static function ensure_roles(): void {
        self::ensure_app_user_role();
    }

    /**
     * Guarantee the App User role is present and provisioned correctly.
     */
    protected static function ensure_app_user_role(): void {
        $capabilities = self::get_app_user_capabilities();

        $role = get_role( self::APP_USER_ROLE );

        if ( ! $role instanceof WP_Role ) {
            add_role( self::APP_USER_ROLE, __( 'App User', 'tcnapp-connector' ), $capabilities );
            return;
        }

        foreach ( $capabilities as $capability => $grant ) {
            if ( $grant ) {
                if ( ! $role->has_cap( $capability ) ) {
                    $role->add_cap( $capability );
                }
                continue;
            }

            if ( $role->has_cap( $capability ) ) {
                $role->remove_cap( $capability );
            }
        }
    }

    /**
     * Assign the App User role to a user when missing.
     */
    public static function maybe_assign_app_user_role( $user ): void {
        if ( ! $user instanceof WP_User ) {
            return;
        }

        if ( in_array( self::APP_USER_ROLE, $user->roles, true ) ) {
            return;
        }

        $user->add_role( self::APP_USER_ROLE );
    }

    /**
     * Retrieve the capabilities granted to the App User role.
     */
    protected static function get_app_user_capabilities(): array {
        return array(
            'read'         => true,
            'upload_files' => true,
        );
    }
}
