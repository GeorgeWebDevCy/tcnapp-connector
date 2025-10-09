<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Support\VendorTiers;
use WP_REST_Server;

/**
 * Public endpoints for vendor catalogue lookups.
 */
class VendorEndpoints {
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/vendors/tiers',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_tiers' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function get_tiers() {
        $catalogue = VendorTiers::get_all();

        $tiers = array();
        foreach ( $catalogue as $slug => $tier ) {
            $tiers[] = array(
                'slug'                => $slug,
                'name'                => isset( $tier['name'] ) ? (string) $tier['name'] : ucfirst( $slug ),
                // Provide both keys to maximise client compatibility.
                'discounts'           => isset( $tier['discounts'] ) ? (array) $tier['discounts'] : array(),
                'discountRates'       => isset( $tier['discounts'] ) ? (array) $tier['discounts'] : array(),
                'promotion_allowance' => isset( $tier['promotion_summary'] ) ? (string) $tier['promotion_summary'] : '',
                'promotionSummary'    => isset( $tier['promotion_summary'] ) ? (string) $tier['promotion_summary'] : '',
                'fees'                => isset( $tier['fees'] ) ? (string) $tier['fees'] : '',
                'benefits'            => isset( $tier['benefits'] ) ? (array) $tier['benefits'] : array(),
                'metadata'            => isset( $tier['metadata'] ) ? (array) $tier['metadata'] : array(),
            );
        }

        return array(
            'success' => true,
            'tiers'   => array_values( $tiers ),
        );
    }
}

