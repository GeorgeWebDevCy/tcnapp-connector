<?php
namespace TCN\Platform\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class WooCommerceEndpoints {
    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/customers',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_customer' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'email' => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_email',
                            'validate_callback' => array( $this, 'validate_email' ),
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_customer' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'email'       => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_email',
                            'validate_callback' => array( $this, 'validate_email' ),
                        ),
                        'password'    => array(
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_text_field_nullable' ),
                        ),
                        'first_name'  => array(
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_text_field_nullable' ),
                        ),
                        'last_name'   => array(
                            'required'          => false,
                            'sanitize_callback' => array( $this, 'sanitize_text_field_nullable' ),
                        ),
                        'billing'     => array(
                            'required'          => false,
                            'validate_callback' => array( $this, 'validate_object_type' ),
                        ),
                        'shipping'    => array(
                            'required'          => false,
                            'validate_callback' => array( $this, 'validate_object_type' ),
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/orders',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_order' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'customer_id'    => array(
                            'required'          => false,
                            'validate_callback' => array( $this, 'validate_positive_int' ),
                        ),
                        'line_items'     => array(
                            'required'          => true,
                            'validate_callback' => array( $this, 'validate_object_type' ),
                        ),
                        'shipping_lines' => array(
                            'required'          => false,
                            'validate_callback' => array( $this, 'validate_object_type' ),
                        ),
                        'payment_method' => array(
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'set_paid'       => array(
                            'required'          => false,
                            'validate_callback' => array( $this, 'validate_boolean_value' ),
                        ),
                        'status'         => array(
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_key',
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Ensure the request is authenticated and authorized.
     */
    public function permissions_check( WP_REST_Request $request ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        $authenticated_user = $this->authenticate_with_consumer_keys( $request );

        if ( $authenticated_user > 0 ) {
            if ( user_can( $authenticated_user, 'manage_woocommerce' ) ) {
                return true;
            }

            return new WP_Error(
                'tcn_rest_forbidden',
                __( 'The API key provided does not have permission to manage WooCommerce customers.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        if ( is_user_logged_in() ) {
            return new WP_Error(
                'tcn_rest_forbidden',
                __( 'You do not have permission to manage WooCommerce customers.', 'tcnapp-connector' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return new WP_Error(
            'tcn_rest_unauthorized',
            __( 'Authentication is required to access this resource.', 'tcnapp-connector' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * Attempt to authenticate the request using WooCommerce REST API keys.
     */
    protected function authenticate_with_consumer_keys( WP_REST_Request $request ): int {
        if ( ! function_exists( 'wc_api_hash' ) ) {
            return 0;
        }

        global $wpdb;

        if ( ! $wpdb instanceof \wpdb ) {
            return 0;
        }

        $credentials = $this->extract_consumer_credentials( $request );

        if ( empty( $credentials['key'] ) || empty( $credentials['secret'] ) ) {
            return 0;
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $hash  = wc_api_hash( $credentials['key'] );

        $key_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, user_id, permissions, consumer_secret FROM {$table} WHERE consumer_key = %s",
                $hash
            )
        );

        if ( empty( $key_data ) ) {
            return 0;
        }

        $permissions = isset( $key_data->permissions ) ? strtolower( (string) $key_data->permissions ) : '';

        if ( ! in_array( $permissions, array( 'write', 'read_write' ), true ) ) {
            return 0;
        }

        $secret = isset( $key_data->consumer_secret ) ? (string) $key_data->consumer_secret : '';

        if ( ! $this->safe_hash_equals( $secret, $credentials['secret'] ) ) {
            return 0;
        }

        $user_id = isset( $key_data->user_id ) ? (int) $key_data->user_id : 0;

        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            return 0;
        }

        wp_set_current_user( $user_id );

        if ( isset( $key_data->key_id ) ) {
            $wpdb->update(
                $table,
                array( 'last_access' => current_time( 'mysql', true ) ),
                array( 'key_id' => (int) $key_data->key_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        return $user_id;
    }

    /**
     * Extract consumer key credentials from a REST request.
     */
    protected function extract_consumer_credentials( WP_REST_Request $request ): array {
        $key    = '';
        $secret = '';

        $header = $request->get_header( 'authorization' );

        if ( is_string( $header ) && stripos( $header, 'basic ' ) === 0 ) {
            $decoded = base64_decode( substr( $header, 6 ), true );

            if ( false !== $decoded ) {
                $parts  = explode( ':', $decoded, 2 );
                $key    = isset( $parts[0] ) ? trim( $parts[0] ) : '';
                $secret = isset( $parts[1] ) ? trim( $parts[1] ) : '';
            }
        }

        if ( '' === $key && '' === $secret ) {
            $param_key    = $request->get_param( 'consumer_key' );
            $param_secret = $request->get_param( 'consumer_secret' );

            if ( is_string( $param_key ) ) {
                $key = trim( wp_unslash( $param_key ) );
            }

            if ( is_string( $param_secret ) ) {
                $secret = trim( wp_unslash( $param_secret ) );
            }
        }

        return array(
            'key'    => $key,
            'secret' => $secret,
        );
    }

    /**
     * Compare two strings in a time-safe manner where possible.
     */
    protected function safe_hash_equals( string $expected, string $actual ): bool {
        if ( function_exists( 'hash_equals' ) ) {
            return hash_equals( $expected, $actual );
        }

        $expected_length = strlen( $expected );
        $actual_length   = strlen( $actual );

        if ( $expected_length !== $actual_length ) {
            return false;
        }

        $result = 0;

        for ( $i = 0; $i < $expected_length; $i++ ) {
            $result |= ord( $expected[ $i ] ) ^ ord( $actual[ $i ] );
        }

        return 0 === $result;
    }

    /**
     * Validate an email parameter.
     */
    public function validate_email( $value ): bool {
        return (bool) is_email( $value );
    }

    /**
     * Sanitize string values while allowing null.
     *
     * @param mixed $value Value to sanitize.
     */
    public function sanitize_text_field_nullable( $value ) {
        if ( null === $value ) {
            return null;
        }

        return sanitize_text_field( $value );
    }

    /**
     * Validate that the provided value is an array.
     *
     * @param mixed $value Value to validate.
     */
    public function validate_object_type( $value ): bool {
        return is_array( $value );
    }

    /**
     * Validate that the provided value is a positive integer.
     *
     * @param mixed $value Value to validate.
     */
    public function validate_positive_int( $value ): bool {
        return is_numeric( $value ) && (int) $value > 0;
    }

    /**
     * Validate boolean-like values.
     *
     * @param mixed $value Value to validate.
     */
    public function validate_boolean_value( $value ): bool {
        if ( is_bool( $value ) ) {
            return true;
        }

        if ( is_numeric( $value ) ) {
            return true;
        }

        if ( is_string( $value ) ) {
            $value = strtolower( $value );
            return in_array( $value, array( 'true', 'false', '1', '0' ), true );
        }

        return false;
    }

    /**
     * Handle GET requests for a WooCommerce customer.
     */
    public function get_customer( WP_REST_Request $request ) {
        $email = $request->get_param( 'email' );

        if ( empty( $email ) ) {
            return new WP_Error( 'tcn_missing_email', __( 'The email parameter is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $customer_id = wc_get_customer_id_by_email( $email );

        if ( ! $customer_id ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $customer_id = $user->ID;
            }
        }

        if ( ! $customer_id ) {
            return new WP_Error( 'tcn_customer_not_found', __( 'Customer not found.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        return new WP_REST_Response( $this->normalize_customer_data( $customer_id ) );
    }

    /**
     * Handle POST requests to create a WooCommerce customer.
     */
    public function create_customer( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }

        $email     = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $password  = isset( $params['password'] ) ? (string) $params['password'] : '';
        $first     = isset( $params['first_name'] ) ? sanitize_text_field( $params['first_name'] ) : '';
        $last      = isset( $params['last_name'] ) ? sanitize_text_field( $params['last_name'] ) : '';
        $billing   = isset( $params['billing'] ) && is_array( $params['billing'] ) ? $params['billing'] : array();
        $shipping  = isset( $params['shipping'] ) && is_array( $params['shipping'] ) ? $params['shipping'] : array();

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'tcn_invalid_email', __( 'A valid email address is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'tcn_customer_exists', __( 'A customer with this email already exists.', 'tcnapp-connector' ), array( 'status' => 409 ) );
        }

        if ( empty( $password ) ) {
            $password = wp_generate_password( 24, true );
        }

        $user_id = wc_create_new_customer( $email, '', $password );

        if ( is_wp_error( $user_id ) ) {
            $user_id->add_data( array( 'status' => 400 ) );
            return $user_id;
        }

        $userdata = array( 'ID' => $user_id );

        if ( $first ) {
            $userdata['first_name'] = $first;
        }

        if ( $last ) {
            $userdata['last_name'] = $last;
        }

        if ( count( $userdata ) > 1 ) {
            wp_update_user( $userdata );
        }

        $this->update_customer_meta( $user_id, 'billing', $billing );
        $this->update_customer_meta( $user_id, 'shipping', $shipping );

        return new WP_REST_Response( $this->normalize_customer_data( $user_id ), 201 );
    }

    /**
     * Normalize the customer data structure.
     */
    protected function normalize_customer_data( int $user_id ): array {
        $user = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            return array();
        }

        return array(
            'id'        => $user->ID,
            'email'     => $user->user_email,
            'first_name'=> get_user_meta( $user->ID, 'first_name', true ),
            'last_name' => get_user_meta( $user->ID, 'last_name', true ),
            'billing'   => $this->collect_address_fields( $user->ID, 'billing' ),
            'shipping'  => $this->collect_address_fields( $user->ID, 'shipping' ),
        );
    }

    /**
     * Update customer billing or shipping meta fields.
     */
    protected function update_customer_meta( int $user_id, string $type, array $data ): void {
        $allowed_fields = array(
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
            'email',
            'phone',
        );

        foreach ( $allowed_fields as $field ) {
            if ( ! array_key_exists( $field, $data ) ) {
                continue;
            }

            $value = $data[ $field ];

            if ( 'email' === $field ) {
                $value = sanitize_email( (string) $value );
            } else {
                $value = sanitize_text_field( (string) $value );
            }

            update_user_meta( $user_id, $type . '_' . $field, $value );
        }
    }

    /**
     * Collect customer meta fields for output.
     */
    protected function collect_address_fields( int $user_id, string $type ): array {
        $fields = array(
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
            'email',
            'phone',
        );

        $data = array();

        foreach ( $fields as $field ) {
            $data[ $field ] = get_user_meta( $user_id, $type . '_' . $field, true );
        }

        return $data;
    }

    /**
     * Handle POST requests to create a WooCommerce order.
     */
    public function create_order( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }

        $customer_id    = isset( $params['customer_id'] ) ? (int) $params['customer_id'] : 0;
        $line_items     = isset( $params['line_items'] ) && is_array( $params['line_items'] ) ? $params['line_items'] : array();
        $shipping_lines = isset( $params['shipping_lines'] ) && is_array( $params['shipping_lines'] ) ? $params['shipping_lines'] : array();
        $payment_method = isset( $params['payment_method'] ) ? sanitize_text_field( (string) $params['payment_method'] ) : '';
        $set_paid       = isset( $params['set_paid'] ) ? wc_string_to_bool( $params['set_paid'] ) : true;
        $status         = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'completed';

        if ( empty( $line_items ) ) {
            return new WP_Error( 'tcn_missing_line_items', __( 'At least one line item is required to create an order.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( $customer_id > 0 && ! get_user_by( 'id', $customer_id ) ) {
            return new WP_Error( 'tcn_invalid_customer', __( 'The specified customer could not be found.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $order_args = array();

        if ( $customer_id > 0 ) {
            $order_args['customer_id'] = $customer_id;
        }

        $order = wc_create_order( $order_args );

        if ( is_wp_error( $order ) ) {
            $order->add_data( array( 'status' => 400 ) );
            return $order;
        }

        foreach ( $line_items as $item ) {
            $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $quantity   = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;

            if ( $product_id <= 0 ) {
                wc_delete_order( $order->get_id() );
                return new WP_Error( 'tcn_missing_product_id', __( 'Each line item must include a valid product_id.', 'tcnapp-connector' ), array( 'status' => 400 ) );
            }

            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                wc_delete_order( $order->get_id() );
                return new WP_Error( 'tcn_invalid_product', __( 'One or more products could not be found.', 'tcnapp-connector' ), array( 'status' => 400 ) );
            }

            $order->add_product( $product, $quantity );
        }

        foreach ( $shipping_lines as $shipping_line ) {
            if ( ! is_array( $shipping_line ) ) {
                continue;
            }

            $shipping_item = new \WC_Order_Item_Shipping();

            if ( isset( $shipping_line['method_id'] ) ) {
                $shipping_item->set_method_id( sanitize_text_field( (string) $shipping_line['method_id'] ) );
            }

            if ( isset( $shipping_line['method_title'] ) ) {
                $shipping_item->set_method_title( sanitize_text_field( (string) $shipping_line['method_title'] ) );
            }

            if ( isset( $shipping_line['total'] ) ) {
                $shipping_item->set_total( wc_format_decimal( $shipping_line['total'] ) );
            }

            if ( isset( $shipping_line['total_tax'] ) ) {
                $shipping_item->set_total_tax( wc_format_decimal( $shipping_line['total_tax'] ) );
            }

            if ( isset( $shipping_line['taxes'] ) && is_array( $shipping_line['taxes'] ) ) {
                $shipping_item->set_taxes( $shipping_line['taxes'] );
            }

            $order->add_item( $shipping_item );
        }

        if ( $payment_method ) {
            $order->set_payment_method( $payment_method );
        }

        $order->calculate_totals();

        if ( $set_paid ) {
            if ( method_exists( $order, 'payment_complete' ) ) {
                $order->payment_complete();
            } else {
                $order->set_paid( true );
            }
        }

        $order->set_status( $status ? $status : 'completed' );
        $order->save();

        return new WP_REST_Response(
            array(
                'id'   => $order->get_id(),
                'data' => $order->get_data(),
            ),
            201
        );
    }
}
