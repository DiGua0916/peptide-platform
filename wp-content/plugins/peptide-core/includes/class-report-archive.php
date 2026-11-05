<?php
/**
 * Explicitly shared source documents, separate from stock-batch report publication.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Report_Archive {

	public const PUBLIC_META = '_pc_report_library_public';
	public const THUMB_META  = '_pc_report_library_thumbnail';
	private const NAMESPACE = 'peptide/v1';
	private const ROUTE     = '/report-library';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'file_meta_changed' ), 10, 4 );
		}
	}

	/** Every write path, including XML-RPC, revokes the previous file's archive grant. */
	public static function file_meta_changed( $meta_ids, int $post_id, string $meta_key, $meta_value ): void {
		if ( Meta::REPORT_FILE !== $meta_key || Post_Types::REPORT !== get_post_type( $post_id ) ) { return; }
		$thumbnail = get_post_meta( $post_id, self::THUMB_META, true );
		$current_file = get_post_meta( $post_id, Meta::REPORT_FILE, true );
		// A malformed thumbnail pointer must never delete the original report (including image reports).
		if ( is_array( $thumbnail ) && ! empty( $thumbnail['stored'] )
			&& ( ! is_array( $current_file ) || ( $current_file['stored'] ?? null ) !== $thumbnail['stored'] )
			&& self::validated_file( $thumbnail, true ) ) {
			Private_Files::delete( (string) $thumbnail['stored'] );
		}
		delete_post_meta( $post_id, self::THUMB_META );
		update_post_meta( $post_id, self::PUBLIC_META, false );
		delete_post_meta( $post_id, Meta::REPORT_SOURCE_SHA256 );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, array(
			'methods' => array( 'GET', 'HEAD' ),
			'permission_callback' => '__return_true',
			'callback' => array( self::class, 'rest_query' ),
		) );
		foreach ( array( 'file', 'thumbnail' ) as $kind ) {
			register_rest_route( self::NAMESPACE, self::ROUTE . '/(?P<id>\d+)/' . $kind, array(
				'methods' => array( 'GET', 'HEAD' ),
				'permission_callback' => '__return_true',
				'callback' => array( self::class, 'thumbnail' === $kind ? 'rest_thumbnail' : 'rest_file' ),
			) );
		}
	}

	/** An editor session never expands this public result set. */
	public static function rest_query( \WP_REST_Request $request ) {
		nocache_headers();
		$limited = self::rate_limit( 'query', 120 );
		if ( $limited ) { return $limited; }
		$filters = $request->get_query_params();
		unset( $filters['rest_route'] ); // WordPress plain permalinks carry the route in the query string.
		$result = self::query( $filters );
		if ( is_wp_error( $result ) ) { return $result; }
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/**
	 * Read only explicit archive opt-ins. Unknown dates always sort after known dates.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function query( array $filters = array() ) {
		$args = self::parse( $filters );
		if ( is_wp_error( $args ) ) { return $args; }
		$items = array();
		$products = array();
		$posts = get_posts( array(
			'post_type' => Post_Types::REPORT,
			'post_status' => array( 'draft', 'publish' ),
			'has_password' => false,
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_key' => self::PUBLIC_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		) );
		// Returning posts primes the native post cache as well as metadata. An IDs
		// query followed by eligible()/get_post() otherwise reloads every report.
		$ids = array_map( static fn( \WP_Post $post ): int => (int) $post->ID, $posts );
		if ( $ids ) { update_meta_cache( 'post', array_map( 'intval', $ids ) ); }
		self::prime_associations( $ids );
		foreach ( $ids as $id ) {
			// Source files still pass the same complete eligibility/MIME/content
			// checks before contributing to counts. Cover images cannot influence
			// filtering or ordering, so inspect only the selected page's covers.
			$item = self::get_public( (int) $id, false );
			if ( ! $item ) { continue; }
			if ( $item['product'] ) {
				$product = $item['product'];
				if ( ! isset( $products[ $product['id'] ] ) ) {
					$products[ $product['id'] ] = array( 'id' => $product['id'], 'name' => $product['name'], 'count' => 0 );
				}
				++$products[ $product['id'] ]['count'];
			}
			if ( $args['product_id'] && ( ! $item['product'] || $args['product_id'] !== $item['product']['id'] ) ) { continue; }
			if ( $args['date_from'] && ( ! $item['issued_on'] || $item['issued_on'] < $args['date_from'] ) ) { continue; }
			if ( $args['date_to'] && ( ! $item['issued_on'] || $item['issued_on'] > $args['date_to'] ) ) { continue; }
			if ( '' !== $args['q'] && ! self::matches( $item, $args['q'] ) ) { continue; }
			$items[] = $item;
		}
		usort( $items, static function( array $a, array $b ) use ( $args ): int {
			if ( null === $a['issued_on'] && null !== $b['issued_on'] ) { return 1; }
			if ( null !== $a['issued_on'] && null === $b['issued_on'] ) { return -1; }
			$direction = 'oldest' === $args['sort'] ? 1 : -1;
			return $direction * ( strcmp( (string) $a['issued_on'], (string) $b['issued_on'] ) ?: ( $a['id'] <=> $b['id'] ) );
		} );
		$products = array_values( $products );
		usort( $products, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) ?: ( $a['id'] <=> $b['id'] ) );
		$total = count( $items );
		$page = array_slice( $items, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] );
		foreach ( $page as &$item ) {
			$item['thumbnail_url'] = self::thumbnail_file( $item['id'] )
				? rest_url( self::NAMESPACE . self::ROUTE . '/' . $item['id'] . '/thumbnail' ) : null;
		}
		unset( $item );
		return array(
			'items' => $page,
			'total' => $total,
			'page' => $args['page'],
			'per_page' => $args['per_page'],
			'total_pages' => (int) ceil( $total / $args['per_page'] ),
			'products' => $products,
			'filters' => $args,
		);
	}

	/** Public allowlist only: never return provenance notes, clients, hashes or storage names. */
	public static function get_public( int $report_id, bool $with_thumbnail = true ): ?array {
		if ( ! self::eligible( $report_id ) ) { return null; }
		$file = self::validated_file( get_post_meta( $report_id, Meta::REPORT_FILE, true ) );
		if ( ! $file ) { return null; }
		$product = null;
		$product_id = Reports::product_id( $report_id );
		$post = $product_id ? get_post( $product_id ) : null;
		if ( $post instanceof \WP_Post && 'product' === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password ) {
			$product = array( 'id' => $product_id, 'name' => self::text( get_the_title( $product_id ) ), 'url' => (string) get_permalink( $product_id ) );
		}
		$date = self::text( get_post_meta( $report_id, Meta::REPORT_ISSUED_ON, true ) );
		$thumbnail = $with_thumbnail ? self::thumbnail_file( $report_id ) : null;
		$url = rest_url( self::NAMESPACE . self::ROUTE . '/' . $report_id . '/file' );
		return array(
			'id' => $report_id,
			'title' => self::text( get_the_title( $report_id ) ),
			'product' => $product,
			'source_product' => self::text( get_post_meta( $report_id, Meta::REPORT_SOURCE_PRODUCT, true ) ),
			'specification' => self::text( get_post_meta( $report_id, Meta::REPORT_SPECIFICATION, true ) ),
			'report_number' => self::text( get_post_meta( $report_id, Meta::REPORT_REFERENCE_ID, true ) ),
			'source_lot' => self::text( get_post_meta( $report_id, Meta::REPORT_SOURCE_LOT, true ) ),
			'issued_on' => self::valid_date( $date ) ? $date : null,
			'issuer_name' => self::text( get_post_meta( $report_id, Meta::REPORT_ISSUER_NAME, true ) ),
			'file' => array( 'mime' => $file['mime'], 'size_bytes' => $file['size'] ),
			'view_url' => add_query_arg( 'download', '0', $url ),
			'download_url' => add_query_arg( 'download', '1', $url ),
			'thumbnail_url' => $thumbnail ? rest_url( self::NAMESPACE . self::ROUTE . '/' . $report_id . '/thumbnail' ) : null,
		);
	}

	/** Prime only native association records; no cross-request validity cache. */
	private static function prime_associations( array $report_ids ): void {
		$related = array();
		foreach ( $report_ids as $id ) {
			$batch = (int) get_post_meta( $id, Meta::REPORT_BATCH_ID, true );
			$product = (int) get_post_meta( $id, Meta::REPORT_PRODUCT_ID, true );
			if ( $batch > 0 ) { $related[ $batch ] = true; }
			if ( $product > 0 ) { $related[ $product ] = true; }
		}
		if ( ! $related ) { return; }
		$posts = get_posts( array(
			'post_type' => array( Post_Types::BATCH, 'product', 'product_variation' ),
			'post_status' => 'any', 'post__in' => array_keys( $related ),
			'posts_per_page' => count( $related ), 'no_found_rows' => true,
			'update_post_meta_cache' => true, 'update_post_term_cache' => true,
		) );
		$products = array();
		foreach ( $posts as $post ) {
			if ( Post_Types::BATCH !== $post->post_type ) { continue; }
			$id = (int) get_post_meta( $post->ID, Meta::BATCH_PRODUCT_ID, true );
			if ( $id > 0 && ! isset( $related[ $id ] ) ) { $products[ $id ] = true; }
		}
		if ( $products ) {
			get_posts( array(
				'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any',
				'post__in' => array_keys( $products ), 'posts_per_page' => count( $products ),
				'no_found_rows' => true, 'update_post_meta_cache' => true, 'update_post_term_cache' => true,
			) );
		}
	}

	public static function rest_file( \WP_REST_Request $request ) {
		nocache_headers();
		// PDF.js can need more than 120 bounded ranges for one large PDF. Full
		// responses retain the original budget; all attempts also have a ceiling.
		$limited = self::rate_limit( 'file_request', 960 );
		if ( $limited ) { return $limited; }
		$download = $request->get_param( 'download' ) ?? '0';
		if ( ! ( is_string( $download ) || is_int( $download ) ) || ! in_array( (string) $download, array( '0', '1' ), true ) ) {
			return self::error( 'download', __( 'Choose a valid file action.', 'peptide-core' ) );
		}
		$id = (int) $request['id'];
		if ( ! self::eligible( $id ) ) { return self::not_found(); }
		$file = self::validated_file( get_post_meta( $id, Meta::REPORT_FILE, true ) );
		if ( ! $file ) { return self::not_found(); }
		$small_range = 'GET' === $request->get_method() && null === $request->get_header( 'if-range' )
			&& Report_File_Response::small_range_request( $file['size'], (string) $request->get_header( 'range' ) );
		if ( ! $small_range ) {
			$limited = self::rate_limit( 'file', 120 );
			if ( $limited ) { return $limited; }
		}
		return self::stream( $file, '1' === (string) $download, 'HEAD' === $request->get_method(), $id, $request->get_headers() );
	}

	public static function rest_thumbnail( \WP_REST_Request $request ) {
		nocache_headers();
		$limited = self::rate_limit( 'thumbnail', 600 );
		if ( $limited ) { return $limited; }
		$id = (int) $request['id'];
		if ( ! self::eligible( $id ) || ! self::validated_file( get_post_meta( $id, Meta::REPORT_FILE, true ) ) ) { return self::not_found(); }
		$file = self::thumbnail_file( $id );
		if ( ! $file ) { return self::not_found(); }
		return self::stream( $file, false, 'HEAD' === $request->get_method(), $id, $request->get_headers() );
	}

	/** Archive visibility is independent of batch publication/access. It must be explicitly granted. */
	private static function eligible( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && Post_Types::REPORT === $post->post_type
			&& in_array( $post->post_status, array( 'draft', 'publish' ), true ) && '' === (string) $post->post_password
			&& '1' === get_post_meta( $id, self::PUBLIC_META, true )
			&& ! in_array( get_post_meta( $id, Meta::REPORT_STATUS, true ), array( 'superseded', 'withdrawn' ), true )
			&& ! Meta::bool( get_post_meta( $id, Meta::REPORT_IS_DEMO, true ) );
	}

	/** A preview must belong to the current uploaded PDF, never an earlier replacement. */
	private static function thumbnail_file( int $report_id ): ?array {
		$file = get_post_meta( $report_id, self::THUMB_META, true );
		$source = get_post_meta( $report_id, Meta::REPORT_SOURCE_SHA256, true );
		if ( ! is_array( $file ) || ! isset( $file['source_sha256'] ) || ! is_string( $file['source_sha256'] ) || ! is_string( $source )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $source ) || ! hash_equals( $source, $file['source_sha256'] ) ) { return null; }
		return self::validated_file( $file, true );
	}

	/** Require plugin-owned names, a real path within storage and content-derived MIME. */
	private static function validated_file( $file, bool $thumbnail = false ): ?array {
		if ( ! is_array( $file ) || ! isset( $file['stored'] ) || ! is_string( $file['stored'] ) ) { return null; }
		$path = Private_Files::path( $file['stored'] );
		$root = realpath( Private_Files::dir() );
		$real = $path ? realpath( $path ) : false;
		if ( false === $root || false === $real || ! is_file( $real ) || ! is_readable( $real ) ) { return null; }
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$compare = str_replace( '\\', '/', $real );
		if ( '\\' === DIRECTORY_SEPARATOR ) { $root = strtolower( $root ); $compare = strtolower( $compare ); }
		if ( ! str_starts_with( $compare, $root ) ) { return null; }
		$size = (int) filesize( $real );
		if ( $size < 1 || $size > Private_Files::MAX_BYTES || ! class_exists( '\finfo' ) ) { return null; }
		$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $real );
		$allowed = $thumbnail ? array( 'image/png' => array( 'png' ), 'image/jpeg' => array( 'jpg', 'jpeg' ) ) : array( 'application/pdf' => array( 'pdf' ), 'image/png' => array( 'png' ), 'image/jpeg' => array( 'jpg', 'jpeg' ) );
		$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( ! is_string( $mime ) || ! isset( $allowed[ $mime ] ) || ! in_array( $ext, $allowed[ $mime ], true ) ) { return null; }
		if ( 'application/pdf' === $mime ) {
			$handle = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) { return null; }
			$magic = fread( $handle, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( '%PDF-' !== $magic ) { return null; }
		} else {
			$image = @getimagesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $image ) || ( $image['mime'] ?? null ) !== $mime || empty( $image[0] ) || empty( $image[1] ) ) { return null; }
		}
		return array( 'path' => $real, 'mime' => $mime, 'size' => $size, 'ext' => $ext, 'original' => isset( $file['original'] ) && is_string( $file['original'] ) ? $file['original'] : '' );
	}

	/** Public eligibility is checked before even a 304/206 response can be sent. */
	private static function stream( array $file, bool $download, bool $head, int $id, array $headers ) {
		try {
			Report_File_Response::send( $file, $download, $head, $id, $headers );
		} catch ( \RuntimeException $error ) {
			return self::not_found();
		}
	}

	private static function matches( array $item, string $q ): bool {
		$parts = array( $item['title'], $item['source_product'], $item['specification'], $item['report_number'], $item['source_lot'] );
		if ( $item['product'] ) {
			$parts[] = $item['product']['name'];
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $item['product']['id'] ) : null;
			if ( $product instanceof \WC_Product ) { $parts[] = self::text( $product->get_sku() ); }
		}
		$haystack = implode( ' ', $parts );
		return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $haystack, $q, 0, 'UTF-8' ) : false !== stripos( $haystack, $q );
	}

	private static function parse( array $filters ) {
		$args = array( 'q' => '', 'product_id' => 0, 'date_from' => '', 'date_to' => '', 'sort' => 'newest', 'page' => 1, 'per_page' => 12 );
		foreach ( array_keys( $filters ) as $key ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $args ) ) { return self::error( 'query', __( 'Unsupported report filter.', 'peptide-core' ) ); }
		}
		foreach ( $args as $key => $default ) {
			if ( ! array_key_exists( $key, $filters ) ) { continue; }
			$value = $filters[ $key ];
			if ( in_array( $key, array( 'page', 'per_page', 'product_id' ), true ) ) {
				if ( ! ( is_int( $value ) || is_string( $value ) ) || ! preg_match( '/^\d{1,9}$/D', (string) $value ) ) { return self::error( $key, __( 'Choose a valid page, page size or product.', 'peptide-core' ) ); }
				$value = (int) $value;
				if ( ( 'product_id' !== $key && $value < 1 ) || ( 'per_page' === $key && $value > 24 ) ) { return self::error( $key, __( 'Choose a valid page, page size or product.', 'peptide-core' ) ); }
			} else {
				if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value ) ) { return self::error( $key, __( 'Use a valid search or filter value.', 'peptide-core' ) ); }
				if ( 'q' === $key ) {
					if ( preg_match_all( '/./us', $value ) > 120 ) { return self::error( 'q', __( 'Use no more than 120 characters.', 'peptide-core' ) ); }
					$value = trim( sanitize_text_field( $value ) );
				} elseif ( 'sort' === $key ) {
					if ( ! in_array( $value, array( 'newest', 'oldest' ), true ) ) { return self::error( 'sort', __( 'Choose a valid date order.', 'peptide-core' ) ); }
				} elseif ( '' !== $value && ! self::valid_date( $value ) ) { return self::error( $key, __( 'Use a valid date in YYYY-MM-DD format.', 'peptide-core' ) ); }
			}
			$args[ $key ] = $value;
		}
		if ( $args['date_from'] && $args['date_to'] && $args['date_from'] > $args['date_to'] ) { return self::error( 'date_to', __( 'The end date must be on or after the start date.', 'peptide-core' ) ); }
		return $args;
	}

	private static function valid_date( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
	}

	private static function error( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'pc_report_library_invalid_filter', $message, array( 'status' => 400, 'field' => $field ) );
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'pc_report_library_not_found', __( 'Report not found.', 'peptide-core' ), array( 'status' => 404 ) );
	}

	/** Only a salted hash is persisted; the client address is never stored. */
	private static function rate_limit( string $bucket, int $limit ): ?\WP_Error {
		$ip = Api\Rest_Controller::client_ip();
		$key = 'pc_report_library_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$now = time();
		$window = 10 * MINUTE_IN_SECONDS;
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) || ! isset( $hit['count'], $hit['reset'] ) || (int) $hit['reset'] <= $now ) { $hit = array( 'count' => 0, 'reset' => $now + $window ); }
		if ( (int) $hit['count'] >= $limit ) {
			return new \WP_Error( 'pc_rate_limited', __( 'Too many requests. Please try again later.', 'peptide-core' ), array( 'status' => 429, 'retry_after' => max( 1, (int) $hit['reset'] - $now ) ) );
		}
		++$hit['count'];
		set_transient( $key, $hit, max( 1, (int) $hit['reset'] - $now ) );
		return null;
	}
}
