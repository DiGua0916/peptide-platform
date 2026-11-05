<?php
/**
 * Native Woo monetary-method regression test; no WordPress bootstrap or database.
 * Run: php -n tests/checkout-savings-native.php [path-to-installed-woocommerce]
 *
 * Loads the installed WC_Product/WC_Cart/WC_Tax classes and price-display/formatting
 * functions unchanged. Objects bypass constructors and receive memory-only known
 * data, totals and fee objects. Tax-rate lookups hit a deterministic memory cache;
 * WordPress option/filter/cache/locale boundaries are isolated fixtures. This does
 * NOT calculate a live cart, apply a real coupon, create data or submit checkout.
 */
declare(strict_types=1);

$native = $argv[1] ?? getenv('WC_NATIVE_ROOT') ?: 'D:/Codex/Sites/vialcovepeptidescom/app/public/wp-content/plugins/woocommerce';
$native = rtrim(str_replace('\\', '/', $native), '/') . '/';
if (!is_readable($native . 'includes/class-wc-cart.php')) { fwrite(STDERR, "Installed WooCommerce source not found. Pass its path.\n"); exit(2); }
define('ABSPATH', __DIR__ . '/isolated-no-wordpress/');
define('WC_ABSPATH', $native);
define('WC_ROUNDING_PRECISION', 6);
define('WC_TAX_ROUNDING_MODE', PHP_ROUND_HALF_UP);
$options = array(); $filters = array(); $rates = array(); $dbAccess = 0; $displayCalls = array();
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option(...$args) { throw new RuntimeException('Option writes forbidden'); }
function add_option(...$args) { throw new RuntimeException('Option writes forbidden'); }
function delete_option(...$args) { throw new RuntimeException('Option writes forbidden'); }
function get_post_meta(...$args) { throw new RuntimeException('Post/meta reads forbidden'); }
function apply_filters($name, $value, ...$args) { global $filters; foreach ($filters[$name] ?? array() as $callback) { $value = $callback($value, ...$args); } return $value; }
function add_filter(...$args) {}
function add_action(...$args) {}
function do_action(...$args) {}
function did_action($name) { return 1; } // Never initialize/load a live Woo session.
function absint($value) { return abs((int)$value); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array)$args); }
function sanitize_text_field($value) { return (string)$value; }
function sanitize_title($value) { return strtolower((string)$value); }
function wp_unslash($value) { return $value; }
function wp_list_pluck($list, $field) { return array_map(static fn($item) => is_object($item) ? $item->$field : $item[$field], $list); }
function wp_cache_get($key, $group = '') { global $rates; return 'taxes' === $group ? $rates : false; }
function wp_cache_set(...$args) { throw new RuntimeException('Unexpected tax cache miss/write'); }
function wc_get_rounding_precision() { return max(4, wc_get_price_decimals() + 2); }
function wc_get_tax_rounding_mode() { return PHP_ROUND_HALF_UP; }
function get_woocommerce_currency() { return get_option('woocommerce_currency', 'USD'); }
function __($text, $domain = '') { return $text; }
function WC() { return $GLOBALS['nativeContext']; }
class WC_Cache_Helper { public static function get_cache_prefix($group) { return 'isolated-'; } }
class ForbiddenDatabase { public function __call($method, $args) { $GLOBALS['dbAccess']++; throw new RuntimeException('Database forbidden: ' . $method); } }
$GLOBALS['wpdb'] = new ForbiddenDatabase();

spl_autoload_register(static function ($class) use ($native) {
    $prefix = 'Automattic\\WooCommerce\\';
    if (str_starts_with($class, $prefix)) {
        $file = $native . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) { require_once $file; }
    }
});
require_once $native . 'includes/wc-formatting-functions.php';
require_once $native . 'includes/wc-conditional-functions.php';
require_once $native . 'includes/wc-product-functions.php';
require_once $native . 'includes/abstracts/abstract-wc-data.php';
require_once $native . 'includes/abstracts/abstract-wc-product.php';
require_once $native . 'includes/class-wc-product-simple.php';
require_once $native . 'includes/class-wc-tax.php';
require_once $native . 'includes/class-wc-cart.php';
require_once dirname(__DIR__) . '/includes/class-pricing.php';

