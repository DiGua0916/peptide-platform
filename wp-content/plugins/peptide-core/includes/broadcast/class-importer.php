<?php
/** Transaction-free TXT/CSV/JSON import into an editing draft. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Importer {

	public const MAX_BYTES = 1048576;

	/** Parse and validate everything before returning; this class never persists. */
	public static function parse( string $text, string $filename, array $current, string $mode = 'append', bool $restore = false ) {
		if ( strlen( $text ) > self::MAX_BYTES ) { return Config::error( 'file', 'too_large', '导入文件最大为 1 MiB，未应用此次导入。' ); }
		if ( 1 !== preg_match( '//u', $text ) ) { return Config::error( 'file', 'invalid_utf8', '请使用 UTF-8 编码的导入文件。' ); }
		if ( ! in_array( $mode, array( 'append', 'replace' ), true ) ) { return Config::error( 'mode', 'invalid_format', '导入方式只能为追加或替换。' ); }
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'txt', 'csv', 'json' ), true ) ) { return Config::error( 'file', 'unsupported_format', '只支持 TXT、CSV 或 JSON 文件。' ); }
		if ( $restore && 'json' !== $extension ) { return Config::error( 'restore', 'unsupported_format', '完整配置恢复只支持包含 items 的 JSON 配置对象。' ); }
		$current = Config::normalize( $current );
		if ( is_wp_error( $current ) ) { return $current; }
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) { $text = substr( $text, 3 ); }
		$items = array(); $patch = array(); $lines = array(); $name_applied = false; $blank = 0;
		if ( 'txt' === $extension ) {
			foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $index => $line ) {
				if ( 1 === preg_match( '/\A[\s\p{Z}]*\z/u', $line ) ) { ++$blank; continue; }
				$items[] = $line; $lines[] = $index + 1;
			}
		} elseif ( 'csv' === $extension ) {
			$parsed = self::csv( $text );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$items = $parsed['items']; $lines = $parsed['lines']; $name_applied = $parsed['nameApplied'];
		} else {
			try { $json = json_decode( $text, true, 32, JSON_THROW_ON_ERROR ); }
			catch ( \JsonException $e ) { return Config::error( 'file', 'invalid_json', 'JSON 格式错误，未应用此次导入。', array( 'format' => 'json' ) ); }
			if ( ! is_array( $json ) ) { return Config::error( 'file', 'invalid_json_shape', 'JSON 必须是文本数组或包含 items 的配置对象。' ); }
			$is_object = str_starts_with( ltrim( $text ), '{' );
			if ( $is_object ) {
				if ( ! array_key_exists( 'items', $json ) ) { return Config::error( 'items', 'required', 'JSON 配置对象必须包含 items。' ); }
				foreach ( array_keys( $json ) as $key ) {
					if ( ! in_array( $key, Config::KEYS, true ) ) { return Config::error( 'file', 'unknown_field', 'JSON 配置包含不支持的字段。' ); }
				}
				$items = self::json_items( $json['items'] );
				if ( is_wp_error( $items ) ) { return $items; }
				$json['items'] = $items;
				$validated = Config::normalize( array_replace( $current, $json ) );
				if ( is_wp_error( $validated ) ) { return $validated; }
				if ( $restore ) { $patch = $validated; $mode = 'replace'; }
				else { $patch = array_intersect_key( $json, array_flip( array( 'keywords', 'name', 'badgeEnabled' ) ) ); }
			} else {
				if ( $restore ) { return Config::error( 'restore', 'invalid_json_shape', '完整恢复需要 JSON 配置对象，文本数组请使用导入文案。' ); }
				$items = self::json_items( $json );
				if ( is_wp_error( $items ) ) { return $items; }
			}
		}
		$source_count = 0;
		foreach ( $items as $value ) { if ( is_string( $value ) && 1 !== preg_match( '/\A[\s\p{Z}]*\z/u', $value ) ) { ++$source_count; } }
		if ( '' === ( $patch['name'] ?? $current['name'] ) ) {
			foreach ( $items as $index => $value ) {
				if ( is_string( $value ) && str_contains( $value, '{name}' ) ) { return self::locate( Config::error( 'items.' . $index, 'name_required', '文案使用了 {name}，请填写统一姓名或在 CSV 中提供该行姓名。' ), $lines, $extension ); }
			}
		}
		$normalized = Config::normalize_items( $items );
		if ( is_wp_error( $normalized ) ) { return self::locate( $normalized, $lines, $extension ); }
		$next = array_replace( $current, $patch );
		$next['items'] = 'append' === $mode ? array_merge( $current['items'], $normalized ) : $normalized;
		$next = Config::normalize( $next );
		if ( is_wp_error( $next ) ) { return self::locate( $next, 'replace' === $mode ? $lines : array(), $extension ); }
		$added = 'append' === $mode ? count( $next['items'] ) - count( $current['items'] ) : count( $next['items'] );
		return array(
			'config' => $next,
			'report' => array( 'sourceCount' => $source_count, 'validCount' => count( $normalized ), 'duplicateCount' => $source_count - $added, 'addedCount' => $added, 'totalCount' => count( $next['items'] ), 'blankCount' => $blank, 'nameColumnApplied' => $name_applied, 'mode' => $mode, 'restored' => $restore, 'warnings' => Config::warnings( $next ) ),
		);
	}

	private static function json_items( $input ) {
		if ( ! is_array( $input ) || ! array_is_list( $input ) ) { return Config::error( 'items', 'invalid_type', 'JSON items 必须是有序数组。' ); }
		$out = array();
		foreach ( $input as $index => $item ) {
			if ( is_string( $item ) ) { $out[] = $item; }
			elseif ( is_array( $item ) && ! array_is_list( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) { $out[] = $item['text']; }
			else { return Config::error( 'items.' . $index, 'invalid_type', sprintf( 'JSON 第 %d 条必须是文本或包含文本 text 的对象。', $index + 1 ) ); }
		}
		return $out;
	}

	/** RFC-style CSV scanner: never repair malformed quotes or inconsistent rows. */
	private static function csv( string $text ) {
		$rows = array(); $row = array(); $field = ''; $quoted = false; $closed = false; $line = 1; $start = 1; $length = strlen( $text );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $text[ $i ];
			if ( $quoted ) {
				if ( '"' === $char ) {
					if ( $i + 1 < $length && '"' === $text[ $i + 1 ] ) { $field .= '"'; ++$i; }
					else { $quoted = false; $closed = true; }
				} else { $field .= $char; if ( "\n" === $char || ( "\r" === $char && ( $i + 1 === $length || "\n" !== $text[ $i + 1 ] ) ) ) { ++$line; } }
			} elseif ( ',' === $char ) { $row[] = $field; $field = ''; $closed = false; }
			elseif ( "\r" === $char || "\n" === $char ) {
				if ( "\r" === $char && $i + 1 < $length && "\n" === $text[ $i + 1 ] ) { ++$i; }
				$row[] = $field; $rows[] = array( 'cells' => $row, 'line' => $start ); $row = array(); $field = ''; $closed = false; ++$line; $start = $line;
			} elseif ( '"' === $char ) {
				if ( '' !== $field || $closed ) { return self::csv_error( $line, 'unexpected_quote', '引号位置不正确。' ); }
				$quoted = true;
			} else {
				if ( $closed ) { return self::csv_error( $line, 'trailing_characters', '结束引号后只能是逗号或换行。' ); }
				$field .= $char;
			}
		}
		if ( $quoted ) { return self::csv_error( $start, 'unclosed_quote', '引号未闭合。' ); }
		if ( '' !== $field || $row || $closed ) { $row[] = $field; $rows[] = array( 'cells' => $row, 'line' => $start ); }
		$rows = array_values( array_filter( $rows, static fn( array $record ): bool => (bool) array_filter( $record['cells'], static fn( string $cell ): bool => 1 !== preg_match( '/\A[\s\p{Z}]*\z/u', $cell ) ) ) );
		if ( ! $rows ) { return self::csv_error( 1, 'missing_header', '需要 text 或 content 表头。' ); }
		$header_row = array_shift( $rows ); $header = array();
		foreach ( $header_row['cells'] as $cell ) {
			$key = Config::clean_text( $cell, 'header', 120 ); if ( is_wp_error( $key ) ) { return $key; }
			$key = Config::lower( $key ); if ( is_wp_error( $key ) ) { return $key; }
			if ( in_array( $key, $header, true ) ) { return self::csv_error( $header_row['line'], 'duplicate_header', '存在重复表头。' ); }
			$header[] = $key;
		}
		$column = array_search( 'text', $header, true );
		if ( false === $column ) { $column = array_search( 'content', $header, true ); }
		if ( false === $column ) { return self::csv_error( $header_row['line'], 'missing_header', '需要 text 或 content 表头。' ); }
		$name_column = array_search( 'name', $header, true ); $items = array(); $lines = array();
		foreach ( $rows as $record ) {
			$cells = $record['cells'];
			if ( count( $cells ) !== count( $header ) ) { return self::csv_error( $record['line'], 'column_count', '字段数量与表头不一致。' ); }
			$value = $cells[ $column ];
			if ( false !== $name_column ) {
				$name = Config::clean_text( $cells[ $name_column ], 'name', Config::MAX_NAME_LENGTH );
				if ( is_wp_error( $name ) ) { return self::with_line( $name, $record['line'], 'csv' ); }
				if ( '' === $name && str_contains( $value, '{name}' ) ) { return self::csv_error( $record['line'], 'name_required', '文案使用了 {name}，但本行姓名为空。' ); }
				$value = str_replace( '{name}', $name, $value );
			}
			$items[] = $value; $lines[] = $record['line'];
		}
		return array( 'items' => $items, 'lines' => $lines, 'nameApplied' => false !== $name_column );
	}

	private static function csv_error( int $line, string $reason, string $message ): \WP_Error {
		return Config::error( 'file', $reason, 'CSV 第 ' . $line . ' 行：' . $message, array( 'line' => $line, 'format' => 'csv' ) );
	}

	private static function locate( \WP_Error $error, array $lines, string $format ): \WP_Error {
		$data = (array) $error->get_error_data();
		foreach ( array_keys( $data['fields'] ?? array() ) as $field ) {
			if ( preg_match( '/^items\.(\d+)$/', $field, $match ) && isset( $lines[ (int) $match[1] ] ) ) { return self::with_line( $error, $lines[ (int) $match[1] ], $format ); }
		}
		return $error;
	}

	private static function with_line( \WP_Error $error, int $line, string $format ): \WP_Error {
		return new \WP_Error( $error->get_error_code(), strtoupper( $format ) . ' 第 ' . $line . ' 行：' . $error->get_error_message(), array_merge( (array) $error->get_error_data(), array( 'line' => $line, 'format' => $format ) ) );
	}
}
