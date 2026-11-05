'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/live-cart-experience.js'), 'utf8');
const { cartSettingsForDevice, createCartDeviceSettings } = require('../assets/live-cart-experience.js');
const flush = async () => { for (let i = 0; i < 24; i++) await Promise.resolve(); };
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const device = overrides => ({ enabled: true, cart_experience_enabled: true, cart_progress_enabled: true, cart_recommendations_enabled: true, cart_recent_enabled: true, cart_title: 'Desktop cart', cart_continue_label: 'Keep exploring', visibility: { desktop: true, mobile: true }, ...overrides });

test('legacy settings retain visibility semantics; independent devices never consume the aggregate enabled flag', () => {
    assert.equal(cartSettingsForDevice({ visibility: { mobile: false } }, 'mobile').enabled, false);
    assert.equal(cartSettingsForDevice({ cart_progress_enabled: false }, 'mobile').enabled, true);
    const config = { enabled: true, cart_experience_enabled: true, devices: { desktop: device({ enabled: false }), mobile: device({ cart_progress_enabled: false, cart_title: '', cart_continue_label: '' }) } };
    assert.equal(cartSettingsForDevice(config, 'desktop').enabled, false);
    assert.equal(cartSettingsForDevice(config, 'mobile').enabled, true);
    assert.equal(cartSettingsForDevice(config, 'mobile').cart_progress_enabled, false);
    assert.equal(cartSettingsForDevice(config, 'mobile').cart_title, '');
    assert.equal(cartSettingsForDevice(config, 'mobile').cart_continue_label, '');
    assert.equal(cartSettingsForDevice({ devices: { desktop: device() } }, 'mobile').enabled, false);
    assert.equal(cartSettingsForDevice({ devices: { mobile: device({ visibility: { mobile: false } }) } }, 'mobile').enabled, false);
});

test('media lifecycle changes once per breakpoint, supports legacy listeners and disposes without side effects', () => {
    const listeners = new Set(), received = [], media = { matches: true, addListener: fn => listeners.add(fn), removeListener: fn => listeners.delete(fn) };
    const controller = createCartDeviceSettings({ settings: { devices: { desktop: device(), mobile: device({ cart_title: 'Mobile cart' }) } }, media, onChange: value => received.push(value.cart_title) });
    const change = [...listeners][0]; change(); assert.equal(received.length, 0);
    media.matches = false; change(); change(); assert.deepEqual(received, ['Mobile cart']);
    media.matches = true; change(); assert.deepEqual(received, ['Mobile cart', 'Desktop cart']);
    controller.destroy(); controller.destroy(); media.matches = false; change();
    assert.equal(listeners.size, 0); assert.equal(received.length, 2);
});

