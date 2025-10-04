<?php
namespace TCN\Platform\Support;

class Options {
    const OPTION_LEVELS         = 'tcn_mlm_levels';
    const OPTION_GENERAL        = 'tcn_mlm_general';
    const OPTION_LOGIN_SETTINGS = 'gn_login_api_settings';

    public static function ensure_defaults(): void {
        if ( ! get_option( self::OPTION_LEVELS ) ) {
            update_option( self::OPTION_LEVELS, self::default_levels() );
        }

        if ( ! get_option( self::OPTION_GENERAL ) ) {
            update_option( self::OPTION_GENERAL, self::default_general_settings() );
        }

        if ( ! get_option( self::OPTION_LOGIN_SETTINGS ) ) {
            update_option( self::OPTION_LOGIN_SETTINGS, self::default_login_settings() );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_levels(): array {
        $levels = get_option( self::OPTION_LEVELS, array() );
        if ( ! is_array( $levels ) ) {
            $levels = array();
        }

        $defaults = self::default_levels();

        $normalized = array();
        foreach ( $levels as $key => $level ) {
            if ( is_string( $level ) ) {
                $level = array( 'name' => $level );
            } elseif ( ! is_array( $level ) ) {
                $level = array();
            }

            $slug = '';
            if ( isset( $level['slug'] ) && is_string( $level['slug'] ) ) {
                $slug = sanitize_key( $level['slug'] );
            } elseif ( is_string( $key ) && '' !== $key ) {
                $slug = sanitize_key( $key );
            }

            if ( '' === $slug ) {
                continue;
            }

            $base = $defaults[ $slug ] ?? array(
                'name'               => '',
                'slug'               => $slug,
                'rank'               => 0,
                'fee'                => 0,
                'commission_direct'  => 0,
                'commission_passive' => 0,
                'benefits'           => array(),
            );

            $level['slug']     = $slug;
            $normalized[ $slug ] = wp_parse_args( $level, $base );
        }

        $levels = array_replace( $defaults, $normalized );

        // Only expose the official tiers even if legacy data introduces stray keys.
        $levels = array_intersect_key( $levels, $defaults );

        return self::apply_membership_product_fees( $levels );
    }

    public static function update_levels( array $levels ): void {
        update_option( self::OPTION_LEVELS, $levels );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_general_settings(): array {
        $settings = get_option( self::OPTION_GENERAL, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, self::default_general_settings() );
    }

    public static function update_general_settings( array $settings ): void {
        update_option( self::OPTION_GENERAL, $settings );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_login_settings(): array {
        $settings = get_option( self::OPTION_LOGIN_SETTINGS, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return wp_parse_args( $settings, self::default_login_settings() );
    }

    public static function update_login_settings( array $settings ): void {
        update_option( self::OPTION_LOGIN_SETTINGS, wp_parse_args( $settings, self::default_login_settings() ) );
    }

    /**
     * Overlay membership fees from WooCommerce products so the admin summary
     * reflects the current catalogue configuration.
     *
     * @param array<string, array<string, mixed>> $levels
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function apply_membership_product_fees( array $levels ): array {
        if ( empty( $levels ) || ! function_exists( 'wc_get_products' ) ) {
            return $levels;
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
            )
        );

        if ( empty( $products ) ) {
            return $levels;
        }

        foreach ( $products as $product ) {
            $level_key = $product->get_meta( '_tcn_membership_level' );

            if ( ! is_string( $level_key ) ) {
                continue;
            }

            $level_key = sanitize_key( $level_key );

            if ( '' === $level_key || ! isset( $levels[ $level_key ] ) ) {
                continue;
            }

            $price = $product->get_price( 'edit' );

            if ( '' === $price ) {
                $price = $product->get_regular_price( 'edit' );
            }

            if ( '' === $price ) {
                $price = 0;
            }

            if ( function_exists( 'wc_format_decimal' ) ) {
                $price = wc_format_decimal( $price );
            }

            $levels[ $level_key ]['fee'] = (float) $price;
        }

        return $levels;
    }

    /**
     * Default membership level configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function default_levels(): array {
        return array(
            'blue'     => array(
                'name'               => __( 'Blue', 'tcnapp-connector' ),
                'slug'               => 'blue',
                'rank'               => 0,
                'fee'                => 0,
                'commission_direct'  => 0,
                'commission_passive' => 0,
                'benefits'           => array( __( 'Basic customer account', 'tcnapp-connector' ) ),
            ),
            'gold'     => array(
                'name'               => __( 'Gold', 'tcnapp-connector' ),
                'slug'               => 'gold',
                'rank'               => 1,
                'fee'                => 500,
                'commission_direct'  => 125,
                'commission_passive' => 125,
                'commission_direct_overrides' => array(
                    'platinum' => 250,
                    'black'    => 250,
                ),
                'benefits'           => array(
                    __( 'Earn THB125 on each direct recruit', 'tcnapp-connector' ),
                    __( 'Unlock passive income after two recruits', 'tcnapp-connector' ),
                ),
            ),
            'platinum' => array(
                'name'               => __( 'Platinum', 'tcnapp-connector' ),
                'slug'               => 'platinum',
                'rank'               => 2,
                'fee'                => 1200,
                'commission_direct'  => 250,
                'commission_passive' => 125,
                'commission_direct_overrides' => array(
                    'black' => 250,
                ),
                'benefits'           => array(
                    __( 'Earn THB250 on each direct recruit', 'tcnapp-connector' ),
                    __( 'Passive commissions continue from first downline level', 'tcnapp-connector' ),
                ),
            ),
            'black'    => array(
                'name'               => __( 'Black', 'tcnapp-connector' ),
                'slug'               => 'black',
                'rank'               => 3,
                'fee'                => 2000,
                'commission_direct'  => 250,
                'commission_passive' => 125,
                'benefits'           => array(
                    __( 'Leadership status with the highest renewal fee', 'tcnapp-connector' ),
                    __( 'Continues earning THB125 from downline activity', 'tcnapp-connector' ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function default_general_settings(): array {
        return array(
            'currency'        => 'THB',
            'default_sponsor' => 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function default_login_settings(): array {
        return array(
            'allowed_origin'    => '',
            'allow_dev_http'    => false,
            'token_lifetime'    => 15 * MINUTE_IN_SECONDS,
            'rate_limit'        => 10,
            'rate_limit_window' => 5 * MINUTE_IN_SECONDS,
        );
    }
}
