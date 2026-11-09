<?php
/**
 * 订单处理中（已付款）邮件（覆盖 WooCommerce emails/customer-processing-order.php）：
 * 店主追单母版「Thanks for Your Order — Add Us on WhatsApp」；顾客留了电话就只感谢，不催加 WhatsApp。
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
$ask   = Chase_Copy::needs_whatsapp( $order );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo esc_html( Chase_Copy::greeting( $order, 'Dear' ) ); ?></p>
<p>Thank you for your order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> (order total: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>). We've received your payment and will process shipment shortly.</p>
<?php if ( $ask ) : ?>
<p>For faster updates on shipping and any questions about your peptides, please add us on WhatsApp at <strong><?php echo esc_html( $wa ); ?></strong> and send a quick hello. That is the easiest way for us to reach you with tracking and support.</p>
<?php echo Chase_Copy::button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p>We look forward to chatting with you on WhatsApp.</p>
<?php else : ?>
<p>We'll keep a close eye on your order until it reaches you, and we'll send shipping and tracking updates as soon as we have them.</p>
<p>If you have any questions along the way, reply to this email or message us on WhatsApp at <strong><?php echo esc_html( $wa ); ?></strong>. We're here to help.</p>
<?php endif; ?>
<p>Here's a summary of what you ordered:</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
echo '<p>Thank you again for choosing ' . esc_html( $brand ) . '.</p>';
echo Chase_Copy::signature_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义。

do_action( 'woocommerce_email_footer', $email );
