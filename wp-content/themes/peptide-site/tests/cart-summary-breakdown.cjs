'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { totals } = require('../assets/live-cart-experience.js');
const currency = { currency_code: 'USD', currency_minor_unit: 2 };
const m = value => ({ ...currency, ...value });
function fixture() {
    return { items: [{ key: 'native-line', id: 42, quantity: 2,
        prices: m({ price: '5500', regular_price: '7800' }), totals: m({ line_subtotal: '11000', line_total: '10000' }) }],
        items_count: 2, coupons: [{ code: 'private-auto-code', totals: m({ total_discount: '1000', total_discount_tax: '0' }) }],
        fees: [{ key: 'pc-promo-10980', name: '黑色星期五', totals: m({ total: '-2500', total_tax: '0' }) }],
        needs_shipping: true, has_calculated_shipping: true, shipping_rates: [],
        totals: m({ total_items: '11000', total_discount: '1000', total_fees: '-2500', total_shipping: '4500', total_tax: '0', total_discount_tax: '0', total_price: '12000' }),
        extensions: { peptide_core: {
            sale_savings: m({ regular_subtotal: '15600', sale_subtotal: '11000', sale_savings: '4600', tax_display: 'excl' }),
            promotion: { applied_all: [{ id: 10980, title: '黑色星期五', discount: 99, discount_text: '$99.00' }], discount: 99 },
            activity_listener: { actualDiscount: { minor: '99900', text: '$999.00' } },
        } },
    };
}
const row = (rows, key) => rows.find(value => value.key === key);
test('regular products, markdown, final activity fee and coupon appear once; native payable is unchanged', () => {
    const cart = fixture(), rows = totals(cart);
    assert.equal(row(rows, 'products').minor, '15600'); assert.equal(row(rows, 'product-saving').minor, '-4600');
    assert.equal(row(rows, 'fee-pc-promo-10980').label, 'Black Friday offer'); assert.equal(row(rows, 'fee-pc-promo-10980').minor, '-2500');
    assert.equal(row(rows, 'coupon-0').minor, '-1000'); assert.equal(row(rows, 'saved').minor, '8100');
    assert.equal(row(rows, 'payable').minor, cart.totals.total_price); assert.equal(row(rows, 'payable').label, 'Total payable');
    assert.doesNotMatch(JSON.stringify(rows), /private-auto-code|99900|99\.00|黑色星期五/);
    assert.equal(rows.filter(value => value.total).length, 1);
});
test('each native coupon and fee has its own row without adding their aggregate again', () => {
    const cart = fixture(); cart.coupons = [{ code: 'one-secret', totals: m({ total_discount: '300' }) }, { code: 'two-secret', totals: m({ total_discount: '700' }) }];
    cart.fees.push({ key: 'pc-promo-777', name: 'Trade discount', totals: m({ total: '-500', total_tax: '0' }) }, { key: 'handling', name: 'Handling', totals: m({ total: '200', total_tax: '0' }) });
    cart.totals.total_fees = '-2800';
    const rows = totals(cart); assert.equal(row(rows, 'coupon-0').label, 'Coupon discount 1'); assert.equal(row(rows, 'coupon-1').label, 'Coupon discount 2');
    assert.equal(row(rows, 'fee-handling').minor, '200'); assert.equal(row(rows, 'saved').minor, '8600'); assert.equal(rows.some(value => value.key === 'coupon-total'), false);
});
test('Black Friday translation requires the exact applied native promotion ID, not a similar name', () => {
    const cart = fixture(); cart.extensions.peptide_core.promotion.applied_all = [{ id: 10981, title: '黑色星期五' }];
    assert.equal(row(totals(cart), 'fee-pc-promo-10980').label, '黑色星期五');
    cart.fees[0].key = 'another-fee'; cart.extensions.peptide_core.promotion.applied_all = [{ id: 10980, title: '黑色星期五' }];
    assert.equal(row(totals(cart), 'fee-another-fee').label, '黑色星期五');
});
test('final capped promotion fee is authoritative even when the evaluation promises a larger amount', () => {
    const cart = fixture(); cart.fees[0].totals.total = '-100'; cart.totals.total_fees = '-100';
    const rows = totals(cart); assert.equal(row(rows, 'fee-pc-promo-10980').minor, '-100'); assert.equal(row(rows, 'saved').minor, '5700');
});
test('coupon allocations that do not reconcile fall back to the native aggregate once without leaking codes', () => {
    const cart = fixture(); cart.coupons[0].totals.total_discount = '900';
    const rows = totals(cart); assert.equal(row(rows, 'coupon-total').minor, '-1000'); assert.equal(row(rows, 'saved').minor, '8100'); assert.equal(row(rows, 'coupon-0'), undefined);
    cart.coupons.push(cart.coupons[0]); assert.doesNotThrow(() => totals(cart)); assert.equal(row(totals(cart), 'coupon-total').minor, '-1000');
});
test('missing extension can use every exact native SKU only when subtotal tax basis reconciles', () => {
    const cart = fixture(); delete cart.extensions.peptide_core.sale_savings;
    assert.equal(row(totals(cart), 'product-saving').minor, '-4600');
    cart.items[0].prices.price = '6050'; cart.items[0].prices.regular_price = '8580';
    const rows = totals(cart); assert.equal(row(rows, 'product-saving'), undefined); assert.equal(row(rows, 'products').minor, '11000'); assert.equal(row(rows, 'saved').minor, '3500');
});
test('inconsistent extension, currency or unsafe values never invent a product saving', () => {
    for (const change of [{ currency_code: 'EUR' }, { currency_minor_unit: 3 }, { sale_subtotal: '12000' }, { sale_savings: '9999' }, { regular_subtotal: '9007199254740992' }]) {
        const cart = fixture(); Object.assign(cart.extensions.peptide_core.sale_savings, change); delete cart.items[0].prices;
        const rows = totals(cart); assert.equal(row(rows, 'product-saving'), undefined); assert.equal(row(rows, 'saved').minor, '3500');
    }
});
test('unknown fee allocation cannot be counted again as complete individual savings', () => {
    const cart = fixture(); cart.fees.push(cart.fees[0]);
    const rows = totals(cart); assert.equal(row(rows, 'fee-total').minor, '-2500'); assert.equal(row(rows, 'fee-pc-promo-10980'), undefined); assert.equal(row(rows, 'saved').minor, '5600');
});
test('tax stays a native payable row; savings are explicitly before tax where tax exists', () => {
    const cart = fixture(); cart.totals.total_tax = '1234'; cart.totals.total_discount_tax = '100'; cart.totals.total_price = '13234';
    const rows = totals(cart); assert.equal(row(rows, 'tax').minor, '1234'); assert.equal(row(rows, 'saved').detail, 'Before tax'); assert.equal(row(rows, 'payable').minor, '13234');
    cart.totals.total_tax = '0'; cart.totals.total_discount_tax = '0'; cart.fees[0].totals.total_tax = '-100';
    assert.equal(row(totals(cart), 'saved').detail, 'Before tax');
});
function shipping(cart) {
    cart.totals.total_shipping = '4000'; cart.shipping_rates = [{ package_id: 0, shipping_rates: [m({ rate_id: 'pc_plans:1-2', price: '4000', selected: true })] }];
    cart.extensions.peptide_core.shipping_savings = m({ version: 1, status: 'applied', complete: true, tax_basis: 'exclusive', original_shipping: '4500', shipping_discount: '500', payable_shipping: '4000',
        packages: [{ package_id: 0, rate_id: 'pc_plans:1-2', original_shipping: '4500', shipping_discount: '500', payable_shipping: '4000', discount_id: 10, discount_name: 'Delivery offer' }] });
    return cart;
}
test('a complete selected-rate shipping trace exposes original, discount and payable without changing native total', () => {
    const cart = shipping(fixture()), rows = totals(cart);
    assert.equal(row(rows, 'shipping-original').minor, '4500'); assert.equal(row(rows, 'shipping-offer-10').minor, '-500'); assert.equal(row(rows, 'shipping-offer-10').detail, 'Shipping after offers: $40.00');
    assert.equal(row(rows, 'saved').minor, '8600'); assert.equal(row(rows, 'payable').minor, '12000'); assert.equal(row(rows, 'shipping'), undefined);
});
test('absent or mismatched shipping metadata never reverse-calculates a discount', () => {
    for (const change of [{ currency_code: 'EUR' }, { complete: false }, { tax_basis: 'inclusive' }, { payable_shipping: '3999' }, { shipping_discount: '600' }, { packages: [] }]) {
        const cart = shipping(fixture()); Object.assign(cart.extensions.peptide_core.shipping_savings, change);
        const rows = totals(cart); assert.equal(row(rows, 'shipping').minor, '4000'); assert.equal(row(rows, 'shipping-original'), undefined); assert.equal(row(rows, 'saved').minor, '8100');
    }
    const cart = fixture(); assert.equal(row(totals(cart), 'shipping').minor, '4500'); assert.equal(row(totals(cart), 'saved').minor, '8100');
});
test('shipping traces must cover each actual selected package once and reconcile every native rate price', () => {
    for (const change of [pack => { pack.rate_id = 'foreign'; }, pack => { pack.payable_shipping = '4100'; }, pack => { delete pack.package_id; }, pack => { pack.discount_id = 0; }]) {
        const cart = shipping(fixture()); change(cart.extensions.peptide_core.shipping_savings.packages[0]); assert.equal(row(totals(cart), 'shipping-original'), undefined);
    }
    const duplicate = shipping(fixture()); duplicate.extensions.peptide_core.shipping_savings.packages.push({ ...duplicate.extensions.peptide_core.shipping_savings.packages[0] });
    assert.equal(row(totals(duplicate), 'shipping-original'), undefined);
    const invalidRates = shipping(fixture()); invalidRates.shipping_rates[0].shipping_rates = {}; assert.doesNotThrow(() => totals(invalidRates)); assert.equal(row(totals(invalidRates), 'shipping-original'), undefined);
});
test('unknown shipping remains at checkout and never changes the native estimated payable', () => {
    const cart = shipping(fixture()); cart.has_calculated_shipping = false; cart.totals.total_shipping = null;
    const rows = totals(cart); assert.equal(row(rows, 'shipping').unknown, true); assert.equal(row(rows, 'payable').label, 'Estimated total'); assert.equal(row(rows, 'payable').minor, '12000');
});
test('zero discounts do not create savings claims or duplicate totals', () => {
    const cart = fixture(); delete cart.extensions; cart.items[0].prices.regular_price = '5500'; cart.coupons = []; cart.fees = []; cart.totals.total_discount = '0'; cart.totals.total_fees = '0';
    const rows = totals(cart); assert.equal(row(rows, 'saved'), undefined); assert.equal(rows.filter(value => value.total).length, 1);
});
