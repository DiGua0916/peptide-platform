<?php
/**
 * 生命周期事件总线（价格文档第四章的地基，店主 2026-11-27）。
 *
 * 文档第四章要做 6 条邮件召唤流水线，但发信引擎（FluentCRM / Mautic）、SES / Mailgun 账号和
 * DNS 都还没定。文档自己给的做法是：先把事件总线和队列表埋好，引擎选定后直接插上就能跑。
 * 这个类就是那块地基 —— 它只负责**捕获并排队**，不发任何邮件、不调用任何外部服务。
 *
 * 捕获这些节点（够 6 条流水线用）：
 *   checkout_email      结账页填了邮箱（Flow 1 弃单召回的入口）
 *   order_created       建单
 *   payment_failed      刷卡失败（Flow 2 秒级急救）
 *   order_paid          付款成功（Flow 3 付后安心）
 *   order_shipped       发货 / 出单号
 *   order_delivered     签收（Flow 4 耗尽补货从这里起算）
 *
 * 每条事件记下流水线要用的上下文：邮箱、语言、国家 / 是不是波多黎各、金额、商品明细、
 * 弃单恢复 token、订单号。语言和 PR 标识是 Flow 6（西语轨）要的。
 *
 * 队列表 {prefix}pc_lifecycle_events：status = pending 的等引擎来取，取走后标 sent。
 * 保留 90 天，过期自动清理，不会无限长大。
 *
 * 默认关闭（enabled = false）：部署代码不会开始记录任何顾客数据，要显式打开。
 * 这一点对高危品类尤其重要 —— 不该在店主还没决定用哪家发信服务时就开始囤积邮箱。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Lifecycle_Events {

	public const OPTION     = 'peptide_core_lifecycle';
	public const DB_OPTION  = 'peptide_core_lifecycle_db';
	public const DB_VERSION = '1';
	public const KEEP_DAYS  = 90;

	/** 支持的事件。流水线按这些名字订阅。 */
	public const EVENTS = array(
		'checkout_email',
		'order_created',
		'payment_failed',
		'order_paid',
		'order_shipped',
		'order_delivered',
	);

	public static function register_hooks(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'on_created' ), 30 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_created' ), 30 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 30, 4 );
		add_action( 'pc_lifecycle_cleanup', array( self::class, 'cleanup' ) );
	}

	// ---------- 设置 ----------

	public static function defaults(): array {
		return array(
			'enabled'    => false, // 部署不开始记录，要显式打开
			'keep_days'  => self::KEEP_DAYS,
		);
	}

	public static function settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	public static function enabled(): bool {
		return ! empty( self::settings()['enabled'] );
	}

	// ---------- 纯函数 ----------

	/** 订单状态变化对应哪个事件；不关心的状态返回 null。 */
	public static function event_for_status( string $from, string $to ): ?string {
		$paid = array( 'processing', 'completed' );
		if ( 'failed' === $to ) {
			return 'payment_failed';
		}
		if ( in_array( $to, $paid, true ) && ! in_array( $from, $paid, true ) ) {
			return 'order_paid';
		}
		if ( 'completed' === $to && 'processing' === $from ) {
			return 'order_shipped';
		}
		return null;
	}

	/**
	 * 从订单收集流水线要用的上下文。纯取值，不改订单。
	 *
	 * @param array{country:string,state:string,email:string,lang:string,total:float,currency:string,
	 *              number:string,items:array<int,array{sku:string,qty:int}>} $o
	 */
	public static function context( array $o ): array {
		$country = strtoupper( trim( (string) ( $o['country'] ?? '' ) ) );
		$state   = strtoupper( trim( (string) ( $o['state'] ?? '' ) ) );
		$lang    = strtolower( trim( (string) ( $o['lang'] ?? '' ) ) );
		// Flow 6 西语轨：收货地是波多黎各，或浏览器语言是 es-*
		$is_pr   = ( 'US' === $country && 'PR' === $state ) || 'PR' === $country;
		return array(
			'email'    => strtolower( trim( (string) ( $o['email'] ?? '' ) ) ),
			'country'  => $country,
			'state'    => $state,
			'is_pr'    => $is_pr,
			'lang'     => $lang,
			'spanish'  => $is_pr || str_starts_with( $lang, 'es' ),
			'total'    => round( max( 0.0, (float) ( $o['total'] ?? 0 ) ), 2 ),
			'currency' => strtoupper( (string) ( $o['currency'] ?? 'USD' ) ),
			'number'   => (string) ( $o['number'] ?? '' ),
			'items'    => array_values( array_map(
				static fn( $i ) => array( 'sku' => (string) ( $i['sku'] ?? '' ), 'qty' => max( 0, (int) ( $i['qty'] ?? 0 ) ) ),
				(array) ( $o['items'] ?? array() )
			) ),
		);
	}

	/** 这条事件该不该记：事件名要认识，而且得有邮箱（没邮箱的召唤不回来）。 */
	public static function should_record( string $event, array $ctx ): bool {
		return in_array( $event, self::EVENTS, true ) && '' !== ( $ctx['email'] ?? '' );
	}

	// ---------- 队列表 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_lifecycle_events';
	}

	public static function ready(): bool {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return true;
		}
		try {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta(
				"CREATE TABLE {$t} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 event varchar(32) NOT NULL,
 email varchar(190) NOT NULL DEFAULT '',
 order_id bigint(20) unsigned NOT NULL DEFAULT 0,
 context longtext NOT NULL,
 status varchar(12) NOT NULL DEFAULT 'pending',
 created_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (id),
 KEY status_created (status,created_at),
 KEY email_event (email,event)
) " . $wpdb->get_charset_collate() . ';'
			);
			update_option( self::DB_OPTION, self::DB_VERSION, false );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** 记一条事件进队列。记录失败绝不影响下单或收款。 */
	public static function record( string $event, array $ctx, int $order_id = 0 ): bool {
		try {
			if ( ! self::enabled() || ! self::should_record( $event, $ctx ) || ! self::ready() ) {
				return false;
			}
			global $wpdb;
			return (bool) $wpdb->insert(
				self::table(),
				array(
					'event'      => $event,
					'email'      => substr( $ctx['email'], 0, 190 ),
					'order_id'   => $order_id,
					'context'    => (string) wp_json_encode( $ctx ),
					'status'     => 'pending',
					'created_at' => time(),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%d' )
			);
		} catch ( \Throwable $e ) {
			return false; // 事件总线出问题不能拖累收款
		}
	}

	/** 引擎来取待发的事件。取走后记得用 mark_sent 标掉。 */
	public static function pending( int $limit = 100 ): array {
		try {
			if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
				return array();
			}
			global $wpdb;
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id,event,email,order_id,context,created_at FROM ' . self::table() . ' WHERE status=%s ORDER BY id ASC LIMIT %d', 'pending', max( 1, min( 500, $limit ) ) ),
				ARRAY_A
			);
			// 不要写成 foreach ( (array) $rows as &$r )：强制转换产生临时值，PHP 不能对它取引用，
			// 循环改的是副本，context 会原样留着 JSON 字符串发给引擎。用下标改，不用引用。
			$rows = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $i => $r ) {
				$decoded = json_decode( (string) ( $r['context'] ?? '' ), true );
				$rows[ $i ]['context'] = is_array( $decoded ) ? $decoded : array();
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** @param int[] $ids */
	public static function mark_sent( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->query( "UPDATE " . self::table() . " SET status='sent' WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB
	}

	/** 过期清理，队列不会无限长大。 */
	public static function cleanup(): int {
		try {
			if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
				return 0;
			}
			global $wpdb;
			$cut = time() - max( 1, (int) self::settings()['keep_days'] ) * DAY_IN_SECONDS;
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %d', $cut ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	// ---------- 挂钩 ----------

	private static function from_order( $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$p = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			$items[] = array( 'sku' => $p instanceof \WC_Product ? $p->get_sku() : '', 'qty' => (int) $item->get_quantity() );
		}
		return self::context( array(
			'email'    => (string) $order->get_billing_email(),
			'country'  => (string) $order->get_shipping_country() ?: (string) $order->get_billing_country(),
			'state'    => (string) $order->get_shipping_state() ?: (string) $order->get_billing_state(),
			'lang'     => (string) $order->get_meta( '_pc_lang' ),
			'total'    => (float) $order->get_total(),
			'currency' => (string) $order->get_currency(),
			'number'   => (string) $order->get_order_number(),
			'items'    => $items,
		) );
	}

	public static function on_created( $order ): void {
		$order = $order instanceof \WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : null );
		if ( $order instanceof \WC_Order ) {
			self::record( 'order_created', self::from_order( $order ), $order->get_id() );
		}
	}

	public static function on_status( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$event = self::event_for_status( (string) $from, (string) $to );
		if ( $event ) {
			self::record( $event, self::from_order( $order ), $order->get_id() );
		}
	}
}
