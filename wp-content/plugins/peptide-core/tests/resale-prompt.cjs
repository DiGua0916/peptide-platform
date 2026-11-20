// Site 1 P1-4 dealer prompt: pure helpers. Run: node resale-prompt.cjs
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { itemsMinor, crossed, message, waUrl } = require('../assets/resale-prompt.js');
const cfg = { strings: { message: "Hi, I'd like dealer pricing for my bag:", total: 'Total', ref: 'ref' } };
const cart = (total, items) => ({ items, totals: { total_items: String(total), currency_minor_unit: 2, currency_symbol: '$' } });

test('fires only when an add crosses the threshold', () => {
    assert.equal(crossed(52760, 61390, 60000), true);
    assert.equal(crossed(61390, 66890, 60000), false, 'already above: no second popup');
    assert.equal(crossed(NaN, 61390, 60000), true, 'first known cart above threshold');
    assert.equal(crossed(11000, 59999, 60000), false);
});
test('message lists the bag, the item total and the creative code, with no discount text', () => {
    const text = message(cart(61390, [{ name: 'Tirzepatide', quantity: 2, variation: [{ value: '20mg*10vials' }] }, { name: 'Tesamorelin', quantity: 1 }]), cfg, 'A12');
    assert.equal(text, "Hi, I'd like dealer pricing for my bag:\n- Tirzepatide 20mg*10vials x 2\n- Tesamorelin x 1\nTotal $613.90\nref: cr=A12");
    assert.equal(/off|discount|%/i.test(text), false);
});
test('unsafe creative codes are dropped', () => {
    assert.equal(message(cart(60000, []), cfg, 'x"><script>').includes('cr='), false);
});
test('whatsapp link keeps digits only and encodes the text', () => {
    assert.equal(waUrl('+1 (303) 944-4124', 'a b\nc'), 'https://wa.me/13039444124?text=a%20b%0Ac');
    assert.equal(Number.isNaN(itemsMinor({})), true);
});
