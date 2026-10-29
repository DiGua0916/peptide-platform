// Run with Node.js. Real observer + adapter, isolated VM, fake clock and HTTP.
// No browser, WordPress state or network is used.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {webcrypto} = require('node:crypto');
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; }
class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatchEvent(event) { for (const fn of [...(this.listeners.get(event.type) || [])]) fn(event); }
}
const drain = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
function fixture(overrides = {}, options = {}) {
  let now = Date.parse('2026-10-27T12:00:00Z'), nextTimer = 0, aborts = 0, videoStops = 0;
  const timers = new Map(), calls = [], responses = [], store = new Map();
  const document = new Target(); document.visibilityState = 'visible'; document.hidden = false;
  const window = new Target();
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++nextTimer; timers.set(id, {fn, due: now + (Number(ms) || 0), interval}); return id; };
  Object.assign(window, {
    window, document, crypto: webcrypto, location: new URL('https://visitor.test/product/item/?private=SECRET#SECRET'),
    URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id),
    setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
    sessionStorage: {getItem: key => { if (options.storageBlocked) throw Error('blocked'); return store.get(key); }, setItem: (key, val) => store.set(key, val)},
    VialCoveVisitorPage: {page_type: 'product', product_id: 101, product_name: 'Synthetic item', path: '/product/item/?private=SECRET#SECRET'},
    VialCoveVisitorConfig: {enabled: true, protocol: 1, test_mode: false, mode: 'live', endpoint: 'https://visitor.test/wp-json/peptide/v1/track/events', heartbeat_ms: 15000, idle_ms: 60000, batch_max: 20, ...overrides},
    VialCoveVisitorVideo: {observe: () => ({flush() {}, stop: () => videoStops++})},
    fetch: async (url, init) => {
      calls.push({url, ...init, body: JSON.parse(init.body)});
      const scenario = responses.shift() || {};
      if (scenario.network) throw Error('Temporary network failure');
      if (scenario.pending) return new Promise((resolve, reject) => init.signal.addEventListener('abort', () => { aborts++; reject(new DOMException('Aborted', 'AbortError')); }, {once: true}));
      const status = scenario.status || 200;
      const data = scenario.data || {protocol: 1, ack: calls.at(-1).body.events.map(e => e.event_id), rejected: []};
      return {status, ok: status >= 200 && status < 300, headers: new Headers(scenario.headers || {}), json: async () => data};
    }
  });
  const context = vm.createContext(window);
  for (const name of ['visitor-tracking.js', 'visitor-adapter.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});
  async function advance(ms) {
    const end = now + ms;
    await drain();
    for (let loops = 0; loops < 10000; loops++) {
      const first = [...timers].filter(([,t]) => t.due <= end).sort((a,b) => a[1].due - b[1].due)[0];
      if (!first) { now = end; await drain(); return; }
      const [id,t] = first; now = t.due;
      if (t.interval) t.due += t.interval; else timers.delete(id);
      t.fn(); await drain();
    }
    throw Error('Timer loop did not settle');
  }
  return {window, document, calls, responses, timers, store, advance,
    stats: () => window.VialCoveVisitorTransport.getStats(),
    action: () => document.dispatchEvent({type: 'vc:visitor-action', detail: {type: 'cart_open', data: {source: 'fixture', email: 'SECRET'}}}),
    counters: () => ({aborts, videoStops}), stop: () => window.VialCoveVisitorTransport.stop()};
}
async function run() {
  for (const mode of [{test_mode: false, mode: 'live'}, {test_mode: true, mode: 'test'}, {test_mode: undefined, mode: undefined}]) {
    const f = fixture(mode); await f.advance(300);
    check('Enabled protocol 1 starts in live, legacy test and unspecified mode', f.calls.length === 1 && f.stats().acknowledged === 2 && f.stats().queued === 0);
    const c = f.calls[0];
    check('Same-origin JSON protocol, no backend identity fabricated', c.credentials === 'same-origin' && c.mode === 'same-origin' && c.body.protocol === 1 && !('visitor_id' in c.body));
    check('No URL query/hash or unknown form data collected', !JSON.stringify(c.body).includes('SECRET'));
    check('Per-tab ID persists', f.store.get('vc_tab_id') === c.body.tab_id);
    await f.advance(15000); check('Configured heartbeat remains active', f.calls.length === 2 && f.calls[1].body.events[0].type === 'heartbeat');
    f.stop(); check('Explicit stop clears queue and timers', f.stats().stopped && f.stats().queued === 0 && f.timers.size === 0);
  }
  for (const config of [{enabled:false}, {enabled:1}, {enabled:'true'}, {protocol:2}, {protocol:'1'}, {protocol:null}, {endpoint:''}, {endpoint:' '}, {endpoint:null}, {endpoint:{}}, {endpoint:'https://elsewhere.test/track'}, {endpoint:'javascript:alert(1)'}, {endpoint:'https://name:password@visitor.test/track'}]) {
    const f = fixture(config); await f.advance(60000);
    check('Disabled/invalid protocol/unsafe endpoint remains inert: ' + JSON.stringify(config), f.stats().stopped && f.calls.length === 0 && f.timers.size === 0 && f.store.size === 0);
  }
  for (const code of ['pc_track_declined', 'pc_track_disabled', 'pc_track_staff']) {
    const f = fixture(); f.responses.push({status:403, data:{code, data:{status:403}}});
    for (let i=0; i<25; i++) f.action();
    await f.advance(300);
    check(code + ' stops entire observer, including events outside submitted batch', f.stats().stopped && f.stats().queued === 0 && f.timers.size === 0 && f.counters().videoStops === 1);
    f.action(); f.window.dispatchEvent({type:'online'}); f.window.dispatchEvent({type:'pageshow', persisted:true});
    f.document.dispatchEvent({type:'visibilitychange'}); f.window.dispatchEvent({type:'pagehide', persisted:false});
    await f.advance(120000);
    check(code + ' never retries or restarts via lifecycle events', f.calls.length === 1 && f.stats().queued === 0);
  }
  {
    const f = fixture(); f.responses.push({pending:true}); await f.advance(300); f.action();
    f.responses.push({status:403, data:{code:'pc_track_declined'}}); f.window.dispatchEvent({type:'pagehide', persisted:false}); await drain();
    check('Opt-out rejection on exit cancels an already in-flight request', f.counters().aborts === 1 && f.stats().stopped && f.stats().queued === 0);
    await f.advance(120000); check('Neither in-flight nor exit queue retries after denial', f.calls.length === 2 && f.timers.size === 0);
  }
  {
    const f = fixture(); f.responses.push({network:true}); await f.advance(300);
    check('Transient network failure retains events', f.stats().queued === 2 && !f.stats().stopped);
    await f.advance(1100); check('Network retry keeps original IDs, then empties queue', f.calls.length === 2 && f.stats().acknowledged === 2 && JSON.stringify(f.calls[0].body.events) === JSON.stringify(f.calls[1].body.events)); f.stop();
  }
  {
    const f = fixture(); f.responses.push({status:429,headers:{'Retry-After':'30'}}); await f.advance(300); f.action();
    await f.advance(20000); check('429 cooldown covers new events and heartbeat', f.calls.length === 1 && f.stats().queued >= 3);
    await f.advance(11000); check('429 resumes only after deadline', f.calls.length > 1 && f.stats().queued === 0); f.stop();
  }
  {
    const f = fixture(); f.responses.push({status:400}); await f.advance(300);
    check('400 drops invalid batch without acknowledging or disabling future valid events', f.stats().queued === 0 && f.stats().acknowledged === 0 && f.stats().dropped === 2 && !f.stats().stopped); f.stop();
  }
  {
    const f = fixture(); f.responses.push({status:413}, {}, {network:true}); f.action(); f.action(); await f.advance(300);
    check('413 split preserves partial ACK and retains only failed half', f.calls.map(c=>c.body.events.length).join(',') === '4,2,2' && f.stats().acknowledged === 2 && f.stats().queued === 2);
    await f.advance(1100); check('Partial retry sends only unacknowledged half', f.stats().acknowledged === 4 && JSON.stringify(f.calls[2].body.events) === JSON.stringify(f.calls[3].body.events)); f.stop();
  }
  {
    const f = fixture({}, {storageBlocked:true}); await f.advance(300);
    check('Blocked storage safely omits tab ID for server page-ID fallback', f.calls.length === 1 && !('tab_id' in f.calls[0].body)); f.stop();
  }
  {
    const f = fixture(); await f.advance(300); f.window.dispatchEvent({type:'pagehide',persisted:false}); await drain();
    check('Normal page exit sends keepalive and page_exit', f.calls.at(-1).keepalive && f.calls.at(-1).body.events.some(e=>e.type==='page_exit')); f.stop();
  }
  console.log(JSON.stringify({suite:'visitor-transport', passed:checks, failed:0}));
}
run().catch(error => { console.error(error); process.exitCode=1; });
