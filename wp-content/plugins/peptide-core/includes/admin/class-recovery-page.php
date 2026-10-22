<?php
/**
 * 后台“订单 → 弃单”（CART-RECOVERY-02），版式参照 Shopname 的弃单列表：
 * 状态标签（全部 / 待召回 / 召回中 / 召回失败 / 召回成功 / 已作废）、按时间和订单编号 / 邮箱筛选、
 * 订单编号、收货国家、配送方式、订单总价、支付方式、支付时间、流水号、订单状态、召回状态、邮件发送时间、召回次数。
 * 每行可以展开看商品和提醒时间线，也可以手动作废。提醒邮件的设置在“系统设置 → 邮件通知 → 弃单通知”。
 *
 * 页面是服务器端渲染的，只有展开详情用了几行脚本。需要 manage_woocommerce。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Recovery;

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
		$parent = in_array( Admin_Menus::ORDERS, array_column( (array) $menu, 2 ), true ) ? Admin_Menus::ORDERS : 'woocommerce';
		if ( Admin_Menus::ORDERS === $parent ) {
			add_submenu_page( $parent, __( 'All orders', 'peptide-core' ), __( 'All orders', 'peptide-core' ), 'edit_shop_orders', Admin_Menus::ORDERS );
		}
		add_submenu_page( $parent, __( 'Abandoned checkouts', 'peptide-core' ), __( 'Abandoned checkouts', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, self::SLUG ) ) {
			$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_enqueue_style( 'peptide-core-store-settings', plugins_url( 'assets/store-settings.css', PEPTIDE_CORE_FILE ), array(), $ver( 'store-settings.css' ) );
			wp_enqueue_style( 'peptide-core-plans', plugins_url( 'assets/plans.css', PEPTIDE_CORE_FILE ), array( 'peptide-core-store-settings' ), $ver( 'plans.css' ) );
			wp_add_inline_script( 'common', "document.addEventListener('click',function(e){var b=e.target.closest('.pc-rc-toggle');if(!b)return;var r=document.getElementById(b.getAttribute('aria-controls'));if(r){r.hidden=!r.hidden;b.setAttribute('aria-expanded',r.hidden?'false':'true');}});" );
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
		wp_safe_redirect( self::url( array( 'pc_msg' => 'scanned', 'n' => $r['tracked'], 's' => $r['sent'] ) ) );
		exit;
	}

	public static function handle_void(): void {
		self::guard( 'pc_recovery_void' );
		Recovery::void( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
		exit;
	}

	public static function status_labels(): array {
		return array(
			'pending'   => __( 'Waiting to recall', 'peptide-core' ),
			'recalling' => __( 'Recalling', 'peptide-core' ),
			'failed'    => __( 'Recall failed', 'peptide-core' ),
			'success'   => __( 'Recalled', 'peptide-core' ),
			'void'      => __( 'Voided', 'peptide-core' ),
		);
	}

	private static function note_label( string $note ): string {
		$map = array(
			'clicked'              => __( 'Came back through the email link', 'peptide-core' ),
			'paid'                 => __( 'Paid after a reminder', 'peptide-core' ),
			'paid before reminder' => __( 'Paid before any reminder', 'peptide-core' ),
			'cart emptied'         => __( 'Cart emptied', 'peptide-core' ),
			'session expired'      => __( 'Cart session expired', 'peptide-core' ),
			'no response'          => __( 'No response 24 hours after the last reminder', 'peptide-core' ),
			'unsubscribed'         => __( 'Unsubscribed', 'peptide-core' ),
			'manual'               => __( 'Voided by an admin', 'peptide-core' ),
			'send failed'          => __( 'The last reminder could not be sent', 'peptide-core' ),
		);
		return $map[ $note ] ?? $note;
	}

	private static function time( int $ms ): string {
		return $ms ? wp_date( 'Y-m-d H:i:s', (int) floor( $ms / 1000 ) ) : '—';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$status = in_array( $status, Recovery::STATUSES, true ) ? $status : '';
		$from   = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$to     = sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) );
		$q      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$msg    = sanitize_key( wp_unslash( $_GET['pc_msg'] ?? '' ) );
		// phpcs:enable
		$tz      = wp_timezone();
		$from_ms = $from && strtotime( $from ) ? ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp() * 1000 : 0;
		$to_ms   = $to && strtotime( $to ) ? ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp() * 1000 : 0;
		$list    = Recovery::records( array( 'status' => $status, 'from_ms' => $from_ms, 'to_ms' => $to_ms, 'q' => $q ), $page, self::PER );
		$counts  = Recovery::counts();
		$labels  = self::status_labels();
		$now     = (int) floor( microtime( true ) * 1000 );
		$st      = Recovery::stats( $now - 30 * DAY_IN_SECONDS * 1000, $now + 1000 );

		echo '<div class="wrap pc-pl pc-rc">';
		if ( 'scanned' === $msg ) {
			/* translators: 1: new records, 2: reminders sent */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Scan finished: %1$d new abandoned checkouts, %2$d reminders sent.', 'peptide-core' ), absint( $_GET['n'] ?? 0 ), absint( $_GET['s'] ?? 0 ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<div class="pc-pl-head"><h1>' . esc_html__( 'Abandoned checkouts', 'peptide-core' ) . '</h1>';
		echo '<a class="button-link" href="' . esc_url( Email_Center::url( 'abandoned' ) ) . '"><span class="dashicons dashicons-email-alt"></span> ' . esc_html__( 'Abandoned cart emails', 'peptide-core' ) . '</a>';
		echo '<a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-failed' ) ) . '"><span class="dashicons dashicons-dismiss"></span> ' . esc_html__( 'Failed payments', 'peptide-core' ) . '</a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-pl-new"><input type="hidden" name="action" value="pc_recovery_scan">';
		wp_nonce_field( 'pc_recovery_scan' );
		echo '<button class="button">' . esc_html__( 'Scan now', 'peptide-core' ) . '</button></form></div>';

		$blocked = Recovery::blocked_reason();
		if ( ! Recovery::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'All reminder emails are turned off, so abandoned checkouts are not recorded or recalled.', 'peptide-core' ) . ' <a href="' . esc_url( Email_Center::url( 'abandoned' ) ) . '">' . esc_html__( 'Turn them on', 'peptide-core' ) . '</a></p></div>';
		} elseif ( 'address' === $blocked ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No reminders are sent until the store address is filled in. US law (CAN-SPAM) requires a physical address in marketing emails.', 'peptide-core' ) . ' <a href="' . esc_url( Basic_Config_Page::url() ) . '">' . esc_html__( 'Public contact information', 'peptide-core' ) . '</a></p></div>';
		} elseif ( 'sender' === $blocked ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No reminders are sent until a valid support email is set as the sender.', 'peptide-core' ) . ' <a href="' . esc_url( Basic_Config_Page::url() ) . '">' . esc_html__( 'Basic settings', 'peptide-core' ) . '</a></p></div>';
		}

		/* translators: 1: abandoned, 2: emailed, 3: recalled, 4: rate, 5: amount */
		echo '<p class="pc-pl-muted">' . esc_html( sprintf( __( 'Last 30 days: %1$d abandoned checkouts, %2$d reminded, %3$d recalled (%4$s), recalled orders paid %5$s.', 'peptide-core' ), $st['abandoned'], $st['emailed'], $st['recovered'], null === $st['rate'] ? '—' : $st['rate'] . '%', wp_strip_all_tags( wc_price( $st['recovered_total'] ) ) ) ) . '</p>';

		echo '<div class="pc-pl-card"><nav class="pc-pl-tabs">';
		$tabs = array( '' => __( 'All', 'peptide-core' ) ) + $labels;
		foreach ( $tabs as $k => $label ) {
			$n = '' === $k ? array_sum( $counts ) : ( $counts[ $k ] ?? 0 );
			echo '<a href="' . esc_url( self::url( array_filter( array( 'status' => $k, 'from' => $from, 'to' => $to, 'q' => $q ) ) ) ) . '" class="' . ( $k === $status ? 'is-active' : '' ) . '">' . esc_html( $label ) . ' <span class="pc-pl-muted">(' . (int) $n . ')</span></a>';
		}
		echo '</nav>';
		echo '<form method="get" class="pc-rc-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<label>' . esc_html__( 'Created', 'peptide-core' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> – <input type="date" name="to" value="' . esc_attr( $to ) . '" aria-label="' . esc_attr__( 'To', 'peptide-core' ) . '">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Order number or email (separate several with spaces)', 'peptide-core' ) . '"><button class="button">' . esc_html__( 'Filter', 'peptide-core' ) . '</button></form>';

		echo '<div class="pc-rc-scroll"><table class="pc-pl-table pc-rc-table"><thead><tr>';
		foreach ( array( __( 'Actions', 'peptide-core' ), __( 'Order number', 'peptide-core' ), __( 'Customer', 'peptide-core' ), __( 'Country', 'peptide-core' ), __( 'Shipping method', 'peptide-core' ), __( 'Order total', 'peptide-core' ), __( 'Payment method', 'peptide-core' ), __( 'Paid at', 'peptide-core' ), __( 'Transaction ID', 'peptide-core' ), __( 'Order status', 'peptide-core' ), __( 'Recall status', 'peptide-core' ), __( 'Email sent at', 'peptide-core' ), __( 'Emails sent', 'peptide-core' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $list['items'] ) {
			echo '<tr><td colspan="13" class="pc-pl-empty">' . esc_html__( 'No abandoned checkouts yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $list['items'] as $r ) {
			self::row( $r, $labels );
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $list['total'] / self::PER );
		/* translators: %d: number of records */
		echo '<div class="pc-rc-pager"><span>' . esc_html( sprintf( __( '%d records', 'peptide-core' ), $list['total'] ) ) . '</span>';
		if ( $pages > 1 ) {
			echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) );
		}
		echo '</div></div></div>';
	}

	private static function row( array $r, array $labels ): void {
		$paid  = (int) $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
		$draft = (int) $r['draft_id'] ? wc_get_order( (int) $r['draft_id'] ) : null;
		$order = $paid ? $paid : $draft;
		$num   = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '—';
		$total = '' !== (string) $r['total'] ? (float) $r['total'] : (float) $r['subtotal'];
		$open  = in_array( $r['status'], Recovery::OPEN, true );
		$det   = 'pc-rc-d' . (int) $r['id'];
		$tid   = $paid ? (string) $paid->get_transaction_id() : '';

		echo '<tr>';
		echo '<td class="pc-pl-col-act"><button type="button" class="button-link pc-rc-toggle" aria-expanded="false" aria-controls="' . esc_attr( $det ) . '" aria-label="' . esc_attr__( 'Details', 'peptide-core' ) . '"><span class="dashicons dashicons-visibility"></span></button>';
		if ( $open ) {
			echo '<a class="button-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_recovery_void&id=' . (int) $r['id'] ), 'pc_recovery_void' ) ) . '" aria-label="' . esc_attr__( 'Void', 'peptide-core' ) . '" title="' . esc_attr__( 'Void', 'peptide-core' ) . '"><span class="dashicons dashicons-dismiss"></span></a>';
		}
		echo '</td><td>' . $num . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已转义。
		echo '<td><strong>' . esc_html( '' !== trim( (string) $r['name'] ) ? $r['name'] : '—' ) . '</strong><br><small>' . esc_html( $r['email'] ) . '</small></td>';
		echo '<td>' . esc_html( $r['country'] ? $r['country'] : '—' ) . '</td><td>' . esc_html( $r['ship_method'] ? $r['ship_method'] : '—' ) . '</td>';
		echo '<td>' . wp_kses_post( wc_price( $total, array( 'currency' => $r['currency'] ) ) ) . '</td><td>' . esc_html( $r['pay_method'] ? $r['pay_method'] : '—' ) . '</td>';
		echo '<td>' . esc_html( self::time( (int) $r['paid_ms'] ) ) . '</td><td>' . esc_html( '' !== $tid ? $tid : '—' ) . '</td>';
		echo '<td><span class="pc-rc-badge ' . ( $paid ? 'is-paid' : 'is-open' ) . '">' . esc_html( $paid ? __( 'Paid', 'peptide-core' ) : __( 'Unfinished', 'peptide-core' ) ) . '</span></td>';
		echo '<td><span class="pc-rc-badge is-' . esc_attr( $r['status'] ) . '">' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</span></td>';
		echo '<td>' . esc_html( self::time( (int) $r['last_sent_ms'] ) ) . '</td><td>' . (int) $r['emails'] . '</td></tr>';

		$items = '';
		foreach ( (array) json_decode( (string) $r['cart'], true ) as $i ) {
			$p      = wc_get_product( ! empty( $i['variation_id'] ) ? (int) $i['variation_id'] : (int) $i['product_id'] );
			$items .= '<li>' . esc_html( $p ? $p->get_name() : '#' . (int) $i['product_id'] ) . ' × ' . esc_html( (string) wc_stock_amount( $i['quantity'] ?? 1 ) ) . ' — ' . wp_kses_post( wc_price( (float) ( $i['line_subtotal'] ?? 0 ), array( 'currency' => $r['currency'] ) ) ) . '</li>';
		}
		$timeline = array(
			__( 'Recorded', 'peptide-core' )             => (int) $r['created_ms'],
			__( 'Cart last changed', 'peptide-core' )    => (int) $r['changed_ms'],
			__( 'Reminder 1 sent', 'peptide-core' )      => (int) $r['sent1_ms'],
			__( 'Reminder 2 sent', 'peptide-core' )      => (int) $r['sent2_ms'],
			__( 'Reminder 3 sent', 'peptide-core' )      => (int) $r['sent3_ms'],
			__( 'Admin notified', 'peptide-core' )       => (int) $r['admin_ms'],
			__( 'Came back from email', 'peptide-core' ) => (int) $r['clicked_ms'],
			__( 'Paid', 'peptide-core' )                 => (int) $r['paid_ms'],
		);
		echo '<tr id="' . esc_attr( $det ) . '" class="pc-rc-detail" hidden><td></td><td colspan="12"><div class="pc-rc-detail__grid"><div><strong>' . esc_html__( 'Cart', 'peptide-core' ) . '</strong><ul>' . $items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已转义。
		if ( $paid ) {
			/* translators: 1: order number, 2: amount */
			echo '<p>' . esc_html( sprintf( __( 'Paid order #%1$s: %2$s', 'peptide-core' ), $paid->get_order_number(), wp_strip_all_tags( wc_price( (float) $r['order_total'], array( 'currency' => $r['currency'] ) ) ) ) ) . '</p>';
		}
		echo '</div><div><strong>' . esc_html__( 'Timeline', 'peptide-core' ) . '</strong><ul>';
		foreach ( $timeline as $label => $ms ) {
			if ( $ms ) {
				echo '<li>' . esc_html( $label ) . ': ' . esc_html( self::time( $ms ) ) . '</li>';
			}
		}
		echo '</ul>' . ( $r['note'] ? '<p class="pc-pl-muted">' . esc_html( self::note_label( (string) $r['note'] ) ) . '</p>' : '' ) . '</div></div></td></tr>';
	}
}
