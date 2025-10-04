<?php
namespace TCN\Platform\Admin;

use TCN\Platform\Support\Logger;

use function __;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function current_user_can;
use function date_i18n;
use function esc_attr;
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
use function wp_is_numeric_array;
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

        if ( ! RestrictedAccess::has_access( 'tcn-platform-logs' ) ) {
            wp_die( esc_html__( 'Please unlock the Activity Log before performing this action.', 'tcnapp-connector' ) );
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

        if ( ! RestrictedAccess::require_access( 'tcn-platform-logs', __( 'Activity Log', 'tcnapp-connector' ) ) ) {
            return;
        }

        $logs = Logger::get_logs();
        ?>
        <div class="wrap tcn-platform-logs">
            <div class="tcn-platform-page-intro">
                <h1><?php esc_html_e( 'TCN Platform Activity Log', 'tcnapp-connector' ); ?></h1>
                <p class="description tcn-platform-page-subtitle">
                    <?php esc_html_e( 'Review REST API calls from the TCNApp mobile client and key plugin actions. The most recent 200 entries are stored.', 'tcnapp-connector' ); ?>
                </p>
            </div>

            <?php if ( isset( $_GET['tcn_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Activity log cleared.', 'tcnapp-connector' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="tcn-platform-panel">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcn-platform-log-actions">
                    <?php wp_nonce_field( 'tcn_platform_clear_logs', 'tcn_platform_clear_logs_nonce' ); ?>
                    <input type="hidden" name="action" value="tcn_platform_clear_logs" />
                    <?php submit_button( __( 'Clear Log', 'tcnapp-connector' ), 'delete', 'submit', false ); ?>
                </form>

                <div class="tcn-platform-log-table-wrapper">
                    <table id="tcn-platform-log-table" class="widefat fixed striped tcn-platform-log-table display nowrap" style="width:100%">
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
                                    <?php $timestamp = isset( $entry['time'] ) ? (int) $entry['time'] : 0; ?>
                                    <tr>
                                        <td data-order="<?php echo esc_attr( $timestamp ); ?>"><?php echo esc_html( $this->format_time( $timestamp ) ); ?></td>
                                        <td><?php echo esc_html( $this->format_source( $entry['source'] ) ); ?></td>
                                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                                        <td><?php echo $this->render_details( $entry['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
        return $this->render_detail_fragment( $details );
    }

    protected function render_detail_fragment( $value, int $depth = 0, string $parent_key = '' ): string {
        if ( null === $value ) {
            return '<span class="tcn-platform-log-empty">—</span>';
        }

        if ( $value instanceof \JsonSerializable ) {
            $encoded = wp_json_encode( $value, JSON_PRETTY_PRINT );
            if ( false === $encoded ) {
                $encoded = wp_json_encode( $value );
            }

            if ( false === $encoded ) {
                return '<span class="tcn-platform-log-empty">—</span>';
            }

            return '<pre class="tcn-platform-log-pre">' . esc_html( (string) $encoded ) . '</pre>';
        }

        if ( is_object( $value ) ) {
            $value = (array) $value;
        }

        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                return '<span class="tcn-platform-log-empty">—</span>';
            }

            if ( wp_is_numeric_array( $value ) ) {
                $items = array();
                foreach ( $value as $item ) {
                    $items[] = '<li>' . $this->render_detail_fragment( $item, $depth + 1 ) . '</li>';
                }

                $classes = array( 'tcn-platform-log-list', 'is-numeric' );
                if ( $depth > 0 ) {
                    $classes[] = 'is-nested';
                }

                return sprintf( '<ol class="%s">%s</ol>', esc_attr( implode( ' ', $classes ) ), implode( '', $items ) );
            }

            $rows = array();
            foreach ( $value as $key => $item ) {
                $label = $this->format_detail_label( $key );
                $rows[] = sprintf(
                    '<div class="tcn-platform-log-detail-row"><dt>%s</dt><dd>%s</dd></div>',
                    esc_html( $label ),
                    $this->render_detail_fragment( $item, $depth + 1, is_string( $key ) ? strtolower( (string) $key ) : '' )
                );
            }

            $classes = array( 'tcn-platform-log-details' );
            if ( $depth > 0 ) {
                $classes[] = 'is-nested';
            }

            return sprintf( '<dl class="%s">%s</dl>', esc_attr( implode( ' ', $classes ) ), implode( '', $rows ) );
        }

        if ( is_bool( $value ) ) {
            return sprintf(
                '<span class="tcn-platform-log-boolean %s">%s</span>',
                $value ? 'is-true' : 'is-false',
                esc_html( $value ? __( 'Yes', 'tcnapp-connector' ) : __( 'No', 'tcnapp-connector' ) )
            );
        }

        if ( is_numeric( $value ) ) {
            return sprintf( '<span class="tcn-platform-log-number">%s</span>', esc_html( (string) $value ) );
        }

        if ( is_string( $value ) ) {
            $trimmed = trim( $value );
            if ( '' === $trimmed ) {
                return '<span class="tcn-platform-log-empty">—</span>';
            }

            $lower_value = strtolower( $trimmed );

            if ( 'result' === $parent_key ) {
                $class = 'tcn-platform-log-badge';
                if ( in_array( $lower_value, array( 'success', 'ok' ), true ) ) {
                    $class .= ' is-success';
                } elseif ( in_array( $lower_value, array( 'error', 'fail', 'failure' ), true ) ) {
                    $class .= ' is-error';
                }

                return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( ucfirst( $lower_value ) ) );
            }

            if ( 'log_level' === $parent_key ) {
                $class = 'tcn-platform-log-badge';
                if ( in_array( $lower_value, array( 'warn', 'warning' ), true ) ) {
                    $class .= ' is-warning';
                } elseif ( in_array( $lower_value, array( 'error', 'critical' ), true ) ) {
                    $class .= ' is-error';
                } elseif ( in_array( $lower_value, array( 'debug' ), true ) ) {
                    $class .= ' is-muted';
                } else {
                    $class .= ' is-success';
                }

                return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( strtoupper( $trimmed ) ) );
            }

            return sprintf( '<span class="tcn-platform-log-text">%s</span>', esc_html( $trimmed ) );
        }

        return esc_html( (string) $value );
    }

    protected function format_detail_label( $key ): string {
        if ( is_int( $key ) ) {
            /* translators: %d: Item number. */
            return sprintf( __( 'Item %d', 'tcnapp-connector' ), $key + 1 );
        }

        $normalized = strtolower( (string) $key );

        $map = array(
            'namespace'     => __( 'Namespace', 'tcnapp-connector' ),
            'status'        => __( 'Status Code', 'tcnapp-connector' ),
            'result'        => __( 'Result', 'tcnapp-connector' ),
            'user'          => __( 'User', 'tcnapp-connector' ),
            'ip'            => __( 'IP Address', 'tcnapp-connector' ),
            'ip_address'    => __( 'IP Address', 'tcnapp-connector' ),
            'params'        => __( 'Parameters', 'tcnapp-connector' ),
            'errors'        => __( 'Errors', 'tcnapp-connector' ),
            'log_level'     => __( 'Log Level', 'tcnapp-connector' ),
            'log_message'   => __( 'Log Message', 'tcnapp-connector' ),
            'log_timestamp' => __( 'Log Timestamp', 'tcnapp-connector' ),
            'log_source'    => __( 'Log Source', 'tcnapp-connector' ),
            'log_params'    => __( 'Log Parameters', 'tcnapp-connector' ),
            'planid'        => __( 'Plan ID', 'tcnapp-connector' ),
        );

        if ( isset( $map[ $normalized ] ) ) {
            return $map[ $normalized ];
        }

        return ucwords( preg_replace( '/[_\-.]+/', ' ', (string) $key ) );
    }
}
