# Optional page context v1

Receiver baseline: `d5951e97f857e4df2444710bf04485b73b93062d` (Attribution v1 / tracking DB4). This additive protocol accompanies tracking transport1. It requires no new session initialization, authentication, client identity, cookie or database column. The receiver and reports can be complete while **SOURCE_OWNER_PENDING remains true**. This document does not authorize a producer deployment or runtime injection.

## Transport and coexistence

Use the existing same-origin `POST /wp-json/peptide/v1/track/events`. The optional `GET /wp-json/peptide/v1/track/session` and its existing privacy, staff and enabled gates are unchanged. The server owns `pc_vid`; never submit visitor, visit, customer or order identity. Existing limits remain20 events and65536 bytes per batch, existing clock correction/future clamp/24-hour admission, ID and tab rules, and ACK/rejection behavior.

Synthetic envelope (replace times with fresh send/occurrence times; IDs are frozen until terminal receipt):

```json
{
  "protocol": 1,
  "tab_id": "synthetic-tab-031",
  "sent_at": "2026-10-31T12:00:00.000Z",
  "events": [{
    "event_id": "synthetic-context-031",
    "page_instance_id": "synthetic-page-031",
    "seq": 1,
    "type": "page_view",
    "occurred_at": "2026-10-31T12:00:00.000Z",
    "page": {"page_type": "shop", "path": "/collections/synthetic/"},
    "data": {
      "page_context": {
        "version": 1,
        "kind": "collection",
        "term_id": 123,
        "geo": {"status": "known", "country_code": "DE"}
      },
      "attribution": {
        "version": 1,
        "host": "example.test",
        "landing_query": {"utm_source": "Synthetic"},
        "referrer_host": null
      }
    }
  }]
}
```

`event_id`, `page_instance_id`, `type` and valid `occurred_at` retain their original requirements; `protocol`, `tab_id`, `sent_at`, `seq`, page details and extensions follow the existing optional/default rules. Event/tab/page identifiers use the existing8–64 ASCII alphanumeric/hyphen admission. Do not send a real term ID until the producer has resolved a native `product_cat` term. The term123 above is a placeholder and is not a runnable catalog fixture. The original `page.page_type` enumeration remains unchanged; `kind=collection` is independent even when an older adapter emits `page_type=shop`.

Attribution v1 remains independently specified in [data-attribution-protocol-v1.md](data-attribution-protocol-v1.md). Neither extension supplies or overrides the other. When both are attempted, the response contains `attribution` before `page_context`, preserving all per-attempt attribution results.

## Strict optional metadata

Only `page_view.data.page_context` is recognized. Absence means unobserved. An explicit null, false, list/malformed object or invalid supported value rejects the entire optional context; a valid core page view still persists and ACKs. Rejected metadata is omitted from saved JSON and receipts never echo it. Extra keys are discarded. No partial/truncated context is stored.

Canonical order: `version`, `kind`, optional `term_id`, optional `geo` (`status`, optional `country_code`).

- `version`: integer1, not a string, float or boolean. Other integers are unsupported versions; missing/null/wrong types are bad context.
- `kind`: exactly `home`, `shop`, `product`, `cart`, `checkout`, `account`, `order_received`, `order_pay`, `content`, `collection`, `search` or `unknown`.
- `term_id`: required strict positive integer for collection. Numeric strings, floats and booleans fail. Every other kind forbids this key, even null. A true new event validates the native `product_cat` identity. No product’s present-day categories or compressed `/shop` path may substitute for it.
- `geo` absent: no country observation. Null or malformed geo is invalid. `status` is exactly `known`, `unknown` or `unavailable`.
- `known` requires exactly two ASCII letters in `country_code`, normalized uppercase. Whitespace, trailing LF/CRLF, extra characters and multibyte lookalikes fail. A true new event validates against **WooCommerce’s global country catalog**, not selling, allowed or shipping countries. It does not require map geometry.
- `unknown` and `unavailable` forbid `country_code`, including null. Use `unavailable` if the source has no country service. A source handling loopback/private/local input reports `unknown`; it must not use a store, billing or shipping country fallback.

Country data is **source_reported**, not an IP check or proof of physical location. The receiver neither collects IP/coordinates/city/search terms nor calls an external geolocation service. No confidence score or threshold is defined.

## Frozen replay, original association and receipts

Context is stored inside the core event’s JSON only when that event is first inserted. No schema change, UPDATE, backfill or visit snapshot claim is introduced. For true new UIDs the current native term/global-country catalog applies. Saved syntax and recognized duplicates never re-query those catalogs: deleting a term does not rewrite a saved collection and a same-UID immutable retry can still return `already_saved`.

Freeze the UID, page context and core identity fields together: cleaned path/page type, product/variation IDs (default0), page instance, tab and sequence. Duplicates compare saved `page_view`/client source and original visitor/event association plus these fields and canonical context. Clock-corrected occurrence and receipt times are deliberately excluded: a frozen envelope retried later can be re-clocked without becoming a payload mismatch. Independent attribution and other data keys do not participate in page-context comparison.

