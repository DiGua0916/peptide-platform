/* CR 素材归因线 1（2026-11-11）：入口快照带上 Meta 模板的全部参数、是否带 fbclid、浏览器时区和语言。
 * 真实的 observer + adapter 跑在 VM 里；只有浏览器、时钟和 HTTP 是假的（夹具和 m14-visitor-attribution-ads.cjs 相同）。 */
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

async function attribution(url, nav = { language: 'en-US' }) {
  let now = Date.parse('2026-11-10T12:00:00Z'), timer = 0;
  const href = new URL(url), timers = new Map(), calls = [];
  const document = new Target(), window = new Target();
  document.visibilityState = 'visible'; document.hidden = false; document.referrer = '';
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++timer; timers.set(id, { fn, due: now + (Number(ms) || 0), interval }); return id; };
  Object.assign(window, {
    window, document, location: { get href() { return href.href; }, get origin() { return href.origin; } }, crypto: webcrypto, URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id), setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
    sessionStorage: { getItem: () => null, setItem() {} }, navigator: nav,
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
  return JSON.parse(JSON.stringify(view.data.attribution));
}

const TEMPLATE = 'https://shop.test/?utm_source=fb&utm_medium=paid&utm_campaign=120200000000001&utm_term=120200000000002&utm_content=120200000000003&placement=Facebook_Mobile_Feed&ad_name=C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1';

test('the full Meta template is kept', async () => {
  const a = await attribution(TEMPLATE + '&fbclid=PRIVATE_CLICK');
  assert.deepEqual(a.landing_query, {
    utm_source: 'fb', utm_medium: 'paid', utm_campaign: '120200000000001', utm_term: '120200000000002', utm_content: '120200000000003',
    placement: 'Facebook_Mobile_Feed', ad_name: 'C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1',
  });
  assert.equal(a.has_fbclid, true);
  assert.ok(!JSON.stringify(a).includes('PRIVATE_CLICK'), 'the fbclid value is never sent');
});

test('no fbclid means no flag', async () => {
  const a = await attribution(TEMPLATE);
  assert.ok(!Object.hasOwn(a, 'has_fbclid'));
});

test('timezone and language come from the browser', async () => {
  const a = await attribution('https://shop.test/');
  assert.equal(a.language, 'en-US');
  assert.equal(a.timezone, Intl.DateTimeFormat().resolvedOptions().timeZone);
});

test('unusable language or missing navigator is left out', async () => {
  assert.ok(!Object.hasOwn(await attribution('https://shop.test/', { language: 'not a language!' }), 'language'));
  assert.ok(!Object.hasOwn(await attribution('https://shop.test/', null), 'language'));
});

test('ad preview macros and over-long values are dropped one by one', async () => {
  const a = await attribution('https://shop.test/?utm_source=fb&utm_medium=paid&utm_content=%7B%7Bad.id%7D%7D&placement=' + 'p'.repeat(80) + '&ad_name=' + 'n'.repeat(300));
  assert.equal(a.landing_query.utm_source, 'fb');
  assert.ok(!Object.hasOwn(a.landing_query, 'utm_content'));
  assert.equal(a.landing_query.placement, 'p'.repeat(64));
  assert.equal(a.landing_query.ad_name, 'n'.repeat(255));
});
