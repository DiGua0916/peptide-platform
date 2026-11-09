<?php
/**
 * 系统自动邮件用店主的追单母版话术（2026-11-09，VialCove-chase-pack）。
 *
 * - 付款失败（WC_Email_Customer_Failed_Order）→ 02「Help Completing Your Payment」；
 * - 付款成功 / 订单处理中（WC_Email_Customer_Processing_Order）→「Thanks for Your Order — Add Us on WhatsApp」
 *   （顾客没留电话时强调加 WhatsApp，留了就只感谢）；
 * - 弃单提醒三阶段（Cart_Recovery_1/2/3）默认文案按 01「A Quick Follow-Up」改写，第三阶段默认关。
 * 两封 WooCommerce 邮件通过 woocommerce_locate_template 指到本插件 templates/emails/ 下的模板。
 * 店名用 Mail_Outbox::brand()（VialCove / VialCovePlus …），发件邮箱是这家店自己的 orders@域名。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

use PeptideCore\Mail_Outbox;

defined( 'ABSPATH' ) || exit;

final class Chase_Copy {

	public const WHATSAPP      = '+1 303 944 4124';
	public const WHATSAPP_LINK = 'https://wa.me/13039444124';
	public const TAGLINE       = 'Peptides are our focus. Your experience is our priority.';

	private const TEMPLATES = array( 'emails/customer-failed-order.php', 'emails/customer-processing-order.php' );

	/** 换母版前的旧默认值：顾客后台里还存着这些旧默认的，视为“没改过”，直接换成新母版。 */
	private const OLD_DEFAULTS = array(
		1 => array( 'subject' => 'Complete your purchase at {site_title}', 'heading' => 'Complete your purchase', 'message' => "You added items to your shopping cart and haven't completed your purchase.\n\nYou can complete it now while they're still available." ),
		2 => array( 'subject' => 'Did you face any problems?', 'heading' => 'Did you face any problems?', 'message' => "You added items to your shopping cart and haven't completed your purchase.\n\nDid you run into a problem during checkout? If so, just reply to this email and we will help right away. If you are still interested, you can complete your order below." ),
		3 => array( 'subject' => 'Your cart is still waiting', 'heading' => 'Still interested?', 'message' => "We noticed you haven't finished your order. Your cart is still saved, so you can pick up right where you left off.\n\nIf anything went wrong at checkout, just reply to this email." ),
	);

	public static function register_hooks(): void {
		add_filter( 'woocommerce_locate_template', array( self::class, 'locate' ), 20, 3 );
		add_filter( 'woocommerce_email_subject_customer_failed_order', array( self::class, 'subject_failed' ), 20, 2 );
		add_filter( 'woocommerce_email_heading_customer_failed_order', static fn() => 'Help completing your payment', 20 );
		add_filter( 'woocommerce_email_subject_customer_processing_order', array( self::class, 'subject_processing' ), 20, 2 );
		add_filter( 'woocommerce_email_heading_customer_processing_order', static fn() => 'Thanks for your order', 20 );
	}

	public static function brand(): string {
		return class_exists( Mail_Outbox::class ) ? Mail_Outbox::brand() : 'VialCove';
	}

	public static function whatsapp(): string {
		return (string) apply_filters( 'pc_chase_whatsapp', self::WHATSAPP );
	}

	public static function team(): string {
		return self::brand() . ' Team';
	}

	/** 这家店的客服邮箱：orders@自己的域名。 */
	public static function store_email(): string {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return 'orders@' . preg_replace( '/^www\./', '', $host );
	}

	public static function locate( $template, $template_name, $template_path ) {
		if ( in_array( (string) $template_name, self::TEMPLATES, true ) ) {
			$own = PEPTIDE_CORE_DIR . '/templates/' . $template_name;
			// 主题自己放了覆盖文件时尊重主题；否则用插件里的追单版本。
			if ( is_readable( $own ) && ( ! is_string( $template ) || false === strpos( $template, '/themes/' ) ) ) {
				return $own;
			}
		}
		return $template;
	}

	public static function subject_failed( $subject, $order ) {
		return self::brand() . ' | Help Completing Your Payment';
	}

	public static function subject_processing( $subject, $order ) {
		return self::brand() . ' | Thanks for Your Order' . ( self::needs_whatsapp( $order ) ? ' — Add Us on WhatsApp' : '' );
	}

	/** 顾客没留电话 → 要强调加 WhatsApp。 */
	public static function needs_whatsapp( $order ): bool {
		return $order instanceof \WC_Order && '' === trim( (string) $order->get_billing_phone() ) && '' === trim( (string) $order->get_shipping_phone() );
	}

	/** 顾客称呼：母版 01/02 用 Dear，03/04 用 Hi。 */
	public static function greeting( ?\WC_Order $order, string $word = 'Dear' ): string {
		$name = $order ? trim( (string) $order->get_billing_first_name() ) : '';
		return $word . ( '' !== $name ? ' ' . $name : ' there' ) . ',';
	}

	/** 母版里的 WhatsApp 按钮。 */
	public static function button_html(): string {
		return '<p style="margin:24px 0"><a href="' . esc_url( self::WHATSAPP_LINK ) . '" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:6px">Chat with us on WhatsApp → ' . esc_html( self::whatsapp() ) . '</a></p>';
	}

	/** 母版签名。 */
	public static function signature_html(): string {
		return '<p>Best regards,<br>' . esc_html( self::team() ) . '<br>WhatsApp: ' . esc_html( self::whatsapp() ) . '<br>Email: ' . esc_html( self::store_email() ) . '</p><p style="color:#6b7280;font-size:13px">' . esc_html( self::TAGLINE ) . '</p>';
	}

	/** 弃单提醒三阶段的默认主题 / 标题 / 正文 / 延时。 */
	public static function defaults_for( int $stage ): array {
		$brand = self::brand();
		$wa    = self::whatsapp();
		if ( 1 === $stage ) {
			return array(
				'subject' => $brand . ' | A Quick Follow-Up on Your Order',
				'heading' => 'A quick follow-up on your order',
				'message' => "We're getting in touch to see whether you need any help completing your order.\n\n"
					. "We tried reaching you on WhatsApp but haven't been able to get in touch, so we're following up by email.\n\n"
					. "Your experience with {$brand} matters to us. If you encountered any difficulties during checkout or have questions about your order, please let us know—we're happy to help.\n\n"
					. "We're also running a Black Friday promotion, with special pricing on many peptide products. If you're still considering an order, we'd be happy to share the available offers and help you review your selection.\n\n"
					. "Could you please reply to this email or message us on WhatsApp at {$wa}? If your WhatsApp number has changed, please let us know the best number to reach you.\n\n"
					. 'For your reference, the items you selected are listed below.',
				'delay'   => 300,
			);
		}
		if ( 2 === $stage ) {
			return array(
				'subject' => $brand . ' | Still Need a Hand With Your Order?',
				'heading' => 'Still need a hand with your order?',
				'message' => "We're following up once more in case our earlier note was missed—your order is still saved and waiting for you.\n\n"
					. "If anything went wrong at checkout, or if you'd prefer to pay another way, just reply to this email or message us on WhatsApp at {$wa} and we'll sort it out right away.\n\n"
					. 'Your order details are below.',
				'delay'   => 1800,
			);
		}
		return array(
			'subject' => $brand . ' | Last Reminder About Your Order',
			'heading' => 'Last reminder about your order',
			'message' => "This is our last reminder about the order you started with {$brand}. Your cart is still saved for now.\n\n"
				. "If you've decided not to go ahead, no action is needed. If we can help with anything, reply to this email or reach us on WhatsApp at {$wa}.",
			'delay'   => 86400,
		);
	}

	/** 顾客后台里还存着旧默认文案的，清掉让新母版生效（店主自己改过的不动）。 */
	public static function migrate( \WC_Email $email, int $stage ): void {
		if ( ! is_array( $email->settings ) || ! isset( self::OLD_DEFAULTS[ $stage ] ) ) {
			return;
		}
		$changed = false;
		foreach ( self::OLD_DEFAULTS[ $stage ] as $key => $old ) {
			if ( isset( $email->settings[ $key ] ) && trim( str_replace( "\r\n", "\n", (string) $email->settings[ $key ] ) ) === trim( $old ) ) {
				unset( $email->settings[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			if ( 3 === $stage && 'yes' === ( $email->settings['enabled'] ?? 'yes' ) ) {
				$email->settings['enabled'] = 'no'; // 没改过文案的店，第三封按新默认关掉。
				$email->enabled             = 'no';
			}
			update_option( $email->get_option_key(), $email->settings, 'yes' );
		}
	}
}
