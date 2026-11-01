'use strict';
// Actual renderers/shell/export helper with the existing memory DOM/API boundary.
const test = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const flush = () => new Promise(resolve => setImmediate(resolve));
const markupContains = (node, value) => { for (let n = node; n; n = n.parentNode) if (n.innerHTML && n.innerHTML.includes(value)) return true; return false; };
const labels = "Object.assign(strings,{channel:'自动流量渠道',utm:'UTM来源',channelNote:'项目v1；直接访问／来源隐藏存在歧义',channelCoverage:'渠道观察%v/%n %t；未知%u；未观察%o',visits:'访问',orders:'付款订单'});";
function boundary(file, sources = false) {
  let code = fs.readFileSync(path.join(__dirname, file), 'utf8').split('(async()=>{')[0];
  if (sources) code = code.replace("(report==='products'?'Products':'Pages')", "(report==='products'?'Products':report==='sources'?'Sources':'Pages')").replace("const prefix=report==='products'?'prod':'pages'", "const prefix=report==='products'?'prod':report==='sources'?'sources':'pages'");
  assert.ok(code.includes('const window={pcData:'));
  code = code.replace('const window={pcData:', labels + 'const window={pcData:');
  const box = {require, __dirname, console, URL, URLSearchParams, setImmediate};
  vm.runInNewContext(code + ';globalThis.exported={harness,data,raw};', box, {filename: file});
  return box.exported;
}
const sources = () => boundary('data-exports.cjs', true);
const dates = {from: '2026-10-30', to: '2026-10-30', compare_from: '2026-10-29', compare_to: '2026-10-29'};
const metrics = {uv: 2, customers: 1, viewed_uv: 2, carted_uv: 1, paid_uv: 1, orders: 2, conversion: 100, sales: 30, aov: 15, uv_value: 15};
function utm(split = 0, name = 'Legacy Source') { return {range: {dates}, sort: 'uv', order: 'desc', q: '', page: 1, per_page: 20, split_medium: split, total: 41, rows: [{utm_source: {state: 'value', value: name}, ...(split ? {utm_medium: {state: 'empty', value: ''}} : {}), ...metrics}]}; }
function channel(label = '直接访问／来源隐藏') { return {...utm(), group_by: 'channel', channel_policy: 'traffic-v1', coverage: {visits: {unit: 'visits', base_count: 4, value: 2, unknown: 1, unobserved: 1}, orders: {unit: 'orders', base_count: 2, value: 2, unknown: 0, unobserved: 0}}, rows: [{channel: {state: 'value', value: 'direct_or_hidden'}, channel_label: label, ...metrics}]}; }

test('Sources keeps default UTM wire and channel selection resets medium and cancels old CSV', async () => {
  const {harness, raw} = sources(), h = harness('sources');
  assert.equal(h.el('group').value, 'utm'); assert.equal(Object.hasOwn(h.last().params, 'group_by'), false);
  h.last().resolve(utm()); await flush(); assert.equal(h.el('export').disabled, false);
  h.el('split').checked = true; h.el('split').fire('change'); assert.equal(h.last().params.split_medium, '1');
  h.last().resolve(utm(1)); await flush(); h.el('export').fire('click'); const oldCsv = h.last();
  h.el('group').value = 'channel'; h.el('group').fire('change'); const requested = h.last();
  assert.equal(requested.params.group_by, 'channel'); assert.equal(requested.params.split_medium, '0'); assert.equal(h.el('split').checked, false); assert.equal(h.el('split').disabled, true); assert.equal(h.el('export').disabled, true); assert.equal(h.el('wrap').innerHTML, '');
  oldCsv.resolve(raw()); await flush(); assert.equal(h.downloads.length, 0);
  requested.resolve(channel('<渠道 & 已知>')); await flush();
  assert.equal(h.el('export').disabled, false); assert.ok(h.el('wrap').innerHTML.includes('&lt;渠道 &amp; 已知&gt;')); assert.equal(h.el('wrap').innerHTML.includes('<渠道'), false);
  assert.ok(h.el('wrap').innerHTML.includes('渠道观察2/4 访问')); assert.ok(h.el('wrap').innerHTML.includes('渠道观察2/2 付款订单')); assert.equal(h.el('wrap').innerHTML.includes('direct_or_hidden'), false);
  assert.equal(h.el('wrap').querySelectorAll('button[data-sort]').length, 10);
  h.el('export').fire('click'); assert.equal(h.last().params.group_by, 'channel'); assert.equal(h.last().params.split_medium, '0'); assert.equal(h.last().options.parse, false); assert.equal(h.last().params.from, dates.from);
  h.last().resolve(raw()); await flush(); assert.equal(h.downloads.length, 1);
});

