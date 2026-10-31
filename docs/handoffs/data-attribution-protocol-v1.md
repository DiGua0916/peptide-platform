# Optional visit attribution v1 — receiver and source-owner handoff

Receiver implementation baseline: `3ed5fc14d41ae290b8a4a85c5c23247adc5f9bb6` (Plan023). Transport remains protocol1. Attribution is an optional, independent extension, version1. This document does not authorize deployment or changes to the storefront producer.

## Transport and identity

Use the existing same-origin `POST /wp-json/peptide/v1/track/events`. Optional `GET /wp-json/peptide/v1/track/session` reports the existing tracking/configuration state; it is not a mandatory initialization step. No new authentication or nonce requirement is introduced. The server issues an HttpOnly `pc_vid` cookie; clients never supply visitor or visit database IDs. Session/configuration response shapes are unchanged.

Synthetic envelope (replace timestamps with fresh values before an authorized local test):

```json
{
  "protocol": 1,
  "tab_id": "synthetic-tab-0001",
  "sent_at": "2030-01-01T12:00:00Z",
  "events": [{
    "event_id": "synthetic-event-0001",
    "page_instance_id": "synthetic-page-0001",
    "seq": 1,
    "type": "page_view",
    "occurred_at": "2030-01-01T12:00:00Z",
    "page": {"page_type": "content", "path": "/synthetic-entry/"},
    "data": {
      "cause": "navigate",
      "attribution": {
        "version": 1,
        "host": "example.test",
        "landing_query": {"utm_source": "synthetic_source", "utm_medium": "synthetic_medium"},
        "referrer_host": ""
      }
    }
  }]
}
```

`events` is required, with at most20 entries; request body limit65536 bytes. Existing event requirements remain: `event_id`, `page_instance_id`, known `type`, valid `occurred_at`. IDs accept8–64 ASCII letters/digits/hyphens. `tab_id`, `sent_at`, `seq`, `page` and `data` retain their existing optional/default behavior; omitted `protocol` remains accepted. Existing page cleaning stores a relative path without arbitrary query/hash. Optional attribution does not tighten any legacy event requirement.

The existing send-clock offset is used only when within24hours; occurrence is clamped to receipt time and events older than24hours are rejected. Existing visitor30minute inactivity/rollover, staff exclusion, tracking switch, GPC/opt-out, request limits and rate limits are unchanged. Tracking-disabled/staff/declined return existing403;429 retains `Retry-After` and requires waiting. This extension does not bypass those gates.

## Strict attribution rules

Only a valid core `page_view` may capture `data.attribution`. The extension key's presence is meaningful: absent is legacy/unknown, whereas present null is invalid. When present, all four keys in the example are required. Extra metadata keys are discarded and never echoed.

- `version`: strict integer1, not string/float/bool. Another integer version is unsupported; missing/null/wrong type is malformed.
- `host`: nonempty ASCII host, at most253 bytes. Lowercase and remove exactly one terminal dot. Every DNS label is1–63 ASCII letters/digits/hyphens; first/last characters cannot be hyphens. Single-label `localhost` is valid. Reject schemes, credentials, ports, paths, queries, fragments, whitespace/control characters, non-ASCII, empty/overlong labels. The producer must provide IDNA ASCII; the receiver performs no DNS lookup. This is an observed entry host, not authentication, cross-domain identity or each later page's current host.
- `landing_query`: map, with decoded empty object/empty array both accepted. A nonempty list is rejected. Only `utm_source` and `utm_medium` are retained, in that fixed order. Each supported value must be a string of at most120 Unicode code points and contain no ASCII control characters (including DEL). Invalid supported values reject the metadata; they are not truncated. Other keys are discarded. Missing key differs from key containing `""`.
- `referrer_host`: null means unavailable; `""` means observed empty; otherwise use the same host normalization/validation. Neither empty nor null proves a direct source.

The producer reads the entry URL once with `URLSearchParams`, decodes once, rejects repeated allowlisted keys as ambiguous, and freezes the resulting metadata with its event UID. The receiver does **not** decode again. Canonical query text uses fixed `utm_source` then `utm_medium` order and one RFC3986 encoding pass. Value case is significant. For example source `A%2B+B` becomes `utm_source=A%252B%2BB`; an absent source produces no key, while an empty source produces `utm_source=`. Do not rebuild metadata from the current navigation on retry.

Invalid optional metadata never blocks persistence/ACK of a valid core event. A valid other core event gets `wrong_event_type`. If the core event is rejected, the extension gets `core_rejected` regardless of its metadata. Rejected raw attribution values are not stored in event JSON, visit fields, receipts or application logs.

## Legacy and opt-in responses

Without any attempted attribution key, the existing response is exactly:

```json
{"protocol":1,"ack":["synthetic-event-0001"],"rejected":[],"server_time":"2030-01-01T12:00:00Z"}
```

When at least one event attempts the key, only one sibling is appended:

```json
{"protocol":1,"ack":["synthetic-event-0001"],"rejected":[],"server_time":"2030-01-01T12:00:00Z","extensions":{"attribution":{"version":1,"results":[{"event_id":"synthetic-event-0001","status":"applied","code":"captured","retry":false}]}}}
```

There is one receipt per attempted event in original **request-index order**, even when two request entries have the same UID or are processed in a different chronological order. Receipt fields are exactly `event_id,status,code,retry`. No visitor/visit database IDs or rejected raw fields are returned. Extension event_id is the original string only when it meets the existing ID pattern; otherwise it is `""`. Existing core rejection's scalar/truncation behavior is unchanged.

