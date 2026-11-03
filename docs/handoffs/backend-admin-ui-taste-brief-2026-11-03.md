# Admin interface redesign brief

The user's explicit request covers both the customer workspace and the management workspace, including complete mobile operation. Taste Skill supplies the overall direction, frontend-design the hierarchy, Web Interface Guidelines the touch/accessibility review, and Impeccable the final motion/response quality pass. Public storefront appearance, actual customer/order/payment data and financial handlers are outside this UI task.

Keep the user's settled black-and-white palette and compact desktop density. Use the Taste Skill `achromatic-ledger` app direction with the existing local Manrope/Chinese fallback. The initially dealt no-sidebar shell conflicts with these workspaces' many destinations; retain the established labelled native navigation instead. No external component kit, framework migration, invented figures, remote fonts or decorative charts.

## Visual system

Use a white navigation and header, a quiet neutral working canvas, and white continuous data/form surfaces. Palette: ink #171717, secondary #5f6368, canvas #f6f6f6, surface #ffffff, line #e5e5e5, control #c9c9c9. Preserve genuine semantic errors/warnings/success, uploaded media and store preview colors. Brand uses the existing VialCove name and mark; remove the generic black letter tile from presentation.

Type: local Manrope with the existing Chinese system fallbacks; page title 24/32 at 650, section 16/24 at 600, body 14/22, metadata 12/18. Mobile input text is 16px and controls have 44px touch areas. Use tabular numerals for compared amounts/counts; no arbitrary mono labels.

Desktop: labelled 220px navigation, 52px header, consistent 24px content gutters, title/action row aligned on one centerline, related controls grouped with 8px gaps and sections separated by 24px. Panels have 8px corners, controls 6px, status tags 4px. Ordinary panels stay flat; depth belongs to dialogs and menus. One dominant action per task.

Tables stay compact and comparable on normal desktops. Use contained scrolling where dense native lists need it; do not convert every table into large cards at a 1280px pane width. On phones, custom data tables reflow to labelled records with identity/actions attached and every field retained. Filters and forms stack according to available width; preserve native Woo/WordPress table behavior and the existing specialised collection/tracking adapters.

Mobile: the navigation overlays the page instead of translating it. Provide a real close control, backdrop, Escape, focus restoration and a predictable focus loop; keep native menu state as authority. No page-width overflow. Dialogs respect dynamic viewport height, safe areas, internal scrolling and reachable close/save actions. Editing panels retain real field values and actions; do not shrink touch targets or hide functionality to fit.

Motion: 140–180ms controls, 180–220ms drawer/dialog transitions using explicit properties, responsive to interruption and reduced-motion preference. No full-page fades, repeated entrances, animation of every card, `transition:all`, busy global observers, or changed native POST/nonce/dirty navigation behavior. Preserve the newly deployed smooth-navigation lifecycle.

## Page questions and verification

Overview: what needs attention and what changed in the actual store/site data? Lists: which record should I act on? Detail/editor: what am I changing and how do I save it? Settings: which setting am I changing and what scope does it affect? Operations: what is the real execution state and next permitted action?

Verify actual rendered overview, sites, servers, accounts, payments, operations, recycle, products, orders, customer/visitor data, collections, app settings, ordinary forms and the decoration editor at 375/390, 768, 1024 and wide desktop. Check keyboard focus, touch target sizes, long domains/names, scroll containment, dialog cancel/close, reduced motion, and navigation without submitting business changes. Source/model checks supplement visual verification and cannot replace it.

Implementation uses isolated `codex/admin-ui-taste-20261103` at `D:/Codex/Worktrees/peptide-admin-ui-taste-20261103`. It includes the colleague's `d3ee614` navigation source. Original paused backend edits and current production are separately backed up; selective publication must re-read actual current releases and preserve all non-UI paths and configuration.

## Implementation checkpoint

