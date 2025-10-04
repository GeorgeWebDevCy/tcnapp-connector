<?php
namespace TCN\Platform\Admin;

class Assets {
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, 'tcn-platform' ) ) {
            return;
        }

        wp_enqueue_style(
            'tcn-platform-admin',
            TCN_PLATFORM_PLUGIN_URL . 'admin/css/tcn-platform-admin.css',
            array(),
            TCN_PLATFORM_VERSION
        );
    }
}
