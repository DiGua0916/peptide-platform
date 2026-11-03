'use strict';
const assert = require('node:assert/strict');
const { createDrawerController, createNativeCheckoutBridge, createClient } = require('../assets/live-cart-experience.js');
let passed = 0;
function check(label, action) { action(); passed++; console.log('ok', label); }
function fixture({ legacyMotion = false } = {}) {
    const classes = () => { const set = new Set(); return { add: (...values) => values.forEach(value => set.add(value)), remove: (...values) => values.forEach(value => set.delete(value)), contains: value => set.has(value) }; };
    const events = [], timers = new Map(), durations = new Map(), frames = new Map(), handlers = new Map(), documentHandlers = new Map(), windowHandlers = new Map(), motionHandlers = new Map(); let id = 0, refreshed = 0, enabled = true;
    const listeners = map => ({ addEventListener(type, fn) { map.set(type, fn); }, removeEventListener(type, fn) { if (map.get(type) === fn) map.delete(type); } });
    const page = { classList: classes(), style: { paddingRight: '10px' }, clientWidth: 375 };
    const trigger = { isConnected: true, attrs: {}, focused: 0, setAttribute(key, value) { this.attrs[key] = value; }, focus() { this.focused++; } };
    const closeButton = { focused: 0, focus() { this.focused++; } };
    const dialog = { id: 'vc-live-cart-drawer', open: false, inert: false, classList: classes(),
        showModal() { this.open = true; }, close() { this.open = false; }, getBoundingClientRect() { return {}; },
        querySelector() { return closeButton; }, addEventListener(type, fn) { handlers.set(type, fn); }, removeEventListener(type) { handlers.delete(type); } };
    const document = { ...listeners(documentHandlers), documentElement: page, hidden: false, dispatchEvent: event => events.push(event) };
    const motion = legacyMotion ? { matches: false, addListener(fn) { motionHandlers.set('change', fn); }, removeListener(fn) { if (motionHandlers.get('change') === fn) motionHandlers.delete('change'); } } : { ...listeners(motionHandlers), matches: false };
    const window = { ...listeners(windowHandlers), innerWidth: 390, matchMedia: () => motion, getComputedStyle: node => node.style,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout(fn, duration) { const key = ++id; timers.set(key, fn); durations.set(key, duration); return key; }, clearTimeout: key => { timers.delete(key); durations.delete(key); },
        requestAnimationFrame(fn) { const key = ++id; frames.set(key, fn); return key; }, cancelAnimationFrame: key => frames.delete(key) };
    const controller = createDrawerController({ dialog, document, window, enabled: () => enabled, refresh: () => { refreshed++; } });
    const run = map => { const work = [...map.values()]; map.clear(); work.forEach(fn => fn()); };
    return { page, trigger, closeButton, dialog, document, window, motion, events, timers, durations, frames, handlers, documentHandlers, windowHandlers, motionHandlers, controller,
        enable: value => { enabled = value; }, frame: () => run(frames), finish: () => run(timers), refreshed: () => refreshed };
}
const f = fixture();
check('mobile opens the same dialog without a desktop condition', () => { assert.equal(f.controller.open(f.trigger), true); assert.equal(f.dialog.open, true); assert.equal(f.refreshed(), 1); });
check('open preserves scroll geometry and publishes the actual cart state', () => { assert.equal(f.page.style.paddingRight, '25px'); assert.equal(f.trigger.attrs['aria-expanded'], 'true'); assert.equal(f.closeButton.focused, 1); assert.equal(f.events.filter(e => e.type === 'vc:visitor-action')[0].detail.type, 'cart_open'); });
check('right-side motion begins on the next frame', () => { assert.equal(f.dialog.classList.contains('is-open'), false); f.frame(); assert.equal(f.dialog.classList.contains('is-open'), true); });
check('normal close keeps the native layer but blocks fading controls until the shorter exit ends', () => { f.controller.close(); assert.equal(f.dialog.open, true); assert.equal(f.dialog.inert, true); assert.equal(f.dialog.classList.contains('is-closing'), true); assert.equal(f.dialog.classList.contains('is-open'), false); assert.equal(f.timers.size, 1); assert.deepEqual([...f.durations.values()], [200]); });
check('rapid reopen cancels the old closing task without duplicate open events', () => { f.controller.open(f.trigger); f.frame(); f.finish(); assert.equal(f.dialog.open, true); assert.equal(f.dialog.inert, false); assert.equal(f.dialog.classList.contains('is-closing'), false); assert.equal(f.events.filter(e => e.type === 'vc:live-cart-opened').length, 1); });
check('browser Back can close immediately and restore its product trigger', () => { f.controller.close(true); assert.equal(f.dialog.open, false); assert.equal(f.page.style.paddingRight, '10px'); assert.equal(f.page.classList.contains('vc-live-cart-open'), false); assert.equal(f.trigger.focused, 1); assert.equal(f.trigger.attrs['aria-expanded'], 'false'); });
check('a late native close event cannot tear down a newly reopened drawer', () => { f.controller.open(f.trigger); f.handlers.get('close')(); assert.equal(f.page.classList.contains('vc-live-cart-open'), true); assert.equal(f.dialog.open, true); });
check('reduced motion closes synchronously', () => { f.motion.matches = true; f.controller.close(); assert.equal(f.dialog.open, false); assert.equal(f.timers.size, 0); });
check('disabled device presentation leaves the real link available', () => { f.enable(false); assert.equal(f.controller.available(), false); assert.equal(f.controller.open(f.trigger), false); assert.equal(f.dialog.open, false); });
check('native dialog failures leave a working fallback path', () => { f.enable(true); f.dialog.showModal = () => { throw new Error('unavailable'); }; assert.equal(f.controller.open(f.trigger), false); assert.equal(f.page.classList.contains('vc-live-cart-open'), false); });
check('dispose clears all dialog, lifecycle and preference handlers, frames and timers', () => { f.controller.destroy(); assert.equal(f.handlers.size, 0); assert.equal(f.documentHandlers.size, 0); assert.equal(f.windowHandlers.size, 0); assert.equal(f.motionHandlers.size, 0); assert.equal(f.frames.size, 0); assert.equal(f.timers.size, 0); assert.equal(f.controller.available(), false); });

