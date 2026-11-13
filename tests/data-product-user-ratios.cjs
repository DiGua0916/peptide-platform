'use strict';
/* Reuse the frozen DOM/API/clock boundaries and load the actual shell/report/export scripts. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../wp-content/plugins/peptide-core');
const testDir=path.join(plugin,'tests');
let passed=0;
const check=(label,value)=>{assert.ok(value,label);passed++;};
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const OLD_METRICS=['views','view_users','carts','cart_users','cart_units','cart_rate','paid_orders','paid_users','paid_units','conversion','sales','net_sales','exposed_users','impressions','exposure_viewed_users','exposure_view_user_rate','exposure_views','exposure_view_rate','exposure_cart_users','exposure_cart_user_rate','exposure_carts','exposure_cart_rate','exposure_paid_users','exposure_paid_user_rate','exposure_paid_units','exposure_paid_rate'];
const METRICS=OLD_METRICS.concat(['cart_user_rate','paid_user_rate']);
const DEFAULTS=['views','carts','cart_rate','cart_units','paid_units','conversion','sales','exposed_users','impressions','exposure_view_user_rate'];
const RATES=['cart_rate','conversion','exposure_view_user_rate','exposure_view_rate','exposure_cart_user_rate','exposure_cart_rate','exposure_paid_user_rate','exposure_paid_rate','cart_user_rate','paid_user_rate'];
function boundary(file,extra){const context={require,__dirname:testDir,console,URL,URLSearchParams,setImmediate};const source=fs.readFileSync(path.join(testDir,file),'utf8');assert(source.includes('(async()=>'));vm.runInNewContext(source.split('(async()=>')[0]+extra,context,{filename:file});return context;}
const tools=boundary('data-report-tools.cjs','\nthis.make=harness;this.base=report;');
const transport=boundary('data-exports.cjs','\nthis.make=harness;this.base=data;this.raw=raw;');
function product(base,name){base.metrics=METRICS;base.defaults=DEFAULTS;for(const row of base.rows){if(name)row.product.name=name;row.variations=[];row.current=Object.fromEntries(METRICS.map(k=>[k,12.5]));row.previous=Object.fromEntries(METRICS.map(k=>[k,10]));row.change=Object.fromEntries(METRICS.map(k=>[k,2.5]));}return base;}
function selected(h){return h.el('columns').querySelectorAll('input[data-key]').filter(box=>box.checked).map(box=>box.getAttribute('data-key'));}
function canonical(params){const filter={entry_domain_state:params.entry_domain_state};if(params.entry_domain_state==='value')filter.entry_domain=params.entry_domain;filter.country_state=params.country_state;if(params.country_state==='value')filter.country=params.country;return filter;}
(async()=>{
 const h=tools.make();h.calls[0].resolve(product(tools.base()));await flush();
 check('unchanged ten defaults preserve the old order',h.headers().join()===DEFAULTS.join());
 check('28 chooser entries are unique',h.boxes().length===28&&new Set(h.boxes().map(box=>box.getAttribute('data-key'))).size===28);
 check('two new chooser entries append after all previous choices',h.boxes().slice(-2).map(box=>box.getAttribute('data-key')).join()==='cart_user_rate,paid_user_rate');
 check('mixed identity and order-created-period help is visible',h.el('wrap').parentNode.parentNode.innerHTML.includes('observed visitor IDs')&&h.el('wrap').parentNode.parentNode.innerHTML.includes('order-created period')&&h.el('wrap').parentNode.parentNode.innerHTML.includes('not an owner-bound visitor funnel or a verified Shopname formula'));
 for(const key of ['cart_user_rate','paid_user_rate']){const box=h.box(key);box.checked=true;box.fire('change');}
 check('new selections append without changing default columns',h.headers().join()===DEFAULTS.concat(['cart_user_rate','paid_user_rate']).join());
 check('ordered selections are saved',h.storage.value===JSON.stringify(DEFAULTS.concat(['cart_user_rate','paid_user_rate'])));
 h.move('paid_user_rate',-1).fire('click');
 check('new rate reorder preserves keyboard focus',h.headers().slice(-2).join()==='paid_user_rate,cart_user_rate'&&h.document.activeElement===h.box('paid_user_rate'));
 const reload=tools.make(h.storage.value);reload.calls[0].resolve(product(tools.base()));await flush();
 check('reload preserves saved order containing both new rates',reload.headers().join()===h.headers().join());
 const old=tools.make('["sales","views"]');old.calls[0].resolve(product(tools.base()));await flush();
 check('old short preference is not reset or expanded',old.headers().join()==='sales,views'&&old.writes.length===0);
 const oldFull=tools.make(JSON.stringify(OLD_METRICS));oldFull.calls[0].resolve(product(tools.base()));await flush();
 check('old full 26-column preference stays byte-ordered',oldFull.headers().join()===OLD_METRICS.join()&&oldFull.storage.value===JSON.stringify(OLD_METRICS));
 const empty=tools.make('[]');empty.calls[0].resolve(product(tools.base()));await flush();
 check('explicit empty legacy preference remains empty',empty.headers().length===0);
 const five=tools.make('["views","cart_user_rate","paid_user_rate","conversion","sales"]');five.calls[0].resolve(product(tools.base()));await flush();
 const blocked=five.box('paid_user_rate');blocked.checked=false;blocked.fire('change');
 check('new ratio cannot bypass minimum five selections',blocked.checked&&five.headers().length===5&&five.writes.length===0);
 check('selected new ratio checkboxes are disabled at five',five.box('cart_user_rate').disabled&&five.box('paid_user_rate').disabled);
 const rates=tools.make(JSON.stringify(RATES.concat(['sales'])));const rateData=product(tools.base());rateData.rows[0].change.paid_user_rate=-3.25;rates.calls[0].resolve(rateData);await flush();
 const cells=[...rates.el('wrap').innerHTML.matchAll(/<td class="pc-prod-metric">([\s\S]*?)<\/td>/g)].map(match=>match[1]);
 // 对比期那一行的“VS ”后来改成只给读屏软件念（包在 screen-reader-text 里），所以按去掉标签后的文字来比。
 const text=cell=>cell.replace(/<[^>]+>/g,'');
 check('all ten rates render percent values',RATES.every((key,index)=>cells[index].includes('<div class="pc-prod-cur">12.5%</div>')&&text(cells[index]).includes('VS 10%')));
 check('all ten rate changes use percentage points',RATES.every((key,index)=>cells[index].includes((key==='paid_user_rate'?'-3.25':'+2.5')+' Percentage points')));
 check('old cart_rate and conversion change labels are corrected',cells[0].includes('+2.5 Percentage points')&&cells[1].includes('+2.5 Percentage points'));
 check('non-rate change keeps the existing formatter',!cells.at(-1).includes('Percentage points')&&cells.at(-1).includes('>2.5</div>'));
 const nulls=tools.make('["cart_user_rate","paid_user_rate"]');const nullData=product(tools.base());for(const key of ['cart_user_rate','paid_user_rate']){nullData.rows[0].current[key]=null;nullData.rows[0].change[key]=null;}nulls.calls[0].resolve(nullData);await flush();
 const nullCells=[...nulls.el('wrap').innerHTML.matchAll(/<td class="pc-prod-metric">([\s\S]*?)<\/td>/g)].map(match=>match[1]);
 check('unavailable new ratios and changes remain dashes',nullCells.every(cell=>cell.includes('<div class="pc-prod-cur">-</div>')&&cell.includes('<div class="pc-prod-chg">-</div>')));
 const uncapped=tools.make('["cart_user_rate","paid_user_rate"]');const uncappedData=product(tools.base());uncappedData.rows[0].current.cart_user_rate=150.25;uncappedData.rows[0].current.paid_user_rate=200;uncapped.calls[0].resolve(uncappedData);await flush();
 check('new ratio presentation remains uncapped',uncapped.el('wrap').innerHTML.includes('150.25%')&&uncapped.el('wrap').innerHTML.includes('200%'));
 const p=transport.make('products');p.last().resolve(product(transport.base('products')));await flush();
 for(let n=0;n<28;n++){const box=p.el('columns').querySelectorAll('input[data-key]').find(box=>!box.checked);if(!box)break;box.checked=true;box.fire('change');}
 check('all 28 columns can be selected exactly once',selected(p).length===28&&new Set(selected(p)).size===28);
 p.el('export').fire('click');let csv=p.last();const firstOrder=selected(p);
 check('CSV includes both new ratio keys in selected order',csv.params.columns===firstOrder.join(',')&&csv.params.columns.endsWith('cart_user_rate,paid_user_rate')&&csv.params.format==='csv');
 p.el('columns').querySelectorAll('button[data-move-key]').find(button=>button.getAttribute('data-move-key')==='paid_user_rate'&&button.getAttribute('data-move-dir')==='-1').fire('click');
 csv.resolve(transport.raw());await flush();
 check('ratio reorder suppresses an in-flight old-column CSV',p.downloads.length===0);
 p.el('export').fire('click');csv=p.last();
 check('next CSV snapshots the reordered ratio keys',csv.params.columns===selected(p).join(',')&&csv.params.columns.endsWith('paid_user_rate,cart_user_rate'));
 const domain=p.root.querySelector('.pc-report-domain-state'),domainValue=p.root.querySelector('.pc-report-domain');domain.value='value';domain.fire('change');domainValue.value='A.TEST.';domainValue.fire('input');
 check('dirty filter immediately disables CSV and hides accepted rows',p.el('export').disabled&&!p.el('wrap').innerHTML.includes('CURRENT'));
 csv.resolve(transport.raw());await flush();check('ratio CSV cannot download under an unapplied filter draft',p.downloads.length===0);
 p.root.querySelector('.pc-report-filter-apply').fire('click');
 check('ratio report keeps existing canonical filter request',p.last().params.entry_domain==='a.test'&&p.last().params.entry_domain_state==='value');
 const accepted=product(transport.base('products'),'FILTERED-RATIOS');accepted.filter=canonical(p.last().params);p.last().resolve(accepted);await flush();
 check('accepted canonical filter alone reenables ratio export',!p.el('export').disabled&&p.el('wrap').innerHTML.includes('FILTERED-RATIOS'));
 p.el('export').fire('click');csv=p.last();
 check('filtered CSV retains all28 ordered keys and accepted filter',csv.params.columns===selected(p).join(',')&&csv.params.columns.split(',').length===28&&csv.params.entry_domain==='a.test');
 p.el('search').value='new search';p.el('search').fire('input');csv.resolve(transport.raw());await flush();
 check('new search draft suppresses previous ratio CSV',p.downloads.length===0&&p.el('export').disabled);
 p.tick(400);const searched=product(transport.base('products'),'LATEST-RATIOS');searched.filter=canonical(p.last().params);p.last().resolve(searched);await flush();
 p.el('wrap').querySelector('button[data-sort="cart_user_rate"]').fire('click');const oldResponse=p.last();
 check('new ratio header requests its own sortable key',oldResponse.params.sort==='cart_user_rate');
 p.el('search').value='later search';p.el('search').fire('keydown',{key:'Enter'});const latest=product(transport.base('products'),'CURRENT-LATEST-RATIOS');latest.filter=canonical(p.last().params);p.last().resolve(latest);await flush();
 const stale=product(transport.base('products'),'STALE-RATIOS');stale.filter=canonical(oldResponse.params);oldResponse.resolve(stale);await flush();
 check('late ratio-sort response cannot revive stale rows',p.el('wrap').innerHTML.includes('CURRENT-LATEST-RATIOS')&&!p.el('wrap').innerHTML.includes('STALE-RATIOS'));
 console.log(JSON.stringify({suite:'product-user-ratios-ui',passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
