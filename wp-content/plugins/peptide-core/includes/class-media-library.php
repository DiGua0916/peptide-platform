<?php
/**
 * 素材管理（照 YY 后台“后台设置 → 素材管理（素材中心）”，用户 2026-10-27）。后台页面见 Admin\Media_Page。
 *
 * YY 的做法：所有素材 / 采集中 / 采集失败 / 采集重试四个标签；左边分组（新建分组）；搜索、上传素材、导出素材；
 * 列：素材、宽高、地址（可复制）、文件大小、素材类型、采集地址；每行可以更改分组。
 *
 * 这里：素材就是 WordPress 媒体库里的文件（商品图片、博客封面等都在里面）。
 * - 分组：附件上的私有分类 pc_media_group。删分组不删素材，只是变成“未分组”。
 * - 采集：填图片地址，服务器下载到媒体库（只下载公网地址，使用 WordPress 的安全下载）。排队处理，
 *   失败的显示原因，可以重试。采集来的素材记着原地址（_pc_source_url）。
 * - 导出：所有素材的文件名、地址、宽高、大小、类型、分组、采集地址（CSV）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Media_Library {

	public const GROUP_TAX    = 'pc_media_group';
	public const QUEUE_OPTION = 'peptide_core_media_queue';
	public const CRON         = 'pc_media_collect';
	public const META_SOURCE  = '_pc_source_url';
	public const CAP          = 'upload_files';

	/** 排队里最多放多少个地址；一次最多提交多少个。 */
	public const MAX_QUEUE  = 200;
	public const MAX_SUBMIT = 50;

	/** 每次处理（提交时、定时任务里）最多下载几个。 */
	private const PER_RUN = 5;

	/** 可以采集的文件类型。 */
	public const COLLECT_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'mp4'          => 'video/mp4',
		'webm'         => 'video/webm',
		'pdf'          => 'application/pdf',
	);

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( self::CRON, array( self::class, 'run_queue' ) );
	}

	public static function register(): void {
		register_taxonomy(
			self::GROUP_TAX,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Media groups', 'peptide-core' ),
					'singular_name' => __( 'Media group', 'peptide-core' ),
				),
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				'query_var'             => false,
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	// ---------- 分组 ----------

	/**
	 * @return array<int,array{id:int,name:string,count:int}>
	 */
	public static function groups(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::GROUP_TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ (int) $t->term_id ] = array(
				'id'    => (int) $t->term_id,
				'name'  => $t->name,
				'count' => (int) $t->count,
			);
		}
		return $out;
	}

	/**
	 * @return int|\WP_Error 新分组的 ID
	 */
	public static function create_group( string $name ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'pc_group_empty', __( 'Enter a group name.', 'peptide-core' ) );
		}
		$made = wp_insert_term( mb_substr( $name, 0, 60 ), self::GROUP_TAX );
		return is_wp_error( $made ) ? $made : (int) $made['term_id'];
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function rename_group( int $id, string $name ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'pc_group_empty', __( 'Enter a group name.', 'peptide-core' ) );
		}
		$done = wp_update_term( $id, self::GROUP_TAX, array( 'name' => mb_substr( $name, 0, 60 ) ) );
		return is_wp_error( $done ) ? $done : true;
	}

	/** 删除分组：素材不删，变成未分组。 */
	public static function delete_group( int $id ): bool {
		return true === wp_delete_term( $id, self::GROUP_TAX );
	}

	/**
	 * 更改分组。$group 为 0 时移出分组。
	 *
	 * @param int[] $ids
	 */
	public static function set_group( array $ids, int $group ): int {
		$done = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			wp_set_object_terms( $id, $group ? array( $group ) : array(), self::GROUP_TAX );
			++$done;
		}
		return $done;
	}

	public static function group_of( int $id ): int {
		$terms = wp_get_object_terms( $id, self::GROUP_TAX, array( 'fields' => 'ids' ) );
		return is_array( $terms ) && $terms ? (int) $terms[0] : 0;
	}

	// ---------- 素材列表 ----------

	/**
	 * @param array{s?:string,group?:int,paged?:int,per_page?:int} $args group：0 = 全部，-1 = 未分组
	 * @return array{0:array<int,array>,1:int} [素材, 总数]
	 */
	public static function query( array $args ): array {
		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) ),
			'paged'          => max( 1, (int) ( $args['paged'] ?? 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$search = trim( (string) ( $args['s'] ?? '' ) );
		if ( '' !== $search ) {
			$q['s'] = $search;
		}
		$group = (int) ( $args['group'] ?? 0 );
		if ( $group > 0 ) {
			$q['tax_query'] = array( array( 'taxonomy' => self::GROUP_TAX, 'terms' => array( $group ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		} elseif ( -1 === $group ) {
			$q['tax_query'] = array( array( 'taxonomy' => self::GROUP_TAX, 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$query = new \WP_Query( $q );
		return array( array_map( array( self::class, 'item' ), $query->posts ), (int) $query->found_posts );
	}

	/**
	 * 一个素材给列表和导出用的资料。
	 */
	public static function item( $post ): array {
		$post = get_post( $post );
		$id   = (int) $post->ID;
		$meta = wp_get_attachment_metadata( $id );
		$file = (string) get_attached_file( $id );
		$size = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( '' !== $file && file_exists( $file ) ? (int) filesize( $file ) : 0 );
		return array(
			'id'     => $id,
			'name'   => '' !== $file ? wp_basename( $file ) : $post->post_title,
			'url'    => (string) wp_get_attachment_url( $id ),
			'thumb'  => wp_attachment_is_image( $id ) ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'size'   => $size,
			'mime'   => (string) $post->post_mime_type,
			'group'  => self::group_of( $id ),
			'source' => (string) get_post_meta( $id, self::META_SOURCE, true ),
			'date'   => (string) $post->post_date,
		);
	}

	/** CSV：所有素材。 */
	public static function export_csv(): string {
		$out    = fopen( 'php://temp', 'r+' );
		$groups = self::groups();
		$safe   = static fn( $v ): string => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v;
		fputcsv( $out, array( 'file', 'url', 'width', 'height', 'bytes', 'type', 'group', 'source_url', 'uploaded' ) );
		$page = 1;
		do {
			[ $items ] = self::query( array( 'paged' => $page, 'per_page' => 200 ) );
			foreach ( $items as $it ) {
				fputcsv( $out, array_map( $safe, array( $it['name'], $it['url'], $it['width'], $it['height'], $it['size'], $it['mime'], $groups[ $it['group'] ]['name'] ?? '', $it['source'], $it['date'] ) ) );
			}
			++$page;
		} while ( count( $items ) === 200 );
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return "\xEF\xBB\xBF" . $csv;
	}

	// ---------- 采集 ----------

	/**
	 * @return array<string,array{id:string,url:string,group:int,status:string,attempts:int,error:string,added:int}>
	 */
	public static function queue(): array {
		$q   = get_option( self::QUEUE_OPTION, array() );
		$out = array();
		foreach ( is_array( $q ) ? $q : array() as $id => $it ) {
			if ( is_array( $it ) && ! empty( $it['url'] ) ) {
				$out[ (string) $id ] = array(
					'id'       => (string) $id,
					'url'      => (string) $it['url'],
					'group'    => (int) ( $it['group'] ?? 0 ),
					'status'   => 'failed' === ( $it['status'] ?? '' ) ? 'failed' : 'pending',
					'attempts' => (int) ( $it['attempts'] ?? 0 ),
					'error'    => (string) ( $it['error'] ?? '' ),
					'added'    => (int) ( $it['added'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	private static function save_queue( array $q ): void {
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/**
	 * 采集队列按标签分：collecting = 采集中（还没试过），retrying = 采集重试（失败后重新排队），failed = 采集失败。
	 */
	public static function queue_tab( array $it ): string {
		if ( 'failed' === $it['status'] ) {
			return 'failed';
		}
		return $it['attempts'] > 0 ? 'retrying' : 'collecting';
	}

	/**
	 * 加入采集队列。
	 *
	 * @param string[] $urls
	 * @return int|\WP_Error 加入的个数
	 */
	public static function enqueue( array $urls, int $group ) {
		$q     = self::queue();
		$known = array_column( $q, 'url' );
		$add   = 0;
		$bad   = array();
		foreach ( array_slice( $urls, 0, self::MAX_SUBMIT ) as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			// 这里只看格式；能不能下载（包括拒绝内网地址）在下载时由 WordPress 的安全下载检查。
			$clean = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( ! preg_match( '#^https?://[^\s/]+#i', $url ) || '' === $clean || strlen( $clean ) > 2000 || '' === (string) wp_parse_url( $clean, PHP_URL_HOST ) ) {
				$bad[] = $url;
				continue;
			}
			if ( in_array( $clean, $known, true ) ) {
				continue;
			}
			if ( count( $q ) >= self::MAX_QUEUE ) {
				/* translators: %d: maximum queue size */
				return new \WP_Error( 'pc_queue_full', sprintf( __( 'At most %d addresses can wait in the queue. Wait for the current ones to finish.', 'peptide-core' ), self::MAX_QUEUE ) );
			}
			$id       = 'q' . wp_generate_password( 10, false );
			$q[ $id ] = array(
				'url'      => $clean,
				'group'    => $group,
				'status'   => 'pending',
				'attempts' => 0,
				'error'    => '',
				'added'    => time(),
			);
			$known[]  = $clean;
			++$add;
		}
		if ( $bad && ! $add ) {
			/* translators: %s: the address */
			return new \WP_Error( 'pc_url_invalid', sprintf( __( '“%s” is not a public http(s) address.', 'peptide-core' ), $bad[0] ) );
		}
		self::save_queue( $q );
		if ( $add ) {
			self::schedule();
		}
		return $add;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/** 定时任务：处理一批，还有就再排一次。 */
	public static function run_queue(): void {
		self::process( self::PER_RUN );
		foreach ( self::queue() as $it ) {
			if ( 'pending' === $it['status'] ) {
				self::schedule();
				break;
			}
		}
	}

	/**
	 * 处理排队中的地址：成功的变成素材并移出队列，失败的标记“采集失败”。
	 *
	 * @return array{done:int,failed:int}
	 */
	public static function process( int $limit ): array {
		$done   = 0;
		$failed = 0;
		foreach ( self::queue() as $id => $it ) {
			if ( $done + $failed >= $limit ) {
				break;
			}
			if ( 'pending' !== $it['status'] ) {
				continue;
			}
			$result = self::collect( $it['url'], $it['group'] );
			$q      = self::queue(); // 下载期间可能有别的改动，重新读一次再写。
			if ( ! isset( $q[ $id ] ) ) {
				continue;
			}
			if ( is_wp_error( $result ) ) {
				$q[ $id ]['status'] = 'failed';
				$q[ $id ]['error']  = mb_substr( $result->get_error_message(), 0, 300 );
				++$q[ $id ]['attempts'];
				++$failed;
			} else {
				unset( $q[ $id ] );
				++$done;
			}
			self::save_queue( $q );
		}
		return array( 'done' => $done, 'failed' => $failed );
	}

	/**
	 * 下载一个地址到媒体库。
	 *
	 * @return int|\WP_Error 素材 ID
	 */
	public static function collect( string $url, int $group = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 ); // 用 wp_safe_remote_get：不会下载内网地址。
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$type = wp_check_filetype( $name, self::COLLECT_TYPES );
		if ( ! $type['type'] ) {
			// 地址里没有扩展名（例如带参数的图片链接）：按文件内容判断图片类型。
			$mime = (string) wp_get_image_mime( $tmp );
			$ext  = array_search( $mime, self::COLLECT_TYPES, true );
			if ( false === $ext ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'pc_collect_type', __( 'Only images (JPG, PNG, GIF, WebP, AVIF), MP4/WebM videos and PDF files can be collected.', 'peptide-core' ) );
			}
			$name = ( '' !== $name ? preg_replace( '/\.[^.]*$/', '', $name ) : 'media-' . gmdate( 'YmdHis' ) ) . '.' . explode( '|', (string) $ext )[0];
		}
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		update_post_meta( (int) $id, self::META_SOURCE, esc_url_raw( $url ) );
		if ( $group ) {
			wp_set_object_terms( (int) $id, array( $group ), self::GROUP_TAX );
		}
		return (int) $id;
	}

	/**
	 * 失败的重新排队（显示在“采集重试”）。
	 *
	 * @param string[] $ids
	 */
	public static function retry( array $ids ): int {
		$q = self::queue();
		$n = 0;
		foreach ( $ids as $id ) {
			if ( isset( $q[ $id ] ) && 'failed' === $q[ $id ]['status'] ) {
				$q[ $id ]['status'] = 'pending';
				++$n;
			}
		}
		self::save_queue( $q );
		if ( $n ) {
			self::schedule();
		}
		return $n;
	}

	/**
	 * 从队列里移除（不再采集）。
	 *
	 * @param string[] $ids
	 */
	public static function dequeue( array $ids ): int {
		$q = self::queue();
		$n = 0;
		foreach ( $ids as $id ) {
			if ( isset( $q[ $id ] ) ) {
				unset( $q[ $id ] );
				++$n;
			}
		}
		self::save_queue( $q );
		return $n;
	}
}
