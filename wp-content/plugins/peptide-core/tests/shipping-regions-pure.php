<?php
/**
 * 运费地区分流（SHIPPING-REGION-01，店主 2026-11-28）。
 *
 * 真的把 PeptideCore\Shipping\Regions、Plans、US_Territories 载进来，只把 WordPress / WooCommerce
 * 换成桩。不碰数据库、不需要 WordPress。
 *
 * 要证明的事：
 * 1. 波多黎各的三种写法（国家 PR、US + 州 PR、US + 邮编 006/007/009 但州填错）都归到同一个地区；
 *    美属维尔京群岛（008）不算波多黎各。
 * 2. **文案先按 IP，地址一填就跟地址。** 用波多黎各 IP 但地址填美国本土的人，按美国算；
 *    反过来也一样。这样就不会出现「看到的和付的不一样」。
 * 3. 主力线路挑的是起步价便宜的那条（表里是港联 / UPS，不是大陆联邦加急），进度条跟着它走。
 * 4. **最要紧的一条：一次请求吐出的阶梯里，不能有另一个地区的任何一个数字。**
 *    这里按店主 2026-11-28 给的表配出美国和波多黎各两套，然后把两边的响应序列化出来逐个数字比对。
 * 5. 运费折扣的地区名单（空 = 所有地区）同样在服务端整条剔除。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['t_checks']  = 0;
	$GLOBALS['t_options'] = array();

	function check( string $label, bool $ok ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$GLOBALS['t_checks']++;
	}

	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
	function __( $text, $domain = '' ) { return $text; }
	function absint( $v ) { return abs( (int) $v ); }
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
	function wp_rand( $min = 0, $max = 0 ) { return 7; }
	function get_option( $name, $default = false ) { return $GLOBALS['t_options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['t_options'][ $name ] = $value; return true; }

	/** WooCommerce 的桩：只要国家 / 州列表和顾客对象。 */
	class WC_Customer {
		public array $f = array( 'shipping_country' => '', 'shipping_state' => '', 'shipping_postcode' => '', 'billing_country' => '', 'billing_state' => '', 'billing_postcode' => '' );
		public function __call( $name, $args ) {
			if ( str_starts_with( $name, 'get_' ) ) { return $this->f[ substr( $name, 4 ) ] ?? ''; }
			if ( str_starts_with( $name, 'set_' ) ) { $this->f[ substr( $name, 4 ) ] = $args[0]; return null; }
			return null;
		}
	}

	class T_Countries {
		public function get_countries() { return array( 'US' => 'United States', 'PR' => 'Puerto Rico', 'CA' => 'Canada', 'GB' => 'United Kingdom' ); }
		public function get_states( $cc ) { return 'US' === $cc ? array( 'CA' => 'California', 'NY' => 'New York', 'DC' => 'DC', 'PR' => 'Puerto Rico' ) : array(); }
		/** 店铺所在国。WooCommerce 用它预填新访客的地址，判定必须把它排除掉。 */
		public function get_base_country() { return 'US'; }
	}

	class T_WC {
		public $countries;
		public $customer = null;
		public function __construct() { $this->countries = new T_Countries(); }
	}

	function WC() { return $GLOBALS['t_wc']; }
	$GLOBALS['t_wc'] = new T_WC();
}

namespace PeptideCore\Tracking {
	/** GeoIP 的桩：测试直接摆结果，不读任何库文件。 */
	final class Country_Provider {
		public static ?string $code = null;
		public static function lookup( $address ): array {
			return null === self::$code ? array( 'status' => 'unknown' ) : array( 'status' => 'known', 'country_code' => self::$code );
		}
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-us-territories.php';
	require_once dirname( __DIR__ ) . '/includes/shipping/class-plans.php';
	require_once dirname( __DIR__ ) . '/includes/shipping/class-regions.php';

	use PeptideCore\Shipping\Plans;
	use PeptideCore\Shipping\Regions;
	use PeptideCore\Tracking\Country_Provider;
	use PeptideCore\US_Territories;

	// ---------- 1. 波多黎各的三种写法 ----------

