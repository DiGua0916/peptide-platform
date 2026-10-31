<?php
/** The small, fixed display catalog shared by HQ and both checkout renderers. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Payment_Icons {

	public static function catalog(): array {
		return array(
			'american_express' => 'American Express', 'mastercard' => 'Mastercard',
			'visa' => 'Visa', 'discover' => 'Discover', 'apple_pay' => 'Apple Pay', 'paypal' => 'PayPal',
		);
	}

	public static function url( string $id ): string {
		return isset( self::catalog()[ $id ] )
			? plugins_url( 'assets/payment-icons/' . $id . '.svg', PEPTIDE_PANAPAY_FILE ) : '';
	}

	/** Explicit public DTO: no account, merchant, key or customer fields. */
	public static function data( array $ids ): array {
		$catalog = self::catalog(); $out = array(); $seen = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $catalog[ $id ] ) || isset( $seen[ $id ] ) ) { continue; }
			$seen[ $id ] = true;
			$out[] = array( 'id' => $id, 'label' => $catalog[ $id ], 'url' => self::url( $id ) );
		}
		return $out;
	}

	public static function markup( array $ids ): string {
		$icons = self::data( $ids );
		if ( ! $icons ) { return ''; }
		$html = '<span class="pc-panapay-icons" role="img" aria-label="' . esc_attr( implode( ', ', array_column( $icons, 'label' ) ) ) . '" style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;vertical-align:middle;margin-inline-start:8px">';
		foreach ( $icons as $icon ) {
			$html .= '<img src="' . esc_url( $icon['url'] ) . '" alt="" width="48" height="30" style="display:block;float:none;max-height:none;margin:0;width:48px;height:30px" decoding="async">';
		}
		return $html . '</span>';
	}
}
