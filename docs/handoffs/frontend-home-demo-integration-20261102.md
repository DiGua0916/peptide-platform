# Approved homepage integration — 2026-11-02, Codex

Task: HOME-DEMO-INTEGRATION-01. The user approved synchronizing the entire independent Demo homepage through the existing editable blocks, retaining its product modal.

## Published result

The current LocalWP template remains 684. Native layouts REST, with revision/modified checks and existing lock enforcement, saved and published home 685 (8 blocks), header 686 and footer 687. Global document 688, active-template option and domain mapping remain unchanged. Original documents and publish results are backed up outside Git under `D:/Codex/Tasks/2026-11-02/demo-home-integration/outputs/`. Preview documents 11463–11465 contain the same draft for reference; template 11462 remains inactive.

The eight homepage blocks are hero, service illustrations, searchable product collection, report feature, live collection counters, selection steps, FAQ and closing CTA. Existing schema types gain an opt-in Demo presentation; their ordinary presentations remain available. Content, links, images, report IDs and order continue to be stored in native block fields. Four approved illustrations were imported into the existing media library; uploads are not versioned.

## Product and cart contract

- Products, images, variation order, stock and actual prices still come from WooCommerce. Cards and initial modal state use the first eligible backend-ordered specification, never the minimum price. The eight configured featured products lead the full catalogue; global product ordering was not changed. Current public catalogue: 82 products / 183 specifications. The current theme catalogue query is bounded to 200 products.
- A read-only theme route `GET /wp-json/vialcove/v1/home-product/{id}` lazy-loads the existing `Showcase_Quick_View` form. It accepts only public eligible parent/simple products, returns no-store responses and has no writes. It rejects hidden/private/password-protected products, missing IDs and variation IDs. Cart writes remain with the existing native form and Woo Store API.
- The modal keeps a full product-details link. No product page, checkout or order system was removed. Desktop bag uses the existing right drawer; mobile navigates to the existing `/cart/` page.
- Eager hero and lazy catalogue modal openings use post-dispatch presentation so trusted browser clicks and replayed clicks behave consistently. Selected native variation data updates the image, exact price and SKU. Existing global CSS conflicts were corrected with Demo-scoped selectors.
- Only one test item, Retatrutide 10mg quantity 1, was added in the test browser; it showed $41 current / $67 comparison and $26 saving, then was removed. Browser bag returned to 0. No checkout/order submission or order/refund modification occurred.

## Reports and animation

Three native public report IDs (11552, 11582, 11580) render the approved paper stack. The source and sample context remains visible; these third-party reports are not represented as verification of VialCove inventory. No PDF or batch record was changed. A paper opens its existing controlled file route in the reader; COA library links remain native.

Counters resolve current public catalogue data at render time. They start only when visible, use ease-out motion, pause offscreen/backgrounded, finish exactly and honor reduced motion. White background and mobile two-column figures match the approved revision.

## Verification

- PHP/JS syntax and `git diff --check` passed.
- Native WP checks: modules/schema 48, quick-view contract 17, endpoint 7, header/footer 21, catalogue review 13, configured ordering 8, saved-document/anchors/anonymous access 26. Counter behavior harness 7 and catalogue UI harness 6 passed. These are task-local verification scripts, not new production tests.
- Actual browser: desktop and 390px mobile rendering, first variation image/price/order, 5-Amino specification change and exact pricing, Retatrutide selection and real cart add/remove, desktop drawer, mobile `/cart/` navigation, catalogue search, report-reader identity/link, counter offscreen 0 then 82/183, and no mobile horizontal overflow. Product modal was rechecked after the eager-click and padding fixes. This is responsive browser verification, not a physical-phone test.
- Source review was independent of UI verification and found no outstanding release blocker. Existing PHP startup emits an Imagick DLL warning; inspected commands completed and the warning did not affect this change.

Evidence: task `outputs/{before-documents.json,after-documents.json,quick-view-contract-check.json,quick-view-endpoint-check.json,demo-module-verification.json,demo-counter-verification.json}` plus browser screenshots. Temporary scripts, runtime snapshots, report files and images are outside Git.

## Ownership and next work

No `peptide-core` business files were changed. Claude can keep the backend contracts unchanged. Future frontend refinements should update the same native documents or the theme's Demo presentation, not reintroduce a second product/cart source. Product-detail page redesign is a separate future task. Publish here means the current local WordPress site, not deployment to an external production host.
