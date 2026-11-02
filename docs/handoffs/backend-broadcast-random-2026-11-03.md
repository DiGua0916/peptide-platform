# Broadcast parameter sampling: runtime integration

The user changed parameter playback from ordered cycling to independent random draws and requested a setting. This supersedes the earlier default ADG/BEG/CDG cycle, which remains available as the optional ordered mode.

Canonical version 2 adds `parameterMode: "random" | "ordered"`, defaulting to random for new and older configurations without that key. Reads remain deterministic and do not migrate options. Templates themselves retain their existing order; each next announcement draws one value independently from each referenced column, with replacement. A one-value column is constant. One announcement reuses its selection for repeated occurrences and highlighting. Pause, measurement, context updates and repeated reads of the current announcement do not draw again.

The public version-1 DTO retains deterministic compiled `items` and `keywords` for older consumers, and adds `parameterMode`, original `templates`/`keywordTemplates`, and only referenced `parameters`. Disabled output clears all template/column data. PHP validation bounds every possible independent combination without enumerating a Cartesian product or consuming random numbers.

The plugin supplies a shared pure JavaScript parameter model to its admin preview and adapts the existing storefront ticker model through its public normalize/sequence/render APIs. WordPress dependency ordering places this adapter after the incumbent model and before its ticker. The theme's source, visual rendering, stock/cart logic and controller lifecycle remain unchanged. Instances remain independent; opaque in-memory frames let the incumbent renderer substitute placeholders once so imported values are literal text. These frames are never part of persisted or public JSON. Missing-helper admin previews report the error rather than pretending a random queue is ordered.

The admin's “参数取值方式” select defaults to “全部随机”; “按顺序轮询” retains the earlier option. This setting survives imports, exports, drafts and save/apply. Automatic preview, single-template next/previous, highlighting and customer playback share the same sampling model. Keywords control highlighting; custom parameters provide substitution values. Importing a column still adds its token to the highlighting list, which merchants may edit independently.

## Actual verification

- Pure PHP contract and worst-case random length validation: 32/32.
- Actual WordPress preview, import/backup mode, temporary-table save/public/draft/CAS, disabled-data clearance and native script dependency order: 17/17. Permanent options fingerprint preserved; no permanent write attempted. Existing visitor-only REST guard unchanged.
- Shared sampler and actual unmodified storefront model/controller VM: 33/33. Covers repeated draws with replacement, repeated keys/highlighting, pause/measurement/context/visibility/motion without redraw, multiple independent instances and literal nested tokens.
- Admin DOM/state/clock/import/save/CAS/stale-response regressions: 69/69; native enqueue method check: 7/7.
- Actual anonymous local homepage contains model, plugin adapter, then ticker in execution order; the disabled public DTO clears all templates/pools (2/2). Actual HTTP-served adapter bytes match the working source.
- In-app browser on a loopback isolated page running exact production admin assets plus real pure PHP preview/import: the setting defaults to random; seven explicit next steps showed BDG, AEG, ADG, CDG, CEG, BEG, CDG with the singleton G constant. One template kept next/previous and automatic playback enabled. This is a synthetic-data visual check, not an authenticated live save. Screenshot and receipts are under `D:/Codex/Tasks/2026-11-03/broadcast-random/outputs/`.

No sample configuration was applied, no real product/customer/order data changed, and the formal local broadcast option remained byte-identical to the task's preflight backup. PHP/JS syntax and scoped Git whitespace checks passed. The older ordered default in the previous handoff is superseded by this user decision. All changes are within the backend plugin and its handoff; no theme worktree edits or branch changes are needed.
