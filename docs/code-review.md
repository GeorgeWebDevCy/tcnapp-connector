# Code Review Report

## Overview
I reviewed the TCN Platform plugin with a focus on security-sensitive REST endpoints, membership onboarding flows, and admin tooling. Below are the most important issues I identified while auditing the PHP sources under `includes/`.

## High Priority Findings

### 1. Password reset verification codes are exposed to unauthenticated callers
The `/gn/v1/forgot-password` route accepts a `return_verification_code` flag. When the flag is set, the handler issues a fresh verification code and returns it in the HTTP response without any authentication. Because the same code can be supplied to `/gn/v1/reset-password`, an attacker who knows or guesses a username/email can fully reset that account without receiving email confirmation. This is a critical account-takeover vulnerability and the `return_verification_code` option should be removed or restricted to trusted, authenticated callers before release.【F:includes/Auth/PasswordLoginService.php†L411-L432】【F:includes/Auth/PasswordLoginService.php†L888-L899】

### 2. Discount redemption API trusts caller-supplied member IDs
`DiscountEndpoints::handle_transaction()` records a redemption for the `member_id` that the client submits, but it never checks that the discount token actually belongs to that member. A malicious vendor (or anyone who can reach the endpoint) could take a token issued to member A, submit it with `member_id` for member B, and have the transaction recorded against the wrong account. Add a strict comparison between `$member_id` and the token’s stored owner before recording the transaction.【F:includes/Rest/DiscountEndpoints.php†L202-L307】

### 3. Failed vendor registrations leave orphaned WordPress users
During registration the service creates the WordPress user before validating the requested vendor tier. If the tier is invalid, the handler returns an error but never deletes the newly created account, leaving inconsistent state and potential attack surface (e.g. duplicate usernames, spam users). Defer user creation until after tier validation or clean up the user on error.【F:includes/Auth/PasswordLoginService.php†L332-L408】

### 4. Expired JWT refresh tokens could be replayed indefinitely (fixed)
`handle_token_refresh()` used to fall back to decoding any presented JWT with `$allow_expired = true` when the transient lookup failed. That meant once an attacker stole an old API token, they could continue refreshing it forever—even after the JWT had expired—because the payload still revealed the user ID and the service never checked the expiration claim. The handler now enforces the normal expiry check and returns a 401 error if the transient entry is missing or the decoded payload does not identify a valid user, closing the replay window.【F:includes/Auth/PasswordLoginService.php†L175-L211】【F:includes/Auth/JwtTokenService.php†L61-L172】

## Medium Priority Findings

### 5. Admin assets load third-party resources from a CDN
When the activity log screen loads, the plugin enqueues DataTables assets directly from `cdn.datatables.net`. WordPress.org guidelines and many enterprise environments require bundling assets locally to avoid privacy and availability issues. Consider shipping the JS/CSS inside the plugin instead of depending on an external CDN.【F:includes/Admin/Assets.php†L17-L55】

## Positive Notes
* The autoloader and service registration pattern keeps bootstrap logic clean and testable.【F:includes/Plugin.php†L30-L111】
* REST handlers consistently sanitize scalar inputs and use descriptive error codes, which will ease client integration.【F:includes/Rest/DiscountEndpoints.php†L34-L160】

