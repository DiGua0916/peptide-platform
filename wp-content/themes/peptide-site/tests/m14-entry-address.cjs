'use strict';
/* FE-02: address-bar rules of assets/entry-address.js (pure functions, no browser). */
const test = require('node:test');
const assert = require('node:assert/strict');
const { withoutKeys, hasKey, samePath, productPath, resolve } = require('../assets/entry-address.js');

const landing = (search, hash = '#vc-products') => ({ path: '/', search, hash });
const tail = 'utm_source=facebook&utm_medium=paid&fbclid=AbC';

test('withoutKeys keeps order and encoding', () => {
  assert.equal(withoutKeys('?a=1&vc_product=5&b=%20x', ['vc_product']), '?a=1&b=%20x');
  assert.equal(withoutKeys('?vc_product=5', ['vc_product']), '');
  assert.equal(withoutKeys('', ['vc_product']), '');
  assert.equal(withoutKeys('?VC_Product=5&a=1', ['vc_product']), '?a=1');
  assert.equal(withoutKeys('?a=1&&b=2', []), '?a=1&b=2');
  assert.equal(withoutKeys('?%E0%A4%A=1&a=2', ['vc_product']), '?%E0%A4%A=1&a=2');
});

test('hasKey', () => {
  assert.equal(hasKey('?a=1&vc_catalog_category=3', 'vc_catalog_category'), true);
  assert.equal(hasKey('?a=1', 'vc_catalog_category'), false);
  assert.equal(hasKey('', 'vc_product'), false);
});

test('samePath accepts only same-origin http(s) links', () => {
  const origin = 'https://shop.example';
  assert.equal(samePath('https://shop.example/product/bpc-157/', origin), '/product/bpc-157/');
  assert.equal(samePath('https://SHOP.example/product/x/?a=1#top', origin), '/product/x/');
  assert.equal(samePath('https://shop.example', origin), '/');
  assert.equal(samePath('https://other.example/product/x/', origin), '');
  assert.equal(samePath('javascript:alert(1)', origin), '');
  assert.equal(samePath('/product/x/', origin), '');
  assert.equal(samePath('', origin), '');
  assert.equal(samePath("https://shop.example/a'b/", origin), '');
});

test('open product dialog shows the product permalink and keeps ad parameters', () => {
  assert.equal(resolve({ base: landing('?vc_product=12&' + tail), homePath: '/', productPath: '/product/bpc-157/', productId: 12 }), '/product/bpc-157/?' + tail);
  assert.equal(resolve({ base: landing(''), homePath: '/', productPath: '/product/bpc-157/', productId: 12 }), '/product/bpc-157/');
  // Opened from a collection landing: the product address, without the collection entry.
  assert.equal(resolve({ base: landing('?vc_catalog_category=4&utm_source=ig'), homePath: '/', categoryPath: '/collections/best/', productPath: '/product/x/', productId: 3 }), '/product/x/?utm_source=ig');
});

test('unknown permalink falls back to the entry link the server already accepts', () => {
  assert.equal(resolve({ base: landing('?' + tail), homePath: '/', productPath: '', productId: 12 }), '/?vc_product=12&' + tail);
  assert.equal(resolve({ base: landing('?vc_product=9'), homePath: '/shop-root/', productPath: '', productId: 12 }), '/shop-root/?vc_product=12');
});

test('closed dialog restores the page address without the product entry', () => {
  assert.equal(resolve({ base: landing('?vc_product=12&' + tail), homePath: '/', productPath: '', productId: 0 }), '/?' + tail + '#vc-products');
  assert.equal(resolve({ base: { path: '/collections/', search: '', hash: '' }, homePath: '/', productPath: '', productId: 0 }), '/collections/');
  assert.equal(resolve({ base: landing('', ''), homePath: '/', productPath: '', productId: 0 }), '/');
});

test('collection landing shows the collection permalink', () => {
  assert.equal(resolve({ base: landing('?vc_catalog_category=4&' + tail), homePath: '/', categoryPath: '/collections/best/', productPath: '', productId: 0 }), '/collections/best/?' + tail + '#vc-products');
  // No permalink known for the collection: leave the entry address alone.
  assert.equal(resolve({ base: landing('?vc_catalog_category=4'), homePath: '/', categoryPath: '', productPath: '', productId: 0 }), '/?vc_catalog_category=4#vc-products');
  // A search entry has no address of its own.
  assert.equal(resolve({ base: landing('?vc_catalog_category=4&vc_catalog_q=bpc'), homePath: '/', categoryPath: '/collections/best/', productPath: '', productId: 0 }), '/?vc_catalog_category=4&vc_catalog_q=bpc#vc-products');
});

