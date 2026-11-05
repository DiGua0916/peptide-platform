# Published the approved GitHub runtime updates

2026-11-05 · Codex. The user requested overall GitHub synchronization because many UI changes had accumulated. This chat publishes the committed code to the existing servers; it does not redesign the UI or migrate the local development database.

## Source and scope

Runtime source is immutable main commit `3dcc4787f1f96bb348195b4396768cb887fd8427`, confirmed by fresh fetch and a later remote-main check. The repository release preparer exported 1,014 files; ZIP SHA-256 `65338dbf2a4b0f32aa5d9036da2578529ee4a5b8a3858c4f8a07790567353793`. Fifteen documentation/development-helper paths were excluded from deployment. Ops runtime tools and the formally used checkout-layout-preview PHP/JS remain included.

Published only the exact differing committed paths for each installed role, cloning its actual current runtime first. Nine roots received 1,347 file-instance updates from 208 distinct committed bodies: HQ 10; merchant first/second/third/template 95/156/156/149; buyer first/second/third/template 163/208/208/202. All other source, WordPress/WooCommerce/SMTP, MU plugins, private configuration, language catalogs, uploads/report links, identities and service configuration were preserved. Payment differences were traced to older committed blobs; 53 production identity/private-file/domain/signature guards already matched main. No newer unknown payment patch was overwritten.

This includes the approved storefront/mobile presentation, product/cart/checkout updates, native shipping-savings integration and matching committed plugin dependencies. The separate local MU preview skin, rejected comparison design and stopped domain-configuration WIP were not published. Local database-held layouts, app/coupon settings, products, content and customer data are not Git source and were not imported.

## Actual publication

Five existing encrypted backup services completed with fresh ciphertext header/ownership/size/SHA checks before publication locks. No archive was decrypted and no restore rehearsal was performed. Tenant backups cover business/configuration data; independent root-private source clones and per-file oldbytes preserve application rollback material.

The publisher used existing canonical lifecycle/payment/nginx/backup locks plus compatibility locks, immutable payload hashes, complete source manifests, source/configuration/pointer CAS, monotonic asset mtimes and PHP parsing of every changed candidate. All candidates on a node were prepared before its first active change. HQ and first-store current pointers changed atomically; existing s0002/s0003 public directories used dependency-first per-file atomic replacement and entrypoints last. Those flat roots are not a whole-site atomic transaction. Each node received a graceful FPM reload.

Admin completed at **16:22:37 Asia/Shanghai**:

- HQ: `/srv/vialcove/hq/releases/github-overall-sync-3dcc4787f1f9-admin-1793866792763885929-hq/public`
- Merchant: `/srv/vialcove/store-admin/releases/github-overall-sync-3dcc4787f1f9-admin-1793866792763885929-admin-s0001`
- Admin template: `/srv/vialcove-provision-template/github-overall-sync-3dcc4787f1f9-admin-1793866792763885929-admin-new-store-template/source`

Web completed at **16:26:31 Asia/Shanghai**:

- Buyer: `/srv/vialcove/releases/github-overall-sync-3dcc4787f1f9-web-1793867057434714997-web-s0001`
- Web template: `/srv/vialcove-provision-template/github-overall-sync-3dcc4787f1f9-web-1793867057434714997-web-new-store-template/source`

Extra-store root locations did not change. The provisioning profiles changed only their respective `source_admin_root` / `source_web_root` value, preserving all other raw bytes and values. Existing metadata was rebuilt from actual complete manifests; the previously known HQ CSS-only metadata drift was explicitly reconciled. No broker, worker, Nginx, DNS/CDN, payment account, order, customer, mail or database migration action was invoked by the publisher.

## Safe stopped attempt

The first admin attempt stopped while staging the template, before any live/profile write. Its new whole-tree nobody readability check did not match the installed worker: the existing template ancestor is intentionally root-private; admin copies it as root, and the current web profile reads its fixed bundle as root. Live manifests/pointers/profile were reread and remained original. The operator check was corrected to the actual consumer branches, independently reviewed and newly authorized. No private/ancestor permissions were relaxed, no failed mutation payload was rerun, and the stopped attempt/checkpoint remains evidence. The final v3 attempts above succeeded.

## Validation and limits

- Immutable candidate: 438 PHP lint, 188 JS syntax, seven isolated PHP groups, joint Node 123/123 passed. Initial missing exported fixture was corrected from the same commit; failures remain recorded.
- Ten key payment pure groups passed 1,197 assertions, with additional amount/return/territory/Blocks checks. Two older canonical fixtures lacked loading/DOM stubs; their failures remain disclosed, and independent complete harnesses passed 132/81 without application changes. This is not real payment acceptance.
- Independent post-publication verification passed 7,642 applicable committed-file SHA checks across nine roots and complete nonallowlisted preservation checks. Both profiles, canonical metadata, old releases/templates, private configurations, MU/language assets, mounts and database tunnels matched the modeled state.
- Twenty-six anonymous public HTML/language/login/static-resource checks passed with normal TLS and exact committed bytes for the selected CSS/JS. Six China direct-to-Cloudflare requests initially timed out; only those six were retried successfully through the user's already enabled system proxy. Initial direct failures are preserved, no global network settings were changed, and this does not establish reliable access from every domestic direct route.
- Backend hosts still connect directly; buyer apex/www remain on Cloudflare, with apex redirecting to www. Authenticated editors, a nonempty checkout submission, real payment, email and new-store creation were not exercised. The production preview URL was queued in the built-in browser; a fresh rendered UI screenshot was not obtained. The user can refresh and inspect the updated UI.

Evidence: `D:/Codex/Tasks/2026-11-05/github-overall-sync/outputs/`, especially `publication-admin-0b5edce413f630f8.json`, `publication-web-fa60bbab16e255ed.json`, `independent-post-source-summary.json`, `public-https-verification-final.json`, `fresh-backups-execution.json`, and the source/operator independent reviews. Root-private checkpoints are retained under `/var/lib/vialcove-ui-deploy/` on each node. No credentials or customer details are included here. No CRK room is bound.
