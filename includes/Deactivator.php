<?php
namespace TCN\Platform;

/**
 * Performs teardown tasks when the plugin is deactivated.
 */
class Deactivator {
    public static function deactivate(): void {
        // Provide an extensibility point so companion plugins can clean up their own resources.
        do_action( 'tcn_platform_deactivated' );
        // Flush rewrite rules to remove any custom endpoints that were registered during runtime.
        flush_rewrite_rules();
    }
}
