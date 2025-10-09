<?php
namespace TCN\Platform\Support;

use WP_Role;
use WP_User;

class Roles {
    const APP_USER_ROLE  = 'tcn_app_user';
    const VENDOR_ROLE    = 'tcn_vendor';

    /**
     * Ensure custom roles exist with the expected capabilities.
     */
    public static function ensure_roles(): void {
        self::ensure_app_user_role();
        self::ensure_vendor_role();
        self::ensure_discount_capabilities();
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
     * Guarantee the Vendor role is provisioned for storefront staff.
     */
    protected static function ensure_vendor_role(): void {
        $capabilities = self::get_vendor_capabilities();

        $role = get_role( self::VENDOR_ROLE );

        if ( ! $role instanceof WP_Role ) {
            add_role( self::VENDOR_ROLE, __( 'Vendor', 'tcnapp-connector' ), $capabilities );
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
     * Grant the discount redemption capability to administrative WooCommerce roles.
     */
    protected static function ensure_discount_capabilities(): void {
        $roles = array( 'administrator', 'shop_manager' );

        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role instanceof WP_Role ) {
                continue;
            }

            if ( ! $role->has_cap( 'tcn_discount_redemptions' ) ) {
                $role->add_cap( 'tcn_discount_redemptions' );
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
     * Assign the Vendor role to a user.
     */
    public static function assign_vendor_role( int $user_id ): void {
        $user = get_user_by( 'id', $user_id );

        if ( ! $user instanceof WP_User ) {
            return;
        }

        if ( ! in_array( self::VENDOR_ROLE, $user->roles, true ) ) {
            $user->add_role( self::VENDOR_ROLE );
        }

        if ( ! in_array( self::APP_USER_ROLE, $user->roles, true ) ) {
            $user->add_role( self::APP_USER_ROLE );
        }

        if ( in_array( 'subscriber', $user->roles, true ) ) {
            if ( count( $user->roles ) === 1 ) {
                $user->set_role( self::VENDOR_ROLE );
            } else {
                $user->remove_role( 'subscriber' );
            }
        }
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

    /**
     * Retrieve the capabilities granted to the Vendor role.
     */
    protected static function get_vendor_capabilities(): array {
        return array(
            'read'                     => true,
            'upload_files'             => true,
            'tcn_discount_redemptions' => true,
        );
    }
}
