# Homepage render work and lazy native product entry

2026-11-05 · Codex · Local theme candidate for the site coordinator. This phase preserves the current storefront design, all published building-block configuration and all 82 server-rendered catalogue cards. The coordinator owns Core integration, GitHub and deployment; this window does not install Core, change the backend worktree, publish configuration or operate a real cart/order.

## Problem and final implementation

The measured baseline made 175 calls to the already request-memoized `vc_product_data()`, resulting in 91 distinct full Woo Store API product-schema responses. The theme retained only the `peptide_core` extension from those responses. Catalogue statistics also hydrated visible child products one at a time, and SSR quick-add descriptors checked native held stock before any customer attempted an add. There was only **one** pre-rendered hero product template, not 82 hidden product forms.

The candidate changes four runtime files:

| File | Result |
| --- | --- |
| `wp-content/themes/peptide-site/functions.php` | Read the exact displayed SKU through the public Core service, retain request-local reuse, use the lightweight SSR quick-add candidate and pass lazy/async image attributes to native compact-card images. |
| `wp-content/themes/peptide-site/inc/demo-home-modules.php` | Batch-prime native raw post/meta/term caches for the same visible-child IDs before the unchanged Woo variation/count checks. |
| `wp-content/themes/peptide-site/inc/home-quick-view.php` | Separate an SSR attempt candidate from the existing fresh quantity/held-stock authorization. |
| `wp-content/themes/peptide-site/template-parts/modules/demo-hero-h1.php` | Use the existing shared on-demand native template loader instead of preloading one hidden hero form and its reset snapshot. |

The catalogue source, promotion/order settings, all-SKU search text, category data, first displayed SKU, real links, prices, image sources, 82 quick-add entries and no-JavaScript catalogue coverage remain. The current hero retains its eager/high-priority image; compact catalogue images use native `loading=lazy`, `decoding=async`, `fetchpriority=auto` and the existing responsive card sizes. This is not a measured browser request-count or mobile LCP claim.

## Core contract and deployment order

Core owner delivered `9690abdb9def180e8924ef46a153ac6372553390`, with a test-only follow-up `53f093ac22c2d4811acba38e6c9758fcef458de2`. The site integration tree has the helper as `f4b9812`; these are **Core references**, not theme commits to merge into this role branch. Only the coordinator installs/merges them.

```php
peptide_core_get_store_product_data( $selectedSku ): ?array
```

The helper receives the actual selected `WC_Product` object and wraps the owned `Api\Store_Api::data()` callback. Its raw/object public status, password, parent identity, native active/visible and force-login checks remain Core-owned. The theme never copies Core meta keys, pricing rules, comparison prices, package rules or report authorization.

- If the function is **missing** on an older Core release, `vc_product_data()` keeps the existing internal REST compatibility path.
- If the function exists but returns null, throws or fails serialization, SKU extras remain empty. There is no broader REST retry after refusal, and no SSR quick-add descriptor in that state.
- Parent/aggregate metadata must not replace exact-child metadata. The first configured visible SKU remains the card's display/purchase identity.
- Existing request-local memoization is retained. No transient, public page/session cache, cross-user report-state cache or computed-price cache is introduced. Report access, currency, tax and locale remain the current request's native context.

Without the matching Core helper installed, the theme remains compatible but cannot realize the full-schema portion of this optimization. Deploy the reviewed Core and theme candidates together through the coordinator's normal process.

## Quick-add authority and interaction

The SSR predicate checks the same native parent/SKU purchasability, stock state and complete concrete variation attributes. It offers an **attempt**, never `canAddOne=true`, and does not initialize a customer session or run QuantityLimits/ReserveStock.

The clicked button still waits for the existing exact `home-product/{parent}?card=true&variation={selected}` GET. Identity, purchase ID, variation, price, currency/precision and strict `canAddOne===true` are checked before the unchanged native cart priming and single add-one POST. The fresh card endpoint still performs the original quantity, cart and held-stock checks; the Store API POST remains authoritative. Failure, changed price, missing permission or stale intent cannot silently add a different SKU.

The hero keeps its actual SKU permalink and shared quick-view/deep-link attributes. Its existing homepage bootstrap loads the native presenter/variation dependencies independently of the removed inert template. Shared loading, cancellation, history restoration and real-URL fallback remain in their existing owners; no endpoint or second purchase queue is added.

## Verification status

Root ran the production reader's modern 9 and legacy 5 checks, the actual statistics function's 5 checks, 56 card guards, 23 product-first guards and 11 hero checks. Five related Node suites passed 38 top-level tests, covering fresh-card-before-POST, selected-SKU sale state, pending/cancelled reads, metadata and box pricing. Nine changed/new PHP files passed lint and `git diff --check` passed. These are isolated source/transport boundary tests, not real purchases or browser measurements.

The independent reviewer found no concrete P1/P2 in the candidate. A separate isolated integration of the actual Core helper/services and current theme reader passed 186 checks (181 base plus 5 integration checks), with no forbidden I/O. This is not added to the root totals or confused with the Core owner's later 187-check suite.

