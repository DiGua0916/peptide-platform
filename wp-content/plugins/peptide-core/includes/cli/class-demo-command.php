<?php
/**
 * WP-CLI：导入 / 清除演示数据。
 *
 * 演示数据全部带 is_demo 标记和 _pc_demo_seed 标记，名称以 "Demo" 开头，
 * 报告文件是带水印的说明页，不含任何检测数值、机构名称或资质信息。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Private_Files;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Demo_Command {

	/**
	 * 导入演示商品、批次和报告。
	 *
	 * 已存在演示数据时拒绝执行，先运行 `wp peptide demo purge`。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide demo seed
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( $this->seeded_ids() ) {
			WP_CLI::error( 'Demo data already exists. Run `wp peptide demo purge` first.' );
		}

		$product_a = $this->create_product( 'Demo Peptide A', 'demo-peptide-a', 'DEMO-001' );
		$product_b = $this->create_product( 'Demo Peptide B', 'demo-peptide-b', 'DEMO-002' );

		// A：两个批次，一个公开报告、一个仅登录可下载的报告。
		$batch_a1 = $this->create_batch( 'DEMO-A-2401', $product_a, false );
		$batch_a2 = $this->create_batch( 'DEMO-A-2402', $product_a, false );
		$this->create_report( $batch_a1, 'DEMO-A-2401', 'public' );
		$this->create_report( $batch_a2, 'DEMO-A-2402', 'registered' );

		// B：一个批次，报告整理中（没有报告文件）。
		$this->create_batch( 'DEMO-B-2401', $product_b, true );

		WP_CLI::success( 'Demo data created: 2 products, 3 batches, 2 reports. All records are marked as demo.' );
	}

	/**
	 * 删除所有由 seed 创建的演示数据（商品、批次、报告及报告文件）。
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : 跳过确认。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide demo purge --yes
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function purge( array $args, array $assoc_args ): void {
		$ids    = $this->seeded_ids();
		$orders = $this->seeded_order_ids();
		if ( ! $ids && ! $orders ) {
			WP_CLI::success( 'No demo data found.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Delete %d demo records and %d demo orders permanently?', count( $ids ), count( $orders ) ), $assoc_args );

		// 先删订单，再删商品和批次。
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true );
			}
		}

		foreach ( $ids as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$product->delete( true );
				}
				continue;
			}
			// 报告文件由 Reports::delete_file_with_post 在删除时清理。
			wp_delete_post( $id, true );
		}

		WP_CLI::success( sprintf( 'Deleted %d demo records and %d demo orders.', count( $ids ), count( $orders ) ) );
	}

	/**
	 * 创建一个演示订单（处理中、未指定批次），用于查看后台“批次分配”面板和工作台待办。
	 *
	 * 订单为访客订单，联系人信息是明显的虚构演示数据（example.com 是保留的示例域名），
	 * 不发任何邮件。需要先运行 seed。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide demo order
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function order( array $args, array $assoc_args ): void {
		$product_a = $this->seeded_product( 'demo-peptide-a' );
		$product_b = $this->seeded_product( 'demo-peptide-b' );
		if ( ! $product_a || ! $product_b ) {
			WP_CLI::error( 'Demo products not found. Run `wp peptide demo seed` first.' );
		}

		// 创建演示订单时不触发 WooCommerce 的邮件通知。
		add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );

		$order = wc_create_order();
		$order->add_product( $product_a, 2 );
		$order->add_product( $product_b, 1 );
		$order->set_address(
			array(
				'first_name' => 'Demo',
				'last_name'  => 'Customer',
				'company'    => 'Demo Lab (synthetic)',
				'email'      => 'demo.customer@example.com',
				'address_1'  => '123 Demo Street',
				'city'       => 'Demo City',
				'state'      => 'CA',
				'postcode'   => '90000',
				'country'    => 'US',
			),
			'billing'
		);
		$order->set_address(
			array(
				'first_name' => 'Demo',
				'last_name'  => 'Customer',
				'address_1'  => '123 Demo Street',
				'city'       => 'Demo City',
				'state'      => 'CA',
				'postcode'   => '90000',
				'country'    => 'US',
			),
			'shipping'
		);
		$order->set_payment_method_title( 'Demo payment (no money involved)' );
		$order->calculate_totals();
		$order->update_meta_data( Meta::DEMO_SEED, '1' );
		$order->set_status( 'processing', 'Demo order created by `wp peptide demo order`. Synthetic data, no payment involved.' );
		$order->save();

		WP_CLI::success( sprintf( 'Demo order #%s created (processing, no batches assigned).', $order->get_order_number() ) );
		WP_CLI::log( 'Edit it in the admin: ' . $order->get_edit_order_url() );
	}

	/**
	 * 开发用的测试结账配置：只卖美国、$0 测试运费、“测试付款”（不扣钱，订单直接进入处理中）。
	 *
	 * 用户 2026-10-12 确认：只卖美国；运费先放测试运费；测试付款直接进入处理中。
	 * 真实运费、真实收款确定后要替换；正式上线前必须运行 --disable 关闭测试付款。
	 *
	 * ## OPTIONS
	 *
	 * [--disable]
	 * : 关闭测试付款并删除测试配送区域（销售国家设置保持不变）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide demo commerce
	 *     wp peptide demo commerce --disable
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function commerce( array $args, array $assoc_args ): void {
		$zone = $this->test_zone();

		if ( ! empty( $assoc_args['disable'] ) ) {
			$cod            = get_option( 'woocommerce_cod_settings', array() );
			$cod            = is_array( $cod ) ? $cod : array();
			$cod['enabled'] = 'no';
			update_option( 'woocommerce_cod_settings', $cod );
			if ( $zone ) {
				$zone->delete();
			}
			WP_CLI::success( 'Test payment disabled and test shipping zone removed.' );
			return;
		}

		// 只卖美国，只配送到可销售国家。
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'US' ) );
		update_option( 'woocommerce_ship_to_countries', '' );

		// 美国测试配送区域：一个 $0 的固定运费，名称写明运费待定，不写时效。
		if ( ! $zone ) {
			$zone = new \WC_Shipping_Zone();
			$zone->set_zone_name( self::TEST_ZONE );
			$zone->add_location( 'US', 'country' );
			$zone->save();
		}
		$has_method = false;
		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( 'flat_rate' === $method->id ) {
				$has_method = true;
			}
		}
		if ( ! $has_method ) {
			$instance_id = $zone->add_shipping_method( 'flat_rate' );
			update_option(
				'woocommerce_flat_rate_' . $instance_id . '_settings',
				array(
					'title'      => 'Test shipping – rates to be confirmed',
					'tax_status' => 'none',
					'cost'       => '0',
				)
			);
		}

		// “测试付款”：借用 WooCommerce 自带的货到付款网关，下单后订单直接是“处理中”。
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'            => 'yes',
				'title'              => 'Test payment – no money is charged',
				'description'        => 'Development only. No payment is taken for this order.',
				'instructions'       => 'This is a test order. No payment was taken.',
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'yes',
			)
		);

		WP_CLI::success( 'Test checkout configured: sell to US only, $0 test shipping, test payment (no money charged).' );
		WP_CLI::warning( 'Run `wp peptide demo commerce --disable` before going live.' );
	}

	/** 测试配送区域的名称，用于识别与删除。 */
	private const TEST_ZONE = 'United States (test shipping)';

	private function test_zone(): ?\WC_Shipping_Zone {
		foreach ( \WC_Shipping_Zones::get_zones() as $data ) {
			if ( self::TEST_ZONE === $data['zone_name'] ) {
				return new \WC_Shipping_Zone( (int) $data['id'] );
			}
		}
		return null;
	}

	private function seeded_product( string $slug ): ?\WC_Product {
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( ! $post || '1' !== get_post_meta( $post->ID, Meta::DEMO_SEED, true ) ) {
			return null;
		}
		$product = wc_get_product( $post );
		return $product ?: null;
	}

	/**
	 * @return int[]
	 */
	private function seeded_order_ids(): array {
		$query = array(
			'limit'  => -1,
			'status' => 'any',
			'type'   => 'shop_order',
			'return' => 'ids',
		);
		if ( \PeptideCore\Order_Batches::hpos_enabled() ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Meta::DEMO_SEED,
					'value' => '1',
				),
			);
		}
		// 旧版订单存储会忽略 meta_query，所以无论哪种模式都逐个核对演示标记，绝不删到真实订单。
		$ids = array();
		foreach ( wc_get_orders( $query ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && '1' === (string) $order->get_meta( Meta::DEMO_SEED ) ) {
				$ids[] = (int) $order_id;
			}
		}
		return $ids;
	}

	/**
	 * 只返回带 _pc_demo_seed 标记的记录，不会碰到手工录入的数据。
	 *
	 * @return int[]
	 */
	private function seeded_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => array( 'product', Post_Types::BATCH, Post_Types::REPORT ),
					'post_status'    => 'any',
					'meta_key'       => Meta::DEMO_SEED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				)
			)
		);
	}

	private function create_product( string $name, string $slug, string $sku ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( $slug );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		// 演示价格，仅用于测试下单流程。
		$product->set_regular_price( '10.00' );
		$product->set_short_description( 'Synthetic demonstration record. Not a real product.' );
		$product->update_meta_data( Meta::PRODUCT_IS_DEMO, '1' );
		$product->update_meta_data(
			Meta::PRODUCT_SPECS,
			array(
				array(
					'key'   => 'appearance',
					'label' => 'Appearance',
					'value' => 'Demo value',
					'unit'  => null,
				),
			)
		);
		$product->update_meta_data( Meta::DEMO_SEED, '1' );
		$id = $product->save();
		if ( ! $id ) {
			WP_CLI::error( "Could not create product {$name}." );
		}
		return (int) $id;
	}

	private function create_batch( string $batch_no, int $product_id, bool $report_pending ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => Post_Types::BATCH,
				'post_status' => 'publish',
				'post_title'  => $batch_no,
				'meta_input'  => array(
					Meta::BATCH_NO             => $batch_no,
					Meta::BATCH_PRODUCT_ID     => $product_id,
					Meta::BATCH_VISIBILITY     => 'public',
					Meta::BATCH_STATUS         => 'active',
					Meta::BATCH_IS_DEMO        => true,
					Meta::BATCH_REPORT_PENDING => $report_pending,
					Meta::DEMO_SEED            => '1',
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		return (int) $id;
	}

	private function create_report( int $batch_id, string $batch_no, string $access ): int {
		$pdf  = Demo_Pdf::build(
			array(
				'DEMONSTRATION DOCUMENT',
				'',
				'This file is synthetic demo data generated by the Peptide Core plugin.',
				'It is NOT a test report and contains no test results.',
				'',
				'Batch: ' . $batch_no,
				'Generated: ' . gmdate( 'Y-m-d' ),
			)
		);
		$file = Private_Files::store_contents( $pdf, 'demo-report-' . strtolower( $batch_no ) . '.pdf', 'pdf', 'application/pdf' );
		if ( is_wp_error( $file ) ) {
			WP_CLI::error( $file->get_error_message() );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => Post_Types::REPORT,
				'post_status' => 'publish',
				'post_title'  => 'Demo Report (watermarked) ' . $batch_no,
				'meta_input'  => array(
					Meta::REPORT_BATCH_ID => $batch_id,
					Meta::REPORT_TYPE     => 'coa',
					Meta::REPORT_VERSION  => 1,
					Meta::REPORT_STATUS   => 'published',
					Meta::REPORT_ACCESS   => $access,
					Meta::REPORT_IS_DEMO  => true,
					Meta::REPORT_FILE     => $file,
					Meta::DEMO_SEED       => '1',
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			Private_Files::delete( $file['stored'] );
			WP_CLI::error( $id->get_error_message() );
		}
		return (int) $id;
	}
}
