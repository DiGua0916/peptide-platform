# Landing A/B received-data contract

## Current delivery status — 2026-11-01

The original source-checkpoint paragraphs and gate results below are historical provenance. Their source-only, no-runtime, no-commit and pending-review statements describe those checkpoints, not the current delivery state.

The Data implementation is included in source commit 828731b2d3ef00303101ff0f5e21a50bd5687c6e and the accepted 085 package of 480 production files on the original service on port 8091. Stage 086 fresh fetch, nonforce main push and remote readback confirmed that commit. Local operational evidence is at D:/Projects/peptide-platform/plans/verification/085-local-release/result.json, D:/Projects/peptide-platform/plans/verification/085-native-release/result.json and D:/Projects/peptide-platform/plans/verification/086-git-main-sync/result.json; these are local evidence paths, not files promised in a remote checkout.

Original-PV binding, order and channel native proofs from stages 074/078 are retained separately. They do not establish that real customer favorite, share or A/B exposure sources were produced, accepted or tested. DiGua owns persistent NEW favorite additions, completed share callbacks, actual variant display/assignment and the trusted-server connector; the current integration contract is [data-digua-integration-v1.md](data-digua-integration-v1.md).

SMTP/provider/account configuration and actual external delivery belong to mail business/operations. Data records and aggregates supported actual sending outcomes and image/link requests: transport acceptance does not prove inbox delivery, and an image/link request does not prove human reading. External joint acceptance is a dependency, not missing Data coding. This phase requests no SMTP credentials or configuration changes.

## Original 058 checkpoint context (historical)

058 is a source-only receiver and analysis page based on frozen052. It does not create experiments, assign variants, route/randomize traffic, activate campaigns, calculate significance, choose winners or assert a causal effect. The reference nonempty hidden formula was unavailable and is not claimed to be copied.

## Trusted source integration

`POST /wp-json/peptide/v1/data/experiments/events` uses native WordPress REST authentication and requires an authenticated user with `manage_woocommerce`. A remote trusted server must use HTTPS and native application-password authentication; a native logged-in cookie request must pass WordPress's REST nonce validation. This endpoint adds no hardcoded key, anonymous bypass or credential UI. Never put an application password in storefront JavaScript.

The public tracking ACK does **not** expose visitor_id or visit_id. The adapter also does not expose database IDs. Opt in to window.VialCoveVisitorTransport.subscribePageViews(listener): only a genuinely ACKed current page view supplies an immutable opaque reference containing event_id, page_instance_id, path, page_type and occurred_at. This is not a source of experiment decisions.

Send that reference to the assignment owner's trusted server. After verifying the real variant is still displayed, retain the actual observation time. The trusted server uses the same native manage_woocommerce authentication to POST /wp-json/peptide/v1/data/experiments/binding:

~~~json
{"protocol":1,"page_view":{"event_id":"synthetic-page-view-0001","page_instance_id":"synthetic-page-instance-0001","path":"/campaign/"}}
~~~

This example contains synthetic opaque references, not real customer facts. Only these three page_view fields are accepted; pick them from the SDK reference rather than forwarding extra fields. A unique existing non-test client page view and its exact non-test original visitor/visit must match all three values. Success returns protocol:1 and binding containing visitor_id, visit_id, page_view_event_id, page_instance_id, path, page_view_occurred_ms. No matching PV returns404; ambiguous original matches return409; unavailable storage/SQL returns503. The read-only lookup never creates a visitor, visit or PV. It is authenticated server/admin only and does not change the public ACK.

The lookup accepts at most8192 bytes, strict protocol integer1, and at most300 valid-shape requests per authenticated user per300 seconds. Invalid shapes return400, oversize413, rate excess429, unauthenticated401 and insufficient capability403. Native REST authentication runs before storage access. Responses are not cacheable; no application password belongs in storefront JavaScript.

The server then submits the exposure below using the resolved binding. The actual observed exposure must be at/after the saved PV, in the same page instance/path, not in the future and within24 hours. If the callback arrives late, verify the currently visible variant and record a new real observation time; do not invent an earlier display or rewrite an old timestamp merely to pass validation. See data-digua-integration-v1.md for the complete Chinese source-owner checklist.

Build this object on the trusted source server from those existing facts, then JSON-encode it as the request body. Variables represent real observed facts, not new identifiers invented for a customer:

```js
const body = {
  protocol: 1,
  events: [{
    event_id: stableOpaqueExposureId, // 8–64 ASCII opaque characters; preserve on retry
    visitor_id: binding.visitor_id,
    visit_id: binding.visit_id,
    page_instance_id: binding.page_instance_id,
    occurred_ms: actualDisplayEpochMilliseconds,
    data: {
      version: 1,
      experiment_id: actualExperimentId,
      variant_id: actualDisplayedVariantId,
      landing_path: binding.path
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

**Original 058 source-checkpoint result (historical):** All test fixtures are portable, in memory and relative to the repository. No WordPress bootstrap, native fixture/customer/catalog mutation, real DB, HTTP/browser, mail, install, service, cleanup, commit, merge or push was performed. Source is held for Root full read/rerun/integration; it is not deployed or native/browser accepted. Frozen052/054/055/056 and the user/runtime trees remain untouched.
