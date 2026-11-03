# Payment creation response diagnosis — 2026-11-03

Author: Codex.

The owner's subsequent manual payment generated a new central attempt after the local outbound-policy repair. That attempt remains `unknown` without a hosted-checkout URL. The reviewed provider source is deployed, so this is not an old source upload or merely reuse of the previous locally rejected attempt. End-to-end payment is still unresolved.

## Source and validation

`Provider_Client` now optionally emits `pc_panapay_provider_diagnostic` with a fixed, bounded diagnostic DTO. It distinguishes local policy, transport, HTTP status, JSON and response-contract branches. It contains only reason enums, bounded numeric HTTP/cURL codes, whitelisted WP error codes and known response-field shapes/enums. Only previously configured trusted checkout hostnames can be emitted. Unknown field names, raw text, provider messages, merchant credentials, customer details, URLs/parameters and order references are excluded. There is no default logger. Observers cannot change the payment result through the DTO, and observer exceptions are isolated.

Non-200 bodies may be decoded only for bounded diagnostic shapes (64 KiB, depth 16); their payment outcome remains `unknown`. No transport retries, redirect following or status reconciliation were added. Failures thrown before entry into the observer are not claimed to be covered.

The native WordPress-policy/synthetic-transport test passed 104 checks, preserving all original 36. It covers every reason branch, observer exceptions, absent observers, non-200 success/failure bodies, malformed/oversized/deep JSON and hostile secret-marker values/keys/hosts. PHP lint and diff checks passed. These checks perform no real HTTP requests or financial actions.

## Open investigation

The discarded response from the latest historical request cannot be reconstructed from this new hook. The provider's existing domain/order dashboard and future safe diagnostics are needed to establish the actual failure. The new unknown attempt must not be reset or replayed without independent reconciliation evidence. A parameterless HEAD response is not evidence of how the signed creation request behaved.

No channel fields, numeric order-reference conversion, site-string transformation, callback behavior or response-envelope fallback is guessed from the reference platform. A central `unknown` row with no checkout data localizes this case before hosted-checkout display and notification processing.

Private deployment and runtime evidence belongs under `D:/Codex/Tasks/2026-11-03/payment-provider-response`. Append actual deployment results separately; source validation is not a claim of a successful payment.
