'use strict';
// Execute the real experience listeners, feedback renderer and native plugin
// submit/apply/reconcile functions. Only DOM, transport and clock primitives are fake.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createVcConfirmedPurchaseReturn } = require('../assets/product-purchase-return.js');
const assets = path.resolve(__dirname, '../assets');
const experience = fs.readFileSync(path.join(assets, 'product-dialog-experience.js'), 'utf8');
const plugin = fs.readFileSync(path.resolve(__dirname, '../../../plugins/peptide-core/assets/showcase-quick-view.js'), 'utf8');
function between(source, first, last) {
  const start = source.indexOf(first), end = source.indexOf(last, start);
  assert.ok(start >= 0 && end > start, `production block boundaries: ${first}`);
  return source.slice(start, end);
}
const listeners = between(experience, "    const mainBuy = root.querySelector('[data-vc-quick-buy]');", '    const formObserver =');
const native = between(plugin, ' function cartShape(', ' function blocked(')
  + between(plugin, ' function applyCart(', ' async function jsonRequest(')
  + between(plugin, ' async function reconcile(', ' function observeBuyNow(')
  + between(plugin, ' async function submit(', " document.addEventListener('click',");
class Events {
  constructor() { this.handlers = new Map(); }
  addEventListener(type, callback, options = {}) {
    if (options.signal?.aborted) return;
    if (!this.handlers.has(type)) this.handlers.set(type, []);
    const entry = { callback, capture: options.capture === true || options === true };
    this.handlers.get(type).push(entry);
    options.signal?.addEventListener('abort', () => this.removeEventListener(type, callback), { once: true });
  }
  removeEventListener(type, callback) { this.handlers.set(type, (this.handlers.get(type) || []).filter(entry => entry.callback !== callback)); }
  dispatchEvent(event) { event.target ||= this; event.preventDefault ||= () => {}; for (const { callback } of [...(this.handlers.get(event.type) || [])].sort((a, b) => Number(b.capture) - Number(a.capture))) callback(event); }
}
class Element extends Events {
  constructor(tagName = 'div') { super(); this.tagName = tagName; this.attrs = new Map(); this.childNodes = []; this.dataset = {}; this.isConnected = true; this.disabled = false; this.inert = false; this.className = ''; this.textContent = ''; const classes = new Set(); this.classList = { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) }; }
  getAttribute(name) { return this.attrs.get(name) ?? null; }
  setAttribute(name, value) { this.attrs.set(name, String(value)); }
  removeAttribute(name) { this.attrs.delete(name); }
  append(...nodes) { this.childNodes.push(...nodes); }
  replaceChildren(...nodes) { this.childNodes = nodes; }
  cloneNode() { const copy = new Element(this.tagName); copy.className = this.className; copy.textContent = this.textContent; copy.childNodes = this.childNodes.map(node => node.cloneNode(true)); return copy; }
  querySelector(selector) { if (selector === '.add-feedback-label') return this.childNodes.flatMap(node => [node, ...node.childNodes]).find(node => node.className === 'add-feedback-label') || null; return null; }
  click() { if (!this.disabled) this.dispatchEvent({ type: 'click' }); }
}
function fixture({ config = {}, mode = 'success', wrongOpener = false, beforeResponse } = {}) {
  const document = new Events(), window = new Events(), dialog = new Element('dialog'), root = new Element(), form = new Element('form'), mainAdd = new Element('button'), mainBuy = new Element('button'), toolbar = new Element(), toolbarClose = new Element('button');
  const trace = [], timers = new Map(), bodyHandlers = new Map(), controller = new AbortController();
  let now = 0, id = 0, nativePromise, feedback, returnController;
  const setTimeout = (fn, duration = 0) => { const key = ++id; timers.set(key, { fn, at: now + duration }); return key; };
  const clearTimeout = key => timers.delete(key);
  const tick = duration => { const end = now + duration; for (;;) { const next = [...timers].filter(([, item]) => item.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0]; if (!next) break; timers.delete(next[0]); now = next[1].at; next[1].fn(); } now = end; };
  Object.assign(dialog, { open: true }); Object.assign(document, { hidden: false, body: {}, createElement: tag => new Element(tag), createElementNS: (_, tag) => new Element(tag) });
  mainAdd.append(new Element('span')); mainAdd.childNodes[0].textContent = 'Add to bag';
  root.dataset.vcProductId = '11306'; root.vcHomeOpener = {}; root.querySelector = selector => selector === '[data-vc-quick-buy]' ? mainBuy : null;
  document.querySelectorAll = selector => selector === 'dialog[open]' ? dialog.open ? [dialog] : [] : [];
  const $ = target => target === document.body ? {
    on(name, handler) { bodyHandlers.set(name, handler); },
    trigger(name, args = []) { trace.push('native:' + name); for (const [key, handler] of bodyHandlers) if (key.split('.')[0] === name) handler({}, ...args); },
    off(namespace) { for (const key of bodyHandlers.keys()) if (key.endsWith(namespace)) bodyHandlers.delete(key); }
  } : { 0: target };
  Object.assign(window, { document, jQuery: $, vcLiveCommerce: { cartFeedback: { config: { enabled: true, ...config } } }, matchMedia: () => ({ matches: false }), setTimeout, clearTimeout,
    createVcConfirmedPurchaseReturn(options) { const api = createVcConfirmedPurchaseReturn({ ...options, window, document }); const confirm = api.confirm; api.confirm = (...args) => { trace.push('return:confirm'); return confirm(...args); }; returnController = api; return api; },
    createLiveCartFeedback() { return { celebrate() { trace.push('firework'); }, destroy() {} }; }
  });
  toolbarClose.addEventListener('click', () => { trace.push('native:close'); dialog.open = false; root.isConnected = false; dialog.dispatchEvent({ type: 'close' }); });
  const globals = { window, document, dialog, root, form, mainAdd, toolbar, toolbarClose, productId: 11306, destroyed: false, settings: { stickyPurchaseEnabled: true }, signal: controller.signal,
    selectedLabel: () => '10mg', metaStrip: null, summary: { querySelector: () => null }, safeURL: () => '', setTimeout, clearTimeout, Promise, console,
    CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } } };
  vm.createContext(globals);
  vm.runInContext(fs.readFileSync(path.join(assets, 'product-dialog-feedback.js'), 'utf8'), globals);
  const feedbackFactory = window.createVcNativeProductFeedback;
  window.createVcNativeProductFeedback = options => { const api = feedbackFactory(options), confirm = api.confirm; api.confirm = () => { trace.push('feedback:confirm'); return confirm(); }; feedback = api; return api; };
  vm.runInContext(`(() => {${listeners}\nwindow.flowSync = syncSticky;})();`, globals);
  const cart = { items: [{ id: 11307, quantity: 1 }], items_count: 1, totals: { total_price: '4000' } };
  Object.assign(globals, { $, mutationGeneration: 0, operation: null, nonce: 'known', lastCart: null, plain: String, location: { assign(url) { trace.push('checkout:' + url); } },
    current: () => dialog.open && root.isConnected,
    snapshot(_, action) { if (form.inert || mainAdd.disabled) return null; return { parentId: '11306', payload: { id: 11307, quantity: 1 }, name: 'Tirzepatide', trigger: wrongOpener ? {} : root.vcHomeOpener, mode: action, config: { checkoutUrl: '/checkout/' }, state: 'pending' }; },
    paint() { trace.push('native:paint:' + globals.operation.state); form.inert = ['pending', 'checking', 'unknown'].includes(globals.operation.state); form.setAttribute('aria-busy', String(globals.operation.state === 'pending')); },
    async jsonRequest() { beforeResponse?.({ document, dialog }); return { response: { status: mode === 'failure' ? 400 : 201, ok: mode !== 'failure' }, data: mode === 'failure' ? { code: 'out_of_stock', message: 'Unavailable' } : mode === 'unconfirmed' ? { ...cart, items: [] } : cart }; },
    async readCart() { globals.applyCart(cart, false); return { cart, applied: true }; },
    observeBuyNow() { trace.push('native:buy-observed'); }, nonceErrors: []
  });
  vm.runInContext(native, globals);
  // Model the native registrations. The genuine experience capture listener must
  // run before the plugin's submit listener and its immediate pending paint.
  form.addEventListener('submit', event => { event.preventDefault(); nativePromise = globals.submit({}, 'add'); });
  mainBuy.addEventListener('click', () => { nativePromise = globals.submit({}, 'buy'); });
  return { trace, document, dialog, root, form, mainAdd, mainBuy, feedback, returnController, tick,
    async add() { form.dispatchEvent({ type: 'submit', submitter: mainAdd }); await nativePromise; await Promise.resolve(); window.flowSync(); },
    async buy() { mainBuy.click(); await nativePromise; await Promise.resolve(); window.flowSync(); },
    added(opener = root.vcHomeOpener) { $(document.body).trigger('added_to_cart', [{}, '', $(opener)]); },
    stop() { controller.abort(); feedback.destroy(); returnController.destroy(); bodyHandlers.clear(); timers.clear(); } };
}
let passed = 0;
async function check(name, test) { const f = fixture(test.options); try { await test(f); passed++; console.log('ok', name); } finally { f.stop(); } }
(async () => {
  await check('actual native 201 sequence paints success after feedback and return confirmation, then closes asynchronously', async f => {
    await f.add(); const a = f.trace.indexOf('feedback:confirm'), b = f.trace.indexOf('return:confirm'), c = f.trace.indexOf('native:paint:success');
    assert.ok(a >= 0 && b > a && c > b); assert.equal(f.trace.filter(x => x === 'firework').length, 1); assert.equal(f.dialog.open, true);
    f.tick(849); assert.equal(f.dialog.open, true); f.tick(1); assert.equal(f.dialog.open, false); assert.equal(f.trace.filter(x => x === 'native:close').length, 1);
  });
  const buy = async f => { await f.buy(); f.tick(2000); assert.ok(f.trace.includes('checkout:/checkout/')); assert.equal(f.trace.includes('return:confirm'), false); assert.equal(f.trace.includes('firework'), false); assert.equal(f.dialog.open, true); }; await check('actual native Buy keeps the existing checkout redirect and cannot auto-return', buy);
  for (const mode of ['failure', 'unconfirmed']) { const run = async f => { await f.add(); f.added(); f.tick(2000); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('return:confirm'), false); assert.equal(f.trace.includes('firework'), false); }; run.options = { mode }; await check(`${mode} native response leaves the product and recovery UI open despite an unrelated late success event`, run); }
  const stale = async f => { await f.add(); f.tick(2000); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('return:confirm'), false); assert.equal(f.trace.includes('firework'), false); assert.ok(f.trace.includes('native:paint:unknown')); }; stale.options = { beforeResponse: ({ document }) => document.dispatchEvent({ type: 'vc:cart-mutation-start', detail: { source: 'live-cart' } }) }; await check('native concurrent-mutation stale success never becomes a return confirmation', stale);
  const wrong = async f => { await f.add(); f.tick(2000); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('return:confirm'), false); assert.equal(f.trace.includes('firework'), false); }; wrong.options = { wrongOpener: true }; await check('added_to_cart from another opener cannot confirm this product flow', wrong);
  await check('duplicate added_to_cart cannot duplicate a completed confirmation or close', async f => { await f.add(); f.added(); f.tick(2000); f.added(); assert.equal(f.trace.filter(x => x === 'return:confirm').length, 1); assert.equal(f.trace.filter(x => x === 'native:close').length, 1); });
  await check('an explicit later pointer interaction cancels auto-return even if a duplicate success arrives', async f => { await f.add(); f.dialog.dispatchEvent({ type: 'pointerdown' }); f.added(); f.tick(2000); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('native:close'), false); });
  const pendingCancel = async f => { await f.add(); f.tick(2000); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('native:close'), false); assert.equal(f.returnController.state, 'idle'); }; pendingCancel.options = { beforeResponse: ({ dialog }) => dialog.dispatchEvent({ type: 'pointerdown' }) }; await check('a canceled pending return ticket cannot close the product when its actual confirmation arrives later', pendingCancel);
  await check('Buy after a confirmed Add cancels the scheduled return and preserves native checkout ownership', async f => { await f.add(); await f.buy(); f.tick(2000); assert.ok(f.trace.includes('checkout:/checkout/')); assert.equal(f.dialog.open, true); assert.equal(f.trace.includes('native:close'), false); });
  const disabled = async f => { await f.add(); assert.equal(f.trace.includes('firework'), false); assert.equal(f.feedback.presentation, null); assert.equal(f.dialog.open, true); f.tick(0); assert.equal(f.dialog.open, false); }; disabled.options = { config: { enabled: false } }; await check('disabled feedback app remains effect-free while confirmed return happens after native paint', disabled);
  await check('feedback presentation is a copied operation snapshot, not the latest mutable setting', async f => { await f.add(); const presentation = f.feedback.presentation; presentation.enabled = false; f.feedback.configure({ enabled: false }); assert.equal(f.feedback.presentation.enabled, true); f.tick(850); assert.equal(f.dialog.open, false); });
  console.log(`${passed} native purchase flow integration checks passed`);
})().catch(error => { console.error(error); process.exitCode = 1; });
