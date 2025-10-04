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
    }

    /**
     * Ensure the request is authenticated and authorized.
     */
    public function permissions_check( WP_REST_Request $request ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
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
}
