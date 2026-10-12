<?php
/**
 * 前台只读服务：批次与报告按当前用户权限整理成接口返回的形状。
 * REST 接口、Store API 扩展和主题用的 PHP 函数都调用这里，保证三处结果一致。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	private const STATE_RANK = array(
		'none'       => 0,
		'pending'    => 1,
		'restricted' => 2,
		'available'  => 3,
	);

	/**
	 * 按 slug 取前台可见的商品（已发布）。
	 */
	public static function product_by_slug( string $slug ): ?\WC_Product {
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$product = wc_get_product( $post );
		return $product ?: null;
	}

	/**
	 * @return array{id:int,slug:string,name:string,is_demo:bool}
	 */
	public static function product_summary( \WC_Product $product ): array {
		return array(
			'id'      => $product->get_id(),
			'slug'    => $product->get_slug(),
			'name'    => $product->get_name(),
			'is_demo' => Meta::bool( $product->get_meta( Meta::PRODUCT_IS_DEMO ) ),
		);
	}

	/**
	 * 某商品前台可见的批次 ID（已发布且 visibility=public）。
	 *
	 * @return int[]
	 */
	public static function public_batch_ids( int $product_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::BATCH,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => Meta::BATCH_PRODUCT_ID,
							'value' => $product_id,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => Meta::BATCH_VISIBILITY,
							'value' => 'public',
						),
					),
				)
			)
		);
	}

	/**
	 * 按批次号取前台可见的批次 ID，找不到或不可见返回 0（两者不区分）。
	 */
	public static function public_batch_id_by_no( string $batch_no ): int {
		$id = Batches::find_id_by_batch_no( $batch_no );
		if ( ! $id || 'publish' !== get_post_status( $id ) || 'public' !== get_post_meta( $id, Meta::BATCH_VISIBILITY, true ) ) {
			return 0;
		}
		$product_id = (int) get_post_meta( $id, Meta::BATCH_PRODUCT_ID, true );
		return 'publish' === get_post_status( $product_id ) ? $id : 0;
	}

	/**
	 * 批次对象（方案 v1 C3 + v2 C'3）。
	 *
	 * @return array<string,mixed>
	 */
	public static function batch( int $batch_id, int $user_id, bool $include_history = false ): array {
		$reports = array();
		foreach ( Reports::ids_for_batch( $batch_id ) as $report_id ) {
			$item = self::report( $report_id, $user_id, $include_history );
			if ( $item ) {
				$reports[] = $item;
			}
		}

		return array(
			'id'                  => $batch_id,
			'batch_no'            => (string) get_post_meta( $batch_id, Meta::BATCH_NO, true ),
			'product_id'          => (int) get_post_meta( $batch_id, Meta::BATCH_PRODUCT_ID, true ),
			'variation_ids'       => array_map( 'intval', (array) get_post_meta( $batch_id, Meta::BATCH_VARIATION_IDS, true ) ),
			'status'              => (string) get_post_meta( $batch_id, Meta::BATCH_STATUS, true ),
			'is_demo'             => Meta::bool( get_post_meta( $batch_id, Meta::BATCH_IS_DEMO, true ) ),
			'manufactured_on'     => self::nullable( get_post_meta( $batch_id, Meta::BATCH_MANUFACTURED, true ) ),
			'retest_or_expiry_on' => self::nullable( get_post_meta( $batch_id, Meta::BATCH_RETEST_EXPIRY, true ) ),
			'origin_note'         => self::nullable( get_post_meta( $batch_id, Meta::BATCH_ORIGIN_NOTE, true ) ),
			'report_state'        => self::report_state( $batch_id, $reports ),
			'reports'             => $reports,
		);
	}

	/**
	 * 报告对象（方案 v1 C4 + v2 的 access_reason）。不应出现在前台的报告返回 null：
	 * 草稿、已撤回、仅员工可见（对非员工）、未请求历史时的旧版本。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function report( int $report_id, int $user_id, bool $include_history = false ): ?array {
		$status = (string) get_post_meta( $report_id, Meta::REPORT_STATUS, true );
		if ( 'published' !== $status && ! ( $include_history && 'superseded' === $status ) ) {
			return null;
		}

		$access = Access::for_report( $report_id, $user_id );
		if ( ! $access['allowed'] && null === $access['locked_reason'] ) {
			return null;
		}

		$file     = get_post_meta( $report_id, Meta::REPORT_FILE, true );
		$has_file = is_array( $file ) && ! empty( $file['stored'] );
		$can      = $access['allowed'] && $has_file;

		return array(
			'id'            => $report_id,
			'batch_id'      => (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true ),
			'title'         => get_the_title( $report_id ),
			'type'          => (string) get_post_meta( $report_id, Meta::REPORT_TYPE, true ),
			'version'       => (int) get_post_meta( $report_id, Meta::REPORT_VERSION, true ),
			'status'        => $status,
			'is_current'    => 'published' === $status,
			'access'        => (string) get_post_meta( $report_id, Meta::REPORT_ACCESS, true ),
			'is_demo'       => Meta::bool( get_post_meta( $report_id, Meta::REPORT_IS_DEMO, true ) ),
			'issued_on'     => self::nullable( get_post_meta( $report_id, Meta::REPORT_ISSUED_ON, true ) ),
			'issuer_name'   => self::nullable( get_post_meta( $report_id, Meta::REPORT_ISSUER_NAME, true ) ),
			'summary'       => self::nullable( get_post_meta( $report_id, Meta::REPORT_SUMMARY, true ) ),
			'file'          => $can ? array(
				'mime'       => (string) $file['mime'],
				'size_bytes' => (int) $file['size'],
			) : null,
			'can_download'  => $can,
			'download_url'  => $can ? self::download_url( $report_id, $user_id ) : null,
			'access_reason' => $access['access_reason'],
			'locked_reason' => $access['locked_reason'],
			'superseded_by' => self::nullable_int( get_post_meta( $report_id, Meta::REPORT_SUPERSEDED_BY, true ) ),
		);
	}

	/**
	 * 批次的报告状态（方案 v1 C3 表格）。只看当前版本，历史版本不影响状态。
	 *
	 * @param array<int,array<string,mixed>> $reports 已按当前用户整理好的报告列表。
	 */
	public static function report_state( int $batch_id, array $reports ): string {
		$current = array_filter( $reports, static fn( $r ) => $r['is_current'] );
		foreach ( $current as $report ) {
			if ( $report['can_download'] ) {
				return 'available';
			}
		}
		if ( $current ) {
			return 'restricted';
		}
		if ( Meta::bool( get_post_meta( $batch_id, Meta::BATCH_REPORT_PENDING, true ) ) ) {
			return 'pending';
		}
		foreach ( Reports::ids_for_batch( $batch_id ) as $report_id ) {
			if ( 'draft' === get_post_meta( $report_id, Meta::REPORT_STATUS, true ) ) {
				return 'pending';
			}
		}
		return 'none';
	}

	/**
	 * 商品所有公开批次中“最好的”报告状态：available > restricted > pending > none。
	 */
	public static function best_report_state( int $product_id, int $user_id ): string {
		$best = 'none';
		foreach ( self::public_batch_ids( $product_id ) as $batch_id ) {
			$state = self::batch( $batch_id, $user_id )['report_state'];
			if ( self::STATE_RANK[ $state ] > self::STATE_RANK[ $best ] ) {
				$best = $state;
			}
			if ( 'available' === $best ) {
				break;
			}
		}
		return $best;
	}

	/**
	 * 报告下载地址。已登录用户附带 wp_rest nonce：普通链接点击时浏览器不会带 X-WP-Nonce 头，
	 * 没有 nonce 的 Cookie 请求会被 WordPress REST 当作访客处理。
	 */
	public static function download_url( int $report_id, int $user_id ): string {
		$url = rest_url( 'peptide/v1/reports/' . $report_id . '/file' );
		return $user_id > 0 ? add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $url ) : $url;
	}

	private static function nullable( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' === $value ? null : $value;
	}

	private static function nullable_int( $value ): ?int {
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}
}
