<?php
/**
 * M6 冒烟用例共用的准备（不是用例，不单独运行）。
 *
 * 数据报表的冒烟用例都要一个“已发布、价格大于 0、不是演示商品的简单商品”来下测试订单。
 * 开发站有这样的商品；生产店的脱敏快照里全是多规格商品，用例一上来就以“FAIL fixture product”退出。
 * 找不到时用这里的函数临时建一个：目录里不可见，脚本结束时（包括中途出错退出）彻底删除。
 *
 * 用法（在 wp eval-file 运行的用例里）：
 *   require_once __DIR__ . '/m6-smoke-fixtures.php';   // 和用例放在同一个目录，打包上传到沙盒时要一起带上
 *   $product = pc_m6_smoke_temp_product();
 *
 * 另外有几个“不用浏览器回放访客行为”的函数（2026-11-12，给 m6-product-activity-smoke.php、m6-funnel-replay-smoke.php 用）：
 *   pc_m6_smoke_browser() / pc_m6_smoke_browser_restore()：让这次命令行运行看起来像一位匿名访客的浏览器，用完恢复；
 *   pc_m6_smoke_new_visitor()：换一位新访客；
 *   pc_m6_smoke_send( [ [ 事件类型, 页面, 数据 ], … ] )：向事件接收接口提交一批浏览器事件，格式和前台脚本上报的一样；
 *   pc_m6_smoke_current_visit()：当前访客的访客编号、访问编号、访问的阶段、访问上挂的订单号。
 *
 * 和各冒烟用例一样，只在开发站 / 沙盒运行，不要在正式站点运行。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pc_m6_smoke_temp_product' ) ) {
	/**
	 * 临时建一个简单商品，返回商品对象；建不出来返回 null。
	 */
	function pc_m6_smoke_temp_product(): ?WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'M6 smoke fixture (temporary)' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '25' );
		$id = (int) $product->save();
		if ( $id <= 0 ) {
			return null;
		}
		register_shutdown_function(
			static function () use ( $id ): void {
				$saved = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( $saved ) {
					$saved->delete( true );
				}
			}
		);
		echo "INFO no usable simple product in this store: temporary product #{$id} created, deleted when the script ends\n";
		$saved = wc_get_product( $id );
		return $saved instanceof WC_Product ? $saved : null;
	}
}

if ( ! function_exists( 'pc_m6_smoke_browser' ) ) {
	/**
	 * 让这一次命令行运行看起来像“一位匿名访客的浏览器”：没有登录、带浏览器的 User-Agent、文档专用的 IP。
	 * 返回运行前的环境，用完交给 pc_m6_smoke_browser_restore() 恢复。
	 */
	function pc_m6_smoke_browser(): array {
		$cookie = \PeptideCore\Tracking\Tracker::COOKIE;
		$backup = array(
			'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
			'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
			'cookie' => $_COOKIE[ $cookie ] ?? null,
			'user'   => get_current_user_id(),
		);
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.' . wp_rand( 2, 250 );
		unset( $_COOKIE[ $cookie ] );
		wp_set_current_user( 0 );
		return $backup;
	}

	function pc_m6_smoke_browser_restore( array $backup ): void {
		$cookie = \PeptideCore\Tracking\Tracker::COOKIE;
		foreach ( array( 'ua' => 'HTTP_USER_AGENT', 'ip' => 'REMOTE_ADDR' ) as $key => $name ) {
			if ( null === ( $backup[ $key ] ?? null ) ) {
				unset( $_SERVER[ $name ] );
			} else {
				$_SERVER[ $name ] = $backup[ $key ];
			}
		}
		if ( null === ( $backup['cookie'] ?? null ) ) {
			unset( $_COOKIE[ $cookie ] );
		} else {
			$_COOKIE[ $cookie ] = $backup['cookie'];
		}
		wp_set_current_user( (int) ( $backup['user'] ?? 0 ) );
	}

	/** 换一位新访客（像换了一台浏览器）：清掉访客 cookie，下一次上报时接口会发一个新的。 */
	function pc_m6_smoke_new_visitor(): void {
		unset( $_COOKIE[ \PeptideCore\Tracking\Tracker::COOKIE ] );
	}

	/**
	 * 以当前访客的身份向事件接收接口（POST /peptide/v1/track/events）提交一批浏览器事件，和前台脚本上报的格式一样。
	 * $events：每项 [ 事件类型, 页面（page_type、path、product_id…）, 数据 ]，按顺序。
	 * 返回 [ 状态码, 被确认的事件数, 被拒绝的原因 ]。
	 */
	function pc_m6_smoke_send( array $events ): array {
		$now  = \PeptideCore\Tracking\Tracker::now_ms();
		$iso  = static fn( int $ms ): string => gmdate( 'Y-m-d\TH:i:s', (int) floor( $ms / 1000 ) ) . '.' . str_pad( (string) ( $ms % 1000 ), 3, '0', STR_PAD_LEFT ) . 'Z';
		$page = wp_generate_uuid4();
		$list = array();
		$n    = count( $events );
		foreach ( array_values( $events ) as $i => $event ) {
			$list[] = array(
				'event_id'         => wp_generate_uuid4(),
				'page_instance_id' => $page,
				'seq'              => $i + 1,
				'type'             => (string) $event[0],
				'occurred_at'      => $iso( $now - ( $n - $i ) ),
				'page'             => (array) ( $event[1] ?? array() ) + array( 'page_type' => 'home', 'path' => '/' ),
				'data'             => (array) ( $event[2] ?? array() ),
			);
		}
		$request = new \WP_REST_Request( 'POST', '/peptide/v1/track/events' );
		$request->set_header( 'Content-Type', 'text/plain' ); // 和浏览器的 sendBeacon 一样。
		$request->set_body( wp_json_encode( array( 'protocol' => 1, 'tab_id' => 'tab-' . substr( md5( $page ), 0, 12 ), 'sent_at' => $iso( $now ), 'events' => $list ) ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		return array(
			(int) $response->get_status(),
			is_array( $data ) && is_array( $data['ack'] ?? null ) ? count( $data['ack'] ) : 0,
			is_array( $data ) && is_array( $data['rejected'] ?? null ) ? array_column( $data['rejected'], 'code' ) : array(),
		);
	}

	/**
	 * 当前访客（按访客 cookie）在追踪表里的编号和最近一次访问：[ 访客编号, 访问编号, 访问的阶段, 访问上挂的订单号 ]；找不到都是 0。
	 */
	function pc_m6_smoke_current_visit(): array {
		global $wpdb;
		$vid = \PeptideCore\Tracking\Tracker::current_vid();
		if ( '' === $vid ) {
			return array( 0, 0, 0, 0 );
		}
		$visitor = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \PeptideCore\Tracking\Schema::table( 'visitors' ) . ' WHERE vid = %s', $vid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$visit   = $visitor ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, stage, order_id FROM ' . \PeptideCore\Tracking\Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY start_ms DESC, id DESC LIMIT 1', $visitor ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( $visitor, (int) ( $visit['id'] ?? 0 ), (int) ( $visit['stage'] ?? 0 ), (int) ( $visit['order_id'] ?? 0 ) );
	}
}
