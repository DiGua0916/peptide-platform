<?php
/**
 * 仪表盘漏斗点进去：发起结账的客户（2026-11-10）。
 *
 * admin.php?page=pc-checkout-dropoff&range=today|yesterday|7d|30d&step=checkout|address|submit|order|paid
 * 不在菜单里显示，菜单高亮“仪表盘”。数据见 Tracking\Checkout_Dropoffs（只读）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Overview;
use PeptideCore\Tracking\Checkout_Dropoffs;

defined( 'ABSPATH' ) || exit;

final class Checkout_Dropoff_Page {

	public const SLUG = 'pc-checkout-dropoff';
	public const CAP  = 'manage_woocommerce';

	private const RANGES = array( 'today' => '今天', 'yesterday' => '昨天', '7d' => '过去 7 天', '30d' => '过去 30 天' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 99 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 99 );
		add_action( 'admin_head', array( self::class, 'drop_hidden_parent' ) );
	}

	/**
	 * 菜单输出前把隐藏父菜单去掉：WordPress 画菜单时会按 $submenu 重新算父菜单（覆盖 parent_file 过滤器的结果），
	 * 去掉后才会停在我们指定的“仪表盘”上。页面权限在这之前已经检查过，不受影响。
	 */
	public static function drop_hidden_parent(): void {
		if ( self::here() ) {
			unset( $GLOBALS['submenu']['pc-hidden'] );
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** 隐藏页：挂在不存在的父菜单下，菜单里看不到，链接能打开。 */
	public static function add_menu(): void {
		add_submenu_page( 'pc-hidden', '发起结账的客户', '发起结账的客户', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	private static function here(): bool {
		return isset( $_GET['page'] ) && self::SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function parent_file( $file ) {
		return self::here() ? Overview_Page::SLUG : $file;
	}

	public static function submenu_file( $file ) {
		return self::here() ? Overview_Page::SLUG : $file;
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::here() ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), $ver( 'store-settings.css' ) );
		wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
		wp_enqueue_style( 'peptide-core-recovery', plugins_url( 'assets/recovery.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-plans' ), $ver( 'recovery.css' ) );
		wp_enqueue_style( 'peptide-core-checkout-dropoff', plugins_url( 'assets/checkout-dropoff.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-recovery' ), $ver( 'checkout-dropoff.css' ) );
	}

	private static function money( ?float $v, string $cur ): string {
		return null === $v ? '-' : wp_kses_post( wc_price( $v, array( 'currency' => $cur ) ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today';
		$range = isset( self::RANGES[ $range ] ) ? $range : 'today';
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'checkout';
		$step  = Checkout_Dropoffs::FUNNEL_FILTER[ $step ] ?? ( isset( Checkout_Dropoffs::STEPS[ $step ] ) ? $step : 'checkout' );
		$why   = isset( $_GET['why'] ) ? sanitize_key( wp_unslash( $_GET['why'] ) ) : '';
		// phpcs:enable
		[ $from, $to ] = Overview::window( $range );
		$data          = Checkout_Dropoffs::list( $from * 1000, $to * 1000 );
		$all           = $data['rows'];
		$rank          = array( 'checkout' => 1, 'address' => 2, 'submit' => 3, 'order' => 4, 'failed' => 5, 'paid' => 6 );
		$rows          = array_values( array_filter( $all, static fn( $r ) => $r['step_rank'] >= $rank[ $step ] && ( '' === $why || $r['reason_key'] === $why ) ) );
		$sum           = $data['summary'];

		echo '<div class="wrap pc-pl pc-rc pc-cd">';
		echo '<div class="pc-rc-detail__top"><a class="pc-rc-back" href="' . esc_url( admin_url( 'admin.php?page=' . Overview_Page::SLUG ) ) . '"><span class="dashicons dashicons-arrow-left-alt"></span> 返回仪表盘</a></div>';
		echo '<div class="pc-pl-head pc-rc-head"><div><h1>发起结账的客户</h1><p class="pc-rc-sub">仪表盘漏斗里“发起结账”的人，一人一行，和漏斗的数字同一个口径（这段时间开始的访问，按不同访客算）。每行写了他走到哪一步、在结账页停了多久、之后去了哪，以及没下单最可能的原因。点“行为记录”能看他每一步的完整时间线。</p></div></div>';

		// 时间段
		echo '<div class="pc-pl-card"><nav class="pc-pl-tabs">';
		foreach ( self::RANGES as $k => $label ) {
			echo '<a href="' . esc_url( self::url( array( 'range' => $k, 'step' => $step ) ) ) . '" class="' . ( $k === $range ? 'is-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		// 汇总
		$r = array();
		foreach ( $all as $one ) {
			if ( $one['step_rank'] >= $rank[ $step ] ) {
				$r[ $one['reason_key'] ] = ( $r[ $one['reason_key'] ] ?? 0 ) + 1;
			}
		}
		$r       = $r + array( 'paid' => 0 );
		$r_all   = $sum['reasons'] ?? array();
		$lost    = (int) ( $sum['total'] ?? 0 ) - (int) ( $r_all['paid'] ?? 0 );
		$no_addr = (int) ( $r_all['bounce'] ?? 0 ) + (int) ( $r_all['browse'] ?? 0 );
		echo '<div class="pc-cd-stats">';
		self::stat( '发起结账', (int) ( $sum['total'] ?? 0 ) . ' 人' );
		self::stat( '没下单', $lost . ' 人' );
		self::stat( '地址没填完就走了', $no_addr . ' 人' );
		self::stat( '结账页停留（中位数）', Checkout_Dropoffs::duration( (int) ( $sum['median_dwell'] ?? 0 ) ) );
		echo '</div>';

		// 原因分布（可点筛选）
		echo '<div class="pc-cd-why"><span class="pc-cd-why__label">没下单的原因：</span>';
		echo '<a class="pc-cd-chip' . ( '' === $why ? ' is-active' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $range, 'step' => $step ) ) ) . '">全部 <b>' . count( array_filter( $all, static fn( $x ) => $x['step_rank'] >= $rank[ $step ] ) ) . '</b></a>';
		foreach ( Checkout_Dropoffs::REASONS as $k => $label ) {
			$n = (int) ( $r[ $k ] ?? 0 );
			if ( ! $n ) {
				continue;
			}
			echo '<a class="pc-cd-chip is-' . esc_attr( $k ) . ( $why === $k ? ' is-active' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $range, 'step' => $step, 'why' => $k ) ) ) . '">' . esc_html( $label ) . ' <b>' . $n . '</b></a>';
		}
		echo '</div>';

		$insight = self::insight( $all );
		if ( $insight ) {
			echo '<div class="pc-cd-insight"><span class="dashicons dashicons-lightbulb"></span><div>' . wp_kses_post( $insight ) . '</div></div>';
		}

		// 步骤筛选
		$steps = array( 'checkout' => '全部发起结账', 'address' => '填完地址', 'submit' => '点了下单', 'order' => '下了单', 'paid' => '已付款' );
		echo '<div class="pc-cd-steps">';
		foreach ( $steps as $k => $label ) {
			$n = count( array_filter( $all, static fn( $x ) => $x['step_rank'] >= $rank[ $k ] ) );
			echo '<a class="' . ( $k === $step ? 'is-active' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $range, 'step' => $k ) ) ) . '">' . esc_html( $label ) . ' <span>' . (int) $n . '</span></a>';
		}
		echo '</div>';

		if ( ! $rows ) {
			echo '<p class="pc-cd-empty">这段时间没有符合条件的客户。</p></div></div>';
			return;
		}

		echo '<div class="pc-rc-scroll"><table class="pc-pl-table pc-rc-table pc-cd-table"><thead><tr>';
		foreach ( array( '进结账时间', '客户', '来源', '购物车', '运费 / 合计', '走到哪一步', '结账页停留', '为什么没下单', '操作' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $x ) {
			self::row( $x );
		}
		echo '</tbody></table></div>';
		echo '<p class="pc-cd-foot">说明：结账页不记录顾客在哪个输入框停下，所以“地址没填完”分不出是卡在哪一项。填过的姓名、邮箱、电话来自顾客的购物会话，顾客没填就是空的。</p>';
		echo '</div></div>';
	}

	private static function stat( string $label, string $value ): void {
		echo '<div class="pc-cd-stat"><span>' . esc_html( $label ) . '</span><b>' . esc_html( $value ) . '</b></div>';
	}

	/** 看得出来的共性：运费占比。 */
	private static function insight( array $rows ): string {
		$high = 0;
		$fees = array();
		$n    = 0;
		foreach ( $rows as $r ) {
			if ( 'paid' === $r['step'] || null === $r['shipping'] || empty( $r['cart']['subtotal'] ) ) {
				continue;
			}
			++$n;
			$fees[ (string) $r['shipping'] ] = true;
			if ( $r['shipping'] / max( 1, (float) $r['cart']['subtotal'] ) >= 0.4 ) {
				++$high;
			}
		}
		if ( $n < 3 || $high * 2 < $n ) {
			return '';
		}
		$fee = 1 === count( $fees ) ? wc_price( (float) array_key_first( $fees ) ) : '';
		return '<strong>运费可能是主要原因：</strong>没下单的 ' . $n . ' 人里，有 ' . $high . ' 人的运费' . ( $fee ? '（' . $fee . '）' : '' ) . '超过了商品金额的 40%。他们大多打开结账页、看到总价后没填地址就走了。可以考虑在商品页 / 购物车提前写清运费，或者降低小单运费、设满额包邮。';
	}

	private static function row( array $x ): void {
		$cur = (string) $x['currency'];
		$c   = $x['contact'];
		echo '<tr>';
		echo '<td class="pc-cd-time">' . esc_html( wp_date( 'm-d H:i', (int) floor( $x['checkout_ms'] / 1000 ) ) ) . '</td>';

		// 客户
		$who = array_filter( array( $c['name'], $c['email'], $c['phone'], trim( $c['city'] . ( $c['city'] && $c['country'] ? ', ' : '' ) . $c['country'] ) ) );
		echo '<td class="pc-cd-who">';
		if ( $who ) {
			echo '<strong>' . esc_html( $c['name'] ?: ( $c['email'] ?: '访客 #' . $x['visitor_id'] ) ) . '</strong>';
			if ( $c['email'] ) {
				echo '<br><a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>';
			}
			if ( $c['phone'] ) {
				echo '<br><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>';
			}
			$place = trim( $c['city'] . ( $c['city'] && $c['country'] ? ', ' : '' ) . $c['country'] );
			if ( $place ) {
				echo '<br><small class="pc-pl-muted">' . esc_html( $place ) . '</small>';
			}
		} else {
			echo '<strong>访客 #' . (int) $x['visitor_id'] . '</strong><br><small class="pc-pl-muted">没留联系方式</small>';
		}
		echo '<br><small class="pc-pl-muted">' . esc_html( $x['device'] . ( $x['browser'] ? ' · ' . $x['browser'] : '' ) . ( $x['visits'] > 1 ? ' · 第 ' . $x['visits'] . ' 次来' : ' · 第一次来' ) ) . '</small></td>';

		echo '<td class="pc-cd-src">' . esc_html( $x['source'] ) . ( $x['campaign'] ? '<br><small class="pc-pl-muted">' . esc_html( $x['campaign'] ) . '</small>' : '' ) . '</td>';

		// 购物车
		$items = (array) ( $x['cart']['items'] ?? array() );
		echo '<td class="pc-cd-cart">';
		if ( $items ) {
			echo '<ul>';
			foreach ( array_slice( $items, 0, 4 ) as $i ) {
				$spec = $i['variation'] ? (string) preg_replace( '/^[^:]+:\s*/', '', $i['variation'] ) : '';
				$name = (string) $i['name'];
				if ( '' !== $spec && false === stripos( str_replace( ' ', '', $name ), str_replace( ' ', '', $spec ) ) ) {
					$name .= '（' . $spec . '）'; // 名字里已经带规格的不重复写。
				}
				echo '<li>' . esc_html( $name ) . ' × ' . esc_html( (string) (float) $i['quantity'] ) . '</li>';
			}
			if ( count( $items ) > 4 ) {
				echo '<li class="pc-pl-muted">还有 ' . ( count( $items ) - 4 ) . ' 种</li>';
			}
			echo '</ul><small class="pc-pl-muted">商品 ' . esc_html( $x['cart']['subtotal_text'] ) . '</small>';
		} else {
			echo '<span class="pc-pl-muted">' . ( $x['order_id'] ? '见订单' : '购物车已清空' ) . '</span>';
		}
		echo '</td>';

		echo '<td class="pc-rc-money">运费 ' . self::money( $x['shipping'], $cur ) . '<br><strong>' . self::money( $x['total'], $cur ) . '</strong></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$cls = array( 'checkout' => 'is-muted', 'address' => 'is-warn', 'submit' => 'is-warn', 'order' => 'is-warn', 'failed' => 'is-bad', 'paid' => 'is-ok' )[ $x['step'] ];
		echo '<td><span class="pc-rc-badge ' . esc_attr( $cls ) . '">' . esc_html( $x['step_text'] ) . '</span></td>';
		echo '<td class="pc-cd-dwell">' . esc_html( Checkout_Dropoffs::duration( (int) $x['dwell_ms'] ) ) . '</td>';
		echo '<td class="pc-cd-reason">' . esc_html( $x['reason'] ) . '</td>';

		echo '<td class="pc-pl-col-act"><a class="button button-small" href="' . esc_url( $x['visitor_url'] ) . '">行为记录</a>';
		if ( $x['order_url'] ) {
			echo ' <a class="button button-small" href="' . esc_url( $x['order_url'] ) . '">弃单 #' . esc_html( $x['order_no'] ) . '</a>';
		} elseif ( $c['email'] && 'paid' !== $x['step'] ) {
			echo ' <a class="button button-small" href="' . esc_url( Mail_Page::compose_url( array( 'to' => $c['email'] ) ) ) . '">写邮件</a>';
		}
		echo '</td></tr>';
	}
}
