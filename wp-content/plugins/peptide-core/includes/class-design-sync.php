<?php
/**
 * 平台统一装修（2026-11-08）。
 *
 * 母版店（总后台指定，一般是主店）在“店铺装修”里正常编辑、发布；总后台把母版店已发布的装修
 * （首页、页眉、页脚、全局样式 + 用到的图片 / 视频）下发给其他店铺。收到下发的店铺：
 *   - 装修写入“平台统一模板”并设为默认，清掉按域名指定的其他模板；
 *   - 媒体从母版店的公开地址下载，按 SHA-256 校验，已有的不重复下载；
 *   - 店铺后台的装修编辑、发布、模板管理被锁住（首页 SEO、域名、菜单、自定义页面不受影响）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Modules\Layout;

defined( 'ABSPATH' ) || exit;

final class Design_Sync {

	public const OPTION    = 'pc_design_managed';
	public const MEDIA_MAP = 'pc_design_media_map';
	private const DOCS     = array( 'home', 'header', 'footer', 'global' );
	private const MAX_MEDIA_BYTES = 64 * 1024 * 1024;
	private const MAX_TOTAL_BYTES = 256 * 1024 * 1024;
	private const MIMES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'video/mp4', 'video/webm' );

	public static function state(): array {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	/** 这个店铺的装修是否由平台统一下发并锁定。 */
	public static function locked(): bool {
		return ! empty( self::state()['locked'] );
	}

	public static function locked_error(): \WP_Error {
		return new \WP_Error( 'pc_design_managed', '这个店铺的装修由平台统一下发，不能在店铺后台修改。', array( 'status' => 403 ) );
	}

	/** 给总后台的简短状态。 */
	public static function summary(): array {
		$s = self::state();
		return array(
			'version'    => (string) ( $s['version'] ?? '' ),
			'applied_at' => (int) ( $s['applied_at'] ?? 0 ),
			'locked'     => ! empty( $s['locked'] ),
			'modified'   => (string) ( Templates::modified( Templates::default_id( false ) ) ?? '' ),
		);
	}

	// ---------- 母版店：导出 ----------

	/** 默认模板已发布的四个装修文档 + 引用的媒体（公开地址、大小、SHA-256）。 */
	public static function export(): array {
		$template = Templates::default_id( false );
		$docs     = array();
		$ids      = array();
		$collect  = static function ( $value ) use ( &$collect, &$ids ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( $value as $key => $v ) {
				if ( 'attachmentId' === $key && is_numeric( $v ) && (int) $v > 0 ) {
					$ids[ (int) $v ] = true;
				}
				if ( is_array( $v ) ) {
					$collect( $v );
				}
			}
		};
		foreach ( self::DOCS as $doc ) {
			$docs[ $doc ] = Layout::published_modules( $doc, $template );
			$collect( $docs[ $doc ] );
		}
		$uploads = realpath( wp_upload_dir()['basedir'] );
		$media   = array();
		foreach ( array_keys( $ids ) as $id ) {
			$file = get_attached_file( $id );
			$real = $file ? realpath( $file ) : false;
			$mime = (string) get_post_mime_type( $id );
			if ( ! $real || ! $uploads || ! str_starts_with( $real, $uploads . DIRECTORY_SEPARATOR ) || is_link( $file ) || ! in_array( $mime, self::MIMES, true ) || filesize( $real ) > self::MAX_MEDIA_BYTES ) {
				continue;
			}
			$media[] = array(
				'id'     => $id,
				'url'    => self::public_media_url( (string) wp_get_attachment_url( $id ) ),
				'mime'   => $mime,
				'size'   => (int) filesize( $real ),
				'sha256' => (string) hash_file( 'sha256', $real ),
				'name'   => basename( $real ),
				'title'  => (string) get_the_title( $id ),
				'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}
		$bundle            = array( 'version' => 1, 'documents' => $docs, 'media' => $media );
		$bundle['digest']  = hash( 'sha256', (string) wp_json_encode( array( $docs, wp_list_pluck( $media, 'sha256' ) ) ) );
		$bundle['source']  = (string) wp_parse_url( self::public_origin(), PHP_URL_HOST );
		$bundle['origins'] = self::own_origins();
		$bundle['modified'] = (string) ( Templates::modified( $template ) ?? '' );
		return $bundle;
	}

	/** 本店的网址（顾客地址和后台地址），用于把装修里的绝对链接换成收到装修的店铺自己的地址。 */
	public static function own_origins(): array {
		$out = array( untrailingslashit( home_url( '/' ) ) );
		if ( class_exists( Management_Host::class ) && Management_Host::enabled() ) {
			$out[] = untrailingslashit( Management_Host::public_url() );
			$out[] = untrailingslashit( Management_Host::management_url() );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/** 媒体地址统一用顾客地址（从后台地址请求导出时，WordPress 会给出后台域名）。 */
	private static function public_media_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $path ? $url : self::public_origin() . $path;
	}

	/** 店铺的顾客地址（链接改写的目标）。 */
	public static function public_origin(): string {
		return untrailingslashit( class_exists( Management_Host::class ) && Management_Host::enabled() ? Management_Host::public_url() : home_url( '/' ) );
	}

	/**
	 * 把装修里指向母版店的绝对链接（含 www / 不带 www、http / https）换成本店地址。
	 * 例如页脚 https://www.vialcovepeptides.com/offers/ → https://www.本店域名.com/offers/。
	 */
	public static function rewrite_links( $value, array $origins, string $target ) {
		$hosts = array();
		foreach ( $origins as $o ) {
			$h = strtolower( (string) wp_parse_url( (string) $o, PHP_URL_HOST ) );
			if ( '' !== $h ) {
				$hosts[ preg_replace( '/^www\./', '', $h ) ] = true;
			}
		}
		if ( ! $hosts || '' === $target ) {
			return $value;
		}
		$pattern = '#https?://(?:www\.)?(?:' . implode( '|', array_map( static fn( $h ) => preg_quote( $h, '#' ), array_keys( $hosts ) ) ) . ')(?=[/?\#"\'\s<)]|$)#i';
		$walk    = static function ( $v ) use ( &$walk, $pattern, $target ) {
			if ( is_string( $v ) ) {
				return preg_replace( $pattern, $target, $v );
			}
			if ( is_array( $v ) ) {
				foreach ( $v as $k => $x ) {
					$v[ $k ] = $walk( $x );
				}
			}
			return $v;
		};
		return $walk( $value );
	}

	// ---------- 其他店铺：应用 ----------

	/** @return array|\WP_Error 应用后的状态 */
	public static function apply( array $bundle, bool $lock, string $source_host, bool $dry_run = false ) {
		$started = microtime( true );
		$fetched = 0;
		$bytes   = 0;
		if ( 1 !== ( $bundle['version'] ?? 0 ) || ! is_array( $bundle['documents'] ?? null ) || ! is_array( $bundle['media'] ?? null ) || count( $bundle['media'] ) > 64 ) {
			return new \WP_Error( 'pc_design_bundle', '下发的装修内容无效。', array( 'status' => 400 ) );
		}
		foreach ( self::DOCS as $doc ) {
			if ( ! is_array( $bundle['documents'][ $doc ] ?? null ) ) {
				return new \WP_Error( 'pc_design_bundle', '下发的装修缺少' . $doc . '。', array( 'status' => 400 ) );
			}
		}
		$source_host = strtolower( $source_host );
		if ( ! preg_match( '/\A[a-z0-9.-]{3,253}\z/D', $source_host ) ) {
			return new \WP_Error( 'pc_design_source', '母版店地址无效。', array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 下载视频可能需要一点时间。
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$map   = get_option( self::MEDIA_MAP, array() );
		$map   = is_array( $map ) ? $map : array();
		$total = 0;
		$ids   = array();
		foreach ( $bundle['media'] as $m ) {
			$sid  = (int) ( $m['id'] ?? 0 );
			$sha  = strtolower( (string) ( $m['sha256'] ?? '' ) );
			$mime = (string) ( $m['mime'] ?? '' );
			$size = (int) ( $m['size'] ?? 0 );
			$url  = (string) ( $m['url'] ?? '' );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $sid < 1 || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha ) || ! in_array( $mime, self::MIMES, true ) || $size < 1 || $size > self::MAX_MEDIA_BYTES
				|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ( $host !== $source_host && 'www.' . $host !== $source_host && $host !== 'www.' . $source_host ) ) {
				return new \WP_Error( 'pc_design_media', '下发的媒体信息无效，已停止，店铺装修没有改动。', array( 'status' => 400 ) );
			}
			$total += $size;
			if ( $total > self::MAX_TOTAL_BYTES ) {
				return new \WP_Error( 'pc_design_media', '下发的媒体总量太大。', array( 'status' => 400 ) );
			}
			$known = $map[ $sid ] ?? null;
			if ( ! $dry_run && is_array( $known ) && $sha === ( $known['sha256'] ?? '' ) && 'attachment' === get_post_type( (int) $known['id'] ) ) {
				$ids[ $sid ] = (int) $known['id'];
				continue;
			}
			$tmp = download_url( $url, 240 );
			if ( is_wp_error( $tmp ) ) {
				return new \WP_Error( 'pc_design_download', '下载母版店的媒体失败（' . $tmp->get_error_message() . '），店铺装修没有改动。', array( 'status' => 502 ) );
			}
			if ( filesize( $tmp ) !== $size || ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'pc_design_download', '下载到的媒体和母版店不一致，已停止，店铺装修没有改动。', array( 'status' => 502 ) );
			}
			++$fetched;
			$bytes += $size;
			if ( $dry_run ) {
				// 测试下发：只验证能下载、内容一致，不保存。
				wp_delete_file( $tmp );
				continue;
			}
			$name = sanitize_file_name( 'master-' . $sid . '-' . (string) ( $m['name'] ?? 'media' ) );
			$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, sanitize_text_field( (string) ( $m['title'] ?? '' ) ) );
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'pc_design_media', '保存媒体失败（' . $id->get_error_message() . '），店铺装修没有改动。', array( 'status' => 500 ) );
			}
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $m['alt'] ?? '' ) ) );
			update_post_meta( $id, '_pc_design_source', $sid );
			$map[ $sid ] = array( 'id' => (int) $id, 'sha256' => $sha );
			update_option( self::MEDIA_MAP, $map, false );
			$ids[ $sid ] = (int) $id;
		}

		if ( $dry_run ) {
			$counts = array();
			foreach ( self::DOCS as $doc ) {
				$counts[ $doc ] = count( Layout::clean_modules( $bundle['documents'][ $doc ] ) );
			}
			return array( 'dry_run' => true, 'documents' => $counts, 'media' => count( $bundle['media'] ), 'downloaded' => $fetched, 'bytes' => $bytes, 'seconds' => round( microtime( true ) - $started, 1 ), 'locked' => self::locked() );
		}

		$remap = static function ( $v ) use ( &$remap, $ids ) {
			if ( ! is_array( $v ) ) {
				return $v;
			}
			foreach ( $v as $key => $value ) {
				$v[ $key ] = 'attachmentId' === $key && is_numeric( $value ) ? ( $ids[ (int) $value ] ?? null ) : ( is_array( $value ) ? $remap( $value ) : $value );
			}
			return $v;
		};

		$state    = self::state();
		$template = (int) ( $state['template'] ?? 0 );
		if ( ! $template || ! Templates::exists( $template ) ) {
			$template = Templates::create( '平台统一模板', '由总后台统一下发，店铺不能修改。' );
			if ( ! $template ) {
				return new \WP_Error( 'pc_design_template', '创建平台统一模板失败。', array( 'status' => 500 ) );
			}
		}
		foreach ( self::DOCS as $doc ) {
			$id      = Templates::document_id( $template, $doc );
			$origins = array_merge( array( 'https://' . $source_host ), array_map( 'strval', (array) ( $bundle['origins'] ?? array() ) ) );
			$modules = Layout::clean_modules( self::rewrite_links( $remap( $bundle['documents'][ $doc ] ), $origins, self::public_origin() ) );
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( Layout::content_from_modules( $modules ) ) ) );
			$published = Layout::publish( $id );
			if ( is_wp_error( $published ) ) {
				return new \WP_Error( 'pc_design_publish', '发布装修失败（' . $published->get_error_message() . '）。', array( 'status' => 500 ) );
			}
		}
		Templates::set_default( $template );
		// 统一以后不再按域名换模板。
		foreach ( array_keys( Templates::domain_map() ) as $domain ) {
			Templates::remove_domain( (string) $domain );
		}
		$digest = (string) ( $bundle['digest'] ?? '' );
		update_option(
			self::OPTION,
			array(
				'locked'     => $lock,
				'template'   => $template,
				'version'    => preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) ? $digest : '',
				'source'     => $source_host,
				'applied_at' => time(),
			),
			false
		);
		if ( class_exists( Page_Cache::class ) && method_exists( Page_Cache::class, 'flush' ) ) {
			Page_Cache::flush();
		}
		return self::summary();
	}

	/** 只改锁定开关（不重新下发）。 */
	public static function set_lock( bool $lock ): array {
		$s           = self::state();
		$s['locked'] = $lock;
		update_option( self::OPTION, $s, false );
		return self::summary();
	}
}
