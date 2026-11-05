# Sandbox mobile report acceptance — 2026-11-06

The in-app browser now opens the authenticated sandbox. Tested at a 390 × 844 viewport; this is desktop responsive emulation, not a physical iPhone speed measurement.

Found and reproduced a product dialog defect: pending and accepted variation updates shared a selection label, so the accepted update skipped restoring the matching report. The report renderer now runs on refresh, retaining its signature guard to avoid rebuilding unchanged document DOM.

Regression test failed against the old implementation and passed with the fix. Fresh combined product-device-settings and home-quick-selection run: 13 tests passed, 0 failed.

Actual browser verification after sandbox deployment:
- 20mg → 15mg: $50 / box, no stale discount, matching 15mg reference report.
- 15mg → 20mg: $55 / box, $78 original price, 29% discount and matching 20mg reference report restored.
- Opened the 3-page 20mg reference PDF, fitted to available width at 100%; zoomed to 150%; closed back to the product.
- Browser error log returned no errors during this run. Temporary viewport override reset.

Screenshot: D:/Codex/Tasks/2026-11-06/chrome-sandbox-acceptance/outputs/coa-mobile-fixed.png

Only sandbox shop/admin code copies updated. Deployment receipt: D:/Codex/Tasks/2026-11-06/skills-performance-rollout/outputs/report-selection-deployment.json. Backup: /var/lib/vialcove-sandbox/backups/report-selection-1793902012. Served JS SHA256: 0879408f159604c86b1a53fa43984fdf9a59c9bde372677905b6ca0cdadef292.

Production was not updated. This functional acceptance does not establish physical-device LCP/INP/CLS or public-network first-load performance; those remain unverified.
