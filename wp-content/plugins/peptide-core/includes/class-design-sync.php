<?php
/**
 * 平台模板（2026-11-08 第二版）。
 *
 * 模板工作室（总后台指定的店铺，一般是主店）里做模板；勾选“下发给店铺”的模板叫系统模板。
 * 收到下发的店铺：
 *   - 每个系统模板在店里对应一个“系统模板”（按工作室模板编号对应），只读，只有总后台下发能改；
 *   - 第一次收到时自动复制一份“修订版”，店铺可以随意修改；
 *   - 店铺自己选用系统模板还是修订版（第一次默认用系统模板），以后下发不会改店铺的选择，也不碰修订版。
 * 媒体从工作室店铺的公开地址下载并校验 SHA-256；指向工作室域名的链接改成本店地址。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Modules\Layout;

defined( 'ABSPATH' ) || exit;

final class Design_Sync {

	public const OPTION        = 'pc_design_managed';
	public const MEDIA_MAP     = 'pc_design_media_map';
	public const SYSTEM_META   = '_pc_system_template'; // 收到的系统模板：工作室模板编号。
	public const REVISION_META = '_pc_revision_of';     // 修订版：对应的系统模板编号。
	public const STUDIO_META   = '_pc_hq_system';       // 工作室里：这个模板要下发给店铺。
	private const DOCS         = array( 'home', 'header', 'footer', 'global' );
	private const MAX_MEDIA_BYTES = 64 * 1024 * 1024;
	private const MAX_TOTAL_BYTES = 256 * 1024 * 1024;
	private const MIMES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'video/mp4', 'video/webm' );

	public static function state(): array {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	/** 旧版的“整店锁定”已取消，只锁系统模板。保留函数名给旧调用。 */
	public static function locked(): bool {
		return false;
	}

	/** 这个模板是不是收到的系统模板（只读）。 */
	public static function is_system( int $template_id ): bool {
		return $template_id > 0 && '' !== (string) get_post_meta( $template_id, self::SYSTEM_META, true );
	}

	public static function is_revision( int $template_id ): bool {
		return $template_id > 0 && '' !== (string) get_post_meta( $template_id, self::REVISION_META, true );
	}

	/** 装修文档所属的模板是不是系统模板。 */
	public static function document_locked( int $document_id ): bool {
		return $document_id > 0 && self::is_system( (int) wp_get_post_parent_id( $document_id ) );
	}

	public static function locked_error(): \WP_Error {
		return new \WP_Error( 'pc_design_system', '这是平台下发的系统模板，不能修改。可以在“修订版”里改，或复制一份再改。', array( 'status' => 403 ) );
	}

	/** 给总后台的状态：每个系统模板的版本、店铺在用哪种模板。 */
	public static function summary(): array {
		$s   = self::state();
		$out = array();
		foreach ( (array) ( $s['templates'] ?? array() ) as $key => $row ) {
			$out[ (string) $key ] = array( 'version' => (string) ( $row['version'] ?? '' ), 'applied_at' => (int) ( $row['applied_at'] ?? 0 ) );
		}
		$default = Templates::default_id( false );
		return array(
			'templates' => $out,
			'using'     => self::is_system( $default ) ? 'system' : ( self::is_revision( $default ) ? 'revision' : 'own' ),
			'modified'  => (string) ( Templates::modified( $default ) ?? '' ),
		);
	}

	// ---------- 模板工作室（工作室店铺上运行） ----------

	/** 工作室模板清单。 */
	public static function studio_list(): array {
		$default = Templates::default_id( false );
		$out     = array();
		foreach ( Templates::all() as $t ) {
			$id    = (int) $t->ID;
			$doc   = Templates::document_id( $id, 'home', false );
			$st    = $doc ? Layout::status( $doc ) : array( 'published' => false, 'changed' => true, 'at' => null );
			$out[] = array(
				'id'        => $id,
				'name'      => (string) $t->post_title,
				'note'      => (string) get_post_meta( $id, Templates::NOTE_META, true ),
				'modified'  => (string) ( Templates::modified( $id ) ?? '' ),
				'published' => ! empty( $st['published'] ),
				'changed'   => ! empty( $st['changed'] ),
				'at'        => (string) ( $st['at'] ?? '' ),
				'system'    => '1' === (string) get_post_meta( $id, self::STUDIO_META, true ),
				'default'   => $id === $default,
				'home_doc'  => $doc,
			);
		}
		return $out;
	}

	/** 新建模板：空白，或复制一个现有模板。@return int|\WP_Error */
	public static function studio_create( string $name, string $note, int $from ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name || mb_strlen( $name ) > 80 ) {
			return new \WP_Error( 'pc_design_name', '请填写模板名称（80 个字以内）。', array( 'status' => 400 ) );
		}
		$id = $from > 0 && Templates::exists( $from ) ? Templates::duplicate( $from ) : Templates::create( $name, $note );
		if ( ! $id ) {
			return new \WP_Error( 'pc_design_create', '新建模板失败。', array( 'status' => 500 ) );
		}
		Templates::update_info( $id, $name, sanitize_text_field( $note ) );
		delete_post_meta( $id, self::STUDIO_META );
		return $id;
	}

	/** 改名 / 备注 / 是否下发。@return true|\WP_Error */
	public static function studio_update( int $id, array $p ) {
		if ( ! Templates::exists( $id ) ) {
			return new \WP_Error( 'pc_design_missing', '模板不存在。', array( 'status' => 404 ) );
		}
		if ( isset( $p['name'] ) || isset( $p['note'] ) ) {
			$name = trim( sanitize_text_field( (string) ( $p['name'] ?? get_the_title( $id ) ) ) );
			if ( '' === $name || mb_strlen( $name ) > 80 ) {
				return new \WP_Error( 'pc_design_name', '请填写模板名称（80 个字以内）。', array( 'status' => 400 ) );
			}
			Templates::update_info( $id, $name, sanitize_text_field( (string) ( $p['note'] ?? get_post_meta( $id, Templates::NOTE_META, true ) ) ) );
		}
		if ( array_key_exists( 'system', $p ) ) {
			if ( $p['system'] ) {
				update_post_meta( $id, self::STUDIO_META, '1' );
			} else {
				delete_post_meta( $id, self::STUDIO_META );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function studio_trash( int $id ) {
		if ( '1' === (string) get_post_meta( $id, self::STUDIO_META, true ) ) {
			return new \WP_Error( 'pc_design_in_use', '这个模板正在下发给店铺，先取消“下发给店铺”再删除。', array( 'status' => 409 ) );
		}
		$r = Templates::trash( $id );
		return is_wp_error( $r ) ? $r : true;
	}

	/** 导出一个模板已发布的四个装修文档 + 引用的媒体。$template 为 0 时用默认模板。 */
	public static function export( int $template = 0 ): array {
		$template = $template > 0 && Templates::exists( $template ) ? $template : Templates::default_id( false );
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
		return array(
			'version'   => 1,
			'key'       => 'm' . $template,
			'name'      => (string) get_the_title( $template ),
			'note'      => (string) get_post_meta( $template, Templates::NOTE_META, true ),
			'documents' => $docs,
			'media'     => $media,
			'digest'    => hash( 'sha256', (string) wp_json_encode( array( $docs, wp_list_pluck( $media, 'sha256' ), get_the_title( $template ) ) ) ),
			'source'    => (string) wp_parse_url( self::public_origin(), PHP_URL_HOST ),
			'origins'   => self::own_origins(),
			'modified'  => (string) ( Templates::modified( $template ) ?? '' ),
		);
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

	/** 把指向工作室店铺的绝对链接（含 www / 不带 www、http / https）换成本店地址。 */
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

	// ---------- 其他店铺：收到系统模板 ----------

	/** @return array|\WP_Error 应用后的状态（$dry_run 时只校验和下载核对，不写入） */
	public static function apply( array $bundle, bool $unused_lock, string $source_host, bool $dry_run = false ) {
		$started = microtime( true );
		$fetched = 0;
		$bytes   = 0;
		$key     = (string) ( $bundle['key'] ?? 'm0' );
		if ( 1 !== ( $bundle['version'] ?? 0 ) || ! preg_match( '/\Am[0-9]{1,12}\z/D', $key ) || ! is_array( $bundle['documents'] ?? null ) || ! is_array( $bundle['media'] ?? null ) || count( $bundle['media'] ) > 64 ) {
			return new \WP_Error( 'pc_design_bundle', '下发的模板内容无效。', array( 'status' => 400 ) );
		}
		foreach ( self::DOCS as $doc ) {
			if ( ! is_array( $bundle['documents'][ $doc ] ?? null ) ) {
				return new \WP_Error( 'pc_design_bundle', '下发的模板缺少' . $doc . '。', array( 'status' => 400 ) );
			}
		}
		$source_host = strtolower( $source_host );
		if ( ! preg_match( '/\A[a-z0-9.-]{3,253}\z/D', $source_host ) ) {
			return new \WP_Error( 'pc_design_source', '模板工作室地址无效。', array( 'status' => 400 ) );
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
				return new \WP_Error( 'pc_design_download', '下载模板媒体失败（' . $tmp->get_error_message() . '），店铺装修没有改动。', array( 'status' => 502 ) );
			}
			if ( filesize( $tmp ) !== $size || ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'pc_design_download', '下载到的媒体和模板工作室不一致，已停止，店铺装修没有改动。', array( 'status' => 502 ) );
			}
			++$fetched;
			$bytes += $size;
			if ( $dry_run ) {
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
			return array( 'dry_run' => true, 'media' => count( $bundle['media'] ), 'downloaded' => $fetched, 'bytes' => $bytes, 'seconds' => round( microtime( true ) - $started, 1 ) );
		}

		$remap = static function ( $v ) use ( &$remap, $ids ) {
			if ( ! is_array( $v ) ) {
				return $v;
			}
			foreach ( $v as $k => $value ) {
				$v[ $k ] = 'attachmentId' === $k && is_numeric( $value ) ? ( $ids[ (int) $value ] ?? null ) : ( is_array( $value ) ? $remap( $value ) : $value );
			}
			return $v;
		};

		$state      = self::state();
		$first_ever = empty( $state['templates'] );
		$name       = trim( sanitize_text_field( (string) ( $bundle['name'] ?? '' ) ) );
		$name       = '' !== $name ? mb_substr( $name, 0, 80 ) : '系统模板';
		$template   = self::system_template_id( $key );
		if ( ! $template ) {
			$template = Templates::create( $name, '平台下发的系统模板，店铺不能修改。' );
			if ( ! $template ) {
				return new \WP_Error( 'pc_design_template', '创建系统模板失败。', array( 'status' => 500 ) );
			}
			update_post_meta( $template, self::SYSTEM_META, $key );
		} else {
			Templates::update_info( $template, $name, '平台下发的系统模板，店铺不能修改。' );
		}
		$origins = array_merge( array( 'https://' . $source_host ), array_map( 'strval', (array) ( $bundle['origins'] ?? array() ) ) );
		foreach ( self::DOCS as $doc ) {
			$id      = Templates::document_id( $template, $doc );
			$modules = Layout::clean_modules( self::rewrite_links( $remap( $bundle['documents'][ $doc ] ), $origins, self::public_origin() ) );
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( Layout::content_from_modules( $modules ) ) ) );
			$published = Layout::publish( $id );
			if ( is_wp_error( $published ) ) {
				return new \WP_Error( 'pc_design_publish', '发布系统模板失败（' . $published->get_error_message() . '）。', array( 'status' => 500 ) );
			}
		}
		// 第一次收到模板：复制一份修订版给店铺自己改，并默认用系统模板。以后不再改店铺的选择。
		if ( empty( $state['revision_created'] ) ) {
			// 新开的店：开店时导入的“店铺默认母版”直接变成修订版（内容换成系统模板），不再多出第三个模板。
			$starter  = (int) get_option( 'vialcove_master_template', 0 );
			$revision = $starter && $starter !== $template && Templates::exists( $starter ) && ! self::is_system( $starter ) ? self::copy_documents( $template, $starter ) : Templates::duplicate( $template );
			if ( $revision ) {
				Templates::update_info( $revision, $name . ' · 修订版', '由系统模板复制，店铺可以随意修改；平台下发不会改动它。' );
				update_post_meta( $revision, self::REVISION_META, $key );
				$state['revision_created'] = true;
			}
		}
		if ( $first_ever ) {
			Templates::set_default( $template );
		}
		$digest                     = (string) ( $bundle['digest'] ?? '' );
		$state['templates'][ $key ] = array( 'id' => $template, 'version' => preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) ? $digest : '', 'applied_at' => time() );
		unset( $state['locked'], $state['template'], $state['version'], $state['applied_at'], $state['source'] );
		update_option( self::OPTION, $state, false );
		if ( class_exists( Page_Cache::class ) && method_exists( Page_Cache::class, 'flush' ) ) {
			Page_Cache::flush();
		}
		return self::summary();
	}

	/** 把一个模板的全部装修文档（草稿和发布快照）复制到另一个模板。@return int 目标模板 */
	private static function copy_documents( int $from_template, int $to_template ): int {
		$keys = self::DOCS;
		foreach ( get_children( array( 'post_parent' => $from_template, 'post_type' => Templates::LAYOUT, 'post_status' => 'any' ) ) as $child ) {
			$keys[] = (string) get_post_meta( $child->ID, Templates::DOC_META, true );
		}
		foreach ( array_unique( array_filter( $keys ) ) as $doc ) {
			$from = Templates::document_id( $from_template, $doc );
			$to   = Templates::document_id( $to_template, $doc );
			if ( ! $from || ! $to ) {
				continue;
			}
			wp_update_post( array( 'ID' => $to, 'post_content' => wp_slash( (string) get_post_field( 'post_content', $from ) ) ) );
			foreach ( get_post_meta( $from ) as $meta => $values ) {
				if ( str_starts_with( (string) $meta, '_pc_' ) && Templates::DOC_META !== $meta ) {
					delete_post_meta( $to, $meta );
					foreach ( $values as $value ) {
						add_post_meta( $to, $meta, wp_slash( maybe_unserialize( $value ) ) );
					}
				}
			}
		}
		return $to_template;
	}

	/** 已收到的某个系统模板在本店的编号。 */
	private static function system_template_id( string $key ): int {
		$found = get_posts( array( 'post_type' => Templates::TEMPLATE, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => self::SYSTEM_META, 'meta_value' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $found ? (int) $found[0] : 0;
	}

	/** 旧接口：不再有整店锁定。 */
	public static function set_lock( bool $lock ): array {
		return self::summary();
	}
}
