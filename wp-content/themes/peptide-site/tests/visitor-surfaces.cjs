'use strict';
// Actual surface observer + collector + transport, isolated DOM/clock/HTTP only.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const assert = require('node:assert/strict'), test = require('node:test');

function mountSurfaces(window, document, options = {}) {
  class Element {
    constructor(tag, classes = '', attrs = {}) {
      this.tagName = tag.toUpperCase(); this.classes = classes.split(' ').filter(Boolean); this.attrs = {...attrs};
      this.ownerDocument = document; this.isConnected = true; this.hidden = false; this.open = false; this.children = []; this.parentElement = null;
      this.style = {display: 'block', visibility: 'visible', opacity: '1'};
      this.rect = {left: 0, top: 0, right: 375, bottom: 700, width: 375, height: 700}; this.textContent = '';
    }
    append(node) { this.children.push(node); node.parentElement = this; return node; }
    getAttribute(name) { return this.attrs[name] ?? null; }
    matches(selector) {
      return selector.split(',').some(raw => {
        const s = raw.trim();
        if (s === '.vc-showcase-quick__body') return this.classes.includes('vc-showcase-quick__body');
        if (s === '.vc-showcase-quick-product[data-vc-product-id]') return this.classes.includes('vc-showcase-quick-product') && 'data-vc-product-id' in this.attrs;
        if (s === '.product_title' || s === '.vc-showcase-quick__title') return this.classes.includes(s.slice(1));
        if (s.startsWith('dialog#')) return this.tagName === 'DIALOG' && this.attrs.id === s.slice(7);
        if (s.startsWith('dialog.')) return this.tagName === 'DIALOG' && this.classes.includes(s.slice(7));
        return false;
      });
    }
    closest(selector) { for (let n = this; n; n = n.parentElement) if (n.matches(selector)) return n; return null; }
    querySelector(selector) { for (const child of this.children) { if (child.matches(selector)) return child; const found = child.querySelector(selector); if (found) return found; } return null; }
    getClientRects() { return this.isConnected && this.rect.width > 0 ? [this.rect] : []; }
    getBoundingClientRect() { return this.rect; }
    get id() { return this.attrs.id || ''; }
  }
  const html = new Element('html'), body = html.append(new Element('body'));
  const opener = body.append(new Element('a', '', {'data-vc-quick-open': '101'}));
  const product = body.append(new Element('dialog', 'vc-showcase-quick', {'aria-modal': 'true'}));
  const content = product.append(new Element('div', 'vc-showcase-quick__body'));
  const root = content.append(new Element('div', 'vc-showcase-quick-product', {'data-vc-product-id': '101'}));
  const heading = root.append(new Element('h2', 'product_title')); heading.textContent = 'Synthetic research item'; root.vcHomeOpener = opener;
  const cart = body.append(new Element('dialog', '', {id: 'vc-live-cart-drawer'}));
  const report = body.append(new Element('dialog', 'vc-product-child-dialog vc-product-report-dialog', {'data-vc-product-parent': '101'}));
  const unknown = body.append(new Element('dialog', 'unrelated-dialog'));
  const all = () => { const result = []; function walk(n) { result.push(n); n.children.forEach(walk); } walk(html); return result; };
  document.documentElement = html;
  document.querySelectorAll = selector => all().filter(n => n.matches(selector));
  window.getComputedStyle = node => node.style; window.innerWidth = 375; window.innerHeight = 800; window.navigator = options.navigator || {};
  window.requestAnimationFrame = fn => window.setTimeout(() => fn(window.Date.now()), 16);
  window.cancelAnimationFrame = key => window.clearTimeout(key);
  const watchers = [];
  window.MutationObserver = class { constructor(fn) { this.fn = fn; watchers.push(this); } observe() { this.active = true; } disconnect() { this.active = false; } };
  const notify = (target, attributeName = 'open', type = 'attributes') => { for (const w of watchers.filter(w => w.active)) w.fn([{target, attributeName, type}]); };
  window.VialCoveVisitorPage = {page_type: options.pageType || 'home', path: '/catalogue/?secret=OMIT'};
  const env = {html, body, opener, product, content, root, heading, cart, report, unknown, watchers,
    open(dialog) { dialog.open = true; notify(dialog); }, close(dialog) { dialog.open = false; notify(dialog); document.dispatchEvent({type: 'close', target: dialog}); },
    notify, hint() { document.dispatchEvent({type: 'vc:product-presented', detail: {productId: 999999, product_name: 'DO NOT COPY'}}); },
    hide(value) { document.hidden = value; document.visibilityState = value ? 'hidden' : 'visible'; document.dispatchEvent({type: 'visibilitychange'}); },
    replaceProduct(id, name) { root.attrs['data-vc-product-id'] = String(id); opener.attrs['data-vc-quick-open'] = String(id); heading.textContent = name; notify(heading, null, 'characterData'); }
  };
  window.__surfaces = env;
  if (options.impressions) window.VialCoveVisitorProducts = {observe(_, callback) { window.__productImpression = callback; return {stop() {}, suspend() {}, resume() {}}; }};
}

