<?php
/** Authenticated-by-caller public archive file delivery, with byte ranges and revalidation. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Report_File_Response {

	private const CHUNK_BYTES = 65536;
	private const CACHE_CONTROL = 'private, no-cache, max-age=0, must-revalidate, no-transform';

	/** Same parser as delivery: only a satisfiable single range of at most 1 MiB gets the chunk budget. */
	public static function small_range_request( int $size, string $range ): bool {
		if ( $size < 1 || strlen( $range ) > 8192 || preg_match( '/[\r\n\0]/', $range ) ) { return false; }
		$span = self::range( trim( $range, " \t" ), $size );
		return is_array( $span ) && $span[1] - $span[0] + 1 <= 1048576;
	}

	/**
	 * The caller MUST recheck public archive eligibility and validated_file on every request,
	 * including conditional requests. This class never grants access or fetches external URLs.
	 *
	 * @param array{path:string,mime:string,size:int,ext:string,original?:string} $file Server-validated file.
	 * @param array<string,string|string[]> $request_headers HTTP names, or HTTP_* server keys.
	 * @return array{status:int,headers:array<string,string>,start:int,length:int,body:bool}
	 */
	public static function prepare( array $file, bool $download, bool $head, int $id, array $request_headers = array() ): array {
		[ $stream, $stat, $etag ] = self::open( $file );
		try { return self::response( $file, $download, $head, $id, self::headers( $request_headers ), $stat, $etag ); }
		finally { fclose( $stream ); }
	}

	/** Same contract as prepare; the hash and emitted bytes come from the same open file. @return never */
	public static function send( array $file, bool $download, bool $head, int $id, array $request_headers = array() ): void {
		[ $stream, $stat, $etag ] = self::open( $file );
		try {
			$plan = self::response( $file, $download, $head, $id, self::headers( $request_headers ), $stat, $etag );
			if ( $plan['body'] && 0 !== fseek( $stream, $plan['start'], SEEK_SET ) ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			while ( ob_get_level() > 0 ) { ob_end_clean(); }
			// A byte range describes the identity representation, not a dynamically compressed body.
			if ( function_exists( 'ini_set' ) ) { @ini_set( 'zlib.output_compression', '0' ); }
			$compression = strtolower( trim( (string) ini_get( 'zlib.output_compression' ) ) );
			if ( ! in_array( $compression, array( '', '0', 'off' ), true ) ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			if ( function_exists( 'header_remove' ) ) { header_remove( 'Content-Encoding' ); }
			http_response_code( $plan['status'] );
			foreach ( $plan['headers'] as $name => $value ) { header( $name . ': ' . $value, true ); }
			$remaining = $plan['body'] ? $plan['length'] : 0;
			while ( $remaining > 0 && ! connection_aborted() ) {
				$bytes = fread( $stream, min( self::CHUNK_BYTES, $remaining ) );
				if ( false === $bytes || '' === $bytes ) { break; }
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated original binary file.
				$remaining -= strlen( $bytes );
			}
		} finally { fclose( $stream ); }
		exit;
	}

	/** @return array{0:resource,1:array,2:string} */
	private static function open( array $file ): array {
		$path = $file['path'] ?? null;
		$mime = $file['mime'] ?? null;
		$ext = $file['ext'] ?? null;
		$types = array( 'application/pdf' => array( 'pdf' ), 'image/png' => array( 'png' ), 'image/jpeg' => array( 'jpg', 'jpeg' ) );
		if ( ! is_string( $path ) || '' === $path || str_contains( $path, "\0" ) || str_contains( $path, '://' ) || is_link( $path )
			|| ! is_string( $mime ) || ! isset( $types[ $mime ] ) || ! is_string( $ext ) || ! in_array( $ext, $types[ $mime ], true )
			|| ! isset( $file['size'] ) || ! is_int( $file['size'] ) || $file['size'] < 1 ) {
			throw new \RuntimeException( 'Report file is unavailable.' );
		}
		$stream = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $stream ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
		try {
			$stat = fstat( $stream );
			if ( ! is_array( $stat ) || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['size'] !== $file['size'] || $stat['mtime'] < 0 ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			$hash = hash_init( 'sha256' );
			if ( hash_update_stream( $hash, $stream ) !== $stat['size'] ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			$etag = '"sha256-' . hash_final( $hash ) . '"';
			$after = fstat( $stream );
			foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $key ) {
				if ( ! is_array( $after ) || $stat[ $key ] !== $after[ $key ] ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			}
			if ( 0 !== fseek( $stream, 0, SEEK_SET ) ) { throw new \RuntimeException( 'Report file is unavailable.' ); }
			return array( $stream, $stat, $etag );
		} catch ( \Throwable $error ) { fclose( $stream ); throw $error; }
	}

	/** @return array<string,string> */
	private static function headers( array $headers ): array {
		$out = array();
		$allowed = array( 'range', 'if-range', 'if-none-match', 'if-modified-since', 'if-match', 'if-unmodified-since' );
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) ) { continue; }
			$name = strtolower( str_replace( '_', '-', $name ) );
			if ( str_starts_with( $name, 'http-' ) ) { $name = substr( $name, 5 ); }
			if ( ! in_array( $name, $allowed, true ) ) { continue; }
			if ( is_array( $value ) ) {
				if ( array_filter( $value, static fn( $part ) => ! is_string( $part ) ) ) { continue; }
				$value = implode( ',', $value );
			}
			if ( ! is_string( $value ) || strlen( $value ) > 8192 || preg_match( '/[\r\n\0]/', $value ) ) { continue; }
			$value = trim( $value, " \t" );
			$out[ $name ] = isset( $out[ $name ] ) ? $out[ $name ] . ',' . $value : $value;
		}
		return $out;
	}

	private static function response( array $file, bool $download, bool $head, int $id, array $request, array $stat, string $etag ): array {
		$size = (int) $stat['size'];
		$mtime = (int) $stat['mtime'];
		$headers = array(
			'Cache-Control' => self::CACHE_CONTROL,
			'Pragma' => 'no-cache',
			'Expires' => '0',
			'X-Content-Type-Options' => 'nosniff',
			'Accept-Ranges' => 'bytes',
			'ETag' => $etag,
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', min( $mtime, time() ) ) . ' GMT',
		);
		$plan = array( 'status' => 200, 'headers' => $headers, 'start' => 0, 'length' => $size, 'body' => ! $head );
		// RFC 9110 13.2.2: check preconditions before ranges, and weakly compare If-None-Match.
		if ( isset( $request['if-match'] ) && ! self::tag_matches( $request['if-match'], $etag, false ) ) { return self::empty_response( $plan, 412 ); }
		if ( ! isset( $request['if-match'] ) && isset( $request['if-unmodified-since'] ) ) {
			$since = self::http_date( $request['if-unmodified-since'] );
			if ( null !== $since && $mtime > $since ) { return self::empty_response( $plan, 412 ); }
		}
		if ( isset( $request['if-none-match'] ) ) {
			if ( self::tag_matches( $request['if-none-match'], $etag, true ) ) { return self::empty_response( $plan, 304 ); }
		} elseif ( isset( $request['if-modified-since'] ) ) {
			$since = self::http_date( $request['if-modified-since'] );
			if ( null !== $since && $mtime <= $since ) { return self::empty_response( $plan, 304 ); }
		}
		// HEAD has GET's metadata, but Range is only defined for GET (RFC 9110 14.2).
		if ( ! $head && isset( $request['range'] ) ) {
			$use_range = true;
			if ( isset( $request['if-range'] ) ) {
				$validator = $request['if-range'];
				$date = self::http_date( $validator );
				$use_range = hash_equals( $etag, $validator ) || ( null !== $date && $date === $mtime && $mtime <= time() - 60 );
			}
			if ( $use_range ) {
				$range = self::range( $request['range'], $size );
				if ( false === $range ) {
					$plan = self::empty_response( $plan, 416 );
					$plan['headers']['Content-Range'] = 'bytes */' . $size;
					return $plan;
				}
				if ( is_array( $range ) ) {
					[ $start, $end ] = $range;
					$plan['status'] = 206;
					$plan['start'] = $start;
					$plan['length'] = $end - $start + 1;
					$plan['headers']['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
				}
			}
		}
		$plan['headers']['Content-Type'] = $file['mime'];
		$plan['headers']['Content-Disposition'] = self::disposition( $file, $download, $id );
		$plan['headers']['Content-Length'] = (string) $plan['length'];
		return $plan;
	}

	private static function empty_response( array $plan, int $status ): array {
		$plan['status'] = $status;
		$plan['length'] = 0;
		$plan['body'] = false;
		if ( 304 !== $status ) { $plan['headers']['Content-Length'] = '0'; }
		return $plan;
	}

	/** Returns null to ignore an unsupported unit/multiple ranges, false for an unsatisfiable byte range. */
	private static function range( string $value, int $size ): array|false|null {
		if ( ! preg_match( '/^bytes=(.*)$/iD', $value, $parts ) ) { return null; }
		$value = trim( $parts[1], " \t" );
		if ( str_contains( $value, ',' ) ) { return null; }
		if ( ! preg_match( '/^(\d*)-(\d*)$/D', $value, $match ) || ( '' === $match[1] && '' === $match[2] ) ) { return false; }
		if ( '' === $match[1] ) {
			$suffix = self::decimal( $match[2] );
			return $suffix < 1 ? false : array( max( 0, $size - $suffix ), $size - 1 );
		}
		$start = self::decimal( $match[1] );
		$end = '' === $match[2] ? $size - 1 : min( $size - 1, self::decimal( $match[2] ) );
		return $start >= $size || $start > $end ? false : array( $start, $end );
	}

	private static function decimal( string $digits ): int {
		$digits = ltrim( $digits, '0' );
		$max = (string) PHP_INT_MAX;
		return strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ? PHP_INT_MAX : (int) $digits;
	}

	private static function tag_matches( string $header, string $etag, bool $weak ): bool {
		if ( '*' === trim( $header ) ) { return true; }
		$remaining = $header;
		$found = false;
		while ( '' !== $remaining ) {
			if ( ! preg_match( '/^\s*((?:W\/)?"[\x21\x23-\x7E\x80-\xFF]*")\s*(,|$)/D', $remaining, $match ) ) { return false; }
			$tag = $match[1];
			if ( $weak && str_starts_with( $tag, 'W/' ) ) { $tag = substr( $tag, 2 ); }
			if ( hash_equals( $etag, $tag ) ) { $found = true; }
			$remaining = substr( $remaining, strlen( $match[0] ) );
			if ( ',' === $match[2] && '' === trim( $remaining ) ) { return false; }
		}
		return $found;
	}

	private static function http_date( string $value ): ?int {
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( array( '!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $utc );
			$errors = \DateTimeImmutable::getLastErrors();
			$valid = false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );
			if ( $date && $valid && $date->format( substr( $format, 1 ) ) === $value ) { return $date->getTimestamp(); }
		}
		$normal = preg_replace( '/ +/', ' ', $value );
		$date = \DateTimeImmutable::createFromFormat( '!D M j H:i:s Y', $normal, $utc );
		$errors = \DateTimeImmutable::getLastErrors();
		$valid = false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );
		return $date && $valid && $date->format( 'D M j H:i:s Y' ) === $normal ? $date->getTimestamp() : null;
	}

	private static function disposition( array $file, bool $download, int $id ): string {
		$original = isset( $file['original'] ) && is_string( $file['original'] ) ? $file['original'] : '';
		$original = basename( str_replace( '\\', '/', str_replace( array( "\r", "\n", "\0" ), '', $original ) ) );
		$name = preg_replace( '/[\x00-\x1f\x7f"<>:|?*]/u', '_', pathinfo( $original, PATHINFO_FILENAME ) );
		if ( ! is_string( $name ) || '' === trim( $name, ' .' ) || strlen( $name ) > 240 ) { $name = 'report-' . max( 0, $id ); }
		$name .= '.' . $file['ext'];
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		return ( $download ? 'attachment' : 'inline' ) . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}
}