function prop(object $object, string $name, $value): void { $property = new ReflectionProperty($object, $name); $property->setValue($object, $value); }
function product(string $regular, string $sale, bool $taxable = true): WC_Product {
    $p = (new ReflectionClass(WC_Product_Simple::class))->newInstanceWithoutConstructor();
    $data = (new ReflectionProperty($p, 'data'))->getValue($p);
    $data = array_replace($data, array('price' => $sale, 'sale_price' => $sale, 'regular_price' => $regular, 'tax_status' => $taxable ? 'taxable' : 'none'));
    prop($p, 'data', $data); prop($p, 'meta_data', array()); prop($p, 'object_read', true);
    return $p;
}
function cart(array $items, float $coupon = 0, float $couponTax = 0, array $feeRows = array()): WC_Cart {
    $c = (new ReflectionClass(WC_Cart::class))->newInstanceWithoutConstructor();
    prop($c, 'cart_contents', $items); prop($c, 'totals', array('discount_total' => $coupon, 'discount_tax' => $couponTax, 'shipping_total' => 42, 'shipping_tax' => 8.4, 'total' => 777.77));
    $fees = new WC_Cart_Fees();
    $objects = array();
    foreach ($feeRows as $index => $row) { $objects[$index] = (object)array_replace(array('id' => 'pc-promo-37', 'amount' => -99, 'total' => -5, 'taxable' => false, 'tax' => 0), $row); }
    prop($fees, 'fees', $objects); prop($c, 'fees_api', $fees);
    return $c;
}
function reset_context(bool $pricesIncludeTax, string $display, int $decimals = 2, bool $vatExempt = false): void {
    $GLOBALS['options'] = array('woocommerce_prices_include_tax' => $pricesIncludeTax ? 'yes' : 'no', 'woocommerce_tax_display_cart' => $display, 'woocommerce_tax_display_shop' => 'incl' === $display ? 'excl' : 'incl', 'woocommerce_price_num_decimals' => $decimals, 'woocommerce_price_decimal_sep' => '.', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_tax_round_at_subtotal' => 'no', 'woocommerce_currency' => 0 === $decimals ? 'JPY' : 'USD');
    $GLOBALS['rates'] = array(1 => array('rate' => '20', 'compound' => 'no', 'shipping' => 'yes', 'label' => 'Fixture VAT 20%'));
    $GLOBALS['filters'] = array();
    $GLOBALS['nativeContext'] = (object)array('customer' => new class($vatExempt) {
        public function __construct(private bool $exempt) {}
        public function get_is_vat_exempt() { return $this->exempt; }
        public function get_taxable_address() { return array('US', 'CA', '90001', 'Fixture'); }
    }, 'countries' => new class {
        public function get_base_country() { return 'US'; } public function get_base_state() { return 'CA'; }
        public function get_base_postcode() { return '90001'; } public function get_base_city() { return 'Fixture'; }
    });
    WC_Tax::init();
}

$tests = array(); $snapshots = array();
function check(string $name, bool $passed, array $evidence = array()): void { $GLOBALS['tests'][] = array('name' => $name, 'passed' => $passed, 'evidence' => $evidence); }
function business_snapshot(WC_Cart $cart): string {
    $items = array_map(static function ($item) {
        $product = $item['data'] ?? null;
        if ($product instanceof WC_Product) {
            $item['data'] = array_map(static fn($key) => (new ReflectionProperty($product, $key))->getValue($product), array('id', 'data', 'changes', 'meta_data'));
        }
        return $item;
    }, $cart->cart_contents);
    $fees = array_map(static fn($fee) => (array)$fee, $cart->get_fees());
    ksort($fees); // Native get_fees() legitimately sorts presentation; values stay authoritative.
    return hash('sha256', serialize(array($items, (new ReflectionProperty($cart, 'totals'))->getValue($cart), $fees, $GLOBALS['options'])));
}
function summary(WC_Cart $cart): array {
    $before = business_snapshot($cart);
    $result = PeptideCore\Pricing::cart_summary($cart);
    $after = business_snapshot($cart);
    check('Summary leaves native in-memory products/totals/fees/options unchanged', $before === $after);
    return $result;
}

reset_context(false, 'excl');
$base = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 2)), 10, 2, array(array('total' => -5))));
check('Ex-tax native cart display plus actual coupon and posted promotion fee', $base['display_regular_subtotal'] === '24000' && $base['display_sale_subtotal'] === '19200' && $base['coupon_savings'] === '1000' && $base['promotion_savings'] === '500' && $base['total_savings'] === '6300', $base);
check('Existing raw fields remain backward compatible', $base['regular_subtotal'] === '24000' && $base['sale_subtotal'] === '19200' && $base['sale_savings'] === '4800');
check('Display basis is cart setting, not deliberately opposite shop setting', $base['savings_basis'] === 'native-cart-display-excluding-tax-and-shipping' && $base['savings_available'] === true);

