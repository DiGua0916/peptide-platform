'use strict';
// Exercise the production HTTP adapter and feedback together. Synthetic responses
// stay in this process; this suite never calls WordPress or mutates a real cart.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createClient } = require('../assets/live-cart-experience.js');
const { addPayload, addedMessage } = require('../assets/live-cart-recommendation.js');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
const line = (id, quantity, key = 'sku-' + id) => ({ key, id, quantity });
function cart(items = [], receipt) {
    const value = {
        items, items_count: items.reduce((sum, item) => sum + item.quantity, 0),
        totals: { total_price: '5500', currency_code: 'USD', currency_minor_unit: 2 },
    };
    if (receipt !== undefined) value.extensions = { peptide_core: { activity_recommendation: receipt } };
    return value;
}
const goodReceipt = () => ({ requested: true, added: true, couponRequested: true, couponApplied: true, reason: 'applied' });
const candidate = (enabled = true) => ({ quantity: 1, purchasableId: 42, name: 'Product', automaticCoupon: { enabled }, offerToken: 'x'.repeat(80) + '.' + 'a'.repeat(64) });
const ordinary = () => ({ id: 42, quantity: 1 });
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300, headers: { get: name => name === 'Nonce' ? 'synthetic-only' : null }, json: async () => data });
function transport({ before = cart(), after = cart([line(42, 1)]), status = 201, post, reconcile = after, timeoutMs = 100 } = {}) {
    const calls = [], events = [];
    const client = createClient({ cartApiUrl: 'https://shop.test/cart' }, {
        timeoutMs, notify: (type, detail) => events.push({ type, detail }),
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (options.method === 'POST') return post ? post(options) : response(after, status);
            return response(calls.filter(call => call.options.method === 'GET').length === 1 ? before : reconcile);
        },
    });
    return { client, calls, events, posts: () => calls.filter(call => call.options.method === 'POST'), reads: () => calls.filter(call => call.options.method === 'GET'), successes: () => events.filter(event => event.type === 'updated' && event.detail.stale === false) };
}
async function rejected(fixture, body = ordinary()) {
    await fixture.client.refresh();
    try {
        await assert.rejects(fixture.client.write('add-item', body));
        assert.equal(fixture.posts().length, 1, 'an uncertain POST is never repeated');
        assert.equal(fixture.reads().length, 2, 'one read reconciles an uncertain response');
        assert.equal(fixture.successes().length, 0, 'reconciliation is not an add receipt');
        assert.equal(fixture.client.snapshot().errorOperation, 'write');
    } finally { fixture.client.destroy(); }
}

