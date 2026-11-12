/* M6“按广告拆分”：来源页的三种广告分组。复用现有的 DOM / 接口测试边界，执行没有改动的正式脚本 assets/data-sources.js。 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
const check = (name, ok) => { assert.ok(ok, name); ++passed; };
const flush = () => new Promise(resolve => setImmediate(resolve));
let boundary = fs.readFileSync(path.join(__dirname, 'data-exports.cjs'), 'utf8').split('(async()=>{')[0];
boundary = boundary.replace("(report==='products'?'Products':'Pages')", "(report==='products'?'Products':report==='sources'?'Sources':'Pages')").replace("const prefix=report==='products'?'prod':'pages'", "const prefix=report==='products'?'prod':report==='sources'?'sources':'pages'");
const scope = { require, __dirname, console, URL, URLSearchParams, setImmediate };
vm.runInNewContext(boundary + ';globalThis.boundary={harness,data,raw};', scope);
const { harness, raw } = scope.boundary;
const dates = { from:'2026-10-30', to:'2026-10-30', compare_from:'2026-10-29', compare_to:'2026-10-29' };
const metrics = { uv:3, customers:1, viewed_uv:2, carted_uv:1, paid_uv:1, orders:1, conversion:33.33, sales:30, aov:30, uv_value:10 };
const base = { range:{dates}, sort:'uv', order:'desc', q:'', page:1, per_page:20, split_medium:0, total:2 };
const utm = () => ({ ...base, rows:[{ utm_source:{state:'value',value:'facebook'}, ...metrics }] });
const value = v => ({ state:'value', value:v });
const missing = { state:'missing', value:null };
const KEYS = { campaign:['utm_campaign'], adset:['utm_campaign','utm_term'], ad:['utm_campaign','utm_term','utm_content'] };
const ads = (group, first = 'Spring <sale>') => ({ ...base, group_by:group, ad_keys:KEYS[group], ad_capture:true, rows:[
  Object.fromEntries(KEYS[group].map((key, i) => [key, value(i === 0 ? first : key + '-A')]).concat(Object.entries(metrics))),
  Object.fromEntries(KEYS[group].map(key => [key, missing]).concat(Object.entries(metrics))),
] });
(async()=>{
  const h = harness('sources'); h.last().resolve(utm()); await flush();
  const group = h.root.querySelector('.pc-sources-group');
  check('the page still opens on UTM source', group.value === 'utm' && !Object.hasOwn(h.calls[0].params, 'group_by') && !h.el('export').disabled);
  check('three ad groupings are offered after the two existing ones', group.querySelectorAll('option').map(option => option.getAttribute('value')).join() === 'utm,channel,campaign,adset,ad');

  for (const name of ['campaign','adset','ad']) {
    group.value = name; group.fire('change'); const request = h.last();
    check(name + ': the request names the grouping and never splits by medium', request.params.group_by === name && request.params.split_medium === '0' && request.params.page === '1');
    check(name + ': export waits for the answer and the medium split is locked', h.el('export').disabled && h.el('split').disabled && !h.el('split').checked);
    request.resolve(ads(name)); await flush();
    const html = h.el('wrap').innerHTML;
    const identity = (html.match(/<thead><tr>(.*?)<th aria-sort/s) || ['', ''])[1];
    check(name + ': one column per ad parameter, in order', (identity.match(/<th class="pc-sources-id">/g) || []).length === KEYS[name].length && KEYS[name].every(key => identity.includes(key)));
    check(name + ': values are escaped, absent parameters shown by their state', html.includes('Spring &lt;sale&gt;') && !html.includes('Spring <sale>') && html.includes('missing'));
    check(name + ': the accepted answer enables export', !h.el('export').disabled);
    h.el('export').fire('click');
    check(name + ': CSV asks for the same grouping', h.last().params.group_by === name && h.last().options.parse === false);
    h.last().resolve(raw()); await flush();
  }
  check('three CSV downloads, one per grouping', h.downloads.length === 3);

  // 回的不是这一种分组：当成出错，不显示、不允许导出。
  group.value = 'adset'; group.fire('change'); h.last().resolve(ads('campaign', 'WRONG')); await flush();
  check('an answer for another grouping is refused', !h.el('wrap').innerHTML.includes('WRONG') && h.el('export').disabled && !!h.el('wrap').querySelector('.pc-sources-retry'));
  // 身份列缺了一项：同样拒绝。
  h.el('wrap').querySelector('.pc-sources-retry').fire('click');
  const broken = ads('adset', 'BROKEN'); delete broken.rows[0].utm_term;
  h.last().resolve(broken); await flush();
  check('a row without one of its ad parameters is refused', !h.el('wrap').innerHTML.includes('BROKEN') && h.el('export').disabled);

  // 换回 UTM：和以前一样不带 group_by，可以再拆媒介。
  group.value = 'utm'; group.fire('change'); const back = h.last();
  check('back on UTM the request carries no grouping and the split is free again', !Object.hasOwn(back.params, 'group_by') && !h.el('split').disabled);
  back.resolve(utm()); await flush();
  check('UTM rows render as before', h.el('wrap').innerHTML.includes('facebook') && !h.el('export').disabled);
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
