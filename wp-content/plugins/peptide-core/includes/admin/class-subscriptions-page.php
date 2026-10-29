<?php
/** Marketing subscription list: consent evidence, export, and explicit deletion. */
namespace PeptideCore\Admin;

use PeptideCore\Subscriptions;

defined( 'ABSPATH' ) || exit;

final class Subscriptions_Page {
	public const SLUG = 'pc-promotions-subscribers';
	public const CAP = 'edit_shop_coupons';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 61 );
		add_action( 'admin_post_pc_subscribers_export', array( self::class, 'export' ) );
		add_action( 'admin_post_pc_subscribers_delete', array( self::class, 'delete' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}
	public static function menu(): void { add_submenu_page( 'pc-promotions', __( 'Email subscribers', 'peptide-core' ), __( 'Email subscribers', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) ); }
	public static function assets(): void {
		if ( self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { return; }
		Marketing::enqueue_assets();
		wp_enqueue_script( 'pc-subscribers-admin', plugins_url( 'assets/subscriptions-admin.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/subscriptions-admin.js' ), true );
	}
	private static function authorize(): void {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to manage subscribers.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
	}
	public static function status_labels(): array { return array( 'pending' => __( 'Awaiting confirmation', 'peptide-core' ), 'active' => __( 'Confirmed', 'peptide-core' ), 'unsubscribed' => __( 'Unsubscribed', 'peptide-core' ) ); }
	private static function filters( array $input ): array {
		$status = sanitize_key( $input['status'] ?? '' );
		return array( 'status' => isset( self::status_labels()[ $status ] ) ? $status : '', 'search' => substr( sanitize_text_field( $input['search'] ?? '' ), 0, 191 ) );
	}
	private static function where( array $filters ): string {
		global $wpdb; $where = '1=1';
		if ( $filters['status'] ) { $where .= $wpdb->prepare( ' AND status=%s', $filters['status'] ); }
		if ( $filters['search'] ) { $where .= $wpdb->prepare( ' AND email LIKE %s', '%' . $wpdb->esc_like( $filters['search'] ) . '%' ); }
		return $where;
	}

	public static function render(): void {
		self::authorize(); global $wpdb; $table = Subscriptions::table();
		$filters = self::filters( wp_unslash( $_GET ) ); $where = self::where( $filters ); $page = max( 1, absint( $_GET['paged'] ?? 1 ) ); $size = 30;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,email,status,source,consent_text,consent_at,created_at,confirmed_at,unsubscribed_at FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $size, ( $page - 1 ) * $size ), ARRAY_A );
		echo '<div class="wrap pc-mk"><h1>' . esc_html__( 'Email subscribers', 'peptide-core' ) . '</h1><p>' . esc_html__( 'Only confirmed addresses may receive subscription emails. Times are shown in UTC. This page does not send campaigns.', 'peptide-core' ) . '</p>';
		if ( isset( $_GET['deleted'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d subscription records deleted.', 'peptide-core' ), absint( $_GET['deleted'] ) ) ) . '</p></div>'; }
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label class="screen-reader-text" for="pc-sub-status">' . esc_html__( 'Subscription status', 'peptide-core' ) . '</label><select id="pc-sub-status" name="status"><option value="">' . esc_html__( 'All statuses', 'peptide-core' ) . '</option>';
		foreach ( self::status_labels() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters['status'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select> <label class="screen-reader-text" for="pc-sub-search">' . esc_html__( 'Search email', 'peptide-core' ) . '</label><input id="pc-sub-search" type="search" name="search" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search email', 'peptide-core' ) . '"> <button class="button">' . esc_html__( 'Filter', 'peptide-core' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:16px 0"><input type="hidden" name="action" value="pc_subscribers_export"><input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '"><input type="hidden" name="search" value="' . esc_attr( $filters['search'] ) . '">' . wp_nonce_field( 'pc_subscribers_export', '_wpnonce', false, false ) . '<button class="button">' . esc_html__( 'Export filtered CSV', 'peptide-core' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pc-subs-delete data-confirm="' . esc_attr__( 'Delete the selected subscription records permanently?', 'peptide-core' ) . '"><input type="hidden" name="action" value="pc_subscribers_delete">' . wp_nonce_field( 'pc_subscribers_delete', '_wpnonce', false, false ) . '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" data-pc-subs-all aria-label="' . esc_attr__( 'Select all records on this page', 'peptide-core' ) . '"></td>';
		foreach ( array( __( 'Email address', 'peptide-core' ), __( 'Status', 'peptide-core' ), __( 'Source', 'peptide-core' ), __( 'Consent evidence', 'peptide-core' ), __( 'Confirmed / unsubscribed (UTC)', 'peptide-core' ) ) as $heading ) { echo '<th scope="col">' . esc_html( $heading ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th class="check-column" scope="row"><input type="checkbox" name="ids[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'peptide-core' ), $row['email'] ) ) . '"></th><td>' . esc_html( $row['email'] ) . '</td><td>' . esc_html( self::status_labels()[ $row['status'] ] ?? $row['status'] ) . '</td><td style="overflow-wrap:anywhere">' . esc_html( $row['source'] ) . '</td><td>' . esc_html( $row['consent_text'] ) . '<br><small>' . esc_html( $row['consent_at'] ) . '</small></td><td>' . esc_html( $row['confirmed_at'] ?: '—' ) . '<br>' . esc_html( $row['unsubscribed_at'] ?: '—' ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html__( 'No subscription records found.', 'peptide-core' ) . '</td></tr>'; }
		echo '</tbody></table><p><label><input type="checkbox" name="confirm_delete" value="1" required> ' . esc_html__( 'I confirm permanent deletion of the selected records.', 'peptide-core' ) . '</label> <button class="button">' . esc_html__( 'Delete selected', 'peptide-core' ) . '</button></p></form>';
		$pages = max( 1, (int) ceil( $total / $size ) );
		if ( $pages > 1 ) { echo '<nav aria-label="' . esc_attr__( 'Subscriber pages', 'peptide-core' ) . '">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array_merge( array( 'page' => self::SLUG ), $filters, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'total' => $pages, 'current' => $page ) ) ) . '</nav>'; }
		echo '</div>';
	}

	public static function export(): void {
		self::authorize(); check_admin_referer( 'pc_subscribers_export' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the export form.', 'peptide-core' ), '', array( 'response' => 405 ) ); }
		global $wpdb; $table = Subscriptions::table(); $where = self::where( self::filters( wp_unslash( $_POST ) ) );
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="store-subscribers-' . gmdate( 'Ymd' ) . '.csv"' ); header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'email', 'status', 'source', 'consent_text', 'consent_at_utc', 'created_at_utc', 'confirmed_at_utc', 'unsubscribed_at_utc' ), ',', '"', '' );
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,email,status,source,consent_text,consent_at,created_at,confirmed_at,unsubscribed_at FROM $table WHERE $where AND id>%d ORDER BY id ASC LIMIT 500", $last ), ARRAY_A );
			foreach ( $rows as $row ) { $last = (int) $row['id']; unset( $row['id'] ); fputcsv( $out, array_map( array( Subscriptions::class, 'csv_cell' ), array_values( $row ) ), ',', '"', '' ); }
		} while ( count( $rows ) === 500 );
		fclose( $out ); exit;
	}
	public static function delete(): void {
		self::authorize(); check_admin_referer( 'pc_subscribers_delete' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '1' !== (string) ( $_POST['confirm_delete'] ?? '' ) ) { wp_die( esc_html__( 'Confirm deletion using the list form.', 'peptide-core' ), '', array( 'response' => 400 ) ); }
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) ) ), 0, 100 );
		global $wpdb; $count = 0;
		if ( $ids ) { $count = (int) $wpdb->query( 'DELETE FROM ' . Subscriptions::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); }
		wp_safe_redirect( Marketing::url( self::SLUG, array( 'deleted' => $count ) ) ); exit;
	}
}
