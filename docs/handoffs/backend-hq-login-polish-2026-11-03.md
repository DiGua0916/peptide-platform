# HQ mobile login polish — 2026-11-03

User requested a mobile login-page cleanup from an iPhone screenshot.

- Runtime commit: `dd5a2231ebfaeb6f0c9c98640ccc4fb83c71972f`.
- Changed only HQ `assets/hq-login.css` and `includes/admin/class-menu.php`.
- Replaced WP 7.1 Remember Me toggletip through its native filter with an inline private-device reminder. Main login labels use Chinese through a login-only gettext filter.
- Reduced mobile header spacing, made submit/password controls touch sized, aligned checkbox and reserved room for the password toggle.
- Authentication, credentials, redirects and cookie durations were not changed. No login submission, reset email or payment request was performed.

Deployment copied the existing immutable runtime, verified the two old hashes before patching, verified all manifest file hashes and PHP syntax, preserved production guard/config/uploads symlinks, then atomically switched current. Previous release remains `6f4fe476087a3415584c8ec3844961b77a9e85e7`. Encrypted pre-update backup: `20261102T210023016542Z.tar.gz.age` (server-local; this particular backup was not restore-tested).

Validation: PHP syntax and git diff check passed; public HTTPS checks 10/10 (Chinese labels, tooltip absent, native form fields, exact CSS bytes, lostpassword GET). nginx, PHP FPM, MySQL and both timers active. Actual production screenshots at desktop and 390×844 mobile confirm unobstructed form and no visible horizontal overflow. Browser DOM/interaction APIs repeatedly timed out, so password-toggle clicks and actual credential submission are not claimed as tested. Existing native password toggle markup and script were preserved. Browser viewport override reset.

Evidence: `D:/Codex/Tasks/2026-11-03/hq-login-polish/outputs/` (`deploy.txt`, `http-check.json`, `login-preview.png`, `login-mobile.png`).
