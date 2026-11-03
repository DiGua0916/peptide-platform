# Scoped bare-domain provider-site trial — 2026-11-03

Author: Codex.

The gateway's captured real response was HTTP 200, business code `0`, `data: null`, message `轮询系统里没有这个b站域名或者被停用`. The owner explicitly requested that the API's `site` be sent as the bare `vialcovepeptides.com` for the next test. This authorizes a controlled format trial; it is not evidence that the provider has accepted that format or that payment is fixed. The provider binding UI, public DNS and buyer/management/callback origins were not changed.

## Implementation

`Hub::provider_site()` reads the optional HQ-only WordPress option `pc_panapay_provider_site_bindings`. Version 1 maps `account_id:registered_store_uuid` to an exact integer site ID, current registered public hostname and explicit bare provider domain. The domain must be lowercase ASCII DNS, at most 253 bytes, and equal the public host or its dot-boundary parent. This is an explicit operator choice, not a public-suffix inference. An absent option/current binding retains the original public URL; malformed or stale current bindings fail before reservation. A copied store, changed UUID/account/site ID or changed public hostname does not inherit this binding.

The original authenticated payload/return validation runs first. Mapping is resolved before reservation; only a reservation winner assigns the final provider `site`, immediately followed by the original five-field signature generation. Store identity, request fingerprints, unknown-attempt fences, callbacks and product URLs retain their original paths. No historical attempt is rewritten or retried. This trial uses an operator-configured option rather than adding a customer-facing setting before the API format has been validated.

Forty-four pure checks execute the actual Hub, signature and URL validation with memory database/provider boundaries. They cover default behavior, scoped roots, tenant/account isolation, malformed/stale bindings before reservation, old unknown/non-winner handling, reservation conflicts, final signing and unchanged callback/product URLs. Independent review and a second run passed. PHP syntax/diff checks passed; no real provider request was made by these checks.

## Production delivery

The initial publication safely stopped at its source-hash precondition: another window had advanced the live customer-domain release. No mapping or release switch was made by that failed preflight. The verified provisioning checkpoint `da92dfe4cac6c345454058512e4cb876e5d9a5c3` and frozen-payment-return checkpoint `c07c6c29aa0599c35a84c63496ddd5223d5a66a3` were normally merged, preserving the live `attempt_return_url()` call and helper. The mapping checks passed again. The frozen-return suite also passed its 100 assertions; its existing fixture-isolation and legacy-ID scope limits remain as documented in the independent review and are not claimed resolved by this stage.

After a fresh verified encrypted HQ backup, deployed only the merged `class-hub.php` onto the actual live customer-domain release. Published release: `pay-provider-site-0be143e3e2c2`; source integration commit: `0be143e3e2c256e11fe178895b3360eafa58d032`; originating mapping commit: `085e299`. All 4,203 copied entries were checked and the final manifest contains 3,748 files. The operator option was added for only the selected existing store/account. Shared configuration and the previous source were byte-identical after publication. The rollback path restores only this publication's own manifest/pointer and only its still-matching option.

An outside-web, temporary production-FPM probe confirmed `providerSite` exactly `vialcovepeptides.com`, retained buyer/management URLs, default behavior for a different UUID, changed synthetic signature when the final site value changes, and the installed source SHA-256. It invoked no creation/transport/order method. Three public HTTPS checks returned 200. The temporary probe was removed. This is native configuration/signing verification, not a signed provider-session acceptance or charge.

The owner was handed one fresh private-window test, stopping on error or hosted checkout before card entry. Do not reuse/reset an earlier unknown checkout. Keep the bounded sealed-response observer available for that owner-operated result, then remove its temporary source/private artifacts when diagnosis ends. Private receipts/scripts and all credentials/ciphertexts remain outside Git under `D:/Codex/Tasks/2026-11-03/payment-provider-response`.
