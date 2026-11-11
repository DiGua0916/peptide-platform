<?php
/**
 * 素材归因（CR）店铺端的约定：广告参数、广告名、素材编号、每日上报的格式。
 *
 * 这里只有纯函数，不读数据库。采集（线 1）、素材与花费（线 2）、看板与导出（线 4）都按这里的规则读写；
 * 总后台（线 3）用 PeptideHq\Creatives::clean_report() 按同一份约定校验。约定全文见 docs/CONTRACT.md 的“CR 素材归因”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Contract {

	/** 投手在 Meta“网址参数”里填的固定模板。 */
	public const URL_TEMPLATE = 'utm_source={{site_source_name}}&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&placement={{placement}}&ad_name={{ad.name}}';

	/** 访问上要保存的广告参数（放宽后的白名单，线 1 在 Tracking\Attribution 里使用）。 */
	public const PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'placement', 'ad_name' );

	/** {{site_source_name}} 的取值。 */
	public const PLATFORMS = array( 'fb', 'ig', 'msg', 'an' );

	/** 访问的来源分类（pc_track_visits.source_class）。 */
	public const SOURCE_PAID     = 'paid';
	public const SOURCE_UNTAGGED = 'fb_untagged';
	public const SOURCE_OTHER    = 'other';

	/** 没登记的素材在统计和上报里用这个编号。 */
	public const UNREGISTERED = 'UNREG';

	/** 必填的素材属性。属性的可选值由总后台的词表下发（pc_ad_attribute_vocab）。 */
	public const REQUIRED_ATTRS = array( 'hook', 'fmt', 'angle' );

	/** 词表还没从总后台拉到时用的初版（和需求文档“功能三”一致）。 */
	public const DEFAULT_VOCAB = array(
		array( 'attr' => 'hook', 'value' => 'lab', 'label' => '检测报告' ),
		array( 'attr' => 'hook', 'value' => 'price', 'label' => '价格' ),
		array( 'attr' => 'hook', 'value' => 'ship', 'label' => '发货速度' ),
		array( 'attr' => 'hook', 'value' => 'q', 'label' => '提问式' ),
		array( 'attr' => 'fmt', 'value' => 'vid', 'label' => '视频' ),
		array( 'attr' => 'fmt', 'value' => 'img', 'label' => '单图' ),
		array( 'attr' => 'fmt', 'value' => 'car', 'label' => '轮播' ),
		array( 'attr' => 'fmt', 'value' => 'ugc', 'label' => '用户视角' ),
		array( 'attr' => 'angle', 'value' => 'purity', 'label' => '纯度' ),
		array( 'attr' => 'angle', 'value' => 'price', 'label' => '价格' ),
		array( 'attr' => 'angle', 'value' => 'trust', 'label' => '信任' ),
		array( 'attr' => 'angle', 'value' => 'range', 'label' => '品类齐全' ),
		array( 'attr' => 'lang', 'value' => 'en', 'label' => '英文' ),
		array( 'attr' => 'lang', 'value' => 'es', 'label' => '西班牙文' ),
	);

	/** 每次上报最多多少行（一行 = 一天里的一个素材）。 */
	public const MAX_ROWS = 500;

	/** 上报行里的计数字段（非负整数）。 */
	public const COUNTS = array( 'visitors', 'valid_visitors', 'add_to_cart', 'checkout', 'cashier', 'orders', 'sales_cents', 'spend_cents', 'impressions', 'link_clicks' );

	/** 意向分层的键（和《客户分析师 v2》的分层一致）。 */
	public const INTENT_TIERS = array( 'high', 'mid', 'low', 'invalid' );

	// ---------- 广告参数 ----------

	/** Meta 的广告 / 广告组 / 广告系列 ID：纯数字。广告预览时是没替换的 {{ad.id}}，算无效。 */
	public static function valid_meta_id( string $id ): bool {
		return 1 === preg_match( '/^\d{6,25}$/', $id );
	}

	/** 素材编号：C 加至少 4 位数字，每家店独立计数。 */
	public static function valid_code( string $code ): bool {
		return 1 === preg_match( '/^C\d{4,9}$/', $code );
	}

	/** 第 n 个素材的编号（从 1 开始）。 */
	public static function code_for( int $n ): string {
		return 'C' . str_pad( (string) max( 1, $n ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * 一次访问带来的广告参数（已经过白名单）是否完整有效：平台、广告、广告组、广告系列都要在。
	 *
	 * @param array<string,string> $p
	 */
	public static function params_valid( array $p ): bool {
		return in_array( (string) ( $p['utm_source'] ?? '' ), self::PLATFORMS, true )
			&& 'paid' === (string) ( $p['utm_medium'] ?? '' )
			&& self::valid_meta_id( (string) ( $p['utm_content'] ?? '' ) )
			&& self::valid_meta_id( (string) ( $p['utm_term'] ?? '' ) )
			&& self::valid_meta_id( (string) ( $p['utm_campaign'] ?? '' ) );
	}

	/**
	 * 来源分类：带完整参数 = paid；没参数但看得出来自 FB / IG = fb_untagged；其余 = other。
	 *
	 * @param array<string,string> $p  白名单内的参数
	 */
	public static function source_class( array $p, bool $has_fbclid, string $referrer_host, string $user_agent ): string {
		if ( self::params_valid( $p ) ) {
			return self::SOURCE_PAID;
		}
		$host = strtolower( $referrer_host );
		$from = (bool) preg_match( '/(^|\.)(facebook|instagram)\.com$/', $host );
		$app  = (bool) preg_match( '/FBAN|FBAV|Instagram/', $user_agent );
		if ( '' === (string) ( $p['utm_source'] ?? '' ) && ( $has_fbclid || $from || $app ) ) {
			return self::SOURCE_UNTAGGED;
		}
		return self::SOURCE_OTHER;
	}

	/** FB / IG 内置浏览器：'fb'、'ig' 或空。 */
	public static function in_app( string $user_agent ): string {
		if ( str_contains( $user_agent, 'Instagram' ) ) {
			return 'ig';
		}
		return preg_match( '/FBAN|FBAV|FB_IAB/', $user_agent ) ? 'fb' : '';
	}

	// ---------- 广告名 ----------

	/**
	 * 拆开广告名：C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1。
	 * 编号必须在最前面；大小写和多余空格不影响；看不懂的“属性-取值”放进 custom。
	 *
	 * @return array{code:string,attrs:array<string,string>,custom:array<string,string>,version:int}
	 */
	public static function parse_ad_name( string $name ): array {
		$out   = array( 'code' => '', 'attrs' => array(), 'custom' => array(), 'version' => 0 );
		$parts = array_values( array_filter( array_map( 'trim', explode( '_', strtolower( trim( $name ) ) ) ), 'strlen' ) );
		if ( ! $parts ) {
			return $out;
		}
		$first = strtoupper( $parts[0] );
		if ( self::valid_code( $first ) ) {
			$out['code'] = $first;
			array_shift( $parts );
		}
		$known = array_merge( self::REQUIRED_ATTRS, array( 'lang' ) );
		foreach ( $parts as $part ) {
			if ( preg_match( '/^v(\d{1,4})$/', $part, $m ) ) {
				$out['version'] = (int) $m[1];
				continue;
			}
			$pair = explode( '-', $part, 2 );
			if ( 2 !== count( $pair ) || ! preg_match( '/^[a-z][a-z0-9]{0,15}$/', $pair[0] ) || ! preg_match( '/^[a-z0-9]{1,24}$/', $pair[1] ) ) {
				continue;
			}
			if ( in_array( $pair[0], $known, true ) ) {
				$out['attrs'][ $pair[0] ] = $pair[1];
			} else {
				$out['custom'][ $pair[0] ] = $pair[1];
			}
		}
		return $out;
	}

	/**
	 * 按登记的属性生成规范广告名（素材库“一键复制”用）。
	 *
	 * @param array<string,string> $attrs
	 */
	public static function build_ad_name( string $code, array $attrs, int $version = 0 ): string {
		$parts = array( $code );
		foreach ( array_merge( self::REQUIRED_ATTRS, array( 'lang' ) ) as $key ) {
			if ( isset( $attrs[ $key ] ) && '' !== $attrs[ $key ] ) {
				$parts[] = $key . '-' . strtolower( $attrs[ $key ] );
			}
		}
		if ( $version > 0 ) {
			$parts[] = 'v' . $version;
		}
		return implode( '_', $parts );
	}

	/**
	 * 属性是否齐全且都在词表里。返回缺少或不认识的属性名，空数组 = 合规。
	 *
	 * @param array<string,string>                       $attrs
	 * @param array<int,array{attr:string,value:string}> $vocab 启用中的词表
	 * @return string[]
	 */
	public static function attr_problems( array $attrs, array $vocab ): array {
		$allowed = array();
		foreach ( $vocab as $item ) {
			$allowed[ $item['attr'] ][] = $item['value'];
		}
		$bad = array();
		foreach ( self::REQUIRED_ATTRS as $key ) {
			if ( ! isset( $attrs[ $key ] ) || ! in_array( $attrs[ $key ], $allowed[ $key ] ?? array(), true ) ) {
				$bad[] = $key;
			}
		}
		if ( isset( $attrs['lang'] ) && ! in_array( $attrs['lang'], $allowed['lang'] ?? array(), true ) ) {
			$bad[] = 'lang';
		}
		return $bad;
	}

	// ---------- 每日上报（店铺 → 总后台） ----------

	/**
	 * 整理一行上报：一天里的一个素材。缺的计数补 0，负数和非数字当 0，多余字段丢掉。
	 * 总后台用同样规则校验（PeptideHq\Creatives::clean_row）。
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|null 编号不合规时为 null
	 */
	public static function report_row( array $row ): ?array {
		$code = (string) ( $row['code'] ?? '' );
		if ( self::UNREGISTERED !== $code && ! self::valid_code( $code ) ) {
			return null;
		}
		$text = static function ( $map ): array {
			$out = array();
			foreach ( is_array( $map ) ? $map : array() as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) && preg_match( '/^[a-z][a-z0-9]{0,15}$/', $k ) && preg_match( '/^[a-z0-9]{1,24}$/', (string) $v ) ) {
					$out[ $k ] = (string) $v;
				}
			}
			ksort( $out );
			return $out;
		};
		$int = static fn( $v ): int => is_int( $v ) && $v > 0 ? $v : 0;
		$out = array(
			'code'   => $code,
			'attrs'  => $text( $row['attrs'] ?? array() ),
			'custom' => $text( $row['custom'] ?? array() ),
			'thumb'  => is_string( $row['thumb'] ?? null ) && preg_match( '#^https://#', $row['thumb'] ) ? substr( $row['thumb'], 0, 500 ) : '',
			'intent' => array(),
		);
		foreach ( self::INTENT_TIERS as $tier ) {
			$out['intent'][ $tier ] = $int( $row['intent'][ $tier ] ?? 0 );
		}
		foreach ( self::COUNTS as $key ) {
			$out[ $key ] = $int( $row[ $key ] ?? 0 );
		}
		return $out;
	}

	/**
	 * 一天的上报请求体。$day 是店铺时区的日期（YYYY-MM-DD）。
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,mixed>|null 日期不对或行数超限时为 null
	 */
	public static function report_payload( string $day, string $timezone, array $rows, int $untagged_visitors ): ?array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || count( $rows ) > self::MAX_ROWS ) {
			return null;
		}
		$clean = array();
		foreach ( $rows as $row ) {
			$r = is_array( $row ) ? self::report_row( $row ) : null;
			if ( null !== $r ) {
				$clean[ $r['code'] ] = $r; // 同一天同一个编号只留最后一行。
			}
		}
		ksort( $clean );
		return array(
			'day'      => $day,
			'tz'       => substr( $timezone, 0, 64 ),
			'rows'     => array_values( $clean ),
			'untagged' => array( 'visitors' => max( 0, $untagged_visitors ) ),
		);
	}
}
