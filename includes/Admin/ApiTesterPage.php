<?php
namespace TCN\Platform\Admin;

use WP_Error;
use function __;
use function add_action;
use function add_submenu_page;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url_raw;
use function home_url;
use function is_array;
use function is_scalar;
use function is_wp_error;
use function sanitize_text_field;
use function selected;
use function wp_die;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_headers;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_response_message;
use function wp_parse_url;
use function wp_unslash;

class ApiTesterPage {
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'tcn-platform',
            __( 'API Tester', 'tcnapp-connector' ),
            __( 'API Tester', 'tcnapp-connector' ),
            'manage_options',
            'tcn-platform-api-tester',
            array( $this, 'render_page' )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tcnapp-connector' ) );
        }

        if ( ! RestrictedAccess::require_access( 'tcn-platform-api-tester', __( 'API Tester', 'tcnapp-connector' ) ) ) {
            return;
        }

        $method        = 'GET';
        $endpoint      = '';
        $headers_input = '';
        $body_input    = '';
        $errors        = array();
        $result        = null;
        $response      = null;
        $request_url   = '';
        $request_args  = array();

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['tcn_platform_api_tester_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            check_admin_referer( 'tcn_platform_api_tester', 'tcn_platform_api_tester_nonce' );

            $method        = isset( $_POST['request_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['request_method'] ) ) ) : 'GET';
            $endpoint      = isset( $_POST['request_url'] ) ? trim( wp_unslash( $_POST['request_url'] ) ) : '';
            $headers_input = isset( $_POST['request_headers'] ) ? trim( wp_unslash( $_POST['request_headers'] ) ) : '';
            $body_input    = isset( $_POST['request_body'] ) ? (string) wp_unslash( $_POST['request_body'] ) : '';

            if ( '' === $method ) {
                $method = 'GET';
            }

            $allowed_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
            if ( ! in_array( $method, $allowed_methods, true ) ) {
                $method = 'GET';
            }

            $request_url = $this->prepare_url( $endpoint );
            if ( '' === $request_url ) {
                $errors[] = esc_html__( 'Please provide a valid endpoint URL.', 'tcnapp-connector' );
            }

            $headers = array();
            if ( '' !== $headers_input ) {
                $decoded_headers = json_decode( $headers_input, true );
                if ( null === $decoded_headers || ! is_array( $decoded_headers ) ) {
                    $errors[] = esc_html__( 'Headers must be provided as a valid JSON object.', 'tcnapp-connector' );
                } else {
                    foreach ( $decoded_headers as $key => $value ) {
                        if ( '' === $key ) {
                            continue;
                        }

                        if ( is_array( $value ) || is_object( $value ) ) {
                            $value = wp_json_encode( $value );
                        }

                        if ( ! is_scalar( $value ) ) {
                            continue;
                        }

                        $headers[ $key ] = (string) $value;
                    }
                }
            }

            if ( empty( $errors ) ) {
                $request_args = array(
                    'method'      => $method,
                    'headers'     => $headers,
                    'timeout'     => 20,
                    'redirection' => 2,
                );

                if ( '' !== $body_input ) {
                    $request_args['body'] = $body_input;
                }

                $result = wp_remote_request( $request_url, $request_args );

                if ( is_wp_error( $result ) ) {
                    $response = $result;
                } else {
                    $response = array(
                        'code'    => wp_remote_retrieve_response_code( $result ),
                        'message' => wp_remote_retrieve_response_message( $result ),
                        'headers' => $this->normalize_headers( wp_remote_retrieve_headers( $result ) ),
                        'body'    => wp_remote_retrieve_body( $result ),
                    );
                }
            }
        }

        $examples = $this->get_examples();

        ?>
        <div class="wrap tcn-platform-api-tester">
            <div class="tcn-platform-page-intro">
                <h1><?php esc_html_e( 'TCN Platform API Tester', 'tcnapp-connector' ); ?></h1>
                <p class="description tcn-platform-page-subtitle">
                    <?php esc_html_e( 'Send requests to the plugin\'s REST endpoints without leaving the WordPress dashboard. Use the form below to craft a request, then review the response details.', 'tcnapp-connector' ); ?>
                </p>
            </div>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="notice notice-error">
                    <ul>
                        <?php foreach ( $errors as $error ) : ?>
                            <li><?php echo esc_html( $error ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="tcn-platform-api-form tcn-platform-panel">
                <?php wp_nonce_field( 'tcn_platform_api_tester', 'tcn_platform_api_tester_nonce' ); ?>

                <div class="tcn-platform-api-grid">
                    <div class="tcn-platform-api-field">
                        <label for="request_method" class="tcn-platform-api-label"><?php esc_html_e( 'HTTP Method', 'tcnapp-connector' ); ?></label>
                        <select id="request_method" name="request_method">
                            <?php foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $option_method ) : ?>
                                <option value="<?php echo esc_attr( $option_method ); ?>" <?php selected( $method, $option_method ); ?>><?php echo esc_html( $option_method ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tcn-platform-api-field is-wide">
                        <label for="request_url" class="tcn-platform-api-label"><?php esc_html_e( 'Endpoint URL', 'tcnapp-connector' ); ?></label>
                        <input type="text" id="request_url" name="request_url" class="regular-text" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-json/gn/v1/login' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Enter a full URL or a relative path such as /wp-json/gn/v1/login.', 'tcnapp-connector' ); ?></p>
                    </div>
                </div>

                <div class="tcn-platform-api-field">
                    <label for="request_headers" class="tcn-platform-api-label"><?php esc_html_e( 'Headers (JSON object)', 'tcnapp-connector' ); ?></label>
                    <textarea id="request_headers" name="request_headers" rows="4" placeholder="{&#10;  &quot;Content-Type&quot;: &quot;application/json&quot; &#10;}"><?php echo esc_textarea( $headers_input ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Use JSON format to provide any headers (for example, authentication or content-type). Leave blank if none are required.', 'tcnapp-connector' ); ?></p>
                </div>

                <div class="tcn-platform-api-field">
                    <label for="request_body" class="tcn-platform-api-label"><?php esc_html_e( 'Request Body', 'tcnapp-connector' ); ?></label>
                    <textarea id="request_body" name="request_body" rows="8" placeholder="{&#10;  &quot;username&quot;: &quot;demo@example.com&quot;,&#10;  &quot;password&quot;: &quot;secret&quot;&#10;}"><?php echo esc_textarea( $body_input ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Provide the raw request payload. JSON is common for these endpoints, but you can use any format required by the route.', 'tcnapp-connector' ); ?></p>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Send Request', 'tcnapp-connector' ); ?></button>
                    <button type="button" class="button tcn-platform-api-reset"><?php esc_html_e( 'Clear', 'tcnapp-connector' ); ?></button>
                </p>
            </form>

            <?php if ( null !== $response && empty( $errors ) ) : ?>
                <div class="tcn-platform-panel tcn-platform-api-response">
                    <h2><?php esc_html_e( 'Response', 'tcnapp-connector' ); ?></h2>
                    <?php if ( $response instanceof WP_Error ) : ?>
                        <div class="notice notice-error">
                            <p><strong><?php esc_html_e( 'Request failed:', 'tcnapp-connector' ); ?></strong> <?php echo esc_html( $response->get_error_message() ); ?></p>
                            <?php $error_data = $response->get_error_data(); ?>
                            <?php if ( ! empty( $error_data ) && is_scalar( $error_data ) ) : ?>
                                <pre><?php echo esc_html( (string) $error_data ); ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <div class="tcn-platform-api-meta">
                            <div>
                                <span class="tcn-platform-api-meta-label"><?php esc_html_e( 'Endpoint', 'tcnapp-connector' ); ?></span>
                                <span class="tcn-platform-api-meta-value"><?php echo esc_html( $method ); ?> <?php echo esc_html( $request_url ); ?></span>
                            </div>
                            <div>
                                <span class="tcn-platform-api-meta-label"><?php esc_html_e( 'Status', 'tcnapp-connector' ); ?></span>
                                <span class="tcn-platform-api-status code-<?php echo esc_attr( $response['code'] ); ?>">
                                    <?php echo esc_html( $response['code'] ); ?>
                                    <?php if ( ! empty( $response['message'] ) ) : ?>
                                        <small><?php echo esc_html( $response['message'] ); ?></small>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <?php if ( ! empty( $request_args ) ) : ?>
                            <details class="tcn-platform-api-details">
                                <summary><?php esc_html_e( 'View request details', 'tcnapp-connector' ); ?></summary>
                                <div class="tcn-platform-api-details-grid">
                                    <div>
                                        <h3><?php esc_html_e( 'Headers', 'tcnapp-connector' ); ?></h3>
                                        <?php if ( empty( $request_args['headers'] ) ) : ?>
                                            <p><?php esc_html_e( 'No headers sent.', 'tcnapp-connector' ); ?></p>
                                        <?php else : ?>
                                            <pre><?php echo esc_html( wp_json_encode( $request_args['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '' ); ?></pre>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3><?php esc_html_e( 'Body', 'tcnapp-connector' ); ?></h3>
                                        <?php if ( empty( $request_args['body'] ) ) : ?>
                                            <p><?php esc_html_e( 'No body sent.', 'tcnapp-connector' ); ?></p>
                                        <?php else : ?>
                                            <pre><?php echo esc_html( $this->format_body_for_display( (string) $request_args['body'] ) ); ?></pre>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                        <?php endif; ?>

                        <details class="tcn-platform-api-details" open>
                            <summary><?php esc_html_e( 'View response headers', 'tcnapp-connector' ); ?></summary>
                            <?php if ( empty( $response['headers'] ) ) : ?>
                                <p><?php esc_html_e( 'No response headers returned.', 'tcnapp-connector' ); ?></p>
                            <?php else : ?>
                                <pre><?php echo esc_html( wp_json_encode( $response['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '' ); ?></pre>
                            <?php endif; ?>
                        </details>

                        <details class="tcn-platform-api-details" open>
                            <summary><?php esc_html_e( 'View response body', 'tcnapp-connector' ); ?></summary>
                            <?php if ( '' === $response['body'] ) : ?>
                                <p><?php esc_html_e( 'The response body is empty.', 'tcnapp-connector' ); ?></p>
                            <?php else : ?>
                                <pre><?php echo esc_html( $this->format_body_for_display( $response['body'] ) ); ?></pre>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tcn-platform-panel tcn-platform-api-examples">
                <h2><?php esc_html_e( 'Example requests', 'tcnapp-connector' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Use these ready-made examples to explore key endpoints. Click “Load in tester” to populate the form above.', 'tcnapp-connector' ); ?></p>
                <div class="tcn-platform-api-examples-grid">
                    <?php foreach ( $examples as $example ) : ?>
                        <div class="tcn-platform-api-example" data-url="<?php echo esc_attr( $example['url'] ); ?>" data-method="<?php echo esc_attr( $example['method'] ); ?>" data-headers="<?php echo esc_attr( $example['headers'] ); ?>" data-body="<?php echo esc_attr( $example['body'] ); ?>">
                            <div class="tcn-platform-api-example-header">
                                <span class="tcn-platform-api-method tcn-platform-api-method-<?php echo esc_attr( strtolower( $example['method'] ) ); ?>"><?php echo esc_html( $example['method'] ); ?></span>
                                <code class="tcn-platform-api-path"><?php echo esc_html( $this->abbreviate_url( $example['url'] ) ); ?></code>
                            </div>
                            <p><?php echo esc_html( $example['description'] ); ?></p>
                            <?php if ( ! empty( $example['note'] ) ) : ?>
                                <p class="description"><?php echo esc_html( $example['note'] ); ?></p>
                            <?php endif; ?>
                            <details>
                                <summary><?php esc_html_e( 'Sample response', 'tcnapp-connector' ); ?></summary>
                                <pre><?php echo esc_html( $example['response'] ); ?></pre>
                            </details>
                            <p>
                                <button type="button" class="button button-secondary tcn-platform-api-example-fill"><?php esc_html_e( 'Load in tester', 'tcnapp-connector' ); ?></button>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function prepare_url( string $endpoint ): string {
        $endpoint = trim( $endpoint );

        if ( '' === $endpoint ) {
            return '';
        }

        if ( preg_match( '#^https?://#i', $endpoint ) ) {
            return esc_url_raw( $endpoint );
        }

        if ( 0 === strpos( $endpoint, '//' ) ) {
            $endpoint = 'https:' . $endpoint;
            return esc_url_raw( $endpoint );
        }

        if ( 0 !== strpos( $endpoint, '/' ) ) {
            $endpoint = '/' . $endpoint;
        }

        return home_url( $endpoint );
    }

    /**
     * @param mixed $headers Raw headers from wp_remote_request.
     *
     * @return array<string, string>
     */
    protected function normalize_headers( $headers ): array {
        if ( empty( $headers ) ) {
            return array();
        }

        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        if ( ! is_array( $headers ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $headers as $key => $value ) {
            if ( is_array( $value ) ) {
                $normalized[ (string) $key ] = implode( ', ', array_map( 'strval', $value ) );
            } else {
                $normalized[ (string) $key ] = (string) $value;
            }
        }

        ksort( $normalized );

        return $normalized;
    }

    protected function format_body_for_display( string $body ): string {
        $decoded = json_decode( $body, true );
        if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
            $pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            if ( $pretty ) {
                return $pretty;
            }
        }

        return $body;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function get_examples(): array {
        $login_url        = home_url( '/wp-json/gn/v1/login' );
        $register_url     = home_url( '/wp-json/gn/v1/register' );
        $forgot_url       = home_url( '/wp-json/gn/v1/forgot-password' );
        $reset_url        = home_url( '/wp-json/gn/v1/reset-password' );
        $change_url       = home_url( '/wp-json/gn/v1/change-password' );
        $avatar_url       = home_url( '/wp-json/gn/v1/profile/avatar' );
        $me_url           = home_url( '/wp-json/gn/v1/me' );
        $log_url          = home_url( '/wp-json/gn/v1/log' );
        $plans_url        = home_url( '/wp-json/gn/v1/memberships/plans' );
        $stripe_intent    = home_url( '/wp-json/gn/v1/memberships/stripe-intent' );
        $confirm_upgrade  = home_url( '/wp-json/gn/v1/memberships/confirm' );
        $member_url       = home_url( '/wp-json/tcn-mlm/v1/member' );
        $genealogy_url    = home_url( '/wp-json/tcn-mlm/v1/genealogy' );
        $commissions_url  = home_url( '/wp-json/tcn-mlm/v1/commissions' );
        $customer_url     = home_url( '/wp-json/gn/v1/customers' );
        $orders_url       = home_url( '/wp-json/gn/v1/orders' );

        return array(
            array(
                'method'     => 'POST',
                'url'        => $login_url,
                'description'=> __( 'Authenticate a user with the Password Login API module.', 'tcnapp-connector' ),
                'note'       => __( 'Requires valid WordPress user credentials.', 'tcnapp-connector' ),
                'headers'    => wp_json_encode( array( 'Content-Type' => 'application/json' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'       => wp_json_encode(
                    array(
                        'username' => 'demo@example.com',
                        'password' => 'secret-password',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'   => wp_json_encode(
                    array(
                        'success'    => true,
                        'token'      => 'example-token',
                        'api_token'  => 'example-api-token',
                        'expires_in' => 604800,
                        'user'       => array(
                            'id'    => 123,
                            'email' => 'demo@example.com',
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $register_url,
                'description' => __( 'Create a new account using the registration endpoint.', 'tcnapp-connector' ),
                'note'        => __( 'Open endpoint; validation rules from WordPress apply.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'Content-Type' => 'application/json' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'username'   => 'newuser',
                        'email'      => 'newuser@example.com',
                        'password'   => 'ChooseAStrongPassword!',
                        'first_name' => 'Taylor',
                        'last_name'  => 'Jordan',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'success' => true,
                        'user_id' => 123,
                        'message' => __( 'Registration successful.', 'tcnapp-connector' ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $forgot_url,
                'description' => __( 'Trigger the password reset workflow.', 'tcnapp-connector' ),
                'note'        => __( 'Optional flag returns a verification code for in-app flows.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'Content-Type' => 'application/json' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'username'                 => 'demo@example.com',
                        'return_verification_code' => true,
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'success'            => true,
                        'verification_code'  => '123456',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $reset_url,
                'description' => __( 'Complete a password reset using a verification code.', 'tcnapp-connector' ),
                'note'        => __( 'Use the code returned from /forgot-password or a WordPress reset key.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'Content-Type' => 'application/json' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'login'              => 'demo@example.com',
                        'verification_code'  => '123456',
                        'password'           => 'NewSecurePassword!1',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'success' => true,
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $change_url,
                'description' => __( 'Change the logged-in user password.', 'tcnapp-connector' ),
                'note'        => __( 'Requires an authenticated session or X-WP-Nonce header.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Content-Type' => 'application/json',
                        'X-WP-Nonce'   => 'paste-nonce-here',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'current_password' => 'secret-password',
                        'password'         => 'NewSecurePassword!1',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'success' => true,
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $me_url,
                'description' => __( 'Fetch the authenticated user payload using an API token.', 'tcnapp-connector' ),
                'note'        => __( 'Requires Authorization: Bearer header with a valid API token issued by /login.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Authorization' => 'Bearer paste-api-token-here',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'user' => array(
                            'id'         => 123,
                            'email'      => 'member@example.com',
                            'first_name' => 'Member',
                            'last_name'  => 'Example',
                            'roles'      => array( 'customer' ),
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $avatar_url,
                'description' => __( 'Upload a profile photo and refresh the user session payload.', 'tcnapp-connector' ),
                'note'        => __( 'Requires Authorization: Bearer token or authenticated cookies plus multipart/form-data with an avatar file.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Authorization' => 'Bearer paste-login-token',
                        'Content-Type'  => 'multipart/form-data',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => sprintf(
                    /* translators: %s: Example REST endpoint URL. */
                    __( "This endpoint expects multipart/form-data. Example curl command:\n\ncurl -X POST \\\n  -H \"Authorization: Bearer paste-login-token\" \\\n  -F \"avatar=@/path/to/avatar.jpg\" \\\n  \"%s\"", 'tcnapp-connector' ),
                    $avatar_url
                ),
                'response'    => wp_json_encode(
                    array(
                        'id'          => 123,
                        'email'       => 'member@example.com',
                        'name'        => 'Member Example',
                        'first_name'  => 'Member',
                        'last_name'   => 'Example',
                        'avatar_urls' => array(
                            '48' => 'https://example.com/avatar-48x48.jpg',
                            '96' => 'https://example.com/avatar-96x96.jpg',
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $log_url,
                'description' => __( 'Send diagnostic log entries from remote clients.', 'tcnapp-connector' ),
                'note'        => __( 'Public endpoint; include contextual fields to help with troubleshooting.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Content-Type' => 'application/json',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'log_level'   => 'info',
                        'log_source'  => 'mobile-app',
                        'log_message' => 'User opened the dashboard.',
                        'log_params'  => array(
                            'user_id' => 123,
                            'screen'  => 'dashboard',
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'ok' => true,
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $plans_url,
                'description' => __( 'Retrieve active membership plans exposed by the plugin.', 'tcnapp-connector' ),
                'note'        => __( 'Public endpoint returning plan metadata.', 'tcnapp-connector' ),
                'headers'     => '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'currency'        => 'THB',
                        'publishableKey'  => 'pk_test_xxx',
                        'plans' => array(
                            array(
                                'id'          => 'blue',
                                'name'        => 'Blue Membership',
                                'fee'         => 0,
                                'price'       => 0,
                                'formatted_fee' => 'THB 0.00',
                                'amount_minor'  => 0,
                                'benefits'    => array( 'Basic customer account' ),
                            ),
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $stripe_intent,
                'description' => __( 'Create a Stripe payment intent for a paid upgrade.', 'tcnapp-connector' ),
                'note'        => __( 'Requires login and configured Stripe secret key.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Content-Type' => 'application/json',
                        'X-WP-Nonce'   => 'paste-nonce-here',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'plan' => 'gold',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'id'            => 'pi_example',
                        'client_secret' => 'pi_example_secret_123',
                        'status'        => 'requires_confirmation',
                        'metadata'      => array(
                            'plan'    => 'gold',
                            'user_id' => 123,
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $confirm_upgrade,
                'description' => __( 'Confirm a membership upgrade after payment.', 'tcnapp-connector' ),
                'note'        => __( 'Requires login; include the Stripe payment intent ID for paid plans.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Content-Type' => 'application/json',
                        'X-WP-Nonce'   => 'paste-nonce-here',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'plan'           => 'platinum',
                        'payment_intent' => 'pi_example',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'success' => true,
                        'level'   => 'platinum',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $member_url,
                'description' => __( 'Fetch the authenticated member profile.', 'tcnapp-connector' ),
                'note'        => __( 'Requires login via cookie or X-WP-Nonce header.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'X-WP-Nonce' => 'paste-nonce-here' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'user' => array(
                            'id'               => 123,
                            'display_name'     => 'Taylor Jordan',
                            'email'            => 'demo@example.com',
                            'membership_level' => 'gold',
                            'direct_recruits'  => 2,
                            'sponsor_id'       => 42,
                        ),
                        'commissions' => array(
                            'total'   => 250,
                            'paid'    => 125,
                            'pending' => 125,
                        ),
                        'ledger' => array(),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $genealogy_url . '?depth=3',
                'description' => __( 'Render a genealogy tree for the current member.', 'tcnapp-connector' ),
                'note'        => __( 'Requires login; depth defaults to 3 levels.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'X-WP-Nonce' => 'paste-nonce-here' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'id'       => 123,
                        'name'     => 'Taylor Jordan',
                        'level'    => 'gold',
                        'recruits' => 2,
                        'children' => array(),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $commissions_url,
                'description' => __( 'List the commission summary and ledger.', 'tcnapp-connector' ),
                'note'        => __( 'Requires login via cookie or nonce.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode( array( 'X-WP-Nonce' => 'paste-nonce-here' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'summary' => array(
                            'total'   => 250,
                            'paid'    => 125,
                            'pending' => 125,
                        ),
                        'ledger' => array(),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'GET',
                'url'         => $customer_url . '?email=customer%40example.com',
                'description' => __( 'Look up a WooCommerce customer by email address.', 'tcnapp-connector' ),
                'note'        => __( 'Requires WooCommerce REST authentication (consumer key/secret).', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Authorization' => 'Basic base64encodedcredentials',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => '',
                'response'    => wp_json_encode(
                    array(
                        'id'         => 456,
                        'email'      => 'customer@example.com',
                        'first_name' => 'Sky',
                        'last_name'  => 'River',
                        'billing'    => array(),
                        'shipping'   => array(),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $customer_url,
                'description' => __( 'Create a WooCommerce customer account.', 'tcnapp-connector' ),
                'note'        => __( 'Authenticate with a REST consumer key/secret that has write access.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Authorization' => 'Basic base64encodedcredentials',
                        'Content-Type'  => 'application/json',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'email'      => 'newcustomer@example.com',
                        'first_name' => 'Sky',
                        'last_name'  => 'River',
                        'billing'    => array(
                            'phone' => '+66-0000-0000',
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'id'        => 789,
                        'email'     => 'newcustomer@example.com',
                        'first_name'=> 'Sky',
                        'last_name' => 'River',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
            array(
                'method'      => 'POST',
                'url'         => $orders_url,
                'description' => __( 'Create a WooCommerce order with line items.', 'tcnapp-connector' ),
                'note'        => __( 'Requires consumer key/secret authentication with write permissions.', 'tcnapp-connector' ),
                'headers'     => wp_json_encode(
                    array(
                        'Authorization' => 'Basic base64encodedcredentials',
                        'Content-Type'  => 'application/json',
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'body'        => wp_json_encode(
                    array(
                        'customer_id' => 789,
                        'set_paid'    => true,
                        'line_items'  => array(
                            array(
                                'product_id' => 1234,
                                'quantity'   => 1,
                            ),
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
                'response'    => wp_json_encode(
                    array(
                        'id'   => 555,
                        'data' => array(
                            'status' => 'completed',
                        ),
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) ?: '',
            ),
        );
    }

    protected function abbreviate_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed ) ) {
            return $url;
        }

        $path  = isset( $parsed['path'] ) ? $parsed['path'] : '';
        $query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

        if ( '' === $path ) {
            return $url;
        }

        return $path . $query;
    }
}
