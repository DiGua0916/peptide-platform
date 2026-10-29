# Gallery: horizontal layout and editable captions

2026-10-30 · Codex. Gallery phase verified; the complete builder goal remains active.

## Reference and implementation

Reference21 was added on the disposable404 page, edited, observed and removed without Publish. Existing media was selected only for the scratch block. Actual rich captions, image/homepage links and CTA binding worked. Native image dragging changed the wrapper0→-765.396px and its bottom indicator0→225.693px. A direct indicator drag produced no movement, so draggable-reference-bar parity is not claimed.

Phone images had uniform370px height and natural widths. Desktop measured343.396px image height in1029.33px content width; container-third is the local responsive choice, not an extracted reference CSS formula. The reference fullWidth switch changed maxWidth1440px→100%, retaining50px side padding; this viewport was below the cap, so actual width stayed equal.

- Registry adds gallery-only grid/rail mode. Existing records still default to grid; only new insertion defaults to rail. The original six-item default, columns, IDs, media and URLs remain.
- Nullable descriptionHtml/captionHtml preserve legacy text for null and clear it for an explicit empty value. Separate figure/caption links avoid the reference's repaired nested-anchor markup. A caption with its own link keeps that link; the image still uses the item URL.
- Rail images share a desktop container-third height or370px phone height and natural aspect ratios, with safe square fallback and bounded extreme metadata. Captions grow naturally. Legacy null-caption grid output is byte-identical.
- Native overflow, mouse drag, keyboard controls and a real24px range control move actual content. Range and scroll update each other, including RTL handling. No autoplay or lightbox was added. No-JS keeps SSR content, links and native scrolling.

Changed files: Registry, Fallback, modules.css/js. Final prefixes:0fb3bb81/1a9ab376/27ee8e20/100aea12. Fields, symbols and the verified shared rich-text assets are unchanged. Full pins: D:/Codex/Tasks/2026-10-30/gallery-integration/work/freeze-pin.json.

## Actual verification

Independent fixtures completed six groups plus one later pointer-release boundary check, with their version scopes kept separate.34 other basic/shared definitions and all old Gallery field contracts were unchanged. Fresh review found no new P1/P2 or source blocker.

Five actual WordPress/Woo targets passed, JS0: native new/legacy insertion and rich/null/empty persistence; actual theme proportions,370px phone/30px gap,785px unclipped caption and fullWidth1200→1920; range/scroll/keyboard and160px image dragging with a later normal link click; independent caption/image/CTA targets and real site-link lookup/save/reopen; old phone grid counts1–6 and no-JS SSR/native scroll. See D:/Codex/Tasks/2026-10-30/gallery-runtime/work/gallery-runtime-check.md/.json. Initial G2/G4 harness label/class mistakes were corrected only in tests, with just those targets rerun. This is not touch, RTL or all-browser real-WP acceptance.

One missing portrait fixture was created only in the independent QA site and precisely removed, together with its marker directory, temporary layout/template/revisions/user, five preview transients, credentials and two profiles. Existing media/data and original858/2555 read-only fingerprints remained unchanged, mail/orders0. The independent QA service and previous fixtures remain for continued work. Source-owner retained fixture profiles were not touched.

Next: Custom HTML22; separately close the still-missing current-phase MediaText15 actual-theme checks and finish rich-text colors/inline media. Payment/mail/search remain empty and AI deferred. No deployment occurred.
