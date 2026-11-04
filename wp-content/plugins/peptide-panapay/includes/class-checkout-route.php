<?php
namespace VialCove\Panapay;

use Automattic\WooCommerce\StoreApi\Routes\V1\Checkout;

defined( 'ABSPATH' ) || exit;

/** Keep Woo's entire checkout pipeline; intercept only its draft lookup after native authentication. */
final class Checkout_Route extends Checkout {
	private ?\WP_REST_Request $handoff_request = null;
	private bool $validated_dispatch = false;

	public static function dispatch( \WP_REST_Request $request, Checkout $native ) {
		// A fresh route prevents the native private order property leaking between batch subrequests.
		$route = new self( $native->schema_controller, $native->schema );
		$route->set_namespace( $native->get_namespace() );
		return $route->get_response( $request );
	}

	public function get_response( \WP_REST_Request $request ) {
		$this->handoff_request = $request;
		try { return parent::get_response( $request ); }
		finally {
			Checkout_Handoff::finish();
			$this->handoff_request = null;
			$this->validated_dispatch = false;
		}
	}

	protected function get_response_by_request_method( \WP_REST_Request $request ) {
		// Native get_response loads the authenticated cart and checks its nonce before this dispatch.
		$this->validated_dispatch = true;
		return parent::get_response_by_request_method( $request );
	}

	protected function get_draft_order() {
		$order = parent::get_draft_order();
		return $this->validated_dispatch && $this->handoff_request
			? Checkout_Handoff::resolve( $order, $this->handoff_request ) : $order;
	}

	/** Older native variants sync drafts after failed update requests; never let that mutate a frozen order. */
	protected function cart_updated( \WP_REST_Request $request ) {
		if ( ! $this->validated_dispatch ) { return; }
		$order = parent::get_draft_order();
		if ( $order instanceof \WC_Order && '' !== $order->get_meta( '_pc_panapay_sessions', true ) ) { return; }
		parent::cart_updated( $request );
	}
}
