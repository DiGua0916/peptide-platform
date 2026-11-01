# Native MIME test dependency provenance

Only the14 PHP files directly required by the accepted037/047 in-memory MIME harness are included. Source bytes and absolute read-only source origins are listed in plans/055-integration/test-provenance.json. These origins are audit metadata, not runtime dependencies.

Original source copyright/license headers remain unchanged. The accompanying WordPress license is retained. PHPMailer source headers retain their LGPL license notice and upstream attribution; Woo source retains its original package headers. No complete WordPress installation, configuration, user content, plugin settings or private mail data is included.

These files are loaded only by the pure CLI mail harness with platform/transport/persistence boundaries. The harness does not load wp-load.php, connect to a database or deliver mail.
