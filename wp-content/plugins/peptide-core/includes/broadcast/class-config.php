<?php
/** Pure validation for the store-owned product broadcast configuration. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const VERSION = 2;
	public const MAX_ITEMS = 1000;
	public const MAX_TEXT_LENGTH = 500;
	public const MAX_NAME_LENGTH = 120;
	public const MAX_COLUMNS = 32;
	public const MAX_PARAMETER_VALUES = 2000;
	public const KEYS = array( 'version', 'enabled', 'badgeEnabled', 'items', 'keywords', 'parameters', 'parameterMode', 'enterMs', 'holdMs', 'exitMs', 'gapMs' );
	public const TIMINGS = array( 'enterMs' => array( 150, 2000 ), 'holdMs' => array( 1500, 15000 ), 'exitMs' => array( 150, 2000 ), 'gapMs' => array( 0, 2000 ) );
	public const TOKENS = array( 'product', 'specification', 'price', 'sku' );

	/** Defaults fill a draft; no content is enabled or published by this method. */
	public static function defaults(): array {
		return array(
			'version' => self::VERSION, 'enabled' => false, 'badgeEnabled' => true, 'parameters' => array(), 'parameterMode' => 'random',
			'items' => array( '{product} · Choose a pack', '{specification} · {price}', 'Product details in one place' ),
			'keywords' => array( '{product}', '{price}', 'Product details' ),
			'enterMs' => 550, 'holdMs' => 3200, 'exitMs' => 450, 'gapMs' => 200,
		);
	}

	/** Return a complete canonical configuration, or a field-addressable error. */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || ( $input && array_is_list( $input ) ) ) { return self::error( 'config', 'invalid_type', '播报配置必须是一个对象。' ); }
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, array_merge( self::KEYS, array( 'name' ) ), true ) ) { return self::error( 'config', 'unknown_field', '播报配置包含不支持的字段。' ); }
		}
		$out = array_replace( self::defaults(), $input );
		if ( ! in_array( $out['version'], array( 1, self::VERSION ), true ) ) { return self::error( 'version', 'unsupported_version', '仅支持 version 为整数 1 或 2 的配置。' ); }
		$out['version'] = self::VERSION;
		if ( ! in_array( $out['parameterMode'], array( 'random', 'ordered' ), true ) ) { return self::error( 'parameterMode', 'invalid_mode', '参数抽取方式只能为 random（随机）或 ordered（按顺序）。' ); }
		foreach ( array( 'enabled', 'badgeEnabled' ) as $key ) {
			if ( ! is_bool( $out[ $key ] ) ) { return self::error( $key, 'invalid_type', '播报开关必须为 true 或 false。' ); }
		}
		foreach ( self::TIMINGS as $key => $range ) {
			if ( ! is_int( $out[ $key ] ) || $out[ $key ] < $range[0] || $out[ $key ] > $range[1] ) {
				return self::error( $key, 'out_of_range', sprintf( '%s 必须为 %d–%d 范围内的整数毫秒。', $key, $range[0], $range[1] ) );
			}
		}
		$out['parameters'] = self::normalize_parameters( $out['parameters'] );
		if ( is_wp_error( $out['parameters'] ) ) { return $out['parameters']; }
		// Keep old stored/backup configurations readable without a write-on-read migration.
		if ( array_key_exists( 'name', $out ) ) {
			$legacy_name = self::clean_text( $out['name'], 'name', self::MAX_NAME_LENGTH );
			if ( is_wp_error( $legacy_name ) ) { return $legacy_name; }
			if ( '' !== $legacy_name && ! in_array( 'name', array_column( $out['parameters'], 'key' ), true ) ) { $out['parameters'][] = array( 'key' => 'name', 'values' => array( $legacy_name ) ); }
			unset( $out['name'] );
			$out['parameters'] = self::normalize_parameters( $out['parameters'] );
			if ( is_wp_error( $out['parameters'] ) ) { return $out['parameters']; }
		}
		foreach ( array( 'items', 'keywords' ) as $key ) {
			$out[ $key ] = self::normalize_items( $out[ $key ], $key );
			if ( is_wp_error( $out[ $key ] ) ) { return $out[ $key ]; }
		}
		$playback = Playback::compile( $out );
		return is_wp_error( $playback ) ? $playback : $out;
	}

	/** Only import may fill empty editing placeholders; all keys, values and limits remain validated. */
	public static function normalize_for_import( $input ) {
		if ( is_array( $input ) && array_key_exists( 'parameters', $input ) ) {
			$parameters = self::parameter_columns( $input['parameters'], true );
			if ( is_wp_error( $parameters ) ) { return $parameters; }
			$input['parameters'] = array_values( array_filter( $parameters, static fn( array $column ): bool => (bool) $column['values'] ) );
		}
		return self::normalize( $input );
	}

	/** Independent ordered columns; a parameter table is data, never an expression. */
	public static function normalize_parameters( $input ) {
		return self::parameter_columns( $input, false );
	}

	private static function parameter_columns( $input, bool $allow_empty ) {
		if ( ! is_array( $input ) || ! array_is_list( $input ) ) { return self::error( 'parameters', 'invalid_type', '参数表必须是有序列数组。' ); }
		if ( count( $input ) > self::MAX_COLUMNS ) { return self::error( 'parameters', 'too_many_columns', '最多支持 32 个参数列。' ); }
		$out = array(); $keys = array(); $total = 0;
		foreach ( $input as $index => $column ) {
			$path = 'parameters.' . $index;
			if ( ! is_array( $column ) || array_is_list( $column ) || array_diff( array_keys( $column ), array( 'key', 'values' ) ) ) { return self::error( $path, 'invalid_type', '每个参数列只包含 key 和 values。' ); }
			$key = self::clean_text( $column['key'] ?? null, $path . '.key', 42 );
			if ( is_wp_error( $key ) ) { return $key; }
			if ( str_starts_with( $key, '{' ) && str_ends_with( $key, '}' ) ) { $key = substr( $key, 1, -1 ); }
			if ( ! preg_match( '/^[\p{L}_][\p{L}\p{N}_-]{0,39}$/uD', $key ) || in_array( strtolower( $key ), array( '__proto__', 'constructor', 'prototype' ), true ) ) { return self::error( $path . '.key', 'invalid_key', '参数名最多 40 字，以字母或下划线开头，可包含文字、数字、下划线和短横线。' ); }
			if ( isset( $keys[ $key ] ) ) { return self::error( $path . '.key', 'duplicate_key', '参数表存在重复列 {' . $key . '}。' ); }
			$values = self::normalize_items( $column['values'] ?? null, $path . '.values' );
			if ( is_wp_error( $values ) ) { return $values; }
			if ( ! $values && ! $allow_empty ) { return self::error( $path . '.values', 'empty_column', '参数 {' . $key . '} 至少需要一个非空值。' ); }
			$total += count( $values );
			if ( $total > self::MAX_PARAMETER_VALUES ) { return self::error( 'parameters', 'too_many_values', '参数表各列合计最多 2,000 个有效值；单列最多 1,000 个。' ); }
			$keys[ $key ] = true; $out[] = array( 'key' => $key, 'values' => $values );
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
			$key = ! str_ends_with( $field, '.values' ) && preg_match( '/\{[^{}]+\}/u', $text ) ? "\x01" . $text : self::lower( $text );
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
		$known = array_merge( self::TOKENS, array_column( $config['parameters'] ?? array(), 'key' ) );
		foreach ( array( 'items', 'keywords' ) as $field ) {
			foreach ( $config[ $field ] ?? array() as $index => $text ) {
				preg_match_all( '/\{([^{}]+)\}/u', $text, $matches );
				foreach ( $matches[1] as $token ) {
					$key = $field . ':' . $token;
					if ( in_array( $token, $known, true ) || isset( $seen[ $key ] ) ) { continue; }
					$seen[ $key ] = true;
					$out[] = array( 'field' => $field . '.' . $index, 'token' => '{' . $token . '}', 'message' => '参数 {' . $token . '} 尚未提供数据，将按原文字显示；请在参数表添加对应列。' );
				}
			}
		}
		return $out;
	}

	public static function error( string $field, string $reason, string $message, array $extra = array() ): \WP_Error {
		return new \WP_Error( 'pc_broadcast_invalid', $message, array_merge( array( 'status' => 400, 'fields' => array( $field => $reason ) ), $extra ) );
	}
}
