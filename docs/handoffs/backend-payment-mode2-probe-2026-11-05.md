# PayPal mode 2: native session and cashier entry probe

Author: Codex. Date: 2026-11-05, Asia/Shanghai. Task files and redacted receipts: `D:/Codex/Tasks/2026-11-05/payment-mode-test`.

The owner supplied the payment provider's feedback that the `mode` value should be changed and requested an immediate test. The supplied integration document defines `1` as synchronous and `2` as asynchronous, defaulting to `2`; it does not define `0` or identify either value as a payment-channel selector. The existing implementation accepts `1` and `2`, copies the HQ account's value into the actual create body, and signs the documented five fields (`mchid`, `currency`, `amount`, `site`, `order_id`). `mode` is outside that signature field set. The provider's actual returned type and URL continue to determine iframe versus redirect handling.

## Bounded production configuration change

At 08:25 CST, a source-pinned read confirmed account-book revision 15, with both active card and PayPal accounts configured as `mode="1"`. At 08:28 CST, the bounded operation changed only the PayPal account to `mode="2"` and the account-book revision to 16. The active credit-card account retained `mode="1"`. Other account configuration, saved credentials, provider-site bindings and trusted hosts were preserved. A root-only configuration backup was retained on the server; no secret values were exported. The inspect and apply operations performed no provider calls, order saves or historical-attempt changes, and the resolved current release remained unchanged.

The first local operator-script inspect attempt failed because of a string-replacement construction bug. That script was corrected and compiled before the successful inspect/apply sequence; the failed attempt did not reach a production configuration mutation. This was an operator-helper correction, not a payment runtime source change.

Receipts:

- `outputs/current-mode-inspect-20261105T002521Z.json`.
- `outputs/paypal-mode-inspect-20261105T002757Z.json`.
- `outputs/paypal-mode-apply-20261105T002814Z.json`.

## Actual native creation and provider cashier response

At 08:31 CST, the controlled native test created order 11745, explicitly marked synthetic, and exercised the store-to-HQ CREATE path exactly once. This was a real native order/session integration probe, not a complete browser checkout. The request targeted the PayPal account configured with HQ mode `2`; the native attempt was persisted before network activity. The result was `created`, with the native order still `pending`. No transaction number, paid date or stock-reduction marker was present. No card entry, PayPal approval or payment confirmation was submitted. The operation did not reset or resend an earlier order's create request.

The provider returned `type="redirect"` on `bestgoodsforyou.top`, with path `/pay/pay/index.php` and nonsecret channel marker `type=pp`. This differs from the historical mode-1 response path `/pay/singlepay/index.php`, which had produced a WordPress redirect and 404 in the earlier investigation. These observations show the actual before/after request outcomes; they do not establish an undocumented general rule for all merchants or routing assignments.

At 08:34 CST, a TLS-verified GET of the newly returned cashier entry received HTTP 200, `text/html; charset=UTF-8`, title `PayPal Checkout`, PayPal text and payment-button text, with no page-not-found text or redirect location. No PayPal SDK script was detected by that bounded HTML inspection. The GET made no additional CREATE POST or payment approval. Raw HTML, full cashier URL/query credentials and customer information were not exported.

Receipts:

- `outputs/native-mode2-20261105T003133Z.json`.
- `outputs/mode2-cashier-check-20261105T003431Z.json`.

## Browser and financial verification limits

The in-app browser navigation and subsequent tab acquisition each timed out after 30 seconds. A Chrome create attempt also failed locally with a `timeoutMs` API error. Therefore the native creation and HTTP 200 HTML check are verified, but a rendered browser interaction and full checkout UI acceptance are not claimed.

No sandbox buyer login/approval, sandbox purchase, browser return, server notification, native paid transition or settlement was verified in this probe. The successful cashier response resolves the observed new-entry 404 for this mode-2 session; it is not evidence that the entire payment lifecycle has completed. The next acceptance step is to use a sandbox buyer account in the newly working payment flow and verify the resulting notification and native order state. Changing account mode does not recreate historical sessions or rewrite their saved cashier URLs.

No payment runtime source was changed or deployed by this task. Existing collaborator runtime changes and dirty working-tree files were left untouched. This handoff records the bounded account-setting change and observed test results only.
