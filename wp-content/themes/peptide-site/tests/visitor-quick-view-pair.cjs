'use strict';
// Complete production collector, fake platform and in-memory read-only bridge.
// No browser, network, private plans, WordPress or database are used.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const plain = value => JSON.parse(JSON.stringify(value));
const drain = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatchEvent(event) { for (const fn of [...(this.listeners.get(event.type) || [])]) fn(event); }
}
const quick = (event_id = 'quick-pair-000001') => ({event_id, version: 1, product_id: 101, variation_id: 303, kind: 'quick_view_open', result: 'completed'});
function fixture(options = {}) {
  const document = new Target(), window = new Target(), timers = new Map(), calls = [], retirements = [], deliveries = [];
  let now = Date.parse('2026-11-01T04:00:00Z'), serial = 0, ids = 0;
  document.visibilityState = 'visible'; document.hidden = false;
  const crypto = {fail: false, getRandomValues(array) { array.fill(8); return array; }, randomUUID() { if (crypto.fail) throw Error('random generation blocked'); return '12345678-1234-4234-8234-' + String(++ids).padStart(12, '0'); }};
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = fn => { const key = ++serial; timers.set(key, fn); return key; };
  Object.assign(window, {window, document, crypto, navigator: {}, location: new URL('https://pair.test/shop/?private=OMIT'), URL, Date: ClockDate, Uint8Array, AbortController,
    setTimeout: later, clearTimeout: key => timers.delete(key), setInterval: later, clearInterval: key => timers.delete(key)});
  const file = path.join(__dirname, '../assets/visitor-tracking.js'), source = fs.readFileSync(file, 'utf8');
  const marker = '    const api = {flush, stop, pushProductAction, getStats:';
  assert.equal(source.split(marker).length, 2, 'one private API construction boundary');
  const bridge = `    window.__pairRead = () => ({maxQueue,seq,pending:pending.map(entry => ({record:entry.record,inFlight:entry.inFlight,attempts:entry.attempts,readyAt:entry.readyAt})),actions:[...productActions],stats:{...stats}});\n`;
  vm.runInContext(source.replace(marker, bridge + marker), vm.createContext(window), {filename: file});
  const api = window.VialCoveVisitorObserver.start({context: {page_type: 'shop', path: '/shop/?private=OMIT'}, productActionsProtocol: 1, maxQueue: options.maxQueue ?? 10, batchMax: options.batchMax ?? 20,
    ...(options.metadata ? {pageViewMetadata: () => ({page_context: {version: 1, kind: 'shop'}})} : {}),
    onEventRetired: (id, reason) => retirements.push({id, reason}),
    sendBatch(events, hint) { calls.push({events: plain(events), unload: hint.unload}); if (options.hold) return new Promise(resolve => deliveries.push(() => resolve({ackEventIds: events.map(e => e.event_id)}))); return Promise.resolve({ackEventIds: options.noAck ? [] : events.map(e => e.event_id)}); }
  });
  assert.ok(api);
  const f = {api, window, document, crypto, calls, retirements, deliveries, read: () => plain(window.__pairRead()), advance: ms => { now += ms; }, fill(count) {
    let next = 0;
    while (f.read().pending.length < count) { const id = 'favorite-seed-' + String(++next).padStart(8, '0'); assert.equal(api.pushProductAction('product_favorite', {version: 1, product_id: 202, action: 'add', event_id: id}), id); }
  }};
  return f;
}
function pairAtEnd(f, uid = 'quick-pair-000001') {
  const pending = f.read().pending, action = pending.at(-2).record, view = pending.at(-1).record;
  assert.equal(action.type, 'product_intent'); assert.equal(action.event_id, uid);
  assert.equal(view.type, 'product_view'); assert.notEqual(view.event_id, uid);
  assert.deepEqual(view.data, {product_id: 101});
  assert.equal(view.seq, action.seq + 1); assert.equal(view.occurred_at, action.occurred_at); assert.equal(view.page_instance_id, action.page_instance_id);
  assert.deepEqual(view.page, action.page); assert.deepEqual(view.page, {page_type: 'shop', path: '/shop/'});
  return [action, view];
}

test('minimum queue is ten and accepted pair shares native page/time with sequential sequence', async () => {
  const f = fixture({maxQueue: 1}); assert.equal(f.read().maxQueue, 10);
  assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); const pair = pairAtEnd(f);
  const before = f.read(); assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); assert.deepEqual(f.read(), before);
  await f.api.flush(); assert.equal(f.read().pending.length, 0); assert.equal(f.retirements.filter(x => pair.some(e => e.event_id === x.id) && x.reason === 'acknowledged').length, 2);
  const acked = f.read(); assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); assert.deepEqual(f.read(), acked, 'ACKed tombstone deduplicates both records'); f.api.stop();
});