const scope = {require, __dirname, console, URL, Headers, TextEncoder, AbortController, DOMException, mountSurfaces};
let fixtureSource = fs.readFileSync(path.join(__dirname, 'visitor-transport.cjs'), 'utf8').split('async function run()')[0];
const mountMarker = '  const context = vm.createContext(window);';
assert.equal(fixtureSource.split(mountMarker).length, 2);
fixtureSource = fixtureSource.replace(mountMarker, '  mountSurfaces(window, document, options);\n' + mountMarker);
const loadMarker = "  for (const name of ['visitor-tracking.js', 'visitor-adapter.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});";
assert.equal(fixtureSource.split(loadMarker).length, 2);
fixtureSource = fixtureSource.replace(loadMarker, `  for (const name of ['visitor-surfaces.js', 'visitor-tracking.js', 'visitor-adapter.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});
    if (name === 'visitor-tracking.js') {
      const nativeStart = window.VialCoveVisitorObserver.start;
      window.VialCoveVisitorObserver.start = args => { window.__collector = nativeStart({...args, maxQueue: options.queueMax || 100}); return window.__collector; };
    }
  }`);
vm.runInNewContext(fixtureSource + '\nthis.make = fixture;', scope);
const events = f => f.calls.flatMap(c => c.body.events);
const current = f => events(f).at(-1)?.page;
const plain = value => JSON.parse(JSON.stringify(value));
const quick = uid => ({version: 1, event_id: uid, product_id: 101, variation_id: 0, kind: 'quick_view_open', result: 'completed'});

test('real product opens, remains current through heartbeat and restores without another page view', async () => {
  const f = scope.make({product_actions_protocol: 1}), e = f.window.__surfaces;
  await f.advance(300); assert.equal(events(f).filter(r => r.type === 'page_view').length, 1);
  e.open(e.product); await f.advance(300);
  assert.deepEqual(plain(current(f)), {page_type: 'product', path: '/catalogue/', product_id: 101, product_name: 'Synthetic research item'});
  await f.advance(15000); assert.equal(current(f).product_id, 101);
  e.close(e.product); await f.advance(300); assert.deepEqual(plain(current(f)), {page_type: 'home', path: '/catalogue/'});
  assert.equal(events(f).filter(r => r.type === 'page_view').length, 1); assert.equal(events(f).filter(r => r.type === 'product_view').length, 0);
  assert.ok(!JSON.stringify(events(f)).includes('OMIT')); f.stop();
});

