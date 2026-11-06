<?php
/** Stress test item 4: corrected "Specificatio" label and the reused Spanish translation. */
namespace { define( 'ABSPATH', __DIR__ . '/' ); $GLOBALS['vc_filters'] = array(); $checks = 0;
    function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['vc_filters'][ $hook ] = $callback; } function add_action( ...$args ) {}
    $check = static function ( string $label, bool $ok ) use ( &$checks ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL $label\n" ); exit( 1 ); } ++$checks; };
    require dirname( __DIR__ ) . '/inc/product-options.php';
    $filter = $GLOBALS['vc_filters']['woocommerce_attribute_label'] ?? null;
    $check( 'filter registered', 'vc_attribute_display_label' === $filter );
    $check( 'exact imported label corrected', 'Specification' === vc_attribute_display_label( 'Specificatio' ) );
    $check( 'case/space variant corrected', 'Specification' === vc_attribute_display_label( ' specificatio ' ) );
    foreach ( array( 'Specification', 'Specifications', 'Size', 'L-carnitine', 'Specificati', '' ) as $other ) { $check( 'other label unchanged: ' . $other, $other === vc_attribute_display_label( $other ) ); }
    $check( 'non-string passes through', null === vc_attribute_display_label( null ) );
}
namespace PeptideCore { final class Storefront_Translation {} }
namespace {
    require dirname( __DIR__, 3 ) . '/plugins/peptide-core/includes/class-storefront-translator.php';
    $dictionary = array( 'Specificatio: 10ml*10vials' => 'Especificaciones: 10ml*10vials', 'Specification: 5mg*10vials' => 'Especificación: 5mg*10viales' );
    $text = array( \PeptideCore\Storefront_Translator::class, 'text' );
    $check( 'corrected label reuses reviewed translation', 'Especificaciones: 10ml*10vials' === $text( 'Specification: 10ml*10vials', $dictionary ) );
    $check( 'direct key still wins', 'Especificación: 5mg*10viales' === $text( 'Specification: 5mg*10vials', $dictionary ) );
    $check( 'whitespace preserved', ' Especificaciones: 10ml*10vials ' === $text( ' Specification: 10ml*10vials ', $dictionary ) );
    $check( 'unknown value not invented', 'Specification: 7mg*10vials' === $text( 'Specification: 7mg*10vials', $dictionary ) );
    $check( 'original imported text still translated', 'Especificaciones: 10ml*10vials' === $text( 'Specificatio: 10ml*10vials', $dictionary ) );
    echo "PASS {$checks} attribute label checks\n";
}
