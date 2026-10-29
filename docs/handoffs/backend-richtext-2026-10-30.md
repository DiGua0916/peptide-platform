# Shared rich-text source editing, fullscreen and help

2026-10-30 · Codex. Source/fullscreen/help phase verified; text color and inline media are still open work.

Shared rich fields now expose explicit source draft Apply/Cancel, editing fullscreen and local help in the compact monochrome toolbar. Source draft toggles and unchanged Apply do not materialize inherited null values or create mobile overrides. Existing legal special links stay in the protected source-only path until the user explicitly converts them. The HTML allowlist and URL policy are unchanged.

Fullscreen keeps the same editor/source node and field data flow, restores body overflow and focus on exit, and supports native Tab/Shift+Tab. Escape closes help, then the link form, then fullscreen; ordinary source Escape cancels its pending draft. Device switches discard pending drafts even when both effective values are equal. Different entities retain their existing stable keys.

Changes are limited to module-controls.js and module-editor.css. Final JS SHA256:7cbdadf012874517ed10ba3d4d1d98805e737d77ed0f0b1359c05052d8bc8348; CSS:0d800d0b06d08f85357393f34a341091d564aca091df7b8f8eb911d0a0c8a3c0.

## Verification and fixes

Independent native evidence is kept by version:16 groups at490ec591, six targeted Escape groups atc94c1a82, then five targeted Tab groups at7cbdadf0. These counts are not summed into a final-version full-suite claim. Fresh review closed the link-form Escape P3. Actual WP then exposed a separate P2: an anchor inside contentEditable was included as the last focus candidate, while native Tab skipped it and left the dialog. The final narrow filter excludes editable descendants but retains the editable host and external form controls. The two sanitizer functions and all other code outside that filter remain byte-identical to c94. The older failing trajectory and review are retained.

Four actual WP targets completed, JS0: source/null/cancel and unchanged full save payload; explicit Apply/empty clearing/real KSES and protected FTP compatibility; fullscreen geometry, same nodes, natural focus loop and safe local forms; and same-value desktop/mobile draft isolation with precise saved field differences. A1/A2/A4 passed atc94. Only A3 was rerun after the Tab fix at final7cbd; the other three paths were unchanged. A3 included real rich links, both natural Tab directions,390px layout, link input/Apply/Cancel Escape, help precedence, overflow/focus restoration and real Save→GET→signed preview. See D:/Codex/Tasks/2026-10-30/richtext-runtime/work/richtext-runtime-check.md/.json.

The combined eight Counter/rich-text targets and final seven-source/helper equality proof are in counter-runtime/work/counter-richtext-runtime-check.json. No claim is made for IME, every undo case, Gutenberg or all browsers. Colors/images were not enabled in this phase.

Runtime-owned temporary data, credentials, preview transients and profiles were precisely removed; old QA data/media and the original-site baseline remained unchanged, mail/orders0. QA56139 and its independent database remain for continued work. This phase did not touch other owners' retained profiles or bypass the recorded Slogan cleanup rejection.

Next: add selected-text colors through a narrowly scoped trusted policy and controlled inline media, preserving legacy/null behavior and verifying actual saves again. No deployment occurred.
