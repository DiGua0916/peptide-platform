<?php
/**
 * 店铺后台 → 邮件（2026-11-09）：发件箱 + 手动写信 + 常用模板。
 *
 * - 发件箱：这家店发出的每一封邮件（系统自动的订单通知、弃单提醒，和后台手动写的信），按类型 / 结果筛选，可看正文。
 * - 写邮件：输入收件人（搜索顾客 / 订单号）、选模板、填主题正文、发送。发出去的信也进发件箱。
 * - 自动通知的开关在“店铺设置 → 系统设置 → 邮件通知”，群发在“营销活动 → 邮件活动”，页面上有入口。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Mail_Outbox;

defined( 'ABSPATH' ) || exit;

final class Mail_Page {

	public const SLUG = 'pc-mail';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_post_pc_mail_send', array( self::class, 'send' ) );
		add_action( 'admin_post_pc_mail_template', array( self::class, 'template' ) );
		add_action( 'wp_ajax_pc_mail_search', array( self::class, 'ajax_search' ) );
		add_action( 'wp_ajax_pc_mail_body', array( self::class, 'ajax_body' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		add_menu_page( '邮件', '邮件', self::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-email-alt', 4 );
		add_submenu_page( self::SLUG, '发件箱', '发件箱', self::CAP, self::SLUG, array( self::class, 'render' ) );
		add_submenu_page( self::SLUG, '写邮件', '写邮件', self::CAP, self::SLUG . '-compose', array( self::class, 'render_compose' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function compose_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG . '-compose' ) );
	}

	public static function assets(): void {
		$page = (string) ( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && self::SLUG . '-compose' !== $page ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'pc-mail-page', plugins_url( 'assets/mail-page.css', PEPTIDE_CORE_FILE ), array(), $ver( 'mail-page.css' ) );
		wp_enqueue_script( 'pc-mail-page', plugins_url( 'assets/mail-page.js', PEPTIDE_CORE_FILE ), array(), $ver( 'mail-page.js' ), true );
		wp_localize_script( 'pc-mail-page', 'pcMail', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'pc_mail_ajax' ),
			'templates' => Mail_Outbox::templates(),
		) );
		if ( self::SLUG . '-compose' === $page ) {
			wp_enqueue_editor();
		}
	}

	private static function guard( string $nonce = '' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '你没有发送邮件的权限。', '无权访问', array( 'response' => 403 ) );
		}
		if ( '' !== $nonce ) {
			check_admin_referer( $nonce );
		}
	}

	private static function flash( string $type, string $msg ): void {
		set_transient( 'pc_mail_flash_' . get_current_user_id(), array( $type, $msg ), 120 );
	}

	private static function take_flash(): ?array {
		$f = get_transient( 'pc_mail_flash_' . get_current_user_id() );
		delete_transient( 'pc_mail_flash_' . get_current_user_id() );
		return is_array( $f ) ? $f : null;
	}

	// ---------- 动作 ----------

	public static function send(): void {
		self::guard( 'pc_mail_send' );
		$to = preg_split( '/[\s,;，；]+/u', (string) wp_unslash( $_POST['to'] ?? '' ) );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$res = Mail_Outbox::send_manual( (array) $to, (string) wp_unslash( $_POST['subject'] ?? '' ), (string) wp_unslash( $_POST['body'] ?? '' ), $order_id );
		if ( is_wp_error( $res ) ) {
			set_transient( 'pc_mail_draft_' . get_current_user_id(), array( 'to' => (string) wp_unslash( $_POST['to'] ?? '' ), 'subject' => (string) wp_unslash( $_POST['subject'] ?? '' ), 'body' => (string) wp_unslash( $_POST['body'] ?? '' ), 'order_id' => $order_id ), 600 );
			self::flash( 'error', $res->get_error_message() );
			wp_safe_redirect( self::compose_url() );
			exit;
		}
		self::flash( 'success', '已发送。发件箱里能看到这封信和结果。' );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function template(): void {
		self::guard( 'pc_mail_template' );
		$do = sanitize_key( (string) ( $_POST['do'] ?? 'save' ) );
		if ( 'delete' === $do ) {
			Mail_Outbox::delete_template( sanitize_key( (string) ( $_POST['id'] ?? '' ) ) );
			self::flash( 'success', '模板已删除。' );
		} else {
			$row = Mail_Outbox::save_template( array(
				'id'      => (string) ( $_POST['id'] ?? '' ),
				'name'    => (string) wp_unslash( $_POST['name'] ?? '' ),
				'subject' => (string) wp_unslash( $_POST['subject'] ?? '' ),
				'body'    => (string) wp_unslash( $_POST['body'] ?? '' ),
			) );
			self::flash( 'success', '模板「' . $row['name'] . '」已保存。' );
		}
		wp_safe_redirect( self::compose_url( array( 'tab' => 'templates' ) ) );
		exit;
	}

	public static function ajax_search(): void {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'pc_mail_ajax', 'nonce', false ) ) {
			wp_send_json_error( array(), 403 );
		}
		wp_send_json_success( Mail_Outbox::search_recipients( (string) wp_unslash( $_GET['q'] ?? '' ) ) );
	}

	public static function ajax_body(): void {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'pc_mail_ajax', 'nonce', false ) ) {
			wp_send_json_error( array(), 403 );
		}
		$row = Mail_Outbox::get( absint( $_GET['id'] ?? 0 ) );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => '没有这条记录。' ), 404 );
		}
		$body = (string) ( $row['body'] ?? '' );
		if ( '' === $body ) {
			$body = '<p style="color:#666">正文已过保留期（90 天），只剩标题和结果。</p>';
		} elseif ( false === stripos( $body, '<html' ) && false === stripos( $body, '<table' ) ) {
			$body = wpautop( $body );
		}
		wp_send_json_success( array(
			'subject'   => (string) $row['subject'],
			'recipient' => (string) $row['recipient'],
			'kind'      => Mail_Outbox::kind_label( (string) $row['kind'] ),
			'status'    => (string) $row['status'],
			'error'     => (string) $row['error'],
			'time'      => wp_date( 'Y-m-d H:i:s', (int) floor( (int) $row['created_ms'] / 1000 ) ),
			'body'      => $body,
		) );
	}

	// ---------- 页面 ----------

	private static function head( string $active ): void {
		echo '<div class="wrap pc-mail"><header class="pc-mail__head"><div><h1>邮件</h1><p>这家店发出去的所有邮件都在这里；也可以直接给顾客写信。发件人是店铺自己的域名（由总后台统一发出）。</p></div>';
		echo '<div class="pc-mail__actions"><a class="button button-primary" href="' . esc_url( self::compose_url() ) . '">写邮件</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=email' ) ) . '">自动通知的开关</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Email_Campaigns_Page::SLUG ) ) . '">群发（邮件活动）</a></div></header>';
		echo '<nav class="nav-tab-wrapper pc-mail__tabs"><a class="nav-tab' . ( 'outbox' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '">发件箱</a><a class="nav-tab' . ( 'compose' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::compose_url() ) . '">写邮件</a><a class="nav-tab' . ( 'templates' === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::compose_url( array( 'tab' => 'templates' ) ) ) . '">常用模板</a></nav>';
		$flash = self::take_flash();
		if ( $flash ) {
			echo '<div class="notice notice-' . ( 'error' === $flash[0] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $flash[1] ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		self::head( 'outbox' );
		$stats = Mail_Outbox::stats();
		echo '<dl class="pc-mail__stats"><div><dt>今天已发</dt><dd>' . (int) $stats['today'] . '</dd></div><div><dt>近 7 天已发</dt><dd>' . (int) $stats['week'] . '</dd></div><div class="' . ( $stats['failed_week'] ? 'is-bad' : '' ) . '"><dt>近 7 天失败</dt><dd>' . (int) $stats['failed_week'] . '</dd></div><div><dt>近 7 天手动发送</dt><dd>' . (int) $stats['manual_week'] . '</dd></div></dl>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array(
			'kind'     => sanitize_text_field( (string) ( $_GET['kind'] ?? '' ) ),
			'status'   => sanitize_key( (string) ( $_GET['status'] ?? '' ) ),
			'q'        => sanitize_text_field( (string) wp_unslash( $_GET['q'] ?? '' ) ),
			'order_id' => absint( $_GET['order_id'] ?? 0 ),
		);
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$per  = 40;
		$res  = Mail_Outbox::query( $f, $page, $per );

		echo '<form method="get" class="pc-mail__filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<select name="kind"><option value="">全部类型</option><option value="auto"' . selected( 'auto', $f['kind'], false ) . '>系统自动发的</option><option value="manual"' . selected( 'manual', $f['kind'], false ) . '>手动发送</option></select>';
		echo '<select name="status"><option value="">全部结果</option><option value="sent"' . selected( 'sent', $f['status'], false ) . '>已发出</option><option value="failed"' . selected( 'failed', $f['status'], false ) . '>失败</option><option value="queued"' . selected( 'queued', $f['status'], false ) . '>发送中</option></select>';
		echo '<input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="收件人邮箱或主题"> <button class="button">筛选</button>';
		if ( $f['kind'] || $f['status'] || $f['q'] || $f['order_id'] ) {
			echo ' <a class="button-link" href="' . esc_url( self::url() ) . '">清除</a>';
		}
		echo '</form>';

		echo '<table class="widefat striped pc-mail__table"><thead><tr><th>时间</th><th>类型</th><th>收件人</th><th>主题</th><th>结果</th><th></th></tr></thead><tbody>';
		if ( ! $res['items'] ) {
			echo '<tr><td colspan="6" class="pc-mail__empty">还没有邮件记录。顾客下单、系统发通知或你手动写信后，都会出现在这里。</td></tr>';
		}
		foreach ( $res['items'] as $r ) {
			$status = 'sent' === $r['status'] ? '<span class="pc-mail__badge is-ok">已发出</span>' : ( 'failed' === $r['status'] ? '<span class="pc-mail__badge is-bad">失败</span>' . ( '' !== $r['error'] ? '<small>' . esc_html( $r['error'] ) . '</small>' : '' ) : '<span class="pc-mail__badge">发送中</span>' );
			$kind   = Mail_Outbox::kind_label( (string) $r['kind'] );
			$order  = (int) $r['order_id'] ? ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order_id'] ) ) . '">#' . (int) $r['order_id'] . '</a>' : '';
			echo '<tr><td class="pc-mail__time">' . esc_html( wp_date( 'm-d H:i', (int) floor( (int) $r['created_ms'] / 1000 ) ) ) . '</td><td>' . esc_html( $kind ) . $order . '</td><td>' . esc_html( $r['recipient'] ) . '</td><td>' . esc_html( $r['subject'] ) . '</td><td>' . $status . '</td><td class="pc-mail__ops"><button type="button" class="button-link" data-mail-view="' . (int) $r['id'] . '">查看</button> <a class="button-link" href="' . esc_url( self::compose_url( array( 'to' => $r['recipient'], 'order_id' => (int) $r['order_id'] ) ) ) . '">再写一封</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<div class="pc-mail__pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo $i === $page ? '<span class="is-current">' . $i . '</span>' : '<a href="' . esc_url( self::url( array_filter( array_merge( $f, array( 'paged' => $i ) ) ) ) ) . '">' . $i . '</a>';
			}
			echo '</div>';
		}
		echo '<p class="pc-mail__note">共 ' . (int) $res['total'] . ' 封。记录保留 180 天，正文保留 90 天。“失败”表示没发出去，原因写在后面；失败的信不会自动重发，可以点“再写一封”。</p>';
		self::viewer();
		echo '</div>';
	}

	public static function render_compose(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'compose' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::head( 'templates' === $tab ? 'templates' : 'compose' );
		if ( 'templates' === $tab ) {
			self::render_templates();
			echo '</div>';
			return;
		}
		$draft = get_transient( 'pc_mail_draft_' . get_current_user_id() );
		delete_transient( 'pc_mail_draft_' . get_current_user_id() );
		$draft = is_array( $draft ) ? $draft : array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$to       = (string) ( $draft['to'] ?? sanitize_text_field( (string) wp_unslash( $_GET['to'] ?? '' ) ) );
		$order_id = (int) ( $draft['order_id'] ?? absint( $_GET['order_id'] ?? 0 ) );
		// phpcs:enable
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order instanceof \WC_Order && '' === $to ) {
			$to = (string) $order->get_billing_email();
		}
		$subject = (string) ( $draft['subject'] ?? '' );
		$body    = (string) ( $draft['body'] ?? '' );
		$sender  = self::sender_preview();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mail__compose" id="pc-mail-compose">';
		wp_nonce_field( 'pc_mail_send' );
		echo '<input type="hidden" name="action" value="pc_mail_send">';
		echo '<div class="pc-mail__grid">';
		echo '<label class="pc-mail__field"><span>发件人</span><input type="text" value="' . esc_attr( $sender ) . '" readonly><small>由总后台统一发出，顾客回信会到店铺设置里的客服邮箱。</small></label>';
		echo '<label class="pc-mail__field pc-mail__field--to"><span>收件人</span><input type="text" name="to" id="pc-mail-to" value="' . esc_attr( $to ) . '" placeholder="输入邮箱、顾客名或订单号搜索；多个收件人用逗号隔开" autocomplete="off" required><div class="pc-mail__suggest" id="pc-mail-suggest" hidden></div></label>';
		echo '<label class="pc-mail__field"><span>关联订单（可选）</span><input type="number" name="order_id" id="pc-mail-order" value="' . esc_attr( $order_id ? (string) $order_id : '' ) . '" placeholder="订单号，填了就能用 {order_number} 等占位符"></label>';
		echo '<label class="pc-mail__field"><span>套用模板</span><select id="pc-mail-template"><option value="">不用模板</option>';
		foreach ( Mail_Outbox::templates() as $t ) {
			echo '<option value="' . esc_attr( $t['id'] ) . '">' . esc_html( $t['name'] ) . '</option>';
		}
		echo '</select></label>';
		echo '</div>';
		echo '<label class="pc-mail__field"><span>主题</span><input type="text" name="subject" id="pc-mail-subject" value="' . esc_attr( $subject ) . '" required></label>';
		echo '<div class="pc-mail__field"><span>正文</span>';
		wp_editor( $body, 'pc-mail-body', array( 'textarea_name' => 'body', 'textarea_rows' => 14, 'media_buttons' => false, 'teeny' => false, 'quicktags' => true, 'tinymce' => array( 'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,blockquote,undo,redo', 'toolbar2' => '' ) ) );
		echo '</div>';
		echo '<details class="pc-mail__vars"><summary>可以用的占位符</summary><ul><li><code>{customer_name}</code> 顾客名字（按订单或账号自动填）</li><li><code>{order_number}</code> 订单号 <code>{order_total}</code> 订单金额 <code>{pay_url}</code> 付款链接 <code>{tracking}</code> 物流单号（订单里填过才有）</li><li><code>{store_name}</code> 店铺品牌名 <code>{store_url}</code> 店铺地址 <code>{store_email}</code> 这家店的发件邮箱（orders@域名）</li>
				<li><code>{whatsapp}</code> 店铺后台「客服联系方式」里的 WhatsApp 号</li></ul></details>';
		if ( $order instanceof \WC_Order ) {
			echo '<div class="pc-mail__order"><strong>订单 #' . esc_html( $order->get_order_number() ) . '</strong> · ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ' · ' . wp_kses_post( $order->get_formatted_order_total() ) . ' · ' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ) ) . '">打开订单</a></div>';
		}
		echo '<p class="pc-mail__submit"><button type="submit" class="button button-primary button-hero">发送</button> <a class="button" href="' . esc_url( self::url() ) . '">取消</a></p>';
		echo '</form></div>';
	}

	private static function render_templates(): void {
		echo '<p class="pc-mail__note">常用模板：写邮件时一键套用。主题和正文里可以用占位符，发送时自动替换成顾客和订单的真实信息。内置的 5 套是你 11 月 9 日给的追单母版（弃单跟进、支付问题、成交感谢加 WhatsApp、物流追踪、WhatsApp 联系），发件邮箱已改成各店自己的 orders@ 地址；黑五那段不用时直接删掉那一段即可。</p>';
		echo '<div class="pc-mail__templates">';
		foreach ( Mail_Outbox::templates() as $t ) {
			echo '<details class="pc-mail__tpl"><summary><strong>' . esc_html( $t['name'] ) . '</strong><span>' . esc_html( $t['subject'] ) . '</span></summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'pc_mail_template' );
			echo '<input type="hidden" name="action" value="pc_mail_template"><input type="hidden" name="id" value="' . esc_attr( $t['id'] ) . '">';
			echo '<label class="pc-mail__field"><span>模板名</span><input type="text" name="name" value="' . esc_attr( $t['name'] ) . '" required></label>';
			echo '<label class="pc-mail__field"><span>主题</span><input type="text" name="subject" value="' . esc_attr( $t['subject'] ) . '"></label>';
			echo '<label class="pc-mail__field"><span>正文（HTML）</span><textarea name="body" rows="8">' . esc_textarea( $t['body'] ) . '</textarea></label>';
			echo '<p><button class="button button-primary" name="do" value="save">保存</button> <button class="button-link-delete" name="do" value="delete" onclick="return confirm(\'删除这个模板？\')">删除</button></p></form></details>';
		}
		echo '</div>';
		echo '<details class="pc-mail__tpl pc-mail__tpl--new"><summary><strong>＋ 新建模板</strong></summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pc_mail_template' );
		echo '<input type="hidden" name="action" value="pc_mail_template"><input type="hidden" name="do" value="save">';
		echo '<label class="pc-mail__field"><span>模板名</span><input type="text" name="name" required placeholder="例如：补寄说明"></label>';
		echo '<label class="pc-mail__field"><span>主题</span><input type="text" name="subject" placeholder="Your order {order_number} …"></label>';
		echo '<label class="pc-mail__field"><span>正文（HTML）</span><textarea name="body" rows="8" placeholder="<p>Hi {customer_name},</p>"></textarea></label>';
		echo '<p><button class="button button-primary">保存模板</button></p></form></details>';
	}

	private static function sender_preview(): string {
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$bare  = preg_replace( '/^www\./', '', $host );
		return Mail_Outbox::brand() . ' <orders@' . $bare . '>';
	}

	private static function viewer(): void {
		echo '<div class="pc-mail__viewer" id="pc-mail-viewer" hidden><div class="pc-mail__viewer-box"><header><div><strong id="pc-mail-viewer-subject"></strong><small id="pc-mail-viewer-meta"></small></div><button type="button" class="button" id="pc-mail-viewer-close">关闭</button></header><iframe id="pc-mail-viewer-frame" title="邮件正文" sandbox="allow-same-origin"></iframe></div></div>';
	}
}
