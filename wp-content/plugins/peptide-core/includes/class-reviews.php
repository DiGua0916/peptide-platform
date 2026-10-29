<?php
/** Product reviews use Woo comments; customer media remains protected until approval. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Reviews {

	public const SOURCE = '_pc_review_source';
	public const MEDIA_OWNER = '_pc_review_owner';
	private const MEDIA_FILE = '_pc_review_file';
	private static bool $submitting = false;

	public static function register_hooks(): void {
		add_filter( 'woocommerce_product_review_comment_form_args', array( self::class, 'form' ) );
		add_filter( 'pre_comment_approved', array( self::class, 'moderate' ), PHP_INT_MAX, 2 );
		add_filter( 'preprocess_comment', array( self::class, 'review_type' ), -1 );
		add_action( 'comment_post', array( self::class, 'normalize_verified' ), 30 );
		add_action( 'edit_comment', array( self::class, 'normalize_verified' ), 30 );
		add_action( 'admin_post_pc_review_submit', array( self::class, 'submit_action' ) );
		add_action( 'admin_post_nopriv_pc_review_submit', array( self::class, 'submit_action' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'woocommerce_before_single_product', array( self::class, 'received_notice' ) );
		add_filter( 'comment_text', array( self::class, 'comment_html' ), 20, 2 );
		add_filter( 'wp_get_attachment_url', array( self::class, 'attachment_url' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'image_srcset' ), 20, 5 );
		add_action( 'init', array( self::class, 'serve_media' ), -30 );
		add_action( 'delete_attachment', array( self::class, 'delete_media_file' ) );
		add_action( 'delete_comment', array( self::class, 'delete_review_media' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'edit_products' ) && current_user_can( 'moderate_comments' ) && ( ! Staff::is_staff( get_current_user_id() ) || in_array( 'products', Staff::granted( get_current_user_id() ), true ) );
	}

	public static function is_review( $comment ): bool {
		$c = get_comment( $comment );
		return $c && 'product' === get_post_type( $c->comment_post_ID ) && in_array( $c->comment_type, array( '', 'review' ), true ) && ! (int) $c->comment_parent;
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
		wp_enqueue_style( 'pc-reviews', plugins_url( 'assets/reviews.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION );
		wp_enqueue_script( 'pc-reviews', plugins_url( 'assets/reviews.js', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION, true );
	}

	public static function received_notice(): void {
		if ( '1' === ( $_GET['pc_review_received'] ?? '' ) ) { wc_print_notice( __( 'Thank you. Your review is awaiting approval.', 'peptide-core' ), 'success' ); }
	}

	public static function form( array $args ): array {
		global $product;
		if ( ! $product instanceof \WC_Product ) { return $args; }
		if ( Templates::previewing_id() ) {
			$args['comment_field'] = '<p>' . esc_html__( 'Review uploads are disabled in design previews.', 'peptide-core' ) . '</p>';
			$args['fields'] = array(); $args['submit_field'] = ''; $args['submit_button'] = '';
			return $args;
		}
		$id = $product->get_id();
		$args['action'] = admin_url( 'admin-post.php' );
		$args['submit_button'] = '<input name="%1$s" type="submit" id="%2$s" class="%3$s" value="%4$s" formenctype="multipart/form-data" />';
		$args['comment_field'] .= '<fieldset class="pc-review-upload"><legend>' . esc_html__( 'Add photos or a video (optional)', 'peptide-core' ) . '</legend><p>' . esc_html__( 'Up to 5 JPG, PNG or WebP photos (5 MB each) and one MP4 or WebM video (25 MB). Upload only media you own and consent to publish with this review. All submissions are reviewed before publication.', 'peptide-core' ) . '</p>';
		$args['comment_field'] .= '<label>' . esc_html__( 'Photos', 'peptide-core' ) . '<input type="file" name="pc_review_photos[]" multiple accept="image/jpeg,image/png,image/webp"></label><label>' . esc_html__( 'Video', 'peptide-core' ) . '<input type="file" name="pc_review_video" accept="video/mp4,video/webm"></label><label class="pc-review-consent"><input type="checkbox" name="media_consent" value="1"> ' . esc_html__( 'I own this media and agree to publish it with my review after approval.', 'peptide-core' ) . '</label><input type="hidden" name="action" value="pc_review_submit">' . wp_nonce_field( 'pc_review_' . $id, 'pc_review_nonce', false, false ) . '<span class="pc-review-honey" aria-hidden="true"><label>Website<input name="pc_review_website" tabindex="-1" autocomplete="off"></label></span><div class="pc-review-result" role="status" aria-live="polite"></div></fieldset>';
		return $args;
	}

	public static function moderate( $approved, array $data ) {
		if ( self::$submitting || ( ! is_admin() && ! current_user_can( 'moderate_comments' ) && 'product' === get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) ) ) ) { return 0; }
		return $approved;
	}

	public static function review_type( array $data ): array {
		if ( self::$submitting && 'product' === get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) ) ) { $data['comment_type'] = 'review'; }
		return $data;
	}

	private static function error( string $message, string $code = 'pc_review_invalid', int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** A typed email or administrator flag alone does not authenticate a purchase. */
	private static function verified_customer( \WP_Comment $comment ): bool {
		$user = $comment->user_id ? get_userdata( (int) $comment->user_id ) : false;
		return $user && 0 === strcasecmp( $user->user_email, $comment->comment_author_email ) && (bool) wc_customer_bought_product( $user->user_email, $user->ID, $comment->comment_post_ID );
	}

	public static function normalize_verified( int $id ): void {
		$comment = get_comment( $id ); if ( ! self::is_review( $comment ) ) { return; }
		update_comment_meta( $id, 'verified', self::verified_customer( $comment ) ? 1 : 0 );
		if ( ! metadata_exists( 'comment', $id, self::SOURCE ) ) { update_comment_meta( $id, self::SOURCE, self::$submitting || ! is_admin() ? 'buyer' : 'store' ); }
	}

	private static function discard_new_media( array $media ): void {
		foreach ( array_merge( $media['images'], $media['video'] ? array( $media['video'] ) : array() ) as $id ) { wp_delete_attachment( (int) $id, true ); }
	}

	private static function storage_dir() {
		$base = wp_normalize_path( Private_Files::dir() );
		$web = wp_normalize_path( ABSPATH );
		if ( str_starts_with( trailingslashit( $base ), trailingslashit( $web ) ) ) { return self::error( __( 'Private review media storage must be configured outside the public web folder.', 'peptide-core' ), 'pc_review_storage', 503 ); }
		$dir = $base . '/review-media';
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) { return self::error( __( 'Review media storage is unavailable.', 'peptide-core' ), 'pc_review_storage', 503 ); }
		return $dir;
	}

	/** Validate every file before persisting anything. No SVG, HTML or caller paths. */
	public static function validate_uploads( array $files ) {
		$rows = array();
		$photos = $files['pc_review_photos'] ?? array();
		foreach ( (array) ( $photos['name'] ?? array() ) as $i => $name ) {
			if ( UPLOAD_ERR_NO_FILE === (int) ( $photos['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) ) { continue; }
			$rows[] = array( 'name' => $name, 'tmp_name' => $photos['tmp_name'][ $i ] ?? '', 'error' => $photos['error'][ $i ] ?? UPLOAD_ERR_NO_FILE, 'size' => $photos['size'][ $i ] ?? 0, 'video' => false );
		}
		if ( count( $rows ) > 5 ) { return self::error( __( 'Choose at most five review photos.', 'peptide-core' ) ); }
		if ( isset( $files['pc_review_video']['name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $files['pc_review_video']['error'] ?? UPLOAD_ERR_NO_FILE ) ) { $rows[] = $files['pc_review_video'] + array( 'video' => true ); }
		$total = 0;
		foreach ( $rows as &$row ) {
			$max = $row['video'] ? 25 * MB_IN_BYTES : 5 * MB_IN_BYTES;
			if ( UPLOAD_ERR_OK !== (int) $row['error'] || ! is_string( $row['tmp_name'] ) || ! is_uploaded_file( $row['tmp_name'] ) ) { return self::error( __( 'One of the review files could not be uploaded.', 'peptide-core' ) ); }
			$size = filesize( $row['tmp_name'] );
			if ( ! $size || $size > min( $max, wp_max_upload_size() ) ) { return self::error( __( 'A review file is empty or exceeds the size limit.', 'peptide-core' ) ); }
			$total += $size;
			if ( $total > 30 * MB_IN_BYTES ) { return self::error( __( 'The combined review uploads exceed 30 MB.', 'peptide-core' ) ); }
			$mimes = $row['video'] ? array( 'mp4' => 'video/mp4', 'webm' => 'video/webm' ) : array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
			$check = wp_check_filetype_and_ext( $row['tmp_name'], sanitize_file_name( (string) $row['name'] ), $mimes );
			$finfo = new \finfo( FILEINFO_MIME_TYPE ); $actual = $finfo->file( $row['tmp_name'] );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $actual, array_values( $mimes ), true ) || $actual !== $check['type'] ) { return self::error( __( 'Only genuine JPG, PNG, WebP, MP4 or WebM files are allowed.', 'peptide-core' ) ); }
			$dimensions = $row['video'] ? null : @getimagesize( $row['tmp_name'] );
			if ( ! $row['video'] && ( ! $dimensions || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 40000000 ) ) { return self::error( __( 'The review image dimensions are invalid or too large.', 'peptide-core' ) ); }
			$row['mime'] = $actual; $row['ext'] = 'jpeg' === $check['ext'] ? 'jpg' : $check['ext']; $row['dimensions'] = $dimensions;
		}
		return $rows;
	}

	private static function persist_uploads( int $comment, array $rows ) {
		if ( ! $rows ) { return array( 'images' => array(), 'video' => 0 ); }
		$dir = self::storage_dir(); if ( is_wp_error( $dir ) ) { return $dir; }
		$ids = array(); $out = array( 'images' => array(), 'video' => 0 );
		foreach ( $rows as $row ) {
			$name = bin2hex( random_bytes( 16 ) ) . '.' . $row['ext']; $target = $dir . '/' . $name;
			if ( ! move_uploaded_file( $row['tmp_name'], $target ) ) { foreach ( $ids as $id ) { wp_delete_attachment( $id, true ); } return self::error( __( 'The review media could not be saved. Please try again.', 'peptide-core' ), 'pc_review_storage', 500 ); }
			$id = wp_insert_attachment( array( 'post_title' => __( 'Review media', 'peptide-core' ), 'post_status' => 'private', 'post_mime_type' => $row['mime'], 'post_author' => get_current_user_id(), 'post_parent' => 0 ), false, 0, true );
			if ( is_wp_error( $id ) || ! $id ) { @unlink( $target ); foreach ( $ids as $owned ) { wp_delete_attachment( $owned, true ); } return self::error( __( 'The review attachment could not be saved.', 'peptide-core' ), 'pc_review_storage', 500 ); }
			$ids[] = $id; update_post_meta( $id, self::MEDIA_OWNER, $comment ); update_post_meta( $id, self::MEDIA_FILE, $name );
			$native_path = '\\' === DIRECTORY_SEPARATOR ? str_replace( '/', '\\', $target ) : $target;
			update_attached_file( $id, wp_slash( $native_path ) );
			if ( $row['dimensions'] ) { wp_update_attachment_metadata( $id, array( 'width' => $row['dimensions'][0], 'height' => $row['dimensions'][1], 'file' => $name, 'sizes' => array() ) ); }
			if ( $row['video'] ) { $out['video'] = $id; } else { $out['images'][] = $id; }
		}
		return $out;
	}

	/** Public submission keeps all native Woo comment and purchase requirements. */
	public static function submit( array $input, array $files = array() ) {
		$product_id = absint( $input['comment_post_ID'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_text_field( $input['pc_review_nonce'] ?? '' ), 'pc_review_' . $product_id ) || ! empty( $input['pc_review_website'] ) ) { return self::error( __( 'Please reload the product page and try again.', 'peptide-core' ), 'pc_review_nonce', 403 ); }
		$p = wc_get_product( $product_id );
		if ( ! $p || 'product' !== get_post_type( $product_id ) || 'publish' !== $p->get_status() || ! $p->is_visible() || ! comments_open( $product_id ) ) { return self::error( __( 'This product is not accepting reviews.', 'peptide-core' ), 'pc_review_closed', 403 ); }
		if ( ! is_user_logged_in() && get_option( 'comment_registration' ) ) { return self::error( __( 'Please sign in to submit a review.', 'peptide-core' ), 'pc_review_login', 401 ); }
		if ( 'yes' === get_option( 'woocommerce_review_rating_verification_required' ) && ( ! is_user_logged_in() || ! wc_customer_bought_product( wp_get_current_user()->user_email, get_current_user_id(), $product_id ) ) ) { return self::error( __( 'Only customers with a verified purchase can review this product.', 'peptide-core' ), 'pc_review_purchase', 403 ); }
		$rating = (string) ( $input['rating'] ?? '' );
		if ( ( '' !== $rating && ( ! ctype_digit( $rating ) || (int) $rating < 1 || (int) $rating > 5 ) ) || ( wc_review_ratings_required() && '' === $rating ) ) { return self::error( __( 'Choose a rating from one to five.', 'peptide-core' ) ); }
		$rows = self::validate_uploads( $files ); if ( is_wp_error( $rows ) ) { return $rows; }
		if ( $rows ) { $storage = self::storage_dir(); if ( is_wp_error( $storage ) ) { return $storage; } }
		if ( $rows && empty( $input['media_consent'] ) ) { return self::error( __( 'Please confirm that you own and consent to publish the uploaded media.', 'peptide-core' ) ); }
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); $rate_key = 'pc_review_rate_' . hash_hmac( 'sha256', $ip . '|' . get_current_user_id(), wp_salt() );
		if ( (int) get_transient( $rate_key ) >= 6 ) { return self::error( __( 'Too many review submissions. Please try again later.', 'peptide-core' ), 'pc_review_rate', 429 ); }
		$clean = array( 'comment_post_ID' => $product_id, 'author' => sanitize_text_field( $input['author'] ?? '' ), 'email' => sanitize_email( $input['email'] ?? '' ), 'comment' => trim( wp_strip_all_tags( (string) ( $input['comment'] ?? '' ) ) ), 'comment_parent' => 0, 'rating' => $rating );
		if ( '' === $clean['comment'] || strlen( $clean['comment'] ) > 20000 ) { return self::error( __( 'Write a review of at most 20,000 characters.', 'peptide-core' ) ); }
		$old_post = $_POST; self::$submitting = true;
		try { $_POST = $clean; $result = wp_handle_comment_submission( $clean ); } finally { $_POST = $old_post; self::$submitting = false; }
		if ( is_wp_error( $result ) ) { return $result; }
		$id = (int) $result->comment_ID;
		$media = self::persist_uploads( $id, $rows );
		if ( is_wp_error( $media ) ) { wp_delete_comment( $id, true ); return $media; }
		update_comment_meta( $id, self::SOURCE, 'buyer' );
		update_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_IMAGES, $media['images'] ); update_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_VIDEO, $media['video'] );
		update_comment_meta( $id, '_pc_review_media_consent', $rows ? gmdate( 'c' ) : '' );
		update_comment_meta( $id, 'verified', self::verified_customer( $result ) ? 1 : 0 );
		set_transient( $rate_key, (int) get_transient( $rate_key ) + 1, HOUR_IN_SECONDS );
		return array( 'id' => $id, 'status' => 'pending', 'message' => __( 'Thank you. Your review is awaiting approval.', 'peptide-core' ) );
	}

	public static function submit_action(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { status_header( 405 ); exit; }
		$result = self::submit( wp_unslash( $_POST ), $_FILES );
		$json = str_contains( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ), 'application/json' );
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data(); $status = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 400 ) : ( is_int( $error_data ) ? $error_data : 400 );
			if ( $json ) { wp_send_json( array( 'success' => false, 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), $status ); }
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Review not submitted', 'peptide-core' ), array( 'response' => $status, 'back_link' => true ) );
		}
		if ( $json ) { wp_send_json( array( 'success' => true ) + $result ); }
		wp_safe_redirect( add_query_arg( 'pc_review_received', '1', get_permalink( (int) $_POST['comment_post_ID'] ) ) . '#reviews' ); exit;
	}

	public static function save_admin( array $input, array $files = array() ) {
		if ( ! self::can_manage() ) { return self::error( __( 'You cannot manage product reviews.', 'peptide-core' ), 'pc_review_forbidden', 403 ); }
		$id = absint( $input['id'] ?? 0 ); $old = $id ? get_comment( $id ) : null;
		$old_rating = $old ? (int) get_comment_meta( $id, 'rating', true ) : 0;
		$old_images = $old ? array_values( array_filter( array_map( 'absint', (array) get_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_IMAGES, true ) ) ) ) : array();
		$old_video = $old ? (int) get_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_VIDEO, true ) : 0;
		if ( $id && ( ! self::is_review( $old ) || ! current_user_can( 'edit_comment', $id ) ) ) { return self::error( __( 'This product review cannot be edited.', 'peptide-core' ), 'pc_review_forbidden', 403 ); }
		$product = absint( $input['product'] ?? 0 );
		if ( 'product' !== get_post_type( $product ) || ! current_user_can( 'edit_post', $product ) ) { return self::error( __( 'Choose a product you can edit.', 'peptide-core' ) ); }
		$rating = (string) ( $input['rating'] ?? '' ); $name = sanitize_text_field( $input['author'] ?? '' ); $email = sanitize_email( $input['email'] ?? '' ); $text = trim( wp_strip_all_tags( (string) ( $input['content'] ?? '' ) ) );
		if ( ! ctype_digit( $rating ) || (int) $rating < 1 || (int) $rating > 5 || '' === $name || '' === $text || strlen( $text ) > 20000 || ( ! empty( $input['email'] ) && ! is_email( $input['email'] ) ) ) { return self::error( __( 'Enter a reviewer name, review text, a rating from one to five and a valid optional email.', 'peptide-core' ) ); }
		$status = (string) ( $input['status'] ?? '0' ); if ( ! in_array( $status, array( '0', '1', 'spam', 'trash' ), true ) ) { return self::error( __( 'Invalid review status.', 'peptide-core' ) ); }
		$source = $old ? (string) get_comment_meta( $id, self::SOURCE, true ) : sanitize_key( $input['source'] ?? 'store' );
		if ( ! $old && ! in_array( $source, array( 'store', 'imported' ), true ) ) { return self::error( __( 'Choose store entry or imported review as the source.', 'peptide-core' ) ); }
		$date = str_replace( 'T', ' ', sanitize_text_field( $input['date'] ?? '' ) );
		if ( '' === $date ) { $date = $old ? $old->comment_date : current_time( 'mysql' ); }
		if ( 16 === strlen( $date ) ) { $date .= ':00'; }
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, wp_timezone() );
		if ( ! $dt || $dt->format( 'Y-m-d H:i:s' ) !== $date || $dt->getTimestamp() > time() + 300 ) { return self::error( __( 'Choose a valid review date that is not in the future.', 'peptide-core' ) ); }
		$images = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $input['image_ids'] ?? '' ) ) ) ) ) ); $video = absint( $input['video_id'] ?? 0 );
		foreach ( $images as $attachment ) { if ( ! wp_attachment_is_image( $attachment ) || ! self::selectable_media( $attachment, $id ) ) { return self::error( __( 'One of the selected review images is not available.', 'peptide-core' ) ); } }
		if ( $video && ( ! in_array( get_post_mime_type( $video ), array( 'video/mp4', 'video/webm' ), true ) || ! self::selectable_media( $video, $id ) ) ) { return self::error( __( 'Choose an available MP4 or WebM attachment.', 'peptide-core' ) ); }
		$rows = self::validate_uploads( $files ); if ( is_wp_error( $rows ) ) { return $rows; }
		if ( $rows ) { $storage = self::storage_dir(); if ( is_wp_error( $storage ) ) { return $storage; } }
		if ( count( $images ) + count( array_filter( $rows, static fn( $r ) => ! $r['video'] ) ) > max( 5, count( $old_images ) ) || ( $video && array_filter( $rows, static fn( $r ) => $r['video'] ) ) ) { return self::error( __( 'A review supports at most five photos and one video.', 'peptide-core' ) ); }
		$prepared_media = null;
		if ( $old ) { $prepared_media = self::persist_uploads( $id, $rows ); if ( is_wp_error( $prepared_media ) ) { return $prepared_media; } }
		$data = array( 'comment_post_ID' => $product, 'comment_author' => $name, 'comment_author_email' => $email, 'comment_content' => $text, 'comment_type' => 'review', 'comment_parent' => 0, 'comment_approved' => $status, 'comment_date' => $date, 'comment_date_gmt' => $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		if ( $id ) { $data['comment_ID'] = $id; $result = wp_update_comment( wp_slash( $data ), true ); } else { $data['user_id'] = 0; $result = wp_insert_comment( wp_slash( $data ) ); $id = (int) $result; }
		if ( is_wp_error( $result ) || ! $id ) { if ( $prepared_media ) { self::discard_new_media( $prepared_media ); } return self::error( __( 'The product review could not be saved.', 'peptide-core' ), 'pc_review_save', 500 ); }
		$media = $prepared_media ?? self::persist_uploads( $id, $rows );
		if ( is_wp_error( $media ) ) { if ( ! $old ) { wp_delete_comment( $id, true ); } return $media; }
		update_comment_meta( $id, 'rating', (int) $rating ); update_comment_meta( $id, self::SOURCE, $source ?: 'legacy' );
		update_comment_meta( $id, '_pc_review_reference', sanitize_textarea_field( $input['reference'] ?? '' ) );
		update_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_IMAGES, array_merge( $images, $media['images'] ) ); update_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_VIDEO, $media['video'] ?: $video ); update_comment_meta( $id, Modules\Commerce_Blocks::REVIEW_FEATURED, empty( $input['featured'] ) ? '0' : '1' );
		$comment = get_comment( $id ); update_comment_meta( $id, 'verified', self::verified_customer( $comment ) ? 1 : 0 );
		$changed = $old && ( $old->comment_content !== $text || $old->comment_author !== $name || $old->comment_author_email !== $email || (int) $old->comment_post_ID !== $product || $old_rating !== (int) $rating || $old->comment_date !== $date || $old_images !== array_merge( $images, $media['images'] ) || $old_video !== ( $media['video'] ?: $video ) );
		if ( $changed && in_array( $source, array( 'buyer', 'legacy', '' ), true ) ) { update_comment_meta( $id, '_pc_review_store_edited', '1' ); }
		$history = get_comment_meta( $id, '_pc_review_changes', true ); $history = is_array( $history ) ? $history : array(); $history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'action' => $old ? 'edit' : 'create' ); update_comment_meta( $id, '_pc_review_changes', array_slice( $history, -20 ) );
		$before_media = array_merge( $old_images, $old_video ? array( $old_video ) : array() ); $after_media = array_merge( $images, $media['images'], ( $media['video'] ?: $video ) ? array( $media['video'] ?: $video ) : array() );
		foreach ( array_diff( array_map( 'intval', $before_media ), array_map( 'intval', $after_media ) ) as $removed ) { if ( (int) get_post_meta( $removed, self::MEDIA_OWNER, true ) === $id ) { wp_delete_attachment( $removed, true ); } }
		\WC_Comments::clear_transients( $product ); if ( $old && (int) $old->comment_post_ID !== $product ) { \WC_Comments::clear_transients( (int) $old->comment_post_ID ); }
		return $id;
	}

	private static function selectable_media( int $id, int $review ): bool {
		$owner = (int) get_post_meta( $id, self::MEDIA_OWNER, true );
		if ( 'attachment' !== get_post_type( $id ) ) { return false; }
		if ( $owner ) { return $owner === $review && $review > 0 && self::can_manage() && current_user_can( 'edit_comment', $review ); }
		if ( current_user_can( 'edit_post', $id ) ) { return true; }
		$parent = (int) wp_get_post_parent_id( $id );
		return current_user_can( 'upload_files' ) && in_array( get_post_status( $id ), array( 'inherit', 'publish' ), true ) && ( ! $parent || ( 'publish' === get_post_status( $parent ) && ! post_password_required( $parent ) ) );
	}

	public static function source_html( $comment, bool $admin_context = false ): string {
		if ( ! $admin_context || ! self::can_manage() ) { return ''; }
		$c = get_comment( $comment ); if ( ! self::is_review( $c ) ) { return ''; }
		$source = (string) get_comment_meta( $c->comment_ID, self::SOURCE, true );
		$labels = array( 'store' => __( 'Store entry', 'peptide-core' ), 'imported' => __( 'Imported review', 'peptide-core' ), 'buyer' => __( 'Customer submission', 'peptide-core' ) );
		$out = isset( $labels[ $source ] ) ? '<span class="pc-review-source">' . esc_html( $labels[ $source ] ) . '</span>' : '';
		if ( get_comment_meta( $c->comment_ID, '_pc_review_store_edited', true ) ) { $out .= '<span class="pc-review-source">' . esc_html__( 'Edited by store', 'peptide-core' ) . '</span>'; }
		return $out ? '<div class="pc-review-provenance">' . $out . '</div>' : '';
	}

	public static function comment_html( string $text, $comment = null ): string {
		if ( is_admin() || ! self::is_review( $comment ) ) { return $text; }
		return $text . self::source_html( $comment ) . self::media_html( $comment );
	}

	public static function media_html( $comment ): string {
		$c = get_comment( $comment ); if ( ! $c || ( '1' !== $c->comment_approved && ! self::can_manage() && ( ! get_current_user_id() || (int) $c->user_id !== get_current_user_id() ) ) ) { return ''; }
		$out = ''; foreach ( (array) get_comment_meta( $c->comment_ID, Modules\Commerce_Blocks::REVIEW_IMAGES, true ) as $id ) { if ( wp_attachment_is_image( $id ) ) { $url = wp_get_attachment_url( $id ); $out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $id, 'medium' ) . '</a>'; } }
		$video = (int) get_comment_meta( $c->comment_ID, Modules\Commerce_Blocks::REVIEW_VIDEO, true ); if ( $video && in_array( get_post_mime_type( $video ), array( 'video/mp4', 'video/webm' ), true ) ) { $out .= '<video controls preload="metadata" src="' . esc_url( wp_get_attachment_url( $video ) ) . '"></video>'; }
		return $out ? '<div class="pc-review-media">' . $out . '</div>' : '';
	}

	public static function attachment_url( string $url, int $id ): string {
		$owner = (int) get_post_meta( $id, self::MEDIA_OWNER, true );
		if ( ! $owner || ! get_post_meta( $id, self::MEDIA_FILE, true ) ) { return $url; }
		$key = hash_hmac( 'sha256', $id . '|' . $owner, wp_salt( 'auth' ) );
		return add_query_arg( array( 'pc_review_media' => $id, 'key' => $key ), home_url( '/' ) );
	}

	public static function image_srcset( $sources, $size, $src, $meta, $id ) { return get_post_meta( (int) $id, self::MEDIA_OWNER, true ) ? false : $sources; }

	public static function serve_media(): void {
		if ( ! isset( $_GET['pc_review_media'] ) ) { return; }
		$id = absint( $_GET['pc_review_media'] ); $owner = (int) get_post_meta( $id, self::MEDIA_OWNER, true ); $comment = $owner ? get_comment( $owner ) : null;
		$signature = hash_hmac( 'sha256', $id . '|' . $owner, wp_salt( 'auth' ) );
		$linked = $comment && ( in_array( $id, array_map( 'intval', (array) get_comment_meta( $owner, Modules\Commerce_Blocks::REVIEW_IMAGES, true ) ), true ) || $id === (int) get_comment_meta( $owner, Modules\Commerce_Blocks::REVIEW_VIDEO, true ) );
		$public = $linked && '1' === $comment->comment_approved && 'publish' === get_post_status( $comment->comment_post_ID ) && ! post_password_required( $comment->comment_post_ID );
		$allowed = $linked && ( $public || ( $comment && ( self::can_manage() || ( get_current_user_id() && get_current_user_id() === (int) $comment->user_id ) ) ) );
		$name = (string) get_post_meta( $id, self::MEDIA_FILE, true ); $dir = self::storage_dir();
		if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) || ! $allowed || ! hash_equals( $signature, sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ) ) || ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp|mp4|webm)$/', $name ) || is_wp_error( $dir ) || ! is_file( $dir . '/' . $name ) ) { status_header( 404 ); nocache_headers(); exit; }
		$file = $dir . '/' . $name; $size = filesize( $file ); $start = 0; $end = $size - 1;
		$mime = get_post_mime_type( $id ); if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm' ), true ) ) { status_header( 404 ); exit; }
		if ( ! empty( $_SERVER['HTTP_RANGE'] ) ) {
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( (string) $_SERVER['HTTP_RANGE'] ), $range ) || ( '' === $range[1] && '' === $range[2] ) ) { status_header( 416 ); header( 'Content-Range: bytes */' . $size ); exit; }
			if ( '' === $range[1] ) { $start = max( 0, $size - (int) $range[2] ); } else { $start = (int) $range[1]; if ( '' !== $range[2] ) { $end = min( $end, (int) $range[2] ); } }
			if ( $start > $end || $start >= $size ) { status_header( 416 ); header( 'Content-Range: bytes */' . $size ); exit; }
			status_header( 206 ); header( "Content-Range: bytes $start-$end/$size" );
		}
		nocache_headers(); header( 'Content-Type: ' . $mime ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: inline; filename="review-media.' . pathinfo( $name, PATHINFO_EXTENSION ) . '"' ); header( 'Accept-Ranges: bytes' ); header( 'Content-Length: ' . ( $end - $start + 1 ) ); header( 'Cache-Control: private, no-store' );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) { exit; }
		$handle = fopen( $file, 'rb' ); if ( ! $handle ) { exit; } fseek( $handle, $start ); $remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $handle ) && ! connection_aborted() ) { $data = fread( $handle, min( 65536, $remaining ) ); if ( false === $data || '' === $data ) { break; } echo $data; $remaining -= strlen( $data ); }
		fclose( $handle ); exit;
	}

	public static function delete_media_file( int $id ): void {
		$name = (string) get_post_meta( $id, self::MEDIA_FILE, true );
		if ( preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp|mp4|webm)$/', $name ) ) { $file = Private_Files::dir() . '/review-media/' . $name; if ( is_file( $file ) ) { unlink( $file ); } }
	}

	public static function delete_review_media( int $id ): void {
		$owned = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::MEDIA_OWNER, 'meta_value' => $id ) );
		foreach ( $owned as $media ) { wp_delete_attachment( $media, true ); }
	}
}
