const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/home-quick-view.js'), 'utf8');
const helpers = source.slice(source.indexOf('  function clearPending()'), source.indexOf('  function announce('));
const capture = source.slice(source.indexOf("  document.addEventListener('click', event => {"), source.indexOf('  // Keep the product selection underneath the live bag'));
const legacy = source.slice(source.indexOf('  // The shared plugin dialog can also be opened'), source.indexOf('  const cancelPending = '));
const cancellation = source.slice(source.indexOf('  const cancelPending = '), source.lastIndexOf('})(window.jQuery)'));

function fixture() {
  const events = () => ({handlers: new Map(), addEventListener(type, callback) { const list = this.handlers.get(type) || []; list.push(callback); this.handlers.set(type, list); }, fire(type, event = {}) { for (const callback of this.handlers.get(type) || []) callback(event); }});
  const document = Object.assign(events(), {hidden: false, visibilityState: 'visible', querySelector() { return null; }});
  const window = Object.assign(events(), {setTimeout() {}});
  const calls = [], navigations = [], announcements = [], requests = new Map(), ready = new Set();
  const status = {textContent: ''};
  vm.runInNewContext(helpers + capture + legacy + cancellation, {
    document, window, openIntent: 0, loadingStatus: status, pendingOpeners: new Map(), requests,
    homeProduct: '.vc-home-quick-product', canOpen: () => true, templateFor: id => ready.has(id), present() {},
    announce(message) { status.textContent = message; announcements.push(message); },
    location: {assign(url) { navigations.push(url); }},
    loadTemplate(id) {
      let resolve, reject;
      const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
      const call = {id, resolve() { ready.add(id); resolve(); }, reject() { reject(new Error('Unavailable')); }};
      calls.push(call); requests.set(id, {controller: {abort() { call.reject(); }}}); return promise;
    }
  });
  function link(id) {
    const attrs = new Map();
    return {dataset: {vcHomeProduct: String(id)}, isConnected: true, href: 'https://example.test/product/' + id, clicks: 0,
      setAttribute(name, value) { attrs.set(name, value); }, removeAttribute(name) { attrs.delete(name); }, getAttribute(name) { return attrs.get(name); },
      closest(selector) { return selector.includes('[data-vc-home-product]') && !this.dataset.vcHomeProduct ? null : this; },
      hasAttribute(name) { return name === 'data-vc-home-product' ? !!this.dataset.vcHomeProduct : attrs.has(name); }, click() { this.clicks++; }};
  }
  function click(target, options = {}) {
    const event = {target, button: 0, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() {}, ...options};
    document.fire('click', event); return event;
  }
  return {document, window, calls, navigations, announcements, ready, status, link, click};
}
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

