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

        return wp_parse_args( $levels, self::default_levels() );
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
                'fee'                => 149,
                'commission_direct'  => 50,
                'commission_passive' => 10,
                'benefits'           => array(
                    __( 'Earn direct commissions on recruits', 'tcnapp-connector' ),
                    __( 'Eligible for passive commissions after two direct recruits', 'tcnapp-connector' ),
                ),
            ),
            'platinum' => array(
                'name'               => __( 'Platinum', 'tcnapp-connector' ),
                'slug'               => 'platinum',
                'rank'               => 2,
                'fee'                => 399,
                'commission_direct'  => 80,
                'commission_passive' => 20,
                'benefits'           => array(
                    __( 'Higher direct commissions', 'tcnapp-connector' ),
                    __( 'Passive commissions from first level downline', 'tcnapp-connector' ),
                ),
            ),
            'black'    => array(
                'name'               => __( 'Black', 'tcnapp-connector' ),
                'slug'               => 'black',
                'rank'               => 3,
                'fee'                => 899,
                'commission_direct'  => 120,
                'commission_passive' => 40,
                'benefits'           => array(
                    __( 'Maximum commission tier', 'tcnapp-connector' ),
                    __( 'Priority support and leadership resources', 'tcnapp-connector' ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function default_general_settings(): array {
        return array(
            'currency'        => 'USD',
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
