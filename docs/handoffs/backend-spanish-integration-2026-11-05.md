# Spanish storefront integration — backend handoff

2026-11-05 · Codex. This handoff records the existing LocalWP installation and read-only acceptance. Production remains pending; no production deployment or customer purchase/payment acceptance is claimed here.

## Language and content boundary

The independent language resolver retains manual `en`/`es` preference priority, otherwise uses only the existing local country provider with canonical `REMOTE_ADDR`. Local settings enable automatic detection with default English, US English and PR Spanish. Real translation uses the separate `pc_storefront_translation_enabled` option, enabled locally; it does not overwrite the English database, duplicate products, change Woo product/variation IDs, split sale units, change prices/stock, translate COA/PDF files or invoke an external translation service.

The source-backed content artifact contains 1603 exact EN→ES text-node mappings plus 328 preserved product/scientific names, formulas, numeric values, sequences and similar tokens. It covers all 82 main product long descriptions, five nonempty short descriptions, 183 variation descriptions, public page/menu text, and 153 known decoration text leaves (89 under active template 684). Its optional full-field review artifacts carry object ID, field and original SHA256, but the shipped translator uses exact whole-text-node mappings and bounded UI templates, not full-field database overrides. Unmatched/new content falls back until translated. Source claims were translated without silently resolving factual or storage contradictions; 28 source products have explicit review notes in the task artifact.

Task root: `D:/Codex/Tasks/2026-11-05/spanish-integration`. Full content artifact: `work/translation-content.json`, SHA256 `5153214b7bb936edce9758f9b30e3e06655c0be562e351f1ebca2e2525e08d2b`. Root owns the integrated runtime dictionaries and UI collision policy.

## Local resource installation

The existing LocalWP instance is `D:/Codex/Sites/vialcovepeptidescom/app/public`, WordPress 7.1.4 / WooCommerce 11.2.1. Official version-matched `es_ES` core catalogs (73 MO/JSON files) and Woo catalogs (233 files) were installed in its physical `wp-content/languages` directory with all 306 file hashes verified. No prior same-name Spanish files were present; unrelated languages were preserved.

DBIP Country Lite is outside the public root in the task's private resource library. Both previously absent constants, `PC_DATA_GEOIP_DATABASE` and `PC_DATA_GEOIP_ATTRIBUTION`, were inserted into the private local configuration after backing it up. All other original configuration bytes were preserved. Configuration/backup bodies and credentials are excluded from Git and public artifacts. Native POMO reads Spanish core/Woo samples; the actual native country provider resolves a fixed public-address DB fixture to PR. This is a local database fixture, not a real Puerto Rican network visit.

Root installed the application sources; this subtask did not copy plugin/theme source. The local locale class reviewed at enable time was SHA256 `64f65b63487b85198e8dde800bce3bee2927a84d9a146c195721a866944dba29`.

## Actual local acceptance

- Native WP CLI `update_option()` wrote only the two dedicated language options. Original values were backed up separately without credentials. The process SQL guard recorded these two writes, zero transient writes and zero rejected writes. Product records, product meta and basic CPT/HPOS order hashes were equal before/after. A later read after public HTTP remained equal.
- Eight anonymous HTTP GET cases passed: home, Retatrutide PDP, COA library and delivery page, each in English and Spanish. Spanish HTML has `lang=es` and actual Spanish body content; English remains `en-US` with original body. All were 200 with private/no-store and Vary Cookie.
- Public Retatrutide quickview `GET /wp-json/vialcove/v1/home-product/11006` returns Spanish description and native Spanish purchase text with Spanish preference. All nine actual variations retain identical IDs, raw attributes, native display/regular amounts, stock/purchase flags and quantity limits between EN/ES. Initial variation 11007 remains the same; unit-price metadata retains USD 8630 minor units per existing sale unit and 863 minor units per vial, ten vials, with no single-vial purchase authority.
- Anonymous Spanish `GET /cart/` loaded eight real inline WP/Woo script translation catalogs, each with native JSON `lang=es`, including cart-checkout-base, data store and cart-frontend. Anonymous `GET /checkout/` correctly redirected to the empty cart. No existing administrator/Woo session was supplied, so the browser's existing five cart items were not used, cleared or modified. This does **not** prove the populated checkout screen.
- Native script-catalog loading loaded the actual installed Spanish cart-checkout-base catalog. CLI does not automatically register cart/checkout frontend handles without rendering those blocks; the first probe records that limitation. A second bounded in-memory registration of the real installed Woo assets allowed WordPress's native `load_script_textdomain()` to load Spanish cart and checkout JSON catalogs (23 and 60 entries). This proves resource/loader compatibility, not checkout browser execution.
- Native nested locale API switching `es_ES → en_US → es_ES → original` succeeded without any email/order callback. CLI excludes the storefront adapter, so this is not a live Woo email adapter proof and no email was sent.

Anonymous HTTP generated the site's existing `pc_vid` cookie; product pages also issued recent-product cookies. Cookie values are not in receipts. No tracking JavaScript/API, cart mutation, checkout submission, order, customer or payment operation was invoked. Structured embedded DTO attributes deliberately preserve machine values; embedded price/promotion prose still requires client-mounted UI review rather than being mistaken for verified complete JS translation.

## Evidence and remaining work

Task receipts: `outputs/local-resource-install.json`, `local-resource-ready.json`, `local-feature-options-before.json`, `local-feature-enable.json`, `local-anonymous-http.json`, `local-after-http.json`, `local-quickview-language.json`, `local-cart-script-catalogs.json`, and `local-native-script-locale.json`. Initial script-registration limitation is retained in `local-native-script-locale-initial.json`.

Earlier content inventory rejected three plugin bootstrap DELETE attempts under its stricter read-only guard, with before/after content/product-meta/options hashes equal. That extraction returned exit 1 honestly; it is not reported as zero attempted writes. Subsequent feature enable and native verification recorded no rejected writes.

Root still needs to complete browser/mobile/client-mount review, the populated existing-cart checkout read, current metadata-controller synchronization/acceptance, and the separately guarded production resource/source installation. No real payment/order is needed or authorized by this translation acceptance. Production DNS/CDN, deployment, end-to-end checkout/payment and real multi-country traffic are outside the passed scope above.
