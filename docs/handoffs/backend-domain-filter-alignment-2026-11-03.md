# Domain filter toolbar alignment

2026-11-03 Codex. The owner highlighted the customer backend's domain management filter controls as uneven. This refinement preserves the existing monochrome workspace, filter fields, native date picker, GET parameters, permissions and domain data.

Only `wp-content/plugins/peptide-core/assets/domains-admin.css` changes at runtime. Scoped rules set the six visible controls to a shared 36px border box, zero child margins and a 20px text line; selects reserve 32px for their existing native arrow. The two dates use equal grid tracks and keep the separator together. Narrow screens use 40px controls, wrapping status selectors and then a full-width date group; search and Filter share the next row. No global input rule or storefront theme is changed.

Actual existing LocalWP domain page verified six controls at the same y coordinate and 36px height on the 1280px viewport, with 32px select arrow padding. At 390px the toolbar had equal scroll/client width (343px), dates shared a row, search/Filter shared a row, and every control was 40px tall. Final responsive selector specificity was corrected to preserve equal status selector flex growth. No separate test site was created and no domain or DNS action was submitted. Impeccable layout detector and Git whitespace checks passed. Server publication and final browser evidence follow below.

## Published result

The final 390px browser check confirms equal 167.33px status selectors, equal 158.70px dates, 40px controls and zero toolbar overflow. Desktop 1280px confirms all six controls at y=362.67 and height=36px, with 32px select arrow padding. The default viewport was restored. Screenshot and computed DOM measurements are in task outputs; this is the existing local native page, not a deployed test environment.

Feature commit `c235e3e6b0d21d9ad16253e4d52bf49ea46e23ba` was published to the customer backend release `/srv/vialcove/store-admin/releases/domain-filter-c235e3e6b0d2`, preserving the actual `6f20b62-admin-mail-ready-20261103` baseline. 12,659 copied entries were verified; the separate customer-backend manifest records 11,047 files. Only domains-admin.css changed (SHA256 `a5fd6b79fd6e5a12f3b8446d82f1d086fd03594e6ba9e1eaabbac002a81560a5`). HQ's release/manifest and the public storefront runtime were not changed. The old release and private rollback checkpoint remain available.

Both the runtime configuration wrapper and the actual included shared production configuration were preserved byte-for-byte. The initial guard checked the wrapper for an inline environment define and safely stopped before any server writes; read-only discovery established the shared configuration source, and the guard was corrected to validate that source. No environment protection was removed. No domain, DNS, primary-domain toggle, filter data, credential or database setting was changed.

Fresh encrypted customer-backend backup: 30,920 bytes, ciphertext SHA256 `28d007d79b1bcc5ab96ef09b3128c4ba5798a6209ea197bc6f81acb0ee5f6cec`, copied privately to D and hash-matched; decryption was not exercised. FPM reload and the HTTPS login check passed. An independent HTTPS GET of the formal domains-admin.css returned 200 and matched the exact new source hash.

The owner reported login completed, but this tool's fresh formal tabs still returned the login page. No authenticated formal-page DOM/screenshot is claimed. A fresh formal domain-management entry is retained in the in-app browser; existing login/input tabs were preserved. The source layout was visually verified on the existing local page, and formal delivery was verified through the deployed asset hash. Evidence root: `D:/Codex/Tasks/2026-11-03/domain-filter-alignment/outputs/`.
