<?php
/**
 * Functional public COA directory for ordinary WordPress pages.
 *
 * Markup uses only the archive's public DTO. Styling belongs to the theme.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Report_Archive_Page {

	public const SHORTCODE = 'pc_report_library';

	public static function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/** @param array|string $atts Shortcode attributes. */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'per_page' => 12 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$per_page = is_scalar( $atts['per_page'] ) && ctype_digit( (string) $atts['per_page'] ) ? max( 1, min( 24, (int) $atts['per_page'] ) ) : 12;
		$filters = self::filters();
		$data = null;
		$error = $filters['error'];
		if ( ! $error ) {
			if ( ! class_exists( Report_Archive::class ) ) {
				$error = __( 'The report library is temporarily unavailable. Please try again later.', 'peptide-core' );
			} else {
				$query_args = array(
					'q' => $filters['q'], 'product_id' => $filters['product_id'],
					'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
					'sort' => $filters['sort'], 'page' => $filters['page'], 'per_page' => $per_page,
				);
				$data = Report_Archive::query( $query_args );
				if ( is_array( $data ) && (int) $data['total'] > 0 && $filters['page'] > max( 1, (int) $data['total_pages'] ) ) {
					$filters['page'] = max( 1, (int) $data['total_pages'] );
					$query_args['page'] = $filters['page'];
					$data = Report_Archive::query( $query_args );
				}
				if ( is_wp_error( $data ) ) {
					$error_data = $data->get_error_data();
					$status = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 500 ) : 500;
					$error = $status >= 500 ? __( 'The reports could not be loaded. Please try again later.', 'peptide-core' ) : $data->get_error_message();
					$data = null;
				}
			}
		}
		$base_url = self::page_url();
		$instance = wp_unique_id( 'pc-coa-' );
		$results_id = $instance . 'results';
		$products = is_array( $data ) && isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
		ob_start();
		?>
		<section class="pc-coa-library" aria-label="<?php esc_attr_e( 'COA report library', 'peptide-core' ); ?>">
			<form class="pc-coa-library__filters" method="get" action="<?php echo esc_url( $base_url ); ?>">
				<?php self::plain_permalink_field( $base_url ); ?>
				<div class="pc-coa-library__field">
					<label for="<?php echo esc_attr( $instance . 'q' ); ?>"><?php esc_html_e( 'Search reports', 'peptide-core' ); ?></label>
					<input id="<?php echo esc_attr( $instance . 'q' ); ?>" name="coa_q" type="search" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( 'Product name, report number or lot', 'peptide-core' ); ?>">
				</div>
				<div class="pc-coa-library__field">
					<label for="<?php echo esc_attr( $instance . 'product' ); ?>"><?php esc_html_e( 'Product', 'peptide-core' ); ?></label>
					<select id="<?php echo esc_attr( $instance . 'product' ); ?>" name="coa_product">
						<option value=""><?php esc_html_e( 'All products', 'peptide-core' ); ?></option>
						<?php foreach ( $products as $product ) : ?>
							<option value="<?php echo esc_attr( (string) $product['id'] ); ?>" <?php selected( (string) $filters['product_id'], (string) $product['id'] ); ?>><?php echo esc_html( $product['name'] . ' (' . (int) $product['count'] . ')' ); ?></option>
						<?php endforeach; ?>
						<?php if ( $filters['product_id'] > 0 && ! in_array( $filters['product_id'], array_map( 'intval', array_column( $products, 'id' ) ), true ) ) : ?>
							<option value="<?php echo esc_attr( (string) $filters['product_id'] ); ?>" selected><?php esc_html_e( 'Selected product', 'peptide-core' ); ?></option>
						<?php endif; ?>
					</select>
				</div>
				<div class="pc-coa-library__field">
					<label for="<?php echo esc_attr( $instance . 'from' ); ?>"><?php esc_html_e( 'Issued from', 'peptide-core' ); ?></label>
					<input id="<?php echo esc_attr( $instance . 'from' ); ?>" name="coa_from" type="date" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
				</div>
				<div class="pc-coa-library__field">
					<label for="<?php echo esc_attr( $instance . 'to' ); ?>"><?php esc_html_e( 'Issued to', 'peptide-core' ); ?></label>
					<input id="<?php echo esc_attr( $instance . 'to' ); ?>" name="coa_to" type="date" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
				</div>
				<div class="pc-coa-library__field">
					<label for="<?php echo esc_attr( $instance . 'sort' ); ?>"><?php esc_html_e( 'Sort reports', 'peptide-core' ); ?></label>
					<select id="<?php echo esc_attr( $instance . 'sort' ); ?>" name="coa_sort">
						<option value="newest" <?php selected( $filters['sort'], 'newest' ); ?>><?php esc_html_e( 'Newest first', 'peptide-core' ); ?></option>
						<option value="oldest" <?php selected( $filters['sort'], 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'peptide-core' ); ?></option>
					</select>
				</div>
				<div class="pc-coa-library__filter-actions">
					<button type="submit"><?php esc_html_e( 'Search reports', 'peptide-core' ); ?></button>
					<a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear filters', 'peptide-core' ); ?></a>
				</div>
			</form>
			<div class="pc-coa-library__results" id="<?php echo esc_attr( $results_id ); ?>">
				<?php if ( $error ) : ?>
					<p class="pc-coa-library__error" role="alert"><?php echo esc_html( $error ); ?></p>
				<?php elseif ( is_array( $data ) ) : ?>
					<p class="pc-coa-library__count" role="status"><?php
						/* translators: %d: total reports matching the filters. */
						echo esc_html( sprintf( _n( '%d report', '%d reports', (int) $data['total'], 'peptide-core' ), (int) $data['total'] ) );
					?></p>
					<?php if ( ! $data['items'] ) : ?>
						<div class="pc-coa-library__empty"><h2><?php echo esc_html( self::filtered( $filters ) ? __( 'No reports match these filters', 'peptide-core' ) : __( 'No reports are available yet', 'peptide-core' ) ); ?></h2><p><?php echo esc_html( self::filtered( $filters ) ? __( 'Try another product, report number or date range.', 'peptide-core' ) : __( 'Published documents will appear here when they are available.', 'peptide-core' ) ); ?></p></div>
					<?php else : ?>
						<div class="pc-coa-library__items">
							<?php foreach ( $data['items'] as $item ) { self::report_card( $item ); } ?>
						</div>
						<?php self::pagination( $data, $filters, $base_url, $results_id ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Read only the directory's own GET parameters. */
	private static function filters(): array {
		$values = array();
		$error = '';
		foreach ( array( 'coa_q', 'coa_product', 'coa_from', 'coa_to', 'coa_sort', 'coa_page' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public filters.
				$error = __( 'Some filters are invalid. Clear the filters and try again.', 'peptide-core' );
			}
			$values[ $key ] = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' !== $values['coa_product'] && ( ! ctype_digit( $values['coa_product'] ) || (int) $values['coa_product'] < 1 ) ) {
			$error = __( 'Choose a valid product or clear the filters.', 'peptide-core' );
		}
		if ( '' !== $values['coa_page'] && ( ! ctype_digit( $values['coa_page'] ) || (int) $values['coa_page'] < 1 ) ) {
			$error = __( 'Choose a valid results page or clear the filters.', 'peptide-core' );
		}
		foreach ( array( 'coa_from', 'coa_to' ) as $key ) {
			if ( '' !== $values[ $key ] && ! self::valid_date( $values[ $key ] ) ) {
				$error = __( 'Enter valid issue dates in YYYY-MM-DD format.', 'peptide-core' );
			}
		}
		if ( '' !== $values['coa_from'] && '' !== $values['coa_to'] && $values['coa_from'] > $values['coa_to'] ) {
			$error = __( 'The start date must be on or before the end date.', 'peptide-core' );
		}
		if ( '' !== $values['coa_sort'] && ! in_array( $values['coa_sort'], array( 'newest', 'oldest' ), true ) ) {
			$error = __( 'Choose a valid report order or clear the filters.', 'peptide-core' );
		}
		return array(
			'q' => $values['coa_q'], 'product_id' => '' !== $values['coa_product'] ? (int) $values['coa_product'] : 0,
			'date_from' => $values['coa_from'], 'date_to' => $values['coa_to'],
			'sort' => in_array( $values['coa_sort'], array( 'newest', 'oldest' ), true ) ? $values['coa_sort'] : 'newest',
			'page' => '' !== $values['coa_page'] ? max( 1, (int) $values['coa_page'] ) : 1, 'error' => $error,
		);
	}

	private static function valid_date( string $value ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	private static function filtered( array $filters ): bool {
		return '' !== $filters['q'] || $filters['product_id'] > 0 || '' !== $filters['date_from'] || '' !== $filters['date_to'];
	}

	private static function page_url(): string {
		$id = get_queried_object_id();
		$url = $id ? get_permalink( $id ) : false;
		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/** GET forms replace query strings, so retain the native plain permalink. */
	private static function plain_permalink_field( string $url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( isset( $query['page_id'] ) && is_scalar( $query['page_id'] ) && absint( $query['page_id'] ) > 0 ) {
			echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) absint( $query['page_id'] ) ) . '">';
		}
	}

	private static function report_card( array $item ): void {
		$product = isset( $item['product'] ) && is_array( $item['product'] ) ? $item['product'] : null;
		$product_name = $product ? (string) $product['name'] : (string) ( $item['source_product'] ?? '' );
		$thumbnail = (string) ( $item['thumbnail_url'] ?? '' );
		$view_url = (string) ( $item['view_url'] ?? '' );
		$download_url = (string) ( $item['download_url'] ?? '' );
		?>
		<article class="pc-coa-library__report" data-report-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
			<div class="pc-coa-library__preview">
				<?php if ( '' !== $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>" width="640" loading="lazy" decoding="async" alt="<?php echo esc_attr( sprintf( __( 'First page of %s', 'peptide-core' ), (string) $item['title'] ) ); ?>">
				<?php else : ?>
					<p class="pc-coa-library__preview-label"><?php esc_html_e( 'PDF report', 'peptide-core' ); ?></p>
				<?php endif; ?>
			</div>
			<h2 class="pc-coa-library__title"><?php echo esc_html( (string) $item['title'] ); ?></h2>
			<dl class="pc-coa-library__metadata">
				<?php if ( '' !== $product_name ) : ?>
					<dt><?php esc_html_e( 'Product', 'peptide-core' ); ?></dt><dd><?php if ( $product && ! empty( $product['url'] ) ) : ?><a href="<?php echo esc_url( (string) $product['url'] ); ?>"><?php echo esc_html( $product_name ); ?></a><?php else : ?><?php echo esc_html( $product_name ); ?><?php endif; ?></dd>
				<?php endif; ?>
				<?php foreach ( array( 'specification' => __( 'Specification', 'peptide-core' ), 'report_number' => __( 'Report number', 'peptide-core' ), 'source_lot' => __( 'Report lot', 'peptide-core' ), 'issuer_name' => __( 'Issued by', 'peptide-core' ) ) as $key => $label ) : ?>
					<?php if ( isset( $item[ $key ] ) && '' !== (string) $item[ $key ] ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) $item[ $key ] ); ?></dd><?php endif; ?>
				<?php endforeach; ?>
				<dt><?php esc_html_e( 'Issued on', 'peptide-core' ); ?></dt><dd><?php if ( ! empty( $item['issued_on'] ) ) : ?><time datetime="<?php echo esc_attr( (string) $item['issued_on'] ); ?>"><?php echo esc_html( (string) $item['issued_on'] ); ?></time><?php else : ?><?php esc_html_e( 'Not provided', 'peptide-core' ); ?><?php endif; ?></dd>
				<dt><?php esc_html_e( 'File', 'peptide-core' ); ?></dt><dd><?php esc_html_e( 'PDF', 'peptide-core' ); ?><?php if ( ! empty( $item['file']['size_bytes'] ) ) { echo ' · ' . esc_html( size_format( (int) $item['file']['size_bytes'], 1 ) ); } ?></dd>
			</dl>
			<div class="pc-coa-library__report-actions">
				<?php if ( '' !== $view_url ) : ?><a class="pc-coa-library__view" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View PDF', 'peptide-core' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'peptide-core' ); ?></span></a><?php endif; ?>
				<?php if ( '' !== $download_url ) : ?><a class="pc-coa-library__download" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download PDF', 'peptide-core' ); ?></a><?php endif; ?>
				<?php if ( '' === $view_url && '' === $download_url ) : ?><span><?php esc_html_e( 'PDF unavailable', 'peptide-core' ); ?></span><?php endif; ?>
			</div>
		</article>
		<?php
	}

	private static function pagination( array $data, array $filters, string $base_url, string $results_id ): void {
		$total_pages = max( 1, (int) $data['total_pages'] );
		$current = max( 1, min( $total_pages, (int) $data['page'] ) );
		if ( $total_pages < 2 ) { return; }
		$args = array( 'coa_q' => $filters['q'], 'coa_product' => $filters['product_id'] ?: '', 'coa_from' => $filters['date_from'], 'coa_to' => $filters['date_to'], 'coa_sort' => $filters['sort'] );
		$args = array_filter( $args, static fn( $value ) => '' !== $value );
		$page_url = static fn( int $page ) => add_query_arg( $args + array( 'coa_page' => $page ), $base_url ) . '#' . rawurlencode( $results_id );
		$pages = array_unique( array_merge( array( 1, $total_pages ), range( max( 1, $current - 2 ), min( $total_pages, $current + 2 ) ) ) );
		sort( $pages, SORT_NUMERIC );
		echo '<nav class="pc-coa-library__pagination" aria-label="' . esc_attr__( 'Report results pages', 'peptide-core' ) . '">';
		if ( $current > 1 ) { echo '<a rel="prev" href="' . esc_url( $page_url( $current - 1 ) ) . '">' . esc_html__( 'Previous', 'peptide-core' ) . '</a>'; }
		$previous = 0;
		foreach ( $pages as $page ) {
			if ( $previous && $page > $previous + 1 ) { echo '<span class="pc-coa-library__pagination-gap" aria-hidden="true">…</span>'; }
			if ( $page === $current ) {
				echo '<span aria-current="page">' . esc_html( (string) $page ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $page_url( $page ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Page %d', 'peptide-core' ), $page ) ) . '">' . esc_html( (string) $page ) . '</a>';
			}
			$previous = $page;
		}
		if ( $current < $total_pages ) { echo '<a rel="next" href="' . esc_url( $page_url( $current + 1 ) ) . '">' . esc_html__( 'Next', 'peptide-core' ) . '</a>'; }
		echo '</nav>';
	}
}
