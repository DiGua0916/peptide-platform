# Customer tracking surface

Mode: Operate. Audience: store operator. The user confirmed a compact monochrome customer list → detail structure and prioritises customer interests. Preserve the existing store, capability checks, carts, visitor history and evidence timeline. Individual AI analysis and site AI analysis require separate personas and configuration; compatible API provider details come later.

## Direction contract

THESIS: make retained customer history readable in a bounded work pane; separate event reading from interest reporting and keep technical details expandable.

OWN-WORLD: inherit DESIGN.md's white working surface, neutral gray rail, Manrope/system Chinese text, 6px controls and 12px outer panel. Flat tables, tabular numerals and space between sections carry hierarchy.

STORY: find a customer, open their detail, choose a day/week/month, compare page attention, module clicks and product interactions, then inspect the original timeline. Statistics are observations, never a claim of inferred personal attributes.

FIRST VIEWPORT (updated by user 2026-11-05): compact identity strip, two keyboard tabs. Default history has a left visit list and right newest-first event table, each in a bounded scroll region; no per-event cards. On narrow panes these stack, with a short visit rail and explicit horizontal-table guidance. Interest report lives in the second tab. This is a scoped exception to the incumbent tracking-card description. Previous report composition retained in that tab: Title/date/export row; short cycle control row; one horizontal definition list; page attention and module click tables beside each other on desktop and stacked on mobile. Product interest follows with the dedicated customer AI state, then original visit timeline. Live list uses search, status filter and pause rather than constant uninterruptible refresh.

FORM: code-led existing-world extension, reading desk with real report tables. seed: none (unavailable). Signature interaction: switching report period cancels stale requests and disables export until a matching complete response is accepted.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.

Preview screenshots are actual browser renders of the shipping insight component with explicitly labelled synthetic data; they do not prove production data rendering. Desktop: outputs/customer-interest-desktop.png; mobile 390px: outputs/customer-interest-mobile.png in D:/Codex/Tasks/2026-11-05/customer-insights. No document overflow at 390px; the product table scrolls inside its labelled focusable region. No raster ships in the application.
