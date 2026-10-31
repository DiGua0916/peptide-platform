<?php
/** Fixed, public-only search shared by the O03 suggestions and its GET results page. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Public_Site_Search {
	public const ROUTE = 'pc_site_search';
	public const REST_ROUTE = '/site-search';
	public const PER_PAGE = 8;
	private const MAX_PAGE = 20;
	private const BATCH = 32;
	private const MAX_SCAN = 512;
	private const TYPES = array( 'products' => 'Products', 'collections' => 'Collections', 'topics' => 'Topics', 'blogs' => 'Blogs', 'pages' => 'Pages', 'promotions' => 'Promotions', 'coupons' => 'Coupons' );
	private static $page_state = null;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'query_vars', static function( $vars ) { $vars[] = self::ROUTE; return $vars; } );
		add_action( 'pre_get_posts', array( self::class, 'main_query' ), 99 );
		add_action( 'template_redirect', array( self::class, 'page_gate' ), 1 );
		add_filter( 'template_include', array( self::class, 'template' ), 10000 );
		add_filter( 'pre_get_document_title', static fn( $title ) => self::is_results_request() ? __( 'Search results', 'peptide-core' ) . ' | ' . get_bloginfo( 'name' ) : $title, 100 );
	}

	public static function labels(): array {
		return array_map( static fn( $text ) => translate( $text, 'peptide-core' ), self::TYPES );
	}

	public static function routes(): void {
		register_rest_route( 'peptide/v1', self::REST_ROUTE, array( 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'rest' ) ) );
	}

	/** The public boundary also applies to administrators; an editor nonce grants no extra rows. */
	public static function permission() {
		if ( Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
			return new \WP_Error( 'pc_search_login_required', __( 'Please sign in to search this store.', 'peptide-core' ), array( 'status' => 401, 'login_url' => self::site_url( (string) $url ) ?: wp_login_url() ) );
		}
		return true;
	}

	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'pc_search_' . $code, $message, array( 'status' => $status ) );
	}

	/** A scope is a display subset, never an authorization grant. Empty/unknown never means all. */
	public static function parse( array $input ) {
		foreach ( array_keys( $input ) as $key ) { if ( ! in_array( $key, array( 'q', 'scope', 'type', 'page' ), true ) ) { return self::error( 'query', __( 'Unsupported search parameter.', 'peptide-core' ) ); } }
		if ( ! isset( $input['q'] ) || ! is_string( $input['q'] ) || ! preg_match( '//u', $input['q'] ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $input['q'] ) ) { return self::error( 'query', __( 'Enter a valid search phrase.', 'peptide-core' ) ); }
		$q = trim( $input['q'] );
		$length = preg_match_all( '/./us', $q );
		if ( false === $length || $length < 1 || $length > 100 ) { return self::error( 'query', __( 'Use between 1 and 100 characters.', 'peptide-core' ) ); }
		$q = sanitize_text_field( $q );
		if ( '' === $q ) { return self::error( 'query', __( 'Enter a search phrase.', 'peptide-core' ) ); }
		$scope = array_keys( self::TYPES );
		if ( array_key_exists( 'scope', $input ) ) {
			if ( ! is_string( $input['scope'] ) || strlen( $input['scope'] ) > 100 ) { return self::error( 'scope', __( 'Choose valid search types.', 'peptide-core' ) ); }
			$requested = explode( ',', $input['scope'] );
			if ( ! $requested || count( $requested ) > 7 || array_diff( $requested, array_keys( self::TYPES ) ) ) { return self::error( 'scope', __( 'Choose valid search types.', 'peptide-core' ) ); }
			$scope = array_values( array_filter( array_keys( self::TYPES ), static fn( $type ) => in_array( $type, $requested, true ) ) );
			if ( ! $scope ) { return self::error( 'scope', __( 'Choose at least one search type.', 'peptide-core' ) ); }
		}
		$type = $input['type'] ?? $scope[0];
		if ( ! is_string( $type ) || ! isset( self::TYPES[ $type ] ) || ! in_array( $type, $scope, true ) ) { return self::error( 'type', __( 'Choose a valid search type.', 'peptide-core' ) ); }
		$page = $input['page'] ?? '1';
		if ( ! ( is_string( $page ) || is_int( $page ) ) || ! preg_match( '/^[1-9][0-9]?$/D', (string) $page ) || (int) $page > self::MAX_PAGE ) { return self::error( 'page', __( 'Choose a page between 1 and 20.', 'peptide-core' ) ); }
		return array( 'q' => $q, 'type' => $type, 'scope' => $scope, 'page' => (int) $page );
	}

	/** Consume only enabled fixed enum rows from the trusted repeater protocol. */
	public static function configured_types( $rows ): array {
		$chosen = array();
		foreach ( is_array( $rows ) ? array_slice( $rows, 0, 7 ) : array() as $row ) {
			$type = is_array( $row ) && ! empty( $row['enabled'] ) ? ( $row['settings']['type'] ?? null ) : null;
			if ( is_string( $type ) && isset( self::TYPES[ $type ] ) ) { $chosen[ $type ] = true; }
		}
		return array_values( array_filter( array_keys( self::TYPES ), static fn( $type ) => isset( $chosen[ $type ] ) ) );
	}

	public static function rest( \WP_REST_Request $request ) {
		nocache_headers();
		$result = self::search( $request->get_params() );
		if ( is_wp_error( $result ) ) { return $result; }
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/** Scan bounded candidates so eligibility filtering does not turn a real later match into fake empty. */
	public static function search( array $input ) {
		$permission = self::permission(); if ( is_wp_error( $permission ) ) { return $permission; }
		$args = self::parse( $input ); if ( is_wp_error( $args ) ) { return $args; }
		$skip = ( $args['page'] - 1 ) * self::PER_PAGE; $seen = array(); $eligible = 0; $rows = array();
		try {
			for ( $offset = 0; $offset < self::MAX_SCAN; $offset += self::BATCH ) {
				$candidates = self::candidates( $args['type'], $args['q'], $offset );
				foreach ( $candidates as $candidate ) {
					$id = $candidate instanceof \WP_Term ? (int) $candidate->term_id : (int) $candidate->ID;
					if ( isset( $seen[ $id ] ) ) { continue; } $seen[ $id ] = true;
					$row = self::public_row( $args['type'], $candidate, $eligible >= $skip );
					if ( ! $row ) { continue; }
					if ( $eligible++ < $skip ) { continue; }
					if ( count( $rows ) === self::PER_PAGE ) { return self::payload( $args, $rows, true ); }
					$rows[] = $row;
				}
				if ( count( $candidates ) < self::BATCH ) { return self::payload( $args, $rows, false ); }
			}
			return self::error( 'budget', __( 'Search is busy. Refine your phrase and try again.', 'peptide-core' ), 503 );
		} catch ( \Throwable $error ) { return self::error( 'unavailable', __( 'Search could not be loaded. Please try again.', 'peptide-core' ), 503 ); }
	}

	private static function payload( array $args, array $rows, bool $more ): array {
		return $args + array( 'perPage' => self::PER_PAGE, 'results' => $rows, 'hasMore' => $more, 'nextPage' => $more && $args['page'] < self::MAX_PAGE ? $args['page'] + 1 : null, 'resultsUrl' => self::results_url( $args ) );
	}

	private static function candidates( string $type, string $q, int $offset ): array {
		if ( 'collections' === $type ) {
			$taxonomy = get_taxonomy( 'product_cat' );
			if ( ! $taxonomy || ! $taxonomy->public || ! $taxonomy->publicly_queryable ) { return array(); }
			$terms = self::read( static fn() => get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => $q, 'number' => self::BATCH, 'offset' => $offset, 'orderby' => 'term_id', 'order' => 'ASC' ) ) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { throw new \RuntimeException( 'Search term read failed.' ); }
			return $terms;
		}
		$types = array( 'products' => 'product', 'topics' => 'pc_topic', 'blogs' => 'post', 'pages' => 'page', 'promotions' => Promotions::CPT, 'coupons' => 'shop_coupon' );
		$args = array( 'post_type' => $types[ $type ], 'post_status' => 'publish', 'has_password' => false, 's' => $q, 'search_columns' => array( 'post_title' ), 'posts_per_page' => self::BATCH, 'offset' => $offset, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'order' => 'ASC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false );
		if ( 'pages' === $type ) { $args['post__not_in'] = self::system_pages(); }
		if ( 'coupons' === $type ) { $args['meta_query'] = array( array( 'key' => Coupons::PUBLIC_META, 'value' => 'yes' ) ); }
		if ( 'products' === $type ) {
			if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) { throw new \RuntimeException( 'Product search is unavailable.' ); }
			$ids = self::read( static fn() => wc_get_product_visibility_term_ids() );
			$excluded = array( (int) ( $ids['exclude-from-search'] ?? 0 ) );
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) { $excluded[] = (int) ( $ids['outofstock'] ?? 0 ); }
			$excluded = array_values( array_filter( $excluded ) );
			if ( $excluded ) { $args['tax_query'] = array( array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => $excluded, 'operator' => 'NOT IN' ) ); }
		}
		$query = self::read( static fn() => new \WP_Query( $args ) );
		return is_array( $query->posts ) ? $query->posts : array();
	}

	private static function system_pages(): array {
		return function_exists( 'wc_get_page_id' ) ? array_values( array_filter( array_map( 'wc_get_page_id', array( 'cart', 'checkout', 'myaccount' ) ), static fn( $id ) => $id > 0 ) ) : array();
	}

	private static function public_post( $post, string $expected ): bool {
		return $post instanceof \WP_Post && $expected === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password && is_post_publicly_viewable( $post );
	}

	private static function marketing_page( string $type ): bool {
		$id = 'promotions' === $type ? Promotions::page_id() : Coupons::page_id();
		$post = self::read( static fn() => get_post( $id ) );
		return $id > 0 && self::public_post( $post, 'page' );
	}

	private static function public_row( string $type, $candidate, bool $details ): ?array {
		$image_id = 0; $product = null; $url = ''; $title = ''; $subtitle = '';
		if ( 'collections' === $type ) {
			if ( ! $candidate instanceof \WP_Term || 'product_cat' !== $candidate->taxonomy ) { return null; }
			$url = self::read( static fn() => get_term_link( $candidate ) ); if ( is_wp_error( $url ) ) { throw new \RuntimeException( 'Collection link failed.' ); }
			$id = (int) $candidate->term_id; $title = $candidate->name;
			if ( $details ) { $image_id = (int) get_term_meta( $id, 'thumbnail_id', true ); }
		} else {
			$post = $candidate; if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) { return null; }
			$id = (int) $post->ID; $title = get_the_title( $post );
			if ( 'products' === $type ) {
				if ( ! self::public_post( $post, 'product' ) ) { return null; }
				$product = self::read( static fn() => wc_get_product( $id ) );
				if ( ! $product instanceof \WC_Product || $product->get_parent_id() || ! in_array( $product->get_catalog_visibility(), array( 'visible', 'search' ), true ) || ! $product->is_publicly_viewable() || '' !== $product->get_post_password() || 'publish' !== $product->get_status() || ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) ) { return null; }
				$url = $product->get_permalink(); if ( $details ) { $image_id = (int) $product->get_image_id(); }
			} elseif ( 'promotions' === $type ) {
				if ( Promotions::CPT !== $post->post_type || ! self::marketing_page( $type ) ) { return null; }
				$raw = self::read( static fn() => get_post_meta( $id, Promotions::META, true ) );
				$rule = is_array( $raw ) ? ( $raw['customer_rule'] ?? null ) : null;
				$config = self::read( static fn() => Promotions::config( $id ) );
				if ( empty( $config['public'] ) || ! is_array( $rule ) || 'all' !== ( $rule['customers'] ?? null ) || ! in_array( Promotions::status( $id ), array( 'active', 'upcoming' ), true ) ) { return null; }
				$view = self::read( static fn() => Promotions::get_public( $id ) ); if ( ! $view ) { return null; }
				$start = (string) ( $view['starts_text'] ?? '' ); $end = (string) ( $view['ends_text'] ?? '' );
				$subtitle = $start && $end ? $start . ' – ' . $end : ( $start ?: $end );
				$url = Promotions::detail_url( $id );
			} elseif ( 'coupons' === $type ) {
				if ( 'shop_coupon' !== $post->post_type || ! self::marketing_page( $type ) ) { return null; }
				$has_rule = self::read( static fn() => metadata_exists( 'post', $id, Customer_Segments::COUPON_META ) );
				$rule = $has_rule ? self::read( static fn() => get_post_meta( $id, Customer_Segments::COUPON_META, true ) ) : null;
				// Only a truly absent legacy key is All; present empty/false storage is malformed.
				if ( $has_rule && ( ! is_array( $rule ) || 'all' !== ( $rule['customers'] ?? null ) ) ) { return null; }
				$coupon = self::read( static fn() => new \WC_Coupon( $id ) );
				$view = self::read( static fn() => Coupons::get_public( $coupon->get_code() ) );
				if ( ! $view || $coupon->get_id() !== $id || (int) ( $view['id'] ?? 0 ) !== $id ) { return null; }
				$title = strtoupper( $coupon->get_code() ); $url = Coupons::detail_url( $coupon->get_code() );
			} else {
				$expected = array( 'topics' => 'pc_topic', 'blogs' => 'post', 'pages' => 'page' );
				if ( ! isset( $expected[ $type ] ) || ! self::public_post( $post, $expected[ $type ] ) || ( 'pages' === $type && in_array( $id, self::system_pages(), true ) ) ) { return null; }
				$url = get_permalink( $post ); if ( $details ) { $image_id = (int) get_post_thumbnail_id( $post ); }
			}
		}
		$url = self::site_url( (string) $url ); if ( '' === $url ) { return null; }
		$row = array( 'type' => $type, 'id' => $id, 'title' => self::text( (string) $title, 200 ), 'url' => $url );
		if ( '' !== $subtitle ) { $row['subtitle'] = self::text( $subtitle, 200 ); }
		if ( ! $details ) { return $row; }
		if ( $image_id ) {
			$image = self::read( static fn() => wp_get_attachment_image_src( $image_id, 'woocommerce_thumbnail' ) );
			if ( $image && in_array( wp_parse_url( (string) $image[0], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) { $row['image'] = array( 'src' => esc_url_raw( $image[0], array( 'http', 'https' ) ), 'alt' => self::text( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ), 200 ) ); }
		}
		if ( $product ) {
			$price = self::read( static fn() => function_exists( 'vc_card_price_html' ) ? vc_card_price_html( $product ) : $product->get_price_html() );
			$row['priceHtml'] = self::price_html( (string) $price );
		}
		return $row;
	}

	private static function text( string $value, int $max ): string {
		$value = wp_specialchars_decode( wp_strip_all_tags( $value ), ENT_QUOTES );
		return preg_match( '/^.{0,' . $max . '}/us', $value, $match ) ? $match[0] : '';
	}

	public static function price_html( string $html ): string {
		return strlen( $html ) > 4000 ? '' : wp_kses( $html, array( 'span' => array( 'class' => true, 'aria-hidden' => true ), 'bdi' => array(), 'del' => array( 'aria-hidden' => true ), 'ins' => array( 'aria-hidden' => true ), 'small' => array( 'class' => true ) ) );
	}

	private static function site_url( string $url ): string {
		$url = esc_url_raw( $url, array( 'http', 'https' ) ); $site = wp_parse_url( home_url( '/' ) ); $parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $site['host'] ?? '' ) || (int) ( $parts['port'] ?? 0 ) !== (int) ( $site['port'] ?? 0 ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return ''; }
		return $url;
	}

	private static function read( callable $work ) {
		global $wpdb; $before = $wpdb->num_queries; $previous = $wpdb->suppress_errors( true );
		try { $result = $work(); if ( $wpdb->num_queries > $before && $wpdb->last_error ) { throw new \RuntimeException( 'Search read failed.' ); } return $result; }
		finally { $wpdb->suppress_errors( $previous ); }
	}

	public static function results_url( array $args, ?int $page = null, ?string $type = null ): string {
		return add_query_arg( array( self::ROUTE => '1', 'pc_site_q' => rawurlencode( $args['q'] ), 'pc_site_type' => $type ?? $args['type'], 'pc_site_page' => $page ?? $args['page'], 'pc_site_scope' => implode( ',', $args['scope'] ) ), home_url( '/' ) );
	}

	public static function is_results_request(): bool { return ! is_admin() && isset( $_GET[ self::ROUTE ] ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	public static function main_query( \WP_Query $query ): void {
		if ( ! self::is_results_request() || ! $query->is_main_query() ) { return; }
		$query->is_home = false; $query->is_page = false; $query->is_search = true; $query->is_404 = false;
		$query->set( 'post_type', 'page' ); $query->set( 'post__in', array( 0 ) ); $query->set( 'posts_per_page', 1 ); $query->set( 'no_found_rows', true );
	}

	public static function page_gate(): void {
		if ( ! self::is_results_request() ) { return; }
		nocache_headers();
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) || '1' !== ( $_GET[ self::ROUTE ] ?? null ) ) { wp_die( esc_html__( 'Invalid search request.', 'peptide-core' ), '', array( 'response' => 400 ) ); }
		$permission = self::permission();
		if ( is_wp_error( $permission ) ) {
			$data = $permission->get_error_data();
			wp_die( esc_html( $permission->get_error_message() ) . ' <a href="' . esc_url( $data['login_url'] ) . '">' . esc_html__( 'Sign in', 'peptide-core' ) . '</a>', '', array( 'response' => 401 ) );
		}
		$raw = wp_unslash( $_GET );
		foreach ( array_keys( $raw ) as $key ) { if ( ! in_array( $key, array( self::ROUTE, 'pc_site_q', 'pc_site_type', 'pc_site_page', 'pc_site_scope' ), true ) ) { wp_die( esc_html__( 'Unsupported search parameter.', 'peptide-core' ), '', array( 'response' => 400 ) ); } }
		$input = array( 'q' => $raw['pc_site_q'] ?? null, 'page' => $raw['pc_site_page'] ?? '1' );
		foreach ( array( 'type' => 'pc_site_type', 'scope' => 'pc_site_scope' ) as $key => $source ) { if ( array_key_exists( $source, $raw ) ) { $input[ $key ] = $raw[ $source ]; } }
		self::$page_state = self::search( $input );
		status_header( is_wp_error( self::$page_state ) ? (int) ( self::$page_state->get_error_data()['status'] ?? 503 ) : 200 );
	}
	public static function template( string $template ): string { return null !== self::$page_state ? PEPTIDE_CORE_DIR . '/templates/site-search-results.php' : $template; }
	public static function page_state() { return self::$page_state; }

	public static function assets(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-commerce-blocks', $url . 'commerce-blocks.css', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.css' ) );
		wp_enqueue_script( 'pc-commerce-blocks', $url . 'commerce-blocks.js', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.js' ), true );
	}
}
