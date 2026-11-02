# Store management runtime split — deployment checkpoint

2026-11-03 · Codex. User confirmed HQ and merchant administration/design should run on the administration VM, while the existing VM serves customer storefronts. This supersedes the earlier deployment assumption that HQ was the administration VM's only application.

The first store now uses a dedicated Linux identity/FPM pool and runtime on the administration VM. Its fixed management origin is `https://s0001.vialcoveresearch.com`; customer home stays `https://s0001.vialcovepeptides.com`. HQ remains separate at `https://vialcoveresearch.com`.

Both store runtimes use the existing single store database through a dedicated, source-restricted SSH local forward and separate database user. Media/reports use restricted, chrooted SFTP exports and SSHFS mounts. No database port was opened publicly; SSH host identities remain pinned. These are shared state, not two independently writable database copies. The storefront VM remains authoritative for this store's database and media. Distinct future stores still need independent databases/identities.

The database tunnel and both mounts are persistent systemd services. A missing mount blocks the administration application with HTTP503. Actual mount-stop/recovery and database-channel restart checks passed. This introduces a dependency on the storefront host/network for merchant administration; it is not high availability or a performance/load-test claim.

Updated both store runtimes with the 11 core broadcast files between `8c1daedd` and `c258436`, verifying old/new hashes and PHP syntax, and changing file modification times for cache invalidation. No peer worktree was changed. Existing store UUID, salts, users, product/design data and home were preserved; the fixed management constant and DB siteurl changed together. HQ row 2 was updated only after UUID verification, and its exact outgoing management host was allowed.

Actual native authenticated HTTP checks passed for admin, products, template library, the existing home document editor, the cookie+nonce layout API and same-origin preview. Replayed staff cookies on the public host were refused. Signed HQ monitor reported online; a real HQ-issued one-use login ticket reached authenticated merchant administration and that new session was logged out. This is not a claim that the unknown original password was tested.

Before switching, database/config backups were encrypted. A real isolated restore of the store backup passed with 71 tables, 2 users and 82 published products; only the generated restore-check database was dropped. Daily encrypted store-data and administration-config backup timers have been installed. Full backup completion, browser JavaScript editor interaction, automatic multi-store provisioning, DNS API integration, outgoing mail and real payments are not yet asserted by this checkpoint. Existing migration/outgoing guards remain; no real checkout/order/payment or email was submitted.

`tools/deployment/mu-plugins/vialcove-managed-login.php` preserves the user's Chinese login preference only on the configured staff management host, without changing the public customer login, credentials or cookie duration. It removes language selection, return-to-site link and the obstructive remember tooltip.

Evidence and protected deployment work live in `D:/Codex/Tasks/2026-11-03/store-admin-deployment`; secrets, private keys, source config, archives, cookies, nonce and SQL remain outside Git. No room binding.