test('first read immediately shows pending and clears it after the authoritative template is ready', async () => {
  const f = fixture(), link = f.link(1);
  assert.equal(f.click(link).defaultPrevented, true);
  assert.equal(link.getAttribute('aria-busy'), 'true'); assert.match(f.status.textContent, /Loading/);
  f.calls[0].resolve(); await settle();
  assert.equal(link.clicks, 1); assert.equal(link.getAttribute('aria-busy'), undefined); assert.equal(f.status.textContent, '');
});
test('a new product takes over feedback and an old read cannot reopen the previous product', async () => {
  const f = fixture(), a = f.link(1), b = f.link(2);
  f.click(a); f.click(b);
  assert.equal(a.getAttribute('aria-busy'), undefined); assert.equal(b.getAttribute('aria-busy'), 'true');
  f.calls[0].resolve(); await settle();
  assert.equal(a.clicks, 0); assert.equal(b.getAttribute('aria-busy'), 'true');
  f.calls[1].resolve(); await settle(); assert.equal(b.clicks, 1);
});
test('opening an older showcase cancels obsolete card feedback without waiting for its read', async () => {
  const f = fixture(), a = f.link(1), older = f.link(2); delete older.dataset.vcHomeProduct;
  f.click(a); f.click(older);
  assert.equal(a.getAttribute('aria-busy'), undefined); assert.equal(f.status.textContent, '');
  f.calls[0].resolve(); await settle(); assert.equal(a.clicks, 0); assert.equal(f.navigations.length, 0);
});
test('an older completion cannot clear a newer pending intent on the same link', async () => {
  const f = fixture(), link = f.link(1); f.click(link); f.click(link);
  f.calls[0].resolve(); await settle();
  assert.equal(link.getAttribute('aria-busy'), 'true'); assert.equal(link.clicks, 0);
  f.calls[1].resolve(); await settle();
  assert.equal(link.getAttribute('aria-busy'), undefined); assert.equal(link.clicks, 1);
});
test('Escape, restore cancellation, hidden state and pagehide clear pending immediately without navigation', async () => {
  for (const kind of ['Escape', 'restore', 'hidden', 'pagehide']) {
    const f = fixture(), link = f.link(1); f.click(link);
    if (kind === 'Escape') f.document.fire('keydown', {key: 'Escape'});
    if (kind === 'restore') f.document.fire('vc:product-restore-cancel');
    if (kind === 'hidden') { f.document.hidden = true; f.document.visibilityState = 'hidden'; f.document.fire('visibilitychange'); }
    if (kind === 'pagehide') f.window.fire('pagehide');
    assert.equal(link.getAttribute('aria-busy'), undefined, kind); await settle();
    assert.equal(link.clicks, 0, kind); assert.equal(f.navigations.length, 0, kind); assert.equal(f.status.textContent, '', kind);
  }
});
test('cached and modified clicks retain immediate native navigation and clear only superseded feedback', async () => {
  const f = fixture(), a = f.link(1), b = f.link(2); f.ready.add('2'); f.click(a);
  assert.equal(f.click(b, {ctrlKey: true}).defaultPrevented, false);
  assert.equal(a.getAttribute('aria-busy'), 'true', 'new-tab navigation does not cancel the current page intent');
  assert.equal(f.click(b).defaultPrevented, false); assert.equal(a.getAttribute('aria-busy'), undefined); assert.equal(f.status.textContent, '');
  f.calls[0].reject(); await settle(); assert.equal(f.navigations.length, 0);
});
test('only the current connected failed read uses the real product-page fallback', async () => {
  for (const mode of ['current', 'stale', 'detached']) {
    const f = fixture(), link = f.link(1); f.click(link);
    if (mode === 'stale') f.click(f.link(2));
    if (mode === 'detached') link.isConnected = false;
    f.calls[0].reject(); await settle();
    assert.equal(f.navigations.length, mode === 'current' ? 1 : 0, mode);
    assert.equal(link.getAttribute('aria-busy'), undefined, mode);
  }
});

test('real template reader reuses live work, replaces aborted work and ignores old cleanup', async () => {
  const requests = new Map(), calls = [], timers = new Map(); let serial = 0;
  const context = vm.createContext({requests, templateFor: () => false, AbortController, URL,
    window: {vcHomeQuickView: {productApi: 'https://example.test/wp-json/peptide/v1/products/'}},
    location: {href: 'https://example.test/', origin: 'https://example.test'},
    setTimeout(fn) { const id = ++serial; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); },
    fetch(url, options) { let reject; const promise = new Promise((_, no) => { reject = no; }); calls.push({url, options, reject}); return promise; }
  });
  const reader = source.slice(source.indexOf('  async function loadTemplate('), source.indexOf('  const sameOriginImage = '));
  vm.runInContext(reader, context);
  const first = vm.runInContext("loadTemplate('1')", context), same = vm.runInContext("loadTemplate('1')", context);
  const firstRejected = assert.rejects(first), sameRejected = assert.rejects(same);
  assert.equal(calls.length, 1, 'ordinary repeat reads retain deduplication');
  requests.get('1').controller.abort();
  const next = vm.runInContext("loadTemplate('1')", context), nextRejected = assert.rejects(next);
  const current = requests.get('1'); assert.equal(calls.length, 2); assert.equal(current.controller.signal.aborted, false);
  calls[0].reject(new Error('Old aborted read')); await firstRejected; await sameRejected;
  assert.equal(requests.get('1'), current, 'old finally cannot delete the replacement request');
  calls[1].reject(new Error('Current read ended')); await nextRejected;
  assert.equal(requests.size, 0); assert.equal(timers.size, 0);
});
