'use strict';
/* FE-03: "viewed / added to bag" line of assets/product-activity.js. Real numbers only. */
const test = require('node:test');
const assert = require('node:assert/strict');
const { count, lines, endpoint, start } = require('../assets/product-activity.js');

const answer = (viewing, added, more = {}) => ({ product_id: 10, viewing, added, window: { viewing_minutes: 10, added_hours: 24 }, ...more });

test('count accepts only whole, non-negative numbers', () => {
  assert.equal(count(7), 7);
  assert.equal(count(0), 0);
  for (const bad of [-1, 2.5, '7', null, undefined, NaN, Infinity, 1000001, true, [7]]) assert.equal(count(bad), null);
});

test('numbers at or above the minimum are shown exactly as received', () => {
  assert.deepEqual(lines(answer(12, 31), 10, 5), ['12 people viewed this in the last 10 minutes', '31 added to bag in the last 24 hours']);
  assert.deepEqual(lines(answer(5, 5), 10, 5), ['5 people viewed this in the last 10 minutes', '5 added to bag in the last 24 hours']);
});

test('a number below the minimum is left out; a quiet product shows nothing', () => {
  assert.deepEqual(lines(answer(4, 31), 10, 5), ['31 added to bag in the last 24 hours']);
  assert.deepEqual(lines(answer(12, 2), 10, 5), ['12 people viewed this in the last 10 minutes']);
  assert.deepEqual(lines(answer(4, 4), 10, 5), []);
  assert.deepEqual(lines(answer(0, 0), 10, 1), []);
  // A higher minimum set by the store.
  assert.deepEqual(lines(answer(12, 31), 10, 20), ['31 added to bag in the last 24 hours']);
});

test('the wording states the window the server counted', () => {
  assert.deepEqual(lines(answer(9, 9, { window: { viewing_minutes: 1, added_hours: 1 } }), 10, 5), ['9 people viewed this in the last 1 minute', '9 added to bag in the last 1 hour']);
  assert.deepEqual(lines(answer(9, 9, { window: { viewing_minutes: 30, added_hours: 72 } }), 10, 5), ['9 people viewed this in the last 30 minutes', '9 added to bag in the last 72 hours']);
  // No window, or one that makes no sense: the number has no honest sentence, so it is not shown.
  assert.deepEqual(lines(answer(9, 9, { window: undefined }), 10, 5), []);
  assert.deepEqual(lines(answer(9, 9, { window: { viewing_minutes: 0, added_hours: 500 } }), 10, 5), []);
  assert.deepEqual(lines(answer(9, 9, { window: { viewing_minutes: 10 } }), 10, 5), ['9 people viewed this in the last 10 minutes']);
});

test('anything that is not the expected answer shows nothing', () => {
  for (const bad of [null, undefined, 'x', 7, [], {}, { product_id: 11, viewing: 9, added: 9, window: { viewing_minutes: 10, added_hours: 24 } },
    answer('12', '31'), answer(12.5, -3), answer(null, undefined), { code: 'rest_no_route', message: 'No route', data: { status: 404 } }]) {
    assert.deepEqual(lines(bad, 10, 5), []);
  }
  // An unusable minimum never lets small numbers through by accident: it becomes 1, the lowest allowed.
  assert.deepEqual(lines(answer(1, 0), 10, NaN), ['1 person viewed this in the last 10 minutes']);
});

test('endpoint is the configured same-origin address with the product id', () => {
  const origin = 'https://shop.example';
  assert.equal(endpoint('https://shop.example/wp-json/peptide/v1/product-activity', 10, origin), 'https://shop.example/wp-json/peptide/v1/product-activity?product_id=10');
  assert.equal(endpoint('https://shop.example/?rest_route=/peptide/v1/product-activity', 10, origin), 'https://shop.example/?rest_route=/peptide/v1/product-activity&product_id=10');
  assert.equal(endpoint('https://other.example/wp-json/x', 10, origin), '');
  assert.equal(endpoint('/wp-json/x', 10, origin), '');
  assert.equal(endpoint('https://shop.example/wp-json/x', 0, origin), '');
  assert.equal(endpoint('', 10, origin), '');
});

function fakeWindow({ config, respond }) {
  const listeners = {};
  const requested = [];
  const inserted = [];
  const makeEl = tag => ({ tag, className: '', children: [], textContent: '', appendChild(c) { this.children.push(c); }, parentNode: null });
  const content = { firstChild: null, insertBefore(node) { inserted.push(node); } };
  const dialogRoot = { isConnected: true, querySelector: sel => sel === '.vc-product-dialog-content' ? content : null, appendChild(node) { inserted.push(node); } };
  const win = {
    vcProductActivity: config,
    location: { origin: 'https://shop.example' },
    document: { addEventListener: (name, fn) => { listeners[name] = fn; }, createElement: makeEl },
    fetch: url => { requested.push(url); return respond(url); },
    setTimeout: () => 1, clearTimeout: () => {},
  };
  start(win);
  return { listeners, requested, inserted, dialogRoot };
}
const json = (data, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(data) });
const settle = () => new Promise(resolve => setImmediate(resolve));

test('start() does nothing without the store switch', () => {
  const { listeners } = fakeWindow({ config: undefined, respond: () => json({}) });
  assert.deepEqual(Object.keys(listeners), []);
});

test('start() shows the real numbers when a product dialog opens', async () => {
  const w = fakeWindow({ config: { enabled: '1', url: 'https://shop.example/wp-json/peptide/v1/product-activity', min: '5' }, respond: () => json(answer(12, 31)) });
  w.listeners['vc:product-presented']({ detail: { productId: 10, root: w.dialogRoot } });
  await settle(); await settle();
  assert.deepEqual(w.requested, ['https://shop.example/wp-json/peptide/v1/product-activity?product_id=10']);
  assert.equal(w.inserted.length, 1);
  assert.equal(w.inserted[0].className, 'vc-product-activity');
  assert.deepEqual(w.inserted[0].children.map(c => c.textContent), ['12 people viewed this in the last 10 minutes', '31 added to bag in the last 24 hours']);
  // The same product again within a minute: no second request.
  w.listeners['vc:product-presented']({ detail: { productId: 10, root: w.dialogRoot } });
  await settle(); await settle();
  assert.equal(w.requested.length, 1);
});

test('start() shows nothing when the endpoint is missing, fails or the product is quiet', async () => {
  for (const respond of [() => json({ code: 'rest_no_route' }, false), () => Promise.reject(new Error('offline')), () => json(answer(2, 1)), () => json('<html>')]) {
    const w = fakeWindow({ config: { enabled: '1', url: 'https://shop.example/wp-json/peptide/v1/product-activity', min: '5' }, respond });
    w.listeners['vc:product-presented']({ detail: { productId: 10, root: w.dialogRoot } });
    await settle(); await settle();
    assert.equal(w.inserted.length, 0);
  }
});
