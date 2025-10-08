<?php
namespace TCN\Platform;

class Autoloader {
    /**
     * Register the autoloader with SPL.
     */
    public static function register(): void {
        // SplAutoloadRegister allows us to stack multiple autoloaders. By pointing to self::autoload
        // we ensure any class under the TCN\Platform namespace is resolved on demand without paying
        // the cost of require_once calls for files we never use during a request.
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload classes inside the plugin namespace.
     */
    public static function autoload( string $class ): void {
        // We only handle classes that belong to this plugin's namespace. Early exiting keeps the
        // autoloader cheap when WordPress (or other plugins) try to resolve their own classes.
        $prefix = __NAMESPACE__ . '\\';

        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        // Strip the namespace prefix and convert the class name into a relative file path following
        // PSR-4 conventions. Directory separators vary between environments, so we normalise them.
        $relative = substr( $class, strlen( $prefix ) );
        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
        $path     = TCN_PLATFORM_PLUGIN_DIR . 'includes/' . $relative . '.php';

        // Only require the file if it exists—this prevents fatal errors when experimental classes
        // are referenced but not shipped and makes the autoloader safe to run in development.
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}
