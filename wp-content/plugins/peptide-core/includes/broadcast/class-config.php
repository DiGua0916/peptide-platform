<?php
/** Pure validation for the store-owned product broadcast configuration. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const VERSION = 1;
	public const MAX_ITEMS = 1000;
	public const MAX_TEXT_LENGTH = 500;
	public const MAX_NAME_LENGTH = 120;
	public const KEYS = array( 'version', 'enabled', 'badgeEnabled', 'items', 'keywords', 'name', 'enterMs', 'holdMs', 'exitMs', 'gapMs' );
	public const TIMINGS = array( 'enterMs' => array( 150, 2000 ), 'holdMs' => array( 1500, 15000 ), 'exitMs' => array( 150, 2000 ), 'gapMs' => array( 0, 2000 ) );
	public const TOKENS = array( 'name', 'product', 'specification', 'price', 'sku' );

	/** Defaults fill a draft; no content is enabled or published by this method. */
	public static function defaults(): array {
		return array(
			'version' => 1, 'enabled' => false, 'badgeEnabled' => true, 'name' => '',
			'items' => array( '{product} · Choose a pack', '{specification} · {price}', 'Product details in one place' ),
			'keywords' => array( '{product}', '{price}', 'Product details' ),
			'enterMs' => 550, 'holdMs' => 3200, 'exitMs' => 450, 'gapMs' => 200,
		);
	}

	/** Return a complete canonical configuration, or a field-addressable error. */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || ( $input && array_is_list( $input ) ) ) { return self::error( 'config', 'invalid_type', '播报配置必须是一个对象。' ); }
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) { return self::error( 'config', 'unknown_field', '播报配置包含不支持的字段。' ); }
		}
		$out = array_replace( self::defaults(), $input );
		if ( 1 !== $out['version'] ) { return self::error( 'version', 'unsupported_version', '仅支持 version 为整数 1 的配置。' ); }
		foreach ( array( 'enabled', 'badgeEnabled' ) as $key ) {
			if ( ! is_bool( $out[ $key ] ) ) { return self::error( $key, 'invalid_type', '播报开关必须为 true 或 false。' ); }
		}
		foreach ( self::TIMINGS as $key => $range ) {
			if ( ! is_int( $out[ $key ] ) || $out[ $key ] < $range[0] || $out[ $key ] > $range[1] ) {
				return self::error( $key, 'out_of_range', sprintf( '%s 必须为 %d–%d 范围内的整数毫秒。', $key, $range[0], $range[1] ) );
			}
		}
		$out['name'] = self::clean_text( $out['name'], 'name', self::MAX_NAME_LENGTH );
		if ( is_wp_error( $out['name'] ) ) { return $out['name']; }
		if ( '' === $out['name'] && is_array( $out['items'] ) && array_is_list( $out['items'] ) ) {
			foreach ( $out['items'] as $index => $text ) {
				if ( is_string( $text ) && str_contains( $text, '{name}' ) ) { return self::error( 'items.' . $index, 'name_required', sprintf( '第 %d 条文案使用了 {name}，请填写统一姓名或在 CSV 中提供该行姓名。', $index + 1 ) ); }
			}
		}
		foreach ( array( 'items', 'keywords' ) as $key ) {
			$out[ $key ] = self::normalize_items( $out[ $key ], $key );
			if ( is_wp_error( $out[ $key ] ) ) { return $out[ $key ]; }
		}
		return $out;
	}

	/** Normalize and deduplicate after removing blank entries; keep the first position. */
	public static function normalize_items( $input, string $field = 'items' ) {
		if ( ! is_array( $input ) || ! array_is_list( $input ) ) { return self::error( $field, 'invalid_type', $field . ' 必须是有序文本数组。' ); }
		$result = array(); $seen = array();
		foreach ( $input as $index => $value ) {
			$text = self::clean_text( $value, $field . '.' . $index, self::MAX_TEXT_LENGTH );
			if ( is_wp_error( $text ) ) { return $text; }
			if ( '' === $text ) { continue; }
			$key = self::lower( $text );
			if ( is_wp_error( $key ) ) { return $key; }
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true; $result[] = $text;
			if ( count( $result ) > self::MAX_ITEMS ) { return self::error( $field, 'too_many', $field . ' 去重后最多允许 1,000 条，未应用此次修改。' ); }
		}
		return $result;
	}

	/** Plain text only; reject rather than silently stripping imported HTML. */
	public static function clean_text( $value, string $field, int $limit = self::MAX_TEXT_LENGTH ) {
		if ( ! is_string( $value ) ) { return self::error( $field, 'invalid_type', $field . ' 必须是文本。' ); }
		if ( 1 !== preg_match( '//u', $value ) ) { return self::error( $field, 'invalid_utf8', $field . ' 不是有效 UTF-8 文本。' ); }
		if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { return self::error( $field, 'invalid_control', $field . ' 包含不支持的控制字符。' ); }
		if ( preg_match( '/<(?:\/?[a-z][^>]*>|!--|!doctype\b|\?)/iu', $value ) ) { return self::error( $field, 'html_not_allowed', $field . ' 只接受纯文本，请移除 HTML 或脚本标签。' ); }
		if ( class_exists( '\Normalizer' ) ) {
			$value = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( false === $value ) { return self::error( $field, 'invalid_unicode', $field . ' 无法规范化为 Unicode 文本。' ); }
		} elseif ( preg_match( '/[^\x00-\x7f]/', $value ) ) {
			return self::error( $field, 'unicode_normalizer_unavailable', '服务器需要启用 PHP intl 扩展后才能保存非 ASCII 播报文字。' );
		}
		$value = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $value ) );
		$length = preg_match_all( '/./us', $value );
		if ( false === $length || $length > $limit ) { return self::error( $field, 'too_long', sprintf( '%s 最多允许 %d 个字符，未应用此次修改。', $field, $limit ) ); }
		return $value;
	}

	/** Locale-independent case comparison, matching the demo's lower-case rule. */
	public static function lower( string $value ) {
		if ( function_exists( 'mb_strtolower' ) ) { return mb_strtolower( $value, 'UTF-8' ); }
		if ( preg_match( '/[^\x00-\x7f]/', $value ) ) { return self::error( 'config', 'unicode_case_unavailable', '服务器需要启用 PHP mbstring 扩展后才能按大小写合并播报文字。' ); }
		return strtolower( $value );
	}

	/** Unknown placeholders are literal text, not expressions or executable code. */
	public static function warnings( array $config ): array {
		$out = array(); $seen = array();
		foreach ( array( 'items', 'keywords' ) as $field ) {
			foreach ( $config[ $field ] ?? array() as $index => $text ) {
				preg_match_all( '/\{([^{}]+)\}/u', $text, $matches );
				foreach ( $matches[1] as $token ) {
					$key = $field . ':' . $token;
					if ( in_array( $token, self::TOKENS, true ) || isset( $seen[ $key ] ) ) { continue; }
					$seen[ $key ] = true;
					$out[] = array( 'field' => $field . '.' . $index, 'token' => '{' . $token . '}', 'message' => '未知占位符 {' . $token . '} 将按原文字显示，不执行代码。' );
				}
			}
		}
		return $out;
	}

	public static function error( string $field, string $reason, string $message, array $extra = array() ): \WP_Error {
		return new \WP_Error( 'pc_broadcast_invalid', $message, array_merge( array( 'status' => 400, 'fields' => array( $field => $reason ) ), $extra ) );
	}
}
