<?php
/**
 * 付款失败邮件（覆盖 WooCommerce emails/customer-failed-order.php）：店主追单母版 02「Help Completing Your Payment」。2026-11-10 起不再提 PayPal（已不支持），改为关心询问的语气：问是否遇到付款问题、表示重视购物体验、WhatsApp 按钮放在前面。
 *
 * @package PeptideCore
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use PeptideCore\Emails\Chase_Copy;

defined( 'ABSPATH' ) || exit;

$brand = Chase_Copy::brand();
$wa    = Chase_Copy::whatsapp();

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo esc_html( Chase_Copy::greeting( $order, 'Dear' ) ); ?></p>
<p>We noticed that your order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> wasn't completed, and we just wanted to check in with you. Did you run into any trouble with the payment?</p>
<p>If so, we're sorry for the inconvenience. Our team is happy to help you personally. Just tap below to message us on WhatsApp:</p>
<?php echo Chase_Copy::button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。 ?>
<?php if ( $order->has_status( 'cancelled' ) ) : ?>
<p>Your order was cancelled because the payment wasn't completed in time, but please don't worry: your selection is easy to place again whenever you're ready, and we're glad to help you with it.</p>
<?php endif; ?>
<p>Your shopping experience truly matters to us. If anything didn't go smoothly, or if you have any questions at all about checkout or your order, our customer support team is always here for you. Simply reply to this email or reach us on WhatsApp at <strong><?php echo esc_html( $wa ); ?></strong>, and we'll take care of you.</p>
<p>We're also running a Black Friday promotion, with special pricing on many peptide products. If you're still considering an order, we'd be happy to share the available offers and help you review your selection.</p>
<p>We look forward to hearing from you and helping you complete your order. For your reference, the order details are below.</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
echo Chase_Copy::signature_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。

do_action( 'woocommerce_email_footer', $email );
