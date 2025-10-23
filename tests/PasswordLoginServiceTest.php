<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TCN\Platform\Auth\PasswordLoginService;

require_once __DIR__ . '/../includes/Support/ErrorCodes.php';
require_once __DIR__ . '/../includes/Support/Accounts.php';
require_once __DIR__ . '/../includes/Auth/PasswordLoginService.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
    define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        $key = strtolower( (string) $key );

        return preg_replace( '/[^a-z0-9_]/', '', $key );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return trim( (string) $value );
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $value ) {
        $value = trim( (string) $value );

        return strtolower( $value );
    }
}

if ( ! function_exists( 'sanitize_user' ) ) {
    function sanitize_user( $value ) {
        return trim( (string) $value );
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( $value ) {
        $value = trim( (string) $value );

        return '' !== $value && false !== strpos( $value, '@' );
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() {
        return true;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( array $args, string $url ) {
        $separator = false === strpos( $url, '?' ) ? '?' : '&';

        return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
    }
}

if ( ! function_exists( 'wp_login_url' ) ) {
    function wp_login_url() {
        return 'https://example.com/wp-login.php';
    }
}

if ( ! function_exists( 'wp_authenticate' ) ) {
    function wp_authenticate( $login, $password ) {
        $login = (string) $login;
        $users = $GLOBALS['tcn_test_users'] ?? array();

        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }

            if ( 0 === strcasecmp( $user->user_login, $login ) || 0 === strcasecmp( $user->user_email, $login ) ) {
                if ( $password === $user->user_pass ) {
                    return $user;
                }

                break;
            }
        }

        return new WP_Error( 'invalid_credentials', 'Invalid credentials.' );
    }
}

if ( ! function_exists( 'wp_check_password' ) ) {
    function wp_check_password( $password, $hash, $user_id = 0 ) {
        return $password === $hash;
    }
}

if ( ! function_exists( 'get_user_by' ) ) {
    function get_user_by( $field, $value ) {
        $value = (string) $value;
        $users = $GLOBALS['tcn_test_users'] ?? array();

        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }

            if ( 'email' === strtolower( (string) $field ) && 0 === strcasecmp( $user->user_email, $value ) ) {
                return $user;
            }

            if ( 'login' === strtolower( (string) $field ) && 0 === strcasecmp( $user->user_login, $value ) ) {
                return $user;
            }

            if ( 'id' === strtolower( (string) $field ) && (int) $user->ID === (int) $value ) {
                return $user;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $meta_key, $meta_value ) {
        if ( ! isset( $GLOBALS['tcn_user_meta'] ) || ! is_array( $GLOBALS['tcn_user_meta'] ) ) {
            $GLOBALS['tcn_user_meta'] = array();
        }

        if ( ! isset( $GLOBALS['tcn_user_meta'][ $user_id ] ) || ! is_array( $GLOBALS['tcn_user_meta'][ $user_id ] ) ) {
            $GLOBALS['tcn_user_meta'][ $user_id ] = array();
        }

        $GLOBALS['tcn_user_meta'][ $user_id ][ $meta_key ] = $meta_value;

        return true;
    }
}

if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $meta_key, $single = false ) {
        if ( isset( $GLOBALS['tcn_user_meta'][ $user_id ][ $meta_key ] ) ) {
            return $GLOBALS['tcn_user_meta'][ $user_id ][ $meta_key ];
        }

        return '';
    }
}

if ( ! function_exists( 'delete_user_meta' ) ) {
    function delete_user_meta( $user_id, $meta_key ) {
        if ( isset( $GLOBALS['tcn_user_meta'][ $user_id ][ $meta_key ] ) ) {
            unset( $GLOBALS['tcn_user_meta'][ $user_id ][ $meta_key ] );
        }

        return true;
    }
}

if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user, $capability ) {
        return false;
    }
}

if ( ! class_exists( 'WP_User', false ) ) {
    class WP_User {
        public $ID;
        public $user_login;
        public $user_email;
        public $user_pass;
        public $display_name = '';
        public $first_name = '';
        public $last_name = '';

        public function __construct( $id = 0, $user_login = '', $user_email = '', $user_pass = '' ) {
            $this->ID         = $id;
            $this->user_login = $user_login;
            $this->user_email = $user_email;
            $this->user_pass  = $user_pass;
        }
    }
}

