<?php
namespace TCN\Platform\Support;

class Modules {
    const OPTION_KEY          = 'tcn_platform_modules';
    const MODULE_MLM          = 'mlm';
    const MODULE_AUTH_LOGIN   = 'auth_login';

    /**
     * @var array<string, bool>
     */
    protected $modules;

    public function __construct() {
        $this->modules = self::get_all();
    }

    /**
     * Ensure defaults are stored when the plugin activates.
     */
    public static function seed_defaults(): void {
        if ( ! get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, self::defaults() );
        }
    }

    /**
     * Retrieve the merged module map (stored + defaults).
     *
     * @return array<string, bool>
     */
    public static function get_all(): array {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return wp_parse_args( $stored, self::defaults() );
    }

    /**
     * Default module configuration.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array {
        return array(
            self::MODULE_MLM        => true,
            self::MODULE_AUTH_LOGIN => true,
        );
    }

    public function is_enabled( string $module ): bool {
        if ( self::MODULE_MLM === $module ) {
            return true;
        }

        return ! empty( $this->modules[ $module ] );
    }

    public function set_enabled( string $module, bool $enabled ): void {
        if ( self::MODULE_MLM === $module ) {
            $this->modules[ $module ] = true;
        } else {
            $this->modules[ $module ] = $enabled;
        }

        $this->persist();
    }

    protected function persist(): void {
        update_option( self::OPTION_KEY, wp_parse_args( $this->modules, self::defaults() ) );
    }

    /**
     * Update modules in bulk (used by admin UI).
     *
     * @param array<string, bool> $modules Modules keyed by slug.
     */
    public function sync( array $modules ): void {
        foreach ( self::defaults() as $slug => $default ) {
            $value = isset( $modules[ $slug ] ) ? (bool) $modules[ $slug ] : $default;
            $this->set_enabled( $slug, $value );
        }
    }

    /**
     * Return the internal state (used for rendering admin UI).
     *
     * @return array<string, bool>
     */
    public function all(): array {
        return $this->modules;
    }
}
