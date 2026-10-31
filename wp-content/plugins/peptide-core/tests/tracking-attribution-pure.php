<?php
/** Standalone pure wire tests; no WordPress bootstrap, database, network or mocks. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/tracking/class-attribution.php';
use PeptideCore\Tracking\Attribution;
$pass = 0;
$assert = static function ( bool $ok, string $name ) use ( &$pass ): void {
	if ( ! $ok ) { fwrite( STDERR, "FAIL {$name}\n" ); exit( 1 ); }
	++$pass; echo "PASS {$name}\n";
};
$wire = array( 'version' => 1, 'host' => 'EXAMPLE.Test.', 'landing_query' => array( 'utm_medium' => 'Email / 中文', 'secret' => 'discard', 'utm_source' => 'A%2B+B' ), 'referrer_host' => null, 'private' => 'discard' );
$out = Attribution::clean( $wire );
$assert( $out['ok'], 'valid metadata' );
$assert( $out['value'] === array( 'version' => 1, 'host' => 'example.test', 'landing_query' => array( 'utm_source' => 'A%2B+B', 'utm_medium' => 'Email / 中文' ), 'referrer_host' => null ), 'canonical order, case, unknown stripping, null referrer' );
$assert( Attribution::query( $out['value'] ) === 'utm_source=A%252B%2BB&utm_medium=Email%20%2F%20%E4%B8%AD%E6%96%87', 'one RFC3986 encoding without decoding' );
$assert( $wire['host'] === 'EXAMPLE.Test.' && isset( $wire['private'] ), 'input immutable' );
foreach ( array( null, false, true, '', 1, 'v1', array() ) as $bad ) { $assert( ! Attribution::clean( $bad )['ok'], 'invalid metadata container ' . gettype( $bad ) ); }
foreach ( array( null, '1', 1.0, true, false, array() ) as $bad ) { $v = $wire; $v['version'] = $bad; $assert( Attribution::clean( $v ) === array( 'ok' => false, 'code' => 'bad_attribution' ), 'version strict ' . gettype( $bad ) ); }
foreach ( array( 0, 2, -1 ) as $bad ) { $v = $wire; $v['version'] = $bad; $assert( Attribution::clean( $v ) === array( 'ok' => false, 'code' => 'unsupported_version' ), 'unsupported integer version ' . $bad ); }
foreach ( array( 'version', 'host', 'landing_query', 'referrer_host' ) as $key ) { $v = $wire; unset( $v[ $key ] ); $assert( ! Attribution::clean( $v )['ok'], 'required ' . $key ); }
foreach ( array( '', '.', '..', 'example.test..', 'https://example.test', 'a@b', 'a:80', '/a', 'a/b', 'a?x', 'a#x', 'a b', "a\tb", "a\nb", "a\0b", '中文.test', '-a.test', 'a-.test', 'a..test', str_repeat( 'a', 64 ) . '.test', str_repeat( 'a.', 127 ), true, 1, null, array() ) as $i => $host ) {
	$v = $wire; $v['host'] = $host; $bad = Attribution::clean( $v );
	$assert( $bad === array( 'ok' => false, 'code' => 'bad_attribution' ), 'invalid host ' . $i . ' leaks no input' );
}
foreach ( array( 'localhost', 'a', '127.0.0.1', 'xn--fiqs8s.test', str_repeat( 'a', 63 ) . '.test', 'A-B.Test.' ) as $i => $host ) { $v = $wire; $v['host'] = $host; $assert( Attribution::clean( $v )['ok'], 'valid ASCII host ' . $i ); }
$v = $wire; $v['host'] = str_repeat( 'a', 63 ) . '.' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.' . str_repeat( 'd', 61 );
$assert( strlen( $v['host'] ) === 253 && Attribution::clean( $v )['ok'], 'host max253 bytes' ); $v['host'] .= '.'; $assert( ! Attribution::clean( $v )['ok'], 'host254 rejected' );
foreach ( array( null, '', 'REF.Test.' ) as $i => $ref ) { $v = $wire; $v['referrer_host'] = $ref; $r = Attribution::clean( $v ); $assert( $r['ok'] && $r['value']['referrer_host'] === ( $i === 2 ? 'ref.test' : $ref ), 'referrer tristate ' . $i ); }
foreach ( array( false, array(), 'https://ref.test', "bad\nref" ) as $i => $ref ) { $v = $wire; $v['referrer_host'] = $ref; $assert( ! Attribution::clean( $v )['ok'], 'invalid referrer ' . $i ); }
foreach ( array( array(), array( 'other' => 'ignored' ), array( 'utm_source' => '' ), array( 'utm_medium' => '' ) ) as $i => $q ) { $v = $wire; $v['landing_query'] = $q; $r = Attribution::clean( $v ); $assert( $r['ok'] && Attribution::query( $r['value'] ) === array( '', '', 'utm_source=', 'utm_medium=' )[ $i ], 'missing/empty query ' . $i ); }
foreach ( array( null, false, '', array( 'x' ), array( 'utm_source' => null ), array( 'utm_medium' => 1 ), array( 'utm_source' => true ), array( 'utm_source' => array() ), array( 'utm_source' => str_repeat( 'a', 121 ) ), array( 'utm_medium' => str_repeat( '中', 121 ) ), array( 'utm_source' => "x\x7f" ), array( 'utm_source' => "x\r" ), array( 'utm_source' => "\xff" ) ) as $i => $q ) { $v = $wire; $v['landing_query'] = $q; $assert( ! Attribution::clean( $v )['ok'], 'invalid strict query ' . $i ); }
foreach ( array( str_repeat( 'a', 120 ), str_repeat( '中', 120 ), ' A+B%20 C ', '=formula', 'Source' ) as $i => $q ) { $v = $wire; $v['landing_query'] = array( 'utm_source' => $q ); $r = Attribution::clean( $v ); $assert( $r['ok'] && $r['value']['landing_query']['utm_source'] === $q, 'no truncation/normalization of query ' . $i ); }
$assert( Attribution::snapshot( array() ) === array( 'status' => 'unknown', 'version' => 0, 'path' => null, 'host' => null, 'query' => null, 'utm_source' => null, 'utm_medium' => null, 'referrer_host' => null ), 'unknown not captured empty/direct' );
$assert( Attribution::receipt( '', 'rejected', 'core_rejected' ) === array( 'event_id' => '', 'status' => 'rejected', 'code' => 'core_rejected', 'retry' => false ), 'receipt stable keys without raw identity' );
echo "TOTAL {$pass} PASS, 0 FAIL\n";
