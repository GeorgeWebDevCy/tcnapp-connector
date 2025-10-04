=== TCN Platform ===
Contributors: georgewebdevcy
Tags: woocommerce, mlm, memberships, commissions, genealogy, authentication
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
TCN Platform unifies the WooCommerce membership/MLM engine and the GN Password Login REST API into one modular plugin. Enable the full stack to automate tier upgrades, sponsor relationships, commissions, dashboards, and mobile authentication for the TCNApp client—all without juggling multiple codebases. Toggle optional modules as your deployment matures.

=== Modules ===
* **Membership & MLM (always on)** – Seeds and syncs Blue/Gold/Platinum/Black membership products, manages genealogy, promotes members based on network rules, tracks commissions, exposes `tcn-mlm/v1/*` REST endpoints, and injects dashboards into WooCommerce My Account.
* **Password Login API** – Provides hardened REST authentication endpoints under `gn/v1` with HTTPS enforcement, one-time token login, rate limiting, registration, password reset/change flows, and configurable CORS support. The historic `GN_Password_Login_API` class is aliased for backwards compatibility.

Configure modules under **TCN Platform → Modules**. The Membership & MLM module is locked on so the core data structures remain available, while the Password Login API can be disabled if another login layer is preferred.

== Installation ==
1. Upload the `tcn-platform` directory to `/wp-content/plugins/` (or clone the repository there).
2. Activate **TCN Platform** from the Plugins screen.
3. Visit **TCN Platform** in the admin menu to review module toggles, set the Password Login API allowed origin (for SPA/mobile clients), and adjust membership defaults.
4. Flush permalinks (`Settings → Permalinks → Save`) so WooCommerce account endpoints are registered.

== Password Login API Configuration ==
* **Allowed CORS Origin** – Exact origin permitted to post to `gn/v1` endpoints. Leave blank to restrict to same-origin calls.
* **Allow HTTP During Development** – Permits non-HTTPS requests when `WP_DEBUG` is true; use only on local environments. You can further customise HTTPS behaviour via the `gn_password_api_allow_dev_http` filter.

== Mobile App Integration ==
* TCNApp uses `/wp-json/gn/v1/*` for authentication and `/wp-json/tcn-mlm/v1/*` for membership data.
* `/wp-json/gn/v1/login` accepts `mode=token` for cross-origin hand-offs plus optional IP / user-agent locking filters.
* `/wp-json/gn/v1/register`, `/forgot-password`, `/reset-password`, and `/change-password` wrap WordPress flows with opinionated validation and neutral responses to avoid account enumeration.
* Member dashboards consume `/wp-json/tcn-mlm/v1/member`, `/genealogy`, and `/commissions` to populate the app UI.

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
