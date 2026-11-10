<?php
/**
 * 店铺后台的 AI 分析（2026-11-10）：AI 服务和人设都在总后台（AI 设置），店铺这里只负责整理事实、签名后交给总后台。
 *
 * - 客户分析（访客详情页）：这位访客的行为汇总 + 购买意向打分 + 购物车，不含姓名、邮箱、电话、IP。
 * - 全站经营分析（实时访客页）：当天 / 本周 / 本月的全站行为汇总和经营指标。
 * - 同样的条件 30 分钟内重复点，直接用上次结果（省次数）；“重新分析”强制重来。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Hq_Ai {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function available(): bool {
		if ( ! Hq_Connector::configured() ) {
			return false;
		}
		$s = Hq_Connector::settings();
		return 64 === strlen( $s['key'] ) && '' !== $s['uuid'];
	}

	/** 签名后 POST 到总后台。 */
	private static function call( string $route, array $payload, int $timeout ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'pc_ai_hq', '这家店还没有接入总后台。', array( 'status' => 503 ) );
		}
		$s    = Hq_Connector::settings();
		$time = time();
		$body = (string) wp_json_encode( (object) $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$res  = wp_remote_post(
			add_query_arg( 'rest_route', rawurlencode( $route ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-PC-HQ-Site' => $s['uuid'],
					'X-PC-HQ-Time' => (string) $time,
					'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], 'POST', $route, '', $time, $body ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pc_ai_hq', '连不上总后台：' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = is_array( $json ) && is_string( $json['message'] ?? null ) ? $json['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'pc_ai_failed', $msg, array( 'status' => in_array( $code, array( 403, 409, 429 ), true ) ? $code : 502 ) );
		}
		return is_array( $json ) ? $json : new \WP_Error( 'pc_ai_failed', '总后台返回的内容看不懂。', array( 'status' => 502 ) );
	}

	/** 总后台给这家店的 AI 配置（缓存 5 分钟）。 */
	public static function status( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( 'pc_ai_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$r   = self::call( '/peptide-hq/v1/ai/status', array(), 10 );
		$out = is_wp_error( $r )
			? array( 'enabled' => false, 'error' => $r->get_error_message() )
			: array(
				'enabled'    => ! empty( $r['enabled'] ),
				'customer'   => is_array( $r['customer'] ?? null ) ? (string) $r['customer']['name'] : '',
				'business'   => is_array( $r['business'] ?? null ) ? (string) $r['business']['name'] : '',
				'used_today' => (int) ( $r['used_today'] ?? 0 ),
				'daily_cap'  => (int) ( $r['daily_cap'] ?? 0 ),
			);
		set_transient( 'pc_ai_status', $out, is_wp_error( $r ) ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function routes(): void {
		$perm = array( Tracking\Preferences::class, 'permission' );
		register_rest_route( 'peptide/v1', '/track/admin/ai/status', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( self::status() ) ) );
		register_rest_route( 'peptide/v1', '/track/admin/ai/analyse', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_analyse' ) ) );
	}

	public static function rest_analyse( \WP_REST_Request $request ) {
		$scope   = 'site' === $request['scope'] ? 'site' : 'visitor';
		$visitor = absint( $request['visitor_id'] );
		$visit   = absint( $request['visit_id'] );
		$period  = in_array( $request['period'], array( 'all', 'day', 'week', 'month' ), true ) ? (string) $request['period'] : ( 'site' === $scope ? 'day' : 'all' );
		$date    = is_string( $request['date'] ) && preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $request['date'] ) ? $request['date'] : wp_date( 'Y-m-d' );
		if ( 'visitor' === $scope && ! $visitor ) {
			return new \WP_Error( 'pc_ai_bad', '请选择客户。', array( 'status' => 400 ) );
		}
		if ( 'site' === $scope ) {
			$visitor = 0;
			$visit   = 0;
			$period  = 'all' === $period ? 'day' : $period;
		}
		$cache = 'pc_ai_' . md5( implode( '|', array( $scope, $visitor, $visit, $period, $date ) ) );
		if ( empty( $request['refresh'] ) ) {
			$hit = get_transient( $cache );
			if ( is_array( $hit ) ) {
				return rest_ensure_response( $hit + array( 'cached' => true ) );
			}
		}
		$facts = self::facts( $scope, $visitor, $visit, $period, $date );
		if ( is_wp_error( $facts ) ) {
			return $facts;
		}
		$use = 'site' === $scope ? 'business' : 'customer';
		$r   = self::call( '/peptide-hq/v1/ai/analyse', array( 'use' => $use, 'facts' => $facts, 'experience' => self::experience( $use ) ), 100 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array(
			'text'       => (string) ( $r['text'] ?? '' ),
			'persona'    => (string) ( $r['persona'] ?? '' ),
			'model'      => (string) ( $r['model'] ?? '' ),
			'used_today' => (int) ( $r['used_today'] ?? 0 ),
			'daily_cap'  => (int) ( $r['daily_cap'] ?? 0 ),
			'at'         => wp_date( 'Y-m-d H:i' ),
		);
		set_transient( $cache, $out, 30 * MINUTE_IN_SECONDS );
		delete_transient( 'pc_ai_status' );
		self::log_judgment( $use, $visitor, $period, $date, $out['text'], $facts );
		return rest_ensure_response( $out + array( 'cached' => false ) );
	}

	/** 交给 AI 的事实：复用“导出分析数据”的口径，再补购买意向和购物车。不含任何个人联系信息。 */
	private static function facts( string $scope, int $visitor, int $visit, string $period, string $date ) {
		$sub = new \WP_REST_Request( 'GET', '/peptide/v1/track/admin/preferences' );
		$sub->set_query_params( array( 'period' => $period, 'date' => $date, 'visitor_id' => (string) $visitor, 'visit_id' => (string) $visit ) );
		$res = Tracking\Preferences::response( $sub );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $res instanceof \WP_REST_Response ? (array) $res->get_data() : (array) $res;
		unset( $data['ai'], $data['subject'] );
		$data['store'] = array( 'currency' => get_woocommerce_currency(), 'timezone' => wp_timezone_string() );
		$data['【店铺配置】'] = self::store_config();
		if ( 'site' === $scope ) {
			[ $from, $to ] = self::window( $period, $date );
			$data += self::site_extras( $from, $to );
		}
		if ( 'visitor' === $scope && Tracking\Schema::installed() ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				return new \WP_Error( 'pc_ai_bad', '找不到这位访客。', array( 'status' => 404 ) );
			}
			$cart   = Tracking\Carts::view( $row, true );
			$intent = Tracking\Intent::analyse( array( 'id' => (int) $row['id'], 'visits' => (int) $row['visits'] ), $cart, 'away', Tracking\Tracker::now_ms() );
			$visit_row = $wpdb->get_row( $wpdb->prepare( 'SELECT utm_source, utm_medium, referrer_host, landing_path, stage, device FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY start_ms DESC LIMIT 1', $visitor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$max_stage = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(stage) FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE visitor_id = %d', $visitor ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$checkout  = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM " . Tracking\Schema::table( 'events' ) . " WHERE visitor_id = %d AND type IN ('cart_view','checkout_view','checkout_address_completed','checkout_submit_attempt','order_placed','payment_attempt','payment_failed','payment_success') GROUP BY type", $visitor ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$checkout[ (string) $e['type'] ] = (int) $e['n'];
			}
			$data['visitor'] = array(
				'device'         => (string) $row['device'],
				'browser'        => (string) $row['browser'],
				'visits'         => (int) $row['visits'],
				'first_seen'     => wp_date( 'Y-m-d H:i', (int) floor( $row['first_ms'] / 1000 ) ),
				'last_seen'      => wp_date( 'Y-m-d H:i', (int) floor( $row['last_ms'] / 1000 ) ),
				'latest_source'  => $visit_row ? trim( $visit_row['utm_source'] . ' / ' . $visit_row['utm_medium'], ' /' ) ?: (string) $visit_row['referrer_host'] : '',
				'furthest_stage' => $max_stage,
				'checkout_events' => $checkout, // 结账相关动作次数：进购物车、进结账页、填完地址、点下单、生成订单、发起付款、付款失败、付款成功
				'intent_score'   => $intent['score'],
				'intent_level'   => $intent['level_text'],
				'intent_reasons' => $intent['reasons'],
				'top_products'   => $intent['products'],
				'cart'           => array(
					'status'   => $cart['status'],
					'count'    => $cart['count'],
					'subtotal' => $cart['subtotal_text'],
					'items'    => array_map( static fn( $i ) => array( 'name' => $i['name'], 'variation' => $i['variation'], 'quantity' => $i['quantity'], 'line_total' => $i['line_total_text'] ), (array) ( $cart['items'] ?? array() ) ),
				),
			);
		}
		return $data;
	}

	// ---------- 店铺配置（2026-11-10：让 AI 结合店铺真实装修 / 运费 / 优惠给建议） ----------

	private static function window( string $period, string $date ): array {
		$day   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() ) ?: new \DateTimeImmutable( 'today', wp_timezone() );
		$start = 'week' === $period ? $day->modify( 'monday this week' ) : ( 'month' === $period ? $day->modify( 'first day of this month' ) : $day );
		$end   = $start->modify( 'week' === $period ? '+1 week' : ( 'month' === $period ? '+1 month' : '+1 day' ) );
		return array( $start->getTimestamp(), min( $end->getTimestamp(), time() ) );
	}

	private static function module_labels( string $doc ): array {
		$out = array();
		foreach ( (array) Modules\Layout::published_modules( $doc ) as $m ) {
			$block = (string) ( $m['block'] ?? '' );
			$type  = (string) Modules\Registry::type_for_block( $block );
			$reg   = $type ? (array) Modules\Registry::get( $type ) : array();
			$label = (string) ( $reg['label'] ?? $reg['title'] ?? $reg['name'] ?? '' ) ?: preg_replace( '#^[a-z-]+/#', '', $block );
			$attrs = (array) ( $m['attrs'] ?? array() );
			foreach ( array( 'title', 'heading', 'headline' ) as $k ) {
				if ( is_string( $attrs[ $k ] ?? null ) && '' !== trim( wp_strip_all_tags( $attrs[ $k ] ) ) ) {
					$label .= '「' . mb_substr( trim( wp_strip_all_tags( $attrs[ $k ] ) ), 0, 30 ) . '」';
					break;
				}
			}
			$out[] = $label;
		}
		return $out;
	}

	public static function store_config(): array {
		$money = static fn( $v ) => html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ) );
		$ship  = array();
		foreach ( (array) get_option( 'peptide_core_shipping_plans', array() ) as $plan ) {
			if ( 'general' !== ( $plan['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $plan['rates'] ?? array() ) as $r ) {
				if ( empty( $r['enabled'] ) ) {
					continue;
				}
				$range  = 'subtotal' === ( $r['rule'] ?? '' ) ? '商品金额 ' . $money( $r['min'] ?? 0 ) . ( null !== ( $r['max'] ?? null ) ? ' – ' . $money( $r['max'] ) : ' 以上' ) : (string) ( $r['rule'] ?? '' );
				$ship[] = array( '方式' => (string) ( $r['name'] ?? '' ), '条件' => $range, '运费' => $money( $r['fee'] ?? 0 ) );
			}
		}
		$ship_discounts = array();
		foreach ( (array) get_option( 'peptide_core_shipping_discounts', array() ) as $d ) {
			if ( ! empty( $d['enabled'] ) ) {
				$ship_discounts[] = (string) ( $d['name'] ?? '' ) . '（' . ( 'percent' === ( $d['kind'] ?? '' ) ? '运费 ' . (float) $d['value'] . '% off' : '运费减 ' . $money( $d['value'] ?? 0 ) ) . '）';
			}
		}
		$promos = array();
		$now    = time();
		foreach ( get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'numberposts' => 20 ) ) as $p ) {
			$c = Promotions::config( $p->ID );
			if ( ( (int) ( $c['start'] ?? 0 ) && (int) $c['start'] > $now ) || ( (int) ( $c['end'] ?? 0 ) && (int) $c['end'] < $now ) ) {
				continue;
			}
			$tiers = array_map( static fn( $t ) => '满 ' . ( $t['min'] ?? '' ) . ' 减/折 ' . ( $t['value'] ?? '' ), (array) ( $c['tiers'] ?? array() ) );
			$promos[] = array( '名称' => $p->post_title, '类型' => (string) ( $c['type'] ?? '' ), '档位' => $tiers, '结束' => (int) ( $c['end'] ?? 0 ) ? wp_date( 'Y-m-d', (int) $c['end'] ) : '长期' );
		}
		$first = First_Order_Offer::settings();
		$first_txt = '未开启';
		if ( ! empty( $first['enabled'] ) && $first['coupon_id'] ) {
			$cp        = new \WC_Coupon( (int) $first['coupon_id'] );
			$first_txt = '首单优惠：' . ( 'percent' === $cp->get_discount_type() ? (float) $cp->get_amount() . '% off' : $money( $cp->get_amount() ) . ' off' );
		}
		$pays = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $g ) {
			if ( 'yes' === $g->enabled ) {
				$pays[] = wp_strip_all_tags( $g->get_title() );
			}
		}
		global $wpdb;
		$price = $wpdb->get_row( "SELECT MIN(min_price) AS lo, MAX(max_price) AS hi, COUNT(DISTINCT product_id) AS n FROM {$wpdb->wc_product_meta_lookup} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_status = 'publish' WHERE l.min_price > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'运费'       => $ship,
			'运费优惠'   => $ship_discounts,
			'满减活动'   => $promos,
			'首单优惠'   => $first_txt,
			'支付方式'   => $pays,
			'商品价格'   => $price ? array( '在售商品' => (int) $price['n'], '最低' => $money( $price['lo'] ), '最高' => $money( $price['hi'] ) ) : array(),
			'首页版块'   => self::module_labels( 'home' ),
			'商品页版块' => self::module_labels( 'product' ),
			'购物车页版块' => self::module_labels( 'cart' ),
			'结账页版块' => self::module_labels( 'checkout' ),
		);
	}

	/** 全站分析额外的数据：漏斗、渠道、弃单、上期对比。 */
	private static function site_extras( int $from, int $to ): array {
		$out = array();
		try {
			$vf    = new Data\Visitor_Funnel();
			$names = array( 'visitors' => '访问', 'viewed' => '看商品', 'carted' => '加购', 'checkout' => '发起结账', 'address' => '填完地址', 'shipping' => '选物流', 'payment' => '选支付', 'paid' => '付款' );
			$len   = $to - $from;
			foreach ( array( '本期' => array( $from, $to ), '上期' => array( $from - $len, $from ) ) as $k => [ $a, $b ] ) {
				$layers = $vf->period( $a * 1000, $b * 1000 )['overall']['layers'];
				$out['【漏斗】'][ $k ] = array_combine( array_map( static fn( $l ) => $names[ $l['key'] ] ?? $l['key'], $layers ), array_column( $layers, 'count' ) );
			}
		} catch ( \Throwable $e ) {
			$out['【漏斗】'] = '暂时读不到';
		}
		try {
			$range = array( 'from' => $from, 'to' => $to, 'compare_from' => $from - ( $to - $from ), 'compare_to' => $from, 'key' => 'custom' );
			$cv    = Data\Conversion::summary( $range, 'channel' );
			$out['【渠道】'] = array_map( static fn( $r ) => array( '渠道' => (string) $r['label'], '访客' => (int) $r['visitors'], '付款订单' => (int) $r['paid'], '转化率%' => $r['conversion'] ), array_slice( (array) ( $cv['rows'] ?? array() ), 0, 8 ) );
		} catch ( \Throwable $e ) {
			$out['【渠道】'] = '暂时读不到';
		}
		$counts = Recovery_Items::list( array( 'from_ms' => $from * 1000, 'to_ms' => $to * 1000 ), 1, 1 )['counts'] ?? array();
		$out['【弃单】'] = array( '待召回' => (int) ( $counts['pending'] ?? 0 ), '召回中' => (int) ( $counts['recalling'] ?? 0 ), '召回失败' => (int) ( $counts['failed'] ?? 0 ), '召回成功' => (int) ( $counts['success'] ?? 0 ) );
		try {
			$prev = Data\Operations::period( $from - ( $to - $from ), $from );
			$out['【上期对比】'] = $prev['kpis'] ?? array();
		} catch ( \Throwable $e ) {
			$out['【上期对比】'] = '暂时读不到';
		}
		return $out;
	}

	// ---------- 自学习（2026-11-10）：记下每次判断，过几天核对客户有没有下单，汇总成经验交给 AI ----------

	public const LOG     = 'pc_ai_log';
	private const LOG_MAX = 600;
	private const MATURE  = 3 * DAY_IN_SECONDS; // 判断 3 天后再核对结果。

	/** 从回答末尾的“判断记录”取出 key=value。 */
	public static function parse_record( string $text ): array {
		if ( ! preg_match( '/判断记录[\*\s:：]*\n?\s*([^\n]+)/u', $text, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/[；;]/u', $m[1] ) as $kv ) {
			$kv = trim( str_replace( array( '*', '`' ), '', $kv ) );
			if ( preg_match( '/^([^=＝]{1,10})[=＝]\s*(.*)$/u', $kv, $p ) ) {
				$out[ trim( $p[1] ) ] = mb_substr( trim( $p[2] ), 0, 80 );
			}
		}
		return $out;
	}

	private static function log_judgment( string $use, int $visitor, string $period, string $date, string $text, array $facts ): void {
		$rec = self::parse_record( $text );
		if ( ! $rec ) {
			return;
		}
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$row = array( 'use' => $use, 'vid' => $visitor, 'at' => time(), 'period' => $period, 'date' => $date, 'rec' => $rec );
		if ( 'business' === $use ) {
			$row['kpi'] = array_intersect_key( (array) ( $facts['business_kpis'] ?? array() ), array_flip( array( 'visitors', 'orders', 'sales', 'conversion', 'aov' ) ) );
			$row['funnel'] = $facts['【漏斗】']['本期'] ?? array();
		}
		// 同一客户 / 同一时间段重复分析，只留最新一条。
		$log   = array_values( array_filter( $log, static fn( $r ) => ! ( $r['use'] === $use && (int) $r['vid'] === $visitor && $r['period'] === $period && $r['date'] === $date ) ) );
		$log[] = $row;
		update_option( self::LOG, array_slice( $log, -self::LOG_MAX ), false );
	}

	private static function bucket( string $v ): string {
		$v = trim( preg_replace( '/[（(].*$/u', '', $v ) );
		return '' === $v ? '未写' : mb_substr( $v, 0, 16 );
	}

	/** 本店经验：客户分析看判断准不准；全站分析看最近几次的判断和当时的数据。 */
	public static function experience( string $use ): array {
		$log = get_option( self::LOG, array() );
		$log = array_values( array_filter( is_array( $log ) ? $log : array(), static fn( $r ) => ( $r['use'] ?? '' ) === $use ) );
		if ( 'business' === $use ) {
			return array( '最近的全站判断' => array_map( static fn( $r ) => array( '分析时间' => wp_date( 'Y-m-d H:i', (int) $r['at'] ), '周期' => $r['period'] . ' ' . $r['date'], '当时数据' => $r['kpi'] ?? array(), '当时漏斗' => $r['funnel'] ?? array(), '当时判断' => $r['rec'] ), array_slice( $log, -3 ) ) );
		}
		global $wpdb;
		$visits = Tracking\Schema::installed() ? Tracking\Schema::table( 'visits' ) : '';
		$stats  = array( 'level' => array(), 'blocker' => array(), 'type' => array() );
		$done   = 0;
		$wait   = 0;
		$rules  = array();
		foreach ( $log as $r ) {
			if ( '' !== ( $r['rec']['新规律'] ?? '' ) && ! preg_match( '/^\s*(无|没有|暂无|-|—)/u', (string) $r['rec']['新规律'] ) ) {
				$rules[] = $r['rec']['新规律'];
			}
			if ( time() - (int) $r['at'] < self::MATURE || ! (int) $r['vid'] || '' === $visits ) {
				++$wait;
				continue;
			}
			$stage   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(stage) FROM {$visits} WHERE visitor_id = %d AND last_ms >= %d", (int) $r['vid'], (int) $r['at'] * 1000 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$ordered = $stage >= 5 ? 1 : 0;
			++$done;
			foreach ( array( 'level' => '意向', 'blocker' => '主要卡点', 'type' => '心理类型' ) as $k => $field ) {
				$b = self::bucket( (string) ( $r['rec'][ $field ] ?? '' ) );
				$stats[ $k ][ $b ]['判断数'] = ( $stats[ $k ][ $b ]['判断数'] ?? 0 ) + 1;
				$stats[ $k ][ $b ]['后来下单'] = ( $stats[ $k ][ $b ]['后来下单'] ?? 0 ) + $ordered;
			}
		}
		$top = static function ( array $rows ): array {
			uasort( $rows, static fn( $a, $b ) => $b['判断数'] <=> $a['判断数'] );
			return array_slice( $rows, 0, 8, true );
		};
		return array(
			'已核对' => $done,
			'待核对' => $wait,
			'按意向' => $stats['level'],
			'按主要卡点' => $top( $stats['blocker'] ),
			'按心理类型' => $top( $stats['type'] ),
			'待验证的新规律' => array_slice( array_values( array_unique( $rules ) ), -8 ),
		);
	}
}
