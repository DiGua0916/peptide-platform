<?php
/**
 * 广告像素的服务端回传（PIXELS-01）：Facebook Conversions API、TikTok Events API。
 *
 * - 只对填了 access token 的像素发送；token 只在服务端使用，不输出到页面。
 * - 事件和浏览器端用同一个 event_id，平台按 event_id 去重。
 * - 发送放进 WooCommerce 自带的 Action Scheduler 后台执行（不拖慢页面）；结果写进发送记录表 {prefix}pc_px_log（保留 7 天）。
 * - 顾客资料：IP、浏览器 UA、平台 Cookie（_fbp、_fbc、_ttp、ttclid），以及已知的邮箱 / 电话 / 顾客编号的 SHA-256 哈希。
 *   下单时把这些记到订单（_pc_px_ctx），订单之后在后台或支付回调里变成已付款时，仍然能带上顾客的信息回传购买。
 *   顾客拒绝分享（GPC / 退出）或员工下单时，订单记 _pc_px_optout，不回传。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pixels;

use PeptideCore\Pixels;

defined( 'ABSPATH' ) || exit;

final class Server {

	public const HOOK        = 'pc_px_server_send';
	public const ORDER_SENT  = '_pc_px_server';
	public const ORDER_CTX   = '_pc_px_ctx';
	public const GRAPH       = 'v26.0';
	public const KEEP_DAYS   = 7;

	private const DB_OPTION  = 'peptide_core_px_log_db';
	private const DB_VERSION = '1';

	/** 测试时直接发送（不进后台队列）。 */
	public static bool $sync = false;

	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'capture_context' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'capture_context' ), 5, 1 );
	}

	// ---------- 顾客信息 ----------

	/** 当前请求的顾客信息。 */
	public static function context(): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cookie = static fn( string $k ): string => isset( $_COOKIE[ $k ] ) ? substr( sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ), 0, 300 ) : '';
		$ctx    = array(
			'ip'     => class_exists( '\WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'ua'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '',
			'fbp'    => $cookie( '_fbp' ),
			'fbc'    => $cookie( '_fbc' ),
			'ttp'    => $cookie( '_ttp' ),
			'ttclid' => $cookie( 'pc_ttclid' ),
			'host'   => (string) preg_replace( '/^www\./', '', strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ),
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $ctx;
	}

	/** 下单时记下顾客信息，供订单付款后回传购买。 */
	public static function capture_context( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || ! Pixels::any() ) {
			return;
		}
		if ( ! Pixels::allowed() ) {
			$order->update_meta_data( '_pc_px_optout', '1' );
		} else {
			$order->update_meta_data( self::ORDER_CTX, self::context() );
		}
		$order->save_meta_data();
	}

	/** 订单上记的顾客信息；没有（例如后台手动建的订单）时返回 null。 */
	public static function context_from_order( \WC_Order $order ): ?array {
		$ctx = $order->get_meta( self::ORDER_CTX );
		return is_array( $ctx ) && $ctx ? $ctx : null;
	}

	// ---------- 发送 ----------

	/** 要服务端回传的像素：[platform, pixel_id, token]，按关联域名筛选。 */
	public static function targets( array $s, string $host ): array {
		$out = array();
		foreach ( array( 'facebook', 'tiktok' ) as $key ) {
			foreach ( $s['platforms'][ $key ]['pixels'] as $px ) {
				if ( '' !== $px['token'] && ( '' === $px['domain'] || $px['domain'] === $host ) ) {
					$out[] = array( $key, $px['id'], $px['token'] );
				}
			}
		}
		return $out;
	}

	/**
	 * 把事件放进后台队列发送。$ctx 为空时用当前请求的顾客信息；订单付款时传订单上记的信息（没有就不发）。
	 *
	 * @param array[] $events
	 */
	public static function send( array $events, array $user = array(), ?array $ctx = null ): void {
		$s = Pixels::settings();
		if ( null === $ctx ) {
			if ( doing_action( 'woocommerce_order_status_changed' ) ) {
				return; // 订单没有记下顾客信息（后台建的订单等），不回传。
			}
			$ctx = self::context();
		}
		if ( ! self::targets( $s, (string) ( $ctx['host'] ?? '' ) ) || ! $events ) {
			return;
		}
		$payload = array( 'events' => $events, 'user' => $s['match'] ? $user : array(), 'ctx' => $ctx );
		if ( self::$sync || ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run( $payload );
			return;
		}
		as_enqueue_async_action( self::HOOK, array( $payload ), 'peptide-core-pixels' );
	}

	/** 后台任务：真正发给各平台。 */
	public static function run( $payload ): void {
		if ( ! is_array( $payload ) || empty( $payload['events'] ) ) {
			return;
		}
		$s    = Pixels::settings();
		$defs = Pixels::platforms();
		foreach ( self::targets( $s, (string) ( $payload['ctx']['host'] ?? '' ) ) as [ $platform, $pixel, $token ] ) {
			$events = array_values(
				array_filter(
					(array) $payload['events'],
					static fn( $e ) => isset( $defs[ $platform ]['events'][ $e['name'] ] ) && ! in_array( $platform, (array) ( $e['skip'] ?? array() ), true )
				)
			);
			if ( ! $events ) {
				continue;
			}
			if ( 'facebook' === $platform ) {
				$url  = 'https://graph.facebook.com/' . self::GRAPH . '/' . rawurlencode( $pixel ) . '/events?access_token=' . rawurlencode( $token );
				$body = self::facebook_body( $events, (array) $payload['user'], (array) $payload['ctx'], $s );
				$args = array( 'headers' => array( 'Content-Type' => 'application/json' ) );
			} else {
				$url  = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
				$body = self::tiktok_body( $pixel, $events, (array) $payload['user'], (array) $payload['ctx'], $s );
				$args = array( 'headers' => array( 'Content-Type' => 'application/json', 'Access-Token' => $token ) );
			}
			$res  = wp_remote_post( $url, $args + array( 'body' => wp_json_encode( $body ), 'timeout' => 10 ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$text = is_wp_error( $res ) ? $res->get_error_message() : (string) wp_remote_retrieve_body( $res );
			$ok   = 200 === $code && ( 'facebook' === $platform || 0 === (int) ( json_decode( $text, true )['code'] ?? -1 ) );
			foreach ( $events as $e ) {
				self::log( $platform, $pixel, $defs[ $platform ]['events'][ $e['name'] ], (string) $e['id'], $ok, $code, $ok ? '' : substr( wp_strip_all_tags( $text ), 0, 300 ) );
			}
		}
	}

	/** 事件时间：Meta 拒收 7 天以前的事件（整批都不收），晚付款的订单用付款时的时间。 */
	private static function event_time( array $e ): int {
		return max( (int) $e['time'], time() - 6 * DAY_IN_SECONDS );
	}

	/** 金额：设置“销售额使用商品金额”时用商品小计，否则用订单 / 事件金额。 */
	private static function value( array $e, bool $items_value ): float {
		return (float) ( $items_value ? ( $e['items_value'] ?? $e['value'] ) : $e['value'] );
	}

	public static function facebook_body( array $events, array $user, array $ctx, array $s ): array {
		$fb   = $s['platforms']['facebook'];
		$map  = Pixels::platforms()['facebook']['events'];
		$data = array();
		foreach ( $events as $e ) {
			$items = (array) $e['items'];
			$ud    = array_filter(
				array(
					'client_ip_address' => (string) ( $ctx['ip'] ?? '' ),
					'client_user_agent' => (string) ( $ctx['ua'] ?? '' ),
					'fbp'               => (string) ( $ctx['fbp'] ?? '' ),
					'fbc'               => (string) ( $ctx['fbc'] ?? '' ),
					'em'                => isset( $user['em'] ) ? array( $user['em'] ) : null,
					'ph'                => isset( $user['ph'] ) ? array( $user['ph'] ) : null,
					'external_id'       => isset( $user['external_id'] ) ? array( $user['external_id'] ) : null,
				)
			);
			$ids   = array_map( static fn( $i ) => 'sku' === $fb['content_id'] ? $i['sku'] : $i['spu'], $items );
			$cd    = array_filter(
				array(
					'currency'     => (string) $e['currency'],
					'value'        => self::value( $e, (bool) $fb['items_value'] ),
					'content_ids'  => $ids ? $ids : null,
					'content_type' => $items ? 'product' : null,
					'contents'     => $items ? array_map( static fn( $i, $id ) => array( 'id' => $id, 'quantity' => (int) $i['quantity'], 'item_price' => (float) $i['price'] ), $items, $ids ) : null,
					'num_items'    => $items ? array_sum( array_column( $items, 'quantity' ) ) : null,
					'content_name' => 'view_item' === $e['name'] && $items ? self::content_name( $items[0], (string) $fb['content_name'] ) : null,
					'order_id'     => $e['order_id'] ?? null,
					'search_string' => $e['search'] ?? null,
				),
				static fn( $v ) => null !== $v && '' !== $v
			);
			$data[] = array(
				'event_name'       => $map[ $e['name'] ],
				'event_time'       => self::event_time( $e ),
				'event_id'         => (string) $e['id'],
				'event_source_url' => (string) $e['url'],
				'action_source'    => 'website',
				'user_data'        => $ud,
				'custom_data'      => $cd,
			);
		}
		$body = array( 'data' => $data );
		if ( '' !== $s['test_code'] ) {
			$body['test_event_code'] = $s['test_code'];
		}
		return $body;
	}

	public static function content_name( array $item, string $mode ): string {
		switch ( $mode ) {
			case 'internal':
				return (string) $item['internal'];
			case 'category':
				return (string) $item['category'];
			case 'spu':
				return (string) $item['spu'];
		}
		return (string) $item['name'];
	}

	public static function tiktok_body( string $pixel, array $events, array $user, array $ctx, array $s ): array {
		$tt   = $s['platforms']['tiktok'];
		$map  = Pixels::platforms()['tiktok']['events'];
		$data = array();
		foreach ( $events as $e ) {
			$items  = (array) $e['items'];
			$data[] = array(
				'event'      => $map[ $e['name'] ],
				'event_time' => self::event_time( $e ),
				'event_id'   => (string) $e['id'],
				'user'       => array_filter(
					array(
						'email'       => $user['em'] ?? null,
						'phone'       => $user['ph_plus'] ?? null, // TikTok：E.164（带 +）再加密。
						'external_id' => $user['external_id'] ?? null,
						'ip'          => (string) ( $ctx['ip'] ?? '' ),
						'user_agent'  => (string) ( $ctx['ua'] ?? '' ),
						'ttp'         => (string) ( $ctx['ttp'] ?? '' ),
						'ttclid'      => (string) ( $ctx['ttclid'] ?? '' ),
					)
				),
				'page'       => array( 'url' => (string) $e['url'] ),
				'properties' => array_filter(
					array(
						'currency'     => (string) $e['currency'],
						'value'        => self::value( $e, (bool) $tt['items_value'] ),
						'content_type' => $items ? 'product' : null,
						'contents'     => $items ? array_map( static fn( $i ) => array( 'content_id' => $i['spu'], 'content_name' => $i['name'], 'quantity' => (int) $i['quantity'], 'price' => (float) $i['price'] ), $items ) : null,
						'order_id'     => $e['order_id'] ?? null,
						'search_string' => $e['search'] ?? null,
					),
					static fn( $v ) => null !== $v && '' !== $v
				),
			);
		}
		return array( 'event_source' => 'web', 'event_source_id' => $pixel, 'data' => $data );
	}

	// ---------- 发送记录 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_px_log';
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sent_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  platform varchar(20) NOT NULL,
  pixel varchar(120) NOT NULL DEFAULT '',
  event varchar(40) NOT NULL DEFAULT '',
  event_id varchar(64) NOT NULL DEFAULT '',
  ok tinyint(1) NOT NULL DEFAULT 0,
  code smallint(5) unsigned NOT NULL DEFAULT 0,
  message varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY sent_ms (sent_ms)
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function log( string $platform, string $pixel, string $event, string $event_id, bool $ok, int $code, string $message ): void {
		global $wpdb;
		if ( ! self::installed() ) {
			self::install();
		}
		$t = self::table();
		$wpdb->insert( $t, array( 'sent_ms' => (int) round( microtime( true ) * 1000 ), 'platform' => $platform, 'pixel' => $pixel, 'event' => $event, 'event_id' => $event_id, 'ok' => $ok ? 1 : 0, 'code' => $code, 'message' => $message ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === wp_rand( 1, 50 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE sent_ms < %d", (int) ( ( time() - self::KEEP_DAYS * DAY_IN_SECONDS ) * 1000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/** 最近的发送记录。 */
	public static function recent( int $limit = 30 ): array {
		global $wpdb;
		if ( ! self::installed() ) {
			return array();
		}
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
