# Formal payment defaults and server publication

2026-11-03 Codex. The latest user instruction supersedes the earlier test-environment discussion: do not create a separate test site or switch the deployed applications to staging. Publish the normal formal version; the owner will place orders and report issues.

## Scope

New account defaults are enabled=yes and test_mode=no. Both the HQ new-record branch and the new-account form use that default. Existing stored accounts, archive/restore, legacy missing-field defaults, credentials and authorization are retained. The advanced test flag explicitly describes provider test credentials and does not simulate payment or change the gateway. All production/payment validation remains.

No isolated WordPress instance, database, database user or service was created. All three deployed applications remain production. The former staging plan was cancelled before any server write.

The exact prior failure was the incompatible initial combination of new enabled=yes with old test_mode=yes on production. Existing code also restricts test mode in public policy, session creation and notifications; deleting one guard would not correctly resolve the integration. The formal defaults remove the new-form contradiction without bypassing those checks.

## Verification and publication

32/32 model/real-class render checks passed, including new enabled/live defaults, existing disabled/test states, actual form checkboxes, HTTPS production acceptance and explicit production-test rejection. Model evidence is not a provider transaction.

HQ encrypted backup completed before publication: 257,709 bytes; ciphertext SHA recorded in task outputs. Decryption was not tested in this turn. The paired Hub_Accounts/Hub_Page overlay preserves the currently deployed application, root ownership and shared production configuration. Final server receipt is appended below after cutover.

No customer order, payment, card entry or merchant setting is submitted by the agent. This release does not constitute provider/notify/settlement acceptance. Source/Git synchronization does not imply unrelated frontend/main changes were deployed.

Evidence root: D:/Codex/Tasks/2026-11-03/payment-test-mode/outputs/.

## Deployed formal server result

HQ is now `/srv/vialcove/hq/releases/formal-payments-5dd0d844014d`, based on the actual a32a9c1 runtime. Both payment PHP files were published together. All4,201 prior code/link entries were copied and checked before patching; the resulting manifest records3,746 files. PHP syntax and the actual deployed new_account_defaults() probe passed enabled=yes/test_mode=no. The root-owned rollback checkpoint and prior release are retained. HQ shared config remained byte-identical, including production; merchant and storefront configurations were never modified.

The actual authenticated in-app server page at https://vialcoveresearch.com/wp-admin/admin.php?page=pchq-payments&new_account=1 showed the new account form, enabled checked, test_mode unchecked. No form was submitted. The screenshot follow-up timed out and reset the browser tool, so no server screenshot is claimed; the earlier actual DOM result and server/native file receipts are the evidence. The server tab was marked deliverable before that timeout. The local10090 tab and existing inputs were preserved.

No test site was built, no environment switched to staging, no merchant record enabled/edited, and no order/payment created. The owner explicitly chose to place customer-flow orders themselves on the formal site. Existing provider credentials, precise gateway outbound authorization and actual provider/notify/settlement remain separate configuration/acceptance: do not describe this default/UI release as completed payment integration.

Encrypted HQ backup receipt: 257,709 bytes, SHA25620f18ebefb1a79a08222963d61b5299a1d70e8d82b45711bb614c75d50e04dde. No decryption rehearsal in this task. Full receipts remain under the task outputs folder.
