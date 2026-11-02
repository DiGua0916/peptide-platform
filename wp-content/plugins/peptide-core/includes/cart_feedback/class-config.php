<?php
/** Configuration only; WooCommerce remains the sole add-to-cart writer. */
namespace PeptideCore\Cart_Feedback;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const VERSION = 1;
	public const MAX_TEXT_LENGTH = 60;
	public const KEYS = array( 'version', 'enabled', 'successDurationMs', 'bagPulseEnabled', 'successText', 'pendingText' );

	public static function defaults(): array {
		return array( 'version' => 1, 'enabled' => true, 'successDurationMs' => 1400, 'bagPulseEnabled' => true, 'successText' => 'Added', 'pendingText' => 'Adding…' );
	}

	/** PUT/publish replace a full configuration; a missing guard cannot inherit an enabled default. */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_is_list( $input ) ) { return self::error( 'config', 'object_required', '请提供完整的加购反馈配置对象。' ); }
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) { return self::error( (string) $key, 'unknown_field', '包含未支持的配置；减少动态、原位停留与错误显示不可关闭。' ); }
		}
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) { return self::error( $key, 'required', '请重新读取并提交完整配置。' ); }
		}
		if ( 1 !== $input['version'] ) { return self::error( 'version', 'invalid_version', '仅支持整数版本 1。' ); }
		foreach ( array( 'enabled', 'bagPulseEnabled' ) as $key ) {
			if ( ! is_bool( $input[ $key ] ) ) { return self::error( $key, 'boolean_required', '开关必须为 true 或 false。' ); }
		}
		if ( ! is_int( $input['successDurationMs'] ) || $input['successDurationMs'] < 800 || $input['successDurationMs'] > 2200 ) { return self::error( 'successDurationMs', 'out_of_range', '成功提示时长须为 800 至 2200 毫秒的整数。' ); }
		foreach ( array( 'successText', 'pendingText' ) as $key ) {
			$text = $input[ $key ];
			if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<\s*(?:\/?\s*[a-z][^>]*>|!--|!doctype\b|\?)/iu', $text ) ) { return self::error( $key, 'plain_text_required', '提示文案须为有效的纯文本，不能包含 HTML。' ); }
			if ( class_exists( '\Normalizer' ) ) { $text = \Normalizer::normalize( $text, \Normalizer::FORM_C ); }
			$text = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
			$length = preg_match_all( '/./us', $text );
			if ( $length < 1 || $length > self::MAX_TEXT_LENGTH ) { return self::error( $key, 'invalid_length', '提示文案须为 1 至 60 个字符。' ); }
			$input[ $key ] = $text;
		}
		$out = array();
		foreach ( self::KEYS as $key ) { $out[ $key ] = $input[ $key ]; }
		return $out;
	}

	public static function error( string $field, string $reason, string $message ): \WP_Error {
		return new \WP_Error( 'pc_cart_feedback_invalid', $message, array( 'status' => 400, 'fields' => array( $field => $reason ) ) );
	}
}
