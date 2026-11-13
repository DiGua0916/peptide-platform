<?php
/**
 * 总后台 → 跨店报表（HQ-13）：系列 → 投手 → 店铺 三级对比订单、销售额（净额）、广告花费、ROAS。
 *
 * - 数字来自各店上报的订单（pchq_orders，已付款、非测试单，按付款时间）和手填的广告花费（pchq_ad_spend）。
 * - 三级都由同一批店铺行往上加，合计相等。销售额已扣除已标记的退款。
 * - 广告花费先手填：选一天，每家店填一个金额。
 * - 只给总后台管理员；写操作校验 nonce。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Orders;
use PeptideHq\Series;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Report_Page {

	public const SLUG = 'pchq-report';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '跨店报表', '跨店报表', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			22
		);
		add_action( 'admin_post_pchq_spend_save', array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_style( 'pchq-plugins', Orders_Page::css() );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * 报表的日期范围（总后台时区，含两端）。预设：today / yesterday / 7d / 30d / month；或自选 from、to。
	 *
	 * @return array{0:string,1:string,2:string} 开始日期、结束日期、当前预设
	 */
	public static function range( string $preset, string $from, string $to, ?int $now = null ): array {
		$now   = $now ?? time();
		$today = wp_date( 'Y-m-d', $now );
		if ( Orders::valid_day( $from ) && Orders::valid_day( $to ) && $from <= $to ) {
			return array( $from, $to, 'custom' );
		}
		switch ( $preset ) {
			case 'today':
				return array( $today, $today, 'today' );
			case 'yesterday':
				$y = wp_date( 'Y-m-d', $now - DAY_IN_SECONDS );
				return array( $y, $y, 'yesterday' );
			case '30d':
				return array( wp_date( 'Y-m-d', $now - 29 * DAY_IN_SECONDS ), $today, '30d' );
			case 'month':
				return array( wp_date( 'Y-m-01', $now ), $today, 'month' );
			default:
				return array( wp_date( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ), $today, '7d' );
		}
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以填写广告花费。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_spend_save' );
		$day   = sanitize_text_field( wp_unslash( (string) ( $_POST['day'] ?? '' ) ) );
		$sites = Sites::all();
		$saved = 0;
		$bad   = array();
		if ( ! Orders::valid_day( $day ) || $day > wp_date( 'Y-m-d' ) ) {
			set_transient( 'pchq_report_flash_' . get_current_user_id(), array( 'error', '请选择一个不晚于今天的日期。' ), 120 );
		} else {
			foreach ( (array) ( $_POST['spend'] ?? array() ) as $site_id => $value ) {
				$site_id = absint( $site_id );
				$value   = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
				if ( ! isset( $sites[ $site_id ] ) ) {
					continue;
				}
				$cents = '' === $value ? 0 : Orders::parse_money( $value );
				if ( $cents < 0 ) {
					$bad[] = (string) $sites[ $site_id ]['name'];
					continue;
				}
				if ( Orders::set_spend( $site_id, $day, $cents, get_current_user_id() ) ) {
					++$saved;
				}
			}
			$message = $day . ' 的广告花费已保存（' . $saved . ' 家店）。';
			if ( $bad ) {
				$message .= ' 这些店的金额不是有效数字，没有保存：' . implode( '、', $bad ) . '。';
			}
			set_transient( 'pchq_report_flash_' . get_current_user_id(), array( $bad ? 'error' : 'success', $message ), 120 );
		}
		wp_safe_redirect( self::url( array( 'spend_day' => Orders::valid_day( $day ) ? $day : '' ) ) );
		exit;
	}

	private static function cells( array $m ): string {
		$dash = '<span class="pchq-f3-muted">—</span>';
		return '<td class="pchq-f3-num">' . (int) $m['orders'] . '</td>'
			. '<td class="pchq-f3-num">' . ( $m['customers'] > 0 ? (int) $m['customers'] : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . esc_html( Orders::money( $m['gross_cents'] ) ) . '</td>'
			. '<td class="pchq-f3-num">' . ( $m['refunded_cents'] > 0 ? esc_html( Orders::money( $m['refunded_cents'] ) ) : $dash ) . '</td>'
			. '<td class="pchq-f3-num"><strong>' . esc_html( Orders::money( $m['net_cents'] ) ) . '</strong></td>'
			. '<td class="pchq-f3-num">' . ( $m['spend_cents'] > 0 ? esc_html( Orders::money( $m['spend_cents'] ) ) : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null !== $m['roas'] ? esc_html( number_format( (float) $m['roas'], 2 ) ) : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null !== $m['aov_cents'] ? esc_html( Orders::money( (int) $m['aov_cents'] ) ) : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( $m['visitors'] > 0 ? (int) $m['visitors'] : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null !== $m['cvr'] ? esc_html( number_format( (float) $m['cvr'], 2 ) . '%' ) : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null !== $m['abandon_orders'] ? (int) $m['abandoned'] : $dash ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null !== $m['abandon_rate'] ? esc_html( number_format( (float) $m['abandon_rate'], 2 ) . '%' ) : $dash ) . '</td>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_report_flash_' . get_current_user_id() );
		delete_transient( 'pchq_report_flash_' . get_current_user_id() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选。
		[ $from, $to, $preset ] = self::range(
			sanitize_key( (string) ( $_GET['range'] ?? '' ) ),
			sanitize_text_field( wp_unslash( (string) ( $_GET['from'] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $_GET['to'] ?? '' ) ) )
		);
		$spend_day = sanitize_text_field( wp_unslash( (string) ( $_GET['spend_day'] ?? '' ) ) );
		// phpcs:enable
		if ( ! Orders::valid_day( $spend_day ) || $spend_day > wp_date( 'Y-m-d' ) ) {
			$spend_day = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		}

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>跨店报表</h1><p>按 系列 → 投手 → 店铺 三级对比订单、销售额、广告花费和 ROAS。销售额已扣除已标记的退款；退款标记不及时时，销售额和 ROAS 会偏高。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Orders::ready() || ! Series::ready() ) {
			echo '<div class="pcp-flash is-bad">报表用到的数据表还没准备好，请刷新页面。</div></div>';
			return;
		}
		$report = Orders::report( $from, $to );

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>' . esc_html( $from === $to ? $from : $from . ' 至 ' . $to ) . '</h2><span class="pcp-hint">按付款时间统计；不含测试单和未付款订单</span></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pchq-f3-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		foreach ( array( 'today' => '今天', 'yesterday' => '昨天', '7d' => '近 7 天', '30d' => '近 30 天', 'month' => '本月' ) as $key => $label ) {
			echo '<a class="button' . ( $preset === $key ? ' button-primary' : '' ) . '" href="' . esc_url( self::url( array( 'range' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<label>从<input type="date" name="from" value="' . esc_attr( $from ) . '"></label><label>到<input type="date" name="to" value="' . esc_attr( $to ) . '"></label><button type="submit" class="button">查看</button></form>';

		echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">系列 / 投手 / 店铺</th><th scope="col" class="pchq-f3-num">订单</th><th scope="col" class="pchq-f3-num">顾客</th><th scope="col" class="pchq-f3-num">订单金额</th><th scope="col" class="pchq-f3-num">已标记退款</th><th scope="col" class="pchq-f3-num">销售额（净额）</th><th scope="col" class="pchq-f3-num">广告花费</th><th scope="col" class="pchq-f3-num">ROAS</th><th scope="col" class="pchq-f3-num">客单价</th><th scope="col" class="pchq-f3-num">访客</th><th scope="col" class="pchq-f3-num">转化率</th><th scope="col" class="pchq-f3-num">弃单</th><th scope="col" class="pchq-f3-num">弃单率</th></tr></thead><tbody>';
		echo '<tr class="pchq-f3-level1"><td>全部系列合计</td>' . self::cells( $report['total'] ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells() 已转义。
		foreach ( $report['series'] as $s ) {
			echo '<tr class="pchq-f3-level1"><td>' . esc_html( '' !== $s['name'] ? $s['name'] : '未归入系列' ) . '</td>' . self::cells( $s['metrics'] ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $s['buyers'] as $b ) {
				echo '<tr class="pchq-f3-level2"><td>' . esc_html( '' !== $b['buyer_name'] ? $b['buyer_name'] : '未填投手' ) . '</td>' . self::cells( $b['metrics'] ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $b['stores'] as $store ) {
					echo '<tr class="pchq-f3-level3"><td><a href="' . esc_url( add_query_arg( array( 'page' => Orders_Page::SLUG, 'site' => (int) $store['site_id'], 'state' => 'paid', 'from' => $from, 'to' => $to ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $store['name'] ) . '</a></td>' . self::cells( $store ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		}
		echo '</tbody></table></div><p class="pcp-hint">访客和转化率用的是各店每天上报的访客数（店铺时区的日期），和按付款时间统计的订单口径略有出入，只作参考。投手在“系列管理”里填写。</p><p class="pcp-hint">顾客：按客户编号去重，同一位顾客在几家店都买过，上一级只算一次，所以这一列不是下级相加。弃单率 = 弃单数 ÷（弃单数 + 订单数）。弃单数来自各店的每日汇总（按弃单产生那天）；分母里的订单数用的是同一家店、同样那些天汇总里的订单数，所以和左边按付款时间统计的“订单”一列可能差一点。店铺程序还没带上弃单数的那些天不参与计算，全都没有时显示“—”。</p></section>';

		// 广告花费
		$sites = Sites::all();
		$have  = Orders::spend_by_site( $spend_day, $spend_day );
		echo '<h2 class="pcp-section">广告花费（手填）</h2><section class="pcp-card"><div class="pcp-card__head"><h2>' . esc_html( $spend_day ) . ' 的花费</h2><span class="pcp-hint">每家店每天一个金额（美元）；留空或填 0 表示没有花费</span></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pchq-f3-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><label>换一天<input type="date" name="spend_day" value="' . esc_attr( $spend_day ) . '" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"></label><button type="submit" class="button">切换</button></form>';
		if ( ! $sites ) {
			echo '<p class="pcp-hint">还没有店铺。</p></section></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pchq-f3-form">';
		wp_nonce_field( 'pchq_spend_save' );
		echo '<input type="hidden" name="action" value="pchq_spend_save"><input type="hidden" name="day" value="' . esc_attr( $spend_day ) . '">';
		$map = Series::site_map();
		foreach ( $sites as $id => $site ) {
			$buyer = (string) ( $map[ $id ]['buyer_name'] ?? '' );
			$value = isset( $have[ $id ] ) ? number_format( $have[ $id ] / 100, 2, '.', '' ) : '';
			echo '<label>' . esc_html( (string) $site['name'] . ( '' !== $buyer ? '（' . $buyer . '）' : '' ) ) . '<input type="text" inputmode="decimal" name="spend[' . (int) $id . ']" value="' . esc_attr( $value ) . '" maxlength="12" placeholder="0.00"></label>';
		}
		echo '<button type="submit" class="button button-primary">保存这一天的花费</button></form></section></div>';
	}
}
