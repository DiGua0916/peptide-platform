<?php
/**
 * M9 投手版后台 × F2 的只读“当前在跑的活动”（平台所有者 2026-11-13：投手可以看只读列表，不能新建、编辑、启停、删除）。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m9-buyer-campaigns-pure.php
 *
 * 纯逻辑，不加载 WordPress。这里放一个假的 Admin\Marketing 顶替 F2 的类（F2 的真实现在它的分支上，有它自己的用例
 * m4-campaigns-readonly-pure）：它和真的一样，判断“能不能看 / 能不能改”时要回头查当前用户的能力，
 * 所以也顺带验证 Staff_Buyer::request() 和 scoped_caps() 互相调用时不会绕成死循环。
 * F2 的类不在时的行为（三个营销页面对投手仍然整页 403）在 m9-buyer-scope-pure 里。
 */

namespace PeptideCore {
	/** 员工账号的停用开关（真的在 class-staff.php，这里只要这一个方法）。 */
	final class Staff {
		public static array $disabled = array();
		public static function disabled( int $user_id ): bool {
			return in_array( $user_id, self::$disabled, true );
		}
	}
}

namespace PeptideCore\Admin {
	/** 顶替 F2 的 Admin\Marketing：方法名、含义和它的约定一致（docs/CONTRACT.md F2 一节）。 */
	final class Marketing {
		public const CAP      = 'edit_shop_coupons';
		public const VIEW_CAP = 'pc_view_campaigns';
		/** 模拟有人用过滤器 pc_marketing_can_manage 把“能改”放开。 */
		public static bool $forced_manage = false;

		private static function has( string $cap ): bool {
			$user = \get_userdata( \get_current_user_id() );
			if ( ! $user ) {
				return false;
			}
			$caps = \PeptideCore\Staff_Buyer::scoped_caps( $user->allcaps, array( $cap ), array( $cap, $user->ID ), $user );
			return ! empty( $caps[ $cap ] );
		}
		public static function can_manage(): bool {
			$user = \get_userdata( \get_current_user_id() );
			$can  = self::has( self::CAP ) && $user && ! in_array( 'pc_media_buyer', $user->roles, true );
			return $can || self::$forced_manage;
		}
		public static function can_view(): bool {
			return self::has( self::CAP ) || self::has( self::VIEW_CAP );
		}
		public static function menu_cap(): string {
			return self::has( self::CAP ) ? self::CAP : self::VIEW_CAP;
		}
		public static function read_only_gate(): bool {
			return ! self::can_manage();
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	final class WP_User {
		public int $ID;
		public array $roles;
		public array $allcaps;
		public function __construct( int $id, array $roles, array $allcaps = array() ) {
			$this->ID      = $id;
			$this->roles   = $roles;
			$this->allcaps = $allcaps;
		}
	}

	$GLOBALS['f5_users']   = array();
	$GLOBALS['f5_meta']    = array();
	$GLOBALS['f5_current'] = 0;
	function get_current_user_id(): int { return (int) $GLOBALS['f5_current']; }
	function get_userdata( $id ) { return $GLOBALS['f5_users'][ (int) $id ] ?? false; }
	function get_user_meta( $id, $key = '', $single = false ) { return $GLOBALS['f5_meta'][ (int) $id ][ $key ] ?? ''; }
	function is_admin(): bool { return true; }

	/** WordPress 的角色记录（wp_user_roles 选项）。writes 记下每一次写入。 */
	final class WP_Role {
		public string $name;
		public array $capabilities;
		public function __construct( string $name, array $caps ) { $this->name = $name; $this->capabilities = $caps; }
		public function has_cap( string $cap ): bool { return ! empty( $this->capabilities[ $cap ] ); }
		public function add_cap( string $cap, bool $grant = true ): void { $this->capabilities[ $cap ] = $grant; $GLOBALS['f5_role_writes'][] = 'add_cap ' . $cap; }
	}
	$GLOBALS['f5_roles']       = array();
	$GLOBALS['f5_role_writes'] = array();
	function get_role( string $name ) { return $GLOBALS['f5_roles'][ $name ] ?? null; }
	function add_role( string $name, string $label, array $caps = array() ) { $GLOBALS['f5_role_writes'][] = 'add_role ' . $name; return $GLOBALS['f5_roles'][ $name ] = new WP_Role( $name, $caps ); }
	function wp_doing_ajax(): bool { return false; }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }

