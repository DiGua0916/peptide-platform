/* Actual report/shell/export scripts; reuse existing DOM/API/clock boundaries, never rewrite production. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
let passed=0;const check=(label,value)=>{assert.ok(value,label);passed++;};const flush=()=>new Promise(resolve=>setImmediate(resolve));
const METRICS=['views','view_users','carts','cart_users','cart_units','cart_rate','paid_orders','paid_users','paid_units','conversion','sales','net_sales','exposed_users','impressions','exposure_viewed_users','exposure_view_user_rate','exposure_views','exposure_view_rate','exposure_cart_users','exposure_cart_user_rate','exposure_carts','exposure_cart_rate','exposure_paid_users','exposure_paid_user_rate','exposure_paid_units','exposure_paid_rate','cart_user_rate','paid_user_rate'];
const DEFAULTS=['views','carts','cart_rate','cart_units','paid_units','conversion','sales','exposed_users','impressions','exposure_view_user_rate'];
function boundary(file,extra){const context={require,__dirname,console,URL,URLSearchParams,setImmediate};vm.runInNewContext(fs.readFileSync(path.join(__dirname,file),'utf8').split('(async()=>')[0]+extra,context);return context;}
const tools=boundary('data-report-tools.cjs','\nthis.make=harness;this.base=report;');
const transport=boundary('data-exports.cjs','\nthis.make=harness;this.base=data;this.raw=raw;');
const pages=boundary('data-page-conversion.cjs','\nthis.make=make;this.reply=reply;');
function product(base){base.metrics=METRICS;base.defaults=DEFAULTS;for(const row of base.rows){row.current=Object.fromEntries(METRICS.map(k=>[k,k==='exposure_view_user_rate'?37.5:4]));row.previous=Object.fromEntries(METRICS.map(k=>[k,k==='exposure_view_user_rate'?25:2]));row.change=Object.fromEntries(METRICS.map(k=>[k,12.5]));}return base;}
function canonical(params){const f={entry_domain_state:params.entry_domain_state};if(params.entry_domain_state==='value')f.entry_domain=params.entry_domain;f.country_state=params.country_state;if(params.country_state==='value')f.country=params.country;return f;}
(async()=>{
 const h=tools.make();h.calls[0].resolve(product(tools.base()));await flush();
 check('default ten columns retain old seven order then exposure fields',h.headers().join()===DEFAULTS.join());
 check('28 authoritative metrics appear once in ordered chooser',h.boxes().length===28&&new Set(h.boxes().map(b=>b.getAttribute('data-key'))).size===28);
 check('exposure rates display percent with point changes',h.el('wrap').innerHTML.includes('37.5%')&&h.el('wrap').innerHTML.includes('+12.5 Percentage points'));
 h.move('impressions',-1).fire('click');check('native move control persists order and restores checkbox focus',h.headers().indexOf('impressions')===7&&h.document.activeElement===h.box('impressions'));
 while(h.headers().length>5){const key=h.headers().at(-1),box=h.box(key);box.checked=false;box.fire('change');}
 const five=h.storage.value,blocked=h.box(h.headers()[0]);blocked.checked=false;blocked.fire('change');check('minimum five cannot be bypassed by checkbox event',h.headers().length===5&&h.storage.value===five&&blocked.checked);
 check('minimum boundary disables checked boxes but keeps move controls',h.headers().every(k=>h.box(k).disabled)&&!!h.move(h.headers()[1],-1));
 const saved=tools.make('["sales","views"]');saved.calls[0].resolve(product(tools.base()));await flush();check('existing short ordered saved choice is retained without reset',saved.headers().join()==='sales,views');
 const empty=tools.make('[]');empty.calls[0].resolve(product(tools.base()));await flush();check('existing deliberately empty choice remains empty',empty.headers().length===0);
 const p=transport.make('products');p.last().resolve(product(transport.base('products')));await flush();
 for(let n=0;n<28;n++){const box=p.el('columns').querySelectorAll('input[data-key]').find(b=>!b.checked);if(!box)break;box.checked=true;box.fire('change');}
 check('all28 can be selected without duplication',p.el('columns').querySelectorAll('input[data-key]').filter(b=>b.checked).length===28);
 p.el('export').fire('click');const csv=p.last();check('CSV snapshots full selected ordered28 columns',csv.params.columns.split(',').length===28&&csv.params.columns.startsWith(DEFAULTS.join(','))&&csv.params.format==='csv');
 const domain=p.root.querySelector('.pc-report-domain-state'),value=p.root.querySelector('.pc-report-domain');domain.value='value';domain.fire('change');value.value='A.TEST.';value.fire('input');
 check('unapplied Product filter immediately hides previous rows and export',p.el('export').disabled&&!p.el('wrap').innerHTML.includes('CURRENT')&&p.el('wrap').innerHTML.includes('filterApply'));
 csv.resolve(transport.raw());await flush();check('old CSV cannot download after dirty Product filter',p.downloads.length===0);
 p.root.querySelector('.pc-report-filter-apply').fire('click');check('Product apply sends canonical entry domain',p.last().params.entry_domain==='a.test'&&p.last().params.entry_domain_state==='value');
 const accepted=product(transport.base('products','FILTERED'));accepted.filter=canonical(p.last().params);accepted.filter_coverage={current:{episodes:{base_count:9,matched_count:2,country:{value:2,unknown:1,unavailable:0,unobserved:6}}}};p.last().resolve(accepted);await flush();check('only accepted Product filter requalifies export',!p.el('export').disabled&&p.el('wrap').innerHTML.includes('FILTERED'));
 check('new exposure coverage uses localized episode unit key',p.root.querySelector('.pc-report-coverage').textContent.includes('filterEpisodes'));
 p.el('export').fire('click');check('Product CSV keeps canonical accepted filter and28 order',p.last().params.entry_domain==='a.test'&&p.last().params.columns.split(',').length===28);const stale=p.last();p.root.querySelector('.pc-report-country-state').value='unknown';p.root.querySelector('.pc-report-country-state').fire('change');stale.resolve(transport.raw());await flush();check('later country draft suppresses accepted-domain CSV',p.downloads.length===0&&p.el('export').disabled);
 const pc=pages.make();pc.last().resolve(pages.reply(pc));await flush();
 const pcDomain=pc.root.querySelector('.pc-report-domain-state'),pcValue=pc.root.querySelector('.pc-report-domain'),pcCountry=pc.root.querySelector('.pc-report-country-state'),pcCode=pc.root.querySelector('.pc-report-country');
 pcDomain.value='value';pcDomain.fire('change');pcValue.value='A.TEST.';pcValue.fire('input');pcCountry.value='value';pcCountry.fire('change');pcCode.value='us';pcCode.fire('input');
 check('PageConversion dirty filter clears accepted rows and disables chooser',!pc.el('results').innerHTML.includes('data-pc-drill')&&pc.el('columns').disabled);
 const before=pc.calls.length;pc.el('size').value='50';pc.el('size').fire('change');check('unapplied PageConversion draft cannot query old selection as new',pc.calls.length===before&&pc.el('results').innerHTML.includes('filterApply'));
 pc.root.querySelector('.pc-report-filter-apply').fire('click');check('PageConversion applies typed canonical AND intent',pc.last().params.entry_domain==='a.test'&&pc.last().params.country==='US'&&pc.last().params.country_state==='value');
 const pending=pc.last();pcValue.value='other.test';pcValue.fire('input');const old=pages.reply(pc,'STALE');old.filter=canonical(pending.params);pending.resolve(old);await flush();check('late JSON cannot revive rows during newer dirty draft',!pc.el('results').innerHTML.includes('STALE')&&pc.el('columns').disabled);
 pc.root.querySelector('.pc-report-filter-apply').fire('click');const current=pages.reply(pc,'CURRENT-FILTER');current.filter=canonical(pc.last().params);current.filter_coverage={current:{episodes:{base_count:9,matched_count:2}},previous:{episodes:{base_count:4,matched_count:1}}};pc.last().resolve(current);await flush();
 check('canonical PageConversion response alone paints and exposes coverage',pc.el('results').innerHTML.includes('CURRENT-FILTER')&&!pc.el('columns').disabled&&pc.root.querySelector('.pc-report-coverage').textContent.includes('2/9'));
 pc.el('size').value='100';pc.el('size').fire('change');const wrong=pages.reply(pc,'WRONG-CONTEXT');wrong.filter={entry_domain_state:'all',country_state:'all'};pc.last().resolve(wrong);await flush();check('mismatched canonical filter response is rejected locally',!pc.el('results').innerHTML.includes('WRONG-CONTEXT')&&!!pc.el('results').querySelector('[data-pc-retry]'));
 pc.el('results').querySelector('[data-pc-retry]').fire('click');const recovered=pages.reply(pc,'RECOVERED');recovered.filter=canonical(pc.last().params);pc.last().resolve(recovered);await flush();check('same accepted typed filter can recover after error',pc.el('results').innerHTML.includes('RECOVERED')&&pc.last().params.entry_domain==='other.test');
 console.log(JSON.stringify({suite:'product-exposure-controls',passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
