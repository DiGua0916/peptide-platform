<?php
/**
 * 订单摘要上报与退款标记的店铺端（HQ-13、OR-03）。
 *
 * - 订单付款、状态变化时，把订单摘要异步报给总后台 POST /peptide-hq/v1/orders/report（每批最多 50 条）。
 *   签名和邮件中心相同：店铺连接密钥 + X-PC-HQ-Site。总后台按（店铺，订单号）覆盖写入，重复上报结果不变。
 * - 订单元 _pc_hq_reported 记最近一次上报成功的内容指纹和时间；每小时把近 48 小时里内容变了但没报成功的订单补报一次。
 * - 只报汇总需要的字段：状态、金额、时间、所属系列、邮箱的哈希、来源；不报姓名、邮箱、电话、地址。
 * - 没付过款、也从没上报过的订单（未付款、草稿、失败）不上报。
 * - 历史订单回填：wp peptide orders backfill [--dry-run] [--since=YYYY-MM-DD]。先试运行看报告，再正式运行；可重复执行。
 * - 退款标记：总后台调用 POST /peptide/v1/hq/order-refund，这里只写 _pc_refund_cents、_pc_refund_state 和一条订单备注。
 *   不创建 WooCommerce 退款对象（那会触发网关退款），也不改订单状态。
 * - GET /peptide/v1/hq/orders/digest：已付款订单的笔数和金额，给总后台核对“总后台合计 = 各店合计”。
 *
 * 字段格式见 docs/CONTRACT.md “M10 订单汇总、报表与退款”。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Order_Report {

	public const ROUTE        = '/peptide-hq/v1/orders/report';
	public const HOOK         = 'pc_hq_order_report';
	public const RECONCILE    = 'pc_hq_order_reconcile';
	public const META         = '_pc_hq_reported';
	public const REFUND_CENTS = '_pc_refund_cents';
	public const REFUND_STATE = '_pc_refund_state';

	/** 每批最多几条。 */
	public const BATCH = 50;

	/** 补报看最近多少秒内改动过的订单（48 小时）。 */
	public const WINDOW = 172800;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_change' ), 50, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_change' ), 50, 1 );
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		add_action( self::RECONCILE, array( self::class, 'reconcile' ) );
		add_action( 'init', array( self::class, 'schedule' ), 40 );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'render_refund_state' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide orders backfill', array( self::class, 'cli_backfill' ) );
		}
	}

	/** 店铺是否已经接入总后台（有连接密钥和店铺编号）。 */
	public static function enabled(): bool {
		if ( ! Hq_Connector::configured() ) {
			return false;
		}
		$s = Hq_Connector::settings();
		return 64 === strlen( $s['key'] ) && '' !== $s['uuid'];
	}

	public static function schedule(): void {
		if ( self::enabled() && ! wp_next_scheduled( self::RECONCILE ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::RECONCILE );
		}
	}

	/** 向总后台声明本店支持订单上报和退款标记（功能键 => 版本号）。 */
	public static function capabilities( $caps ): array {
		$caps = is_array( $caps ) ? $caps : array();
		return $caps + array(
			'orders.report' => '1',
			'orders.refund' => '1',
		);
	}

	// ---------- 纯函数 ----------

	/** 金额转美分（四舍五入，避免 19.99 * 100 = 1998.9999）。 */
	public static function cents( $amount ): int {
		return (int) round( (float) $amount * 100 );
	}

	/** 摘要的内容指纹：内容不变指纹不变，用来判断要不要再报。 */
	public static function fingerprint( array $summary ): string {
		ksort( $summary );
		return md5( (string) json_encode( $summary ) );
	}

	/** 订单元 _pc_hq_reported（“指纹|时间”）拆开。 */
	public static function parse_reported( string $meta ): array {
		$parts = explode( '|', $meta );
		return array(
			'fingerprint' => preg_match( '/^[a-f0-9]{32}$/D', $parts[0] ) ? $parts[0] : '',
			'at'          => (int) ( $parts[1] ?? 0 ),
		);
	}

	/** 这个订单该不该上报：现在是已付款状态、曾经付过款，或者以前报过（之后状态变了也要让总后台知道）。 */
	public static function reportable( bool $paid, int $paid_at, bool $was_reported ): bool {
		return $paid || $paid_at > 0 || $was_reported;
	}

	/** 退款状态：none / partial / full。 */
	public static function refund_state( int $total_cents, int $refunded_cents ): string {
		if ( $refunded_cents <= 0 ) {
			return 'none';
		}
		return $refunded_cents >= $total_cents ? 'full' : 'partial';
	}

	/**
	 * 校验总后台发来的退款标记。金额必须在 0 和订单金额之间；状态按本店的订单金额重新算。无效时返回 null。
	 *
	 * @return array{refunded_cents:int,state:string,note:string}|null
	 */
	public static function clean_refund( array $p, int $total_cents ): ?array {
		$cents = $p['refunded_cents'] ?? null;
		if ( ! is_int( $cents ) && ! ( is_string( $cents ) && preg_match( '/^\d{1,12}$/D', $cents ) ) ) {
			return null;
		}
		$cents = (int) $cents;
		if ( $cents < 0 || $cents > $total_cents ) {
			return null;
		}
		$note = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $p['note'] ?? '' ) ) ) );
		return array(
			'refunded_cents' => $cents,
			'state'          => self::refund_state( $total_cents, $cents ),
			'note'           => function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 255 ) : substr( $note, 0, 255 ),
		);
	}

	/** 从落地时的查询串里取广告编号：先 ad_id，没有就用 utm_content。 */
	public static function ad_id_from_query( string $query ): string {
		$args = array();
		parse_str( ltrim( $query, '?' ), $args );
		foreach ( array( 'ad_id', 'utm_content' ) as $key ) {
			$v = isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? trim( $args[ $key ] ) : '';
			if ( '' !== $v ) {
				return substr( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $v ), 0, 64 );
			}
		}
		return '';
	}

	// ---------- 订单摘要 ----------

	/**
	 * 订单的口径数据。指标模块的 Data\Metrics::order_fact 可用时以它为准（paid = 收到过支付成功通知，
	 * test = 测试单，gross_cents = 订单金额），否则用同口径的本地实现。
	 *
	 * @return array{paid:bool,is_test:bool,total_cents:int,paid_at:int}
	 */
	public static function facts( \WC_Order $order ): array {
		if ( is_callable( array( '\PeptideCore\Data\Metrics', 'order_fact' ) ) ) {
			$f = \PeptideCore\Data\Metrics::order_fact( $order );
			if ( is_array( $f ) && isset( $f['paid'], $f['test'], $f['gross_cents'], $f['paid_at'] ) ) {
				return array(
					'paid'        => (bool) $f['paid'],
					'is_test'     => (bool) $f['test'],
					'total_cents' => (int) $f['gross_cents'],
					'paid_at'     => (int) $f['paid_at'],
				);
			}
		}
		$paid      = in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
		$paid_date = $order->get_date_paid();
		$created   = $order->get_date_created();
		return array(
			'paid'        => $paid,
			'is_test'     => Test_Orders::is_test_order( $order ) || Overview::is_test_order( $order ),
			'total_cents' => self::cents( $order->get_total() ),
			'paid_at'     => $paid_date ? $paid_date->getTimestamp() : ( $paid && $created ? $created->getTimestamp() : 0 ),
		);
	}

	/** 订单的来源（下单那次访问的归因快照）。没有追踪数据时为空。 */
	private static function attribution( \WC_Order $order ): array {
		$out   = array( 'utm_source' => '', 'ad_id' => '' );
		$visit = (int) $order->get_meta( '_pc_track_visit' );
		if ( $visit < 1 || ! class_exists( Tracking\Schema::class ) || ! Tracking\Schema::installed() ) {
			return $out;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT utm_source, attribution_query FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE id = %d', $visit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( is_array( $row ) ) {
			$out['utm_source'] = mb_substr( sanitize_text_field( (string) ( $row['utm_source'] ?? '' ) ), 0, 120 );
			$out['ad_id']      = self::ad_id_from_query( (string) ( $row['attribution_query'] ?? '' ) );
		}
		return $out;
	}

	/** 报给总后台的订单摘要。 */
	public static function summary( \WC_Order $order ): array {
		$facts   = self::facts( $order );
		$created = $order->get_date_created();
		$email   = strtolower( trim( (string) $order->get_billing_email() ) );
		$attr    = self::attribution( $order );
		$series  = (int) $order->get_meta( Series_Sync::ORDER_META );
		return array(
			'store_order_id' => (int) $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'status'         => (string) $order->get_status(),
			'paid'           => $facts['paid'],
			'is_test'        => $facts['is_test'],
			'currency'       => (string) $order->get_currency(),
			'total_cents'    => $facts['total_cents'],
			'created_at'     => $created ? $created->getTimestamp() : 0,
			'paid_at'        => $facts['paid_at'],
			'order_day'      => $created ? wp_date( 'Y-m-d', $created->getTimestamp() ) : '',
			'series_id'      => $series > 0 ? $series : Series_Sync::series_id(),
			'email_hash'     => '' !== $email ? hash( 'sha256', $email ) : '',
			'utm_source'     => $attr['utm_source'],
			'ad_id'          => $attr['ad_id'],
		);
	}

	private static function reported( \WC_Order $order ): array {
		return self::parse_reported( (string) $order->get_meta( self::META ) );
	}

	// ---------- 上报 ----------

	/** 订单付款或状态变化：值得上报的排一个异步任务。 */
	public static function on_change( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || ! self::enabled() ) {
			return;
		}
		$facts = self::facts( $order );
		if ( self::reportable( $facts['paid'], $facts['paid_at'], '' !== self::reported( $order )['fingerprint'] ) ) {
			self::enqueue( array( $order->get_id() ) );
		}
	}

	public static function enqueue( array $ids ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $ids ), 'peptide-core' );
		} else {
			wp_schedule_single_event( time() + 5, self::HOOK, array( $ids ) );
		}
	}

	/** 异步任务入口。 */
	public static function run( $ids ): void {
		self::report( array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) ) );
	}

	/** 每小时：把近 48 小时改动过的订单过一遍，内容变了但没报成功的补报。 */
	public static function reconcile(): array {
		if ( ! self::enabled() ) {
			return array( 'sent' => 0, 'accepted' => 0, 'skipped' => 0, 'error' => 'not_connected' );
		}
		$ids = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'limit'         => 500,
				'return'        => 'ids',
				'date_modified' => '>' . ( time() - self::WINDOW ),
				'orderby'       => 'modified',
				'order'         => 'DESC',
			)
		);
		return self::report( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * 上报一批订单。内容没变的（指纹相同）和不该上报的跳过。
	 *
	 * @param int[] $ids
	 * @return array{sent:int,accepted:int,skipped:int,error:string}
	 */
	public static function report( array $ids ): array {
		$out = array( 'sent' => 0, 'accepted' => 0, 'skipped' => 0, 'error' => '' );
		if ( ! self::enabled() ) {
			$out['error'] = 'not_connected';
			return $out;
		}
		$batch  = array();
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
				++$out['skipped'];
				continue;
			}
			$summary = self::summary( $order );
			$known   = self::reported( $order )['fingerprint'];
			if ( ! self::reportable( $summary['paid'], $summary['paid_at'], '' !== $known ) || self::fingerprint( $summary ) === $known ) {
				++$out['skipped'];
				continue;
			}
			$batch[]                    = $summary;
			$orders[ $order->get_id() ] = $order;
		}
		foreach ( array_chunk( $batch, self::BATCH ) as $chunk ) {
			$out['sent'] += count( $chunk );
			$res          = self::post( $chunk );
			if ( is_wp_error( $res ) ) {
				$out['error'] = $res->get_error_message();
				continue;
			}
			$accepted = array_map( 'intval', (array) ( $res['accepted'] ?? array() ) );
			foreach ( $chunk as $summary ) {
				if ( ! in_array( $summary['store_order_id'], $accepted, true ) ) {
					continue;
				}
				$order = $orders[ $summary['store_order_id'] ];
				$order->update_meta_data( self::META, self::fingerprint( $summary ) . '|' . time() );
				$order->save_meta_data();
				++$out['accepted'];
			}
		}
		return $out;
	}

	/**
	 * 把一批摘要发给总后台。
	 *
	 * @return array|\WP_Error 总后台的回应
	 */
	private static function post( array $orders ) {
		// nonce 让同一秒内两批内容相同的请求签名也不同（总后台拒绝重复的签名）。
		$body = (string) wp_json_encode( array( 'nonce' => wp_generate_uuid4(), 'orders' => array_values( $orders ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$s    = Hq_Connector::settings();
		$time = time();
		$res  = wp_remote_post(
			add_query_arg( 'rest_route', rawurlencode( self::ROUTE ), trailingslashit( (string) PEPTIDE_HQ_URL ) ),
			array(
				'timeout' => 20,
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
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			return new \WP_Error( 'pc_hq_orders', 'HTTP ' . $code . ( is_array( $json ) && is_string( $json['message'] ?? null ) ? ' ' . $json['message'] : '' ) );
		}
		return $json;
	}

	// ---------- 历史订单回填 ----------

	/**
	 * 回填历史已付款订单。$dry 为 true 时只统计，不改订单、不上报。
	 *
	 * @param int $since 只处理这个时间（UTC 秒）之后创建的订单，0 = 全部
	 * @return array 报告
	 */
	public static function backfill( bool $dry, int $since = 0 ): array {
		$report = array(
			'dry_run'          => $dry,
			'series_id'        => Series_Sync::series_id(),
			'orders'           => 0,
			'total_cents'      => 0,
			'test_orders'      => 0,
			'missing_series'   => 0,
			'stamped'          => 0,
			'already_reported' => 0,
			'to_report'        => 0,
			'sent'             => 0,
			'accepted'         => 0,
			'months'           => array(),
			'errors'           => array(),
		);
		$page = 1;
		do {
			$args = array(
				'type'    => 'shop_order',
				'status'  => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
				'limit'   => 100,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			);
			if ( $since > 0 ) {
				$args['date_created'] = '>=' . $since;
			}
			$orders = (array) wc_get_orders( $args );
			$ids    = array();
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$missing = '' === (string) $order->get_meta( Series_Sync::ORDER_META );
				if ( $missing ) {
					++$report['missing_series'];
					if ( ! $dry && $report['series_id'] > 0 ) {
						$order->update_meta_data( Series_Sync::ORDER_META, (string) $report['series_id'] );
						$order->save_meta_data();
						++$report['stamped'];
					}
				}
				$summary = self::summary( $order );
				++$report['orders'];
				if ( $summary['is_test'] ) {
					++$report['test_orders'];
				} else {
					$report['total_cents'] += $summary['total_cents'];
					$month                  = substr( $summary['order_day'], 0, 7 );
					$report['months'][ $month ]['orders']      = ( $report['months'][ $month ]['orders'] ?? 0 ) + 1;
					$report['months'][ $month ]['total_cents'] = ( $report['months'][ $month ]['total_cents'] ?? 0 ) + $summary['total_cents'];
				}
				if ( self::fingerprint( $summary ) === self::reported( $order )['fingerprint'] ) {
					++$report['already_reported'];
				} else {
					++$report['to_report'];
					$ids[] = (int) $order->get_id();
				}
			}
			if ( ! $dry && $ids ) {
				$r                   = self::report( $ids );
				$report['sent']     += $r['sent'];
				$report['accepted'] += $r['accepted'];
				if ( '' !== $r['error'] ) {
					$report['errors'][] = $r['error'];
				}
			}
			++$page;
		} while ( 100 === count( $orders ) );
		ksort( $report['months'] );
		$report['errors'] = array_values( array_unique( $report['errors'] ) );
		return $report;
	}

	/**
	 * 把历史已付款订单回填到总后台。
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : 只出报告，不改订单、不上报。先用它看一遍数字。
	 *
	 * [--since=<date>]
	 * : 只处理这一天之后创建的订单，格式 YYYY-MM-DD（店铺时区）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide orders backfill --dry-run
	 *     wp peptide orders backfill
	 */
	public static function cli_backfill( $args, $assoc ): void {
		$dry   = ! empty( $assoc['dry-run'] );
		$since = 0;
		if ( ! empty( $assoc['since'] ) ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) $assoc['since'] ) ) {
				\WP_CLI::error( '--since 的格式是 YYYY-MM-DD。' );
			}
			$since = ( new \DateTimeImmutable( (string) $assoc['since'] . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		}
		if ( ! $dry && ! self::enabled() ) {
			\WP_CLI::error( '这家店还没有接入总后台，不能上报。可以先用 --dry-run 看报告。' );
		}
		$r = self::backfill( $dry, $since );
		\WP_CLI::log( sprintf( '所属系列：%s', $r['series_id'] > 0 ? (string) $r['series_id'] : '（还没收到总后台的系列信息）' ) );
		\WP_CLI::log( sprintf( '已付款订单：%d 笔（其中测试单 %d 笔，不计入金额）', $r['orders'], $r['test_orders'] ) );
		\WP_CLI::log( sprintf( '金额合计（不含测试单）：%s', number_format( $r['total_cents'] / 100, 2 ) ) );
		foreach ( $r['months'] as $month => $m ) {
			\WP_CLI::log( sprintf( '  %s：%d 笔，%s', $month, $m['orders'], number_format( $m['total_cents'] / 100, 2 ) ) );
		}
		\WP_CLI::log( sprintf( '缺所属系列的订单：%d 笔%s', $r['missing_series'], $dry ? '' : sprintf( '，已补 %d 笔', $r['stamped'] ) ) );
		\WP_CLI::log( sprintf( '已上报且内容没变：%d 笔；需要上报：%d 笔', $r['already_reported'], $r['to_report'] ) );
		if ( $dry ) {
			\WP_CLI::success( '试运行完成：没有改任何订单，也没有上报。确认数字后去掉 --dry-run 正式运行。' );
			return;
		}
		\WP_CLI::log( sprintf( '本次上报：发出 %d 笔，总后台接收 %d 笔', $r['sent'], $r['accepted'] ) );
		if ( $r['errors'] || $r['accepted'] < $r['sent'] ) {
			\WP_CLI::warning( '有订单没报成功' . ( $r['errors'] ? '：' . implode( '；', $r['errors'] ) : '' ) . '。可以直接重新运行，已报成功的不会重复。' );
			return;
		}
		\WP_CLI::success( '回填完成。重复运行不会产生重复数据。' );
	}

	// ---------- 总后台调用的接口 ----------

	public static function routes(): void {
		$auth = array( Hq_Connector::class, 'verify' );
		register_rest_route( Hq_Connector::NS, '/hq/order-refund', array( 'methods' => 'POST', 'callback' => array( self::class, 'refund_route' ), 'permission_callback' => $auth ) );
		register_rest_route( Hq_Connector::NS, '/hq/orders/digest', array( 'methods' => 'GET', 'callback' => array( self::class, 'digest_route' ), 'permission_callback' => $auth ) );
	}

	/**
	 * 总后台回写退款标记：只写两个订单元和一条备注。内容没变时不重复加备注。
	 *
	 * @return array|\WP_Error
	 */
	public static function refund_route( \WP_REST_Request $request ) {
		$p     = (array) $request->get_json_params();
		$order = wc_get_order( (int) ( $p['order_id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'pc_refund_order', 'Order not found.', array( 'status' => 404 ) );
		}
		$total = self::cents( $order->get_total() );
		$clean = self::clean_refund( $p, $total );
		if ( null === $clean ) {
			return new \WP_Error( 'pc_refund_amount', 'The refund amount must be between 0 and the order total.', array( 'status' => 400 ) );
		}
		$before_cents = max( 0, (int) $order->get_meta( self::REFUND_CENTS ) );
		$before_state = (string) $order->get_meta( self::REFUND_STATE );
		$before_state = '' !== $before_state ? $before_state : 'none';
		$changed      = $before_cents !== $clean['refunded_cents'] || $before_state !== $clean['state'];
		if ( $changed ) {
			$order->update_meta_data( self::REFUND_CENTS, (string) $clean['refunded_cents'] );
			$order->update_meta_data( self::REFUND_STATE, $clean['state'] );
			$order->save_meta_data();
			$order->add_order_note( self::refund_note( $before_cents, $clean, (string) $order->get_currency() ), 0, false );
			do_action( 'pc_order_refund_marked', $order->get_id(), $clean['refunded_cents'], $clean['state'] );
		}
		return array(
			'order_id'       => (int) $order->get_id(),
			'refunded_cents' => $clean['refunded_cents'],
			'state'          => $clean['state'],
			'changed'        => $changed,
		);
	}

	/** 订单备注的文字。 */
	public static function refund_note( int $before_cents, array $clean, string $currency ): string {
		$money = static fn( int $cents ): string => ( 'USD' === $currency || '' === $currency ? '$' : $currency . ' ' ) . number_format( $cents / 100, 2 );
		$label = array( 'none' => '未退款', 'partial' => '部分退款', 'full' => '已全额退款' );
		if ( 0 === $clean['refunded_cents'] ) {
			$text = sprintf( '总后台撤销了退款标记（原已标记 %s），订单恢复为未退款。', $money( $before_cents ) );
		} else {
			$text = sprintf( '总后台标记退款：已退款合计 %1$s（%2$s）。这只是记录，系统没有向支付通道发起退款。', $money( $clean['refunded_cents'] ), $label[ $clean['state'] ] );
		}
		return '' !== $clean['note'] ? $text . ' 备注：' . $clean['note'] : $text;
	}

	/** 订单详情里显示退款标记（投手只能看，不能改）。 */
	public static function render_refund_state( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$state = (string) $order->get_meta( self::REFUND_STATE );
		$cents = max( 0, (int) $order->get_meta( self::REFUND_CENTS ) );
		if ( ! in_array( $state, array( 'partial', 'full' ), true ) || $cents < 1 ) {
			return;
		}
		printf(
			'<p class="form-field form-field-wide pc-refund-state"><strong>%1$s</strong> %2$s</p>',
			esc_html( 'full' === $state ? '已全额退款' : '部分退款' ),
			esc_html( sprintf( '已标记退款 %s（由总后台标记，店铺不能修改）', number_format( $cents / 100, 2 ) ) )
		);
	}

	public static function digest_route( \WP_REST_Request $request ): array {
		return self::digest( max( 0, (int) $request->get_param( 'from' ) ), max( 0, (int) $request->get_param( 'to' ) ) );
	}

	/**
	 * 已付款订单的笔数和金额（不含测试单），按付款时间筛选；$to 为 0 表示不设上限。
	 *
	 * @return array{count:int,total_cents:int,refunded_cents:int,test_count:int,currency:string}
	 */
	public static function digest( int $from = 0, int $to = 0 ): array {
		$out  = array( 'count' => 0, 'total_cents' => 0, 'refunded_cents' => 0, 'test_count' => 0, 'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
		$page = 1;
		do {
			$orders = (array) wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
					'limit'   => 200,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$facts = self::facts( $order );
				if ( ! $facts['paid'] || $facts['paid_at'] < $from || ( $to > 0 && $facts['paid_at'] > $to ) ) {
					continue;
				}
				if ( $facts['is_test'] ) {
					++$out['test_count'];
					continue;
				}
				++$out['count'];
				$out['total_cents']    += $facts['total_cents'];
				$out['refunded_cents'] += max( 0, (int) $order->get_meta( self::REFUND_CENTS ) );
			}
			++$page;
		} while ( 200 === count( $orders ) );
		return $out;
	}
}
