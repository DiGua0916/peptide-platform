<?php
/**
 * M6 冒烟用例共用的准备（不是用例，不单独运行）。
 *
 * 数据报表的冒烟用例都要一个“已发布、价格大于 0、不是演示商品的简单商品”来下测试订单。
 * 开发站有这样的商品；生产店的脱敏快照里全是多规格商品，用例一上来就以“FAIL fixture product”退出。
 * 找不到时用这里的函数临时建一个：目录里不可见，脚本结束时（包括中途出错退出）彻底删除。
 *
 * 用法（在 wp eval-file 运行的用例里）：
 *   require_once __DIR__ . '/m6-smoke-fixtures.php';   // 和用例放在同一个目录，打包上传到沙盒时要一起带上
 *   $product = pc_m6_smoke_temp_product();
 *
 * 和各冒烟用例一样，只在开发站 / 沙盒运行，不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pc_m6_smoke_temp_product' ) ) {
	/**
	 * 临时建一个简单商品，返回商品对象；建不出来返回 null。
	 */
	function pc_m6_smoke_temp_product(): ?WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'M6 smoke fixture (temporary)' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '25' );
		$id = (int) $product->save();
		if ( $id <= 0 ) {
			return null;
		}
		register_shutdown_function(
			static function () use ( $id ): void {
				$saved = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( $saved ) {
					$saved->delete( true );
				}
			}
		);
		echo "INFO no usable simple product in this store: temporary product #{$id} created, deleted when the script ends\n";
		$saved = wc_get_product( $id );
		return $saved instanceof WC_Product ? $saved : null;
	}
}
