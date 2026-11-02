# HQ production deployment — 2026-11-03

Author: Codex. User explicitly requested deploying the management HQ to the newly purchased Lightsail instance. Actual domain, IP, host-key pins, credentials and encrypted backups are kept in the private deployment inventory, not this repository.

## Delivered scope

- Migrated the existing local HQ WordPress instance, administrator, store registration, account configuration and historical dashboard rows. This is HQ only; it does not complete the separate customer-admin/storefront split or production store onboarding.
- Initial committed source snapshot was `91862b3cdd481fbaf1b1725a738199d8b5d254fd`. Before delivery, integrated the published management/public-address compatibility updates and installed immutable runtime snapshot `6f4fe476087a3415584c8ec3844961b77a9e85e7`.
- Final runtime: WordPress 7.1.4, Peptide HQ 0.3.1 with HQ schema 4, Panapay 0.4.2, Ops files 0.1.0 inactive/unconfigured. No WooCommerce or customer-site theme installed on HQ.
- Ubuntu 24.04 LTS, distribution Nginx/MySQL/PHP 8.3 packages, required PHP extensions, security updates and controlled reboot. Services and scheduled jobs recovered after reboot.
- Dedicated unprivileged PHP-FPM user and database-scoped database account; database listens on loopback. Root-owned release source is read-only to PHP; uploads/temp/session directories have separate write permissions. Configuration is outside the document root.
- User bound a static IPv4 and set DNS A records for apex and www. User configured cloud firewall rules for SSH, HTTP and HTTPS. Host firewall permits only these public TCP ports; SSH password and root login disabled, Fail2ban enabled.
- Valid Let's Encrypt certificate for apex/www, HTTP-to-HTTPS and www-to-apex redirects, default-host rejection, credential/config/upload-PHP/XML-RPC denial, login rate limiting, private/no-store responses and noindex headers. Access logs omit query strings and request bodies.
- Preserved authentication salts and verified both registered-store key and merchant-secret decryption. All payment accounts remain OFF. Outbound WordPress HTTP and mail are deliberately blocked until production store/payment integration is accepted.
- Systemd minute job runs due WP events without browser traffic; polling of the legacy local development store is excluded until its production management address is registered. Certificate renewal and daily encrypted-backup timers enabled.

## Backups and migration safety

Source database/configuration/private operations material backed up locally before migration. Application database/configuration/uploads and deployment metadata are backed up with authenticated age encryption. Four encrypted server backups were copied to the restricted local task directory; the recovery identity is held outside Git on the local machine. The server retains only the public encryption recipient after initial verification.

The latest post-upgrade archive was decrypted and imported into a separate, uniquely named MySQL restore database. Verified 18 tables, 1 administrator, 1 store, 97 daily rows, 0 payment attempts, 0 payment events, HQ schema 4 and both new address columns. The restore database and plaintext migration inputs were removed after success; production business data was not restored over or purged.

Daily backups currently remain on the server until copied out; **automatic offsite replication, backup retention/space management and offsite monitoring are not configured**. A manual local encrypted copy exists, not an automatic offsite RPO guarantee. Code rollback is separate from database recovery; schema migration is not represented as automatically reversible.

## Actual validation

- SSH identity was independently pinned from the user's AWS browser terminal. New static IP matched the same host key. No disabled host-key/TLS verification.
- Source/target counts and successful decryption verified. Existing administrator password hash retained; imported development login sessions removed.
- A stale local plaintext password note did not authenticate. It was not used to reset the account. Admin-page rendering QA used a short-lived native WordPress session on server loopback and destroyed it afterward; **this does not claim successful password login by the user**.
- Authenticated native HTTP: overview, stores, servers and payment center returned 200; payment CSS/JS returned real assets. Anonymous admin redirects to login; unsigned root-query payment machine routes return 401 rather than being redirected by the public root-page rule.
- Twelve external HTTP/HTTPS checks passed with normal certificate validation: login, admin/home redirects, www/HTTP redirects, configuration/XML-RPC/dotfile/user-API denial, unsigned payment-policy rejection and payment assets.
- WordPress official core checksum verification passed, including after reboot and final runtime update. The local source lacked `license.txt`; restored the exact-version file from the official WordPress repository and then verified the complete core.
- Final runtime package: 3,743 manifest files verified and plugin PHP syntax checked before atomic release-pointer switch. New pure test suites actually ran: HQ 41, Panapay 33, Ops 9 (in-memory boundaries, not financial integration).
- Minute job exit status 0, service recovery after reboot and certificate renewal dry run passed. Final encrypted-backup MySQL restore passed as described above.
- IAB created the public login tab and metadata confirmed it. Accessibility/screenshot calls repeatedly timed out; no visual screenshot or interactive browser-login acceptance is claimed.

## Remaining integration

1. User logs in using the current original HQ account password; the older local login note is stale.
2. Register/verify production store management/public origins and signed connections before permitting corresponding outbound traffic and enabling monitoring.
3. Real provider domain binding, exact Apple Pay protocol routing and live payment/refund/settlement acceptance remain unfinished. No provider payment creation or real charge was performed during deployment.
4. Complete MFA/access controls, optional edge protection, automatic offsite backup/retention/alerting and production load/security acceptance before treating this as a fully hardened commercial platform.
5. Central customer-admin/editor hosting and cross-server publication are separate work; this migration does not silently relocate the WooCommerce customer backend or database.

Private evidence: D-drive task `2026-11-03/hq-deployment`, including release manifests, migration/decryption receipts, native HTTP checks, public HTTPS checks, restart/renewal checks, encrypted backup-copy receipts and isolated restore receipt. No private backup, database, credential, account cookie or host configuration is committed.
