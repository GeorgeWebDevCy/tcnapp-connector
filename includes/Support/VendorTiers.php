<?php
namespace TCN\Platform\Support;

/**
 * Stores and exposes the vendor tier catalogue used by the mobile app.
 */
class VendorTiers {
    const OPTION_KEY = 'tcn_vendor_tiers';

    /**
     * Ensure default tiers exist.
     */
    public static function ensure_defaults(): void {
        if ( get_option( self::OPTION_KEY, null ) === null ) {
            update_option( self::OPTION_KEY, self::defaults() );
        }
    }

    /**
     * Retrieve normalised tier catalogue.
     *
     * @return array<string, array<string, mixed>> keyed by slug
     */
    public static function get_all(): array {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $tiers = array();

        foreach ( $stored as $key => $tier ) {
            if ( is_string( $tier ) ) {
                // Minimal entry; interpret as name and derive slug from key
                $tier = array( 'name' => $tier );
            } elseif ( ! is_array( $tier ) ) {
                $tier = array();
            }

            $slug = '';
            if ( isset( $tier['slug'] ) && is_string( $tier['slug'] ) ) {
                $slug = sanitize_key( $tier['slug'] );
            } elseif ( is_string( $key ) && '' !== $key ) {
                $slug = sanitize_key( $key );
            }

            if ( '' === $slug ) {
                continue;
            }

            $base = array(
                'slug'               => $slug,
                'name'               => ucfirst( $slug ),
                'discounts'          => array(),
                'promotion_summary'  => '',
                'benefits'           => array(),
                'fees'               => '',
                'metadata'           => array(),
            );

            $tier['slug'] = $slug;

            // Normalise numeric discount values to floats.
            if ( isset( $tier['discounts'] ) && is_array( $tier['discounts'] ) ) {
                foreach ( $tier['discounts'] as $m => $val ) {
                    $tier['discounts'][ $m ] = is_numeric( $val ) ? (float) $val : 0.0;
                }
            } else {
                $tier['discounts'] = array();
            }

            if ( isset( $tier['benefits'] ) && is_array( $tier['benefits'] ) ) {
                $tier['benefits'] = array_values( array_filter( array_map( 'strval', $tier['benefits'] ) ) );
            } else {
                $tier['benefits'] = array();
            }

            if ( isset( $tier['metadata'] ) && is_array( $tier['metadata'] ) ) {
                // leave as-is
            } else {
                $tier['metadata'] = array();
            }

            $tiers[ $slug ] = wp_parse_args( $tier, $base );
        }

        // Overlay defaults so required keys are present.
        $tiers = array_replace( self::defaults(), $tiers );

        return $tiers;
    }

    /**
     * Get a single tier by slug.
     */
    public static function get( string $slug ): ?array {
        $slug  = sanitize_key( $slug );
        $tiers = self::get_all();
        return $tiers[ $slug ] ?? null;
    }

    /**
     * Replace the full catalogue.
     *
     * @param array<string, array<string, mixed>> $tiers
     */
    public static function set_all( array $tiers ): void {
        update_option( self::OPTION_KEY, $tiers );
    }

    /**
     * Default tier catalogue (Sapphire and Diamond).
     *
     * @return array<string, array<string, mixed>> keyed by slug
     */
    public static function defaults(): array {
        return array(
            'sapphire' => array(
                'slug'              => 'sapphire',
                'name'              => __( 'Sapphire', 'tcnapp-connector' ),
                'discounts'         => array(
                    'gold'     => 0.025,
                    'platinum' => 0.05,
                    'black'    => 0.10,
                ),
                'promotion_summary' => __( '1 per quarter', 'tcnapp-connector' ),
                'fees'              => '฿0',
                'benefits'          => array(
                    __( 'Quarterly free promotion', 'tcnapp-connector' ),
                    __( 'Member-facing discount defaults', 'tcnapp-connector' ),
                ),
                'metadata'          => array(),
            ),
            'diamond' => array(
                'slug'              => 'diamond',
                'name'              => __( 'Diamond', 'tcnapp-connector' ),
                'discounts'         => array(
                    'gold'     => 0.05,
                    'platinum' => 0.10,
                    'black'    => 0.20,
                ),
                'promotion_summary' => __( '1 per month', 'tcnapp-connector' ),
                'fees'              => '฿0',
                'benefits'          => array(
                    __( 'Monthly free promotion', 'tcnapp-connector' ),
                    __( 'Premium placement in member newsletters', 'tcnapp-connector' ),
                ),
                'metadata'          => array(),
            ),
        );
    }
}