	check( '国家直接填 PR', 'pr' === Regions::from_address( 'PR', '', '00901' ) );
	check( 'US + 州 PR（归一后的标准写法）', 'pr' === Regions::from_address( 'US', 'PR', '00901' ) );
	check( 'US + PR 邮编但州填错', 'pr' === Regions::from_address( 'US', 'NY', '00725' ) );
	check( 'US + 009 邮编', 'pr' === Regions::from_address( 'US', '', '00970' ) );
	check( '美属维尔京群岛 008 不算 PR', 'us' === Regions::from_address( 'US', 'VI', '00801' ) );
	check( '美国本土', 'us' === Regions::from_address( 'US', 'CA', '90001' ) );
	check( '加拿大归到其他国家', 'intl' === Regions::from_address( 'CA', 'ON', 'M5V' ) );
	check( '没有国家就是没判定', null === Regions::from_address( '', 'PR', '00901' ) );
	check( '邮编名单只有 US_Territories 一份', US_Territories::is_pr_postcode( '00725-1234' ) && ! US_Territories::is_pr_postcode( '00801' ) );

	check( 'GeoIP PR', 'pr' === Regions::from_country_code( 'PR' ) );
	check( 'GeoIP US', 'us' === Regions::from_country_code( 'US' ) );
	check( 'GeoIP CA', 'intl' === Regions::from_country_code( 'CA' ) );
	check( 'GeoIP 乱码不猜', null === Regions::from_country_code( 'zz1' ) );

	// ---------- 2. 地址 > IP > 默认 ----------

	$GLOBALS['t_options'][ Regions::OPTION ] = array( 'enabled' => true, 'default' => 'us', 'plans' => array( 'us' => 1, 'pr' => 2, 'intl' => 0 ) );
	$_SERVER['REMOTE_ADDR'] = '66.50.1.1';

	Country_Provider::$code = null;
	Regions::reset_cache();
	$GLOBALS['t_wc']->customer = null;
	check( 'IP 查不到、地址也没有 → 默认地区', array( 'key' => 'us', 'source' => 'default' ) === Regions::current() );

	Country_Provider::$code = 'PR';
	Regions::reset_cache();
	check( '只有 IP：波多黎各', array( 'key' => 'pr', 'source' => 'ip' ) === Regions::current() );

	$customer = new WC_Customer();
	$customer->f['shipping_country'] = 'US';
	$customer->f['shipping_state']   = 'CA';
	$customer->f['shipping_postcode'] = '90001';
	$GLOBALS['t_wc']->customer = $customer;
	check( 'PR 的 IP + 美国本土地址 → 按地址算美国', array( 'key' => 'us', 'source' => 'address' ) === Regions::current() );

	Country_Provider::$code = 'US';
	Regions::reset_cache();
	$customer->f['shipping_country'] = 'US';
	$customer->f['shipping_state']   = 'PR';
	$customer->f['shipping_postcode'] = '00901';
	check( '美国的 IP + 波多黎各地址 → 按地址算 PR', array( 'key' => 'pr', 'source' => 'address' ) === Regions::current() );

	$customer->f['shipping_country'] = '';
	$customer->f['shipping_state']   = '';
	$customer->f['shipping_postcode'] = '';
	$customer->f['billing_country']  = 'US';
	$customer->f['billing_state']    = 'PR';
	check( '只填了账单地址也认', array( 'key' => 'pr', 'source' => 'address' ) === Regions::current() );

	// ---------- 2b. WooCommerce 预填的国家不能当成访客填的地址 ----------
	// 2026-11-28 在沙盒里实测到的：WooCommerce 给每个新访客都预填了国家 = 店铺所在国（US），
	// 州和邮编都是空的。照收的话 from_customer() 永远返回 us，IP 这条路就是死的，
	// 波多黎各访客刚进站会看到美国的门槛 —— 正好是这次要避免的事故。

	Country_Provider::$code = 'PR';
	Regions::reset_cache();
	$blank = new WC_Customer();
	$blank->f['shipping_country'] = 'US';   // 店铺所在国，州和邮编都空着
	$GLOBALS['t_wc']->customer    = $blank;
	check( '只有预填的店铺所在国 → 让给 IP，PR 访客看到 pr', array( 'key' => 'pr', 'source' => 'ip' ) === Regions::current() );

	$blank->f['shipping_postcode'] = '90001';
	check( '填了邮编就是真地址 → 按地址算美国', array( 'key' => 'us', 'source' => 'address' ) === Regions::current() );

	$blank->f['shipping_postcode'] = '';
	$blank->f['shipping_state']    = 'CA';
	check( '填了州就是真地址 → 按地址算美国', array( 'key' => 'us', 'source' => 'address' ) === Regions::current() );

