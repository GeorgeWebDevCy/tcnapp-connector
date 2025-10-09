# TCN Platform WordPress Plugin

The TCN Platform plugin bundles the network marketing (MLM) engine and the GN Password Login REST API into a single, modular WordPress package. Deploy it on WooCommerce-powered membership sites to automate tier upgrades, sponsor tracking, downline reporting, and mobile authentication flows consumed by the TCNApp client.

## 📦 Modules

| Module | Description | Default |
| ------ | ----------- | ------- |
| **Membership & MLM** | Seeds WooCommerce membership products, manages tier progression, commissions, genealogy, shortcodes, admin tools, and REST endpoints under `tcn-mlm/v1/*`. | Enabled (locked) |
| **Password Login API** | Exposes the `gn/v1` REST endpoints for login, registration, password resets, and token-based cross-origin authentication. Includes rate limiting, HTTPS enforcement, and optional CORS origin whitelisting. | Enabled |

Toggle modules under **TCN Platform → Modules**. The Membership & MLM module stays active because the plugin’s core data models depend on it. The Password Login API module can be switched off if another authentication layer is already in place; the original `GN_Password_Login_API` class remains available for backwards compatibility.

## ✅ Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- HTTPS (strongly recommended; REST login endpoints reject non-SSL requests unless `Allow Dev HTTP` is enabled while `WP_DEBUG` is true)

## 🚀 Installation

1. Copy this repository into `wp-content/plugins/tcn-platform` (or clone it directly).
2. Activate **TCN Platform** from the WordPress admin.
3. Visit **TCN Platform** in the sidebar to review module toggles, configure the Password Login API (allowed origin, dev HTTPS overrides), and adjust membership defaults.
4. Flush permalinks (`Settings → Permalinks → Save`) so WooCommerce account endpoints take effect.

## 🧩 Module Configuration

### Modules Card
- **Membership & MLM** (locked): Core automation and REST APIs. Always on.
- **Password Login API**: Disable if you only need the MLM stack and have another authentication mechanism. When enabled, configure the fields below.

### Password Login API Settings
- **Allowed CORS Origin** – Exact origin (scheme + host + optional port) allowed to call `gn/v1` endpoints cross-origin. Leave blank to restrict to same-origin requests.
- **Allow Dev HTTP** – Permits non-HTTPS requests when `WP_DEBUG` is true. Only enable for local development environments.

These settings persist in the `gn_login_api_settings` option. A compatibility shim keeps `GN_Password_Login_API` usable for legacy code.

## 📊 Activity Monitoring

- Review REST API usage and plugin events from **TCN Platform → Activity Log**.
- The log captures calls to `gn/v1/*` and `tcn-mlm/v1/*` namespaces, redacting sensitive payload fields like passwords and tokens.
- Activation, deactivation, settings changes, and manual log clears are also recorded so administrators can audit configuration changes.

## 🧾 Deployment Checklists

- Head to **TCN Platform → Deployment Checklists** for a curated set of preflight checks and troubleshooting tips covering the Password Login API routes.
- Each section summarises endpoint verification, HTTPS and CORS settings, bearer token expectations, avatar upload requirements, and cURL recipes you can run directly from the server.
- Authentication quick hits captured on the checklist keep the mobile app and server aligned:
  - Protected endpoints expect an `Authorization: Bearer` token unless the route explicitly calls itself public.
  - Retrieve bearer tokens from `POST /wp-json/gn/v1/login` with a valid WordPress username and password.
  - Ensure the authenticated account stays active and is not blocked by membership or capability plugins before issuing tokens.
  - When Cloudflare or another proxy sits in front of the site, verify the `Authorization` header survives to PHP unchanged.
- Quick “App” and “Plugin/Server” lists make it easy to confirm both sides of the integration before shipping builds to QA or production.

## 💼 Membership & MLM Highlights

