'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const contract = require('../assets/live-cart-recommendation.js');
const source = fs.readFileSync(path.join(__dirname, '../assets/live-cart-experience.js'), 'utf8');
const flush = async () => { for (let i = 0; i < 16; i++) await Promise.resolve(); };
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const money = amount => ({ minor: String(amount), text: '$' + (amount / 100).toFixed(2) });
test('the browser quote adapter exposes the same validator used for unexpired same-cart retention', () => {
    const window = {};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/live-cart-recommendation.js'), 'utf8'), { window, URL });
    assert.equal(typeof window.vcRecommendationContract.quotedRecommendation, 'function');
    assert.equal(window.vcRecommendationContract.quotedRecommendation(null, null, ''), null);
});
function fixture() {
    let now = Date.parse('2026-11-05T00:00:00Z'), serial = 0, allowed = true, readable = true;
    const timers = new Map(), module = { exports: {} }, histories = [], quotes = [], decorations = [], events = [], historyViews = [];
    vm.runInNewContext(source, { module, setTimeout: fn => { timers.set(++serial, fn); return serial; }, clearTimeout: id => timers.delete(id), URL, AbortController });
    const activity = { enabled: true, visible: true, status: 'tracking', basis: 'spend', completed: false, stateKey: 'native-state', cartSnapshotKey: 'native-cart', revision: 6, promotionRevision: 'native-promotion', currency: 'USD', currencyMinorUnit: 2, actualDiscount: money(0), next: { key: 'tier300', threshold: '30000', remaining: '2500' } };
    let state = { valid: true, pending: false, cart: { extensions: { peptide_core: { activity_listener: activity } } } };
    const quote = change => ({ version: 1, visible: true, reachesNextTier: true, targetNodeKey: 'tier300', cartStateKey: 'native-state', cartSnapshotKey: 'native-cart', promotionRevision: 'native-promotion', configRevision: 6, currency: 'USD', currencyMinorUnit: 2, expiresAt: new Date(now + 45000).toISOString(), productId: 12, variationId: 14, purchasableId: 14, quantity: 1, name: 'Product', specification: '20 mg × 10 vials', image: '', unitPrice: money(5500), linePrice: money(5500), projectedDiscount: money(2500), additionalSavings: money(2500), afterAdd: { calculationStatus: 'quoted', targetReached: true, remaining: '0', eligibleSubtotal: money(33000), promotionDiscount: money(2500), allDiscountsBefore: money(0), allDiscountsAfter: money(2500), incrementalSavings: money(2500) }, ...change });
    const controller = module.exports.createRecommendationEnrichment({
        getState: () => state, canRead: () => readable,
        allowed: value => allowed && value?.enabled === true && value?.visible === true,
        validQuote: (raw, value) => !!contract.quotedRecommendation(raw, value, value?.cartSnapshotKey, now),
        readHistory: () => { const item = deferred(); histories.push(item); return item.promise; },
        requestQuote: (value, candidates) => { const item = deferred(); quotes.push({ ...item, value, candidates }); return item.promise; },
        onHistory: (value, isCurrent) => { const item = deferred(); historyViews.push({ value, isCurrent, ...item }); return item.promise; },
        decorateQuote: (value, activity, isCurrent) => { const item = deferred(); decorations.push({ value, isCurrent, ...item }); return item.promise; },
        onChange: value => events.push(value),
    });
    return { controller, activity, quote, events, histories, quotes, decorations, historyViews, timers,
        latest: () => events.at(-1), schedule: force => controller.schedule(state, force),
        state: change => { state = { ...state, ...change }; },
        activityChange: change => { state = { ...state, cart: { extensions: { peptide_core: { activity_listener: { ...state.cart.extensions.peptide_core.activity_listener, ...change } } } } }; },
        allow: value => { allowed = value; }, readable: value => { readable = value; },
        clock: ms => { now += ms; },
        async run() { const jobs = [...timers.values()]; timers.clear(); jobs.forEach(fn => fn()); await flush(); },
        async history(candidates = [{ productId: 12, variationId: 14, viewCount: 2, lastViewedAt: null }]) { histories.at(-1).resolve({ visible: true, items: [], candidates }); await flush(); },
        async settle(raw) { quotes.at(-1).resolve({ recommendation: raw }); await flush(); },
    };
}