	$blank->f['shipping_state']   = '';
	$blank->f['shipping_country'] = 'CA';   // 访客自己把国家换掉了，不是预填值
	check( '访客换过国家，哪怕没填州和邮编也算真地址', array( 'key' => 'intl', 'source' => 'address' ) === Regions::current() );

	$blank->f['shipping_country'] = 'US';
	$blank->f['billing_country']  = 'US';   // 账单地址也只有预填的国家
	$blank->f['billing_state']    = '';
	$blank->f['billing_postcode'] = '';
	Country_Provider::$code = null;
	Regions::reset_cache();
	check( '两个地址都只有预填国家、IP 又查不到 → 走默认地区', array( 'key' => 'us', 'source' => 'default' ) === Regions::current() );

	// ---------- 3+4. 两套阶梯，按店主 2026-11-28 的表 ----------

	$band = static fn( string $name, float $min, ?float $max, float $fee ): array => array(
		'id' => $name . $min, 'enabled' => true, 'name' => $name, 'icon' => 0,
		'postcodes' => '', 'rule' => 'subtotal', 'min' => $min, 'max' => $max, 'fee' => $fee,
	);
	$STD  = 'Priority Air 5-8d';
	$FAST = 'Ultra-Express 3-5d';
	$GLOBALS['t_options'][ Plans::OPTION ] = array(
		array(
			'id' => 1, 'type' => 'general', 'name' => 'US mainland',
			'regions' => array( array( 'country' => 'US', 'states' => array( 'CA', 'NY', 'DC' ) ) ),
			'rates' => array(
				$band( $STD, 0, 220, 44 ), $band( $STD, 220, 380, 22 ), $band( $STD, 380, 600, 0 ), $band( $STD, 600, null, 0 ),
				$band( $FAST, 0, 220, 69 ), $band( $FAST, 220, 380, 48 ), $band( $FAST, 380, 600, 32 ), $band( $FAST, 600, null, 19 ),
			),
		),
		array(
			'id' => 2, 'type' => 'general', 'name' => 'Puerto Rico',
			'regions' => array( array( 'country' => 'US', 'states' => array( 'PR' ) ) ),
			'rates' => array(
				$band( $STD, 0, 170, 36 ), $band( $STD, 170, 285, 18 ), $band( $STD, 285, null, 0 ),
				$band( $FAST, 0, 170, 62 ), $band( $FAST, 170, 285, 44 ), $band( $FAST, 285, null, 32 ),
			),
		),
	);

	$us = Regions::ladder( 'us', 0.0 );
	$pr = Regions::ladder( 'pr', 0.0 );
	check( '美国有阶梯', is_array( $us ) );
	check( '波多黎各有阶梯', is_array( $pr ) );
	check( '主力线路是起步便宜的港联，不是加急', $STD === $us['track'] && $STD === $pr['track'] );
	check( '美国起步价 $44', 4400 === $us['current_fee_minor'] );
	check( '波多黎各起步价 $36', 3600 === $pr['current_fee_minor'] );
	check( '美国免邮线 $380', 38000 === $us['free_at_minor'] );
	check( '波多黎各免邮线 $285', 28500 === $pr['free_at_minor'] );
	check( '美国下一档是 $220 收 $22，还差 $220', 22000 === $us['next']['min_minor'] && 2200 === $us['next']['fee_minor'] && 22000 === $us['next']['remaining_minor'] );
	check( '波多黎各下一档是 $170 收 $18', 17000 === $pr['next']['min_minor'] && 1800 === $pr['next']['fee_minor'] );

	// 凑到一半：美国 $250 的车已经过了半价档，下一档是 $380 免邮。
	$us250 = Regions::ladder( 'us', 250.0 );
	check( '美国 $250 现在收 $22', 2200 === $us250['current_fee_minor'] );
	check( '美国 $250 下一档免邮，还差 $130', 38000 === $us250['next']['min_minor'] && true === $us250['next']['free'] && 13000 === $us250['next']['remaining_minor'] );
	$pr250 = Regions::ladder( 'pr', 250.0 );
	check( '波多黎各 $250 现在收 $18', 1800 === $pr250['current_fee_minor'] );
	check( '波多黎各 $250 下一档免邮，还差 $35', 28500 === $pr250['next']['min_minor'] && 3500 === $pr250['next']['remaining_minor'] );
	check( '波多黎各 $300 已经免邮', 0 === Regions::ladder( 'pr', 300.0 )['current_fee_minor'] );
	check( '美国 $300 还没免邮（两边门槛不一样）', 2200 === Regions::ladder( 'us', 300.0 )['current_fee_minor'] );

