<?php
/** Narrow raw CSV response marker. Rows never become REST JSON response data. */
namespace PeptideCore\Data;

defined( 'ABSPATH' ) || exit;

final class Csv_Response extends \WP_REST_Response {

	private iterable $rows;

	public function __construct( iterable $rows, string $filename ) {
		$this->rows = $rows;
		$filename = preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
		parent::__construct( null, 200, array(
			'Content-Type' => 'text/csv; charset=UTF-8',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			'Cache-Control' => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		) );
	}

	/** Untrusted text only. Genuine numeric cells must not call this method. */
	public static function text( $value ): string {
		$text = (string) $value;
		return preg_match( '/^[\s]*[=+@-]/u', $text ) ? "'" . $text : $text;
	}

	/** Runs after native REST header emission and respects the final response authority. */
	public static function serve( $served, $response, $request, $server ) {
		if ( $served || ! $response instanceof self || 200 !== $response->get_status() || ! in_array( $request->get_route(), array( '/peptide/v1/data/products', '/peptide/v1/data/pages' ), true ) || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $served;
		}
		if ( 'HEAD' === $request->get_method() ) { return true; }
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( $response->rows as $row ) { fputcsv( $out, $row, ',', '"', '' ); }
		fclose( $out );
		return true;
	}
}
