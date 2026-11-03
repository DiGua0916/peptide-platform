# Cloudflare CDN for the customer shop and two admin entrances

2026-11-03 · Codex. User authorized CDN for the customer shop, HQ and merchant admin. Cloudflare Free was selected for both zones. Origin configuration, zone settings, nameserver changes and the five production proxy records are published. Final edge acceptance passed, with the pre-existing unavailable HQ Ops GET route documented below.

## Published routing

| Zone / record | Origin IPv4 | Final proxy state |
| --- | --- | --- |
| `vialcovepeptides.com`, `www` | `34.231.148.231` | Proxied |
| peptides `s0001`, `s0002`, `*` | same web origin | DNS only |
| `vialcoveresearch.com`, `www`, `s0001` | `54.144.250.46` | Proxied |
| research `s0002`, `*` | same admin origin | DNS only |

Customer canonical URL remains `https://www.vialcovepeptides.com`; HQ remains `https://vialcoveresearch.com`; merchant admin remains `https://s0001.vialcoveresearch.com`. Existing apex/www redirects remain origin-owned. No application identity, database, credential, merchant configuration or public address was reassigned.

Both registrar NS pairs were changed through Tencent Cloud to `magdalena.ns.cloudflare.com` and `marty.ns.cloudflare.com`, with the user completing Tencent's MFA. The prior pair was `gnat.dnspod.net` and `scales.dnspod.net`. Both original DNSPod zones were inspected in full: exactly five enabled default-line A records each, with no MX/TXT/CNAME/AAAA or additional records. All ten were imported and checked against their respective origin. Original DNSPod records were retained, with BIND backups on D. Cloudflare DNS-only TTL is Auto; do not claim the previous literal 600-second TTL was preserved.

Parent DS queries were empty before migration; no existing DNSSEC setting was disabled. To avoid an HTTPS interruption, all imported records were explicitly DNS-only during NS activation. Both Universal certificates then showed **Active**, including the zone apex and wildcard, before any production record was re-proxied. Backup certificates showed Backup Issued. Both zones use **Full (strict)**. Bot Fight Mode and Under Attack Mode were inspected as false and left false; existing standard protections were retained.

## Source configuration and reproducible artifacts

The user's deployment authorization covers the two existing origins and three current entrances. The committed Nginx files and cache expressions reproduce the deployed policy; adding them to Git does not automatically install them or deploy the rest of main.

Two origins received the same official Cloudflare real-IP list (15 IPv4 and seven IPv6 CIDRs) and a canonical FastCGI IP-header snippet. Only a directly connected peer in those published ranges can supply `CF-Connecting-IP`. Production trust does not include loopback, private ranges or a catch-all. Ordinary PHP locations explicitly pass X-Real-IP, X-Forwarded-For and CF-Connecting-IP as the canonical `$remote_addr`, with unique FastCGI parameters, because Woo can prioritize the legacy headers.

Eleven Nginx files changed across the two servers: web five, admin six. The old buyer callback vhost was preserved byte-for-byte, including its request-header/body restrictions. Admin's shared HQ snippet serves 443 and the existing loopback 8081 listener; no second trusted proxy hop was invented. Actual nginx configuration checks and graceful reloads passed. TLS, H2, firewalls, private configs and concurrent peer application pointers were preserved.

| Change | Root-private checkpoint |
| --- | --- |
| Web real-IP | `/var/lib/vialcove-cdn-origin-deploy/realip-1793693431688017061` |
| Admin real-IP | `/var/lib/vialcove-cdn-origin-deploy/realip-1793693459806008283` |
| Admin public code cache map | `/var/lib/vialcove-cdn-origin-deploy/public-static-1793694957977865287` |

The existing admin volatile source cache map changed only six result values for successful physical CSS/JS responses to `public, max-age=86400, s-maxage=86400`. Method/status/MIME/path/version selectors and private font/image/default branches were retained. Final deployed SHA-256: `61a516ddbccb7fcd2c037b0669f29c46abb791bd3ebc61fe0f6e48e4e64d9ac9`.

An initial immediate post-HUP check saw private headers and rolled back only this map. A subsequent bounded readiness check observed the old private response transition to the new public response and passed unchanged final assertions. Original failure and rollback receipts remain available; do not describe every attempt as successful.

Web's old `/var/lib/vialcove-deploy` parent is a deployment-user HOME and was not chmod'ed. The initial real-IP preflight stopped before writes. Use the root-private CDN publication lock plus the domain-cutover lock; admin uses its existing root global Nginx and CDN locks. Re-read current configuration and peer pointers before any subsequent publication. Do not restore whole checkpoint directories over newer work.

## Edge cache policy

Each zone has exactly one active **Bypass cache** Cache Rule using the versioned expression in `tools/deployment/cloudflare/`. There are no Cache Response Rules. No Cache Everything, ignored query string or blanket override of origin no-store was introduced.

- Customer zone permits default caching only for the allowlisted public upload images and code-owned static paths/extensions. Authorization, login/cart/session cookies, private uploads and sensitive query selectors bypass cache. Ordinary visitor/marketing cookies do not globally disable static caching.
- Admin zone permits default caching only for allowlisted code-owned CSS/JS with a sole nonempty `ver=` query. Origin still enforces its stricter physical file/MIME/version constraints. Admin HTML, API, private uploads/reports and other assets bypass shared caching. Public code bytes can be reused across sessions; account and order pages cannot.
- Default full query-string cache keys remain intact. Continue advancing `?ver=` when code changes; do not overwrite versioned bytes and expect long-lived caches to refresh immediately. Purge exact affected URLs if a release must withdraw already-public bytes.

