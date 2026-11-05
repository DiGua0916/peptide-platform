<?php
/** Isolated same-SKU card contract; native session preparation is safely stubbed. */
namespace PeptideCore\Modules {
    final class Products {
        public static function public_catalog_product( $product ): bool {
            return $product instanceof \WC_Product && 'product' === \get_post_type( $product->get_id() )
                && 'publish' === $product->get_status() && $product->is_publicly_viewable()
                && '' === $product->get_post_password() && $product->is_visible();
        }
    }
    final class Showcase_Quick_View {
        public static int $templates = 0;
        public static function supports( $product ): bool { return $product instanceof \WC_Product && $product->is_type( array( 'simple', 'variable' ) ); }
        public static function catalog_assets(): void {}
        public static function template( $product ): string { ++self::$templates; return '<template data-native-form="kept"><div></div></template>'; }
    }
}
namespace Automattic\WooCommerce\StoreApi\Utilities {
    final class QuantityLimits {
        public static int $calls = 0;
        public function get_add_to_cart_limits( $sku ): array {
            ++self::$calls;
            if ( $GLOBALS['quantity_throws'] ) { throw new \RuntimeException( 'Unavailable native limits.' ); }
            if ( $sku->manage && ! $sku->backorders && null !== $sku->stockQuantity && ! \WC()->session ) { throw new \RuntimeException( 'Native held-stock limits require their session first.' ); }
            return $GLOBALS['limits'];
        }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    class WC_Product {
        public string $type = 'simple', $status = 'publish', $password = '', $price = '55.00', $regular = '55.00', $name = 'Product';
        public bool $public = true, $visible = true, $purchasable = true, $stock = true, $active = true, $manage = false, $backorders = false, $sale = false;
        public int $parent = 0, $image = 0;
        public ?int $stockQuantity = 10;
        public array $attributes = array();
        public function __construct( public int $id ) {}
        public function get_id() { return $this->id; }
        public function is_type( $type ) { return is_array( $type ) ? in_array( $this->type, $type, true ) : $this->type === $type; }
        public function get_status() { return $this->status; }
        public function get_post_password() { return $this->password; }
        public function is_publicly_viewable() { return $this->public; }
        public function is_visible() { return $this->visible; }
        public function get_parent_id() { return $this->parent; }
        public function variation_is_visible() { return $this->visible; }
        public function variation_is_active() { return $this->active; }
        public function is_purchasable() { return $this->purchasable; }
        public function is_in_stock() { return $this->stock; }
        public function get_variation_attributes() { return $this->attributes; }
        public function get_price() { return $this->price; }
        public function get_regular_price() { return $this->regular; }
        public function is_on_sale() { return $this->sale; }
        public function get_price_html() { return '<span>Native price</span>'; }
        public function get_image_id( $context = 'view' ) { $GLOBALS['image_contexts'][] = array( $this->id, $context ); return 'edit' === $context || $this->image ? $this->image : ( $GLOBALS['products'][ $this->parent ]->image ?? 0 ); }
        public function get_name() { return $this->name; }
        public function managing_stock() { return $this->manage; }
        public function backorders_allowed() { return $this->backorders; }
        public function get_stock_quantity() { return $this->stockQuantity; }
        public function get_sku() { return 'SKU-' . $this->id; }
        public function get_rating_count() { return 0; }
    }
    class WC_Product_Variation extends WC_Product { public string $type = 'variation'; }
    class WP_REST_Request implements \ArrayAccess {
        public function __construct( private array $params ) {}
        public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
        public function offsetGet( $offset ): mixed { return $this->params[ $offset ] ?? null; }
        public function offsetSet( $offset, $value ): void { $this->params[ $offset ] = $value; }
        public function offsetUnset( $offset ): void { unset( $this->params[ $offset ] ); }
        public function has_param( $name ) { return array_key_exists( $name, $this->params ); }
    }
    class WP_REST_Response {
        public array $headers = array();
        public function __construct( public array $data ) {}
        public function header( $name, $value ) { $this->headers[ $name ] = $value; }
    }
    class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
    class WP_REST_Server { const READABLE = 'GET'; }
    class WP_HTML_Tag_Processor {
        public function __construct( private string $html ) {}
        public function next_tag( ...$unused ) { return false; }
        public function get_updated_html() { return $this->html; }
    }
    class WP_Post {}
    final class NativeSessionPreparation {
        public ?object $session = null;
        public int $initializations = 0, $cartCalls = 0;
        public bool $fail = false, $noSession = false;
        public function initialize_session(): void {
            ++$this->initializations;
            if ( $this->fail ) { throw new \RuntimeException( 'Native session unavailable.' ); }
            if ( ! $this->noSession ) { $this->session = (object) array(); }
        }
        public function initialize_cart(): void { ++$this->cartCalls; throw new \RuntimeException( 'Card lookup must not initialize a cart.' ); }
        public function get_cart(): void { ++$this->cartCalls; throw new \RuntimeException( 'Card lookup must not load cart contents.' ); }
        public function __get( $name ) { ++$this->cartCalls; throw new \RuntimeException( 'Card lookup must not access cart state.' ); }
    }
    $actions = $routes = $products = $first = $image_contexts = array();
    $limits = array( 'minimum' => 1, 'maximum' => 20, 'multiple_of' => 1 ); $quantity_throws = false;
    $woocommerce = new NativeSessionPreparation(); $passed = 0;
    function add_action( $hook, $callback, ...$unused ) { $GLOBALS['actions'][ $hook ][] = $callback; }
    function add_filter( ...$unused ) {}
    function remove_filter( ...$unused ) {}
    function register_rest_route( $namespace, $path, $definition ) { $GLOBALS['routes'][] = array( $namespace, $path, $definition ); }
    function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
    function vc_card_display_product( $parent ) { return $GLOBALS['first'][ $parent->get_id() ] ?? $parent; }
    function get_post_type( $id ) { $product = wc_get_product( $id ); return $product && $product->is_type( 'variation' ) ? 'product_variation' : 'product'; }
    function get_post_field( $field, $id, $context ) { return $GLOBALS['raw_password'][ $id ] ?? ( wc_get_product( $id )->password ?? '' ); }
    function wc_variation_attribute_name( $name ) { return 'attribute_' . $name; }
    function wc_get_formatted_variation( $sku, ...$flags ) { $GLOBALS['formatted_args'] = array( $sku->id, $flags ); return ( $flags[1] ? 'Specification: ' : '' ) . implode( ' / ', $sku->attributes ); }
    function wc_get_price_to_display( $sku, $args = array() ) { $GLOBALS['priced_id'] = $sku->id; return (float) ( $args['price'] ?? $sku->price ) * $GLOBALS['display_multiplier']; }
    function wc_get_price_decimals() { return $GLOBALS['price_decimals']; }
    function get_woocommerce_currency() { return $GLOBALS['currency']; }
    function wc_format_decimal( $price, $decimals ) { return number_format( (float) $price, $decimals, '.', '' ); }
    function wc_price( $price ) { return '<span>' . ( 'JPY' === $GLOBALS['currency'] ? '¥' : '$' ) . number_format( (float) $price, $GLOBALS['price_decimals'] ) . '</span>'; }
    function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
    function wp_get_attachment_image_src( $id, $size ) { return $id ? array( 'https://store.example/images/' . $id . '.png', 600, 600 ) : false; }
    function wp_get_attachment_image_srcset( ...$unused ) { return ''; }
    function WC() { return $GLOBALS['woocommerce']; }
    function wc_load_cart() { ++$GLOBALS['woocommerce']->cartCalls; throw new \RuntimeException( 'Card lookup must not load a cart.' ); }
    function wp_enqueue_script( ...$unused ) {}
    function wp_enqueue_style( ...$unused ) {}
    function wp_localize_script( ...$unused ) {}
    function get_template_directory_uri() { return 'https://store.example/theme'; }
    function get_template_directory() { return dirname( __DIR__ ); }
    function rest_url( $route ) { return 'https://store.example/wp-json/' . $route; }
    function is_user_logged_in() { return false; }
    function wc_get_cart_url() { return 'https://store.example/cart/'; }
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
    function wp_kses_post( $value ) { return $value; }
    function get_post( $id ) { return null; }
    require dirname( __DIR__ ) . '/inc/home-quick-view.php';
    function check( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$GLOBALS['passed']; }
    function response( array $change = array() ) { return vc_home_quick_view_response( new WP_REST_Request( array_merge( array( 'id' => 10, 'card' => true ), $change ) ) ); }
    function reset_fixture(): void {
        $parent = new WC_Product( 10 ); $parent->type = 'variable'; $parent->price = '1'; $parent->image = 900; $parent->attributes = array( 'pack' => array( '20mg', '10mg' ) );
        $first = new WC_Product_Variation( 12 ); $first->parent = 10; $first->price = '55'; $first->image = 120; $first->attributes = array( 'attribute_pack' => '20mg' );
        $second = new WC_Product_Variation( 13 ); $second->parent = 10; $second->price = '40'; $second->image = 130; $second->attributes = array( 'attribute_pack' => '10mg' );
        $GLOBALS['products'] = array( 10 => $parent, 12 => $first, 13 => $second ); $GLOBALS['first'] = array( 10 => $first );
        $GLOBALS['limits'] = array( 'minimum' => 1, 'maximum' => 20, 'multiple_of' => 1 ); $GLOBALS['quantity_throws'] = false;
        $GLOBALS['raw_password'] = $GLOBALS['image_contexts'] = array(); $GLOBALS['woocommerce'] = new NativeSessionPreparation();
        $GLOBALS['price_decimals'] = 2; $GLOBALS['currency'] = 'USD'; $GLOBALS['display_multiplier'] = 1;
        \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls = 0;
    }
    reset_fixture();
    foreach ( $actions['rest_api_init'] as $callback ) { $callback(); }
    check( 1 === count( $routes ) && 'vialcove/v1' === $routes[0][0] && '/home-product/(?P<id>[1-9][0-9]*)' === $routes[0][1], 'The existing GET route is reused.' );
    $args = $routes[0][2]['args'];
    check( 'boolean' === $args['card']['type'] && false === $args['card']['default'] && 1 === $args['variation']['minimum'], 'The optional card mode and positive variation ID are declared in REST validation.' );
    $products[12]->manage = true; $limits['minimum'] = 2;
    check( true === vc_home_quick_view_card_add_candidate( $products[10], $products[12] ) && 0 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls && 0 === $woocommerce->initializations && null === $woocommerce->session && 0 === $woocommerce->cartCalls, 'SSR candidate exposes only an attempt without native quantity, session, held-stock or cart reads.' );
    check( false === response()->data['card']['canAddOne'] && 1 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls && 1 === $woocommerce->initializations, 'A statically eligible attempt still fails the fresh native quantity-one check.' );
    reset_fixture(); $products[12]->attributes['attribute_pack'] = '';
    check( false === vc_home_quick_view_card_add_candidate( $products[10], $products[12] ) && 0 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls, 'SSR does not offer an ambiguous any-attribute SKU.' );
    reset_fixture(); $products[12]->stock = false;
    check( false === vc_home_quick_view_card_add_candidate( $products[10], $products[12] ), 'SSR keeps native static stock eligibility.' );
    reset_fixture(); $products[10]->purchasable = false;
    check( false === vc_home_quick_view_card_add_candidate( $products[10], $products[12] ), 'SSR keeps parent purchase restrictions.' );
    reset_fixture();
    $result = response(); $card = $result->data['card'];
    check( array( 'id', 'card' ) === array_keys( $result->data ) && 0 === \PeptideCore\Modules\Showcase_Quick_View::$templates, 'Card mode never renders or initializes the native form.' );
    check( array( 'productId', 'purchaseId', 'variationId', 'variationLabel', 'price', 'regularPrice', 'image', 'canAddOne' ) === array_keys( $card ), 'The lightweight card has only its explicit public contract fields.' );
    check( 10 === $card['productId'] && 12 === $card['purchaseId'] && 12 === $card['variationId'] && '20mg' === $card['variationLabel'], 'The merchant first configured variation is shown, not the cheaper second choice.' );
    check( '$55.00' === $card['price']['text'] && 12 === $priced_id && str_ends_with( $card['image']['url'], '/120.png' ), 'Price, image and label come from that same SKU.' );
    check( '5500' === $card['price']['minor'] && 'USD' === $card['price']['currency_code'] && 2 === $card['price']['currency_minor_unit'] && null === $card['regularPrice'], 'Current exact-SKU money metadata is native; a nonsale has no comparison.' );
    check( array( 12, array( true, false, false ) ) === $formatted_args, 'Native variation label formatting preserves all values without legacy attribute-name prefixes.' );
    check( true === $card['canAddOne'] && 1 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls, 'A complete available SKU calls the native quantity-limit API once.' );
    check( 'private, no-store' === $result->headers['Cache-Control'], 'SKU display responses are never publicly cached.' );
    $card = response( array( 'variation' => 13 ) )->data['card'];
    check( 13 === $card['purchaseId'] && '$40.00' === $card['price']['text'] && str_ends_with( $card['image']['url'], '/130.png' ), 'An explicit available variation keeps its own exact price and image.' );
    $products[12]->regular = '78'; $products[12]->sale = true; $products[10]->regular = '999';
    $card = response()->data['card'];
    check( '7800' === $card['regularPrice']['minor'] && '$78.00' === $card['regularPrice']['text'] && '5500' === $card['price']['minor'], 'Comparison is the same configured SKU, not a parent regular price.' );
    $card = response( array( 'variation' => 13 ) )->data['card'];
    check( null === $card['regularPrice'] && '4000' === $card['price']['minor'], 'Another specification never inherits the first specification sale.' );
    $display_multiplier = 1.1; $card = response()->data['card'];
    check( '6050' === $card['price']['minor'] && '8580' === $card['regularPrice']['minor'], 'Current and comparison use the same native tax/display conversion.' );
    $display_multiplier = 1; $currency = 'JPY'; $price_decimals = 0; $card = response()->data['card'];
    check( '55' === $card['price']['minor'] && '78' === $card['regularPrice']['minor'] && 0 === $card['price']['currency_minor_unit'], 'Zero-decimal currency does not assume cents.' );
    $currency = 'USD'; $price_decimals = 8; $products[12]->price = '0.75000001'; $products[12]->regular = '1.00000001'; $card = response()->data['card'];
    check( '75000001' === $card['price']['minor'] && '100000001' === $card['regularPrice']['minor'], 'High-precision display values retain the native fractional precision.' );
    foreach ( array( '-1', 'not-a-price', '0.50', '999999999999999' ) as $invalid_regular ) {
        $products[12]->regular = $invalid_regular;
        check( null === response()->data['card']['regularPrice'], 'Invalid, non-discounted or unsafe regular prices never become sale claims.' );
    }
    reset_fixture();
    $products[13]->parent = 999;
    check( response( array( 'variation' => 13 ) ) instanceof WP_Error, 'An explicit foreign variation cannot fall back to another SKU.' );
    reset_fixture();
    foreach ( array( 'status' => 'draft', 'password' => 'protected', 'active' => false, 'visible' => false ) as $property => $invalid ) {
        $prior = $products[13]->$property; $products[13]->$property = $invalid;
        check( response( array( 'variation' => 13 ) ) instanceof WP_Error, 'An explicit invalid variation is rejected: ' . $property );
        $products[13]->$property = $prior;
    }
    $raw_password[13] = 'protected';
    check( response( array( 'variation' => 13 ) ) instanceof WP_Error, 'A raw protected variation is rejected even if a getter hides its password.' );
    reset_fixture(); $first[10] = $products[10];
    check( response() instanceof WP_Error, 'A variable parent with no first concrete SKU never becomes a lowest-price card.' );
    reset_fixture(); $products[12]->image = 0;
    check( '' === response()->data['card']['image']['url'] && in_array( array( 12, 'edit' ), $image_contexts, true ), 'A missing variation image stays empty rather than inheriting another dosage from the parent.' );
    reset_fixture(); $products[12]->attributes['attribute_pack'] = '';
    check( false === response()->data['card']['canAddOne'] && 0 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls, 'An any-attribute variation cannot be one-click added.' );
    reset_fixture(); $products[12]->stock = false;
    check( false === response()->data['card']['canAddOne'], 'Out-of-stock display data cannot enable add-one.' );
    reset_fixture(); $products[10]->purchasable = false;
    check( false === response()->data['card']['canAddOne'], 'Parent purchase restrictions remain authoritative.' );
    reset_fixture();
    foreach ( array(
        array( 'minimum' => 2, 'maximum' => 20, 'multiple_of' => 1 ),
        array( 'minimum' => 1, 'maximum' => 0, 'multiple_of' => 1 ),
        array( 'minimum' => 1, 'maximum' => 20, 'multiple_of' => 2 ),
        array( 'minimum' => 1, 'maximum' => 20, 'multiple_of' => 0 ),
        array( 'minimum' => 1, 'maximum' => INF, 'multiple_of' => 1 ),
        array( 'minimum' => 1, 'maximum' => 20 ),
    ) as $invalid_limits ) {
        $limits = $invalid_limits;
        check( false === response()->data['card']['canAddOne'], 'A native limit that does not permit quantity one is refused.' );
    }
    reset_fixture(); $limits['multiple_of'] = 0.5;
    check( true === response()->data['card']['canAddOne'], 'Native fractional multiples that allow quantity one remain supported.' );
    $quantity_throws = true;
    check( false === response()->data['card']['canAddOne'], 'Unavailable native limits fail closed.' );
    reset_fixture(); $products[12]->manage = true;
    check( true === response()->data['card']['canAddOne'] && 1 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls && 1 === $woocommerce->initializations && is_object( $woocommerce->session ), 'Held-stock lookup prepares the official Woo session before its native quantity limits.' );
    $native_session = $woocommerce->session;
    check( true === response()->data['card']['canAddOne'] && 1 === $woocommerce->initializations && $native_session === $woocommerce->session, 'An already initialized native session is reused without duplicate initialization.' );
    check( 0 === $woocommerce->cartCalls, 'Native session preparation never initializes, loads or reads a cart.' );
    reset_fixture(); $products[12]->manage = true; $woocommerce->fail = true;
    check( false === response()->data['card']['canAddOne'] && 1 === $woocommerce->initializations && 0 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls, 'A native session initialization exception fails closed before reading limits.' );
    reset_fixture(); $products[12]->manage = true; $woocommerce->noSession = true;
    check( false === response()->data['card']['canAddOne'] && 1 === $woocommerce->initializations && 0 === \Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits::$calls, 'Unsuccessful native session preparation does not enable add-one.' );
    reset_fixture(); $products[12]->manage = true; $products[12]->backorders = true;
    check( true === response()->data['card']['canAddOne'] && null === $woocommerce->session && 0 === $woocommerce->initializations, 'Backorder limits need no held-stock session initialization.' );
    reset_fixture(); $products[12]->price = '';
    check( response() instanceof WP_Error, 'An unpriced SKU never receives a manufactured display price.' );
    reset_fixture(); $products[12]->price = '-1';
    check( response() instanceof WP_Error, 'Invalid negative display prices are refused.' );
    reset_fixture(); $products[10]->type = 'simple'; unset( $first[10] );
    $card = response()->data['card'];
    check( 10 === $card['purchaseId'] && 0 === $card['variationId'] && '' === $card['variationLabel'], 'A simple product is not assigned a fabricated variation.' );
    check( response( array( 'variation' => 13 ) ) instanceof WP_Error, 'A simple product never accepts a variation argument.' );
    reset_fixture(); $products[10]->visible = false;
    check( response() instanceof WP_Error, 'Nonpublic parent products remain unavailable.' );
    reset_fixture(); $result = response( array( 'card' => false ) );
    check( array( 'id', 'html' ) === array_keys( $result->data ) && str_contains( $result->data['html'], 'data-native-form="kept"' ), 'The existing full native HTML response remains unchanged when card mode is off.' );
    echo $passed . ' home product card guards passed' . PHP_EOL;
}