- Seeds Blue/Gold/Platinum/Black WooCommerce products on activation, maintaining pricing alignment with the mobile catalogue.
- Tracks sponsor/direct recruit relationships with automatic upgrades through Gold → Platinum → Black as network conditions are met.
- Schedules background syncs to keep membership product pricing, categories, and metadata aligned with configured defaults.
- Exposes shortcodes:
  - `[tcn_member_dashboard]` – Earnings, counts, ledger history.
  - `[tcn_genealogy]` – Interactive downline tree.
  - `[tcn_mlm_optin]` – Simple opt-in container for custom messaging.
- Adds **MLM Dashboard** and **MLM Genealogy** entries to WooCommerce My Account navigation (`/my-account/tcn-member-dashboard/`, `/my-account/tcn-genealogy/`).
- REST endpoints under `tcn-mlm/v1/*` expose member profiles, genealogy trees, and commission summaries (`/member`, `/genealogy`, `/commissions`) that power the TCNApp dashboards.

## 📱 TCNApp Mobile Alignment

TCN Platform keeps the WooCommerce catalogue aligned with the membership products consumed by the TCNApp mobile client. Default pricing now mirrors the THB membership matrix so the web checkout and in-app upsells stay consistent:

| Tier | Mobile Label | Default Price (THB) | Notes |
| ---- | ------------- | ------------------- | ----- |
| Blue | Customer | ฿0 | Baseline storefront access with no commissions. |
| Gold | Affiliate | ฿500 | Earns THB125 on each direct recruit and unlocks passive rewards after two directs. |
| Platinum | Leader | ฿1,200 | Awards THB250 on new directs while maintaining THB125 passive overrides. |
| Black | Elite | ฿2,000 | Leadership renewal tier with continued THB125 passive income from downline activity. |

Sites can override these amounts from **TCN Platform → Membership Defaults**, but the seeded WooCommerce products and mobile catalogue remain in sync by default.

## 🔑 Password Login API Endpoints

All endpoints live under `wp-json/gn/v1`:

| Route | Method | Description |
| ----- | ------ | ----------- |
| `/login` | POST | Authenticate via username/email + password. Always issues seven-day token hand-offs for `/wp-login.php?action=gn_token_login` redemption. Includes rate limiting and token locking via filters. |
| `/register` | POST | Register a new user with validation for username, email, and password strength. Fires `gn_password_api_user_registered`. |
| `/forgot-password` | POST | Start core WordPress reset workflow without leaking user existence. |
| `/reset-password` | POST | Complete a reset using a verification code (custom or stored). |
| `/change-password` | POST | Authenticated password change that requires the current password plus `Authorization: Bearer {api_token}` (or an active session). |

Additional helpers:
- `GN_Password_Login_API::issue_reset_verification_code( $user_id, $ttl )` for generating short-lived verification codes.
- Cross-origin CORS headers respect the configured allowed origin.
- HTTPS enforcement can be relaxed via `gn_password_api_allow_dev_http` filter for bespoke dev setups.

The legacy class name `GN_Password_Login_API` is aliased to the new service for backwards compatibility, so older bootstrap code continues working.

## 🎟️ Discount Validation & Transaction Logging

The Password Login API now exposes three REST routes that let mobile clients validate QR discount tokens, fetch a member’s eligible entitlements, and post completed redemption transactions. They live alongside the existing `/wp-json/gn/v1/*` namespace and inherit the same HTTPS enforcement, bearer token authentication, and CORS controls described above.

| Route | Method | Auth | Purpose |
| ----- | ------ | ---- | ------- |
| `/gn/v1/discounts/lookup` | POST | `Authorization: Bearer {api_token}` issued by `/login`. Requires the vendor account to have the `tcn_discount_redemptions` capability. | Validate a scanned QR token and return member, plan, and discount eligibility details. |
| `/gn/v1/discounts/transactions` | POST | `Authorization: Bearer {api_token}` with `tcn_discount_redemptions` capability. | Record a redeemed discount, persist the financial breakdown, and return an auditable transaction record. |
| `/gn/v1/discounts/history` | GET | `Authorization: Bearer {api_token}`. Vendor tokens see their own outlet’s transactions; member tokens see their personal redemption history. | Paginate recent discount transactions with aggregation helpers for dashboards. |

