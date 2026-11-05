<?php
/** Standalone original-byte HTTP planning checks. No WordPress, DB, network or real reports. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-report-file-response.php';
use PeptideCore\Report_File_Response as Response;

$dir = $argv[1] ?? '';
if ( ! is_string( $dir ) || ! is_dir( $dir ) || realpath( $dir ) !== $dir ) { throw new RuntimeException( 'Pass an existing absolute test-output directory.' ); }
$path = tempnam( $dir, 'report-response-' );
if ( false === $path ) { throw new RuntimeException( 'Fixture unavailable.' ); }
$bytes = "%PDF-1.4\n% Synthetic HTTP test bytes; NOT a certificate.\n" . implode( '', array_map( static fn( $n ) => chr( $n ), range( 0, 255 ) ) ) . "\n%%EOF\n";
file_put_contents( $path, $bytes );
touch( $path, 1700000000 );
$file = array( 'path' => $path, 'size' => strlen( $bytes ), 'mime' => 'application/pdf', 'ext' => 'pdf', 'original' => 'synthetic-byte-fixture.pdf' );
$count = 0;
$check = static function( bool $pass, string $message ) use ( &$count ): void { ++$count; if ( ! $pass ) { throw new RuntimeException( $message ); } };
$plan = static fn( array $h = array(), bool $head = false, bool $download = false ) => Response::prepare( $file, $download, $head, 42, $h );
$reject = static function( array $input, string $message ) use ( $check ): void {
	try { Response::prepare( $input, false, false, 42 ); }
	catch ( RuntimeException $error ) { $check( $error->getMessage() === 'Report file is unavailable.', $message ); return; }
	$check( false, $message );
};
try {
	$full = $plan(); $etag = $full['headers']['ETag']; $modified = $full['headers']['Last-Modified']; $size = strlen( $bytes );
	$check( $full['status'] === 200 && $full['start'] === 0 && $full['length'] === $size && $full['body'], 'GET describes the full original bytes' );
	$check( $etag === '"sha256-' . hash( 'sha256', $bytes ) . '"', 'Strong ETag derives from actual bytes' );
	$check( $full['headers']['Content-Type'] === 'application/pdf' && $full['headers']['Content-Length'] === (string) $size, 'Full MIME and byte length are correct' );
	$check( $full['headers']['Accept-Ranges'] === 'bytes' && str_contains( $full['headers']['Cache-Control'], 'private, no-cache' ) && str_contains( $full['headers']['Cache-Control'], 'must-revalidate' ) && str_contains( $full['headers']['Cache-Control'], 'no-transform' ) && ! str_contains( $full['headers']['Cache-Control'], 'no-store' ), 'Private cache may store but always revalidate; intermediaries cannot transform ranges' );
	$check( $plan( array(), true )['body'] === false && $plan( array(), true )['headers']['Content-Length'] === (string) $size, 'HEAD has representation length and no body' );
	$check( $plan( array( 'Range' => 'bytes=0-4' ), true )['status'] === 200 && $plan( array( 'Range' => 'bytes=999999-' ), true )['status'] === 200, 'HEAD ignores Range, including an unsatisfiable one' );
	foreach ( array(
		'bytes=0-4' => array( 0, 4 ), 'bytes=10-' => array( 10, $size - 1 ), 'bytes=-7' => array( $size - 7, $size - 1 ),
		'bytes=0-99999999999999999999999' => array( 0, $size - 1 ), 'bytes=-99999999999999999999999' => array( 0, $size - 1 ),
		'bytes=0003-0007' => array( 3, 7 ), 'BYTES=0-0' => array( 0, 0 ),
	) as $request => [ $start, $end ] ) {
		$p = $plan( array( 'HTTP_RANGE' => $request ) );
		$check( $p['status'] === 206 && $p['start'] === $start && $p['length'] === $end - $start + 1 && $p['headers']['Content-Range'] === "bytes $start-$end/$size", 'Single byte range: ' . $request );
	}
	foreach ( array( 'bytes=-0', 'bytes=-', 'bytes=9-2', 'bytes=' . $size . '-', 'bytes=999999999999999999999-', 'bytes=not-a-range', 'bytes=+0-1', 'bytes=0--1' ) as $request ) {
		$p = $plan( array( 'range' => $request ) );
		$check( $p['status'] === 416 && ! $p['body'] && $p['length'] === 0 && $p['headers']['Content-Range'] === 'bytes */' . $size && $p['headers']['Content-Length'] === '0', 'Unsatisfiable or invalid bytes are bounded 416: ' . $request );
	}
	foreach ( array( 'items=0-1', 'bytes=0-1,4-8', 'bytes=0-1,bad' ) as $request ) { $check( $plan( array( 'range' => $request ) )['status'] === 200, 'Unsupported unit/multiple range is ignored: ' . $request ); }
	$check( $plan( array( 'range' => str_repeat( '0', 8193 ) ) )['status'] === 200, 'Oversized range input is ignored without arithmetic work' );
	foreach ( array( $etag, 'W/' . $etag, '"different", W/' . $etag, '*' ) as $tag ) {
		$p = $plan( array( 'If-None-Match' => $tag, 'Range' => 'bytes=0-4' ) );
		$check( $p['status'] === 304 && ! $p['body'] && ! isset( $p['headers']['Content-Length'], $p['headers']['Content-Range'] ), 'GET weak validator precedes Range: ' . $tag );
	}
	$check( $plan( array( 'If-Modified-Since' => $modified ) )['status'] === 304, 'Unchanged HTTP date revalidates to 304' );
	$check( $plan( array( 'If-None-Match' => '"different"', 'If-Modified-Since' => $modified ) )['status'] === 200, 'Nonmatching If-None-Match suppresses If-Modified-Since' );
	$check( $plan( array( 'If-Modified-Since' => 'tomorrow' ) )['status'] === 200, 'Relative/non-HTTP dates are ignored' );
	$check( $plan( array( 'If-None-Match' => $etag . ',malformed' ) )['status'] === 200, 'A malformed validator list never creates a false 304' );
	$check( $plan( array( 'If-None-Match' => array( '"different"', $etag ) ) )['status'] === 304, 'WP REST repeated header arrays are accepted' );
	$check( $plan( array( 'If-Match' => 'W/' . $etag ) )['status'] === 412, 'If-Match requires strong comparison' );
	$check( $plan( array( 'If-Match' => $etag ) )['status'] === 200 && $plan( array( 'If-Match' => '*' ) )['status'] === 200, 'Strong/wildcard If-Match is honored' );
	$check( $plan( array( 'If-Unmodified-Since' => 'Mon, 13 Nov 2023 22:13:20 GMT' ) )['status'] === 412, 'Changed unmodified-since precondition returns 412' );
	$check( $plan( array( 'If-Match' => $etag, 'If-Unmodified-Since' => 'Mon, 13 Nov 2023 22:13:20 GMT' ) )['status'] === 200, 'If-Match has precedence over If-Unmodified-Since' );
	foreach ( array( $etag, $modified ) as $validator ) { $check( $plan( array( 'range' => 'bytes=0-4', 'if-range' => $validator ) )['status'] === 206, 'Matching strong or stable exact date If-Range allows a range' ); }
	foreach ( array( 'W/' . $etag, '"different"', 'Wed, 15 Nov 2023 22:13:20 GMT', 'tomorrow' ) as $validator ) { $check( $plan( array( 'range' => 'bytes=0-4', 'if-range' => $validator ) )['status'] === 200, 'Nonmatching/weak/date-not-exact If-Range returns the full representation' ); }
	$check( str_starts_with( $plan( array(), false, true )['headers']['Content-Disposition'], 'attachment;' ) && str_starts_with( $full['headers']['Content-Disposition'], 'inline;' ), 'View/download disposition preserved' );
	$injected = $file; $injected['original'] = "certificate\r\nX-Evil: yes\".pdf";
	$d = Response::prepare( $injected, false, false, 42 )['headers']['Content-Disposition'];
	$check( ! str_contains( $d, "\r" ) && ! str_contains( $d, "\n" ) && str_contains( $d, "filename*=UTF-8''" ), 'Filename is encoded without response splitting' );
	$bad = $file; $bad['path'] = 'https://example.invalid/report.pdf'; $reject( $bad, 'External paths are rejected' );
	$bad = $file; ++$bad['size']; $reject( $bad, 'Stale file size is rejected' );
	$bad = $file; $bad['mime'] = 'text/html'; $reject( $bad, 'Non-report MIME is rejected' );
	$bad = $file; $bad['path'] = $path . '.missing'; $reject( $bad, 'Missing file is rejected before headers' );
	// A same-length, same-mtime replacement must change the strong validator.
	$replacement = str_replace( 'Synthetic', 'SYNTHETIC', $bytes ); file_put_contents( $path, $replacement ); touch( $path, 1700000000 );
	$changed = $plan();
	$check( $changed['headers']['ETag'] !== $etag && $changed['headers']['ETag'] === '"sha256-' . hash( 'sha256', $replacement ) . '"', 'ETag is not a metadata/mtime surrogate' );
	$check( $plan( array( 'If-None-Match' => $etag ) )['status'] === 200, 'Old byte validator cannot create 304 after replacement' );
	$check( $plan( array( 'Range' => 'bytes=0-4', 'If-Range' => $etag ) )['status'] === 200, 'Old byte validator cannot select ranges from a replacement' );
	$check( hash_file( 'sha256', $path ) === hash( 'sha256', $replacement ), 'Planning never modifies fixture bytes' );
	$check( dirname( realpath( $path ) ) === realpath( $dir ), 'All task fixtures stay in the explicit task directory' );
	foreach ( array(
		array( 20971520, 'bytes=0-65535', true ), array( 20971520, 'bytes=0-1048575', true ), array( 20971520, 'bytes=0-1048576', false ),
		array( 20971520, 'bytes=1048576-2097151', true ), array( 20971520, 'bytes=0-', false ), array( 20971520, 'bytes=-65536', true ),
		array( 20971520, 'bytes=-1048576', true ), array( 20971520, 'bytes=-1048577', false ), array( 20971520, 'bytes=-9999999999999999999999', false ),
		array( 20971520, 'bytes=9999999999999999999999-', false ), array( 20971520, 'bytes=0-9999999999999999999999', false ),
		array( 20971520, 'bytes=20971520-', false ), array( 20971520, 'bytes=2-1', false ), array( 20971520, 'bytes=-0', false ),
		array( 20971520, 'bytes=0-1,4-8', false ), array( 20971520, 'items=0-1', false ), array( 20971520, 'bytes=broken', false ),
		array( 20971520, "bytes=0-1\r\n", false ), array( 20971520, str_repeat( '0', 8193 ), false ), array( 0, 'bytes=0-1', false ),
		array( -1, 'bytes=0-1', false ), array( 1, 'bytes=0-999999999999999999999', true ), array( 1, 'bytes=-999999999999999999999', true ),
		array( 1048576, 'bytes=0-', true ), array( 1048577, 'bytes=0-', false ),
	) as [ $input_size, $range, $expected ] ) {
		$check( Response::small_range_request( $input_size, $range ) === $expected, 'Chunk budget uses the exact bounded delivery parser: ' . $input_size . '/' . $range );
	}
	echo json_encode( array( 'passed' => $count, 'scope' => 'Standalone synthetic original-byte representation checks only; no WordPress, DB, network or real certificate access.' ), JSON_THROW_ON_ERROR ) . "\n";
} finally { unlink( $path ); }
