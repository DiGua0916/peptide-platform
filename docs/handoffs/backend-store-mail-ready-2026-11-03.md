# Store deployment continuation and mail readiness

2026-11-03 · Codex · User explicitly requested continued deployment after the server-role explanation. This updates the earlier Lightsail-resume snapshot and the administration task's retained-migration-gate statement.

## Current deployment

- Public storefront: `https://s0001.vialcovepeptides.com`. The traffic host serves public PHP/API and remains authoritative for this store's MySQL, uploads and private reports on its independent 32GB disk.
- Merchant administration: `https://s0001.vialcoveresearch.com/wp-admin/`, on the administration host, using the restricted SSH database channel and SSHFS media/report mounts. These are shared state, not independently writable database copies or high availability.
- HQ: `https://vialcoveresearch.com/wp-admin/`, a separate application. This continuation did not change HQ configuration, merchant credentials or payment enablement.

The parallel administration deployment already updated both store runtimes. Their 435 production Core files match `6f20b62a40cf7a68a15c1f396952cff810f4bb79`, including the 7 privacy changes. This continuation independently verified the complete storefront Core file set/hashes again. The theme/Panapay/Ops trees were unchanged by those Core increments; a later documentation commit is not a runtime release.

The storefront remains `/srv/vialcove/releases/6f20b62-web01`. The merchant runtime is now `/srv/vialcove/store-admin/releases/6f20b62-admin-mail-ready-20261103`. Its prior `6f20b62-admin01` runtime and root-private checkpoint are retained. Local uncommitted frontend/backend work and local business data were untouched.

## Removed the remaining migration blocker

The merchant runtime still had the exact temporary `vialcove-migration-guard.php` and an empty `shared/migration.lock`. While locked, it short-circuited both `pre_wp_mail` and `pre_http_request`, including SMTP tests and authorized HQ payment-policy reads. Entering valid credentials would not alone have removed this deployment blocker.

After exact guard-hash/ownership, lock-identity, mounts, expected-runtime and private-config checks, the continuation cloned and verified all 11,048 source file/link entries. The new release removes only that migration MU and adds versioned `tools/deployment/mu-plugins/vialcove-system-cron.php`. This small overlay preserves the asynchronous Action Scheduler restriction when external cron is configured; the existing working traffic-host minute scheduler continues owning shared database jobs. The storage wrapper, Core and private configuration stayed unchanged.

Publication held a root-private advisory lock and rechecked the expected symlink target/inode, config hash and migration lock before the atomic switch. Ordinary errors restore the prior runtime and lock. This is not a true CAS against unrelated publishers ignoring the lock, or guaranteed hard-termination recovery; the retained checkpoint supports manual recovery.

The new MU passed PHP syntax validation. Native verification inspected migration callback registrations through reflection, without invoking mail filters or `wp_mail`, and verified management configuration and scheduler ownership. It performed an actual signed HQ METHODS request: one configured method, zero enabled methods. No supplier API, payment-session creation, order or mail send was invoked. The migration lock was removed and the merchant HTTPS login remained healthy.

## Verification actually performed

- Post-cutover HTTP: **28 passed, 0 failed** for public pages/resources, old public personnel-path refusal, new manager login/anonymous redirect, private/config refusal and unsigned machine routes. PDF modules on both origins had JavaScript MIME. No cart or login session was created.
- After the migration-gate release: **5 passed, 0 failed** for public home200, intentional public-login403, merchant login200, HQ login200 and unsigned merchant payment-event401. Both native login forms were present; TLS validation stayed enabled.
- Native merchant-to-HQ signed METHODS read passed after the gate removal. This proves that internal policy connection, not SMTP delivery or supplier validity.
- Storefront certificate renewal dry-run passed; Certbot timer and Nginx configuration were valid. The parallel task separately recorded the management-certificate renewal rehearsal.
- Current Nginx/FPM/MySQL, real 60-second store cron and backup timer were active. Data mount, MySQL bind/guard and the complete current Core manifest passed. Earlier actual missing-mount/cold-reboot tests remain storage evidence, not a new whole-machine test of the split.

