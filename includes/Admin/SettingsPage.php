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

        $level_settings = isset( $_POST['membership_levels'] ) && is_array( $_POST['membership_levels'] )
            ? wp_unslash( $_POST['membership_levels'] )
            : array();

        $this->persist_membership_levels( $level_settings );

        $login = array(
            'allowed_origin'    => isset( $_POST['allowed_origin'] ) ? esc_url_raw( wp_unslash( $_POST['allowed_origin'] ) ) : '',
            'allow_dev_http'    => ! empty( $_POST['allow_dev_http'] ),
            'token_lifetime'    => isset( $_POST['token_lifetime'] ) ? max( 60, absint( $_POST['token_lifetime'] ) ) : 15 * MINUTE_IN_SECONDS,
            'rate_limit'        => isset( $_POST['rate_limit'] ) ? max( 3, absint( $_POST['rate_limit'] ) ) : 10,
            'rate_limit_window' => isset( $_POST['rate_limit_window'] ) ? max( 60, absint( $_POST['rate_limit_window'] ) ) : 5 * MINUTE_IN_SECONDS,
        );

        Options::update_login_settings( $login );

        $general_settings = Options::get_general_settings();
        $general_settings['stripe_publishable_key'] = isset( $_POST['stripe_publishable_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['stripe_publishable_key'] ) )
            : '';
        $general_settings['stripe_secret_key'] = isset( $_POST['stripe_secret_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) )
            : '';

        $membership_products = array();
        if ( isset( $_POST['membership_products'] ) && is_array( $_POST['membership_products'] ) ) {
            $raw_products = wp_unslash( $_POST['membership_products'] );

            foreach ( Options::get_levels() as $level ) {
                if ( empty( $level['slug'] ) ) {
                    continue;
                }

                $slug       = sanitize_key( (string) $level['slug'] );
                $product_id = isset( $raw_products[ $slug ] ) ? absint( $raw_products[ $slug ] ) : 0;

                if ( $slug && $product_id > 0 ) {
                    $membership_products[ $slug ] = $product_id;
                }
            }
        }

        $general_settings['membership_products'] = $membership_products;

        Options::update_general_settings( $general_settings );
        $this->sync_membership_product_meta( $membership_products );

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
        $product_map    = Options::get_membership_product_map();

        if ( ! is_array( $levels ) ) {
            $levels = array();
        }
        $general        = Options::get_general_settings();
        $currency       = isset( $general['currency'] ) ? $general['currency'] : 'USD';
        $publishable    = isset( $general['stripe_publishable_key'] ) ? $general['stripe_publishable_key'] : '';
        $secret         = isset( $general['stripe_secret_key'] ) ? $general['stripe_secret_key'] : '';
        $product_options = $this->get_membership_product_options();

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

                <h2><?php esc_html_e( 'Mobile Payments', 'tcnapp-connector' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Provide your Stripe API keys so the mobile app can create payment intents during membership upgrades.', 'tcnapp-connector' ); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="stripe_publishable_key"><?php esc_html_e( 'Stripe Publishable Key', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="stripe_publishable_key" name="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $publishable ); ?>" placeholder="pk_live_..." />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="stripe_secret_key"><?php esc_html_e( 'Stripe Secret Key', 'tcnapp-connector' ); ?></label>
                            </th>
                            <td>
                                <input type="password" id="stripe_secret_key" name="stripe_secret_key" class="regular-text" value="<?php echo esc_attr( $secret ); ?>" placeholder="sk_live_..." />
                                <p class="description"><?php esc_html_e( 'Stored securely in WordPress and used server-side to talk to Stripe.', 'tcnapp-connector' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e( 'Membership Products', 'tcnapp-connector' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Select the WooCommerce products that correspond to each membership plan. These mappings ensure upgrades keep working even if product titles or slugs change.', 'tcnapp-connector' ); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php if ( empty( $product_options ) && ! function_exists( 'wc_get_products' ) ) : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Membership Products', 'tcnapp-connector' ); ?></th>
                                <td>
                                    <p class="description"><?php esc_html_e( 'Install and activate WooCommerce to assign products to membership levels.', 'tcnapp-connector' ); ?></p>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $levels as $level ) :
                                if ( empty( $level['slug'] ) ) {
                                    continue;
                                }

                                $slug      = sanitize_key( (string) $level['slug'] );
                                $selected  = isset( $product_map[ $slug ] ) ? (int) $product_map[ $slug ] : 0;
                                $label     = isset( $level['name'] ) ? (string) $level['name'] : $slug;
                                $options   = $product_options;

                                if ( $selected > 0 && ! isset( $options[ $selected ] ) ) {
                                    /* translators: %d: WooCommerce product ID */
                                    $options[ $selected ] = sprintf( __( 'Product #%d (not found)', 'tcnapp-connector' ), $selected );
                                }
                                ?>
                                <tr>
                                    <th scope="row">
                                        <label for="membership_products_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></label>
                                    </th>
                                    <td>
                                        <?php if ( empty( $options ) ) : ?>
                                            <p class="description"><?php esc_html_e( 'Create WooCommerce products to enable membership mapping.', 'tcnapp-connector' ); ?></p>
                                        <?php else : ?>
                                            <select id="membership_products_<?php echo esc_attr( $slug ); ?>" name="membership_products[<?php echo esc_attr( $slug ); ?>]">
                                                <?php foreach ( $options as $product_id => $product_label ) : ?>
                                                    <option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( $selected, $product_id ); ?>><?php echo esc_html( $product_label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e( 'Membership Commissions', 'tcnapp-connector' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Adjust the direct and passive commission amounts for each tier. These settings drive the payouts recorded when members recruit their network.', 'tcnapp-connector' ); ?></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ( $levels as $level ) :
                            if ( empty( $level['slug'] ) ) {
                                continue;
                            }

                            $slug        = sanitize_key( (string) $level['slug'] );
                            $level_name  = isset( $level['name'] ) ? (string) $level['name'] : $slug;
                            $direct_id   = 'membership_levels_' . $slug . '_direct';
                            $passive_id  = 'membership_levels_' . $slug . '_passive';
                            $direct_val  = isset( $level['commission_direct'] ) ? (float) $level['commission_direct'] : 0.0;
                            $passive_val = isset( $level['commission_passive'] ) ? (float) $level['commission_passive'] : 0.0;
                        ?>
                        <tr>
                            <th scope="row">
                                <span class="tcn-level-label"><?php echo esc_html( $level_name ); ?></span>
                            </th>
                            <td>
                                <fieldset>
                                    <label for="<?php echo esc_attr( $direct_id ); ?>" class="tcn-level-field">
                                        <?php esc_html_e( 'Direct commission', 'tcnapp-connector' ); ?>
                                        <input type="number" min="0" step="0.01" id="<?php echo esc_attr( $direct_id ); ?>" name="membership_levels[<?php echo esc_attr( $slug ); ?>][commission_direct]" value="<?php echo esc_attr( $direct_val ); ?>" class="small-text" />
                                    </label>
                                    <label for="<?php echo esc_attr( $passive_id ); ?>" class="tcn-level-field">
                                        <?php esc_html_e( 'Passive commission', 'tcnapp-connector' ); ?>
                                        <input type="number" min="0" step="0.01" id="<?php echo esc_attr( $passive_id ); ?>" name="membership_levels[<?php echo esc_attr( $slug ); ?>][commission_passive]" value="<?php echo esc_attr( $passive_val ); ?>" class="small-text" />
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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

    protected function persist_membership_levels( array $submitted ): void {
        $stored_levels = get_option( Options::OPTION_LEVELS, array() );
        if ( ! is_array( $stored_levels ) ) {
            $stored_levels = array();
        }

        $defaults = Options::default_levels();
        $updated  = array();

        foreach ( $defaults as $slug => $default_level ) {
            $slug = sanitize_key( (string) $slug );

            if ( '' === $slug ) {
                continue;
            }

            $existing = $stored_levels[ $slug ] ?? array();

            if ( is_string( $existing ) ) {
                $existing = array( 'name' => $existing );
            } elseif ( ! is_array( $existing ) ) {
                $existing = array();
            }

            $existing = wp_parse_args( $existing, $default_level );

            if ( isset( $submitted[ $slug ] ) && is_array( $submitted[ $slug ] ) ) {
                $input = $submitted[ $slug ];

                if ( array_key_exists( 'commission_direct', $input ) ) {
                    $existing['commission_direct'] = $this->parse_amount( $input['commission_direct'] );
                }

                if ( array_key_exists( 'commission_passive', $input ) ) {
                    $existing['commission_passive'] = $this->parse_amount( $input['commission_passive'] );
                }
            }

            $existing['slug'] = $slug;
            $updated[ $slug ] = $existing;
        }

        if ( ! empty( $updated ) ) {
            Options::update_levels( $updated );
        }
    }

    /**
     * Normalize numeric input from the settings form.
     *
     * @param mixed $value
     */
    protected function parse_amount( $value ): float {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        if ( is_string( $value ) ) {
            $value = sanitize_text_field( $value );

            if ( function_exists( 'wc_format_decimal' ) ) {
                $normalized = wc_format_decimal( $value, false, false );

                if ( is_numeric( $normalized ) ) {
                    return (float) $normalized;
                }
            }

            $value = preg_replace( '/[^0-9\.,-]/u', '', (string) $value );

            if ( '' === $value ) {
                return 0.0;
            }

            $thousand = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
            $decimal  = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';

            if ( '' !== $thousand ) {
                $value = str_replace( $thousand, '', $value );
            }

            if ( '.' !== $decimal && false !== strpos( $value, $decimal ) ) {
                $value = str_replace( $decimal, '.', $value );
            }

            $parts = explode( '.', $value );

            if ( count( $parts ) > 2 ) {
                $last  = array_pop( $parts );
                $value = implode( '', $parts ) . '.' . $last;
            }

            if ( is_numeric( $value ) ) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    /**
     * @return array<int, string>
     */
    protected function get_membership_product_options(): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $statuses = array();
        if ( function_exists( 'wc_get_product_statuses' ) ) {
            $statuses = array_keys( \wc_get_product_statuses() );
        }

        if ( empty( $statuses ) ) {
            $statuses = array( 'publish', 'pending', 'draft', 'private' );
        }

        $products = \wc_get_products(
            array(
                'limit'   => -1,
                'status'  => $statuses,
                'return'  => 'objects',
                'orderby' => 'title',
                'order'   => 'ASC',
            )
        );

        if ( empty( $products ) ) {
            return array();
        }

        $options = array( 0 => __( '— Select —', 'tcnapp-connector' ) );

        foreach ( $products as $product ) {
            $options[ $product->get_id() ] = sprintf(
                /* translators: 1: WooCommerce product name, 2: product ID */
                __( '%1$s (#%2$d)', 'tcnapp-connector' ),
                $product->get_name(),
                $product->get_id()
            );
        }

        return $options;
    }

    /**
     * Synchronize the `_tcn_membership_level` product meta with the saved mappings.
     *
     * @param array<string, int> $mapping
     */
    protected function sync_membership_product_meta( array $mapping ): void {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $normalized = array();
        foreach ( $mapping as $slug => $product_id ) {
            $slug       = sanitize_key( (string) $slug );
            $product_id = (int) $product_id;

            if ( '' === $slug || $product_id <= 0 ) {
                continue;
            }

            $normalized[ $slug ] = $product_id;
        }

        $reverse = array_flip( $normalized );

        $existing_products = wc_get_products(
            array(
                'limit'      => -1,
                'status'     => array( 'publish', 'pending', 'draft' ),
                'meta_query' => array(
                    array(
                        'key'     => '_tcn_membership_level',
                        'compare' => 'EXISTS',
                    ),
                ),
                'return'     => 'ids',
            )
        );

        if ( ! empty( $existing_products ) ) {
            foreach ( $existing_products as $product_id ) {
                $product_id = (int) $product_id;
                $assigned   = get_post_meta( $product_id, '_tcn_membership_level', true );

                if ( isset( $reverse[ $product_id ] ) ) {
                    $slug = $reverse[ $product_id ];

                    if ( $assigned !== $slug ) {
                        update_post_meta( $product_id, '_tcn_membership_level', $slug );
                    }

                    continue;
                }

                delete_post_meta( $product_id, '_tcn_membership_level' );
            }
        }

        foreach ( $normalized as $slug => $product_id ) {
            update_post_meta( $product_id, '_tcn_membership_level', $slug );
        }
    }
}
