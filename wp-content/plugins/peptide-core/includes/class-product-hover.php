<?php
/**
 * 商品卡片的悬停内容（PRODUCT-HOVER-01）。在商品编辑页的 “Peptide data” 面板里按商品设置，主题的商品卡片按这里的结果显示。
 *
 * 模式：
 *   placeholder  报告占位卡片（默认，写明“仅供预览、没有检测数据”）
 *   report       真实批次报告摘要（来自该商品公开批次的当前报告；没有时退回 placeholder）
 *   gallery      商品相册里的第一张图（没有时退回 placeholder）
 *   image        自定义图片（没有时退回 placeholder）
 *   none         不显示
 *
 * 自定义图片不能是虚构的检测报告；真实报告只通过 report 模式从批次记录读取。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Modules\Assets;

defined( 'ABSPATH' ) || exit;

final class Product_Hover {

	public const MODE_META  = '_pc_hover_mode';
	public const IMAGE_META = '_pc_hover_image';
	public const ALT_META   = '_pc_hover_alt';

	/**
	 * @return array<string,string>
	 */
	public static function modes(): array {
		return array(
			'placeholder' => __( 'Report placeholder card (default)', 'peptide-core' ),
			'report'      => __( 'Real batch report summary (placeholder when no report is published)', 'peptide-core' ),
			'gallery'     => __( 'First image of the product gallery', 'peptide-core' ),
			'image'       => __( 'Custom image', 'peptide-core' ),
			'none'        => __( 'Nothing', 'peptide-core' ),
		);
	}

	public static function mode( \WC_Product $product ): string {
		$mode = (string) $product->get_meta( self::MODE_META );
		return isset( self::modes()[ $mode ] ) ? $mode : 'placeholder';
	}

	/**
	 * 某个商品卡片悬停时要显示的内容。mode 是实际生效的模式（缺内容时已退回 placeholder），requested 是后台选择的模式。
	 *
	 * @return array{mode:string,requested:string,image:?array,report:?array}
	 */
	public static function for_product( \WC_Product $product ): array {
		$requested = self::mode( $product );
		$out       = array(
			'mode'      => $requested,
			'requested' => $requested,
			'image'     => null,
			'report'    => null,
		);

		if ( 'image' === $requested || 'gallery' === $requested ) {
			$id = 'image' === $requested ? (int) $product->get_meta( self::IMAGE_META ) : (int) ( $product->get_gallery_image_ids()[0] ?? 0 );
			$alt = (string) $product->get_meta( self::ALT_META );
			$img = $id ? Assets::resolve(
				array(
					'attachmentId' => $id,
					'alt'          => '' !== $alt ? $alt : $product->get_name(),
				),
				'woocommerce_single'
			) : null;
			if ( $img ) {
				$out['image'] = $img;
			} else {
				$out['mode'] = 'placeholder';
			}
		} elseif ( 'report' === $requested ) {
			$report = self::latest_report( $product->get_id() );
			if ( $report ) {
				$out['report'] = $report;
			} else {
				$out['mode'] = 'placeholder';
			}
		}
		return $out;
	}

	/**
	 * 该商品公开批次里最新的当前报告（只取目录信息，不含文件和下载地址）。
	 *
	 * @return array{batch_no:string,title:string,type:string,version:int,issued_on:?string,issuer_name:?string,is_demo:bool,access:string}|null
	 */
	public static function latest_report( int $product_id ): ?array {
		$best = null;
		foreach ( Catalog::public_batch_ids( $product_id ) as $batch_id ) {
			$batch = Catalog::batch( (int) $batch_id, 0 );
			foreach ( $batch['reports'] as $r ) {
				if ( empty( $r['is_current'] ) ) {
					continue;
				}
				$row = array(
					'batch_no'    => $batch['batch_no'],
					'title'       => (string) $r['title'],
					'type'        => (string) $r['type'],
					'version'     => (int) $r['version'],
					'issued_on'   => $r['issued_on'],
					'issuer_name' => $r['issuer_name'],
					'is_demo'     => (bool) $r['is_demo'] || (bool) $batch['is_demo'],
					'access'      => (string) $r['access'],
				);
				if ( null === $best || (string) $row['issued_on'] > (string) $best['issued_on'] ) {
					$best = $row;
				}
			}
		}
		return $best;
	}

	/**
	 * 商品编辑页保存（由 Product_Panel::save 调用，WooCommerce 已校验 nonce 和权限）。
	 *
	 * @param array<string,mixed> $input 表单字段：pc_hover_mode、pc_hover_image、pc_hover_alt
	 */
	public static function save( \WC_Product $product, array $input ): void {
		$mode = sanitize_key( (string) ( $input['pc_hover_mode'] ?? 'placeholder' ) );
		$product->update_meta_data( self::MODE_META, isset( self::modes()[ $mode ] ) ? $mode : 'placeholder' );
		$image = absint( $input['pc_hover_image'] ?? 0 );
		$product->update_meta_data( self::IMAGE_META, $image && wp_attachment_is_image( $image ) ? $image : 0 );
		$alt = sanitize_text_field( (string) ( $input['pc_hover_alt'] ?? '' ) );
		$product->update_meta_data( self::ALT_META, function_exists( 'mb_substr' ) ? mb_substr( $alt, 0, 200 ) : substr( $alt, 0, 200 ) );
	}
}
