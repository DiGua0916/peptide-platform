<?php
/**
 * 批次编辑页。批次号是唯一标识，同时同步为文章标题，方便后台列表与搜索。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Admin_Notices;
use PeptideCore\Batches;
use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Reports;

defined( 'ABSPATH' ) || exit;

final class Batch_Metabox {

	private const NONCE_ACTION = 'pc_save_batch';
	private const NONCE_FIELD  = 'pc_batch_nonce';

	public static function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Post_Types::BATCH, array( self::class, 'add' ) );
		add_action( 'save_post_' . Post_Types::BATCH, array( self::class, 'save' ), 10, 2 );
	}

	public static function add(): void {
		add_meta_box( 'pc_batch_details', __( 'Batch details', 'peptide-core' ), array( self::class, 'render' ), Post_Types::BATCH, 'normal', 'high' );
		add_meta_box( 'pc_batch_reports', __( 'Test reports', 'peptide-core' ), array( self::class, 'render_reports' ), Post_Types::BATCH, 'normal', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$product_id = (int) get_post_meta( $post->ID, Meta::BATCH_PRODUCT_ID, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只用于新建时预选商品。
		if ( ! $product_id && isset( $_GET['pc_product_id'] ) ) {
			$product_id = absint( $_GET['pc_product_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$batch_no      = (string) get_post_meta( $post->ID, Meta::BATCH_NO, true );
		$visibility    = (string) get_post_meta( $post->ID, Meta::BATCH_VISIBILITY, true );
		$status        = (string) get_post_meta( $post->ID, Meta::BATCH_STATUS, true );
		$variation_ids = (array) get_post_meta( $post->ID, Meta::BATCH_VARIATION_IDS, true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pc_batch_no"><?php esc_html_e( 'Batch number', 'peptide-core' ); ?> *</label></th>
				<td>
					<input type="text" id="pc_batch_no" name="pc_batch_no" class="regular-text" maxlength="40" value="<?php echo esc_attr( $batch_no ); ?>" required>
					<p class="description"><?php esc_html_e( '3–40 characters: letters, digits and hyphens. Stored in upper case and must be unique.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_product_id"><?php esc_html_e( 'Product', 'peptide-core' ); ?> *</label></th>
				<td>
					<select id="pc_product_id" name="pc_product_id" required>
						<option value=""><?php esc_html_e( '— Select a product —', 'peptide-core' ); ?></option>
						<?php foreach ( self::product_options() as $id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $product_id, $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php self::render_variations( $product_id, $variation_ids ); ?>
			<tr>
				<th scope="row"><label for="pc_visibility"><?php esc_html_e( 'Visibility', 'peptide-core' ); ?></label></th>
				<td>
					<select id="pc_visibility" name="pc_visibility">
						<option value="public" <?php selected( $visibility, 'public' ); ?>><?php esc_html_e( 'Public (visible on the storefront)', 'peptide-core' ); ?></option>
						<option value="hidden" <?php selected( $visibility, 'hidden' ); ?>><?php esc_html_e( 'Hidden (admin only)', 'peptide-core' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_status"><?php esc_html_e( 'Status', 'peptide-core' ); ?></label></th>
				<td>
					<select id="pc_status" name="pc_status">
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'peptide-core' ); ?></option>
						<option value="depleted" <?php selected( $status, 'depleted' ); ?>><?php esc_html_e( 'Depleted (reports stay searchable)', 'peptide-core' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_manufactured_on"><?php esc_html_e( 'Manufactured on', 'peptide-core' ); ?></label></th>
				<td><input type="date" id="pc_manufactured_on" name="pc_manufactured_on" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Meta::BATCH_MANUFACTURED, true ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_retest_or_expiry_on"><?php esc_html_e( 'Retest / expiry date', 'peptide-core' ); ?></label></th>
				<td><input type="date" id="pc_retest_or_expiry_on" name="pc_retest_or_expiry_on" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Meta::BATCH_RETEST_EXPIRY, true ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_origin_note"><?php esc_html_e( 'Origin note', 'peptide-core' ); ?></label></th>
				<td>
					<input type="text" id="pc_origin_note" name="pc_origin_note" class="large-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Meta::BATCH_ORIGIN_NOTE, true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty unless the information is real and may be published.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Flags', 'peptide-core' ); ?></th>
				<td>
					<label><input type="checkbox" name="pc_report_pending" value="1" <?php checked( Meta::bool( get_post_meta( $post->ID, Meta::BATCH_REPORT_PENDING, true ) ) ); ?>> <?php esc_html_e( 'Report in preparation', 'peptide-core' ); ?></label><br>
					<label><input type="checkbox" name="pc_is_demo" value="1" <?php checked( Meta::bool( get_post_meta( $post->ID, Meta::BATCH_IS_DEMO, true ) ) ); ?>> <?php esc_html_e( 'Demo batch (synthetic data)', 'peptide-core' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_reports( \WP_Post $post ): void {
		$ids = 'auto-draft' === $post->post_status ? array() : Reports::ids_for_batch( $post->ID );
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No test reports yet.', 'peptide-core' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $ids as $report_id ) {
				printf(
					'<li><a href="%1$s">%2$s</a> — %3$s v%4$d · %5$s · %6$s</li>',
					esc_url( get_edit_post_link( $report_id ) ?? '' ),
					esc_html( get_the_title( $report_id ) ),
					esc_html( strtoupper( (string) get_post_meta( $report_id, Meta::REPORT_TYPE, true ) ) ),
					(int) get_post_meta( $report_id, Meta::REPORT_VERSION, true ),
					esc_html( (string) get_post_meta( $report_id, Meta::REPORT_STATUS, true ) ),
					esc_html( (string) get_post_meta( $report_id, Meta::REPORT_ACCESS, true ) )
				);
			}
			echo '</ul>';
		}

		if ( 'auto-draft' !== $post->post_status && current_user_can( 'edit_pc_reports' ) ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'post-new.php?post_type=' . Post_Types::REPORT . '&pc_batch_id=' . $post->ID ) ),
				esc_html__( 'Add test report for this batch', 'peptide-core' )
			);
		} elseif ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the batch first, then add reports.', 'peptide-core' ) . '</p>';
		}
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$errors = array();

		// 批次号：必填、格式合法、全局唯一。
		$batch_no = Batches::normalize_batch_no( sanitize_text_field( wp_unslash( $_POST['pc_batch_no'] ?? '' ) ) );
		if ( ! Batches::is_valid_batch_no( $batch_no ) ) {
			$errors[] = __( 'Batch number is missing or invalid.', 'peptide-core' );
		} elseif ( Batches::find_id_by_batch_no( $batch_no, $post_id ) ) {
			/* translators: %s: batch number */
			$errors[] = sprintf( __( 'Batch number %s is already used by another batch.', 'peptide-core' ), $batch_no );
		} else {
			update_post_meta( $post_id, Meta::BATCH_NO, $batch_no );
		}

		// 所属商品：必填，必须是 WooCommerce 商品（不是变体）。
		$product_id = absint( $_POST['pc_product_id'] ?? 0 );
		if ( ! Batches::is_valid_product( $product_id ) ) {
			$errors[] = __( 'Please select a valid product.', 'peptide-core' );
		} else {
			update_post_meta( $post_id, Meta::BATCH_PRODUCT_ID, $product_id );
			$variation_ids = isset( $_POST['pc_variation_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pc_variation_ids'] ) ) : array();
			update_post_meta( $post_id, Meta::BATCH_VARIATION_IDS, Batches::filter_variation_ids( $product_id, $variation_ids ) );
		}

		$visibility = sanitize_key( wp_unslash( $_POST['pc_visibility'] ?? 'public' ) );
		update_post_meta( $post_id, Meta::BATCH_VISIBILITY, in_array( $visibility, Batches::VISIBILITIES, true ) ? $visibility : 'public' );

		$status = sanitize_key( wp_unslash( $_POST['pc_status'] ?? 'active' ) );
		update_post_meta( $post_id, Meta::BATCH_STATUS, in_array( $status, Batches::STATUSES, true ) ? $status : 'active' );

		update_post_meta( $post_id, Meta::BATCH_MANUFACTURED, Meta::sanitize_date( wp_unslash( $_POST['pc_manufactured_on'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::BATCH_RETEST_EXPIRY, Meta::sanitize_date( wp_unslash( $_POST['pc_retest_or_expiry_on'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::BATCH_ORIGIN_NOTE, sanitize_text_field( wp_unslash( $_POST['pc_origin_note'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::BATCH_REPORT_PENDING, isset( $_POST['pc_report_pending'] ) );
		update_post_meta( $post_id, Meta::BATCH_IS_DEMO, isset( $_POST['pc_is_demo'] ) );

		// 同步标题；有错误时退回草稿，避免不完整的批次被当作已发布。
		$stored_no = (string) get_post_meta( $post_id, Meta::BATCH_NO, true );
		$update    = array(
			'ID'         => $post_id,
			'post_title' => '' !== $stored_no ? $stored_no : __( '(no batch number)', 'peptide-core' ),
		);
		if ( $errors && 'publish' === $post->post_status ) {
			$update['post_status'] = 'draft';
			$errors[]              = __( 'The batch was saved as a draft until the errors are fixed.', 'peptide-core' );
		}

		remove_action( 'save_post_' . Post_Types::BATCH, array( self::class, 'save' ), 10 );
		wp_update_post( $update );
		add_action( 'save_post_' . Post_Types::BATCH, array( self::class, 'save' ), 10, 2 );

		foreach ( $errors as $message ) {
			Admin_Notices::add( $message );
		}
	}

	/**
	 * @return array<int,string> 商品 ID => 名称（含 SKU）。
	 */
	public static function product_options(): array {
		$options  = array();
		$products = wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		foreach ( $products as $product ) {
			$label = $product->get_name();
			if ( $product->get_sku() ) {
				$label .= ' (' . $product->get_sku() . ')';
			}
			$options[ $product->get_id() ] = $label;
		}
		return $options;
	}

	/**
	 * 可变商品才显示包装规格选项；不勾选表示适用于全部规格。
	 *
	 * @param int[] $selected
	 */
	private static function render_variations( int $product_id, array $selected ): void {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		$selected = array_map( 'intval', $selected );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Pack sizes', 'peptide-core' ); ?></th>
			<td>
				<?php foreach ( $product->get_children() as $variation_id ) : ?>
					<?php $variation = wc_get_product( $variation_id ); ?>
					<?php if ( $variation ) : ?>
						<label><input type="checkbox" name="pc_variation_ids[]" value="<?php echo esc_attr( (string) $variation_id ); ?>" <?php checked( in_array( (int) $variation_id, $selected, true ) ); ?>> <?php echo esc_html( wc_get_formatted_variation( $variation, true ) ); ?></label><br>
					<?php endif; ?>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Leave all unchecked if this batch applies to every pack size. Save after changing the product to refresh this list.', 'peptide-core' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
