/* Engagement gate of the visitor observer (assets/visitor-tracking.js, added 2026-11-08):
 * nothing is sent until the person interacts (a trusted pointer / key / scroll / touch event)
 * or the page has stayed open and visible for engageMs (6 s by default).
 * The other visitor-*.cjs suites start their observers already engaged (engageMs: 0), so this
 * file is where the gate itself is checked. Real observer in a VM; only the clock is fake. */
'use strict';
const test = require('node:test');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');

class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatchEvent(event) { for (const fn of [...(this.listeners.get(event.type) || [])]) fn(event); }
}
const drain = async () => { for (let i = 0; i < 50; i++) await Promise.resolve(); };

function observer(options = {}) {
  let now = Date.parse('2026-11-10T12:00:00Z'), timer = 0;
  const timers = new Map(), packets = [];
  const document = new Target(), window = new Target();
  document.visibilityState = options.hidden ? 'hidden' : 'visible'; document.hidden = !!options.hidden; document.referrer = '';
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++timer; timers.set(id, { fn, due: now + (Number(ms) || 0), interval }); return id; };
  Object.assign(window, {
    window, document, navigator: { userAgent: options.userAgent || 'Mozilla/5.0 (iPhone)' }, location: new URL('https://shop.test/'), crypto: webcrypto, URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id), setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
  });
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/visitor-tracking.js'), 'utf8'), vm.createContext(window), { filename: 'visitor-tracking.js' });
  const api = window.VialCoveVisitorObserver.start({
    context: { page_type: 'home', path: '/' },
    ...(options.engageMs === undefined ? {} : { engageMs: options.engageMs }),
    sendBatch: async events => { packets.push(events.map(event => event.type)); return { ackEventIds: events.map(event => event.event_id) }; },
  });
  async function advance(ms) {
    const end = now + ms;
    for (let loops = 0; loops < 10000; loops++) {
      await drain();
      const first = [...timers].filter(([, task]) => task.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
      if (!first) break;
      const [id, task] = first; now = task.due; if (task.interval) task.due += task.interval; else timers.delete(id); task.fn();
    }
    now = end; await drain();
  }
  const sent = () => packets.flat();
  return { api, document, window, advance, sent, stop: () => api?.stop?.() };
}

test('nothing is sent before the visitor interacts', async () => {
  const f = observer();
  assert.ok(f.api, 'observer started');
  await f.advance(5900);
  assert.deepEqual(f.sent(), []);
  f.stop();
});

test('a real interaction releases the queued page view at once', async () => {
  for (const type of ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart']) {
    const f = observer();
    await f.advance(1000);
    f.document.dispatchEvent({ type, isTrusted: true });
    await f.advance(50);
    assert.ok(f.sent().includes('page_view'), type + ' engages');
    f.stop();
  }
});

test('a scripted (untrusted) event is not an interaction', async () => {
  const f = observer();
  f.document.dispatchEvent({ type: 'pointerdown', isTrusted: false });
  f.document.dispatchEvent({ type: 'scroll' });
  await f.advance(3000);
  assert.deepEqual(f.sent(), []);
  f.stop();
});

test('a page left open and visible counts after six seconds', async () => {
  const f = observer();
  await f.advance(6500);
  assert.ok(f.sent().includes('page_view'));
  f.stop();
});

test('a hidden page waits until it is shown', async () => {
  const f = observer({ hidden: true });
  await f.advance(7000);
  assert.deepEqual(f.sent().filter(type => type === 'page_view'), []);
  f.document.hidden = false; f.document.visibilityState = 'visible';
  f.document.dispatchEvent({ type: 'visibilitychange' });
  await f.advance(500);
  assert.ok(f.sent().includes('page_view'));
  f.stop();
});

test('engageMs: 0 starts engaged (what the other suites use)', async () => {
  const f = observer({ engageMs: 0 });
  await f.advance(400);
  assert.ok(f.sent().includes('page_view'));
  f.stop();
});

test('headless and driven browsers never become visitors', async () => {
  const f = observer({ userAgent: 'Mozilla/5.0 HeadlessChrome/120.0' });
  if (f.api) {
    f.document.dispatchEvent({ type: 'pointerdown', isTrusted: true });
    await f.advance(7000);
  }
  assert.deepEqual(f.sent(), []);
});
