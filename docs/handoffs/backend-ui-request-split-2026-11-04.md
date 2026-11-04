# Backend work from the user's storefront UI brief — 2026-11-04

The user explicitly assigned this window the backend portion of Desktop/前端ui.md and requested two illustrated Markdown documents. This supersedes the repository's early role names for this task only; storefront theme appearance stays with the frontend window. No separate Codex chat was messaged or created.

## Source and division

Original: `C:/Users/JingYue/Desktop/前端ui.md`. Seventeen referenced screenshots were inspected and copied, unchanged, to the D-drive task output bundle. The original and its Typora image directory are not modified or committed.

User deliverables: `D:/Codex/Tasks/2026-11-04/frontend-backend-ui-split/outputs/前端UI需求.md`, `后端功能需求.md`, and `前后端需求_含图片.zip`. Images use portable `assets/` relative links. The storefront document includes every original item and all seventeen images; the backend document includes the relevant screenshots and distinguishes existing capabilities from new work.

The user confirmed **one existing sale unit / one box per recommendation**, not breaking a ten-vial box into individual purchases. The per-vial figure is an informational equivalent only. No new coupon amount, campaign schedule, address provider, phone number, or shipping promise was supplied. The source says both “180” and “183+”; the frontend document explicitly preserves the ambiguity and proposes 180+ consistently, without changing actual counts. Report and footer layout requests do not change underlying report ownership/access or necessary checkout acknowledgements.

## Backend integration design (implementation completed below)

- Reuse the activity listener and Woo Store API; quote at most one box and one result, with a merchant-selected existing coupon and promotion. Do not write a cart while reading/quoting or trust client prices. Actual customer click must remain one native add-item mutation, validated against current configuration/cart/rules; use Woo coupon validation rather than a second discount engine.
- Expose a selected SKU's actual packaging count and per-vial price. An explicit merchant count may override strictly recognized Pack data. Unknown/ambiguous counts and unselected variable parents must not manufacture a price. Keep current box pricing, inventory and orders.
- Let the existing header announcement bind an existing promotion, as activity blocks already can. Preserve manual announcements and existing tiers/dates. Expose a read-only public helper for theme consumers.
- Reuse existing `peptide_core_get_support_contacts()` and `/peptide/v1/site.support`; WhatsApp settings already exist. Do not duplicate customer support storage.
- Existing sale-pricing and sale-savings DTOs distinguish product price savings from coupons. Native Blocks customer/address updates own shipping recalculation. No guessing a ZIP from a country/state or replaying the previous test payment.

Implementation results, verification, Git integration and deployment status will be appended when completed. This initial design record does not claim the code or production configuration already implements the new behavior.

## Implementation and reviewed source checkpoint

The fourteen runtime files are complete and frozen for root integration: one existing application admin JS, the six activity classes (`cart-reader`, `config`, `quote-cart`, `recommendations`, new `recommendation-coupon`, `store`), the two activity/store API classes, Plugin, functions, Site_Design, and new Campaign_Announcement/Unit_Pricing. Plugin registers both new service hooks. No theme, HQ, gateway/payment handler, shipping policy or customer data was changed by these source edits.

Detailed contracts and actual verification:

- [Selected-SKU per-vial price](backend-unit-pricing-2026-11-04.md): 79 actual-service in-memory checks plus 16 real Woo read-only simple-product checks; no native variation was available in the existing QA, so that native case is not claimed. No package/price/order migration. Structured explicit count and strictly understood existing Pack data work without assuming ten.
- [Campaign and support](backend-campaign-support-2026-11-04.md): 50 isolated actual-helper/schema checks; optional promotion binding in the existing header, manual compatibility, active/upcoming distinction and unavailable hiding. Support is reused unchanged.
- [One-box recommendation and native coupon](backend-cart-recommendation-coupon-2026-11-04.md): configuration 108, native TEMP quote 67, native TEMP Store API add dispatch 18, HTTP no-coupon WCPay compatibility 46 and enabled-coupon WCPay context 67 checks. These are distinct test groups, not production end-to-end orders. Unsupported existing/individual-use/targeted/shipping coupons are hidden; normal shopping and already-applied coupons remain intact.
- Root reran all three portable pure test files, PHP/JS syntax for all nineteen source/test files, agent frozen-source SHA checks and `git diff --check`; all passed. Receipt `outputs/source-verification.json`. No claim that a JavaScript settings parse check is a live admin save.

The user supplied a further screenshot reporting no mobile recommendation. The frontend's existing D-drive HTTP diagnosis identifies a genuine context bug: native WooPayments non-Store REST no-conversion hooks were rejected, whereas CLI lacked those hooks. The shared guard now verifies exact native trio/callback identity and the audited source hash while retaining unknown/foreign-currency rejection, including actual coupon getters. The strict recognition does not disable WooPayments, remove filters or enable conversion.