// This adapter runs the complete production browser controller with its real
// media listener, DOM updates and read scheduling. Only the native HTTP peer,
// visual child widgets and platform DOM are isolated; no real cart is touched.
class Target {
    constructor() { this.listeners = new Map(); }
    addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name).add(fn); }
    removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
    emit(name, event = {}) { for (const fn of [...(this.listeners.get(name) || [])]) fn(event); }
}
class Node extends Target {
    constructor(tag = 'div') {
        super(); this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.attrs = {}; this.hidden = false; this.inert = false; this.isConnected = true; this.textContent = ''; this.className = ''; this.lookups = new Map();
        const classes = new Set(), styles = new Map();
        this.classList = { add: (...values) => values.forEach(value => classes.add(value)), remove: (...values) => values.forEach(value => classes.delete(value)), contains: value => classes.has(value), toggle: (value, force) => { const next = force === undefined ? !classes.has(value) : force; if (next) classes.add(value); else classes.delete(value); return next; } };
        this.style = { paddingRight: '', setProperty: (key, value) => styles.set(key, value), getPropertyValue: key => styles.get(key) || '', removeProperty: key => styles.delete(key) };
    }
    set innerHTML(value) { this.value = String(value); }
    setAttribute(key, value) { this.attrs[key] = String(value); }
    getAttribute(key) { return this.attrs[key] ?? null; }
    removeAttribute(key) { delete this.attrs[key]; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    querySelector(selector) { return this.lookups.get(selector) || null; }
    querySelectorAll(selector) { if (selector === 'button') return this.children.filter(node => node.tagName === 'BUTTON'); return []; }
    closest(selector) { return selector === 'dialog' ? this.dialog || null : null; }
    matches(selector) { return selector === '[data-vc-native-cart-fallback]' && !!this.fallback; }
    contains() { return false; }
    cloneNode() { const next = new Node(this.tagName); next.dataset = { ...this.dataset }; next.className = this.className; next.href = this.href; return next; }
    getBoundingClientRect() { return { top: 0, bottom: 100, height: 100 }; }
    focus() { this.focused = (this.focused || 0) + 1; }
    remove() { this.isConnected = false; }
}
function fixture({ desktop = true, drawer = false, nativeCheckout = false, nativeBusy = false, pluginEnabled = true, desktopSettings = {}, mobileSettings = {}, legacy = null } = {}) {
    const root = new Node(), document = new Target(), window = new Target(), media = new Target(), motion = new Target();
    media.matches = desktop; motion.matches = true;
    const page = new Node('html'); page.clientWidth = 390;
    const fallback = new Node(); fallback.fallback = true; root.nextElementSibling = fallback;
    const heading = new Node('h2'), emptyLink = new Node('a'), continueLink = new Node('a'), recentHeading = new Node('h2');
    const selectors = ['[data-cart-progress]', '[data-cart-recommendation]', '[data-cart-items]', '[data-cart-status]', '[data-cart-retry]', '[data-cart-empty]', '[data-cart-summary]', '[data-cart-totals]', '[data-cart-coupon]', '[data-cart-coupons]', '[data-cart-checkout]', '[data-cart-recent]', '[data-cart-recent-items]'];
    for (const selector of selectors) root.lookups.set(selector, new Node());
    root.dataset.drawer = drawer ? '1' : '0'; root.hidden = !drawer;
    root.lookups.set('.vc-live-cart__heading h1,.vc-live-cart__heading h2', heading);
    root.lookups.set('[data-cart-empty] a', emptyLink); root.lookups.set('.vc-live-cart__continue', continueLink);
    root.querySelector('[data-cart-recent]').lookups.set('h2', recentHeading);
    continueLink.textContent = drawer ? 'View full bag' : 'Keep exploring';
    const dialog = drawer ? new Node('dialog') : null;
    if (dialog) { root.dialog = dialog; dialog.id = 'vc-live-cart-drawer'; dialog.open = false; dialog.showModal = () => { dialog.open = true; }; dialog.close = () => { dialog.open = false; dialog.emit('close'); }; dialog.lookups.set('[data-cart-close]', new Node('button')); }
    document.documentElement = page; document.body = new Node('body'); document.scrollingElement = new Node(); document.hidden = false; document.activeElement = document.body;
    document.createElement = tag => new Node(tag); document.getElementById = () => dialog;
    document.querySelectorAll = selector => selector === '[data-vc-live-cart]' ? [root] : [];
    document.dispatchEvent = event => { document.emit(event.type, event); return true; };
    window.document = document; window.innerWidth = 390; window.innerHeight = 800;
    window.matchMedia = query => query.includes('min-width') ? media : motion;
    window.getComputedStyle = () => ({ paddingRight: '0' });
    window.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options?.detail; } };
    const timers = new Map(), intervals = new Map(); let serial = 0, postCount = 0, cartReads = 0, busy = nativeBusy, processing = false;
    window.setTimeout = (fn, ms) => { timers.set(++serial, { fn, ms }); return serial; }; window.clearTimeout = id => timers.delete(id);
    window.requestAnimationFrame = fn => window.setTimeout(fn, 0); window.cancelAnimationFrame = window.clearTimeout;
    window.setInterval = (fn, ms) => { intervals.set(++serial, { fn, ms }); return serial; }; window.clearInterval = id => intervals.delete(id);
    const histories = [], quotes = [], progress = [], recommendations = [], reads = [], progressMount = root.querySelector('[data-cart-progress]'), quoteMount = root.querySelector('[data-cart-recommendation]');
    let progressFactories = 0, recommendationFactories = 0;
    const activity = { enabled: pluginEnabled, visible: pluginEnabled, stateKey: 'one-cart', cartSnapshotKey: 'one-cart', revision: 1, promotionRevision: 'one-promo', next: { key: 'next' } };
    const cart = { items: [], items_count: 0, totals: { total_price: '0', total_items: '0', currency_code: 'USD', currency_minor_unit: 2 }, extensions: { peptide_core: { activity_listener: activity } } };
    window.createCartProgress = () => { progressFactories++; return { update: value => { progress.push(value); progressMount.hidden = !value; }, pause() {}, destroy() {} }; };
    window.createCartRecommendation = () => { recommendationFactories++; return { update: value => { recommendations.push(value); quoteMount.hidden = !value.activity?.recommendation; }, destroy() {} }; };
    window.vcRecommendationContract = { quotedRecommendation: value => value?.visible ? value : null, quoteRequest: () => ({}), addPayload: () => ({ id: 14, quantity: 1 }) };
    window.vcLiveCommerce = { activity: { config: { enabled: pluginEnabled, revision: 1 } } };
    window.VialCoveLiveCartConfig = { settings: legacy || { enabled: true, devices: { desktop: device(desktopSettings), mobile: device({ cart_title: 'Mobile cart', cart_continue_label: 'Mobile exploring', ...mobileSettings }) } }, nativeCheckout, cartApiUrl: '/native-cart', recentItemsUrl: '/recent', recommendationsUrl: '/recommendation', recentProductApiUrl: '/card', productsApiUrl: '/products/', activityConfigUrl: '/activity', cartUrl: '/cart/', shopUrl: '/shop/' };
    window.fetch = async (url, options) => {
        reads.push({ url, method: options.method });
        if (url.startsWith('/native-cart/') && options.method === 'POST') postCount++;
        let data;
        if (url === '/native-cart') { cartReads++; data = cart; }
        else if (url === '/recent') { const pending = deferred(); histories.push(pending); data = await pending.promise; }
        else if (url === '/recommendation') { const pending = deferred(); quotes.push(pending); data = await pending.promise; }
        else if (url === '/activity') data = { enabled: pluginEnabled, revision: 1 };
        else if (url.includes('/card/')) data = null;
        else throw Error('Unexpected request ' + url);
        return { ok: true, status: 200, headers: { get: () => 'isolated-native-nonce' }, json: async () => data };
    };
    const nativeSubscriptions = new Set();
    if (nativeCheckout) window.wc = { wcBlocksData: { cartStore: 'native-cart', checkoutStore: 'native-checkout' } };
    if (nativeCheckout) window.wp = { data: { select: key => key === 'native-cart' ? { isCustomerDataUpdating: () => busy, hasPendingItemsOperations: () => false } : { isProcessing: () => processing, isCalculating: () => false }, dispatch: () => ({ receiveCartContents() {} }), subscribe: callback => { nativeSubscriptions.add(callback); return () => nativeSubscriptions.delete(callback); } } };
    const location = { href: 'https://shop.test/', origin: 'https://shop.test', assign() {} }; window.location = location;
    vm.runInNewContext(source, { window, location, URL, AbortController, CustomEvent: window.CustomEvent, setTimeout: window.setTimeout, clearTimeout: window.clearTimeout, setInterval: window.setInterval, clearInterval: window.clearInterval });
    const history = (name = 'Product') => ({ visible: true, title: 'Recently viewed', items: [{ productId: 12, variationId: 14, variationLabel: '20 mg × 10 vials', name, permalink: '/product/', price: { minor: '5500', text: '$55.00' } }], candidates: [{ productId: 12, variationId: 14 }] });
    return { root, heading, emptyLink, continueLink, media, document, window, dialog, fallback, histories, quotes, progress, recommendations, history, timers, reads,
        factories: () => [progressFactories, recommendationFactories], writes: () => postCount, cartReads: () => cartReads,
        async tick() { const tasks = [...timers.entries()].filter(([, task]) => task.ms === 250); tasks.forEach(([id]) => timers.delete(id)); tasks.forEach(([, task]) => task.fn()); await flush(); },
        async change(desktop) { media.matches = desktop; media.emit('change'); await flush(); },
        setBusy(value) { busy = value; nativeSubscriptions.forEach(fn => fn()); }, setProcessing(value) { processing = value; },
        dispose() { window.emit('pagehide', { persisted: false }); },
    };
}

