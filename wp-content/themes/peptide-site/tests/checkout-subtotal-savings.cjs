'use strict';
const assert = require('node:assert/strict');
const { compute, money } = require('../assets/checkout-subtotal-savings.js');

// Store API payload fixture: actual variation lines are already aggregated by
// the server in cart tax-display context. These tests prevent using coupon or
// order totals as product-price savings and prevent stale/mixed-currency pairs.
function fixture() {
    return {
        items: [{ id: 123, quantity: 5 }],
        totals: { currency_code: 'USD', currency_minor_unit: 2, total_items: '27500', total_items_tax: '0', total_discount: '2500', total_price: '25000' },
        extensions: { peptide_core: { sale_savings: {
            savings_available: true,
            savings_basis: 'native-cart-display-excluding-tax-and-shipping',
            tax_display: 'excl', currency_code: 'USD', currency_minor_unit: 2,
            display_regular_subtotal: '39000', display_sale_subtotal: '27500',
            sale_savings: '11500', coupon_savings: '2500', promotion_savings: '1000', total_savings: '15000', compare_is_demo: false
        } } }
    };
}
const pricing = cart => cart.extensions.peptide_core.sale_savings;
let checks = 0;
function test(name, run) { run(); checks++; process.stdout.write('PASS ' + name + '\n'); }

test('TR20 five boxes: 390 regular / 275 native subtotal / Save 115', () => {
    assert.deepEqual(compute(fixture()), { regular: 39000, sale: 27500, savings: 11500, currency: 'USD', decimals: 2 });
});
test('coupon and promotion changes do not become subtotal savings', () => {
    const cart = fixture();
    pricing(cart).coupon_savings = '50000'; pricing(cart).promotion_savings = '20000'; pricing(cart).total_savings = '81500';
    cart.totals.total_price = '0'; cart.totals.total_discount = '50000';
    assert.equal(compute(cart).savings, 11500);
});
test('server mixed exact-SKU aggregate, not product-parent price or item count', () => {
    const cart = fixture(); cart.items.push({ id: 124, quantity: 2 });
    pricing(cart).display_regular_subtotal = '52400'; pricing(cart).display_sale_subtotal = '35700'; cart.totals.total_items = '35700';
    assert.equal(compute(cart).savings, 16700);
});
test('including-tax comparison must match native item subtotal plus item tax', () => {
    const cart = fixture(); Object.assign(pricing(cart), { tax_display: 'incl', savings_basis: 'native-cart-display-including-tax-excluding-shipping', display_regular_subtotal: '42120', display_sale_subtotal: '29700' });
    cart.totals.total_items_tax = '2200'; assert.equal(compute(cart).savings, 12420);
});
test('excluding-tax comparison does not add native item tax', () => {
    const cart = fixture(); cart.totals.total_items_tax = '2200'; assert.equal(compute(cart).sale, 27500);
});
test('tax basis mismatch is unavailable', () => {
    const cart = fixture(); pricing(cart).tax_display = 'incl'; assert.equal(compute(cart), null);
});
test('stale extension sale versus current native subtotal hides comparison', () => {
    const cart = fixture(); cart.totals.total_items = '33000'; assert.equal(compute(cart), null);
});
test('currency and precision mismatches are unavailable', () => {
    for (const patch of [{ currency_code: 'EUR' }, { currency_minor_unit: 3 }]) {
        const cart = fixture(); Object.assign(cart.totals, patch); assert.equal(compute(cart), null);
    }
});
test('unknown availability, unknown tax mode and incomplete display DTO are unavailable', () => {
    for (const patch of [{ savings_available: false }, { savings_available: undefined }, { tax_display: 'unknown' }, { display_regular_subtotal: undefined }, { display_sale_subtotal: undefined }]) {
        const cart = fixture(); Object.assign(pricing(cart), patch); assert.equal(compute(cart), null);
    }
});
test('no sale, negative comparison and demonstration comparisons do not claim savings', () => {
    for (const patch of [{ display_regular_subtotal: '27500' }, { display_regular_subtotal: '27000' }, { compare_is_demo: true }]) {
        const cart = fixture(); Object.assign(pricing(cart), patch); assert.equal(compute(cart), null);
    }
});
test('raw price-pair fields are never a fallback for missing display fields', () => {
    const cart = fixture(); delete pricing(cart).display_regular_subtotal;
    pricing(cart).regular_subtotal = '39000'; pricing(cart).sale_subtotal = '27500'; assert.equal(compute(cart), null);
});
test('empty cart and absent payload are unavailable', () => {
    const cart = fixture(); cart.items = []; assert.equal(compute(cart), null);
    assert.equal(compute({}), null); assert.equal(compute(null), null);
});
test('untrusted or unsafe minor-unit values never reach display', () => {
    for (const value of ['-1', '27500.0', ' 27500', '', '2.75e4', '9007199254740992', NaN, null, false]) {
        const cart = fixture(); pricing(cart).display_sale_subtotal = value; assert.equal(compute(cart), null);
    }
});
test('including-tax safe integers cannot overflow during comparison', () => {
    const cart = fixture(); Object.assign(pricing(cart), { tax_display: 'incl', savings_basis: 'native-cart-display-including-tax-excluding-shipping' });
    cart.totals.total_items = String(Number.MAX_SAFE_INTEGER); cart.totals.total_items_tax = '1'; assert.equal(compute(cart), null);
});
test('zero and three-decimal currencies preserve backend minor-unit precision', () => {
    for (const precision of [0, 3]) {
        const cart = fixture(); pricing(cart).currency_minor_unit = precision; cart.totals.currency_minor_unit = precision;
        assert.equal(compute(cart).decimals, precision);
    }
    assert.equal(money(115, 'JPY', 0, 'en-US'), '¥115');
    assert.equal(money(115001, 'USD', 3, 'en-US'), '$115.001');
});
test('localized display retains exact pennies, including the largest safe integer', () => {
    assert.equal(money(11500, 'USD', 2, 'en-US'), '$115.00');
    assert.equal(money(39000, 'USD', 2, 'es-ES').replace(/\s/g, ''), '390,00US$');
    assert.equal(money(Number.MAX_SAFE_INTEGER, 'USD', 2, 'en-US'), '$90,071,992,547,409.91');
    assert.equal(money(-1, 'USD', 2, 'en-US'), null);
});
process.stdout.write(checks + ' subtotal-savings checks passed.\n');
