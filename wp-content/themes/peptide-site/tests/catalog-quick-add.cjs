'use strict';
// Production helpers and the real cart client, with in-process transport only.
// No WordPress, browser session, or customer cart is contacted by this suite.
const test = require('node:test');
const assert = require('node:assert/strict');
const { verifiedCard, cardUrl } = require('../assets/catalog-quick-add.js');
const { createClient, addOne } = require('../assets/live-cart-experience.js');

const expected = () => ({ productId: 8, purchaseId: 42, variationId: 42,
    price: { minor: '5500', currency_code: 'USD', currency_minor_unit: 2 } });
const fresh = () => ({ id: 8, card: { ...expected(), variationLabel: '20 mg × 10 vials', canAddOne: true } });
const line = (id, quantity) => ({ key: 'sku-' + id, id, quantity });
const cart = (items = []) => ({ items, items_count: items.reduce((sum, item) => sum + item.quantity, 0),
    totals: { total_price: '5500', currency_code: 'USD', currency_minor_unit: 2 } });
const response = (data, status = 200, nonce = 'native-synthetic-nonce') => ({
    status, ok: status >= 200 && status < 300, headers: { get: name => name === 'Nonce' ? nonce : null }, json: async () => data,
});
function transport({ before = cart(), after = cart([line(42, 1)]), status = 201, get, post, timeoutMs = 100 } = {}) {
    const calls = [], events = [];
    const client = createClient({ cartApiUrl: 'https://shop.test/wc/store/v1/cart' }, {
        timeoutMs, notify: (type, detail) => events.push({ type, detail }),
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (options.method === 'POST') return post ? post(options) : response(after, status);
            return get ? get(options) : response(calls.filter(call => call.options.method === 'GET').length === 1 ? before : after);
        },
    });
    return { client, calls, events, posts: () => calls.filter(call => call.options.method === 'POST'),
        reads: () => calls.filter(call => call.options.method === 'GET'),
        successes: () => events.filter(event => event.type === 'updated' && event.detail.stale === false) };
}

test('verification accepts only the exact pictured SKU and its unchanged native price', () => {
    const result = fresh();
    assert.equal(verifiedCard(expected(), result), result.card);
    const simple = { productId: 9, purchaseId: 9, variationId: 0, price: expected().price };
    const simpleResponse = { id: 9, card: { ...simple, canAddOne: true } };
    assert.equal(verifiedCard(simple, simpleResponse), simpleResponse.card);
});

test('changed price, currency or precision forces specification review rather than a silent add', () => {
    for (const change of [{ minor: '5600' }, { currency_code: 'EUR' }, { currency_minor_unit: 3 }, { minor: 5500 }]) {
        const result = fresh(); Object.assign(result.card.price, change);
        assert.equal(verifiedCard(expected(), result), null);
    }
});

test('unavailable native quantity, false-like eligibility and mismatched identities are rejected', () => {
    for (const change of [{ canAddOne: false }, { canAddOne: 'true' }, { canAddOne: 1 },
        { productId: 9 }, { purchaseId: 43 }, { variationId: 43 }, { variationId: 0 }, { purchaseId: '42' }]) {
        const result = fresh(); Object.assign(result.card, change);
        assert.equal(verifiedCard(expected(), result), null);
    }
    const parentMismatch = fresh(); parentMismatch.id = 9;
    assert.equal(verifiedCard(expected(), parentMismatch), null);
    assert.equal(verifiedCard(expected(), { id: 8 }), null);
});

test('invalid expected identity or money metadata cannot grant card eligibility', () => {
    for (const change of [{ productId: 0 }, { purchaseId: -1 }, { variationId: -1 }, { variationId: 1.2 },
        { productId: Number.MAX_SAFE_INTEGER + 1 }, { price: { minor: '-1', currency_code: 'USD', currency_minor_unit: 2 } },
        { price: { minor: '5500', currency_code: 'usd', currency_minor_unit: 2 } },
        { price: { minor: '5500', currency_code: 'USD', currency_minor_unit: 9 } },
        { price: { minor: '9007199254740992', currency_code: 'USD', currency_minor_unit: 2 } }]) {
        assert.equal(verifiedCard({ ...expected(), ...change }, fresh()), null);
    }
});

