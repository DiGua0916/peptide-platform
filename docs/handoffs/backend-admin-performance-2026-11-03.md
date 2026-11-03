# Backend navigation performance

2026-11-03 Codex. The owner reports both deployed backends wait and flash during navigation. Their requested outcome is faster normal use on the existing formal sites. No separate test environment or customer/business data manipulation is required.

## Measured causes

Authenticated loopback SNI measurements (three GETs per route) give median first-byte time of 0.341s for overview, 0.454s for products, 0.308s for domains and 0.302s for data. The bounded existing-administrator measurement session was destroyed. No order or payment action was submitted.

The actual owner's in-app domain page took 0.577s to first byte and 5.248s to load in one baseline capture. It fetched large command-palette dependencies including block-editor, components and core-data on this operational page. Actual resource entries and their transferred byte counts are retained in private task evidence; the baseline is a single browser sample, not a percentile or load test.

Both HQ and the customer backend send no-store for source assets. HQ source CSS/JS was not gzip compressed. Server resource sampling showed spare CPU and RAM, not saturation. Schema validation measured 8.46–9.38ms for eight metadata queries; registration matches the new public www and fixed management URLs without errors. Neither is changed by this performance patch.

## Changes

- Reuse Core page-owned table scrolling containers without moving their tables after first render. Native list fallback wrapping and editor/React ownership remain intact.
- At enqueue priority 1000, remove optional command-palette roots plus the globally enqueued `wc-entities` and `wp-abilities` roots on the three measured Core operational pages (overview, domains, data). HQ's own non-editor pages omit only its core palette roots. This omits the Ctrl+K convenience there. Native menus, Woo routes, product/order/user editors and the decoration editor keep their normal queues. Shared editor/data packages remain registered and load if any remaining feature depends on them, including a legitimate feature that explicitly depends on either optional root.
- The deployment asset map at `tools/deployment/nginx/admin-source-asset-cache.conf` permits a one-day **private browser cache** only for explicit code-owned asset directories, valid source MIME types, successful GET/HEAD responses and versioned CSS/JS. Fonts/source images can use the same bounded TTL without a version query. HTML/PHP, REST, AJAX, uploaded media, reports and JSON remain no-store. No CDN/public administrative-page cache is enabled.
- The formal Nginx overlay changes only two Cache-Control directives and adds HQ source gzip types, preserving routing, TLS/SNI/Host/authentication guards and inherited security headers. The map is installed before its references and checked with Nginx before reload.

## Source verification

Actual production JS passes 21 pure DOM checks for scroll-owner reuse, accessibility and unaffected native/React paths. Actual PHP enqueue methods with native WP_Dependencies and the installed asset manifests pass 44 dependency-closure/guard checks plus two iframe checks. PHP/JS syntax and diff whitespace checks pass. These models are not claimed as browser or real-provider transaction verification.

The first actual post-deployment browser run proved that removing palette roots alone was insufficient: Woo's globally enqueued `wc-entities` and `wp-abilities` independently restored core-data/block-editor. This was observed in the real waterfall and then traced to the installed official manifests; the second scoped revision removes these otherwise-unused roots on the same three operational pages. Shared dependency packages are never forcibly dequeued, and the model covers independent feature consumers retaining the full dependency chain. No first-pass speedup is claimed; its observed load was 5.665s with network variation.

Evidence and reviewed deployment scripts: `D:/Codex/Tasks/2026-11-03/admin-performance/`. Server cutover, security-header probes and browser post measurements are recorded after publication. Existing source/static refresh conventions remain: asset filemtime/query versions change on publication, business pages are always requested.

## Formal publication and final evidence

Source revisions `ca2a016` and `d783d1b` are installed on the existing admin machine. HQ now uses `/srv/vialcove/hq/releases/admin-performance-ca2a01609c4b`; store-admin uses `/srv/vialcove/store-admin/releases/admin-performance-d783d1ba4d81`. Each overlay was copied from the actual previous live release and verified before replacing only the declared files. The current payment/provider and www-domain overlays, shared configuration, identities and all other source files were retained. This is a scoped publication, not deployment of all integrated Git history. Private rollback checkpoints and exact file hashes are in `outputs/runtime-deploy.json` and `outputs/runtime-trim-deploy.json`.

