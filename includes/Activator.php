<?php
namespace TCN\Platform;

use TCN\Platform\Membership\MembershipModule;
use TCN\Platform\Support\Discounts;
use TCN\Platform\Support\Modules;
use TCN\Platform\Support\Options;
use TCN\Platform\Support\VendorTiers;
use TCN\Platform\Support\Roles;

/**
 * Handles one-time work that needs to occur when the plugin is activated.
 */
class Activator {
    public static function activate(): void {
        // Populate option defaults before other services query them. This avoids having to guard
        // against missing keys elsewhere in the plugin.
        Options::ensure_defaults();
        // Seed vendor tier catalogue used by mobile onboarding/marketing.
        VendorTiers::ensure_defaults();
        // Seed the module registry so feature toggles have sensible initial values.
        Modules::seed_defaults();
        // Ensure custom roles/capabilities exist prior to any hooks firing on the same request.
        Roles::ensure_roles();
        // MembershipModule may need to perform schema updates or network calls when the plugin is
        // first enabled. By delegating to the module the logic stays contained.
        MembershipModule::activate();
        // Ensure the discount transaction table exists before any REST calls attempt to write to it.
        Discounts::activate();

        // Fire a WordPress action to allow extensions to hook into the activation lifecycle.
        do_action( 'tcn_platform_activated' );
        // Flush permalinks so any custom rewrite rules defined by the plugin become active without
        // requiring the administrator to visit the settings page manually.
        flush_rewrite_rules();
    }
}
