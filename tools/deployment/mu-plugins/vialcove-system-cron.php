<?php
/** Keep WooCommerce background work on the configured external scheduler. */
defined( 'ABSPATH' ) || exit;

// Deploy only after the shared store database has a working external cron.
if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
	add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
}
