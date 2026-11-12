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

	/** 店主给 AI 的补充说明（2026-11-10：例如“支付已测试正常”），每次分析都带上，AI 当作确认过的事实。 */
	public const NOTES = 'pc_ai_notes'; // 旧的“补充说明”，两种分析共用；新的人设调优没填时沿用它。
	public const TUNE  = 'pc_ai_tune_'; // + customer / business：这家店自己的人设调优（店主 2026-11-10）。

	public const RESOLVED = 'pc_ai_resolved'; // 店主确认已解决的问题（店主 2026-11-10：例如“支付没问题”），AI 不再当原因或建议。

	/** 这家店对某种分析的人设调优（只放店主自己写的阅读习惯和要求）。 */
	public static function tune( string $use ): string {
		return trim( (string) get_option( self::TUNE . $use, '' ) );
	}

	/** @return array<int,array{id:string,text:string,at:int}> */
	public static function resolved(): array {
		$v = get_option( self::RESOLVED, array() );
		return is_array( $v ) ? array_values( array_filter( $v, static fn( $r ) => is_array( $r ) && '' !== (string) ( $r['text'] ?? '' ) ) ) : array();
	}

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( 'init', array( self::class, 'ensure_cron' ) );
		add_action( self::CRON, array( self::class, 'auto_run' ) );
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
				'daily_cap'  => 0,
				'auto_customer' => ! empty( $r['auto_customer'] ),
				'auto_site'     => ! empty( $r['auto_site'] ),
			);
		set_transient( 'pc_ai_status', $out, is_wp_error( $r ) ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function routes(): void {
		$perm = array( Tracking\Preferences::class, 'permission' );
		register_rest_route( 'peptide/v1', '/track/admin/ai/status', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( self::status() ) ) );
		register_rest_route( 'peptide/v1', '/track/admin/ai/analyse', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_analyse' ) ) );
		register_rest_route( 'peptide/v1', '/track/admin/ai/result', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( self::class, 'rest_result' ) ) );
		register_rest_route( 'peptide/v1', '/track/admin/ai/notes', array(
			array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( array( 'notes' => self::tune( 'site' === $r['scope'] ? 'business' : 'customer' ) ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => static function ( \WP_REST_Request $r ) {
				$notes = mb_substr( sanitize_textarea_field( (string) $r['notes'] ), 0, 4000 );
				update_option( self::TUNE . ( 'site' === $r['scope'] ? 'business' : 'customer' ), $notes, false );
				return rest_ensure_response( array( 'notes' => $notes, 'saved' => true ) );
			} ),
		) );
		register_rest_route( 'peptide/v1', '/track/admin/ai/resolved', array(
			array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( array( 'items' => self::resolved() ) ) ),
			array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => static function ( \WP_REST_Request $r ) {
				$list = self::resolved();
				$id   = sanitize_key( (string) $r['remove'] );
				if ( '' !== $id ) {
					$list = array_values( array_filter( $list, static fn( $i ) => $i['id'] !== $id ) );
				} else {
					$text = trim( mb_substr( sanitize_textarea_field( (string) $r['text'] ), 0, 300 ) );
					if ( '' === $text ) {
						return new \WP_Error( 'pc_ai_resolved', '请写一下解决了什么问题。', array( 'status' => 400 ) );
					}
					$list[] = array( 'id' => substr( md5( $text . microtime() ), 0, 10 ), 'text' => $text, 'at' => time() );
					$list   = array_slice( $list, -30 );
				}
				update_option( self::RESOLVED, $list, false );
				return rest_ensure_response( array( 'items' => $list ) );
			} ),
		) );
		register_rest_route( 'peptide/v1', '/store-score/autofill', array(
			'methods'             => 'POST',
			'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
			'callback'            => static fn() => rest_ensure_response( array( 'filled' => Store_Score::autofill(), 'setup' => Store_Score::summary() ) ),
		) );
		register_rest_route( 'peptide/v1', '/overview/extras', array( 'methods' => 'GET', 'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ), 'callback' => array( self::class, 'rest_extras' ) ) );
	}

	public static function rest_analyse( \WP_REST_Request $request ) {
		$scope   = 'site' === $request['scope'] ? 'site' : 'visitor';
		$period  = in_array( $request['period'], array( 'all', 'day', 'week', 'month' ), true ) ? (string) $request['period'] : ( 'site' === $scope ? 'day' : 'all' );
		$date    = is_string( $request['date'] ) && preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $request['date'] ) ? $request['date'] : wp_date( 'Y-m-d' );
		$r = self::run( $scope, absint( $request['visitor_id'] ), absint( $request['visit_id'] ), $period, $date, ! empty( $request['refresh'] ), 'manual' );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function rest_result( \WP_REST_Request $r ) {
		$scope = 'site' === $r['scope'] ? 'site' : 'visitor';
		$ref   = 'site' === $scope ? 0 : absint( $r['visitor_id'] );
		$res   = self::latest( $scope, $ref );
		if ( $res && empty( $res['charts'] ) ) { // 早期保存的结果没有图表数据：按同一时间段现算。
			$facts = self::facts( $scope, $ref, 0, $res['period'] ?: ( 'site' === $scope ? 'day' : 'all' ), $res['date'] ?: wp_date( 'Y-m-d' ) );
			$res['charts'] = is_wp_error( $facts ) ? null : self::charts( $scope, $facts );
		}
		return rest_ensure_response( array( 'result' => $res, 'live' => 'visitor' === $scope && $ref ? self::visitor_live( $ref, $res ? (int) $res['created'] : 0 ) : null ) );
	}

	/**
	 * 客户当前状态（店主 2026-11-10：分析右边要看到回访了没、邮件发了没、点了没、下单了没）。打开页面时现查。
	 */
	public static function visitor_live( int $vid, int $analysed_at ): ?array {
		if ( ! Tracking\Schema::installed() ) {
			return null;
		}
		global $wpdb;
		$vt  = Tracking\Schema::table( 'visitors' );
		$st  = Tracking\Schema::table( 'visits' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$vt} WHERE id = %d", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return null;
		}
		$now_ms   = Tracking\Tracker::now_ms();
		$presence = $now_ms - (int) $row['last_ms'] < 2 * MINUTE_IN_SECONDS * 1000 ? 'active' : 'away';
		$cart     = Tracking\Carts::view( $row, true );
		$intent   = Tracking\Intent::analyse( array( 'id' => $vid, 'visits' => (int) $row['visits'] ), $cart, $presence, $now_ms );

		// 联系方式：会话里填的，或者关联订单里的。
		$email = ''; $phone = '';
		if ( '' !== (string) $row['cart_key'] ) {
			$sd    = Recovery_Items::session_data( (string) $row['cart_key'] );
			$email = (string) ( $sd['billing']['email'] ?? '' );
			$phone = (string) ( $sd['billing']['phone'] ?? '' );
		}
		$orders = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$st} WHERE visitor_id = %d AND order_id > 0 ORDER BY order_id DESC LIMIT 5", $vid ) ) as $oid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$o = wc_get_order( (int) $oid );
			if ( ! $o ) {
				continue;
			}
			$email    = $email ?: (string) $o->get_billing_email();
			$phone    = $phone ?: (string) $o->get_billing_phone();
			$orders[] = array( 'number' => $o->get_order_number(), 'status' => wc_get_order_status_name( $o->get_status() ), 'paid' => (bool) $o->get_date_paid(), 'total' => html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ) ) );
		}
		// 召回：弃单记录 + 发过的邮件。
		$rec = null;
		if ( Recovery::installed() ) {
			$t   = Recovery::table();
			$rec = $wpdb->get_row( $wpdb->prepare( "SELECT status, emails, last_sent_ms, clicked_ms FROM {$t} WHERE ( session_key = %s AND session_key <> '' ) OR ( email = %s AND email <> '' ) ORDER BY id DESC LIMIT 1", (string) $row['cart_key'], strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$mails = $email ? array_values( array_filter( Recovery_Items::mails_for_email( $email ), static fn( $m ) => 'sent' === $m['status'] ) ) : array();
		$last_mail_ms = $mails ? (int) $mails[0]['created_ms'] : (int) ( $rec['last_sent_ms'] ?? 0 );
		$visits_after = static fn( int $ms ) => $ms ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE visitor_id = %d AND start_ms > %d", $vid, $ms ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$label = array( 'pending' => '待召回', 'recalling' => '召回中', 'failed' => '召回失败', 'success' => '召回成功', 'void' => '已作废' );
		return array(
			'online'        => 'active' === $presence,
			'last_seen'     => wp_date( 'm-d H:i', (int) floor( $row['last_ms'] / 1000 ) ),
			'visits'        => (int) $row['visits'],
			'back_after_ai' => $analysed_at ? $visits_after( $analysed_at * 1000 ) : null,
			'contact'       => array( 'email' => $email, 'phone' => $phone ),
			// 和弃单列表同一规则：发过召回邮件就是“召回中”，3 天没下单算“召回失败”。
			'recall'        => $rec ? ( in_array( $rec['status'], array( 'pending', 'recalling' ), true ) && $last_mail_ms ? ( time() - (int) floor( $last_mail_ms / 1000 ) > Recovery_Items::RECALL_DAYS * DAY_IN_SECONDS ? '召回失败' : '召回中' ) : ( $label[ $rec['status'] ] ?? $rec['status'] ) ) : ( $mails ? '召回中' : '' ),
			'mails'         => array_map( static fn( $m ) => array( 'subject' => (string) $m['subject'], 'at' => wp_date( 'm-d H:i', (int) floor( $m['created_ms'] / 1000 ) ) ), array_slice( $mails, 0, 3 ) ),
			'mail_count'    => count( $mails ),
			'clicked'       => ! empty( $rec['clicked_ms'] ) ? wp_date( 'm-d H:i', (int) floor( $rec['clicked_ms'] / 1000 ) ) : '',
			'back_after_mail' => $last_mail_ms ? $visits_after( $last_mail_ms ) : null,
			'orders'        => $orders,
			'intent'        => array( 'score' => $intent['score'], 'level' => $intent['level_text'], 'parts' => $intent['parts'] ),
			// 现在的购物车（和实时访客、弃单同一来源）。
			'cart'          => array( 'count' => (int) ( $cart['count'] ?? 0 ), 'status' => (string) ( $cart['status'] ?? '' ), 'subtotal' => (string) ( $cart['subtotal_text'] ?? '' ), 'payable' => (string) ( $cart['payable_text'] ?? '' ) ),
		);
	}

	/**
	 * 仪表盘底部三张卡（店主 2026-11-10：原来是空的“暂无数据”）：待处理、AI 全站分析、最近的客户分析。
	 */
	public static function rest_extras() {
		$counts = Recovery_Items::list( array(), 1, 1 )['counts'] ?? array();
		$wc     = static fn( string $st ) => (int) wc_orders_count( $st );
		$todo   = array(
			array( 'label' => '待发货订单', 'n' => $wc( 'processing' ), 'url' => admin_url( 'admin.php?page=wc-orders&status=wc-processing' ) ),
			array( 'label' => '弃单待召回', 'n' => (int) ( $counts['pending'] ?? 0 ), 'url' => admin_url( 'admin.php?page=pc-recovery&status=pending' ) ),
			array( 'label' => '弃单召回中', 'n' => (int) ( $counts['recalling'] ?? 0 ), 'url' => admin_url( 'admin.php?page=pc-recovery&status=recalling' ) ),
			array( 'label' => '召回失败', 'n' => (int) ( $counts['failed'] ?? 0 ), 'url' => admin_url( 'admin.php?page=pc-recovery&status=failed' ) ),
			array( 'label' => '付款失败订单', 'n' => $wc( 'failed' ), 'url' => admin_url( 'admin.php?page=wc-orders&status=wc-failed' ) ),
		);
		$site = self::latest( 'site', 0 );
		$ai   = null;
		if ( $site ) {
			preg_match( '/本期结论[\*\s]*[:：][\*\s]*(.+)/u', $site['text'], $m );
			$rec = self::parse_record( $site['text'] );
			$ai  = array(
				'summary' => trim( str_replace( '**', '', (string) ( $m[1] ?? mb_substr( $site['text'], 0, 160 ) ) ) ),
				'health'  => (string) ( $rec['健康分'] ?? '' ),
				'state'   => (string) ( $rec['状态'] ?? '' ),
				'first'   => (string) ( $rec['首要建议'] ?? '' ),
				'now'     => self::section_items( $site['text'], '马上做', array( '本周做', '持续观察', '**下期' ) ),
				'stuck'   => self::section_items( $site['text'], '顾客在哪里卡住', array( '**商品横向', '**最大的问题', '**商品和版块', '**店铺优化' ) ),
				'at'      => $site['at'],
				'period'  => array( 'day' => '日报', 'week' => '周报', 'month' => '月报' )[ $site['period'] ] ?? $site['period'],
				'url'     => admin_url( 'admin.php?page=pc-visitors' ),
				// 店主 2026-11-10：仪表盘“店铺评分”——四项评级和上一份报告的健康分。
				'ratings' => self::ratings( $site['text'] ),
				'prev'    => self::previous_health( (int) $site['created'] ),
			);
		}
		$recent = array();
		self::ensure_results();
		global $wpdb;
		foreach ( (array) $wpdb->get_results( 'SELECT ref, body, created FROM ' . self::results_table() . " WHERE scope = 'visitor' ORDER BY created DESC LIMIT 20", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( isset( $recent[ (int) $r['ref'] ] ) || count( $recent ) >= 5 ) {
				continue;
			}
			$rec = self::parse_record( (string) $r['body'] );
			$recent[ (int) $r['ref'] ] = array( 'visitor' => (int) $r['ref'], 'level' => (string) ( $rec['意向'] ?? '' ), 'blocker' => (string) ( $rec['主要卡点'] ?? '' ), 'type' => (string) ( $rec['心理类型'] ?? '' ), 'at' => wp_date( 'm-d H:i', (int) $r['created'] ), 'url' => admin_url( 'admin.php?page=pc-visitors&visitor=' . (int) $r['ref'] ) );
		}
		$setup = class_exists( Store_Score::class ) ? Store_Score::summary() + array( 'autofill' => Store_Score::autofill_plan() ) : null;
		$res = rest_ensure_response( array( 'todo' => $todo, 'ai' => $ai, 'recent' => array_values( $recent ), 'setup' => $setup ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** 报告“状态评级”里的四项：流量质量 / 商品吸引力 / 结账转化 / 付款成功 → {name, grade, reason}。 */
	private static function ratings( string $text ): array {
		$out = array();
		foreach ( array( '流量质量', '商品吸引力', '结账转化', '付款成功' ) as $name ) {
			if ( preg_match( '/' . $name . '[\*\s]*[:：][\*\s]*([^\n]+)/u', $text, $m ) ) {
				$line  = trim( str_replace( '**', '', $m[1] ) );
				$parts = preg_split( '/\s*(?:——|—|--|－－)\s*/u', $line, 2 );
				$grade = trim( (string) ( $parts[0] ?? '' ), ' ，,；;。' );
				$out[] = array( 'name' => $name, 'grade' => mb_substr( $grade, 0, 12 ), 'reason' => mb_substr( trim( (string) ( $parts[1] ?? '' ) ), 0, 80 ) );
			}
		}
		return $out;
	}

	/** 这一份全站报告之前的那一份的健康分（没有就空）。 */
	private static function previous_health( int $before ): string {
		global $wpdb;
		$body = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . self::results_table() . " WHERE scope = 'site' AND created < %d ORDER BY created DESC LIMIT 1", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return '' === $body ? '' : (string) ( self::parse_record( $body )['健康分'] ?? '' );
	}

	/** 从报告里取某一段的条目（去掉编号和加粗，每条截 140 字，最多 3 条）。 */
	private static function section_items( string $text, string $start, array $ends ): array {
		$pos = mb_strpos( $text, $start );
		if ( false === $pos ) {
			return array();
		}
		$rest = mb_substr( $text, $pos + mb_strlen( $start ) );
		$end  = mb_strlen( $rest );
		foreach ( $ends as $e ) {
			$p = mb_strpos( $rest, $e );
			if ( false !== $p && $p < $end ) {
				$end = $p;
			}
		}
		$out = array();
		foreach ( preg_split( '/\n/u', mb_substr( $rest, 0, $end ) ) as $line ) {
			$line = trim( preg_replace( '/^[\s\-\*•]*(\d+[\.、)]\s*)?/u', '', str_replace( '**', '', $line ) ) );
			$line = ltrim( $line, '：: ' );
			if ( mb_strlen( $line ) < 6 || preg_match( '/^(现象|原因|证据|把握)/u', $line ) ) {
				continue;
			}
			$out[] = mb_strlen( $line ) > 140 ? mb_substr( $line, 0, 140 ) . '…' : $line;
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * 分析一次（手动点按钮和自动分析共用）：整理事实 → 交给总后台 → 保存结果 → 记判断供自学习。
	 *
	 * @return array|\WP_Error
	 */
	public static function run( string $scope, int $visitor, int $visit, string $period, string $date, bool $refresh, string $source ) {
		if ( 'visitor' === $scope && ! $visitor ) {
			return new \WP_Error( 'pc_ai_bad', '请选择客户。', array( 'status' => 400 ) );
		}
		if ( 'site' === $scope ) {
			$visitor = 0;
			$visit   = 0;
			$period  = 'all' === $period ? 'day' : $period;
		}
		$cache = 'pc_ai_' . md5( implode( '|', array( $scope, $visitor, $visit, $period, $date ) ) );
		if ( ! $refresh ) {
			$hit = get_transient( $cache );
			if ( is_array( $hit ) ) {
				return $hit + array( 'cached' => true );
			}
		}
		$facts = self::facts( $scope, $visitor, $visit, $period, $date );
		if ( is_wp_error( $facts ) ) {
			return $facts;
		}
		$use = 'site' === $scope ? 'business' : 'customer';
		$r   = self::call( '/peptide-hq/v1/ai/analyse', array( 'use' => $use, 'facts' => $facts, 'experience' => self::experience( $use ), 'tuning' => self::tune( $use ) ), self::$timeout );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array(
			'text'       => (string) ( $r['text'] ?? '' ),
			'persona'    => (string) ( $r['persona'] ?? '' ),
			'model'      => (string) ( $r['model'] ?? '' ),
			'used_today' => (int) ( $r['used_today'] ?? 0 ),
			'daily_cap'  => 0,
			'at'         => wp_date( 'Y-m-d H:i' ),
			'source'     => $source,
			'period'     => $period,
			'date'       => $date,
			'charts'     => self::charts( $scope, $facts ),
		);
		set_transient( $cache, $out, 30 * MINUTE_IN_SECONDS );
		delete_transient( 'pc_ai_status' );
		self::save_result( $scope, $visitor, $period, $date, $out );
		self::log_judgment( $use, $visitor, $period, $date, $out['text'], $facts );
		return $out + array( 'cached' => false );
	}

	// ---------- 结果保存（自动分析的结果打开页面就能看到） ----------

	private const RESULTS_VER = '2';

	public static function results_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_ai_results';
	}

	private static function ensure_results(): void {
		if ( self::RESULTS_VER === get_option( 'pc_ai_results_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::results_table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(10) NOT NULL,
  ref bigint(20) unsigned NOT NULL DEFAULT 0,
  period varchar(10) NOT NULL DEFAULT '',
  pdate varchar(10) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  charts longtext NULL,
  persona varchar(80) NOT NULL DEFAULT '',
  model varchar(80) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'manual',
  created bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY lookup (scope,ref,created)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'pc_ai_results_db', self::RESULTS_VER, false );
	}

	private static function save_result( string $scope, int $ref, string $period, string $date, array $out ): void {
		self::ensure_results();
		global $wpdb;
		$wpdb->insert( self::results_table(), array( 'scope' => $scope, 'ref' => $ref, 'period' => $period, 'pdate' => $date, 'body' => $out['text'], 'charts' => wp_json_encode( $out['charts'], JSON_UNESCAPED_UNICODE ), 'persona' => mb_substr( $out['persona'], 0, 80 ), 'model' => mb_substr( $out['model'], 0, 80 ), 'source' => $out['source'], 'created' => time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// 只留最近 2000 条。
		$wpdb->query( 'DELETE FROM ' . self::results_table() . ' WHERE id <= ' . (int) $wpdb->get_var( 'SELECT id FROM ' . self::results_table() . ' ORDER BY id DESC LIMIT 1 OFFSET 2000' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 最近一次的分析结果（客户：这位客户；全站：最近一次）。 */
	public static function latest( string $scope, int $ref ): ?array {
		self::ensure_results();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::results_table() . ' WHERE scope = %s AND ref = %d ORDER BY created DESC LIMIT 1', $scope, $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return null;
		}
		$charts = json_decode( (string) ( $row['charts'] ?? '' ), true );
		return array( 'text' => (string) $row['body'], 'charts' => is_array( $charts ) ? $charts : null, 'persona' => (string) $row['persona'], 'model' => (string) $row['model'], 'source' => (string) $row['source'], 'period' => (string) $row['period'], 'date' => (string) $row['pdate'], 'at' => wp_date( 'Y-m-d H:i', (int) $row['created'] ), 'created' => (int) $row['created'] );
	}

	/** 页面上画图用的数据（就是交给 AI 的那份事实里取出来的，图和文字口径一致）。 */
	private static function charts( string $scope, array $facts ): array {
		$products = array_slice( (array) ( $facts['products'] ?? array() ), 0, 8 );
		$prod     = array_map( static fn( $p ) => array( 'name' => html_entity_decode( (string) ( $p['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ), 'views' => (int) ( $p['views'] ?? 0 ), 'carts' => (int) ( $p['cart_additions'] ?? 0 ), 'seconds' => (int) round( (float) ( $p['active_seconds'] ?? 0 ) ) ), $products );
		$mods     = array_map( static fn( $m ) => array( 'name' => (string) ( $m['label'] ?? $m['module_type'] ?? '' ), 'clicks' => (int) ( $m['clicks'] ?? 0 ) ), array_slice( (array) ( $facts['modules'] ?? array() ), 0, 6 ) );
		if ( 'visitor' === $scope ) {
			$v = (array) ( $facts['visitor'] ?? array() );
			return array( 'kind' => 'visitor', 'stage' => (int) ( $v['furthest_stage'] ?? 0 ), 'intent' => (int) ( $v['intent_score'] ?? 0 ), 'visits' => (int) ( $v['visits'] ?? 0 ), 'cart' => (string) ( $v['cart']['subtotal'] ?? '' ), 'products' => $prod, 'modules' => $mods );
		}
		$k    = (array) ( $facts['business_kpis'] ?? array() );
		$prev = is_array( $facts['【上期对比】'] ?? null ) ? $facts['【上期对比】'] : array();
		$kpi  = array();
		foreach ( array( 'visitors' => '访客', 'orders' => '订单', 'sales' => '销售额', 'conversion' => '转化率', 'aov' => '客单价' ) as $key => $label ) {
			$kpi[] = array( 'key' => $key, 'label' => $label, 'value' => $k[ $key ] ?? null, 'prev' => $prev[ $key ] ?? null );
		}
		return array(
			'kind'     => 'site',
			'currency' => get_woocommerce_currency_symbol(),
			'kpis'     => $kpi,
			'funnel'   => is_array( $facts['【漏斗】'] ?? null ) ? $facts['【漏斗】'] : array(),
			'channels' => is_array( $facts['【渠道】'] ?? null ) ? $facts['【渠道】'] : array(),
			'products' => $prod,
			'modules'  => $mods,
			'exits'    => is_array( $facts['【离开页面】']['按页面类型（没付款就离开的访问数）'] ?? null ) ? $facts['【离开页面】']['按页面类型（没付款就离开的访问数）'] : array(),
			'pfunnel'  => array_slice( (array) ( $facts['【商品漏斗】'] ?? array() ), 0, 10 ),
		);
	}

	// ---------- 自动分析（店主 2026-11-10：改成自动） ----------

	public const CRON = 'pc_ai_auto';
	private static int $timeout = 110; // 手动点按钮最多等 110 秒；自动分析 75 秒。

	public static function cron_schedules( array $s ): array {
		$s['pc_ai_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (AI)' );
		return $s;
	}

	public static function ensure_cron(): void {
		$next = wp_get_scheduled_event( self::CRON );
		if ( $next && 'pc_ai_5min' !== $next->schedule ) {
			wp_clear_scheduled_hook( self::CRON ); // 旧的 15 分钟计划换成 5 分钟。
			$next = false;
		}
		if ( self::available() && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'pc_ai_5min', self::CRON );
		}
	}

	/**
	 * 每 5 分钟，每次只做一件事（店铺的定时任务 90 秒就会被系统结束，所以一次只分析一个，调用总后台最多等 75 秒）：
	 * - 全站：每天凌晨 1 点后分析前一天；每周一另外分析上周。
	 * - 客户：最近 3 天加购或进了结账但没付款、已经离开 30 分钟以上、还没分析过（或分析后又回来过）的客户，按最近离开排序。
	 */
	public static function auto_run(): array {
		$done = array();
		if ( get_transient( 'pc_ai_auto_lock' ) ) {
			return $done;
		}
		$st = self::status( true );
		if ( empty( $st['enabled'] ) ) {
			return $done;
		}
		set_transient( 'pc_ai_auto_lock', 1, 4 * MINUTE_IN_SECONDS );
		self::$timeout = 75;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			$now = new \DateTimeImmutable( 'now', wp_timezone() );
			if ( ! empty( $st['auto_site'] ) && '' !== ( $st['business'] ?? '' ) && (int) $now->format( 'G' ) >= 1 ) {
				$jobs = array( array( 'day', $now->modify( '-1 day' )->format( 'Y-m-d' ) ) );
				if ( '1' === $now->format( 'N' ) ) {
					$jobs[] = array( 'week', $now->modify( '-7 days' )->format( 'Y-m-d' ) );
				}
				foreach ( $jobs as [ $period, $date ] ) {
					$key = 'pc_ai_auto_site_' . $period . '_' . $date;
					if ( get_option( $key ) ) {
						continue;
					}
					$r = self::run( 'site', 0, 0, $period, $date, true, 'auto' );
					if ( ! is_wp_error( $r ) ) {
						add_option( $key, time(), '', false );
					}
					$done[] = 'site:' . $period . ':' . $date . ( is_wp_error( $r ) ? ':error' : '' );
					return $done; // 一次只做一件。
				}
			}
			if ( ! empty( $st['auto_customer'] ) && '' !== ( $st['customer'] ?? '' ) && Tracking\Schema::installed() ) {
				self::ensure_results();
				global $wpdb;
				$visits  = Tracking\Schema::table( 'visits' );
				$results = self::results_table();
				$now_ms  = Tracking\Tracker::now_ms();
				$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT s.visitor_id, MAX(s.last_ms) AS last_ms FROM {$visits} s WHERE s.is_test = 0 AND s.last_ms >= %d GROUP BY s.visitor_id HAVING MAX(s.stage) BETWEEN 2 AND 4 AND MAX(s.last_ms) <= %d ORDER BY last_ms DESC LIMIT 40", $now_ms - 3 * DAY_IN_SECONDS * 1000, $now_ms - 30 * MINUTE_IN_SECONDS * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$n = 0;
				foreach ( (array) $rows as $row ) {
					if ( $n >= 1 ) {
						break;
					}
					$vid  = (int) $row['visitor_id'];
					$last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created) FROM {$results} WHERE scope = 'visitor' AND ref = %d", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					if ( $last && $last * 1000 >= (int) $row['last_ms'] ) {
						continue; // 分析过，之后也没再来。
					}
					$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(order_id) FROM {$visits} WHERE visitor_id = %d", $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$ord = $oid ? wc_get_order( $oid ) : null;
					if ( $ord && Test_Orders::is_test_order( $ord ) ) {
						continue; // 测试单（收件信息全 1）不分析。
					}
					$r = self::run( 'visitor', $vid, 0, 'all', wp_date( 'Y-m-d' ), true, 'auto' );
					++$n;
					$done[] = 'visitor:' . $vid . ( is_wp_error( $r ) ? ':error' : '' );
				}
			}
		} finally {
			delete_transient( 'pc_ai_auto_lock' );
		}
		return $done;
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
		// 人设调优不再混在数据里，单独以 tuning 发给总后台（HQ 把它放进系统提示，优先于通用人设）。
		$done = array_map( static fn( $i ) => $i['text'], self::resolved() );
		if ( $done ) {
			$data['【店主确认已解决的问题】'] = $done;
		}
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
				'intent_parts'   => $intent['parts'], // 意向分每一项：得分 / 满分 / 说明
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
			'运费提前显示' => class_exists( Ship_Preview::class ) ? Ship_Preview::ai_note() : '关闭',
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
		$out += self::product_funnel( $from, $to );
		$out['【离开页面】'] = self::exit_pages( $from, $to );
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

	/**
	 * 商品漏斗（店主 2026-11-10：全站分析要看每个商品在哪一步失败、横向比较）：
	 * 浏览 / 点击 / 加购 / 停留来自访客行为；下单（含没付款）/ 付款来自这段时间创建的订单（不含测试单）。
	 */
	private static function product_funnel( int $from, int $to ): array {
		$rows = array();
		if ( Tracking\Schema::installed() ) {
			global $wpdb;
			$ev = Tracking\Schema::table( 'events' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT product_id, SUM(type='product_view') AS views, SUM(type='product_click') AS clicks, SUM(type='add_to_cart') AS carts, COUNT(DISTINCT CASE WHEN type IN ('product_view','product_click') THEN visitor_id END) AS people FROM {$ev} WHERE occurred_ms >= %d AND occurred_ms < %d AND is_test = 0 AND product_id > 0 GROUP BY product_id ORDER BY views DESC, clicks DESC LIMIT 30", $from * 1000, $to * 1000 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$pid          = (int) $r['product_id'];
				$p            = wc_get_product( $pid );
				$pid          = $p && $p->get_parent_id() ? $p->get_parent_id() : $pid;
				$rows[ $pid ] = array( '商品' => html_entity_decode( (string) get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ), '价格' => $p ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $p->get_price() ) ) ) : '', '看的人' => (int) $r['people'], '浏览' => (int) $r['views'], '点击' => (int) $r['clicks'], '加购' => (int) $r['carts'], '下单（含未付款）' => 0, '付款订单' => 0 );
			}
		}
		foreach ( wc_get_orders( array( 'date_created' => $from . '...' . $to, 'limit' => 500, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
			if ( Test_Orders::is_test_order( $o ) ) {
				continue;
			}
			$seen = array();
			foreach ( $o->get_items() as $item ) {
				$pid = (int) $item->get_product_id();
				if ( isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ] = true;
				if ( ! isset( $rows[ $pid ] ) ) {
					$rows[ $pid ] = array( '商品' => html_entity_decode( (string) get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ), '价格' => '', '看的人' => 0, '浏览' => 0, '点击' => 0, '加购' => 0, '下单（含未付款）' => 0, '付款订单' => 0 );
				}
				++$rows[ $pid ]['下单（含未付款）'];
				if ( $o->get_date_paid() ) {
					++$rows[ $pid ]['付款订单'];
				}
			}
		}
		return array( '【商品漏斗】' => array_values( array_slice( $rows, 0, 25 ) ) );
	}

	/** 访客最后停在哪个页面离开（每次访问的最后一个页面），按页面类型和商品统计。 */
	private static function exit_pages( int $from, int $to ): array {
		if ( ! Tracking\Schema::installed() ) {
			return array();
		}
		global $wpdb;
		$ev   = Tracking\Schema::table( 'events' );
		$vs   = Tracking\Schema::table( 'visits' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.page_type, e.product_id, e.path, s.stage FROM {$ev} e INNER JOIN ( SELECT x.visit_id, MAX(x.occurred_ms) AS m FROM {$ev} x INNER JOIN {$vs} v ON v.id = x.visit_id WHERE v.start_ms >= %d AND v.start_ms < %d AND v.is_test = 0 AND x.type = 'page_view' GROUP BY x.visit_id LIMIT 5000 ) last ON last.visit_id = e.visit_id AND last.m = e.occurred_ms AND e.type = 'page_view' INNER JOIN {$vs} s ON s.id = e.visit_id", $from * 1000, $to * 1000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$types = array( 'home' => '首页', 'product' => '商品页', 'cart' => '购物车', 'checkout' => '结账页', 'order_pay' => '付款页', 'order_received' => '下单成功页', 'shop' => '商品列表', 'category' => '分类页', 'search' => '搜索页', 'account' => '我的账户', 'page' => '普通页面', 'post' => '博客', 'content' => '内容页' );
		$by_type = array();
		$by_product = array();
		foreach ( (array) $rows as $r ) {
			if ( (int) $r['stage'] >= 5 ) {
				continue; // 付了款的不算“流失”。
			}
			$t             = $types[ $r['page_type'] ] ?? ( $r['page_type'] ?: '其他' );
			$by_type[ $t ] = ( $by_type[ $t ] ?? 0 ) + 1;
			if ( 'product' === $r['page_type'] && (int) $r['product_id'] ) {
				$n                = html_entity_decode( (string) get_the_title( (int) $r['product_id'] ), ENT_QUOTES, 'UTF-8' );
				$by_product[ $n ] = ( $by_product[ $n ] ?? 0 ) + 1;
			}
		}
		arsort( $by_type );
		arsort( $by_product );
		return array( '按页面类型（没付款就离开的访问数）' => $by_type, '离开最多的商品页' => array_slice( $by_product, 0, 10, true ) );
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
