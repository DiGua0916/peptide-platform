<?php
/** Parent-only names for the shared exposure reducer; never add final page rows together. */
namespace PeptideCore\Data;
defined( 'ABSPATH' ) || exit;

final class Product_Exposure {
	public const MAP = array( 'exposed_users' => 'exposed_users', 'impressions' => 'impressions', 'exposure_viewed_users' => 'viewed_users', 'exposure_view_user_rate' => 'view_user_rate', 'exposure_views' => 'views', 'exposure_view_rate' => 'view_rate', 'exposure_cart_users' => 'cart_users', 'exposure_cart_user_rate' => 'cart_user_rate', 'exposure_carts' => 'carts', 'exposure_cart_rate' => 'cart_rate', 'exposure_paid_users' => 'paid_users', 'exposure_paid_user_rate' => 'paid_user_rate', 'exposure_paid_units' => 'paid_units', 'exposure_paid_rate' => 'paid_rate' );
	public static function metrics( ?array $values ): array {
		$out = array(); foreach ( self::MAP as $key => $source ) { $out[$key] = null === $values ? null : $values[$source]; } return $out;
	}
}
