/* Landing attribution keeps the campaign / ad set / ad names from ad links (M9 ST-01 step 5).
 * Real observer + adapter in a VM; only the browser, clock and HTTP boundaries are fake. */
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

async function landingQuery(url) {
  let now = Date.parse('2026-11-10T12:00:00Z'), timer = 0;
  const href = new URL(url), timers = new Map(), calls = [];
  const document = new Target(), window = new Target();
  document.visibilityState = 'visible'; document.hidden = false; document.referrer = '';
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++timer; timers.set(id, { fn, due: now + (Number(ms) || 0), interval }); return id; };
  Object.assign(window, {
    window, document, location: { get href() { return href.href; }, get origin() { return href.origin; } }, crypto: webcrypto, URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id), setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
    sessionStorage: { getItem: () => null, setItem() {} },
    VialCoveVisitorPage: { page_type: 'home', path: '/' },
    VialCoveVisitorConfig: { enabled: true, protocol: 1, endpoint: href.origin + '/wp-json/peptide/v1/track/events', heartbeat_ms: 15000, batch_max: 20, metadata_protocols: { attribution: 1 } },
    VialCoveVisitorVideo: { observe: () => ({ flush() {}, stop() {} }) },
    fetch: async (target, init) => {
      const body = JSON.parse(init.body); calls.push(body);
      return { status: 200, ok: true, headers: new Headers(), json: async () => ({ protocol: 1, ack: body.events.map(e => e.event_id), rejected: [], extensions: {} }) };
    },
  });
  const context = vm.createContext(window);
  for (const file of ['visitor-tracking.js', 'visitor-adapter.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/' + file), 'utf8'), context, { filename: file });
  }
  await drain();
  // The observer sends nothing until a real interaction (engagement gate, 2026-11-08).
  document.dispatchEvent({ type: 'pointerdown', isTrusted: true });
  const end = now + 2000;
  for (let loops = 0; loops < 1000; loops++) {
    await drain();
    const first = [...timers].filter(([, task]) => task.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
    if (!first) break;
    const [id, task] = first; now = task.due; if (task.interval) task.due += task.interval; else timers.delete(id); task.fn();
  }
  await drain();
  window.VialCoveVisitorTransport?.stop();
  const view = calls.flatMap(body => body.events).find(event => event.type === 'page_view');
  assert.ok(view, 'a page view was sent for ' + url);
  return JSON.parse(JSON.stringify(view.data.attribution.landing_query));
}

test('ad link names are kept next to source and medium', async () => {
  assert.deepEqual(
    await landingQuery('https://shop.test/?utm_source=facebook&utm_medium=paid&utm_campaign=BF%20Sale&utm_term=US%20Women%2030%2B&utm_content=Video%201&fbclid=PRIVATE'),
    { utm_source: 'facebook', utm_medium: 'paid', utm_campaign: 'BF Sale', utm_term: 'US Women 30+', utm_content: 'Video 1' }
  );
});

test('source and medium alone still work', async () => {
  assert.deepEqual(await landingQuery('https://shop.test/?utm_source=facebook&utm_medium=paid'), { utm_source: 'facebook', utm_medium: 'paid' });
  assert.deepEqual(await landingQuery('https://shop.test/'), {});
});

test('an unusable ad name is left out and never costs the source', async () => {
  // Unreplaced ad-platform macros, empty and repeated values.
  assert.deepEqual(
    await landingQuery('https://shop.test/?utm_source=facebook&utm_medium=paid&utm_campaign=%7B%7Bcampaign.name%7D%7D&utm_term=&utm_content=a&utm_content=b'),
    { utm_source: 'facebook', utm_medium: 'paid' }
  );
  // Control characters.
  assert.deepEqual(await landingQuery('https://shop.test/?utm_source=facebook&utm_campaign=bad%0Aname'), { utm_source: 'facebook' });
});

test('a long campaign name is cut to 120 characters instead of dropping everything', async () => {
  const long = 'c'.repeat(150);
  const query = await landingQuery('https://shop.test/?utm_source=facebook&utm_campaign=' + long);
  assert.equal(query.utm_source, 'facebook');
  assert.equal(query.utm_campaign, 'c'.repeat(120));
});
