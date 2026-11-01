# Automatic traffic channels v1

`traffic-v1` is this project's explicit grouping policy. It is not an exact implementation of Shopname or GA4 channel attribution. The conceptual source/medium categories were reviewed against Google's default-channel documentation; the bounded rules below are the contract.

The derived `channel` uses the existing first successful saved attribution snapshot of the original visit. Report receipt cutoff is exclusive; mismatched visit/visitor ownership, uncaptured snapshots and snapshots received at or after cutoff stay `unobserved`. No capture, backfill, order, financial, country or raw UTM behavior changes. Normal/unclassified conversion subsets use the same common dimension adapter.

Raw UTM source/medium values retain their original case, whitespace and missing/empty states. Classification lowercases and trims only local copies. A nonempty source or medium is explicit tagging and takes precedence over referrer. Empty/whitespace tags proceed to the observed referrer.

| Explicit medium | Channel |
| --- | --- |
| `email` | `email` |
| `paid_social`, `social_paid`, `paidsocial` | `paid_social` |
| `cpc`, `ppc`, `paidsearch`, `paid_search` | Recognized social source: `paid_social`; recognized search source: `paid_search`; otherwise `paid_other` |
| `organic` | `organic_search` |
| `social`, `social-network`, `social-media`, `sm` | `organic_social` |
| `referral`, `app`, `link` | `referral` |
| `display`, `cpm`, `banner` | `display` |
| `affiliate`, `affiliates` | `affiliate` |
| `paid`, `retargeting` | `paid_other` |
| Other nonempty explicit tags | `tagged_other` |

The explicit pair source `(direct)` and medium `(none)` maps to `direct_or_hidden`. Source names recognized for paid classification are `google`, `bing`, `yahoo`, `duckduckgo`, `baidu`, `yandex`; and `facebook`, `fb`, `instagram`, `tiktok`, `x`, `twitter`, `pinterest`, `reddit`, `linkedin`, `youtube`, `meta`. Hosts in the following corresponding allowlists, including dot-boundary subdomains, also qualify. Arbitrary substrings do not.

Without meaningful tags, exact host or dot-boundary subdomains of these saved referrers classify as:

- Search: `google.com`, `google.co.uk`, `google.com.hk`, `google.cn`, `bing.com`, `search.yahoo.com`, `duckduckgo.com`, `baidu.com`, `yandex.ru`.
- Social: `facebook.com`, `fb.com`, `instagram.com`, `tiktok.com`, `x.com`, `t.co`, `pinterest.com`, `reddit.com`, `linkedin.com`, `youtube.com`.

A referrer matching the actual entry host or its subdomain is `internal_referral` before external search/social matching. Another valid external host is `referral`. Observed empty referrer is `direct_or_hidden`, visibly labelled **直接访问／来源隐藏**; it does not establish that somebody typed a URL. Null/unavailable referrer is typed `unknown`. `google.com.evil.test` and `evilgoogle.com` never match Google. No IP inference, DNS lookup or additional referrer URL is used.

Conversion adds `group=channel`; only this new group includes `channel_policy: "traffic-v1"`. Its existing 17-column CSV gains policy/label records without changing the old groups' CSV. Channel groups retain the existing distinct-visitor reduction; a visitor can belong to different channels on different visits, so group UVs must not be summed into overall UV.

Sources defaults to the existing UTM grouping. Its default JSON and 18-column UTM CSV remain unchanged. Opt in with `group_by=channel`; the parameter accepts only exact `utm` or `channel`. `channel` plus `split_medium=1` is rejected. The UI resets/disables medium split in channel mode and exports only an accepted request context.

Channel JSON adds `group_by`, `channel_policy` and coverage. Rows have typed `channel`, localized `channel_label` and the existing ten metrics. `coverage.visits` counts visit-start rows, while `coverage.orders` counts eligible native orders by exact original owner; each separates `value`, `unknown` and `unobserved`. Coverage covers all groups matching the text search before pagination. It is not a measure of total physical reach or proof of complete collection.

Channel CSV uses the same complete selection, ignoring pagination: channel state, token, localized label, policy, currency, period bounds, eight visit/order coverage counts and ten metrics (25 aligned columns). Rates remain percentages; zero denominators remain null/empty. Search, sorting, date selection, refunds/test exclusion and native financial definitions are shared with the existing report. Formula escaping and stale JSON/CSV guards remain active.

Portable focused checks execute the actual pure classifier, saved-fact reader, Sources report/CSV, classified conversion and UI renderer against in-memory boundaries. Frozen d19 Sources/CSV classes in `wp-content/plugins/peptide-core/tests/fixtures/071` are the default-UTM oracle; their provenance file records commit and hashes. These checks are source verification, not browser or live customer acceptance.
