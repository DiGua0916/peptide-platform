# Mobile storefront interaction polish

2026-11-03 · Codex · STOREFRONT-INTERACTION-POLISH

The user approved Impeccable + Interaction Design + Web Design Guidelines and asked for mobile-first interaction detail and smoother behavior. The two additional skills were installed through the standard skill installer and their actual guidance was read; the latest Vercel rules were fetched for this review. They are authoring tools, not storefront plugins. No new runtime library was added. Existing storefront identity, editable module settings, native Woo prices/forms, app switches and history ownership remain authoritative.

## Implemented

- `home-quick-view.js`: a current pending opener owns its feedback. A newer product, older showcase, Escape, restore cancellation, hidden page or pagehide clears obsolete feedback immediately. Completing an older request cannot clear a newer pending indicator or reopen the old product. A cancelled request is not reused on immediate retry, and its finally cannot delete the replacement request. Native modified clicks and the existing real product-page failure fallback are retained.
- `storefront-motion.css`: pending reads use the existing card/image arrow area, with a compact Opening label and progress indicator, so no extra layout row is inserted. Existing product controls get intentional touch feedback, the clipped gallery gets an inset keyboard outline, the purchase bar accounts for the bottom safe area, and reduced-motion users keep a static loading state.
- `product-dialog-experience.js`: each child dialog disposes only its own PDF viewer, including queued old close events. Overview/Details/COA follow the actual scroll position with aria-current, use measured toolbar height, and respect reduced motion. The same image source/WordPress responsive attributes and the same report presentation retain their DOM and focus; changed specification/date/identity/file/URLs still update. Empty URLs no longer become accidental same-origin requests. Gallery arrows move focus to the visible slide; pointer identity, vertical scrolling and cancelled gestures do not consume the wrong click.
- `product-dialog-interaction.css`: the report viewer shell keeps its available viewport height while its PDF pages arrive. Width and product-image proportions remain responsive. `functions.php` loads this small stylesheet after the existing motion stylesheet.
- `live-cart-experience.js/css`: keep the existing 320ms drawer entrance with a shorter 200ms exit. A closing drawer is temporarily inert, reopening cancels old motion, and stale callbacks/repeated close cannot close the new session or restart the timeout. Reduced motion, hidden state, pagehide/BFCache and disposal settle/clean the owned presentation. Native focus, actual cart API, keyed rows, totals, checkout processing guards and app switches are preserved.

## Actual verification

- Node combined regression: 63 top-level tests passed, including 8 pending-request cases, 10 product interaction cases, 11 menu lifecycle cases, native product observation, quick-view pairing and surface/history regressions. The cart test file additionally reports 31 drawer/checkout bridge checks; do not add these to the top-level total as if they were separate Node test cases.
- PHP native cart entrypoint checks: 14 passed. Modified PHP lint, three JS syntax checks and git diff whitespace checks passed.
- Impeccable static detection on the six changed/new JS/CSS assets returned exit 0 and `[]`. The explicitly backend-only DESIGN.md was excluded from this public-storefront scan; it was not rewritten. This source scan is not a device performance score.
- Actual local formal site in a 390 × 844 same-origin frame: width=scrollWidth=390. Details and COA each became aria-current=location, with section top approximately 64.4px below the viewport and toolbar bottom approximately 56.7px. The 10mg real report rendered one PDF page in a 390 × 844 report shell. Product → Bag keeps the 390px product under a 378px right-side drawer and the same homepage URL. No quantity, customer address, order or payment action was performed.
- Screenshot: `D:/Codex/Tasks/2026-11-03/storefront-interaction-polish/outputs/mobile-coa-polished.png`. Detailed regression output and source detector output are in the same task outputs directory. The completed local Back check, actual release receipts and bounded public-browser smoke are recorded below. Physical-device performance remains unmeasured.

## Boundaries and release

Full product-content exit remains constrained by the backend native close handler immediately clearing its body. This work does not delay system Back, clone purchase forms or monkeypatch close. A coordinated presentation teardown hook would be needed to add that exit safely. Multi-page PDF virtualization and physical iOS/Android FPS/gesture measurements were not performed; passing model tests and a desktop-hosted mobile viewport are not those measurements.

Runtime allowlist: functions.php, storefront-motion.css, home-quick-view.js, product-dialog-experience.js, new product-dialog-interaction.css, live-cart-experience.js and live-cart-experience.css. Public product-dialog-experience.js must receive only this reviewed delta relative to `7fc5de7`; its exact older public `8c1daed` bytes lack an earlier unrelated FAQ append line. The scoped generator preserves that omission rather than copying the entire local file. No PHP FAQ/content/API, settings, plugin, database or private configuration is part of this release.

The publication framework coordinates the actual canonical lifecycle locks and verifies the exact active store identity alongside the runtime/config/current-pointer guards. It rejects a changed, retiring or retired store rather than resurrecting it. The actual seven-file and separate navigation-only executions are recorded below. See task `work/interaction-deployment-runbook.txt` and `work/navigation-followup/navigation-deployment-runbook.txt` for their distinct guards and rollback boundaries.

