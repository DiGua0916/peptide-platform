<?php
/**
 * 批次、报告后台列表的附加列。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Reports;

defined( 'ABSPATH' ) || exit;

final class List_Columns {

	public static function register_hooks(): void {
		add_filter( 'manage_' . Post_Types::BATCH . '_posts_columns', array( self::class, 'batch_columns' ) );
		add_action( 'manage_' . Post_Types::BATCH . '_posts_custom_column', array( self::class, 'render_batch_column' ), 10, 2 );
		add_filter( 'manage_' . Post_Types::REPORT . '_posts_columns', array( self::class, 'report_columns' ) );
		add_action( 'manage_' . Post_Types::REPORT . '_posts_custom_column', array( self::class, 'render_report_column' ), 10, 2 );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function batch_columns( array $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'title'         => __( 'Batch number', 'peptide-core' ),
			'pc_product'    => __( 'Product', 'peptide-core' ),
			'pc_visibility' => __( 'Visibility', 'peptide-core' ),
			'pc_status'     => __( 'Status', 'peptide-core' ),
			'pc_reports'    => __( 'Reports', 'peptide-core' ),
			'pc_demo'       => __( 'Demo', 'peptide-core' ),
			'date'          => $columns['date'] ?? __( 'Date', 'peptide-core' ),
		);
	}

	public static function render_batch_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'pc_product':
				$product_id = (int) get_post_meta( $post_id, Meta::BATCH_PRODUCT_ID, true );
				if ( $product_id ) {
					printf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_post_link( $product_id ) ?? '' ), esc_html( get_the_title( $product_id ) ) );
				} else {
					echo '—';
				}
				break;
			case 'pc_visibility':
				echo esc_html( (string) get_post_meta( $post_id, Meta::BATCH_VISIBILITY, true ) );
				break;
			case 'pc_status':
				echo esc_html( (string) get_post_meta( $post_id, Meta::BATCH_STATUS, true ) );
				break;
			case 'pc_reports':
				echo esc_html( (string) count( Reports::ids_for_batch( $post_id ) ) );
				break;
			case 'pc_demo':
				echo Meta::bool( get_post_meta( $post_id, Meta::BATCH_IS_DEMO, true ) ) ? esc_html__( 'Yes', 'peptide-core' ) : '—';
				break;
		}
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function report_columns( array $columns ): array {
		return array(
			'cb'               => $columns['cb'] ?? '',
			'title'            => $columns['title'] ?? __( 'Title', 'peptide-core' ),
			'pc_batch'         => __( 'Batch', 'peptide-core' ),
			'pc_type'          => __( 'Type', 'peptide-core' ),
			'pc_version'       => __( 'Version', 'peptide-core' ),
			'pc_report_status' => __( 'Report status', 'peptide-core' ),
			'pc_access'        => __( 'Access', 'peptide-core' ),
			'pc_demo'          => __( 'Demo', 'peptide-core' ),
			'date'             => $columns['date'] ?? __( 'Date', 'peptide-core' ),
		);
	}

	public static function render_report_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'pc_batch':
				$batch_id = (int) get_post_meta( $post_id, Meta::REPORT_BATCH_ID, true );
				if ( $batch_id ) {
					printf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_post_link( $batch_id ) ?? '' ), esc_html( (string) get_post_meta( $batch_id, Meta::BATCH_NO, true ) ) );
				} else {
					echo '—';
				}
				break;
			case 'pc_type':
				echo esc_html( strtoupper( (string) get_post_meta( $post_id, Meta::REPORT_TYPE, true ) ) );
				break;
			case 'pc_version':
				$version = (int) get_post_meta( $post_id, Meta::REPORT_VERSION, true );
				echo $version ? esc_html( 'v' . $version ) : '—';
				break;
			case 'pc_report_status':
				echo esc_html( (string) get_post_meta( $post_id, Meta::REPORT_STATUS, true ) );
				break;
			case 'pc_access':
				echo esc_html( (string) get_post_meta( $post_id, Meta::REPORT_ACCESS, true ) );
				break;
			case 'pc_demo':
				echo Meta::bool( get_post_meta( $post_id, Meta::REPORT_IS_DEMO, true ) ) ? esc_html__( 'Yes', 'peptide-core' ) : '—';
				break;
		}
	}
}
