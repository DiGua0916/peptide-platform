<?php
/** Fixed, authored contact glyphs. No vendor SVG, arbitrary markup or external asset loading. */
namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Contact_Symbols {

	public static function options(): array {
		return array(
			'phone' => '电话', 'mail' => '邮箱', 'skype' => 'Skype', 'whatsapp' => 'WhatsApp',
			'messenger' => 'Messenger', 'qq' => 'QQ', 'wechat' => 'Wechat', 'line' => 'Line',
			'instagram' => 'Instagram', 'telegram' => 'Telegram', 'location' => '位置', 'hours' => '营业时间',
		);
	}

	public static function render( string $name, int $size ): string {
		$shapes = array(
			'phone' => '<path d="M7 3H4.5A1.5 1.5 0 0 0 3 4.5 16.5 16.5 0 0 0 19.5 21a1.5 1.5 0 0 0 1.5-1.5V17l-5-2-1.5 2a13 13 0 0 1-7.5-7.5L9 8 7 3Z"/>',
			'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
			'skype' => '<path d="M8 3.5a4.5 4.5 0 0 0-5 5 9 9 0 0 0 12.5 12 4.5 4.5 0 0 0 5-5A9 9 0 0 0 8 3.5Z"/><path d="M15.5 8.5c-1-2-6-1.8-6 1 0 3 6 1 6 4 0 3-5 3-7 1"/>',
			'whatsapp' => '<path d="M4 17a9 9 0 1 1 3 3l-4 1 1-4Z"/><path d="m9 7-2 1c0 5 4 8 8 8l1-2-3-1-1 1-2-2 1-1-2-4Z"/>',
			'messenger' => '<path d="M3 11a9 8 0 0 1 18 0 9 8 0 0 1-9 8H9l-4 3v-5a8 8 0 0 1-2-6Z"/><path d="m6 13 4-5 3 3 5-3-4 6-3-3-5 2Z"/>',
			'qq' => '<path d="M7 9C5 4 8 2 12 2s7 2 5 7c3 2 4 6 3 8l-3-2c0 3-2 5-5 5s-5-2-5-5l-3 2c-1-2 0-6 3-8Z"/><path d="M8 21H5m11 0h3M9 8h.01M15 8h.01M9 12l3 1 3-1"/>',
			'wechat' => '<path d="M14 15a7 6 0 1 0-8 0l-3 3 1-4M20 19l1 3-4-2a6 5 0 1 1 3-1Z"/><path d="M7 9h.01M12 9h.01M14 15h.01M18 15h.01"/>',
			'line' => '<path d="M21 10c0 5-5 9-12 11l1-4c-4-.5-7-3-7-7a9 7 0 0 1 18 0Z"/><path d="M7 8v4h2m2-4v4m3 0V8l3 4V8"/>',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
			'telegram' => '<path d="m3 10 18-7-4 18-5-6-4 3v-5l10-7-8 8-7-4Z"/>',
			'location' => '<path d="M19 9c0 6-7 12-7 12S5 15 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2.5"/>',
			'hours' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
		);
		if ( ! isset( $shapes[ $name ] ) ) { return ''; }
		$size = max( 12, min( 64, $size ) );
		return '<svg class="vc-contact-symbol" data-contact-symbol="' . esc_attr( $name ) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shapes[ $name ] . '</svg>';
	}
}
