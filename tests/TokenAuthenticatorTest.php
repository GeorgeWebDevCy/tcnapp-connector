<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TCN\Platform\Auth\TokenAuthenticator;

final class TokenAuthenticatorTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $_SERVER = array();
        unset( $GLOBALS['current_user_id'] );
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

    public function test_authenticates_with_authorization_header_fallback(): void {
        $_SERVER['AUTHORIZATION'] = 'Bearer alt-token';

        $authenticator = $this->create_authenticator_expectation( 'alt-token' );
        $request       = new WP_REST_Request();

        $result = $authenticator->authenticate_request( $request );

        $this->assertSame( 123, $result );
        $this->assertSame( 123, $GLOBALS['current_user_id'] ?? null );
        $this->assertContains( md5( 'alt-token' ), $authenticator->received_token_hashes );
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
                // Silence debug output during tests.
            }
        };
    }
}
