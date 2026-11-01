'use strict';
// Actual native source, in-memory platform boundaries only. No WP, network or DB.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, []); this.listeners.get(type).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners.get(event.type) || []) fn(event); return true; }
}
const json = value => JSON.parse(JSON.stringify(value));
const cart = (id = 101, quantity = 2) => ({items: id ? [{id, quantity}] : [], totals: {}, items_count: id ? quantity : 0});
function fixture(options = {}) {
  const document = new Target(), window = new Target(), calls = [], observations = [], navigation = [], order = [], timers = new Map();
  let serial = 0;
  document.body = {};
  document.querySelectorAll = () => [];
  const jq = () => ({on() {}, trigger(name) { order.push(name); }, find() { return this; }});
  jq.fn = {};
  const location = {href: 'https://native.test/product/item/', origin: 'https://native.test', assign(url) { navigation.push(url); order.push('navigate'); if (options.navThrow) throw Error('navigation denied'); }};
  const defaultApi = {pushProductAction(type, facts) { observations.push({type, facts: json(facts)}); order.push('observe'); if (options.dataThrow) throw Error('optional data failure'); return options.dataFalse ? false : facts.event_id; }};
  Object.assign(window, {window, document, jQuery: jq, location, URL, Uint8Array, AbortController,
    crypto: Object.hasOwn(options, 'crypto') ? options.crypto : webcrypto,
    VialCoveVisitorTransport: options.dataOff ? undefined : options.api || defaultApi,
    matchMedia: () => ({addEventListener() {}}),
    setTimeout: fn => { const id = ++serial; timers.set(id, fn); return id; }, clearTimeout: id => timers.delete(id),
    CustomEvent: class { constructor(type, init) { this.type = type; Object.assign(this, init); } },
    fetch: async (url, init) => {
      const call = {url, ...init, payload: init.body && JSON.parse(init.body)}; calls.push(call);
      const scenario = options.respond && options.respond(call, calls.length, f) || {};
      if (scenario.before) scenario.before();
      if (scenario.throw) throw Error('native response lost');
      const status = scenario.status || (init.method === 'POST' ? 201 : 200);
      return {status, ok: status >= 200 && status < 300, headers: {get: name => name === 'Nonce' ? 'synthetic-native-nonce' : null}, json: async () => scenario.data || (init.method === 'POST' ? cart(call.payload.id) : cart(0))};
    }
  });
  const source = fs.readFileSync(path.join(__dirname, '../assets/showcase-quick-view.js'), 'utf8');
  const ending = '})(window.jQuery);';
  assert.equal(source.split(ending).length, 2, 'one native closure ending');
  const bridge = `window.__native = {submit:submit, observe:observeBuyNow, pause:pauseRoot, operation:function(){return operation;}, invalidate:invalidateInlineIntent, configure:function(view){
    if(view.inline){inlineViews.set('fixture',view);}else{views.set(view.id,view);dialog={open:true};active={id:view.id,view:view,token:view.token,config:view.config,opener:{}};status={};checkButton={};acknowledge={};cartLink={};}
  }};`;
  const context = vm.createContext(window);
  vm.runInContext(source.replace(ending, bridge + ending), context, {filename: 'showcase-quick-view.js'});
  const selected = options.variation || 101;
  const form = {reportValidity: () => true, setAttribute() {}, querySelector(selector) {
    if (selector === '[name="quantity"]') return {value: '2'};
    if (selector === '[name="variation_id"]') return {value: String(selected)};
    if (selector === '.single_add_to_cart_button.disabled') return null;
    if (selector === '.single_add_to_cart_button') return {};
    return null;
  }, querySelectorAll: () => [{name: 'attribute_private', value: 'PRIVATE'}]};
  const element = {dataset: {vcVariable: options.variation ? '1' : '0', vcProductName: 'PRIVATE product name'}, isConnected: true, getClientRects: () => [{}], querySelector: () => null};
  const view = {inline: options.modal ? false : true, id: '101', token: 1, element, form, status: {}, feedback: {dataset: {}}, check: {}, acknowledge: {}, cart: {}, config: {cartApi: 'https://native.test/cart', addApi: 'https://native.test/add', cartUrl: 'https://native.test/cart-page', checkoutUrl: 'https://native.test/checkout', wpNonce: 'synthetic-wp-nonce'}};
  const f = {window, document, native: window.__native, view, calls, observations, navigation, order, timers};
  f.native.configure(view);
  return f;
}
function canonical(f, variation = 0) {
  assert.equal(f.observations.length, 1);
  const {type, facts} = f.observations[0];
  assert.equal(type, 'product_intent');
  assert.deepEqual(Object.keys(facts).sort(), ['version', 'product_id', 'variation_id', 'kind', 'result', 'event_id'].sort());
  assert.match(facts.event_id, /^buynow-[A-Za-z0-9-]{1,57}$/);
  assert.deepEqual({...facts, event_id: 'UID'}, {version: 1, product_id: 101, variation_id: variation, kind: 'buy_now', result: 'completed', event_id: 'UID'});
  assert.equal(JSON.stringify(facts).includes('PRIVATE'), false);
  assert.equal(f.native.operation().state, 'success');
  assert.equal(f.navigation.length, 1);
  assert.ok(f.order.indexOf('navigate') < f.order.indexOf('observe'));
}

