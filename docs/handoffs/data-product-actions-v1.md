# Product action observations v1

Data receives explicit successful source operations through the existing anonymous `/peptide/v1/track/events` envelope. It does not implement a wishlist, share UI, purchase-intent score or A/B business system. Future A/B reporting uses a separate authenticated protocol.

Call `window.VialCoveVisitorTransport.pushProductAction(type, facts)` only after the actual feature's success callback. Its result is the queued operation ID or `false`; it does not promise a server ACK or business success.

Every facts object requires `event_id` (8–64 ASCII letters/digits/hyphens), integer `version:1`, positive safe-integer parent `product_id`, and optional nonnegative integer `variation_id` (default0). Reuse the operation ID for reentrant callbacks; never generate an ID for each retry.

- `product_favorite`: `action:"add"`, after a successful addition. This counts additions, not a net/current favorite balance.
- `product_share`: `method:"native"|"copy_link"|"other"`, `result:"completed"`. A cancelled/failed share must not call the API.
- `product_intent`: `kind:"quick_view_open"|"buy_now"`, `result:"completed"`. This is an explicit operation definition, not an inferred Shopname intention formula.

Unknown extras are omitted. Never pass recipients, contact information, URLs, tokens or arbitrary business metadata. Parent/variation publication and ownership are validated on first receipt. Client reports are not server-attested business/payment facts.

The existing collector supplies actual page path/type, page instance, sequence, tab and time, freezes identity once, and uses its current ACK/backoff/batch transport. Exact same-ID retries ACK the original event; changed type, payload or identity conflicts; deleted original associations reject. No funnel/cart/paid stage is raised. Successful new operations update only received-event/last-signal activity fields, as visibility observations do.

The queue is memory/document/BFCache scoped; no new disk queue or cross-refresh delivery guarantee exists. ACK tombstones retain the operation within that lifecycle. A source retaining operation IDs must not reconstruct an old envelope with a new page/tab identity after refresh: that is a conflict, not a new success. Supporting durable cross-document retries requires preserving the entire original envelope outside this API and is not claimed here.

Reports count valid original-owner, non-test client events by `[from,to)` occurrence time. Domain and country filters use the original visit and each period cutoff. Each parent × action kind × period × filter scope independently becomes `observed/partial/full_coverage:false` only when valid operations were received. No observation is `not_observed` with null fields; missing storage is `unavailable`. One operation never proves all-store coverage. Variations have null new metrics; parent UV uses distinct original visitor IDs across all its variations. Favorite ranking is the 21st table; missing comparison-period values and changes remain blank.

Independent email-bound personal export includes only canonical product action facts under the original visitor/visit joins. Existing whole-event erasure and retention already cover these events. No new tables, options, raw recipient store, fixture data or business UI are introduced. Native source producers are not yet present: the API is ready for their real-success integration.

The active document retains at most 2,000 action UID tombstones; further new operations return false rather than evicting identities and risking duplicates. Queue overflow, retirement or collection opt-out can also return false. Source integration must handle this as lack of collection acceptance.
