<?php
declare(strict_types=1);

namespace {
    if ( ! function_exists( 'sanitize_key' ) ) {
        function sanitize_key( $key ) {
            $key = strtolower( (string) $key );
            $key = preg_replace( '/[^a-z0-9_]/', '', $key );

            return $key;
        }
    }

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) {
            return trim( (string) $value );
        }
    }

    if ( ! function_exists( 'wp_parse_args' ) ) {
        function wp_parse_args( $args, $defaults = array() ) {
            if ( ! is_array( $args ) ) {
                $args = array();
            }

            if ( ! is_array( $defaults ) ) {
                $defaults = array();
            }

            return $args + $defaults;
        }
    }

    if ( ! function_exists( 'absint' ) ) {
        function absint( $value ) {
            return abs( (int) $value );
        }
    }

    if ( ! function_exists( 'get_option' ) ) {
        function get_option( $name, $default = false ) {
            return $GLOBALS['tcn_test_options'][ $name ] ?? $default;
        }
    }

    if ( ! function_exists( 'update_option' ) ) {
        function update_option( $name, $value ) {
            $GLOBALS['tcn_test_options'][ $name ] = $value;
        }
    }

    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
            return $GLOBALS['current_user_id'] ?? 0;
        }
    }

    if ( ! function_exists( 'has_filter' ) ) {
        function has_filter() {
            return false;
        }
    }

    if ( ! function_exists( 'do_action' ) ) {
        function do_action() {}
    }

    if ( ! function_exists( 'apply_filters' ) ) {
        function apply_filters( $hook, $value ) {
            return $value;
        }
    }

    if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
        define( 'WEEK_IN_SECONDS', 7 * 24 * 60 * 60 );
    }
}

namespace TCN\Platform\Tests {
    use PHPUnit\Framework\TestCase;
    use TCN\Platform\Membership\MembershipModule;
    use TCN\Platform\Support\Options;

    require_once __DIR__ . '/../includes/Support/WPML.php';
    require_once __DIR__ . '/../includes/Support/Options.php';
    require_once __DIR__ . '/../includes/Membership/MembershipModule.php';

    class TestRestRequest extends \WP_REST_Request {
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

    class StubMembershipModule extends MembershipModule {
        /**
         * @var array<int, mixed>
         */
        private $stripe_responses;

        public function __construct( array $stripe_responses ) {
            parent::__construct();
            $this->stripe_responses = $stripe_responses;
        }

        protected function stripe_request( string $method, string $path, array $body, string $secret ) {
            return array_shift( $this->stripe_responses );
        }

        public function ensure_sponsor_assignment( int $user_id ): void {}

        public function set_membership_level( int $user_id, string $level ): void {}

        public function record_commissions( int $user_id, int $order_id, string $level ): void {}
    }

    class MembershipModuleTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['tcn_test_options'] = array(
                Options::OPTION_GENERAL => array(
                    'currency' => 'thb',
                    'stripe_secret_key' => 'sk_test_123',
                ),
                Options::OPTION_LEVELS  => array(
                    'gold' => array(
                        'name' => 'Gold',
                        'slug' => 'gold',
                        'rank' => 1,
                        'fee'  => 500,
                    ),
                ),
            );

            $GLOBALS['current_user_id'] = 321;
        }

        public function test_confirm_membership_upgrade_fails_with_mismatched_user_metadata(): void {
            $request = new TestRestRequest(
                array(
                    'plan'           => 'gold',
                    'payment_intent' => 'pi_test',
                )
            );

            $module = new StubMembershipModule(
                array(
                    array(
                        'status'          => 'succeeded',
                        'amount_received' => 50000,
                        'currency'        => 'thb',
                        'metadata'        => array(
                            'plan'    => 'gold',
                            'user_id' => 999,
                        ),
                    ),
                )
            );

            $result = $module->rest_confirm_membership_upgrade( $request );

            $this->assertSame( true, is_wp_error( $result ) );
        }

        public function test_confirm_membership_upgrade_succeeds_with_matching_user_metadata(): void {
            $request = new TestRestRequest(
                array(
                    'plan'           => 'gold',
                    'payment_intent' => 'pi_test',
                )
            );

            $module = new StubMembershipModule(
                array(
                    array(
                        'status'          => 'succeeded',
                        'amount_received' => 50000,
                        'currency'        => 'thb',
                        'metadata'        => array(
                            'plan'    => 'gold',
                            'user_id' => 321,
                        ),
                    ),
                )
            );

            $result = $module->rest_confirm_membership_upgrade( $request );

            $this->assertSame( array(
                'success' => true,
                'level'   => 'gold',
            ), $result );
        }
    }
}

