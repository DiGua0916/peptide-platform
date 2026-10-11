<?php
/**
 * 检测报告的规则：版本号、发布时自动取代旧版本、删除时清理文件。
 *
 * 报告状态存在元数据 _pc_report_status 中（draft / published / superseded / withdrawn），
 * 与 WordPress 的 post_status 无关；前台是否可见只看这个状态和访问级别。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Reports {

	public const TYPES    = array( 'coa', 'hplc', 'ms', 'other' );
	public const STATUSES = array( 'draft', 'published', 'superseded', 'withdrawn' );
	public const ACCESS   = array( 'public', 'registered', 'approved', 'admin' );

	public static function register_hooks(): void {
		add_action( 'before_delete_post', array( self::class, 'delete_file_with_post' ), 10, 2 );
	}

	/**
	 * 同一批次、同一类型的下一个版本号。
	 */
	public static function next_version( int $batch_id, string $type, int $exclude_id = 0 ): int {
		$max = 0;
		foreach ( self::ids_for_batch_type( $batch_id, $type, $exclude_id ) as $id ) {
			$max = max( $max, (int) get_post_meta( $id, Meta::REPORT_VERSION, true ) );
		}
		return $max + 1;
	}

	/**
	 * 把同批次、同类型的其他已发布报告标记为 superseded，并指向新的报告。
	 */
	public static function supersede_previous( int $report_id ): void {
		$batch_id = (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true );
		$type     = (string) get_post_meta( $report_id, Meta::REPORT_TYPE, true );
		if ( $batch_id <= 0 || '' === $type ) {
			return;
		}
		foreach ( self::ids_for_batch_type( $batch_id, $type, $report_id ) as $id ) {
			if ( 'published' === get_post_meta( $id, Meta::REPORT_STATUS, true ) ) {
				update_post_meta( $id, Meta::REPORT_STATUS, 'superseded' );
				update_post_meta( $id, Meta::REPORT_SUPERSEDED_BY, $report_id );
			}
		}
	}

	/**
	 * 某批次的报告 ID（后台用，不做访问过滤）。
	 *
	 * @return int[]
	 */
	public static function ids_for_batch( int $batch_id ): array {
		return self::query_ids(
			array(
				array(
					'key'   => Meta::REPORT_BATCH_ID,
					'value' => $batch_id,
					'type'  => 'NUMERIC',
				),
			)
		);
	}

	/**
	 * @return int[]
	 */
	private static function ids_for_batch_type( int $batch_id, string $type, int $exclude_id ): array {
		return array_values(
			array_diff(
				self::query_ids(
					array(
						'relation' => 'AND',
						array(
							'key'   => Meta::REPORT_BATCH_ID,
							'value' => $batch_id,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => Meta::REPORT_TYPE,
							'value' => $type,
						),
					)
				),
				array( $exclude_id )
			)
		);
	}

	/**
	 * @param array<int|string,mixed> $meta_query
	 * @return int[]
	 */
	private static function query_ids( array $meta_query ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::REPORT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * 报告被永久删除时，一并删除私有目录中的文件。
	 */
	public static function delete_file_with_post( int $post_id, $post = null ): void {
		$post = $post ?: get_post( $post_id );
		if ( ! $post || Post_Types::REPORT !== $post->post_type ) {
			return;
		}
		$file = get_post_meta( $post_id, Meta::REPORT_FILE, true );
		if ( is_array( $file ) && ! empty( $file['stored'] ) ) {
			Private_Files::delete( (string) $file['stored'] );
		}
	}
}
