<?php
/**
 * 促销对照价（PRICE-SALE-01）。
 *
 * 做法：完全使用 WooCommerce 原生的 regular_price / sale_price（不加运行时价格过滤器，不另建定价系统）。
 * - 开启对照价：regular_price = 对照价，sale_price = 当前成交价（成交价不变），不设促销日期，因此不会到期失效。
 * - 每个 simple / variation 记录 _pc_pricing = { base, compare, source, formula, applied_at }，用于识别来源、撤销和防重复执行。
 * - 安全结束：任何人在后台把 sale_price 清空、而 regular_price 仍是本插件写入的对照价时，自动把 regular_price 还原成成交价，
 *   绝不会因为“结束促销”而让售价变成对照价（翻倍）。
 *
 * 价格依据：source = demo-formula 的对照价是按“当前价 × 2 + 0.02”生成的演示数值，**没有真实的历史原价依据**。
 * 美国 FTC 16 CFR 233.1 要求“原价”是真实、近期、持续一段时间的实际售价；因此演示对照价只能用于本地演示，
 * 上线体检（wp peptide doctor）会把它列为必须处理的项目。正式使用时应改用有依据的价格，并把 source 记为 documented。
 *
 * 金额全部用最小货币单位（分）的整数计算。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	public const META          = '_pc_pricing';
	public const SNAPSHOT_META = '_pc_price_snapshot';
	public const SOURCE_DEMO   = 'demo-formula';
	public const SOURCE_REAL   = 'documented';
	public const DEMO_FORMULA  = 'current*2+0.02';

	public static function register_hooks(): void {
		// 商品和规格（variation）保存时触发的钩子名不同，两个都要挂。
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'guard_on_save' ) );
		add_action( 'woocommerce_before_product_variation_object_save', array( self::class, 'guard_on_save' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'snapshot_line_item' ), 10, 4 );
	}

	// ---------------------------------------------------------------- 金额工具

	public static function to_cents( $amount ): int {
		return (int) round( (float) wc_format_decimal( (string) $amount ) * ( 10 ** wc_get_price_decimals() ) );
	}

	public static function from_cents( int $cents ): string {
		return wc_format_decimal( $cents / ( 10 ** wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * 演示对照价：当前价 × 2 + 0.02（用户给的例子：29.98 → 59.98，37.98 → 75.98，44.98 → 89.98）。
	 */
	public static function demo_compare_cents( int $base_cents ): int {
		return $base_cents * 2 + 2;
	}

	/**
	 * 优惠百分比：按实际差价向下取整（59.98 → 29.98 是 50.0167%，显示 50），不会夸大优惠；无效时为 0。
	 */
	public static function percent_off( int $regular_cents, int $price_cents ): int {
		if ( $regular_cents <= 0 || $price_cents <= 0 || $price_cents >= $regular_cents ) {
			return 0;
		}
		return intdiv( ( $regular_cents - $price_cents ) * 100, $regular_cents );
	}

	// ---------------------------------------------------------------- 读取

	/**
	 * @return array{base:string,compare:string,source:string,formula:?string,applied_at:string}|null
	 */
	public static function record( \WC_Product $product ): ?array {
		$meta = $product->get_meta( self::META );
		return is_array( $meta ) && isset( $meta['base'], $meta['compare'], $meta['source'] ) ? $meta : null;
	}

	/**
	 * 某个可购买的 simple / variation 的价格对（同一规格的常规价与成交价配对，不跨规格拼凑）。
	 *
	 * @return array<string,mixed>
	 */
	public static function pair( \WC_Product $product ): array {
		$price   = self::to_cents( $product->get_price() );
		$regular = self::to_cents( $product->get_regular_price() );
		$on_sale = $product->is_on_sale() && $regular > $price && $price > 0;
		$record  = self::record( $product );
		return array(
			'product_id'          => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
			'variation_id'        => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'price'               => (string) $price,
			'regular_price'       => (string) ( $on_sale ? $regular : $price ),
			'on_sale'             => $on_sale,
			'savings'             => (string) ( $on_sale ? $regular - $price : 0 ),
			'percent_off'         => $on_sale ? self::percent_off( $regular, $price ) : 0,
			'compare_source'      => $on_sale ? ( $record['source'] ?? 'manual' ) : null,
			'compare_is_demo'     => $on_sale && self::SOURCE_DEMO === ( $record['source'] ?? '' ),
			'currency_code'       => get_woocommerce_currency(),
			'currency_minor_unit' => wc_get_price_decimals(),
			'tax_display'         => get_option( 'woocommerce_tax_display_shop' ),
		);
	}

	/**
	 * 商品卡片用：simple 取自身；variable 取“当前成交价最低”的那个规格，并返回该规格自己的常规价。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function card_pair( \WC_Product $product ): ?array {
		if ( ! $product->is_type( 'variable' ) ) {
			return '' === $product->get_price() ? null : self::pair( $product );
		}
		$best = null;
		foreach ( $product->get_visible_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || '' === $child->get_price() || ! $child->is_purchasable() ) {
				continue;
			}
			$pair = self::pair( $child );
			if ( null === $best || (int) $pair['price'] < (int) $best['price'] ) {
				$best = $pair;
			}
		}
		return $best;
	}

	/**
	 * 购物车某一行的促销节省（不含优惠券；优惠券由 WooCommerce 原生的 discount 字段单独表示）。
	 *
	 * @param array<string,mixed> $cart_item
	 * @return array<string,mixed>
	 */
	public static function cart_line( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		$qty     = (int) ( $cart_item['quantity'] ?? 0 );
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}
		$pair = self::pair( $product );
		return array(
			'variation_id'     => (int) $pair['variation_id'],
			'quantity'         => $qty,
			'regular_subtotal' => (string) ( (int) $pair['regular_price'] * $qty ),
			'sale_subtotal'    => (string) ( (int) $pair['price'] * $qty ),
			'sale_savings'     => (string) ( (int) $pair['savings'] * $qty ),
			'percent_off'      => $pair['percent_off'],
			'compare_source'   => $pair['compare_source'],
			'compare_is_demo'  => $pair['compare_is_demo'],
		);
	}

	/**
	 * 购物车汇总：各行节省之和。只是说明，不改变 WooCommerce 的应付总额。
	 *
	 * @return array<string,mixed>
	 */
	public static function cart_summary( ?\WC_Cart $cart ): array {
		$regular = 0;
		$sale    = 0;
		$demo    = false;
		if ( $cart ) {
			foreach ( $cart->get_cart() as $item ) {
				$line     = self::cart_line( $item );
				$regular += (int) ( $line['regular_subtotal'] ?? 0 );
				$sale    += (int) ( $line['sale_subtotal'] ?? 0 );
				$demo     = $demo || ! empty( $line['compare_is_demo'] );
			}
		}
		return array(
			'regular_subtotal'    => (string) $regular,
			'sale_subtotal'       => (string) $sale,
			'sale_savings'        => (string) ( $regular - $sale ),
			'compare_is_demo'     => $demo,
			'currency_code'       => get_woocommerce_currency(),
			'currency_minor_unit' => wc_get_price_decimals(),
			'tax_display'         => get_option( 'woocommerce_tax_display_cart' ),
			'note'                => 'Informational only. Coupon discounts are reported separately by WooCommerce (totals.total_discount) and are not included here.',
		);
	}

	// ---------------------------------------------------------------- 写入

	/**
	 * 为一个 simple / variation 开启演示对照价。返回 'applied' / 'skipped:<原因>'。
	 * 只在“没有任何促销、也不是本插件写过的”价格上操作，避免覆盖别人的修改，重复执行不会再翻倍。
	 */
	public static function apply_demo_compare( \WC_Product $product, ?string $expected_price = null ): string {
		if ( $product->is_type( 'variable' ) ) {
			return 'skipped:variable-parent';
		}
		$record = self::record( $product );
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		if ( $record ) {
			return ( self::to_cents( $regular ) === self::to_cents( $record['compare'] ) && self::to_cents( $sale ) === self::to_cents( $record['base'] ) ) ? 'skipped:already-applied' : 'skipped:modified-after-apply';
		}
		if ( '' === $regular ) {
			return 'skipped:no-price';
		}
		if ( '' !== $sale || $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) ) {
			return 'skipped:existing-sale';
		}
		if ( null !== $expected_price && self::to_cents( $regular ) !== self::to_cents( $expected_price ) ) {
			return 'skipped:baseline-mismatch';
		}
		$base    = self::to_cents( $regular );
		$compare = self::demo_compare_cents( $base );
		$product->set_regular_price( self::from_cents( $compare ) );
		$product->set_sale_price( self::from_cents( $base ) );
		$product->update_meta_data(
			self::META,
			array(
				'base'       => self::from_cents( $base ),
				'compare'    => self::from_cents( $compare ),
				'source'     => self::SOURCE_DEMO,
				'formula'    => self::DEMO_FORMULA,
				'applied_at' => gmdate( 'c' ),
			)
		);
		$product->save();
		return 'applied';
	}

	/**
	 * 撤销本插件写入的对照价：regular_price 还原成成交价，清空 sale_price。成交价不变。
	 */
	public static function revert( \WC_Product $product ): string {
		$record = self::record( $product );
		if ( ! $record ) {
			return 'skipped:not-managed';
		}
		if ( self::to_cents( $product->get_regular_price( 'edit' ) ) !== self::to_cents( $record['compare'] ) ) {
			return 'skipped:modified-after-apply';
		}
		$base = '' !== $product->get_sale_price( 'edit' ) ? $product->get_sale_price( 'edit' ) : $record['base'];
		$product->set_regular_price( wc_format_decimal( $base ) );
		$product->set_sale_price( '' );
		$product->delete_meta_data( self::META );
		$product->save();
		return 'reverted';
	}

	/**
	 * 保存商品时的保护（后台、导入、API 都会经过）：
	 * - sale 被清空且 regular 仍是对照价 → 还原 regular 为成交价并移除记录（安全结束促销，不翻倍）；
	 * - regular 被人改成别的值 → 这已不是本插件写入的对照价，移除记录；
	 * - 其他情况（例如只改了成交价）→ 保留记录，并把 base 更新为新的成交价。
	 */
	public static function guard_on_save( \WC_Product $product ): void {
		$record = self::record( $product );
		if ( ! $record || $product->is_type( 'variable' ) ) {
			return;
		}
		$regular = self::to_cents( $product->get_regular_price( 'edit' ) );
		$sale    = $product->get_sale_price( 'edit' );
		$compare = self::to_cents( $record['compare'] );

		if ( '' === $sale && $regular === $compare ) {
			$product->set_regular_price( wc_format_decimal( $record['base'] ) );
			$product->delete_meta_data( self::META );
			return;
		}
		if ( $regular !== $compare ) {
			$product->delete_meta_data( self::META );
			return;
		}
		if ( self::to_cents( $sale ) !== self::to_cents( $record['base'] ) ) {
			$record['base'] = wc_format_decimal( $sale );
			$product->update_meta_data( self::META, $record );
		}
	}

	// ---------------------------------------------------------------- 订单快照

	/**
	 * 下单时为每个商品行保存价格快照（区块结账和经典结账都会触发这个钩子）。以后商品改价不会影响旧订单的展示。
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param array<string,mixed>    $values 购物车行。
	 */
	public static function snapshot_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$line = self::cart_line( is_array( $values ) ? $values : array() );
		if ( ! $line ) {
			return;
		}
		$item->add_meta_data(
			self::SNAPSHOT_META,
			array(
				'regular_unit'        => (string) ( $line['quantity'] ? intdiv( (int) $line['regular_subtotal'], $line['quantity'] ) : 0 ),
				'price_unit'          => (string) ( $line['quantity'] ? intdiv( (int) $line['sale_subtotal'], $line['quantity'] ) : 0 ),
				'quantity'            => $line['quantity'],
				'regular_subtotal'    => $line['regular_subtotal'],
				'sale_subtotal'       => $line['sale_subtotal'],
				'sale_savings'        => $line['sale_savings'],
				'percent_off'         => $line['percent_off'],
				'compare_source'      => $line['compare_source'],
				'compare_is_demo'     => $line['compare_is_demo'],
				'currency_code'       => get_woocommerce_currency(),
				'currency_minor_unit' => wc_get_price_decimals(),
				'tax_display'         => get_option( 'woocommerce_tax_display_cart' ),
				'captured_at'         => gmdate( 'c' ),
			),
			true
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( \WC_Order_Item_Product $item ): ?array {
		$snap = $item->get_meta( self::SNAPSHOT_META );
		return is_array( $snap ) ? $snap : null;
	}
}
