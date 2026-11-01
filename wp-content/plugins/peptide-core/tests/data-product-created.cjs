'use strict';
/* Native DOM/API/clock boundaries only; load actual054 report/shell/export source. */
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'../../../..'),verify=root,testDir=path.join(verify,'wp-content/plugins/peptide-core/tests');
const actual=path.join(root,'wp-content/plugins/peptide-core/assets/data-products.js');
assert.equal(fs.readFileSync(actual,'utf8'),fs.readFileSync(path.join(testDir,'../assets/data-products.js'),'utf8'),'verification script bytes equal production');
const php=fs.readFileSync(path.join(root,'wp-content/plugins/peptide-core/includes/data/class-products.php'),'utf8');
const keys=name=>[...php.match(new RegExp('public const '+name+' = array\\((.*?)\\);','s'))[1].matchAll(/'([^']+)'/g)].map(m=>m[1]);
const METRICS=keys('METRICS'),DEFAULTS=keys('DEFAULTS');
function boundary(file,extra){const context={require,__dirname:testDir,console,URL,URLSearchParams,setImmediate};vm.runInNewContext(fs.readFileSync(path.join(testDir,file),'utf8').split('(async()=>')[0]+extra,context);return context;}
const tools=boundary('data-report-tools.cjs','\nthis.make=harness;this.base=report;'),transport=boundary('data-exports.cjs','\nthis.make=harness;this.base=data;this.raw=raw;');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function product(base){base.metrics=METRICS;base.columns=METRICS.concat(['created_at']);base.defaults=DEFAULTS;base.report_timezone='Asia/Shanghai';for(const row of base.rows){row.product.created_at='2026-05-03T23:02:03Z';row.product.created_at_label='2026-05-04 07:02:03';row.current=Object.fromEntries(METRICS.map(k=>[k,4]));row.previous=Object.fromEntries(METRICS.map(k=>[k,2]));row.change=Object.fromEntries(METRICS.map(k=>[k,2]));row.variations=[{variation:{id:2,name:'Child',created_at:'2025-01-01T00:00:00Z',created_at_label:'2025-01-01 08:00:00'},current:row.current,previous:row.previous,change:row.change}];}return base;}
const selected=h=>h.boxes().filter(b=>b.checked).map(b=>b.getAttribute('data-key'));
test('product creation metadata is ordered, optional and separate from numeric measures',async()=>{
 let passed=0;const check=(label,ok)=>{assert.ok(ok,label);passed++;};
 const h=tools.make();h.strings.created_at='商品创建时间';h.calls[0].resolve(product(tools.base()));await flush();
 check('numeric33/default10 contract unchanged',METRICS.length===33&&DEFAULTS.length===10&&h.headers().join()===DEFAULTS.join());
 check('34 unique allowed chooser columns',h.boxes().length===34&&new Set(h.boxes().map(b=>b.getAttribute('data-key'))).size===34&&h.box('created_at'));
 h.box('created_at').checked=true;h.box('created_at').fire('change');
 check('creation column selected after existing default order',selected(h).join()===DEFAULTS.concat(['created_at']).join());
 check('creation header Chinese and plain nonsortable',h.el('wrap').innerHTML.includes('商品创建时间</th>')&&!h.el('wrap').querySelector('button[data-sort="created_at"]'));
 const dates=[...h.el('wrap').innerHTML.matchAll(/<td class="pc-prod-created">([\s\S]*?)<\/td>/g)].map(m=>m[1]);
 check('parent and variation own single date cells',dates.join('|')==='2026-05-04 07:02:03|2025-01-01 08:00:00');
 check('date cells have no comparison percent or change markup',dates.every(cell=>!cell.includes('VS')&&!cell.includes('%')&&!cell.includes('pc-prod-cur')&&!cell.includes('pc-prod-chg')));
 check('store timezone and CSV UTC explained',h.el('wrap').innerHTML.includes('Asia/Shanghai')&&h.el('wrap').innerHTML.includes('CSV uses UTC'));
 h.move('created_at',-1).fire('click');
 check('metadata moves and retains keyboard focus',selected(h).at(-2)==='created_at'&&h.document.activeElement===h.box('created_at'));
 const reload=tools.make(h.storage.value);reload.calls[0].resolve(product(tools.base()));await flush();check('metadata order persists on reload',selected(reload).join()===selected(h).join());
 const five=tools.make('["created_at","views","carts","sales","conversion","paid_units"]');five.calls[0].resolve(product(tools.base()));await flush();
 check('date not disabled at numeric minimum',!five.box('created_at').disabled&&five.box('views').disabled&&five.box('sales').disabled);
 five.box('views').checked=false;five.box('views').fire('change');check('date cannot substitute for fifth numeric measure',five.box('views').checked&&selected(five).length===6);
 five.box('created_at').checked=false;five.box('created_at').fire('change');check('metadata can be unchecked at five numeric columns',!five.box('created_at').checked&&selected(five).length===5);
 five.box('created_at').checked=true;five.box('created_at').fire('change');check('metadata can be checked at five numeric columns',five.box('created_at').checked&&!five.box('created_at').disabled);
 const only=tools.make('["created_at"]');only.calls[0].resolve(product(tools.base()));await flush();only.box('created_at').checked=false;only.box('created_at').fire('change');check('metadata-only choice removable to deliberate empty',selected(only).length===0&&only.storage.value==='[]');
 for(const seed of ['[]','["obsolete"]','["sales","views"]']){const old=tools.make(seed);old.calls[0].resolve(product(tools.base()));await flush();check('saved empty obsolete and short choices preserved '+seed,selected(old).join()===(seed==='[]'?'':seed==='["obsolete"]'?DEFAULTS.join():'sales,views'));}
 const oldResponse=tools.make();const legacy=product(tools.base());delete legacy.columns;oldResponse.calls[0].resolve(legacy);await flush();check('legacy metrics-only response fallback remains valid',oldResponse.boxes().length===33&&!oldResponse.box('created_at'));
 const unsafe=tools.make('["created_at"]');const unsafeData=product(tools.base());unsafeData.rows[0].product.created_at_label='<img src=x onerror=bad>';unsafeData.rows[0].variations[0].variation.created_at_label=null;unsafeData.report_timezone='<script>bad</script>';unsafe.calls[0].resolve(unsafeData);await flush();
 check('date and timezone are escaped and missing variation dash',unsafe.el('wrap').innerHTML.includes('&lt;img')&&unsafe.el('wrap').innerHTML.includes('&lt;script&gt;')&&!unsafe.el('wrap').innerHTML.includes('<img src=x')&&unsafe.el('wrap').innerHTML.includes('pc-prod-created">-</td>'));
 const p=transport.make('products');p.last().resolve(product(transport.base('products')));await flush();
 const boxes=()=>p.el('columns').querySelectorAll('input[data-key]'),dateBox=()=>boxes().find(b=>b.getAttribute('data-key')==='created_at');dateBox().checked=true;dateBox().fire('change');p.el('export').fire('click');const oldCSV=p.last();
 check('CSV snapshots mixed ordered selection',oldCSV.params.columns===DEFAULTS.concat(['created_at']).join());
 p.el('columns').querySelectorAll('button[data-move-key]').find(b=>b.getAttribute('data-move-key')==='created_at'&&b.getAttribute('data-move-dir')==='-1').fire('click');oldCSV.resolve(transport.raw());await flush();check('metadata reorder invalidates in-flight CSV',p.downloads.length===0);
 p.el('export').fire('click');const csv=p.last();check('next CSV uses reordered metadata position',csv.params.columns.split(',').at(-2)==='created_at');
 const state=p.root.querySelector('.pc-report-country-state');state.value='unknown';state.fire('change');csv.resolve(transport.raw());await flush();check('dirty filter prevents stale date CSV',p.downloads.length===0&&p.el('export').disabled);
 console.log(JSON.stringify({suite:'product-created-ui',passed,failed:0}));
});
