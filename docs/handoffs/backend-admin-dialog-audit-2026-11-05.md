# Admin dialog audit and deployment — 2026-11-05

Author: Codex. Request: replace browser-native `alert`, `confirm`, and `prompt` throughout HQ and merchant admin, including the retire-store confirmation in the supplied screenshot. Do not remove confirmation protection.

## Implementation

- Replace the old Core trusted-click interception/temporary native-confirm override/replay shim with explicit `VialCoveDialogs` asynchronous APIs, a native top-layer HTML dialog, safe Cancel focus, Escape handling, text-only messages, queued dialogs and focus/selection restoration. Native browser APIs are not globally overwritten; arbitrary click handlers are not replayed.
- Migrate owned PHP inline handlers to declarative guards, and owned JS handlers to explicit asynchronous decisions. Handle form and link defaults synchronously before awaiting. Use native `requestSubmit` with the original submitter and a one-use approval; retain HTML validation, nonces, capability checks and server payloads. Freeze form configuration, fields and submitter settings while awaiting; use native form getters because named controls can mask `form.action` and `requestSubmit`.
- Adapt 13 legacy WooCommerce 11.2.1 scripts, 2 Woo React client adapters, and 14 WordPress 7.1.4 scripts as reviewed, version/hash-matched plugin assets. Preserve upstream files. Propagate async boolean callers, preserve the Customizer `requestClose()` jQuery Deferred API, and prevent default actions before awaiting. Admin script concatenation is disabled to preserve the normal dependency order for adapted core scripts; frontend scripts are unaffected.
- Cover legacy literal inline confirms, `showNotice.warn()` guards and the unused-result Get Shortlink prompt without evaluating handler source. Unknown inline native-dialog handlers stop safely with an in-page notice rather than auto-approving.
- Keep browser-controlled `beforeunload` protections. Public storefront language switching and CLI confirmations are outside this admin task and remain unchanged.

## Runtime scope

HQ and s0001 use cloned immutable releases under `/srv/vialcove/hq/current` and `/srv/vialcove/store-admin/current`, with regenerated release manifests, fresh source/CAS guards, private rollback checkpoints and FPM reloads. Existing language-dropdown work was preserved from the actual live release, not rolled back to an older checkout.

s0002 and s0003 use `/srv/vialcove/tenant-admin/<slug>/public`. Their changed files were guarded, backed up privately, then replaced atomically per file. Their older design-editor source was changed only at its four confirmation functions; the absent newer shell was not introduced.

New-store provisioning used a separate pinned admin template, not the live s0001 root. Clone that source and apply the same targeted fixes, retain its older editor/business code, verify the 84 loader contracts, then atomically update only `source_admin_root` in the private root configuration. Preserve every other configuration value. No real new shop was created. Current source and release details are in the task receipts below.

No buyer-site deployment, live refund, account revocation, store/domain/media deletion, product save, payment, or email test was performed. Reading an existing product editor can cause normal WordPress edit-lock/heartbeat activity; do not describe the entire browser session as zero database writes.

## Verification and evidence

Task: `D:/Codex/Tasks/2026-11-05/admin-dialog-audit`.

- `admin-dialogs.cjs`: confirmation/prompt cancellation, Escape and focus, text safety, original submitter, changed action/fields/button configuration, deduplication, legacy default and inline guards, async refund-field context and native property masking.
- `admin-vendor-dialogs.cjs`: 22 Woo methods, 57 checks; cancelled decisions issue no mocked mutation request.
- `admin-wordpress-dialogs.cjs`: 17 WordPress methods, 48 checks; cancelled decisions issue no mocked mutation request and reviewed assets contain no native calls in their ASTs.
- `admin-client-dialogs.cjs`: 24 checks cover history.push void return, stale intent, cleanup, predicate bypass, JSONP registration without a runtime, and reset cancellation/approval with mocked state updates.
- `admin-customizer-contract.cjs`: 10 checks, jQuery Deferred `.done/.fail` preserved and cancellation does not dismiss locks/autosave.
- Owned admin coverage: 471 PHP/JS files examined, plus installed upstream/native-inline and other-plugin surveys. The FluentSMTP `confirm(){...}` result is a Vue method declaration, not browser confirm.
- HQ router: 85 contract + 181 all-page synthetic checks. Merchant shell: 25 lifecycle + 21 policy checks. These are models, not a complete live business-flow or performance benchmark.
- Actual installed source loader contracts: 58 HQ + 84 each for s0001/s0002/s0003 = 310 checks. New admin template: 84 additional checks. Normal/minified/relative script paths and unknown-version/foreign-URL guards checked.
- Adapter generators reproduce every JavaScript and manifest byte. Licenses and maintenance instructions are included beside generated adapters.
- Actual IAB: HQ retire-store confirmation opens as a nested in-page dialog; Cancel leaves the tool open. Merchant domain deletion opens in-page and Cancel leaves the domain listed. The product editor actually loads Woo and WordPress adapter URLs. Its native media picker opens normally, permanent-delete opens the in-page dialog with Cancel focus, Cancel retains the attachment, and the picker was closed without setting/saving the product image. Hidden input values were redacted by the browser inspection API; do not assert them as directly inspected.
- `outputs/hq-confirm-installed.png`, `outputs/merchant-media-confirm-installed.png`: real screenshots. `origin-verification.json`: final four origins respond HTTPS 200/TLS verify 0 and have no owned admin native call sites. Do not confuse preserved original upstream files with the adapted scripts actually served by the loader.
- Deployment receipts: `deployed.json`, `tenants-deployed.json`, `compat-deployed.json`, `final-deployed.json`, `latest-deployed.json`, `seed-deployed.json`, and final packaging receipt when present. Private server checkpoints are referenced by those receipts; no credential values are copied into this repository or memory.