test('the live browser controller switches copy and all presentation gates without remounting its native client/widgets', async () => {
    const f = fixture({ mobileSettings: { cart_progress_enabled: false, cart_recommendations_enabled: false, cart_recent_enabled: false, cart_continue_label: '' } });
    await flush(); await f.tick(); f.histories[0].resolve(f.history()); await flush();
    f.quotes[0].resolve({ recommendation: { visible: true, id: 'desktop-quote' } }); await flush();
    assert.equal(f.heading.textContent, 'Desktop cart'); assert.equal(f.root.querySelector('[data-cart-recent]').hidden, false);
    assert.equal(f.root.querySelector('[data-cart-recommendation]').hidden, false);
    await f.change(false);
    assert.equal(f.heading.textContent, 'Mobile cart'); assert.equal(f.emptyLink.textContent, ''); assert.equal(f.continueLink.textContent, '');
    assert.equal(f.root.querySelector('[data-cart-progress]').hidden, true); assert.equal(f.root.querySelector('[data-cart-recent]').hidden, true); assert.equal(f.root.querySelector('[data-cart-recommendation]').hidden, true);
    assert.deepEqual(f.factories(), [1, 1]); assert.equal(f.writes(), 0);
    await f.change(true); assert.equal(f.heading.textContent, 'Desktop cart'); assert.equal(f.continueLink.textContent, 'Keep exploring');
    assert.equal(f.progress.at(-1)?.enabled, true); assert.deepEqual(f.factories(), [1, 1]); f.dispose();
});

