# Published the existing checkout UI repair

2026-11-03 · Codex. The user reported that the checkout layout worked locally but refreshing the public store did not change it, confirmed the customer checkout page, and requested publication while leaving visual inspection to them.

## Cause and published scope

The public runtime still had the prior `functions.php` and `assets/site.css`; `assets/checkout.css` did not exist. A Git pull in a local checkout had not changed the server's versioned runtime. The frontend repair had been checked locally and committed, but not deployed publicly.

Published only these theme files from immutable Git commit `4b534b120672b5027e99fad4e8a46d99ff0b18e1`:

- `wp-content/themes/peptide-site/functions.php`: conditionally enqueue the checkout stylesheet, depending on the existing brand stylesheet and using filemtime cache versioning.
- `wp-content/themes/peptide-site/assets/site.css`: keep Woo checkout inputs out of the generic entry-content reset.
- `wp-content/themes/peptide-site/assets/checkout.css`: the already-approved local label/control layout repair.

No new design, payment logic or field JavaScript was introduced here. The other four pending FAQ/product-theme changes were excluded. Local checkout block ordering, country options and other database content were not migrated. There was no explicit business-data or merchant-settings update, order operation, payment request or email send.

## Per-host publication

Each host was cloned from its own current runtime, preserving its different Core/MU overlays, shared private configuration, upload/report links and other theme files. Old file hashes had to match before applying the three-file overlay; the complete clone manifest was then checked to differ in exactly those three entries. Files received fresh mtimes for stylesheet cache versioning.

- Public runtime: `/srv/vialcove/releases/checkout-ui-4b534b1-web01`, based on `6f20b62-web01`; 11,046 original entries verified.
- Merchant preview runtime: `/srv/vialcove/store-admin/releases/checkout-ui-4b534b1-admin01`, based on `domain-filter-c235e3e6b0d2`; 11,048 original entries verified. The domain-filter and mail-readiness overlays remain intact.

Publication used root-private advisory locks, expected symlink/inode and private-config checks, atomic current-link replacement and graceful FPM reload. Both prior releases and root-private checkpoints under `/var/lib/vialcove-ui-deploy` remain available. Ordinary failures revert only the task's own pointer; this is not a two-host atomic transaction, protection against noncooperative publishers, or guaranteed hard-termination recovery. No business database restore is part of UI rollback.

## Actual verification and limits

- Frozen three-file hashes matched the immutable Git blobs. PHP lint passed for the changed `functions.php` on both hosts.
- Each host served the new `site.css` and `checkout.css` over validated HTTPS with `text/css` and exact expected SHA256. Filemtime query versions changed.
- Public homepage and merchant login remained HTTP200. Private configuration and all unrelated code/link entries were unchanged by the overlay.
- The anonymous, cookie-free `/checkout/` request correctly redirected to `/cart/` because that diagnostic session had an empty cart. The first harness incorrectly expected the checkout-only style link in that redirected page; it was corrected to record the redirect and verify the new CSS asset directly. This is not a claim that a nonempty checkout DOM was exercised.
- At the user's request, no further browser refresh, input, cart or visual inspection was performed. No customer details were entered and no checkout was submitted. Existing user tabs/inputs were left intact; the user will inspect their own checkout after refreshing.

This is a scoped theme publication, not deployment of every file currently on GitHub or a copy of local test data. The source fix's prior local desktop/mobile visual evidence belongs to the frontend task and is not relabelled as a new production screenshot.

Evidence and deployment scripts: `D:/Codex/Tasks/2026-11-03/store-ui-sync`, notably `outputs/checkout-ui-three-file-diagnosis.json`, `theme-sync-review.md`, `published-admin.json`, `published-web.json` and `live-checkout-enqueue.json`. No credentials, SQL, customer content, cookies or private configuration are committed. No CRK room is bound.
