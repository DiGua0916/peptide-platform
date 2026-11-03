# Checkout continuity and payment-return investigation

The owner requested that an unpaid order remain findable and that a new checkout create an independent order instead of being blocked by an earlier pending payment. WooCommerce's same-cart draft reuse had updated an existing payment-bound order's address; the payment consistency guard then correctly refused its changed fingerprint, but the resulting customer flow was unusable. Preserve each original order/payment record while separating a new checkout navigation from that old draft.

The owner subsequently reported a bank debit after a fresh mobile payment, followed by an HQ `invalid_payment_return` JSON page. At the initial read-only investigation the new native order remained pending, its HQ session was created with test mode off in production, its site identity and frozen store return URL were valid, and no result notification event had been saved. The bank report must not be described as a verified provider notification or settled amount. No repeated payment, fabricated notification or manual paid/stock change is authorized by this investigation.

## HQ return-error checkpoint

The return handler now produces a readable, private HTML error page through a route-specific REST serving hook rather than showing raw JSON. This changes error presentation; it does not itself fix the unknown provider-return discrepancy. An error may offer only the registered store home after the original browser reference, token, age, site UUID and active-site checks pass. Provider status, transaction syntax and signature still must all validate before any redirect to the original frozen store callback. The handler never marks an order paid.

`Payment_Return_View` applies no-store/no-referrer/noindex/nosniff headers and a restrictive CSP. It reads no browser target and includes no order key, provider URL or payment data. The standalone view suite passed 70 checks. An independent suite using the actual Hub, signature, frozen URL helper and return view passed 297 negative/binding checks and two separate seven-check success/failed redirect processes, with zero real HTTP or financial writes. Syntax and diff checks passed. This checkpoint is not a production deployment or real callback acceptance claim.

## Storefront work in progress

The checkout-navigation and pending-order modules are being integrated and tested separately. New normal checkout navigation can detach only this Woo session's pointers to its own existing hosted-payment order, preserving that order, its financial fences, cart and customer. Explicit order-pay remains the original payment continuation. Repeated payment POSTs must retain same-order deduplication; no general "clear all payment state" recovery is allowed.

Discovery uses native account ownership or a bounded same-Woo-session order capability, never a public email/order-number lookup. These controls and native Woo QA are still being checked. Do not claim that stale tabs, direct REST address mutation, lost guest sessions, actual mobile layout or production publication have already been verified.

A temporary private, expiring observer for this one reported payment is prepared outside Git to record callback field shapes and validation booleans only. It does not save raw request values, card/contact information, references, transactions, signatures or tokens, and cannot call a payment or notification handler. Actual deployment, capture and cleanup receipts belong in the D-drive task before any claim of completion.

All working evidence is in `D:/Codex/Tasks/2026-11-03/payment-checkout-lifecycle`. Browser control was stopped by automatic approval review because Windows browser URL identity could not be established; no alternative UI mechanism was used to bypass that stop. Server/source investigation continues independently.