The earlier 34-item HTTP and temporary admin-session receipts belong to the pre-split deployment. The parallel administration task's later authenticated editor/REST/preview, public-cookie refusal, one-use HQ login ticket and bridge recovery are recorded in its own handoff; they were not rerun or relabelled here. Unknown original password login, complete browser editing/publishing, full split reboot and load testing remain unverified.

## Backup state and rollback limits

The parallel task subsequently added retention for 7 successful scheduled archives to the active daily scripts. Latest receipts verify both daily tasks, manually copied ciphertext with SHA verification, manual cutover backups retained separately, and UTC19:40 plus up to 300 seconds (about 03:40–03:45 Asia/Shanghai). This continuation inspected those latest receipts and active timer state; it did not execute another rotation test. Earlier statements that the active scripts lacked retention are superseded. Automatic offsite replication and alerts remain absent.

This task's separate manual age archive was actually decrypted/restored into a restricted new database, verified against 71 tables, 2 users, 82 published products, 183 variations and 0 orders; 3,637 media/report files were physically restored with matching hashes. Temporary restore state was removed and production was not restored over. That archive's recovery identity differs from the active daily jobs' identity; do not mix them. A SQL transaction followed by file capture is not an atomic database/files snapshot or a promised RPO/RTO.

Both current Core readers support the new `privacy` field. Do not roll back blindly to c258 after saving a configuration containing that field, even when false: the old reader rejects it. Use a compatible reader or a controlled single-option recovery, not an entire business-database rollback. This continuation's retained 6f20b62 merchant runtime has the compatible reader.

## Remaining real configuration and acceptance

FluentSMTP is installed but no transport option is configured; sender/admin emails still use local placeholders. The merchant migration blocker is removed. SMTP credentials, a verified sender/DNS and an approved recipient are still needed to verify delivery from both storefront-origin and merchant-origin operations. Shared settings alone are not delivery evidence.

HQ still independently blocks its own mail and permits outgoing HTTP only to the two exact store origins. After the owner confirms valid merchant configuration, the operator must validate and allow the **exact gateway API Host**, retaining TLS/public-address/SSRF checks, before provider acceptance. Do not automatically allow every browser `payment_hosts` entry or enable the closed historical merchant. Provider notify/return use HQ; signed store events use the management origin; the buyer's final return uses the public origin.

Actual provider session creation, notify/return, order application, amount/account reconciliation and settlement remain untested. The launch doctor's 6 fail/3 warn items still need owner configuration: payment, shipping rates, published Terms/Privacy, sender/admin email, support, use statement and checkout acknowledgement. Do not invent charges, policies, identities or content to make these checks green.

Enter credentials directly into the existing interfaces, not chat:

- Central payment: `https://vialcoveresearch.com/wp-admin/admin.php?page=pchq-payments`.
- Mail connection: `https://s0001.vialcoveresearch.com/wp-admin/options-general.php?page=fluent-mail#/connections`.

Automatic multi-store provisioning, wildcard/DNS integration, PHP-driven code upgrade/restore, automatic offsite disaster recovery and video/CDN deployment are not claimed complete. Production code remains root-owned. No new paid resource, real email, order or payment was created in this continuation.

Evidence: `D:/Codex/Tasks/2026-11-03/lightsail-resume/outputs/postcutover-http-acceptance.json`, `postcutover-runtime-audit.json`, `postcutover-certificate-renewal.json`, `merchant-migration-gate-released.json`, `post-mail-ready-http.json`, `mail-payment-deploy-audit.md` and `latest-store-release-review.md`. Parallel split/retention evidence remains in its separate task. Secrets, SQL, customer data, cookies, private backups and keys are excluded from Git. No CRK room is bound.
