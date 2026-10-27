<?php
/**
 * 检测报告编辑页：所属批次、类型、状态、访问级别、文件上传。
 * 批次菜单隐藏后（用户 2026-10-27），批次在这里选，或者填新批次号和商品，保存时自动建批次。
 *
 * 规则：
 * - 版本号在首次保存（或更换批次 / 类型）时自动分配，不能手填。
 * - 状态设为 published 时，同批次同类型的旧 published 版本自动变为 superseded。
 * - 没有所属批次或没有文件时不能发布，状态会退回 draft 并提示。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Admin_Notices;
use PeptideCore\Batches;
use PeptideCore\Meta;
use PeptideCore\Post_Types;
use PeptideCore\Private_Files;
use PeptideCore\Reports;

defined( 'ABSPATH' ) || exit;

final class Report_Metabox {

	private const NONCE_ACTION = 'pc_save_report';
	private const NONCE_FIELD  = 'pc_report_nonce';

	public static function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Post_Types::REPORT, array( self::class, 'add' ) );
		add_action( 'save_post_' . Post_Types::REPORT, array( self::class, 'save' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( self::class, 'form_enctype' ) );
	}

	public static function form_enctype( \WP_Post $post ): void {
		if ( Post_Types::REPORT === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function add(): void {
		add_meta_box( 'pc_report_details', __( 'Report details', 'peptide-core' ), array( self::class, 'render' ), Post_Types::REPORT, 'normal', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$batch_id = (int) get_post_meta( $post->ID, Meta::REPORT_BATCH_ID, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只用于新建时预选批次。
		if ( ! $batch_id && isset( $_GET['pc_batch_id'] ) ) {
			$batch_id = absint( $_GET['pc_batch_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// 新报告默认 COA（注册的 meta 默认值是 other，新建时读出来就是“其他”）。
		$type          = 'auto-draft' === $post->post_status ? 'coa' : (string) get_post_meta( $post->ID, Meta::REPORT_TYPE, true );
		$status        = (string) get_post_meta( $post->ID, Meta::REPORT_STATUS, true );
		$access        = (string) get_post_meta( $post->ID, Meta::REPORT_ACCESS, true );
		$version       = (int) get_post_meta( $post->ID, Meta::REPORT_VERSION, true );
		$superseded_by = (int) get_post_meta( $post->ID, Meta::REPORT_SUPERSEDED_BY, true );
		$file          = get_post_meta( $post->ID, Meta::REPORT_FILE, true );

		$type_labels   = array(
			'coa'   => __( 'Certificate of analysis (COA)', 'peptide-core' ),
			'hplc'  => __( 'HPLC', 'peptide-core' ),
			'ms'    => __( 'Mass spectrometry (MS)', 'peptide-core' ),
			'other' => __( 'Other', 'peptide-core' ),
		);
		$status_labels = array(
			'draft'      => __( 'Draft (not visible)', 'peptide-core' ),
			'published'  => __( 'Published', 'peptide-core' ),
			'superseded' => __( 'Superseded (old version)', 'peptide-core' ),
			'withdrawn'  => __( 'Withdrawn', 'peptide-core' ),
		);
		$access_labels = array(
			'public'     => __( 'Public', 'peptide-core' ),
			'registered' => __( 'Logged-in customers', 'peptide-core' ),
			'approved'   => __( 'Approved customers', 'peptide-core' ),
			'admin'      => __( 'Staff only', 'peptide-core' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pc_batch_id"><?php esc_html_e( 'Batch', 'peptide-core' ); ?> *</label></th>
				<td>
					<select id="pc_batch_id" name="pc_batch_id">
						<option value=""><?php esc_html_e( '— Select a batch —', 'peptide-core' ); ?></option>
						<?php foreach ( self::batch_options() as $id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $batch_id, $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $batch_id && Post_Types::BATCH === get_post_type( $batch_id ) ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $batch_id ) ?? '' ); ?>"><?php esc_html_e( 'Edit batch', 'peptide-core' ); ?></a>
					<?php endif; ?>
					<p>
						<label for="pc_new_batch_no"><?php esc_html_e( 'Or a new batch:', 'peptide-core' ); ?></label>
						<input type="text" id="pc_new_batch_no" name="pc_new_batch_no" maxlength="40" placeholder="<?php esc_attr_e( 'Batch number', 'peptide-core' ); ?>">
						<select name="pc_new_batch_product" aria-label="<?php esc_attr_e( 'Product', 'peptide-core' ); ?>">
							<option value=""><?php esc_html_e( '— Select a product —', 'peptide-core' ); ?></option>
							<?php foreach ( Batch_Metabox::product_options() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="description"><?php esc_html_e( 'Choose an existing batch, or fill in a new batch number (3–40 letters, digits and hyphens) and its product: the batch is created when you save.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_type"><?php esc_html_e( 'Type', 'peptide-core' ); ?></label></th>
				<td><?php self::select( 'pc_type', $type_labels, '' !== $type ? $type : 'coa' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Version', 'peptide-core' ); ?></th>
				<td>
					<?php echo $version ? esc_html( 'v' . $version ) : esc_html__( 'Assigned on save', 'peptide-core' ); ?>
					<?php if ( $superseded_by ) : ?>
						<br><?php esc_html_e( 'Superseded by:', 'peptide-core' ); ?> <a href="<?php echo esc_url( get_edit_post_link( $superseded_by ) ?? '' ); ?>"><?php echo esc_html( get_the_title( $superseded_by ) ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_report_status"><?php esc_html_e( 'Report status', 'peptide-core' ); ?></label></th>
				<td><?php self::select( 'pc_report_status', $status_labels, '' !== $status ? $status : 'draft' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_access"><?php esc_html_e( 'Who can download', 'peptide-core' ); ?></label></th>
				<td>
					<?php self::select( 'pc_access', $access_labels, '' !== $access ? $access : 'public' ); ?>
					<p class="description"><?php esc_html_e( 'Customers who received this batch in an order can always download its published reports.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_issued_on"><?php esc_html_e( 'Issued on', 'peptide-core' ); ?></label></th>
				<td><input type="date" id="pc_issued_on" name="pc_issued_on" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Meta::REPORT_ISSUED_ON, true ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_issuer_name"><?php esc_html_e( 'Issuer', 'peptide-core' ); ?></label></th>
				<td>
					<input type="text" id="pc_issuer_name" name="pc_issuer_name" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Meta::REPORT_ISSUER_NAME, true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Real laboratory name only. Leave empty for demo reports.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_summary"><?php esc_html_e( 'Summary', 'peptide-core' ); ?></label></th>
				<td>
					<textarea id="pc_summary" name="pc_summary" class="large-text" rows="3"><?php echo esc_textarea( (string) get_post_meta( $post->ID, Meta::REPORT_SUMMARY, true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. Never enter invented test results.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pc_report_file"><?php esc_html_e( 'File', 'peptide-core' ); ?></label></th>
				<td>
					<?php if ( is_array( $file ) && ! empty( $file['stored'] ) ) : ?>
						<p><?php echo esc_html( sprintf( '%s · %s · %s', $file['original'] ?? '', $file['mime'] ?? '', size_format( (int) ( $file['size'] ?? 0 ) ) ) ); ?></p>
					<?php endif; ?>
					<input type="file" id="pc_report_file" name="pc_report_file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg">
					<p class="description"><?php esc_html_e( 'PDF, PNG or JPEG, up to 20 MB. Uploading a new file replaces the current one. Files are stored privately and are never linked directly.', 'peptide-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Flags', 'peptide-core' ); ?></th>
				<td><label><input type="checkbox" name="pc_is_demo" value="1" <?php checked( Meta::bool( get_post_meta( $post->ID, Meta::REPORT_IS_DEMO, true ) ) ); ?>> <?php esc_html_e( 'Demo report (the file must carry a visible DEMO watermark)', 'peptide-core' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$errors = array();

		$old_batch_id = (int) get_post_meta( $post_id, Meta::REPORT_BATCH_ID, true );
		$old_type     = (string) get_post_meta( $post_id, Meta::REPORT_TYPE, true );
		$old_status   = (string) get_post_meta( $post_id, Meta::REPORT_STATUS, true );

		$batch_id = absint( $_POST['pc_batch_id'] ?? 0 );
		if ( $batch_id && Post_Types::BATCH !== get_post_type( $batch_id ) ) {
			$batch_id = 0;
		}
		// 填了新批次号：按它找批次，没有就建一个（同号批次属于别的商品时报错）。
		$new_no = sanitize_text_field( wp_unslash( $_POST['pc_new_batch_no'] ?? '' ) );
		if ( '' !== trim( $new_no ) ) {
			$type_obj = get_post_type_object( Post_Types::BATCH );
			$made     = $type_obj && current_user_can( $type_obj->cap->create_posts )
				? Batches::find_or_create( $new_no, absint( $_POST['pc_new_batch_product'] ?? 0 ) )
				: new \WP_Error( 'pc_batch_cap', __( 'Sorry, you are not allowed to do that.', 'peptide-core' ) );
			if ( is_wp_error( $made ) ) {
				$errors[] = $made->get_error_message();
			} else {
				$batch_id = $made;
			}
		}
		if ( ! $batch_id ) {
			$errors[] = __( 'Please select the batch this report belongs to.', 'peptide-core' );
		}
		$batch_id = $batch_id ?: $old_batch_id;

		$type   = self::choice( 'pc_type', Reports::TYPES, 'other' );
		$status = self::choice( 'pc_report_status', Reports::STATUSES, 'draft' );
		$access = self::choice( 'pc_access', Reports::ACCESS, 'public' );

		update_post_meta( $post_id, Meta::REPORT_BATCH_ID, $batch_id );
		update_post_meta( $post_id, Meta::REPORT_TYPE, $type );
		update_post_meta( $post_id, Meta::REPORT_ACCESS, $access );
		update_post_meta( $post_id, Meta::REPORT_ISSUED_ON, Meta::sanitize_date( wp_unslash( $_POST['pc_issued_on'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::REPORT_ISSUER_NAME, sanitize_text_field( wp_unslash( $_POST['pc_issuer_name'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::REPORT_SUMMARY, sanitize_textarea_field( wp_unslash( $_POST['pc_summary'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::REPORT_IS_DEMO, isset( $_POST['pc_is_demo'] ) );

		// 版本号：新报告，或更换了批次 / 类型时重新分配。
		$version = (int) get_post_meta( $post_id, Meta::REPORT_VERSION, true );
		if ( $batch_id && ( $version <= 0 || $batch_id !== $old_batch_id || $type !== $old_type ) ) {
			update_post_meta( $post_id, Meta::REPORT_VERSION, Reports::next_version( $batch_id, $type, $post_id ) );
		}

		// 文件：上传新文件时替换旧文件。
		if ( isset( $_FILES['pc_report_file'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['pc_report_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$stored = Private_Files::store_upload( $_FILES['pc_report_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 store_upload 中校验。
			if ( is_wp_error( $stored ) ) {
				$errors[] = $stored->get_error_message();
			} else {
				$old_file = get_post_meta( $post_id, Meta::REPORT_FILE, true );
				update_post_meta( $post_id, Meta::REPORT_FILE, $stored );
				if ( is_array( $old_file ) && ! empty( $old_file['stored'] ) ) {
					Private_Files::delete( (string) $old_file['stored'] );
				}
			}
		}

		// 发布前检查：必须有批次和文件。
		$file = get_post_meta( $post_id, Meta::REPORT_FILE, true );
		if ( 'published' === $status && ( ! $batch_id || ! is_array( $file ) || empty( $file['stored'] ) ) ) {
			$status   = 'draft';
			$errors[] = __( 'A report needs a batch and a file before it can be published. It was kept as a draft.', 'peptide-core' );
		}

		update_post_meta( $post_id, Meta::REPORT_STATUS, $status );
		if ( 'published' === $status ) {
			delete_post_meta( $post_id, Meta::REPORT_SUPERSEDED_BY );
			if ( 'published' !== $old_status || $batch_id !== $old_batch_id || $type !== $old_type ) {
				Reports::supersede_previous( $post_id );
			}
		}

		foreach ( $errors as $message ) {
			Admin_Notices::add( $message );
		}
	}

	/**
	 * @param string[] $allowed
	 */
	private static function choice( string $field, array $allowed, string $default ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 调用方已校验 nonce。
		$value = sanitize_key( wp_unslash( $_POST[ $field ] ?? '' ) );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * @param array<string,string> $options
	 */
	private static function select( string $name, array $options, string $current ): void {
		printf( '<select id="%1$s" name="%1$s">', esc_attr( $name ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * @return array<int,string> 批次 ID => “批次号 — 商品名”。
	 */
	private static function batch_options(): array {
		$options = array();
		$ids     = get_posts(
			array(
				'post_type'      => Post_Types::BATCH,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$batch_no   = (string) get_post_meta( $id, Meta::BATCH_NO, true );
			$product_id = (int) get_post_meta( $id, Meta::BATCH_PRODUCT_ID, true );
			$label      = '' !== $batch_no ? $batch_no : '#' . $id;
			if ( $product_id ) {
				$label .= ' — ' . get_the_title( $product_id );
			}
			$options[ (int) $id ] = $label;
		}
		return $options;
	}
}
