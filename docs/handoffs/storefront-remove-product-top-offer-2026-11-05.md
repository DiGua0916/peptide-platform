# Remove the duplicate product promotion header

The user rejected and cancelled the comparison proposal. Stop that task; do not integrate or deploy the proposed redesign. The standalone loopback helper was stopped and its uncommitted source/handoff were archived outside the repository under `D:/Codex/Tasks/2026-11-05/storefront-preview-comparison/work/`. No comparison artifacts enter Git or WordPress.

The only current storefront change removes the existing product dialog's top Black Friday three-tier text block, its divider and empty toolbar space. `assets/product-dialog-polish.css` hides `.vc-dialog-offers`, gives the native close button a 44px floating hit area, and reserves title space. The existing native promotion, countdown, price, specification, cart and payment JavaScript remain unchanged.

Only this CSS was backed up and installed into the LocalWP frontend runtime after verifying it matched committed source before replacement. Source/runtime hashes match. The isolated existing `product-dialog-polish.cjs` checks passed 41 items and made zero business requests; `git diff --check` passed. New UI-only business tests were not added.

Actual IAB screenshot verification is incomplete: stale-tab close, reconnect and fresh original-preview open attempts timed out. Inventory still responds. Do not report a successful screenshot, final visual measurements or production deployment. No original shopping cart/order/payment changes were made.

Task evidence and original CSS backup: `D:/Codex/Tasks/2026-11-05/remove-product-top-offer/`. Public server deployment is outside this local change.
