<?php
/** Isolated public-service/old-Core compatibility boundary; no WordPress or DB. */
$legacy = 'legacy' === ( $argv[1] ?? '' ); $calls = $rest_calls = $passed = 0;
class WP_REST_Request { public function __construct( public string $method, public string $route ) {} }
class ServiceFixture { public function __construct( private int $id ) {} public function get_id() { return $this->id; } }
function wp_json_encode( $value ) { return json_encode( $value ); }
function extra( $id ) { return array( 'is_demo' => false, 'specs' => array( (object) array( 'label' => 'Actual', 'value' => (string) $id ) ), 'batch_count' => 2, 'report_state' => 'restricted', 'price_pair' => array( 'price' => '5500', 'variation_id' => $id ), 'unit_pricing' => null ); }
function rest_do_request( $request ) {
    ++$GLOBALS['rest_calls'];
    return new class( $request->route ) {
        public function __construct( private string $route ) {}
        public function get_data() { return array( 'extensions' => (object) array( 'peptide_core' => extra( (int) basename( $this->route ) ) ) ); }
        public function is_error() { return str_ends_with( $this->route, '/99' ); }
    };
}
if ( ! $legacy ) {
    function peptide_core_get_store_product_data( $product ) {
        ++$GLOBALS['calls']; $id = $product->get_id();
        if ( 2 === $id ) { return null; }
        if ( 3 === $id ) { throw new RuntimeException( 'Deliberate unavailable service' ); }
        if ( 4 === $id ) { return extra( $id ) + array( 'invalid' => "\xB1\x31" ); }
        return extra( $id );
    }
}
$tokens = token_get_all( file_get_contents( dirname( __DIR__ ) . '/functions.php' ) ); $capture = $opened = false; $depth = 0; $body = '';
foreach ( $tokens as $i => $token ) {
    if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
        for ( $j = $i + 1; $j < count( $tokens ); ++$j ) {
            if ( is_array( $tokens[$j] ) && T_STRING === $tokens[$j][0] ) { $capture = 'vc_product_data' === $tokens[$j][1]; break; }
            if ( '(' === $tokens[$j] ) { break; }
        }
    }
    if ( ! $capture ) { continue; }
    $body .= is_array( $token ) ? $token[1] : $token;
    if ( '{' === $token ) { ++$depth; $opened = true; }
    elseif ( '}' === $token && 0 === --$depth && $opened ) { break; }
}
if ( ! $opened ) { throw new RuntimeException( 'Production reader missing' ); } eval( $body );
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$GLOBALS['passed']; }
$expected = json_decode( json_encode( extra( 1 ) ), true );
check( vc_product_data( new ServiceFixture( 1 ) ) === $expected, 'Exact DTO fields and nested normalization preserved' );
check( vc_product_data( new ServiceFixture( 1 ) ) === $expected, 'Repeated exact SKU remains stable' );
if ( $legacy ) {
    check( 1 === $rest_calls, 'Old Core uses one native REST request per SKU' );
    check( vc_product_data( new ServiceFixture( 99 ) ) === array(), 'Native REST error is empty' );
    vc_product_data( new ServiceFixture( 99 ) ); check( 2 === $rest_calls, 'Failed legacy read is not retried within render' );
} else {
    check( 1 === $calls && 0 === $rest_calls, 'Modern path uses service once without full schema' );
    foreach ( array( 2, 3, 4 ) as $id ) {
        check( vc_product_data( new ServiceFixture( $id ) ) === array(), 'Denied/error/malformed DTO stays empty' );
        vc_product_data( new ServiceFixture( $id ) );
    }
    check( 4 === $calls, 'Negative outcomes are request-local memoized' );
    check( 0 === $rest_calls, 'Null and exception cannot route around the service denial' );
    $next = vc_product_data( new ServiceFixture( 5 ) );
    check( 5 === $calls && 5 === $next['price_pair']['variation_id'], 'A new SKU cannot inherit preceding DTO' );
}
echo $passed . ' product data service checks passed (' . ( $legacy ? 'legacy' : 'modern' ) . ")\n";
