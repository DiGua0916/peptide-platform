# Normal customer checkout capture

Approved feature revision: `679fd3c251a85ce48c6de26e2c1308903dfb52c2`, following feature commit `0095afa` on `evan/data-013-customer-flow-20261029`. The existing frontend dependency is `f9a1207`, merged as `de93c85`; release integrates this history into `evan/work`. The approved code is now deployed on the local8091 preview. The final release handoff commit changes documentation only; plugin/theme code remains byte-identical to679fd3c.

## Responsibility and prerequisites

The source pushes; Data receives. The existing customer-browser observer sends actual observations to `POST /peptide/v1/track/events`. Native WooCommerce business hooks write authoritative source facts through the existing server recording layer. Data validates, deduplicates, attributes and reports received records. There is no storefront crawler, scheduled pull, second collector identity, simulated visitor dashboard, direct counter write or browser-asserted paid fact.

Use the normal published storefront, products and Woo Blocks cart/checkout. Tracking must be enabled through the existing platform setting or `wp peptide tracking enable`. The existing enabled/protocol1, staff, GPC, optout, admin and preview gates still apply. Test as an eligible anonymous customer, using a separate browser session from the administrator. Do not publish or overwrite merchant layout drafts merely to test a theme update. Existing template137's unpublished documents are separate from theme source.

The optional checkout factory requires registered `wp-data`, `wp-hooks` and `wc-blocks-data-store`. It reads the native stores and native form validity/required flags; it never reads address values or validation messages into analytics. It feature-detects APIs and is owned by the existing observer's stop/suspend/bfcache lifecycle. Missing integration leaves the basic collector and DOM submit-attempt fallback operational; it does not infer missing step completion. Installed Woo's experimental hook names may change in future versions and need re-verification.

## Additive event contract

These client observations share the existing UUID/page-instance/sequence/ACK/retry queue:

| Event | Allowed data | Meaning |
| --- | --- | --- |
| `checkout_address_completed` | `source: "native_blocks"` | Settled native required controls are valid and relevant native validation-error keys are absent. A field edit or closed address editor alone is insufficient. |
| `checkout_shipping_selected` | `method`, `origin` | Settled selected shipping rate ID, reconciled after native promise callbacks. User origin requires a trusted native radio action plus the matching successful hook and final selected rate. Failed/reverted optimistic choices are not successful selections. |
| `checkout_payment_selected` | `method`, `origin` | Native selected gateway slug; user origin requires a trusted native radio action plus the matching hook and final selected method. Hooks alone can be automatic. |
| `checkout_step_applicability` | strict booleans `address_required`, `shipping_required` | Native form/cart applicability. No completion is fabricated for an inapplicable step; virtual carts and pickup can have no shipping step. |

`method` is an ASCII identifier matching `[A-Za-z0-9_:-]{1,80}`. `origin` is exactly `initial`, `user` or `automatic`; `initial` includes restored defaults. Passive trusted click/change capture is restricted to native radio controls in `#shipping-option` and `#payment-method`, reading only their static method-ID attribute. Hook evidence and trusted intent can arrive in either order before deferred reconciliation; both must match the settled native method. A hook alone or untrusted/programmatic change cannot establish human intent. An unchanged automatic reselection emits nothing. Pending native work retains the bounded evidence; settled failure/revert/mismatch and observer suspend/reset/stop clear it. Unknown fields are dropped. No names, emails, address/phone/postcode values, card data, payment payloads, order tokens or validation messages are included.

The existing `checkout_submit_attempt` remains a client attempt. With the native bridge installed, native submit is preferred, including keyboard submit; the DOM click fallback is gated so one click plus native submit does not double-count. An invalid submit can produce an attempt, never an order or payment-success fact.

New `payment_attempt` is **server-only**, passively recorded at priority1 on `woocommerce_rest_checkout_process_payment_with_context`, before Woo's legacy gateway invocation. It contains only the native method slug and the existing order association. Each genuine new processing context gets a bounded unique UID; duplicate invocation for the same context within a request is ignored. Zero-total/no-payment branches and missing/deleted associations produce no fabricated attempt or visitor. Context, result, payment payload and order business status are not modified by capture.

Existing `order_placed`, `payment_success`, `payment_failed`, status events, deterministic paid UID (`srv-order-ID-paid`) and stages0–5 remain authoritative. First order attribution is preserved across retries, later visits and different browsers. Partial/deleted associations are not reassigned; cookie-free completion uses the original surviving link. There is no schema-version change.

Transport is best effort. Offline/unload/frozen-browser delivery can be lost; native redirect never waits for analytics ACK. Client order is described by page-instance/sequence; server processing is linked through the native order. Concurrent client/server timestamps do not prove a total causal ordering.

