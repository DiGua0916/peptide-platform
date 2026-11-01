# Operations dimension details v1

082 adds a complete multi-metric backend for Operations dimensions. Baseline: 6b56d41a6842e677681ce906466f63205bb25e70. This is an explicit local received-data contract using unchanged Visit_Metrics, Range, Report_Dimensions, Report_Filter and native paid-order rules. It does not claim hidden Shopname formulas were verified. Sources v1 and its JSON/CSV remain unchanged.

## Registration, permission and request

Dimension_Details::register_routes() registers GET /wp-json/peptide/v1/data/dimension-details. Integration owner083 must call it from the existing unconditional REST registration callback. No new schema, producer, business setting or public identity service is added.

The caller must be an existing authenticated native WordPress user with manage_woocommerce; unauthorized returns401, authenticated without capability403. response() repeats permission checking before reading request parameters or storage. Existing Range::from_params handles store timezone, inclusive calendar dates, DST and the comparison period. Do not calculate dates with fixed24-hour arithmetic in the client.

| Parameter | Accepted values / default |
|---|---|
| dimension | utm (default), country, browser, domain, device, channel |
| sort | One of the10 metrics below; default uv |
| sort_period | current (default), previous, change |
| order | desc (default), asc |
| page | Integer1..999999999; default1 |
| per_page | Integer1..100; default20 |
| q | String, trimmed, at most100 Unicode characters; default empty |
| split_medium | Integer0/1; default0;1 permitted only with dimension=utm |
| columns | Optional comma-separated unique metric keys, ordered,1..10; default all10 |
| entry_domain_state / entry_domain | Existing Report_Filter typed domain contract |
| country_state / country | Existing Report_Filter typed country contract |
| format | json (default) or csv |

Enum parameters reject arrays, null, booleans and unknown/empty values. Numeric controls accept native integer or unsigned decimal string within bounds, reject other types. columns rejects duplicates, unknown/empty keys, empty list or arrays. q must be valid UTF-8. Public identity parameters visitor_id, visit_id, order_id, customer_id, user_id, email, ip and user_agent are unsupported and rejected.

Filters are the same typed AND conditions as existing reports. Domain state is all/unobserved/value (value requires the canonical host); country state is all/unobserved/unknown/unavailable/value (value requires a recognized native country code). Values are not accepted when state is not value. Omitted filter fields normalize to filter:null; explicitly supplied All normalizes to the existing object with both states=all.

## JSON contract

The response is:
~~~js
{
  version: 1,
  dimension: "utm",
  range: originalRangeObject,
  tracking: true,
  currency: "USD", // actual native store currency code
  params: {
    dimension: "utm", sort: "uv", order: "desc", sort_period: "current",
    page: 1, per_page: 20, split_medium: 0, q: "",
    columns: ["uv","customers","viewed_uv","carted_uv","paid_uv","orders",
              "conversion","sales","aov","uv_value"],
    filter: null
  },
  total: completeMatchedGroupCount,
  rows: [],
  coverage: {current: periodCoverage, previous: periodCoverage}
}
~~~

Examples describe shape only; currency and values come from actual store facts. format is not echoed inside params. JSON always includes all10 metrics; columns controls CSV selection and the accepted frontend context.

Each row contains key, dimension:{state,value}, medium:null or a typed UTM-medium object, label, and current/previous/change maps. label contains only the dimension label; it never concatenates medium. Split UTM medium is displayed/exported separately through its typed field and dedicated CSV columns. key is JSON encoding of [typedDimension, mediumOrNull], with state then value in each typed object; Unicode and slashes remain unescaped. It is not a PHP-serialized value and clients must not unserialize it. Missing/empty/unobserved and literal user strings such as "unknown" remain different identities. No visitor, visit, order, customer, email, IP or raw UA is output.

Rows are the union of complete current and previous groups. A previous-only group is retained with genuine empty current accumulators. Search runs on labels and typed values, then sorting runs on the requested metric/period, then JSON pagination applies. Null sorts below every numeric value in both directions; equal metrics use deterministic key order. all_rows() has the identical full selection, ignoring page/per_page without50/100-row truncation.

## Metrics and units

Metric order is exactly:
uv, customers, viewed_uv, carted_uv, paid_uv, orders, conversion, sales, aov, uv_value.

