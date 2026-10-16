<?php
/**
 * 订单行与批次的关联（BE-04，方案 v2 C'4）。
 *
 * - 发货时由员工给每个订单行指定实际发出的批次，存为订单行元数据 _pc_batch_id。
 * - 同时在订单上维护一份索引（每个批次一条 _pc_batch_id 订单元数据），用于按批次反查订单。
 * - 订单所有者可以下载自己订单中批次的已发布报告（access_reason = order_owner）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Order_Batches {

	public const ITEM_META  = '_pc_batch_id';
	public const ORDER_META = '_pc_batch_id';

	/** @var array<string,bool> 本次请求内的“用户是否收到过某批次”缓存。 */
	private static array $received = array();

	public static function register_hooks(): void {
		add_filter( 'peptide_core_report_access', array( self::class, 'grant_order_owner' ), 10, 3 );
		add_action( 'woocommerce_order_item_meta_end', array( self::class, 'render_item_batch' ), 10, 4 );
	}

	/**
	 * 某订单行可以选择的批次：同一商品、已发布，且适用于该行的包装规格。
	 * 已经指定的批次即使已售完也保留在列表里。
	 *
	 * @return int[]
	 */
	public static function candidates( \WC_Order_Item_Product $item ): array {
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		$current      = (int) $item->get_meta( self::ITEM_META );
		$ids          = array();

		foreach ( Batches::ids_for_product( $product_id ) as $batch_id ) {
			if ( 'publish' !== get_post_status( $batch_id ) ) {
				continue;
			}
			if ( $batch_id !== $current && 'active' !== get_post_meta( $batch_id, Meta::BATCH_STATUS, true ) ) {
				continue;
			}
			if ( ! self::fits_variation( $batch_id, $variation_id ) ) {
				continue;
			}
			$ids[] = $batch_id;
		}
		return $ids;
	}

	/**
	 * 校验批次能否指定给该订单行。0 表示清除，总是允许。
	 */
	public static function is_valid_for_item( int $batch_id, \WC_Order_Item_Product $item ): bool {
		if ( 0 === $batch_id ) {
			return true;
		}
		return Post_Types::BATCH === get_post_type( $batch_id )
			&& 'publish' === get_post_status( $batch_id )
			&& (int) get_post_meta( $batch_id, Meta::BATCH_PRODUCT_ID, true ) === (int) $item->get_product_id()
			&& self::fits_variation( $batch_id, (int) $item->get_variation_id() );
	}

	/**
	 * 批没限定规格（空列表）表示适用于全部规格。
	 */
	private static function fits_variation( int $batch_id, int $variation_id ): bool {
		$allowed = array_map( 'intval', (array) get_post_meta( $batch_id, Meta::BATCH_VARIATION_IDS, true ) );
		return ! $allowed || ( $variation_id && in_array( $variation_id, $allowed, true ) );
	}

	/**
	 * 批量保存订单行的批次，并重建订单索引、写订单备注。
	 *
	 * @param array<int,int> $assignments 订单行 ID => 批次 ID（0 表示清除）。
	 * @return string[] 校验失败的订单行商品名。
	 */
	public static function save_assignments( \WC_Order $order, array $assignments ): array {
		$errors  = array();
		$changes = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! array_key_exists( $item_id, $assignments ) ) {
				continue;
			}
			$new = (int) $assignments[ $item_id ];
			$old = (int) $item->get_meta( self::ITEM_META );
			if ( $new === $old ) {
				continue;
			}
			if ( ! self::is_valid_for_item( $new, $item ) ) {
				$errors[] = $item->get_name();
				continue;
			}
			if ( $new ) {
				$item->update_meta_data( self::ITEM_META, $new );
			} else {
				$item->delete_meta_data( self::ITEM_META );
			}
			$item->save();
			$changes[] = sprintf(
				/* translators: 1: product name, 2: batch number or "none" */
				__( '%1$s → batch %2$s', 'peptide-core' ),
				$item->get_name(),
				$new ? (string) get_post_meta( $new, Meta::BATCH_NO, true ) : __( '(none)', 'peptide-core' )
			);
		}

		self::rebuild_index( $order );

		if ( $changes ) {
			$order->add_order_note( __( 'Batch assignment updated:', 'peptide-core' ) . "\n" . implode( "\n", $changes ), 0, true );
		}
		return $errors;
	}

	/**
	 * 订单元数据中每个已指定的批次保存一条，便于 wc_get_orders 的 meta_query 反查。
	 */
	public static function rebuild_index( \WC_Order $order ): void {
		$order->delete_meta_data( self::ORDER_META );
		$seen = array();
		foreach ( $order->get_items() as $item ) {
			$batch_id = (int) $item->get_meta( self::ITEM_META );
			if ( $batch_id && ! isset( $seen[ $batch_id ] ) ) {
				$seen[ $batch_id ] = true;
				$order->add_meta_data( self::ORDER_META, $batch_id, false );
			}
		}
		$order->save();
	}

	/**
	 * 是否启用了 WooCommerce 高性能订单存储（HPOS）。
	 */
	public static function hpos_enabled(): bool {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		/**
		 * 是否用 meta_query 缩小订单查询范围。返回 false 时改为逐个订单核对（与旧版订单存储下的行为相同），
		 * 测试用它来验证“查询条件被忽略时也不会放行”。
		 */
		return (bool) apply_filters( 'peptide_core_use_order_meta_query', $hpos );
	}

	/**
	 * 订单上的批次索引中是否包含该批次。
	 */
	private static function order_indexes_batch( \WC_Order $order, int $batch_id ): bool {
		$values = array_map( 'intval', wp_list_pluck( $order->get_meta( self::ORDER_META, false ), 'value' ) );
		return in_array( $batch_id, $values, true );
	}

	/**
	 * 收到过该批次的订单 ID（按订单索引）。
	 *
	 * 安全要点：旧版订单存储（非 HPOS）下 wc_get_orders 会静默忽略 meta_query。为了在任何存储模式下都“查不到就不放行”，
	 * HPOS 下先用 meta_query 缩小范围，再逐个订单核对索引；非 HPOS 下不依赖 meta_query，直接逐个核对。
	 *
	 * @param array<string,mixed> $args 额外的 wc_get_orders 条件（如 customer_id、status）。
	 * @return int[]
	 */
	public static function order_ids_with_batch( int $batch_id, array $args = array() ): array {
		if ( $batch_id <= 0 ) {
			return array();
		}
		$query = $args + array(
			'limit'   => -1,
			'return'  => 'ids',
			'type'    => 'shop_order',
			'status'  => array_keys( wc_get_order_statuses() ),
			'orderby' => 'date',
			'order'   => 'DESC',
		);
		if ( self::hpos_enabled() ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::ORDER_META,
					'value' => (string) $batch_id,
				),
			);
		}
		$ids = array();
		foreach ( wc_get_orders( $query ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && self::order_indexes_batch( $order, $batch_id ) ) {
				$ids[] = $order->get_id();
			}
		}
		return $ids;
	}

	/**
	 * 用户是否在已付款的订单中收到过该批次。
	 * 已付款状态由 WooCommerce 的 wc_get_is_paid_statuses() 决定（默认 processing、completed）。
	 */
	public static function user_received_batch( int $user_id, int $batch_id ): bool {
		if ( $user_id <= 0 || $batch_id <= 0 ) {
			return false;
		}
		$key = $user_id . ':' . $batch_id;
		if ( ! isset( self::$received[ $key ] ) ) {
			self::$received[ $key ] = array() !== self::order_ids_with_batch(
				$batch_id,
				array(
					'customer_id' => $user_id,
					'status'      => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				)
			);
		}
		return self::$received[ $key ];
	}

	/**
	 * 批次反查订单（用于溯源和召回）：收到过这个批次的全部订单，包括已取消或已退款的订单，按下单时间从新到旧。
	 * 返回内容含顾客联系信息，只能给有订单管理权限的员工使用。
	 *
	 * @return array<int,array{order_id:int,number:string,date:string,status:string,customer:string,email:string,phone:string,country:string,user_id:int,items:array<int,array{name:string,quantity:int}>,quantity:int}>
	 */
	public static function orders_for_batch( int $batch_id ): array {
		if ( $batch_id <= 0 ) {
			return array();
		}
		$orders = array_map( 'wc_get_order', self::order_ids_with_batch( $batch_id ) );

		$rows = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$items    = array();
			$quantity = 0;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && $batch_id === (int) $item->get_meta( self::ITEM_META ) ) {
					$items[]   = array(
						'name'     => $item->get_name(),
						'quantity' => (int) $item->get_quantity(),
					);
					$quantity += (int) $item->get_quantity();
				}
			}
			if ( ! $items ) {
				continue; // 索引过期（例如批次后来被改掉）时以订单行为准。
			}
			$created = $order->get_date_created();
			$rows[]  = array(
				'order_id' => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'date'     => $created ? $created->date( 'Y-m-d H:i' ) : '',
				'status'   => wc_get_order_status_name( $order->get_status() ),
				'customer' => trim( $order->get_formatted_billing_full_name() ),
				'email'    => (string) $order->get_billing_email(),
				'phone'    => (string) $order->get_billing_phone(),
				'country'  => (string) ( $order->get_shipping_country() ?: $order->get_billing_country() ),
				'user_id'  => (int) $order->get_customer_id(),
				'items'    => $items,
				'quantity' => $quantity,
			);
		}
		return $rows;
	}

	/**
	 * 把反查结果整理成 CSV 文本（带 BOM，Excel 直接打开不乱码）。
	 * 以 = + - @ 开头的单元格前加单引号，防止在 Excel 中被当成公式执行（CSV 注入）。
	 *
	 * @param array<int,array<string,mixed>> $rows orders_for_batch() 的结果。
	 */
	public static function orders_csv( string $batch_no, array $rows ): string {
		$safe = static function ( $value ): string {
			$value = (string) $value;
			return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
		};
		$out = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'batch_no', 'order_number', 'order_date', 'order_status', 'customer', 'email', 'phone', 'country', 'items', 'quantity' ), ',', '"', '' );
		foreach ( $rows as $r ) {
			$items = implode( '; ', array_map( static fn( $i ) => $i['name'] . ' x' . $i['quantity'], $r['items'] ) );
			fputcsv( $out, array_map( $safe, array( $batch_no, $r['number'], $r['date'], $r['status'], $r['customer'], $r['email'], $r['phone'], $r['country'], $items, $r['quantity'] ) ), ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * 清空请求内缓存（测试用）。
	 */
	public static function flush_cache(): void {
		self::$received = array();
	}

	/**
	 * peptide_core_report_access 过滤器：订单所有者可下载本单批次的报告（仅员工可见的报告除外）。
	 *
	 * @param array{allowed:bool,access_reason:?string,locked_reason:?string} $result
	 * @return array{allowed:bool,access_reason:?string,locked_reason:?string}
	 */
	public static function grant_order_owner( array $result, int $report_id, int $user_id ): array {
		if ( $result['allowed'] || null === $result['locked_reason'] ) {
			return $result;
		}
		$batch_id = (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true );
		if ( self::user_received_batch( $user_id, $batch_id ) ) {
			return array(
				'allowed'       => true,
				'access_reason' => 'order_owner',
				'locked_reason' => null,
			);
		}
		return $result;
	}

	/**
	 * 顾客视角的订单批次（GET /me/orders/{order_number}/batches，方案 v2 D'2）。
	 *
	 * @return array<string,mixed>
	 */
	public static function customer_view( \WC_Order $order, int $user_id ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product   = wc_get_product( $item->get_product_id() );
			$variation = $item->get_variation_id() ? wc_get_product( $item->get_variation_id() ) : null;
			$batch_id  = (int) $item->get_meta( self::ITEM_META );
			$batch     = null;
			if ( $batch_id && Post_Types::BATCH === get_post_type( $batch_id ) ) {
				$full  = Catalog::batch( $batch_id, $user_id );
				$batch = array(
					'batch_no'     => $full['batch_no'],
					'is_demo'      => $full['is_demo'],
					'report_state' => $full['report_state'],
					'reports'      => $full['reports'],
				);
			}
			$items[] = array(
				'product'         => array(
					'slug' => $product ? $product->get_slug() : null,
					'name' => $item->get_name(),
				),
				'variation_label' => $variation ? wc_get_formatted_variation( $variation, true, false ) : null,
				'quantity'        => (int) $item->get_quantity(),
				'batch'           => $batch,
			);
		}
		return array(
			'order_number' => (string) $order->get_order_number(),
			'items'        => $items,
		);
	}

	/**
	 * 在“我的账户 → 订单详情”和订单邮件的商品行下方显示批次号；页面上另附报告下载链接。
	 * 邮件中只显示批次号（邮件里的链接没有登录状态，无法通过权限校验）。
	 */
	public static function render_item_batch( $item_id, $item, $order, $plain_text = false ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$batch_id = (int) $item->get_meta( self::ITEM_META );
		if ( ! $batch_id || Post_Types::BATCH !== get_post_type( $batch_id ) ) {
			return;
		}
		$batch_no = (string) get_post_meta( $batch_id, Meta::BATCH_NO, true );

		if ( $plain_text ) {
			/* translators: %s: batch number */
			echo "\n" . esc_html( sprintf( __( 'Batch: %s', 'peptide-core' ), $batch_no ) );
			return;
		}

		$is_email = did_action( 'woocommerce_email_header' ) > did_action( 'woocommerce_email_footer' );
		echo '<div class="pc-order-batch"><small>';
		/* translators: %s: batch number */
		echo esc_html( sprintf( __( 'Batch: %s', 'peptide-core' ), $batch_no ) );

		$user_id = get_current_user_id();
		if ( ! $is_email && $user_id && (int) $order->get_customer_id() === $user_id ) {
			foreach ( Catalog::batch( $batch_id, $user_id )['reports'] as $report ) {
				if ( $report['can_download'] ) {
					printf(
						' · <a class="pc-order-batch__report" href="%1$s">%2$s</a>',
						esc_url( $report['download_url'] ),
						esc_html( $report['title'] )
					);
				}
			}
		}
		echo '</small></div>';
	}
}
