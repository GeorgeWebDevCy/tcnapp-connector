<?php
namespace TCN\Platform\Auth;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Accounts;
use TCN\Platform\Support\Options;
use WP_Error;
use WP_REST_Request;
use WP_User;

class PasswordLoginService {
    const RATE_LIMIT_PREFIX = 'gn_login_rate_';
    const TOKEN_PREFIX      = 'gn_login_token_';
    const API_TOKEN_PREFIX  = 'tcn_api_tok_';
    const RESET_META_KEY    = '_gn_password_api_reset_code';

    /**
     * @var array<string, mixed>
     */
    protected $settings = array();

    /**
     * @var TokenAuthenticator|null
     */
    protected $token_authenticator;

    public function __construct( ?TokenAuthenticator $token_authenticator = null ) {
        $this->token_authenticator = $token_authenticator;
    }

    public function register(): void {
        $this->settings = Options::get_login_settings();

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_filter( 'rest_pre_serve_request', array( $this, 'filter_pre_serve_request' ), 15, 4 );
        add_action( 'login_form_gn_token_login', array( $this, 'handle_token_login' ) );

        self::register_compatibility_alias();
    }

    public static function register_compatibility_alias(): void {
        if ( ! class_exists( 'GN_Password_Login_API', false ) ) {
            class_alias( __CLASS__, 'GN_Password_Login_API' );
        }
    }

    public function register_routes(): void {
        register_rest_route(
            'gn/v1',
            '/login',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_login' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/register',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_register' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/forgot-password',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_forgot_password' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/reset-password',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_reset_password' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/change-password',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_change_password' ),
                'permission_callback' => array( $this, 'require_login_or_token' ),
            )
        );

