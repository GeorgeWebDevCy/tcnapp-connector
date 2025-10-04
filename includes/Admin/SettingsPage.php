<?php
namespace TCN\Platform\Admin;

use TCN\Platform\Support\Modules;
use TCN\Platform\Support\Options;

class SettingsPage {
    /**
     * @var Modules
     */
    protected $modules;

    public function __construct( Modules $modules ) {
        $this->modules = $modules;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'TCN Platform', 'tcnapp-connector' ),
            __( 'TCN Platform', 'tcnapp-connector' ),
            'manage_options',
            'tcn-platform',
            array( $this, 'render_page' ),
            'dashicons-networking',
            56
        );
    }

    public function handle_form_submission(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( empty( $_POST['tcn_platform_settings_nonce'] ) ) {
            return;
        }

        check_admin_referer( 'tcn_platform_settings', 'tcn_platform_settings_nonce' );

        $modules = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
            ? array_map( 'boolval', wp_unslash( $_POST['modules'] ) )
            : array();

        $this->modules->sync( $modules );

        $login = array(
            'allowed_origin'    => isset( $_POST['allowed_origin'] ) ? esc_url_raw( wp_unslash( $_POST['allowed_origin'] ) ) : '',
            'allow_dev_http'    => ! empty( $_POST['allow_dev_http'] ),
            'token_lifetime'    => isset( $_POST['token_lifetime'] ) ? max( 60, absint( $_POST['token_lifetime'] ) ) : 15 * MINUTE_IN_SECONDS,
            'rate_limit'        => isset( $_POST['rate_limit'] ) ? max( 3, absint( $_POST['rate_limit'] ) ) : 10,
            'rate_limit_window' => isset( $_POST['rate_limit_window'] ) ? max( 60, absint( $_POST['rate_limit_window'] ) ) : 5 * MINUTE_IN_SECONDS,
        );

        Options::update_login_settings( $login );

        do_action( 'tcn_platform_settings_saved', $modules, $login );

        add_settings_error( 'tcn_platform', 'settings_saved', __( 'Settings saved.', 'tcnapp-connector' ), 'updated' );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tcnapp-connector' ) );
        }

        $modules        = $this->modules->all();
        $login_settings = Options::get_login_settings();
        $levels         = Options::get_levels();

        if ( ! is_array( $levels ) ) {
            $levels = array();
        }
        $general        = Options::get_general_settings();
        $currency       = isset( $general['currency'] ) ? $general['currency'] : 'USD';

        settings_errors( 'tcn_platform' );
        ?>
        <div class="wrap tcn-platform-settings">
            <h1><?php esc_html_e( 'TCN Platform', 'tcnapp-connector' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( 'tcn_platform_settings', 'tcn_platform_settings_nonce' ); ?>

                <div class="tcn-platform-card-grid">
                    <div class="tcn-platform-card">
                        <h2><?php esc_html_e( 'Membership & MLM', 'tcnapp-connector' ); ?></h2>
                        <p><?php esc_html_e( 'Core data models, WooCommerce integration, dashboards, and REST endpoints.', 'tcnapp-connector' ); ?></p>
                        <p class="tcn-platform-notice"><?php esc_html_e( 'Required and always enabled.', 'tcnapp-connector' ); ?></p>
                    </div>
                    <div class="tcn-platform-card">
                        <h2><?php esc_html_e( 'Password Login API', 'tcnapp-connector' ); ?></h2>
                        <p><?php esc_html_e( 'REST endpoints for mobile/password authentication (wp-json/gn/v1).', 'tcnapp-connector' ); ?></p>
                        <label>
                            <input type="checkbox" name="modules[auth_login]" value="1" <?php checked( ! empty( $modules['auth_login'] ) ); ?> />
                            <?php esc_html_e( 'Enable Password Login API module', 'tcnapp-connector' ); ?>
                        </label>
                    </div>
                </div>

                <h2><?php esc_html_e( 'Password Login API Settings', 'tcnapp-connector' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="allowed_origin"><?php esc_html_e( 'Allowed CORS Origin', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="url" id="allowed_origin" name="allowed_origin" class="regular-text" value="<?php echo esc_attr( $login_settings['allowed_origin'] ); ?>" placeholder="https://app.example.com" />
                                <p class="description"><?php esc_html_e( 'Leave blank to restrict requests to the WordPress origin.', 'tcnapp-connector' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="allow_dev_http"><?php esc_html_e( 'Allow HTTP During Development', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" id="allow_dev_http" name="allow_dev_http" value="1" <?php checked( ! empty( $login_settings['allow_dev_http'] ) ); ?> />
                                    <?php esc_html_e( 'Permit non-HTTPS requests when WP_DEBUG is enabled.', 'tcnapp-connector' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="token_lifetime"><?php esc_html_e( 'Login Token Lifetime (seconds)', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="number" min="60" step="60" id="token_lifetime" name="token_lifetime" value="<?php echo esc_attr( $login_settings['token_lifetime'] ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="rate_limit"><?php esc_html_e( 'Rate Limit Attempts', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="number" min="3" id="rate_limit" name="rate_limit" value="<?php echo esc_attr( $login_settings['rate_limit'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'Maximum attempts allowed per window per user/IP before temporarily blocking requests.', 'tcnapp-connector' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="rate_limit_window"><?php esc_html_e( 'Rate Limit Window (seconds)', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="number" min="60" step="60" id="rate_limit_window" name="rate_limit_window" value="<?php echo esc_attr( $login_settings['rate_limit_window'] ); ?>" />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>

            <div class="tcn-platform-card tcn-platform-levels">
                <h2><?php esc_html_e( 'Membership Levels', 'tcnapp-connector' ); ?></h2>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Level', 'tcnapp-connector' ); ?></th>
                            <th><?php esc_html_e( 'Fee', 'tcnapp-connector' ); ?></th>
                            <th><?php esc_html_e( 'Direct Commission', 'tcnapp-connector' ); ?></th>
                            <th><?php esc_html_e( 'Passive Commission', 'tcnapp-connector' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $levels as $level ) :
                            if ( ! is_array( $level ) ) {
                                $level = array( 'name' => (string) $level );
                            }

                            $level = wp_parse_args(
                                $level,
                                array(
                                    'name'               => '',
                                    'benefits'           => array(),
                                    'fee'                => 0,
                                    'commission_direct'  => 0,
                                    'commission_passive' => 0,
                                )
                            );

                            $benefits = array_filter( (array) $level['benefits'], 'strlen' );
                            $benefits = array_map( 'wp_strip_all_tags', $benefits );
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $level['name'] ); ?></strong><br />
                                    <small><?php echo esc_html( implode( ' • ', $benefits ) ); ?></small>
                                </td>
                                <td><?php echo esc_html( $this->format_amount( $level['fee'], $currency ) ); ?></td>
                                <td><?php echo esc_html( $this->format_amount( $level['commission_direct'], $currency ) ); ?></td>
                                <td><?php echo esc_html( $this->format_amount( $level['commission_passive'], $currency ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    protected function format_amount( $amount, string $currency ): string {
        if ( function_exists( 'wc_price' ) ) {
            return wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );
        }

        return sprintf( '%s %.2f', $currency, (float) $amount );
    }
}