test('open pair keeps physical source then immediately restores current product in the same queue', async () => {
  const f = scope.make({product_actions_protocol: 1}), e = f.window.__surfaces; await f.advance(300);
  e.open(e.product);
  const uid = 'surface-pair-000001'; assert.equal(f.window.VialCoveVisitorTransport.pushProductAction('product_intent', quick(uid)), uid);
  await f.advance(300); const sent = events(f), index = sent.findIndex(r => r.event_id === uid), trio = sent.slice(index, index + 3);
  assert.equal(trio.map(r => r.type).join(','), 'product_intent,product_view,heartbeat');
  assert.deepEqual(plain(trio[0].page), {page_type: 'home', path: '/catalogue/'}); assert.deepEqual(plain(trio[1].page), plain(trio[0].page));
  assert.equal(trio[2].page.product_id, 101); assert.equal(trio[2].seq, trio[1].seq + 1);
  assert.equal(trio[2].occurred_at, trio[0].occurred_at); assert.equal(events(f).filter(r => r.type === 'page_view').length, 1);
  f.stop();
});

test('a later physical-source action also cannot leave live state back on the host', async () => {
  const f = scope.make({product_actions_protocol: 1}), e = f.window.__surfaces; await f.advance(300); e.open(e.product); await f.advance(300);
  const uid = 'surface-share-00001'; assert.equal(f.window.VialCoveVisitorTransport.pushProductAction('product_share', {version: 1, event_id: uid, product_id: 101, method: 'native', result: 'completed'}), uid);
  await f.advance(300); const sent = events(f), index = sent.findIndex(r => r.event_id === uid);
  assert.equal(sent[index].page.page_type, 'home'); assert.equal(sent[index + 1].type, 'heartbeat'); assert.equal(sent[index + 1].page.product_id, 101); f.stop();
});

test('a delayed physical product impression is followed by current context without adding PVs', async () => {
  const f = scope.make({product_impression_protocol: 1, page_observation: {version: 1, page: {page_type: 'home', path: '/catalogue/'}, context: {version: 1, kind: 'home'}}}, {impressions: true}), e = f.window.__surfaces;
  await f.advance(300); e.open(e.product); await f.advance(300);
  f.window.__productImpression({version: 1, product_id: 303, variation_id: 0, visible_fraction: .5, visible_ms: 500, context: {kind: 'home'}});
  await f.advance(300); const sent = events(f), index = sent.findIndex(r => r.type === 'product_impression');
  assert.equal(sent[index].page.page_type, 'home'); assert.equal(sent[index].data.product_id, 303);
  assert.equal(sent[index + 1].type, 'heartbeat'); assert.equal(sent[index + 1].page.product_id, 101);
  assert.equal(events(f).filter(r => r.type === 'page_view').length, 1); f.stop();
});

test('cart and nested report follow opening order and reveal their actual underlying surface', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300);
  e.open(e.product); await f.advance(300); e.open(e.report); await f.advance(300); assert.equal(current(f).product_id, 101);
  e.open(e.cart); await f.advance(300); assert.deepEqual(plain(current(f)), {page_type: 'cart', path: '/catalogue/'});
  e.close(e.cart); await f.advance(300); assert.equal(current(f).product_id, 101);
  e.close(e.report); await f.advance(300); assert.equal(current(f).product_id, 101);
  e.close(e.product); await f.advance(300); assert.equal(current(f).page_type, 'home');
  assert.equal(events(f).filter(r => r.type === 'page_view').length, 1); f.stop();
});

test('changing real product or replacing its DOM updates IDs, and detached roots are never retained', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300); e.open(e.product); await f.advance(300);
  e.replaceProduct(202, 'Second synthetic item'); await f.advance(300); assert.equal(current(f).product_id, 202); assert.equal(current(f).product_name, 'Second synthetic item');
  e.root.isConnected = false; e.notify(e.content, null, 'childList'); await f.advance(300); assert.equal(current(f).page_type, 'home'); f.stop();
});

