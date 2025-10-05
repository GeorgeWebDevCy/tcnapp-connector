<?php
namespace TCN\Platform;

use TCN\Platform\Membership\MembershipModule;
use TCN\Platform\Support\Modules;
use TCN\Platform\Support\Options;
use TCN\Platform\Support\Roles;

class Activator {
    public static function activate(): void {
        Options::ensure_defaults();
        Modules::seed_defaults();
        Roles::ensure_roles();
        MembershipModule::activate();

        do_action( 'tcn_platform_activated' );
        flush_rewrite_rules();
    }
}