## Validation

Origin acceptance completed before proxying: each host passed 12 isolated native Linux FastCGI/IP cases, preserving multi-value parameter arrays to prove uniqueness and rejection of duplicate/mixed-case spoofed headers. Trusted-loopback test cases existed only in the isolated fixture. Four real admin CSS/JS files passed GET 200, conditional 304, HEAD and synthetic-cookie body/header checks. Eighteen negative HEAD cases and native REST JSON checks remained private/no-store. Three main public HTTPS entrances remained healthy. Bare retired callbacks remained 400; retired checkout POST remained 410. No valid payment token, order, notification, mail or cron was executed by this task.

Initial ordinary public probes showed mixed DNS propagation, with some requests at Cloudflare and others still directly at Nginx. This is retained as pending/mixed evidence, not mislabeled a cache failure. The first bounded directed test used a publicly observed Cloudflare A address inside the official ranges, original Host/SNI and normal CA validation. Customer PNG/CSS returned HIT with Age; visitor-cookie image remained HIT; synthetic customer auth/cart cookies returned DYNAMIC. Several local cross-border transfers timed out after valid TLS/headers, so transport completion and TLS validation are tracked independently.

Final AWS acceptance closed all six complete bodies: customer PNG/CSS, HQ CSS/JS and merchant CSS/JS each returned MISS then HIT, with SHA-256 matching a fresh same-round origin GET. All selected peers were observed public Cloudflare addresses in the official ranges, original Host/SNI and normal CA validation were preserved, and TLS validation and complete body transfer passed independently. Static responses had no Set-Cookie. The visitor-cookie image remained HIT, customer synthetic auth/cart/Authorization requests were DYNAMIC, and public admin code with a synthetic login cookie remained HIT.

Pages, empty cart/checkout, account/login, JSON, private paths and invalid/unversioned admin assets were never shared-cache HIT and showed no interactive challenge. The actual eight-character synthetic DomainProbe returned 200/text/plain/32-hex at both origin and edge; its public echo matched in memory and was not retained. Merchant unsigned status rejected with 401; the same management route on the customer host rejected with 403. Both NS pairs were observed at Cloudflare by Google DoH from AWS, and all five production A lookups were Cloudflare addresses; QA/old buyer/wildcard remained direct origin records.

The original full receipt keeps `all_checks_passed=false`: its sole failed assumption expected HQ Ops GET `/?rest_route=/peptide-ops/v1/status` to return 401, but both current origin and edge return 404 JSON/private/no-store because that GET route is unavailable. No route was installed to make a test pass. A bounded supplement verified the existing native WordPress `/?rest_route=/wp/v2/settings` rejects anonymous GET with 401 at both origin and edge, with DYNAMIC/no challenge. This is generic GET authorization evidence, not a signed HQ payment POST test. The final adjudication preserves the raw failure and identifies no CDN blocker.

A unique, Nginx-denied HQ `.zip` URI marker was requested through Cloudflare, then only its exact origin-log match was inspected. The log restored the requesting AWS public IP instead of a Cloudflare peer; no customer log lines or full requests were retained. Web and merchant access logs were already off and stayed off, so no per-request live source-log observation is claimed for those two entrances. Their isolated FastCGI tests and loaded configuration checks remain distinct evidence. No diagnostic endpoint was introduced.

Final receipts: `aws-full-observed-edge-acceptance.json`, `hq-get-auth-edge-supplement.json`, `hq-own-cf-realip-marker.json` and `cdn-independent-final-acceptance.json`. Local cross-border timeout/mixed-DNS receipts remain unchanged. These checks do not promise all ISP caches have propagated, a universal speed improvement, or successful real payment/mail delivery; signed payment POST, real callbacks, orders and mail were not exercised.

## Operating boundaries and rollback

- Future provisioning still requires an exact origin A and no AAAA. New/unfinished stores, QA and wildcard records stay DNS-only for installation and certificate issuance. This task did not weaken the worker's ownership checks or refresh its template snapshot.
- Old `s0001.vialcovepeptides.com` stays DNS-only for frozen historical buyer returns; ordinary pages remain retired. Recheck the recorded return expiry before removing that DNS record. Expiry never authorizes deleting unknown financial history.
- Origin 80/443 remains publicly reachable. CIDR trust restores IP addresses; it does not authenticate this Cloudflare account or conceal a known source IP. Origin bypass prevention/mTLS was not deployed.
- If edge behavior breaks, set only the affected production A record to DNS-only with its recorded origin. Normal CA-valid origin HTTPS remains available. NS rollback uses the recorded original pair and remains subject to propagation; do not delete either zone or application data.
- Source rollback must hold the cooperative locks, verify that the current inode/SHA still belongs to this change, and restore only its target files followed by nginx -t/reload. The admin map reverting to private does not recall already cached edge/browser objects; purge affected URLs when appropriate.
- The future 71 MB video is outside this cache allowlist. A separate video delivery service/domain and Range/cache acceptance remain separate work; this task makes no promise of unlimited video delivery on Free.

Local audit artifacts are under `D:/Codex/Tasks/2026-11-03/customer-cdn/`: origin publication receipts, isolated fixture receipts, exact config manifests, BIND backups, actual UI screenshots and read-only edge/DNS acceptance JSON. No secrets, real customer data, private payment URLs or raw application logs are committed.
