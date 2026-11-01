# Landing A/B received-data contract

058 is a source-only receiver and analysis page based on frozen052. It does not create experiments, assign variants, route/randomize traffic, activate campaigns, calculate significance, choose winners or assert a causal effect. The reference nonempty hidden formula was unavailable and is not claimed to be copied.

## Trusted source integration

`POST /wp-json/peptide/v1/data/experiments/events` uses native WordPress REST authentication and requires an authenticated user with `manage_woocommerce`. A remote trusted server must use HTTPS and native application-password authentication; a native logged-in cookie request must pass WordPress's REST nonce validation. This endpoint adds no hardcoded key, anonymous bypass or credential UI. Never put an application password in storefront JavaScript.

An existing storefront page view must first be accepted by the ordinary tracking endpoint. Its actual ACK supplies `visitor_id` and `visit_id` (the existing Ingest result); retain the exact acknowledged page view's `page_instance_id` and local path. The external assignment/display integration sends the actual experiment/variant exposure only after that page view. There is no URL/query inference and no new public visitor action or event producer in this patch.

Build this object on the trusted source server from those existing facts, then JSON-encode it as the request body. Variables represent real observed facts, not new identifiers invented for a customer:

```js
const body = {
  protocol: 1,
  events: [{
    event_id: stableOpaqueExposureId, // 8–64 ASCII opaque characters; preserve on retry
    visitor_id: trackAck.visitor_id,
    visit_id: trackAck.visit_id,
    page_instance_id: acknowledgedPageView.page_instance_id,
    occurred_ms: actualDisplayEpochMilliseconds,
    data: {
      version: 1,
      experiment_id: "landing-hero-v1",
      variant_id: "A",
      landing_path: "/campaign/"
    }
  }]
};
```

Send with `Content-Type: application/json` and the native server-side WordPress authentication header. A batch is1–20 events and at most65,536 bytes. Native positive safe integers are required for visitor, visit and epoch milliseconds. Experiment/variant identifiers are1–40 ASCII opaque slug characters; do not use contact text. Event and page-instance identifiers are8–64 ASCII opaque characters. Paths use the existing local path validator: no external URL, query, fragment, control or HTML content. Unknown fields are discarded, including contact text, HTML, source order amounts and source payment claims. New exposure time must be at/after the native visit start, not in the future and within24 hours. The exact non-test native visit owner and a non-test client page view for that owner/visit/path/page instance at/before exposure must already exist.

The source has a per-authenticated-user transient budget of600 submitted events per five-minute window. Rejected items and exact retries consume submitted-event budget. On429 wait and retry with the same original event IDs. No source activation toggle or additional schema is required; missing existing tracking storage returns503 and never installs anything.

Successful actual production-receiver output from the standalone in-memory gate is:

```json
{"protocol":1,"ack":["exposure-0001"],"rejected":[],"retry":[]}
```

This is an actual successful synthetic exposure through the production callback with an existing matching page view in the memory boundary; it is not a deployed/native/browser acceptance claim. `retry` contains valid opaque event IDs whose storage result could not be established. `rejected` contains input indexes and safe reason codes, without echoing invalid raw source text. Permanent reasons include `invalid_event`, `invalid_owner`, `invalid_time`, `page_view_required`, `duplicate_conflict` and `original_visit_missing`.

Idempotency is the existing native unique namespace **(visitor_id,event_id)**. Lookups occur before new-event age checks, so exact delayed retries preserve their original row. Same-visitor UID changes to visit, type/source, timestamp, page instance/path or canonical data conflict; an orphan original visit cannot ACK. Changing the visitor but retaining another visitor's old visit is rejected by exact ownership. Changing both visitor and visit to another legitimate owner uses a different native UID namespace; cross-visitor global UID idempotency is not promised. No global UID lock, GET_LOCK, alternate unique index or schema is introduced. INSERT IGNORE losers reread the immutable winner; absence/read failure is retry, never an invented ACK. Session, visitor, visit, page-view, stage, activity and tab rows are not created or mutated.

## Analysis contract

