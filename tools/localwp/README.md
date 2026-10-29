# LocalWP cron transport utility

This is an optional **local development** MU plugin. It is not automatically loaded by either business plugin and is not a production deployment default. This directory alone changes no running site.

The installed Requests cURL transport clamps a `0.01` second request timeout to at least one second and still calls `curl_exec()` synchronously. For a non-blocking request, it discards the response only afterwards. This can add about one second to the page request that spawns WordPress cron, including when cron spawning already runs at shutdown.

`local-cron-transport.php` selects Requests' existing `Fsockopen` transport for one narrowly defined request:

- `wp_get_environment_type()` must return exactly `local`.
- The request must be `POST`, with `blocking === false` and no non-null custom transport.
- Scheme, case-insensitive host, effective port and exact path must match this site's `site_url('wp-cron.php')`. Explicit default ports are equivalent to their implicit ports. Subdirectory installs and non-default ports are supported.
- A nonempty scalar `doing_wp_cron` query parameter must exist; a body field alone is insufficient. URL userinfo and fragments are excluded.
- The installed socket transport must be available, including TLS capability for HTTPS.
- No proxy may be configured for this request. Existing proxy configuration is preserved by leaving the original transport unchanged.
- A hostname target additionally needs trusted current Apache information: plain HTTP, `SERVER_SOFTWARE` identifying Apache, loopback `SERVER_ADDR`, a valid `SERVER_PORT`, and `realpath(DOCUMENT_ROOT)` equal to `realpath(ABSPATH)`. Windows slash/case differences are normalized.
- A literal loopback IP target can retain its original socket destination without Apache information, supporting a local server already addressed directly. This does not imply every arbitrary loopback proxy topology is supported.

For the trusted plain-HTTP Apache case, a per-request `fsockopen.remote_socket` callback connects to the current server's loopback address and port. The port is derived from server-provided context, never hardcoded. The logical URL, generated Host header and path remain unchanged. This bypasses only the local router hop for this site's cron spawn. HTTPS is never redirected to a plain TCP connection. A hostname target without that trustworthy context keeps cURL.

The explicit transport option and, where applicable, that request's socket hook are the only changes. Method, body, headers, timeouts, TLS verification and proxy settings remain unchanged. It does not disable cron, change scheduled tasks or locks, fabricate an HTTP success, or modify WordPress/Requests vendor files. An unavailable transport leaves the request unchanged.

Socket transport still waits for connection/DNS/TLS setup and a small request write, then closes without reading the response. It does not guarantee every connection completes in 10 ms. In particular, the upstream socket connection timeout is rounded to seconds; an unavailable local server can still take longer. Successful request submission alone also does not prove a scheduled event executed.

## Optional installation

After explicit local-site approval, copy only `local-cron-transport.php` into that site's `wp-content/mu-plugins/` directory. Confirm the site's environment type is `local`. Install separately in the store and HQ if both need it. Remove that one copied file to roll back. Do not automatically copy it to a deployed server or change the server environment to activate it.

The plugin uses the `VialCove\LocalWp\CronTransport` namespace and the existing Requests bridge hook `requests-requests.before_request` with five mutable arguments. It does not replace the global Requests transport order. The obsolete `http_api_transports` filter is not used.

## Standalone boundary tests

Run the test directly with PHP, **not** WP CLI `eval-file`. Point it at an installed WordPress so it loads the actual vendored Requests classes and `WP_HTTP_Requests_Hooks`. It does not load `wp-load.php`, read configuration or sessions, open sockets, send HTTP, query/write a database, or execute cron.

```powershell
& 'D:/Codex/LocalWP/data/lightning-services/php-8.3.29+1/bin/win64/php.exe' -n `
  'D:/Codex/Projects/peptide-platform-worktrees/backend/tools/localwp/test-local-cron-transport.php' `
  '--wp-root=D:/Codex/Sites/vialcovepeptidescom/app/public'
```

The tests use the real WordPress hook adapter and Requests socket hook. They check same-site/nonmatching origin and path cases, blocking/method/marker boundaries, local versus other environment types, existing custom transports and proxies, Apache identity/document-root/loopback/port checks, dynamically selected destination, subdirectories, TLS boundaries, and exact preservation of the other request fields and options. A PHP build without OpenSSL tests the direct-HTTPS-IP fallback; an OpenSSL-enabled build tests that native TLS selection. A hostname HTTPS request never bypasses to TCP.

The standalone command above passed **43 checks, zero failures** on Windows on 2026-10-28. Both PHP files passed syntax checks. These boundary checks do not themselves send a request or prove cron execution.

## Separate live acceptance

Live acceptance is deliberately separate from these boundary tests. After explicit installation, use a unique temporary cron hook and marker registered by a temporary local MU fixture, schedule only that one event, and trigger it with a normal authenticated admin-page GET. Confirm the marker appears and that event disappears, correlate cron-spawn and page timings, then remove only the temporary hook/marker/MU fixture. Preserve existing events and cron locks. Compare cold and warm page samples; do not infer execution solely from a non-blocking HTTP result.

No live installation, HTTP request or cron execution is performed by either file in this directory's standalone test command.

### Live acceptance reported by the integrating agent, 2026-10-28

The first candidate selected plain Fsockopen through the Local nginx router. The spawn call fell to approximately 4 ms, but its temporary event did not execute and its marker was absent. That candidate was not accepted. Sending the request quickly is insufficient; adding a Connection header alone was not a fix, because Requests already sends `Connection: Close`.

After adding the trusted dynamic Apache connection described above, the integrating agent installed the utility separately in both local sites and reported:

| Site | Temporary event ran | Event consumed | Cron spawn elapsed |
| --- | --- | --- | ---: |
| Store | true | true | 2.22 ms |
| HQ | true | true | 23.9 ms |

The same run also checked three HQ accounts-page requests and their store HTTP calls, all returning 200. The probe refreshed WordPress cron/alloptions cache before inspecting and cleaning its own event, avoiding stale CLI cache conclusions. These live checks were performed by the integrating agent, not by the standalone boundary-test script. The temporary probe files are not part of this utility.

This acceptance covers these two local server arrangements. A different reverse proxy, TLS termination, document-root mapping or PHP server may require a fresh event-execution check; otherwise retain the original cURL path. Do not lower the checks or add a fixed sleep to make an unsupported setup appear successful.

## Source references

- [WordPress spawn_cron](https://developer.wordpress.org/reference/functions/spawn_cron/) and [cron_request](https://developer.wordpress.org/reference/hooks/cron_request/).
- [WordPress Requests hooks](https://github.com/WordPress/Requests/blob/master/docs/hooks.md).
- Installed code: `wp-includes/Requests/src/Transport/Curl.php` (`setup_handle`/`request`), `Transport/Fsockopen.php` (`request`), `Requests.php` (`requests.before_request` and explicit transport option), and `class-wp-http-requests-hooks.php` (WordPress hook bridge).
