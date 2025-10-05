<?php
namespace TCN\Platform\Membership;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Options;
use TCN\Platform\Support\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_User;

class MembershipModule {
    const COMMISSION_TABLE = 'tcn_mlm_commissions';

    /**
     * @var TokenAuthenticator|null
     */
    protected $token_authenticator;

    public function __construct( $modules = null, ?TokenAuthenticator $token_authenticator = null ) {
        // The membership module is always enabled, but the constructor accepts the
        // service container argument for forward compatibility with the module
        // toggles introduced in the unified plugin.
        $this->token_authenticator = $token_authenticator;
    }

    public static function activate(): void {
        global $wpdb;

        $table   = $wpdb->prefix . self::COMMISSION_TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sponsor_id bigint(20) unsigned NOT NULL,
            member_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT 0,
            level varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0,
            currency char(3) NOT NULL DEFAULT 'USD',
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY sponsor_id (sponsor_id),
            KEY member_id (member_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        self::maybe_seed_products();
    }

    public function register(): void {
        add_action( 'init', array( $this, 'maybe_capture_sponsor' ) );
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'user_register', array( $this, 'assign_sponsor_from_cookie' ) );
        add_action( 'gn_password_api_user_registered', array( $this, 'handle_api_registration' ), 20, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

        if ( function_exists( 'add_rewrite_endpoint' ) ) {
            add_action( 'init', array( $this, 'register_account_endpoints' ) );
        }

        if ( function_exists( 'wc_get_order' ) ) {
            add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 20, 1 );
            add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_level_field' ) );
            add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_level_field' ) );
            add_filter( 'woocommerce_get_query_vars', array( $this, 'register_account_query_vars' ) );
            add_filter( 'woocommerce_account_menu_items', array( $this, 'register_account_menu_items' ) );
            add_action( 'woocommerce_account_tcn-member-dashboard_endpoint', array( $this, 'render_member_dashboard_endpoint' ) );
            add_action( 'woocommerce_account_tcn-genealogy_endpoint', array( $this, 'render_genealogy_endpoint' ) );
        }
    }

    public function enqueue_public_assets(): void {
        $should_enqueue = function_exists( 'is_account_page' ) && is_account_page();

        if ( ! $should_enqueue ) {
            global $post;
            if ( $post instanceof \WP_Post ) {
                $content = $post->post_content;
                $should_enqueue = has_shortcode( $content, 'tcn_member_dashboard' ) || has_shortcode( $content, 'tcn_genealogy' ) || has_shortcode( $content, 'tcn_mlm_optin' );
            }
        }

        if ( ! $should_enqueue ) {
            return;
        }

        wp_enqueue_style(
            'tcn-platform-public',
            TCN_PLATFORM_PLUGIN_URL . 'public/css/tcn-platform.css',
            array(),
            TCN_PLATFORM_VERSION
        );
    }

    public function register_shortcodes(): void {
        add_shortcode( 'tcn_member_dashboard', array( $this, 'render_member_dashboard_shortcode' ) );
        add_shortcode( 'tcn_genealogy', array( $this, 'render_genealogy_shortcode' ) );
        add_shortcode( 'tcn_mlm_optin', array( $this, 'render_optin_shortcode' ) );
    }

    public function register_rest_routes(): void {
        register_rest_route(
            'tcn-mlm/v1',
            '/member',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_member_profile' ),
                'permission_callback' => array( $this, 'rest_require_login' ),
            )
        );