### Request/response highlights

- **Lookup (`POST /discounts/lookup`)**
  - **Body:** `{ "qr_token": "...", "vendor_id": 456 }`
  - **Response:**
    ```json
    {
      "success": true,
      "member": { "id": 123, "name": "Ada Example", "plan_tier": "gold" },
      "discount": {
        "token": "...",
        "label": "10% Café Beverage",
        "type": "percentage",
        "value": 0.10,
        "max_uses": 1,
        "max_uses_per_day": 1,
        "expires_at": "2024-08-31T23:59:59+00:00"
      },
      "eligible": true,
      "usage": { "uses_today": 0, "uses_total": 0 }
    }
    ```
  - Invalid or expired tokens return `400 gn_invalid_discount_token` while exhausted entitlements return `409 gn_discount_limit_reached`.

- **Transactions (`POST /discounts/transactions`)**
  - **Body:**
    ```json
    {
      "qr_token": "...",
      "member_id": 123,
      "vendor_id": 456,
      "gross_amount": 250.0,
      "discount_amount": 25.0,
      "net_amount": 225.0,
      "currency": "THB",
      "metadata": { "cashier": "POS-3" }
    }
    ```
  - **Response:**
    ```json
    {
      "success": true,
      "transaction": {
        "id": 987,
        "member_id": 123,
        "vendor_id": 456,
        "plan_tier": "gold",
        "gross_amount": 250,
        "discount_amount": 25,
        "net_amount": 225,
        "currency": "THB",
        "metadata": { "cashier": "POS-3" },
        "created_at": "2024-07-12T04:15:22+00:00"
      }
    }
    ```
  - The endpoint enforces `gross_amount >= discount_amount >= 0` and automatically snapshots the member’s plan tier at the time of redemption.

- **History (`GET /discounts/history`)**
  - **Query params:** `page` (default 1), `per_page` (default 25, max 100), optional `member_id`, `vendor_id`, `date_start`, `date_end`, `plan_tier` filters for privileged dashboards.
  - **Response:**
    ```json
    {
      "success": true,
      "totals": {
        "count": 42,
        "gross_sum": 10250,
        "discount_sum": 1025,
        "net_sum": 9225
      },
      "transactions": [ { ... latest redemption ... } ],
      "pagination": { "page": 1, "per_page": 25, "total_pages": 2 }
    }
    ```

### Storage & schema considerations

- Introduce a custom table `{$wpdb->prefix}tcn_discount_transactions` with the following columns:
  - `id` (bigint unsigned, auto-increment, primary key)
  - `member_id` (bigint unsigned, indexed)
  - `vendor_id` (bigint unsigned, indexed)
  - `plan_tier` (varchar 32)
  - `qr_token` (varchar 191, indexed for lookups)
  - `gross_amount` (decimal 12,2)
  - `discount_amount` (decimal 12,2)
  - `net_amount` (decimal 12,2)
  - `currency` (char 3)
  - `metadata` (longtext, JSON-encoded)
  - `created_at` (datetime, indexed)
- Run schema creation/updates from the plugin activator using `dbDelta()`, logging migrations to the existing activity log for auditability.
- Optionally add a materialised view or scheduled aggregation (`tcn_discount_stats`) if reporting queries prove slow; otherwise rely on SQL `SUM`/`COUNT` aggregations filtered by member/vendor.

### Dashboard alignment & testing

- Member and vendor dashboards should call `/discounts/history` with `member_id` or `vendor_id` filters to fetch rolling totals, then compute KPIs (gross, discount, net) client-side.
- The API tester now includes presets for all three routes, preloading bearer token headers, lookup payloads, and sample transaction bodies so admins can validate the flow after deployments.
- Coordinate with the downstream plugin repository to register the routes, capabilities, and table schema. Add integration tests (e.g., PHPUnit or REST API test cases) covering token validation, entitlement exhaustion, and transaction inserts to guard the mobile contract.

