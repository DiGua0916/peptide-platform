/* ST-05 同屏布局：一页上下两个报表。复用现有的 DOM / 接口测试边界，执行没有改动的正式脚本（data.js、data-sources.js、data-pages.js）。 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
const check = (name, ok) => { assert.ok(ok, name); ++passed; };
const flush = () => new Promise(resolve => setImmediate(resolve));
const source = fs.readFileSync(path.join(__dirname, 'data-exports.cjs'), 'utf8').split('(async()=>{')[0];
const swap = (text, from, to) => { assert.ok(text.includes(from), 'boundary changed: ' + from); return text.replace(from, to); };
// 同屏：外壳配置里多 pages / sections / focus，两个报表的脚本和文案都加载。
let stacked = swap(source, "pcData:{page:report,", "pcData:{page:'sources',pages:['sources','pages'],sections:{sources:'Sources (by ad)',pages:'Pages <b>'},focus:'pages',");
stacked = swap(stacked, "window['pcData'+(report==='products'?'Products':'Pages')]={strings};", "window.pcDataSources={strings};window.pcDataPages={strings};");
stacked = swap(stacked, "for(const name of ['data','data-export','data-'+report])", "for(const name of ['data','data-export','data-sources','data-pages'])");
const load = text => { const scope = { require, __dirname, console, URL, URLSearchParams, setImmediate }; vm.runInNewContext(text + ';globalThis.boundary={harness,data,raw};', scope); return scope.boundary; };
const dates = { from:'2026-10-30', to:'2026-10-30', compare_from:'2026-10-29', compare_to:'2026-10-29' };
const sources = name => ({ range:{dates}, sort:'uv', order:'desc', q:'', page:1, per_page:20, split_medium:0, total:1, rows:[{utm_source:{state:'value',value:name},uv:1,customers:1,viewed_uv:1,carted_uv:0,paid_uv:1,orders:2,conversion:200,sales:30,aov:15,uv_value:30}] });
(async()=>{
  const b = load(stacked);
  const h = b.harness('pages');
  const sections = h.root.querySelectorAll('.pc-data-section');
  check('two sections, in the configured order', sections.length === 2 && sections[0].getAttribute('data-section') === 'sources' && sections[1].getAttribute('data-section') === 'pages');
  const titles = h.root.querySelectorAll('.pc-data-section-title'), shell = sections[0].parentNode.innerHTML; // 假 DOM 不保存子元素的文字，标题看外层写进去的那段 HTML。
  check('each section has its own heading, escaped', titles.length === 2 && shell.includes('>Sources (by ad)</h2>') && shell.includes('>Pages &lt;b&gt;</h2>') && !shell.includes('Pages <b>'));
  const bodies = h.root.querySelectorAll('.pc-data-section-body');
  check('each report draws inside its own section', !!bodies[0].querySelector('.pc-sources') && !bodies[1].querySelector('.pc-sources') && !!bodies[1].querySelector('.pc-pages-export') && !bodies[0].querySelector('.pc-pages-export'));
  const first = h.calls.map(call => call.path);
  check('both reports ask for their own data once', first.length === 2 && first.includes('/peptide/v1/data/sources') && first.includes('/peptide/v1/data/pages'));
  // 两个报表各用自己的写法带时间段（来源带起止日期，页面访问带预设名），指的是同一段时间。
  const period = call => call.params.range || call.params.from + '..' + call.params.to;
  check('both requests are for the same period', h.calls.map(period).sort().join() === dates.from + '..' + dates.to + ',today');
  check('no export button is moved next to the page title', h.root.querySelectorAll('.pc-data-export-proxy').length === 0);

  h.calls.find(call => call.path.endsWith('/sources')).resolve(sources('FROM-SOURCES')); await flush();
  check('the sources answer fills the first section only', bodies[0].querySelector('.pc-sources-wrap').innerHTML.includes('FROM-SOURCES') && !String(bodies[1].innerHTML).includes('FROM-SOURCES'));
  h.calls.find(call => call.path.endsWith('/pages')).resolve(b.data('pages', 'FROM-PAGES')); await flush();
  check('the pages answer fills the second section only', bodies[1].querySelector('.pc-pages-wrap').innerHTML.includes('FROM-PAGES') && !bodies[0].querySelector('.pc-sources-wrap').innerHTML.includes('FROM-PAGES'));
  check('each report keeps its own export button, both usable', !bodies[0].querySelector('.pc-sources-export').disabled && !bodies[1].querySelector('.pc-pages-export').disabled);

  // 换时间段：两个报表都重新取数。
  const before = h.calls.length;
  h.range('yesterday');
  const range = h.calls.at(-1); check('changing the period asks the range service once', range.path === '/peptide/v1/data/range' && h.calls.length === before + 1);
  range.resolve({ preset:'yesterday', dates:{ from:'2026-10-29', to:'2026-10-29', compare_from:'2026-10-28', compare_to:'2026-10-28' } }); await flush();
  // 来源报表在“时间段换好了”的通知里会先按旧时间段补发一次请求（单独一页时也是这样，回来的结果按代号作废），所以看每个报表最后一次请求。
  const latest = suffix => h.calls.slice(before + 1).filter(call => call.path.endsWith(suffix)).at(-1);
  check('then both reports reload for the new period', !!latest('/sources') && !!latest('/pages') && period(latest('/sources')) === '2026-10-29..2026-10-29' && period(latest('/pages')) === 'yesterday');
  latest('/sources').resolve({ ...sources('NEW-PERIOD'), range:{ dates:{ from:'2026-10-29', to:'2026-10-29', compare_from:'2026-10-28', compare_to:'2026-10-28' } } }); await flush();
  check('the new period draws into a fresh first section', h.root.querySelectorAll('.pc-data-section-body')[0].querySelector('.pc-sources-wrap').innerHTML.includes('NEW-PERIOD'));

  // 一个报表的脚本没加载：另一个照常显示，缺的那段写“建设中”。
  let partial = swap(stacked, "for(const name of ['data','data-export','data-sources','data-pages'])", "for(const name of ['data','data-export','data-pages'])");
  const p = load(partial).harness('pages');
  const pb = p.root.querySelectorAll('.pc-data-section-body');
  check('a missing report leaves a placeholder and does not stop the other', pb.length === 2 && String(pb[0].innerHTML).includes('pc-data-empty') && !!pb[1].querySelector('.pc-pages-export') && p.calls.length === 1);

  // 普通页面（没有 pages 配置）不受影响：没有分段，导出按钮仍然搬到标题旁边。
  const plain = load(source).harness('pages');
  plain.last().resolve(load(source).data('pages')); await flush();
  check('ordinary pages have no sections', plain.root.querySelectorAll('.pc-data-section').length === 0 && !!plain.root.querySelector('.pc-pages-export'));
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
