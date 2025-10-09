<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\VendorTiers;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpoints for issuing and validating short-lived membership QR tokens.
 */
class MembershipQrEndpoints {
    const TRANSIENT_PREFIX = 'tcn_member_qr_';

    /**
     * @var TokenAuthenticator
     */
    protected $auth;

    public function __construct( TokenAuthenticator $auth ) {
        $this->auth = $auth;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/membership/qr',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'issue_qr_token' ),
                'permission_callback' => array( $this, 'require_login' ),
                'args'                => array(
                    'payload' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/membership/qr/validate',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'validate_qr_token' ),
                'permission_callback' => array( $this, 'require_login' ),
                'args'                => array(
                    'token' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );
    }

    public function require_login( WP_REST_Request $request ) {
        $user_id = $this->auth->authenticate_request( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( (int) $user_id <= 0 ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        // Ensure global current user is set for downstream helpers.
        if ( get_current_user_id() !== (int) $user_id ) {
            wp_set_current_user( (int) $user_id );
        }

        return true;
    }

    public function issue_qr_token( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        $payload = (string) $request->get_param( 'payload' );

        $issued_at = time();
        $ttl       = (int) apply_filters( 'tcn_membership_qr_ttl', 15 * MINUTE_IN_SECONDS, $user_id, $request );
        $expires   = $issued_at + max( 60, $ttl );

        $token = wp_generate_password( 48, false );
        $data  = array(
            'user_id'  => (int) $user_id,
            'payload'  => $payload,
            'iat'      => $issued_at,
            'exp'      => $expires,
        );

        set_transient( self::TRANSIENT_PREFIX . md5( $token ), $data, max( 60, $ttl ) );

        return array(
            'token'       => $token,
            'qr_payload'  => $payload,
            'issued_at'   => gmdate( 'c', $issued_at ),
            'expires_at'  => gmdate( 'c', $expires ),
        );
    }

    public function validate_qr_token( WP_REST_Request $request ) {
        $vendor_id = get_current_user_id();
        if ( ! $vendor_id ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        $token = (string) $request->get_param( 'token' );
        if ( '' === $token ) {
            return new WP_Error( 'gn_invalid_qr_token', __( 'A QR token is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $bundle = get_transient( self::TRANSIENT_PREFIX . md5( $token ) );
        if ( ! is_array( $bundle ) || empty( $bundle['user_id'] ) ) {
            return new WP_Error( 'gn_invalid_qr_token', __( 'The QR token is invalid or has expired.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $member_id = (int) $bundle['user_id'];
        $member    = get_user_by( 'id', $member_id );
        if ( ! $member ) {
            return new WP_Error( 'gn_user_not_found', __( 'Unable to locate the requested member.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        $level = (string) get_user_meta( $member_id, '_tcn_membership_level', true );
        if ( '' === $level ) {
            $level = 'blue';
        }

        $vendor_tier = (string) get_user_meta( $vendor_id, '_tcn_vendor_tier', true );
        if ( '' === $vendor_tier ) {
            $vendor_tier = 'sapphire';
        }

        $tiers     = VendorTiers::get_all();
        $discounts = isset( $tiers[ $vendor_tier ]['discounts'] ) ? (array) $tiers[ $vendor_tier ]['discounts'] : array();
        $allowed   = isset( $discounts[ $level ] ) ? (float) $discounts[ $level ] : 0.0;

        $response = array(
            'token'            => $token,
            'valid'            => true,
            'membership_tier'  => $level,
            'allowed_discount' => $allowed,
            'member'           => array(
                'id'               => $member->ID,
                'name'             => $member->display_name,
                'membership_tier'  => $level,
                'discount'         => $allowed,
            ),
            'membership'       => array(
                'tier' => $level,
            ),
        );

        return $response;
    }
}