test('desktop-off/mobile-on uses the mobile instance, restores native fallback when disabled and retains a working drawer link', async () => {
    const f = fixture({ desktopSettings: { enabled: false, cart_experience_enabled: false } });
    await flush(); assert.equal(f.cartReads(), 0); assert.equal(f.root.hidden, true); assert.equal(f.window.VialCoveLiveCart.canOpen(), false);
    await f.change(false); assert.equal(f.root.hidden, false); assert.equal(f.fallback.hidden, true); assert.equal(f.cartReads(), 1);
    await f.change(true); assert.equal(f.root.hidden, true); assert.equal(f.fallback.hidden, false); assert.equal(f.writes(), 0); f.dispose();
});

test('disabling the current device closes an open drawer once and never changes its independent full-cart link', async () => {
    const f = fixture({ drawer: true, mobileSettings: { enabled: false, cart_experience_enabled: false } });
    const trigger = new Node('a'); assert.equal(f.window.VialCoveLiveCart.open(trigger), true); await flush();
    assert.equal(f.dialog.open, true); assert.equal(f.continueLink.textContent, 'View full bag');
    await f.change(false); assert.equal(f.dialog.open, false); assert.equal(f.window.VialCoveLiveCart.canOpen(), false);
    assert.equal(f.document.documentElement.classList.contains('vc-live-cart-open'), false); assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(f.continueLink.textContent, 'View full bag'); assert.equal(f.writes(), 0); f.dispose();
});

test('a late desktop history cannot reintroduce recent cards or create a quote after switching to mobile', async () => {
    const f = fixture(); await flush(); await f.tick(); const oldHistory = f.histories[0];
    await f.change(false); await f.tick(); assert.equal(f.histories.length, 2);
    oldHistory.resolve(f.history('Old desktop product')); await flush();
    assert.equal(f.quotes.length, 0); assert.equal(f.root.querySelector('[data-cart-recent]').hidden, true);
    f.histories[1].resolve(f.history('Current mobile product')); await flush();
    assert.equal(f.quotes.length, 1); assert.equal(f.root.querySelector('[data-cart-recent]').hidden, false); f.dispose();
});

test('a late desktop quote is retired even when the native cart identity is unchanged across devices', async () => {
    const f = fixture(); await flush(); await f.tick(); f.histories[0].resolve(f.history()); await flush(); const oldQuote = f.quotes[0];
    await f.change(false); oldQuote.resolve({ recommendation: { visible: true, id: 'old-device' } }); await flush();
    assert.equal(f.root.querySelector('[data-cart-recommendation]').hidden, true);
    assert.equal(f.recommendations.at(-1).activity?.recommendation, undefined);
    await f.tick(); f.histories[1].resolve(f.history()); await flush();
    f.quotes[1].resolve({ recommendation: { visible: true, id: 'new-device' } }); await flush();
    assert.equal(f.recommendations.at(-1).activity.recommendation.id, 'new-device'); assert.equal(f.writes(), 0); f.dispose();
});

test('BFCache pageshow rechecks the same media query even if its change event was suspended', async () => {
    const f = fixture(); await flush();
    f.window.emit('pagehide', { persisted: true });
    f.media.matches = false; f.window.emit('pageshow', { persisted: true }); await flush();
    assert.equal(f.heading.textContent, 'Mobile cart');
    assert.equal(f.media.listeners.get('change').size, 1); assert.deepEqual(f.factories(), [1, 1]); assert.equal(f.writes(), 0); f.dispose();
});

test('device presentation cannot enable a disabled native activity application', async () => {
    const f = fixture({ pluginEnabled: false }); await flush(); await f.tick(); f.histories[0].resolve(f.history()); await flush(); await f.change(false); await f.tick(); f.histories[1].resolve(f.history()); await flush();
    assert.equal(f.quotes.length, 0); assert.equal(f.root.querySelector('[data-cart-progress]').hidden, true); assert.equal(f.root.querySelector('[data-cart-recommendation]').hidden, true); f.dispose();
});

test('native checkout busy/processing protection and one media listener survive repeated breakpoint changes', async () => {
    const f = fixture({ nativeCheckout: true, nativeBusy: true }); await flush(); await f.change(false); await f.change(true);
    await assert.rejects(f.window.VialCoveLiveCart.addOne({ productId: 12, purchaseId: 14, variationId: 14, canAddOne: true }), /updating/);
    assert.equal(f.writes(), 0); assert.equal(f.media.listeners.get('change').size, 1);
    f.setProcessing(true); assert.equal(f.window.VialCoveLiveCart.canOpen(), false);
    f.dispose(); assert.equal(f.media.listeners.get('change').size, 0);
    await f.change(false); assert.equal(f.writes(), 0); assert.deepEqual(f.factories(), [1, 1]);
});
