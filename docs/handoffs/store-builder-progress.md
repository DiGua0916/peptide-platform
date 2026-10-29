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
- After the integration checkpoint, five targeted real WordPress groups verified buyer-account login/fallback, preservation of old legal rich-text markup during editing and saving, rejection of unsafe pasted markup, and a plain-text HTML fallback for legal legacy links that the visual editor cannot represent. The server allowlist and URL policy were not expanded.
- The fixed carousel height follow-up passed two real-theme probes: desktop image340px and mobile image260px, with the CSS source, QA copy and actual browser response matching. Forty separate native-browser fixture groups preserved auto/screen geometry, long-copy growth and position controls; those fixtures are not additional real WordPress cases.
- First seven basic types and three commerce bindings completed 33 scoped actual-theme integration groups. An additional height probe found the unresolved carousel issue below; the passing cases must not be represented as covering that boundary.
- Independent pure/HTML fixtures remain explicitly separate from real WordPress results. Task evidence is under `D:/Codex/Tasks/2026-10-29/store-builder-redesign/work`; database dumps, credentials, accounts and synthetic business records are not part of Git.

## Work still open

1. Card-list masonry, interactive carousel controls and legal old mobile column/gap overflow cases are being implemented; this checkpoint does not include or certify those in-flight files.
2. Continue one-block-at-a-time reference study, real rendering and association checks. The reference catalog has 70 blocks, while local definitions contain 73 types; these counts are not a coverage proof. Fifteen reference blocks have been studied and their temporary instances removed; the card-list reference is currently the next active scratch block, not completed.
3. TikTok and the order fulfillment board lack dedicated equivalents. AI and payment-related exclusions remain subject to the user's earlier deferral. Keep third-party payment, mail and search integrations unconfigured until separately supplied.

The earlier carousel minimum-height, rich-text compatibility and buyer-login items above are resolved within the specifically stated checks. See backend-builder-compatibility-2026-10-30.md and the latest frontend handoff; this is not a declaration that every editor or legacy HTML protocol has been exercised.

The reference site is a disposable 404 editor page. Never Publish. Delete only the temporary block added by this task. Existing business data and published layout records are not test fixtures.

An independent loopback QA site at port 56139, with a separate database, synthetic data and two ownership markers, is retained for further testing. Its owner must eventually verify those markers and precisely remove its own server process, database/account and private files. It is distinct from the already-cleaned original-site QA.

No server deployment, formal payment connection or complete redesign is claimed by this commit.
