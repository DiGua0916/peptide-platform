'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../wp-content/plugins/peptide-core');
const script = fs.readFileSync(path.join(root, 'assets/data-emails.js'), 'utf8');
const labelSource = fs.readFileSync(path.join(root, 'includes/emails/class-observation-labels.php'), 'utf8');
const unquote = value => value.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
const strings = { sent: 'Send attempts', success_rate: 'Success rate', name: 'Email name', successHelp: 'Accepted / attempts', noEmails: 'No emails', logLink: 'Log' };
for (const match of labelSource.matchAll(/'([^']+)'\s*=>\s*__\( '((?:\\.|[^'\\])*)', 'peptide-core' \)/g)) strings[match[1]] = unquote(match[2]);
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function equal(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
function escape(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]); }
function decode(value) { return value.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'); }
class Control {
 constructor(value) { this.value = value; this.listeners = {}; }
 addEventListener(type, callback) { this.listeners[type] = callback; }
 change(value) { this.value = value; assert.equal(typeof this.listeners.change, 'function'); this.listeners.change(); }
}
class Body {
 constructor() { this.html = ''; this.controls = {}; }
 set innerHTML(html) {
  this.html = html; this.controls = {};
  for (const kind of ['family', 'sender']) {
   const select = html.match(new RegExp('<select class="pc-mail-' + kind + '">([\\s\\S]*?)</select>'));
   if (!select) continue;
   const options = [...select[1].matchAll(/<option value="([^"]*)"( selected)?>([\s\S]*?)<\/option>/g)];
   const selected = options.find(option => option[2]) || options[0];
   this.controls['.pc-mail-' + kind] = new Control(selected ? decode(selected[1]) : '');
  }
 }
 get innerHTML() { return this.html; }
 querySelector(selector) { return this.controls[selector] || null; }
}
function setup(href) {
 let render;
 const requests = [];
 const window = { URL, location: { href: href || 'https://example.invalid/wp-admin/admin.php?page=pc-data-emails' } };
 window.history = { replaceState: (state, title, url) => { window.location.href = url; } };
 window.PcData = { register: (name, callback) => { assert.equal(name, 'emails'); render = callback; } };
 window.pcDataEmails = { strings };
 vm.runInNewContext(script, { window }, { filename: 'production/data-emails.js' });
 const ctx = {
  strings: { vs: 'VS', loading: 'Loading', loadError: 'Load failed' },
  esc: escape, number: value => value == null ? '-' : String(value), change: value => value == null ? '-' : String(value),
  api: (endpoint, params) => {
   const request = { endpoint, params: JSON.parse(JSON.stringify(params)) };
   requests.push(request);
   return { then: (resolve, reject) => { request.resolve = resolve; request.reject = reject; } };
  }
 };
 const body = new Body();
 return { window, body, requests, render: range => render(body, range || { preset: 'today' }, ctx) };
}
function row(family, overrides) {
 return Object.assign({ family, name: family + ' name', source_key: family + '.key', sender: 'Sender', coverage: 'plain_no_observations', mime: 'text/plain', attempts: 2, accepted: 1, failed: 0, uncertain: 0, pending: 1, open_eligible: 0, opened: null, open_rate: null, click_eligible: 0, clicked: null, click_rate: null }, overrides);
}
function report(rows, overrides) {
 return Object.assign({ current: { sent: 4, success_rate: 75 }, previous: { sent: 3, success_rate: 50 }, change: { sent: 33.33, success_rate: 50 }, rows: [{ name: 'Woo legacy', sent: 4, success_rate: 75 }], observations: { status: 'enabled', current: { opened: null, open_rate: null, clicked: null, click_rate: null }, previous: null, senders: [], rows: rows || [] } }, overrides);
}
function table(html) { return html.slice(html.lastIndexOf('<table class="pc-emails-table">')); }
function sourceCells(html) { return [...table(html).matchAll(/<tr><td>(.*?)<\/td><th scope="row">/g)].map(match => match[1]); }

// The actual PHP label source supplies the VM's translated-string boundary.
equal([strings.subscriptionSource, strings.loyaltySource, strings.unknownSource], ['Subscription confirmations', 'Points notices', 'Unknown source'], 'new labels are available');
check(strings.observationHelp.includes('subscription confirmations and points notices'), 'help covers the two new families');
check(strings.observationHelp.includes('Plain mail, old log entries and unsupported messages are excluded from these denominators.'), 'plain mail denominator notice retained');
const app = setup();
app.render();
equal(app.requests[0].endpoint, '/peptide/v1/data/emails', 'existing report endpoint retained');
app.requests[0].resolve(report(['woo', 'campaign', 'subscription', 'loyalty'].map(family => row(family))));
const familyOptions = app.body.innerHTML.match(/<select class="pc-mail-family">([\s\S]*?)<\/select>/)[1];
equal([...familyOptions.matchAll(/<option value="([^"]*)"/g)].map(match => match[1]), ['', 'woo', 'campaign', 'subscription', 'loyalty'], 'closed four-family filter options');
equal(sourceCells(app.body.innerHTML), ['WooCommerce', 'Email campaign', 'Subscription confirmations', 'Points notices'], 'each family uses its own source label');
check(app.body.innerHTML.includes('Woo legacy'), 'legacy Woo rows remain visible');
check(table(app.body.innerHTML).includes('Plain text: engagement unavailable'), 'plain coverage stays explicit');
check(table(app.body.innerHTML).includes('<td>0</td><td>-</td><td>-</td><td>0</td><td>-</td><td>-</td>'), 'plain mail engagement rates remain unavailable');

// Unknown or inherited-object keys must never fall back to Email campaign.
const unknown = setup();
unknown.render();
unknown.requests[0].resolve(report(['other', '__proto__', 'constructor', 'toString', '<img src=x onerror=alert(1)>', null].map(family => row(family))));
equal(sourceCells(unknown.body.innerHTML), Array(6).fill('Unknown source'), 'all unknown families use honest fallback');
check(!table(unknown.body.innerHTML).includes('<td>Email campaign</td>'), 'unknown rows cannot be labelled as campaigns');
check(!unknown.body.innerHTML.includes('<img '), 'malicious family/name text cannot create HTML');

// All new labels and row values use the existing escaping boundary.
const originalSubscriptionLabel = strings.subscriptionSource;
strings.subscriptionSource = '<Subscription & "confirmations">';
const escaped = setup();
escaped.render();
escaped.requests[0].resolve(report([row('subscription', { name: '<script>alert(1)</script>', sender: '<b>Sender</b>', mime: '<svg onload=alert(1)>', coverage: 'unknown<mark>' })]));
check(escaped.body.innerHTML.includes('&lt;Subscription &amp; &quot;confirmations&quot;&gt;'), 'new source label escaped in filter and row');
check(escaped.body.innerHTML.includes('&lt;script&gt;alert(1)&lt;/script&gt;'), 'row name escaped');
check(escaped.body.innerHTML.includes('&lt;b&gt;Sender&lt;/b&gt;'), 'sender escaped');
check(escaped.body.innerHTML.includes('&lt;svg onload=alert(1)&gt;'), 'MIME escaped');
check(!/<script|<svg|<b>/.test(escaped.body.innerHTML), 'dynamic values cannot create elements');
strings.subscriptionSource = originalSubscriptionLabel;

// Changing either new family preserves the selected sender and URL context.
const filtering = setup('https://example.invalid/wp-admin/admin.php?page=pc-data-emails&keep=1&mail_sender=sender%3Ca%3E');
filtering.render();
const initialReport = report([row('woo')]);
initialReport.observations.senders = [{ sender_key: 'sender<a>', sender: '<Visible sender>' }];
filtering.requests[0].resolve(initialReport);
equal(filtering.body.querySelector('.pc-mail-sender').value, 'sender<a>', 'initial sender selection retained');
check(filtering.body.innerHTML.includes('&lt;Visible sender&gt;'), 'sender option text escaped');
filtering.body.querySelector('.pc-mail-family').change('subscription');
equal(filtering.requests[1].params, { range: 'today', mail_family: 'subscription', mail_sender: 'sender<a>' }, 'subscription filter triggers reload with sender');
let acceptedUrl = new URL(filtering.window.location.href);
equal([acceptedUrl.searchParams.get('mail_family'), acceptedUrl.searchParams.get('mail_sender'), acceptedUrl.searchParams.get('keep')], ['subscription', 'sender<a>', '1'], 'URL updates family without losing other context');
filtering.requests[1].resolve(report([row('subscription')]));
equal(filtering.body.querySelector('.pc-mail-family').value, 'subscription', 'subscription selection retained after response');
check(filtering.body.innerHTML.includes('Selected sender is not in the available list'), 'missing sender guard stays visible');
filtering.body.querySelector('.pc-mail-family').change('loyalty');
equal(filtering.requests[2].params.mail_family, 'loyalty', 'loyalty filter triggers reload');
equal(filtering.requests[2].params.mail_sender, 'sender<a>', 'loyalty preserves missing selected sender');
filtering.render({ preset: 'yesterday' });
filtering.requests[3].resolve(report([row('loyalty', { name: 'LATEST_LOYALTY' })]));
const latest = filtering.body.innerHTML;
filtering.requests[2].resolve(report([row('subscription', { name: 'STALE_SUBSCRIPTION' })]));
equal(filtering.body.innerHTML, latest, 'late older-family success cannot replace latest report');
filtering.render({ preset: 'last7' });
filtering.render({ preset: 'last30' });
filtering.requests[5].resolve(report([row('loyalty', { name: 'LATEST_MONTH' })]));
const latestMonth = filtering.body.innerHTML;
filtering.requests[4].reject(new Error('stale failed request'));
equal(filtering.body.innerHTML, latestMonth, 'late older request failure cannot replace latest report');
filtering.body.querySelector('.pc-mail-family').change('');
equal(filtering.requests[6].params.mail_family, '', 'all supported sources clears family filter');
acceptedUrl = new URL(filtering.window.location.href);
check(!acceptedUrl.searchParams.has('mail_family'), 'cleared family removed from URL');
equal(acceptedUrl.searchParams.get('mail_sender'), 'sender<a>', 'clearing family retains sender');

// Translation helper extends the frozen dependency entries rather than replacing them.
function helperMap(file) {
 const map = new Map();
 for (const match of fs.readFileSync(file, 'utf8').matchAll(/'((?:\\.|[^'\\])*)'\s*=>\s*'((?:\\.|[^'\\])*)'/g)) map.set(unquote(match[1]), unquote(match[2]));
 return map;
}
const helper = helperMap(path.join(root, 'languages/customer-mail-observations-zh_CN.php'));
const frozenHelper = helperMap(path.resolve(__dirname, 'fixtures/data-observations/047/languages/customer-mail-observations-zh_CN.php'));
check([...frozenHelper].every(([key, value]) => helper.get(key) === value), 'all existing helper entries preserved');
equal([helper.get('Subscription confirmation'), helper.get('Points activity notice')], ['订阅确认', '积分活动通知'], 'backend new source names translated');
check(['Subscription confirmations', 'Points notices', 'Unknown source', strings.observationHelp].every(key => helper.has(key)), 'new UI labels and help translated');
equal(helper.size - frozenHelper.size, 6, 'exactly six translation entries appended');
console.log('PASS data-mail-sources: ' + checks + ' checks');
