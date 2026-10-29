<?php
/** Administrator-owned frontend snippets, virtual text files and reusable support phrases. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Site_Tools {
	public const OPTION = 'peptide_core_site_tools';
	public const FILES = 'peptide_core_virtual_files';
	public const PHRASES = 'peptide_core_support_phrases';
	public const CAP = 'manage_options';
	public const MAX_ITEMS = 50;
	private static array $rendered = array();

	public static function register_hooks(): void {
		add_action( 'wp_head', static function () { self::output( 'head' ); }, 90 );
		add_action( 'wp_footer', static function () { self::output( 'footer' ); }, 90 );
		add_action( 'template_redirect', array( self::class, 'serve_file' ), 0 );
	}

	public static function can_manage(): bool { return current_user_can( self::CAP ) && current_user_can( 'unfiltered_html' ); }
	public static function settings(): array { return array_merge( array( 'enabled' => false, 'snippets' => array() ), (array) get_option( self::OPTION, array() ) ); }
	public static function files(): array { return (array) get_option( self::FILES, array() ); }
	public static function phrases(): array { return (array) get_option( self::PHRASES, array() ); }
	public static function revision( string $option ): string { return hash( 'sha256', wp_json_encode( get_option( $option, array() ) ) ); }

	/** Only these three owned options can be changed; stale tabs cannot silently overwrite newer changes. */
	private static function change( string $option, callable $work, ?string $revision = null ) {
		if ( ! self::can_manage() ) { return new \WP_Error( 'pc_tools_forbidden', '仅有站点代码管理权限的管理员可使用此工具。', array( 'status' => 403 ) ); }
		if ( ! in_array( $option, array( self::OPTION, self::FILES, self::PHRASES ), true ) ) { return new \WP_Error( 'pc_tools_option', '无效的工具设置。' ); }
		global $wpdb;
		$key = 'pc-tools-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $option ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $key ) ) ) { return new \WP_Error( 'pc_tools_busy', '另一项操作正在保存，请稍后重试。', array( 'status' => 409 ) ); }
		try {
			wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' );
			if ( null !== $revision && ! hash_equals( self::revision( $option ), $revision ) ) { return new \WP_Error( 'pc_tools_conflict', '设置已在其他页面修改，请刷新并核对后再保存。', array( 'status' => 409 ) ); }
			$old = (array) get_option( $option, array() ); $new = $work( $old );
			if ( is_wp_error( $new ) ) { return $new; }
			if ( strlen( wp_json_encode( $new ) ) > 1048576 ) { return new \WP_Error( 'pc_tools_size', '当前工具内容总量超过1MB，请减少内容后保存。' ); }
			if ( $old !== $new && ! update_option( $option, $new, false ) ) { return new \WP_Error( 'pc_tools_write', '保存失败，请稍后重试。' ); }
			do_action( 'peptide_core_site_tools_changed', $option, get_current_user_id() );
			return $new;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	public static function save_settings( array $raw, ?string $revision = null ) {
		return self::change( self::OPTION, static function ( $old ) use ( $raw ) { $old['enabled'] = ! empty( $raw['enabled'] ); $old['snippets'] = (array) ( $old['snippets'] ?? array() ); return $old; }, $revision );
	}

	public static function save_snippet( array $raw, ?string $revision = null ) {
		return self::change( self::OPTION, static function ( $old ) use ( $raw ) {
			$id = absint( $raw['id'] ?? 0 ); $items = (array) ( $old['snippets'] ?? array() );
			if ( $id && ! isset( $items[ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '代码片段已不存在。' ); }
			if ( ! $id && count( $items ) >= self::MAX_ITEMS ) { return new \WP_Error( 'pc_tools_limit', '最多保存50个代码片段。' ); }
			$name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) ); $type = (string) ( $raw['type'] ?? '' ); $code = (string) ( $raw['code'] ?? '' );
			if ( ! $name || strlen( $name ) > 200 || ! in_array( $type, array( 'js', 'css' ), true ) || ! trim( $code ) || strlen( $code ) > 100000 || ! preg_match( '//u', $code ) || false !== strpos( $code, "\0" ) || preg_match( '#</?\s*(?:script|style)\b#i', $code ) ) { return new \WP_Error( 'pc_tools_code', '请填写名称和不超过100KB的UTF8纯JS或CSS内容，不要包含script/style标签。' ); }
			$scope = (string) ( $raw['scope'] ?? 'all' );
			if ( ! in_array( $scope, array( 'all', 'home', 'shop', 'product', 'pages', 'posts', 'selected' ), true ) ) { return new \WP_Error( 'pc_tools_scope', '请选择有效的前台页面范围。' ); }
			$targets = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['targets'] ?? array() ) ), static fn( $target ) => in_array( get_post_type( $target ), array( 'page', 'post', 'product', 'pc_topic' ), true ) && 'trash' !== get_post_status( $target ) ) ) );
			if ( 'selected' === $scope && ! $targets ) { return new \WP_Error( 'pc_tools_target', '请选择至少一个目标页面。' ); }
			$id = $id ?: ( $items ? max( array_map( 'intval', array_keys( $items ) ) ) + 1 : 1 );
			$items[ $id ] = array( 'id' => $id, 'name' => $name, 'type' => $type, 'code' => $code, 'scope' => $scope, 'targets' => $targets, 'location' => 'footer' === ( $raw['location'] ?? '' ) ? 'footer' : 'head', 'enabled' => ! empty( $raw['enabled'] ), 'updated' => time(), 'author' => get_current_user_id() );
			$old['enabled'] = ! empty( $old['enabled'] ); $old['snippets'] = $items; return $old;
		}, $revision );
	}

	public static function delete_snippet( int $id, ?string $revision = null ) {
		return self::change( self::OPTION, static function ( $old ) use ( $id ) { if ( ! isset( $old['snippets'][ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '代码片段已不存在。' ); } unset( $old['snippets'][ $id ] ); return $old; }, $revision );
	}

	public static function matches_scope( string $scope, array $targets = array() ): bool {
		switch ( $scope ) {
			case 'all': return true;
			case 'home': return is_front_page();
			case 'shop': return function_exists( 'is_shop' ) && ( is_shop() || is_tax( array( 'product_cat', 'product_tag' ) ) );
			case 'product': return is_singular( 'product' );
			case 'pages': return is_page();
			case 'posts': return is_singular( 'post' );
			case 'selected': return is_singular() && in_array( get_queried_object_id(), $targets, true );
		}
		return false;
	}

	public static function frontend_allowed(): bool {
		global $pagenow;
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && 'wp-login.php' !== $pagenow && ! is_feed() && ! is_robots() && ! get_query_var( 'sitemap' );
	}

	public static function output( string $location ): void {
		$s = self::settings();
		if ( ! $s['enabled'] || ! self::frontend_allowed() || ! in_array( $location, array( 'head', 'footer' ), true ) ) { return; }
		foreach ( $s['snippets'] as $r ) {
			if ( empty( $r['enabled'] ) || $r['location'] !== $location || ! self::matches_scope( $r['scope'], (array) ( $r['targets'] ?? array() ) ) || isset( self::$rendered[ $r['id'] ] ) ) { continue; }
			self::$rendered[ $r['id'] ] = true;
			$tag = 'css' === $r['type'] ? 'style' : 'script';
			echo '<' . $tag . ' id="pc-site-snippet-' . (int) $r['id'] . '">' . "\n" . $r['code'] . "\n</" . $tag . ">\n"; // Trusted administrator code, validated on save; never interpreted as PHP.
		}
	}

	public static function file_types(): array {
		return array( 'txt' => 'text/plain; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8', 'json' => 'application/json; charset=UTF-8', 'xml' => 'application/xml; charset=UTF-8', 'csv' => 'text/csv; charset=UTF-8', 'webmanifest' => 'application/manifest+json; charset=UTF-8' );
	}

	/** Own namespace plus three explicitly owned text endpoints; no arbitrary filesystem targets. */
	public static function valid_path( string $path ): bool {
		if ( strlen( $path ) > 150 || false !== strpos( $path, '..' ) || preg_match( '/[%\\\\?#\x00-\x20]/', $path ) ) { return false; }
		if ( self::protected_path( $path ) ) { return false; }
		if ( in_array( $path, array( '/ads.txt', '/app-ads.txt', '/.well-known/security.txt' ), true ) ) { return true; }
		if ( ! preg_match( '#^/site-files/[a-z0-9][a-z0-9._-]{0,90}\.([a-z]+)$#D', $path, $match ) ) { return false; }
		return isset( self::file_types()[ $match[1] ] );
	}

	private static function protected_path( string $path ): bool {
		$paths = array( '/wp-admin', '/wp-json', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wc-api', '/cart', '/checkout', '/my-account', '/account', '/order-pay', '/order-received', '/api', '/robots.txt', '/sitemap.xml', '/wp-sitemap.xml' );
		$rest = self::request_file_path( rest_url() ); if ( '' !== $rest && '/' !== $rest ) { $paths[] = $rest; }
		$login = self::request_file_path( wp_login_url() ); if ( '' !== $login && '/' !== $login ) { $paths[] = $login; }
		if ( function_exists( 'wc_get_page_permalink' ) ) { foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) { $paths[] = self::request_file_path( wc_get_page_permalink( $page ) ); } }
		foreach ( array_filter( $paths ) as $reserved ) { if ( rtrim( $path, '/' ) === rtrim( $reserved, '/' ) || str_starts_with( $path, rtrim( $reserved, '/' ) . '/' ) ) { return true; } }
		return false;
	}

	public static function save_file( array $raw, ?string $revision = null ) {
		return self::change( self::FILES, static function ( $items ) use ( $raw ) {
			$id = absint( $raw['id'] ?? 0 );
			if ( $id && ! isset( $items[ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '虚拟文件已不存在。' ); }
			if ( ! $id && count( $items ) >= self::MAX_ITEMS ) { return new \WP_Error( 'pc_tools_limit', '最多保存50个虚拟文件。' ); }
			$path = trim( (string) ( $raw['path'] ?? '' ) ); $content = (string) ( $raw['content'] ?? '' );
			if ( ! self::valid_path( $path ) || strlen( $content ) > 262144 || ! preg_match( '//u', $content ) || false !== strpos( $content, "\0" ) || preg_match( '/<\?(?:php|=)|<script\b/i', $content ) ) { return new \WP_Error( 'pc_tools_path', '请使用允许的虚拟文本路径和不超过256KB的UTF8文本，不允许PHP或HTML脚本标签。' ); }
			if ( file_exists( ABSPATH . ltrim( $path, '/' ) ) || get_page_by_path( ltrim( $path, '/' ) ) ) { return new \WP_Error( 'pc_tools_owned', '此路径已有站点文件或页面，不能覆盖。' ); }
			foreach ( $items as $key => $r ) { if ( (int) $key !== $id && $r['path'] === $path ) { return new \WP_Error( 'pc_tools_duplicate', '此虚拟路径已存在，请编辑原文件。' ); } }
			$type = pathinfo( $path, PATHINFO_EXTENSION );
			if ( in_array( $type, array( 'json', 'webmanifest' ), true ) ) { json_decode( $content ); if ( JSON_ERROR_NONE !== json_last_error() ) { return new \WP_Error( 'pc_tools_json', 'JSON内容无效，请修正后保存。' ); } }
			$id = $id ?: ( $items ? max( array_map( 'intval', array_keys( $items ) ) ) + 1 : 1 );
			$items[ $id ] = array( 'id' => $id, 'path' => $path, 'content' => $content, 'enabled' => ! empty( $raw['enabled'] ), 'updated' => time(), 'author' => get_current_user_id() ); return $items;
		}, $revision );
	}

	public static function delete_file( int $id, ?string $revision = null ) { return self::change( self::FILES, static function ( $items ) use ( $id ) { if ( ! isset( $items[ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '虚拟文件已不存在。' ); } unset( $items[ $id ] ); return $items; }, $revision ); }

	public static function resolve_file( string $path ): ?array {
		if ( ! self::valid_path( $path ) ) { return null; }
		foreach ( self::files() as $r ) { if ( ! empty( $r['enabled'] ) && $path === $r['path'] ) { return $r + array( 'mime' => self::file_types()[ pathinfo( $path, PATHINFO_EXTENSION ) ], 'etag' => '"' . hash( 'sha256', $r['content'] ) . '"' ); } }
		return null;
	}

	public static function request_file_path( string $uri ): string {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH ); $base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $base && ! str_starts_with( $path, $base . '/' ) ) { return ''; }
		return $base ? substr( $path, strlen( $base ) ) : $path;
	}

	public static function serve_file(): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) { return; }
		$path = self::request_file_path( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); $r = self::resolve_file( $path );
		if ( ! $r ) { return; }
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { status_header( 405 ); header( 'Allow: GET, HEAD' ); exit; }
		status_header( 200 ); header( 'Content-Type: ' . $r['mime'] ); header( 'X-Content-Type-Options: nosniff' );
		if ( ! in_array( pathinfo( $path, PATHINFO_EXTENSION ), array( 'js', 'css' ), true ) ) { header( "Content-Security-Policy: default-src 'none'; sandbox" ); }
		header( 'Cache-Control: public, max-age=300' ); header( 'ETag: ' . $r['etag'] );
		if ( trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) === $r['etag'] ) { status_header( 304 ); exit; }
		if ( 'HEAD' !== $method ) { echo $r['content']; } exit;
	}

	public static function save_phrase( array $raw, ?string $revision = null ) {
		return self::change( self::PHRASES, static function ( $items ) use ( $raw ) {
			$id = absint( $raw['id'] ?? 0 ); if ( $id && ! isset( $items[ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '话术已不存在。' ); }
			if ( ! $id && count( $items ) >= 200 ) { return new \WP_Error( 'pc_tools_limit', '最多保存200条话术。' ); }
			$title = sanitize_text_field( (string) ( $raw['title'] ?? '' ) ); $text = sanitize_textarea_field( (string) ( $raw['text'] ?? '' ) ); $category = sanitize_text_field( (string) ( $raw['category'] ?? '' ) );
			if ( ! trim( $title ) || ! trim( $text ) || strlen( $title ) > 200 || strlen( $category ) > 100 || strlen( $text ) > 10000 ) { return new \WP_Error( 'pc_tools_phrase', '请填写话术名称和正文，正文最多10KB。' ); }
			$id = $id ?: ( $items ? max( array_map( 'intval', array_keys( $items ) ) ) + 1 : 1 );
			$items[ $id ] = array( 'id' => $id, 'title' => $title, 'category' => $category, 'text' => $text, 'updated' => time(), 'author' => get_current_user_id() ); return $items;
		}, $revision );
	}
	public static function delete_phrase( int $id, ?string $revision = null ) { return self::change( self::PHRASES, static function ( $items ) use ( $id ) { if ( ! isset( $items[ $id ] ) ) { return new \WP_Error( 'pc_tools_missing', '话术已不存在。' ); } unset( $items[ $id ] ); return $items; }, $revision ); }
}
