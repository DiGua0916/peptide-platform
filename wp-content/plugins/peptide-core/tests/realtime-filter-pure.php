<?php
/** Pure typed filters and frozen snapshot classification, no WP/database/bootstrap. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/tracking/class-attribution.php';
require_once dirname( __DIR__ ) . '/includes/data/class-realtime-filter.php';
use PeptideCore\Data\Realtime_Filter as F;
$pass = 0;
$check = static function( string $name, bool $ok ) use ( &$pass ): void { if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $name . "\n" ); exit( 1 ); } ++$pass; echo 'PASS ', $name, "\n"; };
$all = array( 'entry_domain_state' => 'all', 'utm_source_state' => 'all' );
$check( 'missing states canonical All', F::clean( array() ) === array( 'ok' => true, 'value' => $all ) );
$check( 'both All object', F::params( array() )->is_all() && F::params( array() )->normalized() === $all );
foreach ( array( 'entry_domain_state', 'utm_source_state' ) as $key ) {
 foreach ( array( null, false, true, '', 'ALL', array(), 0, 1 ) as $i => $bad ) { $check( 'strict state ' . $key . '/' . $i, ! F::clean( array( $key => $bad ) )['ok'] ); }
}
foreach ( array( 'entry_domain', 'utm_source' ) as $key ) {
 foreach ( array( '', null, 'synthetic', false, array() ) as $i => $bad ) { $check( 'non-value forbids value key ' . $key . '/' . $i, ! F::clean( array( $key => $bad ) )['ok'] ); }
}
$typed = array( 'entry_domain_state' => 'value', 'entry_domain' => 'ExAmPle.TEST.', 'utm_source_state' => 'value', 'utm_source' => ' all +%2F世界 ' );
$canonical = array( 'entry_domain_state' => 'value', 'entry_domain' => 'example.test', 'utm_source_state' => 'value', 'utm_source' => ' all +%2F世界 ' );
$check( 'canonical host and exact-case once-decoded source', F::clean( $typed ) === array( 'ok' => true, 'value' => $canonical ) );
$check( 'input immutable', $typed['entry_domain'] === 'ExAmPle.TEST.' );
foreach ( array( 'https://example.test', 'example.test:80', 'a..test', '-a.test', 'é.test', "a.test\n", '', null ) as $i => $bad ) { $check( 'host rejects syntax ' . $i, ! F::clean( array( 'entry_domain_state' => 'value', 'entry_domain' => $bad ) )['ok'] ); }
foreach ( array( '', null, false, 1, array(), "x\n", "x\x7f", "\xff", str_repeat( '界', 121 ) ) as $i => $bad ) { $check( 'source rejects syntax ' . $i, ! F::clean( array( 'utm_source_state' => 'value', 'utm_source' => $bad ) )['ok'] ); }
foreach ( array( 'all', 'unknown', 'ALL', 'a', 'A', ' ', str_repeat( '界', 120 ), '%2F' ) as $value ) { $check( 'typed value does not collide or decode ' . $value, F::params( array( 'utm_source_state' => 'value', 'utm_source' => $value ) )->normalized()['utm_source'] === $value ); }
$base = array( 'id' => 17, 'visitor_id' => 8, 'attribution_version' => 1, 'attribution_ms' => 1000, 'attribution_host' => 'example.test', 'utm_source' => 'A' );
$filter = F::params( array( 'utm_source_state' => 'value', 'utm_source' => 'A' ) )->at( 1001 );
$fact = $filter->classify( $base );
$check( 'original received <= anchor captured', $fact['captured'] && $filter->matches( $fact ) );
$check( 'case is binary exact', ! F::params( array( 'utm_source_state' => 'value', 'utm_source' => 'a' ) )->at( 1001 )->matches( $fact ) );
$check( 'exclusive history cutoff', ! $filter->at( 1000 )->classify( $base )['captured'] );
$check( 'missing association reason', $filter->classify( null )['reason'] === 'missing_association' );
$zero = $base; $zero['visitor_id'] = 0; $check( 'nonpositive owner unobserved', $filter->classify( $zero )['utm_source_state'] === 'unobserved' );
$zero = $base; $zero['attribution_version'] = 0; $check( 'v0 unobserved', $filter->classify( $zero )['reason'] === 'v0' );
$zero = $base; $zero['attribution_ms'] = 1001; $check( 'late receipt unobserved', $filter->classify( $zero )['reason'] === 'late_capture' );
foreach ( array( 'missing' => null, 'empty' => '', 'value' => 'A' ) as $state => $value ) {
 $row = $base; $row['utm_source'] = $value; $f = F::params( array( 'utm_source_state' => $state ) + ( 'value' === $state ? array( 'utm_source' => $value ) : array() ) )->at( 1001 );
 $check( 'missing/empty/value separate ' . $state, $f->classify( $row )['utm_source_state'] === $state && $f->matches( $f->classify( $row ) ) );
}
$both = F::params( array( 'entry_domain_state' => 'value', 'entry_domain' => 'other.test', 'utm_source_state' => 'value', 'utm_source' => 'A' ) )->at( 1001 );
$check( 'AND zero intersection', ! $both->matches( $fact ) && $both->matches( $fact, 'utm_source' ) );
$check( 'unobserved differs from captured missing', ! F::params( array( 'utm_source_state' => 'unobserved' ) )->at( 1001 )->matches( $fact ) );
$check( 'late CAS does not invent capture wall clock', $filter->classify( $base ) === $fact && ! array_key_exists( 'capture_ms', $fact ) );
foreach ( array( 1, '1', '00017', PHP_INT_MAX, (string) PHP_INT_MAX ) as $i => $value ) { $check( 'strict positive native association ' . $i, F::positive_id( $value ) === (int) $value ); }
foreach ( array( 0, '0', -1, true, false, 1.0, null, array( 1 ), new stdClass(), '1junk', ' 1', '+1', "1\n", '999999999999999999999999999' ) as $i => $value ) { $check( 'malformed native association unobserved ' . $i, null === F::positive_id( $value ) ); }
echo 'TOTAL ', $pass, " PASS / 0 FAIL\n";