test('a 200 cart is not confirmation of add-item, even if the item appeared', async () => rejected(transport({ status: 200 })));
test('a native 201 with the requested SKU increment confirms an ordinary add without a ticket receipt', async () => {
    const f = transport(); await f.client.refresh();
    try { const result = await f.client.write('add-item', ordinary()); assert.equal(result.confirmed, true); assert.equal(result.cartCount, 1); assert.equal(f.successes().length, 1); assert.equal(f.reads().length, 1); }
    finally { f.client.destroy(); }
});
test('a pre-existing SKU without a quantity increment cannot confirm a new add', async () => rejected(transport({ before: cart([line(42, 2)]), after: cart([line(42, 2)]) })));
test('an increment on another SKU is not confirmation of the requested item', async () => rejected(transport({ after: cart([line(99, 1)]) })));
test('a requested SKU increase smaller than the requested quantity is unconfirmed', async () => rejected(transport({ after: cart([line(42, 1)]) }), { id: 42, quantity: 2 }));
test('a larger unexpected increase is not reported as this exact requested add', async () => rejected(transport({ after: cart([line(42, 2)]) })));
test('same-SKU quantities aggregate across distinct native cart lines', async () => {
    const f = transport({ before: cart([line(42, 2, 'a'), line(42, 3, 'b')]), after: cart([line(42, 3, 'a'), line(42, 3, 'b')]) }); await f.client.refresh();
    try { assert.equal((await f.client.write('add-item', ordinary())).confirmed, true); }
    finally { f.client.destroy(); }
});
for (const [name, receipt] of [
    ['absent', undefined], ['requested missing', { added: true }], ['requested false', { ...goodReceipt(), requested: false }],
    ['requested string', { ...goodReceipt(), requested: 'true' }], ['added false', { ...goodReceipt(), added: false }],
    ['added string', { ...goodReceipt(), added: 'true' }],
]) test('ticketed offer requires a strict same-response receipt: ' + name, async () => rejected(transport({ after: cart([line(42, 1)], receipt) }), addPayload(candidate())));
test('a ticket still requires a receipt when its optional coupon is disabled', async () => rejected(transport(), addPayload(candidate(false))));
test('a valid receipt cannot confirm the wrong SKU or unchanged quantity', async () => rejected(transport({ after: cart([line(99, 1)], goodReceipt()) }), addPayload(candidate())));
test('a genuine added:true with coupon failure remains one confirmed add and keeps its partial receipt', async () => {
    const receipt = { ...goodReceipt(), couponApplied: false, reason: 'coupon_changed' }, f = transport({ after: cart([line(42, 1)], receipt) }); await f.client.refresh();
    try { const result = await f.client.write('add-item', addPayload(candidate())); assert.equal(result.confirmed, true); assert.deepEqual(result.rawCart.extensions.peptide_core.activity_recommendation, receipt); assert.match(addedMessage(candidate(), result), /box added.*could not be applied/); assert.equal(f.posts().length, 1); }
    finally { f.client.destroy(); }
});
test('409 never celebrates or replays a one-use offer ticket even when reconciliation shows the item', async () => rejected(transport({ status: 409, after: { message: 'Offer changed' }, reconcile: cart([line(42, 1)]) }), addPayload(candidate())));
test('a timed-out POST reconciles once without repeating add-item', async () => rejected(transport({ timeoutMs: 5, post: options => new Promise((resolve, reject) => {
    options.signal.addEventListener('abort', () => { const failure = new Error('synthetic abort'); failure.name = 'AbortError'; reject(failure); }, { once: true });
}) })));
test('a stale late 201 cannot overwrite a newer external cart or report Added', async () => {
    let resolve; const f = transport({ post: () => new Promise(done => { resolve = done; }) }); await f.client.refresh();
    const failed = assert.rejects(f.client.write('add-item', ordinary()));
    const newer = cart([line(99, 3)]); f.client.externalUpdate({ cart: newer }); resolve(response(cart([line(42, 1)]), 201)); await failed;
    try { assert.equal(f.client.snapshot().cart, newer); assert.equal(f.successes().length, 0); assert.equal(f.posts().length, 1); assert.equal(f.reads().length, 1); }
    finally { f.client.destroy(); }
});
test('destroyed client ignores a late native 201 with no retry or success notification', async () => {
    let resolve; const f = transport({ post: () => new Promise(done => { resolve = done; }) }); await f.client.refresh();
    const failed = assert.rejects(f.client.write('add-item', ordinary())); f.client.destroy(); resolve(response(cart([line(42, 1)]), 201)); await failed;
    assert.equal(f.successes().length, 0); assert.equal(f.posts().length, 1); assert.equal(f.reads().length, 1);
});
test('duplicate taps cannot issue a second POST while the native response is pending', async () => {
    let resolve; const f = transport({ post: () => new Promise(done => { resolve = done; }) }); await f.client.refresh();
    const write = f.client.write('add-item', ordinary()); await assert.rejects(f.client.write('add-item', ordinary())); resolve(response(cart([line(42, 1)]), 201));
    try { assert.equal((await write).confirmed, true); assert.equal(f.posts().length, 1); } finally { f.client.destroy(); }
});
test('the submitted SKU and quantity are captured before caller mutation during await', async () => {
    let resolve; const f = transport({ post: () => new Promise(done => { resolve = done; }) }); await f.client.refresh();
    const body = ordinary(), write = f.client.write('add-item', body); body.id = 99; body.quantity = 8; resolve(response(cart([line(42, 1)]), 201));
    try { assert.equal((await write).confirmed, true); assert.deepEqual(JSON.parse(f.posts()[0].options.body), ordinary()); } finally { f.client.destroy(); }
});
test('other native cart mutations retain successful 200 semantics', async () => {
    const f = transport({ before: cart([line(42, 1)]), after: cart([line(42, 2)]), status: 200 }); await f.client.refresh();
    try { assert.equal((await f.client.write('update-item', { key: 'sku-42', quantity: 2 })).confirmed, true); } finally { f.client.destroy(); }
});
test('public client.request still returns the original DTO, not a transport envelope', async () => {
    const f = transport();
    try { assert.deepEqual(await f.client.request('https://shop.test/config'), cart()); } finally { f.client.destroy(); }
});

