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
