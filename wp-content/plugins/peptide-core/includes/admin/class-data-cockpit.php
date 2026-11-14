<?php
/**
 * 数据栏目改造第 3 步（店主 2026-11-14）：今日作战室（数据 → 今日作战室，pc-data-cockpit）。
 *
 * 一屏回答“今天怎么样、哪里出了问题、现在该做什么”：
 * - 最近 24 小时（默认）/ 美国今天 / 美国昨天，和前一段同样长的时间比较。
 * - 核心数：花费、销售额、ROAS、单单成本、订单、访客、转化率、客单价。
 * - 需要注意（有事才出现）：亏钱、素材疲劳、点了广告没进站、流量下跌、支付失败、像素回传异常、昨天的花费没导入。
 * - 带判断的漏斗、现在正在逛的人（最近 30 分钟，购物车有东西 / 在结账的置顶）、素材表（建议按最近 3 天算）。
 * 计算都复用复盘页（Data_Review）；这里只多两件事：按小时摊算花费（spend_window），和提醒规则（alerts，纯函数）。
 * 原来的“实时数据”页（地图、每分钟访客、9 个面板）不在菜单里了，从这页顶部的按钮进。
 *
 * @package PeptideCore\Admin
 */

namespace PeptideCore\Admin;

use PeptideCore\Tracking\Schema as TS;

defined( 'ABSPATH' ) || exit;

final class Data_Cockpit {

