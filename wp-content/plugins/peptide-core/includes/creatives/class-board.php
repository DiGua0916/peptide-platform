<?php
/**
 * 素材看板的数据（线 4）：把访问、素材、广告、花费汇总成看板的行，算指标、样本不足、漏参提醒。
 *
 * 分两层：
 * - 纯函数（aggregate / metrics / visit_features / untagged_alert / missing_params …）：不碰数据库，纯 PHP 测试直接调用；
 * - load()：用 $wpdb 按 docs/CONTRACT.md“CR 素材归因”里的表和列把数据读出来，交给纯函数。
 *
 * 口径：
 * - 只算 source_class = paid 的访问带来的访客（“付费访客”）；fb_untagged 单独计数，用于漏参提醒。
 * - 首次来源：访客的第一次访问（pc_track_visitors.first_visit_id，没写时用最早一次访问）是付费访问、且在日期范围内；
 *   最近来源：日期范围内最后一次付费访问。访客的行为按“归因的那次访问开始到范围结束”的所有访问合计。
 * - 素材编号：访问上的 creative_code → pc_ad_ads.code → 广告名里的编号；编号没在素材库登记的一律算 UNREG。
 *   素材属性以素材库登记为准（Contract 约定），没登记的素材属性显示“未登记”。
 * - 花费、展示、链接点击来自 pc_ad_daily_spend（按广告和日期）；平台 / 版位维度没有花费数据（Meta 报表按广告导出），显示“—”。
 * - 订单 = 访问阶段到 5（付款成功）且带订单号；销售额 = 这些订单的金额（美分）。进收银页 = 阶段到 4（下单后跳收银台）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Board {

	// 2026-11-12：“按单个素材”放第一个、也是默认维度（一个素材一条链接，投手先看每个素材的数据）。
	public const DIMS  = array(
		'creative' => '按单个素材',
		'attr'     => '按素材属性',
		'ad'       => '按广告',
		'adset'    => '按广告组',
		'platform' => '按平台 / 版位',
	);
	public const MODES = array(
		'first' => '首次来源',
		'last'  => '最近来源',
	);

	/** 有效访客少于这么多的行置灰、标“样本不足”。 */
	public const MIN_VALID = 30;

	/** 漏参提醒的默认阈值（页面上可改）。 */
	public const UNTAGGED_PCT = 10;
	public const MIN_CLICKS   = 20;

	/** 属性的中文名（词表的 label 是取值的中文名）。 */
	public const ATTR_LABELS = array( 'hook' => '钩子', 'fmt' => '形式', 'angle' => '卖点', 'lang' => '语言' );

	/** 不算“主动操作”的事件。 */
	private const PASSIVE = array( 'page_view', 'heartbeat', 'visibility_change', 'page_exit', 'product_impression', 'video_progress', 'checkout_step_applicability', 'visit_classification', 'cart_change_response' );

	/** 看过检测报告 / FAQ：模块类型、页面路径。 */
	private const REPORT_MODULES = array( 'faq', 'report-feature', 'report', 'lab-report', 'coa' );
	private const REPORT_PATH    = '#/(faq|faqs|coa|lab-reports?|test-reports?|reports?|certificates?)(/|$)#i';

	/** 一次最多读多少条访问（日访客 100–200，一年也就几万条）。 */
	public const MAX_VISITS = 200000;

	/** 下钻时每行最多保留多少个访客编号。 */
	public const DRILL_LIMIT = 200;

	// ---------- 每次访问的特征 ----------

	/**
	 * 从一条访问和它的事件整理出意向分层要用的特征（Intent_Tiers 的输入，不含 returning / prior_products / visits，那几项要看访客的其他访问）。
	 *
	 * @param array<string,mixed>            $visit  pc_track_visits 的一行
	 * @param array<int,array<string,mixed>> $events 这次访问的事件（按时间排好）：type, product_id, page_type, path, data
	 * @param string|null                    $status 访问分类：normal / abnormal / unclassified / null
	 */
	public static function visit_features( array $visit, array $events, ?string $status ): array {
		$start = (int) ( $visit['start_ms'] ?? 0 );
		$last  = (int) ( $visit['last_ms'] ?? 0 );
		$stage = (int) ( $visit['stage'] ?? 0 );
		$f     = array(
			'bot'              => 'abnormal' === $status ? true : ( 'normal' === $status ? false : null ),
			'ip_type'          => (string) ( $visit['ip_type'] ?? '' ),
			'dwell_ms'         => $start > 0 && $last >= $start ? $last - $start : null,
			'pageviews'        => null,
			'interactions'     => null,
			'scrolled'         => null,
			'timezone'         => (string) ( $visit['timezone'] ?? '' ),
			'language'         => (string) ( $visit['language'] ?? '' ),
			'ip_country'       => '',
			'carted'           => $stage >= 2,
			'checkout'         => $stage >= 3,
			'products'         => array(),
			'first_product_pv' => 0,
			'variant'          => false,
			'report_faq'       => false,
		);
		if ( ! $events ) {
			// 没有事件明细：页面数用访问上的计数，操作次数看不出。
			$f['pageviews'] = isset( $visit['pageviews'] ) ? (int) $visit['pageviews'] : null;
			return $f;
		}
		$pv = 0;
		$f['interactions'] = 0;
		foreach ( $events as $e ) {
			$type = (string) ( $e['type'] ?? '' );
			$data = $e['data'] ?? array();
			$data = is_array( $data ) ? $data : ( is_string( $data ) ? (array) json_decode( $data, true ) : array() );
			if ( 'page_view' === $type ) {
				++$pv;
				$path = (string) ( $e['path'] ?? '' );
				if ( '' !== $path && preg_match( self::REPORT_PATH, $path ) ) {
					$f['report_faq'] = true;
				}
				$code = $data['page_context']['geo']['country_code'] ?? '';
				if ( '' === $f['ip_country'] && is_string( $code ) && preg_match( '/^[A-Za-z]{2}$/', $code ) ) {
					$f['ip_country'] = strtoupper( $code );
				}
			}
			if ( ! in_array( $type, self::PASSIVE, true ) ) {
				++$f['interactions'];
			}
			if ( 'scroll' === $type || 'product_scroll' === $type ) {
				$f['scrolled'] = true;
			}
			if ( 'product_view' === $type && (int) ( $e['product_id'] ?? 0 ) > 0 ) {
				$f['products'][] = (int) $e['product_id'];
				if ( 0 === $f['first_product_pv'] ) {
					$f['first_product_pv'] = max( 1, $pv );
				}
			}
			if ( 'variant_select' === $type ) {
				$f['variant'] = true;
			}
			if ( 'module_click' === $type && in_array( strtolower( (string) ( $data['module_type'] ?? '' ) ), self::REPORT_MODULES, true ) ) {
				$f['report_faq'] = true;
			}
			if ( in_array( $type, array( 'add_to_cart', 'add_to_cart_attempt' ), true ) ) {
				$f['carted'] = true;
			}
			if ( in_array( $type, array( 'checkout_view', 'checkout_submit_attempt' ), true ) ) {
				$f['checkout'] = true;
			}
		}
		$f['pageviews'] = max( $pv, (int) ( $visit['pageviews'] ?? 0 ) );
		return $f;
	}

	// ---------- 汇总 ----------

	/**
	 * 汇总成看板的行。
	 *
	 * @param array $data {
	 *   visits:    list<array>  相关访客到范围结束为止的全部访问；每行带 features（visit_features() 的结果）
	 *   visitors:  array<int,array{first_visit_id:int,last_paid_visit_id:int}>
	 *   ads:       array<string,array{code:string,ad_name:string,adset_id:string,campaign_id:string}>
	 *   creatives: array<string,array{attrs:array,thumb:string}>  已登记（未归档）的素材
	 *   spend:     list<array{ad_id:string,day:string,spend_cents:int,impressions:int,link_clicks:int,ad_name:string}>  范围内的花费
	 *   orders:    array<int,int>  订单号 => 金额（美分）
	 *   labels:    array<string,array<string,string>>  属性 => 取值 => 中文名（词表）
	 * }
	 * @param array $opt { mode, dim, from_ms, to_ms, tz, min_valid }
	 * @return array{rows:list<array>,total:array,untagged:int,paid:int,ad_day_visitors:array<string,int>}
	 */
	public static function aggregate( array $data, array $opt ): array {
		$mode   = isset( self::MODES[ $opt['mode'] ?? '' ] ) ? $opt['mode'] : 'first';
		$dim    = isset( self::DIMS[ $opt['dim'] ?? '' ] ) ? $opt['dim'] : 'attr';
		$from   = (int) ( $opt['from_ms'] ?? 0 );
		$to     = (int) ( $opt['to_ms'] ?? PHP_INT_MAX );
		$tz     = (string) ( $opt['tz'] ?? 'UTC' );
		$min    = (int) ( $opt['min_valid'] ?? self::MIN_VALID );
		$ads    = (array) ( $data['ads'] ?? array() );
		$crs    = (array) ( $data['creatives'] ?? array() );
		$orders = (array) ( $data['orders'] ?? array() );
		$labels = (array) ( $data['labels'] ?? array() );

		$by_visitor = array();
		foreach ( (array) ( $data['visits'] ?? array() ) as $v ) {
			if ( (int) ( $v['start_ms'] ?? 0 ) < $to ) {
				$by_visitor[ (int) $v['visitor_id'] ][] = $v;
			}
		}

		$rows     = array();
		$total    = self::zero();
		$untagged = array();
		$ad_day   = array();
		$aud      = array();
		foreach ( $by_visitor as $vid => $list ) {
			usort( $list, static fn( $a, $b ) => array( (int) $a['start_ms'], (int) $a['id'] ) <=> array( (int) $b['start_ms'], (int) $b['id'] ) );
			// 回访、以前看过的商品、到这次为止来了几次。
			$prior = array();
			foreach ( $list as $i => $v ) {
				$f                     = (array) ( $v['features'] ?? array() );
				$f['returning']        = $i > 0;
				$f['prior_products']   = array_keys( $prior );
				$f['visits']           = $i + 1;
				$list[ $i ]['features'] = $f;
				foreach ( (array) ( $f['products'] ?? array() ) as $p ) {
					$prior[ (int) $p ] = true;
				}
				$in_range = (int) $v['start_ms'] >= $from;
				if ( $in_range && Contract::SOURCE_UNTAGGED === ( $v['source_class'] ?? '' ) ) {
					$untagged[ $vid ] = true;
				}
				$ad = (string) ( $v['utm_content'] ?? '' );
				if ( $in_range && Contract::SOURCE_PAID === ( $v['source_class'] ?? '' ) && '' !== $ad ) {
					$ad_day[ $ad . '|' . self::day( (int) $v['start_ms'], $tz ) ][ $vid ] = true;
				}
			}
			$anchor = self::anchor( $list, (array) ( $data['visitors'][ $vid ] ?? array() ), $mode, $from, $to );
			if ( null === $anchor ) {
				continue;
			}
			$after = array_values( array_filter( $list, static fn( $v ) => (int) $v['start_ms'] >= (int) $anchor['start_ms'] ) );
			$tier  = Intent_Tiers::visitor_tier( array_map( static fn( $v ) => $v['features'], $after ) );
			$facts = self::facts( $after, $orders );
			$code  = self::resolve_code( $anchor, $ads, $crs );
			$total = self::add( $total, $tier, $facts, $vid );
			foreach ( self::audience_keys( $anchor ) as $group => $value ) {
				$aud[ $group ][ $value ][ $tier ] = ( $aud[ $group ][ $value ][ $tier ] ?? 0 ) + 1;
			}
			foreach ( self::row_keys( $dim, $anchor, $code, $ads, $crs, $labels ) as $key => $label ) {
				$rows[ $key ] = self::add( $rows[ $key ] ?? self::zero( $label, self::row_meta( $dim, $key, $code, $crs ) ), $tier, $facts, $vid );
			}
		}

		foreach ( (array) ( $data['spend'] ?? array() ) as $s ) {
			$ad_id = (string) $s['ad_id'];
			$ad    = (array) ( $ads[ $ad_id ] ?? array() );
			$code  = self::spend_code( $ad, (string) ( $s['ad_name'] ?? '' ), $crs );
			$keys  = self::spend_keys( $dim, $ad_id, $ad, $code, $crs, $labels, (string) ( $s['ad_name'] ?? '' ) );
			foreach ( array( 'spend_cents', 'impressions', 'link_clicks' ) as $k ) {
				$total[ $k ] = (int) $total[ $k ] + (int) $s[ $k ];
			}
			foreach ( $keys as $key => $label ) {
				$rows[ $key ] = $rows[ $key ] ?? self::zero( $label, self::row_meta( $dim, $key, $code, $crs ) );
				foreach ( array( 'spend_cents', 'impressions', 'link_clicks' ) as $k ) {
					$rows[ $key ][ $k ] = (int) $rows[ $key ][ $k ] + (int) $s[ $k ];
				}
			}
		}

		$out = array();
		foreach ( $rows as $key => $row ) {
			if ( 'platform' === $dim ) {
				$row['spend_cents'] = $row['impressions'] = $row['link_clicks'] = null;
			}
			$out[] = array( 'key' => (string) $key ) + self::finish( $row, $min );
		}
		usort(
			$out,
			static function ( $a, $b ) use ( $dim ) {
				if ( 'attr' === $dim ) {
					$ga = (string) strtok( $a['key'], ':' );
					$gb = (string) strtok( $b['key'], ':' );
					$order = array_flip( array_keys( self::ATTR_LABELS ) );
					if ( $ga !== $gb ) {
						return ( $order[ $ga ] ?? 9 ) <=> ( $order[ $gb ] ?? 9 );
					}
				}
				return array( (int) $b['spend_cents'], $b['visitors'], $a['key'] ) <=> array( (int) $a['spend_cents'], $a['visitors'], $b['key'] );
			}
		);
		$counts = array();
		foreach ( $ad_day as $k => $set ) {
			$counts[ $k ] = count( $set );
		}
		return array(
			'rows'            => $out,
			'total'           => self::finish( $total, $min ),
			'untagged'        => count( $untagged ),
			'paid'            => (int) $total['visitors'],
			'ad_day_visitors' => $counts,
			'audience'        => self::audience_table( $aud ),
		);
	}

	/** 人群分布要看的维度（取归因的那次访问）。 */
	public const AUDIENCE = array(
		'country'   => '国家（IP）',
		'region'    => '省 / 州',
		'device'    => '设备',
		'in_app'    => '内置浏览器',
		'platform'  => '平台',
		'placement' => '版位',
		'language'  => '语言',
		'ip_type'   => 'IP 类型',
		'timezone'  => '时区',
	);

	/** @return array<string,string> */
	private static function audience_keys( array $v ): array {
		$f    = (array) ( $v['features'] ?? array() );
		$lang = strtolower( (string) strtok( (string) ( $v['language'] ?? '' ), '-_' ) );
		$out  = array(
			'country'   => (string) ( $f['ip_country'] ?? '' ),
			'region'    => (string) ( $v['geo_region'] ?? '' ),
			'device'    => (string) ( $v['device'] ?? '' ),
			'in_app'    => (string) ( $v['in_app'] ?? '' ),
			'platform'  => (string) ( $v['utm_source'] ?? '' ),
			'placement' => (string) ( $v['placement'] ?? '' ),
			'language'  => $lang,
			'ip_type'   => (string) ( $v['ip_type'] ?? '' ),
			'timezone'  => (string) ( $v['timezone'] ?? '' ),
		);
		return array_map( static fn( $x ) => '' !== $x ? $x : 'unknown', $out );
	}

	/** 维度 => [{value, visitors, intent{high,mid,low,invalid}}]，按人数排序。 */
	private static function audience_table( array $aud ): array {
		$out = array();
		foreach ( array_keys( self::AUDIENCE ) as $group ) {
			$list = array();
			foreach ( (array) ( $aud[ $group ] ?? array() ) as $value => $tiers ) {
				$intent = array_merge( array_fill_keys( Contract::INTENT_TIERS, 0 ), $tiers );
				$list[] = array( 'value' => (string) $value, 'visitors' => (int) array_sum( $intent ), 'intent' => $intent );
			}
			usort( $list, static fn( $a, $b ) => array( $b['visitors'], $a['value'] ) <=> array( $a['visitors'], $b['value'] ) );
			$out[ $group ] = $list;
		}
		return $out;
	}

	/** 归因的那次访问。 */
	private static function anchor( array $list, array $visitor, string $mode, int $from, int $to ): ?array {
		$in = static fn( $v ) => (int) $v['start_ms'] >= $from && (int) $v['start_ms'] < $to;
		if ( 'first' === $mode ) {
			$first = $list[0];
			$id    = (int) ( $visitor['first_visit_id'] ?? 0 );
			foreach ( $list as $v ) {
				if ( $id > 0 && (int) $v['id'] === $id ) {
					$first = $v;
					break;
				}
			}
			return Contract::SOURCE_PAID === ( $first['source_class'] ?? '' ) && $in( $first ) ? $first : null;
		}
		$last = null;
		foreach ( $list as $v ) {
			if ( Contract::SOURCE_PAID === ( $v['source_class'] ?? '' ) && $in( $v ) ) {
				$last = $v;
			}
		}
		return $last;
	}

	/** @return array{carted:bool,checkout:bool,cashier:bool,orders:array<int,int>} */
	private static function facts( array $visits, array $orders ): array {
		$out = array( 'carted' => false, 'checkout' => false, 'cashier' => false, 'orders' => array() );
		foreach ( $visits as $v ) {
			$stage           = (int) ( $v['stage'] ?? 0 );
			$f               = (array) ( $v['features'] ?? array() );
			$out['carted']   = $out['carted'] || $stage >= 2 || ! empty( $f['carted'] );
			$out['checkout'] = $out['checkout'] || $stage >= 3 || ! empty( $f['checkout'] );
			$out['cashier']  = $out['cashier'] || $stage >= 4;
			$oid             = (int) ( $v['order_id'] ?? 0 );
			if ( $stage >= 5 && $oid > 0 ) {
				$out['orders'][ $oid ] = max( 0, (int) ( $orders[ $oid ] ?? 0 ) );
			}
		}
		return $out;
	}

	/** 访问对应的素材编号（没登记 = UNREG）。 */
	public static function resolve_code( array $visit, array $ads, array $creatives ): string {
		$code = strtoupper( (string) ( $visit['creative_code'] ?? '' ) );
		if ( ! Contract::valid_code( $code ) ) {
			$code = (string) ( $ads[ (string) ( $visit['utm_content'] ?? '' ) ]['code'] ?? '' );
		}
		if ( ! Contract::valid_code( $code ) ) {
			$code = Contract::parse_ad_name( (string) ( $visit['ad_name'] ?? '' ) )['code'];
		}
		return isset( $creatives[ $code ] ) ? $code : Contract::UNREGISTERED;
	}

	private static function spend_code( array $ad, string $ad_name, array $creatives ): string {
		$code = (string) ( $ad['code'] ?? '' );
		if ( ! Contract::valid_code( $code ) ) {
			$code = Contract::parse_ad_name( '' !== $ad_name ? $ad_name : (string) ( $ad['ad_name'] ?? '' ) )['code'];
		}
		return isset( $creatives[ $code ] ) ? $code : Contract::UNREGISTERED;
	}

	/**
	 * 一个访客记到哪些行（按属性时一个访客在每个属性下各记一次）。
	 *
	 * @return array<string,string> 行键 => 显示名
	 */
	public static function row_keys( string $dim, array $visit, string $code, array $ads, array $creatives, array $labels ): array {
		$ad_id = (string) ( $visit['utm_content'] ?? '' );
		switch ( $dim ) {
			case 'creative':
				return array( $code => Contract::UNREGISTERED === $code ? '未登记素材' : $code );
			case 'ad':
				$name = (string) ( $ads[ $ad_id ]['ad_name'] ?? '' );
				return array( 'ad:' . $ad_id => '' !== $name ? $name : ( '' !== (string) ( $visit['ad_name'] ?? '' ) ? (string) $visit['ad_name'] : $ad_id ) );
			case 'adset':
				$set = (string) ( $visit['utm_term'] ?? '' );
				return array( 'adset:' . $set => '' !== $set ? '广告组 ' . $set : '未知广告组' );
			case 'platform':
				$p  = (string) ( $visit['utm_source'] ?? '' );
				$pl = (string) ( $visit['placement'] ?? '' );
				return array( 'platform:' . $p . '/' . $pl => ( '' !== $p ? $p : '未知平台' ) . ' / ' . ( '' !== $pl ? $pl : '未知版位' ) );
		}
		return self::attr_keys( $code, $creatives, $labels );
	}

	/** @return array<string,string> */
	private static function spend_keys( string $dim, string $ad_id, array $ad, string $code, array $creatives, array $labels, string $spend_name ): array {
		switch ( $dim ) {
			case 'creative':
				return array( $code => Contract::UNREGISTERED === $code ? '未登记素材' : $code );
			case 'ad':
				$name = (string) ( $ad['ad_name'] ?? '' );
				return array( 'ad:' . $ad_id => '' !== $name ? $name : ( '' !== $spend_name ? $spend_name : $ad_id ) );
			case 'adset':
				$set = (string) ( $ad['adset_id'] ?? '' );
				return array( 'adset:' . $set => '' !== $set ? '广告组 ' . $set : '未知广告组' );
			case 'platform':
				return array(); // 花费按广告导入，分不到平台 / 版位。
		}
		return self::attr_keys( $code, $creatives, $labels );
	}

	/** @return array<string,string> */
	private static function attr_keys( string $code, array $creatives, array $labels ): array {
		$out   = array();
		$attrs = (array) ( $creatives[ $code ]['attrs'] ?? array() );
		foreach ( self::ATTR_LABELS as $attr => $name ) {
			if ( Contract::UNREGISTERED === $code ) {
				$out[ $attr . ':' . Contract::UNREGISTERED ] = $name . ' · 未登记素材';
				continue;
			}
			$value = (string) ( $attrs[ $attr ] ?? '' );
			$out[ $attr . ':' . $value ] = $name . ' · ' . ( '' === $value ? '未填' : ( $labels[ $attr ][ $value ] ?? $value ) );
		}
		return $out;
	}

	private static function row_meta( string $dim, string $key, string $code, array $creatives ): array {
		return 'creative' === $dim ? array( 'thumb' => (string) ( $creatives[ $code ]['thumb'] ?? '' ), 'code' => $code, 'attrs' => (array) ( $creatives[ $code ]['attrs'] ?? array() ) ) : array();
	}

	private static function zero( string $label = '合计', array $meta = array() ): array {
		return array(
			'label'       => $label,
			'meta'        => $meta,
			'visitors'    => 0,
			'intent'      => array_fill_keys( Contract::INTENT_TIERS, 0 ),
			'carted'      => 0,
			'checkout'    => 0,
			'cashier'     => 0,
			'orders'      => 0,
			'sales_cents' => 0,
			'spend_cents' => 0,
			'impressions' => 0,
			'link_clicks' => 0,
			'visitor_ids' => array(),
			'order_ids'   => array(),
		);
	}

	private static function add( array $row, string $tier, array $facts, int $vid ): array {
		++$row['visitors'];
		++$row['intent'][ $tier ];
		if ( Intent_Tiers::INVALID !== $tier ) {
			$row['carted']   += $facts['carted'] ? 1 : 0;
			$row['checkout'] += $facts['checkout'] ? 1 : 0;
			$row['cashier']  += $facts['cashier'] ? 1 : 0;
		}
		foreach ( $facts['orders'] as $oid => $cents ) {
			if ( ! isset( $row['order_ids'][ $oid ] ) ) {
				$row['order_ids'][ $oid ] = true;
				++$row['orders'];
				$row['sales_cents'] += $cents;
			}
		}
		if ( count( $row['visitor_ids'] ) < self::DRILL_LIMIT ) {
			$row['visitor_ids'][] = $vid;
		}
		return $row;
	}

	/** 计数 → 指标。 */
	private static function finish( array $row, int $min ): array {
		$m = self::metrics(
			array(
				'visitors'    => $row['visitors'],
				'intent'      => $row['intent'],
				'carted'      => $row['carted'],
				'checkout'    => $row['checkout'],
				'cashier'     => $row['cashier'],
				'orders'      => $row['orders'],
				'sales_cents' => $row['sales_cents'],
				'spend_cents' => $row['spend_cents'],
				'impressions' => $row['impressions'],
				'link_clicks' => $row['link_clicks'],
			),
			$min
		);
		return array( 'label' => $row['label'], 'meta' => $row['meta'], 'visitor_ids' => $row['visitor_ids'] ) + $m;
	}

	/**
	 * 指标。分母为 0（或花费不知道）时比率是 null，页面显示“—”。
	 * 加购率、进结账率按有效访客算；成本单位是美分。
	 *
	 * @param array{visitors:int,intent:array<string,int>,carted:int,checkout:int,cashier:int,orders:int,sales_cents:int,spend_cents:?int,impressions:?int,link_clicks:?int} $c
	 */
	public static function metrics( array $c, int $min_valid = self::MIN_VALID ): array {
		$div     = static fn( $a, $b ): ?float => ( null === $a || null === $b || (float) $b <= 0 ) ? null : (float) $a / (float) $b;
		$intent  = array_merge( array_fill_keys( Contract::INTENT_TIERS, 0 ), (array) ( $c['intent'] ?? array() ) );
		$visitors = (int) ( $c['visitors'] ?? 0 );
		$valid   = max( 0, $visitors - (int) $intent['invalid'] );
		$spend   = isset( $c['spend_cents'] ) ? (int) $c['spend_cents'] : null;
		$clicks  = isset( $c['link_clicks'] ) ? (int) $c['link_clicks'] : null;
		$sales   = (int) ( $c['sales_cents'] ?? 0 );
		return array(
			'spend_cents'    => $spend,
			'impressions'    => isset( $c['impressions'] ) ? (int) $c['impressions'] : null,
			'link_clicks'    => $clicks,
			'visitors'       => $visitors,
			'valid'          => $valid,
			'intent'         => $intent,
			'arrival_rate'   => $div( $visitors, $clicks ),
			'valid_rate'     => $div( $valid, $visitors ),
			'mid_high_share' => $div( $intent['high'] + $intent['mid'], $valid ),
			'carted'         => (int) ( $c['carted'] ?? 0 ),
			'cart_rate'      => $div( (int) ( $c['carted'] ?? 0 ), $valid ),
			'checkout'       => (int) ( $c['checkout'] ?? 0 ),
			'checkout_rate'  => $div( (int) ( $c['checkout'] ?? 0 ), $valid ),
			'cashier'        => (int) ( $c['cashier'] ?? 0 ),
			'orders'         => (int) ( $c['orders'] ?? 0 ),
			'sales_cents'    => $sales,
			'cost_per_valid' => $div( $spend, $valid ),
			'cost_per_high'  => $div( $spend, $intent['high'] ),
			'roas'           => $div( $sales, $spend ),
			'insufficient'   => $valid < $min_valid,
		);
	}

	// ---------- 漏参提醒 ----------

	/**
	 * fb_untagged 占付费访客（付费 + 没带参数的 FB/IG 访客）的比例超过阈值时提醒。
	 *
	 * @return array{share:float,untagged:int,paid:int}|null
	 */
	public static function untagged_alert( int $untagged, int $paid, float $threshold_pct ): ?array {
		$base = $untagged + $paid;
		if ( $untagged <= 0 || $base <= 0 ) {
			return null;
		}
		$share = $untagged / $base;
		return $share * 100 > $threshold_pct ? array( 'share' => $share, 'untagged' => $untagged, 'paid' => $paid ) : null;
	}

	/**
	 * 疑似漏填参数：某广告某天链接点击 ≥ 阈值，但那天带这个 ad_id 进站的访客是 0。
	 *
	 * @param list<array{ad_id:string,day:string,link_clicks:int,ad_name:string}> $spend
	 * @param array<string,int> $ad_day_visitors "ad_id|day" => 访客数
	 * @param array<string,array{ad_name?:string}> $ads
	 * @return list<array{ad_id:string,day:string,link_clicks:int,ad_name:string}>
	 */
	public static function missing_params( array $spend, array $ad_day_visitors, int $min_clicks, array $ads = array() ): array {
		$out = array();
		foreach ( $spend as $s ) {
			$key = (string) $s['ad_id'] . '|' . (string) $s['day'];
			if ( (int) $s['link_clicks'] >= max( 1, $min_clicks ) && 0 === (int) ( $ad_day_visitors[ $key ] ?? 0 ) ) {
				$name  = (string) ( $s['ad_name'] ?? '' );
				$out[] = array(
					'ad_id'       => (string) $s['ad_id'],
					'day'         => (string) $s['day'],
					'link_clicks' => (int) $s['link_clicks'],
					'ad_name'     => '' !== $name ? $name : (string) ( $ads[ (string) $s['ad_id'] ]['ad_name'] ?? $s['ad_id'] ),
				);
			}
		}
		usort( $out, static fn( $a, $b ) => array( $b['day'], $b['link_clicks'] ) <=> array( $a['day'], $a['link_clicks'] ) );
		return $out;
	}

	// ---------- 日期 ----------

	/** 毫秒时间 → 店铺时区的日期。 */
	public static function day( int $ms, string $tz ): string {
		try {
			$zone = new \DateTimeZone( '' !== $tz ? $tz : 'UTC' );
		} catch ( \Throwable $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		return ( new \DateTimeImmutable( '@' . intdiv( max( 0, $ms ), 1000 ) ) )->setTimezone( $zone )->format( 'Y-m-d' );
	}

	/**
	 * 店铺时区的日期范围 → [开始毫秒, 结束毫秒)（结束是 day_to 的下一天零点）。
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function range_ms( string $day_from, string $day_to, string $tz ): ?array {
		if ( ! self::valid_day( $day_from ) || ! self::valid_day( $day_to ) || $day_from > $day_to ) {
			return null;
		}
		try {
			$zone = new \DateTimeZone( '' !== $tz ? $tz : 'UTC' );
		} catch ( \Throwable $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		$a = new \DateTimeImmutable( $day_from . ' 00:00:00', $zone );
		$b = ( new \DateTimeImmutable( $day_to . ' 00:00:00', $zone ) )->modify( '+1 day' );
		return array( $a->getTimestamp() * 1000, $b->getTimestamp() * 1000 );
	}

	public static function valid_day( string $day ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	// ---------- 读数据库 ----------

	/**
	 * 读出 aggregate() 需要的全部数据。
	 *
	 * @param array{0:int,1:int} $ms   [开始, 结束) 毫秒
	 * @return array
	 */
	public static function load( array $ms, string $day_from, string $day_to ): array {
		global $wpdb;
		$visits_t   = \PeptideCore\Tracking\Schema::table( 'visits' );
		$visitors_t = \PeptideCore\Tracking\Schema::table( 'visitors' );
		$events_t   = \PeptideCore\Tracking\Schema::table( 'events' );
		$out        = array( 'visits' => array(), 'visitors' => array(), 'ads' => array(), 'creatives' => array(), 'spend' => array(), 'orders' => array(), 'labels' => array() );
		if ( ! \PeptideCore\Tracking\Schema::installed() ) {
			return $out;
		}
		// 范围内有过付费 / 未带参访问的访客，再取他们到范围结束为止的全部访问（判断首次来源、回访）。
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT visitor_id FROM {$visits_t} WHERE start_ms >= %d AND start_ms < %d AND source_class IN ('paid','fb_untagged') LIMIT %d", $ms[0], $ms[1], self::MAX_VISITS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$cols = 'id, visitor_id, start_ms, last_ms, utm_source, utm_medium, utm_term, utm_content, placement, ad_name, creative_code, source_class, ip_type, timezone, language, geo_region, in_app, device, pageviews, stage, order_id';
		$owners = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$visits_t} WHERE visitor_id IN ({$in}) AND start_ms < %d ORDER BY start_ms, id", $ms[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$out['visits'][ (int) $r['id'] ] = $r;
				$owners[ (int) $r['id'] ]        = (int) $r['visitor_id'];
			}
			foreach ( (array) $wpdb->get_results( "SELECT id, first_visit_id, last_paid_visit_id FROM {$visitors_t} WHERE id IN ({$in})", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$out['visitors'][ (int) $r['id'] ] = array( 'first_visit_id' => (int) $r['first_visit_id'], 'last_paid_visit_id' => (int) $r['last_paid_visit_id'] );
			}
		}
		// 事件明细：只取判断意向要用的类型。
		$types  = "'page_view','product_view','product_click','product_intent','variant_select','module_click','add_to_cart','add_to_cart_attempt','checkout_view','checkout_submit_attempt','cart_view','cart_open','product_favorite','product_share','video_play','scroll','product_scroll'";
		$events = array();
		foreach ( array_chunk( array_keys( $out['visits'] ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			foreach ( (array) $wpdb->get_results( "SELECT visit_id, type, page_type, path, product_id, data FROM {$events_t} WHERE visit_id IN ({$in}) AND type IN ({$types}) ORDER BY occurred_ms, id", ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$events[ (int) $e['visit_id'] ][] = $e;
			}
		}
		$status = array();
		try {
			foreach ( \PeptideCore\Tracking\Visit_Classifications::for_visits( $owners, \PeptideCore\Tracking\Tracker::now_ms() + 1 ) as $id => $v ) {
				$status[ (int) $id ] = (string) $v['status'];
			}
		} catch ( \Throwable $e ) {
			$status = array(); // 分类读不到就按“看不出”。
		}
		$order_ids = array();
		foreach ( $out['visits'] as $id => $v ) {
			$out['visits'][ $id ]['features'] = self::visit_features( $v, $events[ $id ] ?? array(), $status[ $id ] ?? null );
			if ( (int) $v['stage'] >= 5 && (int) $v['order_id'] > 0 ) {
				$order_ids[] = (int) $v['order_id'];
			}
		}
		$out['visits'] = array_values( $out['visits'] );
		foreach ( array_unique( $order_ids ) as $oid ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : null;
			if ( $order instanceof \WC_Order ) {
				$out['orders'][ $oid ] = (int) round( max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() ) * 100 );
			}
		}
		return array_merge( $out, self::catalog(), array( 'spend' => self::spend( $day_from, $day_to ) ) );
	}

	/** 素材、广告、词表中文名。 */
	public static function catalog(): array {
		global $wpdb;
		$out = array( 'ads' => array(), 'creatives' => array(), 'labels' => array() );
		foreach ( Schema::vocab() as $item ) {
			$out['labels'][ $item['attr'] ][ $item['value'] ] = $item['label'];
		}
		if ( ! Schema::installed() ) {
			return $out;
		}
		$ads = Schema::table( 'ads' );
		$crs = Schema::table( 'creatives' );
		foreach ( (array) $wpdb->get_results( "SELECT ad_id, code, ad_name, adset_id, campaign_id FROM {$ads}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out['ads'][ (string) $r['ad_id'] ] = array( 'code' => (string) $r['code'], 'ad_name' => (string) $r['ad_name'], 'adset_id' => (string) $r['adset_id'], 'campaign_id' => (string) $r['campaign_id'] );
		}
		foreach ( (array) $wpdb->get_results( "SELECT code, attachment_id, attrs, custom FROM {$crs} WHERE status = 'active'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$attrs = json_decode( (string) $r['attrs'], true );
			$cust  = json_decode( (string) $r['custom'], true );
			$thumb = (int) $r['attachment_id'] > 0 && function_exists( 'wp_get_attachment_image_url' ) ? (string) wp_get_attachment_image_url( (int) $r['attachment_id'], 'thumbnail' ) : '';
			$out['creatives'][ (string) $r['code'] ] = array( 'attrs' => is_array( $attrs ) ? $attrs : array(), 'custom' => is_array( $cust ) ? $cust : array(), 'thumb' => $thumb );
		}
		return $out;
	}

	/** @return list<array{ad_id:string,day:string,spend_cents:int,impressions:int,link_clicks:int,ad_name:string}> */
	public static function spend( string $day_from, string $day_to ): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$t   = Schema::table( 'daily_spend' );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ad_id, day, spend_cents, impressions, link_clicks, ad_name FROM {$t} WHERE day >= %s AND day <= %s ORDER BY day, ad_id", $day_from, $day_to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[] = array( 'ad_id' => (string) $r['ad_id'], 'day' => (string) $r['day'], 'spend_cents' => (int) $r['spend_cents'], 'impressions' => (int) $r['impressions'], 'link_clicks' => (int) $r['link_clicks'], 'ad_name' => (string) $r['ad_name'] );
		}
		return $out;
	}

	/** 店铺时区名（没设置时 UTC）。 */
	public static function timezone(): string {
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
		return '' !== $tz ? $tz : 'UTC';
	}
}
