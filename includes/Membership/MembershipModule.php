<?php
namespace TCN\Platform\Membership;

use TCN\Platform\Support\Options;
use WP_REST_Request;
use WP_User;

class MembershipModule {
    const COMMISSION_TABLE = 'tcn_mlm_commissions';

    public function __construct( $modules = null ) {
        // The membership module is always enabled, but the constructor accepts the
        // service container argument for forward compatibility with the module
        // toggles introduced in the unified plugin.
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
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            )
        );

        register_rest_route(
            'tcn-mlm/v1',
            '/genealogy',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_genealogy' ),
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            )
        );

        register_rest_route(
            'tcn-mlm/v1',
            '/commissions',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_commissions' ),
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            )
        );
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
            $this->maybe_handle_auto_upgrade( $sponsor_id );
        }
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
            return;
        }

        $general = Options::get_general_settings();
        if ( ! empty( $general['default_sponsor'] ) ) {
            update_user_meta( $user_id, '_tcn_sponsor_id', (int) $general['default_sponsor'] );
            $this->update_direct_recruits( (int) $general['default_sponsor'] );
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
            $this->insert_commission( $sponsor_id, $member_id, $order_id, 'direct', (float) $levels[ $level_key ]['commission_direct'], $currency );
            $this->update_direct_recruits( $sponsor_id );
            $this->maybe_handle_auto_upgrade( $sponsor_id );

            $upline_id = (int) get_user_meta( $sponsor_id, '_tcn_sponsor_id', true );
            if ( $upline_id && ! empty( $levels[ $level_key ]['commission_passive'] ) ) {
                $this->insert_commission( $upline_id, $member_id, $order_id, 'passive', (float) $levels[ $level_key ]['commission_passive'], $currency );
            }
        }
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

    protected function maybe_handle_auto_upgrade( int $user_id ): void {
        $current = get_user_meta( $user_id, '_tcn_membership_level', true );
        $levels  = Options::get_levels();
        $recruits = (int) get_user_meta( $user_id, '_tcn_direct_recruits', true );

        if ( 'gold' === $current && $recruits >= 2 ) {
            $this->set_membership_level( $user_id, 'platinum' );
        } elseif ( 'platinum' === $current && $recruits >= 2 ) {
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
        $recruits = count( get_users( array(
            'fields'    => 'ids',
            'meta_key'  => '_tcn_sponsor_id',
            'meta_value'=> $user_id,
            'number'    => -1,
        ) ) );

        update_user_meta( $user_id, '_tcn_direct_recruits', (int) $recruits );
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

    protected function maybe_capture_sponsor(): void {
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

        $levels = Options::get_levels();

        foreach ( $levels as $key => $level ) {
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
