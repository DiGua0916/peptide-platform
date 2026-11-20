<?php
/** Site 1 abbreviation search (owner price sheet). Run: php tests/product-codes-pure.php */
define( 'ABSPATH', __DIR__ );
$GLOBALS['products'] = array();
function is_admin() { return false; }
function add_action( ...$a ) {}
function wc_get_products( $args ) { return $GLOBALS['products']; }
final class Fake_Product { public function __construct( private string $name ) {} public function get_name() { return $this->name; } }
require dirname( __DIR__ ) . '/includes/class-product-codes.php';
use PeptideCore\Product_Codes as C;
$ok = 0; $n = 0;
$t = function ( $cond, $msg ) use ( &$ok, &$n ) { $n++; if ( $cond ) { $ok++; } else { echo "FAIL $msg\n"; } };
$t( 'hgh191aasomatropin' === C::key( 'HGH 191AA （Somatropin）' ), 'full-width brackets and spaces normalize' );
$t( C::key( 'HGH  Fragment  176-191' ) === C::key( 'HGH Fragment 176-191' ), 'double spaces normalize' );
$t( in_array( 'TR30', C::for_name( 'Tirzepatide' ), true ) && 9 === count( C::for_name( 'Tirzepatide' ) ), 'Tirzepatide has its 9 codes' );
$w = C::search_words( 'Cagriliintide' );
$t( str_contains( $w, 'CGL5' ) && str_contains( $w, 'cagrilintide' ), 'misspelled Cagriliintide is found by codes and by the right spelling' );
$t( str_contains( C::search_words( 'bac.water' ), 'bacteriostaticwater' ) && str_contains( C::search_words( 'bac.water' ), 'WA10' ), 'bac.water gains Bacteriostatic Water' );
$t( str_contains( C::search_words( 'Lipo-C LC553' ), 'LC553' ) && ! str_contains( C::search_words( 'Lipo-C LC553' ), 'LC120' ), 'each Lipo-C keeps only its own code' );
$t( str_contains( C::search_words( 'Retatrutide 20mg + Tirzepatide 40mg' ), 'RET60' ), 'combo with the sheet typo still maps' );
$t( '' === C::search_words( 'Unknown product' ), 'unknown product adds nothing' );
$GLOBALS['products'] = array( new Fake_Product( 'Tirzepatide' ), new Fake_Product( 'Retatrutide' ), new Fake_Product( 'Cagriliintide' ) );
$t( 'Tirzepatide' === C::name_for_term( 'tr30' ), 'full code TR30 searches Tirzepatide' );
$t( 'Retatrutide' === C::name_for_term( 'RT' ), 'letter part RT searches Retatrutide' );
$t( 'Cagriliintide' === C::name_for_term( 'CGL10' ), 'CGL10 searches the store name' );
$t( null === C::name_for_term( 'semaglutide' ) && null === C::name_for_term( 'bpc 157' ) && null === C::name_for_term( 'T' ), 'ordinary words are untouched' );
echo "product-codes $ok/$n\n";
exit( $ok === $n ? 0 : 1 );
