=== TCN Platform ===
Contributors: georgewebdevcy
Tags: woocommerce, mlm, memberships, commissions, genealogy, authentication
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.42
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
TCN Platform unifies the WooCommerce membership/MLM engine and the GN Password Login REST API into one modular plugin. Enable the full stack to automate tier upgrades, sponsor relationships, commissions, dashboards, and mobile authentication for the TCNApp client—all without juggling multiple codebases. Toggle optional modules as your deployment matures.

== Requirements ==
* WordPress 6.0 or later
* WooCommerce 7.0 or later
* PHP 7.4 or later
* MySQL 5.7 / MariaDB 10.3 or later
* HTTPS (strongly recommended; REST login endpoints reject non-SSL requests unless "Allow HTTP During Development" is enabled while `WP_DEBUG` is true)

=== Modules ===
* **Membership & MLM (always on)** – Seeds and syncs Blue/Gold/Platinum/Black membership products, manages genealogy, promotes members based on network rules, tracks commissions, exposes `tcn-mlm/v1/*` REST endpoints, and injects dashboards into WooCommerce My Account.
* **Password Login API** – Provides hardened REST authentication endpoints under `gn/v1` with HTTPS enforcement, one-time token login, rate limiting, registration, password reset/change flows, and configurable CORS support. The historic `GN_Password_Login_API` class is aliased for backwards compatibility.

Configure modules under **TCN Platform → Modules**. The Membership & MLM module is locked on so the core data structures remain available, while the Password Login API can be disabled if another login layer is preferred.

== Installation ==
1. Upload the `tcn-platform` directory to `/wp-content/plugins/` (or clone the repository there).
2. Activate **TCN Platform** from the Plugins screen.
3. Visit **TCN Platform** in the admin menu to review module toggles, set the Password Login API allowed origin (for SPA/mobile clients), and adjust membership defaults.
4. Flush permalinks (`Settings → Permalinks → Save`) so WooCommerce account endpoints are registered.

== Module Configuration ==
=== Modules Card ===
* **Membership & MLM** – Core automation, genealogy, and REST APIs. Always enabled so data models remain available.
* **Password Login API** – Can be toggled off if you already have an authentication layer; the original `GN_Password_Login_API` class remains available for legacy hooks.

=== Password Login API Settings ===
* **Allowed CORS Origin** – Exact origin permitted to post to `gn/v1` endpoints. Leave blank to restrict to same-origin calls.
* **Allow HTTP During Development** – Permits non-HTTPS requests when `WP_DEBUG` is true; use only on local environments. You can further customise HTTPS behaviour via the `gn_password_api_allow_dev_http` filter.

== Activity Monitoring ==
* Monitor REST activity and plugin events from **TCN Platform → Activity Log** in the WordPress admin.
* The log records calls to `gn/v1/*` and `tcn-mlm/v1/*`, automatically redacting sensitive payload fields such as passwords and tokens.
* Plugin activation, deactivation, module toggles, settings updates, and manual log clears also appear so administrators can audit configuration changes.

== Membership & MLM Highlights ==
* Seed Blue/Gold/Platinum/Black WooCommerce products on activation so the catalogue aligns with the mobile tiers.
* Track sponsor relationships, promote members through Gold → Platinum → Black as conditions are met, and schedule syncs that keep pricing/categories aligned.
* Shortcodes:
  * `[tcn_member_dashboard]` – Earnings, counts, ledger history.
  * `[tcn_genealogy]` – Interactive downline tree.
  * `[tcn_mlm_optin]` – Opt-in container ready for custom messaging.
* Adds **MLM Dashboard** and **MLM Genealogy** entries to WooCommerce My Account navigation.
* REST endpoints under `tcn-mlm/v1/*` expose profiles, genealogy trees, and commissions for the TCNApp dashboards.

== Password Login API Endpoints ==
* `/wp-json/gn/v1/login` – POST authentication with optional one-time token hand-offs (`mode=token`) plus rate limiting and optional device locking filters.
* `/wp-json/gn/v1/register` – POST registration with validation for username, email, and password strength (`gn_password_api_user_registered` fires on success).
* `/wp-json/gn/v1/forgot-password` and `/reset-password` – POST flows that mirror WordPress core without leaking account existence.
* `/wp-json/gn/v1/change-password` – POST authenticated password changes with verification of the current password.
* Helper: `GN_Password_Login_API::issue_reset_verification_code( $user_id, $ttl )` generates short-lived reset codes for bespoke flows.

== Mobile App Integration ==
* TCNApp uses `/wp-json/gn/v1/*` for authentication and `/wp-json/tcn-mlm/v1/*` for membership data that render the dashboards.
* Member dashboards consume `/wp-json/tcn-mlm/v1/member`, `/genealogy`, and `/commissions` to populate the app UI.

TCN Platform keeps the WooCommerce catalogue aligned with the membership tiers consumed by the TCNApp mobile client. Default pricing mirrors the THB membership matrix so the web store and in-app upsells stay consistent:

