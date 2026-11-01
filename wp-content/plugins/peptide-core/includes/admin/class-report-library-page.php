<?php
/**
 * Read-only report workspace: parent products, then reports grouped by issued date.
 *
 * Native report editing and protected downloads remain the only action targets.
 * This view does not create batches, publish reports or change product/stock data.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Catalog;
use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Reports;

defined( 'ABSPATH' ) || exit;

final class Report_Library_Page {

	public const SLUG = 'pc-product-reports';
	public const CAP = 'edit_pc_reports';

	private const PER_PAGE = 25;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 80 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1100 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ), 40 );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 40 );
	}

	public static function allowed(): bool {
		return current_user_can( self::CAP );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=product', __( '检测报告', 'peptide-core' ), __( '检测报告', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	/** A product ID of zero opens reports without a valid parent product. */
	public static function url( ?int $product_id = null ): string {
		$args = array( 'page' => self::SLUG );
		if ( null !== $product_id ) {
			$args['product_id'] = max( 0, $product_id );
			if ( 0 === $product_id ) {
				$args['view'] = 'unassigned';
			}
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function parent_file( $parent ) {
		return self::is_page() ? 'edit.php?post_type=product' : $parent;
	}

	public static function submenu_file( $submenu ) {
		return self::is_page() ? self::SLUG : $submenu;
	}

	public static function assets(): void {
		if ( ! self::is_page() || ! self::allowed() ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/report-library.css';
		$deps = wp_style_is( 'pc-admin-workspace', 'registered' ) ? array( 'pc-admin-workspace' ) : array();
		$ver  = PEPTIDE_CORE_VERSION . '.' . ( is_file( $file ) ? (string) filemtime( $file ) : '0' );
		wp_enqueue_style( 'pc-report-library', plugins_url( 'assets/report-library.css', PEPTIDE_CORE_FILE ), $deps, $ver );
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( '你没有管理检测报告的权限。', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			echo '<div class="wrap pc-report-library"><h1>' . esc_html__( '检测报告', 'peptide-core' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( '商品服务暂时不可用。请确认 WooCommerce 已启用后重试。', 'peptide-core' ) . '</p></div></div>';
			return;
		}

		$data        = self::read_model();
		$selection   = 'unassigned' === self::query( 'view' ) ? '0' : self::query( 'product_id' );
		$is_selected = '' !== $selection;
		$product_id  = ctype_digit( $selection ) ? (int) $selection : -1;
		echo '<div class="wrap pc-report-library"><div class="pc-report-library__heading"><h1>' . esc_html__( '检测报告', 'peptide-core' ) . '</h1><div class="pc-report-library__actions">';
		self::new_report_link( $is_selected && $product_id > 0 && isset( $data['products'][ $product_id ] ) ? $product_id : null );
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Types::REPORT ) ) . '">' . esc_html__( '原生报告列表', 'peptide-core' ) . '</a></div></div>';
		echo '<p class="pc-report-library__intro">' . esc_html__( '先选产品，再按报告签发日期管理。未填写日期的资料列在最后；来源资料与实物批次的关系需逐份核对。', 'peptide-core' ) . '</p>';

		if ( $is_selected ) {
			if ( $product_id < 0 || ( $product_id > 0 && ! isset( $data['products'][ $product_id ] ) ) ) {
				echo '<div class="pc-report-library__empty"><p>' . esc_html__( '未找到该产品。请返回产品目录重新选择。', 'peptide-core' ) . '</p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( '返回产品目录', 'peptide-core' ) . '</a></div>';
			} else {
				self::render_product( $product_id, $data );
			}
		} else {
			self::render_directory( $data );
		}
		echo '</div>';
	}

	/**
	 * Read all existing parent products, including those that have no reports.
	 * Reports::product_id() gives a real batch association priority over source metadata.
	 *
	 * @return array{products:array<int,\WC_Product>,reports:array<int,int[]>}
	 */
	private static function read_model(): array {
		$products = array();
		foreach ( wc_get_products( array( 'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC' ) ) as $product ) {
			if ( $product instanceof \WC_Product && ! $product->is_type( 'variation' ) ) {
				$products[ $product->get_id() ] = $product;
			}
		}
		$reports = array_fill_keys( array_merge( array( 0 ), array_keys( $products ) ), array() );
		$ids     = get_posts( array( 'post_type' => Post_Types::REPORT, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ) );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		foreach ( $ids as $id ) {
			$id         = (int) $id;
			$product_id = Reports::product_id( $id );
			$reports[ isset( $products[ $product_id ] ) ? $product_id : 0 ][] = $id;
		}
		return array( 'products' => $products, 'reports' => $reports );
	}

	/** @param array{products:array<int,\WC_Product>,reports:array<int,int[]>} $data */
	private static function render_directory( array $data ): void {
		$search = self::query( 'report_search' );
		$filter = self::query( 'report_filter' );
		$filter = in_array( $filter, array( 'with', 'without' ), true ) ? $filter : '';
		$items  = array();
		foreach ( $data['products'] as $id => $product ) {
			if ( '' !== $search && false === stripos( $product->get_name() . ' ' . $product->get_sku(), $search ) ) {
				continue;
			}
			$count = count( $data['reports'][ $id ] );
			if ( ( 'with' === $filter && ! $count ) || ( 'without' === $filter && $count ) ) {
				continue;
			}
			$items[ $id ] = $product;
		}
		$pages = max( 1, (int) ceil( count( $items ) / self::PER_PAGE ) );
		$page  = max( 1, min( $pages, absint( self::query( 'report_page' ) ) ?: 1 ) );
		echo '<form class="pc-report-library__toolbar" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><div class="pc-report-library__search"><label class="screen-reader-text" for="pc-report-search">' . esc_html__( '搜索产品名称或 SKU', 'peptide-core' ) . '</label><input type="search" id="pc-report-search" name="report_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( '搜索产品名称或 SKU', 'peptide-core' ) . '"></div><label class="screen-reader-text" for="pc-report-filter">' . esc_html__( '报告收录情况', 'peptide-core' ) . '</label><select id="pc-report-filter" name="report_filter"><option value="">' . esc_html__( '全部产品', 'peptide-core' ) . '</option><option value="with" ' . selected( $filter, 'with', false ) . '>' . esc_html__( '已有报告', 'peptide-core' ) . '</option><option value="without" ' . selected( $filter, 'without', false ) . '>' . esc_html__( '暂无报告', 'peptide-core' ) . '</option></select><button class="button" type="submit">' . esc_html__( '筛选', 'peptide-core' ) . '</button>';
		if ( '' !== $search || '' !== $filter ) {
			echo '<a href="' . esc_url( self::url() ) . '">' . esc_html__( '清除筛选', 'peptide-core' ) . '</a>';
		}
		echo '</form><p class="pc-report-library__count">' . esc_html( sprintf( __( '%1$d 个产品 · 共 %2$d 份报告', 'peptide-core' ), count( $data['products'] ), array_sum( array_map( 'count', $data['reports'] ) ) ) ) . '</p>';
		if ( $data['reports'][0] ) {
			echo '<p class="pc-report-library__unassigned"><a href="' . esc_url( self::url( 0 ) ) . '">' . esc_html( sprintf( __( '查看 %d 份未关联产品的资料', 'peptide-core' ), count( $data['reports'][0] ) ) ) . '</a></p>';
		}
		if ( ! $items ) {
			echo '<div class="pc-report-library__empty"><h2>' . esc_html__( '没有匹配的产品', 'peptide-core' ) . '</h2><p>' . esc_html__( '试试其他产品名称，或清除筛选查看全部产品。', 'peptide-core' ) . '</p></div>';
			return;
		}
		echo '<div class="pc-workspace-table pc-report-library__table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( '产品报告目录，可横向滚动', 'peptide-core' ) . '"><table class="widefat pc-report-library__table pc-report-library__products"><thead><tr><th scope="col">' . esc_html__( '产品', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '报告数量', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '最新签发日期', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '待核验资料', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '产品状态', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '操作', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $items, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE, true ) as $id => $product ) {
			$ids    = $data['reports'][ $id ];
			$groups = self::date_groups( $ids );
			$dates  = array_filter( array_keys( $groups ) );
			$latest = $dates ? (string) reset( $dates ) : '';
			$state  = get_post_status_object( $product->get_status() );
			$unbound = count( array_filter( $ids, static fn( int $report_id ): bool => (int) get_post_meta( $report_id, Meta::REPORT_BATCH_ID, true ) <= 0 ) );
			echo '<tr><th scope="row"><a class="row-title" href="' . esc_url( self::url( $id ) ) . '">' . esc_html( $product->get_name() ) . '</a><span class="pc-report-library__subtext">' . esc_html( $product->get_sku() ?: __( '未填写 SKU', 'peptide-core' ) ) . '</span></th><td>' . esc_html( (string) count( $ids ) ) . '</td><td>' . ( $latest ? '<time datetime="' . esc_attr( $latest ) . '">' . esc_html( $latest ) . '</time>' : esc_html__( '暂无日期', 'peptide-core' ) ) . '</td><td>' . esc_html( (string) $unbound ) . '</td><td>' . esc_html( $state ? $state->label : $product->get_status() ) . '</td><td><a href="' . esc_url( self::url( $id ) ) . '">' . esc_html__( '查看报告', 'peptide-core' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $pages > 1 ) {
			echo '<nav class="pc-report-library__pagination" aria-label="' . esc_attr__( '产品目录分页', 'peptide-core' ) . '"><span>' . esc_html( sprintf( __( '第 %1$d / %2$d 页 · 筛选结果 %3$d 个产品', 'peptide-core' ), $page, $pages, count( $items ) ) ) . '</span>';
			for ( $i = 1; $i <= $pages; ++$i ) {
				if ( $i === $page ) {
					echo '<span class="pc-report-library__current" aria-current="page">' . esc_html( (string) $i ) . '</span>';
				} else {
					echo '<a href="' . esc_url( add_query_arg( array( 'report_search' => $search, 'report_filter' => $filter, 'report_page' => $i ), self::url() ) ) . '" aria-label="' . esc_attr( sprintf( __( '第 %d 页', 'peptide-core' ), $i ) ) . '">' . esc_html( (string) $i ) . '</a>';
				}
			}
			echo '</nav>';
		}
	}

	/** @param array{products:array<int,\WC_Product>,reports:array<int,int[]>} $data */
	private static function render_product( int $product_id, array $data ): void {
		$product = $data['products'][ $product_id ] ?? null;
		$ids     = $data['reports'][ $product_id ];
		echo '<a class="pc-report-library__back" href="' . esc_url( self::url() ) . '">' . esc_html__( '返回产品目录', 'peptide-core' ) . '</a><div class="pc-report-library__product-heading"><div><h2>' . esc_html( $product ? $product->get_name() : __( '未关联产品的资料', 'peptide-core' ) ) . '</h2><p>' . esc_html( sprintf( __( '%d 份报告', 'peptide-core' ), count( $ids ) ) );
		if ( $product && $product->get_sku() ) {
			echo ' · ' . esc_html( $product->get_sku() );
		}
		echo '</p></div>';
		if ( $product && current_user_can( 'edit_post', $product_id ) ) {
			echo '<a class="button" href="' . esc_url( get_edit_post_link( $product_id ) ?? '' ) . '">' . esc_html__( '编辑产品', 'peptide-core' ) . '</a>';
		}
		echo '</div><p class="pc-report-library__note">' . esc_html__( '草稿和待核验来源资料不代表已发布的检测结论。来源报告编号、样品号和文件名称不会自动成为店铺批次号。', 'peptide-core' ) . '</p>';
		if ( ! $ids ) {
			echo '<div class="pc-report-library__empty"><h3>' . esc_html__( '该产品暂无报告', 'peptide-core' ) . '</h3><p>' . esc_html__( '可通过新增报告录入已有资料，并保留真实签发日期。未知日期留空即可。', 'peptide-core' ) . '</p></div>';
			return;
		}
		foreach ( self::date_groups( $ids ) as $date => $group ) {
			echo '<details class="pc-report-library__date" open><summary><span>' . ( '' !== $date ? '<time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time>' : esc_html__( '日期未填写', 'peptide-core' ) ) . '</span><span class="pc-report-library__subtext">' . esc_html( sprintf( __( '%d 份报告', 'peptide-core' ), count( $group ) ) ) . '</span></summary><div class="pc-workspace-table pc-report-library__table-scroll" tabindex="0" role="region" aria-label="' . esc_attr( sprintf( __( '%s 的报告，可横向滚动', 'peptide-core' ), $date ?: __( '日期未填写', 'peptide-core' ) ) ) . '"><table class="widefat pc-report-library__table pc-report-library__reports"><thead><tr><th scope="col">' . esc_html__( '报告与类型', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '规格', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '来源报告编号', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '报告状态', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '文件', 'peptide-core' ) . '</th><th scope="col">' . esc_html__( '操作', 'peptide-core' ) . '</th></tr></thead><tbody>';
			foreach ( $group as $id ) {
				self::render_report( $id );
			}
			echo '</tbody></table></div></details>';
		}
	}

	private static function render_report( int $id ): void {
		$status_labels = array( 'draft' => __( '草稿', 'peptide-core' ), 'published' => __( '已发布', 'peptide-core' ), 'superseded' => __( '历史版本', 'peptide-core' ), 'withdrawn' => __( '已撤回', 'peptide-core' ) );
		$type_labels   = array( 'coa' => 'COA', 'hplc' => 'HPLC', 'ms' => 'MS', 'other' => __( '其他', 'peptide-core' ) );
		$status        = (string) get_post_meta( $id, Meta::REPORT_STATUS, true );
		$type          = (string) get_post_meta( $id, Meta::REPORT_TYPE, true );
		$batch_id      = (int) get_post_meta( $id, Meta::REPORT_BATCH_ID, true );
		$version       = (int) get_post_meta( $id, Meta::REPORT_VERSION, true );
		$file          = get_post_meta( $id, Meta::REPORT_FILE, true );
		$filename      = is_array( $file ) && is_string( $file['original'] ?? null ) ? wp_basename( str_replace( '\\', '/', $file['original'] ) ) : '';
		$has_file      = is_array( $file ) && ! empty( $file['stored'] );
		$edit          = current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : null;
		$title         = get_the_title( $id ) ?: __( '未命名报告', 'peptide-core' );
		echo '<tr><th scope="row">';
		if ( $edit ) {
			echo '<a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo '<strong>' . esc_html( $title ) . '</strong>';
		}
		echo '<span class="pc-report-library__subtext">' . esc_html( $type_labels[ $type ] ?? __( '未设置类型', 'peptide-core' ) );
		if ( $version > 0 ) {
			echo ' · ' . esc_html( sprintf( __( '版本 %d', 'peptide-core' ), $version ) );
		}
		echo '</span>';
		if ( $batch_id > 0 && Post_Types::BATCH === get_post_type( $batch_id ) ) {
			$batch_no = (string) get_post_meta( $batch_id, Meta::BATCH_NO, true );
			echo '<span class="pc-report-library__subtext">' . esc_html( sprintf( __( '关联批次：%s', 'peptide-core' ), $batch_no ?: __( '未填写批次号', 'peptide-core' ) ) ) . '</span>';
		}
		self::render_source( $id );
		echo '</th><td>' . esc_html( (string) get_post_meta( $id, Meta::REPORT_SPECIFICATION, true ) ?: '—' ) . '</td><td>' . esc_html( (string) get_post_meta( $id, Meta::REPORT_REFERENCE_ID, true ) ?: '—' ) . '</td><td><span class="pc-report-library__status">' . esc_html( $status_labels[ $status ] ?? __( '未设置状态', 'peptide-core' ) ) . '</span>';
		if ( $batch_id <= 0 ) {
			echo '<span class="pc-report-library__subtext">' . esc_html__( '来源资料 · 待核验', 'peptide-core' ) . '</span>';
		}
		echo '</td><td>' . esc_html( $filename ?: __( '未附文件', 'peptide-core' ) ) . '</td><td><div class="pc-report-library__row-actions">';
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( '编辑', 'peptide-core' ) . '</a>';
		}
		if ( $has_file ) {
			echo '<a href="' . esc_url( Catalog::download_url( $id, get_current_user_id() ) ) . '">' . esc_html__( '下载文件', 'peptide-core' ) . '</a>';
		}
		if ( ! $edit && ! $has_file ) {
			echo esc_html__( '暂无操作', 'peptide-core' );
		}
		echo '</div></td></tr>';
	}

	private static function render_source( int $id ): void {
		$fields = array(
			__( '原报告品名', 'peptide-core' ) => (string) get_post_meta( $id, Meta::REPORT_SOURCE_PRODUCT, true ),
			__( '报告委托方', 'peptide-core' ) => (string) get_post_meta( $id, Meta::REPORT_SOURCE_CLIENT, true ),
			__( '来源样品或批号原文', 'peptide-core' ) => (string) get_post_meta( $id, Meta::REPORT_SOURCE_LOT, true ),
			'SHA256' => (string) get_post_meta( $id, Meta::REPORT_SOURCE_SHA256, true ),
		);
		$fields = array_filter( $fields, static fn( string $value ): bool => '' !== trim( $value ) );
		if ( ! $fields ) {
			return;
		}
		echo '<details class="pc-report-library__source"><summary>' . esc_html__( '来源信息', 'peptide-core' ) . '</summary><dl>';
		foreach ( $fields as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		echo '</dl></details>';
	}

	/** @param int[] $ids @return array<string,int[]> ISO dates descending, unknown last. */
	private static function date_groups( array $ids ): array {
		$groups  = array();
		$unknown = array();
		foreach ( $ids as $id ) {
			$date = Meta::sanitize_date( get_post_meta( $id, Meta::REPORT_ISSUED_ON, true ) );
			if ( '' === $date ) {
				$unknown[] = $id;
			} else {
				$groups[ $date ][] = $id;
			}
		}
		krsort( $groups, SORT_STRING );
		if ( $unknown ) {
			$groups[''] = $unknown;
		}
		return $groups;
	}

	private static function new_report_link( ?int $product_id ): void {
		$type = get_post_type_object( Post_Types::REPORT );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return;
		}
		$url = admin_url( 'post-new.php?post_type=' . Post_Types::REPORT );
		if ( $product_id ) {
			$url = add_query_arg( 'pc_product_id', $product_id, $url );
		}
		echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( '新增报告', 'peptide-core' ) . '</a>';
	}

	private static function is_page(): bool {
		return self::SLUG === self::query( 'page' );
	}

	/** GET parameters only change this read-only view. */
	private static function query( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation, no mutation.
		$value = $_GET[ $name ] ?? '';
		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}
}