## Final mobile check and bounded navigation follow-up

After reloading the final assets, the 10mg image actually has the authoritative 600 × 600 intrinsic attributes, an 807-character srcset and the WordPress mobile sizes rule. The COA shell rendered the actual one-page report. A screenshot-backed coordinate click on the sticky Bag control preserved COA scrollTop=3694 while opening the drawer. Browser Back then closed only the drawer, retaining the same product, 10mg selection, COA section, scrollTop=3694 and homepage URL. No cart mutation was used. An earlier locator-based automated click scrolled the sticky target into view before opening the bag (3310); that was not attributed to the drawer's motion after the direct-coordinate control confirmed the distinction.

Independent review also found a genuine avoidable restoration issue: `live-commerce-navigation.js` dispatched Woo select/quantity changes even when the values already matched. The bounded follow-up adds only equality guards, retaining permitted-option checks, real changed-value restoration, serial cancellation and the original scroll restoration. Its 7 actual-IIFE popstate tests failed against the former no-op behavior and pass with the guards. This navigation-only publication is separate from the seven-file release; it cannot copy other theme files.

## Actual seven-file release and one-file follow-up

The seven-file source is `73c973c6c6f8eaf7fe0d1095cf1a241034954f9a`; the independent navigation-only source is `b7a0a6531adf49d076c4d839251cb0f7ffe37ad7`. Both are ancestors of integrated frontend checkpoint `238071810e84226c6238d5c3bec89aae650348fb`, which also preserves the concurrent backend history. The public dialog JS is the approved selective artifact, SHA256 `0fad24b99fb9a47cbe6f155ee6acaf86b9a7739660e74706b34599869b595657`; the unrelated previously unpublished FAQ append remains excluded. The follow-up changes only `assets/live-commerce-navigation.js`, whose served SHA256 is `9bef7b8601d3c0cf6bdfad637c9cb26591452ba2d2d411fba230eafdef549c44`.

| Host | Seven-file release completed (UTC) | Navigation follow-up completed (UTC) | Final runtime |
| --- | --- | --- | --- |
| Web | 2026-11-03 09:23:24.481729 | 2026-11-03 09:44:55.741134 | `/srv/vialcove/releases/storefront-navigation-b7a0a6531adf-web01` |
| Admin | 2026-11-03 09:27:41.723649 | 2026-11-03 09:50:27.731240 | `/srv/vialcove/store-admin/releases/storefront-navigation-b7a0a6531adf-admin01` |

Each navigation candidate cloned its host's own `storefront-interaction-73c973c6c6f8-web01/admin01` runtime. Whole-manifest verification confirms exactly one file changed and preserves all seven preceding UI files, plugins, MU plugins, private configuration and upload links. Both release stages held the canonical lifecycle/deployment locks plus the existing UI compatibility lock, verified the exact active store identity, and guarded configuration and current-pointer inode before atomic replacement. The seven-file receipts include PHP lint and six exact HTTPS asset hashes/types; the one-file receipts include the exact navigation JavaScript HTTPS hash/type. Each stage also verified public-home or merchant-login HTTP health. No backend implementation, lifecycle state, configuration, database, payment settings, customer-cart contents, order or payment action was changed by these releases.

Receipt evidence is under `D:/Codex/Tasks/2026-11-03/storefront-interaction-polish/outputs/`: `interaction-published-web.json`, `interaction-published-admin.json`, `navigation-followup/navigation-published-web.json` and `navigation-followup/navigation-published-admin.json`. Local payload/source/hash and runner validation are recorded separately in `interaction-final-readiness.json` and `navigation-followup/navigation-final-readiness.json`; readiness alone is not the deployment evidence.

## Actual public-browser smoke

Root completed a CUA check on the public `https://www.vialcovepeptides.com/` homepage. Its native View Tirzepatide link opened the actual product in place and kept that exact homepage URL. The browser exposed the real product title, specification options, stock, $55/$78 prices, FAQ, COA and Add to bag control. Clicking Details set the actual section navigation to `aria-current="location"`. The public page loaded the motion stylesheet, product interaction stylesheet and the new navigation JavaScript version `1793699083`; no pending product opener remained after loading (`busy=0`).

The actual responsive product image was complete with `naturalWidth=499`, intrinsic attributes 600 × 600, an 807-character srcset, and sizes `(max-width: 767px) calc(100vw - 64px), 500px`. Its selected source was the public TR20 1024 × 1024 PNG. This verifies the live image DTO/rendering path rather than a fixture or local-only preview.

This public smoke did not invoke Add to bag, alter a cart, submit an order or attempt payment. The actual PDF rendering and Bag → Back → COA scroll retention checks above were performed on the local formal site; this public smoke does not claim those additional interactions or physical iOS/Android gestures, FPS and payment completion were tested.
