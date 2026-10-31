<?php
/** Independent, raw-row-paginated personal-data exporter for optional page context. */
namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Page_Context_Privacy {
	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	public static function add_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['peptide-core-page-context'] = array( 'exporter_friendly_name' => __( 'Store page context', 'peptide-core' ), 'callback' => array( self::class, 'export' ) );
		return $exporters;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$ids = Privacy::visitors_for_email( $email );
		if ( ! $ids ) { return array( 'data' => array(), 'done' => true ); }
		$in = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, occurred_ms, data FROM ' . Schema::table( 'events' ) . " WHERE visitor_id IN ({$in}) AND type = 'page_view' ORDER BY id ASC LIMIT 501 OFFSET %d", ( max( 1, $page ) - 1 ) * 500 ), ARRAY_A );
		$items = array();
		foreach ( array_slice( (array) $rows, 0, 500 ) as $row ) {
			$data = json_decode( (string) $row['data'], true );
			$context = Page_Context::saved( is_array( $data ) ? ( $data['page_context'] ?? null ) : null );
			if ( null === $context ) { continue; }
			$fields = array(
				array( 'name' => __( 'Page kind', 'peptide-core' ), 'value' => $context['kind'] ),
				array( 'name' => __( 'Page path', 'peptide-core' ), 'value' => (string) $row['path'] ),
				array( 'name' => __( 'Observed at (UTC)', 'peptide-core' ), 'value' => gmdate( 'Y-m-d H:i:s', (int) floor( (int) $row['occurred_ms'] / 1000 ) ) . ' UTC' ),
				array( 'name' => __( 'Context source', 'peptide-core' ), 'value' => 'source_reported' ),
				array( 'name' => __( 'Country observation', 'peptide-core' ), 'value' => $context['geo']['status'] ?? 'unobserved' ),
			);
			if ( isset( $context['term_id'] ) ) { $fields[] = array( 'name' => __( 'Collection ID', 'peptide-core' ), 'value' => (string) $context['term_id'] ); }
			if ( isset( $context['geo']['country_code'] ) ) { $fields[] = array( 'name' => __( 'Country code', 'peptide-core' ), 'value' => $context['geo']['country_code'] ); }
			$items[] = array( 'group_id' => 'peptide-core-page-context', 'group_label' => __( 'Store page context', 'peptide-core' ), 'item_id' => 'page-context-' . wp_hash( 'page-context|' . $row['id'] ), 'data' => $fields );
		}
		return array( 'data' => $items, 'done' => count( (array) $rows ) <= 500 );
	}

	public static function policy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Store page context', 'peptide-core' ), '<p>' . esc_html__( 'When supplied by the storefront, page views may include a normalized page kind, collection identifier and source-reported country observation. Country observations are not verified physical locations or IP-derived coordinates. Missing observations remain unobserved. These facts follow the store visit retention, account/order association and erasure rules, and are included in a separate personal-data export.', 'peptide-core' ) . '</p>' );
		}
	}
}
