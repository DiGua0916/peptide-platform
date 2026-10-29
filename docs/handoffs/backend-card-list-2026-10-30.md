# Card-list layouts — 2026-10-30

Author: Codex. This is the `card_list` portion of the ongoing store-builder redesign. Five scoped real WordPress groups subsequently passed as recorded below; the full reference catalog remains unfinished.

## Observed reference and implementation

Root studied the reference 404 editor one block at a time. Its card-list settings offer grid, carousel and masonry; desktop columns 3–5 (default 3), mobile 1–2 (default 1), a 10–120px gap (default 30), card text alignment, rich introduction, and repeatable image/title/plain-text/link records. Real PC carousel paging moved the fourth card into view. The reference masonry uses CSS columns with `column-count:3`, `column-gap:30px`, natural-height inline-flex cards and downward column order; mobile uses one column. The section heading stays centered when card text is left-aligned. Root also verified that a selected homepage target reaches image/title anchors.

The local implementation preserves the existing `grid`/`carousel` values and adds `masonry`. It uses CSS columns rather than inventing a shortest-column JavaScript algorithm. Existing saved defaults remain: insertion-only metadata continues to offer the observed gap/alignment, while old records retain their original values. Media, item IDs, enabled state, rich-description null inheritance, explicit empty descriptions, hrefs and all legal old column values remain on the original paths.

The reference's mobile carousel inherited `visibility:hidden` during observation, and its long card description used a three-line clamp. Those local reference defects are not copied. Local cards remain visible and text is allowed to grow. The existing carousel's overall tallest-card sizing has not been redesigned in this increment; root explicitly kept auto-height outside this scope.

Card-list-only additions:

- Accessible previous/next and page controls use actual native scroll geometry. They support keyboard Home/End/arrows, RTL, reduced motion and responsive page counts. Equivalent pager focus survives resize; unchanged status text is not repeatedly announced. Native horizontal scrolling remains available without JavaScript. There is no new autoplay.
- Narrow old mobile five-column cards no longer lose letters inside fixed 16px horizontal padding. Their inline padding becomes `min(16px,8%)` while vertical/wide-card spacing stays familiar.
- Impossible old combinations, such as five or six columns with 120px gaps on a 320px screen, keep their stored values and columns. A 28px minimum grid track and bounded native scrolling contain them; actual overflow exposes a scroll hint and keyboard focus. Ordinary five-column/30px-gap records at 320–375px do not gain horizontal overflow. Product grids and other block types are not changed.

## Changed source

Only `wp-content/plugins/peptide-core/`:

| File | SHA256 |
| --- | --- |
| `includes/modules/class-registry.php` | D6B0CCB1B890B51069D4BD500D828E83A2F4E9260523E66319B1A748D19B810D |
| `includes/modules/class-fallback.php` | C77DADE57D3A4A13EA3163F209F8246C3B28D35B4EBC6D5E03595147437F6D1C |
| `assets/modules.css` | C5430DF0218A6DA7EA807C6C2CCE12D389D3690BEB3CDA186F4683EE9488D767 |
| `assets/modules.js` | 7B62214D3ABB8FECA2A42DECBE9281F857085AE5B0EC99612F4C8FD28B63D969 |

The theme has no dedicated card-list override. No theme, shared controls, menus, business records or third-party core files were modified by this task.

## Actual verification and remaining validation

Evidence is under `D:/Codex/Tasks/2026-10-30/card-list-layout/`. The fixture uses actual module PHP/CSS/JS and explicit WordPress API doubles. It never bootstraps WordPress or touches a database:

- Before: actual native Edge reproduced 375px five-column cards with a 43px card, 11px text box and 17px glyph width, causing clipping.
- Final native baseline: 44 checks across 1280/390/375/320, including actual controls, last-card reachability, native scroll, keyboard Home, responsive page counts, IDs/hrefs, gap and narrow text containment; JavaScript errors/external requests zero.
- Extreme/no-script increment: 18 checks for stored five/six columns with 120px gaps, real keyboard scrolling with JavaScript enabled and disabled, visible overflow affordance, empty/single-card controls and extreme carousel reachability; JavaScript errors zero.
- Masonry/schema/interaction increment: 17 checks for actual column flow, unequal heights/full text, mobile order, heading/card alignment, RTL and resize focus. The pure registry context contains **35 base/shared definitions**, so schema preservation covers the other **34** definitions and all existing card-list fields/defaults/options/items. It does not establish a 73-definition full WordPress-loader result.
- PHP syntax for both PHP files and Node syntax for the JavaScript pass. A separate read-only reviewer inspected only this task's source diff and three actual fixture screenshots, found no new P1/P2 or blocker, and made no edits or new tests.

The first no-script test used page-side animation-frame polling, which cannot complete with page scripts disabled. The fixture changed to bounded Node-side read-only polling; its earlier timeout remains in `work/extreme-native-failure.json`. The source was not altered to satisfy that fixture. Repeated final checks are not counted as new cases.

**Subsequent real WordPress validation:** the independent QA owner copied and verified all four pins, then completed five scoped groups using the actual Custom Editor, layout REST and its signed, active preview iframe: masonry selection/save/readback and retained IDs/hrefs; desktop three-column/mobile one-column flow and card/heading alignment; real carousel next/End and visible mobile controls; saved legacy desktop6/mobile5/gap120 values with bounded rail overflow; and the same signed preview with JavaScript disabled, preserved server-rendered cards/links and real keyboard scrolling. JavaScript errors were zero. A fixture screenshot API error caused the first run to stop one group; only that group was corrected and repeated, without changing source.

The one new QA document/template, accounts, revisions, credentials and two browser profiles were precisely removed after each attempt. Existing products, comments, metadata, users, Commerce fixtures and local-payment state retained their fingerprints; mail/order counts stayed zero, and the original store's read-only fingerprints stayed at baseline. The independent QA service and prior fixtures remain with their existing cleanup owner. Report: `D:/Codex/Tasks/2026-10-30/card-list-runtime/work/card-list-runtime-check.md` and its JSON. These five groups do not claim exhaustive card options, real RTL or all blocks. Root owns Git integration, push and project memory; the source owner performed no Git or database actions.
