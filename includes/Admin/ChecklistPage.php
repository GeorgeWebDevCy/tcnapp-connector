<?php
namespace TCN\Platform\Admin;

use function __;
use function add_action;
use function add_submenu_page;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function wp_die;

class ChecklistPage {
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'tcn-platform',
            __( 'Deployment Checklists', 'tcnapp-connector' ),
            __( 'Deployment Checklists', 'tcnapp-connector' ),
            'manage_options',
            'tcn-platform-checklists',
            array( $this, 'render_page' )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'tcnapp-connector' ) );
        }

        $change_password_payload = <<<'JSON'
{
  "current_password": "oldPass123",
  "password": "newPass456"
}
JSON;

        $curl_examples = array(
            'login'           => implode(
                "\n",
                array(
                    "curl -X POST \"{BASE_URL}/wp-json/gn/v1/login\" \\",
                    "  -H \"Content-Type: application/json\" \\",
                    "  -d '{\"username\":\"YOUR_USER\",\"password\":\"YOUR_PASS\"}'",
                )
            ),
            'change_password' => implode(
                "\n",
                array(
                    "curl -X POST \"{BASE_URL}/wp-json/gn/v1/change-password\" \\",
                    "  -H \"Authorization: Bearer YOUR_TOKEN\" \\",
                    "  -H \"Content-Type: application/json\" \\",
                    "  -d '{\"current_password\":\"oldPass123\",\"password\":\"newPass456\"}'",
                )
            ),
            'upload_avatar'   => implode(
                "\n",
                array(
                    "curl -X POST \"{BASE_URL}/wp-json/gn/v1/profile/avatar\" \\",
                    "  -H \"Authorization: Bearer YOUR_TOKEN\" \\",
                    "  -F \"avatar=@/full/path/to/photo.jpg\"",
                )
            ),
        );

        ?>
        <div class="wrap tcn-platform-checklists">
            <div class="tcn-platform-page-intro">
                <h1><?php esc_html_e( 'TCN Platform Deployment Checklists', 'tcnapp-connector' ); ?></h1>
                <p class="description tcn-platform-page-subtitle">
                    <?php esc_html_e( 'Use these quick guides to validate the password login API, tighten transport security, and troubleshoot the mobile app integration.', 'tcnapp-connector' ); ?>
                </p>
            </div>

            <section id="tcn-checklist-b1" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B1) Verify Endpoints Exist', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Confirm the REST API routes respond before pointing clients at the site.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <p><?php esc_html_e( 'Open WP Admin → Tools → Site Health → REST API or send manual HTTP requests and make sure the following endpoints are registered:', 'tcnapp-connector' ); ?></p>
                    <ul>
                        <li><code>POST /wp-json/gn/v1/login</code></li>
                        <li><code>POST /wp-json/gn/v1/change-password</code></li>
                        <li><code>POST /wp-json/gn/v1/profile/avatar</code></li>
                    </ul>
                    <p><?php esc_html_e( 'If any /gn/v1 route returns a 404, flush permalinks by visiting WP Admin → Settings → Permalinks and clicking Save without changing the structure.', 'tcnapp-connector' ); ?></p>
                </div>
            </section>

            <section id="tcn-checklist-b2" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B2) Security & Transport Settings', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Review HTTPS, CORS, and rate limiting controls in TCN Platform → Settings.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <h3><?php esc_html_e( 'HTTPS Enforcement', 'tcnapp-connector' ); ?></h3>
                    <ul>
                        <li><?php esc_html_e( 'In production, keep HTTPS required (recommended).', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'In development, if the site runs on HTTP, enable “Allow Dev HTTP”. This typically only works when WP_DEBUG is true and should never be enabled in production.', 'tcnapp-connector' ); ?></li>
                    </ul>

                    <h3><?php esc_html_e( 'Allowed Origin (CORS)', 'tcnapp-connector' ); ?></h3>
                    <p><?php esc_html_e( 'Set a precise origin when the app is web or hybrid based (for example, http://localhost:19006 or https://app.example.com). If left blank the plugin reflects the request Origin, but specifying an explicit value helps avoid strict browser CORS blocks.', 'tcnapp-connector' ); ?></p>

                    <h3><?php esc_html_e( 'Token Lifetime & Rate Limits', 'tcnapp-connector' ); ?></h3>
                    <p><?php esc_html_e( 'Token TTL and rate limits can stay on their defaults in production. If developers encounter frequent expiries in testing, extend the lifetime temporarily while debugging.', 'tcnapp-connector' ); ?></p>
                </div>
            </section>

            <section id="tcn-checklist-b3" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B3) Authentication', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Understand how bearer tokens secure the protected routes.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <ul>
                        <li><?php esc_html_e( 'Protected endpoints expect an Authorization: Bearer token unless labelled public.', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'Retrieve tokens from POST /wp-json/gn/v1/login using a valid WordPress username, email, and password.', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'Ensure the authenticated user account is active and not blocked by membership or capability plugins.', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'If the site is behind Cloudflare or another proxy, confirm Authorization headers reach PHP unchanged.', 'tcnapp-connector' ); ?></li>
                    </ul>
                </div>
            </section>

            <section id="tcn-checklist-b4" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B4) Change Password — Server Expectations', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Validate payloads and server-side checks for password rotations.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <p><?php esc_html_e( 'Route: POST /wp-json/gn/v1/change-password (Authorization: Bearer required). Send JSON in the following shape:', 'tcnapp-connector' ); ?></p>
                    <pre><code><?php echo esc_html( $change_password_payload ); ?></code></pre>
                    <p><?php esc_html_e( 'Typical validations include confirming the token maps to the current user, verifying the existing password, enforcing password policy, updating the credential, and optionally revoking older tokens.', 'tcnapp-connector' ); ?></p>
                    <p><?php esc_html_e( 'Common error responses: unauthorized or missing token, invalid current password, https_required, or rate_limited.', 'tcnapp-connector' ); ?></p>
                </div>
            </section>

            <section id="tcn-checklist-b5" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B5) Change Profile Picture (Avatar) — Server Expectations', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Ensure the upload workflow matches the REST endpoint requirements.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <p><?php esc_html_e( 'Route: POST /wp-json/gn/v1/profile/avatar (Authorization: Bearer required). Submit multipart/form-data with an avatar file or JSON containing avatar_url/avatar_base64.', 'tcnapp-connector' ); ?></p>
                    <ul>
                        <li><?php esc_html_e( 'Validate the authenticated user from the token.', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'Check file type and size (JPEG, PNG, or WEBP are typical).', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'Store the file with wp_handle_upload or media_sideload_image and update user meta for avatar mappings.', 'tcnapp-connector' ); ?></li>
                        <li><?php esc_html_e( 'Return success JSON containing the refreshed avatar URLs.', 'tcnapp-connector' ); ?></li>
                    </ul>
                    <p><?php esc_html_e( 'The server rejects requests missing avatar data, using unsupported file types, or targeting other accounts.', 'tcnapp-connector' ); ?></p>
                </div>
            </section>

            <section id="tcn-checklist-b6" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B6) Logs & Where to Debug', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Check the activity log and server logs whenever a REST request fails.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <p><?php esc_html_e( 'Navigate to WP Admin → TCN Platform → Activity Log to review recent REST requests. Expect entries for permissions_check failures, missing avatar files, HTTPS enforcement blocks, or CORS issues.', 'tcnapp-connector' ); ?></p>
                    <p><?php esc_html_e( 'Also inspect wp-content/debug.log (with WP_DEBUG_LOG enabled), web server logs, and any security plugin logs when troubleshooting.', 'tcnapp-connector' ); ?></p>
                </div>
            </section>

            <section id="tcn-checklist-b7" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B7) Quick Server-Side Self-Tests', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Use curl or an API client to validate critical flows directly from the server.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <p><?php esc_html_e( '1) Login and copy the bearer token:', 'tcnapp-connector' ); ?></p>
                    <pre><code><?php echo esc_html( $curl_examples['login'] ); ?></code></pre>
                    <p><?php esc_html_e( '2) Change the password with the issued token:', 'tcnapp-connector' ); ?></p>
                    <pre><code><?php echo esc_html( $curl_examples['change_password'] ); ?></code></pre>
                    <p><?php esc_html_e( '3) Upload an avatar image:', 'tcnapp-connector' ); ?></p>
                    <pre><code><?php echo esc_html( $curl_examples['upload_avatar'] ); ?></code></pre>
                </div>
            </section>

            <section id="tcn-checklist-b8" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'B8) Common Failure Matrix (Cause → Fix)', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Match frequent errors to the quickest resolution.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-content">
                    <ul>
                        <li><strong><?php esc_html_e( '401 Unauthorized:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Acquire a fresh token and ensure Authorization headers survive any proxy or CDN.', 'tcnapp-connector' ); ?></li>
                        <li><strong><?php esc_html_e( '426/400 HTTPS Required:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Enable “Allow Dev HTTP” only on local sites or switch the environment to HTTPS.', 'tcnapp-connector' ); ?></li>
                        <li><strong><?php esc_html_e( '400 Avatar Upload Errors:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Provide either a valid avatar file, a reachable avatar_url, or base64 image data plus a MIME type/filename.', 'tcnapp-connector' ); ?></li>
                        <li><strong><?php esc_html_e( 'Browser CORS Errors:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Set the Allowed Origin exactly, confirm OPTIONS preflight succeeds, and avoid wildcard origins with credentials.', 'tcnapp-connector' ); ?></li>
                        <li><strong><?php esc_html_e( '413 Payload Too Large:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Increase PHP upload_max_filesize/post_max_size or compress the image before uploading.', 'tcnapp-connector' ); ?></li>
                        <li><strong><?php esc_html_e( 'Capability or Role Restrictions:', 'tcnapp-connector' ); ?></strong> <?php esc_html_e( 'Verify the token resolves to the intended user and that the plugin allows that role to update avatars.', 'tcnapp-connector' ); ?></li>
                    </ul>
                </div>
            </section>

            <section id="tcn-checklist-c" class="tcn-platform-section">
                <div class="tcn-platform-section-header">
                    <h2><?php esc_html_e( 'C) Quick Checklists', 'tcnapp-connector' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Run through these condensed lists when debugging either the client app or the server.', 'tcnapp-connector' ); ?></p>
                </div>
                <div class="tcn-platform-checklist-grid">
                    <div class="tcn-platform-checklist-panel">
                        <h3><?php esc_html_e( 'App Checklist', 'tcnapp-connector' ); ?></h3>
                        <ul>
                            <li><?php esc_html_e( 'Confirm BASE_URL targets the correct WordPress site.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Request and store a fresh token securely.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Send Authorization: Bearer <token> on protected calls.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Change Password requests include current_password and password in JSON.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Upload Avatar uses FormData with the avatar field, filename, and MIME type without manually forcing Content-Type.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Handle 401 responses by prompting for a new login.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Log full error responses (excluding secrets) for faster debugging.', 'tcnapp-connector' ); ?></li>
                        </ul>
                    </div>
                    <div class="tcn-platform-checklist-panel">
                        <h3><?php esc_html_e( 'Plugin / Server Checklist', 'tcnapp-connector' ); ?></h3>
                        <ul>
                            <li><?php esc_html_e( 'Verify /gn/v1/login, /gn/v1/change-password, and /gn/v1/profile/avatar are registered.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Enforce HTTPS in production and reserve “Allow Dev HTTP” for local environments.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Set the Allowed Origin to the exact app origin when applicable.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Confirm proxies preserve Authorization headers.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Allow media uploads and size limits that accommodate avatar images.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Monitor the Activity Log and enable WP_DEBUG_LOG during development.', 'tcnapp-connector' ); ?></li>
                            <li><?php esc_html_e( 'Flush permalinks if REST routes return 404 errors.', 'tcnapp-connector' ); ?></li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }
}
