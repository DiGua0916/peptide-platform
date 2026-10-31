<?php
/** Native REST access to working design documents requires per-document edit permission. */
namespace PeptideCore\Api;

use PeptideCore\Templates;
defined( 'ABSPATH' ) || exit;

final class Layout_Posts_Controller extends \WP_REST_Posts_Controller {
	/** A raw publish status is a template-container detail, never storefront publication. */
	public function check_read_permission( $post ) {
		return $post instanceof \WP_Post && Templates::LAYOUT === $post->post_type && parent::check_update_permission( $post );
	}

	/** Core collections still check every result through check_read_permission above. */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'pc_layout_read_forbidden', __( 'Design documents are available only to their editors.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return parent::get_items_permissions_check( $request );
	}
}
