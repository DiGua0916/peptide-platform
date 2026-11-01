<?php
/** Product collections use native WooCommerce categories and real parent products. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Collections {

	public const META = '_pc_collection';
	public const MAX_PRODUCTS = 5000;
	private static bool $busy = false;
	private static array $queued = array();
	private static ?array $automatic = null;
	private static array $touched_terms = array();
	private static array $touched_products = array();
	private static ?bool $transactional = null;

	public static function register_hooks(): void {
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( self::class, 'queue_product' ), 100 );
		}
		add_action( 'woocommerce_product_set_stock', array( self::class, 'queue_product' ), 100 );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'queue_product' ), 100 );
		add_action( 'set_object_terms', array( self::class, 'terms_changed' ), 100, 6 );
		add_action( 'deleted_term_relationships', array( self::class, 'relationships_removed' ), 100, 3 );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 100, 3 );
		add_action( 'shutdown', array( self::class, 'flush_queue' ), 20 );
		add_action( 'pc_collection_sync_product', array( self::class, 'queue_product' ) );
		add_filter( 'woocommerce_default_catalog_orderby', array( self::class, 'default_orderby' ), 100 );
		add_filter( 'woocommerce_catalog_orderby', array( self::class, 'catalog_orderby' ), 100 );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( self::class, 'ordering_args' ), 100, 3 );
		add_filter( 'posts_orderby', array( self::class, 'manual_orderby' ), 100, 2 );
	}

	public static function defaults(): array {
		return array( 'version' => 1, 'revision' => 0, 'type' => 'manual', 'favorite' => false, 'sort' => 'default', 'footer_description' => '', 'rules' => array( 'match' => 'all', 'conditions' => array() ), 'manual_order' => array(), 'updated_at' => '' );
	}

	/** Public category content for storefront templates; no management data. */
	public static function public_data( int $id ): ?array {
		$term = self::term( $id );
		if ( ! $term ) { return null; }
		$state = self::state( $id );
		$url = get_term_link( $term );
		$cover = (int) get_term_meta( $id, 'thumbnail_id', true );
		$image = null;
		if ( $cover && wp_attachment_is_image( $cover ) && 'publish' === get_post_status( $cover ) ) {
			$src = wp_get_attachment_image_url( $cover, 'large' );
			if ( $src ) { $image = array( 'id' => $cover, 'src' => $src, 'alt' => sanitize_text_field( get_post_meta( $cover, '_wp_attachment_image_alt', true ) ) ); }
		}
		return array( 'id' => $id, 'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ), 'slug' => $term->slug, 'url' => is_wp_error( $url ) ? '' : $url, 'description' => wp_kses_post( $term->description ), 'footer_description' => wp_kses_post( $state['footer_description'] ), 'image' => $image, 'sort' => $state['sort'] );
	}

	public static function can_manage(): bool {
		$tax = get_taxonomy( 'product_cat' );
		return $tax && current_user_can( $tax->cap->edit_terms ) && current_user_can( 'edit_products' );
	}

	private static function can_assign(): bool {
		$tax = get_taxonomy( 'product_cat' );
		return self::can_manage() && current_user_can( $tax->cap->assign_terms ) && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_others_products' );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'pc_collection_' . $code, $message );
	}

	private static function term( int $id ): ?\WP_Term {
		$t = $id > 0 ? get_term( $id, 'product_cat' ) : null;
		return $t instanceof \WP_Term && 'product_cat' === $t->taxonomy ? $t : null;
	}

	private static function state( int $id ): array {
		$raw = get_term_meta( $id, self::META, true );
		$state = array_intersect_key( is_array( $raw ) ? $raw : array(), self::defaults() ) + self::defaults();
		$state['type'] = 'automatic' === $state['type'] ? 'automatic' : 'manual';
		$state['sort'] = in_array( $state['sort'], array( 'default', 'manual', 'created_desc', 'sales_desc', 'price_asc', 'price_desc', 'title_asc' ), true ) ? $state['sort'] : 'default';
		$state['manual_order'] = self::ids( $state['manual_order'] );
		$state['favorite'] = (bool) $state['favorite'];
		return $state;
	}

	private static function ids( $raw ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : array() ) ) ) );
	}

	private static function member_ids( int $id, bool $readable = false, bool $children = false ): array {
		$terms = array( $id );
		if ( $children ) {
			$child = get_term_children( $id, 'product_cat' );
			if ( ! is_wp_error( $child ) ) { $terms = array_merge( $terms, array_map( 'intval', $child ) ); }
		}
		$ids = get_objects_in_term( $terms, 'product_cat' );
		if ( is_wp_error( $ids ) ) { throw new \RuntimeException( 'Collection membership could not be read.' ); }
		$out = array();
		foreach ( self::ids( $ids ) as $pid ) {
			if ( 'product' !== get_post_type( $pid ) || in_array( get_post_status( $pid ), array( 'trash', 'auto-draft', false ), true ) || ( $readable && ! current_user_can( 'read_post', $pid ) ) ) { continue; }
			$out[] = $pid;
		}
		return $out;
	}

	private static function ordered_ids( array $ids, array $order ): array {
		$ids = self::ids( $ids );
		$first = array_values( array_intersect( self::ids( $order ), $ids ) );
		$rest = array_values( array_diff( $ids, $first ) );
		sort( $rest, SORT_NUMERIC );
		return array_merge( $first, $rest );
	}

	private static function sorted_ids( array $ids, array $settings ): array {
		if ( in_array( $settings['sort'], array( 'default', 'manual' ), true ) ) { return self::ordered_ids( $ids, $settings['manual_order'] ); }
		$values = array(); $sort = $settings['sort'];
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid ); if ( ! $p ) { continue; }
			$values[ $pid ] = 'title_asc' === $sort ? $p->get_name() : ( 'sales_desc' === $sort ? $p->get_total_sales() : ( 'created_desc' === $sort ? ( $p->get_date_created() ? $p->get_date_created()->getTimestamp() : 0 ) : (float) $p->get_price() ) );
		}
		$descending = in_array( $sort, array( 'created_desc', 'sales_desc', 'price_desc' ), true );
		usort( $ids, static function( $a, $b ) use ( $values, $descending, $sort ) {
			$comparison = 'title_asc' === $sort ? strnatcasecmp( (string) ( $values[ $a ] ?? '' ), (string) ( $values[ $b ] ?? '' ) ) : ( $values[ $a ] ?? 0 ) <=> ( $values[ $b ] ?? 0 );
			return ( $descending ? -$comparison : $comparison ) ?: $a <=> $b;
		} );
		return $ids;
	}

	/** Numeric optimistic token includes native-editor and relationship changes too. */
	private static function revision( \WP_Term $term ): int {
		$parts = array( $term->name, $term->slug, $term->description, (int) $term->parent, get_term_meta( $term->term_id, self::META, true ), get_term_meta( $term->term_id, 'thumbnail_id', true ), get_term_meta( $term->term_id, '_pc_seo', true ), self::member_ids( $term->term_id ) );
		return (int) hexdec( substr( hash( 'sha256', wp_json_encode( $parts ) ), 0, 12 ) );
	}

	public static function get( int $id ): ?array {
		if ( ! self::can_manage() || ! ( $term = self::term( $id ) ) ) { return null; }
		$s = self::state( $id );
		$members = self::member_ids( $id, true );
		$published = array_filter( $members, static fn( $pid ) => 'publish' === get_post_status( $pid ) );
		$seo = get_term_meta( $id, '_pc_seo', true ); $seo = is_array( $seo ) ? $seo : array();
		$cover = (int) get_term_meta( $id, 'thumbnail_id', true );
		$url = get_term_link( $term );
		return array_merge( $s, array(
			'id' => $id, 'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ), 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent,
			'thumbnail_id' => $cover, 'thumbnail_url' => $cover ? ( wp_get_attachment_image_url( $cover, 'thumbnail' ) ?: '' ) : '',
			'product_count' => count( $members ), 'published_count' => count( $published ), 'views' => null,
			'public_url' => is_wp_error( $url ) ? '' : $url, 'revision' => self::revision( $term ),
			'seo_title' => (string) ( $seo['title'] ?? '' ), 'seo_description' => (string) ( $seo['description'] ?? '' ),
			'manual_order' => array_slice( self::ordered_ids( $members, $s['manual_order'] ), 0, self::MAX_PRODUCTS ), 'settings' => $s,
		) );
	}

	public static function all( array $args = array() ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) ); $per = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		if ( ! self::can_manage() ) { return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per ); }
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 0 ) );
		$rows = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$row = self::get( $term->term_id );
			if ( ! $row || ( ! empty( $args['type'] ) && 'all' !== $args['type'] && $args['type'] !== $row['type'] ) ) { continue; }
			$favorite = $args['favorite'] ?? $args['common'] ?? '';
			if ( '' !== $favorite && null !== $favorite && ! in_array( $favorite, array( 'all', 'any' ), true ) && filter_var( $favorite, FILTER_VALIDATE_BOOLEAN ) !== $row['favorite'] ) { continue; }
			$search = mb_strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
			if ( '' !== $search && ! str_contains( mb_strtolower( $row['name'] ), $search ) ) { continue; }
			$rows[] = $row;
		}
		$order = strtolower( (string) ( $args['order'] ?? 'asc' ) ) === 'desc' ? -1 : 1;
		$by = in_array( $args['orderby'] ?? '', array( 'name', 'updated_at', 'product_count' ), true ) ? $args['orderby'] : 'name';
		usort( $rows, static function( $a, $b ) use ( $order, $by ) { $v = 'product_count' === $by ? $a[ $by ] <=> $b[ $by ] : strnatcasecmp( $a[ $by ], $b[ $by ] ); return $order * ( $v ?: $a['id'] <=> $b['id'] ); } );
		return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'page' => $page, 'per_page' => $per );
	}

	/** Validate rules before any relationship changes; an empty rule never means all. */
	public static function clean_rules( $raw ) {
		if ( ! is_array( $raw ) || ! in_array( $raw['match'] ?? 'all', array( 'all', 'any' ), true ) || ! is_array( $raw['conditions'] ?? null ) || count( $raw['conditions'] ) > 20 ) { return self::error( 'rules', __( 'Choose valid automatic collection conditions.', 'peptide-core' ) ); }
		$rules = array( 'match' => $raw['match'] ?? 'all', 'conditions' => array() );
		foreach ( $raw['conditions'] as $condition ) {
			if ( ! is_array( $condition ) ) { return self::error( 'rules', __( 'A collection condition is incomplete.', 'peptide-core' ) ); }
			$field = $condition['field'] ?? ''; $op = $condition['operator'] ?? ''; $value = $condition['value'] ?? '';
			$ops = array( 'title' => array( 'contains', 'equals' ), 'sku' => array( 'contains', 'equals' ), 'tag' => array( 'contains', 'equals' ), 'price' => array( 'equals', 'greater_than', 'less_than' ), 'stock_status' => array( 'equals' ) );
			if ( ! isset( $ops[ $field ] ) || ! in_array( $op, $ops[ $field ], true ) || ! is_scalar( $value ) ) { return self::error( 'rules', __( 'A collection condition is not supported.', 'peptide-core' ) ); }
			$value = mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, 200 );
			if ( '' === $value || ( 'price' === $field && ( ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $value ) || (float) $value > 1000000000 ) ) || ( 'stock_status' === $field && ! in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) ) { return self::error( 'rules', __( 'Enter a valid value for every collection condition.', 'peptide-core' ) ); }
			$rules['conditions'][] = array( 'field' => $field, 'operator' => $op, 'value' => $value );
		}
		return $rules;
	}

	public static function matches( \WC_Product $product, array $rules ): bool {
		if ( ! $rules['conditions'] || $product->get_parent_id() || 'product' !== get_post_type( $product->get_id() ) || in_array( $product->get_status(), array( 'trash', 'auto-draft' ), true ) ) { return false; }
		$results = array();
		foreach ( $rules['conditions'] as $condition ) {
			$value = mb_strtolower( $condition['value'] ); $op = $condition['operator']; $field = $condition['field']; $values = array();
			if ( 'title' === $field ) { $values[] = $product->get_name(); }
			elseif ( 'sku' === $field ) {
				$values[] = $product->get_sku();
				if ( $product->is_type( 'variable' ) ) { foreach ( $product->get_children() as $child ) { $variation = wc_get_product( $child ); if ( $variation ) { $values[] = $variation->get_sku(); } } }
			} elseif ( 'tag' === $field ) {
				$tags = get_the_terms( $product->get_id(), 'product_tag' );
				foreach ( is_array( $tags ) ? $tags : array() as $tag ) { $values[] = $tag->name; $values[] = $tag->slug; $values[] = (string) $tag->term_id; }
			} elseif ( 'stock_status' === $field ) { $values[] = $product->get_stock_status(); }
			elseif ( 'price' === $field ) {
				$price = $product->get_price(); $number = (float) $value;
				$results[] = '' !== $price && ( 'greater_than' === $op ? (float) $price > $number : ( 'less_than' === $op ? (float) $price < $number : abs( (float) $price - $number ) < 0.0000001 ) );
				continue;
			}
			$match = false;
			foreach ( $values as $item ) { $item = mb_strtolower( (string) $item ); if ( 'contains' === $op ? str_contains( $item, $value ) : $item === $value ) { $match = true; break; } }
			$results[] = $match;
		}
		return 'any' === $rules['match'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	private static function catalog_ids( bool $readable = false ) {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => self::MAX_PRODUCTS + 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ) );
		if ( count( $ids ) > self::MAX_PRODUCTS ) { return self::error( 'limit', __( 'This operation supports up to 5,000 products. Narrow the collection before continuing.', 'peptide-core' ) ); }
		return $readable ? array_values( array_filter( $ids, static fn( $pid ) => current_user_can( 'read_post', $pid ) ) ) : $ids;
	}

	private static function product_row( \WC_Product $p ): array {
		return array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'status' => $p->get_status(), 'image_url' => $p->get_image_id() ? ( wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) ?: '' ) : '', 'price' => $p->get_price(), 'price_html' => $p->get_price_html(), 'stock_status' => $p->get_stock_status(), 'stock_quantity' => $p->get_stock_quantity(), 'variation_count' => $p->is_type( 'variable' ) ? count( $p->get_children() ) : 0, 'permalink' => $p->get_permalink(), 'edit_url' => get_edit_post_link( $p->get_id(), 'raw' ) ?: '' );
	}

	private static function paged_products( array $ids, array $args = array() ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) ); $per = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$search = mb_strtolower( trim( (string) ( $args['search'] ?? '' ) ) ); $filtered = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) { continue; }
			$p = wc_get_product( $id ); if ( ! $p || $p->get_parent_id() ) { continue; }
			$hay = mb_strtolower( $p->get_name() . ' ' . $p->get_sku() );
			if ( $p->is_type( 'variable' ) && '' !== $search && ! str_contains( $hay, $search ) ) { foreach ( $p->get_children() as $child ) { $variation = wc_get_product( $child ); if ( $variation ) { $hay .= ' ' . mb_strtolower( $variation->get_sku() ); } } }
			if ( '' !== $search && ! str_contains( $hay, $search ) ) { continue; }
			$filtered[] = $id;
		}
		$items = array(); foreach ( array_slice( $filtered, ( $page - 1 ) * $per, $per ) as $id ) { $items[] = self::product_row( wc_get_product( $id ) ); }
		return array( 'items' => $items, 'total' => count( $filtered ), 'page' => $page, 'per_page' => $per );
	}

	public static function products( int $id, array $args = array() ) {
		if ( ! self::can_manage() || ( $id && ! self::term( $id ) ) ) { return self::error( 'forbidden', __( 'You cannot manage this collection.', 'peptide-core' ) ); }
		$ids = $id && empty( $args['available'] ) ? self::member_ids( $id, true ) : self::catalog_ids( true );
		if ( is_wp_error( $ids ) ) { return $ids; }
		if ( $id && ! empty( $args['available'] ) ) { $ids = array_values( array_diff( $ids, self::member_ids( $id, true ) ) ); }
		elseif ( $id ) { $ids = self::sorted_ids( $ids, self::state( $id ) ); }
		if ( ! empty( $args['exclude_collection'] ) ) { $ids = array_values( array_diff( $ids, self::member_ids( (int) $args['exclude_collection'], true ) ) ); }
		return self::paged_products( $ids, $args );
	}

	public static function preview_rules( array $raw, array $args = array() ) {
		if ( ! self::can_assign() ) { return self::error( 'forbidden', __( 'You cannot change collection membership.', 'peptide-core' ) ); }
		$rules = self::clean_rules( $raw ); if ( is_wp_error( $rules ) ) { return $rules; }
		if ( ! $rules['conditions'] ) { return self::error( 'rules', __( 'Add at least one condition before using automatic selection.', 'peptide-core' ) ); }
		$ids = self::catalog_ids( true ); if ( is_wp_error( $ids ) ) { return $ids; }
		$matches = array(); foreach ( $ids as $pid ) { $p = wc_get_product( $pid ); if ( $p && self::matches( $p, $rules ) ) { $matches[] = $pid; } }
		return array_merge( self::paged_products( $matches, $args ), array( 'ids' => $matches, 'rules' => $rules ) );
	}

	/** Advisory lock + DB transaction serialize our writers and make failure recoverable. */
	private static function locked( int $id, callable $callback ) {
		global $wpdb;
		if ( null === self::$transactional ) {
			$tables = array( $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta );
			$engines = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)', ...$tables ), ARRAY_A );
			self::$transactional = count( $engines ) === count( $tables ) && ! array_filter( $engines, static fn( $row ) => 'innodb' !== strtolower( (string) $row['ENGINE'] ) );
		}
		if ( ! self::$transactional ) { return self::error( 'database', __( 'Collection updates require transactional database tables.', 'peptide-core' ) ); }
		$key = 'pc_collection_' . substr( hash( 'sha256', $wpdb->prefix . '|' . $id ), 0, 46 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $key ) ) ) { return self::error( 'busy', __( 'This collection is being updated. Reload and try again.', 'peptide-core' ) ); }
		$previous = self::$busy; self::$busy = true;
		$members = $id ? self::member_ids( $id ) : array();
		self::$touched_terms = $id ? array( $id => true ) : array(); self::$touched_products = array_fill_keys( $members, true );
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( 'database', __( 'Collection changes could not be started.', 'peptide-core' ) ); }
			if ( $id ) {
				$wpdb->get_row( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id=%d FOR UPDATE", $id ) );
				$taxonomy_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_cat' FOR UPDATE", $id ) );
				if ( $taxonomy_id ) { $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d FOR UPDATE", $taxonomy_id ) ); }
				clean_term_cache( $id, 'product_cat' ); wp_cache_delete( $id, 'term_meta' );
			}
			$result = $callback();
			if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); }
			elseif ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); $result = self::error( 'database', __( 'Collection changes could not be saved.', 'peptide-core' ) ); }
			if ( $id ) { clean_term_cache( $id, 'product_cat' ); clean_object_term_cache( $members, 'product' ); }
			self::$automatic = null;
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $id ) { clean_term_cache( $id, 'product_cat' ); clean_object_term_cache( $members, 'product' ); }
			return self::error( 'database', __( 'Collection changes could not be saved. Reload before trying again.', 'peptide-core' ) );
		} finally {
			foreach ( array_keys( self::$touched_terms ) as $term_id ) { clean_term_cache( $term_id, 'product_cat' ); wp_cache_delete( $term_id, 'term_meta' ); }
			if ( self::$touched_products ) { clean_object_term_cache( array_keys( self::$touched_products ), 'product' ); }
			self::$busy = $previous; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	private static function check_revision( int $id, int $expected ) {
		$t = self::term( $id );
		if ( ! $t ) { return self::error( 'missing', __( 'Collection not found.', 'peptide-core' ) ); }
		$raw = get_term_meta( $id, self::META, true );
		if ( is_array( $raw ) && isset( $raw['version'] ) && 1 !== (int) $raw['version'] ) { return self::error( 'version', __( 'This collection needs a compatible plugin version before it can be changed.', 'peptide-core' ) ); }
		return self::revision( $t ) === $expected ? true : self::error( 'conflict', __( 'This collection changed in another window. Reload it before saving.', 'peptide-core' ) );
	}

	private static function set_members( int $term_id, array $next, array $previous ) {
		self::$touched_terms[ $term_id ] = true; foreach ( array_merge( $next, $previous ) as $pid ) { self::$touched_products[ $pid ] = true; }
		foreach ( array_diff( $previous, $next ) as $pid ) { $result = wp_remove_object_terms( $pid, $term_id, 'product_cat' ); if ( is_wp_error( $result ) || false === $result ) { return self::error( 'membership', __( 'A product could not be removed from this collection.', 'peptide-core' ) ); } }
		foreach ( array_diff( $next, $previous ) as $pid ) { $result = wp_add_object_terms( $pid, $term_id, 'product_cat' ); if ( is_wp_error( $result ) ) { return $result; } }
		return true;
	}

	private static function write_meta( int $id, string $key, $value ): void {
		update_term_meta( $id, $key, wp_slash( $value ) );
		$actual = get_term_meta( $id, $key, true );
		if ( is_array( $value ) ? $actual !== $value : (string) $actual !== (string) $value ) { throw new \RuntimeException( 'Collection metadata could not be saved.' ); }
	}

	public static function save( int $id, array $raw, int $expected_revision = 0 ) {
		if ( ! self::can_manage() ) { return self::error( 'forbidden', __( 'You cannot manage collections.', 'peptide-core' ) ); }
		return self::locked( $id, static function() use ( $id, $raw, $expected_revision ) {
			if ( $id ) { $checked = self::check_revision( $id, $expected_revision ); if ( is_wp_error( $checked ) ) { return $checked; } }
			elseif ( 0 !== $expected_revision ) { return self::error( 'conflict', __( 'Reload the new collection form.', 'peptide-core' ) ); }
			$term = $id ? self::term( $id ) : null; $previous = self::state( $id );
			$input = array_merge( is_array( $raw['settings'] ?? null ) ? $raw['settings'] : array(), $raw );
			$name = trim( sanitize_text_field( (string) ( $raw['name'] ?? ( $term ? wp_specialchars_decode( $term->name, ENT_QUOTES ) : '' ) ) ) );
			if ( '' === $name || mb_strlen( $name ) > 250 ) { return self::error( 'name', __( 'Enter a collection name with up to 250 characters.', 'peptide-core' ) ); }
			$slug = isset( $raw['slug'] ) ? sanitize_title( (string) $raw['slug'] ) : ( $term ? $term->slug : sanitize_title( $name ) );
			if ( '' === $slug ) { return self::error( 'slug', __( 'Enter a valid collection URL name.', 'peptide-core' ) ); }
			$existing = get_term_by( 'slug', $slug, 'product_cat' ); if ( $existing && (int) $existing->term_id !== $id ) { return self::error( 'slug', __( 'This collection URL name is already used.', 'peptide-core' ) ); }
			$s = $previous;
			foreach ( array( 'type', 'sort' ) as $key ) { if ( isset( $input[ $key ] ) ) { $s[ $key ] = (string) $input[ $key ]; } }
			if ( ! in_array( $s['type'], array( 'manual', 'automatic' ), true ) || ! in_array( $s['sort'], array( 'default', 'manual', 'created_desc', 'sales_desc', 'price_asc', 'price_desc', 'title_asc' ), true ) ) { return self::error( 'settings', __( 'Choose a valid collection type and sort order.', 'peptide-core' ) ); }
			if ( isset( $input['favorite'] ) ) { $s['favorite'] = filter_var( $input['favorite'], FILTER_VALIDATE_BOOLEAN ); }
			if ( array_key_exists( 'footer_description', $input ) ) { $s['footer_description'] = wp_kses_post( (string) $input['footer_description'] ); }
			if ( isset( $input['rules'] ) ) { $rules = self::clean_rules( $input['rules'] ); if ( is_wp_error( $rules ) ) { return $rules; } $s['rules'] = $rules; }
			if ( ( $s['type'] !== $previous['type'] || $s['rules'] !== $previous['rules'] ) && ( 'automatic' === $s['type'] || 'automatic' === $previous['type'] ) && ! self::can_assign() ) { return self::error( 'forbidden', __( 'You cannot change automatic collection conditions.', 'peptide-core' ) ); }
			$next_members = null;
			if ( array_key_exists( 'product_ids', $raw ) ) {
				if ( 'manual' !== $s['type'] || ! self::can_assign() || ! is_array( $raw['product_ids'] ) || count( $raw['product_ids'] ) > self::MAX_PRODUCTS ) { return self::error( 'products', __( 'Choose a valid manual collection product list.', 'peptide-core' ) ); }
				$next_members = self::ids( $raw['product_ids'] );
				foreach ( array_merge( $next_members, $id ? self::member_ids( $id ) : array() ) as $pid ) { if ( 'product' !== get_post_type( $pid ) || in_array( get_post_status( $pid ), array( 'trash', 'auto-draft', false ), true ) || ! current_user_can( 'edit_post', $pid ) ) { return self::error( 'products', __( 'Choose only parent products you can edit.', 'peptide-core' ) ); } }
			}
			if ( array_key_exists( 'manual_order', $input ) ) { $s['manual_order'] = array_slice( self::ids( $input['manual_order'] ), 0, self::MAX_PRODUCTS ); }
			if ( 'automatic' === $s['type'] && ( ! $id || 'automatic' !== $previous['type'] || $s['rules'] !== $previous['rules'] ) ) {
				if ( empty( $raw['confirm_rules'] ) ) { return self::error( 'confirmation', __( 'Preview the automatic conditions and confirm the matching products before saving.', 'peptide-core' ) ); }
				$preview = self::preview_rules( $s['rules'] ); if ( is_wp_error( $preview ) ) { return $preview; } $next_members = $preview['ids'];
				foreach ( array_merge( $next_members, $id ? self::member_ids( $id ) : array() ) as $pid ) { if ( ! current_user_can( 'edit_post', $pid ) ) { return self::error( 'forbidden', __( 'You cannot change every product in this collection.', 'peptide-core' ) ); } }
			}
			$cover = isset( $raw['thumbnail_id'] ) ? absint( $raw['thumbnail_id'] ) : (int) get_term_meta( $id, 'thumbnail_id', true );
			if ( $cover && ( 'attachment' !== get_post_type( $cover ) || ! wp_attachment_is_image( $cover ) || ! current_user_can( 'edit_post', $cover ) ) ) { return self::error( 'cover', __( 'Choose an image from the media library.', 'peptide-core' ) ); }
			$fields = array( 'name' => $name, 'slug' => $slug );
			if ( isset( $raw['description'] ) ) { $fields['description'] = wp_kses_post( (string) $raw['description'] ); }
			$saved = $id ? wp_update_term( $id, 'product_cat', $fields ) : wp_insert_term( $name, 'product_cat', $fields );
			if ( is_wp_error( $saved ) ) { return $saved; } $saved_id = (int) $saved['term_id'];
			self::$touched_terms[ $saved_id ] = true;
			$s['version'] = 1; $s['revision'] = (int) $previous['revision'] + 1; $s['updated_at'] = current_time( 'mysql', true );
			$old_members = self::member_ids( $saved_id );
			if ( null !== $next_members ) { $changed = self::set_members( $saved_id, $next_members, $old_members ); if ( is_wp_error( $changed ) ) { return $changed; } $s['manual_order'] = self::ordered_ids( $next_members, $s['manual_order'] ); }
			if ( 'manual' === $s['sort'] && ! array_key_exists( 'manual_order', $input ) && ( 'manual' !== $previous['sort'] || ! $s['manual_order'] ) ) {
				$s['manual_order'] = self::sorted_ids( null === $next_members ? $old_members : $next_members, $previous );
			}
			self::write_meta( $saved_id, 'thumbnail_id', $cover );
			if ( array_key_exists( 'seo_title', $raw ) || array_key_exists( 'seo_description', $raw ) || array_key_exists( 'seo_keywords', $raw ) ) {
				$seo = get_term_meta( $saved_id, '_pc_seo', true ); $seo = is_array( $seo ) ? $seo : array();
				if ( array_key_exists( 'seo_title', $raw ) ) { $seo['title'] = $raw['seo_title']; }
				if ( array_key_exists( 'seo_description', $raw ) ) { $seo['description'] = $raw['seo_description']; }
				if ( array_key_exists( 'seo_keywords', $raw ) ) { $seo['keywords'] = $raw['seo_keywords']; }
				self::write_meta( $saved_id, '_pc_seo', Seo::clean_set( $seo ) );
			}
			self::write_meta( $saved_id, self::META, $s );
			clean_term_cache( $saved_id, 'product_cat' );
			return self::get( $saved_id );
		} );
	}

	public static function mutate_membership( int $id, string $operation, array $ids, int $expected_revision ) {
		if ( ! self::can_assign() ) { return self::error( 'forbidden', __( 'You cannot change collection products.', 'peptide-core' ) ); }
		$ids = self::ids( $ids );
		if ( ! in_array( $operation, array( 'add', 'remove', 'reorder' ), true ) || count( $ids ) > self::MAX_PRODUCTS ) { return self::error( 'products', __( 'Choose a valid collection product action.', 'peptide-core' ) ); }
		return self::locked( $id, static function() use ( $id, $operation, $ids, $expected_revision ) {
			$checked = self::check_revision( $id, $expected_revision ); if ( is_wp_error( $checked ) ) { return $checked; }
			$s = self::state( $id );
			if ( 'automatic' === $s['type'] && 'reorder' !== $operation ) { return self::error( 'automatic', __( 'Automatic collections are controlled by their conditions. Edit the conditions instead.', 'peptide-core' ) ); }
			$members = self::member_ids( $id );
			foreach ( $ids as $pid ) { if ( 'product' !== get_post_type( $pid ) || in_array( get_post_status( $pid ), array( 'trash', 'auto-draft', false ), true ) || ! current_user_can( 'edit_post', $pid ) ) { return self::error( 'products', __( 'Choose only parent products you can edit.', 'peptide-core' ) ); } }
			if ( 'reorder' === $operation ) {
				if ( array_diff( $ids, $members ) ) { return self::error( 'products', __( 'Only products already in this collection can be sorted.', 'peptide-core' ) ); }
				$s['manual_order'] = self::ordered_ids( $members, array_merge( $ids, self::ordered_ids( $members, $s['manual_order'] ) ) ); $s['sort'] = 'manual';
			} else {
				$next = 'add' === $operation ? self::ids( array_merge( $members, $ids ) ) : array_values( array_diff( $members, $ids ) );
				$result = self::set_members( $id, $next, $members ); if ( is_wp_error( $result ) ) { return $result; }
				$s['manual_order'] = self::ordered_ids( $next, $s['manual_order'] );
			}
			$s['revision'] = (int) $s['revision'] + 1; $s['updated_at'] = current_time( 'mysql', true ); self::write_meta( $id, self::META, $s );
			return self::get( $id );
		} );
	}

	public static function duplicate( int $id ) {
		$row = self::get( $id ); if ( ! $row || ! self::can_assign() ) { return self::error( 'forbidden', __( 'You cannot duplicate this collection.', 'peptide-core' ) ); }
		$name = mb_substr( $row['name'], 0, 230 ) . ' ' . __( '(copy)', 'peptide-core' );
		$row['name'] = $name; $row['slug'] = sanitize_title( $name ) . '-' . strtolower( wp_generate_password( 6, false, false ) ); $row['favorite'] = false;
		$row['confirm_rules'] = true; $row['settings'] = array();
		$seo = get_term_meta( $id, '_pc_seo', true ); $row['seo_keywords'] = is_array( $seo ) ? ( $seo['keywords'] ?? array() ) : array();
		if ( 'manual' === $row['type'] ) { $row['product_ids'] = self::member_ids( $id, true ); }
		return self::save( 0, $row );
	}

	public static function delete( int $id, int $expected_revision ) {
		$tax = get_taxonomy( 'product_cat' );
		if ( ! self::can_assign() || ! $tax || ! current_user_can( $tax->cap->delete_terms ) || $id === (int) get_option( 'default_product_cat' ) ) { return self::error( 'forbidden', __( 'You cannot delete this collection.', 'peptide-core' ) ); }
		return self::locked( $id, static function() use ( $id, $expected_revision ) {
			$check = self::check_revision( $id, $expected_revision ); if ( is_wp_error( $check ) ) { return $check; }
			foreach ( self::member_ids( $id ) as $pid ) { if ( ! current_user_can( 'edit_post', $pid ) ) { return self::error( 'forbidden', __( 'You cannot change every product in this collection.', 'peptide-core' ) ); } }
			$result = wp_delete_term( $id, 'product_cat' ); return is_wp_error( $result ) ? $result : ( $result ? true : self::error( 'delete', __( 'The collection could not be deleted.', 'peptide-core' ) ) );
		} );
	}

	public static function queue_product( $value ): void {
		if ( self::$busy ) { return; }
		$id = $value instanceof \WC_Product ? $value->get_id() : absint( $value );
		if ( 'product_variation' === get_post_type( $id ) ) { $id = (int) wp_get_post_parent_id( $id ); }
		if ( $id && 'product' === get_post_type( $id ) ) { self::$queued[ $id ] = true; }
	}

	public static function terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( 'product_tag' === $taxonomy ) { self::queue_product( $object_id ); }
	}

	public static function relationships_removed( $object_id, $tt_ids, $taxonomy ): void {
		if ( 'product_tag' === $taxonomy ) { self::queue_product( $object_id ); }
	}

	public static function status_changed( $new, $old, $post ): void {
		if ( $new !== $old && $post instanceof \WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) { self::queue_product( $post->ID ); }
	}

	public static function flush_queue(): void {
		if ( self::$busy || ! self::$queued ) { return; }
		$queue = array_keys( self::$queued ); self::$queued = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => self::META ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( 'automatic' !== self::state( $term->term_id )['type'] ) { continue; }
			$result = self::locked( $term->term_id, static function() use ( $term, $queue ) {
				$s = self::state( $term->term_id ); $rules = self::clean_rules( $s['rules'] ); if ( 1 !== (int) $s['version'] || is_wp_error( $rules ) || ! $rules['conditions'] || 'automatic' !== $s['type'] ) { return true; }
				$members = self::member_ids( $term->term_id ); $next = $members;
				foreach ( $queue as $pid ) { $product = wc_get_product( $pid ); $match = $product && self::matches( $product, $rules ); $next = array_values( array_diff( $next, array( $pid ) ) ); if ( $match ) { $next[] = $pid; } }
				if ( ! array_diff( $members, $next ) && ! array_diff( $next, $members ) ) { return true; }
				$result = self::set_members( $term->term_id, $next, $members ); if ( is_wp_error( $result ) ) { return $result; }
				$s['manual_order'] = self::ordered_ids( $next, $s['manual_order'] ); $s['revision'] = (int) $s['revision'] + 1; $s['updated_at'] = current_time( 'mysql', true ); self::write_meta( $term->term_id, self::META, $s ); return true;
			} );
			if ( is_wp_error( $result ) ) { foreach ( $queue as $pid ) { if ( ! wp_next_scheduled( 'pc_collection_sync_product', array( $pid ) ) ) { wp_schedule_single_event( time() + 30, 'pc_collection_sync_product', array( $pid ) ); } } }
		}
	}

	private static function category_id(): int {
		if ( is_admin() || ! function_exists( 'is_product_category' ) || ! is_product_category() ) { return 0; }
		$term = get_queried_object(); return $term instanceof \WP_Term && 'product_cat' === $term->taxonomy ? $term->term_id : 0;
	}

	public static function default_orderby( $default ): string {
		$id = self::category_id(); if ( ! $id || isset( $_GET['orderby'] ) ) { return (string) $default; }
		$map = array( 'manual' => 'pc_collection_manual', 'created_desc' => 'date', 'sales_desc' => 'popularity', 'price_asc' => 'price', 'price_desc' => 'price-desc', 'title_asc' => 'title' );
		return $map[ self::state( $id )['sort'] ] ?? (string) $default;
	}

	public static function catalog_orderby( $options ): array {
		$id = self::category_id();
		if ( $id && 'manual' === self::state( $id )['sort'] ) { $options = array( 'pc_collection_manual' => __( 'Collection order', 'peptide-core' ) ) + (array) $options; }
		return (array) $options;
	}

	public static function ordering_args( $args, $orderby = '', $order = '' ): array {
		$id = self::category_id(); if ( ! $id || ( isset( $_GET['orderby'] ) && 'pc_collection_manual' !== $_GET['orderby'] ) ) { return (array) $args; }
		$sort = self::state( $id )['sort'];
		if ( 'manual' === $sort ) { $args['orderby'] = 'menu_order title'; $args['order'] = 'ASC'; unset( $args['meta_key'] ); }
		elseif ( 'title_asc' === $sort ) { $args['orderby'] = 'title'; $args['order'] = 'ASC'; unset( $args['meta_key'] ); }
		elseif ( 'created_desc' === $sort ) { $args['orderby'] = 'date ID'; $args['order'] = 'DESC'; unset( $args['meta_key'] ); }
		return (array) $args;
	}

	public static function manual_orderby( string $orderby, $query ): string {
		$id = self::category_id();
		if ( ! $id || ( isset( $_GET['orderby'] ) && 'pc_collection_manual' !== $_GET['orderby'] ) || ! $query instanceof \WP_Query || ! $query->is_main_query() || 'manual' !== self::state( $id )['sort'] ) { return $orderby; }
		$ids = self::state( $id )['manual_order']; if ( ! $ids ) { return $orderby; }
		global $wpdb; $when = array(); foreach ( $ids as $index => $pid ) { $when[] = 'WHEN ' . (int) $pid . ' THEN ' . (int) $index; }
		return 'CASE ' . $wpdb->posts . '.ID ' . implode( ' ', $when ) . ' ELSE ' . count( $ids ) . ' END ASC, ' . $wpdb->posts . '.ID ASC';
	}

	/** Reuse per-collection order in existing decoration modules, not global menu_order. */
	public static function product_query_args( int $term_id, array $args ): array {
		if ( ! self::term( $term_id ) ) { return $args; }
		$s = self::state( $term_id ); if ( 'default' === $s['sort'] ) { return $args; }
		$ids = array_values( array_filter( self::member_ids( $term_id, false, true ), static fn( $id ) => 'publish' === get_post_status( $id ) ) );
		// Missing price/sales metadata must not make products disappear from sorting.
		$ids = self::sorted_ids( $ids, $s );
		if ( ! empty( $args['include'] ) ) { $ids = array_values( array_intersect( $ids, self::ids( $args['include'] ) ) ); }
		$args['include'] = $ids ?: array( 0 ); $args['orderby'] = 'include'; $args['order'] = 'ASC';
		return $args;
	}
}
