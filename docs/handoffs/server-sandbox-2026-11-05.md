# Server sandbox — 2026-11-05

Author: Codex. User requested a server-side sandbox as the default destination for subsequent tests. This supersedes using production or shared LocalWP for business-flow testing.

## Entry points

- HQ: https://sandbox.vialcoveresearch.com/wp-admin/admin.php?page=pchq
- Store administrator: https://sandbox-admin.vialcoveresearch.com/wp-admin/admin.php?page=pc-overview
- Buyer storefront: https://sandbox-shop.vialcoveresearch.com/

All three are on the existing administrator server, with a new TLS certificate and HTTP Basic access protection. The shop and administrator share one sandbox store database/identity, matching the dual-domain architecture. HQ has its own database, database account and salts. Credentials are outside Git at D:/Codex/Credentials/server-sandbox/沙盒访问账号.txt. Never copy these credentials into handoffs or messages.

## Isolation and baseline

Sandbox root: /srv/vialcove-sandbox. Private installation state and backups: /var/lib/vialcove-sandbox. Separate PHP service: vialcove-sandbox-php.service. Two sandbox-only MySQL identities are granted only their respective databases. New sandbox UUID, registration secret, HMAC key, administrator account and salts; no production credentials are imported.

Code was copied from the current production checkout-return-fixes runtime, excluding wp-config, MU plugins, caches and credentials. This is a baseline environment, NOT the newer integration branch or the frontend performance candidate. Do not advertise the newer mobile fixes as deployed here yet.

Selective catalog import includes 83 published products, variations, public page/template content and 219 explicitly public-library report records. Report posts are draft by design; publish count zero is not the archive total. There are 438 referenced private report/thumbnail files. Media are actual sandbox copies, not symlinks/hardlinks to production. Real customer, user, session, tracking, order, payment-ledger, webhook and HQ-site tables were not imported. One new sandbox administrator exists; native order query returns zero.

MU guard intercepts wp_mail and rejects WordPress HTTP destinations except the three exact HTTPS sandbox origins. The actual PHP service also blocks raw external sockets using systemd IPAddressDeny=any / IPAddressAllow=localhost. Production filesystem paths are inaccessible, PHP open_basedir restricts file access, and process execution/mail functions are disabled. Internal sandbox HTTPS resolves to loopback and retains application HMAC signatures. Public Basic authentication cannot be bypassed externally; loopback access is allowed for native internal requests.

Cron and automatic updates are disabled. No production provision queue or worker is configured. Checkout allows only native offline COD labelled Sandbox test — no charge. No real payment-provider credentials or external email service are installed. Real provider sandbox callbacks/API and email delivery require a separate controlled configuration phase; no real-payment or mail-delivery acceptance is claimed.

Same physical server is shared: this is filesystem/database/process isolation, not another VM. PHP has CPUQuota=75% (0.75 logical CPU), MemoryHigh=768M, MemoryMax=1G and two children per role. Do not compare sandbox wall-clock performance directly to unrestricted production or run unlimited stress tests.

## Actual checks

Receipt: D:/Codex/Tasks/2026-11-05/server-sandbox/outputs/verification.json; server-side verification.json, delivery-verification.json and report-verification.json under the private state root.

- Three external unauthenticated requests: HTTP 401, valid TLS (curl verify result 0).
- Three server-side allowed requests: HTTP 200, valid TLS.
- Both fresh sandbox WordPress logins: POST 302, authenticated administration page 200.
- Native store registration and HQ signed status request succeed; HQ has exactly one sandbox shop with the sandbox management/public origins.
- Actual restricted FPM probe: staging environment; mail interception true; production WordPress HTTP blocked; raw external socket blocked; production config unreadable; cross-database read denied; Management_Host configuration_error empty.
- Report archive: total 219, selected page one item; referenced PDF HEAD 200. Original laboratory file contents were not edited. This is HTTP delivery verification, not iPhone canvas/rendering acceptance.
- Temporary diagnostic PHP and authentication cookie files removed after checks.

An initial import used an incorrect public-meta key and an initial file filter treated serialized file metadata as filenames. These sandbox-only issues were corrected: import uses _pc_report_library_public and file selection uses each metadata array's stored field. Final archive and file checks above supersede the zero-count intermediate receipt. Production records/files were unchanged.

## Workflow and rollback

All subsequent business tests should use these sandbox origins, new test identities and synthetic orders. Apply reviewed candidates here first, keep a scoped manifest, validate mobile/back-forward/cart/COA behaviour, then separately release accepted changes. Do not run test writes against production or reset production as test cleanup. Environment guards must never be deployed into production.

Initial and ready snapshots are under /var/lib/vialcove-sandbox/backups; the final ready-backup.json records the completed snapshot path and sizes. Reset is sandbox-only and must first verify exact sandbox paths and database names. To stop this environment without touching production, stop vialcove-sandbox-php.service and disable only the vialcove-sandbox Nginx site after nginx -t. Existing production site configurations/releases were not rewritten.
