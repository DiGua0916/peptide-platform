<?php
/**
 * 总后台 → 订单与退款（HQ-13、OR-03）。
 *
 * - 列出各店报上来的订单，可按系列、店铺、状态、订单号、付款日期筛选。
 * - 订单详情里“标记退款”：金额、日期、备注；下方是这个订单的全部退款记录，可以撤销（记录保留）。
 * - “核对”：把总后台的订单合计和店铺自己的合计比一遍。
 * - 所有页面和操作只给总后台管理员（manage_options），写操作校验 nonce。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Orders;
use PeptideHq\Refunds;
use PeptideHq\Series;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Orders_Page {

	public const SLUG    = 'pchq-orders';
	private const ACTIONS = array( 'pchq_refund_mark', 'pchq_refund_void', 'pchq_orders_verify', 'pchq_refund_retry' );

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '订单与退款', '订单与退款', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			21
		);
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 表单上的 data-confirm 确认框由它处理。
		wp_add_inline_style( 'pchq-plugins', self::css() );
	}

	/** 订单页和报表页共用的一点补充样式。 */
	public static function css(): string {
		return '.pchq-f3-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 16px}.pchq-f3-filters label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#555}.pchq-f3-filters input,.pchq-f3-filters select{min-height:34px}'
			. '.pchq-f3-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.pchq-f3-muted{color:#777}.pchq-f3-bad{color:#b32d2e}.pchq-f3-ok{color:#1a7f37}'
			. '.pchq-f3-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0 0 16px}.pchq-f3-grid div{background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px}.pchq-f3-grid dt{font-size:12px;color:#777}.pchq-f3-grid dd{margin:4px 0 0;font-size:16px;font-weight:600}'
			. '.pchq-f3-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.pchq-f3-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#555}.pchq-f3-form input[type=text],.pchq-f3-form input[type=date],.pchq-f3-form input[type=number]{min-height:34px}'
			. '.pchq-f3-level1 td{font-weight:700;background:#f3f3f3}.pchq-f3-level2 td{font-weight:600;background:#fafafa}.pchq-f3-level2 td:first-child{padding-left:28px}.pchq-f3-level3 td:first-child{padding-left:52px}'
			. '.pchq-f3-pager{display:flex;gap:8px;align-items:center;margin:12px 0}.pchq-f3-void{opacity:.55;text-decoration:line-through}';
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_orders_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function site_name( array $sites, int $id ): string {
		$s = $sites[ $id ] ?? null;
		if ( ! $s ) {
			return '店铺 #' . $id;
		}
		return '' !== (string) $s['name'] ? (string) $s['name'] : (string) wp_parse_url( (string) $s['url'], PHP_URL_HOST );
	}

	private static function state_label( array $o ): array {
		if ( 1 === $o['is_test'] ) {
			return array( '测试单', 'is-off' );
		}
		if ( 'full' === $o['refund_state'] ) {
			return array( '已退款', 'is-bad' );
		}
		if ( 'partial' === $o['refund_state'] ) {
			return array( '部分退款', 'is-wait' );
		}
		return 1 === $o['paid'] ? array( '已付款', 'is-ok' ) : array( '未付款（' . $o['status'] . '）', 'is-off' );
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以操作订单和退款。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
		$back = self::url();
		switch ( $action ) {
			case 'pchq_refund_mark':
				$order = Orders::by_id( absint( $_POST['order'] ?? 0 ) );
				if ( ! $order ) {
					self::flash( 'error', '订单不存在。' );
					break;
				}
				$back   = self::url( array( 'order' => $order['id'] ) );
				$amount = Orders::parse_money( sanitize_text_field( wp_unslash( (string) ( $_POST['amount'] ?? '' ) ) ) );
				if ( $amount < 0 ) {
					self::flash( 'error', '退款金额不是有效的数字，例如 25 或 25.50。' );
					break;
				}
				$res = Refunds::mark(
					$order['site_id'],
					$order['store_order_id'],
					$amount,
					sanitize_text_field( wp_unslash( (string) ( $_POST['refunded_on'] ?? '' ) ) ),
					sanitize_text_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ),
					get_current_user_id()
				);
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					self::flash( $res['pushed'] ? 'success' : 'error', $res['pushed'] ? '已标记退款 ' . Orders::money( $amount, (string) $order['currency'] ) . '，店铺订单已同步，销售额已按净额重算。' : $res['message'] );
				}
				break;
			case 'pchq_refund_void':
				$order = Orders::by_id( absint( $_POST['order'] ?? 0 ) );
				$back  = $order ? self::url( array( 'order' => $order['id'] ) ) : $back;
				$res   = Refunds::void( absint( $_POST['refund'] ?? 0 ), get_current_user_id() );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
				} else {
					self::flash( $res['pushed'] ? 'success' : 'error', $res['pushed'] ? '已撤销这条退款标记，店铺订单已同步，销售额已恢复。记录保留在下方。' : $res['message'] );
				}
				break;
			case 'pchq_refund_retry':
				Refunds::retry_pending();
				$left = Refunds::pending_count();
				self::flash( $left ? 'error' : 'success', $left ? '还有 ' . $left . ' 个订单的退款标记没写回店铺（店铺可能暂时连不上），系统每 10 分钟自动重试。' : '退款标记已全部写回店铺。' );
				break;
			case 'pchq_orders_verify':
				$site_id = absint( $_POST['site'] ?? 0 );
				$sites   = Sites::all();
				$res     = Orders::verify_site( $site_id );
				$name    = self::site_name( $sites, $site_id );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $name . '：核对失败，' . ( \PeptideHq\Series_Push::is_unsupported( $res ) ? '这家店的程序还不支持订单核对，请先更新店铺程序。' : $res->get_error_message() ) );
				} elseif ( $res['match'] ) {
					self::flash( 'success', sprintf( '%1$s：一致。已付款订单 %2$d 笔，金额 %3$s，已标记退款 %4$s。', $name, $res['hq']['count'], Orders::money( $res['hq']['total_cents'] ), Orders::money( $res['hq']['refunded_cents'] ) ) );
				} else {
					self::flash( 'error', sprintf( '%1$s：不一致。总后台 %2$d 笔 / %3$s / 退款 %4$s；店铺 %5$d 笔 / %6$s / 退款 %7$s。先在店铺运行回填（wp peptide orders backfill），退款不一致时点“重试回写”。', $name, $res['hq']['count'], Orders::money( $res['hq']['total_cents'] ), Orders::money( $res['hq']['refunded_cents'] ), $res['store']['count'], Orders::money( $res['store']['total_cents'] ), Orders::money( $res['store']['refunded_cents'] ) ) );
				}
				break;
		}
		wp_safe_redirect( $back );
		exit;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_orders_flash_' . get_current_user_id() );
		delete_transient( 'pchq_orders_flash_' . get_current_user_id() );
		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>订单与退款</h1><p>各店已付款的订单汇总在这里。支付通道不支持系统内退款：收单方线下通知退款后，在订单里“标记退款”，销售额和报表会按净额重算；标错可以撤销，每次操作都留记录。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Orders::ready() ) {
			echo '<div class="pcp-flash is-bad">订单表还没准备好，请刷新页面；一直这样时检查数据库权限。</div></div>';
			return;
		}
		$order_id = absint( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $order_id ) {
			self::render_order( $order_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选。
		$sites  = Sites::all();
		$series = Series::all( true );
		$f      = array(
			'site_id'   => absint( $_GET['site'] ?? 0 ),
			'series_id' => absint( $_GET['series'] ?? 0 ),
			'state'     => in_array( $_GET['state'] ?? '', array( 'paid', 'unpaid', 'refunded', 'test' ), true ) ? (string) $_GET['state'] : '',
			'q'         => sanitize_text_field( wp_unslash( (string) ( $_GET['q'] ?? '' ) ) ),
		);
		$from_day = sanitize_text_field( wp_unslash( (string) ( $_GET['from'] ?? '' ) ) );
		$to_day   = sanitize_text_field( wp_unslash( (string) ( $_GET['to'] ?? '' ) ) );
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$tz = wp_timezone();
		if ( Orders::valid_day( $from_day ) ) {
			$f['from'] = ( new \DateTimeImmutable( $from_day . ' 00:00:00', $tz ) )->getTimestamp();
		} else {
			$from_day = '';
		}
		if ( Orders::valid_day( $to_day ) ) {
			$f['to'] = ( new \DateTimeImmutable( $to_day . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp() - 1;
		} else {
			$to_day = '';
		}
		$per    = 50;
		$result = Orders::search( $f, $page, $per );

		$pending = Refunds::pending_count();
		if ( $pending ) {
			echo '<div class="pcp-flash is-wait">有 ' . (int) $pending . ' 个订单的退款标记还没写回店铺，系统每 10 分钟自动重试。 <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'pchq_refund_retry' );
			echo '<input type="hidden" name="action" value="pchq_refund_retry"><button type="submit" class="button button-small">重试回写</button></form></div>';
		}

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>订单</h2><span class="pcp-hint">共 ' . (int) $result['total'] . ' 笔</span></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pchq-f3-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>系列<select name="series"><option value="0">全部</option>';
		foreach ( $series as $s ) {
			echo '<option value="' . (int) $s['id'] . '"' . selected( $f['series_id'], $s['id'], false ) . '>' . esc_html( $s['name'] ) . '</option>';
		}
		echo '</select></label><label>店铺<select name="site"><option value="0">全部</option>';
		foreach ( $sites as $id => $s ) {
			echo '<option value="' . (int) $id . '"' . selected( $f['site_id'], (int) $id, false ) . '>' . esc_html( self::site_name( $sites, (int) $id ) ) . '</option>';
		}
		echo '</select></label><label>状态<select name="state">';
		foreach ( array( '' => '全部', 'paid' => '已付款', 'refunded' => '有退款', 'unpaid' => '未付款', 'test' => '测试单' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['state'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>付款日期从<input type="date" name="from" value="' . esc_attr( $from_day ) . '"></label><label>到<input type="date" name="to" value="' . esc_attr( $to_day ) . '"></label>';
		echo '<label>订单号<input type="text" name="q" value="' . esc_attr( $f['q'] ) . '" maxlength="40" placeholder="例如 1234"></label>';
		echo '<button type="submit" class="button button-primary">筛选</button> <a class="button" href="' . esc_url( self::url() ) . '">清空</a></form>';

		if ( ! $result['rows'] ) {
			echo '<div class="pcp-empty"><strong>没有符合条件的订单</strong><p>店铺付款成功后会自动上报；历史订单需要先在每家店运行一次回填。</p></div></section>';
		} else {
			echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">付款时间</th><th scope="col">店铺</th><th scope="col">订单号</th><th scope="col">状态</th><th scope="col" class="pchq-f3-num">订单金额</th><th scope="col" class="pchq-f3-num">已标记退款</th><th scope="col" class="pchq-f3-num">净额</th><th scope="col">来源</th><th scope="col">操作</th></tr></thead><tbody>';
			foreach ( $result['rows'] as $o ) {
				[ $label, $tone ] = self::state_label( $o );
				$when             = $o['paid_at'] ? $o['paid_at'] : $o['created_at'];
				echo '<tr><td>' . esc_html( $when ? wp_date( 'Y-m-d H:i', $when ) : '—' ) . '</td>';
				echo '<td>' . esc_html( self::site_name( $sites, $o['site_id'] ) ) . '</td>';
				echo '<td><a href="' . esc_url( self::url( array( 'order' => $o['id'] ) ) ) . '">#' . esc_html( '' !== $o['order_number'] ? $o['order_number'] : (string) $o['store_order_id'] ) . '</a></td>';
				echo '<td><span class="pcp-state ' . esc_attr( $tone ) . '"><i></i>' . esc_html( $label ) . '</span>' . ( 1 === $o['refund_sync'] ? '<small class="pchq-f3-bad">待写回店铺</small>' : '' ) . '</td>';
				echo '<td class="pchq-f3-num">' . esc_html( Orders::money( $o['total_cents'], (string) $o['currency'] ) ) . '</td>';
				echo '<td class="pchq-f3-num">' . ( $o['refunded_cents'] > 0 ? esc_html( Orders::money( $o['refunded_cents'], (string) $o['currency'] ) ) : '<span class="pchq-f3-muted">—</span>' ) . '</td>';
				echo '<td class="pchq-f3-num">' . esc_html( Orders::money( $o['total_cents'] - $o['refunded_cents'], (string) $o['currency'] ) ) . '</td>';
				echo '<td>' . ( '' !== $o['utm_source'] ? esc_html( $o['utm_source'] ) : '<span class="pchq-f3-muted">—</span>' ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( self::url( array( 'order' => $o['id'] ) ) ) . '">' . ( $o['refunded_cents'] > 0 ? '查看退款' : '标记退款' ) . '</a></td></tr>';
			}
			echo '</tbody></table></div>';
			$pages = (int) ceil( $result['total'] / $per );
			if ( $pages > 1 ) {
				$keep = array_filter( array( 'series' => $f['series_id'], 'site' => $f['site_id'], 'state' => $f['state'], 'q' => $f['q'], 'from' => $from_day, 'to' => $to_day ) );
				echo '<div class="pchq-f3-pager">';
				if ( $page > 1 ) {
					echo '<a class="button" href="' . esc_url( self::url( $keep + array( 'paged' => $page - 1 ) ) ) . '">上一页</a>';
				}
				echo '<span>第 ' . (int) $page . ' / ' . (int) $pages . ' 页</span>';
				if ( $page < $pages ) {
					echo '<a class="button" href="' . esc_url( self::url( $keep + array( 'paged' => $page + 1 ) ) ) . '">下一页</a>';
				}
				echo '</div>';
			}
			echo '</section>';
		}

		// 各店合计与核对
		$totals = Orders::site_totals();
		echo '<h2 class="pcp-section">各店合计（全部时间）</h2><section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">店铺</th><th scope="col" class="pchq-f3-num">已付款订单</th><th scope="col" class="pchq-f3-num">订单金额</th><th scope="col" class="pchq-f3-num">已标记退款</th><th scope="col" class="pchq-f3-num">净额</th><th scope="col">和店铺核对</th></tr></thead><tbody>';
		foreach ( $sites as $id => $s ) {
			$t = $totals[ $id ] ?? array( 'orders' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'currency' => '' );
			echo '<tr><td><strong>' . esc_html( self::site_name( $sites, (int) $id ) ) . '</strong></td><td class="pchq-f3-num">' . (int) $t['orders'] . '</td><td class="pchq-f3-num">' . esc_html( Orders::money( $t['gross_cents'], $t['currency'] ) ) . '</td><td class="pchq-f3-num">' . esc_html( Orders::money( $t['refunded_cents'], $t['currency'] ) ) . '</td><td class="pchq-f3-num">' . esc_html( Orders::money( $t['gross_cents'] - $t['refunded_cents'], $t['currency'] ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'pchq_orders_verify' );
			echo '<input type="hidden" name="action" value="pchq_orders_verify"><input type="hidden" name="site" value="' . (int) $id . '"><button type="submit" class="button button-small">核对</button></form></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function render_order( int $id ): void {
		$o = Orders::by_id( $id );
		echo '<p><a href="' . esc_url( self::url() ) . '">← 返回订单列表</a></p>';
		if ( ! $o ) {
			echo '<section class="pcp-card pcp-empty"><strong>订单不存在</strong></section>';
			return;
		}
		$sites            = Sites::all();
		$series           = Series::all( true );
		[ $label, $tone ] = self::state_label( $o );
		$cur              = (string) $o['currency'];
		$left             = max( 0, $o['total_cents'] - $o['refunded_cents'] );
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>订单 #' . esc_html( '' !== $o['order_number'] ? $o['order_number'] : (string) $o['store_order_id'] ) . '</h2><span class="pcp-state ' . esc_attr( $tone ) . '"><i></i>' . esc_html( $label ) . '</span></div>';
		echo '<dl class="pchq-f3-grid">';
		foreach ( array(
			'店铺'       => self::site_name( $sites, $o['site_id'] ),
			'系列'       => (string) ( $series[ $o['series_id'] ]['name'] ?? '—' ),
			'店铺订单状态' => (string) $o['status'],
			'下单时间'    => $o['created_at'] ? wp_date( 'Y-m-d H:i', $o['created_at'] ) : '—',
			'付款时间'    => $o['paid_at'] ? wp_date( 'Y-m-d H:i', $o['paid_at'] ) : '—',
			'订单金额'    => Orders::money( $o['total_cents'], $cur ),
			'已标记退款'  => Orders::money( $o['refunded_cents'], $cur ),
			'净额'       => Orders::money( $o['total_cents'] - $o['refunded_cents'], $cur ),
			'来源'       => '' !== $o['utm_source'] ? $o['utm_source'] . ( '' !== $o['ad_id'] ? ' / ' . $o['ad_id'] : '' ) : '—',
		) as $k => $v ) {
			echo '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		}
		echo '</dl>';
		if ( 1 === $o['refund_sync'] ) {
			echo '<div class="pcp-flash is-wait">退款标记还没写回店铺' . ( '' !== (string) $o['refund_error'] ? '：' . esc_html( (string) $o['refund_error'] ) : '' ) . '。系统每 10 分钟自动重试。</div>';
		}
		echo '</section>';

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>标记退款</h2><span class="pcp-hint">只做记录，不会向支付通道发起退款；已回传给广告平台的购买事件不撤回</span></div>';
		if ( 1 === $o['is_test'] ) {
			echo '<p class="pcp-hint">这是测试单，不计入销售额，不需要标记退款。</p>';
		} elseif ( 1 !== $o['paid'] && $o['paid_at'] < 1 ) {
			echo '<p class="pcp-hint">这个订单没有付款记录，不能标记退款。</p>';
		} elseif ( $left < 1 ) {
			echo '<p class="pcp-hint">这个订单已经全额标记退款。要改金额，先撤销下面的记录再重新标记。</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pchq-f3-form" data-confirm="确定标记退款？店铺、投手和系列的销售额会按净额重算。">';
			wp_nonce_field( 'pchq_refund_mark' );
			echo '<input type="hidden" name="action" value="pchq_refund_mark"><input type="hidden" name="order" value="' . (int) $o['id'] . '">';
			echo '<label>退款金额（最多 ' . esc_html( Orders::money( $left, $cur ) ) . '）<input type="text" name="amount" inputmode="decimal" required value="' . esc_attr( number_format( $left / 100, 2, '.', '' ) ) . '" maxlength="12"></label>';
			echo '<label>退款日期<input type="date" name="refunded_on" required value="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"></label>';
			echo '<label>备注（可选）<input type="text" name="note" maxlength="255" size="40" placeholder="例如：收单方 11-10 通知，顾客拒付"></label>';
			echo '<button type="submit" class="button button-primary">标记退款</button></form>';
		}
		echo '</section>';

		$rows = Refunds::for_order( $o['site_id'], $o['store_order_id'] );
		echo '<h2 class="pcp-section">退款记录 <span>' . count( $rows ) . '</span></h2>';
		if ( ! $rows ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有退款记录</strong><p>标记和撤销都会记在这里。</p></section>';
			return;
		}
		$user = static function ( int $uid ): string {
			$u = $uid ? get_userdata( $uid ) : false;
			return $u ? (string) $u->display_name : '—';
		};
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">标记时间</th><th scope="col">操作人</th><th scope="col" class="pchq-f3-num">金额</th><th scope="col">退款日期</th><th scope="col">备注</th><th scope="col">状态</th><th scope="col">操作</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$voided = (int) $r['voided_at'] > 0;
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $r['created_at'] ) ) . '</td><td>' . esc_html( $user( (int) $r['created_by'] ) ) . '</td>';
			echo '<td class="pchq-f3-num' . ( $voided ? ' pchq-f3-void' : '' ) . '">' . esc_html( Orders::money( (int) $r['amount_cents'], $cur ) ) . '</td><td>' . esc_html( (string) $r['refunded_on'] ) . '</td><td>' . esc_html( (string) $r['note'] ) . '</td>';
			if ( $voided ) {
				echo '<td><span class="pcp-state is-off"><i></i>已撤销</span><small>' . esc_html( wp_date( 'Y-m-d H:i', (int) $r['voided_at'] ) . ' · ' . $user( (int) $r['voided_by'] ) ) . '</small></td><td></td>';
			} else {
				echo '<td><span class="pcp-state is-ok"><i></i>有效</span></td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="确定撤销这条退款标记？销售额会恢复，记录保留。">';
				wp_nonce_field( 'pchq_refund_void' );
				echo '<input type="hidden" name="action" value="pchq_refund_void"><input type="hidden" name="order" value="' . (int) $o['id'] . '"><input type="hidden" name="refund" value="' . (int) $r['id'] . '"><button type="submit" class="button button-small">撤销</button></form></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';
	}
}
