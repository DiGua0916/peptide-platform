<?php
/**
 * WP-CLI：批次反查订单（wp peptide batch orders）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Batches;
use PeptideCore\Order_Batches;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Batch_Command {

	/**
	 * 列出收到某个批次的全部订单（溯源、召回）。输出包含顾客联系信息，请妥善处理。
	 *
	 * ## OPTIONS
	 *
	 * <batch_no>
	 * : 批次号（不区分大小写）。
	 *
	 * [--format=<format>]
	 * : 输出格式。csv 与后台导出的文件内容相同。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide batch orders DEMO-A-2401
	 *     wp peptide batch orders DEMO-A-2401 --format=csv > recall.csv
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function orders( array $args, array $assoc_args ): void {
		$batch_no = Batches::normalize_batch_no( $args[0] );
		$batch_id = Batches::find_id_by_batch_no( $batch_no );
		if ( ! $batch_id ) {
			WP_CLI::error( "Batch {$batch_no} not found." );
		}
		$rows   = Order_Batches::orders_for_batch( $batch_id );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'csv' === $format ) {
			WP_CLI::line( Order_Batches::orders_csv( $batch_no, $rows ) );
			return;
		}
		$items = array_map(
			static fn( $r ) => array(
				'order'    => $r['number'],
				'date'     => $r['date'],
				'status'   => $r['status'],
				'customer' => $r['customer'],
				'email'    => $r['email'],
				'country'  => $r['country'],
				'quantity' => $r['quantity'],
			),
			$rows
		);
		WP_CLI\Utils\format_items( $format, $items, array( 'order', 'date', 'status', 'customer', 'email', 'country', 'quantity' ) );
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( '%d order(s), %d unit(s) of batch %s.', count( $rows ), array_sum( array_column( $rows, 'quantity' ) ), $batch_no ) );
		}
	}
}
