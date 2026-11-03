# Archived payment method deletion

2026-11-03 Codex. The owner requested a Delete button for archived methods on the existing formal HQ. The owner has authorized direct publication to that server; no new test site or environment switch is requested.

## Behavior

- Archived methods have Delete next to Restore. Delete hides the method from the ordinary list and moves it to the Deleted view.
- Restore from Deleted returns to archived and disabled, retaining the same account ID and all historical bindings. No actual merchant is deleted by this implementation task.
- Logical deletion preserves credentials and frozen in-flight attempt trust. The signed public METHODS DTO retains the disabled identity with its existing eight-field schema. No ledger, order, callback or native gateway identity is purged.
- All read paths fail closed for deleted accounts. Save, toggle, archive and ordinary restore reject deleted identities. The new methods require a current revision and the existing CAS write; POST dispatch retains administrator and nonce validation.
- The existing 20 retained identity cap includes archived and deleted methods. This is not permanent erasure or quota reclamation; the UI states that limit.

## Files and verification

Production files: `wp-content/plugins/peptide-panapay/includes/class-hub-accounts.php` and `class-hub-page.php`. README documents the recoverable behavior. No frontend theme, payment provider endpoint, saved account, order or environment configuration was changed.

57/57 actual-class/in-memory CAS and renderer checks passed: old data without the flag, deletion/restoration, stale revisions, CAS conflict, disabled state normalization, blocked bypass paths, default identity and credentials retention, exact public DTO, the cap, normal/deleted UI filters and buttons. Both PHP files pass syntax checks and the Git diff passes whitespace validation. Independent review found no blocking issue in frozen session/status/notification/return paths. These are model checks and source review, not real provider transaction acceptance.

Task evidence: `D:/Codex/Tasks/2026-11-03/payment-delete/outputs/`. Publication details are recorded after server cutover.

## Formal server publication

Feature commit `abda2e97c9afd4c580032f9ee9c7a78bee358154` was published as the paired payment PHP overlay at `/srv/vialcove/hq/releases/payment-delete-abda2e97c9af`, based on the actual `formal-payments-5dd0d844014d` runtime. All 4,201 prior code/link entries were verified during copying; the complete manifest records 3,746 files. Deployed PHP syntax and native pure-class probes passed: enabled=yes, test_mode=no, deleted=no, both deletion/recovery APIs present. Root-private rollback checkpoint and the old release are retained. Shared configuration remained byte-identical and production; store/public runtime code was not changed.

Fresh encrypted HQ backup: 260,470 bytes, SHA256 `0b6f9adc4df2ec25e37786a856d2ef5dc5e91ecabbc5dc175c085c9fc33b8e11`. The ciphertext was copied to the private task folder and independently hash-matched. No decryption or restore exercise was performed in this task.

Actual authenticated in-app browser verification at `https://vialcoveresearch.com/wp-admin/admin.php?page=pchq-payments` found one archived row with a visible Delete button beside Restore, plus the Deleted (0) link. Screenshot: `D:/Codex/Tasks/2026-11-03/payment-delete/outputs/formal-delete-button.png`. A fresh formal list tab was opened and retained for the owner, preserving existing input tabs. No Delete/Restore/Save operation was submitted against real merchant data. Recovery behavior was verified with actual-class model fixtures, not by deleting the owner's account.

Post-publication HTTPS checks passed: HQ login 200, merchant login 200, public homepage 200, public personnel-login route 403 as intended. Existing payment-provider/full customer-flow acceptance remains separate; this publication makes no claim of a real payment or settlement test. Git synchronization includes the new source and handoff, not deployment of unrelated repository changes.
