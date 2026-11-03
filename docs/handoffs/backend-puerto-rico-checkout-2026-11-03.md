# Puerto Rico checkout availability

The owner serves customers in the United States and Puerto Rico and requested that Puerto Rico be selectable at checkout. This updates the existing first storefront's earlier US-only selling restriction. It does not authorize rewriting a historical payment session's address or changing shipping prices.

## Native representation and cause

Production WooCommerce 11.2.1 already includes `Puerto Rico` as country/region `PR`, separately from `US`. Its PR locale uses Municipality for the city and hides the optional state field. The local payment payload accepts PR with an empty state; no gateway code change was needed. This matches [WooCommerce's support explanation](https://wordpress.org/support/topic/puerto-rico-is-missing-in-united-states/) and its [country data](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/i18n/countries.php).

The first store's `woocommerce_allowed_countries` was `specific`, with only `US` in `woocommerce_specific_allowed_countries`. Shipping countries inherit the selling list, so this hid PR from both billing and shipping selection. The active general shipping plan already explicitly covered PR. Native Woo shipping zones and rest-of-world methods were empty because the existing plan engine supplies checkout rates.

## Production operation and verification

An exact native option snapshot was saved on D before mutation. The only business setting changed was `woocommerce_specific_allowed_countries`, from `[US]` to `[US, PR]`, using `update_option`. The operation checked serialized snapshots of the other country/destination and plan/rule options before and after; all were unchanged. Neither shipping rates nor tax, payment, customer addresses or orders were modified.

A fresh PHP process called the same native Blocks `CartCheckoutUtils::get_country_data()` used by checkout. Its result contains exactly PR and US, both permitting billing and shipping; PR has no states, its state is hidden/optional, and native postcode validation accepts the synthetic sample `00901`. The existing shipping-plan option SHA-256 remains `2b918e4348790bd05ee7a075a1505ee367c31070654b92b8320e6179e9520019`. Native plan quotes with a non-persisted product object and synthetic US/PR destinations both return the existing rate for a sample subtotal of 100. Those calculations created no cart, product, customer or order.

The initial configuration comparison stopped before changing anything because JSON round-tripping lost PHP integer/float distinctions in unrelated plan data. The corrected guard compares the original serialized native values. A subsequent Windows command-line length failure occurred before SSH execution; stdin transport resolved it. Only the final successful native update and fresh-process checks count as completion.

Task evidence is under `D:/Codex/Tasks/2026-11-03/puerto-rico-checkout/outputs`, including `country-enable-20261103T094258Z.json` and `verify-20261103T094503Z.json`. A browser selector interaction was not verified because the browser bindings timed out; server-side native country data and shipping computation were verified. A fresh checkout page is needed to consume the updated country list. This is a database setting change; no theme/plugin deployment or new-store template change is claimed.

## Existing mobile payment investigation

The owner confirmed that the mobile `shipping` message happened with an actual address and no observed bank debit/authorization notification. Exact original captures show all 18 address fields unchanged between store/HQ and provider. At 17:30 they also matched the then-current native order. A later read found native address changes, and the owner explicitly confirmed editing that order. The existing provider session still represents its original creation snapshot; do not relabel that capture as the edited address or clear its creation fence to retry it.

The hosted `shipping` message remains unresolved; adding PR does not prove its cause or successful debit. The owner must use a new correctly addressed order for a later authorized payment test. No provider CREATE, card interaction, callback, paid status or stock change was performed by this task. The provider's PR support is not established by local Woo acceptance.
