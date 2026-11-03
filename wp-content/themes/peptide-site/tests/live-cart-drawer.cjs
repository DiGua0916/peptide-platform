'use strict';
const assert = require('node:assert/strict');
const { createDrawerController, createNativeCheckoutBridge, createClient } = require('../assets/live-cart-experience.js');
let passed = 0;
function check(label, action) { action(); passed++; console.log('ok', label); }
function fixture() {
    const classes = () => { const set = new Set(); return { add: value => set.add(value), remove: value => set.delete(value), contains: value => set.has(value) }; };
    const events = [], timers = new Map(), frames = new Map(), handlers = new Map(); let id = 0, refreshed = 0, enabled = true;
    const page = { classList: classes(), style: { paddingRight: '10px' }, clientWidth: 375 };
    const trigger = { isConnected: true, attrs: {}, focused: 0, setAttribute(key, value) { this.attrs[key] = value; }, focus() { this.focused++; } };
    const closeButton = { focused: 0, focus() { this.focused++; } };
    const dialog = { id: 'vc-live-cart-drawer', open: false, classList: classes(),
        showModal() { this.open = true; }, close() { this.open = false; }, getBoundingClientRect() { return {}; },
        querySelector() { return closeButton; }, addEventListener(type, fn) { handlers.set(type, fn); }, removeEventListener(type) { handlers.delete(type); } };
    const document = { documentElement: page, dispatchEvent: event => events.push(event) };
    const motion = { matches: false };
    const window = { innerWidth: 390, matchMedia: () => motion, getComputedStyle: node => node.style,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout(fn) { const key = ++id; timers.set(key, fn); return key; }, clearTimeout: key => timers.delete(key),
        requestAnimationFrame(fn) { const key = ++id; frames.set(key, fn); return key; }, cancelAnimationFrame: key => frames.delete(key) };
    const controller = createDrawerController({ dialog, document, window, enabled: () => enabled, refresh: () => { refreshed++; } });
    const run = map => { const work = [...map.values()]; map.clear(); work.forEach(fn => fn()); };
    return { page, trigger, closeButton, dialog, document, window, motion, events, timers, frames, handlers, controller,
        enable: value => { enabled = value; }, frame: () => run(frames), finish: () => run(timers), refreshed: () => refreshed };
}
const f = fixture();
check('mobile opens the same dialog without a desktop condition', () => { assert.equal(f.controller.open(f.trigger), true); assert.equal(f.dialog.open, true); assert.equal(f.refreshed(), 1); });
check('open preserves scroll geometry and publishes the actual cart state', () => { assert.equal(f.page.style.paddingRight, '25px'); assert.equal(f.trigger.attrs['aria-expanded'], 'true'); assert.equal(f.closeButton.focused, 1); assert.equal(f.events.filter(e => e.type === 'vc:visitor-action')[0].detail.type, 'cart_open'); });
check('right-side motion begins on the next frame', () => { assert.equal(f.dialog.classList.contains('is-open'), false); f.frame(); assert.equal(f.dialog.classList.contains('is-open'), true); });
check('normal close keeps the native layer until the slide finishes', () => { f.controller.close(); assert.equal(f.dialog.open, true); assert.equal(f.dialog.classList.contains('is-open'), false); assert.equal(f.timers.size, 1); });
check('rapid reopen cancels the old closing task without duplicate open events', () => { f.controller.open(f.trigger); f.frame(); f.finish(); assert.equal(f.dialog.open, true); assert.equal(f.events.filter(e => e.type === 'vc:live-cart-opened').length, 1); });
check('browser Back can close immediately and restore its product trigger', () => { f.controller.close(true); assert.equal(f.dialog.open, false); assert.equal(f.page.style.paddingRight, '10px'); assert.equal(f.page.classList.contains('vc-live-cart-open'), false); assert.equal(f.trigger.focused, 1); assert.equal(f.trigger.attrs['aria-expanded'], 'false'); });
check('a late native close event cannot tear down a newly reopened drawer', () => { f.controller.open(f.trigger); f.handlers.get('close')(); assert.equal(f.page.classList.contains('vc-live-cart-open'), true); assert.equal(f.dialog.open, true); });
check('reduced motion closes synchronously', () => { f.motion.matches = true; f.controller.close(); assert.equal(f.dialog.open, false); assert.equal(f.timers.size, 0); });
check('disabled device presentation leaves the real link available', () => { f.enable(false); assert.equal(f.controller.available(), false); assert.equal(f.controller.open(f.trigger), false); assert.equal(f.dialog.open, false); });
check('native dialog failures leave a working fallback path', () => { f.enable(true); f.dialog.showModal = () => { throw new Error('unavailable'); }; assert.equal(f.controller.open(f.trigger), false); assert.equal(f.page.classList.contains('vc-live-cart-open'), false); });
check('dispose clears handlers, frames and timers', () => { f.controller.destroy(); assert.equal(f.handlers.size, 0); assert.equal(f.frames.size, 0); assert.equal(f.timers.size, 0); assert.equal(f.controller.available(), false); });