* **Blue (Customer)** – ฿0: storefront access without commission eligibility.
* **Gold (Affiliate)** – ฿500: earns THB125 on each direct recruit and unlocks passive rewards after two direct recruits.
* **Platinum (Leader)** – ฿1,200: awards THB250 on new directs while maintaining THB125 passive overrides.
* **Black (Elite)** – ฿2,000: leadership renewal tier with continued THB125 passive income from downline activity.

Adjust these figures from **TCN Platform → Membership Defaults** if your deployment uses bespoke pricing; the plugin will continue syncing WooCommerce products and the TCNApp catalogue defaults unless changed.

== Developer Notes ==
* Review [`docs/TCN_PLATFORM_REFERENCE.md`](docs/TCN_PLATFORM_REFERENCE.md) for a function-by-function and endpoint reference covering hooks, parameters, authentication, and example payloads.
* The bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) polls GitHub for releases. Override the repository URL or branch with the `TCN_PLATFORM_UPDATE_REPOSITORY_URL` and `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH` constants or their filters to point to another source.
* Services bootstrap via the lightweight `TCN\Platform\Plugin` container so modules can decide which features run on each request.

== Frequently Asked Questions ==
= How do automatic updates work? =
The bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) points to this GitHub repository. Override the repository URL or branch using the `TCN_PLATFORM_UPDATE_REPOSITORY_URL`, `TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH` constants, or their filter counterparts.

= Can I keep calling `GN_Password_Login_API` from custom code? =
Yes. The class now aliases `TCN\\Platform\\Auth\\PasswordLoginService`, so legacy bootstraps continue to function.

= What happens if I disable the Password Login API module? =
The REST endpoints stop registering, but existing options remain stored. Re-enable the module to restore the endpoints without reconfiguration.

= Which WooCommerce products are created automatically? =
Activation seeds hidden products for the Blue, Gold, Platinum, and Black tiers (if missing) and keeps their pricing/categories synced with admin defaults. Use the **TCN Membership Level** drop-down on other products to link them to tiers manually.

== Changelog ==
= 0.3.42 =
* Fix: Allow authenticated members without the `upload_files` capability to update their own avatar while still blocking cross-profile uploads.

= 0.3.41 =
* Enhancement: Reuse the Password Login bearer token authenticator across profile, membership, and password-change REST routes so API clients can authenticate with tokens or session cookies.
* Fix: Return explicit `WP_Error` responses when bearer tokens are invalid or expired to keep REST error payloads consistent.

= 0.3.40 =
* Enhancement: Include the `WOOCOMMERCE_CONSUMER_KEY` and `WOOCOMMERCE_CONSUMER_SECRET` values in Password Login API responses so authenticated clients can automatically sign WooCommerce REST requests.

= 0.3.39 =
* Enhancement: Stretch the Activity Log layout so administrators can review wider payloads, context details, and table columns without constant horizontal scrolling.

= 0.3.38 =
* Feature: Add a `/gn/v1/profile/avatar` REST endpoint that stores uploaded profile photos in the Media Library, updates avatar metadata, and returns the refreshed `/wp/v2/users/me` payload for the mobile app.
* Enhancement: Document the avatar upload workflow and surface an API tester preset so administrators can review the required headers, payload, and response format.

= 0.3.37 =
* Maintenance: Sync `readme.txt` with the project README and bump the plugin version for distribution.

= 0.3.36 =
* Enhancement: Polish the admin experience with cohesive cards, panels, and typography refinements across settings, the API tester, and the activity log.

= 0.3.35 =
* Fix: Fall back to a direct WordPress product query when WooCommerce's product helper returns nothing so the membership mapping dropdown still lists existing catalogue items.

= 0.3.34 =
* Fix: Prevent a fatal error on the settings screen when WooCommerce isn't available by guarding membership product status lookups.

= 0.3.33 =
* Fix: Load membership product options using every registered WooCommerce status so private or custom-state products appear in the mapping dropdown instead of triggering the "create products" warning.

= 0.3.32 =
* Compatibility: Register membership level names and benefits with WPML so multilingual sites can translate the settings without custom code.

= 0.3.31 =
* Feature: Configure direct and passive commission amounts for every membership tier without touching code, ensuring payouts align with changing compensation plans.

= 0.3.30 =
* Feature: Map WooCommerce products to membership plans directly from the settings page and keep the `_tcn_membership_level` meta synchronised so product slug changes no longer break upgrades.

= 0.3.29 =
* Security: Gate the Activity Log and API Tester admin tools behind a password-protected prompt that expires every 24 hours per administrator.

= 0.3.28 =
* Fix: Normalise membership fees loaded from WooCommerce so THB pricing keeps its trailing zeros when thousand separators are enabled.

= 0.3.27 =
* Fix: Read membership product pricing directly from the WooCommerce post meta to prevent filtered values from truncating fees in the mobile plans API.