	// ---------- 4. 一个数字都不能串过去 ----------

	/** @return int[] 一份响应里出现的全部整数。 */
	$numbers = static function ( array $payload ) use ( &$numbers ): array {
		$out = array();
		foreach ( $payload as $v ) {
			if ( is_array( $v ) ) { $out = array_merge( $out, $numbers( $v ) ); }
			elseif ( is_int( $v ) ) { $out[] = $v; }
		}
		return array_values( array_unique( $out ) );
	};
	$us_marks = array( 22000, 38000, 60000 ); // 美国独有的门槛
	$pr_marks = array( 17000, 28500 );        // 波多黎各独有的门槛
	foreach ( array( 0.0, 150.0, 250.0, 400.0, 700.0 ) as $subtotal ) {
		$in_us = $numbers( Regions::ladder( 'us', $subtotal ) );
		$in_pr = $numbers( Regions::ladder( 'pr', $subtotal ) );
		check( "美国的响应里没有波多黎各的门槛（小计 {$subtotal}）", ! array_intersect( $pr_marks, $in_us ) );
		check( "波多黎各的响应里没有美国的门槛（小计 {$subtotal}）", ! array_intersect( $us_marks, $in_pr ) );
		check( "美国的响应里有自己的门槛（小计 {$subtotal}）", 3 === count( array_intersect( $us_marks, $in_us ) ) );
		check( "波多黎各的响应里有自己的门槛（小计 {$subtotal}）", 2 === count( array_intersect( $pr_marks, $in_pr ) ) );
	}

	// 同一次请求只会拿到一套：for_cart() 走的是 current()，不是两个地区都算一遍。
	Country_Provider::$code = 'PR';
	Regions::reset_cache();
	$GLOBALS['t_wc']->customer = null;
	$served = Regions::for_cart( null );
	check( 'PR 访客拿到的是 pr 这一套', 'pr' === $served['region'] && 'ip' === $served['source'] );
	check( 'PR 访客的响应里没有美国的门槛', ! array_intersect( $us_marks, $numbers( $served ) ) );

	// 没绑方案的地区不吐数据，也不会退回别人的阶梯。
	Country_Provider::$code = 'CA';
	Regions::reset_cache();
	check( '其他国家没绑方案 → 不给阶梯，也不借用别人的', null === Regions::for_cart( null ) );

	// 开关没打开就完全不动店面。
	$GLOBALS['t_options'][ Regions::OPTION ]['enabled'] = false;
	Country_Provider::$code = 'PR';
	Regions::reset_cache();
	check( '分流开关关着：店面照旧，一个字都不改', null === Regions::for_cart( null ) && false === Regions::active() );
	$GLOBALS['t_options'][ Regions::OPTION ]['enabled'] = true;
	check( '开关打开、且至少绑了一个方案才算生效', true === Regions::active() );

	// ---------- 5. 运费折扣的地区名单 ----------

	require_once dirname( __DIR__ ) . '/includes/shipping/class-discounts.php';
	use PeptideCore\Shipping\Discounts;

	check( '折扣没选地区 = 所有地区', Discounts::in_region( array( 'regions' => array() ), 'pr' ) );
	check( '只给 PR 的折扣，美国访客拿不到', ! Discounts::in_region( array( 'regions' => array( 'pr' ) ), 'us' ) );
	check( '只给 PR 的折扣，PR 访客拿得到', Discounts::in_region( array( 'regions' => array( 'pr' ) ), 'pr' ) );
	check( '地区判不出来时，限定地区的折扣一律不给（宁可不发，不可发错）', ! Discounts::in_region( array( 'regions' => array( 'pr' ) ), null ) );
	check( '收费按收货地址判地区', 'pr' === Discounts::package_region( array( 'destination' => array( 'country' => 'US', 'state' => 'PR', 'postcode' => '00901' ) ) ) );
	check( '收货地址是美国本土就按美国收', 'us' === Discounts::package_region( array( 'destination' => array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001' ) ) ) );

	fwrite( STDOUT, "OK " . $GLOBALS['t_checks'] . " checks\n" );
}
