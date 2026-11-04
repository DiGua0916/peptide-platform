'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { cartItemPricing } = require('../assets/live-cart-experience.js');

function item(overrides = {}) {
    return { key: 'native-line', id: 42, name: 'Tirzepatide', quantity: 1,
        variation: [{ value: '50 mg × 10 vials' }],
        prices: { price: '10200', regular_price: '17100', sale_price: '10200', currency_code: 'USD', currency_minor_unit: 2 },
        totals: { line_subtotal: '10200', line_total: '10200', currency_code: 'USD', currency_minor_unit: 2 }, ...overrides };
}

test('native exact-SKU sale presents unit prices and quantity-aware line prices', () => {
    const value = item({ quantity: 3, totals: { ...item().totals, line_subtotal: '30600', line_total: '30600' } });
    const result = cartItemPricing(value);
    assert.equal(result.unit, '$102.00'); assert.equal(result.regularUnit, '$171.00');
    assert.equal(result.total, '$306.00'); assert.equal(result.regularTotal, '$513.00');
    assert.equal(result.savingsLabel, 'Save 40% ($207.00)');
    assert.equal(result.orderDiscount, '');
});

test('coupon-reduced payable total is preserved while product markdown excludes the coupon', () => {
    const result = cartItemPricing(item({ quantity: 2, totals: { ...item().totals, line_subtotal: '20400', line_total: '17400' } }));
    assert.equal(result.total, '$174.00'); assert.equal(result.regularTotal, '$342.00');
    assert.equal(result.savingsLabel, 'Save 40% ($138.00)');
    assert.equal(result.orderDiscount, 'Order discount −$30.00');
    assert.match(result.savingsDescription, /before order discounts/);
});

test('order discount alone never creates a product-sale label or crossed-out price', () => {
    const result = cartItemPricing(item({ prices: { ...item().prices, regular_price: '10200' }, totals: { ...item().totals, line_total: '9200' } }));
    assert.equal(result.regularUnit, ''); assert.equal(result.regularTotal, '');
    assert.equal(result.savingsLabel, ''); assert.equal(result.orderDiscount, 'Order discount −$10.00');
});

test('same native currency and zero or high precision remain exact', () => {
    for (const [currency, precision, regular, active, expected] of [
        ['JPY', 0, '1000', '750', ['¥750', '¥1,000', 'Save 25% (¥250)']],
        ['USD', 8, '100000001', '75000001', ['$0.75000001', '$1.00000001', 'Save 25% ($0.25000000)']]
    ]) {
        const metadata = { currency_code: currency, currency_minor_unit: precision };
        const result = cartItemPricing(item({ prices: { ...metadata, price: active, regular_price: regular }, totals: { ...metadata, line_subtotal: active, line_total: active } }));
        assert.deepEqual([result.unit, result.regularTotal, result.savingsLabel], expected);
    }
});

test('tax-inclusive or externally adjusted price basis cannot create a false line comparison', () => {
    const result = cartItemPricing(item({ totals: { ...item().totals, line_subtotal: '10000', line_total: '10000' } }));
    assert.equal(result.regularUnit, '$171.00'); assert.equal(result.regularTotal, ''); assert.equal(result.savingsLabel, '');
});

test('mixed currency, precision, unsafe quantity or unsafe extended total suppress line claims', () => {
    const badItems = [
        item({ totals: { ...item().totals, currency_code: 'EUR' } }),
        item({ totals: { ...item().totals, currency_minor_unit: 3 } }),
        item({ quantity: 0 }), item({ quantity: 1.5 }), item({ quantity: Number.MAX_SAFE_INTEGER + 1 }),
        item({ quantity: 2, prices: { ...item().prices, price: '4000000000000000', regular_price: '9000000000000000' }, totals: { ...item().totals, line_subtotal: '8000000000000000', line_total: '8000000000000000' } }),
        item({ totals: { ...item().totals, line_total: '20200' } })
    ];
    for (const value of badItems) {
        assert.equal(cartItemPricing(value).regularTotal, ''); assert.equal(cartItemPricing(value).savingsLabel, '');
    }
});