## Deep bundle audit

A final recursive survey also checked minified/dynamic assets, not only legacy admin sources. Two live backend calls were found in Woo navigation and the analytics-settings chunk and adapted. The remaining hits are buyer-site review/cart validation and the optional stdin shim of a Vips Web Worker: it has no Window and cannot show a browser dialog in worker execution. These are outside admin action scope. Other installed plugin assets have no native window calls; the FluentSMTP bare confirm declaration is a Vue method.

The analytics chunk is registered as a blocking dependency of registered Woo client scripts before either head/footer printing. Its original upstream hash is verified, its factory registration is inert until imported, and its versioned plugin URL avoids relying on cached native chunks. Do not replace native confirm with a Promise or remove the dependency order. The client adapter generator is a separate, reviewed step after the legacy generator.

## Maintenance boundary

Coverage is for the currently installed, audited builds. A changed upstream hash/version deliberately leaves upstream source untouched instead of guessing a patch. Future WordPress/WooCommerce/plugin updates therefore require a fresh scan, reviewed adapter generation and loader/cancellation tests before release; native prompts can otherwise reappear. Do not claim this is a universal patch for arbitrary future third-party code. Browser close/refresh unsaved-data warnings remain intentionally native.

## Final accepted deployment

Behavior source: `4e91486` for the shared dialog/native form getter guard; `c01e090` adds the two Woo React adapters and dependency preload. HQ current remains `/srv/vialcove/hq/releases/dialog-compat-4e91486-1793827370-hq`; merchant current is `/srv/vialcove/store-admin/releases/dialog-compat-c01e090-1793829173-merchant`. The existing s0002/s0003 public roots are updated, and the pinned new-store admin source is `/srv/vialcove-provision-template/dialog-compat-c01e090-1793829173/source`.

`outputs/client-deployed.json` records 414 installed loader checks (58 HQ and 89 for each of the three merchant roots plus the new-store template). `outputs/end-state.json` verifies the provisioning profile differs only at source_admin_root, the seed helper matches the live helper, no additional tenant directory was missed, and remaining recursive native-window hits are the buyer scripts and non-Window worker fallback described above. All four admin origins returned login HTTPS 200/TLS verify 0; helper bytes match on every live root.

Final live acceptance also verified the exact HQ retire confirmation after the native form getter fix: Cancel is focused, Escape closes only that confirmation, and the underlying tools dialog and form remain. The Woo React analytics settings page lists the reviewed chunk before the navigation/app entry scripts. Reset defaults opens the in-page confirmation; Cancel leaves all inspected visible input/select/textarea states exactly equal to the pre-click baseline. No reset/save was performed. Evidence: `outputs/merchant-analytics-confirm-installed.png`. Only the comparison boolean was retained, not raw settings or customer records.

Final model counts: shared dialog 33; legacy Woo cancellation 57/22 methods; WordPress cancellation 48/17 methods; Customizer Deferred 10; Woo client history/chunk/reset 24; HQ router 85 + 181; merchant shell lifecycle 25 and policy 21. Legacy plus client generators and the WordPress generator reproduce the reviewed JavaScript/manifest bytes. These do not claim a live approval test of destructive operations or a guarantee for unreviewed future versions.
