<?php
/**
 * 付款成功弹窗（店主 2026-11-10）。
 *
 * 顾客付款成功后回到店铺首页，弹出“下单成功”：订单号、客服电话、加 WhatsApp 专员（实时跟踪物流、提建议）。
 * 没有 WhatsApp 的顾客点“I don't use WhatsApp”，留下自己的联系方式，客服主动联系；
 * 留下的联系方式写进订单备注，并发邮件给店铺的新订单通知邮箱。
 * 文案在后台“店铺设置 → 付款成功弹窗”里改（顾客看到的都是英文）。
 *
 * 入口：支付插件确认已付款后跳到 /?pc_paid=订单号&pc_key=订单密钥（过滤器 pc_panapay_paid_redirect）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Paid_Popup {

	public const OPTION = 'pc_paid_popup';
	private const CONTACT_META = '_pc_paid_contact';

	public static function register_hooks(): void {
		add_filter( 'pc_panapay_paid_redirect', array( self::class, 'redirect_url' ), 10, 2 );
		add_action( 'wp_footer', array( self::class, 'render' ), 50 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		// 店主 2026-11-10：成功下单立刻发关怀邮件。付款完成流程偶尔会中途被打断（#11841 就没发出），每 5 分钟补发一次。
		add_action( Recovery::CRON, array( self::class, 'care_catchup' ), 40 );
	}

	/** 已付款但还没发出“付款成功 · 关怀”邮件的订单，补发一次（只看开启这个功能之后付款的单）。 */
	public static function care_catchup(): void {
		if ( ! function_exists( 'WC' ) || ! Mail_Outbox::installed() ) {
			return;
		}
		$since = (int) get_option( 'pc_care_since', 0 );
		if ( ! $since ) {
			update_option( 'pc_care_since', time(), false );
			return;
		}
		global $wpdb;
		$t = Mail_Outbox::table();
		foreach ( wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'date_paid' => '>' . max( $since, time() - 2 * DAY_IN_SECONDS ), 'limit' => 50, 'return' => 'objects' ) ) as $order ) {
			if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( '_pc_care_mail' ) || '' === (string) $order->get_billing_email() ) {
				continue;
			}
			if ( class_exists( Test_Orders::class ) && Test_Orders::is_test_order( $order ) ) {
				continue;
			}
			// 用 SQL 查发件记录：这张单的“付款成功”邮件发过没有。
			$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND kind = %s AND status = 'sent'", $order->get_id(), 'wc:customer_processing_order' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $sent ) {
				$emails = WC()->mailer()->get_emails();
				if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
					$emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id(), $order );
					$order->add_order_note( 'Order confirmation email was not sent at payment time; sent automatically now.' );
				}
			}
			$order->update_meta_data( '_pc_care_mail', (string) time() );
			$order->save_meta_data();
		}
	}

	public static function defaults(): array {
		return array(
			'enabled'     => true,
			'title'       => 'Thank you! Your order is confirmed',
			'message'     => "Order #{order} has been placed successfully. We truly value your shopping experience.\nAdd our order specialist on WhatsApp to track your shipment in real time, ask any questions, or share your suggestions with us.",
			'wa_label'    => 'Add us on WhatsApp',
			'wa_text'     => 'Hi! I just placed an order on {store}: {items}. The total is {total} (order #{order}).',
			'phone_label' => 'Customer service: {phone}',
			'no_wa_label' => "I don't use WhatsApp",
			'form_intro'  => 'No problem. Tell us the best way to reach you (phone, email, Telegram, Signal...) and our team will contact you.',
			'form_button' => 'Send',
			'thanks'      => 'Thank you! Our team will reach out to you shortly.',
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$d = self::defaults();
		$out = array();
		foreach ( $d as $k => $v ) {
			$out[ $k ] = 'enabled' === $k ? ( ! array_key_exists( 'enabled', $s ) || ! empty( $s['enabled'] ) ) : ( isset( $s[ $k ] ) && '' !== trim( (string) $s[ $k ] ) ? (string) $s[ $k ] : $v );
		}
		return $out;
	}

	public static function save( array $in ): void {
		$out = array( 'enabled' => ! empty( $in['enabled'] ) );
		foreach ( array_keys( self::defaults() ) as $k ) {
			if ( 'enabled' !== $k ) {
				$out[ $k ] = mb_substr( sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) ), 0, 600 );
			}
		}
		update_option( self::OPTION, $out, false );
	}

	/** 支付插件确认付款后，把顾客带回店铺首页并弹窗。 */
	public static function redirect_url( $url, $order ) {
		if ( ! $order instanceof \WC_Order || ! self::settings()['enabled'] ) {
			return $url;
		}
		return add_query_arg( array( 'pc_paid' => $order->get_id(), 'pc_key' => $order->get_order_key() ), home_url( '/' ) );
	}

	/** 当前请求带的、已付款且密钥正确的订单。 */
	private static function order_from_request(): ?\WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['pc_paid'] ) || empty( $_GET['pc_key'] ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( absint( $_GET['pc_paid'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['pc_key'] ) );
		// phpcs:enable
		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $key ) || ! $order->is_paid() ) {
			return null;
		}
		return $order;
	}

	private static function fill( string $text, \WC_Order $order ): string {
		// 店主 2026-11-10：WhatsApp 预填“我刚刚在平台上下单了什么什么，一共多少钱”。
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items[] = wp_strip_all_tags( $item->get_name() ) . ' x' . (int) $item->get_quantity();
		}
		$total = html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		return strtr( $text, array( '{order}' => $order->get_order_number(), '{phone}' => self::phone(), '{items}' => implode( ', ', $items ), '{total}' => $total, '{store}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
	}

	private static function phone(): string {
		return class_exists( Store_Config::class ) ? trim( (string) Store_Config::get( 'company_phone' ) ) : '';
	}

	private static function whatsapp_url( string $text ): string {
		$link = class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::whatsapp_link() : '';
		return '' === $link ? '' : add_query_arg( 'text', rawurlencode( $text ), $link );
	}

	public static function render(): void {
		if ( is_admin() || ! self::settings()['enabled'] ) {
			return;
		}
		$order = self::order_from_request();
		if ( ! $order ) {
			return;
		}
		$s     = self::settings();
		$wa    = self::whatsapp_url( self::fill( $s['wa_text'], $order ) );
		$phone = self::phone();
		$done  = '' !== (string) $order->get_meta( self::CONTACT_META );
		$msg   = '';
		foreach ( preg_split( '/\n+/', self::fill( $s['message'], $order ) ) as $p ) {
			$msg .= '<p>' . esc_html( $p ) . '</p>';
		}
		?>
<div class="pc-paid" role="dialog" aria-modal="true" aria-labelledby="pc-paid-title" data-pc-paid>
	<div class="pc-paid__box">
		<button type="button" class="pc-paid__x" data-pc-paid-close aria-label="Close">&times;</button>
		<div class="pc-paid__check" aria-hidden="true">&#10003;</div>
		<h2 id="pc-paid-title"><?php echo esc_html( self::fill( $s['title'], $order ) ); ?></h2>
		<div class="pc-paid__msg"><?php echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面逐段转义。 ?></div>
		<?php if ( '' !== $phone ) : ?>
			<p class="pc-paid__phone"><?php echo esc_html( self::fill( $s['phone_label'], $order ) ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $wa ) : ?>
			<a class="pc-paid__wa" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['wa_label'] ); ?></a>
		<?php endif; ?>
		<div class="pc-paid__alt" data-pc-paid-alt>
			<?php if ( $done ) : ?>
				<p class="pc-paid__thanks"><?php echo esc_html( $s['thanks'] ); ?></p>
			<?php else : ?>
				<button type="button" class="pc-paid__link" data-pc-paid-nowa><?php echo esc_html( $s['no_wa_label'] ); ?></button>
				<form class="pc-paid__form" data-pc-paid-form hidden>
					<p><?php echo esc_html( $s['form_intro'] ); ?></p>
					<input type="text" name="contact" maxlength="200" required autocomplete="email" value="<?php echo esc_attr( $order->get_billing_email() ); ?>" aria-label="Your contact">
					<button type="submit"><?php echo esc_html( $s['form_button'] ); ?></button>
					<p class="pc-paid__err" data-pc-paid-err hidden></p>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>
<style>
.pc-paid{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55)}
.pc-paid[hidden]{display:none}
.pc-paid__box{position:relative;width:100%;max-width:420px;max-height:92vh;overflow:auto;background:#fff;color:#171717;border-radius:16px;padding:28px 22px 22px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);font-size:15px;line-height:1.55}
.pc-paid__x{position:absolute;top:8px;right:10px;border:0;background:none;font-size:28px;line-height:1;color:#888;cursor:pointer;padding:6px 10px;min-width:44px;min-height:44px}
.pc-paid__check{width:56px;height:56px;margin:0 auto 12px;border-radius:50%;background:#e8f8ee;color:#16a34a;font-size:30px;line-height:56px;font-weight:700}
.pc-paid h2{font-size:21px;line-height:1.3;margin:0 0 10px;color:#171717}
.pc-paid__msg p{margin:0 0 8px;color:#404040}
.pc-paid__phone{margin:6px 0 14px;font-weight:600}
.pc-paid__wa{display:block;margin:14px 0 8px;padding:14px 16px;border-radius:10px;background:#25D366;color:#fff!important;font-weight:700;text-decoration:none;font-size:16px}
.pc-paid__link{border:0;background:none;color:#555;text-decoration:underline;text-underline-offset:3px;cursor:pointer;font-size:14px;padding:10px;min-height:44px}
.pc-paid__form{margin-top:6px;text-align:left}
.pc-paid__form p{margin:0 0 8px;color:#404040;font-size:14px}
.pc-paid__form input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d4d4d4;border-radius:8px;font-size:16px}
.pc-paid__form button{width:100%;margin-top:8px;padding:12px;border:0;border-radius:8px;background:#171717;color:#fff;font-weight:600;font-size:15px;cursor:pointer;min-height:44px}
.pc-paid__err{color:#b91c1c;font-size:13px;margin-top:6px}
.pc-paid__thanks{margin:10px 0 0;color:#15803d;font-weight:600}
</style>
<script>
(function(){
	var box=document.querySelector('[data-pc-paid]'); if(!box) return;
	function close(){ box.hidden=true; try{ var u=new URL(location.href); u.searchParams.delete('pc_paid'); u.searchParams.delete('pc_key'); history.replaceState(null,'',u.toString()); }catch(e){} }
	box.addEventListener('click',function(e){ if(e.target===box||e.target.closest('[data-pc-paid-close]')) close(); });
	document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
	var nowa=box.querySelector('[data-pc-paid-nowa]'), form=box.querySelector('[data-pc-paid-form]');
	if(nowa&&form){ nowa.addEventListener('click',function(){ nowa.hidden=true; form.hidden=false; form.querySelector('input').focus(); }); }
	if(form){ form.addEventListener('submit',function(e){
		e.preventDefault(); var btn=form.querySelector('button'), err=form.querySelector('[data-pc-paid-err]'); btn.disabled=true; err.hidden=true;
		fetch(<?php echo wp_json_encode( rest_url( 'peptide/v1/paid-contact' ) ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order:<?php echo (int) $order->get_id(); ?>,key:<?php echo wp_json_encode( $order->get_order_key() ); ?>,contact:form.contact.value})})
		.then(function(r){ return r.json().then(function(j){ if(!r.ok) throw new Error(j&&j.message||'Please try again.'); return j; }); })
		.then(function(){ box.querySelector('[data-pc-paid-alt]').innerHTML='<p class="pc-paid__thanks">'+<?php echo wp_json_encode( esc_html( $s['thanks'] ) ); ?>+'</p>'; })
		.catch(function(x){ err.textContent=x.message; err.hidden=false; btn.disabled=false; });
	}); }
})();
</script>
		<?php
	}

	public static function routes(): void {
		register_rest_route(
			'peptide/v1',
			'/paid-contact',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // 用订单密钥校验。
				'callback'            => array( self::class, 'rest_contact' ),
			)
		);
	}

	public static function rest_contact( \WP_REST_Request $r ) {
		$order   = wc_get_order( absint( $r['order'] ) );
		$contact = trim( mb_substr( sanitize_text_field( (string) $r['contact'] ), 0, 200 ) );
		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), (string) $r['key'] ) || ! $order->is_paid() ) {
			return new \WP_Error( 'pc_paid_order', 'This order could not be verified.', array( 'status' => 403 ) );
		}
		if ( '' === $contact ) {
			return new \WP_Error( 'pc_paid_contact', 'Please enter how we can reach you.', array( 'status' => 400 ) );
		}
		if ( '' !== (string) $order->get_meta( self::CONTACT_META ) ) {
			return rest_ensure_response( array( 'ok' => true ) ); // 只收一次，防止重复提交刷邮件。
		}
		$order->update_meta_data( self::CONTACT_META, $contact );
		$order->add_order_note( 'Customer does not use WhatsApp. Preferred contact: ' . $contact . ' — please reach out to the customer.' );
		$order->save();
		$to = array();
		if ( function_exists( 'WC' ) ) {
			$emails = WC()->mailer()->get_emails();
			if ( isset( $emails['WC_Email_New_Order'] ) ) {
				$to = array_filter( array_map( 'trim', explode( ',', (string) $emails['WC_Email_New_Order']->get_recipient() ) ) );
			}
		}
		if ( $to ) {
			wp_mail(
				$to,
				sprintf( 'Order #%s: customer prefers another contact method', $order->get_order_number() ),
				sprintf( "Order #%s (%s %s) has been paid.\n\nThe customer does not use WhatsApp and asked to be contacted here:\n%s\n\nPlease reach out to the customer.\n\nOrder: %s", $order->get_order_number(), $order->get_billing_first_name(), $order->get_billing_last_name(), $contact, $order->get_edit_order_url() )
			);
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
