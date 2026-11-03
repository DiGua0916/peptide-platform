# Retire the old buyer origin; retain exact historical returns

2026-11-03 · Codex. The owner confirmed that only the former customer origin `s0001.vialcovepeptides.com` should close. A subsequent proposed platform-domain reassignment was withdrawn by the owner as a naming mistake. Keep HQ `https://vialcoveresearch.com`, merchant management `https://s0001.vialcoveresearch.com` and customer primary `https://www.vialcovepeptides.com`. Neither DNS nor these active identities was migrated.

## Delivered behavior

The old Host no longer serves ordinary storefront pages, static files, checkout or generic PHP. All ordinary GET/HEAD requests return 301 to the same path and query at the fixed www origin; non-GET/HEAD requests return 410. This canonical redirect also carries native paid receipts/payment/home links generated during an old buyer return to the current customer origin. It does not forward old checkout writes.

Only GET at the exact raw `/wc-api/pc_panapay_return`, its trailing-slash form, and root with one literal `wc-api=pc_panapay_return` selector may enter the existing native return handler. Whole-query key whitelists, per-key duplicate rejection, raw-path checks, body/transfer/method-override checks and fixed front-controller routing reject alternate REST/form dispatch. Pretty callbacks receive exactly one internal selector; root callbacks retain their original query. FastCGI passes no request headers or body, clears content metadata and remote user, fixes the original Host and script, and supplies each CGI key once. The native opaque-token, provider-signature, order/reference and seven-day checks remain unchanged. No valid token was invented or used in testing.

The original HTTP ACME route, HTTPS certificate, SNI/Host restrictions and default unknown-host rejection remain. HTTP ordinary requests first redirect to old HTTPS and then to www. The Core bound alias is intentionally retained for historical return authentication; it is no longer a storefront entry point. No business data, historical payment record, account, key, UUID or merchant configuration was deleted.

Only `/etc/nginx/sites-available/vialcove-web01-bootstrap.conf` and one cron URL changed. The final vhost SHA-256 is `c85c43f52ddaad9281cdb6d4bb0905b3e2036263d625ba0ca5691b877d363be7`. `/usr/local/bin/vialcove-web01-cron` now uses the www CLI URL; its storage wrapper, lock, path and due-event command remain unchanged. Cron SHA-256 is `82d60ac081a5953240f2520ea216e6a5a19818c9c5c82345375c8dc48e34682c`. The task did not invoke cron. Service unit, backup entry point, current application, private configuration and the new www/apex vhost were verified unchanged. Root-private prior files and receipt are under `/var/lib/vialcove-domain-cutover/retired-customer-host-1793687393517471807`.

## DNS retirement boundary

Read-only aggregate inspection found four saved old-host browser returns, all pretty URLs, with the last issued at `2026-11-03T04:33:56Z`; the latest browser-token expiry is **2026-11-10 12:33:56 Asia/Shanghai**. The encrypted URL values, references, tokens, order IDs and UUID were not output. Keep the `s0001` DNS record under **vialcovepeptides.com** and its exact callback/TLS route until that boundary and a fresh review before removal. No automatic deletion was scheduled. Unknown attempts and financial outbox history must never be cleared just because browser tokens expire. The management `s0001` under **vialcoveresearch.com** must remain.

## Actual verification

- Independent static review accepted the generated callback-only candidate; the final source additionally clears `REMOTE_USER` as suggested. Actual isolated and live Nginx syntax checks and reload passed, with guarded file snapshots/ordinary failure rollback.
- **31 actual HTTP routing checks** passed: ordinary page/checkout/query/static/private/staff URLs and HEAD canonical redirects; old checkout writes refused; three bare native return forms reject missing authentication with 400 and no redirect; HEAD/POST/method override/GET body, extra/duplicate/encoded query keys, semicolon injection and normalized alternate paths refused with 410.
- The www customer home, merchant login and HQ login each returned 200. No user browser, cart, checkout form, real signed callback or provider-creation/mail operation was used. These checks do not prove successful collection, notification or settlement.
- The old-host token aggregate and cron/native origin checks were read-only; candidate shell syntax passed without executing cron. No application source or database mutation was part of this task.

Evidence/scripts: `D:/Codex/Tasks/2026-11-03/retire-old-customer-host/{work,outputs}`. Key receipts: `old-host-retired.json`, `retired-host-routing-verified.json`, `old-callback-aggregate.json`, `cron-retirement-readonly.json`, `retirement-static-review.md` and `DELIVERY.json`. The earlier first-store cutover document's broad old-host alias routing is superseded by this restricted retirement configuration. Do not reapply an old bootstrap and silently reopen that origin.
