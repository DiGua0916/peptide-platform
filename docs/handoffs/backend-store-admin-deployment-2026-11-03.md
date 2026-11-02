# Store management runtime split — deployed first store

2026-11-03 · Codex. User confirmed HQ and merchant administration/design should run on the administration VM, while the existing VM serves customer storefronts. This supersedes the earlier deployment assumption that HQ was the administration VM's only application.

The first store now uses a dedicated Linux identity/FPM pool and runtime on the administration VM. Its fixed management origin is `https://s0001.vialcoveresearch.com`; customer home stays `https://s0001.vialcovepeptides.com`. HQ remains separate at `https://vialcoveresearch.com`.

Both store runtimes use the existing single store database through a dedicated, source-restricted SSH local forward and separate database user. Media/reports use restricted, chrooted SFTP exports and SSHFS mounts. No database port was opened publicly; SSH host identities remain pinned. These are shared state, not two independently writable database copies. The storefront VM remains authoritative for this store's database and media. Distinct future stores still need independent databases/identities.

The database tunnel and both mounts are persistent systemd services. A missing mount blocks the administration application with HTTP503. Actual mount-stop/recovery and database-channel restart checks passed. This introduces a dependency on the storefront host/network for merchant administration; it is not high availability or a performance/load-test claim.

Updated both store runtimes with the 11 core broadcast files between `8c1daedd` and `c258436`, verifying old/new hashes and PHP syntax, and changing file modification times for cache invalidation. No peer worktree was changed. Existing store UUID, salts, users, product/design data and home were preserved; the fixed management constant and DB siteurl changed together. HQ row 2 was updated only after UUID verification, and its exact outgoing management host was allowed.

Actual native authenticated HTTP checks passed for admin, products, template library, the existing home document editor, the cookie+nonce layout API and same-origin preview. Replayed staff cookies on the public host were refused. Signed HQ monitor reported online; a real HQ-issued one-use login ticket reached authenticated merchant administration and that new session was logged out. This is not a claim that the unknown original password was tested.

Before switching, database/config backups were encrypted. A real isolated restore of the store backup passed with 71 tables, 2 users and 82 published products; only the generated restore-check database was dropped. No real checkout/order/payment or email was submitted by this task.

`tools/deployment/mu-plugins/vialcove-managed-login.php` preserves the user's Chinese login preference only on the configured staff management host, without changing the public customer login, credentials or cookie duration. It removes language selection, return-to-site link and the obstructive remember tooltip.

Evidence and protected deployment work live in `D:/Codex/Tasks/2026-11-03/store-admin-deployment`; secrets, private keys, source config, archives, cookies, nonce and SQL remain outside Git. No room binding.

## Final verified state

- Integrated the concurrent privacy-feature and deployment handoffs normally. Both store runtimes now have all 435 production Core files byte-matching `6f20b62a40cf7a68a15c1f396952cff810f4bb79`, including the 7-file privacy increment. Prior runtime paths remain for rollback. Non-Core storefront components were preserved. HQ itself remains on its separate `c258436` runtime; this does not claim every component was rebuilt from the latest documentation commit.
- Storefront current: `/srv/vialcove/releases/6f20b62-web01`. Merchant current: `/srv/vialcove/store-admin/releases/6f20b62-admin01`. Merchant MU login overlay matches the versioned deployment file. Request-path scoping fixes the early MU-bootstrap timing; both `zh-CN` and `zh-Hans` are valid Chinese document tags. Main labels are Chinese, with no language dropdown, return link or Remember Me popup. Actual final login screenshot is `outputs/store-admin-login-final.png`; the original English screenshot is superseded.
- Repeated the native authenticated page/REST/preview/public-cookie-isolation checks after the final Core update: 8/8. HQ-issued one-use login was consumed to reach real merchant admin and logged out. The dedicated store-2 monitor runs every five minutes, with a successful actual run; legacy store-row polling was not broadened.
- New management certificate issued and real renewal dry-run passed. Exact Host/SNI are required; PHP code/config/private files remain protected. Both channel restart and missing-report-mount recovery passed. This does not claim a whole-machine reboot or load test of the new split.
- Both daily encrypted backup jobs actually completed: store database/config/media/report archive 1,370,497,912 bytes, administration configuration/channel-key archive 20,680 bytes. Both encrypted files were copied to protected D-drive storage and SHA256 verified. The daily timers run at UTC19:40 plus up to five minutes, keeping the last 7 successful scheduled archives; manual cutover backups in their separate directory remain untouched. Automatic offsite replication/alerts are not configured. The recovery identity for these two jobs is the existing protected HQ backup identity, not the separate identity used by the concurrent storefront deployment task.
- Concurrent storefront deployment subsequently removed its own migration gate and enabled its curated cron; this task preserved those changes. Merchant runtime retains its migration/outgoing safety gate. Real merchant password login, browser JavaScript editing/publish interaction, full new-split reboot/load acceptance, automatic multi-store creation, wildcard/DNS API integration, SMTP delivery and real payment acceptance remain unverified or unconfigured. Root-owned production code is intentionally not writable by the PHP app; this is not a claim that self-service Ops upgrade/restore/provisioning is enabled.

## Operational entry points

- HQ: `https://vialcoveresearch.com/wp-admin/` → Stores → enter the registered first store (row 2).
- Merchant admin: `https://s0001.vialcoveresearch.com/wp-admin/`.
- Theme/design library: merchant `wp-admin/admin.php?page=pc-store-design`; existing home editor document is 685, template 684. No real design was saved/published by deployment QA.
- Customer storefront: `https://s0001.vialcovepeptides.com/`. Its `/wp-admin/` returning403 is intentional; do not restore the old public-origin admin login.
