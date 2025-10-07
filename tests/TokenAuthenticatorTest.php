<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TCN\Platform\Auth\TokenAuthenticator;

final class TokenAuthenticatorTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $_SERVER = array();
        unset( $GLOBALS['current_user_id'] );

        if ( ! defined( 'REST_REQUEST' ) ) {
            define( 'REST_REQUEST', true );
        }
    }

    public function test_authenticates_with_redirect_header_fallback(): void {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';

        $authenticator = $this->create_authenticator_expectation( 'redirect-token' );
        $request       = new WP_REST_Request();

        $result = $authenticator->authenticate_request( $request );

        $this->assertSame( 123, $result );
        $this->assertSame( 123, $GLOBALS['current_user_id'] ?? null );
        $this->assertContains( md5( 'redirect-token' ), $authenticator->received_token_hashes );
    }

    public function test_authenticates_with_authorization_header(): void {
        $authenticator = $this->create_authenticator_expectation( 'header-token' );
        $request       = new WP_REST_Request(
            array(
                'Authorization' => 'Bearer header-token',
            )
        );

        $result = $authenticator->authenticate_request( $request );

        $this->assertSame( 123, $result );
        $this->assertSame( 123, $GLOBALS['current_user_id'] ?? null );
        $this->assertContains( md5( 'header-token' ), $authenticator->received_token_hashes );
    }

    public function test_authenticates_with_authorization_header_fallback(): void {
        $_SERVER['AUTHORIZATION'] = 'Bearer alt-token';

        $authenticator = $this->create_authenticator_expectation( 'alt-token' );
        $request       = new WP_REST_Request();

        $result = $authenticator->authenticate_request( $request );

        $this->assertSame( 123, $result );
        $this->assertSame( 123, $GLOBALS['current_user_id'] ?? null );
        $this->assertContains( md5( 'alt-token' ), $authenticator->received_token_hashes );
    }

    public function test_rejects_non_bearer_authorization_header(): void {
        $authenticator = $this->create_authenticator_expectation( 'unused-token' );
        $request       = new WP_REST_Request(
            array(
                'Authorization' => 'Basic ZGVtbzp0ZXN0',
            )
        );

        $result = $authenticator->authenticate_request( $request );

        $this->assertSame( 'WP_Error', get_class( $result ) );

        foreach ( $authenticator->logged_messages as $log_entry ) {
            if ( isset( $log_entry['message'] ) && 'authenticate_request header missing bearer token prefix' === $log_entry['message'] ) {
                $this->fail( 'Expected Basic authorization header not to trigger bearer prefix debug log.' );
            }
        }
    }

    public function test_determine_current_user_sets_user_from_bearer_token(): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer server-token';

        $authenticator = $this->create_authenticator_expectation( 'server-token' );

        $result = $authenticator->determine_current_user( 0 );

        $this->assertSame( 123, $result );
        $this->assertSame( 123, $GLOBALS['current_user_id'] ?? null );
        $this->assertContains( md5( 'server-token' ), $authenticator->received_token_hashes );
    }

    public function test_determine_current_user_ignores_non_bearer_header(): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ZGVtbzp0ZXN0';

        $authenticator = $this->create_authenticator_expectation( 'unused-token' );

        $result = $authenticator->determine_current_user( 0 );

        $this->assertSame( 0, $result );
    }

    private function create_authenticator_expectation( string $expected_token ): TokenAuthenticator {
        return new class( $expected_token ) extends TokenAuthenticator {
            /**
             * @var string
             */
            private $expected_token;

            /**
             * @var array<int, string>
             */
            public $received_token_hashes = array();

            public function __construct( string $expected_token ) {
                $this->expected_token = $expected_token;
            }

            protected function get_login_token_payload( string $token_hash ) {
                $this->received_token_hashes[] = $token_hash;

                if ( $token_hash === md5( $this->expected_token ) ) {
                    return array( 'user_id' => 123 );
                }

                return false;
            }

            protected function get_api_token_payload( string $token_hash ) {
                $this->received_token_hashes[] = $token_hash;

                return false;
            }

            protected function log_debug( string $message, array $context = array() ): void {
                $this->logged_messages[] = array(
                    'message' => $message,
                    'context' => $context,
                );
            }

            public $logged_messages = array();
        };
    }
}
