<?php
/**
 * 把访客记录里的技术字段说成人话（店主 2026-11-14：“这些给我写的简洁明了一些，现在这样我都看不出是哪里的”）。
 *
 * 原来后台直接显示 geo_region = Houston, Texas、timezone = America/Los_Angeles、language = en-US、isp = T-MOBILE-AS21928，
 * 店主看不出是哪个国家、哪种网络。这里统一转成中文说法：
 * - place()   美国 德州 · 休斯顿
 * - network() T-Mobile · 手机网络 （机房 / VPN 会标出来，那种多半不是真买家）
 * - clock()   客户那边现在几点
 * - language() 英语（美国）
 *
 * 全是纯函数，tests/plain-words-pure.php 直接测。
 *
 * @package PeptideCore\Tracking
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Plain_Words {

	/** 店铺卖的国家优先，其余按需补。 */
	private const COUNTRIES = array(
		'US' => '美国', 'PR' => '波多黎各', 'CA' => '加拿大', 'GB' => '英国', 'AU' => '澳大利亚',
		'DE' => '德国', 'FR' => '法国', 'NL' => '荷兰', 'ES' => '西班牙', 'IT' => '意大利',
		'MX' => '墨西哥', 'BR' => '巴西', 'JP' => '日本', 'KR' => '韩国', 'SG' => '新加坡',
		'CN' => '中国', 'HK' => '中国香港', 'TW' => '中国台湾', 'IN' => '印度', 'PH' => '菲律宾',
		'VI' => '美属维尔京群岛', 'GU' => '关岛', 'AS' => '美属萨摩亚', 'MP' => '北马里亚纳群岛',
	);

	/** 美国各州（GeoIP 给的是英文全称）。 */
	private const US_STATES = array(
		'Alabama' => '阿拉巴马', 'Alaska' => '阿拉斯加', 'Arizona' => '亚利桑那', 'Arkansas' => '阿肯色',
		'California' => '加州', 'Colorado' => '科罗拉多', 'Connecticut' => '康涅狄格', 'Delaware' => '特拉华',
		'District of Columbia' => '华盛顿特区', 'Florida' => '佛罗里达', 'Georgia' => '佐治亚', 'Hawaii' => '夏威夷',
		'Idaho' => '爱达荷', 'Illinois' => '伊利诺伊', 'Indiana' => '印第安纳', 'Iowa' => '艾奥瓦',
		'Kansas' => '堪萨斯', 'Kentucky' => '肯塔基', 'Louisiana' => '路易斯安那', 'Maine' => '缅因',
		'Maryland' => '马里兰', 'Massachusetts' => '马萨诸塞', 'Michigan' => '密歇根', 'Minnesota' => '明尼苏达',
		'Mississippi' => '密西西比', 'Missouri' => '密苏里', 'Montana' => '蒙大拿', 'Nebraska' => '内布拉斯加',
		'Nevada' => '内华达', 'New Hampshire' => '新罕布什尔', 'New Jersey' => '新泽西', 'New Mexico' => '新墨西哥',
		'New York' => '纽约', 'North Carolina' => '北卡罗来纳', 'North Dakota' => '北达科他', 'Ohio' => '俄亥俄',
		'Oklahoma' => '俄克拉荷马', 'Oregon' => '俄勒冈', 'Pennsylvania' => '宾夕法尼亚', 'Rhode Island' => '罗德岛',
		'South Carolina' => '南卡罗来纳', 'South Dakota' => '南达科他', 'Tennessee' => '田纳西', 'Texas' => '德州',
		'Utah' => '犹他', 'Vermont' => '佛蒙特', 'Virginia' => '弗吉尼亚', 'Washington' => '华盛顿州',
		'West Virginia' => '西弗吉尼亚', 'Wisconsin' => '威斯康星', 'Wyoming' => '怀俄明',
	);

	/** 美国时区说法。 */
	private const ZONES = array(
		'America/New_York' => '美东时间', 'America/Detroit' => '美东时间', 'America/Toronto' => '美东时间',
		'America/Chicago' => '美中时间', 'America/Winnipeg' => '美中时间',
		'America/Denver' => '美山地时间', 'America/Phoenix' => '美山地时间（亚利桑那）',
		'America/Los_Angeles' => '美西时间', 'America/Vancouver' => '美西时间',
		'America/Anchorage' => '阿拉斯加时间', 'Pacific/Honolulu' => '夏威夷时间',
		'America/Puerto_Rico' => '波多黎各时间', 'America/Halifax' => '大西洋时间',
		'Asia/Shanghai' => '北京时间', 'Asia/Hong_Kong' => '香港时间', 'Asia/Taipei' => '台北时间',
		'Europe/London' => '伦敦时间', 'UTC' => '世界标准时间',
	);

	private const LANGS = array(
		'en' => '英语', 'es' => '西班牙语', 'zh' => '中文', 'fr' => '法语', 'de' => '德语',
		'pt' => '葡萄牙语', 'it' => '意大利语', 'ja' => '日语', 'ko' => '韩语', 'ru' => '俄语',
		'ar' => '阿拉伯语', 'hi' => '印地语', 'vi' => '越南语', 'th' => '泰语', 'tl' => '他加禄语',
	);

	/** 语言标签里的地区后缀。419 是“拉丁美洲”。 */
	private const LANG_PLACES = array( '419' => '拉美', 'US' => '美国', 'GB' => '英国', 'AU' => '澳大利亚', 'CA' => '加拿大', 'MX' => '墨西哥', 'ES' => '西班牙', 'CN' => '中国', 'TW' => '台湾', 'HK' => '香港', 'BR' => '巴西', 'PR' => '波多黎各' );

	/** IP 类型：说法 + 要不要提醒（机房 / VPN 多半不是真买家）。 */
	private const IP_TYPES = array(
		'residential' => array( '家庭宽带', false ),
		'mobile'      => array( '手机网络', false ),
		'business'    => array( '公司网络', false ),
		'wireless'    => array( '无线网络', false ),
		'vpn'         => array( 'VPN', true ),
		'proxy'       => array( '代理', true ),
		'tor'         => array( 'Tor 匿名网络', true ),
		'hosting'     => array( '机房 / 服务器', true ),
		'datacenter'  => array( '机房 / 服务器', true ),
		'education'   => array( '学校网络', false ),
		'government'  => array( '政府网络', false ),
	);

	public static function country( string $code ): string {
		$code = strtoupper( trim( $code ) );
		return '' === $code ? '' : ( self::COUNTRIES[ $code ] ?? $code );
	}

	/** 州 / 省。美国的翻成中文并带“州”，其他原样。 */
	public static function region( string $region, string $country = '' ): string {
		$region = trim( $region );
		if ( '' === $region ) {
			return '';
		}
		if ( isset( self::US_STATES[ $region ] ) ) {
			$name = self::US_STATES[ $region ];
			return str_ends_with( $name, '州' ) || str_ends_with( $name, '特区' ) ? $name : $name . '州';
		}
		return $region;
	}

	/**
	 * 一句话说清人在哪：美国 德州 · 休斯顿。
	 * 国家总是放在最前面，店主一眼看出是哪个国家的客户。
	 */
	public static function place( string $country, string $region, string $city ): string {
		$c    = self::country( $country );
		$r    = self::region( $region, $country );
		$city = trim( $city );
		if ( '' !== $r && $r === $city ) {
			$r = '';
		}
		$head = trim( $c . ( '' !== $r ? ' ' . $r : '' ) );
		if ( '' === $head && '' === $city ) {
			return '';
		}
		return '' !== $city && '' !== $head ? $head . ' · ' . $city : ( $head ?: $city );
	}

	/** 运营商名字去掉 ASN 编号那串尾巴：T-MOBILE-AS21928 → T-Mobile。 */
	public static function carrier( string $isp ): string {
		$isp = trim( $isp );
		if ( '' === $isp ) {
			return '';
		}
		$isp = (string) preg_replace( '/[\s-]*AS\d+\s*$/i', '', $isp );
		$isp = (string) preg_replace( '/-\d{3,}\s*$/', '', $isp );
		$isp = trim( $isp, " \t-_" );
		if ( '' === $isp ) {
			return '';
		}
		// 全大写的（ATT-INTERNET4、CHARTER）排版上太吵，转成首字母大写；本来就有大小写的保持原样。
		if ( $isp === strtoupper( $isp ) ) {
			$isp = (string) preg_replace_callback( '/[A-Z][A-Z0-9]*/', static fn( $m ) => ucfirst( strtolower( $m[0] ) ), $isp );
		}
		return $isp;
	}

	/**
	 * 网络一句话：T-Mobile · 手机网络。机房 / VPN 这类会在第三位返回提醒。
	 *
	 * @return array{0:string,1:bool} [说法, 要不要当成可疑]
	 */
	public static function network( string $isp, string $ip_type ): array {
		$name          = self::carrier( $isp );
		$type          = strtolower( trim( $ip_type ) );
		[ $label, $odd ] = self::IP_TYPES[ $type ] ?? array( '', false );
		$text          = trim( $name . ( '' !== $name && '' !== $label ? ' · ' : '' ) . $label );
		return array( $text, $odd );
	}

	/** 语言：en-US → 英语（美国）。 */
	public static function language( string $tag ): string {
		$tag = trim( $tag );
		if ( '' === $tag ) {
			return '';
		}
		$parts = preg_split( '/[-_]/', $tag ) ?: array();
		$base  = self::LANGS[ strtolower( (string) ( $parts[0] ?? '' ) ) ] ?? '';
		$place = self::LANG_PLACES[ strtoupper( (string) ( $parts[1] ?? '' ) ) ] ?? '';
		if ( '' === $base ) {
			return $tag;
		}
		return '' !== $place ? $base . '（' . $place . '）' : $base;
	}

	/** 时区说法。认不出的（Etc/Unknown、Etc/GMT-8）返回空，别把乱码摆到店主面前。 */
	public static function zone( string $tz ): string {
		$tz = trim( $tz );
		if ( '' === $tz || str_starts_with( $tz, 'Etc/' ) ) {
			return '';
		}
		return self::ZONES[ $tz ] ?? str_replace( '_', ' ', (string) ( explode( '/', $tz )[1] ?? $tz ) );
	}

	/**
	 * 客户那边现在几点：美西时间 01:03（周五）。认不出时区就返回空。
	 *
	 * @param int $now_ms 现在（毫秒），测试传固定值
	 */
	public static function clock( string $tz, int $now_ms ): string {
		$name = self::zone( $tz );
		if ( '' === $name ) {
			return '';
		}
		try {
			$t = ( new \DateTimeImmutable( '@' . intdiv( $now_ms, 1000 ) ) )->setTimezone( new \DateTimeZone( $tz ) );
		} catch ( \Exception $e ) {
			return '';
		}
		$week = array( '周日', '周一', '周二', '周三', '周四', '周五', '周六' )[ (int) $t->format( 'w' ) ];
		return $name . ' ' . $t->format( 'H:i' ) . '（' . $week . '）';
	}
}
