<?php
namespace TCN\Platform;

class Autoloader {
    /**
     * Register the autoloader with SPL.
     */
    public static function register(): void {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload classes inside the plugin namespace.
     */
    public static function autoload( string $class ): void {
        $prefix = __NAMESPACE__ . '\\';

        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
        $path     = TCN_PLATFORM_PLUGIN_DIR . 'includes/' . $relative . '.php';

        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}
