'use strict';
// Exercise the production IIFE through its native history events. No Woo session,
// endpoint, browser storage or source rewriting is involved.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const filename = path.resolve(__dirname, '../assets/live-commerce-navigation.js');
const source = fs.readFileSync(filename, 'utf8');
const key = '__vcCommerceLayers';
class Events {
    constructor() { this.handlers = new Map(); }
    addEventListener(type, handler) { if (!this.handlers.has(type)) this.handlers.set(type, new Set()); this.handlers.get(type).add(handler); }
    removeEventListener(type, handler) { this.handlers.get(type)?.delete(handler); }
    dispatchEvent(event) { for (const handler of [...(this.handlers.get(event.type) || [])]) handler(event); return true; }
}
function fixture({ jquery = true, value = '10mg', quantity = '3', deferPresent = false } = {}) {
    const window = new Events(), document = new Events(), timers = new Map(), frames = new Map();
    const location = { origin: 'http://vialcove.test', pathname: '/', href: 'http://vialcove.test/' };
    const calls = { replace: 0, push: 0, go: [], cancel: 0, closedCart: 0, activeTab: 'coa' };
    let nextId = 0, mutation;
    function control(initial, extra = {}) {
        let current = initial;
        const el = { ...extra, assignments: 0, nativeChanges: 0, jqueryChanges: 0,
            get value() { return current; }, set value(value) { this.assignments++; current = String(value); },
            dispatchEvent(event) { if (event.type === 'change') { this.nativeChanges++; changed(); } }
        };
        return el;
    }
    function changed() {
        // Woo's change listeners may rebuild variation content and select Details.
        calls.activeTab = 'details'; product.scrollTop = Math.max(0, product.scrollTop - 384);
        document.dispatchEvent({ type: 'change' });
    }
    const select = control(value, { name: 'attribute_specification', options: [{ value: '20mg' }, { value: '10mg' }] });
    const qty = control(quantity);
    const root = { dataset: { vcProductId: '11306' }, querySelectorAll: selector => selector === '.variations select' ? [select] : [], querySelector: selector => selector === 'input.qty' ? qty : null };
    const product = { open: false, scrollTop: 0, matches: selector => selector.includes('.vc-showcase-quick'), querySelector: selector => selector === '.vc-home-quick-product' ? root : null, close() { this.open = false; } };
    const cart = { id: 'vc-live-cart-drawer', open: false, scrollTop: 0, matches: selector => selector.includes('#vc-live-cart-drawer'), close() { this.open = false; calls.closedCart++; } };
    const dialogs = [cart, product];
    const signalPresented = (id = 11306, open = true) => {
        root.dataset.vcProductId = String(id); product.open = open;
        document.dispatchEvent({ type: 'vc:product-presented', detail: { root, productId: id, dialog: product } });
    };
    const opener = { dataset: { vcHomeProduct: '11306', vcQuickOpen: '11306' }, click() { calls.stagedBeforeClick=!!calls.stagedRestore; if (!deferPresent) signalPresented(); } };
    document.body = { append() {} };
    document.querySelectorAll = selector => selector.startsWith('dialog') ? dialogs : [];
    document.querySelector = selector => selector === 'dialog.vc-showcase-quick' ? product : selector.includes('[data-vc-home-product="11306"]') ? opener : null;
    document.addEventListener('vc:product-restore-cancel', () => { calls.cancel++; });
    const history = { state: null, replaceState(value) { this.state = value; calls.replace++; }, pushState(value) { this.state = value; calls.push++; }, go(delta) { calls.go.push(delta); } };
    const schedule = (map, fn) => { const id = ++nextId; map.set(id, fn); return id; };
    Object.assign(window, { history, MutationObserver: class { constructor(fn) { mutation=fn; } observe() {} }, VialCoveLiveCart: { close() { cart.close(); }, open() { cart.open = true; } } });
    window.VialCoveHomeQuickView={stageRestore(item){calls.stagedRestore=item;return()=>{calls.stagedRestore=null;calls.restoreReleased=(calls.restoreReleased||0)+1;};}};
    if (jquery) window.jQuery = el => ({ trigger(type) { if (type === 'change') { el.jqueryChanges++; changed(); } } });
    const globals = { window, document, history, location, MutationObserver: window.MutationObserver, URL,
        Event: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
        CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
        setTimeout: fn => schedule(timers, fn), clearTimeout: id => timers.delete(id), requestAnimationFrame: fn => schedule(frames, fn) };
    vm.runInNewContext(source, globals, { filename });
    // The ordinary empty-page reconciliation must finish before the test opens layers.
    for (const fn of [...timers.values()]) fn(); timers.clear();
    const entry = (attributes = { attribute_specification: '10mg' }, quantity = '3', scroll = 3694) => ({ kind: 'product', productId: 11306, attributes, quantity, scroll });
    const pop = layers => {
        history.state = { [key]: { version: 1, scope: location.origin + location.pathname, layers } };
        window.dispatchEvent({ type: 'popstate', state: history.state });
    };
    return { window, document, history, calls, product, cart, select, qty, root, frames, timers, entry, pop, signalPresented,
        libraryReport(url) {
            const dialog = {open:false,scrollTop:0,dataset:{vcHistoryReportOrigin:'library',vcHistoryReportUrl:url},matches:selector=>selector.includes('.vc-product-report-dialog')||selector.includes('.vc-product-child-dialog'),close(){this.open=false;}};
            dialogs.push(dialog);window.VialCoveReportLibraryViewer={open(value,trigger,scroll){calls.openedLibrary=value;dialog.open=true;dialog.scrollTop=scroll||0;return true;}};return dialog;
        },
        mutate(target) { mutation([{type:'attributes',target}]);const work=[...timers.values()];timers.clear();work.forEach(fn=>fn()); },
        frame() { const work = [...frames.values()]; frames.clear(); work.forEach(fn => fn()); } };
}
const settle = async () => { await Promise.resolve(); await Promise.resolve(); };
let passed = 0;
async function check(name, fn) { await fn(); passed++; console.log('ok', name); }
(async () => {
    await check('Back from Bag preserves existing COA selection without no-op field assignments or change events', async () => {
        const view = fixture(); view.product.open = true; view.product.scrollTop = 3694; view.cart.open = true;
        view.pop([view.entry()]); await settle(); view.frame();
        assert.equal(view.cart.open, false); assert.equal(view.calls.closedCart, 1); assert.equal(view.product.open, true);
        assert.equal(view.select.assignments + view.qty.assignments, 0);
        assert.equal(view.select.jqueryChanges + view.select.nativeChanges + view.qty.nativeChanges, 0);
        assert.equal(view.calls.activeTab, 'coa'); assert.equal(view.product.scrollTop, 3694);
        assert.equal(view.calls.cancel, 1); assert.equal(view.calls.push, 0); assert.deepEqual(view.calls.go, []);
    });
    for (const jquery of [true, false]) await check(`${jquery ? 'jQuery' : 'native'} restore still applies legitimate changed specification and quantity once`, async () => {
        const view = fixture({ jquery, value: '20mg', quantity: '1' });
        view.pop([view.entry()]); await settle(); view.frame();
        assert.equal(view.select.value, '10mg'); assert.equal(view.qty.value, '3');
        assert.equal(view.select.assignments, 1); assert.equal(view.qty.assignments, 1);
        assert.equal(view.calls.stagedBeforeClick,true); assert.equal(view.calls.stagedRestore,null); assert.equal(view.calls.restoreReleased,1);
        assert.equal(view.select.jqueryChanges, Number(jquery)); assert.equal(view.select.nativeChanges, Number(!jquery)); assert.equal(view.qty.nativeChanges, 1);
        assert.equal(view.product.scrollTop, 3694); assert.equal(view.product.open, true);
    });
    await check('invalid option or quantity in a history entry is not assigned or dispatched', async () => {
        const view = fixture({ value: '20mg', quantity: '1' });
        view.pop([view.entry({ attribute_specification: 'missing-option' }, 'not-a-number', 777)]); await settle(); view.frame();
        assert.equal(view.select.assignments + view.qty.assignments, 0); assert.equal(view.select.jqueryChanges + view.qty.nativeChanges, 0);
        assert.equal(view.select.value, '20mg'); assert.equal(view.qty.value, '1'); assert.equal(view.product.scrollTop, 777);
    });
    await check('unchanged specification with a changed quantity dispatches only the quantity event', async () => {
        const view = fixture({ value: '10mg', quantity: '1' });
        view.pop([view.entry()]); await settle(); view.frame();
        assert.equal(view.select.assignments, 0); assert.equal(view.select.jqueryChanges, 0); assert.equal(view.qty.assignments, 1); assert.equal(view.qty.nativeChanges, 1);
        assert.equal(view.product.scrollTop, 3694);
    });
    await check('stale async product completion cannot assign selection or schedule a scroll after a newer Back', async () => {
        const view = fixture({ value: '20mg', quantity: '1', deferPresent: true });
        view.pop([view.entry()]); view.pop([]); await settle();
        assert.equal(view.timers.size, 0, 'superseded presentation deadline must be retired immediately');
        assert.equal(view.document.handlers.get('vc:product-presented')?.size || 0, 0, 'superseded presentation listener must be retired immediately');
        assert.equal(view.calls.stagedRestore,null); assert.equal(view.calls.restoreReleased,1);
        view.signalPresented(11306, false); await settle();
        assert.equal(view.select.assignments + view.qty.assignments, 0); assert.equal(view.frames.size, 0); assert.equal(view.product.open, false);
        assert.equal(view.calls.cancel, 2); assert.deepEqual(view.calls.go, []);
    });
    await check('a queued older scroll restoration cannot overwrite a newer history state', async () => {
        const view = fixture(); view.product.open = true; view.product.scrollTop = 3694;
        view.pop([view.entry()]); await settle(); assert.equal(view.frames.size, 1);
        view.pop([]); await settle(); view.product.open = true; view.product.scrollTop = 17; view.frame();
        assert.equal(view.product.scrollTop, 17); assert.equal(view.select.assignments + view.qty.assignments, 0);
    });
    await check('a genuine missing-presenter deadline retires staged fields before a later ordinary open',async()=>{
        const view=fixture({deferPresent:true});view.pop([view.entry()]);assert.equal(view.calls.stagedBeforeClick,true);
        for(const fn of [...view.timers.values()])fn();await settle();
        assert.equal(view.calls.stagedRestore,null);assert.equal(view.calls.restoreReleased,1);assert.equal(view.document.handlers.get('vc:product-presented')?.size||0,0);assert.equal(view.select.assignments+view.qty.assignments,0);
    });
    await check('library report adds one layer and Back closes it without opening a product or changing caller state',async()=>{
        const view=fixture();const url='http://vialcove.test/wp-json/peptide/v1/report-library/7/file?download=0';const report=view.libraryReport(url);report.open=true;view.mutate(report);
        assert.equal(view.calls.push,1);assert.equal(view.history.state[key].layers[0].kind,'library-report');assert.equal(view.product.open,false);
        view.pop([]);await settle();assert.equal(report.open,false);assert.equal(view.product.open,false);assert.equal(view.select.assignments+view.qty.assignments,0);assert.equal(view.calls.openedLibrary,undefined);
    });
    await check('Forward restores the exact library resource through its own controller without product matching',async()=>{
        const view=fixture();const url='http://vialcove.test/?rest_route=%2Fpeptide%2Fv1%2Freport-library%2F7%2Ffile&download=0';const report=view.libraryReport(url);
        view.pop([{kind:'library-report',url,scroll:1337}]);await settle();view.frame();assert.equal(view.calls.openedLibrary,url);assert.equal(report.open,true);assert.equal(report.scrollTop,1337);assert.equal(view.product.open,false);assert.equal(view.calls.push,0);
    });
    await check('closing library report consumes exactly one history layer',async()=>{
        const view=fixture();const report=view.libraryReport('http://vialcove.test/wp-json/peptide/v1/report-library/7/file?download=0');report.open=true;view.mutate(report);report.close();view.mutate(report);assert.deepEqual(view.calls.go,[-1]);
    });
    console.log(`${passed} navigation restoration checks passed`);
})().catch(error => { console.error(error); process.exitCode = 1; });
