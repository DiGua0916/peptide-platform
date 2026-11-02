# Product broadcast: per-column privacy

The user requested an independent privacy switch in each parameter row, directly before Edit values. It defaults off and is manually selected; parameter names do not automatically turn it on.

## Behavior and contract

- Canonical version 2 parameter columns add optional `privacy: boolean`, normalized to false when missing. Invalid explicit types are rejected. The administrator's original values remain intact, including full JSON backup and restore.
- With privacy on, each whitespace-delimited word retains its first Unicode code point followed by `***`; one-character words become `***`. Example: `Linda Valdez` becomes `L*** V***`. Existing masked values are stable. Parameter substitution and matching highlights share the same masked selection.
- PHP masks values before constructing any public fallback items, keywords, runtime pools or SSR JSON. Public columns include a boolean privacy marker for preserving distinct source entries that produce identical masked text. This marker never asks JavaScript to mask twice. Random selection weights and ordered cycle lengths retain the source entries even when display strings collide.
- CSV/XLSX append and replacement retain privacy for matching existing keys, including valid empty editing placeholders being filled by an import. New columns default off. Complete JSON restore uses the backup's switches. Limits, permission/nonce checks, revision/CAS and draft versus publish behavior remain in effect.
- The row's summary and tooltip show masked samples when on. Edit values deliberately exposes the complete management values. The switch changes only the current editing draft until Save and apply. Existing opened tabs are not forcibly refreshed or saved.

## Scope

Only peptide-core PHP, its merchant admin assets and shared parameter adapter changed. The existing theme/ticker source and public styling remain untouched. There is no automatic migration, import, setting enablement or application of a sample.

## Verification

The final integrated receipts and verification limits are listed below.

## Final integrated verification

- Pure PHP privacy/import contract: 55/55.
- Actual formal-local WordPress REST/Store/public/SSR: 25/25, using process-local synthetic SELECT overrides and a SQL read-only guard. Zero attempted mutations, real stored option fingerprint unchanged. This does not claim a live customer-config save.
- Shared helper with the actual unchanged theme ticker: 73/73, including original regressions, 14 PHP/JS masking vectors and masked-collision weights/cycle lengths.
- Admin DOM/request/clock regression: 117/117, including all original 99 behaviors. Switch changes make one preview request, samples/title are masked, raw editing is intact, malformed input fails safely, and save/publish/JSON requests retain the flag.
- In-app browser: isolated loopback page with the exact production admin assets and production pure PHP, synthetic data only, database absent and save/publish always forbidden. Name switch was activated; summary/tooltips and automatic preview masked, other two columns retained their display. Desktop client/scroll width1265/1265; mobile390 viewport client/scroll375/375. Native switch inputs32×20 within36px labeled targets. Screenshots privacy-desktop.png / privacy-mobile.png; viewport reset afterward.
- Formal local HTTP serves all three changed JS/CSS files200 with bytes matching the final source. Source syntax and whitespace checks passed. Impeccable detector only reported the established neutral #737373 focus/border color as an advisory; that color is already specified in DESIGN.md prose.

The user's existing formal tab was read only: three parameter rows, current draft matched the saved backend, no new privacy controls in that older loaded asset version. A fresh formal tab is opened after integration, preserving the existing tab and its inputs. No switches are automatically enabled for the user.

Evidence: `D:/Codex/Tasks/2026-11-03/broadcast-privacy/outputs/`. Local screenshots use synthetic names and do not prove a customer setting was applied. This feature needs the merchant to enable selected columns and click Save and apply.

