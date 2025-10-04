<?php
namespace TCN\Platform;

class Deactivator {
    public static function deactivate(): void {
        do_action( 'tcn_platform_deactivated' );
        flush_rewrite_rules();
    }
}