- uv: distinct original visitor IDs in visits beginning in the selected period, per typed group.
- viewed_uv/carted_uv: those visit-start visitors whose existing saved stage is at least1/2. These reuse saved stage semantics, not a historical as-of stage reconstruction.
- customers: unchanged existing mixed native customer keys from linked visits and native orders (account ID or normalized guest email inside the accumulator only). Neither identifier is returned.
- paid_uv: distinct valid positive original visitor metadata saved on the native paid orders. This is not the same denominator/cohort as visit-start UV. A missing, deleted or mismatched original visit makes dimension attribution unobserved but does not erase the original positive order visitor fact. Missing/invalid visitor metadata contributes no paid-visitor identity.
- orders: native paid orders created in the selected period after existing test/demo exclusion.
- conversion: orders / uv ×100, null when uv=0; it can exceed100 because multiple orders and order-created/visit-start cohorts differ.
- sales: native order total minus refunded total, using the unchanged existing reducer; native currency.
- aov: sales/orders; unchanged0.0 when no orders.
- uv_value: sales/uv, null when uv=0.

Conversion change is current minus previous in percentage points; known previous0 is valid. Other changes use unchanged Range::change relative percent, with previous0 => null. Any missing operand gives null. Nonfinite numeric results are never emitted. Empty count sets produce0, while undefined rates remain null.

## Dimension attribution and coverage

All visit cohort reads are bounded keyset batches of500, with exact original visitor joins and both visit/visitor test flags excluded. Facts are read once per batch at that period's exclusive end cutoff. Orders use native creation time and their exact saved original visit+visitor metadata, looked up in unique-pair chunks of500, including visits that began outside the cohort. There is no per-product/per-group query loop or latest-visit fallback.

- utm: first saved utm_source, optionally split by separately typed utm_medium; case, captured-missing, empty and unobserved remain separate.
- domain: saved entry_domain on the exact original visit.
- country: latest valid original-visit country observation before the exclusive period cutoff, using the unchanged reader's occurred_ms/id tie rule. Unknown/unavailable can replace older known observations. No billing-country fallback or claim of verified physical location.
- channel: unchanged traffic-v1 project policy derived from saved UTM/referrer facts. It does not rewrite UTM tags; direct/source-hidden is not proof of a direct customer action.
- device: the original valid visit's stored desktop/mobile/tablet value. Empty or malformed value is unknown; missing/deleted/mismatched original ownership is unobserved.
- browser: the original visit's own visitor row's stored Edge/Opera/Samsung/Chrome/Firefox/Safari/Other classification. Empty/malformed is unknown. Missing/deleted/mismatched original ownership is unobserved. No raw UA, fingerprint, newest-other-visitor inference or verified-hardware claim.

Each period has visits and orders coverage objects:
~~~js
{
  unit: "visits", // or orders
  base_count: 0, matched_count: 0,
  value: 0, missing: 0, empty: 0, unknown: 0, unavailable: 0, unobserved: 0
}
~~~
All counters are integers. base_count includes valid non-test candidates before filters/search; matched_count is after the typed AND filters. State counters sum to matched_count and count visits/orders, never UV. Search/pagination do not rewrite this full source coverage. For split UTM, coverage states describe source, not a sum of source and medium.

Unavailable tracking storage or SQL errors return503 rather than a measured empty success. No identities, events, visit activity or financial rows are created by this read-only report.

## CSV

format=csv rejects _envelope/_jsonp/_fields by presence. Selection and numeric/text cells are fully prepared before Csv_Response exists, so query errors precede any header/body. Existing raw CSV serve authority, HEAD no-body handling and UTF-8 BOM remain unchanged; the only old-source edit adds this exact route to its allowlist.

Filename: data-dimensions-DIMENSION-FROM-TO.csv.

Base header:
version,group,dimension_state,dimension_value,dimension_label,medium_state,medium_value,currency,current_from_epoch_s,current_to_epoch_s,previous_from_epoch_s,previous_to_epoch_s.

group is the requested dimension enum, not an internal identity. For each selected metric in requested order append current_KEY, previous_KEY, change_KEY. Conversion current/previous suffixes are _pct, its change suffix is _pp; every other change suffix is _pct. Then append coverage_current/previous_visits/orders_COUNTER for each of base_count,matched_count,value,missing,empty,unknown,unavailable,unobserved.

Every row has equal width. Genuine numeric values are preserved, null is blank, untrusted source/medium/labels/currency go through Csv_Response::text formula protection. CSV uses the complete identical searched/sorted selection, regardless of the displayed JSON page or column ordering.

## Verification boundary

Focused tests execute the actual class, reader helpers, native callback and raw CSV transport with in-memory WordPress/Woo/DB boundaries. They do not bootstrap a site, seed native fixtures, query a real database, create customer facts or send network/mail/payment requests. Source verification is not native/browser acceptance and does not establish whole-Data or hidden-reference formula parity.