test('Sources rejects wrong mode/policy/coverage and stale successes cannot become accepted channel rows', async () => {
  const {harness, raw} = sources(), h = harness('sources'); h.last().resolve(utm()); await flush();
  h.el('group').value = 'channel'; h.el('group').fire('change'); const staleChannel = h.last();
  h.el('group').value = 'utm'; h.el('group').fire('change'); const currentUtm = h.last();
  currentUtm.resolve(utm(0, 'LATEST UTM')); await flush(); staleChannel.resolve(channel('STALE CHANNEL')); await flush();
  assert.ok(h.el('wrap').innerHTML.includes('LATEST UTM')); assert.equal(h.el('wrap').innerHTML.includes('STALE CHANNEL'), false); assert.equal(h.el('split').disabled, false);
  h.el('export').fire('click'); assert.equal(Object.hasOwn(h.last().params, 'group_by'), false); h.last().resolve(raw()); await flush();
  for (const invalid of [utm(), {...channel(), channel_policy: 'wrong-policy'}, {...channel(), coverage: {visits: {unit: 'visits', base_count: 1, value: 2, unknown: 0, unobserved: 0}}}]) {
    h.el('group').value = 'channel'; h.el('group').fire('change'); h.last().resolve(invalid); await flush();
    assert.equal(h.el('export').disabled, true); assert.ok(h.el('wrap').querySelector('.pc-sources-retry'));
  }
  h.el('wrap').querySelector('.pc-sources-retry').fire('click'); h.last().resolve(channel()); await flush(); assert.equal(h.el('export').disabled, false); assert.ok(h.el('wrap').innerHTML.includes('直接访问／来源隐藏'));
});

test('Sources channel CSV failure and unapplied edits retain truthful accepted-state guards', async () => {
  const {harness, raw} = sources(), h = harness('sources'); h.el('group').value = 'channel'; h.el('group').fire('change'); h.last().resolve(channel()); await flush();
  for (const kind of ['network', 'json']) {
    h.el('export').fire('click'); if (kind === 'network') h.last().reject(Error('offline')); else h.last().resolve(raw({type: 'application/json'})); await flush();
    assert.equal(h.downloads.length, 0); assert.equal(h.el('export').disabled, false); assert.equal(h.el('status').textContent, 'Retry <error>');
  }
  h.el('export').fire('click'); const beforeEdit = h.last(); h.el('search').value = 'draft'; h.el('search').fire('input'); beforeEdit.resolve(raw()); await flush(); assert.equal(h.downloads.length, 0); assert.equal(h.el('export').disabled, true);
  h.el('apply').fire('click'); assert.equal(h.last().params.q, 'draft'); assert.equal(h.last().params.group_by, 'channel'); const empty = channel(); empty.q = 'draft'; empty.rows = []; empty.total = 0; h.last().resolve(empty); await flush(); assert.equal(h.el('export').disabled, false); assert.ok(h.el('wrap').innerHTML.includes('渠道观察2/4 访问'));
  h.el('export').fire('click'); const beforeRange = h.last(); h.range('yesterday'); beforeRange.resolve(raw()); await flush(); assert.equal(h.downloads.length, 0); assert.equal(h.el('export').disabled, true); assert.equal(h.el('group').disabled, true);
});

test('Conversion exposes channel group, requires policy echo and exports only accepted grouping', async () => {
  const {harness, data, raw} = boundary('data-conversion-export.cjs'), h = harness('conversion');
  assert.deepEqual(Array.from(h.el('group').querySelectorAll('option'), n => n.getAttribute('value')), ['device', 'browser', 'utm', 'domain', 'country', 'os', 'screen', 'channel']);
  assert.ok(markupContains(h.el('group'), '自动流量渠道')); h.last().resolve(data()); await flush(); h.el('export').fire('click'); const legacyCsv = h.last();
  h.el('group').value = 'channel'; h.el('group').fire('change'); assert.equal(h.last().params.group, 'channel'); assert.equal(h.el('export').disabled, true); legacyCsv.resolve(raw()); await flush(); assert.equal(h.downloads.length, 0);
  h.last().resolve(data('channel')); await flush(); assert.equal(h.el('export').disabled, true); assert.ok(h.el('result').querySelector('[data-conv-retry]'));
  h.el('result').querySelector('[data-conv-retry]').fire('click'); const accepted = data('channel'); accepted.channel_policy = 'traffic-v1'; accepted.rows[0].dimension = {state: 'value', value: 'direct_or_hidden'}; accepted.rows[0].label = '直接访问／来源隐藏'; h.last().resolve(accepted); await flush();
  assert.equal(h.el('export').disabled, false); assert.ok(h.el('result').innerHTML.includes('项目v1；直接访问／来源隐藏存在歧义')); assert.ok(h.el('result').innerHTML.includes('直接访问／来源隐藏'));
  h.el('export').fire('click'); assert.equal(h.last().params.group, 'channel'); const channelCsv = h.last(); h.el('group').value = 'utm'; h.el('group').fire('change'); channelCsv.resolve(raw()); await flush(); assert.equal(h.downloads.length, 0); assert.equal(h.el('export').disabled, true);
});
