<?php
/** Real counting function with native raw-cache boundary peers; no WP or DB. */
namespace PeptideCore\Modules { final class Products { public static function public_catalog_product( $p ) { return $p->public; } } }
namespace {
    define( 'ABSPATH', __DIR__ );
    $reads = $primes = $count_reads = $passed = 0; $children = array(); $warm = array();
    class CountFixture {
        public bool $public = true; public int $child_reads = 0;
        public function __construct( public int $id, public string $type = 'simple', public string $price = '9', public array $ids = array() ) {}
        public function get_id() { return $this->id; }
        public function is_type( $type ) { return $type === $this->type; }
        public function get_price() { return $this->price; }
        public function get_visible_children() { ++$this->child_reads; return $this->ids; }
    }
    class WC_Product_Variation extends CountFixture {
        public bool $visible = true; public int $parent = 1;
        public function get_parent_id() { return $this->parent; }
        public function variation_is_visible() { return $this->visible; }
    }
    function add_action( ...$args ) {} function add_filter( ...$args ) {}
    function wc_get_products( $args ) { ++$GLOBALS['count_reads']; return $GLOBALS['catalogue']; }
    function wc_get_product( $id ) {
        ++$GLOBALS['reads']; if ( empty( $GLOBALS['warm'][$id] ) ) { throw new \RuntimeException( 'Child hydrated before native raw priming' ); }
        return $GLOBALS['children'][$id];
    }
    function _prime_post_caches( $ids, $terms, $meta ) {
        ++$GLOBALS['primes']; if ( true !== $terms || true !== $meta || $ids !== array( 11,12,13,14,15 ) ) { throw new \RuntimeException( 'Incomplete raw priming' ); }
        foreach ( $ids as $id ) { $GLOBALS['warm'][$id] = true; }
    }
    function check( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$GLOBALS['passed']; }
    $variable = new CountFixture( 1, 'variable', '8', array( 11,12,13,14,15 ) );
    $hidden = new CountFixture( 9, 'variable', '8', array( 90 ) ); $hidden->public = false;
    $catalogue = array( $variable, new CountFixture( 2 ), new CountFixture( 3, 'simple', '' ), $hidden );
    foreach ( array( 11,12,14,15 ) as $id ) { $children[$id] = new WC_Product_Variation( $id, 'variation' ); }
    $children[12]->parent = 9; $children[13] = new CountFixture( 13 ); $children[14]->visible = false; $children[15]->price = '';
    require dirname( __DIR__ ) . '/inc/demo-home-modules.php';
    check( vc_demo_home_catalog_counts() === array( 'products' => 2, 'specifications' => 2 ), 'Native parent, type, visibility and nonempty-price rules retained' );
    check( 1 === $primes && 5 === $reads, 'One batch raw prime precedes every native child read' );
    check( 1 === $variable->child_reads && 0 === $hidden->child_reads, 'Each eligible native child list resolved once' );
    check( vc_demo_home_catalog_counts() === array( 'products' => 2, 'specifications' => 2 ), 'Repeated count stable' );
    check( 1 === $count_reads && 1 === $primes && 5 === $reads, 'Existing request-local count memo retained' );
    echo $passed . " home count priming checks passed\n";
}
