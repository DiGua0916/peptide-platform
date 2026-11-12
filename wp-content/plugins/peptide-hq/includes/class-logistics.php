<?php
/**
 * 物流填单（店主 2026-11-12）：店铺把付款成功的订单报上来，总后台按店主的物流填单表格式出两个表。
 *
 * - 店铺签名请求 POST /peptide-hq/v1/logistics/push（和邮件、物流、客户编号同一套签名，Mail::verify）。
 * - 表里只出“处理中 / 已完成”的订单；退款、取消后店铺再报一次，表里自动去掉。
 * - 备注栏的“妥投”：有物流单号的订单每 3 小时用 17TRACK 查一次，送达后不再查（每个单号注册时扣 1 个 17TRACK 额度，和顾客查物流共用）。
 * - 报关填单的固定栏（运输方式、品名、配货信息、申报价值、海关编码）可以在页面上改。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Logistics {

	private const DB_OPTION  = 'pchq_logistics_db';
	private const DB_VERSION = '1';
	public const OPTION      = 'pchq_logistics';
	public const CRON        = 'pchq_logistics_track';
	public const PAID        = array( 'processing', 'completed' );
	public const TZ          = 'Asia/Shanghai';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_init', array( self::class, 'maybe_install' ) );
		add_action( self::CRON, array( self::class, 'refresh_tracking' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 600, 'hourly', self::CRON );
				}
			}
		);
	}

	public static function defaults(): array {
		return array(
			'method'  => '香港FEDEX-美国双清包税M7',
			'name_en' => 'health products',
			'goods'   => '保健品',
			'value'   => '5',
			'hs'      => '2106909090',
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
		return array_map( 'strval', array_intersect_key( $s, self::defaults() ) );
	}

	public static function save_settings( array $in ): void {
		$out = array();
		foreach ( self::defaults() as $k => $d ) {
			$v         = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
			$out[ $k ] = '' !== $v ? mb_substr( $v, 0, 80 ) : $d;
		}
		update_option( self::OPTION, $out, false );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pchq_logistics';
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
 site_id bigint(20) unsigned NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 order_number varchar(60) NOT NULL DEFAULT '',
 status varchar(30) NOT NULL DEFAULT '',
 paid_at bigint(20) unsigned NOT NULL DEFAULT 0,
 data longtext NOT NULL,
 tracking varchar(255) NOT NULL DEFAULT '',
 track_code varchar(40) NOT NULL DEFAULT '',
 delivered_at bigint(20) unsigned NOT NULL DEFAULT 0,
 track_checked bigint(20) unsigned NOT NULL DEFAULT 0,
 updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (site_id,order_id),
 KEY paid_at (paid_at)
) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	public static function routes(): void {
		register_rest_route(
			Register::NS,
			'/logistics/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_push' ),
				'permission_callback' => array( Mail::class, 'verify' ),
			)
		);
	}

	private static function clean( $v, int $max = 200 ): string {
		return mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max );
	}

	public static function rest_push( \WP_REST_Request $r ) {
		self::maybe_install();
		if ( ! self::installed() ) {
			return new \WP_Error( 'pchq_logistics_db', 'Logistics table is not ready.', array( 'status' => 503 ) );
		}
		$site = (int) $r->get_param( '_pchq_site' );
		$json = json_decode( (string) $r->get_body(), true );
		$rows = is_array( $json['rows'] ?? null ) ? array_slice( $json['rows'], 0, 100 ) : array();
		global $wpdb;
		$t = self::table();
		$n = 0;
		foreach ( $rows as $row ) {
			$order = (int) ( $row['order_id'] ?? 0 );
			$paid  = (int) ( $row['paid'] ?? 0 );
			if ( $order <= 0 || $paid <= 0 || ! is_array( $row ) ) {
				continue;
			}
			$items = array();
			foreach ( array_slice( (array) ( $row['items'] ?? array() ), 0, 60 ) as $it ) {
				$qty = (int) ( $it['qty'] ?? 0 );
				if ( $qty > 0 ) {
					$items[] = array( 'sku' => self::clean( $it['sku'] ?? '', 80 ), 'name' => self::clean( $it['name'] ?? '', 160 ), 'qty' => $qty );
				}
			}
			$data = array( 'items' => $items );
			foreach ( array( 'name' => 120, 'company' => 120, 'address' => 255, 'city' => 100, 'state' => 40, 'postcode' => 20, 'country' => 4, 'phone' => 40, 'email' => 120 ) as $k => $max ) {
				$data[ $k ] = self::clean( $row[ $k ] ?? '', $max );
			}
			$data['email'] = sanitize_email( $data['email'] );
			$tracking      = array();
			foreach ( array_slice( (array) ( $row['tracking'] ?? array() ), 0, 5 ) as $tn ) {
				$tn = preg_replace( '/[^0-9A-Za-z-]/', '', (string) $tn );
				if ( '' !== $tn ) {
					$tracking[] = $tn;
				}
			}
			$tracking = implode( ' ', $tracking );
			$old      = $wpdb->get_row( $wpdb->prepare( "SELECT tracking FROM {$t} WHERE site_id = %d AND order_id = %d", $site, $order ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$fields   = array(
				'site_id'      => $site,
				'order_id'     => $order,
				'order_number' => self::clean( $row['number'] ?? (string) $order, 60 ),
				'status'       => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'paid_at'      => $paid,
				'data'         => (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'tracking'     => $tracking,
				'updated_at'   => time(),
			);
			if ( $old && (string) $old['tracking'] === $tracking ) {
				$ok = $wpdb->update( $t, $fields, array( 'site_id' => $site, 'order_id' => $order ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				// 新订单或物流单号变了：重新查物流状态。
				$fields += array( 'track_code' => '', 'delivered_at' => 0, 'track_checked' => 0 );
				$ok      = $wpdb->replace( $t, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( false !== $ok ) {
				++$n;
			}
		}
		return rest_ensure_response( array( 'saved' => $n ) );
	}

	/** 每小时：有单号、还没妥投、3 小时内没查过的订单，用 17TRACK 查一次（每次最多 40 单）。 */
	public static function refresh_tracking( int $limit = 40 ): int {
		if ( ! self::installed() || ! Track17::configured() ) {
			return 0;
		}
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, order_id, tracking FROM {$t} WHERE tracking <> '' AND delivered_at = 0 AND status IN ('processing','completed') AND paid_at > %d AND track_checked < %d ORDER BY track_checked ASC LIMIT %d", time() - 90 * DAY_IN_SECONDS, time() - 3 * HOUR_IN_SECONDS, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n    = 0;
		foreach ( (array) $rows as $row ) {
			$first = explode( ' ', (string) $row['tracking'] )[0];
			$res   = Track17::track( $first );
			$set   = array( 'track_checked' => time() );
			if ( is_array( $res ) ) {
				$set['track_code']   = mb_substr( (string) ( $res['code'] ?? '' ), 0, 40 );
				$set['delivered_at'] = ! empty( $res['delivered'] ) ? max( 1, (int) strtotime( (string) $res['delivered'] ) ) : 0;
			}
			$wpdb->update( $t, $set, array( 'site_id' => (int) $row['site_id'], 'order_id' => (int) $row['order_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			++$n;
		}
		return $n;
	}

	/**
	 * 某个时间段付款成功的订单（按付款时间从早到晚）。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $from, int $to, int $site = 0 ): array {
		if ( ! self::installed() ) {
			return array();
		}
		global $wpdb;
		$t   = self::table();
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('processing','completed') AND paid_at >= %d AND paid_at < %d", $from, $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $site > 0 ) {
			$sql .= $wpdb->prepare( ' AND site_id = %d', $site );
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql . ' ORDER BY paid_at ASC, site_id ASC, order_id ASC LIMIT 3000', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$d          = json_decode( (string) $r['data'], true );
			$r['data']  = is_array( $d ) ? $d : array();
			$out[]      = $r;
		}
		return $out;
	}

	// ---------- 表格内容 ----------

	private const TERRITORIES = array(
		'PR' => '波多黎各',
		'VI' => '美属维尔京群岛',
		'GU' => '关岛',
		'AS' => '美属萨摩亚',
		'MP' => '北马里亚纳群岛',
	);

	private const COUNTRIES = array(
		'US' => '美国',
		'CA' => '加拿大',
		'MX' => '墨西哥',
		'GB' => '英国',
		'AU' => '澳大利亚',
		'DE' => '德国',
		'FR' => '法国',
	);

	public static function country_cn( array $d ): string {
		$cc = strtoupper( (string) ( $d['country'] ?? '' ) );
		$st = strtoupper( (string) ( $d['state'] ?? '' ) );
		if ( isset( self::TERRITORIES[ $cc ] ) ) {
			return self::TERRITORIES[ $cc ];
		}
		if ( 'US' === $cc && isset( self::TERRITORIES[ $st ] ) ) {
			return self::TERRITORIES[ $st ];
		}
		return self::COUNTRIES[ $cc ] ?? $cc;
	}

	/** 州简称：美国属地填属地代码（PR）。 */
	public static function state( array $d ): string {
		$cc = strtoupper( (string) ( $d['country'] ?? '' ) );
		$st = strtoupper( (string) ( $d['state'] ?? '' ) );
		return '' === $st && isset( self::TERRITORIES[ $cc ] ) ? $cc : $st;
	}

	/** 电话：美国号码写成 “+1 7876694764”。 */
	public static function phone( array $d ): string {
		$raw    = trim( (string) ( $d['phone'] ?? '' ) );
		$digits = preg_replace( '/\D/', '', $raw );
		$cc     = strtoupper( (string) ( $d['country'] ?? '' ) );
		$us     = 'US' === $cc || isset( self::TERRITORIES[ $cc ] );
		if ( $us && 10 === strlen( $digits ) ) {
			return '+1 ' . $digits;
		}
		if ( $us && 11 === strlen( $digits ) && '1' === $digits[0] ) {
			return '+1 ' . substr( $digits, 1 );
		}
		return $raw;
	}

	public static function products( array $d ): string {
		$lines = array();
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			$lines[] = self::code( $it ) . '*' . (int) $it['qty'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * 产品简码：用商品编号（SKU，如 TR30、LC216）。导入时留下的长数字编号看不懂，改用“商品名 + 规格”（如 Retatrutide 30mg）。
	 */
	public static function code( array $it ): string {
		$sku = trim( (string) ( $it['sku'] ?? '' ) );
		if ( '' !== $sku && preg_match( '/[A-Za-z]/', $sku ) && ! preg_match( '/\d{9,}/', $sku ) && strlen( $sku ) <= 24 ) {
			return $sku;
		}
		$name = (string) ( $it['name'] ?? $sku );
		$name = preg_replace( '/\s*\*\s*\d+\s*vials?\b/i', '', $name ); // 去掉“*10vials”。
		$name = preg_replace( '/\s+-\s+/', ' ', (string) $name );
		return trim( (string) $name );
	}

	public static function quantity( array $d ): int {
		$n = 0;
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			$n += (int) $it['qty'];
		}
		return $n;
	}

	public static function day( int $ts ): string {
		$dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( self::TZ ) );
		return (int) $dt->format( 'n' ) . '月' . (int) $dt->format( 'j' ) . '日';
	}

	public static function remark( array $r ): string {
		if ( (int) $r['delivered_at'] > 0 || 'Delivered' === $r['track_code'] ) {
			return '妥投';
		}
		$map = array(
			'InfoReceived'       => '已建单',
			'InTransit'          => '运输中',
			'OutForDelivery'     => '派送中',
			'AvailableForPickup' => '待自取',
			'DeliveryFailure'    => '派送失败',
			'Exception'          => '异常',
			'Expired'            => '已过期',
		);
		return $map[ (string) $r['track_code'] ] ?? '';
	}

	/** 第一个表：发货登记。 */
	public static function sheet_ship( array $rows ): array {
		$out = array( array( '序号', '日期', '客户单号', '客户姓名', '产品', '物流单号', '备注' ) );
		$i   = 0;
		foreach ( $rows as $r ) {
			$d     = $r['data'];
			$out[] = array( ++$i, self::day( (int) $r['paid_at'] ), (string) $r['order_number'], (string) ( $d['name'] ?? '' ), self::products( $d ), str_replace( ' ', "\n", (string) $r['tracking'] ), self::remark( $r ) );
		}
		return $out;
	}

	/** 第二个表：报关填单。 */
	public static function sheet_customs( array $rows ): array {
		$s   = self::settings();
		$out = array( array( '序号', '日期', '客户单号', '转单号', '运输方式', '目的国家', '收件人公司', '收件人姓名', '州,省', '城市', '联系地址', '收件人电话', '收件人邮箱', '收件人邮编', '重量', '海关报关品名1', '配货信息1', '申报价值1', '申报品数量1', '海关编码', '配货备注1' ) );
		$i   = 0;
		foreach ( $rows as $r ) {
			$d     = $r['data'];
			$out[] = array( ++$i, self::day( (int) $r['paid_at'] ), (string) $r['order_number'], '', $s['method'], self::country_cn( $d ), (string) ( $d['company'] ?? '' ), (string) ( $d['name'] ?? '' ), self::state( $d ), (string) ( $d['city'] ?? '' ), (string) ( $d['address'] ?? '' ), self::phone( $d ), (string) ( $d['email'] ?? '' ), (string) ( $d['postcode'] ?? '' ), '', $s['name_en'], $s['goods'], $s['value'], self::quantity( $d ), $s['hs'], '' );
		}
		return $out;
	}
}