Final asset-map SHA-256 is `22e3cd64f0ba4910ea8676236a8866fccf8cd02001a21dca3dc628af927f951c`. Both maps use `volatile` so response status/MIME can be evaluated correctly; the temporary diagnostic header/map was removed. Current Nginx uses the same committed map. HQ source CSS transferred 4,180 bytes with gzip versus its original 16,003 bytes. Source cache, unversioned no-store, 304, missing-file no-store, login no-store, gzip and security-header probes passed after worker readiness. Receipts deliberately retain an initial old-worker observation followed by successful retry; they are not described as every first probe passing. Final cache checkpoint: `/var/lib/vialcove-deploy/admin-source-cache-final-20261103T062357Z-ee2e00`.

HTTP/2 is now enabled on the admin machine's shared IPv4/IPv6 port 443 sockets. Nginx 1.24.0 shares protocol options per listening socket, so the four existing HQ/s0001/s0002/s0003 vhost files received 12 `http2` listen parameters, including the existing default-reject and HQ www-redirect listeners for consistency. Removing those parameters reproduces the exact prior bytes. TLS, unknown-SNI rejection, Host/authentication guards, paths and headers were retained; the separate public storefront machine was not changed. Nginx syntax passed without new warnings; both main backends negotiated h2, HTTP/1.1 remains supported, and unknown SNI still fails its TLS handshake. Private checkpoint: `/var/lib/vialcove-deploy/admin-http2-20261103T063247Z-b3ce3b`; receipt `outputs/nginx-http2-apply.json` retains the old-worker readiness retry.

Actual in-app browser measurements, in seconds:

| Page / condition | First byte | DOM interactive | Load | Static resource entries |
| --- | ---: | ---: | ---: | ---: |
| Domains baseline, normal navigation | 0.577 | 5.212 | 5.248 | 71 |
| Domains, final source and first cache population, reload | 0.588 | 2.585 | 3.309 | 21 |
| Domains, normal navigation with populated cache, HTTP/1.1 | 0.567 | 1.786 | 1.803 | 21 |
| Domains, normal navigation with populated cache, HTTP/2 | 0.924 | 1.673 | 1.685 | 21 |
| Overview, HTTP/2; some page-specific assets not cached yet | 0.584 | 1.614 | 2.586 | 25 |

These are single captures under different cache/navigation/network conditions, not controlled protocol benchmarks, percentiles or a whole-admin performance guarantee. In the final domains capture, 20 of 21 static resources required no transfer, static transfer totaled 1,202 bytes, HTML still transferred 14,047 bytes, the filter exists and nested table wrappers are zero. Cached resources may report their original HTTP/1.1 fetch protocol; the new document and transferred script report h2. Evidence: `outputs/browser-{baseline,final-first-load,final-warm,http2-domains,http2-overview}.json`; actual formal-page screenshot `outputs/formal-backend-after.png`.

Post-publication authenticated loopback GET medians (three samples each) were 0.289s overview, 0.402s products, 0.258s domains, 0.266s data; HQ overview 0.271s and HQ payments 0.262s. Routes returned 200 and measurement sessions were destroyed; these server-local times do not include the owner's network. No payment/order/form submission or customer-data modification was used. Both pre-publication encrypted backups were copied to the private D-drive task directory and verified by SHA-256; decryption and restore were not tested.

## Remaining boundaries

The three operational Core pages receive dependency trimming; native product/order/Woo/editor screens retain their functional dependencies. Browser caching and transport improvements apply to the two existing admin hosts' allowed source assets. Current future-store provisioning templates were not changed. Native WordPress navigation still requests a new authenticated page; this does not turn it into a SPA. CDN can separately improve geographic delivery, but no CDN was purchased or enabled and no administrative/customer response was publicly cached. Further perceived waiting can include the China-to-US link and the actual page's queries; no zero-latency promise is made.