	require dirname( __DIR__ ) . '/includes/class-capabilities.php';
	require dirname( __DIR__ ) . '/includes/class-staff-buyer.php';

	use PeptideCore\Staff_Buyer;
	use PeptideCore\Admin\Marketing;

	// 0. 角色上存什么：只有 read、进后台、只读的 pc_view_campaigns。能改东西的权限一项都没有。
	$write_caps = array( 'pc_manage_marketing', 'edit_shop_coupons', 'edit_others_shop_coupons', 'publish_shop_coupons', 'delete_shop_coupons', 'manage_woocommerce', 'edit_shop_orders', 'edit_products', 'manage_options', 'edit_posts' );
	check( 'role stores exactly read, dashboard access and the read-only campaigns cap', array( 'read' => true, 'view_admin_dashboard' => true, 'pc_view_campaigns' => true ) === Staff_Buyer::role_caps() );
	check( 'role stores nothing that can change a campaign or the store', array() === array_intersect( $write_caps, array_keys( Staff_Buyer::role_caps() ) ) );
	Staff_Buyer::ensure_role();
	check( 'new store: the role is created with the read-only cap', array( 'add_role pc_media_buyer' ) === $GLOBALS['f5_role_writes'] && Staff_Buyer::role_caps() === get_role( 'pc_media_buyer' )->capabilities );
	// 2026-11-13 之前建好的角色（沙盒和现有的店）：没有这项权限，F1 在沙盒量到的就是这个状态。
	$GLOBALS['f5_roles']['pc_media_buyer'] = new WP_Role( 'pc_media_buyer', array( 'read' => true, 'view_admin_dashboard' => true ) );
	$GLOBALS['f5_role_writes']             = array();
	check( 'existing store before the fix: the role has no campaigns cap', ! get_role( 'pc_media_buyer' )->has_cap( 'pc_view_campaigns' ) );
	Staff_Buyer::ensure_role();
	check( 'existing store: the read-only cap is added to the role', get_role( 'pc_media_buyer' )->has_cap( 'pc_view_campaigns' ) && array( 'add_cap pc_view_campaigns' ) === $GLOBALS['f5_role_writes'] );
	Staff_Buyer::ensure_role();
	Staff_Buyer::ensure_role();
	check( 'and it is written once, not on every request', array( 'add_cap pc_view_campaigns' ) === $GLOBALS['f5_role_writes'] );
	check( 'the role still stores only the three caps', Staff_Buyer::role_caps() === get_role( 'pc_media_buyer' )->capabilities );

	// WordPress 把角色上存的能力并进账号的 allcaps，这里照样给两个投手账号。
	$buyer   = new WP_User( 7, array( 'pc_staff', 'pc_media_buyer' ), Staff_Buyer::role_caps() );
	$locked  = new WP_User( 8, array( 'pc_staff', 'pc_media_buyer' ), Staff_Buyer::role_caps() );
	// 角色记录还没补上的那一次请求（旧的角色能力）：只读权限由 scoped_caps() 补，结果一样。
	$stale   = new WP_User( 6, array( 'pc_staff', 'pc_media_buyer' ), array( 'read' => true, 'view_admin_dashboard' => true ) );
	$staff   = new WP_User( 9, array( 'pc_staff' ), array( 'read' => true, 'edit_shop_coupons' => true ) );
	$admin   = new WP_User( 1, array( 'administrator' ), array( 'manage_options' => true, 'edit_shop_coupons' => true ) );
	$GLOBALS['f5_users'] = array( 6 => $stale, 7 => $buyer, 8 => $locked, 9 => $staff, 1 => $admin );
	$GLOBALS['f5_meta']  = array( 8 => array( Staff_Buyer::META_MUST_PASS => '1' ) );