Root independently installed only the reviewed WCPay method segment into the original LocalWP plugin after exact baseline/backup checks. Other original-role dirty files and Git index remained untouched. Real IAB `tools/mobile-preview.html` then showed the existing cart's recommendation visible, with exactly one box; no add/quantity/coupon mutation was clicked. Screenshot: `D:/Codex/Tasks/2026-11-04/frontend-backend-ui-split/outputs/mobile-recommendation-restored.png`; install receipt `local-wcpay-hotfix-install.json`. This is an actual local UI result, not a production rollout or an automatic-coupon UI result.

For BE-04/05, source review confirms that existing `Pricing::pair/cart_line/cart_summary` and Store API fields already expose paired price/percent and explicitly separate sale savings from Woo coupon discounts. Existing native Blocks checkout and shipping plans own country/state/postcode-dependent recalculation. No replacement arithmetic or address generator was added, and no real address/checkout was submitted. Unsupported ZIP auto-completion remains a frontend/browser service question, not a reason to guess personal addresses.

Full feature installation, remote publication and final refs are recorded separately after they actually occur. No new coupon discount, promotion dates, WhatsApp number, published header document or application configuration has been invented or activated at this checkpoint. The frontend must implement the documented `supportsAutomaticCoupon`/`offerToken` single-request flow before enabling its new coupon UI; the capability gate prevents misleading old-client quotes.

## Actual installation and selective publication

Implementation checkpoint `80b8894` was normally merged with the peer's Data-menu change `54e3a9d`; resulting source `473dd8fb0046fe9563ca454938e50339c5ebe4f2` was pushed to main and this task branch and independently verified. This is the fourteen-file publication source, not a whole-repository production replacement.

All fourteen files were installed into the original backend role tree used by the LocalWP plugin junction. Each baseline/source SHA and backup was checked. Fourteen unrelated pre-existing dirty files and the existing index were preserved; they were not restored, staged or committed by this task. Read-only actual local HTTP checks confirmed maximum one box/one visible result, disabled/unbound coupon config, reused support DTO, and the unit-pricing field on a Store API product (correctly null for an unselected variable parent). This does not claim a live admin settings save or the theme's new coupon implementation.

Fresh existing encrypted backup services completed on both machines before deployment; ciphertext headers, private modes and SHA were verified. No restore was performed. Root reviewed/froze both exact publishers and reran fresh preflight against each actual current release, preserving the peer's merchant menu release. Both selective publications completed successfully on 2026-11-04:

- Buyer: 09:59:12 UTC, current `/srv/vialcove/releases/ui-backend-473dd8fb0046-buyer-1793786336569121877`.
- Merchant: 09:59:35 UTC, current `/srv/vialcove/store-admin/releases/ui-backend-473dd8fb0046-merchant-1793786362107684889`.

Receipts `outputs/publication-buyer.json` and `publication-merchant.json` verify all fourteen target SHAs, complete nonallowlisted source preservation, config/CDN/payment/MU preservation, unchanged HQ pointer, PHP pure lint, graceful FPM reload, direct-origin TLS HTML 200 and exact admin-JS bytes/MIME. The publisher issued no explicit WP-CLI, database, payment, cron or mail mutation; ordinary HTML health requests can still run normal WordPress lifecycle hooks. No theme, mother template/future-store package or HQ release was deployed. There is no distributed transaction across the two machines.

No application configuration, coupon discount, campaign date or support number was created/enabled by deployment. Original manual announcements and existing coupons remain governed by their own settings. The native variation test limitation and frontend token integration requirement above remain open. Actual public-contract verification is recorded below after it completes.

At 10:06:46 UTC, an independent read-only check confirmed both currents still exactly match these publication receipts. Six anonymous, CA-verified direct-origin HTTPS GETs (activity config, site support, Store API product on each runtime) all returned 200/JSON with no Set-Cookie. Both report `maxVisible=1`/`maxAddBoxes=1`, correctly typed coupon/support fields and the unit-pricing extension. The sampled unselected variable parents return null; this is not a selected-SKU numerical assertion. No cart/session endpoint was requested. Receipt `outputs/remote-public-verification.json` SHA256 `23818c7ebc8453130a961785a967a5beccae356279341697695d6f4ad437c1c4`.

The final illustrated Markdown ZIP was rebuilt after the integration notes; all nineteen archived entries byte-match current documents/assets, and all seventeen source image SHAs remain unchanged. The real local mobile recommendation screenshot is retained for user review. Frontend presentation and its new automatic-coupon action remain separate follow-up work, not reported as production end-to-end acceptance.
