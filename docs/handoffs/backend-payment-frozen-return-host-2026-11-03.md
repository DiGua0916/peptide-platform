# Preserve an existing payment return across a buyer hostname change

2026-11-03 · Codex · STORE-DOMAIN-CUTOVER-01

The owner requested `www` customer domains while keeping store management addresses unchanged. Changing the registered public origin previously rejected an already saved buyer return before HQ could redirect it. This source candidate preserves the original authenticated return of an existing attempt; new payment creation still accepts only the current registered public origin.

This isolated branch starts at `67b5b7491791c84f3fa007ff411795c92a7aa9e7`. The subsequent `4eea29da377113ab03786935c81e0d45bc4de320` update contains documentation only and was read without changing the fixed source base. No other working tree, server, DNS, merchant setting, order or existing uncertain attempt was modified. The parent coordinator owns review, integration and deployment; this branch is not pushed by the implementing child.

## Change

`Hub_Urls::attempt_return_url(row, currentSite)` reads only the persisted, authenticated `return_box` in its existing `hub-return` AEAD context. It checks the exact positive store ID, full immutable UUID, order ID versus parsed local reference, and the existing seven-day lifetime. Canonical database numeric strings remain supported; ambiguous casts and missing bindings fail closed.

For an explicit HTTPS dual-domain root installation, only the previously frozen DNS hostname may differ from the current public registration. Scheme, effective port, installation path, native Woo callback structure, allowed unique query keys, local reference and token syntax retain the existing strict validator. Unchanged legacy local installation URLs retain their old validation behavior; an HTTP or subdirectory hostname migration does not acquire a new exception.

The only runtime call-site change is in `Hub::returned()`, after its existing browser-token hash, provider return signature, full store UUID and seven-day checks. The return still points to the exact frozen URL, never a browser-supplied target. It does not rewrite `return_box`, `request_hash`, order data, status, amount, currency, trust snapshots, secrets or payment state. It does not call the provider, recover status, create a payment or apply a notification.

The HQ browser token and the store-return token are different tokens. The original `Hub::returned()` check verifies the former against the HQ row. The store token remains authenticated inside `return_box` and is later verified by the original store callback. Comparing the latter to the HQ token hash would reject every valid return and is intentionally not introduced.

`Hub::payload()` continues using `Hub_Urls::return_url()` against the current public origin. Registering a new alias or knowing a parent domain cannot authorize a new creation target. The hostname exception is reached only through a saved encrypted attempt, not through request parameters or a generalized alias list.

## Actual validation

- New `tests/frozen-return-domain.php`: 100 checks passed with the actual URL helper, return handler, AEAD crypto and signature implementation. Only DB reads and the final redirect boundary are synthetic in-memory objects; no WordPress bootstrap, DB, HTTP or financial service runs.
- Existing `tests/management-addresses-pure.php`: all 33 checks still pass, including rejecting an old host for new creation and keeping event delivery at the management origin.
- Three changed/new PHP files pass syntax checks; `git diff --check` passes.

The new test covers original plain/pretty callbacks and exact port 443 preservation; current-host creation versus rejected old-host creation; invalid store/UUID/order/reference/lifetime and numeric types; wrong protocol/port/path/query/token shape; real AEAD context swap and tamper rejection; the actual return handler's browser-token/provider-signature checks; ignored attacker-supplied redirect targets; and unchanged persisted rows for both successful and failed browser hints. Payment writes, mail and outbound HTTP remain zero. Redirect interception prevents the handler's process exit; it is not a claim of a complete production browser flow.

The initial `php -n` run omitted the OpenSSL extension and stopped at crypto availability before any check. It was rerun with the existing OpenSSL DLL explicitly loaded; no runtime dependency was installed or global PHP configuration changed.

## Deployment boundary

Only `includes/class-hub-urls.php` and the one-line call-site in `includes/class-hub.php` need overlaying onto the actual HQ release. Preserve the already deployed provider response diagnostic, its observer, shared outbound gateway permission and all other concurrent UI changes. No schema migration or historical row rewrite is required.

The domain cutover must separately retain the old exact Host's DNS/TLS, same-store routing, Core bound alias and native callback path before canonical redirects. Keeping DNS alone does not solve HQ's former rejection, and a whole-host redirect must not intercept payment callbacks. The compatibility does not expand staff login or machine-event access on the old public host. Retain the old callback until all relevant old browser tokens expire and recheck pending history before retiring it; unknown attempts and outbox records are not cleared when a browser TTL ends.

The owner has since confirmed the provider's domain-binding UI accepts root domains only. Preserve the already confirmed root-domain configuration and withdraw the earlier suggestion to add a subdomain there. This UI format does not prove that the signed API `site` value should be rewritten; this change does not alter site formatting or provider protocol. The unresolved real `unknown` attempt is preserved, and a requested branding/domain cutover is not a claim to have fixed provider creation or demonstrated collection, notification, reconciliation or settlement.
