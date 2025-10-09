<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TCN\Platform\Auth\PasswordLoginService;

require_once __DIR__ . '/../includes/Auth/PasswordLoginService.php';

final class PasswordLoginServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $_SERVER = array();
        $GLOBALS['tcn_test_headers'] = array();
        $GLOBALS['tcn_home_url']     = 'https://example.com';
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
