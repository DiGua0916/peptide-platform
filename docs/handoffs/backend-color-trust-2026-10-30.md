# Color B and Custom HTML preview trust — 2026-10-30

Author: Codex. Root-coordinated integration of two separate scopes. Custom HTML's narrow trust fix passed three actual WordPress targets; Color B passed four separate actual WordPress targets. They are not a combined passing test count.

Color B changes only module-controls.js, module-editor.css and Fields, with a new autoloaded Rich_Html helper. Trusted server field metadata type=html plus ui=richtext opts into strict canonical span color; ordinary HTML/template/code, numeric precision and global KSES policy remain unchanged. The selected text accepts 22 observed reference colors or custom HEX/RGB. Remove only removes explicit selected-zone color; existing inline formatting/class/links remain. A collapsed selection and the unsupported span-wrapping-block structure emit no field update. Inline images remain unimplemented.

Custom HTML changes only Fallback's preview trust decision. The current server actor must have unfiltered_html; an old saved editor or a client JSON assertion cannot authorize an unsaved payload. Anonymous and low-capability direct previews are filtered. An authorized current actor can still preview legitimate raw code, including document ID0. Editor filtering and public saved-editor behavior, draft/code storage, published snapshot selection, role capabilities, nonce and user-bound preview tokens remain unchanged. Low-capability previews of already-saved administrator code are intentionally filtered too.

The original isolated QA probe confirmed DOM execution only for B's own unsaved user-bound preview while persisted _edit_last was A. B's genuine save removed script tags and A's unrelated title change did not reactivate them. This is not evidence of stored cross-user XSS, public publication exploitation or production compromise.

Color prototype/fresh evidence: D:/Codex/Tasks/2026-10-30/richtext-color/outputs/HANDOFF.md and work/fresh-review.md. Custom independent fixture/fresh evidence: D:/Codex/Tasks/2026-10-30/custom-html-trust-fix/work/HANDOFF.md and fresh-review.md. These fixtures are separate from real WordPress acceptance. Root checked all baselines, backed up five targets and installed the new helper before Fields. Controlled integration manifest: D:/Codex/Tasks/2026-10-30/color-trust-integration/work/freeze-pin.json. Source PHP/JS syntax and diff whitespace checks passed.

## Custom actual WordPress acceptance

Evidence: D:/Codex/Tasks/2026-10-30/custom-html-trust-runtime/work/trust-runtime-check.md and .json. Three actual targets passed, JS errors0, on Fallback cb3325802390dd03fbc47e67a0dc12150ccdd788d4cfadf2189720d4945fc15b. The original isolated unsaved-preview trust mismatch is closed within this path.

1. Natural B retained edit permissions without unfiltered_html. The original unsaved preview returned200 with no sentinel script element or executed DOM marker. Its transient owner/document/payload matched B; persistent draft, published hash and last editor remained unchanged. B's preview of saved A code was filtered too.
2. After B's harmless save, A's current capability allowed a new raw preview despite lastEditor B. Both A/B editor responses remained filtered. An owned authorized QA endpoint verified document0 preview follows A/B capability, without claiming REST layouts/0 is editable.
3. Anonymous REST returned401 and natural no-edit subscriber403. A created a snapshot only on an owned unbound QA template. A separate cookie-free, currentUser0 QA endpoint read published_modules: it rendered that snapshot rather than the later draft. After B saved, the unchanged prior public saved-editor policy filtered script while snapshot SHA/publisher remained unchanged. This was an explicit temporary QA route, not normal production routing or publication of a real site.

The owner removed its three users, document/template/revisions/snapshot, three preview transients, temporary MU/private file and four closed profiles. Twelve source entries plus two theme files matched before/after. Old QA business fingerprints and original posts858/meta2555 SHA matched; mail0/orders0. QA service and older fixtures remain. No credentials, snapshots or fixture records are committed.

## Color B actual WordPress acceptance

Evidence: D:/Codex/Tasks/2026-10-30/color-runtime/work/color-runtime-check.md and .json. Four effective actual WP targets passed, JS errors0. Final sources: controls d6bfba7f, editor CSS891dba8b, Fields dccfc459 and new Rich_Html d7e08438; exact full pins remain in the integration manifest. These checks used real Registry metadata, native WP components, layout REST and signed theme/Fallback preview.

1. All22 native swatches matched the reference DOM values. Selected text Blue, native Undo/Redo, Save/GET/reopen and actual MediaText computed color agreed. Remove restored inherited #123456 in selected non-link text while preserving unselected color, inline formatting, class and link href/rel.
2. Global/item null survived Cancel and unchanged source Apply; explicit empty saved as empty. Equal desktop/mobile values still cleared pending source/palette on device changes. Real persistence preserved the complete modules object; unchanged colored global and empty-item source also did not rewrite it.
3. Item custom HEX c0ffee and RGB12/34/56 saved canonical #c0ffee/#0c2238 and computed correctly in actual Slogan output. Unsafe source did not introduce image/script/event markup.
4. Actual trusted server metadata alone opted in. A plain non-opt-in Rich text body kept its old policy despite attempted client JSON metadata. Unsupported span-wrapping-block selection emitted no update. Native Custom stayed above fullscreen at PC/390px with bidirectional Tab and layered Escape. Existing rich-link natural Tab/Shift+Tab stayed inside the same editor for40steps.

C2 alone was repeated after correcting an immediate assertion that raced the existing React scope effect; this required no source fix. The owner opened the actual desktop/narrow screenshots. All16 source/dependency/theme SHA entries matched before/after. It precisely removed its own document/template/revisions/user, ten previews, private file and closed profile, with no media upload. Older QA data, original858/2555 fingerprints and video16 remained unchanged; mail0/orders0. The QA instance remains for the continued goal.

Only selected text in opted-in fields is covered. Future typing color, arbitrary HTML selection shapes, inline images, arbitrary CSS/background, Gutenberg, physical devices and other browser engines are not enabled or certified. Independent source-owner fixtures and reference palette observation retain their separate scopes.

Next: continue Custom22 editor UI and the remaining reference blocks. Inline images remain open. No reference Publish, deployment or external integration.
