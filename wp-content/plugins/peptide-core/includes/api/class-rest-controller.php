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

	// 报告下载限流：每个来源每 10 分钟最多 120 次。
	private const DOWNLOAD_LIMIT  = 120;
	private const DOWNLOAD_WINDOW = 10 * MINUTE_IN_SECONDS;

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
			// slug 可能含百分号编码的中文等非 ASCII 字符，所以不限定字符集，由 sanitize_title 处理。
			'/products/(?P<slug>[^/]{1,400})/batches',
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
		$limited = self::rate_limit( 'download', self::DOWNLOAD_LIMIT, self::DOWNLOAD_WINDOW );
		if ( $limited ) {
			return $limited;
		}

		$report_id = (int) $request['id'];
		$user_id   = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = self::user_from_report_nonce( $request, $report_id );
		}
		$is_staff = Access::is_staff( $user_id );

		if ( Post_Types::REPORT !== get_post_type( $report_id ) || 'trash' === get_post_status( $report_id ) ) {
			return Errors::not_found();
		}

		$status = (string) get_post_meta( $report_id, Meta::REPORT_STATUS, true );
		$access = Access::for_report( $report_id, $user_id );

		if ( ! $is_staff ) {
			// 先判断“这份报告对当前用户是否可见”，全部不可见的情况统一返回 404，避免暴露报告是否存在：
			// 草稿、仅员工可见、所属批次不公开（且当前用户不是收到过这个批次的订单所有者）。
			if ( ! in_array( $status, array( 'published', 'superseded', 'withdrawn' ), true ) ) {
				return Errors::not_found();
			}
			if ( ! $access['allowed'] && null === $access['locked_reason'] ) {
				return Errors::not_found();
			}
			$batch_id = (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true );
			$is_owner = \PeptideCore\Order_Batches::user_received_batch( $user_id, $batch_id );
			if ( ! $is_owner && ( ! $batch_id || Catalog::public_batch_id_by_no( (string) get_post_meta( $batch_id, Meta::BATCH_NO, true ) ) !== $batch_id ) ) {
				return Errors::not_found();
			}
			if ( 'withdrawn' === $status ) {
				return new \WP_Error( 'pc_report_withdrawn', __( 'This report has been withdrawn.', 'peptide-core' ), array( 'status' => 410 ) );
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
	 * 下载链接里的专用 nonce（pc_nonce，只对这一份报告有效）：浏览器直接点链接时不会带 X-WP-Nonce，
	 * WordPress REST 会把请求当作访客。这里自己校验登录 Cookie 和这份报告专用的 nonce，
	 * 不在网址里放通用的 wp_rest nonce（它能用于其他 REST 写操作）。
	 */
	private static function user_from_report_nonce( \WP_REST_Request $request, int $report_id ): int {
		$nonce = (string) $request->get_param( 'pc_nonce' );
		if ( '' === $nonce || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return 0;
		}
		$user_id = wp_validate_auth_cookie( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'logged_in' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 由 wp_validate_auth_cookie 校验。
		if ( ! $user_id ) {
			return 0;
		}
		wp_set_current_user( $user_id );
		if ( ! wp_verify_nonce( $nonce, 'pc_report_' . $report_id ) ) {
			wp_set_current_user( 0 );
			return 0;
		}
		return (int) $user_id;
	}

	/**
	 * 访客 IP，用于限流。
	 * - 只有当 REMOTE_ADDR 属于 wp-config 中 PEPTIDE_CORE_TRUSTED_PROXIES（逗号分隔的 IP 或 CIDR，例如 Cloudflare 网段）时，
	 *   才采用 CF-Connecting-IP / X-Forwarded-For，防止访客伪造请求头绕过限流；
	 * - IPv6 按 /64 归为一个来源，防止换地址绕过。
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		$list   = defined( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) ? array_filter( array_map( 'trim', explode( ',', (string) PEPTIDE_CORE_TRUSTED_PROXIES ) ) ) : array();
		if ( $list && self::ip_in_list( $remote, $list ) ) {
			$forwarded = '';
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
				$forwarded = trim( $parts[0] );
			}
			if ( filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
				$ip = $forwarded;
			}
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			$ip  = inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}
		return $ip;
	}

	/**
	 * @param string[] $list IP 或 CIDR。
	 */
	private static function ip_in_list( string $ip, array $list ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return false;
		}
		foreach ( $list as $entry ) {
			[ $net, $bits ] = array_pad( explode( '/', $entry, 2 ), 2, null );
			$net_bin        = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits = null === $bits ? strlen( $bin ) * 8 : max( 0, min( strlen( $bin ) * 8, (int) $bits ) );
			$full = intdiv( $bits, 8 );
			$rest = $bits % 8;
			if ( substr( $bin, 0, $full ) !== substr( $net_bin, 0, $full ) ) {
				continue;
			}
			if ( 0 === $rest || ( ( ord( $bin[ $full ] ) ^ ord( $net_bin[ $full ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 按 IP 哈希计数的简单限流。超限返回 429 错误，否则返回 null。
	 */
	private static function rate_limit( string $bucket, int $limit, int $window ): ?\WP_Error {
		$ip  = self::client_ip();
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
