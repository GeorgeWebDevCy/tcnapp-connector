<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if ( ! class_exists( AssertionFailedError::class, false ) ) {
        class AssertionFailedError extends \RuntimeException {}
    }

    if ( ! class_exists( TestCase::class, false ) ) {
        abstract class TestCase {
            protected function setUp(): void {}

            protected function tearDown(): void {}

            public function runTestMethod( string $method ): void {
                $this->setUp();

                try {
                    $this->$method();
                } finally {
                    $this->tearDown();
                }
            }

            protected function fail( string $message ): void {
                throw new AssertionFailedError( $message );
            }

            protected function assertSame( $expected, $actual, string $message = '' ): void {
                if ( $expected !== $actual ) {
                    $message = $message ?: sprintf(
                        'Failed asserting that %s is identical to %s.',
                        var_export( $actual, true ),
                        var_export( $expected, true )
                    );

                    $this->fail( $message );
                }
            }

            protected function assertContains( $needle, $haystack, string $message = '' ): void {
                $found = false;

                if ( is_array( $haystack ) ) {
                    $found = in_array( $needle, $haystack, true );
                } elseif ( is_string( $haystack ) ) {
                    $found = false !== strpos( $haystack, (string) $needle );
                }

                if ( ! $found ) {
                    $message = $message ?: sprintf(
                        'Failed asserting that %s contains %s.',
                        var_export( $haystack, true ),
                        var_export( $needle, true )
                    );

                    $this->fail( $message );
                }
            }
        }
    }
}

namespace {
    if ( ! class_exists( 'WP_Error', false ) ) {
        class WP_Error {
            public function __construct( $code = '', $message = '', $data = array() ) {}
        }
    }

    if ( ! class_exists( 'WP_REST_Request', false ) ) {
        class WP_REST_Request {
            /**
             * @var array<string, string>
             */
            private $headers = array();

            /**
             * @var string
             */
            private $route = '';

            public function __construct( array $headers = array(), string $route = '' ) {
                foreach ( $headers as $key => $value ) {
                    $this->headers[ strtolower( (string) $key ) ] = (string) $value;
                }

                $this->route = $route;
            }

            public function get_header( $key ) {
                $key = strtolower( (string) $key );

                return $this->headers[ $key ] ?? '';
            }

            public function set_route( string $route ): void {
                $this->route = $route;
            }

            public function get_route(): string {
                return $this->route;
            }
        }
    }

    if ( ! function_exists( 'wp_unslash' ) ) {
        function wp_unslash( $value ) {
            return $value;
        }
    }

    if ( ! function_exists( 'esc_url_raw' ) ) {
        function esc_url_raw( $url ) {
            return $url;
        }
    }

    if ( ! function_exists( '__' ) ) {
        function __( $text ) {
            return $text;
        }
    }

    if ( ! function_exists( 'home_url' ) ) {
        function home_url() {
            return $GLOBALS['tcn_home_url'] ?? '';
        }
    }

    if ( ! function_exists( 'wp_parse_url' ) ) {
        function wp_parse_url( $url ) {
            return parse_url( $url );
        }
    }

    if ( ! function_exists( 'rest_authorization_required_code' ) ) {
        function rest_authorization_required_code() {
            return 401;
        }
    }

    if ( ! function_exists( 'get_user_by' ) ) {
        function get_user_by( $field, $value ) {
            if ( 'id' === strtolower( (string) $field ) && 123 === (int) $value ) {
                return (object) array( 'ID' => 123 );
            }

            return false;
        }
    }

    if ( ! function_exists( 'wp_set_current_user' ) ) {
        function wp_set_current_user( $user_id ) {
            $GLOBALS['current_user_id'] = (int) $user_id;
        }
    }

    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $thing ) {
            return $thing instanceof WP_Error;
        }
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/Auth/TokenAuthenticator.php';
}

namespace TCN\Platform\Auth {
    if ( ! function_exists( __NAMESPACE__ . '\\headers_sent' ) ) {
        function headers_sent() {
            return false;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\header' ) ) {
        function header( $string ) {
            if ( ! isset( $GLOBALS['tcn_test_headers'] ) || ! is_array( $GLOBALS['tcn_test_headers'] ) ) {
                $GLOBALS['tcn_test_headers'] = array();
            }

            $GLOBALS['tcn_test_headers'][] = $string;
        }
    }
}
