<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Discounts;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints that power discount validation and redemption flows.
 */
class DiscountEndpoints {
    /**
     * @var TokenAuthenticator
     */
    protected $token_authenticator;

    public function __construct( TokenAuthenticator $token_authenticator ) {
        $this->token_authenticator = $token_authenticator;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/discounts/lookup',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_lookup' ),
                'permission_callback' => array( $this, 'require_vendor_capability' ),
                'args'                => array(
                    'qr_token'  => array(
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'vendor_id' => array(
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/discounts/transactions',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_transaction' ),
                'permission_callback' => array( $this, 'require_vendor_capability' ),
                'args'                => array(
                    'qr_token'        => array(
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'member_id'       => array(
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'vendor_id'       => array(
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'gross_amount'    => array(
                        'type'     => 'number',
                        'required' => true,
                    ),
                    'discount_amount' => array(
                        'type'     => 'number',
                        'required' => true,
                    ),
                    'net_amount'      => array(
                        'type'     => 'number',
                        'required' => true,
                    ),
                    'currency'        => array(
                        'type'     => 'string',
                        'required' => false,
                    ),
                    'metadata'        => array(
                        'required' => false,
                    ),
                ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/discounts/history',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_history' ),
                'permission_callback' => array( $this, 'require_authenticated_user' ),
            )
        );
    }

    public function require_vendor_capability( WP_REST_Request $request ) {
        $user_id = $this->authenticate_request( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $user_id <= 0 ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        if ( user_can( $user_id, 'tcn_discount_redemptions' ) || user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        return new WP_Error( 'gn_rest_forbidden', __( 'You do not have permission to redeem discounts.', 'tcnapp-connector' ), array( 'status' => 403 ) );
    }

    public function require_authenticated_user( WP_REST_Request $request ) {
        $user_id = $this->authenticate_request( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $user_id <= 0 ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function handle_lookup( WP_REST_Request $request ) {
        $vendor_id = (int) $request->get_param( 'vendor_id' );
        if ( $vendor_id <= 0 ) {
            return new WP_Error( 'tcn_invalid_vendor', __( 'A valid vendor ID is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( ! $this->is_vendor_authorised_for_account( $vendor_id ) ) {
            return new WP_Error( 'gn_rest_forbidden', __( 'The authenticated user cannot redeem discounts for this vendor.', 'tcnapp-connector' ), array( 'status' => 403 ) );
        }

        $qr_token = sanitize_text_field( (string) $request->get_param( 'qr_token' ) );
        if ( '' === $qr_token ) {
            return new WP_Error( 'gn_invalid_discount_token', __( 'The provided discount token is invalid.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $token = Discounts::get_token( $qr_token );
        if ( ! $this->is_token_valid( $token ) ) {
            return new WP_Error( 'gn_invalid_discount_token', __( 'The provided discount token is invalid or has expired.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $member = $this->resolve_member_payload( (int) $token['member_id'] );
        if ( is_wp_error( $member ) ) {
            return $member;
        }

        if ( ! empty( $token['plan_tier'] ) ) {
            $member['plan_tier'] = $token['plan_tier'];
        }

        $usage = Discounts::get_usage( $token['token'], $vendor_id );
        $eligible = $this->is_usage_eligible( $token, $usage );
        if ( ! $eligible ) {
            return new WP_Error(
                'gn_discount_limit_reached',
                __( 'This discount token has reached its usage limit.', 'tcnapp-connector' ),
                array(
                    'status' => 409,
                    'usage'  => $usage,
                )
            );
        }

        $discount = array(
            'token'            => $token['token'],
            'label'            => $token['label'],
            'type'             => $token['type'],
            'value'            => $token['value'],
            'max_uses'         => $token['max_uses'],
            'max_uses_per_day' => $token['max_uses_per_day'],
            'expires_at'       => $token['expires_at'],
        );

        if ( ! empty( $token['metadata'] ) ) {
            $discount['metadata'] = $token['metadata'];
        }

        return array(
            'success'  => true,
            'member'   => $member,
            'discount' => $discount,
            'eligible' => true,
            'usage'    => $usage,
        );
    }

    public function handle_transaction( WP_REST_Request $request ) {
        $vendor_id = (int) $request->get_param( 'vendor_id' );
        $member_id = (int) $request->get_param( 'member_id' );

        if ( $vendor_id <= 0 ) {
            return new WP_Error( 'tcn_invalid_vendor', __( 'A valid vendor ID is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( ! $this->is_vendor_authorised_for_account( $vendor_id ) ) {
            return new WP_Error( 'gn_rest_forbidden', __( 'The authenticated user cannot redeem discounts for this vendor.', 'tcnapp-connector' ), array( 'status' => 403 ) );
        }

        if ( $member_id <= 0 ) {
            return new WP_Error( 'tcn_invalid_member', __( 'A valid member ID is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $qr_token = sanitize_text_field( (string) $request->get_param( 'qr_token' ) );
        if ( '' === $qr_token ) {
            return new WP_Error( 'gn_invalid_discount_token', __( 'The provided discount token is invalid.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $token = Discounts::get_token( $qr_token );
        if ( ! $this->is_token_valid( $token ) ) {
            return new WP_Error( 'gn_invalid_discount_token', __( 'The provided discount token is invalid or has expired.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $member = $this->resolve_member_payload( $member_id );
        if ( is_wp_error( $member ) ) {
            return $member;
        }

        if ( ! empty( $token['plan_tier'] ) ) {
            $member['plan_tier'] = $token['plan_tier'];
        }

        $usage = Discounts::get_usage( $token['token'], $vendor_id );
        if ( ! $this->is_usage_eligible( $token, $usage ) ) {
            return new WP_Error(
                'gn_discount_already_redeemed',
                __( 'This discount token has already been redeemed the maximum allowed number of times.', 'tcnapp-connector' ),
                array(
                    'status' => 409,
                    'usage'  => $usage,
                )
            );
        }

        $gross     = $this->extract_amount( $request->get_param( 'gross_amount' ) );
        $discount  = $this->extract_amount( $request->get_param( 'discount_amount' ) );
        $net       = $this->extract_amount( $request->get_param( 'net_amount' ) );
        $currency  = strtoupper( sanitize_text_field( (string) $request->get_param( 'currency' ) ) );
        $metadata  = $request->get_param( 'metadata' );

        if ( $gross <= 0 ) {
            return new WP_Error( 'gn_discount_amount_invalid', __( 'A valid gross amount is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( $discount < 0 ) {
            return new WP_Error( 'gn_discount_amount_invalid', __( 'The discount amount cannot be negative.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( $net < 0 ) {
            return new WP_Error( 'gn_discount_amount_invalid', __( 'The net amount cannot be negative.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( round( $gross - $discount, 2 ) !== round( $net, 2 ) ) {
            return new WP_Error( 'gn_discount_amount_invalid', __( 'The net amount must equal the gross amount minus the discount.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $metadata = is_array( $metadata ) ? $metadata : array();

        $transaction_id = Discounts::record_transaction(
            array(
                'member_id'       => $member_id,
                'vendor_id'       => $vendor_id,
                'plan_tier'       => $member['plan_tier'],
                'qr_token'        => $token['token'],
                'gross_amount'    => $gross,
                'discount_amount' => $discount,
                'net_amount'      => $net,
                'currency'        => $currency ?: $this->get_site_currency(),
                'metadata'        => $metadata,
            )
        );

        $transaction = array(
            'id'              => $transaction_id,
            'member_id'       => $member_id,
            'vendor_id'       => $vendor_id,
            'plan_tier'       => $member['plan_tier'],
            'gross_amount'    => $gross,
            'discount_amount' => $discount,
            'net_amount'      => $net,
            'currency'        => $currency ?: $this->get_site_currency(),
            'metadata'        => $metadata,
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        );

        return new WP_REST_Response(
            array(
                'success'     => true,
                'transaction' => $transaction,
            ),
            201
        );
    }

    public function handle_history( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $is_vendor = user_can( $user_id, 'tcn_discount_redemptions' ) || user_can( $user_id, 'manage_woocommerce' );
        $is_admin  = user_can( $user_id, 'manage_options' );

        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 25 ) );
        $member_id = (int) $request->get_param( 'member_id' );
        $vendor_id = (int) $request->get_param( 'vendor_id' );

        if ( $member_id && $member_id !== $user_id && ! $is_admin && ! $is_vendor ) {
            return new WP_Error( 'gn_rest_forbidden', __( 'You do not have permission to view discount history for this member.', 'tcnapp-connector' ), array( 'status' => 403 ) );
        }

        if ( $vendor_id && ! $is_admin && ! $is_vendor ) {
            return new WP_Error( 'gn_rest_forbidden', __( 'You do not have permission to view vendor discount history.', 'tcnapp-connector' ), array( 'status' => 403 ) );
        }

        if ( ! $member_id && ! $vendor_id ) {
            if ( $is_vendor ) {
                $vendor_id = $user_id;
            } else {
                $member_id = $user_id;
            }
        }

        $history = Discounts::get_history(
            array(
                'page'       => $page,
                'per_page'   => $per_page,
                'member_id'  => $member_id ?: null,
                'vendor_id'  => $vendor_id ?: null,
                'plan_tier'  => sanitize_text_field( (string) $request->get_param( 'plan_tier' ) ),
                'date_start' => $request->get_param( 'date_start' ),
                'date_end'   => $request->get_param( 'date_end' ),
            )
        );

        $transactions = array_map( array( $this, 'format_transaction_row' ), $history['rows'] );

        $response = new WP_REST_Response(
            array(
                'success' => true,
                'totals'  => $history['totals'],
                'transactions' => $transactions,
                'pagination'   => array(
                    'page'       => $history['page'],
                    'per_page'   => $history['per_page'],
                    'total_pages'=> $history['total_pages'],
                ),
            )
        );

        $response->header( 'X-WP-Total', (string) $history['total'] );
        $response->header( 'X-WP-TotalPages', (string) $history['total_pages'] );

        return $response;
    }

    protected function authenticate_request( WP_REST_Request $request ) {
        $user_id = $this->token_authenticator->authenticate_request( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        return (int) $user_id;
    }

    protected function is_vendor_authorised_for_account( int $vendor_id ): bool {
        $current = get_current_user_id();
        if ( $current === $vendor_id ) {
            return true;
        }

        if ( user_can( $current, 'manage_options' ) || user_can( $current, 'manage_woocommerce' ) ) {
            return true;
        }

        /**
         * Allow integrators to bypass the vendor ownership check.
         */
        return (bool) apply_filters( 'tcn_discount_allow_cross_vendor', false, $current, $vendor_id );
    }

    protected function is_token_valid( $token ): bool {
        if ( ! is_array( $token ) || empty( $token['token'] ) ) {
            return false;
        }

        if ( ! empty( $token['expires_at'] ) && strtotime( $token['expires_at'] ) && time() > strtotime( $token['expires_at'] ) ) {
            return false;
        }

        return (int) ( $token['member_id'] ?? 0 ) > 0;
    }

    protected function resolve_member_payload( int $member_id ) {
        $user = get_user_by( 'id', $member_id );
        if ( ! $user ) {
            return new WP_Error( 'gn_user_not_found', __( 'Unable to locate the requested member.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        $plan_tier = get_user_meta( $member_id, '_tcn_membership_level', true );

        return array(
            'id'          => $user->ID,
            'display_name'=> $user->display_name ?? '',
            'plan_tier'   => $plan_tier ?: '',
        );
    }

    protected function is_usage_eligible( array $token, array $usage ): bool {
        if ( ! empty( $token['max_uses'] ) && $usage['uses_total'] >= (int) $token['max_uses'] ) {
            return false;
        }

        if ( ! empty( $token['max_uses_per_day'] ) && $usage['uses_today'] >= (int) $token['max_uses_per_day'] ) {
            return false;
        }

        return true;
    }

    protected function extract_amount( $value ): float {
        if ( is_numeric( $value ) ) {
            return round( (float) $value, 2 );
        }

        return 0.0;
    }

    protected function format_transaction_row( array $row ): array {
        $metadata = array();
        if ( ! empty( $row['metadata'] ) ) {
            $decoded = json_decode( (string) $row['metadata'], true );
            if ( is_array( $decoded ) ) {
                $metadata = $decoded;
            }
        }

        return array(
            'id'              => (int) $row['id'],
            'member_id'       => (int) $row['member_id'],
            'vendor_id'       => (int) $row['vendor_id'],
            'plan_tier'       => $row['plan_tier'],
            'gross_amount'    => (float) $row['gross_amount'],
            'discount_amount' => (float) $row['discount_amount'],
            'net_amount'      => (float) $row['net_amount'],
            'currency'        => $row['currency'],
            'metadata'        => $metadata,
            'created_at'      => $row['created_at'],
        );
    }

    protected function get_site_currency(): string {
        $woocommerce_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        if ( $woocommerce_currency ) {
            return strtoupper( $woocommerce_currency );
        }

        return strtoupper( get_option( 'woocommerce_currency', 'USD' ) );
    }
}
