<?php
/** Real, separately published merchant replies to approved main-product reviews. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Review_Replies {

	private const MERCHANT = '_pc_review_reply_merchant';
	private const SUBMISSION = '_pc_review_reply_submission';
	private const HISTORY = '_pc_review_reply_changes';

	/**
	 * Minimal public DTOs, keyed by review ID. Only the latest eligible reply is shown.
	 * No comment_text filters, private notes, email addresses or account details escape.
	 *
	 * @param int[] $review_ids Main-product parent review IDs.
	 * @return array<int,array{id:int,content:string,date:string,datetime:string}>
	 */
	public static function public_for( array $review_ids ): array {
		$parents = array();
		foreach ( array_slice( array_unique( array_map( 'absint', $review_ids ) ), 0, 100 ) as $id ) {
			if ( $id < 1 ) { continue; }
			$review = get_comment( $id );
			if ( ! Reviews::is_review( $review ) || (int) $review->comment_ID !== $id || '1' !== (string) $review->comment_approved ) { continue; }
			$product = get_post( (int) $review->comment_post_ID );
			$wc = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $review->comment_post_ID ) : null;
			if ( ! $product || 'publish' !== $product->post_status || $product->post_password || ! $wc || ! $wc->is_visible() ) { continue; }
			$parents[ $id ] = $review;
		}
		if ( ! $parents ) { return array(); }
		$result = array();
		$rows = self::children( array_keys( $parents ), array( 'approve' ) );
		foreach ( $rows as $reply ) {
			$parent_id = (int) $reply->comment_parent;
			if ( '1' !== (string) $reply->comment_approved || isset( $result[ $parent_id ] ) || ! isset( $parents[ $parent_id ] ) || ! self::is_merchant( $reply, $parents[ $parent_id ] ) ) { continue; }
			$content = self::public_html( (string) $reply->comment_content );
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) { continue; }
			$result[ $parent_id ] = array(
				'id' => (int) $reply->comment_ID,
				'content' => $content,
				'date' => get_comment_date( 'Y-m-d', $reply ),
				'datetime' => get_comment_date( 'c', $reply ),
			);
		}
		return $result;
	}

	/** Only management callers can inspect non-public merchant reply history. */
	public static function manageable_for( int $review_id ): array {
		$parent = self::parent_for_management( $review_id, 0, false );
		if ( is_wp_error( $parent ) ) { return array(); }
		return array_values( array_filter( self::children( array( $review_id ) ), static function ( $reply ) use ( $parent ): bool {
			return self::is_merchant( $reply, $parent ) && current_user_can( 'edit_comment', (int) $reply->comment_ID );
		} ) );
	}

	/** Content/status fingerprint detects stale forms, including changes from native WP tools. */
	public static function version( int $review_id ): string {
		if ( $review_id < 1 ) { return ''; }
		$parent = get_comment( $review_id );
		if ( ! $parent || (int) $parent->comment_ID !== $review_id ) { return ''; }
		$state = array( (int) $parent->comment_post_ID, (string) $parent->comment_approved, (string) $parent->comment_content );
		foreach ( self::children( array( $review_id ) ) as $reply ) {
			$state[] = array( (int) $reply->comment_ID, (int) $reply->comment_post_ID, (int) $reply->comment_parent, (string) $reply->comment_approved, (string) $reply->comment_content, (int) $reply->user_id, (string) $reply->comment_author, (string) $reply->comment_author_email, get_comment_meta( (int) $reply->comment_ID, self::MERCHANT, true ) );
		}
		return hash_hmac( 'sha256', (string) wp_json_encode( $state ), wp_salt( 'auth' ) );
	}

	/**
	 * Validates both HTTP intent and authorization; caller supplies unslashed POST data.
	 * wp_insert_comment deliberately avoids wp_new_comment's customer notification path.
	 *
	 * @return int|\WP_Error Reply ID, or a recoverable failure.
	 */
	public static function change( array $input ) {
		$review_id = absint( self::scalar( $input, 'review' ) );
		$product_id = absint( self::scalar( $input, 'product' ) );
		$action = sanitize_key( self::scalar( $input, 'reply_action' ) );
		$reply_id = absint( self::scalar( $input, 'reply' ) );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return self::error( 'method', '请使用公开回复表单提交。', 405 ); }
		if ( ! wp_verify_nonce( self::scalar( $input, '_wpnonce' ), 'pc_review_reply_' . $review_id ) ) { return self::error( 'nonce', '公开回复表单已过期，请刷新页面后重试。', 403 ); }
		if ( ! $product_id ) { return self::error( 'product', '请选择这条评价所属的主商品。', 400 ); }
		if ( ! in_array( $action, array( 'create', 'edit', 'trash', 'restore' ), true ) || ( 'create' === $action ? 0 !== $reply_id : ! $reply_id ) ) { return self::error( 'action', '无效的公开回复操作。', 400 ); }
		$parent = self::parent_for_management( $review_id, $product_id );
		if ( is_wp_error( $parent ) ) { return $parent; }
		$content = wp_kses_post( trim( self::scalar( $input, 'reply_content' ) ) );
		if ( in_array( $action, array( 'create', 'edit' ), true ) && ( '' === trim( wp_strip_all_tags( $content ) ) || strlen( $content ) > 20000 ) ) { return self::error( 'content', '请填写回复正文，长度不超过 20,000 字节。', 400 ); }
		$token = self::scalar( $input, 'submission' );
		if ( 'create' === $action && ! preg_match( '/^[a-f0-9-]{32,64}$/iD', $token ) ) { return self::error( 'submission', '回复提交标识无效，请刷新页面后重试。', 400 ); }
		global $wpdb;
		// A per-parent DB lock closes the fingerprint check/write race across requests.
		$lock = 'pc_review_reply_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $review_id ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return self::error( 'busy', '这条评价正在被编辑，请稍后刷新再试。', 409 ); }
		try {
			clean_comment_cache( $review_id );
			$parent = self::parent_for_management( $review_id, $product_id );
			if ( is_wp_error( $parent ) ) { return $parent; }
			$rows = self::children( array( $review_id ) );
			$submission = hash_hmac( 'sha256', get_current_user_id() . '|' . $review_id . '|' . $token, wp_salt( 'auth' ) );
			if ( 'create' === $action ) {
				foreach ( $rows as $existing ) {
					if ( $submission !== get_comment_meta( (int) $existing->comment_ID, self::SUBMISSION, true ) || ! self::is_merchant( $existing, $parent ) ) { continue; }
					return hash_equals( (string) $existing->comment_content, $content ) ? (int) $existing->comment_ID : self::error( 'reused', '这次提交已处理，正文已改变。请刷新后重新编辑。', 409 );
				}
			}
			if ( ! hash_equals( self::version( $review_id ), self::scalar( $input, 'thread_version' ) ) ) { return self::error( 'conflict', '公开回复已被其他操作更新。请刷新后核对最新内容，再保存。', 409 ); }
			if ( 'create' === $action ) {
				$actor = wp_get_current_user();
				$id = wp_insert_comment( wp_slash( array( 'comment_post_ID' => $product_id, 'comment_parent' => $review_id, 'comment_type' => 'comment', 'comment_content' => $content, 'comment_approved' => 1, 'user_id' => (int) $actor->ID, 'comment_author' => sanitize_text_field( $actor->display_name ), 'comment_author_email' => sanitize_email( $actor->user_email ), 'comment_author_url' => '', 'comment_author_IP' => '', 'comment_agent' => '', 'comment_date' => current_time( 'mysql' ), 'comment_date_gmt' => current_time( 'mysql', true ) ) ) );
				if ( ! $id ) { return self::error( 'save', '公开回复未能发布，请重试。', 500 ); }
				$reply = get_comment( $id );
				update_comment_meta( $id, self::MERCHANT, self::marker( $reply ) );
				update_comment_meta( $id, self::SUBMISSION, $submission );
				self::audit( $id, 'create' );
				return (int) $id;
			}
			$reply = get_comment( $reply_id );
			if ( ! $reply || ! self::is_merchant( $reply, $parent ) || ! current_user_can( 'edit_comment', $reply_id ) ) { return self::error( 'reply', '找不到属于这条评价的可管理商家回复。', 403 ); }
			if ( 'edit' === $action ) {
				if ( ! in_array( (string) $reply->comment_approved, array( '0', '1' ), true ) ) { return self::error( 'status', '请先恢复这条回复，再编辑正文。', 409 ); }
				// Keep validated original authorship; identity fields are never read from POST.
				$result = hash_equals( (string) $reply->comment_content, $content ) ? $reply_id : wp_update_comment( wp_slash( array( 'comment_ID' => $reply_id, 'comment_content' => $content ) ), true );
			} elseif ( 'trash' === $action ) {
				// Core permanently deletes when trash is disabled; fail closed instead.
				if ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! EMPTY_TRASH_DAYS || 'trash' === (string) $reply->comment_approved ) { return self::error( 'trash', '这条回复不能移入回收站；未进行永久删除。', 409 ); }
				$result = wp_trash_comment( $reply_id );
			} else {
				if ( 'trash' !== (string) $reply->comment_approved ) { return self::error( 'restore', '这条回复不在回收站中，请刷新页面。', 409 ); }
				$result = wp_untrash_comment( $reply_id );
			}
			if ( is_wp_error( $result ) || ! $result ) { return self::error( 'save', '公开回复未能保存，请刷新后重试。', 500 ); }
			self::audit( $reply_id, $action );
			return $reply_id;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function parent_for_management( int $review_id, int $product_id, bool $approved = true ) {
		// Core get_comment(0) aliases the current global comment; an ID is mandatory.
		if ( $review_id < 1 ) { return self::error( 'permission', '没有这条商品评价的公开回复管理权限。', 403 ); }
		$parent = get_comment( $review_id );
		if ( ! Reviews::can_manage() || ! Reviews::is_review( $parent ) || (int) $parent->comment_ID !== $review_id || ! current_user_can( 'edit_comment', $review_id ) || ! current_user_can( 'edit_post', (int) $parent->comment_post_ID ) || ( $product_id && $product_id !== (int) $parent->comment_post_ID ) || 'trash' === get_post_status( (int) $parent->comment_post_ID ) ) { return self::error( 'permission', '没有这条商品评价的公开回复管理权限。', 403 ); }
		if ( $approved && '1' !== (string) $parent->comment_approved ) { return self::error( 'parent_status', '请先审核通过买家评价，再管理公开回复。', 409 ); }
		return $parent;
	}

	/** Query only likely merchant children; guest replies cannot crowd out the reply. */
	private static function children( array $review_ids, array $status = array( 'approve', 'hold', 'spam', 'trash' ) ): array {
		global $wpdb;
		$filter = static function ( array $clauses, \WP_Comment_Query $query ) use ( $wpdb ): array {
			if ( ! empty( $query->query_vars['pc_merchant_reply_children'] ) ) {
				$clauses['where'] .= $wpdb->prepare( " AND ({$wpdb->comments}.user_id > 0 OR EXISTS (SELECT 1 FROM {$wpdb->commentmeta} pcm_reply WHERE pcm_reply.comment_id = {$wpdb->comments}.comment_ID AND pcm_reply.meta_key = %s))", self::MERCHANT );
			}
			return $clauses;
		};
		add_filter( 'comments_clauses', $filter, 10, 2 );
		try {
			return get_comments( array( 'pc_merchant_reply_children' => true, 'cache_domain' => 'pc-merchant-replies-v1', 'parent__in' => $review_ids, 'status' => $status, 'number' => 500, 'orderby' => array( 'comment_date_gmt' => 'DESC', 'comment_ID' => 'DESC' ), 'update_comment_meta_cache' => true ) );
		} finally { remove_filter( 'comments_clauses', $filter, 10 ); }
	}

	private static function is_merchant( \WP_Comment $reply, \WP_Comment $parent ): bool {
		if ( (int) $reply->comment_parent !== (int) $parent->comment_ID || (int) $reply->comment_post_ID !== (int) $parent->comment_post_ID || ! in_array( $reply->comment_type, array( '', 'comment', 'review' ), true ) ) { return false; }
		$marker = get_comment_meta( (int) $reply->comment_ID, self::MERCHANT, true );
		if ( is_array( $marker ) && isset( $marker['proof'], $marker['actor'], $marker['name'], $marker['email'], $marker['created'] ) ) {
			$proof = (string) $marker['proof']; unset( $marker['proof'] );
			if ( hash_equals( self::sign( $reply, $marker ), $proof ) && (int) $marker['actor'] > 0 && ( (int) $reply->user_id === (int) $marker['actor'] || ( ! (int) $reply->user_id && ! get_userdata( (int) $marker['actor'] ) ) ) && hash_equals( (string) $marker['name'], (string) $reply->comment_author ) && hash_equals( (string) $marker['email'], hash( 'sha256', strtolower( (string) $reply->comment_author_email ) ) ) ) { return true; }
			// After an auth-salt rotation, an actively authorized real account can
			// still prove a legacy reply. Departed authors require the original salt.
		}
		// Legacy native replies need an identifiable, currently authorized merchant.
		$user = get_userdata( (int) $reply->user_id );
		return $user && $user->ID && $user->user_email && 0 === strcasecmp( $user->user_email, (string) $reply->comment_author_email ) && user_can( $user, 'edit_products' ) && user_can( $user, 'moderate_comments' ) && user_can( $user, 'edit_post', (int) $parent->comment_post_ID ) && ( ! Staff::is_staff( $user->ID ) || in_array( 'products', Staff::granted( $user->ID ), true ) );
	}

	private static function marker( \WP_Comment $reply ): array {
		$value = array( 'actor' => (int) $reply->user_id, 'name' => (string) $reply->comment_author, 'email' => hash( 'sha256', strtolower( (string) $reply->comment_author_email ) ), 'created' => (string) $reply->comment_date_gmt );
		$value['proof'] = self::sign( $reply, $value );
		return $value;
	}

	private static function sign( \WP_Comment $reply, array $marker ): string {
		return hash_hmac( 'sha256', (int) $reply->comment_ID . '|' . (int) $reply->comment_parent . '|' . (int) $reply->comment_post_ID . '|' . wp_json_encode( $marker ), wp_salt( 'auth' ) );
	}

	private static function audit( int $id, string $action ): void {
		$history = get_comment_meta( $id, self::HISTORY, true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'action' => $action );
		update_comment_meta( $id, self::HISTORY, array_slice( $history, -20 ) );
	}

	private static function public_html( string $content ): string { return wp_kses_post( wpautop( $content ) ); }
	private static function scalar( array $input, string $key ): string { return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : ''; }
	private static function error( string $code, string $message, int $status ): \WP_Error { return new \WP_Error( 'pc_reply_' . $code, $message, array( 'status' => $status ) ); }
}
