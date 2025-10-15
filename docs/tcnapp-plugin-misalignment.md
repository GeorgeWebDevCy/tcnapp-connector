# TCNApp ↔︎ TCN Platform Integration Notes

This guide summarises the integration mismatches observed between the React client ("TCNApp") and the WordPress plugin ("TCN Platform"), plus concrete adjustments to bring the two back in sync. Each section references the authoritative behaviour implemented inside the plugin so front-end changes can be prioritised accurately.

## Token refresh endpoint

* **React behaviour:** The app attempts to refresh bearer tokens via `/wp-json/gn/v1/token/refresh` and falls back to an older compatibility route.
* **Plugin contract:** The compatibility layer exposes `/wp-json/jwt-auth/v1/token/refresh` for JWT clients while the Password Login API already handles refreshes internally.【F:includes/Auth/JwtAuthEndpoints.php†L19-L170】
* **Fix:** Point the React `refreshToken` call at `/wp-json/jwt-auth/v1/token/refresh` and remove the unused legacy fallback logic so refresh attempts always hit the supported endpoint.

## Password reset payloads

* **React behaviour:** `requestPasswordReset` posts many redundant fields (`identifier`, `user_login`, `user_email`, etc.) and `resetPasswordWithCode` sends both identifier and email plus duplicated password keys.
* **Plugin contract:** `/wp-json/gn/v1/forgot-password` only inspects `username` (treated as a username or email) and an optional `return_verification_code` flag, while `/wp-json/gn/v1/reset-password` expects `{ login, password, verification_code? | key? }` and rejects extra reset tokens.【F:includes/Auth/PasswordLoginService.php†L560-L659】
* **Fix:** Send `{ login: identifier, return_verification_code: true }` when requesting a reset and `{ login: identifier, password: newPassword, verification_code: code }` (or `{ ..., key: resetKey }`) when confirming the reset.

## Registration payload shape

* **React behaviour:** Registration requests include WooCommerce order/customer payloads, a hard-coded `membership_tier`, and numerous unused metadata fields.
* **Plugin contract:** `/wp-json/gn/v1/register` only validates `username`, `email`, `password`, optional `first_name`/`last_name`, `account_type`, and an optional `vendor_tier` slug when creating vendor accounts.【F:includes/Auth/PasswordLoginService.php†L432-L552】
* **Fix:** Trim requests to the minimal shape—members: `{ username, email, password, first_name?, last_name?, account_type: "member", membership_plan? }`; vendors: `{ username, email, password, first_name?, last_name?, account_type: "vendor", vendor_tier }`.

## Membership QR helpers

* **React behaviour:** The client invokes `/wp-json/gn/v1/membership/qr` and `/wp-json/gn/v1/membership/qr/validate` for QR code flows.
* **Plugin contract:** The plugin still ships QR endpoints via `MembershipQrEndpoints`, but they are legacy helpers layered on top of the newer membership plan + Stripe upgrade flows.【F:includes/Plugin.php†L18-L110】【F:includes/Rest/MembershipQrEndpoints.php†L1-L214】
* **Fix:** Confirm whether the deployment relies on these QR routes. If not, disable the React QR helpers to avoid depending on endpoints that may be deprecated.

## Discount transaction payloads

* **React behaviour:** Transaction requests and history lookups use camelCase keys such as `grossAmount` and a `scope` query parameter.
* **Plugin contract:** The discount endpoints accept snake_case fields (`gross_amount`, `discount_amount`, `net_amount`, `member_id`, `vendor_id`) and history filtering via explicit `member_id`/`vendor_id` query arguments.【F:includes/Rest/DiscountEndpoints.php†L26-L362】
* **Fix:** Map camelCase variables to the documented snake_case names before posting and replace the `scope` parameter with `member_id` or `vendor_id` depending on the view being requested.

## Membership service responses

* **React behaviour:** Stripe onboarding flows expect camelCase fields like `requiresPayment` and `paymentIntentClientSecret`.
* **Plugin contract:** Membership plan and Stripe responses expose snake_case keys—e.g. `requires_payment`, `publishable_key`, and raw Stripe intent objects—while still including a backwards-compatible `publishableKey` copy.【F:includes/Membership/MembershipModule.php†L384-L599】
* **Fix:** Normalise plugin responses in the React service layer, converting snake_case to camelCase only after parsing.

## Vendor promotion allowance

* **React behaviour:** Vendor dashboards read a `promotionSummary` field from the vendor tier catalogue.
* **Plugin contract:** Tiers expose a `promotion_allowance` string, with legacy `promotion_summary` support limited to internal formatting helpers.【F:includes/Rest/VendorEndpoints.php†L24-L120】
* **Fix:** Adjust the React vendor service to consume `promotion_allowance` (mapping to a percentage or count as needed) instead of the non-existent `promotionSummary` key.