## Explicit local payment providers

Both conditions are required: `wp_get_environment_type() === 'local'` and option `peptide_core_local_payments === 'yes'`. The mode defaults off. `WP_DEBUG`, development environments and a persisted flag in production do not authorize these providers. There is no public enable endpoint or arbitrary-order paid endpoint.

Use normal WP-CLI against the intended local installation:

```sh
wp --path=/path/to/local/shop option update peptide_core_local_payments yes
# Disable after testing (or restore its exact previous value):
wp --path=/path/to/local/shop option delete peptide_core_local_payments
```

In this task, the disposable installation is `D:/Projects/peptide-sync-work/20261029/site`. The approved local preview at `D:/Projects/peptide-test2/public` now intentionally has this flag set to `yes`, with strict environment `local`. Before release the option did not exist, so `wp --path=D:/Projects/peptide-test2/public option delete peptide_core_local_payments` both disables it and restores that prior flag state. Keep the target database/path explicit.

Release record: the source database was backed up before integration/flag changes to private `D:/Projects/peptide-customer-work/20261029/preview-peptide_test2-before-customer-flow-20261030-012545.sql` (SHA256 `ffc68011a9ce0e10b388d483033df0a7a4dc0f79a92dff0e6cfe945280584ad8`). Both preview junctions remain on the integration checkout. Only the verified8091 process was restarted,41932→48460, with the same ini/router/listen arguments. Coming-soon stays `no`, tracking stays enabled, and config/credentials and other business settings were preserved. Native related-product cache timeout, Action Scheduler lock and cron changed during ordinary GETs; this housekeeping was recorded and preserved. Source tracking rows and native orders matched their pre-release snapshots before the independent live review; no fixture/order/test was run on8091. Six reviewed runtime assets and47 committed frontend assets passed source-hash/HTTP checks; fresh native PHP and Blocks registries expose only the two local providers.

The native checkout shows `pc_local_card` and `pc_local_wallet`, titled **Local simulation — card / wallet (no charge)** with a clear no-charge description. Explicit local mode restricts PHP availability and the native Blocks payment registry to these providers; off/nonlocal boot leaves existing providers unchanged and the local scripts unavailable. Direct gateway calls repeat the strict gate and validate method, native order and permitted unpaid status.

Only provider interaction is substituted. Woo still owns authentication/nonces/cart tokens, cart/session, variation, discounts, price, tax, address, shipping, acknowledgement, stock reservation/reduction, order lifecycle, emails and transactional hooks. The provider durably tags `_pc_payment_simulated=yes` and `_pc_payment_simulated_channel`, sets its explicit simulation title, calls normal `payment_complete`, empties the native cart and returns Woo's normal order-received URL. An already-paid marked replay returns the same success URL without a second completion or clearing a new cart. No actual provider or external charge is called.

These clearly marked non-DEMO local simulation orders intentionally enter ordinary local Data reports. The existing DEMO-product / `Test payment` exclusions remain unchanged. Disable local mode before production; the strict environment gate also enforces denial if the flag persists. Production provider integration remains separate work.

The disposable site's MU network/mail guard is test containment, separate from application business behavior. The feature does not suppress mail, override inventory/shipping/tax/prices or swap customer identity.

## Reports and normal browser acceptance

The existing conversion endpoint adds sibling `checkout_steps`; old overall keys, groups, rows, Operations and Rankings formulas are unchanged. It groups by visit **start** time in the store timezone, for both current and comparison periods. Counts are distinct visits for exact client checkout/address/shipping/payment/submit observations and exact server processing/paid facts. They are observed states, not an assumed cumulative funnel; default payment may precede address readiness.

Historical stages are not backfilled into invented detail events. New address readiness, selection and payment-processing detail appears when actual post-update activity is observed. Previously received facts remain valid, so existing overall data may be nonzero while the new detail counts are zero.

The channel table separates initial/restored, explicit and automatic selection visits, attempt events, processing visits and distinct processing orders. Repeated real attempts remain separate; repeated callbacks cannot double an order. Its success denominator is distinct orders with observed server processing attempts in that visit cohort, unlike the existing all-orders payment-success metric. Outcomes use native order data and authoritative payment facts; a full refund retains historical successful payment. Test orders and missing linked orders are excluded; genuinely abandoned visits with no linked order still count. Missing method identifiers use `unknown`. Order reads are bounded native `post__in` batches, with returned-ID checks.

For acceptance, record exact fixture IDs/options first, then use public Woo/WP APIs to create only owned temporary fixtures: a published home/topic sentinel, a non-DEMO physical product priced40 with stock12, normal configured US shipping6 (or separately documented native rates), and the normal required acknowledgement. Preserve existing merchant drafts and settings.