The new menu is **落地页 A/B 分析**, slug `pc-data-experiments`. `GET /peptide/v1/data/experiments` retains native Data permissions and no-store responses. It uses one accepted calendar period with no VS. Search targets experiment identifier or landing path. Domain/country selectors reuse the typed report-filter states and original saved visit facts at the exclusive period cutoff; there is no billing, referrer, direct-traffic or latest-other-visit fallback. It supports fixed sort keys, bounded page size and complete CSV.

The cohort is valid **reported exposure events in [from,to)**. Groups are `(experiment_id,landing_path,variant_id)`, preserving exact identifiers. Exposure count is actual received events; raw exposed visitors are distinct observed visitor IDs. Repeats of the same assignment in a visit share one outcome membership and visitor UV. Earlier valid assignments for the same experiment/visit before the cutoff are also checked for variant/path conflicts. A contaminated experiment visit is excluded from every variant's eligible denominator, behavior, paid visitor, order and revenue totals, while its raw exposure and contamination counts remain explicit. An unseen variant is never fabricated as a zero-control row. Search and group selection run after contamination is established.

Outcomes require genuine existing non-test client page/product views, server successful add-to-cart, checkout client page views or native server order placement after the first cohort exposure in that exact visit and before the period end. Visit stage is not substituted. Paid orders are native `Operations::paid_orders` facts, non-test and paid, with both original order metadata IDs matching the eligible visit/visitor and native creation epoch at/after first exposure and before period end. Order IDs count once per experiment. Revenue uses native total minus native refunded total. Paid users are distinct linked observed visitor IDs, never buyer email. Conversion is paid visitors / eligible exposed visitors ×100, null with no eligible denominator. These definitions are an explicit received-data analysis, not an owner assignment engine or verified reference formula.

Every response declares partial coverage (`full_coverage:false`), observed cohort, canonical filters/search/sort/page and currency. No valid cohort is `not_observed`; missing storage is `unavailable` with unavailable coverage counts; SQL failures return503. Reports do not convert missing collection into measured zero performance. Keyset batches of500 run to exhaustion and advance malformed raw records.

`format=csv` exports the identical complete selected cohort rather than the visible page, with text escaping, conversion unit, native revenue currency, exclusive epoch bounds and partial-coverage/status metadata. HEAD returns no body. Only the exact experiment GET route was added to the existing raw CSV whitelist. Accepted-date/filter/search/sort generation and draft guards prevent old rows or CSVs from appearing under new selections. Failure exposes retry and clears the accepted export authority.

## Privacy and verification

The module registers a separate personal exporter via DataPages admin hooks. It uses native `Privacy::visitors_for_email`, exact native owner joins and canonical payload eligibility; raw pages are500 with501 lookahead, so filtered records still advance correctly. Only the email-bound customer's experiment/variant/path, event/page-instance identifiers and observed time are exported. Existing whole-event retention and whole-visitor erasure apply. No existing privacy exporter or policy implementation is modified; the module adds its own scope text.

Focused actual code gates:66 PHP assertions cover permission/body/batch/rate, typed parser/PII discard, age/PV/owner, exact replay/conflict/native namespaces, INSERT IGNORE races/no winner, no activity mutation,500/501/1001 reads, malformed500 lookahead, contamination, native order identity/time/refund/test/dedup, filters/cutoff, empty/unavailable/SQL failures, actual route callbacks/Range/config, privacy501 and real CSV GET/HEAD. Actual renderer `node --test` has23 assertions for accepted dates/domain/country/search/sort/paging, out-of-order/error/retry/range cancellation, draft CSV, escaping and empty coverage. Existing actual CSV guard has61 assertions. Commands, outputs, source hashes and skips are in `plans/058-verification.json`.

All test fixtures are portable, in memory and relative to the repository. No WordPress bootstrap, native fixture/customer/catalog mutation, real DB, HTTP/browser, mail, install, service, cleanup, commit, merge or push was performed. Source is held for Root full read/rerun/integration; it is not deployed or native/browser accepted. Frozen052/054/055/056 and the user/runtime trees remain untouched.
