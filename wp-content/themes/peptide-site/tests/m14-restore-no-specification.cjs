/*
 * Refreshing the page (or coming back to it) with a product dialog open restores that dialog.
 * For a product with specifications the price waits for the restored specification to be matched.
 * A product WITHOUT specifications has nothing to match, so before 2026-11-12 its price stayed
 * "Updating price…" for good and its offers were withheld. Runs the real restore handler and the
 * real price / pending code of assets/home-quick-view.js against small fake forms; no browser.
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/home-quick-view.js'), 'utf8');
const between = (from, to) => { const a = source.indexOf(from), b = source.indexOf(to); assert.ok(a >= 0 && b > a, 'source markers: ' + from); return source.slice(a, b); };
const restore = between("  document.addEventListener('reset', event => {", '  const templateFor = ');
const pending = between('  function price(root, markup) {', '  function quantity(root) {');

function fixture() {
  const listeners = [];
  const calls = {media: [], details: [], updates: 0};
  const context = vm.createContext({
    document: {addEventListener(type, callback, capture) { listeners.push({type, callback, capture}); }},
    homeProduct: '.vc-home-quick-product', stagedRestore: null,
    media(root, image) { calls.media.push(image); }, details(root, sku) { calls.details.push(sku); }
  });
  vm.runInContext(pending + restore, context);
  const reset = listeners.find(item => item.type === 'reset');
  assert.ok(reset && reset.capture === true, 'the restore handler listens for reset in capture');

  // selects: [{name, value, options: [...]}] — empty for a product without specifications.
  function product(id, selects, serverPrice) {
    const priceNode = {textContent: serverPrice, innerHTML: serverPrice, setAttribute() {}};
    const root = {dataset: {vcProductId: String(id)}, vcExperience: {update() { calls.updates++; }}, querySelector: selector => (selector === '.summary > .price' ? priceNode : null)};
    const fields = selects.map(item => ({name: item.name, value: item.value, options: item.options.map(value => ({value, defaultSelected: false}))}));
    const qty = {value: '1', defaultValue: '1'};
    const form = {
      closest: selector => (selector === '.vc-home-quick-product' ? root : null),
      querySelectorAll: selector => (selector === '.variations select' ? fields : []),
      querySelector: selector => (selector === '.variations select' ? fields[0] || null : selector === 'input.qty' ? qty : null)
    };
    return {root, form, fields, qty, priceNode};
  }
  return {
    calls, product,
    // What live-commerce-navigation.js does before it re-opens the dialog, then the form reset the presenter performs.
    restore(target, item) { context.stagedRestore = {item, opener: {}}; reset.callback({target: target.form}); return context.stagedRestore; }
  };
}

test('product without specifications: its restored dialog keeps the server price and offers, and is not left pending', () => {
  const f = fixture(), simple = f.product(10, [], '$40.00');
  const left = f.restore(simple, {kind: 'product', productId: 10, attributes: {}, quantity: '3'});
  assert.equal(left, null, 'the staged restore is used up');
  assert.equal(simple.qty.value, '3', 'the quantity the visitor had is still restored'); assert.equal(simple.qty.defaultValue, '3');
  assert.notEqual(simple.root.vcHomeSelectionPending, true, 'nothing is waiting for a specification match');
  assert.equal(simple.priceNode.textContent, '$40.00', 'the price is not replaced by "Updating price…"');
  assert.equal(simple.root.vcHomePrice, undefined, 'the presenter will use the server-rendered price');
  assert.equal(simple.root.vcHomePromotions, undefined, 'and the server-rendered offers');
  assert.deepEqual(f.calls.media, [], 'the artwork is not cleared');
});

test('product with specifications: unchanged — the restored specification is selected and the price waits for its match', () => {
  const f = fixture(), variable = f.product(17, [{name: 'attribute_specification', value: '', options: ['', '5mg*10vials', '10mg*10vials']}], '$40.00');
  f.restore(variable, {kind: 'product', productId: 17, attributes: {attribute_specification: '10mg*10vials'}, quantity: '2'});
  assert.equal(variable.fields[0].value, '10mg*10vials');
  assert.deepEqual(variable.fields[0].options.map(option => option.defaultSelected), [false, false, true]);
  assert.equal(variable.qty.value, '2');
  assert.equal(variable.root.vcHomeSelectionPending, true, 'still pending until the native match arrives');
  assert.equal(variable.priceNode.textContent, 'Updating price…');
  assert.equal(JSON.stringify(variable.root.vcHomePromotions), '[]', 'offers are withheld until the match'); assert.equal(variable.root.vcHomePrice, '');
  assert.ok(f.calls.media.includes(null), 'default artwork is withheld while pending');
  assert.ok(f.calls.updates >= 1);
});

test('product with specifications but nothing chosen before the refresh: unchanged — pending, and asks for a specification', () => {
  const f = fixture(), variable = f.product(21, [{name: 'attribute_specification', value: '', options: ['', 'a', 'b']}], '$30.00');
  f.restore(variable, {kind: 'product', productId: 21, attributes: {attribute_specification: ''}, quantity: '1'});
  assert.equal(variable.fields[0].value, '');
  assert.equal(variable.root.vcHomeSelectionPending, true);
  assert.equal(variable.priceNode.textContent, 'Updating price…');
});

test('a restore staged for another product does not touch this form', () => {
  const f = fixture(), simple = f.product(10, [], '$40.00');
  const left = f.restore(simple, {kind: 'product', productId: 11, attributes: {}, quantity: '5'});
  assert.notEqual(left, null, 'it stays staged for its own product');
  assert.equal(simple.qty.value, '1'); assert.equal(simple.priceNode.textContent, '$40.00');
});
