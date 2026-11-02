# Product broadcast: templates and parameter columns

The user requested a simpler merchant editor: remove the unified name and manual product/price/SKU preview fields, retain highlighted keywords, import custom parameter columns from Excel/CSV, and automatically preview the resulting sequence.

## Merchant behavior

- Write one template, such as `{test},哈哈哈哈,{shopping},你好吗？{go}`.
- Import a UTF-8 CSV or single-sheet `.xlsx`. The first row names the columns, preferably `{test}`, `{shopping}`, `{go}`; values run down each column independently. Headers also become highlighted keywords. Columns can also be added, renamed, edited and removed in the editor.
- With `test=[A,B,C]`, `shopping=[D,E]`, `go=[G]`, playback is `A/D/G`, `B/E/G`, `C/D/G`, then starts again at `A/D/G`. No Cartesian product or least-common-multiple expansion.
- A single cursor selects `template[i % templateCount]` and `column[i % columnLength]`. The cycle length is the greater of template count and longest column referenced by a template. Unused columns do not extend the message cycle. Repeated resulting text is deduplicated to match the existing storefront ticker.
- Blank cells are skipped independently; repeated values within a column are merged case-insensitively, keeping the first order. Parameter names are case-sensitive and normalized to NFC. Plain text only.
- The preview validates automatically after edits, uses the actual PHP compiler/importer, and plays with the configured enter/hold/exit/gap timings. Pause and previous/next controls remain. It also previews drafts while the customer-facing switch is off, without applying them.
- Import changes the editing draft, not saved/public settings. Append and replace are explicit. Invalid files fail as a whole and keep current inputs. Save draft, save-and-apply, revision conflicts, JSON backup and explicit reload consent remain.

## Storage and storefront compatibility

Canonical editor configuration is version 2 and uses `parameters: [{key: "test", values: ["A", "B", "C"]}]`. There is no canonical unified `name` field. Old version-1 configurations and backups remain readable: a nonempty old name becomes a one-value custom `name` column. This conversion is in memory; reads do not migrate stored options or change revisions.

The option remains `pc_product_broadcast_v1` with its existing version-1 revision envelope and atomic site-local CAS. Its maximum record size is now 16 MiB to accommodate both legal Unicode snapshots. Same-payload publication still keeps the public revision.

`Store::public_config()` compiles columns on the server into the existing bounded version-1 plain-text `items`, `keywords`, empty `name` and timings. The actual storefront ticker model accepts this directly; no theme or visual storefront changes were made. Native `{product}`, `{specification}`, `{price}`, `{sku}` remain available from the current product unless a custom column explicitly supplies that name. Placeholder substitution is text replacement, not code evaluation. Missing custom columns produce editor warnings and retain literal tokens.

`POST /peptide/v1/product-broadcast/preview` returns `{config: canonicalV2, playback: compiledV1, warnings}` and does not save. `POST /import` retains its authenticated JSON envelope; `.xlsx` content is strict Base64, CSV is UTF-8 text. `GET /examples?format=parameters-csv` provides the parameter-table sample. Existing TXT/CSV text imports and JSON backups remain supported; a legacy bare `text`/`content` CSV is still a content file, so use braces (`{text}`) to explicitly name a parameter with those reserved legacy headers.

## Limits and file handling

At most 32 columns and 1,000 valid parameter values across all columns; templates/values/rendered messages are at most 500 Unicode characters. Up to 1,000 templates and expanded highlighted keywords. CSV/XLSX files are limited to 1 MiB; full JSON backup restore is separately limited to 8 MiB. `.xls` is unsupported. Numeric XLSX values use their underlying value; formatted numbers/dates report this explicitly, and text columns preserve intended display strings.

XLSX is parsed in bounded memory without archive extraction, temporary files, network access, macro execution or formula evaluation. ZIP entry counts, offsets, local/central agreement, CRC, compressed/inflated sizes, overlaps and paths are checked. Encryption, ZIP64, symlinks, unsupported compression, macros/embedded content/external relationships, XML DTD/entities/UTF-16 and formulas are rejected. Multiple worksheets are rejected instead of silently partially importing one. Server capabilities, nonce, staff `apps` permission, store scope and draft/public separation remain unchanged.

## Actual verification

- Native WordPress/controller/permissions/TEMP option CAS: 70/70; preview made zero SQL queries and permanent business/configuration fingerprints were preserved. The existing visitor-only REST guard still returned 403; it was not removed or bypassed.
- Actual unmodified storefront ticker model: 11/11, including the exact three-step reset and all 1,000 entries.
- Import/security cases: 79/79, including ZIP/XML adversarial files, sparse/shared/rich/numeric cells, row/column/value boundaries and backup restore.
- Native large-backup follow-up: 6/6. A 1,504,208-byte exported canonical backup restores identically; the earlier full legal Unicode draft/public record of 12,018,503 bytes saves/reloads within the new 16 MiB record limit.
- Admin DOM/clock/request state cases: 52/52, including auto-play, stale responses, invalid-import preservation, CAS failures, disabled previews and exact control labels. PHP/JS syntax and Git whitespace checks pass.
- Browser: isolated page using the exact production JS/CSS and real pure PHP import/preview, with synthetic data and writes permanently denied. Actual Excel import returned 3 columns/6 values; the browser showed `C,哈哈哈哈,D,你好吗？G` and then reset to `A,哈哈哈哈,D,你好吗？G`. Desktop and width390/client375 have no page overflow. Removed name/product-preview fields and manual validation button are absent. The exact import-mode label was corrected after browser inspection.
- Mechanical design check reported only two existing/pinned focus-gray `#737373` palette advisories; this color follows the user's 2026-10-29 focus specification in PRODUCT.md. No new visual palette or storefront theme was introduced.

Formal-site browser binding timed out, so the isolated visual check is not a claim of a live authenticated save through that browser. The native permission/CAS tests cover persistence separately. The formal site's broadcast option was privately backed up and remains byte-identical, revision 2, public disabled; no sample was applied, no product/cart/customer/order data changed, and existing QA source/guards were left intact. Refresh the real application page to load the new editor; the operator chooses when to apply content.

Evidence and screenshots: `D:/Codex/Tasks/2026-11-03/broadcast-parameters/outputs/`. Source changes are limited to the broadcast configuration/compiler/importer/controller, its application editor, and the existing layout reference copy.
