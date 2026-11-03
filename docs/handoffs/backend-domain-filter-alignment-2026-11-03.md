# Domain filter toolbar alignment

2026-11-03 Codex. The owner highlighted the customer backend's domain management filter controls as uneven. This refinement preserves the existing monochrome workspace, filter fields, native date picker, GET parameters, permissions and domain data.

Only `wp-content/plugins/peptide-core/assets/domains-admin.css` changes at runtime. Scoped rules set the six visible controls to a shared 36px border box, zero child margins and a 20px text line; selects reserve 32px for their existing native arrow. The two dates use equal grid tracks and keep the separator together. Narrow screens use 40px controls, wrapping status selectors and then a full-width date group; search and Filter share the next row. No global input rule or storefront theme is changed.

Actual existing LocalWP domain page verified six controls at the same y coordinate and 36px height on the 1280px viewport, with 32px select arrow padding. At 390px the toolbar had equal scroll/client width (343px), dates shared a row, search/Filter shared a row, and every control was 40px tall. Final responsive selector specificity was corrected to preserve equal status selector flex growth. No separate test site was created and no domain or DNS action was submitted. Impeccable layout detector and Git whitespace checks passed. Server publication and final browser evidence follow below.