	public const SLUG   = 'pc-data-cockpit';
	public const RANGES = array( '24h' => '最近 24 小时', 'today' => '美国今天', 'yesterday' => '美国昨天' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 61 );
	}

	public static function menu(): void {
		add_submenu_page( Data_Pages::SLUG, '今日作战室', '今日作战室', Data_Pages::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	// ======================================================================
	// 时间、花费（纯函数）
	// ======================================================================

	/** @return array{from:int,to:int,days:int,label:string} */
	public static function range( string $key, int $now ): array {
		$today = Data_Review::day_start( Data_Review::ymd( $now ) );
		switch ( $key ) {
			case 'today':
				return array( 'from' => $today, 'to' => $now, 'days' => 1, 'label' => '美国今天（' . Data_Review::ymd( $now ) . '，截至现在）' );
			case 'yesterday':
				$y = Data_Review::day_start( Data_Review::ymd( $today - 1 ) );
				return array( 'from' => $y, 'to' => $today, 'days' => 1, 'label' => '美国昨天（' . Data_Review::ymd( $y ) . '）' );
		}
		return array( 'from' => $now - Data_Review::DAY, 'to' => $now, 'days' => 1, 'label' => '最近 24 小时' );
	}

	/**
	 * 按天的花费摊到任意时段：每天的花费按这一天和时段重叠的时间比例算。
	 * 今天还没过完，今天这一天按“零点到现在”算全长，所以“美国今天”拿到今天导入的全部花费。
	 */
	public static function spend_window( array $rows, int $from, int $to, int $now ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$ds = Data_Review::day_start( (string) $row['day'] );
			$de = min( $ds + Data_Review::DAY, max( $ds + 1, $now ) );
			$w  = max( 0, min( $to, $de ) - max( $from, $ds ) ) / max( 1, $de - $ds );
			if ( $w <= 0 ) {
				continue;
			}
			$a                 = $out[ (string) $row['ad_id'] ] ?? array( 'spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0 );
			$a['spend']       += $w * (int) $row['spend_cents'] / 100;
			$a['impressions'] += $w * (int) $row['impressions'];
			$a['clicks']      += $w * (int) $row['link_clicks'];
			$out[ (string) $row['ad_id'] ] = $a;
		}
		return $out;
	}

	/** 取一段时间的数据并算好（花费按小时摊）。 */
	private static function period( array $r, int $now ): array {
		$raw = Data_Review::load( array( 'from' => $r['from'], 'to' => $r['to'], 'days' => $r['days'] ) );
		// load() 按天取花费（时段首尾两天都在里面），这里再按重叠比例摊。
		$raw['spend_ad'] = self::spend_window( $raw['spend'], $r['from'], $r['to'], $now );
		return Data_Review::compute( $raw ) + array( 'spend_rows' => $raw['spend'] );
	}

	// ======================================================================
	// 提醒（纯函数）
	// ======================================================================

	/**
	 * @param array $p 所选时段  @param array $q 前一段  @param array $cr3 最近 3 天的素材  @param array $tr 素材趋势
	 * @param array $extra pay（payment_outcomes() 的结果）、pixel（Health::current() 的结果）、spend_yesterday（昨天有没有导入花费）、any_spend
	 * @return array<int,array{0:string,1:string,2:string}> [标签, 颜色, 说明]
	 */
	public static function alerts( array $p, array $q, array $cr3, array $tr, array $extra = array() ): array {
		$out = array();
		$px  = (array) ( $extra['pixel'] ?? array() );
		if ( in_array( $px['level'] ?? '', array( 'bad', 'warn' ), true ) ) {
			$out[] = array( '像素回传', 'bad' === $px['level'] ? 'red' : 'orange', trim( (string) $px['title'] . '。' . (string) ( $px['detail'] ?? '' ) ) );
		}
		if ( $p['has_spend'] && $p['spend'] >= 40 && null !== $p['roas'] && $p['roas'] < 1 ) {
			$out[] = array( '亏钱', 'red', sprintf( 'ROAS %.2f：花了 %s，只卖了 %s。看下面素材表，先关掉标红的。', $p['roas'], Data_Review::money( $p['spend'] ), Data_Review::money( $p['revenue'] ) ) );
		}
		$pay       = (array) ( $extra['pay'] ?? array() );
		$failed    = (int) ( $pay['failed'] ?? 0 );
		$abandoned = (int) ( $pay['abandoned'] ?? 0 );
		if ( $failed >= 2 ) {
			$out[] = array( '支付被拒', $failed > $p['n_orders'] ? 'red' : 'orange', sprintf( '这段时间有 %d 笔付款被支付通道拒了（成功 %d 单）。订单列表里有些会显示“已取消”——那是没付成以后超时取消的，原因还是被拒。具体拒付代码要找支付通道要。', $failed, $p['n_orders'] ) );
		}
		if ( $abandoned >= 3 && $abandoned > $p['n_orders'] ) {
			$out[] = array( '打开支付页没付', 'orange', sprintf( '%d 个客户点了付款、打开了支付页，然后就没再回来（成功 %d 单）。先在手机上自己走一遍支付页，看是不是加载慢、报错或要求的信息太多。', $abandoned, $p['n_orders'] ) );
		}
		foreach ( $cr3 as $r ) {
			$v    = Data_Review::verdict( $r, $tr[ $r['code'] ] ?? null );
			$name = $r['code'] . ( '' !== (string) $r['note'] ? '（' . $r['note'] . '）' : '' );
			if ( '疲劳·换素材' === $v[0] ) {
				$out[] = array( '素材疲劳', 'orange', $name . '：' . $v[2] . '，准备新素材替换。' );
			}
			if ( null !== $r['land'] && $r['clicks'] >= 20 && $r['land'] < 0.6 ) {
				$out[] = array( '点了没进站', 'orange', sprintf( '%s：最近 3 天 Meta 记了约 %d 次链接点击，只有 %d 人进站（%s）。检查页面打开速度，或斗篷是不是拦了真人。', $name, round( $r['clicks'] ), $r['visitors'], Data_Review::pct( $r['land'], 0 ) ) );
			}
		}
		if ( $q['visitors'] >= 20 && $p['visitors'] < $q['visitors'] * 0.6 ) {
			$out[] = array( '流量下跌', 'orange', sprintf( '访客 %d，比前一段（%d）少了 %d%%。看看广告是不是停了、预算花完了，或被拒审。', $p['visitors'], $q['visitors'], (int) round( ( 1 - $p['visitors'] / $q['visitors'] ) * 100 ) ) );
		}
		if ( ! empty( $extra['any_spend'] ) && empty( $extra['spend_yesterday'] ) ) {
			$out[] = array( '花费没导入', 'gray', '昨天（美国时间）的广告花费还没导入，ROAS 和素材建议会偏高。到素材库导入 Meta 每日报表。' );
		}
		return $out;
	}

	// ======================================================================
	// 取数（只读）
	// ======================================================================

	/**
	 * 一单的支付结果（纯函数）。
	 *
	 * 只看订单状态会漏：付款被拒以后，订单还会被“未付款超时”改成已取消，failed 就被覆盖掉了。
	 * 所以优先看支付网关记下的每次尝试（订单的 _pc_panapay_sessions），没有记录时才退回订单状态。
	 *
	 * @param mixed  $sessions 订单的支付尝试记录
	 * @param string $status   订单状态
	 * @return string paid（付成了）| failed（被拒）| abandoned（打开支付页没付）| none（没走到支付）
	 */
	public static function payment_outcome( $sessions, string $status ): string {
		$states = array();
		if ( is_array( $sessions ) && ! empty( $sessions['attempts'] ) && is_array( $sessions['attempts'] ) ) {
			foreach ( $sessions['attempts'] as $a ) {
				$states[] = is_array( $a ) ? (string) ( $a['state'] ?? '' ) : '';
			}
		}
		if ( in_array( 'paid', $states, true ) || in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return 'paid';
		}
		if ( in_array( 'failed', $states, true ) || 'failed' === $status ) {
			return 'failed';
		}
		if ( array_intersect( $states, array( 'created', 'unknown', 'creating' ) ) ) {
			return 'abandoned';
		}
		return 'none';
	}

	/** 时段里下的单按支付结果分组（不含测试单）。 @return array{paid:int,failed:int,abandoned:int} */
	private static function payment_outcomes( array $r ): array {
		$out = array( 'paid' => 0, 'failed' => 0, 'abandoned' => 0 );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		foreach ( (array) wc_get_orders( array( 'status' => array( 'failed', 'cancelled', 'pending', 'processing', 'completed', 'on-hold' ), 'limit' => 200, 'type' => 'shop_order', 'date_created' => intdiv( $r['from'], 1000 ) . '...' . intdiv( $r['to'], 1000 ) ) ) as $o ) {
			if ( ! $o instanceof \WC_Order || ( class_exists( \PeptideCore\Data\Metrics_Overlay::class ) && \PeptideCore\Data\Metrics_Overlay::is_test_order( $o ) ) ) {
				continue;
			}
			$k = self::payment_outcome( $o->get_meta( '_pc_panapay_sessions' ), $o->get_status() );
			if ( isset( $out[ $k ] ) ) {
				++$out[ $k ];
			}
		}
		return $out;
	}

	/** 某一天（美国中部日期）有没有导入花费。 */
	private static function has_spend_on( string $day ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . \PeptideCore\Creatives\Schema::table( 'daily_spend' ) . ' WHERE day = %s LIMIT 1', $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** 最近 30 分钟有动作的访问，最远走到加购 / 结账的置顶。 */
	private static function live( int $now ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT v.id, v.visitor_id, v.last_ms, v.stage, v.creative_code, v.utm_source, v.referrer_host, v.device, v.os, v.geo_region, v.geo_city, v.in_app, p.cart, p.visits FROM ' . TS::table( 'visits' ) . ' v JOIN ' . TS::table( 'visitors' ) . ' p ON p.id = v.visitor_id WHERE v.is_test = 0 AND v.last_ms >= %d ORDER BY ( v.stage IN (2,3) ) DESC, v.last_ms DESC LIMIT 15', $now - 30 * 60000 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ======================================================================
	// 页面
	// ======================================================================

	public static function render(): void {
		if ( ! current_user_can( Data_Pages::CAP ) ) {
			return;
		}
		$key = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '24h'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( self::RANGES[ $key ] ) ? $key : '24h';
		$now = (int) floor( microtime( true ) * 1000 );
		$r   = self::range( $key, $now );
		$p   = self::period( $r, $now );
		$q   = self::period( array( 'from' => $r['from'] - ( $r['to'] - $r['from'] ), 'to' => $r['from'], 'days' => 1 ), $now );

		$ads    = Data_Review::load_ads();
		$meta   = Data_Review::creative_meta();
		$today  = Data_Review::day_start( Data_Review::ymd( $now ) );
		$cr     = Data_Review::by_creative( $p, $ads, $meta );
		$cr3    = Data_Review::by_creative( self::period( array( 'from' => $now - 3 * Data_Review::DAY, 'to' => $now, 'days' => 3 ), $now ), $ads, $meta );
		$recent = Data_Review::by_creative( Data_Review::compute( Data_Review::load( array( 'from' => $today - 2 * Data_Review::DAY, 'to' => $now, 'days' => 2 ) ) ), $ads, $meta );
		$before = Data_Review::by_creative( Data_Review::compute( Data_Review::load( array( 'from' => $today - 7 * Data_Review::DAY, 'to' => $today - 2 * Data_Review::DAY, 'days' => 5 ) ) ), $ads, $meta );
		$tr     = Data_Review::trends( $recent, $before );

		$any_spend = Data_Review::any_spend();
		$yday      = Data_Review::ymd( $today - 1 );
		$extra     = array(
			'pay'             => self::payment_outcomes( $r ),
			'pixel'           => class_exists( \PeptideCore\Pixels\Health::class ) ? \PeptideCore\Pixels\Health::current() : array(),
			'any_spend'       => $any_spend,
			'spend_yesterday' => $any_spend && self::has_spend_on( $yday ),
		);
		$alerts = self::alerts( $p, $q, $cr3, $tr, $extra );

		echo '<div class="wrap pc-rv">';
		Data_Review::styles();
		self::styles();
		echo '<div class="pc-rv-head"><div><h1>今日作战室</h1><p class="pc-rv-sub">' . esc_html( $r['label'] . ' · 美国中部时间 · 和前一段同样长的时间比较' ) . '</p></div><div class="pc-ck-headr"><div class="pc-rv-tabs">';
		foreach ( self::RANGES as $k => $label ) {
			echo '<a class="' . ( $k === $key ? 'is-on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'range' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Data_Pages::SLUG ) ) . '">实时明细（地图、每分钟访客）</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Data_Review::SLUG ) ) . '">7 天复盘</a></div></div>';

		if ( ! $any_spend ) {
			echo '<div class="notice notice-warning inline pc-rv-notice"><p><b>还没导入广告花费。</b>花费、ROAS、单单成本和素材建议都要靠它。在 Meta 广告管理工具按“天 + 广告”导出报表，然后 <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=pc-creatives-spend' ) ) . '">去导入花费</a></p></div>';
		}

		echo '<section class="pc-rv-card"><div class="pc-rv-kpis">';
		$sd    = Data_Review::delta( $p['has_spend'] ? $p['spend'] : null, $q['has_spend'] ? $q['spend'] : null );
		$sd[1] = 'flat';
		echo Data_Review::kpi( '花费', $p['has_spend'] ? Data_Review::money( $p['spend'] ) : '—', $sd, '导入的 Meta 每日花费，按时段摊算' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '销售额', Data_Review::money( $p['revenue'] ), Data_Review::delta( $p['revenue'], $q['revenue'] ), '付款订单金额减退款，不含测试单' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( 'ROAS', null === $p['roas'] ? '—' : number_format( $p['roas'], 2 ), Data_Review::delta( $p['roas'], $q['roas'] ), '销售额 ÷ 花费。1 以下亏钱，2 以上一般才赚钱' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '单单成本', Data_Review::money( $p['cpa'] ), Data_Review::delta( $p['cpa'], $q['cpa'], false ), '花费 ÷ 订单数' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '订单', (string) $p['n_orders'], Data_Review::delta( $p['n_orders'], $q['n_orders'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '访客', (string) $p['visitors'], Data_Review::delta( $p['visitors'], $q['visitors'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '转化率', Data_Review::pct( $p['cvr'], 1 ), Data_Review::delta( $p['cvr'], $q['cvr'] ), '订单 ÷ 访客，参考 1–3%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Data_Review::kpi( '客单价', Data_Review::money( $p['aov'] ), Data_Review::delta( $p['aov'], $q['aov'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';

		if ( $alerts ) {
			echo '<section class="pc-rv-card pc-ck-alerts"><h2>需要注意</h2><ul>';
			foreach ( $alerts as $a ) {
				echo '<li>' . Data_Review::tag( $a[0], $a[1] ) . ' ' . esc_html( $a[2] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul></section>';
		} else {
			$px_level = (string) ( $extra['pixel']['level'] ?? '' );
			$px_text  = 'ok' === $px_level ? '像素回传正常，' : ( 'none' === $px_level ? '（本店后台还没填 Facebook 像素，回传没法在这里检查）' : '' );
			echo '<p class="pc-ck-ok">' . Data_Review::tag( '没有异常', 'green' ) . ' ' . esc_html( 'none' === $px_level ? '支付、流量、素材都没发现问题' . $px_text . '。' : $px_text . '支付、流量、素材都没发现问题。' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="pc-rv-grid"><section class="pc-rv-card"><h2>钱漏在哪一步</h2>' . Data_Review::funnel_html( $p, $q ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<section class="pc-rv-card"><h2>现在正在逛的人</h2>' . self::live_html( self::live( $now ), $now ) . '</section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<section class="pc-rv-card"><h2>素材：该加预算的、该关的</h2><p class="pc-rv-help">表里的数字是所选时段的；建议按<b>最近 3 天</b>算（一天的样本太小，容易看错）。规则：ROAS ≥ 2.5 加预算；花了 $40 还没单或 ROAS &lt; 1 关掉；最近 2 天 ROAS 掉一半以上 = 疲劳；花费不到 $40 且不到 3 单 = 样本不足。</p>';
		echo Data_Review::creative_table( $cr, $tr, $cr3, '建议（按最近 3 天）' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/** 正在逛的人。 */
	public static function live_html( array $rows, int $now ): string {
		if ( ! $rows ) {
			return '<p class="pc-rv-empty">最近 30 分钟没有人在店里。</p>';
		}
		$stage = array( 0 => '在看首页 / 列表', 1 => '在看商品', 2 => '购物车有东西', 3 => '在结账', 4 => '下了单', 5 => '已付款' );
		$seen  = array();
		$h     = '<ul class="pc-ck-live">';
		foreach ( $rows as $r ) {
			$vid = (int) $r['visitor_id'];
			if ( isset( $seen[ $vid ] ) ) {
				continue;
			}
			$seen[ $vid ] = true;
			$st   = (int) $r['stage'];
			$hot  = in_array( $st, array( 2, 3 ), true );
			$cart = json_decode( (string) $r['cart'], true );
			$cart = is_array( $cart ) && ! empty( $cart['count'] ) && $st >= 2 && $st < 4 ? '购物车 ' . (int) $cart['count'] . ' 件 ' . (string) ( $cart['subtotal_text'] ?? '' ) : '';
			$src  = '' !== (string) $r['creative_code'] ? '素材 ' . $r['creative_code'] : ( '' !== (string) $r['utm_source'] ? (string) $r['utm_source'] : ( '' !== (string) $r['referrer_host'] ? (string) $r['referrer_host'] : '直接打开' ) );
			$dev  = ( '' !== (string) $r['os'] ? (string) $r['os'] : (string) $r['device'] ) . ( '' !== (string) $r['in_app'] ? '（' . strtoupper( (string) $r['in_app'] ) . ' 内）' : '' );
			$geo  = trim( (string) $r['geo_city'] . ( '' !== (string) $r['geo_city'] && '' !== (string) $r['geo_region'] ? ', ' : '' ) . (string) $r['geo_region'] );
			$ago  = max( 0, (int) round( ( $now - (int) $r['last_ms'] ) / 60000 ) );
			$url  = admin_url( 'admin.php?page=pc-visitors&visitor=' . $vid );
			$bits = array_filter( array( $src, $dev, $geo, (int) $r['visits'] > 1 ? '第 ' . (int) $r['visits'] . ' 次来' : '新客', 0 === $ago ? '刚刚' : $ago . ' 分钟前' ) );
			$h   .= '<li class="' . ( $hot ? 'is-hot' : '' ) . '"><div>' . Data_Review::tag( $stage[ $st ] ?? '', $hot ? 'orange' : ( $st >= 4 ? 'green' : 'gray' ) ) . ( '' !== $cart ? ' <b>' . esc_html( $cart ) . '</b>' : '' ) . '<small>' . esc_html( implode( ' · ', $bits ) ) . '</small></div><a class="button button-small" href="' . esc_url( $url ) . '">看他在干嘛</a></li>';
		}
		return $h . '</ul><p class="pc-rv-help">最近 30 分钟有动作的访客，购物车有东西 / 在结账的排在前面。</p>';
	}

	private static function styles(): void {
		echo '<style>
.pc-ck-headr{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pc-ck-alerts ul{margin:0;display:grid;gap:8px}.pc-ck-alerts li{margin:0;font-size:13px;line-height:1.5}
.pc-ck-ok{margin:0 0 14px;font-size:13px;color:var(--rv-muted)}
.pc-ck-live{margin:0;display:grid;gap:6px}
.pc-ck-live li{margin:0;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px;padding:6px 8px;border-radius:6px;background:#fafafa}
.pc-ck-live li.is-hot{background:#fff8ee}
.pc-ck-live li small{display:block;color:var(--rv-muted);font-size:12px;margin-top:2px}
</style>';
	}
}
