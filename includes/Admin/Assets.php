<?php
namespace TCN\Platform\Admin;

use function esc_html__;

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

        $is_log_screen = false !== strpos( $screen->id, 'tcn-platform-logs' );

        if ( $is_log_screen ) {
            wp_enqueue_style(
                'tcn-platform-datatables',
                'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',
                array(),
                '1.13.8'
            );

            wp_enqueue_style(
                'tcn-platform-datatables-responsive',
                'https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css',
                array( 'tcn-platform-datatables' ),
                '2.5.0'
            );

            wp_enqueue_script(
                'tcn-platform-datatables',
                'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
                array( 'jquery' ),
                '1.13.8',
                true
            );

            wp_enqueue_script(
                'tcn-platform-datatables-responsive',
                'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js',
                array( 'tcn-platform-datatables' ),
                '2.5.0',
                true
            );
        }

        $dependencies = array( 'jquery' );
        if ( $is_log_screen ) {
            $dependencies[] = 'tcn-platform-datatables-responsive';
        }

        wp_enqueue_script(
            'tcn-platform-admin',
            TCN_PLATFORM_PLUGIN_URL . 'admin/js/tcn-platform-admin.js',
            $dependencies,
            TCN_PLATFORM_VERSION,
            true
        );

        if ( $is_log_screen ) {
            wp_localize_script(
                'tcn-platform-admin',
                'tcnPlatformAdmin',
                array(
                    'logTable' => array(
                        'search'            => esc_html__( 'Search logs:', 'tcnapp-connector' ),
                        'searchPlaceholder' => esc_html__( 'Search logs…', 'tcnapp-connector' ),
                        'lengthMenu'        => esc_html__( 'Show _MENU_ entries', 'tcnapp-connector' ),
                        'info'              => esc_html__( 'Showing _START_ to _END_ of _TOTAL_ entries', 'tcnapp-connector' ),
                        'infoEmpty'         => esc_html__( 'Showing 0 to 0 of 0 entries', 'tcnapp-connector' ),
                        'infoFiltered'      => esc_html__( '(filtered from _MAX_ total entries)', 'tcnapp-connector' ),
                        'emptyTable'        => esc_html__( 'No activity recorded yet.', 'tcnapp-connector' ),
                        'zeroRecords'       => esc_html__( 'No matching activity found.', 'tcnapp-connector' ),
                        'paginate'          => array(
                            'first'    => esc_html__( 'First', 'tcnapp-connector' ),
                            'last'     => esc_html__( 'Last', 'tcnapp-connector' ),
                            'next'     => esc_html__( 'Next', 'tcnapp-connector' ),
                            'previous' => esc_html__( 'Previous', 'tcnapp-connector' ),
                        ),
                        'aria'              => array(
                            'sortAscending'  => esc_html__( ': activate to sort column ascending', 'tcnapp-connector' ),
                            'sortDescending' => esc_html__( ': activate to sort column descending', 'tcnapp-connector' ),
                        ),
                        'lengthMenuAll'     => esc_html__( 'All', 'tcnapp-connector' ),
                    ),
                )
            );
        }
    }
}
