/* Reuse the existing DOM/API test boundary; execute unmodified production files. */
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
const { harness, data, raw } = scope.boundary;
const dates = { from:'2026-10-30', to:'2026-10-30', compare_from:'2026-10-29', compare_to:'2026-10-29' };
const source = (split = 0, name = 'A') => ({ range:{dates}, sort:'uv', order:'desc', q:'', page:1, per_page:20, split_medium:split, total:41, rows:[{utm_source:{state:'value',value:name}, ...(split?{utm_medium:{state:'empty',value:''}}:{}),uv:1,customers:1,viewed_uv:1,carted_uv:0,paid_uv:1,orders:2,conversion:200,sales:30,aov:15,uv_value:30}] });
(async()=>{
  for (const report of ['products','pages']) {
    const h = harness(report);
    h.last().resolve(data(report)); await flush();
    const el = suffix => h.root.querySelector('.pc-report-'+suffix);
    check(report+' filter controls have named native selectors',!!el('domain-state')&&!!el('country-state')&&!!el('filter-apply'));
    h.el('export').fire('click'); const oldCsv=h.last();
    el('domain-state').value='value';el('domain-state').fire('change');el('domain').value='ENTRY.TEST.';el('domain').fire('input');el('country-state').value='unavailable';el('country-state').fire('change');
    check(report+' editing selection immediately disables export',h.el('export').disabled);
    oldCsv.resolve(raw()); await flush();check(report+' filter edit suppresses stale CSV',h.downloads.length===0);
    el('filter-apply').fire('click');const pending=h.last();
    check(report+' canonical selected AND wire resets page',pending.params.entry_domain==='entry.test'&&pending.params.country_state==='unavailable'&&pending.params.page==='1');
    const filter={entry_domain_state:'value',entry_domain:'entry.test',country_state:'unavailable'};
    pending.resolve({...data(report),filter});await flush();check(report+' accepted echo enables export',!h.el('export').disabled);
    h.el('export').fire('click');check(report+' CSV snapshots accepted filter',h.last().params.entry_domain==='entry.test'&&h.last().params.country_state==='unavailable');h.last().resolve(raw());await flush();
    el('country-state').value='unknown';el('country-state').fire('change');el('filter-apply').fire('click');const stale=h.last();
    el('country-state').value='unobserved';el('country-state').fire('change');el('filter-apply').fire('click');const current=h.last();
    current.resolve({...data(report,'CURRENT'),filter:{entry_domain_state:'value',entry_domain:'entry.test',country_state:'unobserved'}});await flush();stale.resolve({...data(report,'STALE'),filter});await flush();
    check(report+' stale filter success cannot overwrite accepted rows',h.el('wrap').innerHTML.includes('CURRENT')&&!h.el('wrap').innerHTML.includes('STALE'));
    el('filter-apply').fire('click');h.last().resolve({...data(report,'WRONG'),filter});await flush();check(report+' wrong echo is local error without export',h.el('export').disabled&&h.el('wrap').innerHTML.includes('Failed'));

    const draft=harness(report);draft.last().resolve(data(report));await flush();
    const de=suffix=>draft.root.querySelector('.pc-report-'+suffix);
    de('country-state').value='unknown';de('country-state').fire('change');
    if(report==='pages'){draft.el('perpage').value='50';draft.el('perpage').fire('change');}
    else {draft.el('search').value='reload';draft.el('search').fire('keydown',{key:'Enter'});}
    check(report+' unrelated reload retains applied All wire',!Object.hasOwn(draft.last().params,'country_state'));
    draft.last().resolve(data(report,'ALL-RELOAD'));await flush();
    check(report+' unrelated successful reload cannot clear dirty filter export guard',draft.el('export').disabled&&de('country-state').value==='unknown'&&!draft.el('wrap').innerHTML.includes('ALL-RELOAD'));
    const calls=draft.calls.length;draft.el('export').fire('click');check(report+' dirty selection cannot start CSV after reload',draft.calls.length===calls);
    de('country-state').value='all';de('country-state').fire('change');
    if(report==='pages'){draft.el('perpage').value='100';draft.el('perpage').fire('change');}
    else {draft.el('search').value='restored';draft.el('search').fire('keydown',{key:'Enter'});}
    draft.last().resolve(data(report,'RESTORED'));await flush();check(report+' restoring accepted conditions permits accepted reload export',!draft.el('export').disabled&&draft.el('wrap').innerHTML.includes('RESTORED'));
    de('domain-state').value='value';de('domain-state').fire('change');de('domain').value='ENTRY.TEST.';de('domain').fire('input');de('country-state').value='value';de('country-state').fire('change');de('country').value='us';de('country').fire('input');de('filter-apply').fire('click');
    draft.last().resolve({...data(report),filter:{entry_domain_state:'value',entry_domain:'entry.test',country_state:'value',country:'US'}});await flush();check(report+' accepted Apply writes canonical values into controls and enables export',de('domain').value==='entry.test'&&de('country').value==='US'&&!draft.el('export').disabled);
  }
  const landingScope={require,__dirname,console,URL,URLSearchParams,setImmediate};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'data-landing-attribution.cjs'),'utf8').split('(async()=>{')[0]+';globalThis.boundary={harness,data};',landingScope);
  const landing=landingScope.boundary.harness('landing');landing.last().resolve(landingScope.boundary.data());await flush();const le=suffix=>landing.root.querySelector('.pc-report-'+suffix);
  le('country-state').value='unknown';le('country-state').fire('change');landing.el('perpage').value='50';landing.el('perpage').fire('change');landing.last().resolve(landingScope.boundary.data(0,'/ALL-RELOAD/'));await flush();
  check('Landing keeps unapplied draft notice after unrelated success',le('country-state').value==='unknown'&&!landing.el('wrap').innerHTML.includes('ALL-RELOAD')&&landing.el('wrap').innerHTML.includes('filterApply'));
  le('filter-apply').fire('click');landing.last().resolve({...landingScope.boundary.data(0,'/APPLIED/'),filter:{entry_domain_state:'all',country_state:'unknown'}});await flush();check('Landing applied selection restores truthful matching table',landing.el('wrap').innerHTML.includes('APPLIED')&&le('country-state').value==='unknown');
  const h=harness('sources');h.last().resolve(source());await flush();
  check('source actual script exposes ten metrics and accepted export',h.el('wrap').querySelectorAll('button[data-sort]').length===10&&!h.el('export').disabled&&h.el('wrap').innerHTML.includes('200%'));
  h.el('split').checked=true;h.el('split').fire('change');const splitRequest=h.last();check('split medium requests independent grouping and disables export',splitRequest.params.split_medium==='1'&&h.el('export').disabled);
  splitRequest.resolve(source(1,'<campaign>'));await flush();check('source and empty-medium labels escaped',h.el('wrap').innerHTML.includes('&lt;campaign&gt;')&&!h.el('wrap').innerHTML.includes('<campaign>')&&h.el('wrap').innerHTML.includes('empty'));
  h.el('export').fire('click');const oldCsv=h.last();h.el('search').value='new';h.el('search').fire('input');oldCsv.resolve(raw());await flush();check('source pending search suppresses old CSV',h.downloads.length===0&&h.el('export').disabled);
  h.el('apply').fire('click');const old=h.last();h.el('search').value='newer';h.el('search').fire('input');h.el('apply').fire('click');const newest=h.last();newest.resolve({...source(1,'NEWEST'),q:'newer'});await flush();old.resolve({...source(1,'STALE'),q:'new'});await flush();check('source latest generation owns rows',h.el('wrap').innerHTML.includes('NEWEST')&&!h.el('wrap').innerHTML.includes('STALE'));
  h.el('export').fire('click');check('source CSV has explicit dates and selected grouping',h.last().params.split_medium==='1'&&h.last().params.q==='newer'&&h.last().params.from===dates.from&&h.last().options.parse===false);h.last().resolve(raw());await flush();check('source valid CSV download',h.downloads.length===1);
  h.el('split').checked=false;h.el('split').fire('change');h.last().resolve({...source(1,'WRONG'),q:'newer'});await flush();check('source wrong split echo exposes retry and no stale export',!!h.el('wrap').querySelector('.pc-sources-retry')&&h.el('export').disabled);
  h.el('wrap').querySelector('.pc-sources-retry').fire('click');h.last().resolve({...source(0,'RECOVER'),q:'newer'});await flush();check('source retry recovers accepted grouping',!h.el('export').disabled&&h.el('wrap').innerHTML.includes('RECOVER'));
  h.el('export').fire('click');const beforeRange=h.last();h.range('yesterday');beforeRange.resolve(raw());await flush();check('source range gap suppresses pending export',h.downloads.length===1&&h.el('export').disabled);
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
