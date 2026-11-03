# Payment confirmation and mutable contact details

The user confirmed changing the checkout country from US to PR and explicitly required address corrections to stay separate from recording successful payment. Shipping can resolve an address issue; a verified payment must remain visible.

The separately published JSON notification parsing fix handles the actual signed provider transport. This follow-up preserves frozen full-order fingerprints for checkout reuse and audit, while new attempts additionally freeze `payment_fingerprint`: the existing exact currency, total, and ordered native line-item projection, excluding contact/address fields.

Signed success notifications use that financial fingerprint when present. A malformed new fingerprint fails closed and does not fall back. Legacy attempts without it retain their original full fingerprint comparison. Existing signature, site/channel binding, exact amount/currency, transaction uniqueness, native persisted payment confirmation and completion guards remain in place. No manual paid-status writes or provider charge requests are introduced.

An additional narrowly scoped customer-view filter prevents WooCommerce's US default state leaking into PR checkout copies when PR has no state list. It leaves native raw customer values, existing order addresses, and countries with valid states unchanged.

For the reported legacy order, a migration may add only the new financial fingerprint after proving its original full fingerprint exactly equals an in-memory reconstruction with the single known original empty billing state. It must retain the original frozen fingerprint, actual address, status, transaction and creation records. The migration is bounded to the exact reported order and authenticated creation/callback evidence; it cannot infer arbitrary legacy snapshots.

Source and verification status: implementation complete; actual-class in-memory notification/creation/guard tests, financial-hash sensitivity checks and native customer/session getter checks are being frozen. Production publication and actual original callback completion must be recorded separately; source completion alone is not payment recovery.

The broader HQ navigation update remains paused at the user's request while this incident is handled. Paused UI files are excluded from payment commits and deployment.