// Minimal host for the actual feedback production module, not a copy of its
// success logic. A burst is observable only if that module really appends it.
function feedbackHost() {
    class Element {
        constructor(tag) { this.tagName = tag; this.childNodes = []; this._text = ''; this.parentNode = null; this.className = ''; this.disabled = false; this.hidden = false; this.dataset = {}; this.attrs = new Map(); this.style = { setProperty() {} }; this.classList = { add() {}, remove() {} }; }
        get textContent() { return this._text + this.childNodes.map(node => node.textContent).join(''); }
        set textContent(value) { this._text = String(value); this.childNodes = []; }
        get isConnected() { return true; }
        append(...nodes) { for (const node of nodes) { node.remove(); node.parentNode = this; this.childNodes.push(node); } }
        replaceChildren(...nodes) { this.childNodes.forEach(node => { node.parentNode = null; }); this.childNodes = []; this._text = ''; this.append(...nodes); }
        remove() { if (this.parentNode) { this.parentNode.childNodes = this.parentNode.childNodes.filter(node => node !== this); this.parentNode = null; } }
        setAttribute(name, value) { this.attrs.set(name, String(value)); }
        getAttribute(name) { return this.attrs.get(name) ?? null; }
        removeAttribute(name) { this.attrs.delete(name); }
        cloneNode(deep) { const clone = new Element(this.tagName); clone._text = this._text; if (deep) clone.append(...this.childNodes.map(node => node.cloneNode(true))); return clone; }
        getBoundingClientRect() { return { left: 20, top: 20, right: 120, bottom: 60, width: 100, height: 40 }; }
        closest() { return null; }
    }
    const document = { hidden: false, createElement: tag => new Element(tag), createElementNS: (_, tag) => new Element(tag), addEventListener() {}, removeEventListener() {}, body: new Element('body') };
    const window = { document, innerWidth: 390, innerHeight: 844, matchMedia: () => ({ matches: false }) };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/live-cart-feedback.js'), 'utf8'), { window, document, AbortController, setTimeout, clearTimeout, Promise, console });
    const button = new Element('button'), label = new Element('span'), notice = new Element('p'); label.textContent = 'Add 1 box +'; button.append(label); document.body.append(button, notice);
    const states = [], feedback = window.createLiveCartFeedback({ buttons: [button], announce: notice, timeoutMs: 500, successMs: 5000, onStateChange: state => states.push(state) });
    return { button, notice, states, feedback, bursts: () => document.body.childNodes.filter(node => node.className === 'add-feedback-burst').length };
}
for (const [name, status, receipt, expectSuccess] of [
    ['unconfirmed 200', 200, undefined, false], ['ticket missing receipt', 201, undefined, false],
    ['valid same-201 ticket', 201, goodReceipt(), true],
    ['same-201 added, coupon failed', 201, { ...goodReceipt(), couponApplied: false, reason: 'coupon_not_applied' }, true],
]) test('actual feedback and ticket adapter: ' + name, async () => {
    let resolve; const f = transport({ post: () => new Promise(done => { resolve = done; }) }), ui = feedbackHost(); await f.client.refresh();
    try {
        const run = ui.feedback.run({ submit: () => f.client.write('add-item', addPayload(candidate())), triggerButton: ui.button }); await flush();
        assert.equal(ui.button.textContent, 'Adding…'); assert.equal(ui.bursts(), 0); assert.equal(ui.states.includes('success'), false);
        resolve(response(cart([line(42, 1)], receipt), status)); const result = await run;
        assert.equal(result.ok, expectSuccess); assert.equal(ui.states.includes('success'), expectSuccess); assert.equal(ui.bursts(), expectSuccess ? 1 : 0);
        if (expectSuccess) assert.match(ui.button.textContent, /Added/); else { assert.equal(ui.button.textContent, 'Add 1 box +'); assert.match(ui.notice.textContent, /Could not confirm/); }
        assert.equal(f.posts().length, 1);
    } finally { ui.feedback.destroy(); f.client.destroy(); }
});
