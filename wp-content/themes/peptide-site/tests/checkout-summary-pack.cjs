'use strict';
// Order-summary quantity / vials-per-box line (stress test item 4). Runs the real
// asset in a minimal DOM; the native checkout page itself is absent, so only the
// exported helpers execute.
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/checkout-polish.js'), 'utf8');

class Node {
  constructor(className = '', text = '') { this.className = className; this.children = []; this.parent = null; this._text = text; }
  get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
  set textContent(value) { this._text = String(value); this.children = []; }
  append(child) { child.parent = this; this.children.push(child); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); this.parent = null; }
  all() { return this.children.flatMap(child => [child, ...child.all()]); }
  querySelectorAll(selector) { const name = selector.replace(/^\./, ''); return this.all().filter(node => node.className.split(' ').includes(name)); }
  querySelector(selector) {
    if (selector.startsWith(':scope > .')) { const name = selector.slice(10); return this.children.find(child => child.className === name) || null; }
    return this.querySelectorAll(selector)[0] || null;
  }
}
function load() {
  const window = {};
  const document = { querySelector: () => null, createElement: () => new Node(), documentElement: { lang: 'en-US' } };
  class DOMParser { parseFromString(value) { return { body: { textContent: value.replace(/&amp;/g, '&').replace(/&#8211;/g, '–') } }; } }
  vm.runInNewContext(source, { window, document, DOMParser });
  return window.VialCoveCheckoutPack;
}
function summary(names) {
  const content = new Node('content');
  for (const name of names) {
    const row = new Node('wc-block-components-order-summary-item'), description = new Node('wc-block-components-order-summary-item__description');
    description.append(new Node('wc-block-components-product-name', name)); row.append(description); content.append(row);
  }
  return content;
}
const item = (name, quantity, vials) => ({ name, quantity, extensions: { peptide_core: { unit_pricing: vials === undefined ? null : { vialsPerSalesUnit: vials } } } });
const lines = content => content.querySelectorAll('.vc-summary-pack').map(node => node.textContent);

test('line shows box quantity and verified vials per box', () => {
  const { packLine } = load();
  assert.equal(packLine(item('A', 2, 10), false), 'Qty: 2 boxes · 10 vials per box');
  assert.equal(packLine(item('A', 1, 10), false), 'Qty: 1 box · 10 vials per box');
  assert.equal(packLine(item('A', 3, 10), true), 'Cant.: 3 cajas · 10 viales por caja');
  assert.equal(packLine(item('A', 1), false), 'Qty: 1 box', 'no unit pricing: quantity only, no invented count');
  assert.equal(packLine(item('A', 1, 1), false), 'Qty: 1 box', 'single-vial unit adds no per-box claim');
  assert.equal(packLine(item('A', 0, 10), false), '');
  assert.equal(packLine(item('A', '2', 10), false), 'Qty: 2 boxes · 10 vials per box');
});

test('rows are annotated only when order and decoded name both match', () => {
  const { annotateItems } = load();
  const content = summary(['BPC & TB – 10mg', 'Demo Peptide D']);
  annotateItems(content, [item('BPC &amp; TB &#8211; 10mg', 2, 10), item('Demo Peptide D', 1, 10)], false);
  assert.deepEqual(lines(content), ['Qty: 2 boxes · 10 vials per box', 'Qty: 1 box · 10 vials per box']);
  annotateItems(content, [item('BPC &amp; TB &#8211; 10mg', 4, 10), item('Demo Peptide D', 1, 10)], false);
  assert.deepEqual(lines(content), ['Qty: 4 boxes · 10 vials per box', 'Qty: 1 box · 10 vials per box'], 'updates in place, no duplicates');
  annotateItems(content, [item('Demo Peptide D', 1, 10), item('BPC &amp; TB &#8211; 10mg', 4, 10)], false);
  assert.deepEqual(lines(content), [], 'mismatched order removes stale lines instead of mislabelling');
  const partial = summary(['A', 'B']);
  assert.equal(annotateItems(partial, [item('A', 1, 10)], false), false, 'count mismatch reports not settled');
  assert.deepEqual(lines(partial), [], 'different row/item counts are not guessed');
});

test('rows still loading (empty names) are reported unsettled, then labelled once names render', () => {
  const { annotateItems } = load();
  const content = summary(['', '']);
  const cart = [item('Demo Peptide E', 2, 10), item('Demo Peptide C', 1, 10)];
  assert.equal(annotateItems(content, cart, false), false, 'native skeleton rows: look again');
  assert.deepEqual(lines(content), []);
  content.querySelectorAll('.wc-block-components-product-name').forEach((node, i) => { node.textContent = ['Demo Peptide E', 'Demo Peptide C'][i]; });
  assert.equal(annotateItems(content, cart, false), true, 'settled once names are present');
  assert.deepEqual(lines(content), ['Qty: 2 boxes · 10 vials per box', 'Qty: 1 box · 10 vials per box']);
  assert.equal(annotateItems(summary([]), [], false), false, 'empty cart is never "settled" (bounded retries in caller)');
});
