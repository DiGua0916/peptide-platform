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
		$r = self::call( '/peptide-hq/v1/ai/analyse', array( 'use' => 'site' === $scope ? 'business' : 'customer', 'facts' => $facts ), 100 );
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
		if ( 'visitor' === $scope && Tracking\Schema::installed() ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visitors' ) . ' WHERE id = %d', $visitor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				return new \WP_Error( 'pc_ai_bad', '找不到这位访客。', array( 'status' => 404 ) );
			}
			$cart   = Tracking\Carts::view( $row, true );
			$intent = Tracking\Intent::analyse( array( 'id' => (int) $row['id'], 'visits' => (int) $row['visits'] ), $cart, 'away', Tracking\Tracker::now_ms() );
			$visit_row = $wpdb->get_row( $wpdb->prepare( 'SELECT utm_source, utm_medium, referrer_host, landing_path, stage, device FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY start_ms DESC LIMIT 1', $visitor ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$data['visitor'] = array(
				'device'         => (string) $row['device'],
				'browser'        => (string) $row['browser'],
				'visits'         => (int) $row['visits'],
				'first_seen'     => wp_date( 'Y-m-d H:i', (int) floor( $row['first_ms'] / 1000 ) ),
				'last_seen'      => wp_date( 'Y-m-d H:i', (int) floor( $row['last_ms'] / 1000 ) ),
				'latest_source'  => $visit_row ? trim( $visit_row['utm_source'] . ' / ' . $visit_row['utm_medium'], ' /' ) ?: (string) $visit_row['referrer_host'] : '',
				'furthest_stage' => $visit_row ? (int) $visit_row['stage'] : 0,
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
}
