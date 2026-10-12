<?php
/**
 * 站点级设置（演示模式、用途声明、结账确认项）。设置页在 BE-05 再做，目前只用默认值或直接写 option。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'peptide_core_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// 品牌名只在后台使用（如工作台标题），前台文案由主题负责。
			'brand_name'         => 'VialCove',
			'demo_mode'          => true,
			'demo_notice'        => 'This site is a demonstration. Product, batch and report data are synthetic.',
			// 用途声明与结账确认项的文案由用户提供，未提供时为 null，前端不显示。
			'use_statement_html' => null,
			'checkout_ack'       => array(
				'enabled'    => false,
				'label_html' => null,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_replace_recursive( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * GET /site 的返回内容（只包含可以公开的设置）。
	 *
	 * @return array<string,mixed>
	 */
	public static function public_config(): array {
		$s   = self::all();
		$ack = is_array( $s['checkout_ack'] ) ? $s['checkout_ack'] : array();
		return array(
			'demo_mode'          => (bool) $s['demo_mode'],
			'demo_notice'        => $s['demo_mode'] ? (string) $s['demo_notice'] : null,
			'use_statement_html' => self::html_or_null( $s['use_statement_html'] ),
			'checkout_ack'       => array(
				'enabled'    => ! empty( $ack['enabled'] ) && null !== self::html_or_null( $ack['label_html'] ?? null ),
				'label_html' => self::html_or_null( $ack['label_html'] ?? null ),
			),
		);
	}

	private static function html_or_null( $value ): ?string {
		$value = is_string( $value ) ? trim( wp_kses_post( $value ) ) : '';
		return '' === $value ? null : $value;
	}
}
