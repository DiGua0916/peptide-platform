<?php
/** Browser-local recent product guidance; never a customer profile. */
namespace PeptideCore\Recent;

defined( 'ABSPATH' ) || exit;

final class Config {
	public const VERSION = 1;
	public const KEYS = array( 'version', 'enabled', 'title', 'maxVisible', 'maxStored', 'retentionDays', 'excludeCartProducts' );
	public static function defaults(): array {
		return array( 'version' => 1, 'enabled' => true, 'title' => 'Recently viewed', 'maxVisible' => 2, 'maxStored' => 20, 'retentionDays' => 30, 'excludeCartProducts' => true );
	}
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_is_list( $input ) ) { return self::error( 'config', 'object_required', '最近浏览配置必须是完整对象。' ); }
		foreach ( self::KEYS as $key ) { if ( ! array_key_exists( $key, $input ) ) { return self::error( $key, 'required', '请提供完整配置；缺少字段时不会套用默认开关。' ); } }
		foreach ( array_keys( $input ) as $key ) { if ( ! in_array( $key, self::KEYS, true ) ) { return self::error( (string) $key, 'unknown_field', '包含未支持的配置字段。' ); } }
		$config = array_replace( self::defaults(), $input );
		if ( 1 !== $config['version'] ) { return self::error( 'version', 'invalid_version', '仅支持整数版本 1。' ); }
		foreach ( array( 'enabled', 'excludeCartProducts' ) as $key ) { if ( ! is_bool( $config[ $key ] ) ) { return self::error( $key, 'boolean_required', '开关必须为 true 或 false。' ); } }
		foreach ( array( 'maxVisible' => 2, 'maxStored' => 20, 'retentionDays' => 30 ) as $key => $max ) { if ( ! is_int( $config[ $key ] ) || $config[ $key ] < 1 || $config[ $key ] > $max ) { return self::error( $key, 'invalid_range', sprintf( '%s 必须为 1 至 %d 的整数。', $key, $max ) ); } }
		$title = $config['title'];
		if ( ! is_string( $title ) || 1 !== preg_match( '//u', $title ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<\s*(?:\/?\s*[a-z][^>]*>|!--|!doctype\b|\?)/iu', $title ) ) { return self::error( 'title', 'plain_text_required', '标题仅接受有效的纯文本。' ); }
		if ( class_exists( '\Normalizer' ) ) { $title = \Normalizer::normalize( $title, \Normalizer::FORM_C ); }
		$title = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $title ) );
		if ( preg_match_all( '/./us', $title ) > 100 ) { return self::error( 'title', 'too_long', '标题最多 100 个字符。' ); }
		$config['title'] = $title;
		return $config;
	}
	public static function error( string $field, string $reason, string $message ): \WP_Error {
		return new \WP_Error( 'pc_recently_viewed_invalid', $message, array( 'status' => 400, 'fields' => array( $field => $reason ) ) );
	}
}
