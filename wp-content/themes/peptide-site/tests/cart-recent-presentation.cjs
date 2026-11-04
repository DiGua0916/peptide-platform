'use strict';
const assert = require('node:assert/strict');
const { recentPresentation, recentPresentationUrl, recentAddPayload, createClient } = require('../assets/live-cart-experience.js');
const parent = { productId: 30, variationId: 0, variationLabel: '', priceIsFrom: true, price: { text: '$10.00' }, image: { url: '/parent.jpg' } };
const exact = { id: 30, card: { productId: 30, purchaseId: 42, variationId: 42, variationLabel: '20 mg × 10 vials', price: { text: '$55.00' }, image: { url: '/20mg.jpg' }, canAddOne: true } };
const nativeMoney = (minor, text, extra = {}) => ({ minor, text, currency_code: 'USD', currency_minor_unit: 2, ...extra });
const onSale = { ...exact, card: { ...exact.card, price: nativeMoney('5500', '$55.00'), regularPrice: nativeMoney('7800', '$78.00') } };
let passed = 0;
function check(name, fn) { fn(); passed++; console.log('ok', name); }
check('parent minimum is replaced together with exact image and specification', () => {
    const card = recentPresentation(parent, exact);
    assert.equal(card.variationLabel, exact.card.variationLabel); assert.equal(card.price.text, '$55.00'); assert.equal(card.image.url, '/20mg.jpg'); assert.equal(card.priceIsFrom, false); assert.equal(card.canAddOne, true);
});
check('unavailable parent read offers selection without claiming a price', () => {
    const card = recentPresentation(parent, null); assert.equal(card.canAddOne, false); assert.equal(card.price, null); assert.equal(card.variationLabel, 'Choose a specification');
});
check('explicit historical variation may not be silently changed', () => {
    const card = recentPresentation({ ...parent, variationId: 43, variationLabel: '30mg', price: { text: '$70.00' } }, exact);
    assert.equal(card.canAddOne, false); assert.equal(card.variationLabel, '30mg'); assert.equal(card.price.text, '$70.00');
});
check('foreign parent is never an addable presentation', () => { assert.equal(recentPresentation(parent, { ...exact, id: 99 }).canAddOne, false); });
check('missing exact variation label does not expose direct add', () => { assert.equal(recentPresentation(parent, { ...exact, card: { ...exact.card, variationLabel: '' } }).canAddOne, false); });
check('mismatched purchase ID does not expose direct add', () => { assert.equal(recentPresentation(parent, { ...exact, card: { ...exact.card, purchaseId: 90 } }).canAddOne, false); });
check('nonboolean eligibility is not treated as eligible', () => { assert.equal(recentPresentation(parent, { ...exact, card: { ...exact.card, canAddOne: 'true' } }).canAddOne, false); });
check('simple product uses its own exact price without invented package label', () => {
    const card = recentPresentation(parent, { id: 30, card: { ...exact.card, purchaseId: 30, variationId: 0, variationLabel: '', canAddOne: false } }); assert.equal(card.purchaseId, 30); assert.equal(card.variationLabel, 'Single specification'); assert.equal(card.canAddOne, false);
});
check('exact native SKU sale carries a genuine regular price and per-box markdown', () => {
    const card = recentPresentation(parent, onSale);
    assert.equal(card.productSaving.regularText, '$78.00'); assert.equal(card.productSaving.label, 'Save 29% ($23.00)');
    assert.equal(card.variationLabel, '20 mg × 10 vials'); assert.equal(card.canAddOne, true);
    assert.deepEqual(recentAddPayload(card), { id: 42, quantity: 1 });
});
check('legacy response never inherits a stale sale claim from private history', () => {
    assert.equal(recentPresentation({ ...parent, productSaving: { label: 'stale' } }, exact).productSaving, null);
    assert.equal(recentPresentation({ ...parent, productSaving: { label: 'stale' } }, null).productSaving, null);
});
check('sale comparison never escapes an exact historical variation mismatch', () => {
    const card = recentPresentation({ ...parent, variationId: 43, variationLabel: '30mg', productSaving: { label: 'stale' } }, onSale);
    assert.equal(card.productSaving, null); assert.equal(card.canAddOne, false); assert.equal(card.variationLabel, '30mg');
});
check('malformed or mismatched comparison metadata fails closed without blocking the native options action', () => {
    const candidates = [
        { ...onSale.card, regularPrice: nativeMoney('7800', '$78.00', { currency_code: 'EUR' }) },
        { ...onSale.card, regularPrice: nativeMoney('7800', '$78.00', { currency_minor_unit: 3 }) },
        { ...onSale.card, regularPrice: nativeMoney('7800', '$78.00', { currency_minor_unit: '2' }) },
        { ...onSale.card, regularPrice: nativeMoney('-7800', '$78.00') },
        { ...onSale.card, regularPrice: nativeMoney('7800.00', '$78.00') },
        { ...onSale.card, regularPrice: nativeMoney('5500', '$55.00') },
        { ...onSale.card, regularPrice: nativeMoney('9999999999999999', '$99.00') },
        { ...onSale.card, price: { text: '$55.00' } },
        { ...onSale.card, regularPrice: { text: '$78.00' } }
    ];
    for (const card of candidates) {
        const result = recentPresentation(parent, { id: 30, card });
        assert.equal(result.productSaving, null); assert.equal(result.canAddOne, true);
    }
});
check('sale display never escalates a server-disabled direct add', () => {
    const card = recentPresentation(parent, { ...onSale, card: { ...onSale.card, canAddOne: false } });
    assert.equal(card.productSaving.label, 'Save 29% ($23.00)'); assert.equal(card.canAddOne, false);
    assert.throws(() => recentAddPayload(card));
});
check('pretty route retains public origin and explicit native variation', () => {
    const url = new URL(recentPresentationUrl('https://shop.test/wp-json/vialcove/v1/home-product/', { ...parent, variationId: 42 }, 'https://shop.test'));
    assert.equal(url.pathname, '/wp-json/vialcove/v1/home-product/30'); assert.equal(url.searchParams.get('card'), 'true'); assert.equal(url.searchParams.get('variation'), '42');
});
check('plain permalinks are supported without damaging rest_route', () => {
    const url = new URL(recentPresentationUrl('https://shop.test/?rest_route=/vialcove/v1/home-product/', parent, 'https://shop.test'));
    assert.equal(url.searchParams.get('rest_route'), '/vialcove/v1/home-product/30'); assert.equal(url.searchParams.has('variation'), false);
});
check('foreign endpoint is rejected before a credentialed read', () => { assert.throws(() => recentPresentationUrl('https://foreign.test/', parent, 'https://shop.test')); });
check('invalid parent IDs are rejected before a read', () => { assert.throws(() => recentPresentationUrl('https://shop.test/product/', { productId: -1 }, 'https://shop.test')); });
check('direct add uses the native exact SKU ID and one sales unit only', () => { assert.deepEqual(recentAddPayload(recentPresentation(parent, exact)), { id: 42, quantity: 1 }); });
check('unverified options are never submitted as a direct add', () => { assert.throws(() => recentAddPayload(recentPresentation(parent, null))); });
check('a parent cannot be sent in place of its displayed variation', () => { assert.throws(() => recentAddPayload({ ...exact.card, purchaseId: 30 })); });
(async () => {
    const before = { items: [], items_count: 0, totals: { total_price: '0', currency_code: 'USD', currency_minor_unit: 2 } };
    const after = { ...before, items: [{ key: 'native-line', id: 42, quantity: 1 }], items_count: 1, totals: { ...before.totals, total_price: '5500' } };
    const calls = []; let resolveWrite;
    const client = createClient({ cartApiUrl: 'https://shop.test/wp-json/wc/store/v1/cart' }, { fetch: async (url, options) => {
        calls.push({ url, options }); if (options.method === 'POST') await new Promise(resolve => { resolveWrite = resolve; });
        return { ok: true, status: options.method === 'POST' ? 201 : 200, headers: { get: name => name === 'Nonce' ? 'synthetic-test-nonce' : null }, json: async () => options.method === 'POST' ? after : before };
    } });
    await client.refresh();
    const first = client.write('add-item', recentAddPayload(recentPresentation(parent, exact)));
    await assert.rejects(client.write('add-item', recentAddPayload(recentPresentation(parent, exact))));
    assert.equal(calls.filter(call => call.options.method === 'POST').length, 1);
    const post = calls.find(call => call.options.method === 'POST'); assert.equal(post.url, 'https://shop.test/wp-json/wc/store/v1/cart/add-item'); assert.deepEqual(JSON.parse(post.options.body), { id: 42, quantity: 1 });
    resolveWrite(); const result = await first; assert.equal(result.confirmed, true); assert.equal(result.cartCount, 1); client.destroy(); passed++;
    console.log('ok single native POST rejects duplicate taps and waits for confirmed cart');
    console.log(passed + ' recent product presentation checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
