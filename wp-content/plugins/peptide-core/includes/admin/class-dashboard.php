<?php
/**
 * 后台首页的“工作台”面板（BE-04b），并移除用不到的默认面板。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Order_Batches;
use PeptideCore\Post_Types;
use PeptideCore\Settings;
use PeptideCore\Workbench;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	public const WIDGET_ID = 'pc_workbench';

	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'setup' ), 99 );
	}

	public static function can_view(): bool {
		return current_user_can( 'edit_pc_batches' ) || current_user_can( Order_Metabox::CAP );
	}

	public static function setup(): void {
		// 写博客用的“快速草稿”和 WordPress 新闻在这个站点用不上。
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );

		if ( ! self::can_view() ) {
			return;
		}

		/* translators: %s: store brand name */
		$title = sprintf( __( '%s workbench', 'peptide-core' ), Settings::all()['brand_name'] );
		wp_add_dashboard_widget( self::WIDGET_ID, $title, array( self::class, 'render' ), null, null, 'normal', 'high' );
		self::move_to_top();
	}

	/**
	 * 把工作台放到第一列最上面（用户自己拖动过的布局仍以用户为准）。
	 */
	private static function move_to_top(): void {
		global $wp_meta_boxes;
		$normal = $wp_meta_boxes['dashboard']['normal']['high'] ?? array();
		if ( isset( $normal[ self::WIDGET_ID ] ) ) {
			$wp_meta_boxes['dashboard']['normal']['high'] = array( self::WIDGET_ID => $normal[ self::WIDGET_ID ] ) + $normal; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public static function render(): void {
		$orders  = Workbench::orders_missing_batch();
		$ship    = Workbench::orders_awaiting_shipment();
		$batches = Workbench::batches_without_report();
		$drafts  = Workbench::draft_report_count();
		$demo    = Workbench::demo_record_count();
		$demo_on = (bool) Settings::all()['demo_mode'];

		echo '<div class="pc-workbench">';

		// 1. 待指定批次的订单。
		self::section_title( __( 'Orders waiting for batch assignment', 'peptide-core' ), $orders['count'] );
		if ( ! $orders['count'] ) {
			self::muted( __( 'All paid orders have their batches assigned.', 'peptide-core' ) );
		} else {
			echo '<ul>';
			foreach ( $orders['items'] as $o ) {
				$order = wc_get_order( $o['id'] );
				printf(
					'<li><a href="%1$s">#%2$s</a> %3$s — %4$s</li>',
					esc_url( $order ? $order->get_edit_order_url() : '' ),
					esc_html( $o['number'] ),
					esc_html( (string) $o['date'] ),
					/* translators: %d: number of items without a batch */
					esc_html( sprintf( _n( '%d item without batch', '%d items without batch', $o['missing'], 'peptide-core' ), $o['missing'] ) )
				);
			}
			echo '</ul>';
			self::more_link( $orders['count'], self::orders_url( 'wc-processing' ) );
		}

		// 1b. 已付款待发货（SHIP-TRACK-01）。
		if ( null === $ship ) {
			echo '<h3 style="margin-top:1.2em">' . esc_html__( 'Paid orders waiting to ship', 'peptide-core' ) . '</h3>';
			self::muted( __( 'Shipment tracking is off. Run `wp peptide shipping enable` to turn on WooCommerce order fulfillments.', 'peptide-core' ) );
		} else {
			self::section_title( __( 'Paid orders waiting to ship', 'peptide-core' ), $ship['count'] );
			if ( ! $ship['count'] ) {
				self::muted( __( 'All paid orders have been shipped.', 'peptide-core' ) );
			} else {
				echo '<ul>';
				foreach ( $ship['items'] as $o ) {
					$order = wc_get_order( $o['id'] );
					printf(
						'<li><a href="%1$s">#%2$s</a> %3$s%4$s</li>',
						esc_url( $order ? $order->get_edit_order_url() : '' ),
						esc_html( $o['number'] ),
						esc_html( (string) $o['date'] ),
						$o['partial'] ? ' — ' . esc_html__( 'partially shipped', 'peptide-core' ) : ''
					);
				}
				echo '</ul>';
				self::more_link( $ship['count'], self::orders_url( 'wc-processing' ) );
			}
		}

		// 2. 缺少报告的批次。
		self::section_title( __( 'Public batches without a published report', 'peptide-core' ), $batches['count'] );
		if ( ! $batches['count'] ) {
			self::muted( __( 'Every public batch has a published report.', 'peptide-core' ) );
		} else {
			echo '<ul>';
			foreach ( $batches['items'] as $b ) {
				printf(
					'<li><a href="%1$s">%2$s</a>%3$s</li>',
					esc_url( (string) get_edit_post_link( $b['id'] ) ),
					esc_html( $b['batch_no'] ),
					$b['pending'] ? ' — ' . esc_html__( 'marked "report in preparation"', 'peptide-core' ) : ''
				);
			}
			echo '</ul>';
			self::more_link( $batches['count'], admin_url( 'edit.php?post_type=' . Post_Types::BATCH ) );
		}

		// 3. 草稿报告。
		self::section_title( __( 'Draft reports', 'peptide-core' ), $drafts );
		if ( $drafts ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'edit.php?post_type=' . Post_Types::REPORT ) ),
				esc_html__( 'Review and publish', 'peptide-core' )
			);
		} else {
			self::muted( __( 'No draft reports.', 'peptide-core' ) );
		}

		// 4. 演示数据状态。
		echo '<h3 style="margin-top:1.2em">' . esc_html__( 'Demo data', 'peptide-core' ) . '</h3>';
		echo '<p>';
		echo $demo_on
			? esc_html__( 'Demo mode is ON: the storefront shows the demo notice.', 'peptide-core' )
			: esc_html__( 'Demo mode is off.', 'peptide-core' );
		echo ' ';
		/* translators: %d: number of demo records */
		echo esc_html( sprintf( _n( '%d demo record exists.', '%d demo records exist.', $demo, 'peptide-core' ), $demo ) );
		if ( $demo ) {
			echo ' ' . esc_html__( 'Remove them before going live (wp peptide demo purge).', 'peptide-core' );
		}
		echo '</p>';
		if ( Workbench::test_payment_enabled() ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Test payment is enabled: orders are placed without taking any money.', 'peptide-core' ) . '</strong> ';
			echo esc_html__( 'Disable it before going live (wp peptide demo commerce --disable).', 'peptide-core' ) . '</p>';
		}

		// 5. 快捷入口。
		echo '<p class="pc-workbench__links">';
		$links = array(
			admin_url( 'post-new.php?post_type=' . Post_Types::BATCH )  => __( 'Add batch', 'peptide-core' ),
			admin_url( 'post-new.php?post_type=' . Post_Types::REPORT ) => __( 'Add test report', 'peptide-core' ),
			admin_url( 'edit.php?post_type=' . Post_Types::BATCH )      => __( 'All batches', 'peptide-core' ),
			self::orders_url()                                         => __( 'All orders', 'peptide-core' ),
		);
		foreach ( $links as $url => $label ) {
			printf( '<a class="button" href="%1$s" style="margin:0 6px 6px 0">%2$s</a>', esc_url( $url ), esc_html( $label ) );
		}
		echo '</p></div>';
	}

	/**
	 * 订单列表地址：HPOS 与旧版订单页面不同。
	 */
	private static function orders_url( string $status = '' ): string {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$url  = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		if ( '' !== $status ) {
			$url = add_query_arg( $hpos ? 'status' : 'post_status', $status, $url );
		}
		return $url;
	}

	private static function section_title( string $title, int $count ): void {
		printf(
			'<h3 style="margin-top:1.2em">%1$s <span class="count" style="color:%3$s">(%2$d)</span></h3>',
			esc_html( $title ),
			(int) $count,
			$count ? '#b32d2e' : '#646970'
		);
	}

	private static function muted( string $text ): void {
		echo '<p style="color:#646970">' . esc_html( $text ) . '</p>';
	}

	private static function more_link( int $count, string $url ): void {
		if ( $count > Workbench::LIST_LIMIT ) {
			/* translators: %d: total count */
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( sprintf( __( 'View all %d', 'peptide-core' ), $count ) ) );
		}
	}
}
