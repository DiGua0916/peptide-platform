<?php
/**
 * 后台“订单 → 弃单”（2026-11-09 按店主 Shopname 后台重做）。
 *
 * 列表：状态标签（全部 / 待召回 / 召回中 / 召回失败 / 召回成功 / 已作废）、按时间和订单编号 / 邮箱筛选；
 * 列：操作、订单编号、商品、顾客邮箱、国家、配送方式、订单总价、支付方式、支付时间、流水号、订单状态、召回状态、邮件发送时间、召回次数。
 * 详情（?view=<订单号>）：商品、费用拆分、收货 / 账单地址（可复制）、支付信息、物流信息、召回记录（任务列表）；
 * 右栏：购买人信息、订单基础信息、订单备注、订单归因、设备信息。
 * 召回 = 给顾客发邮件；数据见 Recovery_Items。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Recovery;
use PeptideCore\Recovery_Items;
use PeptideCore\Mail_Outbox;

defined( 'ABSPATH' ) || exit;

final class Recovery_Page {

	public const SLUG = 'pc-recovery';
	public const CAP  = 'manage_woocommerce';
	public const PER  = 50;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 61 );
		add_action( 'admin_post_pc_recovery_scan', array( self::class, 'handle_scan' ) );
		add_action( 'admin_post_pc_recovery_void', array( self::class, 'handle_void' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/** 放在“订单”菜单下面（Admin_Menus 建的顶层菜单）；没有这个菜单时放在 WooCommerce 下面。 */
	public static function add_menu(): void {
		global $menu;
		$slugs  = array_column( (array) $menu, 2 );
		$parent = in_array( Admin_Menus::PENDING, $slugs, true ) ? Admin_Menus::PENDING : ( in_array( Admin_Menus::ORDERS, $slugs, true ) ? Admin_Menus::ORDERS : 'woocommerce' );
		add_submenu_page( $parent, __( 'Abandoned checkouts', 'peptide-core' ), __( 'Abandoned checkouts', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, self::SLUG ) ) {
			$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), $ver( 'store-settings.css' ) );
			wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
			wp_enqueue_style( 'peptide-core-recovery', plugins_url( 'assets/recovery.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-plans' ), $ver( 'recovery.css' ) );
			wp_add_inline_script( 'common', "document.addEventListener('click',function(e){var c=e.target.closest('.pc-rc-copy');if(c){var t=c.getAttribute('data-copy')||'';var done=function(){c.classList.add('is-done');c.textContent='已复制';setTimeout(function(){c.classList.remove('is-done');c.textContent='复制';},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,function(){window.prompt('复制：',t);});}else{window.prompt('复制：',t);}return;}var b=e.target.closest('.pc-rc-toggle');if(b){var r=document.getElementById(b.getAttribute('aria-controls'));if(r){r.hidden=!r.hidden;b.setAttribute('aria-expanded',r.hidden?'false':'true');}}});" );
		}
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_scan(): void {
		self::guard( 'pc_recovery_scan' );
		$r = Recovery::scan();
		wp_safe_redirect( self::url( array( 'pc_msg' => 'scanned', 'n' => (int) $r['tracked'], 's' => (int) $r['sent'] ) ) );
		exit;
	}

	public static function handle_void(): void {
		self::guard( 'pc_recovery_void' );
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$undo = ! empty( $_GET['undo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['rec'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			Recovery::void( $id );
			wp_safe_redirect( self::url( array( 'rec' => $id, 'pc_msg' => 'voided' ) ) );
			exit;
		}
		Recovery_Items::set_void( $id, ! $undo );
		wp_safe_redirect( self::url( array( 'view' => $id, 'pc_msg' => $undo ? 'restored' : 'voided' ) ) );
		exit;
	}

	private static function time( int $ms ): string {
		return $ms ? wp_date( 'Y-m-d H:i:s', (int) floor( $ms / 1000 ) ) : '-';
	}

	private static function money( float $v, string $currency ): string {
		return wp_kses_post( wc_price( $v, array( 'currency' => $currency ) ) );
	}

	private static function badge( string $text, string $cls ): string {
		return '<span class="pc-rc-badge ' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$view = absint( $_GET['view'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rec  = absint( $_GET['rec'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $view || $rec ) {
			// 详情页语言开关（店主要求）：只影响弃单详情页，记在当前用户身上，其他页面不变。
			if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				update_user_meta( get_current_user_id(), 'pc_recovery_lang', 'en' === $_GET['lang'] ? 'en' : 'zh' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$en = 'en' === get_user_meta( get_current_user_id(), 'pc_recovery_lang', true );
			ob_start();
			if ( $view ) {
				self::render_detail( $view );
			} else {
				self::render_record( $rec );
			}
			$html = (string) ob_get_clean();
			echo $en ? self::to_english( $html ) : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 渲染函数内已转义。
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$status = in_array( $status, Recovery_Items::STATUSES, true ) ? $status : '';
		$from   = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$to     = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );
		$q      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$msg    = sanitize_key( wp_unslash( $_GET['pc_msg'] ?? '' ) );
		// phpcs:enable
		$tz      = wp_timezone();
		$from_ms = $from && strtotime( $from ) ? ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp() * 1000 : 0;
		$to_ms   = $to && strtotime( $to ) ? ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp() * 1000 : 0;
		$list    = Recovery_Items::list( array( 'status' => $status, 'from_ms' => $from_ms, 'to_ms' => $to_ms, 'q' => $q ), $page, self::PER );
		$labels  = Recovery_Items::status_labels();

		echo '<div class="wrap pc-pl pc-rc">';
		if ( 'scanned' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( '扫描完成：新增 %1$d 条弃单，发出 %2$d 封提醒。', absint( $_GET['n'] ?? 0 ), absint( $_GET['s'] ?? 0 ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<div class="pc-pl-head pc-rc-head"><div><h1>弃单</h1><p class="pc-rc-sub">没付款的结账都在这里。召回 = 给顾客发邮件：付款失败和超时取消的单子系统会自动发付款帮助信，弃单提醒按阶段自动发，也可以点进去手写。</p></div>';
		echo '<div class="pc-rc-head__actions"><a class="button" href="' . esc_url( Email_Center::url( 'abandoned' ) ) . '">弃单通知设置</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-failed' ) ) . '">支付失败汇总</a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-pl-new"><input type="hidden" name="action" value="pc_recovery_scan">';
		wp_nonce_field( 'pc_recovery_scan' );
		echo '<button class="button button-primary">立即扫描</button></form></div></div>';

		$blocked = Recovery::blocked_reason();
		if ( ! Recovery::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>弃单提醒邮件全部关着，结账中途离开的顾客不会收到提醒。<a href="' . esc_url( Email_Center::url( 'abandoned' ) ) . '">去打开</a></p></div>';
		} elseif ( 'address' === $blocked ) {
			echo '<div class="notice notice-warning inline"><p>店铺地址还没填，提醒邮件不会发（美国法律要求营销邮件带实体地址）。<a href="' . esc_url( Basic_Config_Page::url() ) . '">去填写</a></p></div>';
		} elseif ( 'sender' === $blocked ) {
			echo '<div class="notice notice-warning inline"><p>还没有可用的客服邮箱作为发件人，提醒邮件不会发。<a href="' . esc_url( Basic_Config_Page::url() ) . '">去设置</a></p></div>';
		}

		echo '<div class="pc-pl-card pc-rc-card"><nav class="pc-pl-tabs">';
		$tabs = array( '' => '全部' ) + $labels;
		foreach ( $tabs as $k => $label ) {
			$n = '' === $k ? array_sum( $list['counts'] ) : ( $list['counts'][ $k ] ?? 0 );
			echo '<a href="' . esc_url( self::url( array_filter( array( 'status' => $k, 'from' => $from, 'to' => $to, 'q' => $q ) ) ) ) . '" class="' . ( $k === $status ? 'is-active' : '' ) . '">' . esc_html( $label ) . ' <span class="pc-pl-muted">(' . (int) $n . ')</span></a>';
		}
		echo '</nav>';
		echo '<form method="get" class="pc-rc-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<label>创建时间 <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <span class="pc-rc-filter__dash">–</span> <input type="date" name="to" value="' . esc_attr( $to ) . '" aria-label="结束">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="订单编号、邮箱或姓名（多个用空格隔开）"><button class="button">筛选</button></form>';

		echo '<div class="pc-rc-scroll"><table class="pc-pl-table pc-rc-table"><thead><tr>';
		foreach ( array( '操作', '姓名', '电话', '邮箱', '国家', '配送方式', '应付总价（含运费）', '支付方式', '支付时间', '流水号', '订单状态', '召回状态', '邮件发送时间', '召回次数' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $list['items'] ) {
			echo '<tr><td colspan="14" class="pc-pl-empty">还没有弃单。</td></tr>';
		}
		foreach ( $list['items'] as $r ) {
			self::row( $r, $labels );
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $list['total'] / self::PER );
		echo '<div class="pc-rc-pager"><span>共 ' . (int) $list['total'] . ' 条</span>';
		if ( $pages > 1 ) {
			echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
		}
		echo '</div></div></div>';
	}

	private static function row( array $r, array $labels ): void {
		$o    = $r['order'];
		$view = $o ? self::url( array( 'view' => $r['id'] ) ) : self::url( array( 'rec' => (int) $r['rec']['id'] ) );
		echo '<tr>';
		echo '<td class="pc-pl-col-act"><a class="pc-rc-icon" href="' . esc_url( $view ) . '" title="查看详情" aria-label="查看详情"><span class="dashicons dashicons-visibility"></span></a>';
		echo '<a class="pc-rc-icon" href="' . esc_url( Mail_Page::compose_url( array_filter( array( 'order_id' => $o ? $r['id'] : 0, 'to' => $r['email'] ) ) ) ) . '" title="写邮件" aria-label="写邮件"><span class="dashicons dashicons-email"></span></a>';
		// 店主 2026-11-11：按这张弃单创建手动订单——原来的商品、数量、价格直接带进手动订单，改好生成付款链接发给顾客。
		$redo = admin_url( 'admin.php?page=pc-manual-order&' . ( $o ? 'from_order=' . (int) $r['id'] : 'from_rec=' . (int) $r['rec']['id'] ) );
		echo '<a class="pc-rc-icon" href="' . esc_url( $redo ) . '" title="按这个单创建手动订单" aria-label="按这个单创建手动订单"><span class="dashicons dashicons-cart"></span></a></td>';
		$more = ( $r['attempts'] ?? 1 ) > 1 ? ' <span class="pc-rc-attempts" title="同一顾客反复下单，只算一条弃单，点进去看全部">共 ' . (int) $r['attempts'] . ' 单</span>' : '';
		echo '<td><a class="pc-rc-num" href="' . esc_url( $view ) . '">' . esc_html( Recovery_Items::display_name( $r['name'] ) ?: '-' ) . '</a>' . $more . '<br><small class="pc-pl-muted">' . esc_html( $o ? '#' . $r['number'] : '未下单' ) . ' · ' . esc_html( self::time( (int) $r['created_ms'] ) ) . '</small></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td class="pc-rc-phone">' . ( $r['phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['phone'] ) ) . '">' . esc_html( $r['phone'] ) . '</a>' : '-' ) . '</td>';
		echo '<td>' . ( $r['email'] ? '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>' : '-' ) . '</td>';
		echo '<td>' . esc_html( $r['country'] ?: '-' ) . '</td><td>' . esc_html( $r['ship_method'] ?: '-' ) . '</td>';
		$range = ( $r['attempts'] ?? 1 ) > 1 && (float) $r['total_max'] !== (float) $r['total'] ? '<br><small class="pc-pl-muted">最高 ' . wp_strip_all_tags( wc_price( (float) $r['total_max'], array( 'currency' => $r['currency'] ) ) ) . '</small>' : '';
		// 店主 2026-11-10：总价含运费和优惠；下面再写商品金额，和实时访客、AI 分析里的“购物车”对得上。
		$goods = isset( $r['subtotal'] ) && (float) $r['subtotal'] > 0 && abs( (float) $r['subtotal'] - (float) $r['total'] ) > 0.009 ? '<br><small class="pc-pl-muted">商品 ' . wp_strip_all_tags( wc_price( (float) $r['subtotal'], array( 'currency' => $r['currency'] ) ) ) . '</small>' : '';
		echo '<td class="pc-rc-money">' . self::money( $r['total'], $r['currency'] ) . $goods . $range . '</td><td>' . esc_html( $r['pay_method'] ?: '-' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . esc_html( self::time( (int) $r['paid_ms'] ) ) . '</td><td>' . esc_html( '' !== $r['tid'] ? $r['tid'] : '-' ) . '</td>';
		echo '<td>' . self::badge( Recovery_Items::order_status_label( $r['order_status'] ), $r['paid_ms'] ? 'is-paid' : 'is-open' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . self::badge( ! empty( $r['status_text'] ) ? $r['status_text'] : ( $labels[ $r['status'] ] ?? $r['status'] ), 'is-' . $r['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . esc_html( self::time( (int) $r['last_sent_ms'] ) ) . '</td><td>' . (int) $r['emails'] . '</td></tr>';
	}

	/* ---------------- 详情 ---------------- */

	private static function render_detail( int $id ): void {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			echo '<div class="wrap pc-pl pc-rc"><p>找不到这张订单。</p><p><a href="' . esc_url( self::url() ) . '">返回弃单列表</a></p></div>';
			return;
		}
		$r      = Recovery_Items::item( $order );
		$labels = Recovery_Items::status_labels();
		$cur    = $order->get_currency();
		$msg    = sanitize_key( wp_unslash( $_GET['pc_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paid   = (bool) $r['paid_ms'];
		$void   = 'void' === $r['status'];

		echo '<div class="wrap pc-pl pc-rc pc-rc-detail">';
		if ( 'voided' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>已作废，不再召回。</p></div>';
		} elseif ( 'restored' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>已恢复召回。</p></div>';
		}
		echo '<div class="pc-rc-detail__top"><a class="pc-rc-back" href="' . esc_url( self::url() ) . '"><span class="dashicons dashicons-arrow-left-alt"></span> 返回弃单列表</a>';
		echo '<div class="pc-rc-detail__actions">';
		echo '<a class="button pc-rc-lang" href="' . esc_url( add_query_arg( 'lang', 'en' === get_user_meta( get_current_user_id(), 'pc_recovery_lang', true ) ? 'zh' : 'en' ) ) . '" title="切换这一页的显示语言">' . ( 'en' === get_user_meta( get_current_user_id(), 'pc_recovery_lang', true ) ? '中文' : 'English' ) . '</a>';
		echo '<a class="button" href="' . esc_url( $order->get_edit_order_url() ) . '">订单页</a>';
		if ( ! $paid ) {
			echo '<a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '" target="_blank" rel="noopener">付款链接</a>';
		}
		echo '<a class="button button-primary" href="' . esc_url( Mail_Page::compose_url( array( 'order_id' => $id, 'to' => $r['email'] ) ) ) . '">写邮件召回</a>';
		if ( ! $paid ) {
			$void_url = wp_nonce_url( admin_url( 'admin-post.php?action=pc_recovery_void&id=' . $id . ( $void ? '&undo=1' : '' ) ), 'pc_recovery_void' );
			echo '<a class="button pc-rc-danger" href="' . esc_url( $void_url ) . '">' . ( $void ? '恢复召回' : '作废订单' ) . '</a>';
		}
		echo '</div></div>';

		echo '<div class="pc-rc-grid">';
		/* ---------- 左栏 ---------- */
		echo '<div class="pc-rc-main">';
		// 订单卡
		echo '<section class="pc-rc-panel"><header class="pc-rc-panel__head"><div class="pc-rc-title"><strong>#' . esc_html( $r['number'] ) . '</strong>';
		echo self::badge( Recovery_Items::order_status_label( $r['order_status'] ), $paid ? 'is-paid' : 'is-open' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::badge( $paid ? '已支付' : '未支付', $paid ? 'is-paid' : 'is-warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::badge( in_array( $order->get_status(), array( 'completed' ), true ) ? '已发货' : '未发货', 'is-muted' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::badge( ! empty( $r['status_text'] ) ? $r['status_text'] : $labels[ $r['status'] ], 'is-' . $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div class="pc-rc-meta"><span>创建时间：' . esc_html( self::time( (int) $r['created_ms'] ) ) . '</span><span>支付时间：' . esc_html( self::time( (int) $r['paid_ms'] ) ) . '</span></div></header>';

		// 店主 2026-11-11：商品多的单子（超过 4 件）只先显示前 3 件、行更紧凑，点“展开全部”再看其余。
		$all_lines = Recovery_Items::lines( $order );
		$many      = count( $all_lines ) > 4;
		echo '<table class="pc-rc-lines' . ( $many ? ' is-many' : '' ) . '"><thead><tr><th>商品名称</th><th class="pc-rc-right">单价 × 数量</th><th class="pc-rc-right">总价</th></tr></thead><tbody>';
		foreach ( $all_lines as $li => $l ) {
			echo '<tr' . ( $many && $li >= 3 ? ' class="pc-rc-more" hidden' : '' ) . '><td><div class="pc-rc-line">';
			echo $l['image'] ? '<img src="' . esc_url( $l['image'] ) . '" alt="">' : '<span class="pc-rc-line__noimg"></span>';
			echo '<div><strong>' . esc_html( $l['name'] ) . '</strong>';
			if ( '' !== $l['sku'] ) {
				echo '<div class="pc-pl-muted">SKU: ' . esc_html( $l['sku'] ) . '</div>';
			}
			foreach ( $l['attrs'] as $a ) {
				echo '<div class="pc-pl-muted">' . esc_html( $a ) . '</div>';
			}
			echo '</div></div></td>';
			echo '<td class="pc-rc-right">' . self::money( $l['unit'], $cur ) . ' × ' . (int) $l['qty'] . '</td><td class="pc-rc-right">' . self::money( $l['total'], $cur ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $many ) {
			echo '<tr class="pc-rc-moretoggle"><td colspan="3"><button type="button" class="button-link" onclick="var t=this.closest(\'table\');var open=t.classList.toggle(\'is-open\');t.querySelectorAll(\'.pc-rc-more\').forEach(function(r){r.hidden=!open;});this.textContent=open?this.dataset.less:this.dataset.more;" data-more="展开全部 ' . count( $all_lines ) . ' 件商品" data-less="收起">展开全部 ' . count( $all_lines ) . ' 件商品</button></td></tr>';
		}
		echo '</tbody></table>';
		// 店主 2026-11-11：订单时间放在金额左边，一行小字。
		$created = $order->get_date_created();
		$paid_at = $order->get_date_paid();
		echo '<div class="pc-rc-totalrow"><p class="pc-rc-ordered">' . ( $created ? '下单时间 ' . esc_html( wp_date( 'Y-m-d H:i:s', $created->getTimestamp() ) ) : '' ) . ( $paid_at ? '<br>付款时间 ' . esc_html( wp_date( 'Y-m-d H:i:s', $paid_at->getTimestamp() ) ) : '' ) . '</p>';
		echo '<dl class="pc-rc-totals">';
		foreach ( Recovery_Items::totals( $order ) as $row ) {
			if ( 0.0 === (float) $row[1] && ! str_starts_with( $row[0], '商品' ) && ! str_starts_with( $row[0], '运费' ) ) {
				continue;
			}
			echo '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . self::money( (float) $row[1], $cur ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<dt class="pc-rc-totals__sum">订单总价</dt><dd class="pc-rc-totals__sum">' . self::money( (float) $order->get_total(), $cur ) . '</dd></dl></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		self::addresses( $order->get_address( 'shipping' ), $order->get_address( 'billing' ) );

		// 支付信息
		echo '<section class="pc-rc-panel"><h3>支付信息</h3>';
		echo '<dl class="pc-rc-kv"><dt>支付方式</dt><dd>' . esc_html( $r['pay_method'] ?: '-' ) . '</dd><dt>流水号</dt><dd>' . esc_html( $r['tid'] ?: '-' ) . '</dd><dt>支付时间</dt><dd>' . esc_html( self::time( (int) $r['paid_ms'] ) ) . '</dd></dl>';
		$attempts = Recovery_Items::payment_attempts( $order );
		echo '<h4>支付记录</h4>';
		if ( ! $attempts ) {
			echo '<p class="pc-pl-muted">暂无支付信息</p>';
		} else {
			echo '<table class="pc-rc-mini"><thead><tr><th>时间</th><th>结果</th><th>金额</th></tr></thead><tbody>';
			foreach ( $attempts as $a ) {
				echo '<tr><td>' . esc_html( $a['time'] ? wp_date( 'Y-m-d H:i:s', $a['time'] ) : '-' ) . '</td><td>' . esc_html( $a['state'] ) . ( $a['active'] ? ' <span class="pc-pl-muted">（当前）</span>' : '' ) . '</td><td>' . esc_html( trim( $a['amount'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// 物流信息
		echo '<section class="pc-rc-panel"><h3>物流信息</h3>';
		$ship = class_exists( '\\PeptideCore\\Shipping_Tracking' ) ? \PeptideCore\Shipping_Tracking::customer_view( $order ) : null;
		if ( $ship && ! empty( $ship['shipments'] ) ) {
			echo '<ul class="pc-rc-list">';
			foreach ( (array) $ship['shipments'] as $s ) {
				echo '<li>' . esc_html( trim( ( $s['provider'] ?? $s['carrier'] ?? $s['name'] ?? '' ) . ' ' . ( $s['number'] ?? $s['tracking_number'] ?? '' ) ) ) . ( ! empty( $s['url'] ) ? ' <a href="' . esc_url( $s['url'] ) . '" target="_blank" rel="noopener">查询</a>' : '' ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="pc-pl-muted">暂无物流信息</p>';
		}
		echo '</section>';

		// 召回记录
		echo '<section class="pc-rc-panel"><h3>召回记录 <span class="pc-pl-muted">（给这位顾客发过的邮件）</span></h3>';
		if ( ! $r['mails'] ) {
			echo '<p class="pc-pl-muted">还没发过邮件。</p>';
		} else {
			echo '<table class="pc-rc-mini"><thead><tr><th>任务</th><th>主题</th><th>类型</th><th>状态</th><th>结果</th><th>时间</th><th></th></tr></thead><tbody>';
			foreach ( $r['mails'] as $m ) {
				$ok = 'sent' === $m['status'];
				echo '<tr><td>' . esc_html( Recovery_Items::mail_kind_label( (string) $m['kind'] ) ) . '</td><td>' . esc_html( (string) $m['subject'] ) . '</td><td>邮件</td>';
				// 状态 = 这次召回现在算什么（召回中 / 召回成功 / 召回失败）；结果 = 邮件本身发没发出去。
				echo '<td>' . self::badge( $ok ? $labels[ $r['status'] ] : '未召回', $ok ? 'is-' . $r['status'] : 'is-muted' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( $ok ? '已发出' : ( 'queued' === $m['status'] ? '发送中' : '发送失败' ) ) . ( ! $ok && ! empty( $m['error'] ) ? '<br><small class="pc-pl-muted">' . esc_html( (string) $m['error'] ) . '</small>' : '' ) . '</td>';
				echo '<td>' . esc_html( self::time( (int) $m['created_ms'] ) ) . '</td><td><a href="' . esc_url( Mail_Page::url( array( 'q' => $r['email'] ) ) ) . '">查看</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
		$sibs = Recovery_Items::siblings( $order );
		if ( $sibs ) {
			echo '<section class="pc-rc-panel"><h3>这位顾客的其他未付款订单 <span class="pc-pl-muted">（反复下单只算一条弃单，召回信只发一封）</span></h3><table class="pc-rc-mini"><thead><tr><th>订单</th><th>时间</th><th>金额</th><th>支付方式</th><th>状态</th></tr></thead><tbody>';
			foreach ( $sibs as $s ) {
				echo '<tr><td><a href="' . esc_url( self::url( array( 'view' => $s->get_id() ) ) ) . '">#' . esc_html( $s->get_order_number() ) . '</a></td><td>' . esc_html( $s->get_date_created() ? $s->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '-' ) . '</td><td>' . self::money( (float) $s->get_total(), $s->get_currency() ) . '</td><td>' . esc_html( $s->get_payment_method_title() ?: '-' ) . '</td><td>' . esc_html( Recovery_Items::order_status_label( $s->get_status() ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></section>';
		}
		echo '</div>'; // main

		/* ---------- 右栏 ---------- */
		echo '<aside class="pc-rc-side">';
		$sum = Recovery_Items::customer_summary( $r['email'] );
		$vis = Recovery_Items::visitor( $order );
		echo '<section class="pc-rc-panel"><h3>购买人信息</h3><p class="pc-rc-person"><strong>' . esc_html( Recovery_Items::display_name( $r['name'] ) ?: '-' ) . '</strong></p><dl class="pc-rc-kv">';
		echo '<dt>邮箱</dt><dd>' . ( $r['email'] ? '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>' : '-' ) . '</dd>';
		echo '<dt>电话</dt><dd>' . esc_html( $r['phone'] ?: '-' ) . '</dd>';
		echo '<dt>首次支付时间</dt><dd>' . esc_html( $sum['first_paid'] ? wp_date( 'Y-m-d H:i:s', $sum['first_paid'] ) : '-' ) . '</dd>';
		echo '<dt>最后访问时间</dt><dd>' . esc_html( $vis && $vis['last_ms'] ? self::time( $vis['last_ms'] ) : self::time( (int) $r['created_ms'] ) ) . '</dd>';
		echo '<dt>总计付款订单</dt><dd>' . ( $sum['orders'] ? (int) $sum['orders'] . ' 单' : '-' ) . '</dd>';
		echo '<dt>总计消费金额</dt><dd>' . ( $sum['orders'] ? self::money( $sum['spent'], $cur ) : '-' ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>顾客账号</dt><dd>' . ( $order->get_customer_id() ? '<a href="' . esc_url( get_edit_user_link( $order->get_customer_id() ) ) . '">已注册</a>' : '游客' ) . '</dd>';
		echo '</dl></section>';

		echo '<section class="pc-rc-panel"><h3>订单基础信息</h3><dl class="pc-rc-kv">';
		echo '<dt>支付方式</dt><dd>' . esc_html( $r['pay_method'] ?: '-' ) . '</dd>';
		echo '<dt>配送方式</dt><dd>' . esc_html( $r['ship_method'] ?: '-' ) . '</dd>';
		echo '<dt>下单类型</dt><dd>' . esc_html( $order->get_created_via() === 'store-api' || $order->get_created_via() === 'checkout' ? '购物车结账' : ( $order->get_created_via() ?: '-' ) ) . '</dd>';
		echo '<dt>结账流程</dt><dd>单页结账</dd>';
		echo '<dt>域名来源</dt><dd>' . esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</dd>';
		echo '<dt>顾客 IP</dt><dd>' . esc_html( $order->get_customer_ip_address() ?: '-' ) . '</dd>';
		echo '</dl></section>';

		echo '<section class="pc-rc-panel"><h3>订单备注</h3><dl class="pc-rc-kv">';
		echo '<dt>买家备注</dt><dd>' . esc_html( $order->get_customer_note() ?: '-' ) . '</dd></dl>';
		$notes = Recovery_Items::notes( $order );
		echo '<p class="pc-rc-kv__title">卖家 / 系统备注</p>';
		if ( ! $notes ) {
			echo '<p class="pc-pl-muted">-</p>';
		} else {
			echo '<ul class="pc-rc-notes">';
			foreach ( array_slice( $notes, 0, 8 ) as $n ) {
				echo '<li><span class="pc-pl-muted">' . esc_html( $n['time'] ? wp_date( 'm-d H:i', $n['time'] ) : '' ) . '</span> ' . esc_html( $n['text'] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		self::attribution_panels( $vis, Recovery_Items::attribution( $order ) );
		echo '</aside></div></div>';
	}

	/** 没下单的结账（弃单记录）详情。 */
	private static function render_record( int $id ): void {
		$rec = Recovery::get_record( $id );
		if ( ! $rec ) {
			echo '<div class="wrap pc-pl pc-rc"><p>找不到这条弃单。</p><p><a href="' . esc_url( self::url() ) . '">返回弃单列表</a></p></div>';
			return;
		}
		$r      = Recovery_Items::record_item( $rec );
		$labels = Recovery_Items::status_labels();
		$cur    = $r['currency'];
		$msg    = sanitize_key( wp_unslash( $_GET['pc_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap pc-pl pc-rc pc-rc-detail">';
		if ( 'voided' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>已作废，不再召回。</p></div>';
		}
		echo '<div class="pc-rc-detail__top"><a class="pc-rc-back" href="' . esc_url( self::url() ) . '"><span class="dashicons dashicons-arrow-left-alt"></span> 返回弃单列表</a><div class="pc-rc-detail__actions">';
		echo '<a class="button pc-rc-lang" href="' . esc_url( add_query_arg( 'lang', 'en' === get_user_meta( get_current_user_id(), 'pc_recovery_lang', true ) ? 'zh' : 'en' ) ) . '" title="切换这一页的显示语言">' . ( 'en' === get_user_meta( get_current_user_id(), 'pc_recovery_lang', true ) ? '中文' : 'English' ) . '</a>';
		echo '<a class="button" href="' . esc_url( Recovery::recover_url( $rec ) ) . '" target="_blank" rel="noopener">恢复购物车链接</a>';
		echo '<a class="button button-primary" href="' . esc_url( Mail_Page::compose_url( array( 'to' => $r['email'] ) ) ) . '">写邮件召回</a>';
		if ( in_array( $rec['status'], Recovery::OPEN, true ) ) {
			echo '<a class="button pc-rc-danger" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_recovery_void&rec=1&id=' . $id ), 'pc_recovery_void' ) ) . '">作废</a>';
		}
		echo '</div></div><div class="pc-rc-grid"><div class="pc-rc-main">';
		echo '<section class="pc-rc-panel"><header class="pc-rc-panel__head"><div class="pc-rc-title"><strong>未下单的结账</strong>' . self::badge( '未完成', 'is-open' ) . self::badge( '未支付', 'is-warn' ) . self::badge( $labels[ $r['status'] ], 'is-' . $r['status'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="pc-rc-meta"><span>记录时间：' . esc_html( self::time( (int) $rec['created_ms'] ) ) . '</span><span>最后改购物车：' . esc_html( self::time( (int) $rec['changed_ms'] ) ) . '</span></div></header>';
		echo '<table class="pc-rc-lines"><thead><tr><th>商品名称</th><th class="pc-rc-right">单价 × 数量</th><th class="pc-rc-right">总价</th></tr></thead><tbody>';
		foreach ( (array) json_decode( (string) $rec['cart'], true ) as $i ) {
			$p   = wc_get_product( ! empty( $i['variation_id'] ) ? (int) $i['variation_id'] : (int) ( $i['product_id'] ?? 0 ) );
			$qty = max( 1, (float) ( $i['quantity'] ?? 1 ) );
			$sub = (float) ( $i['line_subtotal'] ?? 0 );
			$img = $p ? (string) wp_get_attachment_image_url( (int) $p->get_image_id(), 'thumbnail' ) : '';
			echo '<tr><td><div class="pc-rc-line">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="">' : '<span class="pc-rc-line__noimg"></span>' ) . '<div><strong>' . esc_html( $p ? $p->get_name() : '#' . (int) ( $i['product_id'] ?? 0 ) ) . '</strong>' . ( $p && $p->get_sku() ? '<div class="pc-pl-muted">SKU: ' . esc_html( $p->get_sku() ) . '</div>' : '' );
			foreach ( (array) ( $i['variation'] ?? array() ) as $k => $v ) {
				echo '<div class="pc-pl-muted">' . esc_html( wc_attribute_label( str_replace( 'attribute_', '', (string) $k ) ) . ': ' . $v ) . '</div>';
			}
			echo '</div></div></td><td class="pc-rc-right">' . self::money( $sub / $qty, $cur ) . ' × ' . esc_html( (string) wc_stock_amount( $qty ) ) . '</td><td class="pc-rc-right">' . self::money( $sub, $cur ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$tot = Recovery_Items::session_data( (string) $rec['session_key'] )['totals'];
		echo '</tbody></table><dl class="pc-rc-totals"><dt>商品价格</dt><dd>' . self::money( (float) $rec['subtotal'], $cur ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( array( 'shipping_total' => '运费' . ( $r['ship_method'] ? '（' . $r['ship_method'] . '）' : '' ), 'discount_total' => '优惠', 'fee_total' => '手续费', 'total_tax' => '税费' ) as $k => $label ) {
			if ( isset( $tot[ $k ] ) && ( 0.0 !== (float) $tot[ $k ] || 'shipping_total' === $k ) ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . self::money( ( 'discount_total' === $k ? -1 : 1 ) * (float) $tot[ $k ], $cur ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		if ( '' !== (string) $rec['total'] ) {
			echo '<dt class="pc-rc-totals__sum">结账页总价</dt><dd class="pc-rc-totals__sum">' . self::money( (float) $rec['total'], $cur ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</dl><p class="pc-pl-muted">顾客填了邮箱和地址但没有点“下单”，所以 WooCommerce 里没有订单，也没有支付记录。</p></section>';
		echo '<section class="pc-rc-panel"><h3>召回记录 <span class="pc-pl-muted">（给这位顾客发过的邮件）</span></h3>';
		if ( ! $r['mails'] ) {
			$why = Recovery::blocked_reason();
			echo '<p class="pc-pl-muted">还没发过邮件。' . ( $why ? ' 自动提醒当前被拦着（' . esc_html( 'address' === $why ? '店铺地址没填' : '发件邮箱没设' ) . '），可以先手写一封。' : '' ) . '</p>';
		} else {
			echo '<table class="pc-rc-mini"><thead><tr><th>任务</th><th>主题</th><th>类型</th><th>状态</th><th>结果</th><th>时间</th></tr></thead><tbody>';
			foreach ( $r['mails'] as $m ) {
				$ok = 'sent' === $m['status'];
				echo '<tr><td>' . esc_html( Recovery_Items::mail_kind_label( (string) $m['kind'] ) ) . '</td><td>' . esc_html( (string) $m['subject'] ) . '</td><td>邮件</td><td>' . self::badge( $ok ? $labels[ $r['status'] ] : '未召回', $ok ? 'is-' . $r['status'] : 'is-muted' ) . '</td><td>' . esc_html( $ok ? '已发出' : '发送失败' ) . '</td><td>' . esc_html( self::time( (int) $m['created_ms'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</section>';
		$sd  = Recovery_Items::session_data( (string) $rec['session_key'] );
		$vis = Recovery_Items::visitor_by_session( (string) $rec['session_key'] );
		self::addresses( $sd['shipping'], $sd['billing'] );
		echo '<section class="pc-rc-panel"><h3>支付信息</h3><dl class="pc-rc-kv"><dt>选的支付方式</dt><dd>' . esc_html( $r['pay_method'] ?: '-' ) . '</dd><dt>流水号</dt><dd>-</dd><dt>支付时间</dt><dd>-</dd></dl><h4>支付记录</h4><p class="pc-pl-muted">暂无支付信息（还没点下单，没有进入支付）</p></section>';
		echo '<section class="pc-rc-panel"><h3>物流信息</h3><p class="pc-pl-muted">暂无物流信息</p></section>';
		echo '</div><aside class="pc-rc-side">';
		$sum = Recovery_Items::customer_summary( $r['email'] );
		echo '<section class="pc-rc-panel"><h3>购买人信息</h3><p class="pc-rc-person"><strong>' . esc_html( Recovery_Items::display_name( $r['name'] ) ?: '-' ) . '</strong></p><dl class="pc-rc-kv">';
		echo '<dt>邮箱</dt><dd><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a></dd><dt>电话</dt><dd>' . esc_html( $r['phone'] ?: '-' ) . '</dd>';
		echo '<dt>首次支付时间</dt><dd>' . esc_html( $sum['first_paid'] ? wp_date( 'Y-m-d H:i:s', $sum['first_paid'] ) : '-' ) . '</dd>';
		echo '<dt>最后访问时间</dt><dd>' . esc_html( $vis && $vis['last_ms'] ? self::time( $vis['last_ms'] ) : self::time( (int) $rec['changed_ms'] ) ) . '</dd>';
		echo '<dt>总计付款订单</dt><dd>' . ( $sum['orders'] ? (int) $sum['orders'] . ' 单' : '-' ) . '</dd>';
		echo '<dt>总计消费金额</dt><dd>' . ( $sum['orders'] ? self::money( $sum['spent'], $cur ) : '-' ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>顾客账号</dt><dd>' . ( (int) $rec['user_id'] ? '<a href="' . esc_url( get_edit_user_link( (int) $rec['user_id'] ) ) . '">已注册</a>' : '游客' ) . '</dd></dl></section>';
		echo '<section class="pc-rc-panel"><h3>订单基础信息</h3><dl class="pc-rc-kv">';
		echo '<dt>支付方式</dt><dd>' . esc_html( $r['pay_method'] ?: '-' ) . '</dd><dt>配送方式</dt><dd>' . esc_html( $r['ship_method'] ?: '-' ) . '</dd>';
		echo '<dt>下单类型</dt><dd>购物车结账（未提交）</dd><dt>结账流程</dt><dd>单页结账</dd>';
		echo '<dt>域名来源</dt><dd>' . esc_html( ( $vis && $vis['host'] ) ? $vis['host'] : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . '</dd></dl></section>';
		echo '<section class="pc-rc-panel"><h3>时间线</h3><dl class="pc-rc-kv">';
		foreach ( array( '记录' => (int) $rec['created_ms'], '最后改购物车' => (int) $rec['changed_ms'], '提醒 1' => (int) $rec['sent1_ms'], '提醒 2' => (int) $rec['sent2_ms'], '提醒 3' => (int) $rec['sent3_ms'], '点了邮件回来' => (int) $rec['clicked_ms'], '付款' => (int) $rec['paid_ms'] ) as $k => $ms ) {
			if ( $ms ) {
				echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( self::time( $ms ) ) . '</dd>';
			}
		}
		echo '</dl></section>';
		self::attribution_panels( $vis, array() );
		echo '</aside></div></div>';
	}

	/** 收货 / 账单地址两栏（可复制）。 */
	private static function addresses( array $ship, array $bill ): void {
		echo '<section class="pc-rc-panel pc-rc-addresses">';
		foreach ( array( 'shipping' => array( '收货地址', $ship ), 'billing' => array( '账单地址', $bill ) ) as $type => $pair ) {
			list( $title, $a ) = $pair;
			$country = WC()->countries->countries[ $a['country'] ?? '' ] ?? ( $a['country'] ?? '' );
			$text    = trim( implode( "\n", array_filter( array( trim( ( $a['first_name'] ?? '' ) . ' ' . ( $a['last_name'] ?? '' ) ), $a['company'] ?? '', $a['address_1'] ?? '', $a['address_2'] ?? '', trim( ( $a['city'] ?? '' ) . ' ' . ( $a['state'] ?? '' ) . ' ' . ( $a['postcode'] ?? '' ) ), $country, $a['phone'] ?? '', $a['email'] ?? '' ) ) ) );
			echo '<div class="pc-rc-address"><header><strong>' . esc_html( $title ) . '</strong><button type="button" class="button-link pc-rc-copy" data-copy="' . esc_attr( $text ) . '">复制</button></header>';
			$rows = array( '姓名' => trim( ( $a['first_name'] ?? '' ) . ' ' . ( $a['last_name'] ?? '' ) ), '公司' => $a['company'] ?? '', '街道' => $a['address_1'] ?? '', '寓所' => $a['address_2'] ?? '', '省市区' => trim( ( $a['state'] ?? '' ) . ' ' . ( $a['city'] ?? '' ) ), '邮编' => $a['postcode'] ?? '', '国家' => $country, '电话' => $a['phone'] ?? '' );
			if ( 'billing' === $type ) {
				$rows['邮箱'] = $a['email'] ?? '';
			}
			echo '<dl>';
			foreach ( $rows as $k => $v ) {
				echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( '' !== trim( (string) $v ) ? $v : '-' ) . '</dd>';
			}
			echo '</dl></div>';
		}
		echo '</section>';
	}

	/** 右栏：订单归因 + 设备信息（订单用 WC 归因 meta，没下单的只用访客记录）。 */
	private static function attribution_panels( ?array $vis, array $att ): void {
		echo '<section class="pc-rc-panel"><h3>订单归因</h3><dl class="pc-rc-kv">';
		$channel  = trim( ( $att['utm_source'] ?? '' ) . ' / ' . ( $att['utm_medium'] ?? '' ), ' /' ) ?: ( $vis['utm'] ?? '' );
		$campaign = ( $att['utm_campaign'] ?? '' ) ?: ( $vis['campaign'] ?? '' );
		$content  = ( $att['utm_content'] ?? '' ) ?: ( $vis['content'] ?? '' );
		$referrer = ( $att['referrer'] ?? '' ) ?: ( $vis['referrer'] ?? '' );
		$landing  = ( $att['landing'] ?? '' ) ?: ( $vis && $vis['landing'] ? 'https://' . ( $vis['host'] ?: wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . $vis['landing'] : '' );
		$source   = ( $att['source_type'] ?? '' ) ?: ( $channel ? '广告 / 推广链接' : ( $referrer ? '外部网站' : ( $vis ? '直接访问' : '' ) ) );
		echo '<dt>来源类型</dt><dd>' . esc_html( $source ?: '-' ) . '</dd>';
		echo '<dt>渠道</dt><dd>' . esc_html( $channel ?: '-' ) . '</dd>';
		echo '<dt>广告系列</dt><dd class="pc-rc-break">' . esc_html( $campaign ?: '-' ) . ( $content ? '<br><span class="pc-pl-muted">' . esc_html( $content ) . '</span>' : '' ) . '</dd>';
		echo '<dt>来路</dt><dd class="pc-rc-break">' . esc_html( $referrer ?: '-' ) . '</dd>';
		echo '<dt>落地页</dt><dd class="pc-rc-break">' . ( $landing ? '<a href="' . esc_url( $landing ) . '" target="_blank" rel="noopener">' . esc_html( $landing ) . '</a>' : '-' ) . '</dd>';
		if ( ! empty( $att['session_start'] ) ) {
			echo '<dt>本次会话</dt><dd>' . esc_html( $att['session_start'] . '，' . (int) ( $att['pages'] ?? 0 ) . ' 页' ) . ( ! empty( $att['sessions'] ) ? '<br><span class="pc-pl-muted">第 ' . (int) $att['sessions'] . ' 次来访</span>' : '' ) . '</dd>';
		}
		echo '</dl></section>';

		echo '<section class="pc-rc-panel"><h3>设备信息</h3><dl class="pc-rc-kv">';
		if ( $vis ) {
			echo '<dt>访客</dt><dd>#' . (int) $vis['id'] . ' <a href="' . esc_url( $vis['url'] ) . '">查看轨迹</a></dd>';
			echo '<dt>设备来源</dt><dd>' . esc_html( $vis['device'] ?: '-' ) . '</dd>';
			echo '<dt>浏览器</dt><dd>' . esc_html( $vis['browser'] ?: '-' ) . '</dd>';
			echo '<dt>来访次数</dt><dd>' . (int) $vis['visits'] . ' 次，本次看了 ' . (int) $vis['pageviews'] . ' 页</dd>';
			echo '<dt>首次 / 最近</dt><dd>' . esc_html( self::time( $vis['first_ms'] ) ) . '<br>' . esc_html( self::time( $vis['last_ms'] ) ) . '</dd>';
			if ( $vis['landing'] ) {
				echo '<dt>进站页面</dt><dd class="pc-rc-break">' . esc_html( $vis['landing'] ) . '</dd>';
			}
		} else {
			$dev = $att['device'] ?? '';
			echo '<dt>设备来源</dt><dd>' . esc_html( $dev ? ( array( 'Mobile' => '手机', 'Desktop' => '电脑', 'Tablet' => '平板' )[ $dev ] ?? $dev ) : '-' ) . '</dd>';
			echo '<dt class="pc-pl-muted">说明</dt><dd class="pc-pl-muted">没有匹配到访客记录</dd>';
		}
		if ( ! empty( $att['user_agent'] ) ) {
			echo '<dt>浏览器标识</dt><dd class="pc-rc-break pc-pl-muted">' . esc_html( $att['user_agent'] ) . '</dd>';
		}
		echo '</dl></section>';
	}

	/** 详情页整页转英文：先处理带数字的句式，再按词表替换；国家名用 WooCommerce 的英文表。 */
	private static function to_english( string $html ): string {
		$html = preg_replace( '/共 (\d+) 单/u', '$1 orders', $html );
		$html = preg_replace( '/(\d+) 次，本次看了 (\d+) 页/u', '$1 visits, $2 pages this time', $html );
		$html = preg_replace( '/第 (\d+) 次来访/u', 'visit #$1', $html );
		$html = preg_replace( '/弃单提醒（第 (\d) 封）/u', 'Cart reminder #$1', $html );
		$html = preg_replace( '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})，(\d+) 页/u', '$1, $2 pages', $html );
		$html = preg_replace( '/(\d+) 单</u', '$1 orders<', $html );
		$countries = array();
		// 店主 2026-11-11：国家显示成了“波多黎各”。i18n/countries.php 里是 __()，后台语言是中文时读出来还是中文；
		// 临时切到英文再读一份英文国家名。
		$switched = function_exists( 'switch_to_locale' ) ? switch_to_locale( 'en_US' ) : false;
		$en_list  = ( new \WC_Countries() )->get_countries();
		if ( $switched ) {
			restore_previous_locale();
		}
		foreach ( WC()->countries->countries as $code => $zh ) {
			if ( isset( $en_list[ $code ] ) && $zh !== $en_list[ $code ] ) {
				$countries[ $zh ] = $en_list[ $code ];
			}
		}
		return strtr( $html, self::en_map() + $countries );
	}

	private static function en_map(): array {
		return array(
			'这次召回现在算什么（召回中 / 召回成功 / 召回失败）' => 'current recall result',
			'顾客填了邮箱和地址但没有点“下单”，所以 WooCommerce 里没有订单，也没有支付记录。' => 'The customer entered their email and address but never clicked “Place order”, so there is no order and no payment record.',
			'暂无支付信息（还没点下单，没有进入支付）' => 'No payment yet (checkout was never submitted)',
			'（给这位顾客发过的邮件）' => '(emails sent to this customer)',
			'（反复下单只算一条弃单，召回信只发一封）' => '(repeat attempts count as one abandoned checkout; one recall email only)',
			'这位顾客的其他未付款订单' => 'Other unpaid orders by this customer',
			'同一顾客反复下单，只算一条弃单，点进去看全部' => 'Repeat attempts by the same customer, counted once',
			'自动提醒当前被拦着（店铺地址没填），可以先手写一封。' => 'Automatic reminders are blocked (store address missing); you can write one manually.',
			'自动提醒当前被拦着（发件邮箱没设），可以先手写一封。' => 'Automatic reminders are blocked (no sender email); you can write one manually.',
			'付款帮助信（自动）' => 'Payment help (automatic)',
			'账单 / 付款链接' => 'Invoice / pay link',
			'广告 / 推广链接' => 'Ads / tracked link',
			'没有匹配到访客记录' => 'No visitor record matched',
			'购物车结账（未提交）' => 'Cart checkout (not submitted)',
			'卖家 / 系统备注' => 'Seller / system notes',
			'弃单已手动作废，不再召回。' => 'Marked void, no further recall.',
			'已作废，不再召回。' => 'Voided, no further recall.',
			'已恢复召回。' => 'Recall restored.',
			'找不到这条弃单。' => 'Abandoned checkout not found.',
			'找不到这张订单。' => 'Order not found.',
			'还没发过邮件。' => 'No emails sent yet.',
			'创建结果待核对' => 'Result pending review',
			'支付页已打开' => 'Payment page opened',
			'首次 / 最近' => 'First / last',
			'恢复购物车链接' => 'Restore-cart link',
			'切换这一页的显示语言' => 'Switch this page’s language',
			'最后访问时间' => 'Last visit',
			'点了邮件回来' => 'Returned via email',
			'首次支付时间' => 'First payment',
			'总计消费金额' => 'Total spent',
			'总计付款订单' => 'Paid orders',
			'暂无物流信息' => 'No shipping information',
			'暂无支付信息' => 'No payment information',
			'最后改购物车' => 'Cart last changed',
			'未下单的结账' => 'Checkout not submitted',
			'返回弃单列表' => 'Back to abandoned checkouts',
			'直接输入网址' => 'Typed URL',
			'订单基础信息' => 'Order details',
			'选的支付方式' => 'Chosen payment method',
			'购物车结账' => 'Cart checkout',
			'优惠券优惠' => 'Coupon discount',
			'浏览器标识' => 'User agent',
			'购买人信息' => 'Customer',
			'结账页总价' => 'Checkout total',
			'写邮件召回' => 'Email customer',
			'单页结账' => 'One-page checkout',
			'设备信息' => 'Device',
			'来访次数' => 'Visits',
			'订单总价' => 'Order total',
			'召回失败' => 'Recall failed',
			'召回成功' => 'Recovered',
			'收货地址' => 'Shipping address',
			'账单地址' => 'Billing address',
			'商品价格' => 'Items',
			'支付信息' => 'Payment',
			'支付记录' => 'Payment attempts',
			'配送方式' => 'Shipping method',
			'召回记录' => 'Recall history',
			'作废订单' => 'Void order',
			'恢复召回' => 'Restore recall',
			'支付失败' => 'Payment failed',
			'记录时间' => 'Recorded',
			'创建时间' => 'Created',
			'支付时间' => 'Paid at',
			'域名来源' => 'Store domain',
			'查看轨迹' => 'View activity',
			'搜索引擎' => 'Search engine',
			'付款链接' => 'Pay link',
			'设备来源' => 'Device type',
			'正在创建' => 'Creating',
			'手动发送' => 'Manual email',
			'物流信息' => 'Shipping',
			'广告系列' => 'Campaign',
			'创建失败' => 'Creation failed',
			'进站页面' => 'Entry page',
			'外部网站' => 'Referral site',
			'本次会话' => 'This session',
			'直接访问' => 'Direct',
			'结账流程' => 'Checkout flow',
			'订单备注' => 'Notes',
			'顾客账号' => 'Account',
			'发送失败' => 'Send failed',
			'后台创建' => 'Created in admin',
			'订单归因' => 'Attribution',
			'买家备注' => 'Customer note',
			'商品名称' => 'Product',
			'来源类型' => 'Source type',
			'下单类型' => 'Order type',
			'支付方式' => 'Payment method',
			'顾客 IP' => 'Customer IP',
			'落地页' => 'Landing page',
			'发送中' => 'Sending',
			'未发货' => 'Not shipped',
			'已发货' => 'Shipped',
			'已完成' => 'Completed',
			'已退款' => 'Refunded',
			'浏览器' => 'Browser',
			'已取消' => 'Cancelled',
			'省市区' => 'State / city',
			'未完成' => 'Incomplete',
			'召回中' => 'Recalling',
			'（当前）' => '(current)',
			'未召回' => 'Not recalled',
			'待确认' => 'On hold',
			'已支付' => 'Paid',
			'已付款' => 'Paid',
			'未下单' => 'Not placed',
			'已发出' => 'Sent',
			'已注册' => 'Registered',
			'已作废' => 'Void',
			'手续费' => 'Fee',
			'未支付' => 'Unpaid',
			'订单页' => 'Open order',
			'待召回' => 'To recall',
			'流水号' => 'Transaction ID',
			'时间线' => 'Timeline',
			'单价 × 数量' => 'Unit × qty',
			'平板' => 'Tablet',
			'电脑' => 'Desktop',
			'手机' => 'Mobile',
			'总价' => 'Total',
			'结果' => 'Result',
			'主题' => 'Subject',
			'作废' => 'Void',
			'运费' => 'Shipping',
			'复制' => 'Copy',
			'已复制' => 'Copied',
			'渠道' => 'Channel',
			'订单' => 'Order',
			'时间' => 'Time',
			'查看' => 'View',
			'任务' => 'Task',
			'访客' => 'Visitor',
			'公司' => 'Company',
			'来路' => 'Referrer',
			'查询' => 'Track',
			'邮件' => 'Email',
			'优惠' => 'Discount',
			'状态' => 'Status',
			'邮箱' => 'Email',
			'类型' => 'Type',
			'税费' => 'Tax',
			'寓所' => 'Apt / suite',
			'电话' => 'Phone',
			'说明' => 'Note',
			'邮编' => 'ZIP',
			'付款' => 'Paid',
			'游客' => 'Guest',
			'姓名' => 'Name',
			'国家' => 'Country',
			'街道' => 'Street',
			'金额' => 'Amount',
			'提醒 1' => 'Reminder 1',
			'提醒 2' => 'Reminder 2',
			'提醒 3' => 'Reminder 3',
			'记录' => 'Recorded',
			'下单时间' => 'Ordered',
			'件商品' => ' items',
			'展开全部 ' => 'Show all ',
			'收起' => 'Show less',
			'付款时间' => 'Paid',
		);
	}
}
