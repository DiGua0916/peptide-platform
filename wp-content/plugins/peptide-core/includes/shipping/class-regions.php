<?php
/**
 * 运费地区分流（SHIPPING-REGION-01，店主 2026-11-28）。
 *
 * 要解决的事：美国本土和波多黎各用两套完全不同的运费门槛，而且**两边不能看见对方的数字**。
 * 波多黎各的免邮线要卡在发卡行 $300 拒付墙以内，所以必然比美国低；美国客户看到波多黎各更便宜、
 * 波多黎各客户看到美国门槛更高，都会出事。所以隔离要在服务端做，不能靠前端挑。
 *
 * 三条规矩，顺序不能换：
 *
 * 1. **收费永远按地址。** 这是店主 2026-11-28 拍的。收费本来就走 Plans::quote()，它读的是包裹的
 *    收货地址（国家 + 州 + 邮编），所以只要把两个方案的区域配成「US 除 PR 的州」和「US 仅 PR 州」，
 *    收费侧天然就是按地址，没有新代码路径，也就没有新的出错面。本类不参与收费。
 * 2. **文案先按 IP，地址一填就跟地址。** 访客刚进站没有地址，只能用 GeoIP；一旦填了国家 / 邮编，
 *    立刻改用地址，和收费保持一致。否则会出现「看到 $285 包邮、结账按 $380 收」的投诉。
 * 3. **一次请求只算出一个地区，也只吐出那一个地区的数字。** 见 ladder()：它只读当前地区绑定的那
 *    一个方案，别的地区的方案根本不会被读出来，所以购物车接口的响应里不可能出现另一套门槛。
 *
 * 波多黎各有三种写法，必须归一（这是最容易出事故的点）：
 *   - IP / GeoIP 侧：独立国家码 PR；
 *   - 地址 / 运费区域侧：国家 US + 州 PR（2026-11-19 的 US_Territories 归一，国家下拉里已没有 PR）；
 *   - 还有老数据和手填：国家 US + 邮编 006/007/009，州却不是 PR。
 * 三种都要认，归到同一个 'pr'。邮编名单只有 US_Territories 一份，这里不另抄一遍。
 *
 * 数据存在选项 peptide_core_shipping_regions（不自动加载）。enabled = false 时本类只做判定，
 * 不改变店面任何现有行为 —— 门槛数字店主定下来之前，上线也是静默的。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

use PeptideCore\Tracking\Country_Provider;
use PeptideCore\US_Territories;

defined( 'ABSPATH' ) || exit;

final class Regions {

	public const OPTION = 'peptide_core_shipping_regions';

	/** 地区键。顺序就是后台的显示顺序。 */
	public const KEYS = array( 'us', 'pr', 'intl' );

	/** 本次请求的 GeoIP 结果（一次请求只查一次库；地址每次重算，因为它会在请求中途被填上）。 */
	private static ?array $ip_cache = null;

	// ---------- 配置 ----------

	public static function defaults(): array {
		return array(
			'enabled' => false,
			'default' => 'us',
			'plans'   => array( 'us' => 0, 'pr' => 0, 'intl' => 0 ),
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return self::normalize( is_array( $saved ) ? $saved : array() );
	}

	public static function normalize( array $raw ): array {
		$d           = self::defaults();
		$d['enabled'] = ! empty( $raw['enabled'] ) && 'false' !== $raw['enabled'];
		$default      = is_string( $raw['default'] ?? null ) ? strtolower( $raw['default'] ) : '';
		$d['default'] = in_array( $default, self::KEYS, true ) ? $default : 'us';
		$plans        = (array) ( $raw['plans'] ?? array() );
		foreach ( self::KEYS as $key ) {
			$d['plans'][ $key ] = absint( $plans[ $key ] ?? 0 );
		}
		return $d;
	}

	public static function save( array $raw ): array {
		$settings = self::normalize( $raw );
		update_option( self::OPTION, $settings, false );
		// 门槛变了，购物车里缓存的运费必须重算。
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		self::$ip_cache = null;
		return $settings;
	}

	/** 分流是否已经接管店面文案。没绑方案就等于没开，避免配了一半就上线。 */
	public static function active( ?array $settings = null ): bool {
		$settings = $settings ?? self::settings();
		if ( ! $settings['enabled'] ) {
			return false;
		}
		foreach ( $settings['plans'] as $id ) {
			if ( $id ) {
				return true;
			}
		}
		return false;
	}

	// ---------- 判定（纯函数，可单测） ----------

	/**
	 * 地址 → 地区。三种波多黎各写法都认。
	 *
	 * @param string $country 国家代码
	 * @param string $state   州代码
	 * @param string $zip     邮编（任意格式，内部只取数字）
	 * @return string|null 没有国家就返回 null（访客还没填地址）
	 */
	public static function from_address( string $country, string $state, string $zip ): ?string {
		$country = strtoupper( trim( $country ) );
		$state   = strtoupper( trim( $state ) );
		if ( '' === $country ) {
			return null;
		}
		// 老写法：国家直接填了 PR（US_Territories 会把它改成 US/PR，但归一发生之前也要判对）。
		if ( 'PR' === $country ) {
			return 'pr';
		}
		if ( 'US' === $country ) {
			return ( 'PR' === $state || US_Territories::is_pr_postcode( $zip ) ) ? 'pr' : 'us';
		}
		return 'intl';
	}

	/**
	 * GeoIP 国家码 → 地区。
	 *
	 * @param string|null $code 两位国家码
	 */
	public static function from_country_code( ?string $code ): ?string {
		if ( ! is_string( $code ) || 1 !== preg_match( '/\A[A-Z]{2}\z/D', $code ) ) {
			return null;
		}
		if ( 'PR' === $code ) {
			return 'pr';
		}
		return 'US' === $code ? 'us' : 'intl';
	}

	/** 当前顾客地址上的地区。收货地址优先，没有就用账单地址。 */
	public static function from_customer(): ?string {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$customer = WC()->customer ?? null;
		if ( ! $customer instanceof \WC_Customer ) {
			return null;
		}
		$base = self::base_country();
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			$country  = self::field( $customer, "get_{$type}_country" );
			$state    = self::field( $customer, "get_{$type}_state" );
			$postcode = self::field( $customer, "get_{$type}_postcode" );
			if ( ! self::entered( $country, $state, $postcode, $base ) ) {
				continue;
			}
			$region = self::from_address( $country, $state, $postcode );
			if ( null !== $region ) {
				return $region;
			}
		}
		return null;
	}

	/** 店铺所在国家。WooCommerce 会把它当成新访客地址的默认值，所以判定要把它排除掉。 */
	private static function base_country(): string {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->countries ) || ! is_object( WC()->countries ) ) {
			return '';
		}
		if ( ! is_callable( array( WC()->countries, 'get_base_country' ) ) ) {
			return '';
		}
		try {
			return strtoupper( trim( (string) WC()->countries->get_base_country() ) );
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	/**
	 * 这个地址是访客自己填的，还是 WooCommerce 给的默认值？
	 *
	 * 2026-11-28 在沙盒里实测出来的坑：WooCommerce 会把**每一个**新访客的国家预填成店铺所在国
	 * （这里是 US），哪怕人家一个字都没填。如果照单全收，from_customer() 永远返回 us，IP 这条路
	 * 等于是死的 —— 波多黎各的访客刚进站会看到美国的门槛，这正好是本次要避免的事。
	 *
	 * 所以：州或邮编有一个填了，就是真地址；只有国家、而且就是店铺所在国，那是默认值，让给 IP；
	 * 只有国家但不是店铺所在国（访客自己换过国家，或 WooCommerce 按 IP 预填过），仍然算真地址。
	 */
	private static function entered( string $country, string $state, string $postcode, string $base ): bool {
		if ( '' === trim( $country ) ) {
			return false;
		}
		if ( '' !== trim( $state ) || '' !== trim( $postcode ) ) {
			return true;
		}
		return '' === $base || strtoupper( trim( $country ) ) !== $base;
	}

	/** 读顾客地址的一个字段。读不到就当空，判定本身绝不能把结账打断。 */
	private static function field( object $customer, string $getter ): string {
		if ( ! is_callable( array( $customer, $getter ) ) ) {
			return '';
		}
		try {
			return (string) $customer->{$getter}();
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	/** 本次请求的 GeoIP 地区。查不到返回 null，绝不猜。 */
	public static function from_ip(): ?string {
		if ( null !== self::$ip_cache ) {
			return self::$ip_cache[0];
		}
		$region  = null;
		$address = $_SERVER['REMOTE_ADDR'] ?? null;
		if ( is_string( $address ) && '' !== $address ) {
			try {
				$result = Country_Provider::lookup( $address );
				if ( 'known' === ( $result['status'] ?? null ) ) {
					$region = self::from_country_code( $result['country_code'] ?? null );
				}
			} catch ( \Throwable $error ) {
				$region = null; // 库缺失 / 不可读：退回默认地区，不影响下单。
			}
		}
		self::$ip_cache = array( $region );
		return $region;
	}

	/**
	 * 当前访客的地区。地址 > IP > 默认。
	 *
	 * @return array{key:string,source:string}
	 */
	public static function current(): array {
		$region = self::from_customer();
		if ( null !== $region ) {
			return array( 'key' => $region, 'source' => 'address' );
		}
		$region = self::from_ip();
		if ( null !== $region ) {
			return array( 'key' => $region, 'source' => 'ip' );
		}
		return array( 'key' => self::settings()['default'], 'source' => 'default' );
	}

	/** 测试和 Store API 更新地址之后用。 */
	public static function reset_cache(): void {
		self::$ip_cache = null;
	}

	// ---------- 阶梯 ----------

	/** 这个地区绑定的方案。没绑或方案已删返回 null。 */
	public static function plan_for( string $key ): ?array {
		$id = (int) ( self::settings()['plans'][ $key ] ?? 0 );
		return $id ? Plans::get( $id ) : null;
	}

	/**
	 * 把一个方案的「按订单金额」运费拆成若干条线路（同名的运费算一条线路，比如「港联 5-8 天」和
	 * 「大陆联邦 3-5 天」各一条），每条按门槛从低到高排好。
	 *
	 * @return array<string,array<int,array{min:float,max:?float,fee:float}>>
	 */
	public static function tracks( array $plan ): array {
		$out = array();
		foreach ( (array) ( $plan['rates'] ?? array() ) as $rate ) {
			if ( empty( $rate['enabled'] ) || 'subtotal' !== ( $rate['rule'] ?? '' ) ) {
				continue;
			}
			$out[ (string) $rate['name'] ][] = array(
				'min' => (float) $rate['min'],
				'max' => null === $rate['max'] ? null : (float) $rate['max'],
				'fee' => (float) $rate['fee'],
			);
		}
		foreach ( $out as $name => $bands ) {
			usort( $bands, static fn( $a, $b ) => $a['min'] <=> $b['min'] );
			$out[ $name ] = $bands;
		}
		return $out;
	}

	/**
	 * 主力线路：起步价最便宜的那条（表里「标配主力 港联 / UPS」比「尊享加急 大陆联邦」便宜，
	 * 所以进度条跟的是标配那条）。并列时取先配置的一条。
	 */
	public static function primary_track( array $tracks ): ?string {
		$best = null;
		$fee  = null;
		foreach ( $tracks as $name => $bands ) {
			$start = null;
			foreach ( $bands as $band ) {
				if ( 0.0 === $band['min'] ) {
					$start = $band['fee'];
					break;
				}
			}
			$start = null === $start ? ( $bands ? $bands[0]['fee'] : null ) : $start;
			if ( null === $start ) {
				continue;
			}
			if ( null === $fee || $start < $fee ) {
				$fee  = $start;
				$best = $name;
			}
		}
		return $best;
	}

	/**
	 * 当前地区的运费阶梯。**只读当前地区绑定的那一个方案**，所以响应里不可能带上别的地区的数字。
	 *
	 * @param string $key      地区键
	 * @param float  $subtotal 商品金额（售价，未扣优惠码；和运费判定用同一个数）
	 * @return array|null 没绑方案、方案没有按金额的运费时返回 null
	 */
	public static function ladder( string $key, float $subtotal ): ?array {
		$plan = self::plan_for( $key );
		if ( null === $plan ) {
			return null;
		}
		$tracks  = self::tracks( $plan );
		$primary = self::primary_track( $tracks );
		if ( null === $primary ) {
			return null;
		}
		$bands = $tracks[ $primary ];
		$tiers = array();
		$now   = null;
		$free  = null;
		foreach ( $bands as $band ) {
			if ( $subtotal >= $band['min'] && ( null === $band['max'] || $subtotal < $band['max'] ) ) {
				$now = $band['fee'];
			}
			if ( null === $free && $band['fee'] <= 0.0 ) {
				$free = $band['min'];
			}
			if ( $band['min'] > 0.0 ) {
				$tiers[] = array(
					'min_minor' => (int) round( $band['min'] * 100 ),
					'fee_minor' => (int) round( $band['fee'] * 100 ),
					'free'      => $band['fee'] <= 0.0,
					'reached'   => $subtotal >= $band['min'],
				);
			}
		}
		$next = null;
		foreach ( $tiers as $tier ) {
			if ( ! $tier['reached'] ) {
				$next = array(
					'min_minor'       => $tier['min_minor'],
					'fee_minor'       => $tier['fee_minor'],
					'free'            => $tier['free'],
					'remaining_minor' => max( 0, $tier['min_minor'] - (int) round( $subtotal * 100 ) ),
				);
				break;
			}
		}
		return array(
			'region'            => $key,
			'track'             => $primary,
			'subtotal_minor'    => (int) round( $subtotal * 100 ),
			'current_fee_minor' => null === $now ? null : (int) round( $now * 100 ),
			'free_at_minor'     => null === $free ? null : (int) round( $free * 100 ),
			'tiers'             => $tiers,
			'next'              => $next,
		);
	}

	/**
	 * 给 Store API 的购物车用。分流没开时返回 null，店面照旧。
	 *
	 * @param mixed $cart WC_Cart
	 */
	public static function for_cart( $cart = null ): ?array {
		$settings = self::settings();
		if ( ! self::active( $settings ) ) {
			return null;
		}
		$subtotal = 0.0;
		if ( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ) {
			$subtotal = Discounts::package_subtotal( array( 'contents' => (array) $cart->get_cart() ) );
		}
		$current = self::current();
		$ladder  = self::ladder( $current['key'], $subtotal );
		if ( null === $ladder ) {
			return null;
		}
		$ladder['source'] = $current['source'];
		return $ladder;
	}

	/** 当前地区，给店面文案和页面缓存键用。分流没开时也给，判定本身没有副作用。 */
	public static function public_region(): array {
		$current = self::current();
		return array( 'region' => $current['key'], 'source' => $current['source'], 'active' => self::active() );
	}
}
