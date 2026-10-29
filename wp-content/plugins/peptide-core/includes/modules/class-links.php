<?php
/** Read-only editor target lookup. Only a verified URL is copied into the old href. */
namespace PeptideCore\Modules;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Links {
	private const ACTION = 'pc_layout_link_targets';
	private const POSTS = array( 'product' => 'product', 'page' => 'page', 'post' => 'post', 'topic' => 'pc_topic' );

	public static function register_hooks(): void { add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'ajax' ) ); }
	public static function config(): array { return array( 'url' => admin_url( 'admin-ajax.php' ), 'security' => wp_create_nonce( self::ACTION ), 'types' => self::types() ); }
	private static function types(): array {
		$types = array( array( 'value' => 'route', 'label' => '常用页面' ) );
		foreach ( array( 'product' => '商品', 'category' => '商品专辑', 'page' => '页面', 'post' => '博客文章', 'topic' => '专题页面' ) as $type => $label ) {
			$object = 'category' === $type ? get_taxonomy( 'product_cat' ) : get_post_type_object( self::POSTS[ $type ] );
			if ( $object && $object->public ) { $types[] = array( 'value' => $type, 'label' => $label ); }
		}
		return $types;
	}
	public static function ajax(): void {
		$result = self::request( wp_unslash( $_GET ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) ); }
		wp_send_json_success( $result );
	}
	/** Every entry point checks permissions and nonce, including server-side resolve. */
	public static function request( array $input ) {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) { return self::error( 'permission', '没有装修权限。', 403 ); }
		if ( ! wp_verify_nonce( self::scalar( $input, 'security' ), self::ACTION ) ) { return self::error( 'nonce', '链接选择权限已过期，请刷新后台。', 403 ); }
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return self::error( 'method', '请使用链接选择器读取目标。', 405 ); }
		foreach ( array( 'type', 'mode', 'id', 'term', 'paged' ) as $key ) { if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) && ! is_int( $input[ $key ] ) ) { return self::error( 'query', '链接查询条件无效。', 400 ); } }
		$type = self::scalar( $input, 'type', 'route' ); $mode = self::scalar( $input, 'mode', 'list' );
		if ( ! in_array( $type, array_column( self::types(), 'value' ), true ) || ! in_array( $mode, array( 'list', 'resolve' ), true ) ) { return self::error( 'type', '请选择可用的链接类型。', 400 ); }
		if ( 'resolve' === $mode ) {
			$id = self::scalar( $input, 'id' );
			if ( 'route' !== $type && ! preg_match( '/^[1-9][0-9]{0,17}$/D', $id ) ) { return self::error( 'id', '链接目标已不可用，请重新选择。', 404 ); }
			try { $row = self::read( static fn() => self::resolve( $type, $id ) ); } catch ( \Throwable $exception ) { return self::error( 'unavailable', '无法核验目标，请重试。', 503 ); }
			return $row ? array( 'target' => $row ) : self::error( 'missing', '目标已删除、下架或不公开，请重新选择。', 404 );
		}
		$term = self::scalar( $input, 'term' ); $page = self::scalar( $input, 'paged', '1' );
		if ( ! preg_match( '/^[1-9][0-9]{0,3}$/D', $page ) || (int) $page > 1000 || ! preg_match( '//u', $term ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term ) ) > 100 || ( isset( $input['term'] ) && ! is_scalar( $input['term'] ) ) ) { return self::error( 'query', '搜索条件无效，请精简关键词后重试。', 400 ); }
		try { return self::listing( $type, sanitize_text_field( $term ), (int) $page ); }
		catch ( \Throwable $exception ) { return self::error( 'unavailable', '链接读取失败，请重试。', 503 ); }
	}
	private static function listing( string $type, string $term, int $page ): array {
		if ( 'route' === $type ) {
			$rows = array_values( array_filter( self::routes(), static fn( $row ) => '' === $term || false !== stripos( $row['label'], $term ) || false !== stripos( $row['id'], $term ) ) );
			return array( 'options' => array_slice( $rows, ( $page - 1 ) * 20, 20 ), 'more' => count( $rows ) > $page * 20 );
		}
		if ( 'category' === $type ) {
			$terms = self::read( static fn() => get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => $term, 'number' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'name', 'order' => 'ASC' ) ) );
			if ( is_wp_error( $terms ) ) { throw new \RuntimeException( 'terms' ); }
			$ids = array_map( static fn( $t ) => (string) $t->term_id, array_slice( $terms, 0, 20 ) ); $more = count( $terms ) > 20;
		} else {
			$args = array( 'post_type' => self::POSTS[ $type ], 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true, 's' => $term, 'search_columns' => array( 'post_title' ), 'ignore_sticky_posts' => true );
			if ( 'product' === $type && '' !== $term && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$sku_id = (int) self::read( static fn() => wc_get_product_id_by_sku( $term ) );
				if ( $sku_id && 'product_variation' === get_post_type( $sku_id ) ) { $sku_id = (int) wp_get_post_parent_id( $sku_id ); }
				if ( $sku_id ) { $args['post__in'] = array( $sku_id ); $args['s'] = ''; }
			}
			$query = self::read( static fn() => new \WP_Query( $args ) );
			$ids = array_map( 'strval', wp_list_pluck( array_slice( $query->posts, 0, 20 ), 'ID' ) ); $more = count( $query->posts ) > 20;
		}
		$rows = array(); foreach ( $ids as $id ) { $row = self::read( static fn() => self::resolve( $type, $id ) ); if ( $row ) { $rows[] = $row; } }
		return array( 'options' => $rows, 'more' => $more && $page < 1000 );
	}
	private static function resolve( string $type, string $id ): ?array {
		if ( 'route' === $type ) { foreach ( self::routes() as $row ) { if ( $row['id'] === $id ) { return $row; } } return null; }
		if ( 'category' === $type ) {
			$term = get_term( (int) $id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) { return null; }
			$url = get_term_link( $term );
			return is_wp_error( $url ) ? null : self::row( $type, $id, $term->name, $url );
		}
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== self::POSTS[ $type ] || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! is_post_publicly_viewable( $post ) ) { return null; }
		if ( 'product' === $type ) { $item = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null; if ( ! $item || ! $item->is_visible() || (int) $item->get_parent_id() ) { return null; } }
		return self::row( $type, $id, get_the_title( $post ), get_permalink( $post ) );
	}
	private static function routes(): array {
		$rows = array();
		foreach ( array( 'home' => array( '首页', home_url( '/' ) ), 'search' => array( '站内搜索', get_search_link( '' ) ), 'login' => array( '登录', wp_login_url() ) ) as $id => $value ) { $row = self::row( 'route', $id, $value[0], $value[1] ); if ( $row ) { $rows[] = $row; } }
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop' => '商品列表', 'cart' => '购物车', 'checkout' => '结账', 'myaccount' => '我的账户' ) as $key => $label ) {
				$id = (int) wc_get_page_id( $key );
				if ( $id > 0 && self::resolve( 'page', (string) $id ) ) { $row = self::row( 'route', $key, $label, wc_get_page_permalink( $key ) ); if ( $row ) { $rows[] = $row; } }
			}
		}
		if ( class_exists( '\PeptideCore\Review_Index' ) ) { $row = self::row( 'route', 'reviews', '顾客评价', \PeptideCore\Review_Index::url() ); if ( $row ) { $rows[] = $row; } }
		return $rows;
	}
	private static function row( string $type, string $id, string $label, $url ): ?array {
		$url = is_string( $url ) ? Fields::url( $url ) : '';
		return '' === $url ? null : array( 'key' => $type . ':' . $id, 'type' => $type, 'id' => $id, 'label' => wp_specialchars_decode( wp_strip_all_tags( $label ), ENT_QUOTES ), 'url' => $url );
	}
	/** WP DB errors are not exceptions; don't convert actual failed reads to fake empty results. */
	private static function read( callable $work ) {
		global $wpdb;
		$before = $wpdb->num_queries; $previous = $wpdb->suppress_errors( true );
		try { $result = $work(); if ( $wpdb->num_queries > $before && $wpdb->last_error ) { throw new \RuntimeException( 'lookup' ); } return $result; }
		finally { $wpdb->suppress_errors( $previous ); }
	}
	private static function scalar( array $input, string $key, string $default = '' ): string { return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default; }
	private static function error( string $code, string $message, int $status ): \WP_Error { return new \WP_Error( 'pc_link_' . $code, $message, array( 'status' => $status ) ); }
}
