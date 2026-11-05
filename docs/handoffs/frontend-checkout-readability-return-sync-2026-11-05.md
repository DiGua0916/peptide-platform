# Checkout readability and authoritative cart return sync

2026-11-05 · Codex · local frontend work for the site integration window.

## Scope and ownership

Keep the current storefront, native Woo Blocks checkout, published decoration
documents, payment forms and business rules. This change is theme-only. The
site window owns final integration, GitHub and server release; this window did
not push, deploy or change any database configuration.

## What changed

### Native checkout typography

`assets/checkout.css`, `checkout-polish.css`, `checkout-subtotal-savings.css`:

- Keep the existing local VialCoveInter family. Its variable weight axis is
  100–900 with a default of 400; no broken local font axis was found.
- Give checkout task text explicit weights: supporting labels/variants 500,
  native monetary values 600, savings 700. Product name hierarchy is retained.
- Raise variants, original subtotal and savings annotations from 12 to 14 px,
  with 20 px leading. Override the nested native product-details 12 px rule so
  actual specifications inherit the readable metadata role.
- Keep the existing red item discount treatment, with a readable 14 px / 700
  label. Make the summary total 24 px / 700 and its caption 14 px / 500.
- Keep actual prices, discounts, native layout and tax calculations unchanged.

The source proves several labels inherited regular 400 and some metadata was
12 px. The supplied screenshot's thin discount badge is not completely explained
by that cascade: it already had weight 700. Its new larger label needs physical
iOS visual acceptance; no unsupported root-cause claim is made.

### Returning to a closed cart drawer

`assets/live-cart-experience.js`:

- Previously focus/visibility/pageshow reads used `canRead()`, which requires
  the drawer to be open. A restored homepage could therefore retain its old
  header/floating count until the drawer was opened.
- A shared return lifecycle now performs the existing same-origin, no-store
  Woo cart GET even while the active drawer is closed. State updates feed the
  existing header/floating badges and cart views.
- Coalesce a burst of events for 50 ms and bound repeated foreground focus to
  1 second. A real blur/hidden/pagehide resets that bound.
- Retire pre-leave GETs so late data cannot overwrite a newer cart. Preserve
  the existing cart while waiting or on failure; never fabricate an empty cart.
- Preserve owned pending POSTs without cancellation or replay. No return URL,
  query flag or browser success claim authorizes clearing the cart.

If Woo still returns six items, the frontend correctly keeps six. Backend
session cleanup and authenticated payment-result validation remain separate
owners' work. The legacy drawer fallback was not rewritten: this site's active
live-cart controller replaces it.

### Phone contract

The theme has no `Phone (optional)` / `optionalLabel` replacement and does not
duplicate, hide or weaken the native phone field. The backend window reports a
native `woocommerce_checkout_phone_field=required` policy in Core, with native
Woo schema and Store API validation tests. Integrate that backend commit too;
this frontend commit alone does not enable required phone validation. Verify
the actual shipping/billing phone label and required semantics after integration.

## Verification

- `node --test tests/cart-return-sync.cjs tests/cart-device-settings.cjs
  tests/cart-add-confirmation.cjs tests/live-cart-drawer.cjs
  tests/cart-enrichment-lifecycle.cjs tests/cart-recommendation-lifecycle.cjs
  tests/live-cart-recent.cjs tests/checkout-subtotal-savings.cjs`:
  71 top-level tests passed, zero failed. The custom subtotal script also reports
  its 17 internal checks; these are not added again to the top-level count.
- New actual-controller VM cases show a closed drawer's badge changing 6 → 0
  only after a valid empty Woo response. Adapter cases retain 6 on a nonempty
  response, preserve data on failure, reject stale reads and preserve one POST.
- `node --check assets/live-cart-experience.js` and `git diff --check` passed.
- Source palette contrast: ink/white 10.09:1, secondary/white 5.44:1,
  secondary/paper 5.09:1, white/red badge 6.61:1, savings red/white 6.44:1.
  This is a source calculation, not a browser computed-style audit.
- Impeccable detector ran successfully. Its advisory palette/type/radius
  comparisons use the repository's admin-only DESIGN.md, which explicitly
  excludes the public storefront. Existing storefront identity was preserved;
  this task does not rewrite that unrelated admin design record.

Browser verification was blocked: the existing checkout tab binding timed out;
native browser windows reported minimized and the documented CUA surface did
not provide their suggested activation method. No alternate browser automation
or payment/cart mutation was used. New live screenshots, 320/360/390/430 px
wrapping, text scaling and real iOS/payment-return acceptance remain pending.

## Migration and release

No DB or layout-document migration is needed for these theme styles/scripts.
They retain existing enqueue handles and `filemtime` asset versions. Preserve
all current native documents and frontend mobile overrides. Merge the separate
backend phone/session changes and payment result work before the site window's
end-to-end acceptance. Release these assets together, invalidate ordinary asset
caches as appropriate, and verify paid/unknown/cancelled returns against the
actual Woo cart without blindly clearing any unknown payment state.

Task evidence: `D:/Codex/Tasks/2026-11-05/checkout-readability-cart-authority/outputs`.
