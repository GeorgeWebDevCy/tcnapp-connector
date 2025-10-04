<?php
namespace TCN\Platform\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function __;
use function get_current_user_id;
use function get_userdata;
use function sanitize_text_field;

class ActivityMonitor {
    /**
     * REST namespaces handled by the monitor.
     *
     * @var array<int, string>
     */
    protected $namespaces;

    public function __construct( array $namespaces = array( 'gn/v1', 'tcn-mlm/v1' ) ) {
        $this->namespaces = $namespaces;
    }

    public function register(): void {
        add_filter( 'rest_request_after_callbacks', array( $this, 'capture_rest_activity' ), 99, 3 );
        add_action( 'tcn_platform_activated', array( $this, 'log_plugin_activated' ) );
        add_action( 'tcn_platform_deactivated', array( $this, 'log_plugin_deactivated' ) );
        add_action( 'tcn_platform_settings_saved', array( $this, 'log_settings_saved' ), 10, 2 );
    }

    /**
     * Capture REST API usage for the mobile namespaces.
     *
     * @param mixed             $response Response from callbacks.
     * @param array<string,mixed> $handler  Handler information.
     * @param WP_REST_Request   $request  Current request.
     *
     * @return mixed
     */
    public function capture_rest_activity( $response, array $handler, $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        $namespace = $this->extract_namespace( $request->get_route() );
        if ( ! $namespace || ! in_array( $namespace, $this->namespaces, true ) ) {
            return $response;
        }

        $status = $this->resolve_status( $response );
        $user   = $this->resolve_user();
        $ip     = $this->get_client_ip();

        $params  = $this->scrub_params( $request->get_params() );
        $context = array(
            'namespace' => $namespace,
            'status'    => $status,
            'result'    => $response instanceof WP_Error ? 'error' : 'success',
            'user'      => $user,
            'ip'        => $ip,
        );

        if ( ! empty( $params ) ) {
            $context['params'] = $params;
        }

        if ( $response instanceof WP_Error ) {
            $context['errors'] = $this->scrub_params( $response->errors );
        }

        Logger::log(
            'rest',
            sprintf( '%s %s', $request->get_method(), $request->get_route() ),
            $context
        );

        return $response;
    }

    public function log_plugin_activated(): void {
        Logger::log(
            'plugin',
            __( 'Plugin activated', 'tcnapp-connector' ),
            array(
                'version' => TCN_PLATFORM_VERSION,
            )
        );
    }

    public function log_plugin_deactivated(): void {
        Logger::log(
            'plugin',
            __( 'Plugin deactivated', 'tcnapp-connector' ),
            array(
                'version' => TCN_PLATFORM_VERSION,
            )
        );
    }

    public function log_settings_saved( array $modules, array $login_settings ): void {
        $enabled_modules = array();
        foreach ( $modules as $module => $enabled ) {
            if ( $enabled ) {
                $enabled_modules[] = $module;
            }
        }

        Logger::log(
            'plugin',
            __( 'Settings updated', 'tcnapp-connector' ),
            array(
                'enabled_modules'  => $enabled_modules,
                'allowed_origin'   => isset( $login_settings['allowed_origin'] ) ? $login_settings['allowed_origin'] : '',
                'allow_dev_http'   => ! empty( $login_settings['allow_dev_http'] ),
                'token_lifetime'   => isset( $login_settings['token_lifetime'] ) ? (int) $login_settings['token_lifetime'] : 0,
                'rate_limit'       => isset( $login_settings['rate_limit'] ) ? (int) $login_settings['rate_limit'] : 0,
                'rate_limit_window'=> isset( $login_settings['rate_limit_window'] ) ? (int) $login_settings['rate_limit_window'] : 0,
            )
        );
    }

    protected function extract_namespace( string $route ): string {
        $route = trim( $route, '/' );
        if ( '' === $route ) {
            return '';
        }

        $segments = explode( '/', $route );
        if ( count( $segments ) < 2 ) {
            return '';
        }

        return $segments[0] . '/' . $segments[1];
    }

    protected function resolve_status( $response ): int {
        if ( $response instanceof WP_REST_Response ) {
            return (int) $response->get_status();
        }

        if ( $response instanceof WP_Error ) {
            $data = $response->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) ) {
                return (int) $data['status'];
            }

            if ( is_numeric( $data ) ) {
                return (int) $data;
            }

            return 500;
        }

        return 200;
    }

    protected function resolve_user(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return __( 'Guest', 'tcnapp-connector' );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return (string) $user_id;
        }

        return $user->user_login;
    }

    protected function get_client_ip(): string {
        $ip_keys = array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $ip_keys as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) {
                continue;
            }

            $raw = explode( ',', (string) $_SERVER[ $key ] );
            $ip  = trim( $raw[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        return 'unknown';
    }

    protected function scrub_params( $params ) {
        if ( empty( $params ) ) {
            return array();
        }

        $keys_to_redact = array( 'password', 'pass', 'pass1', 'pass2', 'current_password', 'new_password', 'token', 'otp', 'secret' );

        if ( is_array( $params ) ) {
            $sanitized = array();
            foreach ( $params as $key => $value ) {
                $clean_key = is_string( $key ) ? $this->normalize_key( $key ) : $key;
                if ( is_string( $clean_key ) && in_array( $clean_key, $keys_to_redact, true ) ) {
                    $sanitized[ $clean_key ] = '••••';
                    continue;
                }

                $sanitized[ $clean_key ] = $this->scrub_params( $value );
            }

            return $sanitized;
        }

        if ( is_scalar( $params ) ) {
            if ( is_bool( $params ) ) {
                return (bool) $params;
            }

            if ( is_numeric( $params ) ) {
                return 0 + $params;
            }

            return sanitize_text_field( (string) $params );
        }

        if ( null === $params ) {
            return null;
        }

        return (array) $params;
    }

    protected function normalize_key( string $key ): string {
        $key = strtolower( $key );
        return preg_replace( '/[^a-z0-9_\-\.]/', '_', $key );
    }
}