class ArrayBackedRequest extends WP_REST_Request {
    /**
     * @var array<string, mixed>
     */
    private $params;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct( array $params ) {
        parent::__construct();
        $this->params = $params;
    }

    public function get_param( $key ) {
        return $this->params[ $key ] ?? null;
    }
}

class StubPasswordLoginService extends PasswordLoginService {
    /**
     * @var bool
     */
    public $rate_reset = false;

    public function __construct() {
        parent::__construct();

        $this->settings = array(
            'rate_limit'        => 5,
            'rate_limit_window' => 60,
            'token_lifetime'    => WEEK_IN_SECONDS,
        );
    }

    protected function maybe_enforce_https( \WP_REST_Request $request ) {
        return true;
    }

    protected function build_rate_context( string $action, string $identifier ): array {
        return array(
            'key'   => 'test-rate',
            'limit' => 5,
            'ttl'   => 60,
        );
    }

    protected function is_rate_limited( array $context ): bool {
        return false;
    }

    protected function increment_rate_limit( array $context ): void {}

    protected function reset_rate_limit( array $context ): void {
        $this->rate_reset = true;
    }

    protected function issue_login_token( int $user_id ): array {
        return array(
            'token'      => 'login-token',
            'expires_in' => WEEK_IN_SECONDS,
        );
    }

    protected function issue_api_token( int $user_id ): array {
        return array(
            'token'      => 'api-token',
            'expires_in' => HOUR_IN_SECONDS,
        );
    }

    protected function prepare_user_payload( \WP_User $user ): array {
        return array( 'id' => $user->ID );
    }

    protected function get_woocommerce_credentials(): array {
        return array();
    }
}

final class PasswordLoginServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $_SERVER = array();
        $GLOBALS['tcn_test_headers'] = array();
        $GLOBALS['tcn_home_url']     = 'https://example.com';
        $GLOBALS['tcn_test_users']   = array();
        $GLOBALS['tcn_user_meta']    = array();
    }

    public function test_does_not_emit_cors_headers_for_unconfigured_third_party_origin(): void {
        $headers = $this->serve_request(
            array( 'allowed_origin' => '' ),
            'https://malicious.test'
        );

        $this->assertSame(array(), $headers);
    }

    public function test_emits_cors_headers_for_site_origin_when_unconfigured(): void {
        $headers = $this->serve_request(
            array( 'allowed_origin' => '' ),
            'https://example.com'
        );

        $this->assertContains('Access-Control-Allow-Origin: https://example.com', $headers);
        $this->assertContains('Access-Control-Allow-Credentials: true', $headers);
    }

    public function test_emits_cors_headers_for_configured_origin(): void {
        $headers = $this->serve_request(
            array( 'allowed_origin' => 'https://app.test' ),
            'https://app.test'
        );

        $this->assertContains('Access-Control-Allow-Origin: https://app.test', $headers);
    }

    public function test_login_accepts_email_when_provided_for_username_and_email(): void {
        $user = new WP_User( 42, 'john_doe', 'john@example.com', 'secret' );

        $GLOBALS['tcn_test_users'] = array( $user );

        $service = new StubPasswordLoginService();

        $request = new ArrayBackedRequest(
            array(
                'username' => 'john@example.com',
                'email'    => 'john@example.com',
                'password' => 'secret',
            )
        );

        $result = $service->handle_login( $request );

        $this->assertSame( false, is_wp_error( $result ) );
        $this->assertSame( true, $service->rate_reset );
        $this->assertSame( true, $result['success'] );
        $this->assertSame( array( 'id' => 42 ), $result['user'] );
        $this->assertSame( 'login-token', $result['token'] );
        $this->assertSame( 'api-token', $result['api_token'] );
        $this->assertSame( HOUR_IN_SECONDS, $result['expires_in'] );
        $this->assertSame(
            'https://example.com/wp-login.php?action=gn_token_login&token=login-token',
            $result['token_login_url']
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, string>
     */
    private function serve_request( array $settings, string $origin ): array {
        $_SERVER['HTTP_ORIGIN'] = $origin;

        $service = new PasswordLoginService();

        $reflection = new ReflectionProperty( PasswordLoginService::class, 'settings' );
        $reflection->setAccessible( true );
        $reflection->setValue( $service, $settings );

        $request = new WP_REST_Request();
        $request->set_route( '/gn/v1/login' );

        $service->filter_pre_serve_request( false, null, $request, null );

        return $GLOBALS['tcn_test_headers'];
    }
}
