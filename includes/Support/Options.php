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
