# Woocommerce admin dialog compatibility

These files adapt the installed Woocommerce 11.2.1 JavaScript sources. Original notices and license terms remain applicable; see LICENSE.txt. Upstream source fingerprints and adapted fingerprints are recorded in manifest.json.

Only browser dialog calls, their asynchronous callers, default-event guards, and necessary promise contracts are adapted. Request payloads, endpoint permissions, nonces and server-side validation remain upstream responsibilities. The shared VialCoveDialogs service supplies the UI, cancellation, focus restoration and stale-form guards. Browser-controlled beforeunload protection remains intact.

Regenerate with peptide-core/tools/build-woocommerce-dialogs.cjs and Acorn 8 (ADMIN_DIALOG_ACORN may point to an installed Acorn module). The tool intentionally processes only the reviewed file list. For WordPress, provide a flat directory containing the listed wp-admin/js and wp-includes/js source files. After regeneration, synchronize WordPress adapters to peptide-hq and run the dialog, default-submit, cancellation, Customizer Deferred, and loader contract tests.

The loader only replaces matching installed source hashes and the reviewed upstream version. A different upstream version or changed source is left untouched; therefore a future upstream update requires a fresh audit and adapter regeneration before release. Do not blindly remove that guard or assume arbitrary future plugin code is covered.

No native confirm/prompt API is replaced globally and no arbitrary click handlers are replayed. Generated JavaScript is shipped as source for review.
