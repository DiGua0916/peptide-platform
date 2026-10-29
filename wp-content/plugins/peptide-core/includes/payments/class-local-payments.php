<?php
/** Explicit local-only provider substitution. No checkout, inventory or transport overrides. */
namespace PeptideCore\Payments;

defined( 'ABSPATH' ) || exit;

final class Local_Payments {
	public const OPTION = 'peptide_core_local_payments';
	public const IDS = array( 'pc_local_card', 'pc_local_wallet' );

	public static function allowed(): bool {
		return 'local' === wp_get_environment_type() && 'yes' === get_option( self::OPTION );
	}

	public static function title( string $id ): string {
		return 'pc_local_card' === $id ? __( 'Local simulation — card (no charge)', 'peptide-core' ) : __( 'Local simulation — wallet (no charge)', 'peptide-core' );
	}

	public static function description(): string {
		return __( 'Local test payment only. No charge will be made.', 'peptide-core' );
	}

	public static function register_hooks(): void {
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'available' ), PHP_INT_MAX );
		// Installed Woo IntegrationRegistry::initialize fires this before script/data collection.
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'blocks' ), PHP_INT_MAX );
	}

	public static function gateways( array $gateways ): array {
		if ( self::allowed() ) {
			foreach ( self::IDS as $id ) { $gateways[] = new Local_Gateway( $id ); }
		}
		return $gateways;
	}

	public static function available( array $gateways ): array {
		return self::allowed() ? array_intersect_key( $gateways, array_flip( self::IDS ) ) : $gateways;
	}

	public static function blocks( $registry ): void {
		if ( ! self::allowed() || ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) { return; }
		// Native registry removal also removes real providers' frontend scripts and settings.
		foreach ( $registry->get_all_registered() as $name => $method ) {
			if ( ! in_array( $name, self::IDS, true ) ) { $registry->unregister( $name ); }
		}
		foreach ( self::IDS as $id ) {
			if ( ! $registry->is_registered( $id ) ) { $registry->register( new Local_Blocks( $id ) ); }
		}
	}
}
