<?php
/** Bounded CSV/OOXML parameter tables. No extraction, temporary file, formula evaluation or network calls. */
namespace PeptideCore\Broadcast;

defined( 'ABSPATH' ) || exit;

final class Parameter_Table {
	public const MAX_BYTES = 1048576;
	private const MAX_ENTRIES = 128;
	private const MAX_XML_BYTES = 2097152;
	private const MAX_INFLATED_BYTES = 8388608;
	private const MAX_CELLS = 32032;
	private const MAX_ROWS = 10001;

	/** RFC-style scanner; short data rows may be padded later, malformed quoting is never repaired. */
	public static function csv_rows( string $text ) {
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
				if ( '' !== $field || $closed ) { return self::error( 'unexpected_quote', 'CSV 引号位置不正确。', $line ); }
				$quoted = true;
			} else {
				if ( $closed ) { return self::error( 'trailing_characters', 'CSV 结束引号后只能是逗号或换行。', $line ); }
				$field .= $char;
			}
			if ( count( $row ) > 32 || count( $rows ) > self::MAX_ROWS ) { return self::error( 'table_too_large', '参数表最多 32 列、10,000 个数据行。', $line ); }
		}
		if ( $quoted ) { return self::error( 'unclosed_quote', 'CSV 引号未闭合。', $start ); }
		if ( '' !== $field || $row || $closed ) { $row[] = $field; $rows[] = array( 'cells' => $row, 'line' => $start ); }
		if ( count( $rows ) > self::MAX_ROWS ) { return self::error( 'table_too_large', '参数表最多 10,000 个数据行。' ); }
		return $rows;
	}

	public static function csv( string $text ) {
		$rows = self::csv_rows( $text );
		return is_wp_error( $rows ) ? $rows : self::rows( $rows, 'csv' );
	}

	/** Public to the importer so old text/content CSV can be identified without guessing from data values. */
	public static function rows( array $rows, string $format = 'csv' ) {
		while ( $rows && ! self::nonblank( $rows[0]['cells'] ) ) { array_shift( $rows ); }
		if ( ! $rows ) { return self::error( 'missing_header', '参数表需要第一行表头。' ); }
		$header = array_shift( $rows ); $width = count( $header['cells'] );
		if ( $width < 1 || $width > 32 ) { return self::error( 'column_limit', '参数表需要 1 至 32 列。', $header['line'] ); }
		$parameters = array(); $seen_keys = array(); $seen_values = array(); $source = 0; $blanks = 0; $duplicates = 0; $cell_count = $width;
		foreach ( $header['cells'] as $index => $cell ) {
			$key = Config::clean_text( $cell, 'header.' . $index, 42 );
			if ( is_wp_error( $key ) ) { return $key; }
			if ( str_starts_with( $key, '{' ) || str_ends_with( $key, '}' ) ) {
				if ( ! preg_match( '/\A\{([^{}]+)\}\z/u', $key, $match ) ) { return self::error( 'invalid_header', '表头请填写参数名或 {参数名}。', $header['line'] ); }
				$key = $match[1];
			}
			if ( '' === $key || isset( $seen_keys[ $key ] ) ) { return self::error( '' === $key ? 'empty_header' : 'duplicate_header', '表头不能为空，也不能包含重复参数名。', $header['line'] ); }
			$seen_keys[ $key ] = true; $seen_values[] = array(); $parameters[] = array( 'key' => $key, 'values' => array() );
		}
		// Use the same key validation as manual configuration before reading any values.
		$keys = Config::normalize_parameters( array_map( static fn( $p ) => array( 'key' => $p['key'], 'values' => array( 'validation' ) ), $parameters ) );
		if ( is_wp_error( $keys ) ) { return $keys; }
		foreach ( $rows as $record ) {
			$cells = $record['cells'];
			if ( count( $cells ) > $width ) {
				while ( count( $cells ) > $width && ! self::nonblank( array( end( $cells ) ) ) ) { array_pop( $cells ); }
				if ( count( $cells ) > $width ) { return self::error( 'column_count', '数据超出已有表头；请为每一列填写参数名。', $record['line'] ); }
			}
			$cells = array_pad( $cells, $width, '' ); $cell_count += $width;
			if ( $cell_count > self::MAX_CELLS ) { return self::error( 'cell_limit', '参数表单元格数量超过安全上限。', $record['line'] ); }
			foreach ( $cells as $index => $value ) {
				$value = Config::clean_text( $value, 'parameters.' . $index . '.values', 500 );
				if ( is_wp_error( $value ) ) { return $value; }
				if ( '' === $value ) { ++$blanks; continue; }
				if ( str_starts_with( $value, '=' ) ) { return self::error( 'formula_not_supported', '参数表不接受公式，请先转换为纯文本值。', $record['line'] ); }
				++$source; $lower = Config::lower( $value ); if ( is_wp_error( $lower ) ) { return $lower; }
				if ( isset( $seen_values[ $index ][ $lower ] ) ) { ++$duplicates; continue; }
				$seen_values[ $index ][ $lower ] = true; $parameters[ $index ]['values'][] = $value;
			}
		}
		foreach ( $parameters as $parameter ) { if ( ! $parameter['values'] ) { return self::error( 'empty_column', '参数 {' . $parameter['key'] . '} 整列为空，未导入任何内容。' ); } }
		$parameters = Config::normalize_parameters( $parameters ); if ( is_wp_error( $parameters ) ) { return $parameters; }
		return array( 'parameters' => $parameters, 'report' => array( 'sourceCount' => $source, 'validCount' => $source - $duplicates, 'duplicateCount' => $duplicates, 'blankCount' => $blanks, 'columnCount' => count( $parameters ), 'warnings' => array() ), 'format' => $format );
	}

	private static function nonblank( array $cells ): bool {
		foreach ( $cells as $cell ) { if ( ! is_string( $cell ) || 1 !== preg_match( '/\A[\s\p{Z}]*\z/u', $cell ) ) { return true; } }
		return false;
	}

	/** Strict base64 XLSX, exactly one populated visible worksheet; empty sheets are fully validated then ignored. */
	public static function xlsx( string $encoded ) {
		if ( strlen( $encoded ) > 4 * (int) ceil( self::MAX_BYTES / 3 ) || strlen( $encoded ) % 4 || strspn( $encoded, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=' ) !== strlen( $encoded ) ) { return self::error( 'invalid_base64', 'XLSX 必须以标准 Base64 上传，文件最大 1 MiB。' ); }
		$binary = base64_decode( $encoded, true );
		if ( false === $binary || '' === $binary || base64_encode( $binary ) !== $encoded || strlen( $binary ) > self::MAX_BYTES ) { return self::error( 'invalid_base64', 'XLSX 编码无效或文件超过 1 MiB。' ); }
		$parts = self::zip( $binary ); if ( is_wp_error( $parts ) ) { return $parts; }
		if ( ! function_exists( 'simplexml_load_string' ) ) { return self::error( 'xml_unavailable', '服务器需要 SimpleXML 扩展后才能读取 XLSX。' ); }
		$xml_parts = array();
		foreach ( $parts as $name => $body ) {
			if ( ! preg_match( '/(?:\.xml|\.rels)\z/i', $name ) ) { continue; }
			$xml = self::xml( $body ); if ( is_wp_error( $xml ) ) { return $xml; } $xml_parts[ $name ] = $xml;
			if ( preg_match( '~\Axl/worksheets/.*\.xml\z~i', $name ) && $xml->xpath( '//*[local-name()="f"]' ) ) { return self::error( 'formula_not_supported', 'XLSX 含有公式；请将所有公式转换为值后重试。' ); }
			if ( preg_match( '/\.rels\z/i', $name ) ) {
				$ids = array();
				foreach ( $xml->xpath( '//*[local-name()="Relationship"]' ) as $rel ) {
					$id = (string) $rel['Id'];
					$target = (string) $rel['Target'];
					if ( '' === $id || isset( $ids[ $id ] ) || '' === $target || preg_match( '~[:?#\\\\\x00-\x20]|\A//~', $target ) || isset( $rel['TargetMode'] ) && 'internal' !== strtolower( (string) $rel['TargetMode'] ) ) { return self::error( 'external_relationship', 'XLSX 不允许外部链接或重复关系。' ); }
					$ids[ $id ] = true;
				}
			}
		}
		if ( ! isset( $xml_parts['[Content_Types].xml'], $xml_parts['xl/workbook.xml'], $xml_parts['xl/_rels/workbook.xml.rels'] ) ) { return self::error( 'invalid_xlsx', '文件缺少标准 XLSX 工作簿结构。' ); }
		if ( 'Types' !== $xml_parts['[Content_Types].xml']->getName() || 'workbook' !== $xml_parts['xl/workbook.xml']->getName() || 'Relationships' !== $xml_parts['xl/_rels/workbook.xml.rels']->getName() ) { return self::error( 'invalid_xlsx', '文件不是标准 XLSX 工作簿。' ); }
		foreach ( $xml_parts['[Content_Types].xml']->xpath( '//*' ) as $type ) {
			if ( preg_match( '/macro|vba|activex|oleobject/i', (string) $type['ContentType'] ) ) { return self::error( 'active_content', 'XLSX 不允许宏、ActiveX 或嵌入对象。' ); }
		}
		$sheets = $xml_parts['xl/workbook.xml']->xpath( './*[local-name()="sheets"]/*[local-name()="sheet"]' );
		if ( ! $sheets ) { return self::error( 'single_sheet_required', 'XLSX 至少需要一张包含参数表的可见工作表。' ); }
		$strings = array();
		if ( isset( $xml_parts['xl/sharedStrings.xml'] ) ) {
			foreach ( $xml_parts['xl/sharedStrings.xml']->xpath( './*[local-name()="si"]' ) as $item ) { $strings[] = self::rich_text( $item ); if ( count( $strings ) > self::MAX_CELLS ) { return self::error( 'shared_strings_limit', '共享文本数量超过安全上限。' ); } }
		}
		$selected = null; $count = 0; $warnings = array(); $sheet_ids = array();
		foreach ( $sheets as $sheet ) {
			$ids = $sheet->xpath( './@*[local-name()="id"]' ); $target = '';
			if ( 1 !== count( $ids ) || isset( $sheet_ids[ (string) $ids[0] ] ) ) { return self::error( 'invalid_xlsx', '工作表关系无效或重复。' ); }
			$sheet_ids[ (string) $ids[0] ] = true;
			foreach ( $xml_parts['xl/_rels/workbook.xml.rels']->xpath( './*[local-name()="Relationship"]' ) as $rel ) {
				if ( (string) $rel['Id'] === (string) $ids[0] && str_ends_with( (string) $rel['Type'], '/worksheet' ) ) { $target = (string) $rel['Target']; }
			}
			$target = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
			if ( ! preg_match( '~\Axl/worksheets/[A-Za-z0-9_.-]+\.xml\z~D', $target ) || ! isset( $xml_parts[ $target ] ) ) { return self::error( 'invalid_sheet_path', '工作表路径无效或不受支持。' ); }
			if ( 'worksheet' !== $xml_parts[ $target ]->getName() ) { return self::error( 'invalid_xlsx', '工作表 XML 结构无效。' ); }
			$records = self::worksheet_rows( $xml_parts[ $target ], $strings, $count, $warnings );
			if ( is_wp_error( $records ) ) { return $records; }
			if ( ! $records ) { continue; }
			if ( null !== $selected || isset( $sheet['state'] ) && 'visible' !== (string) $sheet['state'] ) { return self::error( 'single_sheet_required', 'XLSX 只能有一张包含数据的可见参数表；多个非空工作表不会被部分导入。空白工作表可保留。' ); }
			$selected = $records;
		}
		if ( null === $selected ) { return self::error( 'missing_header', 'XLSX 没有包含参数表的非空工作表。' ); }
		$result = self::rows( $selected, 'xlsx' ); if ( ! is_wp_error( $result ) ) { $result['report']['warnings'] = array_values( $warnings ); }
		return $result;
	}

	/** Validate even blank sheets; a workbook-wide cell counter bounds all of them together. */
	private static function worksheet_rows( \SimpleXMLElement $sheet, array $strings, int &$count, array &$warnings ) {
		$matrix = array();
		foreach ( $sheet->xpath( './*[local-name()="sheetData"]/*[local-name()="row"]' ) as $row ) {
			foreach ( $row->xpath( './*[local-name()="c"]' ) as $cell ) {
				if ( ++$count > self::MAX_CELLS || ! preg_match( '/\A([A-Z]{1,3})([1-9][0-9]{0,6})\z/D', (string) $cell['r'], $match ) ) { return self::error( 'cell_limit', '单元格数量或位置无效。' ); }
				$col = 0; foreach ( str_split( $match[1] ) as $letter ) { $col = $col * 26 + ord( $letter ) - 64; } $line = (int) $match[2];
				if ( $col > 32 || $line > self::MAX_ROWS || isset( $row['r'] ) && (string) $row['r'] !== (string) $line || isset( $matrix[ $line ][ $col ] ) ) { return self::error( 'cell_limit', '参数表最多 32 列，单元格不能重复或超出范围。', $line ); }
				$type = (string) $cell['t']; $values = $cell->xpath( './*[local-name()="v"]' ); $value = $values ? (string) $values[0] : '';
				if ( count( $values ) > 1 ) { return self::error( 'invalid_cell', '单元格包含多个值。', $line ); }
				if ( 's' === $type ) {
					if ( ! preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/D', $value ) || ! array_key_exists( (int) $value, $strings ) ) { return self::error( 'invalid_shared_string', '共享文本索引无效。', $line ); } $value = $strings[ (int) $value ];
				} elseif ( 'inlineStr' === $type ) { $inline = $cell->xpath( './*[local-name()="is"]' ); if ( count( $inline ) !== 1 ) { return self::error( 'invalid_cell', '文本单元格结构无效。', $line ); } $value = self::rich_text( $inline[0] ); }
				elseif ( 'b' === $type ) { if ( ! in_array( $value, array( '0', '1' ), true ) ) { return self::error( 'invalid_cell', '布尔单元格值无效。', $line ); } $value = '1' === $value ? 'TRUE' : 'FALSE'; }
				elseif ( ! in_array( $type, array( '', 'n', 'str', 'd' ), true ) ) { return self::error( 'unsupported_cell', '参数表包含错误或不支持的单元格。', $line ); }
				elseif ( in_array( $type, array( '', 'n' ), true ) && '' !== $value && ( ! preg_match( '/\A-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?\z/D', $value ) || ! is_finite( (float) $value ) ) ) { return self::error( 'invalid_number', '数字单元格值无效。', $line ); }
				if ( isset( $cell['s'] ) && '0' !== (string) $cell['s'] && in_array( $type, array( '', 'n' ), true ) && '' !== $value ) { $warnings['numeric_format'] = array( 'field' => 'file', 'message' => '带格式的数字或日期按原始值导入；如需保留显示格式，请先将该列转换为文本。' ); }
				$matrix[ $line ][ $col ] = $value;
			}
		}
		ksort( $matrix ); $records = array(); $width = 0;
		foreach ( $matrix as $line => $cells ) {
			if ( ! self::nonblank( array_values( $cells ) ) ) { continue; }
			$last = max( array_keys( array_filter( $cells, static fn( $value ) => self::nonblank( array( $value ) ) ) ) );
			if ( ! $width ) { $width = $last; }
			$list = array_fill( 0, max( $width, $last ), '' ); foreach ( $cells as $col => $value ) { if ( $col <= count( $list ) ) { $list[ $col - 1 ] = $value; } }
			$records[] = array( 'cells' => $list, 'line' => $line );
		}
		return $records;
	}

	private static function rich_text( \SimpleXMLElement $node ): string {
		$out = ''; foreach ( $node->xpath( './*[local-name()="t"]|./*[local-name()="r"]/*[local-name()="t"]' ) as $part ) { $out .= (string) $part; } return $out;
	}

	private static function xml( string $body ) {
		if ( strlen( $body ) > self::MAX_XML_BYTES || str_contains( $body, "\0" ) || ! preg_match( '//u', $body ) || preg_match( '/<!\s*(?:DOCTYPE|ENTITY)/i', $body ) ) { return self::error( 'unsafe_xml', 'XLSX XML 无效，或包含被禁止的 DTD / ENTITY。' ); }
		if ( preg_match( '/<\?xml[^>]*encoding\s*=\s*["\']([^"\']+)/i', $body, $match ) && ! in_array( strtolower( $match[1] ), array( 'utf-8', 'us-ascii' ), true ) ) { return self::error( 'xml_encoding', 'XLSX XML 必须使用 UTF-8 编码。' ); }
		$before = libxml_use_internal_errors( true );
		try { $xml = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $before ); }
		if ( false === $xml || count( $xml->xpath( '//*' ) ) > 100000 ) { return self::error( 'invalid_xml', 'XLSX XML 结构无效或过于复杂。' ); }
		return $xml;
	}

	/** ZIP32 central/local headers and CRC are checked before using any part. */
	private static function zip( string $data ) {
		$length = strlen( $data ); $end = strrpos( $data, "PK\x05\x06" );
		if ( $length < 22 || ! str_starts_with( $data, "PK\x03\x04" ) || false === $end || $end < max( 0, $length - 65557 ) || $end + 22 > $length ) { return self::error( 'invalid_zip', '不是有效的 XLSX ZIP 文件。' ); }
		$footer = unpack( 'vdisk/vstart/vdiskCount/vcount/Vsize/Voffset/vcomment', substr( $data, $end + 4, 18 ) );
		if ( $footer['disk'] || $footer['start'] || $footer['count'] < 1 || $footer['count'] > self::MAX_ENTRIES || $footer['diskCount'] !== $footer['count'] || $footer['offset'] + $footer['size'] !== $end || $end + 22 + $footer['comment'] !== $length ) { return self::error( 'zip_limits', '不支持分卷、ZIP64、附加数据或过多 ZIP 条目。' ); }
		$pos = $footer['offset']; $parts = array(); $names = array(); $ranges = array(); $inflated = 0;
		for ( $i = 0; $i < $footer['count']; ++$i ) {
			if ( $pos + 46 > $end || "PK\x01\x02" !== substr( $data, $pos, 4 ) ) { return self::error( 'invalid_zip', 'ZIP 中央目录无效。' ); }
			$entry = unpack( 'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr( $data, $pos + 4, 42 ) );
			$next = $pos + 46 + $entry['name'] + $entry['extra'] + $entry['comment']; $name = substr( $data, $pos + 46, $entry['name'] ); $folded = strtolower( $name );
			if ( $next > $end || strlen( $name ) > 240 || '' === $name || preg_match( '~[\x00-\x1f\\\\]|(?:\A/)|(?:\A[A-Za-z]:)|(?:(?:\A|/)\.\.?(?:/|\z))|//~', $name ) || ! preg_match( '//u', $name ) || isset( $names[ $folded ] ) ) { return self::error( 'unsafe_zip_path', 'ZIP 包含危险路径、重复路径或无效名称。' ); }
			$names[ $folded ] = true;
			if ( preg_match( '~(?:vba|activex|externallinks|embeddings|oleobject|connections\.xml|querytables|customui)~i', $name ) || 0120000 === ( ( $entry['external'] >> 16 ) & 0170000 ) || $entry['disk'] || $entry['needed'] >= 45 || ( $entry['flags'] & ~0x080e ) || ! in_array( $entry['method'], array( 0, 8 ), true ) || $entry['size'] > self::MAX_XML_BYTES || ( $inflated += $entry['size'] ) > self::MAX_INFLATED_BYTES ) { return self::error( 'unsafe_zip_entry', 'ZIP 含宏、外部链接、加密条目或超过解压安全限制。' ); }
			if ( ! self::zip_extra_safe( substr( $data, $pos + 46 + $entry['name'], $entry['extra'] ) ) ) { return self::error( 'zip64_not_supported', '不支持 ZIP64 或损坏的扩展字段。' ); }
			$local = $entry['offset'];
			if ( $local + 30 > $footer['offset'] || "PK\x03\x04" !== substr( $data, $local, 4 ) ) { return self::error( 'invalid_zip', 'ZIP 本地条目头无效。' ); }
			$head = unpack( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr( $data, $local + 4, 26 ) );
			$begin = $local + 30 + $head['name'] + $head['extra']; $stop = $begin + $entry['compressed'];
			if ( $head['needed'] !== $entry['needed'] || $head['flags'] !== $entry['flags'] || $head['method'] !== $entry['method'] || substr( $data, $local + 30, $head['name'] ) !== $name || $stop > $footer['offset'] || ! self::zip_extra_safe( substr( $data, $local + 30 + $head['name'], $head['extra'] ) ) || ( ! ( $entry['flags'] & 8 ) && ( $head['crc'] !== $entry['crc'] || $head['compressed'] !== $entry['compressed'] || $head['size'] !== $entry['size'] ) ) ) { return self::error( 'invalid_zip', 'ZIP 本地条目与目录不一致。' ); }
			if ( $entry['flags'] & 8 ) {
				$descriptor = $stop; if ( "PK\x07\x08" === substr( $data, $descriptor, 4 ) ) { $descriptor += 4; }
				if ( $descriptor + 12 > $footer['offset'] ) { return self::error( 'invalid_zip', 'ZIP 数据描述符缺失。' ); }
				$check = unpack( 'Vcrc/Vcompressed/Vsize', substr( $data, $descriptor, 12 ) ); if ( $check['crc'] !== $entry['crc'] || $check['compressed'] !== $entry['compressed'] || $check['size'] !== $entry['size'] ) { return self::error( 'invalid_zip', 'ZIP 数据描述符不一致。' ); } $stop = $descriptor + 12;
			}
			foreach ( $ranges as [ $a, $b ] ) { if ( $local < $b && $stop > $a ) { return self::error( 'overlapping_zip', 'ZIP 条目相互重叠。' ); } } $ranges[] = array( $local, $stop );
			$compressed = substr( $data, $begin, $entry['compressed'] );
			if ( 8 === $entry['method'] && ! function_exists( 'gzinflate' ) ) { return self::error( 'zlib_unavailable', '服务器需要 zlib 扩展后才能读取压缩 XLSX。' ); }
			$raw = 8 === $entry['method'] ? @gzinflate( $compressed, self::MAX_XML_BYTES ) : $compressed; // bounded input/output; malformed input rejected below.
			if ( false === $raw || strlen( $raw ) !== $entry['size'] || sprintf( '%08x', crc32( $raw ) ) !== sprintf( '%08x', $entry['crc'] ) ) { return self::error( 'zip_crc', 'ZIP 内容损坏或解压长度不符。' ); }
			if ( ! str_ends_with( $name, '/' ) ) { $parts[ $name ] = $raw; } elseif ( '' !== $raw ) { return self::error( 'invalid_zip', 'ZIP 目录不能包含数据。' ); }
			$pos = $next;
		}
		if ( $pos !== $end ) { return self::error( 'invalid_zip', 'ZIP 目录长度不一致。' ); }
		return $parts;
	}

	private static function zip_extra_safe( string $extra ): bool {
		for ( $i = 0, $length = strlen( $extra ); $i < $length; ) { if ( $i + 4 > $length ) { return false; } $part = unpack( 'vid/vlength', substr( $extra, $i, 4 ) ); if ( 1 === $part['id'] || $i + 4 + $part['length'] > $length ) { return false; } $i += 4 + $part['length']; } return true;
	}

	private static function error( string $reason, string $message, ?int $line = null ): \WP_Error {
		return Config::error( 'file', $reason, $message, null === $line ? array() : array( 'line' => $line ) );
	}
}