	/** 以某个账号打开一个后台地址（每次都是一次新的请求）。 */
	$open = static function ( int $user_id, string $page, array $query = array(), string $method = 'GET', string $file = 'admin.php' ): array {
		$GLOBALS['f5_current'] = $user_id;
		$GLOBALS['pagenow']    = $file;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET     = array_filter( array( 'page' => $page ) + $query, static fn( $v ) => null !== $v );
		$_REQUEST = $_GET;
		$cache    = new ReflectionProperty( Staff_Buyer::class, 'request' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
		$r = Staff_Buyer::request();
		return array( 'request' => $r, 'verdict' => Staff_Buyer::classify( $r, Staff_Buyer::must_change( $user_id ) ) );
	};
	$caps = static function ( WP_User $user ): array {
		return Staff_Buyer::scoped_caps( $user->allcaps, array(), array(), $user );
	};
	$menu_labels = static function (): array {
		$GLOBALS['menu']    = array( 5 => array( 'Posts', 'edit_posts', 'edit.php' ) );
		$GLOBALS['submenu'] = array();
		Staff_Buyer::build_menu();
		return array_values( array_map( static fn( $item ) => $item[0] . ' => ' . $item[2], $GLOBALS['menu'] ) );
	};
	$allow = array( 'verdict' => 'allow', 'scope' => '', 'to' => '' );
	$deny  = array( 'verdict' => 'deny', 'scope' => '', 'to' => '' );

	check( 'F2 read-only code counts as present', Staff_Buyer::campaigns_ready() );
	check( 'the literal cap name is F2\'s', Marketing::VIEW_CAP === Staff_Buyer::CAP_CAMPAIGNS );

	// 1. 投手打开三个营销页面：放行，看到的是 F2 的只读列表；不给任何店铺能力。
	foreach ( Staff_Buyer::CAMPAIGN_PAGES as $page ) {
		$got = $open( 7, $page );
		check( "$page: F2 would show its read-only list to this account", '1' === $got['request']['campaigns'] && Marketing::read_only_gate() );
		check( "$page: the buyer gate lets the plain list through", $allow === $got['verdict'] );
		$have = $caps( $buyer );
		check( "$page: the buyer holds the view cap and nothing that can change a campaign or the store", ! empty( $have['pc_view_campaigns'] ) && array() === array_intersect( $write_caps, array_keys( array_filter( $have ) ) ) );
		check( "$page: exactly the role caps plus the buyer-page cap, nothing else", array( 'pc_view_buyer_admin', 'pc_view_campaigns', 'read', 'view_admin_dashboard' ) === ( static function ( array $c ) { $k = array_keys( array_filter( $c ) ); sort( $k ); return $k; } )( $have ) );
		check( "$page: F2 agrees — can view, cannot manage, menu registered with the view cap", Marketing::can_view() && ! Marketing::can_manage() && 'pc_view_campaigns' === Marketing::menu_cap() );
		check( "$page: the entry is shown", Staff_Buyer::campaigns_visible() );
	}

	// 1b. 角色记录还没补上的那一次请求：照样能看，照样没有写权限。
	$got = $open( 6, 'pc-promotions' );
	check( 'stale role record: the list still opens and the entry is shown', $allow === $got['verdict'] && Staff_Buyer::campaigns_visible() );
	check( 'stale role record: view cap yes, write caps no', ! empty( $caps( $stale )['pc_view_campaigns'] ) && array() === array_intersect( $write_caps, array_keys( array_filter( $caps( $stale ) ) ) ) );

	// 2. 新建、编辑、带操作的地址，和提交：都拒绝。
	foreach ( Staff_Buyer::CAMPAIGN_PAGES as $page ) {
		check( "$page?new=1 denied", $deny === $open( 7, $page, array( 'new' => '1' ) )['verdict'] );
		check( "$page?edit=12 denied", $deny === $open( 7, $page, array( 'edit' => '12' ) )['verdict'] );
		check( "$page?edit= (empty value) denied", $deny === $open( 7, $page, array( 'edit' => '' ) )['verdict'] );
		check( "$page with a bulk action denied", $deny === $open( 7, $page, array( 'action' => 'delete', 'ids' => '3,4' ) )['verdict'] );
		check( "$page&action=new denied", $deny === $open( 7, $page, array( 'action' => 'new' ) )['verdict'] );
		check( "$page&action=edit&id=12 denied", $deny === $open( 7, $page, array( 'action' => 'edit', 'id' => '12' ) )['verdict'] );
		check( "$page POST denied", $deny === $open( 7, $page, array(), 'POST' )['verdict'] );
	}
	foreach ( array( 'pc_promo_save', 'pc_promo_toggle', 'pc_promo_bulk', 'pc_coupon_save', 'pc_coupon_toggle', 'pc_shipdisc_save', 'pc_shipdisc_toggle' ) as $action ) {
		$got = $open( 7, '', array( 'page' => null, 'action' => $action ), 'POST', 'admin-post.php' );
		check( "submit $action denied", 'post' === $got['request']['doing'] && $deny === $got['verdict'] );
	}

	// 3. 菜单：七项，“营销活动”在数据后面、“广告数据追踪”（主线 2026-11-12：广告设置 + 素材库）前面；在别的页面上也显示。
	$open( 7, 'pc-buyer-home' );
	$expected = array(
		'首页 => admin.php?page=pc-buyer-home',
		'订单 => admin.php?page=wc-orders&status=wc-processing',
		'弃单 => admin.php?page=pc-recovery',
		'数据 => admin.php?page=pc-data',
		'营销活动 => admin.php?page=pc-promotions',
		'广告数据追踪 => admin.php?page=pc-buyer-ads',
		'店铺信息 => admin.php?page=pc-store-info',
	);
	check( 'buyer menu: seven entries, campaigns after data', $expected === $menu_labels() );
	check( 'ad tracking keeps its two sub-entries, campaigns has none', array( '广告设置', '素材库' ) === array_column( $GLOBALS['submenu']['admin.php?page=pc-buyer-ads'] ?? array(), 0 ) && ! isset( $GLOBALS['submenu']['admin.php?page=pc-promotions'] ) );
	check( 'creative pages still open for the buyer without store caps', $allow === $open( 7, 'pc-creatives' )['verdict'] && $allow === $open( 7, 'pc-creatives-board' )['verdict'] && $deny === $open( 7, 'pc-creatives', array(), 'POST' )['verdict'] );
	$open( 7, 'pc-buyer-home' );
	$open( 7, 'pc-coupons' );
	check( 'on a campaign page the campaigns entry is the highlighted one', 'admin.php?page=pc-promotions' === Staff_Buyer::parent_file( 'x' ) );
	$GLOBALS['title'] = 'Coupon codes';
	Staff_Buyer::campaigns_title();
	check( 'the page is titled like the buyer menu entry, not like the editable page', '营销活动' === $GLOBALS['title'] );
	$open( 7, 'pc-data' );
	$GLOBALS['title'] = 'Data';
	Staff_Buyer::campaigns_title();
	check( 'other buyer pages keep their own title', 'Data' === $GLOBALS['title'] );
	$open( 9, 'pc-coupons' );
	$GLOBALS['title'] = 'Coupon codes';
	Staff_Buyer::campaigns_title();
	check( 'people who can edit keep the page\'s own title', 'Coupon codes' === $GLOBALS['title'] );

	// 4. 其余营销页面和应用中心（离开挽回、转盘、加购推荐等设置）：仍然不开放。
	foreach ( array( 'pc-first-order-offer', 'pc-exit-intent', 'pc-marketing', 'pc-promotions-product-offers', 'pc-promotions-campaigns', 'pc-promotions-subscribers', 'pc-apps', 'pc-paid-popup', 'pc-tracking-ball', 'pc-cart-links', 'pc-referrals' ) as $page ) {
		check( "still closed with the read-only list present: $page", $deny === $open( 7, $page )['verdict'] );
	}

	// 5. F2 认为这个账号能改（有人把 pc_marketing_can_manage 放开）：投手这一层整页拒绝，菜单里也没有入口。
	Marketing::$forced_manage = true;
	foreach ( Staff_Buyer::CAMPAIGN_PAGES as $page ) {
		$got = $open( 7, $page );
		check( "$page: closed when F2 would show the editable page", '' === $got['request']['campaigns'] && $deny === $got['verdict'] );
	}
	$open( 7, 'pc-buyer-home' );
	check( 'no campaigns entry in that case', ! Staff_Buyer::campaigns_visible() && 6 === count( $menu_labels() ) && ! in_array( '营销活动 => admin.php?page=pc-promotions', $menu_labels(), true ) );
	Marketing::$forced_manage = false;

	// 6. 还没改密码的投手：只读权限在角色上，但只能去向导，菜单只有首页。
	$got = $open( 8, 'pc-promotions' );
	check( 'locked buyer is sent to the wizard', array( 'verdict' => 'redirect', 'scope' => '', 'to' => 'pc-onboarding' ) === $got['verdict'] );
	foreach ( Staff_Buyer::CAMPAIGN_PAGES as $page ) {
		check( "locked buyer cannot open $page, with or without parameters", 'redirect' === $open( 8, $page )['verdict']['verdict'] && 'redirect' === $open( 8, $page, array( 'new' => '1' ) )['verdict']['verdict'] );
	}
	check( 'locked buyer still has no store caps', array() === array_intersect( $write_caps, array_keys( array_filter( $caps( $locked ) ) ) ) );
	$open( 8, 'pc-promotions' );
	check( 'locked buyer menu is home only', array( '首页 => admin.php?page=pc-buyer-home' ) === $menu_labels() );

	// 7. 被停用的投手：什么都不给。
	\PeptideCore\Staff::$disabled = array( 7 );
	$open( 7, 'pc-promotions' );
	check( 'disabled buyer gets nothing from this class beyond what the role stores', $buyer->allcaps === $caps( $buyer ) && empty( $caps( $buyer )['pc_view_buyer_admin'] ) && array() === array_intersect( $write_caps, array_keys( array_filter( $caps( $buyer ) ) ) ) );
	\PeptideCore\Staff::$disabled = array();

	// 8. 不是投手的人不受影响：营销员工照旧能改；管理员照旧。
	$open( 9, 'pc-promotions' );
	check( 'marketing staff: untouched by the buyer class, still manages', $staff->allcaps === $caps( $staff ) && Marketing::can_manage() && 'edit_shop_coupons' === Marketing::menu_cap() );
	$open( 1, 'pc-promotions' );
	$admin_caps = $caps( $admin );
	check( 'administrator: still manages, only gains the buyer-page cap as before', Marketing::can_manage() && ! empty( $admin_caps['pc_view_buyer_admin'] ) && ! isset( $admin_caps['pc_view_campaigns'] ) );

	// 9. 后台任务里问“这个投手有没有某个能力”（当前登录的是别人）：只读权限有，店铺能力没有。
	$open( 1, 'wc-orders' );
	$as_other = $caps( $buyer );
	check( 'asked from someone else\'s request: view cap yes, store caps no', ! empty( $as_other['pc_view_campaigns'] ) && empty( $as_other['manage_woocommerce'] ) && empty( $as_other['edit_shop_coupons'] ) );

	echo "PASS {$checks} media-buyer campaigns checks\n";
}
