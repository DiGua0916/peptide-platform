# Store builder redesign checkpoint — 2026-10-30

Author: Codex. User authorized pulling the collaborator's GitHub work, committing the current work, and automatically synchronizing each subsequent completed phase. This is a development checkpoint, not a completed redesign or deployment.

## Incoming collaborator work

`origin/evan/work` at `40f3be5` contains the Data section, customer checkout capture and trusted native-action correlation, and already includes storefront branch `f9a1207`. Preserve its complete registrations, translations, event collectors and tests when integrating. Local payment support requires both a strictly local WordPress environment and an explicit `peptide_core_local_payments=yes` option; Git synchronization does not enable it or configure a payment provider.

## Current implementation

- Customer/HQ workspace updates and the existing core 0.32.0 / HQ 0.3.1 / ops 0.1.0 work are included in the backend checkpoint. Preserve their earlier handoffs and tests.
- The decoration editor has authored icons for 73 local types, a searchable library, compact properties, independent work regions, a narrow-pane switch, retry with the last usable preview, and device-aware controls.
- Activity tiers, coupon tickets, collection tabs, four review block types, manual featured reviews, merchant replies and the public review index are implemented. Review source/internal notes/edit history remain merchant-only. Review indexing uses bounded, signed continuation and reports unknown aggregates when it cannot establish a complete result.
- Site-link selection uses authenticated read-only lookup and confirmation-time visibility checks. It writes only the existing href, preserving button labels, targets and manual URLs.
- First basic batch: carousel, image, image row, image grid, and three/four/five-image compositions. Original keys, defaults, item IDs and legal device data remain. Optional richer descriptions distinguish null inheritance from an explicit empty value. Old row scrolling remains available alongside the new inline layout.
- Second batch is partially implemented: media/text properties and editor placeholder layout; card-list properties are in progress. Do not advertise masonry or the entire second batch as verified.

## Actual verification and limits

- The prior real local WordPress editor checks covered 29 scoped cases and their temporary original-site accounts/drafts/sessions were precisely removed. This is not a 73-type acceptance result.
- Reviews have independent real WordPress/WooCommerce, HTTP and native-browser evidence. The index completed 19 groups, with separate targeted SQL-failure, recovery, rating and invalid-parent regressions. Historical failed probes are retained in task reports.
- Site-link lookup completed seven actual API and eight native UI groups, zero JavaScript exceptions. Shared item panels and paired top-level device numbers completed seven actual editor/save/readback groups.
- First seven basic types and three commerce bindings completed 33 scoped actual-theme integration groups. An additional height probe found the unresolved carousel issue below; the passing cases must not be represented as covering that boundary.
- Independent pure/HTML fixtures remain explicitly separate from real WordPress results. Task evidence is under `D:/Codex/Tasks/2026-10-29/store-builder-redesign/work`; database dumps, credentials, accounts and synthetic business records are not part of Git.

## Work still open

1. Carousel: the existing theme's overlay image minimum height forces a configured mobile fixed height of 260px to render at 280px. The narrow fixed-mode CSS repair has not been applied at this checkpoint.
2. Rich-text editing: the client sanitizer currently flattens some old server-allowed h3/h4/span/link attributes. Preserve compatible old markup before treating the new rich-text controls as finished.
3. Common login target: prefer the actual public WooCommerce buyer account route when configured, with a clearly named native WordPress fallback. This small correction is not yet applied.
4. Card-list masonry and a legal old mobile five-column overflow case remain incomplete.
5. Continue one-block-at-a-time reference study, real rendering and association checks. The reference catalog has 70 blocks, while local definitions contain 73 types; these counts are not a coverage proof. Fifteen reference blocks have been studied and their temporary instances removed; the card-list reference is currently the next active scratch block, not completed.
6. TikTok and the order fulfillment board lack dedicated equivalents. AI and payment-related exclusions remain subject to the user's earlier deferral. Keep third-party payment, mail and search integrations unconfigured until separately supplied.

The reference site is a disposable 404 editor page. Never Publish. Delete only the temporary block added by this task. Existing business data and published layout records are not test fixtures.

An independent loopback QA site at port 56139, with a separate database, synthetic data and two ownership markers, is retained for further testing. Its owner must eventually verify those markers and precisely remove its own server process, database/account and private files. It is distinct from the already-cleaned original-site QA.

No server deployment, formal payment connection or complete redesign is claimed by this commit.
