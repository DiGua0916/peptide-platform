# Public COA library presentation — 2026-11-05

Author: Codex. Candidate implementation only; root coordinates installation, real-browser acceptance and publication.

## Scope and files

- `wp-content/plugins/peptide-core/includes/class-report-archive-page.php`: retain the existing `[pc_report_library]` page and `Report_Archive::query` authority; add scoped frontend assets, real issue-month sections, accessible cover cards, native compact filters and recovery states.
- `wp-content/plugins/peptide-core/assets/public-report-library.css`: new public-only stylesheet. It does not reuse or alter the similarly named **admin** `report-library.css`. White/deep teal/teal follow the existing storefront. The generic VIALCOVE eyebrow is hidden only on the standard page containing this library. Inputs remain 16px with 44–48px operation targets, reduced-motion support and mobile card layout.
- `wp-content/plugins/peptide-core/assets/public-report-library.js`: progressive native GET submit feedback/BFCache reset and failed-thumbnail fallback only; no router, fetch, cookie, tracking, cart or data mutation.
- `wp-content/plugins/peptide-core/tests/report-archive-presentation.php` and `tests/public-report-library.cjs`: isolated regression checks.

`Report_Archive_Page::register_hooks()` already has its existing plugin registration. It now registers `wp_enqueue_scripts` at priority 45 and detects the real shortcode in the queried native page. No shared plugin or theme hook change is required. Both new asset paths must be installed together with the PHP class; versions use each asset's filemtime. The shortcode also enqueues as a fallback.

## Behavior and authority

- Existing product/specification/report-number/lot search, product/date filters, newest/oldest sorting, total count, pagination and genuine PDF/thumbnail endpoints remain authoritative. The service class, REST controller, report files and record metadata were not edited.
- Grouping uses only strict valid `issued_on` dates and preserves exact service order/current-page boundaries. Newest and oldest both group by their real year/month. Missing, blank or invalid dates have a separate explicit unknown-date section. No date is guessed. A future nondate sort receives a flat group instead of a falsely chronological presentation.
- Cards retain real product/source names and specifications, report number, source lot, issuer, full original report title and file size. Full title/issuer/file details use native `<details>`; real PDF actions stay directly visible. Product/source/technical values are protected from translation.
- Existing storefront EN/ES decision and native date locale are reused. Six new strings have scoped Spanish translations; no new language/country resolver or business option was introduced. Native GET filters/reset/paging preserve only valid existing `pc_lang`/`pc_lang_auto` navigation context.
- No fake purity score, report provenance/current-batch assertion, date or certificate image is generated.

## Evidence and actual verification

Evidence root: `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/`.

- `outputs/coa-presentation-tests.txt`: **35/35** isolated PHP renderer/group/filter tests passed. These use synthetic public DTOs and WordPress-function boundaries without WP bootstrap, DB or PDF execution. They verify real-date grouping/order preservation, missing-date behavior, safe escaping/service error privacy, original GET argument forwarding, PDF URLs, language context and native page/pagination semantics.
- `outputs/coa-client-tests.tap`: **6/6** production helper checks passed with synthetic DOM. Native submission remains unintercepted; invalid/prevented submission stays usable; BFCache clears own pending state; failed/lazy image behavior and duplicate-binding prevention pass.
- PHP lint, `node --check`, and own PHP `git diff --check` passed.
- Anonymous read-only LocalWP REST inventory returned **219 reports**. `work/coa/public-report-fixture.json` retains its first 24 existing public DTOs, with actual thumbnails/PDF URLs and issue dates. That HTTP GET can cause ordinary server cache work; it is not a claim of zero server-side cache effects. No business database write, report file edit or customer/order/session access was performed by this task.

## Isolated preview and remaining acceptance

`outputs/coa-preview-en.html` / `coa-preview-es.html` render the candidate shortcode using those actual public DTOs and the existing theme styles plus candidate public assets. The loopback-only preview server receipt is `work/coa/preview-server.json`; its current port is 54509:

- `http://127.0.0.1:54509/coa-preview-en.html`
- `http://127.0.0.1:54509/preview?width=375&lang=es` (neutral 375px iframe wrapper)

The fixture navigation uses a synthetic host; this is a visual preview, **not** a live WordPress filter/PDF workflow acceptance. No candidate file was copied into the role runtime directories. Browser extension operations failed; the official Windows Computer Use tool then stopped because it could not determine the current URL reliably. Computer control was stopped and root was notified. **Visual screenshots, actual mobile overflow/keyboard verification and installed native page/filters/PDF acceptance remain for root's working IAB.** Do not describe this candidate as visually accepted or published until those checks are complete.