reset_context(false, 'incl');
$incl = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 2)), 10, 2, array(array('total' => -5))));
check('Ex-tax catalogue shown incl VAT uses actual Woo display functions', $incl['display_regular_subtotal'] === '28800' && $incl['display_sale_subtotal'] === '23040' && $incl['coupon_savings'] === '1200' && $incl['total_savings'] === '7460', $incl);
check('Including-tax basis is explicit and shipping excluded', $incl['savings_basis'] === 'native-cart-display-including-tax-excluding-shipping');

reset_context(true, 'excl');
$net = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 2)), 10, 2));
check('Tax-inclusive catalogue removes base VAT for ex-tax cart display', $net['regular_subtotal'] === '24000' && $net['display_regular_subtotal'] === '20000' && $net['display_sale_subtotal'] === '16000' && $net['total_savings'] === '5000', $net);
reset_context(true, 'incl');
$gross = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 2)), 10, 2));
check('Tax-inclusive catalogue stays gross and includes only actual coupon VAT', $gross['display_regular_subtotal'] === '24000' && $gross['display_sale_subtotal'] === '19200' && $gross['total_savings'] === '6000', $gross);
reset_context(true, 'incl', 2, true);
$exempt = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 1)), 10, 0));
check('Native VAT-exempt customer removes tax even with incl cart setting', $exempt['display_regular_subtotal'] === '10000' && $exempt['display_sale_subtotal'] === '8000' && $exempt['total_savings'] === '3000', $exempt);

reset_context(false, 'incl');
$mixed = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 1), array('data' => product('100', '80', false), 'quantity' => 1)), 7.77, 1.23));
check('Mixed taxable/non-taxable products keep native per-line tax calculation', $mixed['display_regular_subtotal'] === '24400' && $mixed['display_sale_subtotal'] === '19520' && $mixed['coupon_savings'] === '900' && $mixed['total_savings'] === '5780', $mixed);

