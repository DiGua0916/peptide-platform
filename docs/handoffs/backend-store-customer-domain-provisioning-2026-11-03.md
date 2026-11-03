# Customer-owned www domains for future store creation — local candidate

2026-11-03 · Codex. Base `67b5b7491791c84f3fa007ff411795c92a7aa9e7`, isolated branch `codex/store-domain-provisioning-20261103`. User clarified that every customer supplies their own domain and manually adds only `@` and `www` records; management addresses remain platform-assigned. This candidate has **not been installed or run on a server**. It does not rename, recreate or migrate the first store, the two existing acceptance stores, any account, UUID, database or OS identity. No new WordPress instance/database/resource was created for testing.

## Contract and behavior

HQ `Provision_Page` submits exact version-2 fields: `version, operation_id, actor_id, title, login, email, password, customer_domain`. Input accepts a customer-declared ASCII/Punycode base such as `example.com` or its `www.example.com` form, normalizing both to `example.com`. URLs, paths, ports, IPs, wildcards, Unicode, control characters, invalid labels and repeated `www.www` are rejected. No new public-suffix dependency was added: this validates DNS names, not registrant ownership/eTLD+1. The UI explicitly asks for the customer's root domain.

New public URL is `https://www.<customer_domain>`. Immutable `sNNNN`, generated UUID, DB/OS/FPM/media identities and `https://sNNNN.<management_domain>` remain unchanged. Root-profile management/public infrastructure suffixes are reserved against new customer claims. Store title and owner login keep their existing independent fields; no account or customer data is copied from an existing store.

Before any new user/SQL/runtime creation, root workers persist the canonical base claim on both hosts and verify customer DNS. Result `waiting_dns` contains only the public base and two records: `{type:A,name:@,value:<configured fixed public IPv4>,ttl:600}` and `{type:A,name:www,value:<same IPv4>,ttl:600}`. The progress page renders these with `textContent` for manual registrar/DNS changes. Public wildcard resolution is no longer required. Both hosts must resolve to the one configured IPv4; unexpected AAAA is rejected because the offered instructions are A-only. Existing management DNS remains platform-maintained.

After DNS, the existing bounded worker creates only the new store's independent installation, obtains a single certificate covering www and apex, and configures exact Host/SNI entry points to the same new runtime. It does not overwrite another enabled vhost that explicitly owns either Host. Existing certificates missing a required SAN fail closed for operator review rather than being expanded/replaced blindly. HTTP uses 308 to the same Host; native `Domains::add/check/set_primary` binds and verifies both HTTPS origins, uses www as primary, and `set_redirect(apex,www)` performs the canonical GET/HEAD redirect. Native redirects skip non-GET/HEAD, REST and AJAX; domain-check challenges are answered before redirects. Cookie domain remains false/host-only.

HQ registration still uses the existing per-store UUID/key and fixed management address; final confirmation now checks the expected public origin as well. No merchant credentials or payment authorization are inherited. Existing template copying remains limited to the established design documents/public images, not an old business database, products, orders, private reports or connection identity.

## Compatibility and durable ownership

- A version-1 request is accepted only as the exact metadata replay of an already completed job (password excluded because its persisted copy is redacted). Such jobs/results/account files are untouched and processing skips them. An already completed legacy result remains readable in the UI; submitting its old form only returns that result after the existing capability/nonce/operation checks.
- New or unfinished version-1 requests stop with an explicit missing-domain failure; they are not silently converted into customer-domain jobs. Submit a new operation with the v2 form after operator review. Pending v2 requests bind the original canonical domain and full request; another domain under the same operation is rejected.
- Claims live under `<state_root>/domain-claims` on admin and `<web_ledger_root>/domain-claims` on web. Filenames are SHA-256 of the canonical base. Files/directories are root-owned private, no-follow/exclusive creation with file and parent-directory fsync before creation proceeds; claim replay must match the entire record. Operation/UUID/slug changes cannot transfer a claim. Web forced-command creation is additionally flock-serialized. Claims are retained after failure; an operator must audit partial resources before any deliberate release. A kill during initial reservation can leave a partial claim, which fails closed. Claims are not a transaction with the other VM/DNS/HQ or a proof of registrar ownership. Restore both hosts' matching claims and state while the worker is stopped, not just SQL followed by an empty claim registry.
- New web ledger records persist `customer_domain`; cron reinstallation reads it. Old ledger records lacking that field keep their old numbered public URL. Admin encrypted backups include the private claims directory when present; existing web-ledger backups already cover web claims. No offsite backup is added.

### Reserve existing first-store domain before enabling new jobs

