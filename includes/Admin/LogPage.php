<?php
namespace TCN\Platform\Admin;

use TCN\Platform\Support\Logger;

use function __;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function current_user_can;
use function date_i18n;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function submit_button;
use function wp_die;
use function wp_get_current_user;
use function wp_get_referer;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;
use function wp_verify_nonce;

class LogPage {
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_tcn_platform_clear_logs', array( $this, 'handle_clear_logs' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'tcn-platform',
            __( 'Activity Log', 'tcnapp-connector' ),
            __( 'Activity Log', 'tcnapp-connector' ),
            'manage_options',
            'tcn-platform-logs',
            array( $this, 'render_page' )
        );
    }

    public function handle_clear_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tcnapp-connector' ) );
        }

        $nonce = isset( $_POST['tcn_platform_clear_logs_nonce'] ) ? wp_unslash( $_POST['tcn_platform_clear_logs_nonce'] ) : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'tcn_platform_clear_logs' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'tcnapp-connector' ) );
        }

        $actor       = wp_get_current_user();
        $actor_label = ( $actor && $actor->ID ) ? $actor->user_login : __( 'Unknown user', 'tcnapp-connector' );

        Logger::clear();
        Logger::log(
            'plugin',
            __( 'Activity log cleared', 'tcnapp-connector' ),
            array(
                'user' => $actor_label,
            )
        );

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=tcn-platform-logs' );
        }

        wp_safe_redirect( add_query_arg( 'tcn_log_cleared', '1', $redirect ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tcnapp-connector' ) );
        }

        $logs = Logger::get_logs();
        ?>
        <div class="wrap tcn-platform-logs">
            <h1><?php esc_html_e( 'TCN Platform Activity Log', 'tcnapp-connector' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Review REST API calls from the TCNApp mobile client and key plugin actions. The most recent 200 entries are stored.', 'tcnapp-connector' ); ?>
            </p>

            <?php if ( isset( $_GET['tcn_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Activity log cleared.', 'tcnapp-connector' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcn-platform-log-actions">
                <?php wp_nonce_field( 'tcn_platform_clear_logs', 'tcn_platform_clear_logs_nonce' ); ?>
                <input type="hidden" name="action" value="tcn_platform_clear_logs" />
                <?php submit_button( __( 'Clear Log', 'tcnapp-connector' ), 'delete', 'submit', false ); ?>
            </form>

            <table class="widefat fixed striped tcn-platform-log-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'tcnapp-connector' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'tcnapp-connector' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'tcnapp-connector' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'tcnapp-connector' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e( 'No activity recorded yet.', 'tcnapp-connector' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $this->format_time( $entry['time'] ) ); ?></td>
                                <td><?php echo esc_html( $this->format_source( $entry['source'] ) ); ?></td>
                                <td><?php echo esc_html( $entry['message'] ); ?></td>
                                <td><?php echo $this->render_details( $entry['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    protected function format_time( $timestamp ): string {
        if ( empty( $timestamp ) ) {
            return '—';
        }

        return date_i18n( sprintf( '%s %s', get_option( 'date_format' ), get_option( 'time_format' ) ), (int) $timestamp );
    }

    protected function format_source( string $source ): string {
        switch ( $source ) {
            case 'rest':
                return __( 'REST API', 'tcnapp-connector' );
            case 'plugin':
                return __( 'Plugin', 'tcnapp-connector' );
        }

        return ucfirst( $source );
    }

    protected function render_details( $details ): string {
        if ( empty( $details ) ) {
            return '<span class="tcn-platform-log-empty">—</span>';
        }

        if ( is_scalar( $details ) ) {
            if ( is_bool( $details ) ) {
                return $details ? esc_html__( 'Yes', 'tcnapp-connector' ) : esc_html__( 'No', 'tcnapp-connector' );
            }

            return esc_html( (string) $details );
        }

        if ( is_array( $details ) ) {
            $items = array();
            foreach ( $details as $key => $value ) {
                $label  = is_string( $key ) ? esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ) : esc_html( (string) $key );
                $items[] = sprintf(
                    '<li><span class="tcn-platform-log-key">%s</span>%s</li>',
                    $label,
                    $this->render_detail_value( $value )
                );
            }

            return '<ul class="tcn-platform-log-list">' . implode( '', $items ) . '</ul>';
        }

        if ( $details instanceof \JsonSerializable ) {
            return '<pre class="tcn-platform-log-pre">' . esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT ) ) . '</pre>';
        }

        return esc_html( (string) $details );
    }

    protected function render_detail_value( $value ): string {
        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                return '<span class="tcn-platform-log-empty">—</span>';
            }

            $encoded = wp_json_encode( $value, JSON_PRETTY_PRINT );
            if ( false === $encoded ) {
                $encoded = wp_json_encode( $value );
            }

            return '<pre class="tcn-platform-log-pre">' . esc_html( (string) $encoded ) . '</pre>';
        }

        if ( is_bool( $value ) ) {
            return sprintf( '<span class="tcn-platform-log-boolean %s">%s</span>', $value ? 'is-true' : 'is-false', esc_html( $value ? __( 'Yes', 'tcnapp-connector' ) : __( 'No', 'tcnapp-connector' ) ) );
        }

        if ( is_numeric( $value ) ) {
            return '<span class="tcn-platform-log-number">' . esc_html( (string) $value ) . '</span>';
        }

        if ( null === $value ) {
            return '<span class="tcn-platform-log-empty">null</span>';
        }

        return '<span class="tcn-platform-log-text">' . esc_html( (string) $value ) . '</span>';
    }
}