reset_context(false, 'excl');
$fees = summary(cart(array(array('data' => product('10', '10'), 'quantity' => 1)), 0, 0, array(array('id' => 'pc-promo-37', 'amount' => -99, 'total' => -3.45), array('id' => 'other-discount', 'total' => -100), array('id' => 'pc-promo-38', 'total' => 5), array('id' => 'pc-promo-0', 'total' => -40), array('id' => 'pc-promo-39-extra', 'total' => -40))));
check('Only actual negative native pc-promo fee total counts, never fee amount/foreign/positive/malformed fees', $fees['promotion_savings'] === '345' && $fees['total_savings'] === '345', $fees);
$newline = summary(cart(array(array('data' => product('10', '10'), 'quantity' => 1)), 0, 0, array(array('id' => "pc-promo-40\n", 'total' => -9))));
check('Trailing-newline fee ID does not masquerade as exact pc-promo native fee', $newline['promotion_savings'] === '0', $newline);
$actual = summary(cart(array(array('data' => product('100', '100'), 'quantity' => 1)), 3.21, 0));
check('Coupon savings are native actual posted amount, not headline/eligibility estimate', $actual['coupon_savings'] === '321' && $actual['total_savings'] === '321');
$unposted = summary(cart(array(array('data' => product('100', '100'), 'quantity' => 1))));
check('No coupon/fee posted means no speculative eligibility savings', $unposted['coupon_savings'] === '0' && $unposted['promotion_savings'] === '0' && $unposted['total_savings'] === '0');
check('Null cart returns no savings safely', PeptideCore\Pricing::cart_summary(null)['total_savings'] === '0');

reset_context(false, 'excl', 0);
$jpy = summary(cart(array(array('data' => product('120', '96'), 'quantity' => 3)), 7, 0));
check('Zero-decimal currency uses native minor units without hardcoded cents', $jpy['currency_code'] === 'JPY' && $jpy['currency_minor_unit'] === 0 && $jpy['display_regular_subtotal'] === '360' && $jpy['display_sale_subtotal'] === '288' && $jpy['total_savings'] === '79', $jpy);
reset_context(false, 'excl', 3);
$three = summary(cart(array(array('data' => product('12.345', '10.123'), 'quantity' => 2)), .111, 0));
check('Three-decimal currency preserves amount precision', $three['display_regular_subtotal'] === '24690' && $three['display_sale_subtotal'] === '20246' && $three['total_savings'] === '4555', $three);
reset_context(false, 'excl');
$fractional = product('12.345', '10.123');
$fractionalSummary = summary(cart(array(array('data' => $fractional, 'quantity' => 10))));
$nativeRegular = PeptideCore\Pricing::to_cents(wc_get_price_to_display($fractional, array('qty' => 10, 'price' => $fractional->get_regular_price(), 'display_context' => 'cart')));
$nativeSale = PeptideCore\Pricing::to_cents(wc_get_price_to_display($fractional, array('qty' => 10, 'price' => $fractional->get_price(), 'display_context' => 'cart')));
check('High-precision native unit prices round after line quantity, not before multiplication', (string)$nativeRegular === $fractionalSummary['display_regular_subtotal'] && (string)$nativeSale === $fractionalSummary['display_sale_subtotal'] && (string)($nativeRegular - $nativeSale) === $fractionalSummary['total_savings'], array('expected_regular' => (string)$nativeRegular, 'expected_sale' => (string)$nativeSale, 'actual' => $fractionalSummary));
$invalid = summary(cart(array(array('data' => null, 'quantity' => 1))));
check('Unavailable product calculation is explicitly marked unavailable', $invalid['savings_available'] === false);

check('Production native methods loaded from installed Woo unchanged', array_reduce(array(new ReflectionFunction('wc_get_price_to_display'), new ReflectionClass(WC_Product::class), new ReflectionClass(WC_Cart::class), new ReflectionClass(WC_Tax::class)), static fn($ok, $reflection) => $ok && str_starts_with(str_replace('\\', '/', $reflection->getFileName()), WC_ABSPATH), true));
check('No database access occurred', 0 === $dbAccess);
$failed = array_values(array_filter($tests, static fn($test) => !$test['passed']));
echo json_encode(array('passed' => count($tests) - count($failed), 'total' => count($tests), 'failed' => $failed, 'tests' => $tests, 'dbAccess' => $dbAccess, 'nativeWooRoot' => WC_ABSPATH, 'scope' => 'Installed Woo product/cart/tax/display methods; memory-only prepared totals/rates and isolated WP hooks/options. Not live cart calculation, actual coupon application, HTTP or checkout submission.'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($failed ? 1 : 0);
