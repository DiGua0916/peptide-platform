<?php
/** Searchable editor references. Stored IDs survive inactive/deleted records; renderers enforce visibility. */
namespace PeptideCore\Modules;
defined( 'ABSPATH' ) || exit;

final class Records {
	private const POSTS = array( 'promotion' => 'pc_promotion', 'coupon' => 'shop_coupon', 'product_offer' => 'pc_product_offer', 'post' => 'post', 'topic' => 'pc_topic', 'product' => 'product' );
	public static function register_hooks(): void { add_action( 'wp_ajax_pc_layout_records', array( self::class, 'ajax' ) ); }
	public static function resources(): array { return array_merge( array_keys( self::POSTS ), array( 'blog_category', 'shipping_discount', 'review' ) ); }
	public static function sanitize( $value ): ?int {
		return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/^[0-9]+$/D', (string) $value ) && (int) $value > 0 ? (int) $value : null;
	}
	public static function ajax(): void {
		if ( ! current_user_can( \PeptideCore\Templates::CAP_EDIT ) ) { wp_send_json_error( array( 'message' => '没有装修权限。' ), 403 ); }
		check_ajax_referer( 'pc_layout_records', 'security' );
		$resource = sanitize_key( wp_unslash( $_GET['resource'] ?? '' ) );
		if ( ! in_array( $resource, self::resources(), true ) ) { wp_send_json_error( array( 'message' => '无效的记录类型。' ), 400 ); }
		$metadata_only = '1' === ( $_GET['metadata_only'] ?? '' ); $filters = array();
		if ( 'review' === $resource && ! $metadata_only ) {
			$media = wp_unslash( $_GET['review_media'] ?? 'all' ); $rating = wp_unslash( $_GET['review_rating'] ?? '0' );
			if ( ! is_string( $media ) || ! in_array( $media, array( 'all', 'with_media', 'without_media' ), true ) || ( ! is_string( $rating ) && ! is_int( $rating ) ) || ! in_array( (string) $rating, array( '0', '1', '2', '3', '4', '5' ), true ) || ! is_string( $_GET['term'] ?? '' ) ) { wp_send_json_error( array( 'message' => '无效的评论筛选条件。' ), 400 ); }
			$filters = array( 'media' => $media, 'rating' => (int) $rating );
		}
		$raw_term = wp_unslash( $_GET['term'] ?? '' ); $term = sanitize_text_field( $raw_term );
		if ( 'review' === $resource && ! $metadata_only && '' !== trim( $raw_term ) && '' === trim( $term ) ) { wp_send_json_success( array( 'options' => array(), 'more' => false ) ); }
		wp_send_json_success( self::search( $resource, $term, max( 1, absint( $_GET['paged'] ?? 1 ) ), absint( $_GET['selected'] ?? 0 ), self::ids( wp_unslash( $_GET['selected_ids'] ?? '' ) ), $metadata_only, $filters ) );
	}
	/** Bounded references only; every resolved option still passes its original resource and capability checks. */
	private static function ids( $value ): array {
		if ( is_string( $value ) ) { $value = explode( ',', substr( $value, 0, 2400 ), 101 ); }
		if ( ! is_array( $value ) ) { return array(); }
		$ids = array();
		foreach ( array_slice( $value, 0, 100 ) as $candidate ) {
			$id = self::sanitize( $candidate );
			if ( $id && ! in_array( $id, $ids, true ) ) { $ids[] = $id; }
		}
		return $ids;
	}
	/** Metadata only: no customer lists, credentials or internal rule configuration. */
	public static function search( string $resource, string $term = '', int $page = 1, int $selected = 0, array $selected_ids = array(), bool $metadata_only = false, array $filters = array() ): array {
		if ( ! current_user_can( \PeptideCore\Templates::CAP_EDIT ) || ! in_array( $resource, self::resources(), true ) ) { return array( 'options' => array(), 'more' => false ); }
		$selected_ids = self::ids( array_merge( $selected > 0 ? array( $selected ) : array(), $selected_ids ) );
		if ( $metadata_only ) {
			if ( 'review' === $resource ) {
				$resolved = Review_Blocks::record_options( $selected_ids ); $options = array();
				foreach ( $selected_ids as $id ) { $options[] = $resolved[ $id ] ?? array( 'value' => (string) $id, 'label' => '记录不可用（#' . $id . '）', 'unavailable' => true ); }
				return array( 'options' => $options, 'more' => false );
			}
			$options = array();
			foreach ( $selected_ids as $id ) { $options[] = self::option( $resource, $id ) ?: array( 'value' => (string) $id, 'label' => '记录不可用（#' . $id . '）', 'unavailable' => true ); }
			return array( 'options' => $options, 'more' => false );
		}
		$page = max( 1, min( 100000, $page ) ); $term = mb_substr( $term, 0, 100 ); $options = array();
		if ( 'review' === $resource ) { return Review_Blocks::record_search( $term, $page, $filters ); }
		if ( 'shipping_discount' === $resource ) {
			$marketing = current_user_can( 'edit_shop_coupons' );
			$rows = array_values( array_filter( \PeptideCore\Shipping\Discounts::all(), static fn( $r ) => ( $marketing || 'all' === $r['customers'] ) && ( '' === $term || false !== mb_stripos( $r['name'], $term ) ) ) );
			$more = count( $rows ) > $page * 20;
			foreach ( array_slice( $rows, ( $page - 1 ) * 20, 20 ) as $row ) { $options[] = array( 'value' => (string) $row['id'], 'label' => $row['name'] . ( $row['enabled'] ? '' : '（已停用）' ) ); }
		} elseif ( 'blog_category' === $resource ) {
			$rows = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'search' => $term, 'number' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'name', 'order' => 'ASC' ) ); $rows = is_wp_error( $rows ) ? array() : $rows; $more = count( $rows ) > 20;
			foreach ( array_slice( $rows, 0, 20 ) as $row ) { $options[] = array( 'value' => (string) $row->term_id, 'label' => $row->name ); }
		} else {
			$type = self::POSTS[ $resource ]; $object = get_post_type_object( $type ); $statuses = array( 'publish' );
			if ( $object && current_user_can( $object->cap->edit_posts ) ) { $statuses = array( 'publish', 'draft', 'pending', 'private', 'future' ); }
			$query = new \WP_Query( array( 'post_type' => $type, 'post_status' => $statuses, 's' => $term, 'search_columns' => array( 'post_title' ), 'posts_per_page' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true ) );
			$more = count( $query->posts ) > 20;
			foreach ( array_slice( $query->posts, 0, 20 ) as $post ) { $option = self::option( $resource, $post->ID ); if ( $option ) { $options[] = $option; } }
		}
		foreach ( array_reverse( $selected_ids ) as $id ) {
			if ( ! in_array( (string) $id, array_column( $options, 'value' ), true ) ) { array_unshift( $options, self::option( $resource, $id ) ?: array( 'value' => (string) $id, 'label' => '记录不可用（#' . $id . '）', 'unavailable' => true ) ); }
		}
		return array( 'options' => $options, 'more' => $more );
	}
	private static function option( string $resource, int $id ): ?array {
		if ( 'review' === $resource ) { return Review_Blocks::record_options( array( $id ) )[ $id ] ?? null; }
		if ( 'shipping_discount' === $resource ) { $r = \PeptideCore\Shipping\Discounts::get( $id ); return $r && ( current_user_can( 'edit_shop_coupons' ) || 'all' === $r['customers'] ) ? array( 'value' => (string) $id, 'label' => $r['name'] . ( $r['enabled'] ? '' : '（已停用）' ) ) : null; }
		if ( 'blog_category' === $resource ) { $term = get_term( $id, 'category' ); return $term instanceof \WP_Term ? array( 'value' => (string) $id, 'label' => $term->name ) : null; }
		$post = get_post( $id );
		if ( ! $post || ( self::POSTS[ $resource ] ?? '' ) !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) { return null; }
		if ( ! current_user_can( 'edit_post', $id ) ) {
			if ( 'publish' !== $post->post_status || '' !== $post->post_password ) { return null; }
			if ( 'coupon' === $resource && ( 'yes' !== get_post_meta( $id, \PeptideCore\Coupons::PUBLIC_META, true ) || ( new \WC_Coupon( $id ) )->get_email_restrictions() ) ) { return null; }
			if ( 'promotion' === $resource && empty( get_post_meta( $id, \PeptideCore\Promotions::META, true )['public'] ) ) { return null; }
			if ( 'product_offer' === $resource && empty( get_post_meta( $id, '_pc_product_offer', true )['enabled'] ) ) { return null; }
			if ( 'product' === $resource ) { $p = wc_get_product( $id ); if ( ! $p || ! $p->is_visible() ) { return null; } }
		}
		$labels = array( 'publish' => '已发布', 'draft' => '草稿', 'pending' => '待审', 'private' => '私密', 'future' => '定时' );
		return array( 'value' => (string) $id, 'label' => $post->post_title . '（#' . $id . ' · ' . ( $labels[ $post->post_status ] ?? $post->post_status ) . '）' );
	}
}
