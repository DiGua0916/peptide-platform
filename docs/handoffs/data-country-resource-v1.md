# Country resource attribution

Country observations retain the existing local-only provider, global-address validation, Woo country catalogue validation and honest unknown/unavailable states. Attribution appears only when an explicit readable local database and the exact PC_DATA_GEOIP_ATTRIBUTION = 'db-ip' configuration are present. Ordinary Woo/MaxMind configuration receives no DB-IP credit by default.

The Data wrapper and the storefront footer show the fixed, escaped [IP Geolocation by DB-IP](https://db-ip.com) credit. No configured path, arbitrary URL, request address or HTML is printed.

The operator must configure PC_DATA_GEOIP_DATABASE to an absolute readable local MMDB path and PC_DATA_GEOIP_ATTRIBUTION to db-ip. The resource and machine-specific bootstrap remain outside committed application source. Existing Woo-managed GeoLite2 fallback still works without these attribution constants.

Root verified DB-IP Country Lite October 2026 against the official uncompressed representation: 8,348,509 bytes; SHA256 dbd70ccfa2a13627eaf4913d19920a1a229f1b3c5439ac01c509995b6364202a; MD5 b8647f712bcabbe01f8afe3ff9e7569e; SHA1 e515b63075ad48847f9c4332b724742e8ba15b0e. Download information and license attribution: https://db-ip.com/db/download/ip-to-country-lite. DB-IP Lite is CC BY 4.0; retain the displayed DB-IP link when using this resource.

The pure attribution test loads actual provider, Data renderer and footer callback with PHP platform boundaries. The separate local child probe uses the verified MMDB plus Woo's Reader and native country catalogue without a WordPress bootstrap or customer event. This is source preparation; service activation and customer-journey acceptance remain separate.
