'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {mountOfferFollow, bindOfferFollow} = require('../assets/product-dialog-offer-follow.js');

class Events {
 constructor() { this.listeners = new Map(); }
 addEventListener(type, fn, options = {}) {
  if (!this.listeners.has(type)) this.listeners.set(type, new Map());
  this.listeners.get(type).set(fn, options);
 }
 removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
 dispatch(type, extra = {}) {
  const event = {type, target:this, ...extra};
  for (const [fn, options] of [...(this.listeners.get(type) || [])]) {
   if (options?.once) this.removeEventListener(type, fn);
   fn(event);
  }
 }
 count(type) { return type ? this.listeners.get(type)?.size || 0 : [...this.listeners.values()].reduce((sum, entries) => sum + entries.size, 0); }
}
class Element extends Events {
 constructor(tag, classes = '') {
  super(); this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null;
  this.hidden = false; this.textContent = ''; this.attributes = new Map();
  const values = new Set(classes.split(/\s+/).filter(Boolean));
  this.classList = {add:(...names)=>names.forEach(name=>values.add(name)), remove:(...names)=>names.forEach(name=>values.delete(name)), contains:name=>values.has(name), toggle(name, force) { const enabled = force === undefined ? !values.has(name) : !!force; if (enabled) values.add(name); else values.delete(name); return enabled; }};
 }
 get parentElement() { return this.parentNode; }
 get isConnected() { return this.connected !== false && (!this.parentNode || this.parentNode.isConnected); }
 append(...nodes) { for (const item of nodes) { item.remove(); this.children.push(item); item.parentNode = this; } }
 prepend(...nodes) { for (const item of [...nodes].reverse()) { item.remove(); this.children.unshift(item); item.parentNode = this; } }
 insertBefore(item, next) { if (!next) return this.append(item); const index = this.children.indexOf(next); assert.ok(index >= 0); item.remove(); this.children.splice(index, 0, item); item.parentNode = this; }
 remove() { if (this.parentNode) { const siblings = this.parentNode.children; siblings.splice(siblings.indexOf(this), 1); this.parentNode = null; } }
 contains(item) { for (let at = item; at; at = at.parentNode) if (at === this) return true; return false; }
 matches(selector) { if (selector === 'dialog' || selector === 'dialog[open]') return this.tagName === 'DIALOG' && (selector === 'dialog' || this.open); const cls = selector.match(/\.([\w-]+)/g); return !!cls?.length && cls.every(name=>this.classList.contains(name.slice(1))); }
 querySelector(selector) { const direct = selector.startsWith(':scope > '); const wanted = direct ? selector.slice(9) : selector; for (const item of this.children) { if (item.matches(wanted)) return item; if (!direct) { const found = item.querySelector(wanted); if (found) return found; } } return null; }
 querySelectorAll(selector) { const found = []; for (const item of this.children) { if (item.matches(selector)) found.push(item); found.push(...item.querySelectorAll(selector)); } return found; }
 closest(selector) { for (let at = this; at; at = at.parentNode) if (at.matches(selector)) return at; return null; }
 setAttribute(name, value) { this.attributes.set(name, String(value)); }
 getAttribute(name) { return this.attributes.get(name) ?? null; }
 removeAttribute(name) { this.attributes.delete(name); }
}
function fixture() {
 const document = new Events(), window = new Events();
 const dialog = new Element('dialog', 'vc-home-quick vc-product-dialog'); dialog.open = true;
 const toolbar = new Element('div', 'vc-home-quick__toolbar');
 const close = new Element('button', 'vc-showcase-quick__close'); toolbar.append(close);
 function product() {
  const root = new Element('article', 'vc-showcase-quick-product vc-product-experience');
  const heading = new Element('header', 'vc-dialog-heading');
  const offers = new Element('section', 'vc-dialog-offers');
  offers.textContent = 'Order savings · Spend $300 · $25 off';
  heading.append(offers); root.append(heading); dialog.append(root);
  return {root, heading, offers};
 }
 dialog.append(toolbar); const initial = product();
 document.querySelectorAll = selector => dialog.matches(selector) ? [dialog] : dialog.querySelectorAll(selector);
 document.querySelector = selector => document.querySelectorAll(selector)[0] || null;
 const emit = (type, root = initial.root) => document.dispatch(type, {detail:{root, dialog}});
 return {document, window, dialog, toolbar, close, ...initial, product, emit};
}

