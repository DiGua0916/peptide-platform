# Sandbox performance continuation

User explicitly started the sandbox performance goal. Production remains unchanged.

Fresh server-loopback samples: homepage TTFB 0.862/0.784/0.786 seconds, 318617 bytes; report 64KiB ranges 0.233/0.198/0.221 seconds, HTTP 206; one-item report listing 0.489/0.440/0.464 seconds. Load average 0.08 and the existing 0.75-core sandbox quota are recorded in the task receipt. These are neither mobile metrics nor public-network timings.

The PDF reader formerly fetched geometry for every page during preparation, despite rendering only nearby pages. Preparation now fetches the first page only and uses its ratio for later placeholders. The renderer resolves each later page's actual ratio on demand; mixed landscape pages, cancellation, timeout and existing resource ownership remain covered. No source PDF bytes or payment behavior changed.

Fresh isolated checks: reader 20/20 and library navigation 6/6. A targeted regression run against the unchanged previous reader fails because it reads all 100 synthetic pages; the new reader reads only page 1 on open and passes. These mocks test the actual reader source with DOM/PDF boundary peers, not actual PDF.js network or phone rendering. An initial broad old-source regression attempt stalled and was interrupted; source was restored explicitly, then the targeted non-mutating task fixture supplied the red/green proof.

Only the reader JS was installed into both sandbox shop/admin roots after baseline byte comparison and backup. Backup: `/var/lib/vialcove-sandbox/backups/lazy-reader-1793897749`. Served complete JS bytes matched SHA256 `a80c39ef97d8baaa51a1e6061d942c4f28dfa3aa5cef18d9f0c6c5e3c6919992`. Receipt: `D:/Codex/Tasks/2026-11-06/skills-performance-rollout/outputs/lazy-reader-deployment.json`.

Chrome still has the user's authenticated sandbox tab. The CUA inventory succeeds, but reading its current accessibility state again fails at the browser transport timeout-options boundary. No clicks, viewport change, cart writes or browser-policy bypass occurred. Actual mobile screenshots, network/render timing, fit/back and first-load interaction acceptance remain incomplete. Keep goal active and continue independent profiling work; do not present protocol/unit checks as complete smoothness acceptance.
