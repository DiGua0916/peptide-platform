<?php
/**
 * WP-CLI：促销对照价（wp peptide pricing）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Pricing;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Pricing_Command {

	/**
	 * 为所有已发布、正在销售的 simple / variation 开启**演示**对照价（当前价 × 2 + 0.02），成交价不变。
	 *
	 * 演示对照价没有真实原价依据，只能用于本地演示；上线体检会把它列为必须处理的项目。
	 * 已有促销价、已被本插件处理过、或与基准不一致的商品都会跳过，重复执行不会再次翻倍。
	 *
	 * ## OPTIONS
	 *
	 * --backup=<file>
	 * : 写入前把当前价格备份到这个 JSON 文件（必须是新文件）。
	 *
	 * [--baseline=<file>]
	 * : 可选，基准价格 JSON（Codex 的 after.json 格式）。当前价与基准不一致的商品会跳过。
	 *
	 * [--dry-run]
	 * : 只显示计划，不写入。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide pricing apply-demo --backup=D:/backups/before.json --baseline=D:/work/after.json --dry-run
	 *
	 * @subcommand apply-demo
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function apply_demo( array $args, array $assoc_args ): void {
		$items    = $this->priced_items();
		$baseline = $this->load_baseline( $assoc_args['baseline'] ?? '' );
		$dry      = ! empty( $assoc_args['dry-run'] );

		if ( ! $dry ) {
			$this->write_backup( (string) $assoc_args['backup'], $items );
		}

		$counts = array();
		foreach ( $items as $product ) {
			$expected = $baseline[ $product->get_id() ] ?? null;
			if ( $dry ) {
				$result = Pricing::record( $product ) ? 'skipped:already-managed' : ( '' !== $product->get_sale_price( 'edit' ) ? 'skipped:existing-sale' : ( null !== $expected && Pricing::to_cents( $product->get_regular_price( 'edit' ) ) !== Pricing::to_cents( $expected ) ? 'skipped:baseline-mismatch' : 'would-apply' ) );
			} else {
				$result = Pricing::apply_demo_compare( $product, $expected );
			}
			$counts[ $result ] = ( $counts[ $result ] ?? 0 ) + 1;
			if ( 'applied' !== $result && 'would-apply' !== $result && 'skipped:already-applied' !== $result ) {
				WP_CLI::log( sprintf( '#%d %s: %s', $product->get_id(), $product->get_name(), $result ) );
			}
		}
		if ( ! $dry ) {
			$this->sync_parents( $items );
		}
		WP_CLI::success( ( $dry ? 'Dry run: ' : '' ) . wp_json_encode( $counts ) );
		if ( ! $dry ) {
			WP_CLI::warning( 'These comparison prices are DEMO values without a genuine former-price basis. Do not publish them as real discounts.' );
		}
	}

	/**
	 * 撤销本插件写入的对照价：常规价还原为成交价，清空促销价。成交价不变。
	 *
	 * ## OPTIONS
	 *
	 * --backup=<file>
	 * : 写入前备份当前价格（必须是新文件）。
	 *
	 * [--dry-run]
	 * : 只显示计划，不写入。
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function revert( array $args, array $assoc_args ): void {
		$items = $this->priced_items();
		$dry   = ! empty( $assoc_args['dry-run'] );
		if ( ! $dry ) {
			$this->write_backup( (string) $assoc_args['backup'], $items );
		}
		$counts = array();
		foreach ( $items as $product ) {
			$result            = $dry ? ( Pricing::record( $product ) ? 'would-revert' : 'skipped:not-managed' ) : Pricing::revert( $product );
			$counts[ $result ] = ( $counts[ $result ] ?? 0 ) + 1;
		}
		if ( ! $dry ) {
			$this->sync_parents( $items );
		}
		WP_CLI::success( ( $dry ? 'Dry run: ' : '' ) . wp_json_encode( $counts ) );
	}

	/**
	 * 显示对照价状态（多少个价格由本插件管理、来源是否为演示数据）。
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$counts = array(
			'priced_items'   => 0,
			'managed_demo'   => 0,
			'managed_real'   => 0,
			'other_on_sale'  => 0,
			'not_on_sale'    => 0,
		);
		foreach ( $this->priced_items() as $product ) {
			++$counts['priced_items'];
			$record = Pricing::record( $product );
			if ( $record ) {
				++$counts[ Pricing::SOURCE_DEMO === $record['source'] ? 'managed_demo' : 'managed_real' ];
			} elseif ( $product->is_on_sale() ) {
				++$counts['other_on_sale'];
			} else {
				++$counts['not_on_sale'];
			}
		}
		WP_CLI\Utils\format_items( 'table', array( $counts ), array_keys( $counts ) );
	}

	/**
	 * 所有已发布父商品下可单独定价的 simple 和 variation。
	 *
	 * @return \WC_Product[]
	 */
	private function priced_items(): array {
		$out = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child && 'publish' === $child->get_status() ) {
						$out[] = $child;
					}
				}
			} else {
				$out[] = $product;
			}
		}
		return $out;
	}

	/**
	 * @param \WC_Product[] $items
	 */
	private function sync_parents( array $items ): void {
		$parents = array_unique( array_filter( array_map( static fn( $p ) => $p->is_type( 'variation' ) ? $p->get_parent_id() : 0, $items ) ) );
		foreach ( $parents as $parent_id ) {
			\WC_Product_Variable::sync( $parent_id );
		}
	}

	/**
	 * @return array<int,string> 商品 ID => 期望的当前价（active）。
	 */
	private function load_baseline( string $file ): array {
		if ( '' === $file ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || ! isset( $data['products'] ) ) {
			WP_CLI::error( 'Baseline file is not in the expected format.' );
		}
		$out = array();
		foreach ( $data['products'] as $row ) {
			if ( 'variable' !== ( $row['type'] ?? '' ) ) {
				$out[ (int) $row['id'] ] = (string) $row['active'];
			}
		}
		return $out;
	}

	/**
	 * @param \WC_Product[] $items
	 */
	private function write_backup( string $file, array $items ): void {
		if ( '' === $file ) {
			WP_CLI::error( '--backup=<file> is required.' );
		}
		if ( file_exists( $file ) ) {
			WP_CLI::error( "Backup file already exists: {$file}" );
		}
		$rows = array_map(
			static fn( $p ) => array(
				'id'        => $p->get_id(),
				'parent_id' => $p->get_parent_id(),
				'sku'       => $p->get_sku(),
				'regular'   => $p->get_regular_price( 'edit' ),
				'sale'      => $p->get_sale_price( 'edit' ),
				'active'    => $p->get_price( 'edit' ),
				'pricing'   => Pricing::record( $p ),
			),
			$items
		);
		wp_mkdir_p( dirname( $file ) );
		$ok = file_put_contents( $file, wp_json_encode( array( 'captured_at' => gmdate( 'c' ), 'currency' => get_woocommerce_currency(), 'items' => $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $ok ) {
			WP_CLI::error( "Could not write backup: {$file}" );
		}
		WP_CLI::log( sprintf( 'Backup of %d prices written to %s', count( $rows ), $file ) );
	}
}
