<?php
/**
 * 付款失败邮件（覆盖 WooCommerce emails/customer-failed-order.php）：店主追单母版 02「Help Completing Your Payment」。2026-11-10 起不再提 PayPal（已不支持），改为换卡 / 银行确认的通用付款帮助。
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
<p>We're following up on your recent order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> to see whether you need any help completing payment.</p>
<?php if ( $order->has_status( 'cancelled' ) ) : ?>
<p>Your order was cancelled because the payment wasn't completed in time, but your selection is easy to place again whenever you're ready.</p>
<?php endif; ?>
<p>If your payment didn't go through, it's usually something simple: your bank may have declined an online or international transaction, a verification step (such as a code sent to your phone) may not have been completed, or a card detail may have been entered incorrectly.</p>
<p>Here's what usually helps:</p>
<ul>
<li><?php echo $order->has_status( 'cancelled' ) ? 'Place the order again on our website.' : 'Try the payment again.'; ?></li>
<li>Use a different card.</li>
<li>Contact your bank and ask them to approve the transaction, then try again.</li>
</ul>
<p>If it still doesn't work, message us on WhatsApp at <strong><?php echo esc_html( $wa ); ?></strong> and we'll help you complete your order quickly.</p>
<p>Your experience with <?php echo esc_html( $brand ); ?> matters to us. If you have any questions about checkout or your order, please let us know—we're happy to help.</p>
<p>We're also running a Black Friday promotion, with special pricing on many peptide products. If you're still considering an order, we'd be happy to share the available offers and help you review your selection.</p>
<?php echo Chase_Copy::button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。 ?>
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