Original visit ownership must still exist and match. A replay after a30-minute rollover keeps the original association; it does not create a fresh visit to host this fact. Same-batch or INSERT-IGNORE race outcomes are read from the actual saved winner. Receipt positions follow the original **request index**, not a UID map: repeated UIDs with different payloads receive separate results. A saved legacy event without context cannot acquire it by replay. A changed frozen payload is terminal.

With no attempted context key, this extension is absent and the legacy response remains unchanged. An attempted key adds only:

```json
{"extensions":{"page_context":{"version":1,"results":[{"event_id":"synthetic-context-031","status":"applied","code":"saved_context","retry":false}]}}}
```

Each result contains only `event_id`, `status`, `code`, `retry`. Unsafe IDs are reported as an empty string; original core rejections remain unchanged.

| Status | Code | Retry | Meaning |
|---|---|---|---|
| applied | saved_context | false | A newly persisted event contains this context. |
| applied | already_saved | false | The original saved event and association match. |
| rejected | bad_page_context | false | Invalid shape/value or invalid new native catalog identity. |
| rejected | unsupported_version | false | A well-typed unsupported integer version. |
| rejected | wrong_event_type | false | Valid core event has another type. |
| rejected | core_rejected | false | Core validation did not accept the event. |
| rejected | duplicate_without_context | false | The original event has no context; it cannot be enriched. |
| rejected | duplicate_payload_mismatch | false | Frozen core/context differs from the saved fact. |
| rejected | original_visit_missing | false | Original association is missing or mismatched. |
| retry | server_error | true | Saving/readback has not been proved successful. |

Precedence inside an accepted request is core rejection, wrong type, syntax/version, duplicate recovery, then true-new catalog/save. Disabled, declined, parse/size and rate gates retain their old bodies. A core ACK alone is **not** evidence that metadata was accepted; metadata failure neither removes ACK nor enters core rejections. Missing/unrecognized v1 receipts mean unsupported/unknown outcome, never success. Retain the frozen envelope until a recognized terminal receipt; retry only pending/retryable outcomes. Existing403 stop and429 `Retry-After` behavior is unchanged.

## Saved facts, report units and privacy

Realtime’s new JSON-only protected `GET/HEAD /wp-json/peptide/v1/data/realtime/dimensions` uses accepted server `anchor_ms` and nine independent10/20/30-minute windows, or explicit accepted history dates. It adds no domain/UTM global filter. The old realtime live/history JSON is unchanged.

- Recently viewed/carted: events. Sold: native net product units by paid-order creation time. Pages: saved page-view paths/PV. These lists preserve existing eligibility and expose Top5 plus the complete matching group total; observation coverage does not apply.
- Map and active countries: each cohort visit’s latest saved geo observation before cutoff; distinct visitors per country. A later unknown/unavailable replaces known, but an absent geo does not erase an earlier observation. Rows are nonexclusive and cannot be summed to a global UV. Map and active windows are independent.
- Popular countries: each page view’s own geo observation, counted as PV; this is a local documented definition, not a verified reference-site hidden formula.
- Collections: each saved collection observation/PV, with deleted native identity shown as `#ID`. Known noncollection, unknown and unobserved coverage remain distinct. Source path compression cannot be repaired by the receiver; preserving real taxonomy paths remains producer work.
- Sources: Attribution v1 first-successfully-captured visit snapshots, distinct visitors per source tuple. Missing key, observed empty and exact-case values are separate. Original received-time cutoff is not the later CAS clock; late claims can change a re-query of an earlier anchor. No direct source is inferred.

Coverage counts known/unknown/unobserved/unavailable base records separately; no observations is not a zero-country claim. Anchors are cutoffs, **not MVCC/as-of snapshots**: late events and activity updates may change results. Country outlines are an offline reviewed Natural Earth110m asset. Countries without geometry remain in the accessible table; no visitor dots or fabricated coordinates are drawn.

The visitor timeline formats only normalized metadata; malformed context/attribution is omitted rather than stringified. The independent personal-data exporter `peptide-core-page-context` follows the existing email/account/order association and exports normalized kind, collection ID, geo state/code, source_reported, path and UTC time. Its opaque item IDs do not expose event UIDs. Raw page views are paginated500 at a time (501 lookahead), including sparse nonmatching rows. Existing retention and exact visitor erasure remove the whole event as before.

## Source-owner acceptance

The source owner must implement this in its normal producer/adapter and deploy through its own reviewed normal push. Data must not scrape, inject a collector or enrich requests at runtime. Validate privately:

1. Normal producer push with frozen metadata/UID and existing privacy gates.
2. Recognizable per-index v1 receipt, including invalid metadata with valid core ACK.
3. The **original** saved event and visit association, including retry after rollover and after a term is deleted.
4. Timeline and independent privacy export/owned erasure.
5. Actual realtime windows/history/coverage/map/table behavior using those saved facts.

Include context absence, unknown, unavailable, noncollection, collection, global-country-but-no-outline, mixed Attribution v1, duplicate payload mismatch and retryable readback failure. Use exact private ownership ledgers and preserve retained data. Fixture insertion, a manual HTTP probe or core ACK cannot clear **SOURCE_OWNER_PENDING**. No production cookie, nonce, customer value or credential belongs in an acceptance document or public log.