test('arbitrary events, closed, hidden, invisible, mismatched and unrelated dialogs are not product facts', async () => {
  for (const mode of ['hint', 'closed', 'hidden', 'opacity', 'outside', 'wrong-id', 'wrong-parent', 'no-title', 'unknown']) {
    const f = scope.make(), e = f.window.__surfaces; await f.advance(300);
    if (mode === 'hint' || mode === 'closed') e.hint();
    else if (mode === 'unknown') e.open(e.unknown);
    else {
      if (mode === 'hidden') e.product.hidden = true;
      if (mode === 'opacity') e.product.style.opacity = '0';
      if (mode === 'outside') e.product.rect = {left: 400, right: 700, top: 0, bottom: 500, width: 300, height: 500};
      if (mode === 'wrong-id') e.opener.attrs['data-vc-quick-open'] = '202';
      if (mode === 'wrong-parent') e.root.parentElement = e.body;
      if (mode === 'no-title') e.heading.textContent = '';
      e.open(e.product);
    }
    await f.advance(900); assert.equal(current(f).page_type, 'home', mode); assert.ok(!JSON.stringify(events(f)).includes('DO NOT COPY')); f.stop();
  }
});

test('first offscreen animation frames are followed until the real drawer becomes visible', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300);
  e.cart.rect.left = 400; e.cart.rect.right = 775; e.open(e.cart); await f.advance(80); assert.equal(current(f).page_type, 'home');
  e.cart.rect.left = 0; e.cart.rect.right = 375; await f.advance(300); assert.equal(current(f).page_type, 'cart'); f.stop();
});

test('background visibility preserves an already verified product but never promotes a new hidden one', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300); e.open(e.product); await f.advance(300);
  e.hide(true); await f.advance(300); assert.equal(current(f).product_id, 101); assert.equal(events(f).at(-1).data.visibility, 'hidden');
  e.hide(false); await f.advance(300); e.close(e.product); await f.advance(300);
  e.hide(true); e.open(e.product); await f.advance(300); assert.equal(current(f).page_type, 'home');
  e.hide(false); await f.advance(300); assert.equal(current(f).product_id, 101); f.stop();
});

test('closing a known cart while backgrounded restores the previously visible product', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300);
  e.open(e.product); await f.advance(300); e.open(e.cart); await f.advance(300);
  e.hide(true); await f.advance(300); assert.equal(current(f).page_type, 'cart');
  e.close(e.cart); await f.advance(300); assert.equal(current(f).product_id, 101);
  e.close(e.product); await f.advance(300); assert.equal(current(f).page_type, 'home'); f.stop();
});

test('disabled collection and GPC do not create surface observers or overlay facts', async () => {
  for (const [config, options] of [[{enabled: false}, {}], [{}, {navigator: {globalPrivacyControl: true}}]]) {
    const f = scope.make(config, options), e = f.window.__surfaces; e.open(e.product); e.open(e.cart); await f.advance(1000);
    assert.equal(e.watchers.length, 0); assert.ok(!events(f).some(r => r.page.page_type === 'product' || r.page.page_type === 'cart')); f.stop();
  }
});

test('BFCache restores the same visible context and stop removes observation and queued work', async () => {
  const f = scope.make(), e = f.window.__surfaces; await f.advance(300); e.open(e.product); await f.advance(300);
  f.window.dispatchEvent({type: 'pagehide', persisted: true}); await f.advance(300); const before = f.calls.length;
  e.open(e.cart); await f.advance(1000); assert.equal(f.calls.length, before);
  f.window.dispatchEvent({type: 'pageshow', persisted: true}); await f.advance(300); assert.equal(current(f).page_type, 'cart');
  assert.equal(events(f).filter(r => r.type === 'page_view').length, 2);
  f.stop(); const stoppedCalls = f.calls.length; e.close(e.cart); e.close(e.product); await f.advance(30000);
  assert.equal(f.calls.length, stoppedCalls); assert.equal(f.timers.size, 0); assert.ok(e.watchers.every(w => !w.active));
});