test('card read URL preserves same-origin pretty and rest_route endpoints with the displayed variation', () => {
    const pretty = new URL(cardUrl('https://shop.test/wp-json/vialcove/v1/home-product/', expected(), 'https://shop.test/'));
    assert.equal(pretty.pathname, '/wp-json/vialcove/v1/home-product/8');
    assert.equal(pretty.searchParams.get('card'), 'true'); assert.equal(pretty.searchParams.get('variation'), '42');
    const route = new URL(cardUrl('/?rest_route=/vialcove/v1/home-product/&lang=en', expected(), 'https://shop.test/'));
    assert.equal(route.pathname, '/'); assert.equal(route.searchParams.get('rest_route'), '/vialcove/v1/home-product/8');
    assert.equal(route.searchParams.get('lang'), 'en'); assert.equal(route.searchParams.get('variation'), '42');
    const simple = new URL(cardUrl('/wp-json/vialcove/v1/home-product/', { productId: 9, variationId: 0 }, 'https://shop.test/'));
    assert.equal(simple.searchParams.has('variation'), false);
    for (const base of ['https://other.test/home-product/', 'http://shop.test/home-product/', 'https://user:pass@shop.test/home-product/', 'javascript:alert(1)']) {
        assert.throws(() => cardUrl(base, expected(), 'https://shop.test/'));
    }
    assert.throws(() => cardUrl('/wp-json/products/', { productId: 0 }, 'https://shop.test/'));
});

test('addOne primes the native cart and nonce before one confirmed exact-SKU POST', async () => {
    const f = transport();
    try {
        assert.equal(f.client.snapshot().valid, false);
        const result = await addOne(f.client, fresh().card);
        assert.equal(result.confirmed, true); assert.equal(result.cartCount, 1);
        assert.deepEqual(f.calls.map(call => call.options.method), ['GET', 'POST']);
        assert.equal(f.posts()[0].options.headers.Nonce, 'native-synthetic-nonce');
        assert.deepEqual(JSON.parse(f.posts()[0].options.body), { id: 42, quantity: 1 });
        assert.equal(f.successes().length, 1);
    } finally { f.client.destroy(); }
});

test('same exact SKU can add one to an existing quantity while preserving all other items', async () => {
    const f = transport({ before: cart([line(42, 2), line(99, 4)]), after: cart([line(42, 3), line(99, 4)]) });
    try { const result = await addOne(f.client, fresh().card); assert.equal(result.confirmed, true); assert.equal(result.cartCount, 7); assert.equal(f.posts().length, 1); }
    finally { f.client.destroy(); }
});

test('200, wrong SKU, unchanged quantity and unexpected larger increase never report add success or retry', async () => {
    for (const options of [{ status: 200 }, { after: cart([line(99, 1)]) },
        { before: cart([line(42, 2)]), after: cart([line(42, 2)]) }, { after: cart([line(42, 2)]) }]) {
        const f = transport(options);
        try {
            await assert.rejects(addOne(f.client, fresh().card));
            assert.equal(f.posts().length, 1); assert.equal(f.reads().length, 2);
            assert.equal(f.successes().length, 0);
        } finally { f.client.destroy(); }
    }
});

test('a timed-out POST only reconciles and never retries or reports the read-back as success', async () => {
    const f = transport({ timeoutMs: 5, post: options => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => { const error = new Error('synthetic timeout'); error.name = 'AbortError'; reject(error); }, { once: true });
    }) });
    try {
        await assert.rejects(addOne(f.client, fresh().card));
        assert.equal(f.posts().length, 1); assert.equal(f.reads().length, 2); assert.equal(f.successes().length, 0);
    } finally { f.client.destroy(); }
});

test('cancellation during the initial native read prevents the subsequent POST', async () => {
    let resolveRead; const signal = new AbortController(), f = transport({ get: () => new Promise(resolve => { resolveRead = resolve; }) });
    const operation = addOne(f.client, fresh().card, signal.signal);
    signal.abort(); resolveRead(response(cart()));
    try {
        await assert.rejects(operation, /cancelled/);
        assert.equal(f.reads().length, 1); assert.equal(f.posts().length, 0); assert.equal(f.successes().length, 0);
    } finally { f.client.destroy(); }
});

test('invalid eligibility and pre-cancelled operations perform neither native reads nor writes', async () => {
    for (const item of [{ ...fresh().card, canAddOne: false }, { ...fresh().card, purchaseId: 43 }, { ...fresh().card, productId: 0 }]) {
        const f = transport();
        try { await assert.rejects(addOne(f.client, item)); assert.equal(f.calls.length, 0); }
        finally { f.client.destroy(); }
    }
    const f = transport(), cancelled = new AbortController(); cancelled.abort();
    try { await assert.rejects(addOne(f.client, fresh().card, cancelled.signal), /cancelled/); assert.equal(f.calls.length, 0); }
    finally { f.client.destroy(); }
});

test('failed cart priming and native checkout write lock prevent quick-add submission', async () => {
    const f = transport({ get: () => response({ code: 'unavailable', message: 'Cart unavailable' }, 503) });
    try { await assert.rejects(addOne(f.client, fresh().card)); assert.equal(f.reads().length, 1); assert.equal(f.posts().length, 0); }
    finally { f.client.destroy(); }
    let writes = 0;
    const client = createClient({ cartApiUrl: 'https://shop.test/wc/store/v1/cart' }, { canWrite: () => false,
        fetch: async (_, options) => { if (options.method === 'POST') writes++; return response(cart()); } });
    try { await assert.rejects(addOne(client, fresh().card)); assert.equal(writes, 0); }
    finally { client.destroy(); }
});
