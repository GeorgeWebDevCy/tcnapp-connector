<?php
namespace TCN\Platform\Rest;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Accounts;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_User;
use WP_User_Query;

/**
 * REST endpoints that expose admin-only directory management tools.
 */
class AdminDirectoryEndpoints {
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
            'tcn/v1',
            '/admin/accounts',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_accounts' ),
                'permission_callback' => array( $this, 'require_admin_capability' ),
                'args'                => array(
                    'page' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'per_page' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'tcn/v1',
            '/admin/vendors/(?P<id>\d+)/approve',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'approve_vendor' ),
                'permission_callback' => array( $this, 'require_admin_capability' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => array( $this, 'validate_positive_int' ),
                    ),
                ),
            )
        );

        register_rest_route(
            'tcn/v1',
            '/admin/vendors/(?P<id>\d+)/reject',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'reject_vendor' ),
                'permission_callback' => array( $this, 'require_admin_capability' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => array( $this, 'validate_positive_int' ),
                    ),
                    'reason' => array(
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ),
                ),
            )
        );
    }

    public function require_admin_capability( WP_REST_Request $request ) {
        $user_id = $this->resolve_user( $request );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $user_id <= 0 ) {
            return new WP_Error( 'tcn_rest_unauthorized', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        return new WP_Error( 'gn_rest_forbidden', __( 'You do not have permission to manage accounts.', 'tcnapp-connector' ), array( 'status' => 403 ) );
    }

    public function get_accounts( WP_REST_Request $request ) {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = (int) $request->get_param( 'per_page' );
        if ( $per_page <= 0 ) {
            $per_page = 25;
        }
        $per_page = min( 100, $per_page );

        $query = new WP_User_Query(
            array(
                'number'      => $per_page,
                'offset'      => ( $page - 1 ) * $per_page,
                'orderby'     => 'registered',
                'order'       => 'DESC',
                'count_total' => true,
            )
        );

        $users = array();

        foreach ( $query->get_results() as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }

            Accounts::ensure_defaults( $user->ID );
            $snapshot = Accounts::get_account_snapshot( $user->ID );

            $account = array(
                'id'               => $user->ID,
                'username'         => $user->user_login,
                'email'            => $user->user_email,
                'display_name'     => $user->display_name,
                'first_name'       => $user->first_name,
                'last_name'        => $user->last_name,
                'roles'            => array_values( $user->roles ),
                'registered_at'    => $user->user_registered,
                'membership_level' => get_user_meta( $user->ID, '_tcn_membership_level', true ),
                'account_type'     => $snapshot['account_type'],
                'account_status'   => $snapshot['account_status'],
                'vendor_status'    => $snapshot['vendor_status'],
                'sponsor_id'       => (int) get_user_meta( $user->ID, '_tcn_sponsor_id', true ),
                'contact'          => array(
                    'phone'    => $this->get_contact_phone( $user->ID ),
                    'billing'  => $this->collect_address_fields( $user->ID, 'billing' ),
                    'shipping' => $this->collect_address_fields( $user->ID, 'shipping' ),
                ),
            );

            if ( isset( $snapshot['vendor_rejection_reason'] ) ) {
                $account['vendor_rejection_reason'] = $snapshot['vendor_rejection_reason'];
            }

            $users[] = $account;
        }

        $total       = (int) $query->get_total();
        $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

        return array(
            'success'    => true,
            'accounts'   => $users,
            'pagination' => array(
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
            ),
        );
    }

    public function approve_vendor( WP_REST_Request $request ) {
        $user_id = (int) $request->get_param( 'id' );

        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof WP_User ) {
            return new WP_Error( 'tcn_vendor_not_found', __( 'Unable to locate the requested vendor.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        Accounts::approve_vendor( $user->ID );

        return array(
            'success' => true,
            'vendor'  => Accounts::get_account_snapshot( $user->ID ),
        );
    }

    public function reject_vendor( WP_REST_Request $request ) {
        $user_id = (int) $request->get_param( 'id' );
        $user    = get_user_by( 'id', $user_id );

        if ( ! $user instanceof WP_User ) {
            return new WP_Error( 'tcn_vendor_not_found', __( 'Unable to locate the requested vendor.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        $reason = (string) $request->get_param( 'reason' );
        Accounts::reject_vendor( $user->ID, sanitize_textarea_field( $reason ) );

        return array(
            'success' => true,
            'vendor'  => Accounts::get_account_snapshot( $user->ID ),
        );
    }

    public function validate_positive_int( $value ): bool {
        return is_numeric( $value ) && (int) $value > 0;
    }

    protected function resolve_user( WP_REST_Request $request ) {
        $current = get_current_user_id();
        if ( $current ) {
            return $current;
        }

        $user_id = $this->token_authenticator->authenticate_request( $request );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $user_id > 0 ) {
            wp_set_current_user( $user_id );
        }

        return $user_id;
    }

    protected function get_contact_phone( int $user_id ): string {
        $phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( '' !== $phone ) {
            return (string) $phone;
        }

        $phone = get_user_meta( $user_id, 'phone', true );
        return (string) $phone;
    }

    protected function collect_address_fields( int $user_id, string $type ): array {
        $allowed = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
        $data    = array();

        foreach ( $allowed as $field ) {
            $meta_key        = sprintf( '%s_%s', $type, $field );
            $value           = get_user_meta( $user_id, $meta_key, true );
            $data[ $field ] = '' === $value ? '' : (string) $value;
        }

        return $data;
    }
}