| status | code | retry | Meaning |
| --- | --- | --- | --- |
| applied | captured | false | This persisted event won the visit claim. |
| applied | already_captured | false | This same persisted event already owns the snapshot. |
| preserved | visit_already_captured | false | Another event already owns the snapshot. |
| rejected | bad_attribution | false | Metadata malformed; a valid core event may still be ACKed. |
| rejected | unsupported_version | false | Attribution version unsupported. |
| rejected | wrong_event_type | false | A valid non-page_view core type attempted attribution. |
| rejected | core_rejected | false | Core validation failed, including invalid ID. |
| rejected | duplicate_without_capture | false | The saved event has no normalized attribution; replay cannot add it. |
| rejected | duplicate_payload_mismatch | false | The saved page path/normalized attribution differs from the replay. |
| rejected | original_visit_missing | false | Original event association/visit is missing; it is not reconstructed. |
| retry | server_error | true | Event/snapshot persistence or readback failed; retry the frozen event. |

Core ACK proves only core insertion or a recognized duplicate. It does not prove attribution succeeded; extension failure does not remove ACK or add a core rejection. Require a recognizable version1 receipt. Missing/unrecognized receipt means unsupported, never success. Keep the frozen event/UID/metadata until a terminal attribution receipt; retry only pending/retryable work, with the existing403-stop and429-wait policy. A terminal attribution rejection must not be retried with different metadata under the same UID.

## Immutable capture and recovery

Schema v4 adds only visit snapshot columns. Version0 means unobserved/unknown; version1 includes a valid captured-empty query. Missing UTM keys are stored null, observed empty values as empty strings; referrer null/empty remains distinct. Historical visits are not backfilled. Existing legacy `landing_path` and other columns remain unchanged.

The core event must persist first. One prepared atomic compare-and-set updates all snapshot fields together, constrained by exact visit ID, visitor ID and `attribution_version=0`. The winning persisted event supplies event UID, original path and **receipt time**. This is the **first successful capture**, not a promise about earliest occurrence/navigation/insertion. If A inserts but its claim fails, B may win; A's later retry must preserve B. No mixed fields from two tabs, lock dependency or select-then-overwrite is allowed.

Only normalized attribution is saved on a new event, for retry recovery. A duplicate uses the saved visitor+UID, original visit/path/receipt time and saved normalized metadata. Changing canonical payload is terminal; an old event with no metadata cannot be upgraded by replay. An extension-only duplicate after a30minute rollover retries its original visit without creating an empty rollover visit. Missing original records are terminal; do not reconstruct/backfill. Only new core insertion changes event/pageview counters and tab state.

## Landing and privacy

Default Landing (`show_params` omitted, null, empty string or scalar0) keeps the legacy path-only API/field order/math/URLs; null follows the existing `?? ''` empty/default convention. Scalar1 enables mode1; booleans, arrays and other nonempty values are invalid parameters. Captured grouping uses status, normalized entry host, lowercased capture path and exact-case canonical allowed query. Unknown grouping uses status unknown and lowercased legacy landing_path. Unknown must remain separate from captured-empty. Visits and linked orders use the **same** snapshot-to-key, including old-visit/current-order lookup, existing paid/test/refund/buyer rules. Row UV counts overlap and are not globally additive.

Mode1 adds top-level show_params1 and row attribution_status/host/query/display_path. URLs use only the configured site origin, clean relative path and canonical allowlist query; a pushed host is escaped text, never a redirect destination. Missing path has no URL. Search is path/display-path only. The native checkbox defaults OFF on a new page, survives report remount only, and creates no new browser-storage preference.

Privacy exports include the normalized visit snapshot/status/version/path/host/query/UTMs/referrer; null and empty retain their meanings. They do not export rejected raw values, cookies or receipts. Existing whole-row retention/erasure, association, permissions and settings remain unchanged.

## Synthetic curl examples — documentation only

Do not run these against shared/production data. Use a private server-generated cookie jar path; never paste a real cookie, nonce or credential into source or chat. Replace `RECEIVER_ORIGIN` with an authorized local origin and use fresh timestamps in the JSON file above.

```sh
# Save the synthetic envelope as synthetic-attribution.json; obtain a fresh private cookie jar normally.
curl --request POST "RECEIVER_ORIGIN/wp-json/peptide/v1/track/events" \
  --header 'Content-Type: application/json' \
  --cookie '/PRIVATE/server-generated-cookie-jar.txt' \
  --data-binary '@synthetic-attribution.json'
```

For a valid core ACK with rejected metadata, copy the envelope to another file, use a **new** synthetic event UID and set attribution.host to `"https://example.test"`. Expect core ACK and `rejected/bad_attribution/false`; do not expect the raw host in the response. For an immutable retry, resend the **same unchanged** file/UID/canonical metadata using the same server-generated cookie context; expect already_captured or preserved after another claim, never a replaced snapshot. These examples are not execution evidence.

## Receiver/source-owner acceptance checklist

`receiver_complete` and `source_owner_pending` are separate states. This receiver batch does not modify the storefront producer, frozen observer configuration or adapter. Core ACK, curl/fixture success and seeded database records cannot clear source_owner_pending.

The source owner must implement the optional capture/freeze/receipt queue, then demonstrate an ordinary storefront **normal push → recognizable receipt → original persisted event and visit snapshot → default and mode1 Landing** path. Cover captured-empty vs unknown, reload, retry, clock/session rollover and privacy gates; preserve existing transport behavior. Verify retries never read the current navigation or attach old metadata to a new visit. Record the actual source revision and normal-push evidence before clearing pending. No theme/runtime injection, scraping/crawling, historical backfill, direct analytics fixture or messaging/deployment permission is supplied by this handoff.
