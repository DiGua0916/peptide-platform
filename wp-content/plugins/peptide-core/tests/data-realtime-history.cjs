/* Run the actual realtime renderer in a DOM/clock/API harness; no browser packages. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/data-realtime.js'), 'utf8');
let passed = 0;
function check(label, condition) { assert.ok(condition, label); passed++; }
function harness(url = 'http://localhost:8093/wp-admin/admin.php?page=pc-data', missing = false) {
  class Element {
    constructor() { this.events = {}; this.attrs = {}; this.value = ''; this.hidden = false; this.textContent = ''; this.html = ''; this.classes = new Set(); this.classList = { toggle: (k, on) => on ? this.classes.add(k) : this.classes.delete(k) }; }
    set innerHTML(value) { this.html = value; }
    get innerHTML() { return this.html; }
    addEventListener(k, fn) { this.events[k] = fn; }
    setAttribute(k, value) { this.attrs[k] = value; }
    fire(k) { this.events[k]({ preventDefault() {} }); }
  }
  const elements = {};
  const body = new Element();
  body.querySelector = selector => elements[selector] || (elements[selector] = new Element());
  const calls = [], intervals = new Map(), listeners = {}, documentListeners = {}, writes = [];
  let sequence = 0, renderer;
  const location = {};
  function navigate(value) { const u = new URL(value); location.href = u.href; location.search = u.search; }
  navigate(url);
  const strings = new Proxy({ updated: 'Updated %s', today: 'Today %s', rangeError: 'Invalid dates' }, { get: (o, k) => o[k] || String(k) });
  const window = {
    location, URL: missing ? undefined : URL, URLSearchParams: missing ? undefined : URLSearchParams,
    localStorage: { getItem() { if (missing) throw Error('disabled'); return null; }, setItem() { if (missing) throw Error('disabled'); } },
    history: { pushState(a, b, u) { writes.push(['push', u]); navigate(u); }, replaceState(a, b, u) { writes.push(['replace', u]); navigate(u); } },
    setInterval(fn, ms) { const id = ++sequence; intervals.set(id, { fn, ms }); return id; }, clearInterval(id) { intervals.delete(id); },
    addEventListener(k, fn) { listeners[k] = fn; }, pcDataRealtime: { strings, timezone: 'Asia/Shanghai' }
  };
  const document = { visibilityState: 'visible', documentElement: { contains: () => true }, addEventListener(k, fn) { documentListeners[k] = fn; } };
  const ctx = {
    esc: v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
    number: v => String(v || 0), money: v => String(v || 0), strings: { loading: 'Loading', loadError: 'Failed load' }, currency: { code: 'USD' },
    api(path, params) { return new Promise((resolve, reject) => calls.push({ path, params, resolve, reject })); },
    register(key, fn) { assert.equal(key, 'realtime'); renderer = fn; }
  };
  window.PcData = ctx;
  vm.runInNewContext(source, { window, document, Date, console });
  const start = () => renderer(body, { dates: { from: '2026-10-30', to: '2026-10-30' } }, ctx);
  start();
  return { elements, calls, intervals, document, listeners, documentListeners, writes, navigate, start, window, el: k => elements['.pc-rt-' + k] };
}
function report(history = false, preset = 'today', dates = ['2026-10-30', '2026-10-30']) {
  const data = { updated: 'fixture-time', tracking: true, cards: { visitors: 17 }, funnel: { layers: [] }, lists: {}, links: {}, minutes: { points: [{ ago: 0, visitors: 3 }] }, today: { visitors: 27 } };
  if (history) { data.mode = 'history'; data.range = { preset, dates: { from: dates[0], to: dates[1] }, timezone: 'Asia/Shanghai' }; data.series = { points: [{ label: '00:00 CST', visitors: 8 }] }; delete data.today; delete data.minutes; }
  return data;
}
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const h = harness();
  check('default request keeps original route and window parameters', h.calls[0].path === '/peptide/v1/data/realtime' && h.calls[0].params.funnel === 10 && h.calls[0].params.minutes === 10);
  check('one live minute timer', h.intervals.size === 1 && [...h.intervals.values()][0].ms === 60000);
  h.calls[0].resolve(report()); await flush();
  check('live labels and today values', h.el('period').textContent.includes('live') && h.el('cards-wrap').innerHTML.includes('Today 27'));
  check('initial URL canonicalization replaces', h.writes.length === 1 && h.writes[0][0] === 'replace');
  h.el('today').fire('click');
  check('today uses history endpoint and exact params', h.calls[1].path.endsWith('/history') && h.calls[1].params.range === 'today');
  check('history request stops timer immediately', h.intervals.size === 0);
  check('pending period keeps last successful label', h.el('period').textContent.startsWith('live'));
  h.calls[1].resolve(report(true)); await flush();
  check('history hides minute controls', h.el('funnel-select').hidden && h.el('minutes-select').hidden);
  check('history titles, period and chart labels agree', h.el('period').textContent.includes('2026-10-30') && h.el('chart-title').textContent === 'historyChart' && h.el('chart-wrap').innerHTML.includes('00:00 CST'));
  check('history removes today sublabels', !h.el('cards-wrap').innerHTML.includes('pc-rt-metric__today'));
  check('valid user change pushes canonical range', h.writes.at(-1)[0] === 'push' && new URL(h.window.location.href).searchParams.get('range') === 'today');
  const at = h.calls.length;
  h.documentListeners.visibilitychange();
  check('history visibility does not refresh', h.calls.length === at);
  h.el('refresh').fire('click'); h.calls.at(-1).resolve(report(true)); await flush();
  check('manual history refresh does not write URL', h.calls.length === at + 1 && h.writes.length === 2);
  h.el('yesterday').fire('click'); const slow = h.calls.at(-1);
  h.el('from').value = '2026-10-29'; h.el('to').value = '2026-10-29'; h.el('range').fire('submit');
  const custom = h.calls.at(-1);
  check('custom request sends both inclusive date strings', custom.params.from === '2026-10-29' && custom.params.to === '2026-10-29' && !custom.params.range);
  custom.resolve(report(true, null, ['2026-10-29', '2026-10-29'])); await flush();
  slow.resolve(report(true, 'yesterday')); await flush();
  check('delayed older selection cannot repaint', h.el('period').textContent.startsWith('custom') && h.el('period').textContent.includes('2026-10-29'));
  check('custom URL clears preset', !new URL(h.window.location.href).searchParams.has('range') && new URL(h.window.location.href).searchParams.get('from') === '2026-10-29');
  const previous = h.el('cards-wrap').innerHTML;
  const urlBefore = h.window.location.href;
  for (const dates of [['', ''], ['2026-10-30', '2026-10-29'], ['2026-02-30', '2026-03-01'], ['2025-01-01', '2026-01-02']]) {
    h.el('from').value = dates[0]; h.el('to').value = dates[1]; const count = h.calls.length; h.el('range').fire('submit');
    check('invalid custom input stays editable without request/URL mutation ' + dates, h.calls.length === count && h.window.location.href === urlBefore && !h.el('error').hidden && h.el('from').value === dates[0]);
  }
  check('invalid custom retains last good data and label', h.el('cards-wrap').innerHTML === previous && h.el('period').textContent.includes('2026-10-29'));
  h.el('yesterday').fire('click'); h.calls.at(-1).reject({ message: 'transient' }); await flush();
  check('transient failure retains last good report and URL', h.el('cards-wrap').innerHTML === previous && h.window.location.href === urlBefore && !h.el('error').hidden);
  h.el('live').fire('click'); h.calls.at(-1).resolve(report()); await flush();
  check('return live restores controls and exactly one timer', !h.el('funnel-select').hidden && !h.el('minutes-select').hidden && h.intervals.size === 1);
  check('live clears date, preset and compare URL keys', !/[?&](range|from|to|compare_from|compare_to)=/.test(h.window.location.href));
  h.el('funnel-select').value = '20'; h.el('funnel-select').fire('change');
  check('restored live window selectors work', h.calls.at(-1).params.funnel === 20);
  h.calls.at(-1).resolve(report()); await flush();
  const writeCount = h.writes.length;
  h.navigate('http://localhost:8093/wp-admin/admin.php?page=pc-data&range=yesterday'); h.listeners.popstate();
  check('back/forward restores history route', h.calls.at(-1).params.range === 'yesterday' && h.intervals.size === 0);
  h.calls.at(-1).resolve(report(true, 'yesterday', ['2026-10-29', '2026-10-29'])); await flush();
  check('popstate does not push/replace history', h.writes.length === writeCount);
  h.el('live').fire('click'); const liveSlow = h.calls.at(-1);
  h.el('today').fire('click'); h.calls.at(-1).resolve(report(true)); await flush(); liveSlow.resolve(report()); await flush();
  check('old live response cannot overwrite selected history', h.el('period').textContent.startsWith('todayOption') && h.intervals.size === 0);
  const escaped = report(true); escaped.series.points[0].label = '<script>bad</script>'; escaped.lists.pages = [{ path: '<img src=x>', url: 'javascript:bad', count: 1 }]; escaped.links.pages = 'javascript:bad';
  h.el('refresh').fire('click'); h.calls.at(-1).resolve(escaped); await flush();
  check('chart and list labels escaped', h.el('chart-wrap').innerHTML.includes('&lt;script&gt;') && h.el('lists').innerHTML.includes('&lt;img'));
  check('unsafe list and drilldown URLs removed', !h.el('lists').innerHTML.includes('href="javascript:'));
  h.start(); check('re-render does not duplicate timer', h.intervals.size === 0); h.calls.at(-1).resolve(report(true)); await flush();
  const restored = harness('http://localhost:8093/wp-admin/admin.php?page=pc-data&from=2026-10-29&to=2026-10-30');
  check('reload restores custom date route and inputs', restored.calls[0].params.from === '2026-10-29' && restored.el('from').value === '2026-10-29' && restored.intervals.size === 0);
  const invalidUrl = harness('http://localhost:8093/wp-admin/admin.php?page=pc-data&from=&to=');
  check('empty explicit URL dates never silently become live', invalidUrl.calls[0].path.endsWith('/history') && invalidUrl.calls[0].params.from === '');
  invalidUrl.calls[0].reject({ code: 'pc_invalid_param', data: { status: 400 } }); await flush();
  check('invalid initial URL remains unchanged and editable', invalidUrl.writes.length === 0 && !invalidUrl.el('error').hidden && invalidUrl.el('from').value === '');
  const noStorage = harness(undefined, true);
  check('missing URL support and denied storage gracefully default live', noStorage.calls[0].params.funnel === 10 && noStorage.intervals.size === 1);
  noStorage.calls[0].resolve(report()); await flush();
  const visibleCount = noStorage.calls.length;
  noStorage.document.visibilityState = 'hidden'; [...noStorage.intervals.values()][0].fn();
  check('hidden live page does not poll', noStorage.calls.length === visibleCount);
  noStorage.document.visibilityState = 'visible'; noStorage.documentListeners.visibilitychange();
  check('visible live page refreshes immediately', noStorage.calls.length === visibleCount + 1);
  noStorage.calls.at(-1).resolve(report()); await flush();
  noStorage.start();
  check('re-render live retains exactly one timer', noStorage.intervals.size === 1);
  noStorage.calls.at(-1).resolve(report()); await flush();
  noStorage.el('today').fire('click'); noStorage.calls.at(-1).resolve(report(true)); await flush();
  check('history works with no URL APIs and storage', noStorage.el('period').textContent.includes('2026-10-30') && noStorage.intervals.size === 0);
  console.log(JSON.stringify({ passed, failed: 0 }));
})().catch(error => { console.error(error); process.exitCode = 1; });
