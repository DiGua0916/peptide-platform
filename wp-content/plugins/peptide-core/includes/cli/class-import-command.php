<?php
/**
 * WP-CLI：从报价表（.xlsx）导入商品（BE-06 的第一步）。
 *
 * 表格格式（按用户 2026-10-14 提供的报价表）：
 *   A 列 编号（作为 SKU）、B 列 产品名称（合并单元格，空白表示沿用上一行）、C 列 规格、D 列 价格。
 *   第一行价格是数字的行开始计为数据，前面的标题行自动跳过。
 *
 * 规则：
 * - 同名产品（忽略大小写和多余空格）合并为一个商品；只有一个规格时建简单商品，多个规格时建可变商品，属性为 “Pack”。
 * - 价格按表格数值原样写入（用户要求“按我后面的价格来”），货币沿用店铺设置。
 * - 同一商品内规格文字重复时，在规格后附上编号以区分；编号与已有 SKU 冲突时，在编号后附行号。
 * - 所有导入的商品都标记为演示数据（is_demo）并写入导入标签，可用 --delete 一次性删除。
 *   在用户取得合规意见并确认可销售的产品前，不导入为真实商品。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Cli;

use PeptideCore\Meta;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Import_Command {

	public const TAG_META = '_pc_import_tag';

	/**
	 * 从报价表导入商品。
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : .xlsx 文件路径。
	 *
	 * [--tag=<tag>]
	 * : 导入标签，用于识别和删除这批商品。
	 * ---
	 * default: quote
	 * ---
	 *
	 * [--dry-run]
	 * : 只解析并显示结果，不写入数据库。
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide import quote "C:/Users/me/Desktop/quote.xlsx" --tag=quote-2026-10-14 --dry-run
	 *     wp peptide import quote "C:/Users/me/Desktop/quote.xlsx" --tag=quote-2026-10-14
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function quote( array $args, array $assoc_args ): void {
		$file = $args[0];
		$tag  = sanitize_key( $assoc_args['tag'] ?? 'quote' );
		$dry  = ! empty( $assoc_args['dry-run'] );

		$rows     = $this->read_xlsx( $file );
		$products = $this->group( $rows );
		$skus     = array_sum( array_map( 'count', array_column( $products, 'skus' ) ) );

		WP_CLI::log( sprintf( 'Parsed %d price rows into %d products (%d SKUs).', count( $rows ), count( $products ), $skus ) );
		if ( $dry ) {
			foreach ( $products as $p ) {
				WP_CLI::log( sprintf( '- %s: %s', $p['name'], implode( '; ', array_map( static fn( $s ) => $s['code'] . ' ' . $s['label'] . ' = ' . $s['price'], $p['skus'] ) ) ) );
			}
			WP_CLI::success( 'Dry run only. Nothing was written.' );
			return;
		}

		if ( $this->tagged_ids( $tag ) ) {
			WP_CLI::error( "Products with import tag '{$tag}' already exist. Run `wp peptide import delete --tag={$tag}` first." );
		}

		$created = 0;
		foreach ( $products as $p ) {
			$this->create_product( $p, $tag );
			++$created;
		}
		WP_CLI::success( sprintf( 'Imported %d products (%d SKUs) with tag %s. All are marked as demo data.', $created, $skus, $tag ) );
	}

	/**
	 * 删除某个导入标签下的全部商品（包括变体）。
	 *
	 * ## OPTIONS
	 *
	 * --tag=<tag>
	 * : 导入时使用的标签。
	 *
	 * [--yes]
	 * : 跳过确认。
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		$tag = sanitize_key( $assoc_args['tag'] );
		$ids = $this->tagged_ids( $tag );
		if ( ! $ids ) {
			WP_CLI::success( "No products with import tag '{$tag}'." );
			return;
		}
		WP_CLI::confirm( sprintf( 'Delete %d imported products permanently?', count( $ids ) ), $assoc_args );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$child->delete( true );
				}
			}
			$product->delete( true );
		}
		WP_CLI::success( sprintf( 'Deleted %d products.', count( $ids ) ) );
	}

	/**
	 * @return int[]
	 */
	private function tagged_ids( string $tag ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => self::TAG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $tag, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * 读取第一个工作表的 A–D 列。
	 *
	 * @return array<int,array{row:int,code:string,name:string,spec:string,price:string}>
	 */
	private function read_xlsx( string $file ): array {
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "File not readable: {$file}" );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			WP_CLI::error( 'Not a valid .xlsx file.' );
		}
		$shared = array();
		$xml    = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $xml ) {
			$sst = simplexml_load_string( $xml );
			foreach ( $sst->si as $si ) {
				$text = '';
				foreach ( $si->xpath( './/*[local-name()="t"]' ) as $t ) {
					$text .= (string) $t;
				}
				$shared[] = $text;
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		if ( false === $sheet ) {
			WP_CLI::error( 'The first worksheet was not found.' );
		}

		$cells = array();
		$doc   = simplexml_load_string( $sheet );
		foreach ( $doc->xpath( '//*[local-name()="c"]' ) as $c ) {
			if ( ! preg_match( '/^([A-D])(\d+)$/', (string) $c['r'], $m ) ) {
				continue;
			}
			$v = $c->xpath( './*[local-name()="v"]' );
			if ( ! $v ) {
				continue;
			}
			$value = (string) $v[0];
			if ( 's' === (string) $c['t'] ) {
				$value = $shared[ (int) $value ] ?? '';
			}
			$cells[ (int) $m[2] ][ $m[1] ] = trim( preg_replace( '/\s+/u', ' ', $value ) );
		}
		ksort( $cells );

		$rows    = array();
		$current = '';
		foreach ( $cells as $num => $c ) {
			$price = $c['D'] ?? '';
			if ( '' !== ( $c['B'] ?? '' ) ) {
				$current = $c['B'];
			}
			if ( ! is_numeric( $price ) || '' === ( $c['A'] ?? '' ) || '' === $current ) {
				continue;
			}
			$rows[] = array(
				'row'   => $num,
				'code'  => $c['A'],
				'name'  => $current,
				'spec'  => $c['C'] ?? '',
				'price' => wc_format_decimal( $price ),
			);
		}
		if ( ! $rows ) {
			WP_CLI::error( 'No price rows found (expected columns: code, product, spec, price).' );
		}
		return $rows;
	}

	/**
	 * 按产品名合并，并为每个规格生成显示名称。
	 *
	 * @param array<int,array{row:int,code:string,name:string,spec:string,price:string}> $rows
	 * @return array<int,array{name:string,skus:array<int,array{code:string,label:string,price:string,row:int}>}>
	 */
	private function group( array $rows ): array {
		$groups = array();
		foreach ( $rows as $r ) {
			$key = strtolower( $r['name'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'name' => $r['name'],
					'skus' => array(),
				);
			}
			$label = trim( str_replace( '*', ' × ', $r['spec'] ) );
			$label = '' === $label ? $r['code'] : $label;
			$groups[ $key ]['skus'][] = array(
				'code'  => $r['code'],
				'label' => $label,
				'price' => $r['price'],
				'row'   => $r['row'],
			);
		}
		// 同一商品内规格文字重复时附上编号。
		foreach ( $groups as &$g ) {
			$counts = array_count_values( array_map( 'strtolower', array_column( $g['skus'], 'label' ) ) );
			foreach ( $g['skus'] as &$s ) {
				if ( $counts[ strtolower( $s['label'] ) ] > 1 ) {
					$s['label'] .= ' (' . $s['code'] . ')';
				}
			}
			unset( $s );
		}
		unset( $g );
		return array_values( $groups );
	}

	/**
	 * @param array{name:string,skus:array<int,array{code:string,label:string,price:string,row:int}>} $p
	 */
	private function create_product( array $p, string $tag ): void {
		$single  = 1 === count( $p['skus'] );
		$product = $single ? new \WC_Product_Simple() : new \WC_Product_Variable();
		$product->set_name( $p['name'] );
		$product->set_status( 'publish' );
		$product->set_short_description( 'Demo catalog entry imported from a supplier price list. Not a confirmed product listing.' );
		$product->update_meta_data( Meta::PRODUCT_IS_DEMO, '1' );
		$product->update_meta_data( self::TAG_META, $tag );

		if ( $single ) {
			$s = $p['skus'][0];
			$product->set_sku( $this->unique_sku( $s['code'], $s['row'] ) );
			$product->set_regular_price( $s['price'] );
			$product->update_meta_data(
				Meta::PRODUCT_SPECS,
				array(
					array(
						'key'   => 'pack',
						'label' => 'Pack',
						'value' => $s['label'],
						'unit'  => null,
					),
				)
			);
			$product->save();
			return;
		}

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Pack' );
		$attribute->set_options( array_column( $p['skus'], 'label' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$parent_id = $product->save();

		foreach ( $p['skus'] as $s ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'pack' => $s['label'] ) );
			$variation->set_sku( $this->unique_sku( $s['code'], $s['row'] ) );
			$variation->set_regular_price( $s['price'] );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $parent_id );
	}

	/**
	 * SKU 已被占用时在编号后附行号（例如报价表里重复的 H15）。
	 */
	private function unique_sku( string $code, int $row ): string {
		$sku = $code;
		if ( wc_get_product_id_by_sku( $sku ) ) {
			$sku = $code . '-R' . $row;
		}
		return $sku;
	}
}
