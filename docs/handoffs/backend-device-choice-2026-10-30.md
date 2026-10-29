# Device-specific select menus — 2026-10-30

Author: Codex. Root assigned a narrow shared-control capability for the video-text block's height selector. This phase changes only `wp-content/plugins/peptide-core/assets/module-controls.js`; the block's schema, rendering and playback are owned by the separate video-text implementation phase. No parent editor, Fields, Links, lookup API or database behavior is changed here.

## Metadata contract

An explicitly configured select field may provide `uiOptionsByDevice: { desktop: { key: label, ... }, mobile: { key: label, ... } }`. Each active device map must be a nonempty object with string labels. This metadata selects the visible key order from the existing flat `field.options` schema. Labels remain those of the schema; UI metadata cannot invent a server option or replace its authoritative label.

For example, `heightPreset.options` may contain the union of `450px`, `550px`, `650px`, `750px`, `250px`, `300px`, `400px`, `500px`, `screen` and `custom`. Desktop metadata lists the four desktop pixel presets, screen and custom; mobile lists the four mobile presets, screen and custom. The control does not interpret these values as heights or patch companion fields.

Missing metadata, a missing active device, unsupported device names, invalid/empty active maps or maps with no recognized schema keys use the original full option list. Unknown keys are never added. If the effective current value is already legal in `field.options` but absent from the active preferred menu, its original schema option is appended so old or inherited values remain visible. There is no clamping, normalization, migration or focus/blur write.

The `control()` wrapper remains responsible for the device object and mobile null inheritance. A selection invokes the original callback once for the one nested field. Reset-to-desktop remains a null operation. No parent editor callback or state-update contract changed. Fields without device-menu metadata retain the original `options(field.options)` list; the additional explicit paired presentation is described below.

## Initial menu-only verification

JavaScript syntax and the scoped whitespace check passed. Five independent native Edge groups used the actual shared source and installed React with explicit synthetic WordPress component wrappers and App state:

- Desktop menu order, selected value and zero writes on focus/blur.
- Mobile menu, a single nested-key write per selection, null reset/inherited desktop value and unchanged companion pixel values.
- Legal cross-device saved values remain selected and visible without automatic mutation.
- Absent/malformed metadata falls back; unknown keys and metadata label substitutions cannot expand or relabel the schema.
- The 390px viewport keeps the native select reachable without horizontal overflow; a real keyboard selection writes once.

JavaScript exceptions and external requests were zero. Desktop and mobile screenshots were opened and inspected. The owned browser profile was closed, its exact D-drive path and all descendants were checked for reparse links, and only that profile was removed. Evidence and exact before/source hashes are under `D:/Codex/Tasks/2026-10-30/device-choice-controls/work/`; screenshots are in `outputs/`.

That first menu-only source SHA256 was `6fd59929771faffa1c583105dc814609b9196b5af9096cce3e9cc5ea6cf01d11`. It is now an intermediate checkpoint, retained in `source-pin-before-paired-select.json`; it must not be used as the final composed video-text UI pin.

A separate read-only reviewer inspected the narrow source delta and device wrapper context and found no new P1/P2 issue, blocker or missing requirement. That reviewer did not edit files or run tests; it did not expand the five-group evidence boundary.

## Same-panel computer and phone selects

Root subsequently required both height menus to be visible together, matching the observed reference. A top-level select may now opt into `deviceUI: 'paired'` as well as `device: true`. The existing settings-panel context must enable paired controls and provide `pairedDeviceSafe(field.key) === true`. Items contexts do not enable that flag, so repeater fields keep the original single-device presentation.

The stored value must be a non-array object with exactly the two own slots `desktop` and `mobile`. Desktop must be a string or finite number whose string key is already legal in the flat schema; mobile must be similarly legal or exactly null. Missing/throwing parent guards, old same-key sparse overrides, malformed shapes or invalid choices fall back to the original single-device control without a write or migration. This is a client UI fallback, not an expansion of the server's existing device-data contract.

The two rows call the existing select renderer with desktop/mobile device contexts, so their `uiOptionsByDevice` menus differ on the same screen. A mobile null displays the legal effective desktop value, even if that value is not a normal mobile preset. Each user action clones the original nested object, changes one slot and calls the original field callback once. Reset changes only mobile to null. Companion pixel-height fields, the other device slot and unrelated data are not patched. The previous numeric paired branch and the menu-filter helper are retained verbatim.

Four additional native Edge groups passed, with no JavaScript exceptions or external requests:

- Both menus, inherited null and a desktop-only change with one nested-field callback.
- A phone-only change and reset, each with one callback, while other settings remain intact.
- Legal cross-device selections, the old numeric paired slider behavior and both selects at 390px.
- Missing/unsafe/throwing guards, no opt-in, malformed stored values and actual ItemsEditor contexts fall back without migration.

The four groups use real shared source and React with explicit synthetic WordPress controls/App state. They do not rerun or add together the earlier five menu groups, and are not real WordPress save acceptance. Both new screenshots were opened and inspected. A second read-only source review found no new P1/P2 issue, blocker or omission and did not run tests.

The new fixture asserted a unique D-drive profile path did not exist before creating it with an explicit owner marker. After its context closed and process exited zero, a separate read-only preflight verified the exact resolved target, parent/name, owner and 217 entries with no reparse links. The subsequent narrow cleanup rechecked that same literal target and owner, exited zero and confirmed the profile absent. Evidence is `paired-profile-preflight.json`, `cleanup-paired-profile.py` and `paired-profile-cleanup-proof.json`. The initial menu fixture's earlier cleanup history remains separately recorded in `cleanup-review-record.md`.

Final frozen source SHA256: `6a028a98d63841a34b72c9a9f80857c8e84dd3e3c56808ba2552d871b1bba6f7`. Final pin and incremental evidence are `source-pin.json` and `paired-select-native-result.json` in this task's work directory.

The independent checks above do not bootstrap WordPress. Root subsequently composed this final source with the pinned video-text schema/rendering phase. Five scoped real WordPress groups passed, including both actual menu sets on the same panel, original nested single-slot saves, mobile null/reset, preserved legal cross-device choices and companion custom pixel values, and actual desktop/mobile heights. The other groups cover the separate video-text rendering and playback work; they are not five extra shared-only cases. Evidence: D:/Codex/Tasks/2026-10-30/video-text-runtime/work/video-text-runtime-check.md and JSON. Temporary QA data and profiles were precisely cleaned and prior fixture/original-store fingerprints were unchanged. This source owner performed no original-store, reference, QA, Git, memory or publication action; the integration owner's actual QA is reported separately.