Do **not** create a fake s0001 broker job or call web_create for it. First stop the admin provisioning timer/worker during the upgrade, inventory the current HQ identities and existing custom public bases, and verify the first store's actual UUID against both the live store and HQ site 2. Keep the real UUID only in protected input outside Git/argv/chat.

The new root-only entry point on **each** host is:

```text
/usr/bin/python3 -I /usr/local/lib/vialcove-provision/worker.py --reserve-legacy-domain
```

It consumes one bounded UTF-8 JSON object on stdin with exact fields `customer_domain` (the intended base), `uuid` (the verified unchanged UUID) and `slug` (`s0001`). Pass stdin from a root-private file/secure transport; never put the UUID in a command argument. It writes only the private claim and returns public `{reserved:true,customer_domain:...,kind:legacy}`. It performs no SQL, account install, DNS/HTTP, TLS, job creation or store configuration change. The profile chooses the host's real claim root. Repeating the identical import succeeds; a different existing claim fails without overwriting it. Schema is version 1 / kind `legacy` / canonical base / UUID / slug. Provisioned claims use kind `provisioned` plus the original operation ID, so they cannot replace a legacy reservation.

Reserve the first-store base and every other existing customer-declared custom base in both ledgers before enabling v2 submissions. Old QA numbered Hosts remain protected by the configured infrastructure suffix and do not get new fake jobs. Keep all completed v1 jobs, results and legacy ledger records intact. If either claim conflicts, stop and investigate; do not delete it or transfer ownership automatically. The provisioning claim does not retrofit centralized ownership validation into the separate existing manual Domains UI; operator-managed aliases must respect this inventory too.

Upgrade order: pause worker → preserve root profiles/queue/results/ledgers → install reviewed worker/support sources on both hosts → import/verify existing legacy claims on both → install the HQ v2 form → resume worker. Do not run `install-operations.py` merely to publish this change: it enables timers; any needed operations reinstall is a separate reviewed action. Certificates/vhosts/DB resources for a new customer remain deferred until both customer records and platform management resolution are correct. The first-store public cutover and frozen-payment-return compatibility are a separate task, not performed by this candidate.

## Actual local verification

- Python `unittest` **22/22**: strict v2/normalization, stable internal identity, two-record/AAAA/DNS requirements, root-claim replay/conflicts/legacy reservation, immutable completed v1 replay and processing, pending-domain request immutability, waiting-DNS no-create gate, dual SAN request/checks, exact-host vhost template and cookie/config/data-copy boundaries. Follow-up independent review found the existing-vhost exact-name check was case-sensitive; it now normalizes existing names, including quoted names, and ignores line comments before comparing. Upper/mixed-case collisions in IPv6 listen blocks fail before writing the target vhost or running commands; comment-only examples do not create false collisions. Every unmocked worker command raises; no provisioning command, DB or network is executed.
- PHP **66/66** with synthetic queue/function stubs: v2 form, legacy result/submit replay, DNS result filtering/rendering, malformed input/link/data rejection. The test loads the real Core `Domains::redirect()` and proves POST/PUT/PATCH/DELETE do not read domain options or call redirects and preserve the request body. No WordPress bootstrap/DB/HTTP.
- PHP lint: both changed/new PHP files. Python AST parse: worker, test, backup and operations installer. `git diff --check` passed.
- Local Windows Python has no POSIX `fcntl/pwd/grp`; pure tests explicitly fake those modules and model root mode/ownership. They **do not certify** Linux flock/no-follow/permissions, Nginx parsing, real DNS/TLS issuance, native fresh install, media bridge, HQ registration, cron/backup execution or real browser behavior. No WSL distribution was installed. The first test pass exposed the host's GBK default decoding of a UTF-8 fixture; worker text reads were made explicitly UTF-8, then the suite passed. ResourceWarning exposed the prior unclosed worker lock; it now uses a verified context-managed descriptor and tests pass with ResourceWarning treated as error.

Commands (run from this worktree; set `VIALCOVE_TEST_TMPDIR` to a task directory on D):

```text
python -B -W error::ResourceWarning -m unittest discover -s tools/deployment/provisioning -p test_worker.py -v
php -l wp-content/plugins/peptide-hq/includes/admin/class-provision-page.php
php -l wp-content/plugins/peptide-hq/tests/provision-customer-domain-pure.php
php wp-content/plugins/peptide-hq/tests/provision-customer-domain-pure.php
```

The local PHP binary required its existing mbstring extension enabled for the pure test; no dependency was installed. Remaining deployment/acceptance requires a reviewed server publication and, only when separately authorized, a real new customer domain/install. This checkpoint makes no claim of commercial payment/mail readiness and never resets an unknown payment attempt.