2026-11-03 · Codex. The shared customer/HQ shells, native form/list presentation, operations/payment layouts, overview density and decoration editor panes have been updated. A small progressive controller owns the touch drawer's focus boundary and native WordPress menu state. It yields to visible external native/ARIA modals; two obsolete WAAPI menu observers were removed so that the drawer has one transition. PHP changes only enqueue/brand/header markup and table field labels. Financial handlers, forms, nonce names and persistence remain unchanged.

Actual source checks: five changed JavaScript files parse; the drawer DOM harness passed 84 state/edge checks plus four external-modal regressions; the editor model passed 52 checks with no API calls and preserved 13 persistence/action function bodies byte-for-byte. CSS parsers and diff checks passed. Taste's static HQ scan has no failures; its shadow warnings refer to genuine overlays. Impeccable reports older DESIGN.md ramp advisories and an existing Menlo code-editor font exception; these are not evidence that all rendered screens are correct.

Actual rendered pre-publication evidence uses sanitized copies of the live HQ/customer overview DOM and the exact native production CSS/font assets, served only on loopback with POST and outbound application calls disabled. HQ/customer overview widths 375, 390, 768, 1024 and 1440 have been inspected for root overflow; the HQ phone drawer opens, makes content inert, closes with Escape and restores focus. The rendered HQ KPI pass reduced the desktop metrics from two tall rows to one compact row when space permits. This checkpoint does not claim production deployment, every-page visual verification or a new real payment/order test. Those outcomes require the subsequent publication receipt and browser checks.

Task evidence: `D:/Codex/Tasks/2026-11-03/admin-ui-taste-redesign/outputs/`. Public storefront appearance is not part of this update.

## Publication and rendered review

2026-11-03 · Codex. Published the reviewed 20 administration paths to HQ and the customer management application, finally from source `4b7a6940a65199dcb7f79a1594ad6b6cde03f467`. The publication clones each actual current release under the established locks and compares every nonallowlisted source/configuration path; 15 origin HTTPS CSS/JS MIME/SHA checks passed. Existing encrypted backups were checked before cutover; restoration was not exercised. No public storefront pointer, theme, payment handler, order, product, customer, setting, DNS/CDN or private configuration was changed by this publication.

Rendered follow-up fixed two concrete issues: `hq-operations.css` now has an asset timestamp in its enqueue version so an older browser cannot retain the previous layout indefinitely; native product filters reserve 36px for the single existing arrow, and the desktop product list uses legible columns inside its existing scroll region instead of splitting SKU/category text. The final customer product-list screenshot confirms both fixes. The sites page and its More dialog were opened and closed on production without saving settings; the dialog stays bounded. The phone decoration editor at 390×844 exposes Preview/Blocks/Properties at the bottom, all three and its save/publish controls are 44px high, with document width 390px. Blocks was switched without changing document content. The inner editor preview remained a real page; it was not published during review.

The final preflight detected a colleague's new Woo React readiness gate in the live Workspace PHP. Normal Git merge of `87b22de` retained it; the merged Workspace SHA exactly matched live before the last overlay. Its actual PHP-emitted helper passed 48 isolated DOM checks. Frontend peer source was merged for Git synchronization but not selected for this UI deployment.

Verification limits: this is a first complete shared UI implementation, not a claim that every admin/plugin state, browser engine or physical phone model was manually tested. The actual production checks covered overview, sites/dialog, native products and the decoration editor; overview breakpoint checks used sanitized live DOM plus exact native CSS at five widths. Other settings/forms inherit the reviewed shared rules and still need normal use feedback. Browser capture occasionally timed out or produced emulation tiling; those attempts were not treated as successful visual evidence. No real payment or save/publish transaction was used to test appearance.

Production receipts and screenshots are in the task's `outputs/`, including `admin-ui-publication-final-v2.json`, `hq-live-final-sites.png`, `hq-live-tools-dialog.png`, and `merchant-live-final-products.png`. The user's independent frontend worktree and the original paused backend edits remain untouched.
