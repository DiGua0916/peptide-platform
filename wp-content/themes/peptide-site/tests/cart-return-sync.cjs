'use strict';
// The production client and lifecycle run against an isolated HTTP peer. No
// checkout, payment, WordPress session or real cart is created or modified.
const test = require('node:test'), assert = require('node:assert/strict');
const { createClient, createCartReturnSync } = require('../assets/live-cart-experience.js');
const flush = async () => { for (let i = 0; i < 16; i++) await Promise.resolve(); };
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const cart = quantity => ({ items: quantity ? [{ key: 'sku-42', id: 42, quantity }] : [], items_count: quantity, totals: { total_price: String(quantity * 1000), currency_code: 'USD', currency_minor_unit: 2 } });
const response = (value, status = 200) => ({ ok: status >= 200 && status < 300, status, headers: { get: () => 'isolated-nonce' }, json: async () => value });
class Target {
    constructor() { this.listeners = new Map(); }
    addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name).add(fn); }
    removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
    emit(name, event = {}) { for (const fn of [...(this.listeners.get(name) || [])]) fn(event); }
    count() { return [...this.listeners.values()].reduce((sum, listeners) => sum + listeners.size, 0); }
}
function fixture(peer = () => response(cart(6))) {
    const document = new Target(), window = new Target(), timers = new Map(), calls = [], states = [];
    document.hidden = false; let time = 10000, serial = 0;
    window.setTimeout = (fn, ms) => { timers.set(++serial, { fn, ms }); return serial; };
    window.clearTimeout = id => timers.delete(id);
    const client = createClient({ cartApiUrl: 'https://shop.test/wc/store/v1/cart' }, {
        notify: (type, detail) => { if (type === 'state') states.push(detail); },
        fetch: async (url, options) => { calls.push({ url, options }); return peer(url, options, calls.length); },
    });
    const sync = createCartReturnSync({ document, window, refresh: () => client.refresh(), retireRead: () => client.retireRead(), now: () => time });
    return { document, window, client, sync, calls, states, timers,
        async tick() { const tasks = [...timers.values()]; timers.clear(); tasks.forEach(task => task.fn()); await flush(); },
        elapse(ms) { time += ms; },
        hide() { document.hidden = true; document.emit('visibilitychange'); },
        show() { document.hidden = false; document.emit('visibilitychange'); },
        reads: () => calls.filter(call => call.options.method === 'GET'),
        posts: () => calls.filter(call => call.options.method === 'POST'),
        destroy() { sync.destroy(); client.destroy(); },
    };
}

test('pageshow, visibility and focus in one return burst perform one authoritative no-store GET', async () => {
    const f = fixture();
    try {
        f.window.emit('pageshow', { persisted: true }); f.document.emit('visibilitychange'); f.window.emit('focus');
        assert.equal(f.calls.length, 0); assert.equal(f.timers.size, 1); await f.tick();
        assert.equal(f.reads().length, 1); assert.equal(f.posts().length, 0);
        assert.equal(f.calls[0].options.credentials, 'same-origin'); assert.equal(f.calls[0].options.cache, 'no-store');
        assert.equal(f.client.snapshot().cart.items_count, 6);
    } finally { f.destroy(); }
});

test('repeated foreground focus is bounded, while an actual short leave still forces a fresh read', async () => {
    const f = fixture();
    try {
        f.window.emit('focus'); await f.tick();
        for (let i = 0; i < 10; i++) { f.window.emit('focus'); f.document.emit('visibilitychange'); }
        await f.tick(); assert.equal(f.reads().length, 1);
        f.window.emit('blur'); f.window.emit('focus'); await f.tick(); assert.equal(f.reads().length, 2);
        f.elapse(1001); f.window.emit('focus'); await f.tick(); assert.equal(f.reads().length, 3);
    } finally { f.destroy(); }
});

test('hidden documents cancel a queued return read and wait until visible again', async () => {
    const f = fixture();
    try {
        f.window.emit('pageshow', { persisted: true }); f.hide(); f.window.emit('focus'); await f.tick();
        assert.equal(f.calls.length, 0); f.show(); await f.tick(); assert.equal(f.reads().length, 1);
    } finally { f.destroy(); }
});

