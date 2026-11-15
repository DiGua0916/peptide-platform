<?php
/**
 * 投手版后台（M9，PRD 6.2）：角色 pc_media_buyer。“投手”= 负责投广告的内部员工，不是顾客。
 *
 * 投手的菜单：首页、订单（只读）、弃单、数据、营销活动（只读）、广告数据追踪（广告设置、素材库）、店铺信息。其余菜单不显示，直接打开地址返回 403。
 * “营销活动”（平台所有者 2026-11-13 定）：投手可以看只读的“当前在跑的活动”，不能新建、编辑、启停、删除。
 * 只读页面归 F2（Admin\Marketing::read_only_gate()），这里只管菜单入口和放行“不带任何操作参数的 GET”；
 * F2 的只读代码不在（或它认为这个账号能改）时，三个营销页面对投手仍然整页 403。
 * “素材”（素材库、花费导入、素材看板）由 Creatives\Module 提供，2026-11-11 加入；2026-11-12 和广告设置合成“广告数据追踪”。
 * 管理员（包括从总后台一键登录）不受影响，仍然看到全部菜单。
 *
 * 做法：
 * - 按账号开关。给销售账号（pc_staff）加上 pc_media_buyer 这个角色就是投手版，去掉就恢复原来按模块勾选的权限。
 *   原来的角色和勾选的模块都不动，所以总后台的“销售账号”列表、专属登录入口、停用开关照常工作。
 *   默认不改任何现有账号；开关在“员工管理”页面和 `wp peptide buyer on|off <账号>`。
 * - 角色本身只存 read、view_admin_dashboard 和只读的 pc_view_campaigns（role_caps()）。页面需要的能力（多数店铺页面共用 manage_woocommerce）只在
 *   “这次请求是允许的页面或接口”时通过 user_has_cap 临时给（classify()、rest_scope()，能力清单在 Capabilities::BUYER_SCOPES）。
 *   在其他地址上投手没有任何店铺能力，WordPress、WooCommerce 和插件自己的权限检查都会拒绝。
 * - 后台页面、admin-ajax、admin-post 另有一道白名单（gate_page()、gate_action()）：不在名单里的直接 403。
 * - 订单只读：只允许用 GET 打开列表和单张订单；保存、批量操作、退款等写请求都不在名单里。按钮的隐藏在 Admin_Menus。
 * - 需要先改密码的账号（用户资料 pc_must_change_password）只能打开开店向导，见 Admin\Onboarding。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Staff_Buyer {

	public const ROLE           = 'pc_media_buyer';
	/** 投手版页面（首页、广告设置、店铺信息、向导）的能力：投手和管理员都有。 */
	public const CAP_VIEW       = 'pc_view_buyer_admin';
	public const META_SINCE     = 'pc_media_buyer_since';
	public const META_MUST_PASS = 'pc_must_change_password';
	/** 值为 '1' 时，新建的销售账号直接是投手版（新店用；现有店铺默认没有这个选项）。 */
	public const OPTION_DEFAULT = 'pc_media_buyer_default';

	/** 其他类用 Staff::granted() 判断模块时，投手相当于勾了这两个模块。 */
	public const MODULES = array( 'orders', 'data' );

	public const PAGE_HOME   = 'pc-buyer-home';
	public const PAGE_ADS    = 'pc-buyer-ads';
	public const PAGE_STORE  = 'pc-store-info';
	public const PAGE_WIZARD = 'pc-onboarding';
	/** 订单详情页（Admin\Order_Detail_Page，2026-11-11 起订单列表和原生编辑页都转到这里）：投手只读。 */
	public const PAGE_ORDER  = 'pc-order';
	/** 导出订单（Admin\Buyer_Order_Export，ST-03）。只读，走 admin-post.php 下载。 */
	public const ACTION_EXPORT = 'pc_buyer_orders_export';

	/** F2（M4）的三个营销页面：满减、优惠码、运费折扣。投手只能看 F2 的只读列表。只认这三个地址，带同样前缀的其他页面不算。 */
	public const CAMPAIGN_PAGES = array( 'pc-promotions', 'pc-coupons', 'pc-shipping-discounts' );
	/** F2 定的只读权限（Admin\Marketing::VIEW_CAP）。写字面值：F2 的代码合进来之前那个常量还不存在。 */
	public const CAP_CAMPAIGNS  = 'pc_view_campaigns';

	/**
	 * 按店开关（值 on 才生效，默认关）：投手后台在线上版本之外新加、会改变页面内容的几样——订单列表上方的导出、
	 * 订单列表的“来源广告 / 运单号 / 退款状态”三列和详情页顶部的退款提示、店铺信息里的“显示客服按钮”。
	 * 平台所有者 2026-11-13 定“优化，不改功能”：这些算功能变化，代码随模块发布，开不开另由所有者定。
	 */
	public const OPTION_EXTRAS     = 'pc_buyer_extras';
	/** 按店开关（值 on 才生效，默认关）：投手不加载 WordPress 的命令面板（⌘K）。等所有者定性（算 bug 还是算功能调整）。 */
	public const OPTION_NO_PALETTE = 'pc_buyer_no_palette';

	public const ACTION_PASSWORD = 'pc_buyer_password';
	public const ACTION_STORE    = 'pc_buyer_store_save';
	public const ACTION_ADS      = 'pc_buyer_ads_save';

	/** admin-post.php 里投手能提交的表单（都是投手版页面自己的，含导出订单、素材登记、花费导入、看板导出）。 */
	private const POST = array( self::ACTION_PASSWORD, self::ACTION_STORE, self::ACTION_ADS, self::ACTION_EXPORT, 'pc_creative_save', 'pc_creative_spend_import', 'pc_creative_export' ); // 后三个和 Creatives\Module::ACTION_* 一致。

	/** admin-ajax.php 里投手能用的动作 => 需要的能力范围（空 = 不需要店铺能力）。 */
	private const AJAX = array(
		'heartbeat'                         => '',
		'closed-postboxes'                  => '',
		'hidden-columns'                    => '',
		'meta-box-order'                    => '',
		'rest-nonce'                        => '', // 页面开久了以后刷新接口用的 nonce。
		'woocommerce_get_order_details'     => 'orders', // 订单列表里的预览。
		'woocommerce_json_search_customers' => 'orders', // 订单列表按顾客筛选。
	);

	/** 只读（GET）才给能力的接口前缀。 */
	private const REST_READ = array( '/peptide/v1/data', '/peptide/v1/overview' );

	/** 读写都给能力的接口前缀：实时访客（含 AI 分析）、像素验证、投放链接、弃单“已联系”。 */
	private const REST_ANY = array( '/peptide/v1/track/admin', '/peptide/v1/admin/pixel-test', '/peptide/v1/admin/ad-link', '/peptide/v1/admin/recovery', '/peptide/v1/admin/buyer-home' );

	/** @var array<int,array{0:string,1:string}> 正在处理的 REST 请求（路由、方法）；页面预加载会嵌套。 */
	private static array $rest = array();

	/** @var array<string,string>|null 这次请求的要素。 */
	private static ?array $request = null;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'ensure_role' ), 5 );
		add_filter( 'user_has_cap', array( self::class, 'scoped_caps' ), 30, 4 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'rest_enter' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'rest_leave' ), PHP_INT_MAX, 3 );
		add_action( 'init', array( self::class, 'gate_page' ), 3 );
		add_action( 'admin_init', array( self::class, 'gate_action' ), 0 );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 30, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'role_name' ), 10, 4 );
		add_action( 'admin_head', array( self::class, 'build_menu' ), 9999 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 9999 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 9999 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'no_command_palette' ), 0 );
		// 这四个页面不挂在菜单下（隐藏页面），WordPress 给不出标题：浏览器标签和面包屑用页面自己的名字。
		foreach ( array( self::PAGE_HOME => '首页', self::PAGE_ADS => '广告设置', self::PAGE_STORE => '店铺信息', self::PAGE_WIZARD => '开店向导' ) as $slug => $label ) {
			add_action(
				'load-admin_page_' . $slug,
				static function () use ( $label ): void {
					$GLOBALS['title'] = $label; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			);
		}
		add_action( 'current_screen', array( self::class, 'campaigns_title' ) );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		add_filter( 'editable_roles', array( self::class, 'hide_role' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide buyer', Staff_Buyer_Cli::class );
		}
		// 投手版自己的页面（首页、向导和广告设置）。店铺信息在 Admin\Store_Settings 里。
		foreach ( array( Admin\Buyer_Home::class, Admin\Onboarding::class, Admin\Buyer_Order_Export::class ) as $page ) {
			if ( class_exists( $page ) ) {
				$page::register_hooks();
			}
		}
	}

	/** 向总后台声明这家店的代码支持投手版后台和开店向导（新旧版本混跑时用，约定见 F1 的 pc_hq_capabilities）。 */
	public static function capabilities( $caps ) {
		return is_array( $caps ) ? $caps + array( 'm9.media_buyer' => '1', 'm9.onboarding' => '1', 'm9.buyer_extras' => '1', 'm9.buyer_no_palette' => '1' ) : $caps;
	}

	/** 投手后台的新增内容开着没有（OPTION_EXTRAS，默认关）。 */
	public static function extras(): bool {
		return (bool) apply_filters( 'pc_buyer_extras_enabled', 'on' === (string) get_option( self::OPTION_EXTRAS, 'off' ) );
	}

	/** 投手不加载命令面板这一条开着没有（OPTION_NO_PALETTE，默认关 = 和线上一样加载）。 */
	public static function hide_palette(): bool {
		return (bool) apply_filters( 'pc_buyer_no_palette_enabled', 'on' === (string) get_option( self::OPTION_NO_PALETTE, 'off' ) );
	}

	// ---------- 角色和开关 ----------

	/**
	 * 角色上存的能力，只有这三项。pc_view_campaigns 是 F2 约定的只读权限：有它只能看“当前在跑的活动”。
	 * 能改东西的权限（edit_shop_coupons、manage_woocommerce 等）不在这里，也永远不加到角色上。
	 *
	 * @return array<string,bool>
	 */
	public static function role_caps(): array {
		return array(
			'read'                 => true,
			'view_admin_dashboard' => true, // WooCommerce 只让有这个能力的人进后台。
			self::CAP_CAMPAIGNS    => true,
		);
	}

	public static function ensure_role(): void {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'Media buyer', self::role_caps() );
			return;
		}
		// 2026-11-13 之前建好角色的店：补上只读权限。每家店只写一次（之后 has_cap 为真，不再写）。
		if ( ! $role->has_cap( self::CAP_CAMPAIGNS ) ) {
			$role->add_cap( self::CAP_CAMPAIGNS );
		}
	}

	/** 角色名存的是英文，角色下拉框里显示“投手”。 */
	public static function role_name( $translation, $text, $context, $domain ) {
		return 'User role' === $context && 'Media buyer' === $text && 'default' === $domain ? '投手' : $translation;
	}

	/**
	 * WordPress 自带的角色下拉框里不出现“投手”：只带这个角色、没有 pc_staff 的账号不在员工管理和总后台里，
	 * 也不受专属登录入口和停用开关管。投手版只能通过 set() 开关。
	 */
	public static function hide_role( $roles ) {
		if ( is_array( $roles ) ) {
			unset( $roles[ self::ROLE ] );
		}
		return $roles;
	}

	/** 是不是投手版账号。管理员永远不算。 */
	public static function is_buyer( $user ): bool {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		return $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! in_array( 'administrator', (array) $user->roles, true );
	}

	/** 当前登录的人是不是投手。 */
	public static function current(): bool {
		$id = get_current_user_id();
		return $id > 0 && self::is_buyer( $id );
	}

	/** 能不能打开投手版页面：投手本人，或者管理员（排查问题用）。 */
	public static function can_view(): bool {
		return self::current() || current_user_can( 'manage_options' );
	}

	/** 能不能用投手版的数据和接口：管理员，或者已经改过密码的投手（没改密码之前只能打开向导）。 */
	public static function can_use(): bool {
		return current_user_can( 'manage_options' ) || ( self::current() && ! self::must_change( get_current_user_id() ) );
	}

	/**
	 * 把销售账号切到投手版，或者改回按模块勾选的权限。只加、去掉 pc_media_buyer 这个角色，原角色和模块不动。
	 * 调用的地方（员工管理页面、命令行、总后台接口）自己负责检查操作人的权限。
	 *
	 * @return true|\WP_Error
	 */
	public static function set( int $user_id, bool $on, bool $must_change_password = false ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'pc_buyer_missing', '账号不存在。' );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return new \WP_Error( 'pc_buyer_admin', '管理员账号不能切到投手版。' );
		}
		if ( $on && ! Staff::is_staff( $user ) ) {
			return new \WP_Error( 'pc_buyer_not_staff', '只有销售账号可以切到投手版。' );
		}
		$was = self::is_buyer( $user );
		if ( $on ) {
			self::ensure_role();
			$user->add_role( self::ROLE );
			if ( ! $was ) {
				update_user_meta( $user_id, self::META_SINCE, array( 'at' => time(), 'by' => get_current_user_id() ) );
			}
			if ( $must_change_password ) {
				self::set_must_change( $user_id, true );
			}
		} else {
			$user->remove_role( self::ROLE );
			delete_user_meta( $user_id, self::META_SINCE );
			delete_user_meta( $user_id, self::META_MUST_PASS );
		}
		Staff::flush_cache();
		if ( $was !== $on ) {
			// 用“状态”这个事件：同一次保存里如果还改了勾选的模块（事件 permissions），10 秒内同类事件只记一条，两条都要留下。
			Activity_Log::log( 'staff', 'status', array( 'type' => 'user', 'id' => $user_id, 'label' => (string) $user->user_login ), array( 'from' => $was ? '投手版' : '按模块', 'to' => $on ? '投手版' : '按模块' ) );
		}
		return true;
	}

	/** 新建的销售账号是不是直接用投手版。 */
	public static function default_on(): bool {
		return '1' === (string) get_option( self::OPTION_DEFAULT, '' );
	}

	/** 下次登录是不是要先改密码（ST-01 第 1 步）。 */
	public static function must_change( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::META_MUST_PASS, true );
	}

	public static function set_must_change( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::META_MUST_PASS, '1' );
		} else {
			delete_user_meta( $user_id, self::META_MUST_PASS );
		}
	}

	// ---------- 地址 ----------

	public static function page_url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function home_url(): string {
		return self::page_url( self::PAGE_HOME );
	}

	public static function login_redirect( $redirect, $requested, $user ) {
		return $user instanceof \WP_User && self::is_buyer( $user ) ? self::home_url() : $redirect;
	}

	// ---------- 投手版页面共用 ----------

	/** 这次打开的是不是投手版自己的页面（首页、广告设置、店铺信息、向导）。 */
	public static function on_own_page(): bool {
		$r = self::request();
		return 'page' === $r['doing'] && 'admin.php' === $r['file'] && in_array( $r['page'], array( self::PAGE_HOME, self::PAGE_ADS, self::PAGE_STORE, self::PAGE_WIZARD ), true );
	}

	/**
	 * WordPress 的命令面板（⌘K）会把这次请求里登记的全部后台菜单连名字带地址写进页面，包括投手看不到的页面
	 * （客户、邮件、店铺设置……）。它在 admin_enqueue_scripts 时读菜单，比这里重建菜单（admin_head）早。
	 * 投手不加载命令面板；顶部工具条上的入口在 admin_bar() 里去掉。只在开关 OPTION_NO_PALETTE 打开时。
	 */
	public static function no_command_palette(): void {
		if ( self::current() && self::hide_palette() ) {
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );
		}
	}

	/** 投手版页面的样式和脚本（一份，四个页面共用）。 */
	public static function enqueue(): void {
		if ( ! self::on_own_page() ) {
			return;
		}
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-buyer-admin', plugins_url( 'assets/buyer-admin.css', PEPTIDE_CORE_FILE ), array(), $ver( 'buyer-admin.css' ) );
		wp_enqueue_script( 'peptide-core-buyer-admin', plugins_url( 'assets/buyer-admin.js', PEPTIDE_CORE_FILE ), array(), $ver( 'buyer-admin.js' ), true );
		wp_localize_script(
			'peptide-core-buyer-admin',
			'pcBuyerAdmin',
			array(
				'rest'  => esc_url_raw( rest_url( 'peptide/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/** 表单提交后的一次性提示（成功 / 失败），下一个页面显示。 */
	public static function flash( string $type, string $text ): void {
		set_transient( 'pc_buyer_flash_' . get_current_user_id(), array( 'error' === $type ? 'error' : 'success', $text ), 120 );
	}

	public static function render_flash(): void {
		$key = 'pc_buyer_flash_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && 2 === count( $msg ) ) {
			delete_transient( $key );
			echo '<div class="pc-by-flash is-' . esc_attr( (string) $msg[0] ) . '" role="' . ( 'error' === $msg[0] ? 'alert' : 'status' ) . '">' . esc_html( (string) $msg[1] ) . '</div>';
		}
	}

	// ---------- 这次请求能做什么 ----------

	/**
	 * 这次请求的要素：doing（front / page / ajax / post）、file（后台脚本名）、method、page、action、action2、
	 * writes（带了会直接执行写操作的参数时为 '1'）。
	 *
	 * @return array<string,string>
	 */
	public static function request(): array {
		if ( null !== self::$request ) {
			return self::$request;
		}
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只用来判断地址属于哪个页面，不执行任何操作。
		$text = static function ( $value ): string {
			return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		};
		$file  = is_string( $pagenow ) ? $pagenow : '';
		$doing = 'front';
		if ( is_admin() ) {
			$doing = ( 'admin-ajax.php' === $file || wp_doing_ajax() ) ? 'ajax' : ( 'admin-post.php' === $file ? 'post' : 'page' );
		}
		self::$request = array(
			'doing'   => $doing,
			'file'    => $file,
			'method'  => strtoupper( $text( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
			'page'    => $text( $_GET['page'] ?? '' ),
			'action'  => $text( $_REQUEST['action'] ?? '' ),
			'action2' => $text( $_REQUEST['action2'] ?? '' ),
			// 订单页上不靠 action 也会执行写操作的参数：清空回收站（delete_all）、接管编辑锁（claim-lock）。
			'writes'  => ( ! empty( $_REQUEST['delete_all'] ) || ! empty( $_REQUEST['claim-lock'] ) ) ? '1' : '',
			// 营销页面上打开新建 / 编辑表单的参数（Admin\Marketing::read_only_gate() 看的是同样两个）。
			'edits'   => ( isset( $_REQUEST['new'] ) || isset( $_REQUEST['edit'] ) ) ? '1' : '',
			'campaigns' => '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// 先把上面的结果存好再问 F2：它的判断要查当前用户的能力，会回到 scoped_caps() 再读一次这里。
		self::$request['campaigns'] = self::campaigns_read_only() ? '1' : '';
		return self::$request;
	}

	/** F2 的只读“当前在跑的活动”在不在这份代码里。 */
	public static function campaigns_ready(): bool {
		$marketing = Admin\Marketing::class;
		return class_exists( $marketing ) && is_callable( array( $marketing, 'can_view' ) ) && is_callable( array( $marketing, 'can_manage' ) ) && is_callable( array( $marketing, 'read_only_gate' ) );
	}

	/**
	 * 当前登录的人打开营销页面时，F2 给的是不是只读列表。F2 的代码不在，或者它认为这个人能改（比如有人用过滤器
	 * pc_marketing_can_manage 放开了），都返回 false：投手的拦截仍然整页拒绝，不会因为这里放行而露出可编辑的页面。
	 */
	public static function campaigns_read_only(): bool {
		return self::campaigns_ready() && ! Admin\Marketing::can_manage();
	}

	/**
	 * 投手打开三个营销页面时，页面顶部和浏览器标签用投手菜单里的名字。这三个页面登记的名字是给能改的人看的
	 * （满减活动 / 优惠码 / 运费折扣），投手看到的三个页面都是同一张只读列表。
	 */
	public static function campaigns_title(): void {
		if ( self::current() && in_array( self::request()['page'], self::CAMPAIGN_PAGES, true ) ) {
			$GLOBALS['title'] = self::menu_items()['campaigns']['label']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/** 投手菜单里要不要显示“营销活动”：F2 说这个人能看（Marketing::can_view()），并且看到的是只读列表。 */
	public static function campaigns_visible(): bool {
		return ! empty( self::request()['campaigns'] ) && (bool) Admin\Marketing::can_view();
	}

	/**
	 * 投手的一次请求怎么处理（不读数据库，测试里直接调用）。
	 *
	 * $r：request() 的结果；ajax / post 另带 nopriv（这个动作本来就对未登录的人开放）。
	 * 返回 verdict（allow 放行 / deny 返回 403 / redirect 转到 to 这个页面）和 scope（临时给哪一组能力，空 = 不给）。
	 *
	 * @param array<string,mixed> $r
	 * @return array{verdict:string,scope:string,to:string}
	 */
	public static function classify( array $r, bool $must_change = false ): array {
		$allow    = static fn( string $scope = '' ): array => array( 'verdict' => 'allow', 'scope' => $scope, 'to' => '' );
		$deny     = array( 'verdict' => 'deny', 'scope' => '', 'to' => '' );
		$redirect = static fn( string $to ): array => array( 'verdict' => 'redirect', 'scope' => '', 'to' => $to );
		$doing    = (string) ( $r['doing'] ?? 'front' );
		$action   = (string) ( $r['action'] ?? '' );
		$public   = ! empty( $r['nopriv'] );

		if ( 'front' === $doing ) {
			return $allow();
		}
		if ( 'ajax' === $doing ) {
			if ( ! $must_change && isset( self::AJAX[ $action ] ) ) {
				return $allow( self::AJAX[ $action ] );
			}
			return $public ? $allow() : $deny;
		}
		if ( 'post' === $doing ) {
			if ( in_array( $action, $must_change ? array( self::ACTION_PASSWORD ) : self::POST, true ) ) {
				return $allow();
			}
			return $public ? $allow() : $deny;
		}

		// 后台页面。
		$file = (string) ( $r['file'] ?? '' );
		$page = (string) ( $r['page'] ?? '' );
		$read = in_array( (string) ( $r['method'] ?? 'GET' ), array( 'GET', 'HEAD' ), true );
		if ( $must_change ) {
			return ( 'admin.php' === $file && self::PAGE_WIZARD === $page && $read ) ? $allow() : $redirect( self::PAGE_WIZARD );
		}
		if ( 'index.php' === $file ) {
			return $redirect( self::PAGE_HOME );
		}
		if ( 'profile.php' === $file ) {
			return $redirect( self::PAGE_STORE ); // 改密码在“店铺信息”里。
		}
		if ( 'admin.php' !== $file || '' === $page || ! $read ) {
			return $deny;
		}
		if ( in_array( $page, array( self::PAGE_HOME, self::PAGE_ADS, self::PAGE_STORE, self::PAGE_WIZARD ), true ) ) {
			return $allow();
		}
		// 素材的三个页面只用 CAP_VIEW，不需要店铺能力（“广告数据追踪”栏目下 pc-creatives 开头的页面）。
		if ( 'adtrack' === self::menu_for_page( $page ) && str_starts_with( $page, 'pc-creatives' ) ) {
			return $allow();
		}
		if ( 'pc-overview' === $page ) {
			return $redirect( self::PAGE_HOME ); // 后台里“回到首页”的链接都指向数据大盘，投手的首页是另一个页面。
		}
		if ( self::PAGE_ORDER === $page ) {
			// 订单详情页：只读打开（走到这里的只有 GET / HEAD）。页面上的作废、卖家备注、标签提交到
			// admin-post.php（pc_order_detail），不在投手的名单里，仍然 403。
			return $allow( 'orders' );
		}
		$none = array( '', '-1' );
		if ( in_array( $page, self::CAMPAIGN_PAGES, true ) ) {
			// F2 的只读列表“当前在跑的活动”：只放行不带任何操作参数的 GET。打开新建 / 编辑表单（new、edit）、
			// 批量和启停（action、action2）一律拒绝；保存和启停的提交走 admin-post.php，不在投手的名单里。
			$plain = empty( $r['edits'] ) && in_array( $action, $none, true ) && in_array( (string) ( $r['action2'] ?? '' ), $none, true );
			return ( ! empty( $r['campaigns'] ) && $plain ) ? $allow() : $deny;
		}
		if ( 'wc-orders' === $page ) {
			// 只读：列表（筛选、搜索、翻页）和打开一张订单。批量操作、新建、删除等都带别的 action；
			// “清空回收站”和“接管编辑”不带 action，靠单独的参数触发（WooCommerce 的 ListTable::current_action()、
			// PageController::handle_edit_lock()），一样拒绝。
			$idle = in_array( (string) ( $r['action2'] ?? '' ), $none, true ) && empty( $r['writes'] );
			return ( $idle && ( in_array( $action, $none, true ) || 'edit' === $action ) ) ? $allow( 'orders' ) : $deny;
		}
		// 弃单、数据（含实时访客）和它们带同样前缀的子页面。
		return in_array( self::menu_for_page( $page ), array( 'recovery', 'data' ), true ) ? $allow( 'data' ) : $deny;
	}

	/** 一个 REST 请求给哪一组能力（空 = 不给，接口自己的权限检查会拒绝需要店铺能力的请求）。 */
	public static function rest_scope( string $route, string $method ): string {
		$route  = '/' . trim( $route, '/' );
		$method = strtoupper( $method );
		$under  = static function ( array $prefixes ) use ( $route ): bool {
			foreach ( $prefixes as $prefix ) {
				if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
					return true;
				}
			}
			return false;
		};
		if ( $under( self::REST_ANY ) ) {
			return 'data';
		}
		return in_array( $method, array( 'GET', 'HEAD' ), true ) && $under( self::REST_READ ) ? 'data' : '';
	}

	public static function rest_enter( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request ) {
			self::$rest[] = array( (string) $request->get_route(), (string) $request->get_method() );
		}
		return $response;
	}

	public static function rest_leave( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request ) {
			array_pop( self::$rest );
		}
		return $response;
	}

	/** 当前这次请求给投手哪一组能力。 */
	private static function scope( int $user_id ): string {
		if ( self::must_change( $user_id ) ) {
			return '';
		}
		if ( self::$rest ) {
			$top = self::$rest[ count( self::$rest ) - 1 ];
			return self::rest_scope( $top[0], $top[1] );
		}
		return is_admin() ? self::classify( self::request() )['scope'] : '';
	}

	public static function scoped_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User || ! is_array( $allcaps ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP_VIEW ] = true; // 管理员可以直接打开投手版页面排查问题。
			return $allcaps;
		}
		if ( ! self::is_buyer( $user ) || Staff::disabled( (int) $user->ID ) ) {
			return $allcaps;
		}
		$allcaps[ self::CAP_VIEW ] = true;
		// F2 约定的只读权限：有它只能看“当前在跑的活动”，不能改。角色上已经存了（ensure_role()）；
		// 这里再给一次，是为了角色记录还没来得及补上的那一次请求（拦截在 init 3，补角色在 init 5）。
		$allcaps[ self::CAP_CAMPAIGNS ] = true;
		// 只按“当前登录的人、当前这次请求”给：后台任务里的 user_can( 投手, … ) 不会顺带拿到店铺能力。
		if ( (int) get_current_user_id() !== (int) $user->ID ) {
			return $allcaps;
		}
		foreach ( Capabilities::BUYER_SCOPES[ self::scope( (int) $user->ID ) ] ?? array() as $cap ) {
			$allcaps[ $cap ] = true;
		}
		return $allcaps;
	}

	// ---------- 拦截 ----------

	/** 后台页面：尽早判断（WooCommerce 在注册菜单时就会检查订单权限）。 */
	public static function gate_page(): void {
		if ( ! is_admin() || ! self::current() ) {
			return;
		}
		$r = self::request();
		if ( 'page' === $r['doing'] ) {
			self::enforce( self::classify( $r, self::must_change( get_current_user_id() ) ) );
		}
	}

	/** admin-ajax.php 和 admin-post.php：这时各个动作的处理函数都已经登记好。 */
	public static function gate_action(): void {
		if ( ! self::current() ) {
			return;
		}
		$r = self::request();
		if ( 'ajax' !== $r['doing'] && 'post' !== $r['doing'] ) {
			return;
		}
		// 投手看订单是只读的：心跳里不续订单的编辑锁，免得管理员看到“某某正在编辑”。
		if ( 'heartbeat' === $r['action'] && isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			unset( $_POST['data']['wc-refresh-order-lock'], $_REQUEST['data']['wc-refresh-order-lock'] );
		}
		// 对未登录访客也开放的动作（前台的加载更多、订阅等）照常可用。
		$r['nopriv'] = '' !== $r['action'] && false !== has_action( ( 'ajax' === $r['doing'] ? 'wp_ajax_nopriv_' : 'admin_post_nopriv_' ) . $r['action'] );
		self::enforce( self::classify( $r, self::must_change( get_current_user_id() ) ) );
	}

	private static function enforce( array $verdict ): void {
		if ( 'allow' === $verdict['verdict'] ) {
			return;
		}
		if ( 'redirect' === $verdict['verdict'] ) {
			wp_safe_redirect( self::page_url( $verdict['to'] ) );
			exit;
		}
		self::deny();
	}

	/** 统一的 403 页面。 */
	public static function deny(): void {
		wp_die(
			'<p>' . esc_html( '这个页面不在你的后台里，由总后台统一管理。' ) . '</p><p><a href="' . esc_url( self::home_url() ) . '">' . esc_html( '回到首页' ) . '</a></p>',
			esc_html( '没有权限' ),
			array( 'response' => 403 )
		);
	}

	// ---------- 菜单 ----------

	/**
	 * 投手的菜单，按显示顺序。slug 是菜单链接，pages 是算在这个菜单下的页面（带同样前缀的子页面也算；
	 * exact 为真时只认 pages 里写的地址）。“营销活动”只在 campaigns_visible() 时显示，见 build_menu()。
	 *
	 * @return array<string,array{label:string,slug:string,icon:string,pages:string[],exact?:bool}>
	 */
	public static function menu_items(): array {
		$url = static fn( string $page ): string => 'admin.php?page=' . $page;
		return array(
			'home'     => array( 'label' => '首页', 'slug' => $url( self::PAGE_HOME ), 'icon' => 'dashicons-dashboard', 'pages' => array( self::PAGE_HOME, self::PAGE_WIZARD ) ),
			'orders'   => array( 'label' => '订单', 'slug' => 'admin.php?page=wc-orders&status=wc-processing', 'icon' => 'dashicons-cart', 'pages' => array( 'wc-orders', self::PAGE_ORDER ) ),
			'recovery' => array( 'label' => '弃单', 'slug' => $url( 'pc-recovery' ), 'icon' => 'dashicons-backup', 'pages' => array( 'pc-recovery' ) ),
			'data'     => array( 'label' => '数据', 'slug' => $url( 'pc-data' ), 'icon' => 'dashicons-chart-area', 'pages' => array( 'pc-data', 'pc-visitors' ) ),
			'campaigns' => array( 'label' => '营销活动', 'slug' => $url( self::CAMPAIGN_PAGES[0] ), 'icon' => 'dashicons-tag', 'pages' => self::CAMPAIGN_PAGES, 'exact' => true ),
			// 2026-11-12：广告设置和素材库合成一个栏目“广告数据追踪”（子菜单见 adtrack_submenu()）。
			'adtrack'  => array( 'label' => '广告数据追踪', 'slug' => $url( self::PAGE_ADS ), 'icon' => 'dashicons-megaphone', 'pages' => array( self::PAGE_ADS, 'pc-creatives' ) ), // Creatives\Module::PAGE_*
			'store'    => array( 'label' => '店铺信息', 'slug' => $url( self::PAGE_STORE ), 'icon' => 'dashicons-store', 'pages' => array( self::PAGE_STORE ) ),
		);
	}

	/** 页面（page 参数）属于哪个菜单；不属于任何菜单时为空。 */
	public static function menu_for_page( string $page ): string {
		if ( '' === $page ) {
			return '';
		}
		foreach ( self::menu_items() as $key => $item ) {
			foreach ( $item['pages'] as $base ) {
				if ( $page === $base || ( empty( $item['exact'] ) && str_starts_with( $page, $base . '-' ) ) ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * 左侧菜单换成投手的这几项。
	 *
	 * 在 admin_head 才换：页面能不能打开，WordPress 是按各页面原来登记的菜单判断的（admin_menu 之后立刻检查），
	 * 那时不能动；到这里只剩显示。“数据”下面保留数据页面自己登记的子页面和实时访客。
	 */
	public static function build_menu(): void {
		if ( ! self::current() ) {
			return;
		}
		global $menu, $submenu;
		$registered = is_array( $submenu ) ? $submenu : array();
		$locked     = self::must_change( get_current_user_id() );
		$menu       = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$position   = 0;
		foreach ( self::menu_items() as $key => $item ) {
			if ( $locked && 'home' !== $key ) {
				continue; // 还没改密码：只留首页（会转到向导）。
			}
			if ( 'campaigns' === $key && ! self::campaigns_visible() ) {
				continue; // F2 的只读列表不在这份代码里，或者 F2 说这个人不能看。
			}
			$position         += 5;
			$menu[ $position ] = array( $item['label'], 'read', $item['slug'], $item['label'], 'menu-top pc-buyer-menu', 'pc-buyer-menu-' . $key, $item['icon'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( 'data' === $key ) {
				$children = self::data_submenu( $registered );
				if ( count( $children ) > 1 ) {
					$submenu[ $item['slug'] ] = $children; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
			if ( 'adtrack' === $key ) {
				$submenu[ $item['slug'] ] = self::adtrack_submenu(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/** “广告数据追踪”的子菜单：广告设置、素材库（花费导入和素材看板从素材库顶部的标签进）。 */
	public static function adtrack_submenu(): array {
		return array(
			array( '广告设置', 'read', 'admin.php?page=' . self::PAGE_ADS, '广告设置' ),
			array( '素材库', 'read', 'admin.php?page=pc-creatives', '素材库' ),
		);
	}

	/** “数据”的子菜单：数据页面和实时访客在这次请求里登记的子页面，链接改成完整地址。 */
	private static function data_submenu( array $registered ): array {
		$out  = array();
		$seen = array();
		foreach ( array( 'pc-data', 'pc-visitors' ) as $parent ) {
			foreach ( (array) ( $registered[ $parent ] ?? array() ) as $item ) {
				$slug = (string) ( $item[2] ?? '' );
				if ( isset( $seen[ $slug ] ) || 'data' !== self::menu_for_page( $slug ) ) {
					continue;
				}
				$seen[ $slug ] = true;
				$out[]         = array( (string) ( $item[0] ?? '' ), 'read', 'admin.php?page=' . $slug, (string) ( $item[3] ?? $item[0] ?? '' ) );
			}
		}
		return $out;
	}

	public static function parent_file( $parent_file ) {
		if ( ! self::current() ) {
			return $parent_file;
		}
		$key = self::menu_for_page( self::request()['page'] );
		return '' !== $key ? self::menu_items()[ $key ]['slug'] : $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		if ( ! self::current() ) {
			return $submenu_file;
		}
		$page = self::request()['page'];
		if ( 'adtrack' === self::menu_for_page( $page ) ) {
			return 'admin.php?page=' . ( str_starts_with( $page, 'pc-creatives' ) ? 'pc-creatives' : self::PAGE_ADS );
		}
		if ( 'data' !== self::menu_for_page( $page ) ) {
			return $submenu_file;
		}
		// 数据页面自己可能已经把隐藏的子页面指到别的子菜单上，沿用它的选择。
		$target = is_string( $submenu_file ) && 'data' === self::menu_for_page( $submenu_file ) ? $submenu_file : $page;
		return 'admin.php?page=' . $target;
	}

	/** 顶部工具条：去掉“新建”等入口；“编辑个人资料”改到店铺信息（改密码在那里）。 */
	public static function admin_bar( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! self::current() ) {
			return;
		}
		$nodes = array( 'new-content', 'edit', 'view', 'archive', 'search', 'customize', 'updates', 'comments' );
		if ( self::hide_palette() ) {
			$nodes[] = 'command-palette';
		}
		foreach ( $nodes as $node ) {
			$bar->remove_node( $node );
		}
		$store = self::page_url( self::PAGE_STORE );
		foreach ( array( 'my-account', 'user-info', 'edit-profile' ) as $node ) {
			if ( $bar->get_node( $node ) ) {
				$bar->add_node( array( 'id' => $node, 'href' => $store ) );
			}
		}
	}
}
