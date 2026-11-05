# Local storefront integration and native editing controls

2026-11-05 · Codex

The user requires local integration first, with native editable layout blocks following the previously studied YY hierarchy. Upload to GitHub is deferred until the outstanding local checks are resolved. No public server deployment is authorized by this phase.

## Source reconciliation

The active LocalWP theme is a junction to the frontend worktree, not the main integration checkout. All 12 pending frontend peer files were byte-for-byte identical to their already committed origin/main `2767eda` versions. They were backed up on D, guarded against concurrent changes, and protected in checkpoint `799cb03`. A normal main merge of origin/main followed by a normal frontend merge of main (`50e77ad`) preserves both peer work and the previously unpushed `9f7947a` footer/subtotal implementation. No stash/reset/clean, force push or backend-worktree write was used.

The latest `2767eda` removal of the duplicated product promotion header is intentional and retained. The former offer-follow controller still owns its same node, while the latest presentation CSS hides the rejected duplicate. No second offer writer is introduced. Latest frontend module/product click collection is now in the actual runtime.

## Native block coverage

- New `inc/ui-presentation-settings.php` uses the final `peptide_core_layout_modules` filter after Registry's reference-control metadata. It retains original fields, peer panels, native item operations and device overrides.
- Footer panels: region, brand/media, shop navigation, help/resources, official contacts, notice/copyright/subscription, mobile navigation, colors, visibility/spacing. Existing contact rows remain editable and use real URLs to choose their official icon.
- Product experience panels: purchase controls, image/report behavior, broadcast, support contact, device visibility/spacing. Products, variations and reports retain their existing native sources.
- Cart context panels: layout/copy, activity/recommendation/recent-browser display, checkout annotation, visibility/spacing. These remain presentation gates; a disabled/unavailable backend application is never substituted with a mock widget.
- Collection gains `catalogQuickAdd` (default true). The demo-catalog view consumes it as the existing card renderer's sixth argument; false retains the specification/detail link. Native device variants carry independent mobile overrides.
- Context content gains `checkout_subtotal_savings_enabled` (default true). A registered **checkout display settings document** supplies its published configuration. The helper respects disabled modules, showDesktop/showMobile and mobile-specific overrides; the JS removes only its own annotation when a viewport is disabled. Native Woo prices, payment fields, submission and routing remain untouched.

Settings are not duplicated in localStorage or a parallel option. Saving a draft remains distinct from publishing. Existing field defaults retain the approved appearance until explicitly configured.

## Local layout updates

Only native Templates/Layout_Controller APIs were used, with revision/modified/lock checks and protected hashes:

- Existing home document 685: explicitly persisted `catalogQuickAdd=true` in its existing collection block; all other home blocks retained.
- Newly created checkout display document 11752: one native context-content block with the annotation setting enabled. It does not replace the native Checkout block or take over the checkout route.
- Existing footer 687 and product/cart layouts already contain their configuration instances. Only their schema organization changes; no duplicate sections are inserted.
- All other layout contents/published snapshots, store Settings, and the original Woo Checkout page content were unchanged. These local IDs are not portable deployment constants. Layout DB changes do not travel with Git.

## Verification actually run

- 100 Node top-level checks across quick add, quote lifecycle, add confirmation, item/summary pricing, drawer/checkout bridge, offer follow, product polish and click collection. Nested script outputs are separate assertions, not extra top-level tests.
- 17 checkout subtotal checks, 18 route/enqueue checks, 39 footer checks, 129 schema compatibility checks; PHP lint and diff checks passed.
- Actual LocalWP Registry reports the new controls and task-based panels. Six request-local metadata-filter scenarios execute the published settings getter: disabled module, false switch, device visibility, false mobile override and mobile-only enable all pass. No persistent settings were changed by that behavior probe.
- Anonymous local HTTP: homepage/cart/COA and the legacy product redirect load successfully. Homepage has one real cart drawer, expanded footer and 82 quick-add controls. Nine served assets exactly match runtime source. Anonymous empty-cart checkout correctly returns to cart; that is not payment-form visual acceptance.
- The first HTTP audit's product-link 400 was an audit client proxy/fragment artifact. Local per-request direct HTTP and normal fragment-stripped origin requests return 200; website redirect code was not changed to accommodate the audit.

## Remaining boundaries

Actual new phone screenshots/scroll interactions could not be obtained: existing IAB, existing Edge and fresh IAB binding attempts each timed out. No browser-cookie extraction, alternative automation bypass, actual add/coupon/order/payment action or public deployment was used. Do not describe the phone visual check as completed.

LocalWP peptide-core points to the backend worktree. Its public six-module features match committed source, but older admin workspace/dialog and newest tracking-interest files remain in that runtime. The detailed read-only runtime audit and a request for the owning backend window to synchronize safely were posted to the authorized room. This frontend phase does not overwrite that worktree or assert the full backend runtime is current.

GitHub upload is intentionally held for the user's local-first verification condition, not reported as successful. Next: backend owner completes its runtime synchronization, supported browser control resumes for actual phone acceptance, then fetch/reconcile and normal push of the verified integrated branches.

Private evidence and backups: `D:/Codex/Tasks/2026-11-05/local-ui-integration-review/`. No DB snapshots, private configuration, tokens or contact numbers are committed.
