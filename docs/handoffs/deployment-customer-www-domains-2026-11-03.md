# Customer www storefronts, fixed management addresses — delivered

2026-11-03 · Codex. The owner confirmed that **all newly opened customer storefronts** should use their own `www` domain, with only `@` and `www` A records supplied at opening. Assigned management addresses remain unchanged. This delivery changes the existing first store and the future opening workflow; it does not invent domains for, rename or recreate the two existing acceptance stores.

## Live addresses and unchanged identities

- Customer primary: `https://www.vialcovepeptides.com`; apex redirects there with 301.
- Merchant management: `https://s0001.vialcoveresearch.com`; HQ remains `https://vialcoveresearch.com`.
- Both customer A records already point to web01 `34.231.148.231`; no new DNS change was made by this delivery.
- First-store raw `home` is the www origin; raw `siteurl` and configured management origin retain the merchant address. Native `Domains` checks verified both new names as connected/HTTPS active and assigned the same template 684. UUID, connection key, site ID 2, accounts, database, OS/media identities and private configuration were retained.
- Web application remains `/srv/vialcove/releases/checkout-ui-4b534b1-web01`; merchant application remains `/srv/vialcove/store-admin/releases/checkout-ui-4b534b1-admin01`. No local business database was uploaded or replaced.

## Coordinated first-store cutover

A new Let's Encrypt lineage `vialcovepeptides.com` covers apex and www. Its renewal webroot is `/srv/vialcove-acme`; the old exact-host certificate and other tenants' renewals were preserved. Native aliases were checked through an ownership-probe-only maintenance vhost before changing the primary.

After publishing frozen-return compatibility, all three public TLS origins briefly returned 503, including buyer return URLs; HQ notifications and management event delivery were not gated. This full gate avoids WordPress REST method override and checkout-form handling before Woo API dispatch. The old Nginx worker epoch exited, and the ondemand web01 FPM pool had zero children and an empty listen queue in two consecutive samples. Native `Domains::set_primary`, unchanged-key `Hq_Connector::register`, same site-2 acknowledgment and a separate HQ database readback confirmed the new public origin before traffic resumed. The maintenance window was 96.24 seconds. This was a coordinated procedure, not a two-host atomic transaction.

The old customer host's ordinary query-free navigation redirects to www with 302. Query-bearing requests, native callback paths and staff/private paths retain direct routing. An initial location-scoped conditional interfered with `try_files` for an old pretty callback; actual HTTP probes exposed a Nginx 404. The final configuration moves canonical conditions to server scope and restores a plain `location /` fallback. Both pretty and query-form bare return requests now reach the native handler and reject missing authentication with 400, without a redirect. No rewrite flush or callback-specific URL rewriting was needed. Final old vhost SHA-256 is `db01270452ebf346f42a750f1aff9909b220ffa144535818ca28f1feea409ffe`.

Root-private checkpoints are under `/var/lib/vialcove-domain-cutover`; original domain options, vhosts, final configurations and receipts remain there. Do not rerun an old bootstrap to reset these deployed addresses. Retain the old bound Host, DNS/TLS and callback routing for at least seven days after the final old-host attempt, then review pending history before retirement. Token expiry never authorizes deleting/retrying unknown attempts.

## Payment compatibility and concurrent publication

The reviewed `15cd1996f1dea6034807e36e40ce07d935302683` helper validates existing authenticated `return_box` bindings and preserves their exact frozen hostname. New creation remains restricted to the current registered buyer origin. Initial identity checks retain the existing legacy 16–64-character contract; only the hostname-change exception requires a full canonical UUID and explicit HTTPS root dual-domain registration. No schema or historical payment row was rewritten.

Only two PHP files were initially overlaid onto the actual HQ capture release, preserving ProviderClient, diagnostic/sealed observers, private outbound policy and other UI changes. A fresh encrypted HQ backup and copied-entry/manifest verification preceded each overlay. The future-domain form was a separate one-file overlay. A concurrent authorized payment task subsequently published `/srv/vialcove/hq/releases/pay-provider-site-0be143e3e2c2`; it retained the helper and form, and its provider-domain trial was preserved rather than overwritten.