test('lost ACK retries keep original source and current-context records after the product closes', async () => {
  const f = scope.make({product_actions_protocol: 1}), e = f.window.__surfaces; await f.advance(300);
  f.responses.push({network: true}); e.open(e.product);
  const uid = 'surface-retry-00001'; f.window.VialCoveVisitorTransport.pushProductAction('product_intent', quick(uid));
  await f.advance(300); const originals = f.calls.at(-1).body.events.map(plain); assert.ok(originals.some(r => r.event_id === uid));
  e.close(e.product); await f.advance(1300);
  const retried = events(f);
  for (const original of originals) {
    const copies = retried.filter(r => r.event_id === original.event_id); assert.ok(copies.length >= 2);
    for (const copy of copies) assert.deepEqual(plain(copy), original);
  }
  // Ingest compares page-instance/seq, so a late immutable retry cannot replace
  // the newer close heartbeat that was already acknowledged.
  const newest = events(f).reduce((a, b) => a.seq > b.seq ? a : b);
  assert.equal(newest.page.page_type, 'home'); assert.equal(events(f).filter(r => r.type === 'page_view').length, 1); f.stop();
});

test('pair plus current-state restoration reserves all three slots without partial enqueue', async () => {
  const f = scope.make({product_actions_protocol: 1, batch_max: 8}, {queueMax: 10}), e = f.window.__surfaces;
  for (let i = 0; i < 7; i++) f.action(); f.responses.push({pending: true}); await f.advance(300);
  e.open(e.product); const before = f.stats();
  assert.equal(f.window.VialCoveVisitorTransport.pushProductAction('product_intent', quick('surface-refused-0001')), false);
  const after = f.stats(); assert.equal(after.created, before.created); assert.equal(after.queued, before.queued); assert.equal(after.dropped, before.dropped);
  f.stop();
});

test('a source pair and its restoration do not straddle a normal batch boundary', async () => {
  const f = scope.make({product_actions_protocol: 1, batch_max: 4}), e = f.window.__surfaces;
  await f.advance(300); for (let i = 0; i < 3; i++) f.action(); e.open(e.product);
  const uid = 'surface-boundary-001'; f.window.VialCoveVisitorTransport.pushProductAction('product_intent', quick(uid));
  await f.advance(300);
  const groupBatch = f.calls.find(call => call.body.events.some(r => r.event_id === uid));
  const index = groupBatch.body.events.findIndex(r => r.event_id === uid), group = groupBatch.body.events.slice(index, index + 3);
  assert.equal(group.map(r => r.type).join(','), 'product_intent,product_view,heartbeat');
  assert.equal(group[2].page.product_id, 101); assert.ok(f.calls.every(call => call.body.events.length <= 4));
  assert.ok(f.calls.every(call => !JSON.stringify(call.body).includes('presenceGroup'))); f.stop();
});

test('tiny batch caps drain without starvation or inflating the configured request size', async () => {
  const f = scope.make({product_actions_protocol: 1, batch_max: 1}), e = f.window.__surfaces;
  await f.advance(300); e.open(e.product); f.window.VialCoveVisitorTransport.pushProductAction('product_intent', quick('surface-tinycap-001'));
  await f.advance(300); assert.equal(f.stats().queued, 0); assert.equal(current(f).product_id, 101);
  assert.ok(f.calls.every(call => call.body.events.length === 1)); f.stop();
});

test('a physical cart page without an open drawer stays a single physical navigation', async () => {
  const f = scope.make({}, {pageType: 'cart'}), e = f.window.__surfaces;
  e.body.classes.push('woocommerce-cart'); e.hint(); await f.advance(300);
  assert.equal(events(f).map(r => r.type).join(','), 'page_view,cart_view');
  assert.deepEqual(plain(current(f)), {page_type: 'cart', path: '/catalogue/'});
  const probe = f.window.VialCoveVisitorSurfaces.observe(f.document, () => {});
  assert.equal(probe.snapshot(), null); probe.stop(); f.stop();
});
