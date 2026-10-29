# Counting numbers: layout, symbols and decimal values

2026-10-30 · Codex. Verified development phase; the full builder goal and deployment remain open.

The reference counter uses a horizontal icon/number layout on desktop, stacked icons on phone, separate column settings and decimal numbers. Its observed values include9876.543 and-12.5. The local block now follows that layout while preserving old icon strings, media, item IDs, enabled states, units, defaults and legal column counts. Six decimal places are a chosen local extension; the reference was only exercised to three places.

## Changes

- Registry adds nullable descriptionHtml, compact property panels and40 authored symbol choices. The default none keeps the old icon; hidden suppresses symbols while a valid custom image still takes priority. Original image/icon controls remain usable.
- Counter.number alone opts into precision6/step.000001 and finite±999999999. Fields accepts precision only from the trusted server definition, with an integer0–6 guard. Other number fields retain their existing integer/two-place behavior. Default numbers remain0; no reference claims became content.
- Fallback emits precise raw values separately from locale-formatted SSR text. The animation handles signed fractions and restores the exact SSR text at completion or reduced-motion changes. Units retain their case.
- Scoped CSS uses74px icon frames/32px symbols, desktop separators and phone column layout. Legal six-column/120px-font values remain available through bounded native scrolling. Counter has no gapPx field: PC gap30/phone0 are fixed; earlier draft references to gap120 were inaccurate.

Files: Registry, Fields, Fallback, modules.css and modules.js. Existing Slogan_Symbols is unchanged. Source pins are in the integration task freeze-pin.json and runtime report.

## Verification

Independent source/native fixtures completed six scoped groups;130 other numeric fields×21 samples and34 other basic/shared definitions remained equal. An earlier draft-schema artifact predates precision6 and is superseded, rather than evidence for the final version. Fresh review found no new P1/P2 or source blocker.

Four actual WordPress/WooCommerce targets passed, JS0: native insertion/42 choices/image priority/legacy icons; keyboard editing, save/reopen, valid JSON1e309 safely returning0, real fractional animation with exact WP en_US SSR completion; PC/phone1/2 and legal6-column/120px-font rendering with real keyboard scroll; and no-JS SSR/scroll plus an unchanged numeric sentinel. See D:/Codex/Tasks/2026-10-30/counter-runtime/work/counter-runtime-check.md and .json.

These four ran with shared controls c94c1a82. A later shared fullscreen-only Tab fix was verified separately in backend-richtext-2026-10-30.md; the four Counter targets were not rerun or miscounted. No Gutenberg or all-browser acceptance is claimed.

All runtime-owned temporary records,25 preview transients across the combined phase, credentials and profiles were precisely cleaned. Existing products/comments/users/Commerce/media and the original-site858/2555 read-only baseline remained unchanged; mail/orders0. The independent QA service/database and old fixtures remain for the continuing goal. The author's closed Counter fixture profile is retained separately, without a deletion attempt; the older Slogan cleanup rejection remains recorded in its own handoff.

Next: continue Gallery and remaining reference blocks, and finish rich-text color/media capabilities. Payment/mail/search remain unconfigured, AI remains deferred.
