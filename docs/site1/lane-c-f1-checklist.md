# Site 1 · Lane C release checklist (for F1)

Branch: `claude/site1-lane-c` (cut from `claude/site1-ui-optimization`, based on mainline `0a100736`). Releases go only through F1's release tool. This branch has not deployed anything or touched production data.

## A. Code changes (shipped with the release package)

| Module | File | What it does |
|---|---|---|
| P1-7 | `themes/peptide-site/assets/product-dialog-experience.js`, `product-report-library-viewer.js` | COA area in the product dialog: "REFERENCE DOCUMENT / third-party reference, not a VialCove batch" becomes "BATCH COA / Lot · lab · date / Results apply to the lot named in the report"; button reads "View full report ↗" |
| P1-7 | `plugins/peptide-core/languages/storefront-es-ui.json` | Spanish strings for the new COA wording |
| P1-9 | `plugins/peptide-core/languages/storefront-es-content.json` | Spanish strings for the standard FAQ |
| P2-1 | `themes/peptide-site/inc/layout-modules.php` | Each "counting numbers" module item gets a "number source" field (manual / live product count / live specification count), cached 1 hour |
| P2-4 | `plugins/peptide-core/includes/admin/class-customers-page.php` | Customer list gains order count / total spent / main category columns; "export current filter (CSV)" |
| P2-5 | `themes/peptide-site/assets/visitor-tracking.js`, `plugins/peptide-core/includes/tracking/class-ingest.php`, `class-labels.php`, `admin/class-tracking-page.php` | New events `product_load_failed`, `pending_banner_click`, `whatsapp_click`, `catalog_search`. Page code reports them through `document` `vc:track` events. Homepage catalogue search is recorded automatically |

Not changed: the tracking table schema (`type` is varchar(32), and the new type names fit), product slugs, prices, variations.

## B. Steps after release

1. **Product content script (master store first)**
   ```
   wp eval-file tools/site1/lane-c-content-fixes.php            # dry run: check the list
   wp eval-file tools/site1/lane-c-content-fixes.php apply      # write; each product's original title and content is backed up in post meta
   ```
   - Expected (from the live data read on 2026-11-14): 3 renames (Cagriliintide → Cagrilintide, adamax → Adamax, bac.water → Bacteriostatic Water), 1 purity typo fix, 36 FAQ sections replaced with the standard FAQ.
   - Under series sync: **run on the master store**, then push from "series management". In non-master stores the script warns that products are series-managed.
   - Rollback: `wp eval-file tools/site1/lane-c-content-fixes.php rollback`
   - **Wait for legal to approve the standard FAQ wording** (`docs/site1/lane-c-legal-review.md`) before running `apply`.
2. **Homepage counters**: in the homepage layout, open the "counting numbers" module. Set "Products in the collection" to "live product count" and "Specifications to explore" to "live specification count". Publish.
3. **Dealer tag**: in "Customer operations → tags and levels", add a line `dealer|经销商` (and `retail|零售` if you want it). The account manager tags customers on the customer list after a P1-4 deal closes.
4. **Carrier wording**: change the checkout shipping method title from "UPS • FedEx • USPS" to "FedEx". This is a WooCommerce shipping setting; coordinate with lane B, which owns checkout.
5. **Storage wording**: rewrite each product's storage passage by hand to 2–8 °C, using `docs/site1/lane-c-content-review.md` (90 products have storage passages). The script deliberately does not swap temperatures automatically.

## C. Checks actually run

- `jsc` (macOS JavaScriptCore) syntax check: `visitor-tracking.js`, `product-dialog-experience.js`, `product-report-library-viewer.js` all pass.
- The two translation JSON files parse with Python `json`.
- Script matching was run in Python against the 92 live products from the public Store API: FAQ section matches 36, purity typo 1, renames 3.
- **Not run**: PHP lint and PHP tests (this machine has no PHP), the theme's Node tests (no Node), browser verification. Before release, run `php -l` on the changed PHP files and the project test suite in an environment that has PHP and Node.
