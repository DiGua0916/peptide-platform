<?php
/**
 * peptide/v1 只读接口（BE-03）。字段与错误码见方案 v1 D 节、v2 D' 节。
 *
 * - GET /site
 * - GET /products/{slug}/batches?include_history=0|1
 * - GET /batches/lookup?batch_no=
 * - GET /reports/{id}/file
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

use PeptideCore\Access;
use PeptideCore\Batches;
use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Private_Files;
use PeptideCore\Settings;

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {

	public const ROUTE_NAMESPACE = 'peptide/v1';

	// 批次查询限流：每个 IP 每 10 分钟最多 60 次。
	private const LOOKUP_LIMIT  = 60;
	private const LOOKUP_WINDOW = 10 * MINUTE_IN_SECONDS;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$public = '__return_true';

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_site' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/products/(?P<slug>[a-z0-9-]{1,200})/batches',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_product_batches' ),
				'permission_callback' => $public,
				'args'                => array(
					'include_history' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/batches/lookup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'lookup_batch' ),
				'permission_callback' => $public,
				'args'                => array(
					'batch_no'        => array(
						'type'     => 'string',
						'required' => false,
					),
					'include_history' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/reports/(?P<id>\d+)/file',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'download_report' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/me/orders/(?P<order_number>\d+)/batches',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_my_order_batches' ),
				'permission_callback' => $public,
			)
		);
	}

	/**
	 * 我的某个订单各行的批次与报告。订单号目前就是订单 ID（WooCommerce 默认）。
	 * 不存在和不属于本人都返回 404，不作区分。
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_my_order_batches( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error( 'pc_login_required', __( 'Please log in to view your orders.', 'peptide-core' ), array( 'status' => 401 ) );
		}
		$order = wc_get_order( (int) $request['order_number'] );
		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== $user_id ) {
			return Errors::not_found();
		}
		return rest_ensure_response( \PeptideCore\Order_Batches::customer_view( $order, $user_id ) );
	}

	public static function get_site(): \WP_REST_Response {
		return rest_ensure_response( Settings::public_config() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product_batches( \WP_REST_Request $request ) {
		$result = self::product_batches( (string) $request['slug'], get_current_user_id(), (bool) $request['include_history'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function lookup_batch( \WP_REST_Request $request ) {
		$limited = self::rate_limit( 'lookup', self::LOOKUP_LIMIT, self::LOOKUP_WINDOW );
		if ( $limited ) {
			return $limited;
		}
		$result = self::batch_by_no( (string) $request['batch_no'], get_current_user_id(), (bool) $request['include_history'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * 共享给主题 PHP 函数的实现。
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function product_batches( string $slug, int $user_id, bool $include_history ) {
		$product = Catalog::product_by_slug( sanitize_title( $slug ) );
		if ( ! $product ) {
			return Errors::not_found();
		}
		$batches = array();
		foreach ( Catalog::public_batch_ids( $product->get_id() ) as $batch_id ) {
			$batches[] = Catalog::batch( $batch_id, $user_id, $include_history );
		}
		return array(
			'product' => Catalog::product_summary( $product ),
			'batches' => $batches,
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function batch_by_no( string $batch_no, int $user_id, bool $include_history ) {
		$batch_no = Batches::normalize_batch_no( $batch_no );
		if ( '' === $batch_no ) {
			return Errors::invalid( array( 'batch_no' => 'required' ) );
		}
		if ( ! Batches::is_valid_batch_no( $batch_no ) ) {
			return Errors::invalid( array( 'batch_no' => 'invalid_format' ) );
		}
		$batch_id = Catalog::public_batch_id_by_no( $batch_no );
		if ( ! $batch_id ) {
			return Errors::not_found();
		}
		$product = wc_get_product( (int) get_post_meta( $batch_id, Meta::BATCH_PRODUCT_ID, true ) );
		if ( ! $product ) {
			return Errors::not_found();
		}
		return Catalog::batch( $batch_id, $user_id, $include_history ) + array( 'product' => Catalog::product_summary( $product ) );
	}

	/**
	 * 校验权限后输出文件流；出错时返回 JSON 错误。
	 *
	 * @return \WP_Error|void
	 */
	public static function download_report( \WP_REST_Request $request ) {
		$report_id = (int) $request['id'];
		$user_id   = get_current_user_id();
		$is_staff  = Access::is_staff( $user_id );

		if ( Post_Types::REPORT !== get_post_type( $report_id ) || 'trash' === get_post_status( $report_id ) ) {
			return Errors::not_found();
		}

		$status = (string) get_post_meta( $report_id, Meta::REPORT_STATUS, true );
		if ( ! $is_staff ) {
			if ( 'withdrawn' === $status ) {
				return new \WP_Error( 'pc_report_withdrawn', __( 'This report has been withdrawn.', 'peptide-core' ), array( 'status' => 410 ) );
			}
			if ( ! in_array( $status, array( 'published', 'superseded' ), true ) ) {
				return Errors::not_found();
			}
		}

		$access = Access::for_report( $report_id, $user_id );

		// 所属批次必须前台可见；订单所有者例外（隐藏批次也可能已经发给了他）。
		if ( ! $is_staff && 'order_owner' !== $access['access_reason'] ) {
			$batch_id = (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true );
			if ( ! $batch_id || Catalog::public_batch_id_by_no( (string) get_post_meta( $batch_id, Meta::BATCH_NO, true ) ) !== $batch_id ) {
				return Errors::not_found();
			}
		}
		if ( ! $access['allowed'] ) {
			if ( null === $access['locked_reason'] ) {
				return Errors::not_found();
			}
			return $user_id > 0
				? new \WP_Error( 'pc_forbidden', __( 'You do not have permission to download this report.', 'peptide-core' ), array( 'status' => 403 ) )
				: new \WP_Error( 'pc_login_required', __( 'Please log in to download this report.', 'peptide-core' ), array( 'status' => 401 ) );
		}

		$file = get_post_meta( $report_id, Meta::REPORT_FILE, true );
		$path = is_array( $file ) && ! empty( $file['stored'] ) ? Private_Files::path( (string) $file['stored'] ) : null;
		if ( ! $path || ! is_readable( $path ) ) {
			return Errors::not_found();
		}

		self::stream( $path, $file, 'superseded' === $status );
	}

	/**
	 * @param array<string,mixed> $file
	 * @return never
	 */
	private static function stream( string $path, array $file, bool $superseded ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		$name = sanitize_file_name( (string) ( $file['original'] ?? 'report' ) );
		header( 'Content-Type: ' . ( $file['mime'] ?? 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $superseded ) {
			header( 'X-PC-Report-Superseded: 1' );
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * 按 IP 哈希计数的简单限流。超限返回 429 错误，否则返回 null。
	 */
	private static function rate_limit( string $bucket, int $limit, int $window ): ?\WP_Error {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'pc_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$hit = get_transient( $key );
		$now = time();
		if ( ! is_array( $hit ) || $hit['reset'] <= $now ) {
			$hit = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		if ( $hit['count'] >= $limit ) {
			return new \WP_Error(
				'pc_rate_limited',
				__( 'Too many requests. Please try again later.', 'peptide-core' ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $hit['reset'] - $now ),
				)
			);
		}
		++$hit['count'];
		set_transient( $key, $hit, $hit['reset'] - $now );
		return null;
	}
}
