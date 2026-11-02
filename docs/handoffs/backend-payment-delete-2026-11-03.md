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
