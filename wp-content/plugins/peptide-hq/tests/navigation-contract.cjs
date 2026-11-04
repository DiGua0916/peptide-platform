'use strict';
/** Actual navigation module against synthetic jsdom documents and intercepted fetch/history. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require(path.join(process.env.HQ_NAV_MODEL_DEPS || 'D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps', 'node_modules/jsdom'));
const nav = require('../assets/hq-navigation.js');
const BASE = 'https://hq.example.invalid/wp-admin/admin.php';
const POLICY = {
  pchq: { range: ['today', 'yesterday', '7d', '30d'] },
  'pchq-sites': { site_server: 'positiveId', site_tools: 'positiveId' },
  'pchq-servers': { edit: 'positiveId' },
  'pchq-accounts': { site: 'positiveId', edit: 'positiveId', add: ['1'] },
  'pchq-alerts': {},
  'pchq-site-recycle': { restore_requested: ['1'], retry_requested: ['1'] }
};
const BUILD = 'a'.repeat(64);
const SESSION = 'synthetic-session-only';
const cases = [];
const activeFixtures = new Set();
const test = (name, run) => cases.push({ name, run });
const esc = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
const route = page => `${BASE}?page=${page}`;
const screen = page => page === 'pchq' ? 'toplevel_page_pchq' : `master-admin_page_${page}`;
const assetsHTML = `<link id="peptide-hq-workspace-css" rel="stylesheet" href="https://hq.example.invalid/wp-content/plugins/peptide-hq/assets/hq-workspace.css?ver=synthetic"><script id="peptide-hq-workspace-js" src="https://hq.example.invalid/wp-content/plugins/peptide-hq/assets/hq-workspace.js?ver=synthetic"></script><script id="peptide-hq-navigation-js" src="https://hq.example.invalid/wp-content/plugins/peptide-hq/assets/hq-navigation.js?ver=synthetic"></script>`;
function html(page = 'pchq', label = page, options = {}) {
  const metadata = { build: BUILD, base: BASE, page, screen: screen(page), session: SESSION, policy: POLICY, ...options.meta };
  const meta = `<meta name="pchq-navigation" content="${esc(metadata.build)}" data-base="${esc(metadata.base)}" data-page="${esc(metadata.page)}" data-screen="${esc(metadata.screen)}" data-session="${esc(metadata.session)}" data-policy="${esc(JSON.stringify(metadata.policy))}">`;
  return `<!doctype html><html><head><title>${esc(label)} — synthetic HQ</title>${options.noMeta ? '' : meta}${assetsHTML}${options.head || ''}</head><body class="wp-admin pchq-workspace ${esc(screen(page))}"><div id="wpwrap"><div id="wpadminbar">Persistent synthetic bar</div><div id="adminmenuwrap"><ul id="adminmenu">${Object.keys(POLICY).map(key => `<li><a href="${route(key)}">${key}</a></li>`).join('')}</ul></div><div id="wpcontent"><header class="pchq-workspace-head"><nav class="pchq-workspace-location"><a>Management</a><span>/</span><span aria-current="page">${esc(label)}</span></nav></header><div id="wpbody"><div id="wpbody-content"><div class="wrap pchq" data-fixture="${esc(label)}"><h1>${esc(label)}</h1><form id="synthetic-form" method="post" action="https://hq.example.invalid/wp-admin/admin-post.php"><input type="hidden" name="action" value="synthetic_save"><input type="hidden" name="_wpnonce" value="synthetic_nonce"><input name="title" value="Original"><input type="checkbox" name="enabled"><textarea name="note">Original note</textarea><select name="role"><option value="one">One</option><option value="two">Two</option></select><button>Save</button></form><a id="synthetic-link" href="${route('pchq-accounts')}">Accounts</a>${options.content || ''}</div><div class="clear"></div></div></div></div></div></body></html>`;
}
function documentFor(source, url = route('pchq')) { return new JSDOM(source, { url }).window.document; }
const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); };
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function response(page, label = page, changes = {}) {
  return { ok: true, status: 200, redirected: false, url: route(page), headers: { get: key => key.toLowerCase() === 'content-type' ? 'text/html; charset=UTF-8' : null }, text: async () => html(page, label), ...changes };
}
function fixture(start = 'pchq') {
  // Script execution and subresource loading are disabled. No real HTTP transport is supplied.
  const dom = new JSDOM(html(start, 'Initial'), { url: route(start), pretendToBeVisual: true });
  const window = dom.window, requests = [], native = [], scrolls = [], histories = [], confirms = [];
  window.AbortController = globalThis.AbortController;
  window.fetch = (url, options) => { const work = deferred(); requests.push({ url: String(url), options, ...work }); return work.promise; };
  window.VialCoveDialogs = {confirm: async message => { confirms.push(message); return fixture.confirmDecision; }};
  window.scrollTo = (x, y) => { if (typeof x === 'object') { y = x.top ?? x.y ?? 0; x = x.left ?? x.x ?? 0; } scrolls.push({ x, y }); Object.defineProperty(window, 'scrollX', { configurable: true, value: x }); Object.defineProperty(window, 'scrollY', { configurable: true, value: y }); };
  for (const name of ['pushState', 'replaceState']) { const original = window.history[name].bind(window.history); window.history[name] = (state, title, url) => { histories.push({ method: name, state: structuredClone(state), url: url && String(url) }); return original(state, title, url); }; }
  const controller = nav.mount(window, { navigate: (url, replace) => native.push({ url: String(url), replace }) });
  assert.ok(controller, 'Synthetic supported HQ document mounts');
  let closed = false;
  const result = { window, document: window.document, requests, native, scrolls, histories, confirms, controller };
  result.close = () => { if (closed) return; closed = true; activeFixtures.delete(result); controller.destroy(); window.close(); };
  activeFixtures.add(result);
  return result;
}
fixture.confirmDecision = true;
function busy(doc) { return Boolean(doc.querySelector('[aria-busy="true"]')) || doc.body.classList.contains('pchq-navigation-busy'); }
function click(window, element, extras = {}) { return element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...extras })); }

test('public contract exports only the required callable boundaries', () => { for (const key of ['safeURL', 'formSignature', 'assets', 'readMeta', 'compatibleDocument', 'mount']) assert.equal(typeof nav[key], 'function', key); });
for (const page of Object.keys(POLICY)) test(`safe URL allows dedicated page ${page}`, () => assert.equal(nav.safeURL(route(page), BASE, POLICY)?.searchParams.get('page'), page));
for (const [page, values] of [
  ['pchq', 'range=7d'], ['pchq-sites', 'site_server=2'], ['pchq-sites', 'site_tools=2'], ['pchq-servers', 'edit=3'],
  ['pchq-accounts', 'site=2&edit=4'], ['pchq-accounts', 'site=2&add=1'], ['pchq-site-recycle', 'restore_requested=1'], ['pchq-site-recycle', 'retry_requested=1']
]) test(`safe URL preserves supported ${page} ${values}`, () => assert.ok(nav.safeURL(`${route(page)}&${values}`, BASE, POLICY)));
for (const value of [
  `${route('pchq')}&range=unexpected`, `${route('pchq')}&range[]=today`, `${route('pchq')}&page=pchq-alerts`, `${route('pchq')}&_wpnonce=synthetic`, `${route('pchq')}&action=pchq_site_refresh`, `${route('pchq')}&nonce=synthetic`, `${route('pchq')}&token=synthetic`, `${route('pchq')}&unknown=1`,
  route('pchq-operations'), route('pchq-payments'), route('pchq-create-store'), route('pc-overview'), `${BASE}?page=pchq-accounts&site=-2`, `${BASE}?page=pchq-accounts&site=0`, `${BASE}?page=pchq-accounts&site=2.0`, `${BASE}?page=pchq-accounts&site=1e3`, `${BASE}?page=pchq-accounts&site=9007199254740992`, `${BASE}?page=pchq-accounts&site[]=2`, `${BASE}?page=pchq-accounts&add=0`,
  'https://external.example.invalid/wp-admin/admin.php?page=pchq', 'https://user:pass@hq.example.invalid/wp-admin/admin.php?page=pchq', 'http://hq.example.invalid/wp-admin/admin.php?page=pchq', 'https://hq.example.invalid/wp-admin/admin-post.php?action=pchq_login&site=2&_wpnonce=synthetic', 'https://hq.example.invalid/wp-login.php', 'https://hq.example.invalid/wp-admin/profile.php', 'javascript:alert(1)'
]) test(`unsafe URL rejected: ${value.replaceAll('synthetic', 'fixture')}`, () => assert.equal(nav.safeURL(value, BASE, POLICY), null));

test('form signature observes editable changes without changing the form', () => {
  const doc = documentFor(html()), form = doc.querySelector('form'), initial = nav.formSignature(form), before = form.outerHTML;
  assert.equal(nav.formSignature(form), initial); assert.equal(form.outerHTML, before);
  for (const [selector, change] of [['input[name=title]', node => { node.value += ' changed'; }], ['input[type=checkbox]', node => { node.checked = true; }], ['textarea', node => { node.value += ' changed'; }], ['select', node => { node.value = 'two'; }]]) { const saved = nav.formSignature(form); change(form.querySelector(selector)); assert.notEqual(nav.formSignature(form), saved); }
});
test('document compatibility accepts same identity and already-loaded resource set', () => {
  const current = documentFor(html()), incoming = documentFor(html('pchq-accounts', 'Accounts'), route('pchq-accounts'));
  const result = nav.compatibleDocument(incoming, nav.readMeta(current), nav.assets(current), new URL(route('pchq-accounts')));
  assert.ok(result?.content); assert.equal(result.meta.page, 'pchq-accounts');
});
for (const [name, make] of [
  ['missing identity meta', () => html('pchq-accounts', 'Bad', { noMeta: true })], ['changed session', () => html('pchq-accounts', 'Bad', { meta: { session: 'other-session' } })], ['changed build', () => html('pchq-accounts', 'Bad', { meta: { build: 'b'.repeat(64) } })], ['wrong page identity', () => html('pchq-alerts', 'Bad')], ['invalid screen class token', () => html('pchq-accounts', 'Bad', { meta: { screen: 'invalid two-tokens' } })], ['expanded same-build policy', () => html('pchq-accounts', 'Bad', { meta: { policy: { ...POLICY, 'pchq-payments': {} } } })], ['content script', () => html('pchq-accounts', 'Bad', { content: '<script>window.synthetic=1</script>' })], ['content style', () => html('pchq-accounts', 'Bad', { content: '<style>.synthetic{display:none}</style>' })], ['content iframe', () => html('pchq-accounts', 'Bad', { content: '<iframe src="https://external.example.invalid/"></iframe>' })], ['new script', () => html('pchq-accounts', 'Bad', { head: '<script src="https://hq.example.invalid/unknown.js"></script>' })], ['new stylesheet', () => html('pchq-accounts', 'Bad', { head: '<link rel="stylesheet" href="https://hq.example.invalid/unknown.css">' })], ['duplicate content root', () => html('pchq-accounts', 'Bad', { content: '<div id="wpbody-content">Duplicate</div>' })], ['duplicate metadata', () => html('pchq-accounts', 'Bad', { head: '<meta name="pchq-navigation" content="duplicate">' })], ['login document', () => '<!doctype html><html><body class="login"><form id="loginform"></form></body></html>']
]) test(`incompatible document rejected: ${name}`, () => { const current = documentFor(html()); assert.equal(nav.compatibleDocument(documentFor(make(), route('pchq-accounts')), nav.readMeta(current), nav.assets(current), new URL(route('pchq-accounts'))), null); });

test('mount neither prefetches nor intercepts native form submissions', async () => {
  const f = fixture(); try { await flush(); assert.equal(f.requests.length, 0); const event = new f.window.Event('submit', { bubbles: true, cancelable: true }); f.document.querySelector('form').dispatchEvent(event); assert.equal(event.defaultPrevented, false); assert.equal(f.requests.length, 0); } finally { f.close(); }
});
test('legitimate same-origin admin POST is not intercepted and avoids redundant unsaved prompt', () => {
  const f = fixture(); try {
    const form = f.document.querySelector('form'), input = form.querySelector('input[name=title]'); input.value = 'Synthetic saved title'; input.dispatchEvent(new f.window.Event('input', { bubbles: true })); assert.equal(f.controller.dirty(), true);
    const submit = new f.window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('button') }); form.dispatchEvent(submit);
    const unload = new f.window.Event('beforeunload', { cancelable: true }); f.window.dispatchEvent(unload);
    assert.equal(submit.defaultPrevented, false); assert.equal(unload.defaultPrevented, false); assert.equal(f.requests.length, 0); assert.equal(f.native.length, 0);
  } finally { f.close(); }
});
test('late-cancelled POST does not suppress future dirty guard', () => {
  const f = fixture(); try {
    const form = f.document.querySelector('form'); form.querySelector('input[name=title]').value = 'Synthetic not submitted';
    f.document.addEventListener('submit', event => event.preventDefault(), { once: true });
    const submit = new f.window.SubmitEvent('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(submit);
    const unload = new f.window.Event('beforeunload', { cancelable: true }); f.window.dispatchEvent(unload);
    assert.equal(submit.defaultPrevented, true); assert.equal(unload.defaultPrevented, true); assert.equal(f.requests.length, 0);
  } finally { f.close(); }
});
test('browser constraint-invalid form never obtains native POST exemption', () => {
  const f = fixture(); try {
    const form = f.document.querySelector('form'), input = form.querySelector('input[name=title]'); input.required = true; input.value = ''; let submitted = 0;
    f.document.addEventListener('submit', () => { submitted++; }); form.requestSubmit(); assert.equal(submitted, 0); assert.equal(input.validity.valueMissing, true);
    const unload = new f.window.Event('beforeunload', { cancelable: true }); f.window.dispatchEvent(unload); assert.equal(unload.defaultPrevented, true); assert.equal(f.requests.length, 0);
  } finally { f.close(); }
});
test('target-blank form does not disable dirty guard for current window navigation', async () => {
  const f = fixture(); fixture.confirmDecision = false; try {
    const form = f.document.querySelector('form'); form.target = '_blank'; form.querySelector('input[name=title]').value = 'Synthetic new window';
    const submit = new f.window.SubmitEvent('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(submit); assert.equal(submit.defaultPrevented, false);
    const unload = new f.window.Event('beforeunload', { cancelable: true }); f.window.dispatchEvent(unload); assert.equal(unload.defaultPrevented, true);
    assert.equal(await f.controller.navigate(route('pchq-alerts')), false); assert.equal(f.requests.length, 0);
  } finally { fixture.confirmDecision = true; f.close(); }
});
test('deliberate supported click retains persistent shell and updates content', async () => {
  const f = fixture(); try { const shell = f.document.querySelector('#adminmenuwrap'), body = f.document.querySelector('#wpbody-content'); click(f.window, f.document.querySelector('#synthetic-link')); assert.equal(f.requests.length, 1); assert.equal(f.requests[0].options.credentials, 'same-origin'); assert.equal(f.requests[0].options.cache, 'no-store'); assert.ok(body.textContent.includes('Initial')); f.requests[0].resolve(response('pchq-accounts', 'Loaded accounts')); await flush(); assert.equal(f.document.querySelector('#adminmenuwrap'), shell); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Loaded accounts')); assert.equal(f.window.location.search, '?page=pchq-accounts'); } finally { f.close(); }
});
test('history SecurityError falls back natively before visible DOM changes without false retained-content claim', async () => {
  const f = fixture(); try {
    const original = f.document.querySelector('#wpbody-content'); f.window.history.pushState = () => { throw new f.window.DOMException('Synthetic denied history write', 'SecurityError'); };
    const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Should open natively')); assert.equal(await work, false);
    assert.equal(f.document.querySelector('#wpbody-content'), original); assert.ok(original.textContent.includes('Initial')); assert.deepEqual(f.native, [{ url: route('pchq-alerts'), replace: false }]);
    assert.equal(f.document.querySelector('.pchq-navigation-status').textContent.includes('已保留'), false); assert.equal(busy(f.document), false);
  } finally { f.close(); }
});
test('initial replaceState disabled makes mount native-only and removes installed handlers', () => {
  const dom = new JSDOM(html(), { url: route('pchq') }), window = dom.window, tracked = new Map(); let fetches = 0;
  const trackedTypes = new Set(['click', 'input', 'change', 'submit', 'beforeunload', 'popstate', 'scroll', 'pageshow', 'pagehide']);
  for (const [label, target] of [['document', window.document], ['window', window]]) {
    const add = target.addEventListener.bind(target), remove = target.removeEventListener.bind(target);
    target.addEventListener = (type, listener, options) => { if (trackedTypes.has(type)) { const key = `${label}:${type}`; if (!tracked.has(key)) tracked.set(key, new Set()); tracked.get(key).add(listener); } return add(type, listener, options); };
    target.removeEventListener = (type, listener, options) => { tracked.get(`${label}:${type}`)?.delete(listener); return remove(type, listener, options); };
  }
  window.fetch = () => { fetches++; throw new Error('Forbidden model fetch'); }; window.AbortController = globalThis.AbortController;
  window.history.replaceState = () => { throw new window.DOMException('Synthetic history unavailable', 'SecurityError'); };
  try { assert.equal(nav.mount(window, { navigate: () => {} }), null); assert.ok(Array.from(tracked.values()).every(listeners => listeners.size === 0)); assert.equal(window.document.querySelector('.pchq-navigation-status'), null); assert.equal(busy(window.document), false); assert.equal(fetches, 0); } finally { window.close(); }
});
test('initial metadata screen absent from body makes mount native-only without handlers or fetch', () => {
  const dom = new JSDOM(html(), { url: route('pchq') }), window = dom.window; let fetches = 0;
  window.document.body.classList.remove(screen('pchq')); window.fetch = () => { fetches++; throw new Error('Forbidden model fetch'); }; window.AbortController = globalThis.AbortController;
  try { assert.equal(nav.mount(window, { navigate: () => {} }), null); assert.equal(window.document.querySelector('.pchq-navigation-status'), null); assert.equal(fetches, 0); } finally { window.close(); }
});
test('target metadata screen absent from body falls back natively before current content replacement', async () => {
  const f = fixture(); try {
    const original = f.document.querySelector('#wpbody-content'), target = documentFor(html('pchq-alerts', 'Wrong body screen'), route('pchq-alerts')); target.body.classList.remove(screen('pchq-alerts'));
    const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Wrong body screen', { text: async () => target.documentElement.outerHTML }));
    assert.equal(await work, false); assert.equal(f.document.querySelector('#wpbody-content'), original); assert.deepEqual(f.native, [{ url: route('pchq-alerts'), replace: false }]); assert.equal(busy(f.document), false);
  } finally { f.close(); }
});
test('pre-navigation replaceState failure immediately uses native path without fetch or busy content', async () => {
  const f = fixture(); try {
    const original = f.document.querySelector('#wpbody-content'); f.window.history.replaceState = () => { throw new f.window.DOMException('Synthetic history frequency limit', 'SecurityError'); };
    assert.equal(await f.controller.navigate(route('pchq-alerts')), false); assert.deepEqual(f.native, [{ url: route('pchq-alerts'), replace: false }]); assert.equal(f.requests.length, 0); assert.equal(f.document.querySelector('#wpbody-content'), original); assert.equal(busy(f.document), false);
  } finally { f.close(); }
});
test('same URL refresh replaces history entry without growing the backstack', async () => {
  const f = fixture(); try {
    const length = f.window.history.length, pushes = f.histories.filter(item => item.method === 'pushState').length;
    for (const label of ['First same-URL refresh', 'Second same-URL refresh']) { const work = f.controller.navigate(route('pchq')); f.requests.at(-1).resolve(response('pchq', label)); assert.equal(await work, true); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes(label)); }
    assert.equal(f.window.history.length, length); assert.equal(f.histories.filter(item => item.method === 'pushState').length, pushes); assert.equal(f.window.location.href, route('pchq'));
  } finally { f.close(); }
});
test('native screenMeta old click handler is removed and new controls initialized exactly once', async () => {
  const f = fixture(); try {
    const order = [], handler = () => {}; f.window.screenMeta = { toggleEvent: handler, toggles: { off: (event, callback) => { assert.equal(event, 'click'); assert.equal(callback, handler); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); order.push('off'); } }, init: () => { assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('New help controls')); order.push('init'); } };
    f.document.addEventListener('pchq:before-swap', () => order.push('before')); f.document.addEventListener('pchq:after-swap', () => order.push('after'));
    const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'New help controls')); assert.equal(await work, true); assert.deepEqual(order, ['off', 'before', 'after', 'init']);
  } finally { f.close(); }
});
test('quick second navigation aborts first and stale completion cannot replace newer content', async () => {
  const f = fixture(); try { const first = f.controller.navigate(route('pchq-accounts')); const second = f.controller.navigate(route('pchq-alerts')); assert.equal(f.requests.length, 2); assert.equal(f.requests[0].options.signal.aborted, true); f.requests[1].resolve(response('pchq-alerts', 'Latest alerts')); await second; f.requests[0].resolve(response('pchq-accounts', 'Stale accounts')); await first; assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Latest alerts')); assert.ok(!f.document.querySelector('#wpbody-content').textContent.includes('Stale accounts')); } finally { f.close(); }
});
test('dirty form rejected by user leaves content and performs no fetch', async () => {
  const f = fixture(); fixture.confirmDecision = false; try { const input = f.document.querySelector('input[name=title]'); input.value = 'Unsaved synthetic value'; input.dispatchEvent(new f.window.Event('input', { bubbles: true })); assert.equal(f.controller.dirty(), true); assert.equal(await f.controller.navigate(route('pchq-alerts')), false); assert.equal(f.requests.length, 0); assert.equal(input.value, 'Unsaved synthetic value'); } finally { fixture.confirmDecision = true; f.close(); }
});
test('input created while fetch is pending is guarded before replacement', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); const input = f.document.querySelector('input[name=title]'); input.value = 'Typed during fetch'; input.dispatchEvent(new f.window.Event('input', { bubbles: true })); fixture.confirmDecision = false; f.requests[0].resolve(response('pchq-alerts', 'Should not replace')); assert.equal(await work, false); assert.equal(f.document.querySelector('input[name=title]').value, 'Typed during fetch'); assert.ok(!f.document.querySelector('#wpbody-content').textContent.includes('Should not replace')); } finally { fixture.confirmDecision = true; f.close(); }
});
for (const [name, reply] of [['401', response('pchq-alerts', 'Denied', { status: 401, ok: false })], ['403', response('pchq-alerts', 'Denied', { status: 403, ok: false })], ['redirected login', response('pchq-alerts', 'Login', { url: 'https://hq.example.invalid/wp-login.php', redirected: true })], ['unknown resources', response('pchq-alerts', 'Unknown', { text: async () => html('pchq-alerts', 'Unknown', { head: '<script src="https://hq.example.invalid/new.js"></script>' }) })]]) test(`native fallback for ${name} does not inject returned document`, async () => { const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(reply); await work; assert.equal(f.native.length, 1); assert.equal(f.native[0].url, route('pchq-alerts')); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); assert.equal(busy(f.document), false); } finally { f.close(); } });
test('network failure retains old content clears busy and never retries automatically', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].reject(new Error('Synthetic disconnected transport')); assert.equal(await work, false); await flush(); assert.equal(f.requests.length, 1); assert.equal(f.native.length, 0); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); assert.equal(busy(f.document), false); } finally { f.close(); }
});
test('history stores URL scroll only and pop navigation restores scroll without HTML cache', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Alerts')); await work; for (const item of f.histories) { const encoded = JSON.stringify(item.state); for (const secret of ['Original note', 'synthetic_nonce', 'synthetic-session', '<form', '<div', 'Original']) assert.equal(encoded.includes(secret), false); } const pop = f.controller.navigate(route('pchq'), { pop: true, scroll: { x: 7, y: 321 } }); f.requests[1].resolve(response('pchq', 'Returned overview')); await pop; await flush(); assert.ok(f.scrolls.some(value => value.x === 7 && value.y === 321)); } finally { f.close(); }
});
test('actual popstate handler refetches prior URL and scroll without adding history entry', async () => {
  const f = fixture(); try {
    f.window.scrollTo(11, 404); const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Alerts')); await work;
    const old = f.histories.filter(item => item.method === 'replaceState' && item.url === route('pchq')).at(-1).state;
    const pushes = f.histories.filter(item => item.method === 'pushState').length;
    f.window.history.replaceState(old, '', route('pchq')); f.window.dispatchEvent(new f.window.PopStateEvent('popstate', { state: old }));
    assert.equal(f.requests.length, 2); f.requests[1].resolve(response('pchq', 'Overview from pop')); await flush();
    assert.equal(f.window.location.href, route('pchq')); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Overview from pop'));
    assert.ok(f.scrolls.some(value => value.x === 11 && value.y === 404)); assert.equal(f.histories.filter(item => item.method === 'pushState').length, pushes);
  } finally { f.close(); }
});
test('bfcache return resets prior native-navigation approval for future unsaved edits', async () => {
  const f = fixture(); try {
    const input = f.document.querySelector('input[name=title]'); input.value = 'Unsaved before native';
    const work = f.controller.navigate(route('pchq-alerts')); await flush(); f.requests[0].resolve(response('pchq-alerts', 'Denied', { status: 401, ok: false })); await work;
    assert.equal(f.native.length, 1); f.window.dispatchEvent(new f.window.PageTransitionEvent('pagehide', { persisted: true })); f.window.dispatchEvent(new f.window.PageTransitionEvent('pageshow', { persisted: true }));
    input.value = 'New unsaved after back'; const event = new f.window.Event('beforeunload', { cancelable: true }); f.window.dispatchEvent(event); assert.equal(event.defaultPrevented, true);
  } finally { f.close(); }
});
test('server error retains current content without native fallback or automatic retry', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Synthetic outage', { status: 503, ok: false })); assert.equal(await work, false); assert.equal(f.native.length, 0); assert.equal(f.requests.length, 1); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); assert.equal(busy(f.document), false); } finally { f.close(); }
});
test('returned base tag forces native fallback before replacing current content', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.requests[0].resolve(response('pchq-alerts', 'Bad base', { text: async () => html('pchq-alerts', 'Bad base', { head: '<base href="https://external.example.invalid/">' }) })); await work; assert.equal(f.native.length, 1); assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); } finally { f.close(); }
});
test('unsafe nonce GET default-prevented and modified links never issue navigation fetch', async () => {
  const f = fixture(); try { const link = f.document.querySelector('#synthetic-link'); link.href = 'https://hq.example.invalid/wp-admin/admin-post.php?action=pchq_login&_wpnonce=synthetic'; click(f.window, link); link.href = route('pchq-alerts'); click(f.window, link, { ctrlKey: true }); const cancelled = new f.window.MouseEvent('click', { bubbles: true, cancelable: true }); cancelled.preventDefault(); link.dispatchEvent(cancelled); assert.equal(f.requests.length, 0); } finally { f.close(); }
});
test('destroy aborts pending navigation and removes controller handlers', async () => {
  const f = fixture(); try { const work = f.controller.navigate(route('pchq-alerts')); f.controller.destroy(); assert.equal(f.requests[0].options.signal.aborted, true); f.requests[0].resolve(response('pchq-alerts', 'Destroyed result')); await work; assert.ok(f.document.querySelector('#wpbody-content').textContent.includes('Initial')); } finally { f.close(); }
});

(async () => {
  const report = { complete: false, modelOnly: true, realBrowser: false, realHttp: false, realWordPress: false, jsdom: require(path.join(process.env.HQ_NAV_MODEL_DEPS || 'D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps', 'node_modules/jsdom/package.json')).version, cases: [] };
  for (const item of cases) { let timer; try { await Promise.race([Promise.resolve().then(item.run), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Bounded test timeout')), 2500); })]); report.cases.push({ name: item.name, pass: true }); } catch (error) { report.cases.push({ name: item.name, pass: false, error: error.message }); } finally { clearTimeout(timer); for (const item of Array.from(activeFixtures)) item.close(); } }
  report.passed = report.cases.filter(item => item.pass).length; report.total = report.cases.length; report.complete = report.passed === report.total;
  if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(report, null, 2) + '\n');
  for (const item of report.cases.filter(item => !item.pass)) console.error('FAIL', item.name, item.error);
  console.log(JSON.stringify({ complete: report.complete, passed: report.passed, total: report.total, modelOnly: true })); process.exitCode = report.complete ? 0 : 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
