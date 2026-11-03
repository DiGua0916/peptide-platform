# Scoped storefront motion publication

2026-11-03 · Codex · STOREFRONT-MOTION-POLISH

Runtime source: `04c1bcedcb178d837084dccab1d86fd029fd8adb`. Git integration is `86db130`; integration does not imply that every file from that merge was deployed. Only the five reviewed theme files below are the runtime payload.

## Scope and behavior

- `functions.php` enqueues the two new versioned presentation assets.
- `assets/demo-site-parts.js` and `assets/demo-site-parts.css` add interruptible mobile menu/search entrance and exit feedback while retaining native focus, actual links and existing configuration.
- `assets/storefront-motion.css` adds restrained product/child-dialog entrances, backdrop feedback, button press states and native FAQ answer entrances. Disabled controls and reduced-motion preferences are respected.
- `assets/storefront-motion.js` fades a newly loaded authoritative product image and cancels obsolete animation on replacement, close, hidden state, reduced-motion change or pagehide. It does not change the image URL, specification, price, stock, form data or navigation.

The existing product dialog's backend-owned close handler immediately detaches its body. This pass therefore adds product **entrance** only; it does not claim a full product-content exit animation, delay Back, clone forms or modify native close. A full exit requires a separately coordinated presentation lifecycle hook. Menu/search have their own complete entrance/exit treatment; the already working cart slide lifecycle is unchanged.

## Executed publication status

| Host | Previous runtime | Published runtime | Receipt status |
| --- | --- | --- | --- |
| web | `cart-cards-0635585d19fa-web01` | `storefront-motion-04c1bcedcb17-web01` | Executed successfully at 2026-11-03 08:09:25 UTC |
| admin | `cart-cards-0635585d19fa-admin01` | `storefront-motion-04c1bcedcb17-admin01` | Executed successfully at 2026-11-03 08:14:28 UTC |

Both actual receipts were read from `D:/Codex/Tasks/2026-11-03/storefront-motion-polish/outputs/motion-published-{web,admin}.json`. They confirm exactly five allowlisted changes, verified clones of each host's own current runtime, candidate PHP lint, preserved private configuration/plugins/MU plugins/upload links, atomic current-pointer switches and graceful FPM reloads. All four public JS/CSS assets on each host returned HTTPS 200 with the expected content type and exact committed SHA256; buyer homepage and merchant login health passed. New cache mtimes are `1793693353` (web) and `1793693658` (admin).

Per-host current pointer/inode, private configuration inode/hash and complete runtime manifests guard the publication. No backend plugin, database, product content, customer address, order, payment session or payment settings were mutated. Whole-theme/repository copying was not used. In particular, `home-quick-view.css` and `product-dialog-experience.js` were excluded because their local versions contain unrelated unpublished FAQ changes.

## Verification and limits

The menu controller's 11 lifecycle regression tests, JS syntax, PHP lint and diff checks passed. The independent image-helper review exercised first load, same-source deduplication, replacement cancellation, hidden state, reduced motion, close and pagehide. Its two recommendations—backwards animation fill and excluding Woo class-disabled controls—are included in the committed payload.

Actual local formal-site responsive verification used a 390 × 844 same-origin frame: menu opens with `aria-expanded=true`, no horizontal overflow, and Escape closes it. The product has its 360ms entrance and no horizontal overflow; selecting 10mg updates the real selection and TR10 image. No cart write, customer-address entry, order or payment action was performed. The final backwards-fill refinement was verified in committed source and deployed asset hashes, not by a later successful visual interaction. This is not a physical iOS/Android FPS measurement or a guarantee that every interaction is now smooth on every device.

The public homepage opened and rendered in the browser. The subsequent product-click/readback attempt timed out, so its outcome and a final public dialog screenshot were not verified. Resource hashes and health checks do not substitute for that interaction evidence; the timeout alone is not reported as a confirmed site defect.

## Operational artifacts and rollback

Task artifacts stay outside Git at `D:/Codex/Tasks/2026-11-03/storefront-motion-polish/{work,outputs}`. `outputs/motion-payload-verification.json` records the immutable source-byte/hash validation; `outputs/motion-published-web.json` and `outputs/motion-published-admin.json` are the executed host receipts. Earlier `prepared_only` and scope-probe files are preparation evidence and do not override the later publication receipts.

The guarded runner is `work/run_motion_remote.py`; its documented manual rollback is `rollback web|admin --commit 04c1bcedcb178d837084dccab1d86fd029fd8adb`. Remote private checkpoints are under `/var/lib/vialcove-ui-deploy/<release-name>/`. Rollback requires the expected current pointer/inode, private configuration guard and before/after code manifests, refuses after unrelated publication changes, and never rewinds the database. No rollback was invoked during this documentation review.
