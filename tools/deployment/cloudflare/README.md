# CDN configuration snapshots

On 2026-11-03 the owner authorized CDN coverage for the customer storefront, HQ and the first merchant administration site. The origin Nginx configurations below were deployed, and both v2 Cloudflare bypass expressions were deployed in the UI. These repository copies support reproduction and review; checking out or pulling them does **not** deploy anything.

Scope: `vialcovepeptides.com` / `www.vialcovepeptides.com`, and `vialcoveresearch.com` / its `www` redirect / `s0001.vialcoveresearch.com`. The retired buyer hostname, QA hosts and wildcard stay DNS-only. Do not infer permission to proxy other hosts or replace their configurations.

## Origin files

| Repository file | Installed destination |
| --- | --- |
| `../nginx/cloudflare-realip.conf` | Both VMs: `/etc/nginx/conf.d/vialcove-cloudflare-realip.conf` in the HTTP context |
| `../nginx/canonical-client-ip.conf` | Both VMs: `/etc/nginx/snippets/vialcove-canonical-client-ip.conf` |
| `../nginx/admin-source-asset-cache.conf` | Admin VM only: `/etc/nginx/conf.d/vialcove-source-asset-cache.conf` |

Include the canonical client-IP snippet immediately after `include fastcgi_params;` in each reviewed ordinary PHP/login location, before its FastCGI upstream. Keep the existing `REMOTE_ADDR $remote_addr` parameter. The installed positions are the storefront and QA PHP locations on web, and the shared HQ app snippet plus merchant/QA PHP locations on admin. HQ's app snippet is shared by direct TLS and its loopback 8081 listener; do not invent another proxy hop or trust loopback in production.

Do not add this HTTP-header snippet to the retired buyer's three callback locations: their request-header/body forwarding remains off and their existing `REMOTE_ADDR` already inherits Nginx realip. Preserve exact Host/SNI, TLS, routing, private-file guards, existing cache maps and HTTP/2 settings; whole vhosts are intentionally not included here.

The realip file contains the official Cloudflare IPv4/IPv6 list retrieved on 2026-11-03 from [ips-v4](https://www.cloudflare.com/ips-v4) and [ips-v6](https://www.cloudflare.com/ips-v6). Refresh from those official HTTPS resources when updating the snapshot, validate CIDR family/format and review the change on both VMs. Never replace it with catch-all or localhost trust. This restores trusted client IPs; it does not restrict the firewall to Cloudflare, hide the origin IP or authenticate a particular zone.

The admin map shares only versioned physical CSS/JS with successful status/MIME/path conditions. Defaults remain private/no-store; source fonts/images keep their private TTL. Do not broaden it to dynamic HTML, PHP, JSON, REST, AJAX, uploads or reports.

## Cloudflare rules and updates

- `peptides-cache-bypass-v2.txt`: storefront **Bypass cache** expression.
- `research-cache-bypass-v2.txt`: HQ/merchant **Bypass cache** expression, with a narrow public physical CSS/JS exception.

Use the expressions as bypass rules, not Cache Everything or an edge TTL override of private/no-store/Set-Cookie. Respect origin TTL and retain the default full query-string cache key. In particular, do not enable ignore-query or normalize away `ver`; bump the asset version when its bytes change and purge exact old URLs when withdrawing a release. The text files use repository LF line endings.

New stores must first keep customer DNS grey/DNS-only while the existing worker verifies the fixed origin A records and completes native setup/TLS. Enable CDN afterward through the reviewed process; do not proxy the wildcard or change the worker's DNS contract as a side effect.

Before any later application, capture fresh target hashes/current pointers/private-config guards and merge only the necessary snippets under the existing root deployment locks. Run Nginx syntax and appropriate HTTP cache/IP checks; preserve peer changes and keep a matching rollback checkpoint. This directory supplies no automatic installer, credentials, zone IDs or authoritative DNS values.
