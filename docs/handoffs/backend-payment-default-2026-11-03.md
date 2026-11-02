# New payment methods default to enabled

The user clarified that a newly added payment method should default to on, and was concerned that the switch restricted concurrent customers. The switch controls availability of the whole payment method. Existing order locks and creation reservations are scoped by order/site, not by merchant as a single-user queue. This was verified by source review, not a real concurrent-charge test.

## Change

- `Hub_Accounts::new_account_defaults()` overrides only enabled=yes for a new account. The new-record save branch and the new-account form use it.
- Stored/legacy defaults remain enabled=no because the same fallback fills missing historical fields. Editing an explicitly disabled account, archival and restore continue to use the stored state.
- The form explains that new methods default on, multiple customers can pay concurrently, and disabling pauses the entire method while earlier payment results may still update.
- Existing merchant completeness, HTTPS, production test-mode, authorization, nonce and revision checks remain. No merchant settings were saved or enabled by this task, and no payment was created.

The user's follow-up screenshot showed an archived method and asked how to delete it. The current product offers Archive/Restore, not permanent deletion. This task explains that limitation; it does not remove accounts, credentials, transactions, or historical order associations, and does not add a permanent-delete implementation.

## Actual verification

- 29/29 isolated existing-account model/rendering checks with the real production classes: new defaults, explicit opt-out, legacy missing enabled, disabled edit, missing credentials rejection, form checked state and archive/restore. These are model checks, not native database or provider transactions.
- Both modified PHP files passed syntax checks and Git whitespace checks.
- The two local runtime copies were patched after exact baseline checks and backups. A pre-existing runtime-only site URL expression was preserved instead of overwriting that file wholesale.
- Actual in-app local HQ new-account page shows the enabled checkbox checked and the revised copy. The only browser keystroke after opening moved focus with Tab; no form was submitted. Screenshot: `D:/Codex/Tasks/2026-11-03/payment-default/outputs/new-method-default.png`.

This is a local code/UI update plus Git synchronization. The public servers were not deployed or modified. It does not claim live payment/settlement or load-test acceptance.
