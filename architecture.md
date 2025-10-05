# TCN Platform Architecture

## Overview
TCN Platform combines the WooCommerce membership/MLM stack with the GN Password Login REST API in a single modular plugin. It tracks sponsor relationships, handles automatic upgrades, records commissions, surfaces dashboards, and exposes REST endpoints for the mobile and web clients that consume the platform. Each capability lives behind a module toggle so sites can slim down to the pieces they need while sharing a unified codebase.

## Key Components
- **Plugin Bootstrap (`tcnapp-connector.php`)** – Registers activation hooks, loads dependencies, and bootstraps the main service container. During activation it seeds module settings, membership defaults, WooCommerce endpoints, and password login defaults before flushing rewrite rules.
- **Service Container (`TCN\\Platform\\Plugin`)** – Coordinates setup of subsystems (membership, network, commissions, dashboards, REST routes, authentication) and honours module toggles exposed by `Support\\Modules`.
- **Modules (`Support\\Modules`)** – Stores the enabled/disabled state of top-level features. The Membership & MLM module is locked on; the Password Login API module can be toggled and governs whether authentication endpoints boot. Settings persist in the `tcn_platform_modules` option.
- **Authentication Service (`Auth\\PasswordLoginService`)** – Registers `/wp-json/gn/v1/*` routes for login, registration, password reset, and password change operations with rate limiting, HTTPS enforcement, one-time token login links, and configurable CORS headers. Provides helper methods (like issuing verification codes) and aliases itself to the legacy `GN_Password_Login_API` class.
- **Membership Manager** – Stores membership levels on users, enforces upgrade rules (Gold → Platinum after 2 direct recruits, Platinum → Black after 2 active network members), and syncs WooCommerce order completions.
- **Network Service** – Maintains sponsor relationships, assigns network owners, and exposes traversal helpers for genealogies and commission roll-ups.
- **Commission Manager** – Calculates commissions during WooCommerce order completion, records them in a custom table, and provides summary totals for dashboards and REST responses.
- **Admin UI** – Provides settings for modules, authentication, membership tiers, WooCommerce product mapping, and manual adjustments. Adds a product data field for associating SKUs with membership levels and seeds default membership products during activation.
- **Branding & UX** – Front-end dashboard styling and admin previews mirror the mobile app’s blue gradient branding to provide a consistent experience across platforms.
- **Member Dashboard Shortcodes** – Front-end shortcodes for members to view earnings, commissions, and downline activity. Genealogy output uses localized REST endpoints to render an interactive tree.
- **WooCommerce Account Endpoints** – Adds `tcn-member-dashboard` and `tcn-genealogy` endpoints under My Account that render the same templates as the shortcodes, keeping page and account navigation aligned.
- **REST API** – Namespaced endpoints under `/wp-json/tcn-mlm/v1/` expose genealogy data, member metrics, and commission summaries for authenticated users (notably `/member`, `/genealogy`, `/commissions`). `/wp-json/gn/v1/*` routes expose authentication flows when the Password Login module is enabled.
- **Update Manager** – Wraps plugin-update-checker to fetch releases from GitHub and installs updates automatically. Repository URL and branch can be overridden via constants or filters.

## Data Model
- **User Meta**
  - `_tcn_membership_level` (string: `blue|gold|platinum|black`)
  - `_tcn_sponsor_id` (int, user ID of direct sponsor)
  - `_tcn_network_owner` (int, root of the member's current network)
  - `_tcn_direct_recruits` (int, cached count for upgrade checks)
  - `_tcn_network_size` (int, total members in the active downline used for Platinum → Black promotions)
  - `_gn_login_token_*` (JSON payloads for one-time token logins; created and deleted dynamically)
  - `_gn_password_api_reset_code` (hashed reset code bundle)
- **Options**
  - `tcn_mlm_levels` – Associative array defining membership metadata (fee, commission amounts, upgrade thresholds, benefits list).
  - `tcn_mlm_general` – Global settings (default sponsor, currency).
  - `tcn_platform_modules` – Module enablement map (`mlm`, `auth_login`).
  - `gn_login_api_settings` – Allowed CORS origin and dev HTTP override for the password login endpoints.
- **Custom Table** `${wpdb->prefix}tcn_mlm_commissions`
  - `id` bigint PK
  - `sponsor_id` bigint FK → `wp_users.ID`
  - `member_id` bigint (recruit the commission came from)
  - `order_id` bigint WooCommerce order reference
  - `level` varchar (`direct`, `passive`, etc.)
  - `amount` decimal(10,2)
  - `currency` char(3)
  - `status` varchar (`pending`, `paid`, `cancelled`)
  - `created_at` datetime, `updated_at` datetime

## WooCommerce Integration Flow
1. On activation, the plugin seeds baseline membership products (Blue, Gold, Platinum, Black) with the correct level mapping if they don’t already exist.
2. Member purchases a membership product and the order captures the level selected in the product data panel or the mobile app directs the shopper through WooCommerce checkout using the mapped membership SKU.
3. On `woocommerce_order_status_completed`, the Membership Manager promotes the purchasing user to the corresponding membership level (preferring the highest-ranking level in the order) and links the sponsor using referral metadata (shortcode or query param).
4. Network Service updates direct recruit counts and determines whether the sponsor should form their own network or upgrade to Platinum/Black.
5. Commission Manager records a direct commission for the sponsor. It then walks up the upline hierarchy to award passive commissions per rules (currently one depth level for Gold/Platinum to align with provided scenarios).
6. Dashboards and REST endpoints (including the mobile-facing `/wp-json/wp/v2/users/me` augmentation) read aggregated data from the commission table and user meta for reporting.
7. Account endpoints reuse shortcode renderers so members see consistent dashboards when browsing the WooCommerce My Account area.
8. Seeded products are automatically assigned to the `Memberships` product category so storefront organization matches mobile expectations.

## Authentication Flow
1. Mobile or web client posts credentials to `POST /wp-json/gn/v1/login`.
2. The service enforces HTTPS (unless development overrides apply), rate limits by IP/username, and authenticates via WordPress core.
3. Successful requests issue a seven-day token hand-off that redirects through `/wp-login.php?action=gn_token_login` when the browser finally completes the flow.
4. Registration, forgot/reset, and change password flows share the same HTTPS and response-hardening rules, avoiding user enumeration.
5. Verification helpers (`issue_reset_verification_code`, filters for custom verification) keep the endpoint flexible for SMS/email code workflows.

## Genealogy Visualization
- REST endpoint returns a nested tree structure limited to the authenticated user's downline.
- Front-end script renders the tree using nested HTML lists styled with CSS connectors. Future iterations can swap in D3 or another visualization library.

## Extensibility
- Hooks (`do_action` / `apply_filters`) are provided around commission calculations, upgrade thresholds, REST responses, token lock behaviour, and HTTPS enforcement so integrators can extend flows without editing core files.
- Additional membership tiers can be added via settings without schema changes.
- Module system allows future capabilities (e.g., rewards, analytics exporters) to ship disabled by default and activated per deployment.

## Update Workflow
- Automatic updates are handled by plugin-update-checker. Override the repository URL or branch via constants (`TCN_PLATFORM_UPDATE_REPOSITORY_URL`, `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH`) or filters (`tcn_platform_update_repository_url`, `tcn_platform_update_repository_branch`).
- When the Password Login module is disabled the authentication services skip booting, but options remain stored so re-enabling is instant.
