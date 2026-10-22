<?php
/**
 * 访客追踪接口（VISITOR-ANALYTICS-TEST-01，测试版）。
 *
 * 前台（不需要登录；追踪关闭时 403 pc_track_disabled）：
 *   GET  /peptide/v1/track/session  当前浏览器的追踪状态：{protocol, enabled, test_mode, tracked, config}。
 *                                    tracked=false 表示员工账号（不追踪）。访客 cookie 由页面加载时的服务器发放，
 *                                    这里没有时也会补发。不返回访客编号本身。
 *   POST /peptide/v1/track/events   批量上报，格式和返回见 Tracking\Ingest。
 *
 * 后台（需要 manage_woocommerce）：
 *   GET /peptide/v1/track/admin/live
 *   GET /peptide/v1/track/admin/visitors/{id}
 *   GET /peptide/v1/track/admin/visits/{id}
 *   GET /peptide/v1/track/admin/stats?from=YYYY-MM-DD&to=YYYY-MM-DD&device=
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

use PeptideCore\Tracking\Ingest;
use PeptideCore\Tracking\Query;
use PeptideCore\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Tracking_Controller {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns = Rest_Controller::ROUTE_NAMESPACE;
		register_rest_route(
			$ns,
			'/track/session',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'session' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/track/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'events' ),
				'permission_callback' => '__return_true',
			)
		);
		$admin = array( self::class, 'can_view' );
		register_rest_route( $ns, '/track/admin/live', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'live' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/track/admin/visitors/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'visitor' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/track/admin/visits/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'visit' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/track/admin/stats', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'stats' ), 'permission_callback' => $admin ) );
	}

	private static function disabled(): \WP_Error {
		return new \WP_Error( 'pc_track_disabled', __( 'Visitor tracking is turned off.', 'peptide-core' ), array( 'status' => 403 ) );
	}

	/** 追踪接口的回复不能被缓存。 */
	private static function respond( $data, int $status = 200 ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function session(): \WP_REST_Response {
		$enabled = Tracker::enabled();
		$staff   = Tracker::is_staff( Tracker::cookie_user_id() );
		if ( $enabled && ! $staff && '' === Tracker::current_vid() ) {
			Tracker::issue_vid();
		}
		return self::respond(
			array(
				'protocol'  => Tracker::PROTOCOL,
				'enabled'   => $enabled,
				'test_mode' => true,
				'tracked'   => $enabled && ! $staff,
				'config'    => $enabled ? Tracker::observer_config( array() ) : null,
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function events( \WP_REST_Request $request ) {
		if ( ! Tracker::enabled() ) {
			return self::disabled();
		}
		$body = Ingest::parse( (string) $request->get_body() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$result = Ingest::handle( $body, Tracker::now_ms() );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( isset( $data['retry_after'] ) ) {
				$res = rest_convert_error_to_response( $result );
				$res->header( 'Retry-After', (string) (int) $data['retry_after'] );
				return $res;
			}
			return $result;
		}
		return self::respond(
			array(
				'protocol'    => Tracker::PROTOCOL,
				'ack'         => $result['ack'],
				'rejected'    => $result['rejected'],
				'server_time' => gmdate( 'Y-m-d\TH:i:s', (int) floor( Tracker::now_ms() / 1000 ) ) . 'Z',
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_view() {
		return current_user_can( 'manage_woocommerce' ) ? true : new \WP_Error( 'pc_track_forbidden', __( 'Sorry, you are not allowed to view visitor data.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public static function live(): \WP_REST_Response {
		$now = Tracker::now_ms();
		return self::respond( array( 'now_ms' => $now, 'enabled' => Tracker::enabled(), 'visitors' => Query::live( $now ) ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function visitor( \WP_REST_Request $request ) {
		$data = Query::visitor( (int) $request['id'] );
		return $data ? self::respond( $data ) : new \WP_Error( 'pc_track_not_found', __( 'Visitor not found.', 'peptide-core' ), array( 'status' => 404 ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function visit( \WP_REST_Request $request ) {
		$data = Query::timeline( (int) $request['id'] );
		return $data ? self::respond( $data ) : new \WP_Error( 'pc_track_not_found', __( 'Visit not found.', 'peptide-core' ), array( 'status' => 404 ) );
	}

	public static function stats( \WP_REST_Request $request ): \WP_REST_Response {
		// 日期按店铺时区（每天 0 点开始）。
		$tz    = wp_timezone();
		$day   = static function ( $value, int $fallback ) use ( $tz ): int {
			if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $fallback;
			}
			$d = date_create_immutable( $value . ' 00:00:00', $tz );
			return $d ? $d->getTimestamp() * 1000 : $fallback;
		};
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 )->getTimestamp() * 1000;
		$from  = $day( $request['from'], $today - 6 * 86400000 );
		$to    = $day( $request['to'], $today ) + 86400000;
		if ( $to <= $from ) {
			$to = $from + 86400000;
		}
		$device = is_string( $request['device'] ) ? sanitize_key( $request['device'] ) : '';
		return self::respond( Query::stats( $from, min( $to, $from + 366 * 86400000 ), $device ) );
	}
}
