<?php
/** Product browsing reports for the existing visitor tracking workspace. */
namespace PeptideCore\Admin;

use PeptideCore\Api\Tracking_Controller;

defined( 'ABSPATH' ) || exit;

final class Tracking_Product_Page {

	public const SLUG = 'pc-visitors-products';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 58 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Tracking_Page::SLUG, __( '商品浏览统计', 'peptide-core' ), __( '商品浏览统计', 'peptide-core' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) ) {
			return;
		}
		self::require_access();
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-tracking-products', $url . 'tracking-products.css', array(), $ver( 'tracking-products.css' ) );
		wp_enqueue_script( 'peptide-core-tracking-products', $url . 'tracking-products.js', array( 'wp-api-fetch' ), $ver( 'tracking-products.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'peptide-core-tracking-products',
			'window.pcTrackingProducts = ' . wp_json_encode(
				array(
					'endpoint'   => '/peptide/v1/track/admin/products',
					'pageUrl'    => admin_url( 'admin.php?page=' . self::SLUG ),
					'visitorUrl' => admin_url( 'admin.php?page=' . Tracking_Page::SLUG ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'timezone'   => wp_timezone_string(),
					'today'      => wp_date( 'Y-m-d' ),
					'weekAgo'    => wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ),
					'strings'    => self::strings(),
				)
			) . ';',
			'before'
		);
	}

	private static function strings(): array {
		return array(
			'from'              => __( '开始日期', 'peptide-core' ),
			'to'                => __( '结束日期', 'peptide-core' ),
			'device'            => __( '设备', 'peptide-core' ),
			'allDevices'        => __( '全部设备', 'peptide-core' ),
			'desktop'           => __( '电脑', 'peptide-core' ),
			'mobile'            => __( '手机', 'peptide-core' ),
			'tablet'            => __( '平板', 'peptide-core' ),
			'unknown'           => __( '未知设备', 'peptide-core' ),
			'search'            => __( '商品名称', 'peptide-core' ),
			'searchPlaceholder' => __( '搜索商品名称或编号', 'peptide-core' ),
			'sort'              => __( '排序', 'peptide-core' ),
			'sortViews'         => __( '按浏览次数', 'peptide-core' ),
			'sortViewers'       => __( '按浏览访客数', 'peptide-core' ),
			'sortRepeat'        => __( '按重复浏览人数', 'peptide-core' ),
			'sortReturning'     => __( '按回访浏览人数', 'peptide-core' ),
			'sortLast'          => __( '按最近浏览时间', 'peptide-core' ),
			'apply'             => __( '查询', 'peptide-core' ),
			'refresh'           => __( '刷新', 'peptide-core' ),
			'loading'           => __( '正在读取商品浏览记录…', 'peptide-core' ),
			'error'             => __( '暂时无法读取记录，请重试。若仍失败，请刷新页面确认登录状态。', 'peptide-core' ),
			'forbidden'         => __( '无法访问此报表，请确认登录状态及访客数据查看权限。', 'peptide-core' ),
			'retry'             => __( '重试', 'peptide-core' ),
			'badDates'          => __( '结束日期不能早于开始日期。', 'peptide-core' ),
			'products'          => __( '被浏览商品数', 'peptide-core' ),
			'views'             => __( '浏览次数', 'peptide-core' ),
			'viewers'           => __( '浏览访客数', 'peptide-core' ),
			'repeat'            => __( '重复浏览人数', 'peptide-core' ),
			'returning'         => __( '回访浏览人数', 'peptide-core' ),
			'lastView'          => __( '最近浏览', 'peptide-core' ),
			'product'           => __( '商品', 'peptide-core' ),
			'productId'         => __( '商品编号', 'peptide-core' ),
			'actions'           => __( '操作', 'peptide-core' ),
			'viewVisitors'      => __( '查看访客', 'peptide-core' ),
			'back'              => __( '返回商品排行', 'peptide-core' ),
			'ranking'           => __( '商品浏览排行', 'peptide-core' ),
			'visitorDetail'     => __( '商品浏览访客', 'peptide-core' ),
			'visitor'           => __( '访客', 'peptide-core' ),
			'accountType'       => __( '账号类型', 'peptide-core' ),
			'registered'        => __( '注册访客', 'peptide-core' ),
			'guest'             => __( '匿名访客', 'peptide-core' ),
			'visits'            => __( '浏览此商品的访问次数', 'peptide-core' ),
			'viewJourney'       => __( '查看访问记录', 'peptide-core' ),
			'none'              => __( '此筛选范围内还没有商品浏览记录。可以调整日期、设备或商品名称后查询。', 'peptide-core' ),
			'noVisitors'        => __( '此商品在当前筛选范围内还没有浏览访客。可以调整日期或设备后查询。', 'peptide-core' ),
			'noTime'            => __( '尚无记录', 'peptide-core' ),
			'previous'          => __( '上一页', 'peptide-core' ),
			'next'              => __( '下一页', 'peptide-core' ),
			'page'              => __( '页', 'peptide-core' ),
			'totalProducts'     => __( '件商品', 'peptide-core' ),
			'totalVisitors'     => __( '位访客', 'peptide-core' ),
			'timezone'          => __( '时间按店铺时区显示', 'peptide-core' ),
			'collecting'        => __( '访客采集中', 'peptide-core' ),
			'notCollecting'     => __( '访客采集已关闭，仍可查询已有记录', 'peptide-core' ),
			'unavailable'       => __( '追踪记录尚不可用', 'peptide-core' ),
			'privacy'           => __( '此处显示浏览行为，不代表访客已购买。账号类型及名称只表示当前绑定的账号，不代表每次历史浏览时使用的账号。', 'peptide-core' ),
			'rulesTitle'        => __( '统计口径', 'peptide-core' ),
			'rules'             => __( '商品按主商品汇总，规格归入所属主商品。只统计已采集的商品浏览事件，不叠加页面访问或商品曝光。浏览访客数按匿名访客编号及浏览器去重，同一客户换设备可能分别计算。重复浏览人数：所选范围内，同一访客浏览同一商品至少 2 次。回访浏览人数：所选范围内，同一访客在至少 2 次访问中浏览同一商品。刷新会增加浏览次数，同一事件重传不会重复计数。历史缺少记录不回填。', 'peptide-core' ),
		);
	}

	public static function render(): void {
		self::require_access();
		?>
		<div class="wrap pc-track-products">
			<h1 class="wp-heading-inline"><?php esc_html_e( '商品浏览统计', 'peptide-core' ); ?></h1>
			<p class="pc-tp-intro"><?php esc_html_e( '查看哪些商品被经常浏览，并打开对应访客的访问记录。', 'peptide-core' ); ?></p>
			<div id="pc-tracking-products" aria-busy="true"><p role="status"><?php esc_html_e( '正在读取商品浏览记录…', 'peptide-core' ); ?></p></div>
			<noscript><p><?php esc_html_e( '请启用 JavaScript 后查询商品浏览统计。', 'peptide-core' ); ?></p></noscript>
		</div>
		<?php
	}

	private static function require_access(): void {
		if ( true !== Tracking_Controller::can_view_products() ) {
			wp_die( esc_html__( '此账号没有访客数据权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
	}
}