= 0.3.26 =
* Fix: Restore numeric pricing in the membership plans API response and expose formatted amounts to prevent "THBNaN" labels in the mobile app.

= 0.3.23 =
* Fix: Suppress default WordPress new user notification emails when registrations flow through the Password Login API.

= 0.3.22 =
* Maintenance: Bump the plugin version for the 0.3.22 release.

= 0.3.20 =
* New API Tester admin submenu that lets administrators compose REST calls, inspect responses, and troubleshoot connectivity without leaving WordPress.
* Bundled Postman-style examples showcasing common authentication and membership requests, complete with sample payloads and expected results.

= 0.3.19 =
* Enhancement: Allow WooCommerce REST API consumer keys to authenticate `gn/v1` customer and order endpoints for trusted integrators.

= 0.3.18 =
* Feature: Add a WooCommerce order creation endpoint to the REST service so external clients can register purchases programmatically.

= 0.3.17 =
* Fix: Ensure long log parameters wrap within the Activity Log DataTable so columns remain readable on smaller screens.

= 0.3.16 =
* Upgraded the Activity Log with a responsive DataTable UI that supports instant searching, filtering, pagination, and clearer sorting of timestamped events.

= 0.3.15 =
* Reformat the Activity Log details with clear labels, badges, and list styling so REST calls mirror the original payloads while remaining easy to scan.

= 0.3.14 =
* Allow unauthenticated clients to load membership plans via `/wp-json/gn/v1/memberships/plans` so the mobile catalogue works for logged-out shoppers.

= 0.3.13 =
* Restored the GN membership upgrade endpoints consumed by the mobile app, including Stripe intent creation and confirmation.
* Added Stripe key inputs to the settings screen so payment intents can be created server-side.
* Surface membership plan details, pricing, and publishable Stripe key over the `/wp-json/gn/v1/memberships/plans` route.

= 0.3.12 =
* Maintenance release to bump the plugin version for distribution.

= 0.3.11 =
* Introduced the Activity Monitor to audit REST traffic and plugin lifecycle events directly from the WordPress admin.

= 0.3.10 =
* Sync the membership summary table with WooCommerce product pricing and suppress stray legacy tiers.

= 0.3.9 =
* Normalise membership level slugs when loading options so the admin summary avoids rendering duplicate blank rows.

= 0.3.8 =
* Ensure membership level defaults backfill missing pricing/commission values so the admin summary table always renders the correct data.

= 0.3.7 =
* Align default membership fees with the updated THB consumer network programme and introduce commission overrides for Platinum/Black sponsors.
* Recalculate cached network sizes so Platinum members promote to Black once their active downline reaches two people.
* Default plugin currency and documentation to THB to match the membership catalogue.

= 0.3.6 =
* Document the TCNApp mobile pricing matrix and update default membership fees to match the app catalogue.

= 0.3.5 =
* Wire the plugin to the public GitHub repository via Plugin Update Checker so WordPress can discover releases automatically.

= 0.3.4 =
* Ensure membership product seeding only creates the Blue, Gold, Platinum, and Black tiers even when legacy options contain stray entries.

= 0.3.3 =
* Prevent a fatal error on the settings screen when membership level option data is stored as strings instead of arrays.

= 0.3.2 =
* Fix a fatal error on `init` by making the sponsor capture callback public so it can be used as an action hook.

= 0.3.1 =
* Harden membership product seeding against malformed level options that could trigger a fatal error during activation.

= 0.3.0 =
* Renamed the combined package to **TCN Platform**.
* Integrated the GN Password Login API service, added module toggles, and exposed CORS/HTTPS configuration in the admin.
* Refreshed the settings UI with module, authentication, and membership cards.

= 0.2.1 =
* Return membership plan prices in minor currency units for mobile display accuracy.

= 0.2.0 =
* Enforce membership pricing and category alignment every load plus scheduled re-syncs.

(See previous entries for earlier releases.)

== Upgrade Notice ==
= 0.3.7 =
Updates membership pricing to the THB consumer network matrix, recalculates network size-driven upgrades, and refreshes documentation.

= 0.3.6 =
Aligns default membership pricing with the latest TCNApp catalogue and documents the mobile plan matrix.

= 0.3.5 =
Enables automatic updates through the GitHub repository using the bundled Plugin Update Checker integration.

= 0.3.4 =
Restricts auto-generated membership products to the official Blue/Gold/Platinum/Black tiers when old configuration data includes unexpected entries.

= 0.3.3 =
Prevents a fatal error on the TCN Platform settings screen when membership levels are misconfigured.

= 0.3.2 =
Addresses a fatal error thrown on sites hooking into sponsor capture during `init`.

= 0.3.1 =
Prevents activation failures when legacy membership level options contain unexpected data structures.

= 0.3.0 =
New modular architecture plus integrated password login endpoints. Review module settings after updating.

= 0.2.1 =
Corrects mobile membership plan pricing units.

= 0.2.0 =
Improves pricing/category enforcement for seeded membership products.
