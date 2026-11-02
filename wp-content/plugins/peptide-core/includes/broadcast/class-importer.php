<?php
/** Transaction-free parameter-table import, JSON backup restore and safe legacy text imports. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Importer {

	public const MAX_BYTES = 1048576;
	public const MAX_BACKUP_BYTES = 8388608;

	/** Validate the complete file and editing draft before returning; never persist or publish. */
	public static function parse( string $text, string $filename, array $current, string $mode = 'append', bool $restore = false ) {
		if ( ! in_array( $mode, array( 'append', 'replace' ), true ) ) { return Config::error( 'mode', 'invalid_format', '导入方式只能为追加或替换。' ); }
		if ( strlen( $filename ) > 255 || preg_match( '/[\x00-\x1f\x7f]/', $filename ) ) { return Config::error( 'filename', 'invalid_format', '文件名无效。' ); }
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'txt', 'csv', 'json', 'xlsx' ), true ) ) { return Config::error( 'file', 'unsupported_format', '参数表支持 CSV 或 XLSX；配置备份支持 JSON，旧文案支持 TXT / CSV。不支持 .xls。' ); }
		if ( $restore && 'json' !== $extension ) { return Config::error( 'restore', 'unsupported_format', '完整配置恢复只支持 JSON 配置对象。' ); }
		$current = Config::normalize_for_import( $current ); if ( is_wp_error( $current ) ) { return $current; }
		if ( 'xlsx' === $extension ) { $table = Parameter_Table::xlsx( $text ); return is_wp_error( $table ) ? $table : self::parameters( $table, $current, $mode ); }
		$limit = $restore && 'json' === $extension ? self::MAX_BACKUP_BYTES : self::MAX_BYTES;
		if ( strlen( $text ) > $limit ) { return Config::error( 'file', 'too_large', $restore ? 'JSON 配置备份最大为 8 MiB，未应用此次恢复。' : '导入文件最大为 1 MiB，未应用此次导入。' ); }
		if ( 1 !== preg_match( '//u', $text ) ) { return Config::error( 'file', 'invalid_utf8', '请使用 UTF-8 编码的导入文件。' ); }
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) { $text = substr( $text, 3 ); }
		$items = array(); $patch = array(); $lines = array(); $blank = 0; $name_applied = false;
		if ( 'txt' === $extension ) {
			foreach ( preg_split( '/\r\n|\n|\r/', $text ) as $index => $line ) {
				if ( self::blank( $line ) ) { ++$blank; continue; } $items[] = $line; $lines[] = $index + 1;
			}
		} elseif ( 'csv' === $extension ) {
			$rows = Parameter_Table::csv_rows( $text ); if ( is_wp_error( $rows ) ) { return $rows; }
			$legacy = self::legacy_csv( $rows ); if ( is_wp_error( $legacy ) ) { return $legacy; }
			if ( null === $legacy ) { $table = Parameter_Table::rows( $rows ); return is_wp_error( $table ) ? $table : self::parameters( $table, $current, $mode ); }
			$items = $legacy['items']; $lines = $legacy['lines']; $blank = $legacy['blankCount']; $name_applied = $legacy['nameApplied'];
		} else {
			try { $json = json_decode( $text, true, 32, JSON_THROW_ON_ERROR ); }
			catch ( \JsonException $e ) { return Config::error( 'file', 'invalid_json', 'JSON 格式错误，未应用此次导入。', array( 'format' => 'json' ) ); }
			if ( ! is_array( $json ) ) { return Config::error( 'file', 'invalid_json_shape', 'JSON 必须是文本数组或配置对象。' ); }
			if ( str_starts_with( ltrim( $text ), '{' ) ) {
				foreach ( array_keys( $json ) as $key ) { if ( ! in_array( $key, array_merge( Config::KEYS, array( 'name' ) ), true ) ) { return Config::error( 'file', 'unknown_field', 'JSON 配置包含不支持的字段。' ); } }
				if ( $restore ) {
					if ( ! array_key_exists( 'items', $json ) ) { return Config::error( 'items', 'required', '完整配置恢复需要包含 items 的 JSON 配置对象。' ); }
					$json['items'] = self::json_items( $json['items'] ); if ( is_wp_error( $json['items'] ) ) { return $json['items']; }
					$next = Config::normalize_for_import( $json ); if ( is_wp_error( $next ) ) { return $next; }
					$columns = count( $next['parameters'] ); $values = $columns ? self::value_count( $next['parameters'] ) : count( $next['items'] );
					return array( 'config' => $next, 'report' => array( 'sourceCount' => $values, 'validCount' => $values, 'duplicateCount' => 0, 'addedCount' => $values, 'totalCount' => $values, 'blankCount' => 0, 'columnCount' => $columns, 'mode' => 'replace', 'restored' => true, 'warnings' => Config::warnings( $next ) ) );
				}
				if ( array_key_exists( 'parameters', $json ) ) {
					$parameters = Config::normalize_parameters( $json['parameters'] ); if ( is_wp_error( $parameters ) ) { return $parameters; }
					$source = 0; $blank = 0;
					foreach ( $json['parameters'] as $column ) { foreach ( $column['values'] as $value ) { self::blank( $value ) ? ++$blank : ++$source; } }
					$table = array( 'parameters' => $parameters, 'report' => array( 'sourceCount' => $source, 'validCount' => self::value_count( $parameters ), 'blankCount' => $blank, 'warnings' => array() ) );
					return self::parameters( $table, $current, $mode );
				}
				if ( ! array_key_exists( 'items', $json ) ) { return Config::error( 'items', 'required', 'JSON 配置对象需要 parameters 或 items。' ); }
				$items = self::json_items( $json['items'] ); if ( is_wp_error( $items ) ) { return $items; }
				// Legacy content import keeps optional highlight/name/badge settings, never timings or enabled.
				$patch = array_intersect_key( $json, array_flip( array( 'keywords', 'name', 'badgeEnabled' ) ) );
			} else {
				if ( $restore ) { return Config::error( 'restore', 'invalid_json_shape', '完整恢复需要 JSON 配置对象，文本数组请使用导入文案。' ); }
				$items = self::json_items( $json ); if ( is_wp_error( $items ) ) { return $items; }
			}
		}
		$source_count = 0;
		foreach ( $items as $value ) { self::blank( $value ) ? ++$blank : ++$source_count; }
		$normalized = Config::normalize_items( $items ); if ( is_wp_error( $normalized ) ) { return self::locate( $normalized, $lines, $extension ); }
		$next = array_replace( $current, $patch );
		$next['items'] = 'append' === $mode ? array_merge( $current['items'], $normalized ) : $normalized;
		$next = Config::normalize( $next ); if ( is_wp_error( $next ) ) { return self::locate( $next, 'replace' === $mode ? $lines : array(), $extension ); }
		$added = 'append' === $mode ? count( $next['items'] ) - count( $current['items'] ) : count( $next['items'] );
		return array( 'config' => $next, 'report' => array( 'sourceCount' => $source_count, 'validCount' => count( $normalized ), 'duplicateCount' => max( 0, $source_count - $added ), 'addedCount' => $added, 'totalCount' => count( $next['items'] ), 'blankCount' => $blank, 'columnCount' => 0, 'nameColumnApplied' => $name_applied, 'mode' => $mode, 'restored' => false, 'warnings' => Config::warnings( $next ) ) );
	}

	private static function parameters( array $table, array $current, string $mode ) {
		$next = $current; $before = self::value_count( $current['parameters'] );
		if ( 'replace' === $mode ) { $next['parameters'] = $table['parameters']; }
		else {
			$columns = array_column( $next['parameters'], 'key' );
			foreach ( $table['parameters'] as $column ) {
				$index = array_search( $column['key'], $columns, true );
				if ( false === $index ) { $next['parameters'][] = $column; $columns[] = $column['key']; }
				else { $next['parameters'][ $index ]['values'] = array_merge( $next['parameters'][ $index ]['values'], $column['values'] ); }
			}
		}
		foreach ( $table['parameters'] as $column ) { $next['keywords'][] = '{' . $column['key'] . '}'; }
		$next = Config::normalize( $next ); if ( is_wp_error( $next ) ) { return $next; }
		$total = self::value_count( $next['parameters'] ); $added = 'append' === $mode ? $total - $before : $total;
		$source = $table['report']['sourceCount'];
		return array( 'config' => $next, 'report' => array( 'sourceCount' => $source, 'validCount' => $table['report']['validCount'], 'duplicateCount' => max( 0, $source - $added ), 'addedCount' => $added, 'totalCount' => $total, 'blankCount' => $table['report']['blankCount'], 'columnCount' => count( $next['parameters'] ), 'mode' => $mode, 'restored' => false, 'warnings' => array_merge( $table['report']['warnings'] ?? array(), Config::warnings( $next ) ) ) );
	}

	private static function value_count( array $parameters ): int { return array_sum( array_map( static fn( $column ) => count( $column['values'] ), $parameters ) ); }
	private static function blank( string $value ): bool { return 1 === preg_match( '/\A[\s\p{Z}]*\z/u', $value ); }

	private static function json_items( $input ) {
		if ( ! is_array( $input ) || ! array_is_list( $input ) ) { return Config::error( 'items', 'invalid_type', 'JSON items 必须是有序数组。' ); }
		$out = array();
		foreach ( $input as $index => $item ) {
			if ( is_string( $item ) ) { $out[] = $item; }
			elseif ( is_array( $item ) && ! array_is_list( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && ! array_diff( array_keys( $item ), array( 'text' ) ) ) { $out[] = $item['text']; }
			else { return Config::error( 'items.' . $index, 'invalid_type', sprintf( 'JSON 第 %d 条必须是文本或只包含 text 的对象。', $index + 1 ) ); }
		}
		return $out;
	}

	/** Only the old unwrapped text/content [+ name] header is legacy; {text} forces a parameter column. */
	private static function legacy_csv( array $rows ) {
		$rows = array_values( array_filter( $rows, static fn( $r ) => (bool) array_filter( $r['cells'], static fn( $v ) => ! self::blank( $v ) ) ) );
		if ( ! $rows ) { return null; }
		$header_row = array_shift( $rows ); $header = array();
		foreach ( $header_row['cells'] as $cell ) {
			$key = Config::clean_text( $cell, 'header', 120 ); if ( is_wp_error( $key ) ) { return $key; }
			$key = Config::lower( $key ); if ( is_wp_error( $key ) ) { return $key; } $header[] = $key;
		}
		if ( ! in_array( 'text', $header, true ) && ! in_array( 'content', $header, true ) || array_diff( $header, array( 'text', 'content', 'name' ) ) ) { return null; }
		if ( count( $header ) !== count( array_unique( $header ) ) || in_array( 'text', $header, true ) && in_array( 'content', $header, true ) ) { return self::csv_error( $header_row['line'], 'duplicate_header', '旧文案 CSV 只能有一个 text 或 content 列，以及可选 name 列。' ); }
		$column = array_search( in_array( 'text', $header, true ) ? 'text' : 'content', $header, true );
		$name_column = array_search( 'name', $header, true ); $items = array(); $lines = array(); $blank = 0;
		foreach ( $rows as $record ) {
			$cells = $record['cells']; if ( count( $cells ) !== count( $header ) ) { return self::csv_error( $record['line'], 'column_count', '字段数量与表头不一致。' ); }
			$value = $cells[ $column ];
			if ( false !== $name_column ) {
				$name = Config::clean_text( $cells[ $name_column ], 'name', Config::MAX_NAME_LENGTH ); if ( is_wp_error( $name ) ) { return self::with_line( $name, $record['line'], 'csv' ); }
				if ( '' === $name && str_contains( $value, '{name}' ) ) { return self::csv_error( $record['line'], 'name_required', '文案使用了 {name}，但本行姓名为空。' ); }
				$value = str_replace( '{name}', $name, $value );
			}
			$items[] = $value; $lines[] = $record['line'];
		}
		return array( 'items' => $items, 'lines' => $lines, 'blankCount' => $blank, 'nameApplied' => false !== $name_column );
	}

	private static function csv_error( int $line, string $reason, string $message ): \WP_Error { return Config::error( 'file', $reason, 'CSV 第 ' . $line . ' 行：' . $message, array( 'line' => $line, 'format' => 'csv' ) ); }
	private static function locate( \WP_Error $error, array $lines, string $format ): \WP_Error {
		$data = (array) $error->get_error_data();
		foreach ( array_keys( $data['fields'] ?? array() ) as $field ) { if ( preg_match( '/^items\.(\d+)$/', $field, $match ) && isset( $lines[ (int) $match[1] ] ) ) { return self::with_line( $error, $lines[ (int) $match[1] ], $format ); } }
		return $error;
	}
	private static function with_line( \WP_Error $error, int $line, string $format ): \WP_Error { return new \WP_Error( $error->get_error_code(), strtoupper( $format ) . ' 第 ' . $line . ' 行：' . $error->get_error_message(), array_merge( (array) $error->get_error_data(), array( 'line' => $line, 'format' => $format ) ) ); }
}
