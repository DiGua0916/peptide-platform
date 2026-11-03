# Payment provider outbound access — 2026-11-03

Author: Codex. The user reported that checkout could not obtain a payment session, confirmed the merchant parameters were the same as the working reference platform, and asked how to resolve the failure. This stage fixes the verified production HQ outbound restriction; it does not replace merchant parameters, create a charge, or implement additional payment channels.

## Actual change

- Identified the effective gateway of the single active, unarchived, undeleted payment account without decrypting or displaying its merchant signing secret. The native WordPress HTTP policy blocked that gateway.
- Validated the configured HTTPS origin with the payment configuration parser, public-only DNS answers, normal CA/SNI certificate validation and a successful TLS 1.3 handshake.
- Ran the existing encrypted HQ backup service before changing configuration. Copied the new encrypted archive to the restricted local task directory and verified its SHA-256. This stage did not perform a new backup decryption or database restore.
- Added only that exact lowercase API hostname to the production shared configuration's `WP_ACCESSIBLE_HOSTS`. Preserved all four existing internal hosts and `WP_HTTP_BLOCK_EXTERNAL=true`; did not allow wildcard domains or copy the checkout-host list into outbound permissions.
- Used the existing payment deployment lock, configuration hash/inode and active-account option hash checks, release-pointer checks, a root-only rollback checkpoint, a same-directory PHP-linted candidate and atomic replacement. Preserved root ownership, application group and 0640 permissions. Rechecked the release and configuration after verification. A noncooperating external publisher is outside the advisory lock's guarantees.
- Reloaded PHP-FPM and verified the HQ HTTPS login endpoint remained healthy. The release pointer stayed on the existing payment-modal release; no application runtime files or merchant settings were changed. The shared configuration survives normal release-pointer switches.

## Verification and limits

- A new native WP-CLI process reports that the configured gateway is allowed, external HTTP blocking remains enabled and an unrelated public hostname remains blocked.
- An uncredentialed `GET /` to the gateway through `wp_safe_remote_get`, with normal TLS verification and redirects disabled, received HTTP 302. No merchant fields, signing secret or authorization header were sent, the redirect was not followed and the payment creation endpoint was not called. This verifies basic outbound HTTPS reachability, not acceptance of a signed payment request.
- Public HTTPS checks returned 200 for HQ login, the first store management login and the first customer storefront.
- The saved payment account option was byte-identical. Central attempt and event tables were hash-identical across the configuration-change verification window: one existing `unknown` attempt and zero events. No attempt was deleted, reset, retried or marked paid.
- CLI policy validation plus FPM reload and HTTP health were observed; this stage did not install a diagnostic FPM endpoint or claim a separate FPM policy introspection test.
- The earlier unknown order remains protected against duplicate creation. Correcting the outbound restriction does not automatically recreate its session. Reconcile that order with the provider before any further payment attempt; the user performs a fresh real checkout only after confirming no prior debit.
- A real checkout, valid provider notification, store order completion and provider settlement are still unverified. Embedded rendering is already supported when the documented provider response contains `type=iframe`; the presentation setting is separate from the API connectivity repair.

Evidence is in the D-drive task `2026-11-03/payment-gateway-egress`: preflight state, TLS receipt, configuration-change receipt, encrypted-backup copy receipt, native reachability result and public health checks. Server paths, actual gateway hostname, account identity, customer/order identifiers, private configuration and encrypted backups remain outside Git.