test('a pre-navigation GET is retired so a late six-item response cannot overwrite the new empty Woo cart', async () => {
    const old = deferred(); let reads = 0;
    const f = fixture(() => ++reads === 1 ? old.promise : response(cart(0)));
    try {
        const stale = f.client.refresh(); assert.equal(f.reads().length, 1);
        f.window.emit('pagehide', { persisted: true });
        assert.equal(f.calls[0].options.signal.aborted, true);
        f.window.emit('pageshow', { persisted: true }); await f.tick();
        assert.equal(f.client.snapshot().cart.items_count, 0);
        old.resolve(response(cart(6))); await stale;
        assert.equal(f.client.snapshot().cart.items_count, 0); assert.equal(f.reads().length, 2); assert.equal(f.posts().length, 0);
    } finally { f.destroy(); }
});

test('a server-confirmed nonempty cart remains six on return; a return is never an instruction to clear it', async () => {
    const f = fixture();
    try {
        await f.client.refresh(); const before = f.client.snapshot().cart;
        f.hide(); assert.equal(f.client.snapshot().cart, before);
        f.show(); await f.tick();
        assert.equal(f.client.snapshot().cart.items_count, 6); assert.equal(f.client.snapshot().valid, true);
        assert.equal(f.posts().length, 0); assert.equal(f.states.some(state => state.cart?.items_count === 0), false);
    } finally { f.destroy(); }
});

for (const [name, failed] of [
    ['malformed', () => response({ items: [] })],
    ['unavailable', () => { throw Error('Isolated connection failure'); }],
]) test('a failed ' + name + ' return GET retains the previous cart and never fabricates an empty result', async () => {
    let reads = 0; const f = fixture(() => ++reads === 1 ? response(cart(6)) : failed());
    try {
        await f.client.refresh(); const before = f.client.snapshot().cart;
        f.hide(); f.show(); await f.tick();
        assert.equal(f.client.snapshot().cart, before); assert.equal(f.client.snapshot().valid, false);
        assert.equal(f.posts().length, 0); assert.equal(f.states.some(state => state.cart?.items_count === 0), false);
    } finally { f.destroy(); }
});

test('leaving during an owned POST does not cancel, replay or overwrite its single native operation', async () => {
    const post = deferred();
    const f = fixture((url, options) => options.method === 'POST' ? post.promise : response(cart(6)));
    try {
        await f.client.refresh(); const write = f.client.write('update-item', { key: 'sku-42', quantity: 7 });
        f.hide(); f.window.emit('pagehide', { persisted: true }); f.show(); f.window.emit('pageshow', { persisted: true }); await f.tick();
        assert.equal(f.posts().length, 1); assert.equal(f.posts()[0].options.signal.aborted, false);
        assert.equal(f.reads().length, 1); assert.equal(f.client.snapshot().pending, true);
        post.resolve(response(cart(7))); await write;
        assert.equal(f.client.snapshot().cart.items_count, 7); assert.equal(f.posts().length, 1);
    } finally { f.destroy(); }
});

test('a newer external mutation supersedes a pending return GET even if the HTTP peer ignores abort', async () => {
    const old = deferred(); const f = fixture(() => old.promise);
    try {
        f.window.emit('pageshow', { persisted: true }); await f.tick();
        f.client.externalUpdate({ cart: cart(9) }); old.resolve(response(cart(6))); await flush();
        assert.equal(f.client.snapshot().cart.items_count, 9); assert.equal(f.posts().length, 0);
    } finally { f.destroy(); }
});

test('disposing clears all lifecycle listeners and queued work', async () => {
    const f = fixture(); f.window.emit('pageshow', { persisted: true }); f.destroy();
    await f.tick(); f.window.emit('focus'); f.show(); await f.tick();
    assert.equal(f.document.count() + f.window.count(), 0); assert.equal(f.timers.size, 0); assert.equal(f.calls.length, 0);
});