test('moves the actual offer into the sticky toolbar without cloning or changing native values', () => {
 const f = fixture(), content = f.offers.textContent, owner = mountOfferFollow(f.root, f.dialog);
 assert.ok(owner); assert.equal(owner.offers, f.offers); assert.equal(owner.root, f.root); assert.equal(owner.dialog, f.dialog); assert.equal(owner.toolbar, f.toolbar);
 assert.equal(f.offers.parentNode, f.toolbar); assert.equal(f.offers.textContent, content); assert.equal(f.toolbar.querySelectorAll('.vc-dialog-offers').length, 1);
 assert.equal(f.toolbar.classList.contains('vc-offers-follow'), true); assert.equal(f.close.parentNode, f.toolbar);
});

test('native hidden status and subsequent offer text changes remain on the same node', () => {
 const f = fixture(); f.offers.hidden = true; const owner = mountOfferFollow(f.root, f.dialog);
 assert.equal(f.offers.hidden, true); f.offers.hidden = false; f.offers.textContent = 'Spend $500 · $55 off';
 assert.equal(owner.offers.textContent, 'Spend $500 · $55 off'); assert.equal(owner.offers.hidden, false);
 owner.destroy(); assert.equal(f.offers.hidden, false); assert.equal(f.offers.textContent, 'Spend $500 · $55 off');
});

test('destroy returns only its own offer to the heading and is safe to repeat', () => {
 const f = fixture(), owner = mountOfferFollow(f.root, f.dialog); owner.destroy(); owner.destroy();
 assert.equal(f.offers.parentNode, f.heading); assert.equal(f.toolbar.classList.contains('vc-offers-follow'), false);
 assert.equal(f.toolbar.children.length, 1); assert.equal(f.toolbar.children[0], f.close);
 const next = mountOfferFollow(f.root, f.dialog), elsewhere = new Element('aside'); elsewhere.append(f.offers); next.destroy();
 assert.equal(f.offers.parentNode, elsewhere);
});

test('incomplete or unrelated roots cannot acquire a follow header', () => {
 assert.equal(mountOfferFollow(null, null), null);
 const f = fixture(); f.offers.remove(); assert.equal(mountOfferFollow(f.root, f.dialog), null);
 f.heading.append(f.offers); f.toolbar.remove(); assert.equal(mountOfferFollow(f.root, f.dialog), null);
});

test('both presentation events share one owner and closing permits a clean cached reopen', () => {
 const f = fixture(), binding = bindOfferFollow(f.document, f.window);
 f.emit('vc:product-dialog-selection'); f.emit('vc:product-presented'); f.emit('vc:product-dialog-selection');
 assert.equal(f.toolbar.querySelectorAll('.vc-dialog-offers').length, 1); assert.equal(f.dialog.count('close'), 1);
 f.dialog.dispatch('close'); assert.equal(f.offers.parentNode, f.heading); assert.equal(f.dialog.count('close'), 0);
 f.emit('vc:product-presented'); assert.equal(f.offers.parentNode, f.toolbar); assert.equal(f.dialog.count('close'), 1);
 binding.destroy(); assert.equal(f.offers.parentNode, f.heading);
});

test('a different product in the same dialog releases the old offer before following the new one', () => {
 const f = fixture(), binding = bindOfferFollow(f.document, f.window); f.emit('vc:product-presented');
 const next = f.product(); f.emit('vc:product-dialog-selection', next.root);
 assert.equal(f.offers.parentNode, f.heading); assert.equal(next.offers.parentNode, f.toolbar); assert.equal(f.toolbar.querySelectorAll('.vc-dialog-offers').length, 1); assert.equal(f.dialog.count('close'), 1);
 binding.destroy(); assert.equal(next.offers.parentNode, next.heading); assert.equal(f.dialog.count('close'), 0);
});

test('BFCache retains the binding; final pagehide releases nodes and all its listeners', () => {
 const f = fixture(), binding = bindOfferFollow(f.document, f.window); f.emit('vc:product-presented');
 f.window.dispatch('pagehide', {persisted:true}); assert.equal(f.offers.parentNode, f.toolbar); assert.ok(f.document.count());
 f.window.dispatch('pagehide', {persisted:false}); assert.equal(f.offers.parentNode, f.heading); assert.equal(f.document.count(), 0); assert.equal(f.window.count(), 0); assert.equal(f.dialog.count(), 0);
 f.emit('vc:product-presented'); assert.equal(f.offers.parentNode, f.heading); binding.destroy();
});
