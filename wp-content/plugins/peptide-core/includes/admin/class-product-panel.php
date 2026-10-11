<?php
/**
 * WooCommerce 商品编辑页的 “Peptide data” 面板：演示标记、规格表，以及该商品的批次列表。
 *
 * 保存由 WooCommerce 的商品保存流程触发（它已校验 nonce 和 edit_product 权限）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Batches;
use PeptideCore\Meta;
use PeptideCore\Post_Types;

defined( 'ABSPATH' ) || exit;

final class Product_Panel {

	private const PANEL_ID = 'peptide_core_product_data';

	public static function register_hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', array( self::class, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( self::class, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_tab( array $tabs ): array {
		$tabs['peptide_core'] = array(
			'label'    => __( 'Peptide data', 'peptide-core' ),
			'target'   => self::PANEL_ID,
			'class'    => array(),
			'priority' => 80,
		);
		return $tabs;
	}

	public static function render_panel(): void {
		global $post;
		$product = wc_get_product( $post );
		if ( ! $product ) {
			return;
		}

		echo '<div id="' . esc_attr( self::PANEL_ID ) . '" class="panel woocommerce_options_panel hidden">';

		woocommerce_wp_checkbox(
			array(
				'id'          => 'pc_is_demo',
				'label'       => __( 'Demo product', 'peptide-core' ),
				'description' => __( 'Synthetic demonstration record. The storefront must show a "Demo" label.', 'peptide-core' ),
				'value'       => Meta::bool( $product->get_meta( Meta::PRODUCT_IS_DEMO ) ) ? 'yes' : 'no',
			)
		);

		woocommerce_wp_textarea_input(
			array(
				'id'          => 'pc_specs',
				'label'       => __( 'Specifications', 'peptide-core' ),
				'description' => __( 'One per line: Label | Value | Unit (unit optional). Do not enter usage, dosage or efficacy claims.', 'peptide-core' ),
				'desc_tip'    => false,
				'value'       => self::specs_to_text( self::get_specs( $product ) ),
				'rows'        => 8,
				'style'       => 'width:100%;height:auto;font-family:monospace;',
			)
		);

		self::render_batches( $product->get_id() );

		echo '</div>';
	}

	public static function save( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce 已在保存流程中校验 nonce。
		$product->update_meta_data( Meta::PRODUCT_IS_DEMO, isset( $_POST['pc_is_demo'] ) ? '1' : '0' );

		if ( isset( $_POST['pc_specs'] ) ) {
			$text = sanitize_textarea_field( wp_unslash( $_POST['pc_specs'] ) );
			$product->update_meta_data( Meta::PRODUCT_SPECS, self::parse_specs( $text ) );
		}
		// phpcs:enable
	}

	/**
	 * @return array<int,array{key:string,label:string,value:string,unit:?string}>
	 */
	public static function get_specs( \WC_Product $product ): array {
		$specs = $product->get_meta( Meta::PRODUCT_SPECS );
		return is_array( $specs ) ? $specs : array();
	}

	/**
	 * @return array<int,array{key:string,label:string,value:string,unit:?string}>
	 */
	public static function parse_specs( string $text ): array {
		$specs = array();
		$seen  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$label = $parts[0] ?? '';
			$value = $parts[1] ?? '';
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$key = sanitize_key( str_replace( ' ', '_', $label ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unit         = $parts[2] ?? '';
			$specs[]      = array(
				'key'   => $key,
				'label' => $label,
				'value' => $value,
				'unit'  => '' === $unit ? null : $unit,
			);
		}
		return $specs;
	}

	/**
	 * @param array<int,array{key:string,label:string,value:string,unit:?string}> $specs
	 */
	private static function specs_to_text( array $specs ): string {
		$lines = array();
		foreach ( $specs as $spec ) {
			$line = $spec['label'] . ' | ' . $spec['value'];
			if ( ! empty( $spec['unit'] ) ) {
				$line .= ' | ' . $spec['unit'];
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	private static function render_batches( int $product_id ): void {
		echo '<div class="options_group"><p class="form-field"><label>' . esc_html__( 'Batches', 'peptide-core' ) . '</label><span>';

		$ids = $product_id ? Batches::ids_for_product( $product_id ) : array();
		if ( ! $ids ) {
			esc_html_e( 'No batches yet.', 'peptide-core' );
		}
		foreach ( $ids as $batch_id ) {
			$batch_no = (string) get_post_meta( $batch_id, Meta::BATCH_NO, true );
			printf(
				'<a href="%1$s">%2$s</a><br>',
				esc_url( get_edit_post_link( $batch_id ) ?? '' ),
				esc_html( '' !== $batch_no ? $batch_no : '#' . $batch_id )
			);
		}

		if ( $product_id && current_user_can( 'edit_pc_batches' ) ) {
			printf(
				'<br><a class="button" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'post-new.php?post_type=' . Post_Types::BATCH . '&pc_product_id=' . $product_id ) ),
				esc_html__( 'Add batch for this product', 'peptide-core' )
			);
		}

		echo '</span></p></div>';
	}
}
