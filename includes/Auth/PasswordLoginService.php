<?php
namespace TCN\Platform\Auth;

use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Support\Accounts;
use TCN\Platform\Support\ErrorCodes;
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
        add_filter( 'jwt_auth_token_response', array( $this, 'append_api_token_to_jwt_response' ), 10, 3 );

        self::register_compatibility_alias();
    }

    /**
     * Ensure JWT compatibility responses also expose the long-lived API token.
     *
     * @param mixed         $response Response data dispatched by the jwt-auth endpoint.
     * @param WP_User|mixed $user     Authenticated user object when available.
     * @param array         $payload  Decoded JWT payload.
     *
     * @return mixed
     */
    public function append_api_token_to_jwt_response( $response, $user, array $payload ) {
        if ( ! is_array( $response ) ) {
            return $response;
        }

        $user_id = 0;

        if ( $user instanceof WP_User ) {
            $user_id = (int) $user->ID;
        } elseif ( isset( $payload['data']['user']['id'] ) ) {
            $user_id = (int) $payload['data']['user']['id'];
        }

        if ( $user_id <= 0 ) {
            return $response;
        }

        $api_token = $this->issue_api_token( $user_id );

        if ( empty( $api_token['token'] ) ) {
            return $response;
        }

        $response['api_token']            = $api_token['token'];
        $response['api_token_expires_in'] = $api_token['expires_in'];

        if ( ! isset( $response['expires_in'] ) || ! is_numeric( $response['expires_in'] ) ) {
            $response['expires_in'] = $api_token['expires_in'];
        }

        return $response;
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
                        return ErrorCodes::to_wp_error(
                            ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
                            'Missing bearer',
                            401,
                            array( 'legacy_code' => 'gn_unauth' )
                        );
                    }

                    $token   = trim( substr( $hdr, 7 ) );
                    $user_id = $this->validate_api_token( $token );
                    if ( ! $user_id ) {
                        return ErrorCodes::to_wp_error(
                            ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
                            'Invalid token',
                            401,
                            array( 'legacy_code' => 'gn_unauth' )
                        );
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
            '/token/refresh',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_token_refresh' ),
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

    public function handle_token_refresh( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $user_id = 0;

        if ( $this->token_authenticator ) {
            $result = $this->token_authenticator->authenticate_request( $request );
            if ( ! is_wp_error( $result ) && $result > 0 ) {
                $user_id = (int) $result;
            }
        }

        if ( $user_id <= 0 ) {
            $header = $request->get_header( 'authorization' );
            $token  = '';

            if ( is_string( $header ) && 0 === stripos( $header, 'Bearer ' ) ) {
                $token = trim( substr( $header, 7 ) );
            } else {
                $param = $request->get_param( 'token' );
                if ( ! is_string( $param ) || '' === trim( $param ) ) {
                    $param = $request->get_param( 'api_token' );
                }

                if ( is_string( $param ) ) {
                    $token = trim( $param );
                }
            }

            if ( '' !== $token ) {
                $validated = $this->validate_api_token( $token );
                if ( $validated ) {
                    $user_id = (int) $validated;
                } elseif ( class_exists( '\TCN\Platform\Auth\JwtTokenService' ) ) {
                    $payload = \TCN\Platform\Auth\JwtTokenService::decode_token( $token );
                    if ( is_wp_error( $payload ) ) {
                        return ErrorCodes::to_wp_error(
                            ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
                            __( 'Invalid or expired token.', 'tcnapp-connector' ),
                            401,
                            array( 'legacy_code' => 'gn_unauth' )
                        );
                    }

                    $user_id = (int) ( $payload['data']['user']['id'] ?? 0 );
                    if ( $user_id <= 0 ) {
                        return ErrorCodes::to_wp_error(
                            ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
                            __( 'Invalid or expired token.', 'tcnapp-connector' ),
                            401,
                            array( 'legacy_code' => 'gn_unauth' )
                        );
                    }
                }
            }
        }

        if ( $user_id <= 0 ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
                __( 'Invalid or expired token.', 'tcnapp-connector' ),
                401,
                array( 'legacy_code' => 'gn_unauth' )
            );
        }

        $api = $this->issue_api_token( $user_id );

        return array(
            'token'      => $api['token'],
            'api_token'  => $api['token'],
            'expires_in' => $api['expires_in'],
        );
    }

    public function handle_api_token_refresh( WP_REST_Request $request ) {
        return $this->handle_token_refresh( $request );
    }

    public function handle_login( WP_REST_Request $request ) {
        $https = $this->maybe_enforce_https( $request );
        if ( is_wp_error( $https ) ) {
            return $https;
        }

        $username = sanitize_text_field( $request->get_param( 'username' ) );
        $email    = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );
        if ( empty( $password ) || ( empty( $username ) && empty( $email ) ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_LOGIN_MISSING_CREDENTIALS,
                __( 'A username or email address and password are required.', 'tcnapp-connector' ),
                400,
                array( 'legacy_code' => 'gn_missing_credentials' )
            );
        }

        $login_identifier = ! empty( $username ) ? $username : $email;

        $rate_context = $this->build_rate_context( 'login', $username . '|' . $email );
        if ( $this->is_rate_limited( $rate_context ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_LOGIN_RATE_LIMITED,
                __( 'Too many attempts. Try again shortly.', 'tcnapp-connector' ),
                429,
                array( 'legacy_code' => 'gn_rate_limited' )
            );
        }

        $user = wp_authenticate( $login_identifier, $password );
        if ( is_wp_error( $user ) ) {
            $this->increment_rate_limit( $rate_context );

            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_WORDPRESS_CREDENTIALS,
                __( 'The provided username, email, or password are incorrect.', 'tcnapp-connector' ),
                401,
                array( 'legacy_code' => 'gn_invalid_credentials' )
            );
        }

        if ( ! $this->credentials_match_user( $user, $username, $email ) ) {
            $this->increment_rate_limit( $rate_context );

            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_WORDPRESS_CREDENTIALS,
                __( 'The provided username, email, or password are incorrect.', 'tcnapp-connector' ),
                401,
                array( 'legacy_code' => 'gn_invalid_credentials' )
            );
        }

        $this->reset_rate_limit( $rate_context );

        Accounts::ensure_defaults( $user->ID );
        $snapshot = Accounts::get_account_snapshot( $user->ID );

        if ( Accounts::STATUS_SUSPENDED === $snapshot['account_status'] ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_ACCOUNT_SUSPENDED,
                __( 'Your account is suspended. Contact support for assistance.', 'tcnapp-connector' ),
                403,
                array( 'legacy_code' => 'gn_account_suspended' )
            );
        }

        if ( Accounts::TYPE_VENDOR === $snapshot['account_type'] ) {
            if ( Accounts::STATUS_PENDING === $snapshot['vendor_status'] ) {
                return ErrorCodes::to_wp_error(
                    ErrorCodes::AUTH_VENDOR_PENDING,
                    __( 'Your vendor account is pending approval.', 'tcnapp-connector' ),
                    403,
                    array( 'legacy_code' => 'gn_vendor_pending' )
                );
            }

            if ( Accounts::STATUS_REJECTED === $snapshot['vendor_status'] ) {
                $data = array( 'status' => 403 );
                if ( isset( $snapshot['vendor_rejection_reason'] ) ) {
                    $data['reason'] = $snapshot['vendor_rejection_reason'];
                }

                return ErrorCodes::to_wp_error(
                    ErrorCodes::AUTH_VENDOR_REJECTED,
                    __( 'Your vendor account has been rejected. Contact support for assistance.', 'tcnapp-connector' ),
                    403,
                    array_merge( $data, array( 'legacy_code' => 'gn_vendor_rejected' ) )
                );
            }

            if ( Accounts::STATUS_SUSPENDED === $snapshot['vendor_status'] ) {
                return ErrorCodes::to_wp_error(
                    ErrorCodes::AUTH_VENDOR_SUSPENDED,
                    __( 'Your vendor account is suspended. Contact support for assistance.', 'tcnapp-connector' ),
                    403,
                    array( 'legacy_code' => 'gn_vendor_suspended' )
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

        $api                   = $this->issue_api_token( $user->ID );
        $response['api_token'] = $api['token'];
        $response['expires_in'] = $api['expires_in'];

        // The diagnostics screen expects both the one-click login URL and the long-lived bearer
        // token. Preserve the generated redirect under the documented `token_login_url` field
        // while avoiding the legacy `redirect` key.
        $response['token_login_url'] = $response['redirect'];
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
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_REGISTER_ACCOUNT_FAILED,
                __( 'Username, email, and password are required.', 'tcnapp-connector' ),
                400,
                array( 'legacy_code' => 'gn_missing_fields' )
            );
        }

        if ( username_exists( $username ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_REGISTER_ACCOUNT_FAILED,
                __( 'This username is already in use.', 'tcnapp-connector' ),
                409,
                array( 'legacy_code' => 'gn_username_exists' )
            );
        }

        if ( email_exists( $email ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_REGISTER_ACCOUNT_FAILED,
                __( 'This email address is already registered.', 'tcnapp-connector' ),
                409,
                array( 'legacy_code' => 'gn_email_exists' )
            );
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
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_REGISTER_ACCOUNT_FAILED,
                __( 'Unable to create the user at this time.', 'tcnapp-connector' ),
                500,
                array( 'legacy_code' => 'gn_registration_failed' )
            );
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
                    return ErrorCodes::to_wp_error(
                        ErrorCodes::REGISTER_VENDOR_TIER_FETCH_FAILED,
                        __( 'The specified vendor tier is not valid.', 'tcnapp-connector' ),
                        400,
                        array( 'legacy_code' => 'gn_invalid_vendor_tier' )
                    );
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
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_PASSWORD_RESET_EMAIL_FAILED,
                __( 'A new password is required.', 'tcnapp-connector' ),
                400,
                array( 'legacy_code' => 'gn_missing_password' )
            );
        }

        $user = null;

        if ( ! empty( $code ) ) {
            $user = $this->get_user_from_login( $login );
            if ( ! $user || ! $this->validate_reset_code( $user->ID, $code ) ) {
                return ErrorCodes::to_wp_error(
                    ErrorCodes::AUTH_RESET_PASSWORD_FAILED,
                    __( 'The verification code is invalid or expired.', 'tcnapp-connector' ),
                    400,
                    array( 'legacy_code' => 'gn_invalid_code' )
                );
            }
        } elseif ( ! empty( $key ) ) {
            $checked = check_password_reset_key( $key, $login );
            if ( is_wp_error( $checked ) ) {
                return ErrorCodes::to_wp_error(
                    ErrorCodes::AUTH_RESET_PASSWORD_FAILED,
                    __( 'The password reset link is invalid or expired.', 'tcnapp-connector' ),
                    400,
                    array( 'legacy_code' => 'gn_invalid_reset_key' )
                );
            }

            $user = $checked;
        } else {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_RESET_PASSWORD_FAILED,
                __( 'Provide a verification code or reset key.', 'tcnapp-connector' ),
                400,
                array( 'legacy_code' => 'gn_missing_reset_token' )
            );
        }

        if ( ! $user ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_RESET_PASSWORD_FAILED,
                __( 'Unable to locate the requested account.', 'tcnapp-connector' ),
                404,
                array( 'legacy_code' => 'gn_user_not_found' )
            );
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
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_WORDPRESS_CREDENTIALS,
                __( 'Authentication required.', 'tcnapp-connector' ),
                401,
                array( 'legacy_code' => 'gn_not_authenticated' )
            );
        }

        $current = (string) $request->get_param( 'current_password' );
        $new     = (string) $request->get_param( 'password' );

        if ( empty( $current ) || empty( $new ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_CHANGE_PASSWORD_FAILED,
                __( 'Current and new passwords are required.', 'tcnapp-connector' ),
                400,
                array( 'legacy_code' => 'gn_missing_password' )
            );
        }

        if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
            return ErrorCodes::to_wp_error(
                ErrorCodes::AUTH_CHANGE_PASSWORD_FAILED,
                __( 'The current password is incorrect.', 'tcnapp-connector' ),
                403,
                array( 'legacy_code' => 'gn_invalid_current_password' )
            );
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

        return ErrorCodes::to_wp_error(
            ErrorCodes::AUTH_WORDPRESS_CREDENTIALS,
            __( 'Authentication required.', 'tcnapp-connector' ),
            rest_authorization_required_code(),
            array( 'legacy_code' => 'gn_not_authenticated' )
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

        if ( user_can( $user, 'manage_options' ) ) {
            $snapshot['account_type']   = Accounts::TYPE_VENDOR;
            $snapshot['account_status'] = Accounts::STATUS_ACTIVE;
            $snapshot['vendor_status']  = Accounts::STATUS_ACTIVE;
        }

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
        if ( is_ssl() ) {
            return true;
        }

        $wp_debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $host_value = $request->get_header( 'host' );
        $host_value = is_string( $host_value ) ? trim( $host_value ) : '';
        $host_name  = '';

        if ( '' !== $host_value ) {
            $parsed_host = wp_parse_url( 'http://' . $host_value, PHP_URL_HOST );
            if ( is_string( $parsed_host ) ) {
                $host_name = strtolower( $parsed_host );
            }
        }

        $is_local = in_array( $host_name, array( 'localhost', '127.0.0.1', '::1' ), true );

        $allow_dev_http = false;

        if ( $wp_debug && ! empty( $this->settings['allow_dev_http'] ) ) {
            $allow_dev_http = true;
        }

        if ( $wp_debug && $is_local ) {
            $allow_dev_http = true;
        }

        $allow_dev_http = apply_filters( 'gn_password_api_allow_dev_http', $allow_dev_http, $request );

        if ( $allow_dev_http ) {
            return true;
        }

        return ErrorCodes::to_wp_error(
            ErrorCodes::AUTH_PASSWORD_LOGIN_FAILED,
            __( 'HTTPS is required to access this endpoint.', 'tcnapp-connector' ),
            403,
            array( 'legacy_code' => 'gn_https_required' )
        );
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

    protected function credentials_match_user( WP_User $user, string $expected_username, string $expected_email ): bool {
        $expected_username = trim( $expected_username );
        $expected_email    = trim( $expected_email );

        $login_matches = '' === $expected_username ? true : 0 === strcasecmp( $user->user_login, $expected_username );
        $email_matches = '' === $expected_email ? true : 0 === strcasecmp( $user->user_email, $expected_email );

        return $login_matches && $email_matches;
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

        $expires_at  = time() + $lifetime;
        $token_string = wp_generate_password( 64, false );

        $token_string = apply_filters( 'gn_password_api_issue_api_token', $token_string, $user_id, $expires_at );

        if ( ! is_string( $token_string ) ) {
            $token_string = '';
        }

        $token_string = trim( $token_string );

        if ( '' === $token_string ) {
            $token_string = wp_generate_password( 64, false );
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
