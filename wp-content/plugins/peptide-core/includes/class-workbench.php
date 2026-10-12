<?php
/**
 * 后台工作台的数据（BE-04b）：需要员工处理的事项。只读，不修改任何数据。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Workbench {

	/** 列表最多显示的条数；计数仍按全部计算。 */
	public const LIST_LIMIT = 5;

	/**
	 * 待指定批次的订单：处理中（已付款、待发货），且至少有一个商品行还没指定批次。
	 *
	 * @return array{count:int,items:array<int,array{id:int,number:string,date:?string,missing:int}>}
	 */
	public static function orders_missing_batch(): array {
		$ids   = wc_get_orders(
			array(
				'status'  => array( 'wc-processing' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
		$items = array();
		$count = 0;
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$missing = 0;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && ! (int) $item->get_meta( Order_Batches::ITEM_META ) ) {
					++$missing;
				}
			}
			if ( ! $missing ) {
				continue;
			}
			++$count;
			if ( count( $items ) < self::LIST_LIMIT ) {
				$created = $order->get_date_created();
				$items[] = array(
					'id'      => $order->get_id(),
					'number'  => (string) $order->get_order_number(),
					'date'    => $created ? $created->date_i18n( get_option( 'date_format' ) ) : null,
					'missing' => $missing,
				);
			}
		}
		return array(
			'count' => $count,
			'items' => $items,
		);
	}

	/**
	 * 缺少可用报告的公开批次：已发布、公开，且没有已发布的报告（包括标为“报告整理中”的批次）。
	 *
	 * @return array{count:int,items:array<int,array{id:int,batch_no:string,pending:bool}>}
	 */
	public static function batches_without_report(): array {
		$ids   = get_posts(
			array(
				'post_type'      => Post_Types::BATCH,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_key'       => Meta::BATCH_VISIBILITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'public', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$items = array();
		$count = 0;
		foreach ( $ids as $batch_id ) {
			$has_published = false;
			foreach ( Reports::ids_for_batch( (int) $batch_id ) as $report_id ) {
				if ( 'published' === get_post_meta( $report_id, Meta::REPORT_STATUS, true ) ) {
					$has_published = true;
					break;
				}
			}
			if ( $has_published ) {
				continue;
			}
			++$count;
			if ( count( $items ) < self::LIST_LIMIT ) {
				$items[] = array(
					'id'       => (int) $batch_id,
					'batch_no' => (string) get_post_meta( $batch_id, Meta::BATCH_NO, true ),
					'pending'  => Meta::bool( get_post_meta( $batch_id, Meta::BATCH_REPORT_PENDING, true ) ),
				);
			}
		}
		return array(
			'count' => $count,
			'items' => $items,
		);
	}

	/**
	 * 草稿状态的报告数量（已上传但还没发布）。
	 */
	public static function draft_report_count(): int {
		return count(
			get_posts(
				array(
					'post_type'      => Post_Types::REPORT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => Meta::REPORT_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => 'draft', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * 是否启用了 `wp peptide demo commerce` 配置的“测试付款”（不扣钱）。上线前必须关闭。
	 */
	public static function test_payment_enabled(): bool {
		$cod = get_option( 'woocommerce_cod_settings', array() );
		return is_array( $cod )
			&& 'yes' === ( $cod['enabled'] ?? 'no' )
			&& str_starts_with( (string) ( $cod['title'] ?? '' ), 'Test payment' );
	}

	/**
	 * 演示数据的数量（由 demo seed 创建、带 _pc_demo_seed 标记的记录）。
	 */
	public static function demo_record_count(): int {
		return count(
			get_posts(
				array(
					'post_type'      => array( 'product', Post_Types::BATCH, Post_Types::REPORT ),
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => Meta::DEMO_SEED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}
}