test('a repeated native snapshot does not cancel an identical in-flight history or quote read', async () => {
    const f = fixture(); f.schedule(); await f.run(); f.schedule(); assert.equal(f.histories.length, 1); assert.equal(f.timers.size, 0);
    await f.history(); f.schedule(); assert.equal(f.quotes.length, 1); await f.settle(f.quote()); assert.equal(f.latest().recommendation.visible, true); f.controller.destroy();
});

test('slow recent card decoration does not delay the native quote or its first visible result', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history();
    assert.equal(f.historyViews.length, 1); assert.equal(f.quotes.length, 1);
    const native = f.quote(); await f.settle(native); assert.equal(f.latest().recommendation, native); assert.equal(f.latest().pending, false);
    assert.equal(f.decorations.length, 1); f.controller.destroy();
});

test('a same-cart refresh preserves its unexpired confirmed quote while reading and on a temporary failure', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); const native = f.quote(); await f.settle(native);
    f.schedule(); assert.equal(f.latest().recommendation, native); assert.equal(f.latest().pending, true);
    await f.run(); f.histories.at(-1).reject(Error('temporary history failure')); await flush();
    assert.equal(f.latest().recommendation, native); assert.equal(f.latest().pending, false); f.controller.destroy();
});

test('quote read failure retains only a still-valid same-cart quote and never a later expired one', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); const native = f.quote(); await f.settle(native);
    f.schedule(); await f.run(); await f.history(); f.quotes.at(-1).reject(Error('temporary quote failure')); await flush();
    assert.equal(f.latest().recommendation, native);
    f.clock(46000); f.schedule(); assert.equal(f.latest().recommendation, null); await f.run(); f.histories.at(-1).reject(Error('offline')); await flush(); assert.equal(f.latest().recommendation, null); f.controller.destroy();
});

test('a changed native cart clears the old quote immediately and ignores every late response or decoration', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); const native = f.quote(); await f.settle(native);
    const decoration = f.decorations[0]; f.activityChange({ stateKey: 'new-state', cartSnapshotKey: 'new-cart' }); f.schedule();
    assert.equal(f.latest().recommendation, null); assert.equal(decoration.isCurrent(), false);
    decoration.resolve({ ...native, regularUnitPrice: money(7800) }); await flush(); assert.equal(f.latest().recommendation, null);
    await f.run(); await f.history(); await f.settle(native); assert.equal(f.latest().recommendation, null); f.controller.destroy();
});

test('pending writes cancel recommendations and late quote results cannot claim eligibility', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); f.state({ valid: false, pending: true }); f.schedule();
    assert.equal(f.latest().recommendation, null); assert.equal(f.latest().pending, false);
    await f.settle(f.quote()); assert.equal(f.latest().recommendation, null); f.controller.destroy();
});

test('explicit native hidden results and empty candidates remove the old quote instead of inventing a fallback', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); await f.settle(f.quote());
    f.schedule(); await f.run(); await f.history(); await f.settle({ visible: false, reason: 'no_eligible_candidate' }); assert.equal(f.latest().recommendation, null);
    f.schedule(); await f.run(); await f.history(); await f.settle(f.quote()); f.schedule(); await f.run(); await f.history([]);
    assert.equal(f.latest().recommendation, null); f.controller.destroy();
});

test('plugin disable, closed visibility and destruction cannot restore a completed or stale quote', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); await f.settle(f.quote());
    f.allow(false); f.schedule(); assert.equal(f.latest().recommendation, null); await f.run(); await f.history(); assert.equal(f.quotes.length, 1);
    f.allow(true); f.schedule(); await f.run(); await f.history(); const pending = f.quotes.at(-1); f.readable(false); f.schedule();
    const count = f.events.length; pending.resolve({ recommendation: f.quote() }); await flush(); assert.equal(f.events.length, count);
    f.controller.destroy(); f.controller.destroy(); assert.equal(f.timers.size, 0); f.schedule(); assert.equal(f.events.length, count);
});

test('forced browsing/config refresh replaces an in-flight read while an identical valid quote remains usable', async () => {
    const f = fixture(); f.schedule(); await f.run(); await f.history(); const native = f.quote(); await f.settle(native);
    f.schedule(); await f.run(); const previous = f.histories.at(-1); f.schedule(true); assert.equal(f.latest().recommendation, native);
    await f.run(); previous.resolve({ visible: true, items: [], candidates: [] }); await flush(); assert.equal(f.latest().recommendation, native);
    await f.history(); await f.settle(f.quote()); assert.equal(f.latest().recommendation.visible, true); f.controller.destroy();
});
