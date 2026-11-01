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
    navigator: options.navigator || {}, BroadcastChannel: options.channel,
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

const action = (id = 'action-00000001', overrides = {}) => ({event_id:id, version:1, product_id:101, ...overrides});
const actions = f => f.calls.flatMap(call => call.body.events).filter(event => event.type.startsWith('product_') && event.type !== 'product_view');
async function run() {
  for (const capability of [undefined, 0, true, '1', 2]) {
    const f=fixture({product_actions_protocol:capability});
    check('Strict capability rejects '+String(capability), f.window.VialCoveVisitorTransport.pushProductAction('product_favorite', action(undefined,{action:'add'}))===false);
    await f.advance(300); check('Missing capability has no action events', actions(f).length===0); f.stop();
  }
  for (const facts of [
    action(undefined,{version:'1',action:'add'}),action(undefined,{version:2,action:'add'}),action(undefined,{product_id:'101',action:'add'}),action(undefined,{product_id:0,action:'add'}),action(undefined,{product_id:Number.MAX_SAFE_INTEGER+1,action:'add'}),
    action('short',{action:'add'}),action('a'.repeat(65),{action:'add'}),action('email@example.test',{action:'add'}),action(undefined,{variation_id:-1,action:'add'}),action(undefined,{variation_id:'0',action:'add'}),action(undefined,{action:'remove'}),action(undefined,{action:'click'})
  ]) {
    const f=fixture({product_actions_protocol:1});
    check('Malformed facts reject atomically', f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',facts)===false);
    await f.advance(300); check('Malformed facts never queue a partial event', actions(f).length===0);f.stop();
  }
  {
    const f=fixture({product_actions_protocol:1}), api=f.window.VialCoveVisitorTransport;
    const input=action('favorite-00000001',{action:'add',email:'private@example.test',url:'https://private.test/token',metadata:{recipient:'private'}});
    check('Explicit favorite uses source operation UID',api.pushProductAction('product_favorite',input)==='favorite-00000001');
    check('Reentrant callback returns same queued UID',api.pushProductAction('product_favorite',{...input})==='favorite-00000001');
    check('Changed canonical payload with same UID rejected',api.pushProductAction('product_favorite',{...input,product_id:102})===false);
    check('Changed type with same UID rejected',api.pushProductAction('product_share',{...input,method:'native',result:'completed'})===false);
    input.product_id=999;input.action='remove';
    check('Share completed operation accepted',api.pushProductAction('product_share',action('share-000000001',{variation_id:303,method:'copy_link',result:'completed'}))==='share-000000001');
    check('Intent completed operation accepted',api.pushProductAction('product_intent',action('intent-00000001',{kind:'quick_view_open',result:'completed'}))==='intent-00000001');
    for(const [type,data] of [['product_share',{method:'native',result:'cancelled'}],['product_share',{method:'email',result:'completed'}],['product_intent',{kind:'purchase',result:'completed'}],['product_intent',{kind:'buy_now',result:'failed'}],['unknown',{action:'add'}]]) check('Only declared successful contracts accepted',api.pushProductAction(type,action('reject-00000001',data))===false);
    f.document.dispatchEvent({type:'vc:visitor-action',detail:{type:'product_favorite',data:action('public-00000001',{action:'add'})}});
    await f.advance(300);const got=actions(f);
    check('Exactly three explicit actions emitted',got.length===3);
    check('Snapshot frozen and omitted variation canonicalized',JSON.stringify(got[0].data)==='{"version":1,"product_id":101,"variation_id":0,"action":"add"}');
    check('No source ID in data or private extras retained',!JSON.stringify(got).includes('private')&&!Object.hasOwn(got[0].data,'event_id'));
    check('Actual page path/type preserved',got.every(e=>e.page.path==='/product/item/'&&e.page.page_type==='product'));
    check('ACKed operation retains idempotence tombstone',api.pushProductAction('product_favorite',action('favorite-00000001',{action:'add'}))==='favorite-00000001');
    await f.advance(300);check('ACKed callback does not create another request',f.calls.length===1); f.stop();
    check('Explicit stop refuses pushes without restarting',api.pushProductAction('product_favorite',action('stopped-0000001',{action:'add'}))===false&&api.getStats().stopped);
  }
  {
    const f=fixture({product_actions_protocol:1}); f.responses.push({network:true});
    f.window.VialCoveVisitorTransport.pushProductAction('product_intent',action('lost-ack-000001',{kind:'buy_now',result:'completed'}));
    await f.advance(300); const first=JSON.stringify(actions(f)[0]);
    f.window.dispatchEvent({type:'pagehide',persisted:true}); await drain();
    check('Suspended page refuses new action',f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',action('hidden-0000001',{action:'add'}))===false);
    f.window.dispatchEvent({type:'pageshow',persisted:true}); await f.advance(1100);
    const retried=actions(f).filter(e=>e.event_id==='lost-ack-000001');
    check('Lost ACK/BFCache retry retains complete immutable event',retried.length>=2&&retried.every(e=>JSON.stringify(e)===first));
    check('BFCache duplicate callback does not create new page identity',f.window.VialCoveVisitorTransport.pushProductAction('product_intent',action('lost-ack-000001',{kind:'buy_now',result:'completed'}))==='lost-ack-000001');
    f.window.VialCoveVisitorTransport.pushProductAction('product_intent',action('restored-000001',{kind:'buy_now',result:'completed'}));await f.advance(300);
    check('New operation after BFCache uses new page identity',actions(f).find(e=>e.event_id==='restored-000001').page_instance_id!==JSON.parse(first).page_instance_id);f.stop();
  }
  {
    class Channel { static all=[]; constructor(){this.posts=[];Channel.all.push(this);}postMessage(data){this.posts.push(data);}close(){} }
    const f=fixture({product_actions_protocol:1},{channel:Channel}); await f.advance(100);
    f.responses.push({network:true});f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',action('tab-rotate-0001',{action:'add'}));await f.advance(300);
    const original=f.calls[0].body.tab_id, channel=Channel.all[0], owner=channel.posts[0].owner;
    channel.onmessage({data:{owner:'different-owner',type:'occupied',target:owner,tab_id:original}});
    check('Clone collision rotates current tab ID',f.store.get('vc_tab_id')!==original);
    await f.advance(1100);
    check('Action-only capability freezes original tab on retry',f.calls.length===2&&f.calls[1].body.tab_id===original);
    check('Rotated retry keeps event identity unchanged',JSON.stringify(f.calls[0].body.events)===JSON.stringify(f.calls[1].body.events));f.stop();
  }
  for(const options of [{navigator:{globalPrivacyControl:true}},{config:{enabled:false}}]) {
    const f=fixture({product_actions_protocol:1,...options.config},options);
    check('Privacy or disabled guard rejects new source operation',f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',action('guard-00000001',{action:'add'}))===false);f.stop();
  }
  {
    const f=fixture({product_actions_protocol:1});f.responses.push({status:403,data:{code:'pc_track_declined'}});
    f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',action('denied-0000001',{action:'add'}));await f.advance(300);
    check('Server privacy denial stops source API',f.window.VialCoveVisitorTransport.pushProductAction('product_favorite',action('denied-0000002',{action:'add'}))===false&&f.stats().stopped);
    await f.advance(120000);check('Denied action never retries',f.calls.length===1);
  }
  console.log(JSON.stringify({suite:'visitor-product-actions',passed:checks,failed:0}));
}
run().catch(error=>{console.error(error);process.exitCode=1;});
