# Known local payment rejection — 2026-11-03

Author: Codex. This corrects a deterministic local transport-policy rejection being recorded as an ambiguous payment result. It does not authorize automatic retries after a network timeout or an uncertain provider response.

## Source change

`Provider_Client::create()` now asks the native WordPress HTTP policy whether the validated endpoint is blocked **before** invoking the transport. A definite local rejection returns `failed`; no provider request is made. Existing timeout, WP_Error, non-200, malformed or incomplete response branches remain `unknown`, and no automatic HTTP retry was added. TLS verification, public-origin validation, zero redirect following and response-size limits remain unchanged.

The new `tests/provider-client-local-policy.php` loads the actual WordPress HTTP policy implementation without WordPress configuration or a database, and substitutes only the URL boundary and transport. Thirty-six assertions passed: blocked requests call transport zero times; allowed iframe/redirect responses retain their contract; ambiguous transport/response outcomes remain unknown and transport is called once. Both changed PHP files passed syntax checks and the diff check passed.

## Live diagnosis

The public and management runtimes both receive the enabled central payment account. A fresh real browser checkout displays credit card. A separate, synthetic Store API cart retained the payment method before and after address recalculation; its address and cart were restored afterward. The user's original browser tab displayed a selected credit-card method and the old unconfirmed-session error. Read-only order inspection identified reuse of an earlier unresolved attempt, not a newly missing frontend integration.

The owner confirmed that this attempt never reached hosted checkout and had no card entry or debit. Independent evidence binds the old attempt to its frozen gateway, the pre-repair local host policy and unchanged provider transport source; no pre-HTTP override, checkout URL or notification event was present. This supports a bounded **local-rejection reconciliation**, not a fabricated provider failure notification.

Deployment and any reconciliation results must be appended after they actually complete. An uncertain attempt without this evidence must still be reconciled with the provider before release. No provider payment creation, card entry or charge is performed by this stage's validation.

Private evidence remains in the D-drive task `2026-11-03/payment-methods-live`; order identifiers, frozen credentials, private backups and customer fields are excluded from Git.
