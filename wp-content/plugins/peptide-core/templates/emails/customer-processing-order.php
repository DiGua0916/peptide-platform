<?php
/**
 * 订单处理中（已付款）邮件（覆盖 WooCommerce emails/customer-processing-order.php）：
 * - 普通订单（< $600）：保持原版关怀与物流跟进邮件；
 * - 大额订单（>= $600，阈值可通过 pc_vip_order_threshold 配置）：自动切换为 Priority Client 专属留白版感谢信，
 *   不写死具体奖励，点明“基于本次采购规模已为您预留定制化的优惠方案与账户礼遇政策”，引导及时联系专属客户经理 WhatsApp。
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

$brand         = Chase_Copy::brand();
$wa            = Chase_Copy::whatsapp();
$wa_base       = Chase_Copy::whatsapp_link();
$vip_threshold = (float) get_option( 'pc_vip_order_threshold', 600 );
$order_total   = (float) $order->get_total();
$is_vip        = $vip_threshold > 0 && $order_total >= $vip_threshold;
$total_plain   = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo esc_html( Chase_Copy::greeting( $order, 'Dear' ) ); ?></p>
<p>Thank you for your order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> (order total: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>). We've received your payment and our team is preparing your shipment.</p>

<?php if ( $is_vip ) :
	$vip_msg  = 'Hi ' . $brand . ', checking in for my Priority Client order #' . $order->get_order_number() . ' (' . $total_plain . ').';
	$vip_href = $wa_base . ( str_contains( $wa_base, '?' ) ? '&' : '?' ) . 'text=' . rawurlencode( $vip_msg );
	?>
<div style="margin:22px 0;padding:18px 20px;border-radius:12px;background:#f0fdfa;border:1.5px solid #0d9488;color:#0f2e36">
	<p style="margin:0 0 8px;font-size:15px;font-weight:700;color:#0b5d66">✦ Priority Client Access Unlocked</p>
	<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#133840">With this order of <strong><?php echo esc_html( $total_plain ); ?></strong>, you now qualify for our <strong>Priority Client Program</strong>. Please reach out to your dedicated account manager on WhatsApp at your earliest convenience — we have tailored pricing arrangements and special account privileges available based on your current order volume.</p>
	<p style="margin:0"><a href="<?php echo esc_url( $vip_href ); ?>" style="display:inline-block;background:#0e7484;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px">Connect with My Account Manager on WhatsApp (#<?php echo esc_html( $order->get_order_number() ); ?>) →</a></p>
</div>
<p>Your account manager can also assist you with priority shipment tracking and any product questions along the way.</p>
<?php else : ?>
<p>Your shopping experience truly matters to us. We'd love for you to add our order specialist on WhatsApp — it's the easiest way to:</p>
<ul>
<li>Track your shipment in real time</li>
<li>Ask any questions about your order or products</li>
<li>Share your feedback and suggestions to help us improve</li>
</ul>
<?php echo Chase_Copy::button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php endif; ?>

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
