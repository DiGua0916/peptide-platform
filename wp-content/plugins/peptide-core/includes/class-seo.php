<?php
/**
 * 首页 SEO 和活动专题页的 SEO（用户 2026-10-27 要求照 YY 后台做实际功能）。
 *
 * YY 的做法（店铺装修 → 首页SEO）：
 * - “默认模板”：SEO 标题、SEO 描述、SEO 关键词；
 * - “域名指定模板”：先选域名，每个域名单独一套，没有指定的域名用默认模板；可以导入 / 导出；
 * - 支持变量 {current_domain}（当前店铺域名）。
 *
 * 前台输出：首页的 <title>（替换整个标题）、meta description、meta keywords，以及 og / twitter 的标题和描述。
 * 活动专题页用它自己的 SEO（见 Topics），没填时用标题和简短描述。主题只负责 title-tag，不输出这些标签。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public const OPTION   = 'peptide_core_seo';
	public const MAX_TEXT = 2048;
	public const MAX_KEYS = 30;

	public static function register_hooks(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_action( 'wp_head', array( self::class, 'head' ), 2 );
	}

	// ---------- 设置 ----------

	/** 一套 SEO：标题、描述、关键词。 */
	public static function blank(): array {
		return array( 'title' => '', 'description' => '', 'keywords' => array() );
	}

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		$domains = array();
		foreach ( (array) ( $o['domains'] ?? array() ) as $host => $set ) {
			$host = (string) Templates::normalize_host( (string) $host );
			if ( '' !== $host && is_array( $set ) ) {
				$domains[ $host ] = self::clean_set( $set );
			}
		}
		ksort( $domains );
		return array(
			'home'    => self::clean_set( is_array( $o['home'] ?? null ) ? $o['home'] : array() ),
			'domains' => $domains,
		);
	}

	/** 清理一套 SEO。关键词可以是数组，也可以是逗号 / 换行分隔的文字；去掉空的和重复的。 */
	public static function clean_set( array $raw ): array {
		$keys = $raw['keywords'] ?? array();
		if ( ! is_array( $keys ) ) {
			$keys = preg_split( '/[\r\n,，]+/u', (string) $keys );
		}
		$clean = array();
		foreach ( $keys as $k ) {
			$k = trim( sanitize_text_field( (string) $k ) );
			if ( '' !== $k && ! in_array( mb_strtolower( $k ), array_map( 'mb_strtolower', $clean ), true ) ) {
				$clean[] = mb_substr( $k, 0, 100 );
			}
		}
		return array(
			'title'       => mb_substr( trim( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ) ), 0, self::MAX_TEXT ),
			'description' => mb_substr( trim( sanitize_textarea_field( (string) ( $raw['description'] ?? '' ) ) ), 0, self::MAX_TEXT ),
			'keywords'    => array_slice( $clean, 0, self::MAX_KEYS ),
		);
	}

	public static function save_home( array $raw ): void {
		$s         = self::settings();
		$s['home'] = self::clean_set( $raw );
		update_option( self::OPTION, $s, false );
	}

	/** 保存一个域名的 SEO；三项都空时删除这个域名的设置（回到默认模板）。 */
	public static function save_domain( string $host, array $raw ) {
		$host = (string) Templates::normalize_host( $host );
		if ( '' === $host ) {
			return new \WP_Error( 'pc_seo_domain', __( 'Enter a valid domain name, for example shop.example.com.', 'peptide-core' ) );
		}
		$s   = self::settings();
		$set = self::clean_set( $raw );
		if ( '' === $set['title'] && '' === $set['description'] && ! $set['keywords'] ) {
			unset( $s['domains'][ $host ] );
		} else {
			$s['domains'][ $host ] = $set;
		}
		update_option( self::OPTION, $s, false );
		return $host;
	}

	/** 选择域名时可选的域名：域名管理里绑定的、主题装修里指定过模板的、已经设过 SEO 的域名，以及当前网站的域名。 */
	public static function known_domains(): array {
		$hosts = array_merge( Domains::hosts(), array_keys( Templates::domain_map() ), array_keys( self::settings()['domains'] ) );
		$site  = (string) Templates::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $site ) {
			$hosts[] = $site;
		}
		$hosts = array_values( array_unique( array_filter( $hosts ) ) );
		sort( $hosts );
		return $hosts;
	}

	// ---------- 导入 / 导出（域名指定模板） ----------

	/** CSV：domain,title,description,keywords（关键词之间用 | 分隔）。 */
	public static function export_csv(): string {
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'domain', 'title', 'description', 'keywords' ) );
		foreach ( self::settings()['domains'] as $host => $set ) {
			fputcsv( $out, array( $host, self::csv_cell( $set['title'] ), self::csv_cell( $set['description'] ), self::csv_cell( implode( '|', $set['keywords'] ) ) ) );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return "\xEF\xBB\xBF" . $csv; // 带 BOM，Excel 打开中文不乱码。
	}

	/** 表格软件会把 = + - @ 开头的格子当公式执行，导出时前面加一个单引号。 */
	private static function csv_cell( string $v ): string {
		return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ? "'" . $v : $v;
	}

	/**
	 * 导入 CSV，按域名覆盖。返回 [导入的域名数, 错误信息[]]。
	 *
	 * @return array{0:int,1:string[]}
	 */
	public static function import_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $csv ) );
		$count = 0;
		$errs  = array();
		foreach ( $lines as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$row = str_getcsv( $line );
			if ( 0 === $i && 'domain' === strtolower( trim( (string) ( $row[0] ?? '' ) ) ) ) {
				continue;
			}
			$saved = self::save_domain(
				(string) ( $row[0] ?? '' ),
				array(
					'title'       => ltrim( (string) ( $row[1] ?? '' ), "'" ),
					'description' => ltrim( (string) ( $row[2] ?? '' ), "'" ),
					'keywords'    => explode( '|', ltrim( (string) ( $row[3] ?? '' ), "'" ) ),
				)
			);
			if ( is_wp_error( $saved ) ) {
				/* translators: %d: line number */
				$errs[] = sprintf( __( 'Line %d: the domain is not valid.', 'peptide-core' ), $i + 1 );
				continue;
			}
			++$count;
		}
		return array( $count, $errs );
	}

	// ---------- 前台 ----------

	/** 当前访问的域名（小写；www 和不带 www 算两个域名，和 YY 一样）。IP 地址等无效的域名返回空。 */
	public static function current_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return (string) Templates::normalize_host( $host );
	}

	/** 替换变量 {current_domain}。 */
	public static function vars( string $text ): string {
		$host = self::current_host();
		return str_replace( '{current_domain}', '' !== $host ? $host : (string) wp_parse_url( home_url(), PHP_URL_HOST ), $text );
	}

	/** 首页用的那一套：当前域名有指定的就用它，否则用默认模板。 */
	public static function home_set( string $host = '' ): array {
		$s    = self::settings();
		$host = '' !== $host ? (string) Templates::normalize_host( $host ) : self::current_host();
		return $s['domains'][ $host ] ?? $s['home'];
	}

	/** 当前页面的 SEO（首页或活动专题页）；不是这两种页面时为 null。 */
	public static function current(): ?array {
		if ( is_front_page() ) {
			return self::home_set();
		}
		if ( is_singular( Topics::POST_TYPE ) ) {
			return Topics::seo( (int) get_queried_object_id() );
		}
		return null;
	}

	public static function document_title( $title ) {
		$set = self::current();
		return $set && '' !== $set['title'] ? self::vars( $set['title'] ) : $title;
	}

	public static function head(): void {
		$set = self::current();
		if ( ! $set ) {
			return;
		}
		$title = '' !== $set['title'] ? self::vars( $set['title'] ) : wp_get_document_title();
		$desc  = self::vars( $set['description'] );
		$url   = is_front_page() ? home_url( '/' ) : (string) get_permalink( get_queried_object_id() );
		echo "\n<!-- peptide-core SEO -->\n";
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $set['keywords'] ) {
			echo '<meta name="keywords" content="' . esc_attr( self::vars( implode( ', ', $set['keywords'] ) ) ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="' . ( is_front_page() ? 'website' : 'article' ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( '' !== $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		$image = is_singular( Topics::POST_TYPE ) ? (string) get_the_post_thumbnail_url( get_queried_object_id(), 'large' ) : '';
		if ( '' !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		}
		echo '<meta name="twitter:card" content="' . ( '' !== $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( '' !== $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}
}
