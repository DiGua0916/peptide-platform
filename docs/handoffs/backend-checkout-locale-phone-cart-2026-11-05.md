# Checkout promotion labels, required phone and paid-cart boundaries

2026-11-05 · Codex. User assigned the checkout fixes across frontend, backend and payment chats, with `独立站后端 站点` owning final acceptance, integration, GitHub synchronization and server release. This handoff covers the backend candidate only. No runtime installation, server deployment, configuration write, real checkout or payment was performed here.

## Candidate scope

- `includes/class-promotions.php`: share one narrow `Promotions::customer_title()` mapping for the established stored names `黑色星期五` / `黑五`. Buyer-facing English is `Black Friday`; Spanish follows the existing request language selection and displays `Viernes Negro`. Actual fee/applied/next/public-detail/list/product paths now consume this label. Default administrative `view()` keeps the merchant's stored title. Custom promotion names, IDs, eligibility, amounts, tax, tiers and dates are unchanged.
- `includes/class-campaign-countdown.php`: reuse the shared label; administrative selection candidates retain the stored title. This removes the divergent countdown-only translation which made the homepage English while the real checkout fee stayed Chinese.
- New `includes/class-checkout-phone.php`: read-time `pre_option_woocommerce_checkout_phone_field` policy returns `required`. Woo's native Blocks `defaultFields`, WC_Countries address requirements and Store API validation consume the same option. The existing phone input becomes required and visible for every payment method. No duplicate phone field, number rewriting, address mutation or alternate validation API.
- `includes/class-plugin.php`: register `Checkout_Phone` immediately after `Checkout_Ack` in `Plugin::boot()`; existing autoload discovers the new class.
- New isolated tests: `tests/promotions-public-label-pure.php`, `tests/checkout-phone-required-pure.php`, `tests/checkout-phone-required-native.php`.

## Verification

Root reran the assembled candidate: **14/14 commands passed** (seven PHP syntax checks, four pure groups, installed native phone fixture, native cart diagnostic and diff whitespace).

- New promotion actual-class fee/public paths: 65 assertions. Includes EN/ES, custom titles unchanged, stable native fee amount/tax/identity and unchanged source configuration.
- Existing countdown: 451 assertions; actual database writes/public-read mutations zero. Its two simulated admin saves are in memory, not production saves.
- Existing announcement: 50 assertions.
- Phone policy: 13 assertions, no stored option writes.
- Installed phone native fixture: 24 assertions. It loads actual WordPress hooks/errors, Woo CartCheckoutUtils/CoreCheckoutFields/WC_Countries and Store API OrderController address validation source. US/PR × billing/shipping missing/blank phone is rejected; filled phone is accepted with original address/number preserved. Native `defaultFields.phone.required=true`, `hidden=false`, `label=Phone`.
- Cart diagnostic: 20 assertions using installed Woo source, in-memory order/cart/session/request doubles and the real WC_Cart_Session destructor plus CheckoutTrait/DraftOrderTrait methods. These assertions establish native behavior, including unsafe defaults; **they do not certify the final payment cleanup as safe**.

Evidence and full logs: `D:/Codex/Tasks/2026-11-05/backend-checkout-locale-phone-cart/outputs/verification.json`; task `work/verify-candidate.py` contains exact commands. The fixtures do not bootstrap a real WordPress request, connect SQL, save customers/orders/carts or call payment/network endpoints. This is not fresh mobile rendering or real payment acceptance.

## Frontend agreement

Frontend was informed through the user-authorized chat coordination. Use the native `defaultFields.phone.required` and `label`; `optionalLabel` remains in Woo's dictionary but must not be chosen for this required field. Do not add another phone input or remove server validation. Frontend owns typography, contrast and stale/cart-count presentation, and will deliver its own changes to the integrating chat.

## Cart findings and payment-owned contract

No second cleanup service/hook was added to Core. Current payment-return authentication failure can exit before Woo's normal cleanup. The payment chat owns the authenticated result and cleanup implementation.

Native source observations were sent to the payment and integrating chats:

- `StoreApi/Utilities/CheckoutTrait.php` sets `order_awaiting_payment` and calls session `save_data()` before the gateway hook. `DraftOrderTrait.php` sets `store_api_draft_order`. The two markers can coexist with different order IDs; a simple either-marker match is insufficient.
- `WC_Order::has_cart_hash()` checks equality, not session identity. The same items can produce the same hash in a different/new cart. Native tracking observations are not a checkout epoch proof.
- `wc_clear_cart_after_payment()` protects changed hashes, but its ordinary awaiting-order branch treats `on-hold` as clearable despite no `is_paid()` requirement. Its order-received/key branch does not establish the original session by itself.
- `WC_Shortcode_Checkout::order_received()` subsequently calls `wc_empty_cart()` unconditionally, including changed carts/invalid key cases. Thus a blind redirect to every native order-received page does not protect later cart additions.
- Native `empty_cart()` dispatches `woocommerce_cart_emptied`; the native cart session handler clears cart-derived state and both order markers. Use the native API when cleanup is actually authorized.

Latest payment-chat design is to keep even an authenticated paid result on its own result view, require the exact applied attempt/transaction and persisted native paid fact, current browser session ownership, nonconflicting same-order markers, matching cart hash and an unchanged session checkout snapshot/epoch. Unknown/unpaid/no-session/new-cart/legacy unproven state is retained. It is also examining a payment-method-scoped veto for the native on-hold auto-clear branch. That code and acceptance belong to the payment chat, not this commit; do not report this handoff's diagnostics as implementation of its final contract.

The integrating chat must confirm the final payment implementation and front/back integration before publication. No real order, payment or third-party notification was used to test these paths.

## Configuration migration and release

Neither backend fix requires a database migration. The stored activity title and original phone option are preserved. Existing historical order fee names are not rewritten; new native cart calculations/new order fee lines use the shared buyer label after release. Do not copy the local database or reset payment accounts, products, orders, addresses, rules or customer values.

The phone read policy is effective while this Core feature is loaded. Disabling it restores the stored merchant preference; do not describe this as having persisted `required` in the database. Existing storefront language restrictions on payment/POST/order routes remain unchanged; contexts without storefront translation keep English. This is not a new full multilingual payment strategy.

The integrating chat should deploy the new class and boot registration together, retain all other chats' in-flight work, and verify required phone display (mobile/desktop EN/ES), native US/PR validation, refreshed fee label, and payment-owned cleanup/unknown-result paths. This chat only commits its reviewed candidate locally and reports the hash; it does not push or publish independently.