        register_rest_route(
            'tcn-mlm/v1',
            '/genealogy',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_genealogy' ),
                'permission_callback' => array( $this, 'rest_require_login' ),
            )
        );

        register_rest_route(
            'tcn-mlm/v1',
            '/commissions',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_commissions' ),
                'permission_callback' => array( $this, 'rest_require_login' ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/memberships/plans',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get_membership_plans' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/memberships/stripe-intent',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_create_stripe_intent' ),
                'permission_callback' => array( $this, 'rest_require_login' ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/memberships/confirm',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_confirm_membership_upgrade' ),
                'permission_callback' => array( $this, 'rest_require_login' ),
            )
        );
    }

    public function handle_api_registration( int $user_id, WP_REST_Request $request ): void {
        $this->ensure_sponsor_assignment( $user_id );

        if ( ! get_user_meta( $user_id, '_tcn_membership_level', true ) ) {
            $this->set_membership_level( $user_id, 'blue' );
        }

        $this->ensure_customer_role( $user_id );
        $this->maybe_create_welcome_order( $user_id, $request );
    }

    public function register_account_endpoints(): void {
        add_rewrite_endpoint( 'tcn-member-dashboard', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'tcn-genealogy', EP_ROOT | EP_PAGES );
    }

    public function register_account_query_vars( array $vars ): array {
        $vars['tcn-member-dashboard'] = 'tcn-member-dashboard';
        $vars['tcn-genealogy']        = 'tcn-genealogy';
        return $vars;
    }

    public function register_account_menu_items( array $items ): array {
        $items['tcn-member-dashboard'] = __( 'MLM Dashboard', 'tcnapp-connector' );
        $items['tcn-genealogy']        = __( 'MLM Genealogy', 'tcnapp-connector' );
        return $items;
    }

    public function render_member_dashboard_endpoint(): void {
        echo $this->render_member_dashboard();
    }

    public function render_genealogy_endpoint(): void {
        echo $this->render_genealogy();
    }

    public function render_member_dashboard_shortcode(): string {
        return $this->render_member_dashboard();
    }

    public function render_genealogy_shortcode(): string {
        return $this->render_genealogy();
    }

    public function render_optin_shortcode(): string {
        ob_start();
        ?>
        <div class="tcn-mlm-optin">
            <h3><?php esc_html_e( 'Join the TCN Network', 'tcnapp-connector' ); ?></h3>
            <p><?php esc_html_e( 'Opt in to receive updates and unlock referral rewards across the TCN platform.', 'tcnapp-connector' ); ?></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    protected function render_member_dashboard(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Log in to view your membership dashboard.', 'tcnapp-connector' ) . '</p>';
        }

        $user_id    = get_current_user_id();
        $levels     = Options::get_levels();
        $level_key  = get_user_meta( $user_id, '_tcn_membership_level', true ) ?: 'blue';
        $level      = isset( $levels[ $level_key ] ) ? $levels[ $level_key ] : $levels['blue'];
        $recruits   = (int) get_user_meta( $user_id, '_tcn_direct_recruits', true );
        $sponsor_id = (int) get_user_meta( $user_id, '_tcn_sponsor_id', true );
        $sponsor    = $sponsor_id ? get_userdata( $sponsor_id ) : null;
        $comm       = $this->get_commission_summary( $user_id );
        $ledger     = $this->get_commission_ledger( $user_id );

        ob_start();
        ?>
        <div class="tcn-member-dashboard">
            <div class="tcn-member-summary">
                <h2><?php echo esc_html( $level['name'] ); ?></h2>
                <p><?php esc_html_e( 'Current Membership Level', 'tcnapp-connector' ); ?></p>
                <ul>
                    <?php foreach ( (array) $level['benefits'] as $benefit ) : ?>
                        <li><?php echo esc_html( $benefit ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="tcn-member-stats">
                <p><strong><?php esc_html_e( 'Direct Recruits', 'tcnapp-connector' ); ?>:</strong> <?php echo esc_html( $recruits ); ?></p>
                <p><strong><?php esc_html_e( 'Sponsor', 'tcnapp-connector' ); ?>:</strong> <?php echo esc_html( $sponsor ? $sponsor->display_name : __( 'Unassigned', 'tcnapp-connector' ) ); ?></p>
                <p><strong><?php esc_html_e( 'Total Earned', 'tcnapp-connector' ); ?>:</strong> <?php echo esc_html( $this->format_currency( $comm['total'] ) ); ?></p>
                <p><strong><?php esc_html_e( 'Pending Payouts', 'tcnapp-connector' ); ?>:</strong> <?php echo esc_html( $this->format_currency( $comm['pending'] ) ); ?></p>
                <p><strong><?php esc_html_e( 'Paid Out', 'tcnapp-connector' ); ?>:</strong> <?php echo esc_html( $this->format_currency( $comm['paid'] ) ); ?></p>
            </div>
            <div class="tcn-member-ledger">
                <h3><?php esc_html_e( 'Recent Commissions', 'tcnapp-connector' ); ?></h3>
                <?php if ( empty( $ledger ) ) : ?>
                    <p><?php esc_html_e( 'No commissions recorded yet.', 'tcnapp-connector' ); ?></p>
                <?php else : ?>
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'tcnapp-connector' ); ?></th>
                                <th><?php esc_html_e( 'Member', 'tcnapp-connector' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'tcnapp-connector' ); ?></th>
                                <th><?php esc_html_e( 'Amount', 'tcnapp-connector' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ledger as $row ) : ?>
                                <?php $member = get_userdata( $row['member_id'] ); ?>
                                <tr>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['created_at'] ) ) ); ?></td>
                                    <td><?php echo esc_html( $member ? $member->display_name : '#' . $row['member_id'] ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $row['level'] ) ); ?></td>
                                    <td><?php echo esc_html( $this->format_currency( $row['amount'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    protected function render_genealogy(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Log in to view your genealogy.', 'tcnapp-connector' ) . '</p>';
        }

        $tree = $this->build_genealogy_tree( get_current_user_id(), 3 );
        if ( empty( $tree ) ) {
            return '<p>' . esc_html__( 'Genealogy data is not available yet.', 'tcnapp-connector' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="tcn-genealogy-tree">
            <?php echo $this->render_genealogy_node( $tree ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    protected function render_genealogy_node( array $node ): string {
        $output = '<ul class="tcn-genealogy-node">';
        $output .= '<li>';        
        $output .= '<div class="tcn-genealogy-card">';
        $output .= '<strong>' . esc_html( $node['name'] ) . '</strong>';        
        $output .= '<span class="tcn-genealogy-level">' . esc_html( strtoupper( $node['level'] ) ) . '</span>';
        $output .= '<span class="tcn-genealogy-count">' . esc_html( sprintf( __( '%d recruits', 'tcnapp-connector' ), (int) $node['recruits'] ) ) . '</span>';
        $output .= '</div>';

        if ( ! empty( $node['children'] ) ) {
            $output .= '<ul class="tcn-genealogy-children">';
            foreach ( $node['children'] as $child ) {
                $output .= $this->render_genealogy_node( $child );
            }
            $output .= '</ul>';
        }

        $output .= '</li>';
        $output .= '</ul>';

        return $output;
    }

    public function rest_get_member_profile( WP_REST_Request $request ) {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return array();
        }

        return array(
            'user'        => $this->prepare_member_payload( $user ),
            'commissions' => $this->get_commission_summary( $user->ID ),
            'ledger'      => $this->get_commission_ledger( $user->ID, 50 ),
        );
    }

    public function rest_get_genealogy( WP_REST_Request $request ) {
        $depth = (int) $request->get_param( 'depth' );
        $depth = max( 1, min( 5, $depth ?: 3 ) );

        return $this->build_genealogy_tree( get_current_user_id(), $depth );
    }

    public function rest_get_commissions( WP_REST_Request $request ): array {
        return array(
            'summary' => $this->get_commission_summary( get_current_user_id() ),
            'ledger'  => $this->get_commission_ledger( get_current_user_id(), 100 ),
        );
    }

    public function rest_require_login( WP_REST_Request $request ) {
        $authenticated = $this->authenticate_with_token( $request );
        if ( is_wp_error( $authenticated ) ) {
            return $authenticated;
        }

        if ( $authenticated > 0 || is_user_logged_in() ) {
            return true;
        }

        return new WP_Error(
            'tcn_rest_unauthorized',
            __( 'Authentication is required to access this resource.', 'tcnapp-connector' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    protected function authenticate_with_token( WP_REST_Request $request ) {
        if ( ! $this->token_authenticator ) {
            return 0;
        }

        return $this->token_authenticator->authenticate_request( $request );
    }

    public function rest_get_membership_plans( WP_REST_Request $request ): array {
        $levels      = Options::get_levels();
        $general     = Options::get_general_settings();
        $currency    = isset( $general['currency'] ) ? $general['currency'] : 'USD';
        $publishable = isset( $general['stripe_publishable_key'] ) ? $general['stripe_publishable_key'] : '';

        $products = $this->get_membership_products();
        $plans    = array();

        foreach ( $levels as $key => $level ) {
            if ( ! is_array( $level ) ) {
                continue;
            }

            $benefits = array();
            foreach ( isset( $level['benefits'] ) ? (array) $level['benefits'] : array() as $benefit ) {
                if ( ! is_string( $benefit ) || '' === trim( $benefit ) ) {
                    continue;
                }
                $benefits[] = wp_strip_all_tags( $benefit );
            }

            $fee = isset( $level['fee'] ) ? (float) $level['fee'] : 0.0;

            $plan = array(
                'id'                 => $key,
                'name'               => isset( $level['name'] ) ? (string) $level['name'] : '',
                'fee'                => $fee,
                'price'              => $fee,
                'formatted_fee'      => $this->format_currency( $fee ),
                'currency'           => $currency,
                'benefits'           => $benefits,
                'commission_direct'  => isset( $level['commission_direct'] ) ? (float) $level['commission_direct'] : 0.0,
                'commission_passive' => isset( $level['commission_passive'] ) ? (float) $level['commission_passive'] : 0.0,
                'product_id'         => $this->get_membership_product_id( (string) $key, $products ),
            );

            $plan['amount_minor']      = (int) round( $fee * 100 );
            $plan['requires_payment'] = $fee > 0;

            $plans[] = $plan;
        }

        return array(
            'currency'          => $currency,
            'publishableKey'    => $publishable,
            'publishable_key'   => $publishable,
            'plans'             => array_values( $plans ),
        );
    }

    public function rest_create_stripe_intent( WP_REST_Request $request ) {
        $plan    = sanitize_key( $request->get_param( 'plan' ) );
        $user_id = get_current_user_id();
        if ( '' === $plan ) {
            return new WP_Error( 'invalid_plan', __( 'A membership plan is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $levels = Options::get_levels();
        if ( empty( $levels[ $plan ] ) || ! is_array( $levels[ $plan ] ) ) {
            return new WP_Error( 'unknown_plan', __( 'The requested membership plan could not be found.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        $general    = Options::get_general_settings();
        $secret_key = isset( $general['stripe_secret_key'] ) ? trim( $general['stripe_secret_key'] ) : '';
        if ( '' === $secret_key ) {
            return new WP_Error( 'stripe_not_configured', __( 'Stripe API keys are not configured.', 'tcnapp-connector' ), array( 'status' => 500 ) );
        }

        $amount   = isset( $levels[ $plan ]['fee'] ) ? (float) $levels[ $plan ]['fee'] : 0.0;
        $currency = isset( $general['currency'] ) ? strtolower( $general['currency'] ) : 'usd';

        if ( $amount <= 0 ) {
            return array(
                'plan'              => $plan,
                'requires_payment' => false,
            );
        }

        $intent = $this->stripe_request(
            'POST',
            'payment_intents',
            array(
                'amount'                             => (int) round( $amount * 100 ),
                'currency'                           => sanitize_key( $currency ),
                'description'                        => sprintf( __( 'TCN membership upgrade (%s)', 'tcnapp-connector' ), $levels[ $plan ]['name'] ?? $plan ),
                'automatic_payment_methods[enabled]' => 'true',
                'metadata[plan]'                     => $plan,
                'metadata[user_id]'                  => $user_id,
            ),
            $secret_key
        );

        if ( is_wp_error( $intent ) ) {
            return $intent;
        }

        return $intent;
    }

    public function rest_confirm_membership_upgrade( WP_REST_Request $request ) {
        $plan = sanitize_key( $request->get_param( 'plan' ) );
        if ( '' === $plan ) {
            return new WP_Error( 'invalid_plan', __( 'A membership plan is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $levels = Options::get_levels();
        if ( empty( $levels[ $plan ] ) || ! is_array( $levels[ $plan ] ) ) {
            return new WP_Error( 'unknown_plan', __( 'The requested membership plan could not be found.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        $general     = Options::get_general_settings();
        $secret_key  = isset( $general['stripe_secret_key'] ) ? trim( $general['stripe_secret_key'] ) : '';
        $expected    = isset( $levels[ $plan ]['fee'] ) ? (float) $levels[ $plan ]['fee'] : 0.0;
        $currency    = isset( $general['currency'] ) ? strtolower( $general['currency'] ) : 'usd';
        $intent_id   = sanitize_text_field( $request->get_param( 'payment_intent' ) );
        $user_id     = get_current_user_id();

        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        if ( $expected > 0 && '' === $secret_key ) {
            return new WP_Error( 'stripe_not_configured', __( 'Stripe API keys are not configured.', 'tcnapp-connector' ), array( 'status' => 500 ) );
        }

        if ( $expected > 0 ) {
            if ( '' === $intent_id ) {
                return new WP_Error( 'missing_intent', __( 'A Stripe payment intent is required for this upgrade.', 'tcnapp-connector' ), array( 'status' => 400 ) );
            }

            $intent = $this->stripe_request( 'GET', 'payment_intents/' . rawurlencode( $intent_id ), array(), $secret_key );
            if ( is_wp_error( $intent ) ) {
                return $intent;
            }

            $status = isset( $intent['status'] ) ? $intent['status'] : '';
            if ( ! in_array( $status, array( 'succeeded', 'processing', 'requires_capture' ), true ) ) {
                return new WP_Error( 'intent_incomplete', __( 'The payment intent has not completed successfully.', 'tcnapp-connector' ), array( 'status' => 409 ) );
            }

            $amount_received = isset( $intent['amount_received'] ) ? (int) $intent['amount_received'] : null;
            if ( null === $amount_received ) {
                $amount_received = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
            }

            $expected_minor = (int) round( $expected * 100 );
            if ( $expected_minor > 0 && $amount_received < $expected_minor ) {
                return new WP_Error( 'intent_amount_mismatch', __( 'The payment amount did not match the membership fee.', 'tcnapp-connector' ), array( 'status' => 409 ) );
            }

            if ( ! empty( $intent['currency'] ) && sanitize_key( $intent['currency'] ) !== sanitize_key( $currency ) ) {
                return new WP_Error( 'intent_currency_mismatch', __( 'The payment currency did not match the site configuration.', 'tcnapp-connector' ), array( 'status' => 409 ) );
            }

            if ( ! empty( $intent['metadata']['plan'] ) && sanitize_key( $intent['metadata']['plan'] ) !== $plan ) {
                return new WP_Error( 'intent_plan_mismatch', __( 'The payment intent does not belong to this membership plan.', 'tcnapp-connector' ), array( 'status' => 409 ) );
            }
        }

        $this->ensure_sponsor_assignment( $user_id );
        $this->set_membership_level( $user_id, $plan );
        $this->record_commissions( $user_id, 0, $plan );

        return array(
            'success' => true,
            'level'   => $plan,
        );
    }

    /**
     * @return array<string, int>
     */
    protected function get_membership_products(): array {
        $mapping   = Options::get_membership_product_map();
        $validated = array();

        if ( ! empty( $mapping ) ) {
            foreach ( $mapping as $slug => $product_id ) {
                $product_id = (int) $product_id;

                if ( $product_id <= 0 ) {
                    continue;
                }

                if ( function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $product_id );

                    if ( ! $product ) {
                        continue;
                    }
                } elseif ( ! get_post( $product_id ) ) {
                    continue;
                }

                $validated[ $slug ] = $product_id;
            }

            if ( ! empty( $validated ) ) {
                return $validated;
            }
        }

        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $products = wc_get_products(
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

        if ( empty( $products ) ) {
            return array();
        }

        $mapping = array();
        foreach ( $products as $product_id ) {
            $level = get_post_meta( $product_id, '_tcn_membership_level', true );
            if ( ! $level ) {
                continue;
            }

            $level = sanitize_key( (string) $level );
            if ( '' === $level ) {
                continue;
            }

            if ( ! isset( $mapping[ $level ] ) ) {
                $mapping[ $level ] = (int) $product_id;
            }
        }

        return $mapping;
    }

    /**
     * Perform a REST request to the Stripe API.
     *
     * @param string               $method HTTP method.
     * @param string               $path   Endpoint path relative to /v1/.
     * @param array<string, mixed> $body   Request body.
     * @param string               $secret Secret key.
     *
     * @return array<string, mixed>|WP_Error
     */
    protected function stripe_request( string $method, string $path, array $body, string $secret ) {
        $url = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );

        $args = array(
            'method'  => strtoupper( $method ),
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret,
            ),
            'timeout' => 20,
        );

        if ( ! empty( $body ) ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'stripe_request_failed', __( 'Unable to contact Stripe.', 'tcnapp-connector' ), array( 'status' => 502 ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Stripe rejected the request.', 'tcnapp-connector' );
            return new WP_Error( 'stripe_error', $message, array( 'status' => $code ?: 500 ) );
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'stripe_error', __( 'Unexpected response from Stripe.', 'tcnapp-connector' ), array( 'status' => 500 ) );
        }

        return $data;
    }

    public function handle_order_completed( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return;
        }

        $levels = Options::get_levels();
        $target = null;

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $level      = get_post_meta( $product_id, '_tcn_membership_level', true );

            if ( $level && isset( $levels[ $level ] ) ) {
                if ( ! $target || $levels[ $level ]['rank'] > $levels[ $target ]['rank'] ) {
                    $target = $level;
                }
            }
        }

        if ( ! $target ) {
            return;
        }

        $this->ensure_sponsor_assignment( $user_id );
        $this->set_membership_level( $user_id, $target );
        $this->record_commissions( $user_id, $order_id, $target );
    }

    public function render_product_level_field(): void {
        woocommerce_wp_select(
            array(
                'id'          => '_tcn_membership_level',
                'label'       => __( 'TCN Membership Level', 'tcnapp-connector' ),
                'description' => __( 'Associate this product with a membership level. Completing an order containing it will promote the buyer.', 'tcnapp-connector' ),
                'options'     => $this->get_level_options(),
                'desc_tip'    => true,
                'value'       => get_post_meta( get_the_ID(), '_tcn_membership_level', true ),
            )
        );
    }

    public function save_product_level_field( int $product_id ): void {
        if ( isset( $_POST['_tcn_membership_level'] ) ) {
            $level = sanitize_key( wp_unslash( $_POST['_tcn_membership_level'] ) );
            if ( array_key_exists( $level, Options::get_levels() ) ) {
                update_post_meta( $product_id, '_tcn_membership_level', $level );
            } else {
                delete_post_meta( $product_id, '_tcn_membership_level' );
            }
        }
    }

    public function assign_sponsor_from_cookie( int $user_id ): void {
        $sponsor_id = $this->get_sponsor_cookie();
        if ( $sponsor_id ) {
            update_user_meta( $user_id, '_tcn_sponsor_id', $sponsor_id );
            $this->update_direct_recruits( $sponsor_id );
            $this->maybe_handle_ancestor_upgrades( $sponsor_id );
        }
    }

    protected function ensure_customer_role( int $user_id ): void {
        $user = get_user_by( 'id', $user_id );

        if ( ! $user instanceof WP_User ) {
            return;
        }

        Roles::maybe_assign_app_user_role( $user );

        if ( ! in_array( 'customer', $user->roles, true ) ) {
            $user->add_role( 'customer' );
        }

        if ( in_array( 'subscriber', $user->roles, true ) ) {
            if ( count( $user->roles ) === 1 ) {
                $user->set_role( 'customer' );
            } else {
                $user->remove_role( 'subscriber' );
            }
        }
    }

    protected function maybe_create_welcome_order( int $user_id, WP_REST_Request $request ): void {
        if ( get_user_meta( $user_id, '_tcn_welcome_order_id', true ) ) {
            return;
        }

        if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product_id = $this->get_membership_product_id( 'blue' );

        if ( $product_id <= 0 ) {
            return;
        }

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return;
        }

        $order = wc_create_order( array( 'customer_id' => $user_id ) );

        if ( ! $order || is_wp_error( $order ) ) {
            return;
        }

        $order->add_product( $product, 1 );

        $user = get_user_by( 'id', $user_id );

        $first_name = $user instanceof WP_User ? $user->first_name : (string) $request->get_param( 'first_name' );
        $last_name  = $user instanceof WP_User ? $user->last_name : (string) $request->get_param( 'last_name' );
        $email      = $user instanceof WP_User ? $user->user_email : (string) $request->get_param( 'email' );

        $billing = array(
            'first_name' => sanitize_text_field( $first_name ),
            'last_name'  => sanitize_text_field( $last_name ),
            'email'      => sanitize_email( $email ),
        );

        $billing = array_filter( $billing );

        if ( ! empty( $billing ) ) {
            $order->set_address(
                array_merge(
                    array(
                        'first_name' => '',
                        'last_name'  => '',
                        'email'      => '',
                    ),
                    $billing
                ),
                'billing'
            );

            $shipping = array(
                'first_name' => isset( $billing['first_name'] ) ? $billing['first_name'] : '',
                'last_name'  => isset( $billing['last_name'] ) ? $billing['last_name'] : '',
            );

            if ( ! empty( array_filter( $shipping ) ) ) {
                $order->set_address(
                    array_merge(
                        array(
                            'first_name' => '',
                            'last_name'  => '',
                        ),
                        $shipping
                    ),
                    'shipping'
                );
            }
        }

        $order->set_created_via( 'tcn-register' );
        $order->update_meta_data( '_tcn_membership_level', 'blue' );
        $order->update_meta_data( '_tcn_membership_autogenerated', '1' );

        $order->calculate_totals();
        $order->save();

        $order->update_status(
            'completed',
            __( 'Automatically completed after API registration.', 'tcnapp-connector' )
        );

        update_user_meta( $user_id, '_tcn_welcome_order_id', $order->get_id() );
    }

    /**
     * Retrieve the WooCommerce product ID associated with a membership level.
     */
    protected function get_membership_product_id( string $level, ?array $products = null ): int {
        $level = sanitize_key( $level );

        if ( '' === $level ) {
            return 0;
        }

        if ( null === $products ) {
            $products = $this->get_membership_products();
        }

        if ( isset( $products[ $level ] ) ) {
            return (int) $products[ $level ];
        }

        $fallback_slugs = array(
            'blue'     => 'blue-membership',
            'gold'     => 'gold-membership',
            'platinum' => 'platinum-membership',
            'black'    => 'black-membership',
        );

        if ( isset( $fallback_slugs[ $level ] ) ) {
            $fallback = $this->get_product_id_by_slug( $fallback_slugs[ $level ] );

            if ( $fallback > 0 ) {
                return $fallback;
            }
        }

        return 0;
    }

    /**
     * Attempt to locate a product by its slug.
     */
    protected function get_product_id_by_slug( string $slug ): int {
        $slug = sanitize_title( $slug );

        if ( '' === $slug ) {
            return 0;
        }

        $product = get_page_by_path( $slug, OBJECT, array( 'product' ) );

        if ( $product instanceof \WP_Post ) {
            return (int) $product->ID;
        }

        return 0;
    }

    protected function ensure_sponsor_assignment( int $user_id ): void {
        $sponsor_id = (int) get_user_meta( $user_id, '_tcn_sponsor_id', true );
        if ( $sponsor_id ) {
            return;
        }

        $cookie = $this->get_sponsor_cookie();
        if ( $cookie ) {
            update_user_meta( $user_id, '_tcn_sponsor_id', $cookie );
            $this->update_direct_recruits( $cookie );
            $this->maybe_handle_ancestor_upgrades( $cookie );
            return;
        }

        $general = Options::get_general_settings();
        if ( ! empty( $general['default_sponsor'] ) ) {
            update_user_meta( $user_id, '_tcn_sponsor_id', (int) $general['default_sponsor'] );
            $this->update_direct_recruits( (int) $general['default_sponsor'] );
            $this->maybe_handle_ancestor_upgrades( (int) $general['default_sponsor'] );
        }
    }

    protected function record_commissions( int $member_id, int $order_id, string $level_key ): void {
        $levels   = Options::get_levels();
        $general  = Options::get_general_settings();
        $currency = isset( $general['currency'] ) ? $general['currency'] : 'USD';

        if ( empty( $levels[ $level_key ] ) ) {
            return;
        }

        $sponsor_id = (int) get_user_meta( $member_id, '_tcn_sponsor_id', true );
        if ( $sponsor_id ) {
            $direct_amount = $this->calculate_direct_commission_amount( $level_key, $sponsor_id, $levels );
            if ( $direct_amount > 0 ) {
                $this->insert_commission( $sponsor_id, $member_id, $order_id, 'direct', $direct_amount, $currency );
            }
            $this->update_direct_recruits( $sponsor_id );
            $this->maybe_handle_ancestor_upgrades( $sponsor_id );

            $upline_id = (int) get_user_meta( $sponsor_id, '_tcn_sponsor_id', true );
            $passive_amount = isset( $levels[ $level_key ]['commission_passive'] ) ? (float) $levels[ $level_key ]['commission_passive'] : 0.0;
            if ( $upline_id && $passive_amount > 0 ) {
                $this->insert_commission( $upline_id, $member_id, $order_id, 'passive', $passive_amount, $currency );
            }
        }
    }

    protected function calculate_direct_commission_amount( string $level_key, int $sponsor_id, array $levels ): float {
        if ( empty( $levels[ $level_key ] ) ) {
            return 0.0;
        }

        $level         = $levels[ $level_key ];
        $base_amount   = isset( $level['commission_direct'] ) ? (float) $level['commission_direct'] : 0.0;
        $sponsor_level = get_user_meta( $sponsor_id, '_tcn_membership_level', true );
        if ( ! $sponsor_level ) {
            $sponsor_level = 'blue';
        }

        if ( ! empty( $level['commission_direct_overrides'] ) && is_array( $level['commission_direct_overrides'] ) ) {
            if ( isset( $level['commission_direct_overrides'][ $sponsor_level ] ) ) {
                $base_amount = (float) $level['commission_direct_overrides'][ $sponsor_level ];
            }
        }

        return (float) apply_filters( 'tcn_mlm_direct_commission_amount', $base_amount, $level_key, $sponsor_id, $sponsor_level, $levels );
    }

    protected function insert_commission( int $sponsor_id, int $member_id, int $order_id, string $level, float $amount, string $currency ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::COMMISSION_TABLE,
            array(
                'sponsor_id' => $sponsor_id,
                'member_id'  => $member_id,
                'order_id'   => $order_id,
                'level'      => $level,
                'amount'     => $amount,
                'currency'   => $currency,
                'status'     => 'pending',
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s' )
        );
    }

    protected function maybe_handle_ancestor_upgrades( int $user_id ): void {
        $current = $user_id;
        $visited = array();

        while ( $current && ! in_array( $current, $visited, true ) ) {
            $visited[] = $current;
            $this->maybe_handle_auto_upgrade( $current );
            $current = (int) get_user_meta( $current, '_tcn_sponsor_id', true );
        }
    }

    protected function maybe_handle_auto_upgrade( int $user_id ): void {
        $current = get_user_meta( $user_id, '_tcn_membership_level', true );
        $recruits = (int) get_user_meta( $user_id, '_tcn_direct_recruits', true );
        $network  = (int) get_user_meta( $user_id, '_tcn_network_size', true );

        if ( 'gold' === $current && $recruits >= 2 ) {
            $this->set_membership_level( $user_id, 'platinum' );
        } elseif ( 'platinum' === $current && $network >= 2 ) {
            $this->set_membership_level( $user_id, 'black' );
        } elseif ( empty( $current ) ) {
            $this->set_membership_level( $user_id, 'blue' );
        }
    }

    protected function set_membership_level( int $user_id, string $level ): void {
        $levels = Options::get_levels();
        if ( ! isset( $levels[ $level ] ) ) {
            return;
        }

        $current = get_user_meta( $user_id, '_tcn_membership_level', true );
        if ( $current === $level ) {
            return;
        }

        update_user_meta( $user_id, '_tcn_membership_level', $level );
        do_action( 'tcn_mlm_user_level_changed', $user_id, $current, $level );
    }

    protected function update_direct_recruits( int $user_id ): void {
        if ( $user_id <= 0 ) {
            return;
        }

        $this->refresh_network_metrics( $user_id, array() );
    }

    protected function refresh_network_metrics( int $user_id, array $visited ): void {
        if ( $user_id <= 0 || in_array( $user_id, $visited, true ) ) {
            return;
        }

        $visited[] = $user_id;

        $recruits = count( get_users( array(
            'fields'     => 'ids',
            'meta_key'   => '_tcn_sponsor_id',
            'meta_value' => $user_id,
            'number'     => -1,
        ) ) );

        update_user_meta( $user_id, '_tcn_direct_recruits', (int) $recruits );
        update_user_meta( $user_id, '_tcn_network_size', (int) $this->count_network_members( $user_id ) );

        $sponsor_id = (int) get_user_meta( $user_id, '_tcn_sponsor_id', true );
        if ( $sponsor_id > 0 ) {
            $this->refresh_network_metrics( $sponsor_id, $visited );
        }
    }

    protected function count_network_members( int $user_id, array $visited = array() ): int {
        if ( $user_id <= 0 || in_array( $user_id, $visited, true ) ) {
            return 0;
        }

        $visited[] = $user_id;

        $children = get_users( array(
            'fields'     => 'ids',
            'meta_key'   => '_tcn_sponsor_id',
            'meta_value' => $user_id,
            'number'     => -1,
        ) );

        $count = 0;

        foreach ( $children as $child_id ) {
            $child_id = (int) $child_id;
            if ( in_array( $child_id, $visited, true ) ) {
                continue;
            }

            $count++;
            $count += $this->count_network_members( $child_id, $visited );
        }

        return $count;
    }

    protected function build_genealogy_tree( int $user_id, int $depth ): array {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return array();
        }

        $level   = get_user_meta( $user_id, '_tcn_membership_level', true ) ?: 'blue';
        $node    = array(
            'id'       => $user_id,
            'name'     => $user->display_name,
            'level'    => $level,
            'recruits' => (int) get_user_meta( $user_id, '_tcn_direct_recruits', true ),
            'children' => array(),
        );

        if ( $depth > 1 ) {
            $children = get_users( array(
                'fields'    => 'ids',
                'meta_key'  => '_tcn_sponsor_id',
                'meta_value'=> $user_id,
                'number'    => -1,
            ) );

            foreach ( $children as $child_id ) {
                $node['children'][] = $this->build_genealogy_tree( (int) $child_id, $depth - 1 );
            }
        }

        return $node;
    }

    protected function get_commission_summary( int $user_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::COMMISSION_TABLE;
        $total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$table} WHERE sponsor_id = %d", $user_id ) );
        $paid  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$table} WHERE sponsor_id = %d AND status = 'paid'", $user_id ) );
        $pending = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$table} WHERE sponsor_id = %d AND status = 'pending'", $user_id ) );

        return array(
            'total'   => $total,
            'paid'    => $paid,
            'pending' => $pending,
        );
    }

    protected function get_commission_ledger( int $user_id, int $limit = 25 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::COMMISSION_TABLE;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sponsor_id, member_id, level, amount, status, created_at FROM {$table} WHERE sponsor_id = %d ORDER BY created_at DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );
    }

    protected function prepare_member_payload( WP_User $user ): array {
        return array(
            'id'               => $user->ID,
            'display_name'     => $user->display_name,
            'email'            => $user->user_email,
            'membership_level' => get_user_meta( $user->ID, '_tcn_membership_level', true ),
            'direct_recruits'  => (int) get_user_meta( $user->ID, '_tcn_direct_recruits', true ),
            'sponsor_id'       => (int) get_user_meta( $user->ID, '_tcn_sponsor_id', true ),
        );
    }

    public function maybe_capture_sponsor(): void {
        if ( headers_sent() ) {
            return;
        }

        $sponsor = isset( $_GET['tcn_sponsor'] ) ? absint( $_GET['tcn_sponsor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $sponsor <= 0 ) {
            return;
        }

        setcookie( 'tcn_sponsor', (string) $sponsor, time() + MONTH_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE['tcn_sponsor'] = (string) $sponsor;
    }

    protected function get_sponsor_cookie(): int {
        return isset( $_COOKIE['tcn_sponsor'] ) ? absint( $_COOKIE['tcn_sponsor'] ) : 0;
    }

    protected function format_currency( float $amount ): string {
        $general  = Options::get_general_settings();
        $currency = isset( $general['currency'] ) ? $general['currency'] : 'USD';

        if ( function_exists( 'wc_price' ) ) {
            return wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );
        }

        return sprintf( '%s %.2f', $currency, $amount );
    }

    protected function get_level_options(): array {
        $options = array( '' => __( '— None —', 'tcnapp-connector' ) );
        foreach ( Options::get_levels() as $key => $level ) {
            $options[ $key ] = $level['name'];
        }
        return $options;
    }

    protected static function maybe_seed_products(): void {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $levels         = Options::get_levels();
        $default_levels = Options::default_levels();

        foreach ( $default_levels as $key => $level_defaults ) {
            $level = $levels[ $key ] ?? $level_defaults;

            if ( ! is_array( $level ) ) {
                $level = $level_defaults;
            } else {
                $level = wp_parse_args( $level, $level_defaults );
            }

            $existing = get_posts( array(
                'post_type'      => 'product',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_tcn_membership_level',
                'meta_value'     => $key,
            ) );

            if ( ! empty( $existing ) ) {
                continue;
            }

            $product_id = wp_insert_post(
                array(
                    'post_title'   => sprintf( __( '%s Membership', 'tcnapp-connector' ), $level['name'] ),
                    'post_status'  => 'publish',
                    'post_type'    => 'product',
                    'post_excerpt' => __( 'Auto-generated TCN membership level product.', 'tcnapp-connector' ),
                )
            );

            if ( $product_id ) {
                update_post_meta( $product_id, '_tcn_membership_level', $key );
                update_post_meta( $product_id, '_price', $level['fee'] );
                update_post_meta( $product_id, '_regular_price', $level['fee'] );
                update_post_meta( $product_id, '_virtual', 'yes' );
                update_post_meta( $product_id, '_sold_individually', 'yes' );
            }
        }
    }
}
