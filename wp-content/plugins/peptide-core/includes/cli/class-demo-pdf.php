<?php
/**
 * 生成一页带 “DEMO” 水印的极简 PDF，用作演示报告文件。
 * 内容只包含说明文字，不包含任何检测数值或机构名称。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

defined( 'ABSPATH' ) || exit;

final class Demo_Pdf {

	/**
	 * @param string[] $lines 正文（只支持 ASCII）。
	 */
	public static function build( array $lines ): string {
		$watermark = "q 0.85 g BT /F1 40 Tf 0.7071 0.7071 -0.7071 0.7071 140 200 Tm (DEMO - NOT A REAL TEST REPORT) Tj ET Q\n";

		$body = "BT /F1 12 Tf 16 TL 72 740 Td\n";
		foreach ( $lines as $line ) {
			$body .= '(' . self::escape( $line ) . ") Tj T*\n";
		}
		$body .= "ET\n";

		$stream  = $watermark . $body;
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream',
		);

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $index => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$count       = count( $objects ) + 1;
		$pdf        .= "xref\n0 {$count}\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF\n";

		return $pdf;
	}

	private static function escape( string $text ): string {
		$text = preg_replace( '/[^\x20-\x7E]/', '?', $text ) ?? '';
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}
}
