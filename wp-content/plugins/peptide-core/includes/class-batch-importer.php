<?php
/**
 * 批次与检测报告的批量导入（BE-06 第二步）。CLI 和测试都调用这里，本类不直接输出。
 *
 * CSV 列（第一行是表头，列名不区分大小写，顺序不限）：
 *   sku（必填，商品或某个规格的 SKU）、batch_no（必填）、
 *   manufactured_on、retest_or_expiry_on（YYYY-MM-DD）、visibility（public/hidden）、status（active/depleted）、
 *   origin_note、report_pending（yes/no）、is_demo（yes/no），
 *   report_file（报告文件名，放在 --reports-dir 指定的文件夹里）、report_type（coa/hplc/ms/other）、
 *   report_access（public/registered/approved/admin）、report_issued_on、report_issuer、report_title。
 *
 * 同一个 batch_no 可以写多行：用于一个批次对应多个规格（每行一个规格 SKU），或一个批次附多份报告。
 * Excel 在中文 Windows 下另存的 CSV 常是 GB18030 编码，读取时会自动转换成 UTF-8。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Batch_Importer {

	public const TAG_META = '_pc_import_tag';

	private const YES = array( 'yes', 'y', '1', 'true', '是' );

	/**
	 * 读取 CSV，返回 [行号 => 列名 => 值]。
	 *
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	public static function read_csv( string $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'pc_import_file', 'CSV file not readable: ' . $path );
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'GB18030' );
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$header = null;
		$rows   = array();
		$line   = 0;
		while ( false !== ( $cols = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $cols || '' === trim( implode( '', $cols ) ) ) {
				continue;
			}
			if ( null === $header ) {
				$header = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $cols );
				continue;
			}
			$row = array();
			foreach ( $header as $i => $key ) {
				if ( '' !== $key ) {
					$row[ $key ] = trim( (string) ( $cols[ $i ] ?? '' ) );
				}
			}
			$rows[ $line ] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( null === $header || ! in_array( 'sku', $header, true ) || ! in_array( 'batch_no', $header, true ) ) {
			return new \WP_Error( 'pc_import_header', 'The CSV must have a header row with at least "sku" and "batch_no".' );
		}
		return $rows;
	}

	/**
	 * 校验并整理成按批次分组的计划，不写数据库。
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array{batches:array<string,array<string,mixed>>,errors:array<int,string[]>}
	 */
	public static function plan( array $rows, string $reports_dir, bool $update ): array {
		$batches = array();
		$errors  = array();

		foreach ( $rows as $line => $r ) {
			$err = array();

			$batch_no = Batches::normalize_batch_no( $r['batch_no'] ?? '' );
			if ( ! Batches::is_valid_batch_no( $batch_no ) ) {
				$err[] = 'invalid batch_no';
			}

			$sku        = $r['sku'] ?? '';
			$product_id = 0;
			$variation  = 0;
			$id         = '' !== $sku ? wc_get_product_id_by_sku( $sku ) : 0;
			$product    = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				$err[] = 'unknown sku "' . $sku . '"';
			} elseif ( $product->is_type( 'variation' ) ) {
				$product_id = $product->get_parent_id();
				$variation  = $product->get_id();
			} else {
				$product_id = $product->get_id();
			}

			foreach ( array( 'manufactured_on', 'retest_or_expiry_on', 'report_issued_on' ) as $date_key ) {
				$value = $r[ $date_key ] ?? '';
				if ( '' !== $value && '' === Meta::sanitize_date( $value ) ) {
					$err[] = $date_key . ' must be YYYY-MM-DD';
				}
			}
			$visibility = strtolower( $r['visibility'] ?? '' ) ?: 'public';
			if ( ! in_array( $visibility, Batches::VISIBILITIES, true ) ) {
				$err[] = 'visibility must be public or hidden';
			}
			$status = strtolower( $r['status'] ?? '' ) ?: 'active';
			if ( ! in_array( $status, Batches::STATUSES, true ) ) {
				$err[] = 'status must be active or depleted';
			}

			$report = null;
			$file   = $r['report_file'] ?? '';
			if ( '' !== $file ) {
				$path   = rtrim( $reports_dir, '/\\' ) . DIRECTORY_SEPARATOR . wp_basename( $file );
				$type   = strtolower( $r['report_type'] ?? '' ) ?: 'coa';
				$access = strtolower( $r['report_access'] ?? '' ) ?: 'public';
				if ( '' === $reports_dir || ! is_file( $path ) ) {
					$err[] = 'report file not found: ' . $file;
				}
				if ( ! in_array( $type, Reports::TYPES, true ) ) {
					$err[] = 'report_type must be one of ' . implode( '/', Reports::TYPES );
				}
				if ( ! in_array( $access, Reports::ACCESS, true ) ) {
					$err[] = 'report_access must be one of ' . implode( '/', Reports::ACCESS );
				}
				$report = array(
					'path'      => $path,
					'type'      => $type,
					'access'    => $access,
					'issued_on' => Meta::sanitize_date( $r['report_issued_on'] ?? '' ),
					'issuer'    => sanitize_text_field( $r['report_issuer'] ?? '' ),
					'title'     => sanitize_text_field( $r['report_title'] ?? '' ),
				);
			}

			if ( $err ) {
				$errors[ $line ] = $err;
				continue;
			}

			if ( ! isset( $batches[ $batch_no ] ) ) {
				$existing = Batches::find_id_by_batch_no( $batch_no );
				if ( $existing && ! $update ) {
					$errors[ $line ] = array( 'batch_no ' . $batch_no . ' already exists (use --update to add to it)' );
					continue;
				}
				$batches[ $batch_no ] = array(
					'existing_id'         => $existing,
					'product_id'          => $product_id,
					'variation_ids'       => array(),
					'manufactured_on'     => Meta::sanitize_date( $r['manufactured_on'] ?? '' ),
					'retest_or_expiry_on' => Meta::sanitize_date( $r['retest_or_expiry_on'] ?? '' ),
					'visibility'          => $visibility,
					'status'              => $status,
					'origin_note'         => sanitize_text_field( $r['origin_note'] ?? '' ),
					'report_pending'      => in_array( strtolower( $r['report_pending'] ?? '' ), self::YES, true ),
					'is_demo'             => in_array( strtolower( $r['is_demo'] ?? '' ), self::YES, true ),
					'reports'             => array(),
					'lines'               => array(),
				);
			} elseif ( $batches[ $batch_no ]['product_id'] !== $product_id ) {
				$errors[ $line ] = array( 'batch_no ' . $batch_no . ' is used for two different products' );
				continue;
			}

			if ( $variation && ! in_array( $variation, $batches[ $batch_no ]['variation_ids'], true ) ) {
				$batches[ $batch_no ]['variation_ids'][] = $variation;
			}
			if ( $report ) {
				$batches[ $batch_no ]['reports'][] = $report;
			}
			$batches[ $batch_no ]['lines'][] = $line;
		}

		// 某个批次的任意一行有错，整个批次都不导入，避免只导入一半。
		foreach ( $batches as $batch_no => $b ) {
			if ( array_intersect( $b['lines'], array_keys( $errors ) ) ) {
				unset( $batches[ $batch_no ] );
			}
		}

		return array(
			'batches' => $batches,
			'errors'  => $errors,
		);
	}

	/**
	 * 按计划写入数据库。返回 [批次号 => [batch_id, report_ids[]]]；单份报告失败时记入 errors。
	 *
	 * @param array<string,array<string,mixed>> $batches
	 * @return array{created:array<string,array{batch_id:int,report_ids:int[]}>,errors:string[]}
	 */
	public static function run( array $batches, string $tag ): array {
		$created = array();
		$errors  = array();

		foreach ( $batches as $batch_no => $b ) {
			$meta = array(
				Meta::BATCH_NO             => $batch_no,
				Meta::BATCH_PRODUCT_ID     => $b['product_id'],
				Meta::BATCH_VARIATION_IDS  => $b['variation_ids'],
				Meta::BATCH_VISIBILITY     => $b['visibility'],
				Meta::BATCH_STATUS         => $b['status'],
				Meta::BATCH_MANUFACTURED   => $b['manufactured_on'],
				Meta::BATCH_RETEST_EXPIRY  => $b['retest_or_expiry_on'],
				Meta::BATCH_ORIGIN_NOTE    => $b['origin_note'],
				Meta::BATCH_REPORT_PENDING => $b['report_pending'],
				Meta::BATCH_IS_DEMO        => $b['is_demo'],
			);

			if ( $b['existing_id'] ) {
				$batch_id = (int) $b['existing_id'];
				// 更新时合并规格列表，不清空原有字段；只覆盖表格里填写了的值。
				$old_variations = array_map( 'intval', (array) get_post_meta( $batch_id, Meta::BATCH_VARIATION_IDS, true ) );
				$meta[ Meta::BATCH_VARIATION_IDS ] = array_values( array_unique( array_merge( $old_variations, $b['variation_ids'] ) ) );
				foreach ( $meta as $key => $value ) {
					if ( '' !== $value && array() !== $value ) {
						update_post_meta( $batch_id, $key, $value );
					}
				}
			} else {
				$meta[ self::TAG_META ] = $tag;
				$batch_id               = wp_insert_post(
					array(
						'post_type'   => Post_Types::BATCH,
						'post_status' => 'publish',
						'post_title'  => $batch_no,
						'meta_input'  => $meta,
					),
					true
				);
				if ( is_wp_error( $batch_id ) ) {
					$errors[] = $batch_no . ': ' . $batch_id->get_error_message();
					continue;
				}
			}

			$report_ids = array();
			foreach ( $b['reports'] as $rep ) {
				$file = Private_Files::store_local_file( $rep['path'] );
				if ( is_wp_error( $file ) ) {
					$errors[] = $batch_no . ' / ' . wp_basename( $rep['path'] ) . ': ' . $file->get_error_message();
					continue;
				}
				$title     = '' !== $rep['title'] ? $rep['title'] : strtoupper( $rep['type'] ) . ' ' . $batch_no;
				$report_id = wp_insert_post(
					array(
						'post_type'   => Post_Types::REPORT,
						'post_status' => 'publish',
						'post_title'  => $title,
						'meta_input'  => array(
							Meta::REPORT_BATCH_ID    => (int) $batch_id,
							Meta::REPORT_TYPE        => $rep['type'],
							Meta::REPORT_VERSION     => Reports::next_version( (int) $batch_id, $rep['type'] ),
							Meta::REPORT_STATUS      => 'published',
							Meta::REPORT_ACCESS      => $rep['access'],
							Meta::REPORT_IS_DEMO     => $b['is_demo'],
							Meta::REPORT_ISSUED_ON   => $rep['issued_on'],
							Meta::REPORT_ISSUER_NAME => $rep['issuer'],
							Meta::REPORT_FILE        => $file,
							self::TAG_META           => $tag,
						),
					),
					true
				);
				if ( is_wp_error( $report_id ) ) {
					Private_Files::delete( $file['stored'] );
					$errors[] = $batch_no . ' / ' . $title . ': ' . $report_id->get_error_message();
					continue;
				}
				Reports::supersede_previous( (int) $report_id );
				$report_ids[] = (int) $report_id;
			}

			$created[ $batch_no ] = array(
				'batch_id'   => (int) $batch_id,
				'report_ids' => $report_ids,
			);
		}

		return array(
			'created' => $created,
			'errors'  => $errors,
		);
	}

	/**
	 * 某个导入标签下的批次和报告 ID。
	 *
	 * @return int[]
	 */
	public static function tagged_ids( string $tag ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => array( Post_Types::BATCH, Post_Types::REPORT ),
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
}