test('start() does nothing without the store switch', () => {
  const calls = [];
  const win = { document: { addEventListener: () => calls.push('listen'), querySelector: () => null }, history: { replaceState: () => calls.push('replace') }, location: { pathname: '/', search: '', hash: '' }, addEventListener: () => calls.push('listen'), setTimeout: () => calls.push('timer'), clearTimeout: () => {} };
  require('../assets/entry-address.js').start(win);
  assert.deepEqual(calls, []);
});

test('start() rewrites the current entry only, with the native method', () => {
  const written = [];
  const timers = [];
  const dialog = { open: true, querySelector: sel => sel.startsWith('a.') ? { href: 'https://shop.example/product/bpc-157/' } : { getAttribute: () => '12' } };
  const win = {
    vcEntryAddress: { enabled: true, home: '/', category: '' },
    document: { addEventListener: () => {}, querySelector: () => dialog },
    History: { prototype: { replaceState(state, title, url) { written.push(['native', state, url]); } } },
    history: { state: { layers: 1 }, replaceState: () => written.push(['wrapped']) , pushState: () => written.push(['push']) },
    location: { pathname: '/', search: '?vc_product=12&utm_source=fb', hash: '#vc-products', origin: 'https://shop.example' },
    addEventListener: () => {}, setTimeout: fn => { timers.push(fn); return timers.length; }, clearTimeout: () => {},
  };
  require('../assets/entry-address.js').start(win);
  timers[0]();
  assert.deepEqual(written, [['native', { layers: 1 }, '/product/bpc-157/?utm_source=fb']]);
  // Dialog closed: back to the page address without the entry parameter.
  dialog.open = false; written.length = 0;
  win.location.pathname = '/product/bpc-157/'; win.location.search = '?utm_source=fb'; win.location.hash = '';
  timers[0]();
  assert.deepEqual(written, [['native', { layers: 1 }, '/?utm_source=fb#vc-products']]);
});

test('productPath takes the first usable same-origin link that is not the home page', () => {
  const origin = 'https://shop.example';
  assert.equal(productPath(['https://shop.example/product/bpc-157/'], origin, '/'), '/product/bpc-157/');
  assert.equal(productPath(['https://other.example/product/x/', 'https://shop.example/', 'https://shop.example/product/y/?a=1'], origin, '/'), '/product/y/');
  assert.equal(productPath(['https://shop.example/'], origin, '/'), '');
  assert.equal(productPath([], origin, '/'), '');
  assert.equal(productPath(undefined, origin, '/'), '');
});

// The real product dialog has no details link: the permalink comes from the product's card on
// the page, or from the server for the product the page was entered through.
function realWindow({ cards = [], product = null, search = '' }) {
  const written = [];
  const timers = [];
  const dialog = { open: true, querySelector: sel => sel.startsWith('a.') ? null : { getAttribute: () => '10' } };
  const asked = [];
  const win = {
    vcEntryAddress: { enabled: '1', home: '/', category: '', product },
    document: { addEventListener: () => {}, querySelector: () => dialog, querySelectorAll: sel => { asked.push(sel); return cards; } },
    History: { prototype: { replaceState(state, title, url) { written.push(url); } } },
    history: { state: null, replaceState: () => written.push('wrapped') },
    location: { pathname: '/', search, hash: '', origin: 'https://shop.example' },
    addEventListener: () => {}, setTimeout: fn => { timers.push(fn); return timers.length; }, clearTimeout: () => {},
  };
  require('../assets/entry-address.js').start(win);
  timers[0]();
  return { written, asked };
}

test('start() reads the permalink from the product card on the page', () => {
  const { written, asked } = realWindow({ cards: [{ href: 'https://shop.example/product/demo-peptide-a/' }], search: '?utm_source=fb' });
  assert.deepEqual(written, ['/product/demo-peptide-a/?utm_source=fb']);
  assert.deepEqual(asked, ['a[data-vc-quick-open="10"][href], a[data-vc-home-product="10"][href]']);
});

test('start() uses the server-provided permalink of the entered product', () => {
  assert.deepEqual(realWindow({ product: { id: 10, path: '/product/demo-peptide-a/' }, search: '?vc_product=10&fbclid=x' }).written, ['/product/demo-peptide-a/?fbclid=x']);
  // Another product is open than the one the page was entered through, and it has no card.
  assert.deepEqual(realWindow({ product: { id: 11, path: '/product/other/' }, search: '?vc_product=11' }).written, ['/?vc_product=10']);
  // A path that does not start with a slash is never joined onto the origin.
  assert.deepEqual(realWindow({ product: { id: 10, path: '.evil.example/x' }, search: '' }).written, ['/?vc_product=10']);
});
