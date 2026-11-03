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

## Production delivery and bounded reconciliation

- Deployed the reviewed `Provider_Client` file to a new root-owned HQ release, preserving the preceding payment-modal application and the repaired shared outbound configuration. All 4,201 copied entries were compared, the final 3,746-file manifest was generated, PHP syntax passed and FPM reload plus HTTPS health passed. This is a single-file overlay from source commit `8086189f09fe7c84b0961bd2a28a996347aa1c84`, not a deployment of the entire latest main branch. The prior release and rollback metadata remain available.
- An actual native production CLI probe against a deliberately blocked, unrelated origin returned `failed` with zero transport-hook calls. It sent no provider request and created no payment records.
- Ran fresh encrypted backups on HQ and the store. The store backup service succeeded; its archive lives on the independent data volume. An initial receipt collector looked in the wrong directory and failed its receipt assertion, then the actual archive was located and checked for age format, stable size and SHA-256. No additional backup decryption/restore was performed in this stage.
- With the store's native per-order lease held, rechecked the exact pending order, active attempt/reference, reservation owner, central binding, amount/currency and order fingerprint. Required no paid date, transaction, refund, stock reduction, transaction ledger row, checkout URL or notification event.
- Used a primary-database transaction and exact compare-and-set to classify only the evidenced old local rejection as `failed`, release its central active-creation slot and retain every identity, frozen field and historical row. No provider `FAILED` event or signed notification was fabricated.
- Recovered the store through its existing `recover_central()` path, with a process-local transport guard allowing exactly one signed read-only HQ STATUS request and only a verified failed response. The original book identity/count and reservation owner were preserved; the attempt and creation reservation became `failed`. Strict native postconditions confirmed that the order remained pending/unpaid and its financial/stock snapshot did not change. No `start()`/CREATE call, card entry or charge occurred.
- The user's existing Chrome context has a fresh checkout tab with its saved address and the credit-card option visible. It is retained for user-operated payment. The original user tab/form was not refreshed, resubmitted or cleared by the agent. The separate diagnostic carts were emptied and the temporary IAB tab closed.

Only this demonstrated pre-transport rejection was reconciled. Other unknown attempts still require reconciliation evidence; the absence of a charge alone does not authorize a general reset. Actual signed provider creation, hosted payment, notification and settlement remain for the user's next manual test.
