<?php
/**
 * 订单处理中（已付款）邮件（覆盖 WooCommerce emails/customer-processing-order.php）：
 * 店主 2026-11-10：付款成功立刻发的关怀邮件（模板库 05 下单成功感谢）：感谢、重视购物体验、加专员 WhatsApp（实时物流 / 问题 / 建议）、没有 WhatsApp 就回信留联系方式。
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
<?php /* 店主 2026-11-10：付款成功立刻发的关怀邮件，用模板库「05 下单成功感谢」的文案。 */ ?>
<p>Thank you for your order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> (order total: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>). We've received your payment and our team is preparing your shipment.</p>
<p>Your shopping experience truly matters to us. We'd love for you to add our order specialist on WhatsApp — it's the easiest way to:</p>
<ul>
<li>Track your shipment in real time</li>
<li>Ask any questions about your order or products</li>
<li>Share your feedback and suggestions to help us improve</li>
</ul>
<?php echo Chase_Copy::button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p>Don't use WhatsApp? Simply reply to this email with the best way to reach you (phone, Telegram, Signal…), and our team will contact you directly.</p>
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
