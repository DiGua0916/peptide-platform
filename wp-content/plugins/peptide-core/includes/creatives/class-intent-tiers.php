<?php
/**
 * 素材看板的意向分层（线 4）：把一次访问或一个访客分成 高 / 中 / 低 / 无效。
 *
 * 只有纯函数，不读数据库。输入是“特征”数组（Board 从访问表和事件表整理出来）：
 * - bot              bool|null  访问分类判为异常或已知机器人（null = 没分类过）
 * - ip_type          string     线 1 写入的 IP 类型（'' = 不知道）
 * - dwell_ms         int|null   停留时长（最后活动 - 开始）
 * - pageviews        int|null   打开页面数
 * - interactions     int|null   主动操作次数（看商品、点模块、选规格、加购、结账……；不含心跳、可见性、离开）
 * - scrolled         bool|null  是否滚动过（目前前端不上报滚动，null = 看不出）
 * - timezone         string     浏览器时区（'' = 不知道）
 * - language         string     浏览器语言，如 en-US（'' = 不知道）
 * - ip_country       string     IP 所在国家两位代码（'' = 不知道）
 * - carted / checkout bool      加过购物车 / 进过结账
 * - products         int[]      按顺序看过的商品
 * - first_product_pv int        第一次看商品详情时是这次访问的第几个页面（0 = 没看）
 * - prior_products   int[]      以前的访问看过的商品
 * - returning        bool       不是第一次来
 * - visits           int        这个访客累计来了几次
 * - variant          bool       切换过规格
 * - report_faq       bool       看过检测报告 / FAQ
 *
 * 规则（需求 2026-11-11，线 4 任务）：
 * - 无效：机器人或访问分类异常；IP 是机房 / VPN / 代理；停留不到 5 秒且没有任何操作；时区和 IP 所在地明显冲突。
 * - 高：加过购物车或进过结账；回访并直奔以前看过的商品；企业 / 高校 IP 且深度浏览。
 * - 中：看了 2 个以上商品或切换过规格；看过检测报告 / FAQ；回访 2 次以上（累计来 3 次以上）。
 * - 低：其余有效访客。
 * 冲突时取更高一档，但无效优先。缺的数据按“看不出”处理，不会因为缺数据判成无效。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Intent_Tiers {

	public const HIGH    = 'high';
	public const MID     = 'mid';
	public const LOW     = 'low';
	public const INVALID = 'invalid';

	/** 档位从高到低（无效单独处理）。 */
	public const RANK = array( self::INVALID => -1, self::LOW => 0, self::MID => 1, self::HIGH => 2 );

	public const LABELS = array( self::HIGH => '高意向', self::MID => '中意向', self::LOW => '低意向', self::INVALID => '无效' );

	/** 判无效的 IP 类型（线 1 写入的取值，联调时对齐）。 */
	public const BAD_IP = array( 'hosting', 'datacenter', 'dc', 'vpn', 'proxy', 'tor', 'relay' );

	/** 算“企业 / 高校”的 IP 类型。 */
	public const ORG_IP = array( 'business', 'corporate', 'enterprise', 'education', 'edu', 'university' );

	/** 停留不到这么久且没有操作算无效（毫秒）。 */
	public const BOUNCE_MS = 5000;

	/** 深度浏览：页面数、看过的商品数、停留时长，满足任一条。 */
	public const DEEP_PAGES    = 5;
	public const DEEP_PRODUCTS = 3;
	public const DEEP_MS       = 180000;

	/** 直奔：第一次看商品是在前几个页面以内。 */
	public const DIRECT_PV = 2;

	/** 时区第一段 / 国家 → 大洲。只收常见的，不认识的国家不判冲突。 */
	private const TZ_CONTINENT = array(
		'America' => 'am', 'US' => 'am', 'Canada' => 'am', 'Brazil' => 'am', 'Mexico' => 'am', 'Chile' => 'am',
		'Europe'  => 'eu', 'Asia' => 'as', 'Africa' => 'af', 'Australia' => 'oc', 'Pacific' => 'oc',
	);
	private const COUNTRY_CONTINENT = array(
		'am' => array( 'US', 'CA', 'MX', 'BR', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC', 'UY', 'PY', 'BO', 'GT', 'CR', 'PA', 'DO', 'PR', 'JM', 'HN', 'SV', 'NI', 'CU' ),
		'eu' => array( 'GB', 'IE', 'FR', 'DE', 'ES', 'PT', 'IT', 'NL', 'BE', 'LU', 'CH', 'AT', 'DK', 'SE', 'NO', 'FI', 'IS', 'PL', 'CZ', 'SK', 'HU', 'RO', 'BG', 'GR', 'HR', 'SI', 'RS', 'UA', 'LT', 'LV', 'EE' ),
		'as' => array( 'CN', 'HK', 'MO', 'TW', 'JP', 'KR', 'SG', 'MY', 'TH', 'VN', 'PH', 'ID', 'IN', 'PK', 'BD', 'LK', 'AE', 'SA', 'IL', 'QA', 'KW' ),
		'af' => array( 'ZA', 'NG', 'KE', 'EG', 'MA', 'GH', 'TZ', 'ET' ),
		'oc' => array( 'AU', 'NZ' ),
	);

	/**
	 * 分层。
	 *
	 * @param array<string,mixed> $f 特征（见文件头）
	 */
	public static function tier( array $f ): string {
		return self::explain( $f )['tier'];
	}

	/**
	 * 分层和命中的规则（供导出和页面提示）。
	 *
	 * @return array{tier:string,rules:string[]}
	 */
	public static function explain( array $f ): array {
		$invalid = self::invalid_rules( $f );
		if ( $invalid ) {
			return array( 'tier' => self::INVALID, 'rules' => $invalid );
		}
		$high = array();
		if ( ! empty( $f['carted'] ) || ! empty( $f['checkout'] ) ) {
			$high[] = 'cart_or_checkout';
		}
		if ( self::direct_return( $f ) ) {
			$high[] = 'return_direct_product';
		}
		if ( in_array( self::ip_type( $f ), self::ORG_IP, true ) && self::deep( $f ) ) {
			$high[] = 'org_ip_deep';
		}
		if ( $high ) {
			return array( 'tier' => self::HIGH, 'rules' => $high );
		}
		$mid = array();
		if ( count( array_unique( self::ints( $f['products'] ?? array() ) ) ) >= 2 || ! empty( $f['variant'] ) ) {
			$mid[] = 'products_or_variant';
		}
		if ( ! empty( $f['report_faq'] ) ) {
			$mid[] = 'report_or_faq';
		}
		if ( (int) ( $f['visits'] ?? 0 ) >= 3 ) {
			$mid[] = 'returned_twice';
		}
		if ( $mid ) {
			return array( 'tier' => self::MID, 'rules' => $mid );
		}
		return array( 'tier' => self::LOW, 'rules' => array() );
	}

	/** @return string[] 命中的无效规则；空 = 有效（或看不出）。 */
	public static function invalid_rules( array $f ): array {
		$out = array();
		if ( true === ( $f['bot'] ?? null ) ) {
			$out[] = 'bot_or_abnormal';
		}
		if ( in_array( self::ip_type( $f ), self::BAD_IP, true ) ) {
			$out[] = 'datacenter_or_vpn';
		}
		$dwell = $f['dwell_ms'] ?? null;
		$moved = $f['interactions'] ?? null;
		// 停留和操作都要“看得出”：时长已知且 < 5 秒，操作次数已知且为 0，也没有滚动、没有翻页。
		if ( is_int( $dwell ) && $dwell < self::BOUNCE_MS && is_int( $moved ) && 0 === $moved && true !== ( $f['scrolled'] ?? null ) && (int) ( $f['pageviews'] ?? 1 ) <= 1 && empty( $f['carted'] ) && empty( $f['checkout'] ) ) {
			$out[] = 'bounce_under_5s';
		}
		if ( self::geo_conflict( (string) ( $f['timezone'] ?? '' ), (string) ( $f['language'] ?? '' ), (string) ( $f['ip_country'] ?? '' ) ) ) {
			$out[] = 'geo_conflict';
		}
		return $out;
	}

	/**
	 * 时区（缺时区时用语言里的地区码，如 en-US）所在大洲和 IP 国家所在大洲不一样 = 明显冲突。
	 * 任何一边看不出（空、不认识）都不算冲突。只看语言本身不判（海外华人用中文很正常）。
	 */
	public static function geo_conflict( string $timezone, string $language, string $ip_country ): bool {
		$ip = self::country_continent( strtoupper( trim( $ip_country ) ) );
		if ( '' === $ip ) {
			return false;
		}
		$local = '';
		$tz    = trim( $timezone );
		if ( '' !== $tz && 'UTC' !== strtoupper( $tz ) && 'ETC' !== strtoupper( strtok( $tz, '/' ) ) ) {
			$local = self::TZ_CONTINENT[ (string) strtok( $tz, '/' ) ] ?? '';
		} elseif ( preg_match( '/^[a-z]{2,3}[-_]([A-Za-z]{2})$/', trim( $language ), $m ) ) {
			$local = self::country_continent( strtoupper( $m[1] ) );
		}
		return '' !== $local && $local !== $ip;
	}

	public static function country_continent( string $country ): string {
		foreach ( self::COUNTRY_CONTINENT as $continent => $list ) {
			if ( in_array( $country, $list, true ) ) {
				return $continent;
			}
		}
		return '';
	}

	/** 回访，并且这次访问很早（前 2 个页面内）就打开了以前看过的商品。 */
	public static function direct_return( array $f ): bool {
		if ( empty( $f['returning'] ) ) {
			return false;
		}
		$products = self::ints( $f['products'] ?? array() );
		$pv       = (int) ( $f['first_product_pv'] ?? 0 );
		return $products && $pv > 0 && $pv <= self::DIRECT_PV && in_array( $products[0], self::ints( $f['prior_products'] ?? array() ), true );
	}

	public static function deep( array $f ): bool {
		return (int) ( $f['pageviews'] ?? 0 ) >= self::DEEP_PAGES
			|| count( array_unique( self::ints( $f['products'] ?? array() ) ) ) >= self::DEEP_PRODUCTS
			|| (int) ( $f['dwell_ms'] ?? 0 ) >= self::DEEP_MS;
	}

	/**
	 * 一个访客的多次访问合成一份特征：时长、页面数、操作相加；布尔量取“有过”；
	 * 看不出的（null）只有在所有访问都看不出时才保持 null。IP 类型取最“坏”的（机房 / VPN 优先，其次企业）。
	 * 回访、直奔用第一份特征以外的访问决定（Board 已经在每次访问上算好 returning / prior_products）。
	 *
	 * @param array<int,array<string,mixed>> $list 同一个访客按时间排好的访问特征
	 */
	public static function merge( array $list ): array {
		$out = array(
			'bot' => null, 'ip_type' => '', 'dwell_ms' => null, 'pageviews' => null, 'interactions' => null, 'scrolled' => null,
			'timezone' => '', 'language' => '', 'ip_country' => '', 'carted' => false, 'checkout' => false, 'products' => array(),
			'first_product_pv' => 0, 'prior_products' => array(), 'returning' => false, 'visits' => 0, 'variant' => false, 'report_faq' => false,
			'direct' => false,
		);
		$geo_conflict = false;
		foreach ( $list as $f ) {
			if ( null !== ( $f['bot'] ?? null ) ) {
				$out['bot'] = ( true === $out['bot'] ) || true === $f['bot'];
			}
			$ip   = self::ip_type( $f );
			$rank = static fn( string $t ): int => in_array( $t, self::BAD_IP, true ) ? 3 : ( in_array( $t, self::ORG_IP, true ) ? 2 : ( '' !== $t ? 1 : 0 ) );
			if ( $rank( $ip ) > $rank( $out['ip_type'] ) ) {
				$out['ip_type'] = $ip;
			}
			foreach ( array( 'dwell_ms', 'pageviews', 'interactions' ) as $k ) {
				if ( is_int( $f[ $k ] ?? null ) ) {
					$out[ $k ] = (int) $out[ $k ] + $f[ $k ];
				}
			}
			if ( null !== ( $f['scrolled'] ?? null ) ) {
				$out['scrolled'] = true === $out['scrolled'] || true === $f['scrolled'];
			}
			foreach ( array( 'carted', 'checkout', 'variant', 'report_faq', 'returning' ) as $k ) {
				$out[ $k ] = $out[ $k ] || ! empty( $f[ $k ] );
			}
			$out['products'] = array_merge( $out['products'], self::ints( $f['products'] ?? array() ) );
			$out['visits']   = max( $out['visits'], (int) ( $f['visits'] ?? 0 ) );
			$out['direct']   = $out['direct'] || self::direct_return( $f );
			// 时区 / 语言 / 国家：任何一次访问明显冲突就算冲突；否则留第一份看得出的。
			$geo_conflict = $geo_conflict || self::geo_conflict( (string) ( $f['timezone'] ?? '' ), (string) ( $f['language'] ?? '' ), (string) ( $f['ip_country'] ?? '' ) );
			foreach ( array( 'timezone', 'language', 'ip_country' ) as $k ) {
				if ( '' === $out[ $k ] && '' !== (string) ( $f[ $k ] ?? '' ) ) {
					$out[ $k ] = (string) $f[ $k ];
				}
			}
		}
		if ( $geo_conflict ) {
			$out['geo_conflict'] = true;
		}
		// “直奔”已经在单次访问上判断过，合并后用一个已知成立的组合表达出来。
		if ( $out['direct'] ) {
			$out['returning']        = true;
			$out['prior_products']   = array( -1 );
			$out['products']         = array_merge( array( -1 ), $out['products'] );
			$out['first_product_pv'] = 1;
		}
		return $out;
	}

	/** 合并后的访客分层（合并特征里带 geo_conflict 时直接无效）。 */
	public static function visitor_tier( array $visit_features ): string {
		$m = self::merge( $visit_features );
		if ( ! empty( $m['geo_conflict'] ) ) {
			return self::INVALID;
		}
		return self::tier( $m );
	}

	private static function ip_type( array $f ): string {
		return strtolower( trim( (string) ( $f['ip_type'] ?? '' ) ) );
	}

	/** @return int[] */
	private static function ints( $list ): array {
		return array_values( array_map( 'intval', is_array( $list ) ? $list : array() ) );
	}
}
