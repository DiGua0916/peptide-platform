/* Actual complete observer/adapter in VM; only browser/clock/HTTP boundaries are fake. */
'use strict';
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const {webcrypto} = require('node:crypto');
let passed = 0;
function check(name, condition) { assert.ok(condition, name); passed++; console.log('PASS ' + name); }
const json = value => JSON.parse(JSON.stringify(value));
const drain = async () => { for (let i = 0; i < 50; i++) await Promise.resolve(); };
class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatchEvent(event) { for (const fn of [...(this.listeners.get(event.type) || [])]) fn(event); }
}
function response(body) {
  const data = {protocol: 1, ack: body.events.map(e => e.event_id), rejected: [], extensions: {}};
  for (const key of ['attribution', 'page_context']) {
    const attempts = body.events.filter(e => Object.hasOwn(e.data, key));
    if (attempts.length) data.extensions[key] = {version: 1, results: attempts.map(e => ({event_id: e.event_id, status: 'applied', code: key === 'attribution' ? 'captured' : 'saved_context', retry: false}))};
  }
  return data;
}
function fixture(options = {}) {
  let now = Date.parse('2026-10-31T12:00:00Z'), timer = 0, href = new URL(options.url || 'https://shop.test/Collections/Native/page/2/'), urlReads = 0, refReads = 0, aborts = 0, videoStops = 0;
  const timers = new Map(), calls = [], scenarios = [], pending = [], store = new Map(), channels = [];
  const document = new Target(), window = new Target(); document.visibilityState = 'visible'; document.hidden = false;
  Object.defineProperty(document, 'referrer', {get() { refReads++; if (options.referrerHook) options.referrerHook(window); if (options.referrerThrows) throw Error('unavailable'); return options.referrer ?? ''; }});
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++timer; timers.set(id, {fn, due: now + (Number(ms) || 0), interval}); return id; };
  class Channel { constructor() { this.posted = []; this.closed = false; channels.push(this); } postMessage(data) { this.posted.push(data); } close() { this.closed = true; } }
  const observation = options.observation === undefined ? {version: 1, page: {page_type: 'shop', path: '/Collections/Native/page/2/'}, context: {version: 1, kind: 'collection', term_id: 42, geo: {status: 'unknown'}}} : options.observation;
  const config = {enabled: true, protocol: 1, endpoint: href.origin + '/wp-json/peptide/v1/track/events', heartbeat_ms: 15000, batch_max: 20, metadata_protocols: {attribution: 1, page_context: 1}, page_observation: observation, ...options.config};
  const location = {get href() { urlReads++; return href.href; }, get origin() { return href.origin; }};
  function makeResponse(body, scenario) { const status = scenario.status || 200; const data = typeof scenario.data === 'function' ? scenario.data(body) : scenario.data || response(body); return {status, ok: status >= 200 && status < 300, headers: new Headers(scenario.headers || {}), json: async () => data}; }
  Object.assign(window, {window, document, location, crypto: webcrypto, URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id), setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
    sessionStorage: {getItem(key) { if (options.storageBlocked) throw Error('blocked'); return store.get(key); }, setItem(key, value) { if (options.storageWriteBlocked) throw Error('blocked'); store.set(key, value); }},
    ...(options.channel === false ? {} : {BroadcastChannel: Channel}),
    VialCoveVisitorPage: options.context || {page_type: 'content', path: '/legacy/'}, VialCoveVisitorConfig: config,
    VialCoveVisitorVideo: {observe: () => ({flush() {}, stop() { videoStops++; }})},
    fetch: async (url, init) => {
      const body = JSON.parse(init.body); calls.push({body, keepalive: init.keepalive, signal: init.signal});
      const scenario = scenarios.shift() || {}; scenario.before?.(body);
      if (scenario.network) throw Error('Fake network failure');
      if (scenario.pending) return new Promise((resolve, reject) => { pending.push({resolve: result => resolve(makeResponse(body, result || {})), reject}); init.signal.addEventListener('abort', () => { aborts++; reject(new DOMException('Aborted', 'AbortError')); }, {once: true}); });
      return makeResponse(body, scenario);
    }
  });
  const context = vm.createContext(window);
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/visitor-tracking.js'), 'utf8'), context, {filename: 'visitor-tracking.js'});
  if (options.adapter !== false) vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/visitor-adapter.js'), 'utf8'), context, {filename: 'visitor-adapter.js'});
  async function advance(ms = 400) {
    const end = now + ms; await drain();
    for (let loops = 0; loops < 10000; loops++) {
      const first = [...timers].filter(([, task]) => task.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
      if (!first) { now = end; await drain(); return; }
      const [id, task] = first; now = task.due; if (task.interval) task.due += task.interval; else timers.delete(id); task.fn(); await drain();
    }
    throw Error('Unbounded fake timer loop');
  }
  const stats = () => window.VialCoveVisitorTransport.getStats();
  const action = () => document.dispatchEvent({type: 'vc:visitor-action', detail: {type: 'cart_open', data: {source: 'synthetic', email: 'PRIVATE_FORM'}}});
  const rotate = () => { const ch = channels.at(-1), owner = ch.posted[0].owner, old = store.get('vc_tab_id'); ch.onmessage({data: {type: 'occupied', owner: 'foreign-owner', target: owner, tab_id: old}}); return {old, next: store.get('vc_tab_id')}; };
  const hide = () => window.dispatchEvent({type: 'pagehide', persisted: true});
  const show = () => window.dispatchEvent({type: 'pageshow', persisted: true});
  return {window, document, config, calls, scenarios, pending, store, channels, timers, advance, stats, action, rotate, hide, show, navigate: value => { href = new URL(value); }, reads: () => ({urlReads, refReads}), counters: () => ({aborts, videoStops}), stop: () => window.VialCoveVisitorTransport?.stop()};
}
const pv = call => call.body.events.find(e => e.type === 'page_view');
async function attributionCases() {
  for (const [query, expected] of [['', {}], ['?utm_medium=', {utm_medium: ''}], ['?utm_source=&utm_medium=Email', {utm_source: '', utm_medium: 'Email'}], ['?UTM_SOURCE=ignored&utm_source=A%252B%2BB&other=PRIVATE_QUERY#PRIVATE_HASH', {utm_source: 'A%2B+B'}], ['?utm_source=' + encodeURIComponent('😀'.repeat(120)), {utm_source: '😀'.repeat(120)}]]) {
    const f = fixture({url: 'https://SHOP.test.:8443/entry/' + query, referrer: 'https://Ref.Test:99/private?q=PRIVATE_REF#private'}); await f.advance();
    const record = pv(f.calls[0]); check('allowlist missing/empty/case/decode/120-codepoint attribution ' + passed, JSON.stringify(record.data.attribution.landing_query) === JSON.stringify(expected));
    check('entry and referrer host only with no port/query/hash', record.data.attribution.host === 'shop.test' && record.data.attribution.referrer_host === 'ref.test' && !JSON.stringify(f.calls[0].body).includes('PRIVATE'));
    check('recognized independent terminals retire metadata identities', f.stats().queued === 0 && f.stats().metadataPending === 0); f.stop();
  }
  for (const tail of ['?utm_source=A&utm_source=B', '?utm_medium=&utm_medium=', '?utm_source=%00', '?utm_source=%7F', '?utm_source=' + 'a'.repeat(121)]) {
    const f = fixture({url: 'https://shop.test/' + tail}); await f.advance(); check('unsupported attribution omitted without losing real PV ' + passed, !Object.hasOwn(pv(f.calls[0]).data, 'attribution') && Object.hasOwn(pv(f.calls[0]).data, 'page_context') && f.stats().extensionUnsupported === 1); f.stop();
  }
  for (const url of ['https://[::1]/', 'https://' + 'a'.repeat(64) + '.test/', 'https://' + Array(4).fill('a'.repeat(63)).join('.') + '/']) {
    const f = fixture({url}); await f.advance(); check('unsupported host never fabricated ' + passed, !Object.hasOwn(pv(f.calls[0]).data, 'attribution') && f.stats().extensionUnsupported === 1); f.stop();
  }
  for (const url of ['https://localhost/', 'https://127.0.0.1/', 'https://é.test/']) {
    const f = fixture({url}); await f.advance(); check('URL hostname supports localhost IPv4 IDNA ' + passed, pv(f.calls[0]).data.attribution.host === new URL(url).hostname); f.stop();
  }
  for (const opts of [{referrer: ''}, {referrer: 'not an absolute URL'}, {referrer: 'https://[::1]/'}, {referrerThrows: true}]) {
    const f = fixture(opts); await f.advance(); check('empty vs unavailable/unsupported referrer ' + passed, pv(f.calls[0]).data.attribution.referrer_host === (opts.referrer === '' ? '' : null)); f.stop();
  }
  const f = fixture(); const firstReads = f.reads(); await f.advance(); const old = pv(f.calls[0]);
  check('URL/referrer read once at genuine PV creation', firstReads.urlReads === 1 && f.reads().urlReads === 2 && f.reads().refReads === 1);
  f.config.page_observation.context.term_id = 999; f.config.page_observation.page.path = '/MUTATED/'; f.hide(); await drain(); f.navigate('https://shop.test/new/?utm_source=Next'); const reads = f.reads(); f.show(); await f.advance();
  const newest = f.calls.flatMap(c => c.body.events).filter(e => e.type === 'page_view').at(-1);
  check('BFCache new PV reads current attribution once but keeps server document context', newest.event_id !== old.event_id && newest.data.attribution.landing_query.utm_source === 'Next' && newest.data.page_context.term_id === 42 && newest.page.path === '/Collections/Native/page/2/' && f.reads().urlReads === reads.urlReads + 1 && f.reads().refReads === reads.refReads + 1);
  f.action(); await f.advance(15000); f.hide(); await drain(); check('corrected path applies consistently to actions heartbeat and exit', f.calls.flatMap(c => c.body.events).every(e => e.page.path === '/Collections/Native/page/2/')); f.stop();
}
async function capabilityCases() {
  for (const version of [true, '1', 1.5, 2, null]) {
    const f = fixture({config: {metadata_protocols: {attribution: version, page_context: version}}}); await f.advance(); check('strict integer-one capabilities preserve legacy ' + String(version), !Object.hasOwn(pv(f.calls[0]).data, 'attribution') && pv(f.calls[0]).page.path === '/legacy/' && !Object.hasOwn(f.stats(), 'metadataPending')); f.stop();
  }
  const inherited = Object.create({attribution: 1, page_context: 1}); const old = fixture({config: {metadata_protocols: inherited}}); await old.advance(); check('inherited capabilities do not enable extensions', !Object.hasOwn(pv(old.calls[0]).data, 'attribution')); old.stop();
  for (const key of ['attribution', 'page_context']) { const f = fixture({config: {metadata_protocols: {[key]: 1}}}); await f.advance(); check('independent capability ' + key, Object.hasOwn(pv(f.calls[0]).data, key) && !Object.hasOwn(pv(f.calls[0]).data, key === 'attribution' ? 'page_context' : 'attribution')); f.stop(); }
  for (const observation of [null, {}, {version: '1'}, {version: 1, page: {page_type: 'shop', path: '/bad/?private'}, context: {version: 1, kind: 'shop'}}, {version: 1, page: {page_type: 'shop', path: '/safe/'}, context: {version: 1, kind: 'collection', term_id: '42'}}, {version: 1, page: {page_type: 'account', path: '/my-account/private/'}, context: {version: 1, kind: 'account'}}]) {
    const f = fixture({observation}); await f.advance(); check('malformed observation keeps legacy page and independent attribution ' + passed, pv(f.calls[0]).page.path === '/legacy/' && Object.hasOwn(pv(f.calls[0]).data, 'attribution') && !Object.hasOwn(pv(f.calls[0]).data, 'page_context') && f.stats().extensionUnsupported === 1); f.stop();
  }
}
async function receiptCases() {
  const changes = {
    missing: d => delete d.extensions.page_context,
    version: d => { d.extensions.page_context.version = '1'; },
    duplicate: d => { d.extensions.page_context.results.push(json(d.extensions.page_context.results[0])); },
    wrongId: d => { d.extensions.page_context.results[0].event_id = 'different-event'; },
    unknown: d => { d.extensions.page_context.results[0].code = 'future_code'; },
    wrongRetry: d => { d.extensions.page_context.results[0].retry = true; },
    retry: d => { Object.assign(d.extensions.page_context.results[0], {status: 'retry', code: 'server_error', retry: true}); },
    partial: d => { Object.assign(d.extensions.attribution.results[0], {status: 'rejected', code: 'bad_attribution', retry: false}); Object.assign(d.extensions.page_context.results[0], {status: 'retry', code: 'server_error', retry: true}); }
  };
  for (const [name, change] of Object.entries(changes)) {
    const f = fixture(); f.scenarios.push({data: body => { const data = response(body); change(data); return data; }}); await f.advance();
    const frozen = json(pv(f.calls[0])); check('core ACK with unresolved extension remains pending ' + name, f.stats().queued === 1 && f.stats().acknowledged === 0 && f.stats().extensionUnresolved === 1 && f.stats().metadataPending === 1);
    f.navigate('https://shop.test/changed/?utm_source=Changed'); await f.advance(1100);
    check('immutable complete retry including terminal sibling ' + name, JSON.stringify(pv(f.calls[1])) === JSON.stringify(frozen) && f.calls[1].body.tab_id === f.calls[0].body.tab_id && f.stats().queued === 0 && f.stats().metadataPending === 0); f.stop();
  }
  for (const [key, statuses] of Object.entries({attribution: [['applied', 'captured'], ['applied', 'already_captured'], ['preserved', 'visit_already_captured'], ...['core_rejected','wrong_event_type','bad_attribution','unsupported_version','duplicate_without_capture','duplicate_payload_mismatch','original_visit_missing'].map(code => ['rejected', code])], page_context: [['applied', 'saved_context'], ['applied', 'already_saved'], ...['core_rejected','wrong_event_type','bad_page_context','unsupported_version','duplicate_without_context','duplicate_payload_mismatch','original_visit_missing'].map(code => ['rejected', code])] })) for (const [status, code] of statuses) {
    const f = fixture(); f.scenarios.push({data: body => { const data = response(body); Object.assign(data.extensions[key].results[0], {status, code}); return data; }}); await f.advance(); check('recognized terminal ' + key + '/' + status + '/' + code, f.stats().queued === 0 && f.stats().metadataPending === 0 && f.stats().extensionRejected === (status === 'rejected' ? 1 : 0)); f.stop();
  }
  const rejected = fixture(); rejected.scenarios.push({data: body => ({protocol: 1, ack: [], rejected: body.events.map(e => ({event_id: e.event_id, retry: false, code: 'bad_event'}))})}); await rejected.advance(); check('terminal core rejection discards metadata record without capture ACK', rejected.stats().queued === 0 && rejected.stats().acknowledged === 0 && rejected.stats().dropped === 1); rejected.stop();
  const exhausted = fixture(); for (let i = 0; i < 3; i++) exhausted.scenarios.push({data: body => { const d = response(body); delete d.extensions; return d; }}); await exhausted.advance(4000); check('missing receipts reach finite delivery limit honestly', exhausted.calls.length === 3 && exhausted.stats().queued === 0 && exhausted.stats().acknowledged === 0 && exhausted.stats().extensionDropped === 1 && exhausted.stats().metadataPending === 0); exhausted.stop();
  const ordered = fixture(); ordered.scenarios.push({pending: true}); await ordered.advance(); ordered.scenarios.push({network: true}); ordered.hide(); await drain(); ordered.pending[0].reject(Error('Fake original network failure')); await drain(); await ordered.advance(1100); ordered.show(); ordered.scenarios.push({data: body => { const d = response(body); d.extensions.attribution.results.reverse(); d.extensions.page_context.results.reverse(); return d; }}); await ordered.advance();
  check('two PV receipt indices are validated before UID decisions', ordered.calls.at(-1).body.events.filter(e => e.type === 'page_view').length === 2 && ordered.stats().queued === 2 && ordered.stats().acknowledged === 1);
  await ordered.advance(4100); check('out-of-order receipts cannot acknowledge either PV and immutable retry recovers', ordered.stats().queued === 0 && ordered.stats().metadataPending === 0); ordered.stop();
  for (const status of [400, 413]) { const terminal = fixture(); terminal.scenarios.push({status}); await terminal.advance(); check('HTTP terminal discard releases identity map ' + status, terminal.stats().queued === 0 && terminal.stats().metadataPending === 0 && terminal.stats().acknowledged === 0); terminal.stop(); }
  const cap = fixture(); cap.scenarios.push({network: true}); await cap.advance(); for (let i = 0; i < 100; i++) cap.action(); check('overflow retirement releases a previously sent PV identity without capture', cap.stats().queued === 100 && cap.stats().metadataPending === 0 && cap.stats().extensionDropped === 1 && cap.stats().acknowledged === 0); cap.stop(); check('stop leaves bounded bookkeeping empty', cap.stats().metadataPending === 0 && cap.stats().queued === 0);
}
async function transportCases() {
  const f = fixture(); await f.advance(); f.action(); f.action(); f.scenarios.push({network: true}); await f.advance(); f.scenarios.push({network: true}); f.hide(); await drain(); await f.advance(2100); f.show(); f.action();
  let rotation; f.scenarios.push({status: 413, before: () => { rotation = f.rotate(); }}, {}, {network: true}); const index = f.calls.length; await f.advance();
  const split = f.calls.slice(index); check('combined actual413 split positions and partial failure', split.map(c => c.body.events.length).join(',') === '5,3,2' && split[1].body.events.every(e => e.type !== 'page_view') && pv(split[2]) && f.stats().queued === 2);
  const frozen = json(pv(split[2])), tab = split[2].body.tab_id; check('tab rotation happens after capture and split keeps frozen identity', rotation.old !== rotation.next && split.every(c => c.body.tab_id === tab) && tab === rotation.old);
  f.scenarios.push({network: true}); const retryIndex = f.calls.length; f.hide(); await drain();
  check('pagehide keepalive retry uses same UID tab and nested metadata', f.calls[retryIndex].keepalive && JSON.stringify(pv(f.calls[retryIndex])) === JSON.stringify(frozen) && f.calls[retryIndex].body.tab_id === tab);
  await f.advance(2100); f.navigate('https://shop.test/restored/?utm_source=New'); f.show(); await f.advance();
  const replay = f.calls.slice(retryIndex + 1), restored = replay.flatMap(c => c.body.events).filter(e => e.type === 'page_view');
  check('BFCache retries old frozen PV and creates distinct new PV', restored.some(e => JSON.stringify(e) === JSON.stringify(frozen)) && restored.some(e => e.event_id !== frozen.event_id && e.data.attribution.landing_query.utm_source === 'New'));
  check('every actual request has one frozen tab and maps retire', replay.find(c => c.body.events.some(e => e.event_id === frozen.event_id)).body.tab_id === tab && f.stats().queued === 0 && f.stats().metadataPending === 0); f.stop();
  const blocked = fixture({storageBlocked: true}); blocked.scenarios.push({network: true}); await blocked.advance(); blocked.navigate('https://shop.test/new/'); await blocked.advance(1100); check('storage-blocked undefined identity is stable across retry', blocked.calls.length === 2 && blocked.calls.every(c => !Object.hasOwn(c.body, 'tab_id')) && JSON.stringify(pv(blocked.calls[0])) === JSON.stringify(pv(blocked.calls[1])) && blocked.stats().metadataPending === 0); blocked.stop();
  const wait = fixture(); wait.scenarios.push({status: 429, headers: {'Retry-After': new Date(Date.parse('2026-10-31T12:00:00Z') + 30000).toUTCString()}}); await wait.advance(); const first = json(pv(wait.calls[0])); wait.action(); wait.hide(); await drain(); await wait.advance(20000); check('HTTP-date429 deadline covers new events and pagehide', wait.calls.length === 1); wait.show(); await wait.advance(11000); check('429 resumes with original frozen PV after deadline', wait.calls.length > 1 && wait.calls.some(c => c.body.events.some(e => JSON.stringify(e) === JSON.stringify(first))) && wait.stats().queued === 0); wait.stop();
  for (const code of ['pc_track_declined', 'pc_track_staff', 'pc_track_disabled']) { const denied = fixture(); denied.scenarios.push({pending: true}); await denied.advance(); for (let i = 0; i < 25; i++) denied.action(); denied.scenarios.push({status: 403, data: {code}}); denied.hide(); await drain(); denied.show(); denied.action(); await denied.advance(120000); check('403 queued/inflight/BFCache full stop ' + code, denied.calls.length === 2 && denied.counters().aborts === 1 && denied.stats().stopped && denied.stats().queued === 0 && denied.stats().metadataPending === 0 && denied.timers.size === 0); }
  const boot = fixture({referrerHook: window => window.VialCoveVisitorTransport.stop()}); await boot.advance(); check('synchronous boot-stop assignment race still stops returned observer and clears maps', boot.stats().stopped && boot.stats().queued === 0 && boot.stats().metadataPending === 0 && boot.timers.size === 0 && boot.calls.length === 0);
}
async function observerCases() {
  const snapshot = {version: 1, host: 'shop.test', landing_query: {utm_source: 'Frozen'}, referrer_host: ''};
  const f = fixture({adapter: false}); let factories = 0; const retired = [], sent = [];
  const api = f.window.VialCoveVisitorObserver.start({context: {page_type: 'content', path: '/'}, pageViewMetadata: () => { factories++; return {attribution: snapshot, forbidden: 'PRIVATE_FORM'}; }, onEventRetired: (id, reason) => { retired.push({id, reason}); throw Error('isolated callback'); }, sendBatch: async events => { sent.push(events); try { events[0].data.attribution.landing_query.utm_source = 'Mutated'; } catch {} return sent.length === 1 ? {ackEventIds: []} : {ackEventIds: events.map(e => e.event_id)}; }});
  snapshot.landing_query.utm_source = 'Changed caller'; await f.advance(); f.action(); await f.advance(1100);
  check('factory called only once for genuine PV not action/retry', factories === 1 && sent.length === 3);
  check('caller/send-copy deep mutations cannot change queued metadata', sent[0][0].data.attribution.landing_query.utm_source === 'Frozen' && sent.at(-1)[0].data.attribution.landing_query.utm_source === 'Frozen' && !JSON.stringify(sent).includes('PRIVATE_FORM'));
  check('ack retirement exactly once after queue removal despite callback exception', retired.length === 2 && new Set(retired.map(r => r.id)).size === 2 && retired.every(r => r.reason === 'acknowledged') && api.getStats().queued === 0); api.stop();
  for (const [reason, send, maxAttempts] of [['discarded', async e => ({discardEventIds: e.map(x => x.event_id)}), 3], ['exhausted', async () => ({ackEventIds: []}), 1]]) {
    const g = fixture({adapter: false}), reasons = []; const instance = g.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, sendBatch: send, maxAttempts, onEventRetired: (id, value) => reasons.push(value)}); await g.advance(); check('normal-finally retirement reason ' + reason, reasons.join(',') === reason && instance.getStats().queued === 0); instance.stop();
  }
  const overflow = fixture({adapter: false}), removals = []; let count = 0;
  const q = overflow.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, flushMs: 5000, maxQueue: 10, pageViewMetadata: () => { count++; return {attribution: snapshot}; }, onEventRetired: (id, reason) => removals.push({id, reason}), sendBatch: async () => ({})});
  for (let i = 0; i < 12; i++) overflow.action(); check('removable queue overflow retires only removed records', removals.length === 3 && removals.every(r => r.reason === 'overflow') && q.getStats().queued === 10 && count === 1 && q.getStats().extensionDropped === 1); q.stop(); check('stop retires every remaining queued record once', removals.length === 13 && new Set(removals.map(r => r.id)).size === 13 && removals.filter(r => r.reason === 'stopped').length === 10);
  const full = fixture({adapter: false}), ends = []; let release;
  const busy = full.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, maxQueue: 10, batchMax: 10, onEventRetired: (id, reason) => ends.push({id, reason}), sendBatch: () => new Promise(resolve => { release = resolve; })}); for (let i = 0; i < 9; i++) full.action(); await full.advance(); full.action(); check('all-inflight prospective drop creates no UID or retirement', busy.getStats().created === 10 && busy.getStats().dropped === 1 && ends.length === 0); busy.stop(); release({ackEventIds: []}); await drain(); check('stopped inflight records retire once only', ends.length === 10 && ends.every(e => e.reason === 'stopped'));
  const exit = fixture({adapter: false}), exitReasons = []; let finish;
  const exiting = exit.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, maxAttempts: 1, onEventRetired: (id, reason) => exitReasons.push({id, reason}), sendBatch: (events, hint) => hint.unload ? Promise.resolve({ackEventIds: events.map(e => e.event_id)}) : new Promise(resolve => { finish = () => resolve({ackEventIds: events.map(e => e.event_id)}); })}); await exit.advance(); exit.action(); exit.hide(); await drain(); check('exit then/finally acknowledge retirement once per queued record', exitReasons.length === 2 && exitReasons.every(e => e.reason === 'acknowledged')); finish(); await drain(); check('normal completion after exit retires original record once', exitReasons.length === 3 && new Set(exitReasons.map(e => e.id)).size === 3); exiting.stop();
  const exitFail = fixture({adapter: false}), failedReasons = []; const failApi = exitFail.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, maxAttempts: 1, onEventRetired: (id, reason) => failedReasons.push(reason), sendBatch: (events, hint) => hint.unload ? Promise.reject(Error('Fake exit failure')) : new Promise(() => {})}); await exitFail.advance(); exitFail.action(); exitFail.hide(); await drain(); check('exit finally exhausted retirement path', failedReasons.join(',') === 'exhausted,exhausted'); failApi.stop(); check('stop retires only surviving normal inflight record', failedReasons.join(',') === 'exhausted,exhausted,stopped');
  const replace = fixture({adapter: false}), replaced = []; const first = replace.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, sendBatch: async () => ({}), onEventRetired: (id, reason) => replaced.push(reason)}); const second = replace.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, sendBatch: async () => ({})}); check('replacement-start stops and retires previous queue', first.getStats().stopped && replaced.join(',') === 'stopped'); second.stop();
  for (const factory of [() => { throw Error('factory unavailable'); }, () => null, () => ({attribution: {version: 1, host: 'bad\n', landing_query: {}, referrer_host: ''}})]) { const bad = fixture({adapter: false}), packets = []; const instance = bad.window.VialCoveVisitorObserver.start({context: {page_type: 'content'}, pageViewMetadata: factory, sendBatch: async events => { packets.push(events); return {ackEventIds: events.map(e => e.event_id)}; }}); await bad.advance(); check('unsupported factory preserves genuine core PV ' + passed, packets[0][0].type === 'page_view' && !Object.hasOwn(packets[0][0].data, 'attribution') && instance.getStats().extensionUnsupported === 1); instance.stop(); }
}
(async () => { await attributionCases(); await capabilityCases(); await receiptCases(); await transportCases(); await observerCases(); console.log('TOTAL ' + passed + ' PASS, 0 FAIL, 0 SKIP'); })().catch(error => { console.error(error); process.exitCode = 1; });