## 🛠 Developer Notes

- See [`docs/TCN_PLATFORM_REFERENCE.md`](docs/TCN_PLATFORM_REFERENCE.md) for a function-by-function and endpoint-by-endpoint reference covering hooks, parameters, authentication, and example payloads designed for the built-in API tester.
- The plugin keeps using the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library to fetch releases from GitHub. Adjust `TCN_PLATFORM_UPDATE_REPOSITORY_URL` and `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH` constants or the corresponding filters to change the source.
- Services are registered through a lightweight service container (`TCN\Platform\Plugin`). Modules hook into this container to decide which services boot.
- Activation seeds module options, membership defaults, WooCommerce endpoints, and products. Deactivation clears scheduled events and flushes rewrite rules.
- Namespaced PHP classes live under `includes/` and autoload via `includes/Autoloader.php`.

## 📝 Release Notes

### 0.3.79
- Add discount lookup, transaction, and history presets to the API tester so vendor rollouts can be exercised entirely inside WordPress before going live.

### 0.3.78
- Escape the avatar upload cURL placeholders in the API tester preset so PHP no longer emits warnings or fatal errors while rendering the admin page.

### 0.3.76
- Introduce `/gn/v1/discounts/lookup`, `/gn/v1/discounts/transactions`, and `/gn/v1/discounts/history` endpoints with vendor capability checks, automatic usage tracking, and transaction storage in the new `tcn_discount_transactions` table.
- Seed the discount transaction schema during activation and assign the `tcn_discount_redemptions` capability to administrators and shop managers so they can process QR redemptions immediately.
- Refresh the platform reference manual to document the discount API surface alongside existing authentication, membership, and WooCommerce routes.

### 0.3.59
- Mirror the `jwt-auth/v1` REST endpoints from the standalone JWT Authentication plugin so existing clients can obtain, refresh, and validate JWTs without additional dependencies.

### 0.3.58
- Reduce memory usage when enumerating membership products by querying WooCommerce IDs and metadata on demand instead of instantiating every product object up front.

### 0.3.57
- Automatically authenticate REST requests that supply bearer tokens so protected endpoints recognise the same API tokens issued by `/gn/v1/login`, including WooCommerce customer and order routes.

### 0.3.55
- Expand the deployment checklist authentication guidance to reinforce bearer token expectations, token retrieval, account status checks, and proxy header forwarding.

### 0.3.54
- Refresh the deployment checklist copy to match the latest HTTPS and CORS guidance, highlighting the “Allow Dev HTTP” toggle and explicit origin recommendations.
- Rename the HTTPS development toggle in settings to “Allow Dev HTTP” so the UI and documentation use the same label.

### 0.3.53
- Add a Deployment Checklists admin page that consolidates endpoint verification steps, HTTPS/CORS guidance, REST payload expectations, and server-side cURL examples for `/gn/v1/login`, `/gn/v1/change-password`, and `/gn/v1/profile/avatar`.
- Style the new panel alongside existing admin screens so troubleshooting guides are easy to read directly inside WordPress.

### 0.3.52
- Ensure `/change-password` resolves the authenticated user when requests rely solely on `Authorization: Bearer` tokens so mobile clients can rotate credentials without a browser cookie.
- Refresh the API tester preset and docs to call out the bearer token requirement, payload fields, and multipart form expectations for avatar updates.
- Extend the token authenticator tests to cover direct bearer headers and reject Basic credentials that bypass the security layer.

### 0.3.51
- Accept bearer tokens passed through `REDIRECT_HTTP_AUTHORIZATION` or `AUTHORIZATION` when FastCGI/proxy setups strip the standard header so API clients keep authenticating successfully.
- Add a lightweight test harness that exercises the new header fallbacks to prevent regressions.

### 0.3.50
- Add a dedicated App User role with upload permissions and automatically assign it to API-registered customers to unblock avatar uploads.

### 0.3.49
- Remove the cookie-based login mode so Password Login API responses always issue one-time hand-off tokens.

