<?php
/**
 * 波多黎各地址统一成「国家 US + 州 PR」（店主 2026-11-19）。
 *
 * 真的把 PeptideCore\US_Territories 载进来，只把 WordPress 和 WooCommerce 换成桩。
 * 不碰数据库、不需要 WordPress。
 *
 * 要证明的事：
 * 1. 三种旧写法（PR/空、PR/城市名、美国 + 波多黎各邮编但州填错）一律收成 US/PR；
 *    已经正确的 US/PR、普通美国地址、美属维尔京群岛（008 邮编）都不动。
 * 2. 国家下拉里没有 Puerto Rico，美国的州列表里有 —— 新顾客只有一个入口。
 * 3. **旧入口不能把人卡死。** PR 已经从可配送国家里去掉，而算运费用的是顾客对象的地址：
 *    地址里存着「国家 = PR」的老客，必须在算运费之前就被改成 US/PR，否则他们连运费都选不到。
 *    这是 F1 2026-11-19 在沙盒里测出来的缺陷，只在建单时改写是不够的。
 *    这里按真实的钩子顺序验证：顾客对象 → 算运费 → 建单。
 * 4. 经典结账提交的字段也先改写（PR 不在可配送国家里，不改会被校验挡下来）。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['t_hooks']  = array();
	$GLOBALS['t_checks'] = 0;

	function check( string $label, bool $ok ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$GLOBALS['t_checks']++;
	}

	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['t_hooks'][ $hook ][] = $cb; return true; }
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return add_filter( $hook, $cb, $priority, $args ); }
	function __( $text, $domain = '' ) { return $text; }

	/** 地址对象的桩：WC_Order 和 WC_Customer 的读写方法同名，插件就是靠这一点共用一套改写。 */
	abstract class T_Address {
		public array $f = array();
		public int $saves = 0;
		public function __construct( array $fields = array() ) { $this->f = $fields; }
		public function __call( string $name, array $args ) {
			if ( preg_match( '/^(get|set)_(billing|shipping)_(country|state|postcode)$/', $name, $m ) ) {
				if ( 'get' === $m[1] ) { return (string) ( $this->f[ $m[2] . '_' . $m[3] ] ?? '' ); }
				$this->f[ $m[2] . '_' . $m[3] ] = (string) $args[0];
				return null;
			}
			throw new \BadMethodCallException( $name );
		}
		public function save(): void { $this->saves++; }
		/** 账单和收货两边的「国家/州」，方便断言。 */
		public function pair(): array {
			return array(
				$this->f['billing_country'] . '/' . $this->f['billing_state'],
				$this->f['shipping_country'] . '/' . $this->f['shipping_state'],
			);
		}
	}
	class WC_Order extends T_Address {}
	class WC_Customer extends T_Address {}

	/**
	 * 运费：配送方案按「国家 + 州」匹配，覆盖美国全部州的方案自然包含 PR 州。
	 * PR 已经从可配送国家里去掉，所以国家还是 PR 的地址一个方案都匹配不到 —— 那就是结不了账。
	 */
	function t_shipping_rate( string $country, string $state ): ?float {
		$allowed = \PeptideCore\US_Territories::drop_states_from_countries( array( 'US' => 'United States', 'PR' => 'Puerto Rico', 'CA' => 'Canada' ) );
		if ( ! isset( $allowed[ $country ] ) ) { return null; }
		return 'US' === $country ? 55.0 : 25.0;
	}
}

namespace PeptideCore {

	require_once __DIR__ . '/../includes/class-us-territories.php';

	US_Territories::register_hooks();

	// ---------- 1. 改写规则 ----------
	// [国家, 州, 邮编] => 期望的 [国家, 州]，null = 不动
	$cases = array(
		'PR/空（选了国家 Puerto Rico，州字段被 WooCommerce 隐藏）' => array( array( 'PR', '', '00901' ), array( 'US', 'PR' ) ),
		'PR/Trujillo（2026-11-11 那次把市镇写进了州字段）'          => array( array( 'PR', 'TRUJILLO', '00976' ), array( 'US', 'PR' ) ),
		'PR/Cidra, Puerto Rico'                                     => array( array( 'PR', 'CIDRA, PUERTO RICO', '00739' ), array( 'US', 'PR' ) ),
		'美国 + 006 邮编但州填成别的'                               => array( array( 'US', 'NY', '00646' ), array( 'US', 'PR' ) ),
		'美国 + 007 邮编、州空着'                                   => array( array( 'US', '', '00739' ), array( 'US', 'PR' ) ),
		'美国 + 009 邮编'                                           => array( array( 'US', 'FL', '00901' ), array( 'US', 'PR' ) ),
	);
	foreach ( $cases as $label => [ $in, $want ] ) {
		\check( "改写：$label → US/PR", $want === US_Territories::normalize( $in[0], $in[1], $in[2] ) );
	}

	$untouched = array(
		'已经正确的 US/PR'                      => array( 'US', 'PR', '00901' ),
		'普通美国地址'                          => array( 'US', 'CA', '90210' ),
		'美属维尔京群岛（008 邮编，不是波多黎各）' => array( 'US', 'VI', '00801' ),
		'美国、邮编空着'                        => array( 'US', 'TX', '' ),
		'加拿大'                                => array( 'CA', 'ON', 'M5V3L9' ),
	);
	foreach ( $untouched as $label => $in ) {
		\check( "不动：$label", null === US_Territories::normalize( $in[0], $in[1], $in[2] ) );
	}