That peer's one existing account/store-scoped provider binding still pinned the old public hostname. Under the cooperative payment lock, an exact raw-option compare-and-swap changed **only `public_host`** to www, preserving the bare `provider_site`, site/UUID/account binding and every other field. Native pure `provider_site` resolution confirmed the same bare root without creating a request. Its private rollback snapshot is under `/var/lib/vialcove-deploy/customer-public-host-binding-1793682669176526534`. The private shared configuration remained byte-identical. HQ still blocks arbitrary www outbound HTTP; signed requests and monitoring use the allowed management origin.

Read-only validation used the four already saved return boxes in memory against the projected/current www registration and proved byte-identical returns, old-host rejection for new creation and valid new-host targets. Financial rows were unchanged during each projection probe; the known unknown attempts were not unlocked, retried or replaced. This domain delivery is **not** payment-provider acceptance, settlement, email acceptance or a real signed browser-return test.

## Future store opening: installed

Source `da92dfe4cac6c345454058512e4cb876e5d9a5c3` is installed on both hosts at `/usr/local/lib/vialcove-provision/{worker.py,backup.py,install-operations.py}`. The owner supplies the customer's root domain; the v2 job displays only two A records, `@` and `www`, to the configured web IPv4 with TTL 600. It waits for correct DNS before account/database/runtime creation. Public URLs become `https://www.<customer-domain>`; management addresses and immutable numbered internal identities keep their existing rules.

Root-private, immutable domain claims prevent duplicate automatic assignments, including case-insensitive existing-vhost collisions. The real first-store base was imported as an idempotent legacy reservation on both hosts using the verified existing UUID over stdin. No fake first-store job was created. Claim bytes match across hosts; profile, queue, old completed jobs/results and legacy ledgers retained bytes, ownership and modes. Existing v1 completed replays remain readable; new/unfinished v1 jobs require an explicit customer domain instead of silently receiving a numbered buyer address.

The previously active provisioning timer was paused for publication, then resumed only after native HQ form rendering confirmed the required customer-domain field and record instructions. A new empty-queue run succeeded. The operations installer was not executed, and no new store, VM, database, paid resource or merchant credential was created for testing. This has no fresh-customer-install end-to-end acceptance claim.

## Actual verification and limits

- Merged source: 132 frozen-return, 33 management-address and 44 provider-binding pure checks; three runtime PHP lint checks. Synthetic boundaries only, no WordPress/database/provider bootstrap.
- Installed workers: each Linux host passed 22 synthetic tests and three compilations. Future generated vhost passed actual isolated Nginx parsing with an existing certificate fixture; no fresh certificate/store was created by that test.
- Native HQ form rendered required domain/two-record/fixed-management instructions without submission; old completed results and timer operation passed.
- Cookie-free public GETs: www home 200, 270 www navigation links and zero old-origin navigation links, apex 301, old home 302, both old bare callback formats 400, staff/admin paths 403, private config/reports 404, merchant/HQ logins 200. Empty checkout correctly redirected to the www cart; no cart/order was created to test visual checkout.
- New-origin `site.css` and `checkout.css` are 200 and exactly match the published `4b534b1` UI blobs. Valid HTTPS was verified for both new names; unknown SNI and mismatched Host/SNI were rejected without application dispatch. The first guard harness expected a HTTP/1 curl exit while negotiating HTTP/2; forcing HTTP/1.1 made the expected empty-response result explicit, without changing the server.
- Independent final preservation audit confirms the concurrent HQ source, observers/private configuration, all three existing store identities/managers, both worker/claim installations, existing job results and merchant/web code pointers. It separately rechecked the final provider public-host alignment.

Task evidence and scripts: `D:/Codex/Tasks/2026-11-03/store-domain-cutover/{work,outputs}`. Key receipts: `public-domains-verified.json`, `old-native-return-routing-repaired.json`, `hq-primary-readback-before-public.json`, `web-primary-cutover-complete.json`, `provider-public-host-metadata-aligned.json`, `final-deployment-preservation-audit.json`, `merged-model-tests.json`, `provisioning-installed-operations-report.md` and `DELIVERY.json`. No credentials, tokens, raw customer records or private option snapshots belong in Git. Whole-main integration does not mean the entire main branch was deployed.