### Actual same-dependency anonymous homepage comparison

The process-only probe loaded `off` from the committed theme `01b38dc` and `on` from that same snapshot with only the four runtime files above replaced by frozen candidate bytes. Both variants included the exact same reviewed public Core helper fragment (SHA256 `530132a72b93952119af993c4205d16015d4f3f753025432a820b81a97fdd5ac`), and the installed backend was not overwritten. The 417-file Core manifest and both task-only theme overlays remained stable. The sequence was off/on/on/off/off/on, with three HTTP200 samples per variant, no rejected writes or outbound attempts in any of those six samples. This is real WordPress/Woo rendering through loopback PHP with Xdebug/SAVEQUERIES, **not production FPM, a mobile browser or a cold/warm-cache benchmark**.

| Actual observation | Off, three samples | On, three samples |
| --- | --- | --- |
| SQL queries | 1,918 each | **820 each** |
| Catalogue-count queries | 547 each | **4 each** |
| Full product schema responses | 91 each | **0** |
| SSR QuantityLimits / ReserveStock calls | 173 / 166 each | **0 / 0** |
| Owned extension callback calls | 91 each | **82 each, through the helper** |
| Uncompressed HTML | 558,370 B each | **427,451 B each** |
| Catalogue cards / shortcut descriptors | 82 / 82 | **82 / 82** |
| Preloaded hero templates/forms | 1 / 1 | **0 / 0** |
| Observed first-byte time, range | 5,998–7,327 ms | **3,550–3,790 ms** |
| Observed first-byte time, median | 6,239 ms | **3,602 ms** |

The measured render removes 1,098 queries and 130,919 uncompressed HTML bytes. The elapsed-time improvement is specific to this instrumented local experiment. It does not establish a server/mobile percentage or eliminate the remaining 820 queries; do not reuse it as a production speed guarantee. Categories in the attribution report reflect different call paths and cannot all be treated as independent additive savings.

Strict semantic readback keeps all actual content and script assets. After excluding only the approved inert template, gallery-reset script and its single output newline, one byte-identical duplicate `vcHomeQuickView` declaration, catalogue image hints, native time and paired `pc-first-offer` unique IDs/ARIA references, both canonical pages are 425,546 B with SHA256 `ee095bd0b66e5b055066eee6befc05e366016ede89e8e7b86c2beae0ca58682e`; no remaining differences. The nonempty 760 B counter region, visible hero, 82 card descriptors and complete catalogue content/order match. All 82 compact images now have `loading=lazy`; the actual hero image remains eager/high.

A separate clean native comparison matched all six extension fields for four exact SKU/parent reads, and the full two fresh-card DTOs match. The **fresh** clicked-card path still made two native QuantityLimits and two ReserveStock calls; zero SSR calls do not mean stock checks were removed from purchase validation. These checks were read-only and did not POST to a real cart.

One earlier extra DTO/bootstrap diagnostic encountered three expired Panapay policy-transient DELETE attempts, all rejected by the guard. That run is explicitly excluded from the six timing samples and is not described as zero attempted writes. No transient was changed or guard relaxed to obtain a clean result; subsequent separately recorded semantic/DTO samples had zero rejected writes/outbound attempts. All probe servers were stopped.

Full experiment, source hashes, scoped canonicalization and limitations: `D:/Codex/Tasks/2026-11-05/home-render-performance/outputs/ab-report.md` and its JSON receipts. Source-availability and measured-overlay results do not mean that the new Core helper is already installed in the shared LocalWP runtime or deployed server.

## Configuration and remaining acceptance

No database/building-block migration or new settings panel is required: this is implementation behind the existing native modules and feature toggles. Existing mobile overrides, enabled/disabled blocks, real recommendation/broadcast applications and checkout/payment behavior are unchanged. The already-synchronized first-order 5% activity is unrelated; this phase does not create another coupon.

This phase has not captured new actual 320/360/390/430 browser screenshots, iOS font/canvas or mobile network LCP/INP. Previous controlled browser access timed out and was not bypassed. Native read-only HTTP/profiling and fixtures do not substitute for those checks. The final coordinator still needs integrated mobile interaction, on-demand product opening and normal release acceptance. No runtime/deployment claim follows merely from a source commit.

Task evidence and process-only tools are outside the website at `D:/Codex/Tasks/2026-11-05/home-render-performance/`. Key reports: `outputs/source-profile.md`, `outputs/dom-analysis.md`, `outputs/schema-analysis.md`, and `outputs/root-candidate-independent-review.md`. Original HTML and probes are task work data, not deployable plugin files or committed customer data.

The separate read-only `outputs/broadcast-payload-followup.md` identifies approximately 83 KB of initial broadcast items that the current product consumer fetches again through its existing URL. It is a **future independent candidate**, not part of this four-file runtime change or its measurements. Core SSR JSON, authorized preview and the complete fetched sequence must remain intact; removing the PHP getter's full items globally would break their contract.