### 0.3.48
- Accept long-lived Password Login API bearer tokens in addition to one-time hand-off tokens so REST requests authenticated from the mobile app can reuse the stored `api_token` without falling back to cookie flows.
- Document the shared API token prefix in code to keep authenticator lookups aligned.

### 0.3.47
- Switch the mobile login flow to rely on `mode=token` hand-offs and extend the login token lifetime to a full week so members can finish authentication even if the browser redirect is delayed.

### 0.3.46
- Enhancement: Expand the API tester presets to cover every REST endpoint, including `/gn/v1/me` and `/gn/v1/log`, so admins can prefill requests for authentication, membership, MLM, and WooCommerce bridges.

### 0.3.45
- Feature: Issue week-long bearer tokens on login responses, expose a `/gn/v1/me` profile endpoint, and add a `/gn/v1/log` ingestor for mobile diagnostics.

### 0.3.44
- Feature: Add a Download Log button that exports the complete Activity Log to a timestamped text file for offline auditing.

### 0.3.43
- Maintenance: Bump the plugin version for the 0.3.43 release.

### 0.3.42
- Relax the avatar upload permission gate so members without the `upload_files` capability can update their own profile photo while continuing to block cross-account uploads.

### 0.3.41
- Centralise Password Login bearer token validation and reuse it across profile, membership, and password-change REST endpoints so mobile clients can authenticate with either tokens or session cookies.
- Return REST-friendly `WP_Error` responses when tokens are invalid or expired to keep API feedback consistent.

### 0.3.40
- Surface the `WOOCOMMERCE_CONSUMER_KEY`/`WOOCOMMERCE_CONSUMER_SECRET` bundle in Password Login API responses so authenticated clients can automatically sign WooCommerce bridge requests.

### 0.3.39
- Expand the Activity Log canvas so the DataTable, payload badges, and context panels have room to display wide REST parameters without forcing horizontal scrolling.

### 0.3.38
- Add a `/gn/v1/profile/avatar` REST endpoint that uploads profile photos to the Media Library, stores avatar metadata, and returns the refreshed `/wp/v2/users/me` payload used by the mobile app.
- Extend the docs and API tester presets so administrators can review the multipart upload requirements, headers, and sample responses for the avatar route.

### 0.3.37
- Sync the WordPress.org `readme.txt` with the detailed project README so plugin documentation stays consistent across distribution channels.

### 0.3.36
- Polish every admin screen with elevated cards, section panels, and richer typography so settings, logs, and the API tester feel cohesive and easier to scan.

### 0.3.35
- Fall back to a direct WordPress product lookup when WooCommerce's product helper returns nothing so membership mapping always lists existing catalogue items.

### 0.3.34
- Prevent a fatal error on the settings screen when WooCommerce is unavailable by guarding membership product status lookups.

### 0.3.33
- Fix the membership product dropdown to honour every registered WooCommerce status so private or custom-state products appear instead of showing the "create products" warning.

### 0.3.32
- Register membership level labels and benefits with WPML so multilingual storefronts can translate TCN settings directly from the String Translation UI.

### 0.3.31
- Expose commission controls on the admin settings screen so administrators can adjust direct and passive payouts per membership tier without editing code, keeping compensation programmes aligned with organisational changes.

### 0.3.30
- Allow administrators to map WooCommerce products to membership levels from the settings screen, persist the selections in plugin options, and keep the `_tcn_membership_level` meta in sync for reliable upgrades even when product slugs change.

### 0.3.29
- Lock the Activity Log and API Tester admin pages behind a non-public password challenge that expires every 24 hours per administrator session.

### 0.3.28
- Normalise WooCommerce-derived membership fees so THB prices retain the expected zeros when thousand separators are configured.

### 0.3.27
- Read membership pricing directly from WooCommerce product meta so third-party price filters no longer downscale the fees surfaced to the mobile app.

