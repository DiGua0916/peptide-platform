<?php
/**
 * 客服联系方式（SUPPORT-CHAT-01）：后台填写 WhatsApp / Telegram / 邮箱，前台浮动按钮由主题显示。
 * 本类只负责清理、校验和生成公开链接；按钮的样式和交互由主题负责。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Support_Contacts {

	public const POSITIONS = array( 'right', 'left' );

	/** 预填消息的最大长度（WhatsApp 链接里的 text 参数）。 */
	public const MESSAGE_MAX = 300;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'  => false,
			'position' => 'right',
			'whatsapp' => '',
			'telegram' => '',
			'email'    => '',
			'message'  => '',
		);
	}

	/**
	 * WhatsApp 号码：国际格式，只保留数字（8–15 位，含国家代码，不含 +）。无效时返回 null。
	 */
	public static function clean_whatsapp( string $value ): ?string {
		$digits = preg_replace( '/\D+/', '', $value );
		return ( null !== $digits && 1 === preg_match( '/^[1-9]\d{7,14}$/', $digits ) ) ? $digits : null;
	}

	/**
	 * Telegram 用户名：允许输入 @name 或 t.me/name，保存为 name（5–32 位，字母开头，字母数字下划线）。
	 */
	public static function clean_telegram( string $value ): ?string {
		$value = trim( $value );
		$value = (string) preg_replace( '#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/#i', '', $value );
		$value = ltrim( $value, '@' );
		$value = rtrim( $value, '/' );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $value ) ? $value : null;
	}

	public static function clean_email( string $value ): ?string {
		$email = sanitize_email( $value );
		return ( '' !== $email && is_email( $email ) ) ? $email : null;
	}

	/**
	 * 清理设置页提交的客服字段。无效的值会被丢弃并给出提示；没有任何有效渠道时不能启用。
	 *
	 * @param array<string,mixed> $input 表单字段 support_*。
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$out   = self::defaults();
		$error = static function ( string $code, string $message ): void {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( Settings::OPTION, $code, $message );
			}
		};

		$raw = trim( (string) ( $input['support_whatsapp'] ?? '' ) );
		if ( '' !== $raw ) {
			$clean = self::clean_whatsapp( $raw );
			if ( null === $clean ) {
				$error( 'pc_support_whatsapp', __( 'The WhatsApp number was not saved: use the international format with country code, for example +1 555 123 4567.', 'peptide-core' ) );
			}
			$out['whatsapp'] = (string) $clean;
		}

		$raw = trim( (string) ( $input['support_telegram'] ?? '' ) );
		if ( '' !== $raw ) {
			$clean = self::clean_telegram( $raw );
			if ( null === $clean ) {
				$error( 'pc_support_telegram', __( 'The Telegram username was not saved: use 5–32 letters, digits or underscores, for example @vialcove_support.', 'peptide-core' ) );
			}
			$out['telegram'] = (string) $clean;
		}

		$raw = trim( (string) ( $input['support_email'] ?? '' ) );
		if ( '' !== $raw ) {
			$clean = self::clean_email( $raw );
			if ( null === $clean ) {
				$error( 'pc_support_email', __( 'The support email address was not saved because it is not valid.', 'peptide-core' ) );
			}
			$out['email'] = (string) $clean;
		}

		$message        = sanitize_text_field( (string) ( $input['support_message'] ?? '' ) );
		$out['message'] = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, self::MESSAGE_MAX ) : substr( $message, 0, self::MESSAGE_MAX );

		$position        = (string) ( $input['support_position'] ?? 'right' );
		$out['position'] = in_array( $position, self::POSITIONS, true ) ? $position : 'right';

		$out['enabled'] = ! empty( $input['support_enabled'] );
		if ( $out['enabled'] && ! self::channels( $out ) ) {
			$out['enabled'] = false;
			$error( 'pc_support_empty', __( 'The support button was not enabled because no valid contact method was entered.', 'peptide-core' ) );
		}
		return $out;
	}

	/**
	 * 已清理的设置转回表单字段（设置第一次保存时 WordPress 会对清理结果再调用一次 sanitize）。
	 *
	 * @param array<string,mixed> $support
	 * @return array<string,mixed>
	 */
	public static function to_form( array $support ): array {
		return array(
			'support_enabled'  => ! empty( $support['enabled'] ),
			'support_position' => (string) ( $support['position'] ?? 'right' ),
			'support_whatsapp' => (string) ( $support['whatsapp'] ?? '' ),
			'support_telegram' => (string) ( $support['telegram'] ?? '' ),
			'support_email'    => (string) ( $support['email'] ?? '' ),
			'support_message'  => (string) ( $support['message'] ?? '' ),
		);
	}

	/**
	 * 有效渠道及其公开链接，顺序固定：WhatsApp、Telegram、邮箱。
	 *
	 * @param array<string,mixed> $support
	 * @return array<int,array{type:string,label:string,value:string,url:string}>
	 */
	public static function channels( array $support ): array {
		$out     = array();
		$message = trim( (string) ( $support['message'] ?? '' ) );

		$wa = self::clean_whatsapp( (string) ( $support['whatsapp'] ?? '' ) );
		if ( null !== $wa ) {
			$out[] = array(
				'type'  => 'whatsapp',
				'label' => 'WhatsApp',
				'value' => '+' . $wa,
				'url'   => 'https://wa.me/' . $wa . ( '' !== $message ? '?text=' . rawurlencode( $message ) : '' ),
			);
		}

		$tg = self::clean_telegram( (string) ( $support['telegram'] ?? '' ) );
		if ( null !== $tg ) {
			$out[] = array(
				'type'  => 'telegram',
				'label' => 'Telegram',
				'value' => '@' . $tg,
				'url'   => 'https://t.me/' . $tg,
			);
		}

		$email = self::clean_email( (string) ( $support['email'] ?? '' ) );
		if ( null !== $email ) {
			$out[] = array(
				'type'  => 'email',
				'label' => 'Email',
				'value' => $email,
				'url'   => 'mailto:' . $email,
			);
		}
		return $out;
	}

	/**
	 * GET /site 中的 support 字段。关闭或没有渠道时 enabled=false、channels=[]，前台不显示按钮。
	 *
	 * @return array{enabled:bool,position:string,channels:array<int,array<string,string>>}
	 */
	public static function public_view( ?array $support = null ): array {
		$support  = $support ?? ( Settings::all()['support'] ?? array() );
		$support  = is_array( $support ) ? $support : array();
		$channels = self::channels( $support );
		$enabled  = ! empty( $support['enabled'] ) && (bool) $channels;
		$position = (string) ( $support['position'] ?? 'right' );
		return array(
			'enabled'  => $enabled,
			'position' => in_array( $position, self::POSITIONS, true ) ? $position : 'right',
			'channels' => $enabled ? $channels : array(),
		);
	}
}