test('all-inflight full queue refuses pair without half records, sequence, tombstone or retirement mutations', async () => {
  const f = fixture({hold: true}); f.fill(10); const delivery = f.api.flush(); await drain(); assert.equal(f.read().pending.filter(e => e.inFlight).length, 10);
  const before = f.read(); assert.equal(f.api.pushProductAction('product_intent', quick()), false); assert.deepEqual(f.read(), before); assert.deepEqual(f.retirements, []);
  f.deliveries.shift()(); await delivery; f.api.stop();
});

test('one free slot or only one removable record cannot enqueue a half pair or evict anything', async () => {
  for (const count of [9, 10]) {
    const f = fixture({hold: true, batchMax: 9}); f.fill(count); const delivery = f.api.flush(); await drain(); assert.equal(f.read().pending.filter(e => e.inFlight).length, 9);
    const before = f.read(); assert.equal(f.api.pushProductAction('product_intent', quick()), false); assert.deepEqual(f.read(), before); assert.deepEqual(f.retirements, []);
    f.deliveries.shift()(); await delivery; f.api.stop();
  }
});

test('pair evicts exactly minimum oldest non-inflight records and preserves all protected records', async () => {
  for (const count of [9, 10]) {
    const f = fixture({hold: true, batchMax: 3}); f.fill(count); const delivery = f.api.flush(); await drain(); const before = f.read(), needed = count + 2 - 10, old = before.pending.map(e => e.record.event_id), removed = old.slice(3, 3 + needed);
    assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); pairAtEnd(f); const after = f.read();
    assert.equal(after.pending.length, 10); assert.equal(after.seq, before.seq + 2); assert.equal(after.stats.created, before.stats.created + 2); assert.equal(after.stats.dropped, before.stats.dropped + needed);
    assert.deepEqual(f.retirements, removed.map(id => ({id, reason: 'overflow'})));
    assert.deepEqual(after.pending.slice(0, -2).map(e => e.record.event_id), old.filter(id => !removed.includes(id)));
    assert.ok(after.pending.slice(0, 3).every(e => e.inFlight));
    for (const id of removed) assert.equal(after.actions.find(([key]) => key === id)[1].retired, 'overflow');
    f.deliveries.shift()(); await delivery; f.api.stop();
  }
});

test('overflow retains original retirement callback and metadata/drop accounting', () => {
  const f = fixture({metadata: true}); f.fill(10); const before = f.read(), old = before.pending.slice(0, 2).map(e => e.record.event_id);
  assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); pairAtEnd(f); const after = f.read();
  assert.deepEqual(f.retirements, old.map(id => ({id, reason: 'overflow'}))); assert.equal(after.stats.dropped, before.stats.dropped + 2); assert.equal(after.stats.extensionDropped, before.stats.extensionDropped + 1); assert.equal(after.stats.created, before.stats.created + 2); f.api.stop();
});

test('throwing generated view UID leaves sequence, tombstones, queue, drops and retirements untouched', () => {
  for (const count of [1, 10]) {
    const f = fixture(); f.fill(count); f.crypto.fail = true; const before = f.read();
    assert.equal(f.api.pushProductAction('product_intent', quick()), false); assert.deepEqual(f.read(), before); assert.deepEqual(f.retirements, []);
    f.crypto.fail = false; assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); pairAtEnd(f); f.api.stop();
  }
});

test('lost ACK and BFCache retain both immutable records and same-UID tombstone prevents another pair', async () => {
  const f = fixture({noAck: true}); f.api.pushProductAction('product_intent', quick()); const pair = pairAtEnd(f); await f.api.flush();
  f.window.dispatchEvent({type: 'pagehide', persisted: true}); await drain();
  for (const record of pair) { const sent = f.calls.flatMap(c => c.events).filter(e => e.event_id === record.event_id); assert.ok(sent.length >= 2); assert.ok(sent.every(e => JSON.stringify(e) === JSON.stringify(record))); }
  assert.ok(f.calls.some(c => c.unload === true));
  assert.equal(f.api.pushProductAction('product_intent', quick('quick-while-suspended')), false);
  f.window.dispatchEvent({type: 'pageshow', persisted: true}); const before = f.read(); assert.equal(f.api.pushProductAction('product_intent', quick()), quick().event_id); assert.deepEqual(f.read(), before);
  const next = 'quick-reopened-00001'; assert.equal(f.api.pushProductAction('product_intent', quick(next)), next); const reopened = pairAtEnd(f, next); assert.notEqual(reopened[0].page_instance_id, pair[0].page_instance_id); f.api.stop();
});