        register_rest_route(
            'gn/v1',
            '/me',
            array(
                'methods'             => 'GET',
                'callback'            => function( WP_REST_Request $req ) {
                    $hdr = $req->get_header( 'authorization' );
                    if ( ! $hdr || 0 !== stripos( $hdr, 'Bearer ' ) ) {
                        return new WP_Error( 'gn_unauth', 'Missing bearer', array( 'status' => 401 ) );
                    }

                    $token   = trim( substr( $hdr, 7 ) );
                    $user_id = $this->validate_api_token( $token );
                    if ( ! $user_id ) {
                        return new WP_Error( 'gn_unauth', 'Invalid token', array( 'status' => 401 ) );
                    }

                    $user = get_user_by( 'ID', $user_id );

                    return array(
                        'user' => $this->prepare_user_payload( $user ),
                    );
                },
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            'gn/v1',
            '/log',
            array(
                'methods'             => 'POST',
                'callback'            => function( WP_REST_Request $r ) {
                    $level   = sanitize_text_field( $r->get_param( 'log_level' ) ?: 'info' );
                    $message = sanitize_text_field( $r->get_param( 'log_message' ) ?: '(no message)' );
                    $source  = sanitize_text_field( $r->get_param( 'log_source' ) ?: 'mobile-app' );
                    $params  = $r->get_param( 'log_params' );

                    \TCN\Platform\Support\Logger::log(
                        $source,
                        $message,
                        array(
                            'level'  => $level,
                            'params' => is_array( $params ) ? $params : array(),
                        )
                    );

                    return array( 'ok' => true );
                },
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_login( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $username = sanitize_text_field( $request->get_param( 'username' ) );
        $password = (string) $request->get_param( 'password' );
        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'gn_missing_credentials', __( 'Username and password are required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $rate_context = $this->build_rate_context( 'login', $username );
        if ( $this->is_rate_limited( $rate_context ) ) {
            return new WP_Error( 'gn_rate_limited', __( 'Too many attempts. Try again shortly.', 'tcnapp-connector' ), array( 'status' => 429 ) );
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            $this->increment_rate_limit( $rate_context );

            return new WP_Error( 'gn_invalid_credentials', __( 'The provided credentials are incorrect.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        $this->reset_rate_limit( $rate_context );

        Accounts::ensure_defaults( $user->ID );
        $snapshot = Accounts::get_account_snapshot( $user->ID );

        if ( Accounts::STATUS_SUSPENDED === $snapshot['account_status'] ) {
            return new WP_Error(
                'gn_account_suspended',
                __( 'Your account is suspended. Contact support for assistance.', 'tcnapp-connector' ),
                array( 'status' => 403 )
            );
        }

        if ( Accounts::TYPE_VENDOR === $snapshot['account_type'] ) {
            if ( Accounts::STATUS_PENDING === $snapshot['vendor_status'] ) {
                return new WP_Error(
                    'gn_vendor_pending',
                    __( 'Your vendor account is pending approval.', 'tcnapp-connector' ),
                    array( 'status' => 403 )
                );
            }

            if ( Accounts::STATUS_REJECTED === $snapshot['vendor_status'] ) {
                $data = array( 'status' => 403 );
                if ( isset( $snapshot['vendor_rejection_reason'] ) ) {
                    $data['reason'] = $snapshot['vendor_rejection_reason'];
                }

                return new WP_Error(
                    'gn_vendor_rejected',
                    __( 'Your vendor account has been rejected. Contact support for assistance.', 'tcnapp-connector' ),
                    $data
                );
            }

            if ( Accounts::STATUS_SUSPENDED === $snapshot['vendor_status'] ) {
                return new WP_Error(
                    'gn_vendor_suspended',
                    __( 'Your vendor account is suspended. Contact support for assistance.', 'tcnapp-connector' ),
                    array( 'status' => 403 )
                );
            }
        }

        $response = array(
            'success' => true,
            'user'    => $this->prepare_user_payload( $user ),
        );

        $token = $this->issue_login_token( $user->ID );

        $response['token']    = $token['token'];
        $response['redirect'] = add_query_arg(
            array(
                'action' => 'gn_token_login',
                'token'  => rawurlencode( $token['token'] ),
            ),
            wp_login_url()
        );

        $api                     = $this->issue_api_token( $user->ID );
        $response['api_token']    = $api['token'];
        $response['expires_in']   = $api['expires_in'];
        unset( $response['redirect'] );

        $woocommerce_credentials = $this->get_woocommerce_credentials();
        if ( ! empty( $woocommerce_credentials ) ) {
            $response['auth'] = array(
                'woocommerce' => $woocommerce_credentials,
            );
        }

        return $response;
    }

    public function handle_register( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $username     = sanitize_user( (string) $request->get_param( 'username' ), true );
        $email        = sanitize_email( (string) $request->get_param( 'email' ) );
        $password     = (string) $request->get_param( 'password' );
        $first_name   = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
        $last_name    = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
        $account_type = (string) $request->get_param( 'account_type' );
        $vendor_tier  = sanitize_key( (string) $request->get_param( 'vendor_tier' ) );

        if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
            return new WP_Error( 'gn_missing_fields', __( 'Username, email, and password are required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( username_exists( $username ) ) {
            return new WP_Error( 'gn_username_exists', __( 'This username is already in use.', 'tcnapp-connector' ), array( 'status' => 409 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'gn_email_exists', __( 'This email address is already registered.', 'tcnapp-connector' ), array( 'status' => 409 ) );
        }

        $suppress_new_user_notification = static function( $send ) {
            return false;
        };

        add_filter( 'wp_send_new_user_notification_to_admin', $suppress_new_user_notification );
        add_filter( 'wp_send_new_user_notification_to_user', $suppress_new_user_notification );

        $user_id = wp_create_user( $username, $password, $email );

        remove_filter( 'wp_send_new_user_notification_to_admin', $suppress_new_user_notification );
        remove_filter( 'wp_send_new_user_notification_to_user', $suppress_new_user_notification );
        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'gn_registration_failed', __( 'Unable to create the user at this time.', 'tcnapp-connector' ), array( 'status' => 500 ) );
        }

        wp_update_user(
            array(
                'ID'         => $user_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
            )
        );

        Accounts::bootstrap_new_account( $user_id, $account_type );

        if ( \TCN\Platform\Support\Accounts::TYPE_VENDOR === strtolower( sanitize_key( $account_type ) ) ) {
            if ( '' !== $vendor_tier ) {
                $tiers = \TCN\Platform\Support\VendorTiers::get_all();
                if ( isset( $tiers[ $vendor_tier ] ) ) {
                    update_user_meta( $user_id, '_tcn_vendor_tier', $vendor_tier );
                } else {
                    return new WP_Error( 'gn_invalid_vendor_tier', __( 'The specified vendor tier is not valid.', 'tcnapp-connector' ), array( 'status' => 400 ) );
                }
            } else {
                // Default to sapphire when omitted
                update_user_meta( $user_id, '_tcn_vendor_tier', 'sapphire' );
            }
        }

        /**
         * Fires after a user is registered through the Password Login API.
         */
        do_action( 'gn_password_api_user_registered', $user_id, $request );

        $user = get_user_by( 'id', $user_id );

        return array(
            'success' => true,
            'user'    => $this->prepare_user_payload( $user ),
        );
    }

    public function handle_forgot_password( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $login      = sanitize_text_field( (string) $request->get_param( 'username' ) );
        $send_code  = (bool) $request->get_param( 'return_verification_code' );
        $user       = $this->get_user_from_login( $login );
        $response   = array( 'success' => true );

        if ( $user ) {
            $result = retrieve_password( $user->user_login );

            if ( $send_code && ! is_wp_error( $result ) ) {
                $ttl = apply_filters( 'gn_password_api_reset_code_ttl', 15 * MINUTE_IN_SECONDS, $user->ID, $request );
                $response['verification_code'] = self::issue_reset_verification_code( $user->ID, $ttl );
            }
        }

        return $response;
    }

    public function handle_reset_password( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $login    = sanitize_text_field( (string) $request->get_param( 'login' ) );
        $password = (string) $request->get_param( 'password' );
        $code     = sanitize_text_field( (string) $request->get_param( 'verification_code' ) );
        $key      = sanitize_text_field( (string) $request->get_param( 'key' ) );

        if ( empty( $password ) ) {
            return new WP_Error( 'gn_missing_password', __( 'A new password is required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        $user = null;

        if ( ! empty( $code ) ) {
            $user = $this->get_user_from_login( $login );
            if ( ! $user || ! $this->validate_reset_code( $user->ID, $code ) ) {
                return new WP_Error( 'gn_invalid_code', __( 'The verification code is invalid or expired.', 'tcnapp-connector' ), array( 'status' => 400 ) );
            }
        } elseif ( ! empty( $key ) ) {
            $checked = check_password_reset_key( $key, $login );
            if ( is_wp_error( $checked ) ) {
                return new WP_Error( 'gn_invalid_reset_key', __( 'The password reset link is invalid or expired.', 'tcnapp-connector' ), array( 'status' => 400 ) );
            }

            $user = $checked;
        } else {
            return new WP_Error( 'gn_missing_reset_token', __( 'Provide a verification code or reset key.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( ! $user ) {
            return new WP_Error( 'gn_user_not_found', __( 'Unable to locate the requested account.', 'tcnapp-connector' ), array( 'status' => 404 ) );
        }

        wp_set_password( $password, $user->ID );
        delete_user_meta( $user->ID, self::RESET_META_KEY );

        do_action( 'gn_password_api_password_reset', $user->ID, $request );

        return array( 'success' => true );
    }

    public function handle_change_password( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $authenticated = $this->authenticate_with_token( $request );
        if ( is_wp_error( $authenticated ) ) {
            return $authenticated;
        }

        $user = wp_get_current_user();

        if ( ( ! $user || ! $user->ID ) && is_int( $authenticated ) && $authenticated > 0 ) {
            $user = get_user_by( 'id', $authenticated );

            if ( $user instanceof WP_User ) {
                wp_set_current_user( $user->ID );
            }
        }

        if ( ! $user || ! $user->ID ) {
            return new WP_Error( 'gn_not_authenticated', __( 'Authentication required.', 'tcnapp-connector' ), array( 'status' => 401 ) );
        }

        $current = (string) $request->get_param( 'current_password' );
        $new     = (string) $request->get_param( 'password' );

        if ( empty( $current ) || empty( $new ) ) {
            return new WP_Error( 'gn_missing_password', __( 'Current and new passwords are required.', 'tcnapp-connector' ), array( 'status' => 400 ) );
        }

        if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
            return new WP_Error( 'gn_invalid_current_password', __( 'The current password is incorrect.', 'tcnapp-connector' ), array( 'status' => 403 ) );
        }

        wp_set_password( $new, $user->ID );
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        do_action( 'gn_password_api_password_changed', $user->ID, $request );

        return array( 'success' => true );
    }

    public function require_login_or_token( WP_REST_Request $request ) {
        $authenticated = $this->authenticate_with_token( $request );
        if ( is_wp_error( $authenticated ) ) {
            return $authenticated;
        }

        if ( $authenticated > 0 || is_user_logged_in() ) {
            return true;
        }

        return new WP_Error(
            'gn_not_authenticated',
            __( 'Authentication required.', 'tcnapp-connector' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    protected function authenticate_with_token( WP_REST_Request $request ) {
        if ( ! $this->token_authenticator ) {
            return 0;
        }

        return $this->token_authenticator->authenticate_request( $request );
    }

    public function filter_pre_serve_request( $served, $result, $request, $server ) {
        if ( 0 !== strpos( $request->get_route(), '/gn/v1' ) ) {
            return $served;
        }

        $origin         = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        $allowed_origin = isset( $this->settings['allowed_origin'] ) ? trim( (string) $this->settings['allowed_origin'] ) : '';

        if ( '' === $allowed_origin ) {
            $home_url = home_url();

            if ( is_string( $home_url ) && '' !== $home_url ) {
                $parsed_home = wp_parse_url( $home_url );

                if ( is_array( $parsed_home ) && isset( $parsed_home['scheme'], $parsed_home['host'] ) ) {
                    $allowed_origin = $parsed_home['scheme'] . '://' . $parsed_home['host'];

                    if ( isset( $parsed_home['port'] ) ) {
                        $allowed_origin .= ':' . $parsed_home['port'];
                    }
                }
            }
        }

        if ( ! headers_sent() && $origin && $allowed_origin && 0 === strcasecmp( $allowed_origin, $origin ) ) {
            header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
            header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
        }

        return $served;
    }

    public function handle_token_login(): void {
        $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

        if ( empty( $token ) ) {
            wp_die( esc_html__( 'Missing login token.', 'tcnapp-connector' ) );
        }

        $transient = $this->get_token_transient( $token );
        if ( empty( $transient ) || empty( $transient['user_id'] ) ) {
            wp_die( esc_html__( 'This login link has expired or is invalid.', 'tcnapp-connector' ) );
        }

        $user_id = absint( $transient['user_id'] );
        delete_transient( $this->build_token_key( $token ) );

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        $redirect = apply_filters( 'gn_password_api_token_redirect', admin_url(), $user_id, $transient );
        wp_safe_redirect( $redirect );
        exit;
    }

    protected function prepare_user_payload( WP_User $user ): array {
        Accounts::ensure_defaults( $user->ID );
        $snapshot = Accounts::get_account_snapshot( $user->ID );

        $payload = array(
            'id'               => $user->ID,
            'username'         => $user->user_login,
            'email'            => $user->user_email,
            'display_name'     => $user->display_name,
            'first_name'       => $user->first_name,
            'last_name'        => $user->last_name,
            'membership_level' => get_user_meta( $user->ID, '_tcn_membership_level', true ),
            'sponsor_id'       => (int) get_user_meta( $user->ID, '_tcn_sponsor_id', true ),
            'account_type'     => $snapshot['account_type'],
            'account_status'   => $snapshot['account_status'],
            'vendor_status'    => $snapshot['vendor_status'],
        );

        if ( isset( $snapshot['vendor_rejection_reason'] ) ) {
            $payload['vendor_rejection_reason'] = $snapshot['vendor_rejection_reason'];
        }

        $vendor_tier = (string) get_user_meta( $user->ID, '_tcn_vendor_tier', true );
        if ( '' !== $vendor_tier ) {
            $payload['vendor_tier'] = $vendor_tier;
        }

        $woocommerce_credentials = $this->get_woocommerce_credentials();
        if ( ! empty( $woocommerce_credentials ) ) {
            $payload['woocommerce'] = $woocommerce_credentials;
        }

        return $payload;
    }

    /**
     * Retrieve WooCommerce REST API credentials exposed via constants.
     */
    protected function get_woocommerce_credentials(): array {
        if ( ! defined( 'WOOCOMMERCE_CONSUMER_KEY' ) || ! defined( 'WOOCOMMERCE_CONSUMER_SECRET' ) ) {
            return array();
        }

        $key    = trim( (string) WOOCOMMERCE_CONSUMER_KEY );
        $secret = trim( (string) WOOCOMMERCE_CONSUMER_SECRET );

        if ( '' === $key || '' === $secret ) {
            return array();
        }

        return array(
            'consumer_key'    => $key,
            'consumer_secret' => $secret,
            'authorization'   => 'Basic ' . base64_encode( $key . ':' . $secret ),
        );
    }

    protected function get_user_from_login( string $login ) {
        if ( empty( $login ) ) {
            return null;
        }

        if ( is_email( $login ) ) {
            return get_user_by( 'email', $login );
        }

        return get_user_by( 'login', $login );
    }

    protected function maybe_enforce_https( WP_REST_Request $request ) {
        $allow_dev = ! empty( $this->settings['allow_dev_http'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG;
        $allow_dev = apply_filters( 'gn_password_api_allow_dev_http', $allow_dev, $request );

        if ( is_ssl() || $allow_dev ) {
            return true;
        }

        return new WP_Error( 'gn_https_required', __( 'HTTPS is required to access this endpoint.', 'tcnapp-connector' ), array( 'status' => 403 ) );
    }

    protected function build_rate_context( string $action, string $identifier ): array {
        $identifier = strtolower( trim( $identifier ) );
        $ip         = $this->get_client_ip();

        return array(
            'key'   => self::RATE_LIMIT_PREFIX . md5( $action . '|' . $identifier . '|' . $ip ),
            'limit' => max( 1, (int) $this->settings['rate_limit'] ),
            'ttl'   => max( 60, (int) $this->settings['rate_limit_window'] ),
        );
    }

    protected function is_rate_limited( array $context ): bool {
        $data = get_transient( $context['key'] );
        if ( ! is_array( $data ) || empty( $data['count'] ) ) {
            return false;
        }

        return $data['count'] >= $context['limit'];
    }

    protected function increment_rate_limit( array $context ): void {
        $data = get_transient( $context['key'] );
        if ( ! is_array( $data ) ) {
            $data = array( 'count' => 0 );
        }

        $data['count'] = isset( $data['count'] ) ? (int) $data['count'] + 1 : 1;
        set_transient( $context['key'], $data, $context['ttl'] );
    }

    protected function reset_rate_limit( array $context ): void {
        delete_transient( $context['key'] );
    }

    protected function issue_login_token( int $user_id ): array {
        $lifetime = apply_filters( 'gn_password_api_login_token_lifetime', (int) $this->settings['token_lifetime'], $user_id );
        $lifetime = max( WEEK_IN_SECONDS, $lifetime );

        $generated = wp_generate_password( 48, false );
        $token     = apply_filters( 'gn_password_api_issue_login_token', $generated, $user_id, $lifetime );

        if ( ! is_string( $token ) ) {
            $token = '';
        }

        $token = trim( $token );

        if ( '' === $token ) {
            $token = wp_generate_password( 48, false );
        }

        set_transient(
            $this->build_token_key( $token ),
            array(
                'user_id'   => $user_id,
                'issued_at' => time(),
            ),
            $lifetime
        );

        return array(
            'token'      => $token,
            'expires_in' => $lifetime,
        );
    }

    protected function issue_api_token( int $user_id ): array {
        $lifetime = (int) apply_filters( 'gn_password_api_api_token_lifetime', DAY_IN_SECONDS * 7, $user_id );
        $lifetime = max( HOUR_IN_SECONDS, $lifetime );

        $user = get_user_by( 'id', $user_id );

        $jwt_token  = null;
        $expires_at = time() + $lifetime;

        if ( $user instanceof WP_User ) {
            $jwt_token = JwtTokenService::generate_token(
                $user,
                array(
                    'expire' => $expires_at,
                )
            );
        }

        if ( $jwt_token instanceof WP_Error || ! is_array( $jwt_token ) ) {
            $token_string = wp_generate_password( 64, false );
        } else {
            $token_string = $jwt_token['token'];
            $payload      = $jwt_token['payload'];

            if ( isset( $payload['exp'] ) && (int) $payload['exp'] > 0 ) {
                $expires_at = (int) $payload['exp'];
            }
        }

        $ttl = max( 1, $expires_at - time() );

        set_transient(
            self::API_TOKEN_PREFIX . md5( $token_string ),
            array(
                'user_id' => $user_id,
                'exp'     => $expires_at,
            ),
            $ttl
        );

        return array(
            'token'      => $token_string,
            'expires_in' => max( 0, $expires_at - time() ),
        );
    }

    protected function validate_api_token( string $token ) {
        $t = get_transient( self::API_TOKEN_PREFIX . md5( $token ) );
        if ( empty( $t ) || empty( $t['user_id'] ) || time() > (int) $t['exp'] ) {
            return false;
        }

        return (int) $t['user_id'];
    }

    protected function build_token_key( string $token ): string {
        return self::TOKEN_PREFIX . md5( $token );
    }

    protected function get_token_transient( string $token ) {
        return get_transient( $this->build_token_key( $token ) );
    }

    protected function validate_reset_code( int $user_id, string $code ): bool {
        $bundle = get_user_meta( $user_id, self::RESET_META_KEY, true );
        if ( empty( $bundle['hash'] ) || empty( $bundle['expires'] ) ) {
            return false;
        }

        if ( time() > (int) $bundle['expires'] ) {
            delete_user_meta( $user_id, self::RESET_META_KEY );
            return false;
        }

        return wp_check_password( $code, $bundle['hash'] );
    }

    protected function get_client_ip(): string {
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip    = trim( $parts[0] );
        } else {
            $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }

        return preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip );
    }

    public static function issue_reset_verification_code( int $user_id, int $ttl = 900 ): string {
        $ttl   = max( 60, $ttl );
        $code  = apply_filters( 'gn_password_api_generate_reset_code', wp_generate_password( 6, false ), $user_id, $ttl );
        $hash  = wp_hash_password( $code );
        $bundle = array(
            'hash'    => $hash,
            'expires' => time() + $ttl,
        );

        update_user_meta( $user_id, self::RESET_META_KEY, $bundle );

        return $code;
    }
}
