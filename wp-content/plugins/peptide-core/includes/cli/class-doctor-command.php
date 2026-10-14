<?php
/**
 * WP-CLI：上线体检（wp peptide doctor）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Launch_Check;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Doctor_Command {

	/**
	 * 列出正式上线前必须处理（fail）或需要确认（warn）的事项。只读。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : 输出格式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide doctor
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$results = Launch_Check::run();
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $results, array( 'level', 'check', 'detail' ) );
		$sum = Launch_Check::summary( $results );
		$msg = sprintf( '%d must fix, %d to confirm, %d ok.', $sum['fail'], $sum['warn'], $sum['ok'] );
		if ( $sum['fail'] ) {
			WP_CLI::warning( 'Not ready for launch: ' . $msg );
		} else {
			WP_CLI::success( 'No blocking issues: ' . $msg );
		}
	}
}
