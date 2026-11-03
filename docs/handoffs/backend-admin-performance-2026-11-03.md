# Backend navigation performance

2026-11-03 Codex. The owner reports both deployed backends wait and flash during navigation. Their requested outcome is faster normal use on the existing formal sites. No separate test environment or customer/business data manipulation is required.

## Measured causes

Authenticated loopback SNI measurements (three GETs per route) give median first-byte time of 0.341s for overview, 0.454s for products, 0.308s for domains and 0.302s for data. The bounded existing-administrator measurement session was destroyed. No order or payment action was submitted.

The actual owner's in-app domain page took 0.577s to first byte and 5.248s to load in one baseline capture. It fetched large command-palette dependencies including block-editor, components and core-data on this operational page. Actual resource entries and their transferred byte counts are retained in private task evidence; the baseline is a single browser sample, not a percentile or load test.

Both HQ and the customer backend send no-store for source assets. HQ source CSS/JS was not gzip compressed. Server resource sampling showed spare CPU and RAM, not saturation. Schema validation measured 8.46–9.38ms for eight metadata queries; registration matches the new public www and fixed management URLs without errors. Neither is changed by this performance patch.

## Changes

- Reuse Core page-owned table scrolling containers without moving their tables after first render. Native list fallback wrapping and editor/React ownership remain intact.
- At enqueue priority 1000, remove only optional command-palette roots on the three measured Core operational pages (overview, domains, data), plus HQ's own non-editor pages. This omits the Ctrl+K convenience there. Native menus, Woo routes, product/order/user editors and the decoration editor keep their normal queues. Shared editor/data packages remain registered and load if any remaining feature depends on them.
- The deployment asset map at `tools/deployment/nginx/admin-source-asset-cache.conf` permits a one-day **private browser cache** only for explicit code-owned asset directories, valid source MIME types, successful GET/HEAD responses and versioned CSS/JS. Fonts/source images can use the same bounded TTL without a version query. HTML/PHP, REST, AJAX, uploaded media, reports and JSON remain no-store. No CDN/public administrative-page cache is enabled.
- The formal Nginx overlay changes only two Cache-Control directives and adds HQ source gzip types, preserving routing, TLS/SNI/Host/authentication guards and inherited security headers. The map is installed before its references and checked with Nginx before reload.

## Source verification

Actual production JS passes 21 pure DOM checks for scroll-owner reuse, accessibility and unaffected native/React paths. Actual PHP enqueue methods with native WP_Dependencies and the installed asset manifests pass 42 dependency-closure/guard checks plus two iframe checks. PHP/JS syntax and diff whitespace checks pass. These models are not claimed as browser or real-provider transaction verification.

Evidence and reviewed deployment scripts: `D:/Codex/Tasks/2026-11-03/admin-performance/`. Server cutover, security-header probes and browser post measurements are recorded after publication. Existing source/static refresh conventions remain: asset filemtime/query versions change on publication, business pages are always requested.