check('repeated Escape/backdrop close does not restart the exit or duplicate close events', () => {
    const view = fixture(); view.controller.open(view.trigger); view.frame(); view.controller.close();
    const ticket = [...view.timers.keys()][0]; view.controller.close(); view.controller.close();
    assert.deepEqual([...view.timers.keys()], [ticket]); view.finish();
    assert.equal(view.dialog.open, false); assert.equal(view.dialog.inert, false); assert.equal(view.dialog.classList.contains('is-closing'), false);
    view.handlers.get('close')(); assert.equal(view.events.filter(event => event.type === 'vc:live-cart-closed').length, 1); view.controller.destroy();
});
check('stale exit and entry callbacks cannot tear down or repaint a newer reversal', () => {
    const view = fixture(); view.controller.open(view.trigger); const oldFrame = [...view.frames.values()][0];
    view.controller.close(); const oldExit = [...view.timers.values()][0];
    view.controller.open(view.trigger); view.frame(); view.controller.close();
    oldFrame(); oldExit(); assert.equal(view.dialog.open, true); assert.equal(view.dialog.classList.contains('is-open'), false); assert.equal(view.dialog.inert, true);
    view.finish(); assert.equal(view.dialog.open, false); view.controller.destroy();
});
check('browser Back during an animated exit closes immediately without a trailing close task', () => {
    const view = fixture(); view.controller.open(view.trigger); view.frame(); view.controller.close(); view.controller.close(true);
    assert.equal(view.dialog.open, false); assert.equal(view.dialog.inert, false); assert.equal(view.timers.size, 0); assert.equal(view.trigger.focused, 1);
    assert.equal(view.events.filter(event => event.type === 'vc:live-cart-closed').length, 1); view.controller.destroy();
});
check('reduced motion opens synchronously with no queued frame or timer', () => {
    const view = fixture(); view.motion.matches = true; view.controller.open(view.trigger);
    assert.equal(view.dialog.classList.contains('is-open'), true); assert.equal(view.frames.size, 0);
    view.controller.close(); assert.equal(view.dialog.open, false); assert.equal(view.timers.size, 0); assert.equal(view.dialog.inert, false); view.controller.destroy();
});
check('a new reduced-motion preference settles opening and closes an in-flight exit with focus restored', () => {
    const view = fixture(); view.controller.open(view.trigger); view.motion.matches = true; view.motionHandlers.get('change')();
    assert.equal(view.frames.size, 0); assert.equal(view.dialog.classList.contains('is-open'), true);
    view.motion.matches = false; view.controller.close(); view.motion.matches = true; view.motionHandlers.get('change')();
    assert.equal(view.timers.size, 0); assert.equal(view.dialog.open, false); assert.equal(view.trigger.focused, 1); view.controller.destroy();
});
check('backgrounding settles the current state without delayed animation or hidden focus restoration', () => {
    const view = fixture(); view.controller.open(view.trigger); view.document.hidden = true; view.documentHandlers.get('visibilitychange')();
    assert.equal(view.frames.size, 0); assert.equal(view.dialog.open, true); assert.equal(view.dialog.classList.contains('is-open'), true);
    view.document.hidden = false; view.controller.close(); view.document.hidden = true; view.documentHandlers.get('visibilitychange')();
    assert.equal(view.timers.size, 0); assert.equal(view.dialog.open, false); assert.equal(view.dialog.inert, false); assert.equal(view.trigger.focused, 0); view.controller.destroy();
});
check('BFCache pagehide settles an open drawer but preserves the native layer for history restoration', () => {
    const view = fixture(); view.controller.open(view.trigger); view.windowHandlers.get('pagehide')({ persisted: true });
    assert.equal(view.frames.size, 0); assert.equal(view.dialog.open, true); assert.equal(view.dialog.classList.contains('is-open'), true);
    assert.equal(view.controller.available(), true); assert.equal(view.trigger.focused, 0);
    view.controller.close(); view.windowHandlers.get('pagehide')({ persisted: true });
    assert.equal(view.timers.size, 0); assert.equal(view.dialog.open, false); assert.equal(view.trigger.focused, 0); view.controller.destroy();
});
check('a changed opener clears the old aria state and restores only the current trigger', () => {
    const view = fixture(), other = { ...view.trigger, attrs: {}, focused: 0 };
    view.controller.open(view.trigger); view.frame(); view.controller.open(other); view.controller.close(true);
    assert.equal(view.trigger.attrs['aria-expanded'], 'false'); assert.equal(view.trigger.focused, 0);
    assert.equal(other.attrs['aria-expanded'], 'false'); assert.equal(other.focused, 1); view.controller.destroy();
});
check('an external native close clears the exit lock and does not leave the page or drawer inert', () => {
    const view = fixture(); view.controller.open(view.trigger); view.frame(); view.controller.close(); view.dialog.close(); view.handlers.get('close')();
    assert.equal(view.dialog.inert, false); assert.equal(view.timers.size, 0); assert.equal(view.page.style.paddingRight, '10px');
    assert.equal(view.page.classList.contains('vc-live-cart-open'), false); view.controller.destroy();
});
check('legacy media-query listeners settle motion and are removed on disposal', () => {
    const view = fixture({ legacyMotion: true }); view.controller.open(view.trigger); view.motion.matches = true; view.motionHandlers.get('change')();
    assert.equal(view.frames.size, 0); assert.equal(view.dialog.classList.contains('is-open'), true);
    view.controller.destroy(); assert.equal(view.motionHandlers.size, 0); assert.equal(view.dialog.inert, false);
});
check('disposal during an exit clears interaction locks and all lifecycle work without restoring hidden focus', () => {
    const view = fixture(); view.controller.open(view.trigger); view.frame(); view.controller.close(); const oldExit = [...view.timers.values()][0]; view.controller.destroy(); oldExit();
    assert.equal(view.dialog.open, false); assert.equal(view.dialog.inert, false); assert.equal(view.timers.size, 0); assert.equal(view.frames.size, 0);
    assert.equal(view.documentHandlers.size + view.windowHandlers.size + view.motionHandlers.size + view.handlers.size, 0); assert.equal(view.trigger.focused, 0);
    assert.equal(view.events.filter(event => event.type === 'vc:live-cart-closed').length, 0);
});

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