const cart = { items: [{ key: 'a', id: 4, quantity: 2 }], items_count: 2, totals: { total_price: '5500', currency_code: 'USD', currency_minor_unit: 2 }, shipping_address: { city: 'not-for-the-bridge' }, billing_address: { city: 'not-for-the-bridge' } };
let nativeBusy = false, processing = false, received = [], subscriptions = [];
const state = { isCustomerDataUpdating: () => nativeBusy, hasPendingItemsOperations: () => false };
const order = { isProcessing: () => processing, isCalculating: () => false };
const nativeWindow = { wc: { wcBlocksData: { cartStore: 'cart', checkoutStore: 'checkout' } }, wp: { data: {
    select: store => store === 'cart' ? state : order,
    dispatch: () => ({ receiveCartContents: value => received.push(value) }),
    subscribe: (fn, store) => { subscriptions.push(store); return () => { subscriptions = subscriptions.filter(value => value !== store); }; }
} } };
const bridge = createNativeCheckoutBridge(nativeWindow, true);
check('checkout requires the native store before intercepting its bag link', () => { assert.equal(bridge.ready(), true); assert.equal(createNativeCheckoutBridge({}, true).ready(), false); });
check('server-confirmed contents never overwrite typed billing or shipping addresses', () => { assert.equal(bridge.receive(cart), true); assert.equal('shipping_address' in received[0], false); assert.equal('billing_address' in received[0], false); assert.equal(received[0].items[0].quantity, 2); });
check('unchanged contents are not written back to the native store in a loop', () => { assert.equal(bridge.receive(cart), false); assert.equal(received.length, 1); });
check('native customer updates pause cart writes and mirroring', () => { nativeBusy = true; assert.equal(bridge.busy(), true); assert.equal(bridge.receive({ ...cart, items_count: 3 }), false); nativeBusy = false; });
check('payment processing is a separate no-open/no-write guard', () => { processing = true; assert.equal(bridge.processing(), true); assert.equal(bridge.busy(), true); processing = false; });
check('malformed or uncertain cart data is never sent to native checkout', () => { assert.equal(bridge.receive({ items: [] }), false); assert.equal(bridge.receive(null), false); });
check('normal pages do not depend on native Checkout Blocks', () => { const ordinary = createNativeCheckoutBridge({}, false); assert.equal(ordinary.ready(), true); assert.equal(ordinary.busy(), false); assert.equal(ordinary.receive(cart), false); });
check('native subscriptions are removable on page exit', () => { const stop = bridge.subscribe(() => {}); assert.deepEqual(subscriptions, ['cart', 'checkout']); stop(); assert.deepEqual(subscriptions, []); });

(async () => {
    let postCount = 0;
    const client = createClient({ cartApiUrl: '/cart' }, { canWrite: () => false, fetch: async (_, options) => { if (options.method === 'POST') postCount++; return { ok: true, headers: { get: () => 'native-nonce' }, json: async () => cart }; } });
    await client.refresh();
    await assert.rejects(client.write('update-item', { key: 'a', quantity: 3 }), /updating/);
    check('blocked native checkout never sends a cart mutation POST', () => assert.equal(postCount, 0));
    client.destroy(); console.log(`${passed} drawer/checkout bridge checks passed`);
})().catch(error => { console.error(error); process.exitCode = 1; });
