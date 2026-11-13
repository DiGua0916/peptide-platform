/*
 * The landing page's product must still open when the page was loaded in the background:
 * an ad link opened in another tab, or an in-app browser that loads the page before showing it.
 * Runs the real assets/product-dialog-polish.js against a small fake page; no browser, no network.
 *
 * Before 2026-11-12 the entry dropped the product whenever the page was hidden at the moment of
 * presenting and never came back to it, so the visitor returned to the home page instead of the
 * product. With pc_front_speed on that moment is DOMContentLoaded, which made it easy to hit.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/product-dialog-polish.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

class Target {
  constructor() { this.events = new Map(); }
  addEventListener(type, callback) { if (!this.events.has(type)) this.events.set(type, new Set()); this.events.get(type).add(callback); }
  removeEventListener(type, callback) { this.events.get(type)?.delete(callback); }
  dispatchEvent(event) { event.target ??= this; for (const callback of [...this.events.get(event.type) || []]) callback(event); return true; }
}

/*
 * options: productId (the ?vc_product= of the page), hidden (page starts in the background),
 * inline (the product's template is already in the page, as pc_front_speed prints it),
 * layers (history.state layers, as after a refresh with a dialog open).
 */
function fixture(options = {}) {
  const state = {nativeOpens: 0, shown: null, loads: [], templates: new Set(options.inline ? [String(options.productId)] : [])};
  class Element extends Target {
    constructor(tag = 'div') { super(); this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.parentElement = null; this.hidden = false; this.disabled = false; this._text = ''; this.classList = {contains: () => false, add() {}, remove() {}}; }
    set className(value) { this._class = value; } get className() { return this._class || ''; }
    get isConnected() { return !!this.parentElement; }
    set textContent(value) { this._text = String(value); } get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    append(...nodes) { for (const node of nodes) { node.remove(); node.parentElement = this; this.children.push(node); } }
    prepend(node) { node.remove(); node.parentElement = this; this.children.unshift(node); }
    remove() { if (!this.parentElement) return; const list = this.parentElement.children, at = list.indexOf(this); if (at >= 0) list.splice(at, 1); this.parentElement = null; }
    contains(node) { return node === this || this.children.some(child => child.contains(node)); }
    setAttribute() {} focus() {}
    querySelector(selector) { return this.children.find(child => selector === 'p' && child.tagName === 'P' || selector === 'button' && child.tagName === 'BUTTON') || null; }
    querySelectorAll() { return []; }
    click() {
      if (this.disabled) return;
      this.dispatchEvent({type: 'click'}); this.onclick?.();
      if (!this.dataset.vcQuickOpen) return;
      // The native presenter: opens the dialog and announces it.
      state.nativeOpens++; state.shown = root; root.dataset.vcProductId = this.dataset.vcQuickOpen;
      document.dispatchEvent({type: 'vc:product-presented', detail: {root, dialog, productId: Number(this.dataset.vcQuickOpen)}});
    }
  }
  const document = new Target();
  Object.assign(document, {readyState: 'complete', hidden: !!options.hidden, documentElement: {lang: 'en'}, activeElement: null, createElement: tag => new Element(tag), body: new Element('body')});
  const main = new Element('main'), dialog = new Element('dialog'), root = new Element();
  document.body.append(main, dialog); dialog.append(root);
  document.querySelector = selector => {
    if (selector === 'main') return main;
    if (selector === 'dialog.vc-showcase-quick[open] .vc-home-quick-product') return state.shown;
    const id = selector.match(/^template\[data-vc-quick-product="(\d+)"\]$/)?.[1];
    return id && state.templates.has(id) ? {} : null;
  };
  const window = new Target();
  Object.assign(window, {
    document,
    vcStorefrontEntry: {version: 1, productId: options.productId || 0, catalog: {}, campaign: null, release: 'a'.repeat(64)},
    performance: {now: () => 1000, getEntriesByType: () => [{responseEnd: 900}]},
    location: {href: 'https://store.test/?vc_product=' + (options.productId || ''), origin: 'https://store.test', pathname: '/', hash: '', reload() {}},
    history: {state: options.layers ? {__vcCommerceLayers: {layers: options.layers}} : null},
    sessionStorage: {getItem() { return null; }, setItem() {}},
    vcHomeQuickView: {productApi: 'https://store.test/wp-json/vialcove/v1/home-product/'},
    // The shared template reader of home-quick-view.js: one read per product, resolved by the test.
    VialCoveHomeQuickView: {loadTemplate(id) {
      let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
      state.loads.push({id, finish() { state.templates.add(String(id)); resolve(); }, fail() { reject(new Error('aborted')); }});
      return promise;
    }}
  });
  const timers = new Map(); let nextTimer = 1;
  vm.runInNewContext(source, {
    window, fetch: async () => { throw new Error('no network in this test'); }, MutationObserver: class { observe() {} disconnect() {} }, AbortController, URL,
    CustomEvent: class { constructor(type, init = {}) { this.type = type; Object.assign(this, init); } }, Event: class { constructor(type) { this.type = type; } },
    setInterval: () => 0, clearInterval() {}, setTimeout: (callback, delay) => { const id = nextTimer++; timers.set(id, {callback, delay}); return id; }, clearTimeout: id => timers.delete(id), console
  });
  const card = () => main.children[0] || null;
  return {
    state, document, window, main, card,
    opens: () => state.nativeOpens,
    // What the visitor would see in the page instead of the product: nothing, "loading", or a failure card.
    visibleCard: () => (card() && !card().hidden ? card().dataset.entryState : ''),
    hide() { document.hidden = true; document.dispatchEvent({type: 'visibilitychange'}); },
    show() { document.hidden = false; document.dispatchEvent({type: 'visibilitychange'}); }
  };
}

