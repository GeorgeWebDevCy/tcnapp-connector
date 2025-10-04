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

- The plugin keeps using the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library to fetch releases from GitHub. Adjust `TCN_PLATFORM_UPDATE_REPOSITORY_URL` and `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH` constants or the corresponding filters to change the source.
- Services are registered through a lightweight service container (`TCN\Platform\Plugin`). Modules hook into this container to decide which services boot.
- Activation seeds module options, membership defaults, WooCommerce endpoints, and products. Deactivation clears scheduled events and flushes rewrite rules.
- Namespaced PHP classes live under `includes/` and autoload via `includes/Autoloader.php`.

## 📝 Release Notes

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
