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

## Provider binding format clarification

The owner subsequently supplied the enabled root-domain list and explicitly confirmed the provider's instruction: the binding UI accepts only the root domain (for example `aaa.com`), without a hostname prefix. Preserve that configuration. The earlier suggestion to add the store subdomain to that UI is withdrawn and must not be repeated as a repair prerequisite. This UI format does not establish a different format for the API's `site` field; no transformation of the signed HTTPS site value, domain/DNS change or payment retry was performed.

A fresh read-only production observation still found no additional real diagnostic event or attempt. The historical unresolved creation response was not retained. A new browser tab could navigate to the provider dashboard, but DOM and screenshot reads also timed out, so the provider-side result remains unverified. A private lookup note with the exact merchant order reference and timestamp was prepared outside Git for the owner to request the provider's processing status/error. No message was sent to the provider. Do not reset or replay the uncertain attempt to obtain a new diagnostic sample.

## Local reference and native FPM verification

The owner reported that the provider dashboard contains no corresponding order. The `PH...` value is allocated locally before the outbound request; it is not a provider-issued order number and is not evidence that the provider received or accepted anything. No dashboard record also does not reveal the discarded HTTP response. The local unresolved attempt remains protected.

To check the real web-worker environment rather than only CLI, an operator-private temporary PHP probe outside the web tree was invoked through the existing HQ UNIX FPM socket. It loaded the live application and registered REST routes, inspected only bounded transport metadata, then made one credential-free, TLS-verified, zero-redirect **HEAD to the gateway root**, not the payment endpoint. The production `fpm-fcgi` worker had cURL/streams available, no local gateway-policy block, no pre-HTTP short-circuit or request-argument callbacks, and received HTTP 302 in 259 ms. The live provider source hash matched the reviewed overlay. The probe produced no stderr, exited successfully and its exact private file was removed. An 11-case in-memory FastCGI protocol test passed. No software package, public route, order/session method or financial write was added.

This verifies present root HTTPS connectivity from the actual FPM worker only. It does not prove that the earlier creation request was transmitted, show its API response, or validate payment. The next required evidence is a single owner-operated creation from a fresh private/guest checkout session with diagnostics enabled. The owner was asked to select credit card and stop at hosted checkout before card entry/payment confirmation. The existing unresolved order must not be reset; repeated submissions of that checkout reuse the unresolved attempt and cannot capture a new creation response.

## Captured real business rejection

The next owner-operated creation produced HTTP 200 with `response_contract_invalid`: the documented top-level `status`, `type`, and `payment_url` were absent and `data` was null. It did not produce hosted checkout. The owner separately supplied a screenshot of YY's working card-entry page and clarified that this was YY, not a successful result from this implementation. Card information is entered after hosted checkout; this failure occurs before that stage.

To obtain the actual business error without plaintext response logging, a temporary, exact-endpoint native `http_api_debug` observer was deployed as a single additional HQ MU file in `pay-capture-66918e0a4659`. It seals only response bodies to an operator public key; its private key remains root-only on the server. It does not persist request bodies, credentials, headers or customer fields from the request. Capture is bounded to 64 KiB per body, four records, 256 KiB total and a 24-hour absolute deadline. Twenty-three in-memory boundary checks and an actual production-FPM local synthetic dispatch/seal/root-decryption check passed without HTTP calls. The regular application, provider client and shared configuration remained unchanged; an encrypted backup preceded publication. The collector and its private artifacts require operator cleanup when investigation ends; they are not product source or Git content.

The following real owner-operated creation returned a 101-byte response containing business code `0`, `data: null` and this actual provider message: **`轮询系统里没有这个b站域名或者被停用`**. This is an HTTP response with a business rejection, not a confirmed payment session. A credentials-safe comparison also confirmed that the active merchant number matches the owner's reference screenshot, and that the latest attempt's frozen merchant scope, signing secret and gateway match the current account. No credentials were printed or copied.

The English notice is the plugin's own `Session_Service::session_url()` protection: the attempt is not `created` or lacks its saved encrypted hosted URL. In this case, `Provider_Client` cannot validate the documented successful-session fields, returns `unknown`, and that local notice is displayed. The notice is not the literal provider error and does not mean the user must enter card details first.

Keep the provider's root-only binding format as clarified above. The observed rejection requires checking the API `site` value and its merchant/polling-system association; it does not justify editing the binding UI or inventing root-domain normalization. The owner was asked specifically for the provider's accepted `createPayment.site` example (including scheme/prefix/trailing slash), distinct from the binding UI's input format. The supplied document uses a URL example but does not establish this root-versus-subdomain rule. Do not guess a different signed site, replay unresolved attempts, or claim payment is fixed.

Only redacted error summaries and native verification receipts are stored in the D-drive task. The sealed body and its private key remain server-side. Source pointers and sanitized evidence are sufficient for coordination; full order references, encrypted bodies and credentials stay out of Git and project memory.