### 0.3.26
- Restore numeric membership pricing in the mobile plans endpoint and expose formatted amounts so the app no longer renders "THBNaN" labels.

### 0.3.25
- Ensure the membership plans endpoint returns the correct WooCommerce product IDs by falling back to the official product slugs for each tier.

### 0.3.24
- Add an exhaustive reference manual that documents every public class method and REST endpoint, including WooCommerce routes, with example payloads tailored for the built-in API tester.
- Expand the admin API tester presets so every endpoint can be exercised without leaving the dashboard.

### 0.3.23
- Suppress default WordPress new user notification emails when registrations are created through the Password Login API so the app no longer triggers duplicate notices.

### 0.3.22
- Maintenance release to bump the plugin version for distribution.

### 0.3.21
- Maintenance release to bump the plugin version for distribution.

### 0.3.20
- Added an API Tester admin screen that mirrors Postman inside WordPress, letting you compose requests, send them to plugin endpoints, and inspect the raw responses.
- Documented starter examples for authentication, registration, membership plans, and WooCommerce lookups so teams can quickly verify their integrations.

### 0.3.19
- Allow WooCommerce REST API consumer keys to authenticate the `gn/v1` customer and order endpoints for secure headless integrations.

### 0.3.18
- Add a WooCommerce order creation endpoint to the REST service so external systems can register purchases directly from the API.

### 0.3.17
- Wrap long Activity Log parameter values so the DataTable columns remain readable on narrow screens.

### 0.3.16
- Refresh the Activity Log UI with a responsive DataTable that adds instant search, pagination, and filtering while keeping entries easy to scan on any screen size.

### 0.3.15
- Restyled the Activity Log so REST and plugin events show labeled detail rows and badges, keeping payloads intact while making them easier to read.

### 0.3.14
- Allow unauthenticated clients to query `/wp-json/gn/v1/memberships/plans` so the mobile membership screen works for logged-out users.

### 0.3.13
- Restore the GN `/memberships/*` REST endpoints so the mobile upgrade flow can load plans, create Stripe payment intents, and confirm upgrades.
- Add Stripe API key fields to the settings screen and expose the publishable key in the plans payload.

### 0.3.12
- Maintenance release to bump the plugin version for distribution.

### 0.3.11
- Added an Activity Monitor inside the WordPress admin to review REST traffic and plugin lifecycle events at a glance.

### 0.3.8
- Ensure membership level defaults repopulate missing fee and commission fields so the admin overview table shows accurate values.

### 0.3.7
- Align default membership pricing with the THB consumer network matrix, including commission overrides for Platinum/Black sponsors.
- Track total network size for auto-upgrades so Platinum members advance to Black once their active downline reaches two people.
- Update docs and option defaults to reflect THB currency throughout the plugin.

### 0.3.6
- Document TCNApp mobile pricing alignment and update default membership fees to match the current catalogue.

### 0.3.5
- Integrate Plugin Update Checker so WordPress installs can detect GitHub releases automatically.

### 0.3.4
- Ensure the membership product seeder only creates Blue/Gold/Platinum/Black tiers even if legacy configuration data contains stray levels.

### 0.3.3
- Prevent a fatal error on the admin settings screen when membership level option data is unexpectedly stored as strings.

### 0.3.2
- Resolve a fatal error triggered on `init` when capturing sponsors by making the hooked callback public.

### 0.3.1
- Hardened the membership product seeder to gracefully handle corrupted membership level option values.

### 0.3.0
- Renamed plugin to **TCN Platform** and introduced module toggles.
- Integrated the GN Password Login REST API directly into the codebase with backwards-compatible class aliasing.
- Added admin settings for module toggles, CORS configuration, and HTTP dev overrides.
- Refreshed admin UI and documentation to reflect the unified platform.

## 🔗 Related Projects

- [TCNApp (React Native client)](https://github.com/GeorgeWebDevCy/TCNApp)
- [TCN Platform Architecture Guide](docs/architecture.md)

For architecture diagrams, data models, and subsystem breakdowns, see `architecture.md` inside the repository.