test('native passive state keeps showcase target and uses only exact opener when root is absent', () => {
  const f = fixture(), root = new Target(), opener = new Target(), seen = [];
  root.addEventListener('vc:showcase-quick-state', event => seen.push({target: root, event}));
  opener.addEventListener('vc:showcase-quick-state', event => seen.push({target: opener, event}));
  for (const open of [true, false]) f.native.pause(root, open, opener);
  for (const open of [true, false]) f.native.pause(null, open, opener);
  f.native.pause(null, true); f.native.pause(null, false);
  assert.equal(seen.length, 4);
  assert.deepEqual(seen.map(row => row.target), [root, root, opener, opener]);
  assert.deepEqual(seen.map(row => json(row.event.detail)), [{open: true}, {open: false}, {open: true}, {open: false}]);
  assert.ok(seen.every(row => row.event.bubbles !== true));
  assert.equal(f.calls.length, 0); assert.equal(f.navigation.length, 0); assert.equal(f.observations.length, 0);
});

for (const modal of [false, true]) test((modal ? 'modal' : 'inline') + ' native successful buy queues exactly canonical facts after navigation', async () => {
  const f = fixture({modal}); await f.native.submit(f.view, 'buy'); canonical(f);
  assert.deepEqual(f.calls.map(c => c.method), ['GET', 'POST']);
  assert.equal(f.calls[1].payload.id, 101); assert.equal(f.calls[1].payload.quantity, 2);
  f.native.observe(f.native.operation()); assert.equal(f.observations.length, 1, 'queued callback cannot duplicate');
  assert.equal(f.timers.size, 0, 'native request timers cleared');
});
test('variable native item retains actual parent and variation identity', async () => {
  const f = fixture({variation: 303}); await f.native.submit(f.view, 'buy'); canonical(f, 303); assert.equal(f.calls[1].payload.id, 303);
});
test('ordinary add succeeds without checkout or buy intent', async () => {
  const f = fixture(); await f.native.submit(f.view, 'add'); assert.equal(f.native.operation().state, 'success'); assert.equal(f.observations.length, 0); assert.equal(f.navigation.length, 0);
});
test('native nonce refresh retries the same add and produces one buy observation', async () => {
  let posts = 0;
  const f = fixture({respond(call) { if (call.method === 'POST' && ++posts === 1) return {status: 403, data: {code: 'woocommerce_rest_invalid_nonce', message: 'retry'}}; }});
  await f.native.submit(f.view, 'buy'); canonical(f); assert.deepEqual(f.calls.map(c => c.method), ['GET', 'POST', 'GET', 'POST']); assert.equal(JSON.stringify(f.calls[1].payload), JSON.stringify(f.calls[3].payload));
});
test('4xx, uncertain success and missing requested item never produce completed intent', async () => {
  for (const scenario of [{status: 400, data: {code: 'invalid_item', message: '<b>Rejected</b>'}}, {status: 200, data: cart()}, {status: 201, data: cart(202)}, {status: 201, data: cart(101, 0)}, {throw: true}]) {
    const f = fixture({respond: call => call.method === 'POST' ? scenario : undefined}); await f.native.submit(f.view, 'buy');
    assert.equal(f.observations.length, 0); assert.equal(f.navigation.length, 0); assert.ok(['failed', 'unknown'].includes(f.native.operation().state));
  }
});
test('concurrent native mutation remains unknown instead of completed buy', async () => {
  const f = fixture({respond(call, number, f) { if (call.method === 'POST') return {before: () => f.document.dispatchEvent({type: 'vc:cart-mutation-start', detail: {source: 'drawer'}})}; }});
  await f.native.submit(f.view, 'buy'); assert.equal(f.native.operation().state, 'unknown'); assert.equal(f.observations.length, 0); assert.equal(f.navigation.length, 0);
});
test('cancelled pre-send and no-longer-current success do not navigate or observe', async () => {
  const cancelled = fixture({respond(call, number, f) { if (call.method === 'GET') return {before: () => f.native.invalidate()}; }});
  await cancelled.native.submit(cancelled.view, 'buy'); assert.equal(cancelled.native.operation().state, 'cancelled'); assert.equal(cancelled.calls.length, 1); assert.equal(cancelled.observations.length, 0);
  const stale = fixture({respond(call, number, f) { if (call.method === 'POST') return {before: () => { f.view.token++; }}; }});
  await stale.native.submit(stale.view, 'buy'); assert.equal(stale.native.operation().state, 'success'); assert.equal(stale.navigation.length, 0); assert.equal(stale.observations.length, 0);
});
test('optional absent, throwing, disabled/full or crypto-unavailable Data cannot change native success', async () => {
  for (const options of [{dataOff: true}, {dataThrow: true}, {dataFalse: true}, {crypto: undefined}, {crypto: {randomUUID() { throw Error('blocked'); }}}]) {
    const f = fixture(options); await f.native.submit(f.view, 'buy'); assert.equal(f.native.operation().state, 'success'); assert.equal(f.navigation.length, 1); assert.equal(f.calls.filter(c => c.method === 'POST').length, 1); assert.equal(f.native.operation().dataBuyNowQueued, undefined);
  }
});
test('malformed UUID source results never become fixed operation IDs or affect native success', async () => {
  for (const value of [undefined, null, false, 123, '', 'undefined', 'null', 'not-a-uuid', 'a'.repeat(31), 'g'.repeat(32), '12345678-1234-4234-8234-123456789abz']) {
    const f = fixture({crypto: {randomUUID: () => value}}); await f.native.submit(f.view, 'buy');
    assert.equal(f.native.operation().state, 'success'); assert.equal(f.navigation.length, 1);
    assert.equal(f.calls.filter(c => c.method === 'POST').length, 1); assert.equal(f.observations.length, 0);
    assert.equal(f.native.operation().dataBuyNowUid, undefined); assert.equal(f.native.operation().dataBuyNowQueued, undefined);
  }
});

