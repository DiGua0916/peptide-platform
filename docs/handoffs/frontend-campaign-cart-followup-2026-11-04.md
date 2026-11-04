# Campaign placement and mobile cart follow-up — 2026-11-04

Owner: Codex, frontend. This follows the 14-item UI refresh; the user's subsequent screenshots identify missed cart cleanup and recent-product purchase controls. Work is on the local published storefront, not a new static demo. No public-server deployment was performed.

## Result

- The homepage campaign is a normal-flow native `promotion_card`, opt-in `presentation=campaign_bubble`. Header navigation retains its configured sticky behavior; the campaign scrolls out of view. A dark plum speech bubble and rose savings distinguish an activity from a generic announcement. Native amounts, conditions, eligibility, status and custom templates remain authoritative; no campaign dates/countdown or new offer are invented.
- Local layout document 685 was saved/published through the existing layout API with this single native module prepended. Document 686 only changed `announcement` to false. Both reference existing promotion 10980; original home modules and header settings remain. Guarded plans, raw backups, revisions and apply receipts are under `D:/Codex/Tasks/2026-11-04/campaign-bubble/outputs/`, not Git. Cart document 11727 and plugin data were not rewritten.
- Removed the cart summary's “Shipping and taxes…” sentence and commerce template's report-detour block. Removed `VIALCOVE / YOUR ORDER`, deduplicated the full-page cart heading, and retained its title setting. Full-page cart padding is now scoped separately from the drawer. The observed summary-to-footer gap is 24px, rather than the old stacked paddings and removed report block. Terms, required checkout acknowledgements, shipping calculations and payment errors remain.
- Recently viewed cards now have a specification, an exact-SKU native price/image pair and an explicit action. A fully specified, publicly available, one-unit-eligible SKU gets `Add 1 box +`. Otherwise `Select & add` opens the existing native product options flow. Add uses the same guarded cart client and a single native `/cart/add-item` POST with quantity 1. It waits for confirmation, reuses enabled feedback/fireworks, refreshes the real cart/recent exclusions, and does not retry an uncertain write.

## Presentation data contract

The existing theme `GET /vialcove/v1/home-product/{parentId}` retains its original HTML response by default. Optional `card=true[&variation={id}]` returns `{id,card:{productId,purchaseId,variationId,variationLabel,price:{text},image:{url,alt},canAddOne}}` without rendering the full form. An explicit invalid or foreign variation is rejected, not changed to another SKU. A parent-only history entry uses the merchant's first configured visible variation as one coherent image/label/price presentation; it never combines the parent's minimum price with that label. Missing exact images do not borrow another dosage's image.

`canAddOne` requires public native product eligibility, complete variation attributes, availability and Woo's `QuantityLimits`. Managed-stock reads may prepare Woo's ordinary session handler to read held-stock limits. This can initialize/refresh an anonymous session record; it is not a claim of zero DB effects. This adapter does not load/create a cart, alter stock, or create orders. Actual native cart submission revalidates availability and quantities. Failed reads fall back to an options action rather than guessed prices or permissive direct adds. Public GET responses are private/no-store.

## Verification and limits

- 25 isolated campaign presentation guards; 41 PHP exact-SKU card guards; 16 recent-card/transport JS checks; 31 drawer/checkout bridge checks; 14 entrypoint checks; 13 recommendation lifecycle/offer tests passed. The recent transport check proves one native SKU/one-unit POST, duplicate-tap exclusion and confirmed-response handling with synthetic responses. PHP lint, JS syntax and `git diff --check` passed.
- Actual browser: home bubble is inside `main`, exactly one, header announcements 0. After normal UI scrolling its bottom was -2924px, its CSS position `static`, sticky navigation top 0. Coupon cutout pseudo-elements were removed from this presentation; all three native savings stay on one line.
- Actual mobile preview at nominal 390 and 430 (available content widths 375 and 415 with the Windows iframe scrollbar) has no horizontal overflow. Recent AOD9604 2mg and Retatrutide 20mg each display their correct image, label, native price and enabled 44px `Add 1 box +`. Removed phrases and report block are absent. Latest snapshots are local task outputs `cart-390.json`, `cart-430.json`, `campaign-scroll.json`, and screenshots.
- No real add, coupon application, order, address entry or payment was submitted during QA. The user changed the real cart while work was in progress; no QA cleanup altered it. Mobile iframe checks are not physical-device FPS tests. No backend plugin, activity amounts, coupon settings, contact numbers or product records were edited.

## Next

Review the current mobile preview. Publish this theme through the normal approved server release workflow when authorized; Git integration and the LocalWP published layout are not a claim of public deployment. On a server release, migrate the same native campaign module and header announcement setting through the layout editor/API as well as releasing theme files.
