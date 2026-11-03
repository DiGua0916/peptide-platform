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

## Delivered diagnostic stage

The source checkpoint `c065a43ace6d91c2897374bd4db64b8e8b744346` was synchronized to the payment branch and main. After a verified encrypted HQ backup, the production HQ was switched to `pay-response-diag-c065a43ace6d`. Only the provider client and a temporary HQ MU observer differ from the preceding release; 4,201 copied entries were verified and the final manifest has 3,747 files. Shared configuration remained byte-identical. Native PHP, FPM reload and all three public HTTPS health checks passed. A separate post-check confirmed both target paths are direct and the old release's provider source was unchanged.

The temporary observer writes only the sanitized DTO to an operator-private file outside the web tree, requires a root-owned non-writable parent and a pre-created worker-owned 0600 regular single-link file, uses a nonblocking lock, and stops at 256 KiB or the absolute deadline of 2026-11-04 03:06:20 UTC. The existing group-writable `/var/log` permissions were preserved; a separate restricted `/srv` directory was used. Thirty-seven in-memory file-boundary checks passed for the final sink. A native blocked-origin CLI probe recorded one explicitly synthetic diagnostic with zero transport calls. This proves observer wiring, not provider communication. No historical response can be recovered from it. Capture stops automatically, while later removal of the private log/observer remains an operator cleanup task.

Independent verification matched the live provider file to the reviewed source SHA-256, found three HTTPS 200 responses and confirmed that the existing unknown attempt and prior failed attempt remained unchanged. No provider creation, state reset, card entry or charge was performed. Existing input checks also ruled out the document's intentional-failure email example and reserved email suffixes without disclosing an address.

The provider browser's page operations timed out, and the relevant Chrome/Edge windows were minimized. The owner was asked to restore the logged-in provider window; domain binding and provider-side order lookup remain unverified. The existing checkout tab was retained without submitting, reloading or clearing its form. Payment remains unresolved until the actual creation failure is established and hosted checkout succeeds.
