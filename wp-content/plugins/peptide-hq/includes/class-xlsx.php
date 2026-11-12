<?php
/**
 * 极简 .xlsx 生成（物流填单下载用）：多个工作表、表头颜色、绿色单号列、边框、自动换行、列宽、冻结表头。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Xlsx {

	// 样式编号（对应 styles.xml 的 cellXfs 顺序）。
	public const S_HEAD_BLUE = 1;
	public const S_HEAD      = 2;
	public const S_GREEN     = 3;
	public const S_CELL      = 4;
	public const S_LEFT      = 5;

	/**
	 * @param array<int,array{name:string,rows:array,widths:array<int,float>,head:int,green:int,left:int[]}> $sheets
	 *        head = 表头样式；green = 前几列涂绿；left = 左对齐的列（从 0 开始）。
	 * @return string|\WP_Error 文件内容
	 */
	public static function build( array $sheets ) {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'pchq_xlsx', '服务器缺少 zip 组件，无法生成 Excel。' );
		}
		$tmp = wp_tempnam( 'pchq-xlsx' );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'pchq_xlsx', '无法生成 Excel。' );
		}
		$n     = count( $sheets );
		$ct    = '';
		$wb    = '';
		$rels  = '';
		foreach ( array_values( $sheets ) as $i => $sh ) {
			$k     = $i + 1;
			$ct   .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb   .= '<sheet name="' . self::x( mb_substr( (string) $sh['name'], 0, 31 ) ) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
			$rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $k . '.xml', self::sheet( $sh ) );
		}
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$zip->addFromString( 'xl/styles.xml', self::styles() );
		$zip->close();
		$data = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp );
		return $data;
	}

	private static function x( string $s ): string {
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s );
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function col( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	private static function sheet( array $sh ): string {
		$cols = '';
		foreach ( (array) ( $sh['widths'] ?? array() ) as $i => $w ) {
			$cols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
		}
		$left  = (array) ( $sh['left'] ?? array() );
		$green = (int) ( $sh['green'] ?? 0 );
		$xml   = '';
		foreach ( array_values( (array) $sh['rows'] ) as $ri => $row ) {
			$lines = 1;
			$cells = '';
			foreach ( array_values( (array) $row ) as $ci => $v ) {
				$style = 0 === $ri ? (int) $sh['head'] : ( $ci < $green ? self::S_GREEN : ( in_array( $ci, $left, true ) ? self::S_LEFT : self::S_CELL ) );
				$ref   = self::col( $ci ) . ( $ri + 1 );
				if ( is_int( $v ) || is_float( $v ) ) {
					$cells .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
				} else {
					$v      = (string) $v;
					$lines  = max( $lines, substr_count( $v, "\n" ) + 1 );
					$cells .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x( $v ) . '</t></is></c>';
				}
			}
			$ht   = 0 === $ri ? 30 : max( 20, 15 * $lines + 6 );
			$xml .= '<row r="' . ( $ri + 1 ) . '" ht="' . $ht . '" customHeight="1">' . $cells . '</row>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' ) . '<sheetData>' . $xml . '</sheetData></worksheet>';
	}

	private static function styles(): string {
		$b = '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>';
		$c = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="12"/><name val="Arial"/></font></fonts>'
			. '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8EDCF8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF92D050"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $b . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="6">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $c . '</xf>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1">' . $c . '</xf>'
			. '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">' . $c . '</xf>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">' . $c . '</xf>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
			. '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}
}