	// ---------- 2. 只留一个入口 ----------
	$countries = US_Territories::drop_states_from_countries( array( 'US' => 'United States', 'PR' => 'Puerto Rico', 'CA' => 'Canada' ) );
	\check( '国家下拉里没有 Puerto Rico', ! isset( $countries['PR'] ) && isset( $countries['US'] ) && isset( $countries['CA'] ) );
	$states = US_Territories::states( array( 'US' => array( 'CA' => 'California' ) ) );
	\check( '美国的州列表里有 Puerto Rico，原有的州没被挤掉', 'Puerto Rico' === $states['US']['PR'] && 'California' === $states['US']['CA'] );
	\check( '美国本身没开放配送时不动国家列表（否则波多黎各顾客连入口都没有）', array( 'CA' => 'Canada' ) === US_Territories::drop_states_from_countries( array( 'CA' => 'Canada' ) ) );

	// ---------- 3. 旧入口：老客不能被卡死（F1 2026-11-19） ----------
	// 地址里存着「国家 = PR」的老客，按真实顺序走一遍：顾客对象 → 算运费 → 建单。
	$old = new \WC_Customer( array(
		'billing_country' => 'PR', 'billing_state' => 'TRUJILLO', 'billing_postcode' => '00976',
		'shipping_country' => 'PR', 'shipping_state' => 'TRUJILLO', 'shipping_postcode' => '00976',
	) );
	\check( '前提：改写之前这种地址一个配送方案都匹配不到（这就是结不了账的原因）', null === \t_shipping_rate( 'PR', 'TRUJILLO' ) );

	US_Territories::normalize_customer( $old );
	\check( '算运费之前顾客地址已经是 US/PR', array( 'US/PR', 'US/PR' ) === $old->pair() );
	\check( '改到了才保存，所以只会写一次', 1 === $old->saves );
	\check( '现在算得出运费了', 55.0 === \t_shipping_rate( $old->f['shipping_country'], $old->f['shipping_state'] ) );

	US_Territories::normalize_customer( $old );
	\check( '已经是 US/PR 的顾客不会被重复保存', 1 === $old->saves );

	$order = new \WC_Order( array(
		'billing_country' => 'PR', 'billing_state' => '', 'billing_postcode' => '00901',
		'shipping_country' => 'PR', 'shipping_state' => '', 'shipping_postcode' => '00901',
	) );
	US_Territories::normalize_order( $order );
	\check( '建单时的兜底还在', array( 'US/PR', 'US/PR' ) === $order->pair() );
	\check( '订单由 WooCommerce 随后保存，这里不自己存', 0 === $order->saves );

	// 普通美国顾客一点都不受影响。
	$us = new \WC_Customer( array(
		'billing_country' => 'US', 'billing_state' => 'CA', 'billing_postcode' => '90210',
		'shipping_country' => 'US', 'shipping_state' => 'CA', 'shipping_postcode' => '90210',
	) );
	US_Territories::normalize_customer( $us );
	\check( '普通美国顾客不被改写、不被保存', array( 'US/CA', 'US/CA' ) === $us->pair() && 0 === $us->saves );

	// 只有收货地址是旧写法（账单填的是别人的卡）。
	$mixed = new \WC_Customer( array(
		'billing_country' => 'US', 'billing_state' => 'NY', 'billing_postcode' => '10001',
		'shipping_country' => 'PR', 'shipping_state' => 'CIDRA', 'shipping_postcode' => '00739',
	) );
	US_Territories::normalize_customer( $mixed );
	\check( '只改该改的那一边', array( 'US/NY', 'US/PR' ) === $mixed->pair() );

	// ---------- 4. 经典结账提交的字段 ----------
	$posted = US_Territories::normalize_posted( array(
		'billing_country' => 'PR', 'billing_state' => 'San lorenzo', 'billing_postcode' => '00754',
		'shipping_country' => 'US', 'shipping_state' => 'NY', 'shipping_postcode' => '00646',
		'billing_email' => 'buyer@example.test',
	) );
	\check( '提交的账单地址先改写（否则 PR 不在可配送国家里会被校验挡下）', 'US' === $posted['billing_country'] && 'PR' === $posted['billing_state'] );
	\check( '提交的收货地址按邮编改写', 'US' === $posted['shipping_country'] && 'PR' === $posted['shipping_state'] );
	\check( '其他字段原样不动', 'buyer@example.test' === $posted['billing_email'] );
	$kept = array( 'billing_country' => 'US', 'billing_state' => 'CA', 'billing_postcode' => '90210' );
	\check( '普通美国地址提交上来不动', $kept === US_Territories::normalize_posted( $kept ) );
	\check( '没有收货字段时不会凭空造出来（勾了「收货地址同账单」）', ! isset( US_Territories::normalize_posted( array( 'billing_country' => 'US', 'billing_state' => 'CA', 'billing_postcode' => '90210' ) )['shipping_country'] ) );
	\check( '不是数组就原样返回', 'nonsense' === US_Territories::normalize_posted( 'nonsense' ) );

	// ---------- 5. 钩子真的挂上了 ----------
	foreach ( array(
		'woocommerce_countries_allowed_countries',
		'woocommerce_countries_shipping_countries',
		'woocommerce_before_calculate_totals',
		'woocommerce_store_api_cart_update_customer_from_request',
		'woocommerce_customer_save_address',
		'woocommerce_checkout_posted_data',
		'woocommerce_checkout_create_order',
		'woocommerce_store_api_checkout_update_order_from_request',
	) as $hook ) {
		\check( "挂上了钩子：$hook", ! empty( $GLOBALS['t_hooks'][ $hook ] ) );
	}

	echo 'PASS ', $GLOBALS['t_checks'], " Puerto Rico address assertions\n";
}
