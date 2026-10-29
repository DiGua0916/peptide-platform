<?php
/** Read-only, server-rendered public review directory; no Page or rewrite flush. */
namespace PeptideCore;

use PeptideCore\Modules\Review_Blocks;

defined( 'ABSPATH' ) || exit;

final class Review_Index {

	public const QUERY_VAR = 'pc_review_index';
	private const DEFAULTS = array( 'rating' => 0, 'media' => 'all', 'order' => 'newest', 'productId' => 0 );
	private const PARAMS = array( 'rating' => 'rating', 'media' => 'media', 'order' => 'review_order', 'productId' => 'review_product', 'cursor' => 'review_cursor' );
	private static bool $active = false;
	private static array $filters = self::DEFAULTS;
	private static ?string $cursor = null;
	private static bool $failed = false;

	public static function register_hooks(): void {
		add_filter( 'query_vars', static function ( array $vars ): array { $vars[] = self::QUERY_VAR; return $vars; } );
		add_action( 'parse_request', array( self::class, 'detect' ), 30 );
		add_action( 'pre_get_posts', array( self::class, 'main_query' ) );
		add_filter( 'posts_pre_query', array( self::class, 'skip_posts' ), 10, 2 );
		add_filter( 'pre_handle_404', static function ( $pre ) { return self::$active ? true : $pre; } );
		add_filter( 'redirect_canonical', static function ( $url ) { return self::$active ? false : $url; } );
		add_filter( 'pre_get_document_title', static function ( $title ) { return self::$active ? __( 'Customer Reviews', 'peptide-core' ) . ' | ' . get_bloginfo( 'name' ) : $title; }, 90 );
		add_filter( 'body_class', static function ( array $classes ): array { if ( self::$active ) { $classes[] = 'pc-review-index-page'; } return $classes; } );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 99 );
		add_action( 'wp_head', array( self::class, 'canonical' ), 5 );
		// Run after the store's force-login, domain and other storefront gates.
		add_action( 'template_redirect', array( self::class, 'serve' ), 99 );
	}

	/** A genuine existing comments slug wins; links then use the query fallback. */
	private static function occupied(): bool {
		$types = array_values( get_post_types( array( 'public' => true ) ) );
		return (bool) get_page_by_path( 'comments', OBJECT, $types ?: array( 'page', 'post', 'product' ) );
	}

	/** Public links accept typed local filters only, never an arbitrary target URL. */
	public static function url( array $filters = array() ): string {
		$base = '' === (string) get_option( 'permalink_structure', '' ) || self::occupied() ? add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) ) : home_url( '/comments/' );
		$raw = array();
		foreach ( self::PARAMS as $key => $param ) { if ( array_key_exists( $key, $filters ) ) { $raw[ $param ] = $filters[ $key ]; } }
		$parsed = self::parse_filters( $raw );
		if ( is_wp_error( $parsed ) ) { return $base; }
		$args = array();
		foreach ( self::DEFAULTS as $key => $default ) { if ( $parsed['filters'][ $key ] !== $default ) { $args[ self::PARAMS[ $key ] ] = $parsed['filters'][ $key ]; } }
		if ( $parsed['cursor'] ) { $args[ self::PARAMS['cursor'] ] = $parsed['cursor']; }
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** No database writes, rewrites, REST/admin/feed or existing-page takeover. */
	public static function detect( $wp ): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) { return; }
		$path = trim( (string) $wp->request, '/' );
		// Plain permalink installs leave WP::request empty even for /comments/.
		// Resolve only this exact local path, retaining subdirectory site support.
		if ( '' === $path ) {
			$uri_path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
			$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/';
			if ( is_string( $uri_path ) && str_starts_with( $uri_path, $home_path ) ) { $path = trim( substr( $uri_path, strlen( $home_path ) ), '/' ); }
		}
		$vars = (array) $wp->query_vars;
		foreach ( array( 'rest_route', 'feed', 'robots', 'sitemap', 'sitemap-subtype', 'preview', 'wc-api', 'category_name', 'tag', 'taxonomy', 'term', 'author_name' ) as $reserved ) { if ( ! empty( $vars[ $reserved ] ) || ! empty( $_GET[ $reserved ] ) ) { return; } }
		$pretty = 'comments' === $path && ! self::occupied();
		$fallback = '' === $path && '1' === ( $vars[ self::QUERY_VAR ] ?? null );
		if ( ! $pretty && ! $fallback ) { return; }
		// Do not replace a custom route or resolved post even if its name is comments.
		if ( $pretty && ( ! empty( $vars['p'] ) || ! empty( $vars['page_id'] ) || ! empty( $vars['post_type'] ) || ( ! empty( $wp->matched_rule ) && str_contains( (string) $wp->matched_rule, 'comments' ) ) ) ) { return; }
		self::$active = true;
		$wp->query_vars = array( self::QUERY_VAR => '1' );
	}

	public static function main_query( $query ): void {
		if ( ! self::$active || ! $query->is_main_query() ) { return; }
		$query->is_home = false; $query->is_page = false; $query->is_singular = false; $query->is_archive = false; $query->is_404 = false;
		$query->set( 'posts_per_page', 1 ); $query->set( 'no_found_rows', true ); $query->set( 'ignore_sticky_posts', true );
	}

	public static function skip_posts( $posts, $query ) { return self::$active && $query->is_main_query() ? array() : $posts; }

	/** URL inputs are stricter than absint coercion; arrays and foreign enum values fail. */
	public static function parse_filters( array $raw ) {
		$filters = self::DEFAULTS;
		foreach ( self::PARAMS as $key => $param ) {
			if ( ! array_key_exists( $param, $raw ) ) { continue; }
			$value = $raw[ $param ];
			if ( ! is_string( $value ) && ! is_int( $value ) ) { return new \WP_Error( 'invalid_filters' ); }
			$value = (string) $value;
			if ( 'rating' === $key ) {
				if ( ! in_array( $value, array( '0', '1', '2', '3', '4', '5' ), true ) ) { return new \WP_Error( 'invalid_filters' ); }
				$filters['rating'] = (int) $value;
			} elseif ( 'media' === $key ) {
				if ( ! in_array( $value, array( 'all', 'with_media', 'without_media' ), true ) ) { return new \WP_Error( 'invalid_filters' ); }
				$filters['media'] = $value;
			} elseif ( 'order' === $key ) {
				if ( ! in_array( $value, array( 'newest', 'highest', 'lowest', 'oldest' ), true ) ) { return new \WP_Error( 'invalid_filters' ); }
				$filters['order'] = $value;
			} elseif ( 'productId' === $key ) {
				if ( ! preg_match( '/^(0|[1-9][0-9]{0,17})$/D', $value ) ) { return new \WP_Error( 'invalid_filters' ); }
				$filters['productId'] = (int) $value;
			} elseif ( '' !== $value && ( strlen( $value ) > 2048 || ! preg_match( '/^[A-Za-z0-9_.=-]+$/D', $value ) ) ) { return new \WP_Error( 'invalid_cursor' ); }
		}
		$cursor = isset( $raw['review_cursor'] ) && '' !== (string) $raw['review_cursor'] ? (string) $raw['review_cursor'] : null;
		return array( 'filters' => $filters, 'cursor' => $cursor );
	}

	public static function robots( array $robots ): array {
		if ( self::$active && ( self::$failed || self::$cursor || self::$filters !== self::DEFAULTS ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
		return $robots;
	}

	public static function canonical(): void {
		if ( self::$active && ! self::$failed ) { echo '<link rel="canonical" href="' . esc_url( self::url( self::$filters ) ) . '">' . "\n"; }
	}

	public static function serve(): void {
		if ( ! self::$active ) { return; }
		$parsed = self::parse_filters( wp_unslash( $_GET ) );
		$error = is_wp_error( $parsed ) ? $parsed->get_error_code() : null;
		if ( ! $error ) { self::$filters = $parsed['filters']; self::$cursor = $parsed['cursor']; }
		$page = array( 'html' => '', 'count' => 0, 'nextCursor' => null, 'hasMore' => false, 'truncated' => false, 'stats' => null, 'error' => $error );
		if ( ! $error ) {
			try { $page = Review_Blocks::cursor_page( self::$cursor, self::$filters ); }
			catch ( \Throwable $exception ) { $page['error'] = 'unavailable'; }
		}
		if ( isset( $page['ratingsEnabled'] ) && ! $page['ratingsEnabled'] ) { self::$filters = self::without_ratings( self::$filters ); }
		self::$failed = ! empty( $page['error'] );
		$status = ! self::$failed ? 200 : ( 'invalid_product' === $page['error'] ? 404 : ( 'expired_cursor' === $page['error'] ? 410 : ( 'unavailable' === $page['error'] ? 503 : 400 ) ) );
		status_header( $status );
		if ( self::$failed || self::$cursor ) { nocache_headers(); }
		if ( 'unavailable' === ( $page['error'] ?? null ) ) { header( 'Retry-After: 60' ); }
		wp_enqueue_style( 'pc-review-index', plugins_url( 'assets/review-index.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/review-index.css' ) );
		get_header();
		echo self::content( self::$filters, $page, self::$cursor );
		get_footer();
		exit;
	}

	/** The page consumes the public renderer facade; no private SQL/metadata copies. */
	public static function content( array $filters, array $page, ?string $cursor = null ): string {
		$error = $page['error'] ?? null;
		$ratings_enabled = ! isset( $page['ratingsEnabled'] ) || (bool) $page['ratingsEnabled'];
		if ( ! $ratings_enabled ) { $filters = self::without_ratings( $filters ); }
		ob_start();
		echo '<main id="main-content" class="pc-review-index container"><header class="pc-ri-heading"><h1>' . esc_html__( 'Customer Reviews', 'peptide-core' ) . '</h1></header>';
		if ( $error ) {
			$expired = 'expired_cursor' === $error;
			echo '<section class="pc-ri-empty" role="status"><h2>' . esc_html__( 'Reviews are unavailable', 'peptide-core' ) . '</h2><p>' . esc_html( $expired ? __( 'This review link has expired. Open the latest reviews to continue.', 'peptide-core' ) : ( 'invalid_product' === $error ? __( 'This review page is unavailable.', 'peptide-core' ) : __( 'We could not open these reviews. Please try again.', 'peptide-core' ) ) ) . '</p><a class="pc-ri-button" href="' . esc_url( self::url( 'invalid_product' === $error ? array() : $filters ) ) . '">' . esc_html__( 'View latest reviews', 'peptide-core' ) . '</a></section></main>';
			return (string) ob_get_clean();
		}
		self::summary( $page['stats'] ?? null, $filters, $ratings_enabled );
		self::filter_form( $filters, $ratings_enabled );
		if ( $filters['productId'] ) { echo '<p class="pc-ri-scope"><a href="' . esc_url( self::url( array_replace( $filters, array( 'productId' => 0 ) ) ) ) . '">' . esc_html__( 'View reviews for all products', 'peptide-core' ) . '</a></p>'; }
		$count = max( 0, (int) ( $page['count'] ?? 0 ) );
		echo '<section aria-label="' . esc_attr__( 'Customer reviews', 'peptide-core' ) . '" id="pc-review-results">';
		if ( $count && ! empty( $page['html'] ) ) {
			echo '<p class="pc-ri-count">' . esc_html( sprintf( _n( 'Showing %d review', 'Showing %d reviews', $count, 'peptide-core' ), $count ) ) . '</p><div class="pc-rb pc-rb--featured-manual pc-ri-results" data-pc-review-block><div class="pc-rb__list">' . $page['html'] . '</div></div>';
		} else {
			$known_empty = ! $cursor && ! $filters['rating'] && 'all' === $filters['media'] && is_array( $page['stats'] ?? null ) && isset( $page['stats']['total'] ) && 0 === (int) $page['stats']['total'];
			$heading = ! empty( $page['truncated'] ) ? __( 'Continue browsing reviews', 'peptide-core' ) : ( $known_empty ? __( 'No reviews yet', 'peptide-core' ) : ( $cursor ? __( 'No reviews to show on this page', 'peptide-core' ) : __( 'No reviews match your filters', 'peptide-core' ) ) );
			$hint = ! empty( $page['truncated'] ) ? __( 'Keep browsing to find more reviews.', 'peptide-core' ) : ( $known_empty ? __( 'Customer reviews will appear here once published.', 'peptide-core' ) : __( 'Try another rating or media filter.', 'peptide-core' ) );
			echo '<div class="pc-ri-empty">' . self::empty_icon() . '<h2>' . esc_html( $heading ) . '</h2><p>' . esc_html( $hint ) . '</p><a href="' . esc_url( self::url( $filters['productId'] ? array( 'productId' => $filters['productId'] ) : array() ) ) . '">' . esc_html__( 'Reset filters', 'peptide-core' ) . '</a></div>';
		}
		echo '</section><nav class="pc-ri-pagination" aria-label="' . esc_attr__( 'Review pages', 'peptide-core' ) . '">';
		if ( $cursor ) { echo '<a href="' . esc_url( self::url( $filters ) ) . '#pc-review-results">' . esc_html__( 'Back to first page', 'peptide-core' ) . '</a>'; }
		if ( ! empty( $page['nextCursor'] ) ) { echo '<a class="pc-ri-button" rel="next" href="' . esc_url( self::url( array_merge( $filters, array( 'cursor' => $page['nextCursor'] ) ) ) ) . '#pc-review-results">' . esc_html( ! empty( $page['truncated'] ) ? __( 'Continue browsing', 'peptide-core' ) : __( 'Next reviews', 'peptide-core' ) ) . '</a>'; }
		elseif ( ! empty( $page['truncated'] ) ) { echo '<p role="status">' . esc_html__( 'More reviews may be available. Refine your filters to continue.', 'peptide-core' ) . '</p>'; }
		echo '</nav><a class="pc-ri-back" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to store', 'peptide-core' ) . '</a></main>';
		return (string) ob_get_clean();
	}

	private static function summary( $stats, array $filters, bool $ratings_enabled ): void {
		$known = is_array( $stats ) && isset( $stats['total'], $stats['rated'], $stats['average'], $stats['ratings'] );
		echo '<section class="pc-ri-summary' . ( ! $ratings_enabled || ! $known ? ' pc-ri-summary--simple' : '' ) . '" aria-label="' . esc_attr( $ratings_enabled ? __( 'Ratings summary', 'peptide-core' ) : __( 'Reviews summary', 'peptide-core' ) ) . '"><div><h2>' . esc_html( get_bloginfo( 'name' ) ) . '</h2>';
		if ( ! $known ) {
			echo '<p>' . esc_html( $ratings_enabled ? __( 'Ratings summary is currently unavailable.', 'peptide-core' ) : __( 'Review summary is currently unavailable.', 'peptide-core' ) ) . '</p></div></section>'; return;
		}
		$total = max( 0, (int) $stats['total'] ); $rated = max( 0, (int) $stats['rated'] ); $average = max( 0, min( 5, (float) $stats['average'] ) );
		if ( ! $ratings_enabled ) { echo '<p>' . esc_html( sprintf( _n( '%d review', '%d reviews', $total, 'peptide-core' ), $total ) ) . '</p></div></section>'; return; }
		echo '<div class="pc-ri-average">' . self::stars( $average ) . '<strong>' . ( $rated ? esc_html( number_format_i18n( $average, 1 ) ) : esc_html__( 'No ratings yet', 'peptide-core' ) ) . '</strong></div><p>' . esc_html( sprintf( _n( '%d review', '%d reviews', $total, 'peptide-core' ), $total ) ) . '</p></div><div class="pc-ri-distribution">';
		for ( $rating = 5; $rating >= 1; $rating-- ) {
			$count = max( 0, (int) ( $stats['ratings'][ $rating ] ?? 0 ) );
			echo '<a href="' . esc_url( self::url( array_replace( $filters, array( 'rating' => $rating ) ) ) ) . '"><span>' . esc_html( sprintf( _n( '%d star', '%d stars', $rating, 'peptide-core' ), $rating ) ) . '</span><meter min="0" max="' . max( 1, $rated ) . '" value="' . min( $rated, $count ) . '" aria-label="' . esc_attr( sprintf( __( '%1$d of %2$d rated reviews', 'peptide-core' ), $count, $rated ) ) . '"></meter><span>(' . $count . ')</span></a>';
		}
		echo '</div></section>';
	}

	private static function filter_form( array $filters, bool $ratings_enabled ): void {
		$target = self::url(); $fallback = false !== strpos( $target, self::QUERY_VAR . '=' );
		echo '<form class="pc-ri-filters' . ( $ratings_enabled ? '' : ' pc-ri-filters--no-ratings' ) . '" method="get" action="' . esc_url( $fallback ? home_url( '/' ) : $target ) . '">';
		if ( $fallback ) { echo '<input type="hidden" name="' . esc_attr( self::QUERY_VAR ) . '" value="1">'; }
		if ( $filters['productId'] ) { echo '<input type="hidden" name="review_product" value="' . (int) $filters['productId'] . '">'; }
		echo '<label for="pc-ri-media">' . esc_html__( 'Media', 'peptide-core' ) . '<select id="pc-ri-media" name="media">';
		foreach ( array( 'all' => __( 'All reviews', 'peptide-core' ), 'with_media' => __( 'Photos & videos', 'peptide-core' ), 'without_media' => __( 'Without media', 'peptide-core' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['media'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label>';
		if ( $ratings_enabled ) {
			echo '<label for="pc-ri-rating">' . esc_html__( 'Rating', 'peptide-core' ) . '<select id="pc-ri-rating" name="rating"><option value="0">' . esc_html__( 'All ratings', 'peptide-core' ) . '</option>';
			for ( $rating = 5; $rating >= 1; $rating-- ) { echo '<option value="' . $rating . '"' . selected( $filters['rating'], $rating, false ) . '>' . esc_html( sprintf( _n( '%d star', '%d stars', $rating, 'peptide-core' ), $rating ) ) . '</option>'; }
			echo '</select></label>';
		}
		echo '<label class="pc-ri-order" for="pc-ri-order">' . esc_html__( 'Sort by', 'peptide-core' ) . '<select id="pc-ri-order" name="review_order">';
		$orders = array( 'newest' => __( 'Date, new to old', 'peptide-core' ) );
		if ( $ratings_enabled ) { $orders += array( 'highest' => __( 'Highest ratings', 'peptide-core' ), 'lowest' => __( 'Lowest ratings', 'peptide-core' ) ); }
		if ( ! $ratings_enabled || 'oldest' === $filters['order'] ) { $orders['oldest'] = __( 'Date, old to new', 'peptide-core' ); }
		foreach ( $orders as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['order'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button class="pc-ri-button" type="submit">' . esc_html__( 'Apply filters', 'peptide-core' ) . '</button></form>';
	}

	private static function without_ratings( array $filters ): array {
		$filters['rating'] = 0;
		if ( in_array( $filters['order'], array( 'highest', 'lowest' ), true ) ) { $filters['order'] = 'newest'; }
		return $filters;
	}

	private static function stars( float $average ): string {
		$svg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/></svg>';
		$row = '<span class="pc-ri-stars__row">' . str_repeat( $svg, 5 ) . '</span>';
		return '<span class="pc-ri-stars" role="img" aria-label="' . esc_attr( sprintf( __( '%s out of 5', 'peptide-core' ), number_format_i18n( $average, 1 ) ) ) . '">' . $row . '<span class="pc-ri-stars__fill" style="width:' . (float) ( $average * 20 ) . '%">' . $row . '</span></span>';
	}

	private static function empty_icon(): string { return '<svg class="pc-ri-empty-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9 10h46v37H36l-8 8v-8H9Z"/><path d="M22 28h1m8 0h1m8 0h1" stroke-linecap="round" stroke-width="3"/></svg>'; }
}