test('page that was visible all along: the product opens once at load and a later hide / show does not reopen it', async () => {
  const f = fixture({productId: 17, inline: true}); await settle();
  assert.equal(f.opens(), 1); assert.equal(f.visibleCard(), '');
  f.hide(); f.show(); await settle();
  assert.equal(f.opens(), 1);
});

test('pc_front_speed case: loaded in the background with the product already in the page — it opens when the page is looked at', async () => {
  const f = fixture({productId: 17, inline: true, hidden: true}); await settle();
  assert.equal(f.opens(), 0, 'nothing is presented while nobody can see it');
  assert.equal(f.visibleCard(), '', 'and no loading or failure card is left behind');
  f.show(); await settle();
  assert.equal(f.opens(), 1, 'presented exactly once on return');
  assert.equal(f.visibleCard(), '');
  f.hide(); f.show(); await settle();
  assert.equal(f.opens(), 1, 'a second hide / show does not open it again');
});

test('switch off: loaded in the background, the product is read while hidden and opens when the page is looked at', async () => {
  const f = fixture({productId: 17, hidden: true}); await settle();
  assert.equal(f.state.loads.length, 1);
  f.state.loads[0].finish(); await settle();
  assert.equal(f.opens(), 0); assert.equal(f.visibleCard(), '');
  f.show(); await settle();
  assert.equal(f.opens(), 1); assert.equal(f.state.loads.length, 1, 'the product is not read a second time');
});

test('page still hidden when visibility is reported again: nothing opens until it really is visible', async () => {
  const f = fixture({productId: 17, inline: true, hidden: true}); await settle();
  f.document.dispatchEvent({type: 'visibilitychange'}); await settle();
  assert.equal(f.opens(), 0);
  f.show(); await settle();
  assert.equal(f.opens(), 1);
});

test('visitor switches away while the product is still loading and comes back: it opens, with no failure card in between', async () => {
  const f = fixture({productId: 17}); await settle();
  assert.equal(f.visibleCard(), 'loading');
  f.hide(); await settle();
  assert.equal(f.visibleCard(), '', 'the loading card is retired, not turned into a failure');
  f.state.loads[0].finish(); await settle();
  assert.equal(f.opens(), 0, 'a read finishing in the background does not present anything');
  f.show(); await settle();
  assert.equal(f.opens(), 1); assert.equal(f.visibleCard(), '');
});

test('read aborted because the page went to the background (the abort lands before this script hears about it): not a failure, retried on return', async () => {
  const f = fixture({productId: 17}); await settle();
  // home-quick-view.js aborts its reads on hide; when its listener runs first, the rejection reaches
  // openProduct while the page is already hidden but before this script's own visibility listener.
  f.document.hidden = true; f.state.loads[0].fail(); await settle();
  assert.equal(f.visibleCard(), '', 'no "could not load" card for a read the browser was told to drop');
  f.document.dispatchEvent({type: 'visibilitychange'}); await settle();
  assert.equal(f.opens(), 0);
  f.show(); await settle();
  assert.equal(f.state.loads.length, 2, 'the product is read again');
  f.state.loads[1].finish(); await settle();
  assert.equal(f.opens(), 1);
});

test('a real failure on a visible page still shows the retry card', async () => {
  const f = fixture({productId: 17}); await settle();
  f.state.loads[0].fail(); await settle();
  assert.equal(f.visibleCard(), 'failed'); assert.equal(f.opens(), 0);
  f.hide(); f.show(); await settle();
  assert.equal(f.opens(), 0, 'a failure is not silently retried by switching tabs');
  assert.equal(f.visibleCard(), 'failed');
});

test('Back, or leaving the page, while it waits in the background drops the product', async () => {
  for (const kind of ['back', 'pagehide']) {
    const f = fixture({productId: 17, inline: true, hidden: true}); await settle();
    if (kind === 'back') f.document.dispatchEvent({type: 'vc:product-restore-cancel'}); else f.window.dispatchEvent({type: 'pagehide'});
    f.show(); await settle();
    assert.equal(f.opens(), 0, kind); assert.equal(f.visibleCard(), '', kind);
  }
});

test('another product presented in the meantime wins; the waiting one is not opened over it', async () => {
  const f = fixture({productId: 17, inline: true, hidden: true}); await settle();
  f.state.shown = {dataset: {vcProductId: '21'}};
  f.document.dispatchEvent({type: 'vc:product-presented', detail: {root: null, dialog: null, productId: 21}});
  f.show(); await settle();
  assert.equal(f.opens(), 0);
});

test('a product dialog that is already open on return is left alone', async () => {
  const f = fixture({productId: 17, inline: true, hidden: true}); await settle();
  f.state.shown = {dataset: {vcProductId: '17'}};
  f.show(); await settle();
  assert.equal(f.opens(), 0);
});

test('refreshed page whose history entry already holds layers: the history controller presents it, this script does not add a second attempt', async () => {
  const f = fixture({productId: 17, inline: true, layers: [{kind: 'product', productId: 17}]}); await settle();
  assert.equal(f.opens(), 0); assert.equal(f.visibleCard(), 'loading');
  f.hide(); await settle();
  assert.equal(f.visibleCard(), '');
  f.show(); await settle();
  assert.equal(f.opens(), 0); assert.equal(f.visibleCard(), '', 'and no failure card is invented');
});
