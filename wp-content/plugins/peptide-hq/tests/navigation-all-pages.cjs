'use strict';
/** Real router; synthetic nine-page documents, intercepted HTTP/history only. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(path.join(process.env.HQ_NAV_MODEL_DEPS || 'D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps', 'node_modules/jsdom'));
const nav = require('../assets/hq-navigation.js');
const BASE = 'https://hq.example.invalid/wp-admin/admin.php';
const UUID = '12345678-1234-4234-8234-123456789abc';
const ACCOUNT = '0123456789abcdef0123456789abcdef';
const POLICY = {
  pchq: { range: ['today', 'yesterday', '7d', '30d'] },
  'pchq-sites': { site_server: 'positiveId', site_tools: 'positiveId' },
  'pchq-servers': { edit: 'positiveId' },
  'pchq-accounts': { site: 'positiveId', edit: 'positiveId', add: ['1'] },
  'pchq-alerts': {},
  'pchq-site-recycle': { restore_requested: ['1'], retry_requested: ['1'] },
  'pchq-operations': { tab: ['tasks', 'releases', 'create', 'backups', 'agents'], site: 'signedId' },
  'pchq-payments': { site_id: 'nonnegativeId', state: ['', 'creating', 'created', 'unknown', 'failed', 'succeeded', 'confirmed_failed', 'pending', 'delivered', 'review'], paged: 'pageNumber', deleted: ['1'], edit_account: 'accountId', new_account: ['1'], account_error: ['1'] },
  'pchq-create-store': { job: 'uuid4' }
};
const cases = [], active = new Set();
const test = (name, run) => cases.push({ name, run });
const esc = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
const route = (page, query = '') => `${BASE}?page=${page}${query ? '&' + query : ''}`;
const screen = page => page === 'pchq' ? 'toplevel_page_pchq' : `master-admin_page_${page}`;
const staticAssets = ['peptide-hq/assets/hq-workspace.js', 'peptide-hq/assets/hq-navigation.js', 'peptide-hq/assets/hq-operations.js', 'peptide-hq/assets/hq-provision.js', 'peptide-panapay/assets/hub.js'];
function html(page = 'pchq', body = '', extra = '') {
  return `<!doctype html><html><head><title>${page}</title><meta name="pchq-navigation" content="${'a'.repeat(64)}" data-base="${BASE}" data-page="${page}" data-screen="${screen(page)}" data-session="synthetic-only" data-policy="${esc(JSON.stringify(POLICY))}">${staticAssets.map(src => `<script src="https://hq.example.invalid/wp-content/plugins/${src}?ver=model"></script>`).join('')}<link rel="stylesheet" href="https://hq.example.invalid/wp-content/plugins/peptide-hq/assets/hq-workspace.css?ver=model">${extra}</head><body class="wp-admin pchq-workspace ${screen(page)}"><div id="wpadminbar">Bar</div><div id="adminmenuwrap">Menu</div><div class="pchq-workspace-head"><nav class="pchq-workspace-location"><span>${page}</span></nav></div><div id="wpbody-content"><div class="wrap pchq${page === 'pchq-payments' ? ' pc-pay-hub' : ''}"><h1>${page}</h1>${body}</div></div></body></html>`;
}
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); };
function fixture(page = 'pchq', content = '') {
  const dom = new JSDOM(html(page, content), { url: route(page), pretendToBeVisual: true });
  const window = dom.window, requests = [], native = [], confirms = [];
  window.AbortController = globalThis.AbortController;
  window.fetch = (url, options) => { const work = deferred(); requests.push({ url: String(url), options, ...work }); return work.promise; };
  window.confirm = message => { confirms.push(message); return true; }; window.scrollTo = () => {};
  const controller = nav.mount(window, { navigate: (url, replace) => native.push({ url: String(url), replace }) });
  assert.ok(controller);
  const f = { dom, window, document: window.document, controller, requests, native, confirms, close() { controller.destroy(); window.close(); active.delete(f); } }; active.add(f); return f;
}
function respond(request, url, source) { request.resolve({ ok: true, status: 200, url, headers: { get: name => name === 'content-type' ? 'text/html' : null }, text: async () => source }); }
function formHTML(fields = '<input type="hidden" name="page" value="pchq-payments"><select name="site_id"><option value="0">All</option><option value="7" selected>Seven</option></select><select name="state"><option selected value="pending">Pending</option></select>') { return `<form id="filters" method="get" action="${BASE}">${fields}<button type="submit">Filter</button></form>`; }
function submit(f, button = f.document.querySelector('#filters button')) { const event = new f.window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button }); f.document.querySelector('#filters').dispatchEvent(event); return event; }

for (const page of Object.keys(POLICY)) {
  test(`nine-page router accepts ${page}`, () => assert.equal(nav.safeURL(route(page), BASE, POLICY)?.searchParams.get('page'), page));
  for (const query of ['_wpnonce=model', 'nonce=model', 'action=pchq_login', 'token=model', 'ticket=model', 'return_url=https%3A%2F%2Fx.invalid', 'page=pchq', 'unrecognized=1']) test(`never intercepts ${page} with ${query.split('=')[0]}`, () => assert.equal(nav.safeURL(route(page, query), BASE, POLICY), null));
}
for (const [page, query] of [
  ...['tasks', 'releases', 'create', 'backups', 'agents'].map(tab => ['pchq-operations', `tab=${tab}&site=-7`]),
  ...['0', '1', '-1', '9007199254740991', '-9007199254740991'].map(site => ['pchq-operations', `site=${site}`]),
  ...POLICY['pchq-payments'].state.map(state => ['pchq-payments', `site_id=0&state=${state}&paged=100000`]),
  ['pchq-payments', 'site_id=7&paged=1'], ['pchq-payments', 'deleted=1'], ['pchq-payments', 'edit_account=default'], ['pchq-payments', `edit_account=${ACCOUNT}`], ['pchq-payments', 'new_account=1&account_error=1'], ['pchq-create-store', `job=${UUID}`]
]) test(`typed query accepts ${page} ${query}`, () => assert.ok(nav.safeURL(route(page, query), BASE, POLICY)));
for (const [page, query] of [
  ...['-0', '+1', '01', '-01', '1.0', '1e3', '9007199254740992', '-9007199254740992', '', 'Infinity'].map(site => ['pchq-operations', `site=${encodeURIComponent(site)}`]),
  ['pchq-operations', 'tab=destroy'], ['pchq-operations', 'operation=backup'], ['pchq-operations', 'site[]=1'], ['pchq-operations', 'site=1&site=2'],
  ...['-1', '-0', '+1', '01', '1.0', '', '9007199254740992'].map(site => ['pchq-payments', `site_id=${encodeURIComponent(site)}`]),
  ...['0', '-1', '100001', '01', '1.0', '1e3', ''].map(paged => ['pchq-payments', `paged=${paged}`]),
  ['pchq-payments', 'state=SUCCEEDED'], ['pchq-payments', 'state=blocked'], ['pchq-payments', 'new_account=0'], ['pchq-payments', 'deleted=0'], ['pchq-payments', 'edit_account=DEFAULT'], ['pchq-payments', `edit_account=${ACCOUNT.toUpperCase()}`], ['pchq-payments', `edit_account=${ACCOUNT.slice(1)}`], ['pchq-payments', 'new_account=1&edit_account=default'], ['pchq-payments', 'deleted=1&new_account=1'], ['pchq-payments', 'deleted=1&edit_account=default'],
  ...[UUID.replace('-4234-', '-1234-'), UUID.replace('-8234-', '-7234-'), UUID.toUpperCase(), '', UUID + '/'].map(job => ['pchq-create-store', `job=${encodeURIComponent(job)}`])
]) test(`typed query rejects ${page} ${query}`, () => assert.equal(nav.safeURL(route(page, query), BASE, POLICY), null));

for (const page of ['pchq-operations', 'pchq-payments', 'pchq-create-store']) test(`complex page ${page} swaps content with the same live document and shell`, async () => {
  const f = fixture(); try { const doc = f.document, shell = doc.querySelector('#adminmenuwrap'), initial = doc.querySelector('#wpbody-content'); const work = f.controller.navigate(route(page)); assert.equal(f.requests.length, 1); assert.equal(doc.querySelector('#wpbody-content'), initial); respond(f.requests[0], route(page), html(page, '<input name="new_control" value="ready">')); assert.equal(await work, true); assert.equal(f.window.document, doc); assert.equal(doc.querySelector('#adminmenuwrap'), shell); assert.equal(doc.querySelector('input[name=new_control]').value, 'ready'); assert.equal(f.native.length, 0); assert.equal(f.requests[0].options.cache, 'no-store'); } finally { f.close(); }
});
for (const start of Object.keys(POLICY)) test(`every HQ destination works after direct entry at ${start}`, async () => {
  for (const target of Object.keys(POLICY)) {
    const f = fixture(start); try { const doc = f.document, bar = doc.querySelector('#wpadminbar'), shell = doc.querySelector('#adminmenuwrap'); const work = f.controller.navigate(route(target)); assert.equal(f.requests.length, 1); respond(f.requests[0], route(target), html(target, '<button id="new-control">Ready</button>')); assert.equal(await work, true, `${start} -> ${target}`); assert.equal(f.window.document, doc); assert.equal(doc.querySelector('#wpadminbar'), bar); assert.equal(doc.querySelector('#adminmenuwrap'), shell); assert.equal(doc.querySelector('h1').textContent, target); assert.equal(f.native.length, 0); assert.equal(doc.querySelector('#wpbody-content').inert, false); } finally { f.close(); }
  }
});
test('GET payment filters serialize selected successful controls and retain the outer shell', async () => {
  const f = fixture('pchq-payments', formHTML()); try { const event = submit(f); assert.equal(event.defaultPrevented, true); assert.equal(f.requests.length, 1); const url = new URL(f.requests[0].url); assert.equal(url.searchParams.get('site_id'), '7'); assert.equal(url.searchParams.get('state'), 'pending'); assert.equal(url.searchParams.get('page'), 'pchq-payments'); respond(f.requests[0], url.href, html('pchq-payments', '<p>Filtered fresh ledger</p>')); await flush(); assert.match(f.document.querySelector('#wpbody-content').textContent, /Filtered fresh ledger/); assert.equal(f.native.length, 0); } finally { f.close(); }
});
test('GET disabled and unchecked controls are excluded while the successful submitter is preserved', () => {
  const f = fixture('pchq-payments', formHTML('<input name="page" value="pchq-payments"><input disabled name="nonce" value="ignored"><input type="checkbox" name="unknown" value="ignored"><input type="radio" name="token" value="ignored">')); try { const button = f.document.querySelector('button'); button.name = 'paged'; button.value = '2'; const event = submit(f, button); assert.equal(event.defaultPrevented, true); const url = new URL(f.requests[0].url); assert.equal(url.searchParams.get('paged'), '2'); assert.deepEqual([...url.searchParams.keys()].sort(), ['page', 'paged']); } finally { f.close(); }
});
for (const [label, change] of [
  ['POST', form => { form.method = 'post'; }], ['foreign action', form => { form.action = 'https://elsewhere.example.invalid/wp-admin/admin.php'; }], ['admin-post action', form => { form.action = BASE.replace('admin.php', 'admin-post.php'); }], ['new-window target', form => { form.target = '_blank'; }], ['nonce field', form => { form.insertAdjacentHTML('beforeend', '<input name="_wpnonce" value="model">'); }], ['unknown field', form => { form.insertAdjacentHTML('beforeend', '<input name="unknown" value="model">'); }], ['duplicate page', form => { form.insertAdjacentHTML('beforeend', '<input name="page" value="pchq">'); }], ['file field', form => { form.insertAdjacentHTML('beforeend', '<input type="file" name="data">'); }], ['multipart encoding', form => { form.enctype = 'multipart/form-data'; }], ['submitter POST override', form => { form.querySelector('button').setAttribute('formmethod', 'post'); }], ['submitter foreign action override', form => { form.querySelector('button').setAttribute('formaction', 'https://elsewhere.example.invalid/'); }], ['submitter new-window override', form => { form.querySelector('button').setAttribute('formtarget', '_blank'); }]
]) test(`unsafe or non-read GET form stays native: ${label}`, () => { const f = fixture('pchq-payments', formHTML()); try { change(f.document.querySelector('form')); assert.equal(submit(f).defaultPrevented, false); assert.equal(f.requests.length, 0); } finally { f.close(); } });
test('locally canceled GET submit remains untouched', () => { const f = fixture('pchq-payments', formHTML()); try { f.document.querySelector('form').addEventListener('submit', event => event.preventDefault()); const event = submit(f); assert.equal(event.defaultPrevented, true); assert.equal(f.requests.length, 0); } finally { f.close(); } });
test('GET action carrying a mutation query stays native even though native GET would replace that query', () => { const f = fixture('pchq-payments', formHTML()); try { f.document.querySelector('form').action = BASE + '?action=pchq_login&_wpnonce=model'; assert.equal(submit(f).defaultPrevented, false); assert.equal(f.requests.length, 0); } finally { f.close(); } });
test('provision return keeps arbitrary inline script inert and rejects that document', async () => { const f = fixture(); try { const work = f.controller.navigate(route('pchq-create-store', `job=${UUID}`)); respond(f.requests[0], route('pchq-create-store', `job=${UUID}`), html('pchq-create-store', '<script>window.untrusted=1</script>')); assert.equal(await work, false); assert.equal(f.window.untrusted, undefined); assert.equal(f.native.length, 1); } finally { f.close(); } });
test('missing complex page controller resource is native fallback rather than an inert fake success', async () => { const f = fixture(); try { const work = f.controller.navigate(route('pchq-payments')); respond(f.requests[0], route('pchq-payments'), html('pchq-payments', '', '<script src="https://hq.example.invalid/wp-content/plugins/unknown/controller.js"></script>')); assert.equal(await work, false); assert.equal(f.native.length, 1); assert.equal(f.document.querySelector('h1').textContent, 'pchq'); } finally { f.close(); } });
test('login redirection from a newly enabled route never replaces the live HQ content', async () => { const f = fixture(); try { const old = f.document.querySelector('#wpbody-content'); const work = f.controller.navigate(route('pchq-operations', 'site=-7')); f.requests[0].resolve({ ok: true, status: 200, url: 'https://hq.example.invalid/wp-login.php', headers: { get: () => 'text/html' }, text: async () => '<form id="loginform"></form>' }); assert.equal(await work, false); assert.equal(f.document.querySelector('#wpbody-content'), old); assert.equal(f.native.length, 1); } finally { f.close(); } });

(async () => {
  const results = [];
  for (const item of cases) { let timer; try { await Promise.race([Promise.resolve().then(item.run), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('model deadline exceeded')), 3000); })]); results.push({ name: item.name, pass: true }); } catch (error) { results.push({ name: item.name, pass: false, error: error.message }); } finally { clearTimeout(timer); for (const f of [...active]) f.close(); } }
  const report = { complete: true, checks: results.length, failed: results.filter(item => !item.pass).length, modelOnly: true, realBrowser: false, realHttp: false, cases: results };
  if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(report, null, 2) + '\n');
  for (const item of results.filter(item => !item.pass)) console.error(`FAIL ${item.name}: ${item.error}`);
  console.log(`${report.failed ? 'FAIL' : 'PASS'} ${report.checks - report.failed}/${report.checks} actual all-HQ router checks; synthetic boundaries only.`); process.exitCode = report.failed ? 1 : 0;
})();
