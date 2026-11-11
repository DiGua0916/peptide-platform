<?php
/**
 * 素材归因（CR）店铺端入口：建表、登记后台页面。
 *
 * 页面（投手和店铺管理员都能看，能力 Staff_Buyer::CAP_VIEW）：
 * - pc-creatives          素材库：登记素材、生成编号和广告名（线 2，Library_Page）
 * - pc-creatives-spend    花费导入：上传 Meta 报表 CSV（线 2，Spend_Page）
 * - pc-creatives-board    素材看板：按素材 / 属性看效果、漏参提醒、检查点导出（线 4，Board_Page）
 * 投手的左侧菜单由 Staff_Buyer::menu_items() 生成（“素材”在“数据”和“广告设置”之间）；
 * 店铺管理员在“营销活动”下面看到“广告素材”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Module {

	public const PAGE_LIBRARY = 'pc-creatives';
	public const PAGE_SPEND   = 'pc-creatives-spend';
	public const PAGE_BOARD   = 'pc-creatives-board';

	/** 后台写操作（admin-post.php 的 action）；投手放行名单在 Staff_Buyer::POST。 */
	public const ACTION_SAVE   = 'pc_creative_save';
	public const ACTION_IMPORT = 'pc_creative_spend_import';
	public const ACTION_EXPORT = 'pc_creative_export';

	/** REST 前缀：peptide/v1/creatives/...（投手放行在 Staff_Buyer::REST_ANY）。 */
	public const REST_BASE = '/creatives';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( Schema::class, 'maybe_install' ) );
		add_action( 'admin_menu', array( self::class, 'add_pages' ), 63 );
		foreach ( array( Library_Page::class, Spend_Page::class, Board_Page::class ) as $page ) {
			if ( method_exists( $page, 'register_hooks' ) ) {
				$page::register_hooks();
			}
		}
		// 联调：素材归位、花费导入（线 2）之后，让每日上报（线 4）重报受影响的日期。
		add_action( 'pc_creatives_spend_imported', array( self::class, 'rereport_days' ), 10, 1 );
		add_action( 'pc_creatives_relinked', array( self::class, 'rereport_ad' ), 10, 1 );
	}

	/** 花费导入之后：重报导入涉及的日期。 */
	public static function rereport_days( $days ): void {
		Daily_Report::rereport( array_values( array_filter( (array) $days, 'is_string' ) ) );
	}

	/** 一条广告归到某个素材之后：重报这条广告带来访客的那些日期。 */
	public static function rereport_ad( $ad_id ): void {
		global $wpdb;
		if ( ! is_string( $ad_id ) || ! Contract::valid_meta_id( $ad_id ) || ! \PeptideCore\Tracking\Schema::installed() ) {
			return;
		}
		$since = ( time() - Daily_Report::MAX_DAYS * 86400 ) * 1000;
		$ms    = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT start_ms FROM ' . \PeptideCore\Tracking\Schema::table( 'visits' ) . ' WHERE utm_content = %s AND start_ms >= %d', $ad_id, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$tz    = Board::timezone();
		$days  = array();
		foreach ( $ms as $m ) {
			$days[ Board::day( (int) $m, $tz ) ] = true;
		}
		Daily_Report::rereport( array_keys( $days ) );
	}

	/**
	 * 管理员：营销活动 → 广告素材（素材库）；花费导入和看板不进菜单，从素材库页面顶部的标签切换。
	 * 投手：菜单由 Staff_Buyer 生成，这里登记的页面只决定能不能打开。
	 */
	public static function add_pages(): void {
		$cap = Staff_Buyer::CAP_VIEW;
		add_submenu_page( 'pc-promotions', '广告素材', '广告素材', $cap, self::PAGE_LIBRARY, array( Library_Page::class, 'render' ) );
		add_submenu_page( '', '花费导入', '花费导入', $cap, self::PAGE_SPEND, array( Spend_Page::class, 'render' ) );
		add_submenu_page( '', '素材看板', '素材看板', $cap, self::PAGE_BOARD, array( Board_Page::class, 'render' ) );
	}

	/** 三个页面顶部共用的标签栏。 */
	public static function tabs( string $current ): void {
		$tabs = array(
			self::PAGE_LIBRARY => '素材库',
			self::PAGE_SPEND   => '花费导入',
			self::PAGE_BOARD   => '素材看板',
		);
		echo '<nav class="nav-tab-wrapper pc-creatives-tabs">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$slug === $current ? ' nav-tab-active' : '',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/** 还没做完的页面先显示这个。 */
	public static function placeholder( string $title, string $current, string $text ): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-core' ), 403 );
		}
		echo '<div class="wrap pc-creatives">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		self::tabs( $current );
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '</div>';
	}
}
