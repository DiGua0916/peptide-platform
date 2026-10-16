<?php
/**
 * 站点级设置（品牌名、演示模式、用途声明、结账确认项），在后台“WooCommerce → VialCove 设置”中编辑（BE-05）。
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

	/**
	 * 结账确认项的纯文本文案；未启用或文案为空时返回 null。
	 * WooCommerce 结账复选框的标签只支持纯文本，所以设置页只接受纯文本。
	 */
	public static function checkout_ack_label(): ?string {
		$ack = self::all()['checkout_ack'];
		if ( ! is_array( $ack ) || empty( $ack['enabled'] ) ) {
			return null;
		}
		$label = trim( wp_strip_all_tags( (string) ( $ack['label_html'] ?? '' ) ) );
		return '' === $label ? null : $label;
	}

	/**
	 * 设置页提交内容的清理与校验（register_setting 的 sanitize_callback）。
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$old   = self::all();

		// 第一次保存时 option 还不存在，WordPress 会对已经清理过的结果再调用一次本函数。
		// 已清理结果的形状是 checkout_ack => {enabled, label_html}，这里转回表单字段，保证重复调用结果不变。
		if ( isset( $input['checkout_ack'] ) && is_array( $input['checkout_ack'] ) && ! isset( $input['checkout_ack_label'] ) ) {
			$input['checkout_ack_enabled'] = ! empty( $input['checkout_ack']['enabled'] );
			$input['checkout_ack_label']   = (string) ( $input['checkout_ack']['label_html'] ?? '' );
		}

		$brand = sanitize_text_field( (string) ( $input['brand_name'] ?? '' ) );
		$use   = trim( wp_kses_post( (string) ( $input['use_statement_html'] ?? '' ) ) );
		$label = trim( sanitize_textarea_field( (string) ( $input['checkout_ack_label'] ?? '' ) ) );
		$ack   = ! empty( $input['checkout_ack_enabled'] );

		if ( $ack && '' === $label ) {
			$ack = false;
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'pc_ack_label_missing', __( 'The checkout confirmation was not enabled because its text is empty.', 'peptide-core' ) );
			}
		}

		return array(
			'brand_name'         => '' !== $brand ? $brand : $old['brand_name'],
			'demo_mode'          => ! empty( $input['demo_mode'] ),
			'demo_notice'        => sanitize_text_field( (string) ( $input['demo_notice'] ?? '' ) ),
			'use_statement_html' => '' === $use ? null : $use,
			'checkout_ack'       => array(
				'enabled'    => $ack,
				'label_html' => '' === $label ? null : $label,
			),
		);
	}

	private static function html_or_null( $value ): ?string {
		$value = is_string( $value ) ? trim( wp_kses_post( $value ) ) : '';
		return '' === $value ? null : $value;
	}
}
