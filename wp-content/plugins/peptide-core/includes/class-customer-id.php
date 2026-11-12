<?php
/**
 * 客户编号（店主 2026-11-12）：给每个顾客一个固定编号，同一个人重复下单、下次再来都能认出来。
 *
 * - 编号 = “C” + 7 位，由顾客邮箱算出（小写、去掉 +后缀，Gmail 去掉点）；没有邮箱用手机号。同一个邮箱在所有店铺都是同一个编号。
 * - 新订单下单时写入 _pc_customer_id，并在后台（cron）把“订单 → 编号”报给总后台，支付中心据此合并同一个人的多次付款。
 * - 老订单不改：显示时现算；补报总后台只写总后台的表。
 * - 总后台只收编号、打码邮箱（j***@gmail.com）、国家和设备摘要，不收邮箱原文。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Customer_Id {

	public const META  = '_pc_customer_id';
	public const CRON  = 'pc_customer_id_push';
	private const ROUTE = '/peptide-hq/v1/customers/link';
	private const ALPHA = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford，不含 I L O U，读起来不混。

	public static function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order' ), 30, 1 );
		add_action( self::CRON, array( self::class, 'push' ), 10, 1 );
	}

	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! str_contains( $email, '@' ) ) {
			return '';
		}
		list( $user, $domain ) = explode( '@', $email, 2 );
		$user = explode( '+', $user, 2 )[0];
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$user   = str_replace( '.', '', $user );
			$domain = 'gmail.com';
		}
		return '' === $user ? '' : $user . '@' . $domain;
	}

	public static function compute( string $email, string $phone = '' ): string {
		$key = self::normalize_email( $email );
		if ( '' === $key ) {
			$digits = preg_replace( '/\D/', '', $phone );
			if ( strlen( (string) $digits ) < 7 ) {
				return '';
			}
			$key = 'tel:' . substr( (string) $digits, -10 ); // 只看后 10 位，带不带国家码都一样。
		}
		$bin = hash( 'sha256', 'vialcove-customer|' . $key, true );
		$out = 'C';
		$acc = ( ord( $bin[0] ) << 24 ) | ( ord( $bin[1] ) << 16 ) | ( ord( $bin[2] ) << 8 ) | ord( $bin[3] );
		$acc = ( $acc << 8 ) | ord( $bin[4] ); // 40 位，取前 35 位 = 7 个字符。
		for ( $i = 0; $i < 7; $i++ ) {
			$out .= self::ALPHA[ ( $acc >> ( 35 - 5 * $i ) ) & 31 ];
		}
		return $out;
	}

	public static function for_order( \WC_Order $o ): string {
		$id = (string) $o->get_meta( self::META );
		return '' !== $id ? $id : self::compute( (string) $o->get_billing_email(), (string) $o->get_billing_phone() );
	}

	/** 新订单：写编号，排队报总后台。 */
	public static function on_order( $order ): void {
		$o = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $o instanceof \WC_Order ) {
			return;
		}
		$id = self::compute( (string) $o->get_billing_email(), (string) $o->get_billing_phone() );
		if ( '' !== $id && $id !== (string) $o->get_meta( self::META ) ) {
			$o->update_meta_data( self::META, $id );
			$o->save_meta_data();
		}
		if ( ! wp_next_scheduled( self::CRON, array( $o->get_id() ) ) ) {
			wp_schedule_single_event( time() + 20, self::CRON, array( $o->get_id() ) );
		}
	}

	private static function mask_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! str_contains( $email, '@' ) ) {
			return '';
		}
		list( $u, $d ) = explode( '@', $email, 2 );
		return mb_substr( $u, 0, 1 ) . '***@' . $d;
	}

	private static function device( \WC_Order $o ): string {
		global $wpdb;
		$vid = (int) $o->get_meta( '_pc_track_visitor' );
		if ( ! $vid ) {
			return '';
		}
		$t = $wpdb->prefix . 'pc_track_visitors';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return '';
		}
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT vid FROM {$t} WHERE id = %d", $vid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '' === $raw ? '' : substr( hash( 'sha256', 'vialcove-device|' . $raw ), 0, 16 );
	}

	/** @return array<string,string>|null */
	public static function link( \WC_Order $o ): ?array {
		$id = self::for_order( $o );
		if ( '' === $id ) {
			return null;
		}
		return array(
			'order_id'    => (string) $o->get_id(),
			'customer_id' => $id,
			'device'      => self::device( $o ),
			'email_mask'  => self::mask_email( (string) $o->get_billing_email() ),
			'country'     => strtoupper( (string) $o->get_billing_country() ),
		);
	}

	/**
	 * 报给总后台。cron 调用时传一个订单号；补报时传多个。
	 *
	 * @param int|int[] $ids
	 * @return int|\WP_Error 总后台存下的条数
	 */
	public static function push( $ids ) {
		if ( ! class_exists( Hq_Connector::class ) || ! Hq_Connector::configured() || ! defined( 'PEPTIDE_HQ_URL' ) ) {
			return new \WP_Error( 'pc_cid_hq', 'This store is not connected to the master admin.' );
		}
		$links = array();
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof \WC_Order && ! $o->get_meta( '_pc_test_order' ) ) {
				$l = self::link( $o );
				if ( $l ) {
					$links[] = $l;
				}
			}
		}
		if ( ! $links ) {
			return 0;
		}
		$s     = Hq_Connector::settings();
		$saved = 0;
		foreach ( array_chunk( $links, 200 ) as $chunk ) {
			$time = time();
			$body = (string) wp_json_encode( array( 'links' => $chunk ), JSON_UNESCAPED_SLASHES );
			$res  = wp_remote_post(
				add_query_arg( 'rest_route', rawurlencode( self::ROUTE ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type' => 'application/json',
						'X-PC-HQ-Site' => $s['uuid'],
						'X-PC-HQ-Time' => (string) $time,
						'X-PC-HQ-Sign' => Hq_Connector::sign( $s['key'], 'POST', self::ROUTE, '', $time, $body ),
					),
					'body'    => $body,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return new \WP_Error( 'pc_cid_hq', 'HTTP ' . wp_remote_retrieve_response_code( $res ) . ' ' . ( is_array( $j ) ? (string) ( $j['message'] ?? '' ) : '' ) );
			}
			$saved += (int) ( $j['saved'] ?? 0 );
		}
		return $saved;
	}

	/** 补报：最近 $days 天的订单（只读订单，不改）。 */
	public static function backfill( int $days = 30 ) {
		$ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ) ) );
		return self::push( $ids );
	}
}