1. As an anonymous eligible guest, open the real homepage/topic, product, native add-to-cart, cart and checkout.
2. Enter a valid normal address, wait for native shipping calculation, choose shipping and each available local payment channel in the native UI, accept the required acknowledgement, and place the order.
3. Verify normal order-received, native totals (40+6=46 for a tax-none fixture), stock12→11 for one paid order, durable simulation markers and exactly one linked server paid fact. Observe actual browser network/queue/timeline records; do not POST tracking events to manufacture proof.
4. A separate anonymous guest stops before payment. Their browser selections/attempts may exist, but no paid fact is fabricated.
5. Inspect the existing Data conversion page's separate checkout section/channel table, realtime visit timeline, landing/product/sales reports and current/comparison dates. Verify375/1100/1900 widths, table scroll, errors/empty states, existing filters/sorts and no console failures. Staff/GPC/off modes must not install the observer; production/off mode must not show simulated providers.

Theme source still references12 upstream private scene WebPs that are intentionally gitignored and unavailable. Some preview missing-resource paths return HTML soft responses rather than images. Do not treat200 alone as image/render proof or claim full visual parity. The committed brand, first packaging image and fonts are available; authorized original scene assets/replacements are separate work.

## Owned cleanup and verification evidence

Keep exact run ownership, product/order/visitor/session/template IDs and prior option values. Refund/restock paid owned fixtures with normal `wc_create_refund(..., refund_payment=false, restock_items=true)` and verify original stock; cancel unpaid owned orders normally. Delete only those native order/refund/product/template fixtures. Remove only their tracking via `Tracking\Privacy::delete_visitors(exact_owned_ids)` and their exact Woo sessions. Restore each prior option, including local payment mode, published-template selection, shipping/ack configuration and temporary disposable home/siteurl/coming-soon aliases. Never use a demo purge or broad visitor/session cleanup.

`tests/smoke-customer-flow.php` is restricted to the disposable local DB and verifies real code plus two public native Store API checkouts, with exact cleanup. Its HTTP proof is a transport/business-lifecycle proof, not a substitute for browser observer acceptance. `tests/customer-checkout-observer.cjs` runs the actual observer/adapter/bridge in a controlled native-store/hook environment. Existing frontend tests are retained unchanged.

Two earlier focused PHP failures were reported honestly: an incorrect GET-draft test assumption, then unsupported `wc_get_orders(include)` causing unstable linked-order classification. The installed native flow defers draft creation to POST; supported batched `post__in` and returned-ID validation fix classification. A regression adds more newer unrelated owned orders than the requested batch and preserves counts through full refund.

Formal review r1 of `0095afa` independently passed the full suite and reached native paid order7580 at46 total with shipping6 and stock12→11, with one order/attempt/success. The actual Chrome review also exposed a default shipping reselection incorrectly labeled `user` during address recalculation, before any shipping-control action. Native successful hooks alone were insufficient evidence of human intent. Revision679fd3c adds the trusted-radio correlation above to both shipping and payment, with regressions for automatic hooks/reselections, both hook/action orders, untrusted changes, pending work, failures, stale evidence and lifecycle cleanup.

Final independent review APPROVED679fd3c after rerunning47 smokes1664 PASS/0 FAIL, admin34, frontend148+48+81 and syntax/JScript gates. Actual Chrome performed the complete published-home→product→native cart→address→shipping→payment-choice→order-received flow without manual tracking injection. Before radio actions there was exactly one initial shipping/payment selection and one address-ready observation, with no order/attempt/paid facts. Express→standard and wallet→card produced the expected explicit choices. Order8099 totaled46 with shipping6 and one order/attempt/paid fact; stock12→11. A distinct second guest reached checkout then left without paid facts. Landing/product/report deltas matched2 visitors/1 order, landing sales46 and product sales40; checkout counts were2/1/2/2/1/1/1.375/1100/1900 layouts, keyboard table scrolling, empty dates, grouping, timeline/order link, no address values, six asset hashes/MIMEs and empty console errors were verified. Staff, real GPC, tracking-off and fresh local-mode-off gates were checked. All reviewer fixtures were natively refunded/restocked to12/deleted, exact sessions/mail logs/tracking removed and options/alias restored; original tracking rows were unchanged. DiGua's separate acceptance test remains pending. The40+6 specimen was a cleaned isolated fixture; current customer totals depend on actual native configuration.

UTM/source/domain/country capture, impressions/page-product conversion, email opens/clicks and CSV export remain separate scope.