test('malformed prices never create a sale, and sub-one-percent sale is not advertised as zero', () => {
    for (const invalid of [undefined, '-1', '171.00', '9999999999999999', 17100]) {
        const result = cartItemPricing(item({ prices: { ...item().prices, regular_price: invalid } }));
        assert.equal(result.regularUnit, ''); assert.equal(result.savingsLabel, '');
    }
    const value = item({ prices: { ...item().prices, price: '99999', regular_price: '100000' }, totals: { ...item().totals, line_subtotal: '99999', line_total: '99999' } });
    assert.equal(cartItemPricing(value).savingsLabel, 'Save <1% ($0.01)');
});

test('zero-price native sale is supported without dividing by the sale amount', () => {
    const result = cartItemPricing(item({ prices: { ...item().prices, price: '0', regular_price: '1000' }, totals: { ...item().totals, line_subtotal: '0', line_total: '0' } }));
    assert.equal(result.total, '$0.00'); assert.equal(result.savingsLabel, 'Save 100% ($10.00)');
    const almostFree = cartItemPricing(item({ prices: { ...item().prices, price: '1', regular_price: '1000' }, totals: { ...item().totals, line_subtotal: '1', line_total: '1' } }));
    assert.equal(almostFree.savingsLabel, 'Save >99% ($9.99)');
});

function renderer() {
    const source = fs.readFileSync(path.join(__dirname, '../assets/live-cart-experience.js'), 'utf8');
    const start = source.indexOf('    function cartRow(item, state) {'), end = source.indexOf('    function render(state) {', start);
    assert.ok(start >= 0 && end > start);
    function el(tag, cls = '', text = '') {
        const node = { tagName: tag, className: cls, textContent: text, dataset: {}, attrs: {}, children: [], events: {}, hidden: false,
            append(...children) { this.children.push(...children); },
            setAttribute(key, value) { this.attrs[key] = String(value); }, getAttribute(key) { return this.attrs[key] ?? null; }, removeAttribute(key) { delete this.attrs[key]; },
            addEventListener(type, callback) { this.events[type] = callback; } };
        Object.defineProperty(node, 'src', { get() { return this.attrs.src; }, set(value) { this.attrs.src = value; } });
        node.classList = { toggle(name, state) { const classes = new Set(node.className.split(' ')); if (state) classes.add(name); else classes.delete(name); node.className = [...classes].join(' '); } };
        return node;
    }
    const calls = [], client = { write(action, payload) { calls.push({ action, payload }); return Promise.resolve(); } };
    const make = new Function('el', 'productLink', 'safeUrl', 'config', 'parentIds', 'client', 'clean', 'cartItemPricing', 'return (' + source.slice(start, end).trim() + ');')(
        el, (product, cls = 'vc-live-cart__product') => el('a', cls, product.name), String, { shopUrl: '/shop/' }, new Map(), client, String, cartItemPricing);
    const find = (node, cls) => node.className.split(' ').includes(cls) ? node : node.children.map(child => find(child, cls)).find(Boolean);
    return { calls, make, find };
}

test('actual keyed-row renderer updates existing nodes, clears expired sale and retains native quantity payload', async () => {
    const h = renderer(), first = item(), row = h.make(first, {});
    row.update(first, { valid: true, pending: false });
    const unit = h.find(row.node, 'vc-live-cart__unit'), total = h.find(row.node, 'vc-live-cart__line-total'), saving = h.find(row.node, 'vc-live-cart__sale-tag');
    assert.equal(total.textContent, '$102.00'); assert.equal(saving.hidden, false); assert.equal(saving.textContent, 'Save 40% ($69.00)');
    const next = item({ quantity: 2, prices: { ...first.prices, regular_price: '10200' }, totals: { ...first.totals, line_subtotal: '20400', line_total: '20400' } });
    row.update(next, { valid: true, pending: false });
    assert.equal(h.find(row.node, 'vc-live-cart__unit'), unit); assert.equal(h.find(row.node, 'vc-live-cart__line-total'), total);
    assert.equal(saving.hidden, true); assert.equal(h.find(row.node, 'vc-live-cart__line-regular').hidden, true); assert.equal(total.textContent, '$204.00');
    const quantity = h.find(row.node, 'vc-live-cart__quantity');
    await quantity.children.find(node => node.dataset.cartAction === 'plus').events.click();
    assert.deepEqual(h.calls, [{ action: 'update-item', payload: { key: 'native-line', quantity: 3 } }]);
    row.update(next, { valid: true, pending: true });
    assert.equal(quantity.children.find(node => node.dataset.cartAction === 'plus').disabled, true);
});
