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
- HTTPS (strongly recommended; REST login endpoints reject non-SSL requests unless `Allow HTTP During Development` is enabled while `WP_DEBUG` is true)

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
- **Allow HTTP During Development** – Permits non-HTTPS requests when `WP_DEBUG` is true. Only enable for local development environments.

These settings persist in the `gn_login_api_settings` option. A compatibility shim keeps `GN_Password_Login_API` usable for legacy code.

## 📊 Activity Monitoring

- Review REST API usage and plugin events from **TCN Platform → Activity Log**.
- The log captures calls to `gn/v1/*` and `tcn-mlm/v1/*` namespaces, redacting sensitive payload fields like passwords and tokens.
- Activation, deactivation, settings changes, and manual log clears are also recorded so administrators can audit configuration changes.

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
| `/login` | POST | Authenticate via username/email + password. Supports `mode=cookie` for same-origin flows or returns a one-time token for cross-origin login hand-offs. Includes rate limiting and token locking via filters. |
| `/register` | POST | Register a new user with validation for username, email, and password strength. Fires `gn_password_api_user_registered`. |
| `/forgot-password` | POST | Start core WordPress reset workflow without leaking user existence. |
| `/reset-password` | POST | Complete a reset using a verification code (custom or stored). |
| `/change-password` | POST | Authenticated password change with verification of the current password. |

Additional helpers:
- `GN_Password_Login_API::issue_reset_verification_code( $user_id, $ttl )` for generating short-lived verification codes.
- Cross-origin CORS headers respect the configured allowed origin.
- HTTPS enforcement can be relaxed via `gn_password_api_allow_dev_http` filter for bespoke dev setups.

The legacy class name `GN_Password_Login_API` is aliased to the new service for backwards compatibility, so older bootstrap code continues working.

## 🛠 Developer Notes

- See [`docs/TCN_PLATFORM_REFERENCE.md`](docs/TCN_PLATFORM_REFERENCE.md) for a function-by-function and endpoint-by-endpoint reference covering hooks, parameters, authentication, and example payloads designed for the built-in API tester.
- The plugin keeps using the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library to fetch releases from GitHub. Adjust `TCN_PLATFORM_UPDATE_REPOSITORY_URL` and `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH` constants or the corresponding filters to change the source.
- Services are registered through a lightweight service container (`TCN\Platform\Plugin`). Modules hook into this container to decide which services boot.
- Activation seeds module options, membership defaults, WooCommerce endpoints, and products. Deactivation clears scheduled events and flushes rewrite rules.
- Namespaced PHP classes live under `includes/` and autoload via `includes/Autoloader.php`.

## 📝 Release Notes

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
