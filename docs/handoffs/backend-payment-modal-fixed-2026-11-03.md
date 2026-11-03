# Fixed centered payment editor

2026-11-03 · Codex. User explicitly requires the payment account editor to always use the centered dialog in their second screenshot. Root cause: server HTML unconditionally opened an inline form; CSS switched to centered positioning only after JS added `is-enhanced`. Any delayed/failed script or pre-enhancement render therefore exposed the full-width alternative.

Proposal and implementation: use the same centered fixed-size scrollable editor whenever `[open]` is present, including the server fallback. Only render `open` for explicit add/edit URLs; retain native `showModal` focus/Escape handling when supported. The fallback has matching dimming, and its close control is a normal cancel link when JS is unavailable. Responsive sizing also uses `[open]`, not an enhancement class. Preserve all fields, submission, nonce/capability checks and real merchant settings. No payment account Save/Enable/Delete or test payment is part of this task.

Files: `peptide-panapay/assets/hub.css`, `assets/hub.js`, `includes/class-hub-page.php`. Formal baseline independently matched all three files to repository HEAD before patching. Deployment and rendered checks are recorded below after completion.
