# Source-marked visit status v1

This is a Data intake/report filter, not a normal/abnormal classifier and not a Shopname algorithm. An authenticated source explicitly marks an existing visit. No IP, test flag, activity threshold or payment result is used to guess a verdict. No source business feature is silently enabled.

## Private intake

POST `/wp-json/peptide/v1/data/visit-classifications`, with native WordPress authentication and `manage_woocommerce`. A logged-in admin uses the native REST nonce; a remote source uses a native WordPress application password over HTTPS. Do not embed those credentials, a nonce or an application password in a public storefront. There is no anonymous classification event or custom shared API key.

```json
{"protocol":1,"events":[{"event_id":"source-verdict-0001","visitor_id":1,"visit_id":2,"revision":1,"data":{"version":1,"status":"normal","rule_version":"source-rule-v1"}}]}
```

The IDs in this example are placeholders; the source must supply its actual existing visit and original visitor pair. Positive JavaScript-safe integer IDs, revision1..1,000,000,000, stable event_id8..64 ASCII letters/digits/hyphens and an opaque rule_version1..40 ASCII letters/digits/dots/underscores/hyphens are required. Status is `normal`, `abnormal` or explicit `unclassified` (retract an earlier label with a higher revision). Unknown extras are omitted. No reason, contact, IP, raw rule, URL or arbitrary payload is retained.

The native uniqueness domain is `(visitor_id,event_id)`. Retry the same source operation with its original visitor/visit/payload. Exact retries ACK without rewriting their original server receipt time. Same visitor UID with changed visit, type, status, revision or rule version conflicts. A mismatched visitor and old visit rejects. Two different legitimate visitor namespaces may independently use the same UID; this is not a global UID guarantee. Do not move retries across owners or change the original UID casing. Use a producer-specific UID prefix rather than borrowing native checkout/order event identifiers. The source owns revision sequencing within each visit and must coordinate its own rule producers.

The server writes occurred_ms=received_ms from its own clock; source timestamps cannot backdate historical classification. No visit, visitor, order or session is created; no stage, activity or order state is updated. Body64KB, batches20, per authenticated account600 events/5minutes. Whole request400/403/413/429/503 follows native REST; item rejection `server_error` is retryable, malformed/conflicting/orphan input is not. A200 ACK is stored observation acceptance, not verified classifier accuracy.

## Selection and history

GET `/wp-json/peptide/v1/data/conversion` accepts optional `visitor_status=all|normal|abnormal|unclassified` alongside existing group/range/format parameters. Absent/all retains the original JSON and 17-column CSV exactly. Group remains device/browser/utm/domain/country/os/screen.

For each current/previous period, select non-test visits starting in `[from,to)` and their actual existing original owners. Read only classification events for those visits with server received_ms strictly before that period's exclusive end. Highest revision wins. Different status OR rule_version at the highest revision makes the visit conflicted/unclassified; identical verdicts under different operation UIDs do not create a conflict. No accepted verdict, malformed historical facts and explicit retraction are unclassified, never assumed normal. A later higher revision resolves an earlier conflict. Missing storage/SQL errors return503 instead of an all/normal fallback.

Candidate coverage counts visits by normal/abnormal/unclassified, plus observed/conflict. Coverage is partial and full_coverage=false; one verdict does not establish complete source coverage. The UI names this **来源标记访问状态**, retains未分类 and does not expose classifier settings.

Every selected section uses the same visit-owner selection: top counts, eight visitor steps, seven group dimensions, native paid orders/sales and observed checkout/payment channels. Global visitor counts union visitor IDs across selected visits; group counts can overlap. Device/browser use the selected native visit/owner; other dimensions use that original visit's saved facts before the period ends. No other visit, billing data or current browser fallback supplies a label.

As in the existing historical report, first four steps use accumulated visit.stage; middle client steps and server payment_success observations use accumulated owner-bound facts. This is not an event-time snapshot. Classification alone is as-of server receipt cutoff. Financial order count and sales additionally require order creation in the period and both native tracking metas matching a selected original owner; the existing native paid eligibility and total-minus-refund calculation stay intact. Paid visitor events remain distinct from native paid-order counts. The selected cohort can be empty without proving that no real normal customers exist.

Selected JSON echoes visitor_status, coverage, cutoffs and `classified_visit_start_cohort` scopes. Selected CSV keeps the same17-column header then adds aligned typed filter/cutoff/coverage rows; status remains a textual key, never a numeric zero. The remaining rows are the same accepted selected summary. Existing GET/HEAD, format and formula-escaping protections apply.

The independent email-associated personal export includes canonical status/revision/rule-version and original visit/server receipt time. Existing whole-event retention/deletion cover it. Raw500/501 export pages advance even when malformed rows are all filtered. Existing visit/impression exporters are not changed.

No runtime/DB/native fixtures/external mail were executed for this source delivery. Pure boundary tests do not prove a real source classifier is producing labels. Root must review/rerun the frozen source gates and perform any authorized native integration separately.

## Acknowledgement and partial failure

The059 receiver returns the actual protocol shape below. These operation IDs are synthetic examples, not customer identifiers.

Successful batch:

    {"protocol":1,"ack":["classification-demo-0001"],"rejected":[]}

Partial failure:

    {"protocol":1,"ack":["classification-demo-0001"],"rejected":[{"event_id":"classification-demo-0002","code":"server_error","retry":true}]}

There is no independent retry array. Confirm only the event IDs listed in ack. For rejected entries, resend the original unchanged observation only when retry is exactly true; retry false is terminal and requires reconciliation instead of a new UID or altered payload. Handle whole-request HTTP400, unauthenticated HTTP401, authenticated-but-unauthorized HTTP403 and other transport-level failures separately; they are not a per-event acknowledgement.


## 072 approved native operational policy and manual correction

The four-field authenticated external contract remains compatible. Automatic labels now have a separate server-internal writer, triggered only after genuine accepted client persistence; public clients cannot submit origin:automatic. Canonical optional manual reason and frozen automatic policy/evidence are documented in data-visit-classifier-policy-v1.md. Public management authentication, limits, exact native ownership, immutable visitor/UID namespace and server received cutoffs remain unchanged. This is source-only until Root integrates; do not infer runtime enablement from these files.

072 revision2 serializes public/external, internal automatic and manual classification writers under the same database/prefix/original-visit GET_LOCK (at most2seconds). Manual expected-revision selection and insert, and automatic priority/policy verification, run inside that guard. INSERT SELECT independently requires IS_USED_LOCK(name)=CONNECTION_ID() so reconnect cannot perform an unguarded retry. Lock timeout/SQL errors or lost ownership without an immutable winner remain retryable server_error; there is no false ACK. Release runs in finally; no nested lock or business transaction. Native visitor/event UID uniqueness, four-field payload and exclusive server receipt cutoffs are unchanged.
