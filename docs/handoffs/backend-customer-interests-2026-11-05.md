# Customer interests and separate AI input contracts · 2026-11-05

User confirmed customer list → detail, prioritising interests, inherited compact monochrome. The two future compatible-API integrations are independent: a customer persona analyses one browser visitor; a separate business persona analyses a store. Their instructions and results must not be shared by default. No provider/persona was supplied; no AI call, credential setting, scheduled analysis or automatic customer outreach is enabled by this change.

## Delivered facts and UI

- Live list search/status filtering, pause/resume, bounded single-flight polling with request timeout and failure preservation.
- Compact profile, day/week/month interest work surface, page active observation, identified module click counts, product views/clicks/observed active time/server add-to-cart counts, original history/cart/event timeline. Long technical event descriptions become expandable, not removed.
- `GET /peptide/v1/track/admin/preferences?visitor_id=0|<id>&period=day|week|month&date=YYYY-MM-DD`. `0` is store scope. Date is a date in the store timezone, weeks start Monday, months use calendar boundaries, interval ends exclusively, current period is clipped to now. Future/invalid dates and malformed IDs fail 400. manage_woocommerce plus staff data-module grants are checked. Anonymous fails 401.
- schema_version 1 facts: summary, pages, modules, products, ranking_counts, period, coverage, subject, ai; store scope also has native Operations business_kpis, including real paid-order facts under its existing test-order/refund rules. Customer subject is a store-salt HMAC, not an email/account ID. No raw query strings, cookies, IP, form values, clicked text or destinations are exported. Account/payment route paths are replaced with their page type.
- Active time is the union per browser visitor of adjacent visible/active presence intervals up to 30 seconds; same-page and same-product overlaps are also unioned. Idle age clips the last active interval; no exit/heartbeat means no fabricated tail, missing historical observations are not backfilled. This is observation, not an exact reading timer. Browser visitor does not prove a natural person across devices or shared browsers.
- Read bounded to 20,000 event rows; each ranking bounded to 50. Coverage exposes truncation; complete-data export is disabled for partial/truncated results. A future large-store AI pipeline must page/roll up facts rather than silently treat this bounded operator report as complete. Tracking-not-initialized returns explicit unknown metrics, not fabricated zeros. Retention/export/erasure reuse the existing raw event pipeline; no persistent customer dossier is created.
- New module_click/product_click observations use the existing enabled observer/transport, trusted interaction and GPC checks. Existing identified storefront sections plus header/footer/first-order offer are covered; product controls use existing product-ID DOM references. They are client observations, not independently verified purchase intent. No form inputs are read. Old visits do not acquire retroactive click counts.

## Separate future AI profiles

| Profile ID | Input | Persona | Schedule/output |
|---|---|---|---|
| visitor_analysis | visitor-scope facts for one subject/period | user will supply customer persona | future independent daily/weekly/monthly jobs and per-customer results |
| site_analysis | store-scope behaviour + native business facts; existing `/data/*` reports as needed | user will supply business persona | future independent daily/weekly/monthly jobs and store reports |

Both DTOs report not_connected, persona not_configured and automatic_analysis false. No provider endpoint, key, model, prompt storage, billing call, scheduler or completed report is implied. Next integration needs two explicit persona configurations, provider/model, secure key entry, cadence/timezone, isolated result storage, job idempotency/retry limits and retention/erasure of generated reports. Treat all page/product text as untrusted input to future prompts; model output must never execute store actions or infer sensitive personal traits.

## Validation

Preflight 5 PHP lints, 18 pure interval/count/privacy cases; 17 real insight-script DOM/request-race cases; 8 live-list controls/single-flight/pause/failure cases; 5 collector click/GPC/minimal-payload cases. Existing transport 48, surfaces 17, quick-view pair 7, timeline context 23, impressions admin 26 pass. Timeline harness now returns null for absent insight DOM rather than a false-positive node; it retains the independent old timeline HTML oracle. JS syntax and diff checks pass. A mechanical design scan produced advisory type/token findings; sizes were aligned to the established ramp, selection uses existing line color, and the user-confirmed #737373 focus color remains. No shipping raster.

Before deployment, actual installed WP read-only dispatch tested site and visitor day/week/month facts (6 x 200), invalid inputs (4 x 400), anonymous 401; no personal field keys present. These timings exclude browser/network. No synthetic observations were inserted into real stores and no orders, carts or tracking switches were changed. Initial installed checks found s0002/s0003 lack initialized tracking schema; the finishing correction returns an explicit uninitialized state instead of 503.

Visual review of actual component synthetic previews found factual label, mobile scroll cue and stale-period wording faults, all patched. Actual primary-store detail later exposed a CSS cascade conflict with the legacy profile strip; finishing correction scopes the compact strip to its real root and collapses long timeline diagnostics. Actual production desktop/mobile acceptance and review disposition are recorded below when finished. Synthetic preview is labelled and is not production evidence.

## Deployment evidence

Exact-context, zero-fuzz patching over cloned current releases preserves unrelated peer source, unlike replacing shared class-plugin.php from the newer repository. Whole-source manifests assert only allowlisted runtime changes; PHP is linted before activation; source/current/profile metadata CAS checks precede swaps. Tenant overlays are backed up, primary pointers and templates cloned, failure rolls back owned changes. No uploads, database/config content, secrets or peer work are included in Git.

Initial primary admin `/srv/vialcove/store-admin/releases/customer-insights-1793837913`; buyer `/srv/vialcove/releases/customer-insights-1793838020`. Both existing tenant runtime pairs and both separately pinned new-store sources were included. Task receipts under `D:/Codex/Tasks/2026-11-05/customer-insights/outputs`: deployed-admin.json, deployed-web.json, preflight.json, runtime.json, installed.json. Final finishing receipts supersede those roots below.

## Final acceptance

Production desktop and 390px mobile screenshots: D:/Codex/Tasks/2026-11-05/customer-insights/outputs/production-customer-desktop.png and production-customer-mobile.png. Actual browser checked weekly pressed state, complete rendered results, compact profile flex and no document overflow. Mobile empty product state has no misleading scroll cue; populated five-column product tables retain the cue. Pure DOM tests cover pending/stale/error/export behavior; screenshots alone do not prove those transitions. Documenter checked incumbent DESIGN.md and sidecar and made no system changes.

Final primary admin /srv/vialcove/store-admin/releases/customer-insights-hint-1793839418; primary web /srv/vialcove/releases/customer-insights-hint-1793839435. Matching new-store seed paths /srv/vialcove-provision-template/customer-insights-hint-1793839418/source and /srv/vialcove-provision-template/customer-insights-hint-1793839435/source. Existing s0002/s0003 runtime pairs updated. Final receipts hint-admin.json and hint-web.json verify public versioned asset hashes and HTTP200/TLS0. An earlier unversioned CDN verification rolled back cleanly; later versioned checks succeeded without altering CDN settings.

Installed actual bootstrap tests on all three admin stores: day/week/month 200, anonymous401. Uninitialized test-store tracking remains uninitialized with explicit unknown metrics and disabled export. No synthetic live events were inserted; real click-to-ingest workflow with customer activity is not claimed as tested. No whole-site freeze root cause or full browser performance certification is claimed.