test('navigation throw follows existing unknown reconciliation and queues no completed observation', async () => {
  const f = fixture({navThrow: true}); await f.native.submit(f.view, 'buy'); assert.equal(f.navigation.length, 1); assert.equal(f.observations.length, 0); assert.equal(f.native.operation().state, 'unknown'); assert.equal(f.calls.filter(c => c.method === 'GET').length, 2);
});
test('failed optional queue reuses operation UID; accepted callback is idempotent', async () => {
  let counter = 0;
  const f = fixture({dataFalse: true, crypto: {randomUUID: () => { counter++; return '12345678-1234-4234-8234-123456789abc'; }}});
  await f.native.submit(f.view, 'buy'); const op = f.native.operation(), first = f.observations[0].facts.event_id; f.native.observe(op); assert.equal(f.observations[1].facts.event_id, first); assert.equal(counter, 1);
  f.window.VialCoveVisitorTransport.pushProductAction = (type, facts) => { f.observations.push({type, facts: json(facts)}); return facts.event_id; };
  f.native.observe(op); f.native.observe(op); assert.equal(f.observations.length, 3); assert.equal(op.dataBuyNowQueued, true); assert.equal(counter, 1);
});
test('random-byte UID fallback and unsafe native identity guards', async () => {
  const f = fixture({crypto: {getRandomValues: array => { array.fill(9); return array; }}}); await f.native.submit(f.view, 'buy'); canonical(f); assert.equal(f.observations[0].facts.event_id, 'buynow-' + '09'.repeat(16));
  for (const [parent, selected] of [[0, 101], [Number.MAX_SAFE_INTEGER + 1, 101], [101, 0], [101, '303'], [101, Number.MAX_SAFE_INTEGER + 1]]) {
    const before = f.observations.length; f.native.observe({parentId: parent, payload: {id: selected}}); assert.equal(f.observations.length, before);
  }
});

function collectorFixture() {
  const file = path.resolve(__dirname, '../../../themes/peptide-site/tests/visitor-product-actions.cjs');
  const original = fs.readFileSync(file, 'utf8'), marker = 'async function run() {';
  assert.equal(original.split(marker).length, 2, 'existing056 reusable fixture boundary');
  const module = {exports: {}};
  vm.runInNewContext(original.slice(0, original.indexOf(marker)) + '\nmodule.exports={fixture,actions,drain};', {require, module, __dirname: path.dirname(file), console, Headers, AbortController, DOMException, URL, TextEncoder}, {filename: file});
  return module.exports;
}
test('native successful navigation feeds real facade and pagehide immutable keepalive queue', async () => {
  const shared = collectorFixture(), collector = shared.fixture({product_actions_protocol: 1});
  const f = fixture({api: collector.window.VialCoveVisitorTransport}); await f.native.submit(f.view, 'buy');
  const uid = f.native.operation().dataBuyNowUid; assert.match(uid, /^buynow-/); assert.equal(f.native.operation().dataBuyNowQueued, true); assert.equal(f.navigation.length, 1);
  collector.window.dispatchEvent({type: 'pagehide', persisted: false}); await shared.drain();
  const sent = shared.actions(collector).filter(e => e.event_id === uid); assert.equal(sent.length, 1);
  assert.deepEqual(json(sent[0].data), {version: 1, product_id: 101, variation_id: 0, kind: 'buy_now', result: 'completed'});
  assert.ok(collector.calls.some(c => c.keepalive === true && c.body.events.some(e => e.event_id === uid)));
  assert.equal(sent[0].page.path, '/product/item/'); assert.ok(sent[0].page_instance_id); assert.ok(sent[0].occurred_at); collector.stop();
});
