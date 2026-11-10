<?php
/**
 * 投手首页（ST-02）。这里的“Buyer”是投手（media buyer），不是顾客；已购顾客的回访在 PeptideCore\Buyer_Status。
 *
 * 页面只有三块：
 * - 5 个核心数字：访客、订单、销售额、转化率、弃单数，可切换今天 / 昨天 / 近 7 天，每个数字都能点开看解释；
 * - 待办：开店向导没做完、支付域名没登记、还有弃单没跟进；
 * - 向导进度。
 *
 * 数字不在这里算：
 * - 这家店的指标开关（M6，peptide_core_m6_mode）是 on 时，用 F4 的统一入口 PeptideCore\Data\Metrics::preset()；
 * - 开关是 off / shadow，或者 F4 的代码还没合进来时，沿用现有“数据大盘”的口径（Overview::range_data()）和弃单列表的
 *   统计（Recovery_Items::list()）。这样投手在首页和数据页看到的数字任何时候都是同一个口径，而且都是真实数据。
 * 页面先出框架，数字由 GET peptide/v1/admin/buyer-home 取回（每个时间段缓存 60 秒），不拖慢页面打开。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Overview;
use PeptideCore\Recovery_Items;
use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Buyer_Home {

	public const SLUG = Staff_Buyer::PAGE_HOME;

	/** 可以切换的时间段 => 名称。 */
	public const RANGES = array(
		'today'     => '今天',
		'yesterday' => '昨天',
		'7d'        => '近 7 天',
	);

	/** 总后台发现这家店的收银台域名没登记时写入 '1'，登记好后删除（约定见 docs/CONTRACT.md）。 */
	public const PAY_DOMAIN_OPTION = 'pc_pay_domain_missing';

	private const CACHE_SECONDS = 60;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 62 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/** 不出现在原来的菜单里（投手的菜单由 Staff_Buyer 生成）；管理员可以直接打开地址。 */
	public static function add_page(): void {
		add_submenu_page( '', '首页', '首页', Staff_Buyer::CAP_VIEW, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * 5 个数字的名称和解释（大白话）。
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function metrics(): array {
		return array(
			'visitors'   => array( 'label' => '访客', 'help' => '这段时间里打开过店铺的人数。同一个人来多次只算一个。' ),
			'orders'     => array( 'label' => '订单', 'help' => '已经付款成功的订单数，不包括测试单。' ),
			'sales'      => array( 'label' => '销售额', 'help' => '已付款订单的金额合计，已经减去退款。' ),
			'conversion' => array( 'label' => '转化率', 'help' => '每 100 个访客里有几个人付款。' ),
			'abandoned'  => array( 'label' => '弃单', 'help' => '开始结账但没有付款的顾客数。可以到“弃单”里联系他们。' ),
		);
	}

	// ---------- 数字 ----------

	/** 转化率（0–1）；没有访客时为 null。 */
	public static function conversion( int $orders, int $visitors ): ?float {
		return $visitors > 0 ? $orders / $visitors : null;
	}

	/** 用不用 F4 的统一指标入口：代码已经在，而且这家店的指标开关是 on。 */
	public static function modern(): bool {
		$metrics = '\PeptideCore\Data\Metrics';
		$switch  = '\PeptideCore\Data\Metrics_Switch';
		return is_callable( array( $metrics, 'preset' ) ) && is_callable( array( $switch, 'mode' ) ) && 'on' === call_user_func( array( $switch, 'mode' ) );
	}

	/**
	 * 把 Metrics::preset() 的结果整理成首页用的数字（不读数据库，测试里直接调用）。
	 * Metrics 的转化率是百分数（支付成功访客 ÷ 访客，没有访客时为 null），这里换成 0–1。
	 *
	 * @param array<string,mixed> $raw
	 * @return array{visitors:int,orders:int,sales_cents:int,abandoned:int,conversion:?float,source:string}
	 */
	public static function from_metrics( array $raw ): array {
		return array(
			'visitors'    => (int) ( $raw['visitors'] ?? 0 ),
			'orders'      => (int) ( $raw['orders'] ?? 0 ),
			'sales_cents' => (int) ( $raw['sales_cents'] ?? 0 ),
			'abandoned'   => (int) ( $raw['abandoned'] ?? 0 ),
			'conversion'  => isset( $raw['conversion_rate'] ) ? (float) $raw['conversion_rate'] / 100 : null,
			'source'      => 'metrics',
		);
	}

	/**
	 * 某个时间段的原始数字：visitors、orders、sales_cents、abandoned、conversion（0–1 或 null）、source（数字来自哪里）。
	 *
	 * @return array{visitors:int,orders:int,sales_cents:int,abandoned:int,conversion:?float,source:string}
	 */
	public static function numbers( string $range ): array {
		$range = isset( self::RANGES[ $range ] ) ? $range : 'today';
		if ( self::modern() ) {
			return self::from_metrics( (array) call_user_func( array( '\PeptideCore\Data\Metrics', 'preset' ), $range ) );
		}
		// 指标开关没开：沿用数据大盘和弃单列表现在的口径。
		$data          = Overview::range_data( $range );
		[ $from, $to ] = Overview::window( $range );
		$counts        = Recovery_Items::list( array( 'from_ms' => $from * 1000, 'to_ms' => $to * 1000 ), 1, 1 )['counts'];
		$visitors      = (int) ( $data['visitors'] ?? 0 );
		$orders        = (int) ( $data['orders'] ?? 0 );
		return array(
			'visitors'    => $visitors,
			'orders'      => $orders,
			'sales_cents' => (int) round( (float) ( $data['sales'] ?? 0 ) * 100 ),
			'abandoned'   => (int) ( $counts['pending'] ?? 0 ) + (int) ( $counts['recalling'] ?? 0 ) + (int) ( $counts['failed'] ?? 0 ),
			'conversion'  => self::conversion( $orders, $visitors ),
			'source'      => 'overview',
		);
	}

	/** 还没跟进的弃单数：近 7 天待召回和召回中的顾客；日常会话加上“已联系”标记后，标记过的不算。 */
	public static function abandoned_open(): int {
		if ( self::modern() ) {
			return (int) ( call_user_func( array( '\PeptideCore\Data\Metrics', 'preset' ), '7d' )['abandoned_open'] ?? 0 );
		}
		if ( is_callable( array( Recovery_Items::class, 'uncontacted_count' ) ) ) {
			return (int) Recovery_Items::uncontacted_count();
		}
		$open = 0;
		foreach ( Recovery_Items::list( array( 'from_ms' => ( time() - 7 * DAY_IN_SECONDS ) * 1000 ), 1, 400 )['items'] as $item ) {
			if ( in_array( $item['status'] ?? '', array( 'pending', 'recalling' ), true ) && empty( $item['contacted_ms'] ) ) {
				++$open;
			}
		}
		return $open;
	}

	/**
	 * 接口返回的内容：数字已经排好格式，页面直接显示。
	 *
	 * @return array<string,mixed>
	 */
	public static function payload( string $range ): array {
		$range = isset( self::RANGES[ $range ] ) ? $range : 'today';
		$key   = 'pc_buyer_home_' . md5( $range . '|' . wp_date( 'Y-m-d' ) );
		$data  = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$n             = self::numbers( $range );
			$rate          = $n['conversion'];
			[ $from, $to ] = Overview::window( $range );
			$data          = array(
				'range'          => $range,
				'metrics'        => array(
					'visitors'   => number_format_i18n( $n['visitors'] ),
					'orders'     => number_format_i18n( $n['orders'] ),
					'sales'      => html_entity_decode( wp_strip_all_tags( wc_price( $n['sales_cents'] / 100 ) ), ENT_QUOTES, 'UTF-8' ),
					'conversion' => null === $rate ? '—' : number_format_i18n( $rate * 100, 2 ) . '%',
					'abandoned'  => number_format_i18n( $n['abandoned'] ),
				),
				'period'         => wp_date( 'm-d H:i', $from ) . ' – ' . wp_date( 'm-d H:i', $to - 1 ) . '（' . wp_timezone_string() . '）',
				'abandoned_open' => self::abandoned_open(),
				'source'         => $n['source'],
			);
			set_transient( $key, $data, self::CACHE_SECONDS );
		}
		return $data;
	}

	public static function register_routes(): void {
		register_rest_route(
			'peptide/v1',
			'/admin/buyer-home',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => Staff_Buyer::can_use() ? true : new \WP_Error( 'pc_forbidden', '没有权限。', array( 'status' => rest_authorization_required_code() ) ),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$response = new \WP_REST_Response( self::payload( sanitize_key( (string) $request['range'] ) ) );
					$response->header( 'Cache-Control', 'no-store' );
					return $response;
				},
			)
		);
	}

	// ---------- 待办 ----------

	/** 这家店的收银台域名是不是还没登记。 */
	public static function pay_domain_missing(): bool {
		return (bool) apply_filters( 'pc_pay_domain_missing', '1' === (string) get_option( self::PAY_DOMAIN_OPTION, '' ) );
	}

	/**
	 * 待办清单（不读数据库，测试里直接调用）。
	 *
	 * @param array{wizard_remaining:int,wizard_next:string,pay_domain_missing:bool,abandoned_open:?int} $facts
	 *        abandoned_open 为 null = 还没取到（页面先留位置，数字回来后再显示）。
	 * @return array<int,array{key:string,text:string,page:string,level:string}>
	 */
	public static function todos( array $facts ): array {
		$out = array();
		if ( (int) ( $facts['wizard_remaining'] ?? 0 ) > 0 ) {
			$next  = (string) ( $facts['wizard_next'] ?? '' );
			$out[] = array(
				'key'   => 'wizard',
				'text'  => sprintf( '开店向导还差 %d 步', (int) $facts['wizard_remaining'] ) . ( '' !== $next ? '，下一步：' . $next : '' ),
				'page'  => Staff_Buyer::PAGE_WIZARD,
				'level' => 'bad',
			);
		}
		if ( ! empty( $facts['pay_domain_missing'] ) ) {
			$out[] = array(
				'key'   => 'pay_domain',
				'text'  => '支付域名还没登记，顾客可能付不了款。请马上联系平台负责人，处理好之前先不要加大投放。',
				'page'  => '',
				'level' => 'bad',
			);
		}
		$open = $facts['abandoned_open'] ?? null;
		if ( null === $open || (int) $open > 0 ) {
			$out[] = array(
				'key'   => 'abandoned',
				'text'  => null === $open ? '正在查看有没有待跟进的弃单…' : sprintf( '有 %d 个弃单还没跟进', (int) $open ),
				'page'  => 'pc-recovery',
				'level' => 'warn',
			);
		}
		return $out;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! Staff_Buyer::can_view() ) {
			return;
		}
		$status = Onboarding::status( Staff_Buyer::current() ? get_current_user_id() : 0 );
		$labels = Onboarding::labels();

		echo '<div class="wrap pc-by pc-by-home" data-pc-by-home data-range="today"><h1>首页</h1>';
		echo Onboarding::banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- banner() 里已转义。
		Staff_Buyer::render_flash();

		// 5 个核心数字。
		echo '<section class="pc-by-card" aria-labelledby="pc-by-numbers"><div class="pc-by-card__head"><h2 id="pc-by-numbers">' . esc_html( (string) get_bloginfo( 'name' ) ) . ' 的数据</h2><div class="pc-by-tabs" role="group" aria-label="时间段">';
		foreach ( self::RANGES as $range => $name ) {
			echo '<button type="button" class="pc-by-tab' . ( 'today' === $range ? ' is-on' : '' ) . '" data-pc-by-range="' . esc_attr( $range ) . '" aria-pressed="' . ( 'today' === $range ? 'true' : 'false' ) . '">' . esc_html( $name ) . '</button>';
		}
		echo '</div></div><div class="pc-by-metrics" data-pc-by-metrics aria-live="polite">';
		foreach ( self::metrics() as $key => $metric ) {
			echo '<div class="pc-by-metric"><span class="pc-by-metric__label">' . esc_html( $metric['label'] ) . '</span><strong class="pc-by-metric__value" data-pc-by-metric="' . esc_attr( $key ) . '">—</strong><details><summary>这是什么</summary><p>' . esc_html( $metric['help'] ) . '</p></details></div>';
		}
		echo '</div><p class="pc-by-hint" data-pc-by-period>正在读取数据…</p><p class="pc-by-actions"><a class="button" href="' . esc_url( Staff_Buyer::page_url( 'pc-data' ) ) . '">看详细数据</a></p></section>';

		// 待办。
		$todos = self::todos(
			array(
				'wizard_remaining'   => (int) $status['remaining'],
				'wizard_next'        => (string) ( $labels[ $status['current'] ] ?? '' ),
				'pay_domain_missing' => self::pay_domain_missing(),
				'abandoned_open'     => null,
			)
		);
		echo '<section class="pc-by-card" aria-labelledby="pc-by-todo"><h2 id="pc-by-todo">待办</h2><ul class="pc-by-todos" data-pc-by-todos>';
		foreach ( $todos as $todo ) {
			$text = esc_html( $todo['text'] );
			echo '<li class="pc-by-todo is-' . esc_attr( $todo['level'] ) . '" data-pc-by-todo="' . esc_attr( $todo['key'] ) . '">' . ( '' !== $todo['page'] ? '<a href="' . esc_url( Staff_Buyer::page_url( $todo['page'] ) ) . '"><span data-pc-by-todo-text>' . $text . '</span><span class="pc-by-todo__go" aria-hidden="true">去处理 ›</span></a>' : '<span data-pc-by-todo-text>' . $text . '</span>' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul><p class="pc-by-hint" data-pc-by-todo-empty hidden>现在没有待办。</p></section>';

		// 向导进度。
		$total = count( Onboarding::REQUIRED );
		echo '<section class="pc-by-card" aria-labelledby="pc-by-progress"><h2 id="pc-by-progress">向导进度</h2><p class="pc-by-why">' . esc_html( $status['complete'] ? '必填的 ' . $total . ' 步都完成了。' : sprintf( '已完成 %1$d / %2$d 步。', $total - (int) $status['remaining'], $total ) ) . '</p><ul class="pc-by-progress">';
		foreach ( Onboarding::STEPS as $key ) {
			$done = ! empty( $status['steps'][ $key ] );
			echo '<li class="' . ( $done ? 'is-done' : '' ) . '"><span aria-hidden="true">' . ( $done ? '✓' : '○' ) . '</span> ' . esc_html( $labels[ $key ] ) . ( 'link' === $key ? '（可选）' : '' ) . '<span class="screen-reader-text">' . ( $done ? '已完成' : '未完成' ) . '</span></li>';
		}
		echo '</ul><p class="pc-by-actions"><a class="button" href="' . esc_url( Onboarding::url() ) . '">' . esc_html( $status['complete'] ? '查看向导' : '继续设置' ) . '</a></p></section></div>';
	}
}
