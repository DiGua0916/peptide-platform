# Payment confirmation and mutable contact details

The user confirmed changing the checkout country from US to PR and explicitly required address corrections to stay separate from recording successful payment. Shipping can resolve an address issue; a verified payment must remain visible.

The separately published JSON notification parsing fix handles the actual signed provider transport. This follow-up preserves frozen full-order fingerprints for checkout reuse and audit, while new attempts additionally freeze `payment_fingerprint`: the existing exact currency, total, and ordered native line-item projection, excluding contact/address fields.

Signed success notifications use that financial fingerprint when present. A malformed new fingerprint fails closed and does not fall back. Legacy attempts without it retain their original full fingerprint comparison. Existing signature, site/channel binding, exact amount/currency, transaction uniqueness, native persisted payment confirmation and completion guards remain in place. No manual paid-status writes or provider charge requests are introduced.

An additional narrowly scoped customer-view filter prevents WooCommerce's US default state leaking into PR checkout copies when PR has no state list. It leaves native raw customer values, existing order addresses, and countries with valid states unchanged.

For the reported legacy order, a migration may add only the new financial fingerprint after proving its original full fingerprint exactly equals an in-memory reconstruction with the single known original empty billing state. It must retain the original frozen fingerprint, actual address, status, transaction and creation records. The migration is bounded to the exact reported order and authenticated creation/callback evidence; it cannot infer arbitrary legacy snapshots.

Verification: the actual-class in-memory notification/creation/completion-guard model passed 65 checks, and the native customer/session getter model passed 172. Independent review of the merged source found no blocker. These models do not send a real payment or notification.

Production publication completed at 10:49 UTC from source commit `0894a4b8226f2459b7711ce311d2db93a6aceec9`. Exactly three PHP files were overlaid on fresh copies of the then-current buyer and merchant runtimes; the colleague's checkout lifecycle and pending-order discovery remained intact. Canonical lifecycle/publication locks, encrypted backups, compare-and-swap release checks, native FPM reflection of all changed classes, and HTTPS 200 health checks passed. Configuration, DNS/CDN and all non-selected runtime entries were preserved. Deployment did not charge, invoke a notification or mark an order paid.

The reported legacy attempt's compatibility key has now been added after a read-only preflight: its original full fingerprint exactly matched an in-memory reconstruction with only the original empty billing state, and its financial fingerprint was unchanged. Private attestation bound the exact HQ identity and previously observed signed success; no callback was replayed or fabricated.

The one-time metadata write's broad business-object post-check stopped because native HPOS `update_meta()` calls `after_meta_change()`, which can call `WC_Order::save()` and update `date_modified`. This corrects the initial assumption that `save_meta_data()` cannot indirectly save the order. No retry or blind rollback followed. Independent primary-store reads confirmed the new key matches and every other session-book field equals the encrypted prior checkpoint; the old full fingerprint, amount, currency, items and address scope remain intact. The order is still pending with no paid date or transaction. The native modified timestamp advanced. This private migration is not a reusable production feature.

At 10:59 UTC the HQ still had no durable event for the original success notification after the JSON fix. The visible provider record remained successful and exposed no notification resend control. The user has been asked for a provider resend, without another payment. Actual provider retry and native completion remain unverified; this document does not claim that the old payment has already been recovered. The temporary observer has a fixed expiry at 11:14 UTC and must not be extended without a concrete need; its later cleanup must preserve the current HQ release and colleague updates.

The broader HQ navigation update remains paused at the user's request while this incident is handled. Paused UI files are excluded from payment commits and deployment.

## Later user decision

The user said the reported payment was a test order and chose to stop pursuing a provider notification resend. Stop asking the user to contact the provider, do not initiate another payment, and preserve the test order's actual unpaid/native record rather than inventing payment completion. The JSON transport and address-separation production fixes remain installed. A new real customer payment end-to-end after these fixes has not yet been verified; do not equate model or source deployment checks with that result. Any later test should use the ordinary customer flow, with incident investigation handled by the agent.
