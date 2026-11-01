<?php
/** Relative, source-only dependencies for the three standalone product-action gates. */
spl_autoload_register( static function ( string $class ): void {
 $files = array( 'PeptideCore\\Tracking\\Product_Actions' => 'tracking/class-product-actions.php', 'PeptideCore\\Tracking\\Impressions' => 'tracking/class-impressions.php', 'PeptideCore\\Data\\Product_Actions' => 'data/class-product-actions.php' );
 if ( isset( $files[$class] ) ) { require_once dirname( __DIR__ ) . '/includes/' . $files[$class]; }
} );
