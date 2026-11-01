/* Actual renderer, shared CSV helper and Operations source; native DOM/API memory boundaries. */
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const assert = require('node:assert/strict'), test = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../assets/data-dimension-details.js'), 'utf8');
const operationsTest = fs.readFileSync(path.join(__dirname, 'data-operations-checkout.cjs'), 'utf8');
const elementBoundary = operationsTest.slice(operationsTest.indexOf(' class Element{'), operationsTest.indexOf(' document.body=new Element'));
const metrics = ['uv','customers','viewed_uv','carted_uv','paid_uv','orders','conversion','sales','aov','uv_value'];
const dimensions = ['utm','country','browser','domain','device','channel'];
const dates = {from:'2026-10-31',to:'2026-11-01',compare_from:'2026-10-29',compare_to:'2026-10-30'};
const clone = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const decode = value => value.replace(/&(?:amp|lt|gt|quot|#39);/g, e => ({'&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&#39;':"'"}[e]));
function fixture({dimension='utm',invalid=false,stored={},storageFailure=false}={}) {
 const document = {activeElement:null};
 const Element = Function('document','decode','assert',elementBoundary+';return Element;')(document,decode,assert);
 Object.defineProperty(Element.prototype,'checked',{get(){return this._checked===undefined?this.hasAttribute('checked'):this._checked;},set(value){this._checked=!!value;}});
 const calls=[], csv=[], downloads=[], timers=new Map(), store={...stored}; let timer=0, render, rangeListeners=[];
 Element.prototype.click=function(){if(this.tagName==='a')downloads.push({href:this.href,filename:this.download});else this.fire('click');};
 document.body=new Element('body'); document.documentElement=document.body; document.createElement=tag=>new Element(tag);
 let body=new Element(); document.body.appendChild(body);
 const strings=new Proxy({error:'Report error',invalidDimension:'Invalid dimension',loading:'Loading',points:'百分点',moveUp:'Move %s up',moveDown:'Move %s down',coverage:'%p %u %m/%b values%v missing%s empty%e unknown%k unavailable%a unobserved%o',medium:'Medium',backToOperations:'Back to Operations',sourceHelp:'Saved visit source facts; paid buyers use original order metadata.'},{get:(o,k)=>o[k]||String(k)});
 const window={pcDataDimensionDetails:{dimension,invalidDimension:invalid,operationsUrl:'http://localhost/wp-admin/admin.php?page=pc-data-operations',strings},PcData:{register(key,fn){assert.equal(key,'dimension_details');render=fn;}},localStorage:{getItem(key){if(storageFailure)throw Error('storage');return store[key]??null;},setItem(key,value){if(storageFailure)throw Error('storage');store[key]=value;}},setTimeout(fn,ms){timers.set(++timer,{fn,ms});return timer;},clearTimeout(id){timers.delete(id);},URL:{createObjectURL(){return 'blob:fixture';},revokeObjectURL(){}}};
 window.wp={apiFetch(options){return new Promise((resolve,reject)=>csv.push({options,params:Object.fromEntries(new URL(options.path,'http://localhost').searchParams),resolve,reject}));},url:{addQueryArgs(route,params){const u=new URL(route,'http://localhost');for(const[k,v]of Object.entries(params))u.searchParams.set(k,v);return u.pathname+u.search;}}};
 const context={esc,number:value=>String(value),currency:{code:'USD'},api(route,params){return new Promise((resolve,reject)=>calls.push({route,params:clone(params),resolve,reject}));},onRangeState(fn){rangeListeners.push(fn);return()=>{rangeListeners=rangeListeners.filter(f=>f!==fn);};}};
 const sandbox=vm.createContext({window,document,console,Date,setTimeout:window.setTimeout,clearTimeout:window.clearTimeout});
 vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/data-export.js'),'utf8'),sandbox);
 vm.runInContext(source,sandbox); render(body,{dates:clone(dates)},context);
 return {window,document,calls,csv,downloads,store,timers,get body(){return body;},el:key=>body.querySelector('.pc-dim-'+key),latest:()=>calls.at(-1),setRange(on){for(const fn of [...rangeListeners])fn(on);},tick(ms){for(const[id,item]of [...timers])if(item.ms===ms){timers.delete(id);item.fn();}},remount(newDates=dates){const old=body;document.body.removeChild(old);body=new Element();document.body.appendChild(body);render(body,{dates:clone(newDates)},context);return old;},detach(){document.body.removeChild(body);},listeners:()=>rangeListeners.length};
}
function response(call,{state='value',value='<saved>&',total=43}={}) {
 const p=call.params, filter=p.entry_domain_state?{entry_domain_state:p.entry_domain_state,...(p.entry_domain?{entry_domain:p.entry_domain}:{}),country_state:'all'}:null;
 const params={dimension:p.dimension,sort:p.sort,order:p.order,sort_period:p.sort_period,page:p.page,per_page:p.per_page,split_medium:p.split_medium,q:p.q,columns:p.columns.split(','),filter};
 const current=Object.fromEntries(metrics.map((key,i)=>[key,i+1]));current.conversion=25;current.sales=6.98;
 const previous={...current,uv:0,conversion:0,sales:0},change=Object.fromEntries(metrics.map(key=>[key,key==='conversion'?25:key==='sales'||key==='uv'?null:0]));
 const typed={state,value:state==='value'?value:state==='empty'?'':null},medium=p.dimension==='utm'&&p.split_medium===1?{state:'missing',value:null}:null;
 const row={key:JSON.stringify([typed,medium]),dimension:typed,medium,label:state==='value'?value:state,current,previous,change};
 const coverage=Object.fromEntries(['current','previous'].map(period=>[period,Object.fromEntries(['visits','orders'].map(unit=>[unit,{unit,base_count:3,matched_count:2,value:state==='value'?2:0,missing:state==='missing'?2:0,empty:state==='empty'?2:0,unknown:state==='unknown'?2:0,unavailable:state==='unavailable'?2:0,unobserved:state==='unobserved'?2:0}]))]));
 return {version:1,dimension:p.dimension,range:{dates:Object.fromEntries(Object.keys(dates).map(key=>[key,p[key]]))},tracking:true,currency:'USD',params,total,rows:[row],coverage};
}
async function accept(h,options){const call=h.latest();call.resolve(response(call,options));await flush();return call;}
function change(h,key,value,event='change'){const node=h.el(key);node.value=value;node.fire(event);return node;}
function csvResponse(){return {ok:true,headers:{get:name=>name==='Content-Type'?'text/csv; charset=UTF-8':'attachment; filename="dimension.csv"'},blob:()=>Promise.resolve({size:12})};}

test('six real dimensions, exact four-date requests, escaped identities and numeric units',async()=>{
 for(const dimension of dimensions){const h=fixture({dimension});assert.equal(h.calls.length,1);const call=h.latest();assert.equal(call.route,'/peptide/v1/data/dimension-details');assert.equal(call.params.dimension,dimension);for(const key of Object.keys(dates))assert.equal(call.params[key],dates[key]);assert.equal(call.params.columns,metrics.join(','));assert.equal(Boolean(h.el('group')),['utm','channel'].includes(dimension));await accept(h);assert.equal(h.el('export').disabled,false);assert.equal(h.el('wrap').querySelector('tbody').children.length,1);assert.equal(h.el('wrap').querySelectorAll('th').length,12);assert.equal(h.body.querySelector('saved'),null);assert.ok(h.el('wrap').textContent.includes('<saved>&'));assert.ok(h.el('wrap').textContent.includes('+25.00 百分点'));assert.ok(h.el('wrap').textContent.includes('6.98 USD'));assert.ok(h.el('wrap').textContent.includes('VS 0'));assert.ok(h.el('wrap').textContent.includes('-'));const u=new URL(h.el('back').querySelector('a').getAttribute('href'));for(const key of Object.keys(dates))assert.equal(u.searchParams.get(key),dates[key]);assert.equal(h.el('coverage').querySelectorAll('p').length,4);}
 const invalid=fixture({dimension:null,invalid:true});assert.equal(invalid.calls.length,0);assert.ok(invalid.body.textContent.includes('Invalid dimension'));
});

test('source grouping and medium remain typed, independent and dimension-scoped',async()=>{
 const h=fixture();await accept(h);const split=h.el('split');split.checked=true;split.fire('change');assert.equal(h.latest().params.split_medium,1);await accept(h);assert.ok(h.el('wrap').querySelector('tbody').children[0].children[0].textContent.includes('Medium: missing'));
 change(h,'group','channel');assert.equal(h.latest().params.dimension,'channel');assert.equal(h.latest().params.split_medium,0);assert.equal(h.el('split').disabled,true);await accept(h,{state:'unknown'});assert.equal(h.el('wrap').querySelector('tbody').children[0].children[0].textContent,'unknown');
 change(h,'group','utm');assert.equal(h.latest().params.split_medium,1);await accept(h,{state:'empty'});assert.ok(h.el('wrap').textContent.includes('empty'));assert.equal(h.el('split').disabled,false);
});

test('draft entry filters, normalization, query races, pagination and sort periods use accepted requests',async()=>{
 const h=fixture();await accept(h);change(h,'domain-state','value');change(h,'domain',' EXAMPLE.COM. ','input');change(h,'search','  source & medium  ','input');assert.equal(h.el('export').disabled,true);assert.equal(h.el('columns').disabled,true);assert.equal(h.calls.length,1);h.el('apply').fire('click');assert.equal(h.latest().params.entry_domain,'example.com');assert.equal(h.latest().params.q,'source & medium');await accept(h);assert.equal(h.el('export').disabled,false);
 const old=h.latest();change(h,'search','next','input');h.el('apply').fire('click');const pending=h.latest();change(h,'search','newest','input');h.el('apply').fire('click');const newest=h.latest();pending.resolve(response(pending,{value:'STALE'}));await flush();assert.equal(h.el('wrap').textContent,'');newest.resolve(response(newest,{value:'CURRENT'}));await flush();assert.ok(h.el('wrap').textContent.includes('CURRENT'));assert.ok(!h.el('wrap').textContent.includes('STALE'));assert.notEqual(old,newest);
 change(h,'sort-period','change');assert.equal(h.latest().params.sort_period,'change');await accept(h);h.el('wrap').querySelector('[data-dim-sort="conversion"]').fire('click');assert.equal(h.latest().params.sort,'conversion');assert.equal(h.latest().params.sort_period,'change');await accept(h);h.el('foot').querySelector('[data-dim-page="1"]').fire('click');assert.equal(h.latest().params.page,2);await accept(h);change(h,'perpage','50');assert.equal(h.latest().params.page,1);assert.equal(h.latest().params.per_page,50);await accept(h);
 change(h,'domain-state','unobserved');h.el('apply').fire('click');assert.equal(h.latest().params.entry_domain_state,'unobserved');assert.ok(!Object.hasOwn(h.latest().params,'entry_domain'));await accept(h);
});

test('columns preserve order, min one, cancel, Escape, restore and storage failure',async()=>{
 const key='pcDataDimensionColumns-v1-country';const h=fixture({dimension:'country',stored:{[key]:JSON.stringify(['sales','uv'])}});assert.equal(h.latest().params.columns,'sales,uv');await accept(h);
 h.el('columns').fire('click');assert.equal(h.el('settings').hidden,false);assert.equal(h.el('column-list').querySelector('[data-dim-down="sales"]').textContent,'Move sales down');h.el('column-list').querySelector('[data-dim-down="sales"]').fire('click');h.el('cancel').fire('click');assert.equal(h.calls.length,1);assert.equal(h.el('export').disabled,false);
 h.el('columns').fire('click');h.el('column-list').querySelector('[data-dim-down="sales"]').fire('click');h.el('save').fire('click');assert.equal(h.latest().params.columns,'uv,sales');assert.equal(h.el('export').disabled,true);await accept(h);assert.deepEqual(JSON.parse(h.store[key]),['uv','sales']);
 h.el('columns').fire('click');const box=h.el('column-list').querySelector('[data-dim-column="sales"]');box.checked=false;box.fire('change');assert.equal(h.el('column-list').querySelector('[data-dim-column="uv"]').disabled,true);h.el('save').fire('click');assert.equal(h.latest().params.columns,'uv');await accept(h);
 h.el('columns').fire('click');h.el('restore').fire('click');h.body.fire('keydown',{key:'Escape'});assert.equal(h.el('settings').hidden,true);assert.equal(h.document.activeElement,h.el('columns'));assert.equal(h.latest().params.columns,'uv');
 h.el('columns').fire('click');h.el('restore').fire('click');h.el('save').fire('click');assert.equal(h.latest().params.columns,metrics.join(','));await accept(h);const old=h.remount();assert.equal(h.latest().params.columns,metrics.join(','));assert.equal(h.listeners(),1);assert.equal(old.parentNode,null);
 const noStorage=fixture({storageFailure:true});await accept(noStorage);noStorage.el('columns').fire('click');noStorage.el('column-list').querySelector('[data-dim-down="uv"]').fire('click');noStorage.el('save').fire('click');assert.equal(noStorage.latest().params.columns.split(',')[0],'customers');await accept(noStorage);assert.equal(noStorage.el('export').disabled,false);
 const duplicate=fixture({dimension:'country',stored:{[key]:JSON.stringify(['uv','uv'])}});assert.equal(duplicate.latest().params.columns,metrics.join(','));
});

test('strict server snapshots, 503 retry, typed null versus zero and malformed coverage',async()=>{
 const h=fixture();h.latest().reject(Error('503'));await flush();assert.equal(h.el('export').disabled,true);assert.equal(h.el('status').textContent,'Report error');h.el('retry').fire('click');await accept(h,{state:'unavailable'});assert.ok(h.el('wrap').textContent.includes('unavailable'));
 const mutations=[d=>d.dimension='device',d=>d.version=2,d=>d.currency='EUR',d=>d.params.q='other',d=>d.range.dates.compare_to='2026-11-02',d=>delete d.rows[0].current.uv,d=>d.rows[0].change.conversion='25',d=>d.rows[0].medium={state:'missing',value:null},d=>d.rows[0].dimension={state:'missing',value:''},d=>d.coverage.current.visits.matched_count=4,d=>d.coverage.previous.orders.value=3,d=>d.rows[0].key='not-json'];
 for(const mutate of mutations){change(h,'perpage',h.latest().params.per_page===20?'50':'20');const call=h.latest(),bad=response(call);mutate(bad);call.resolve(bad);await flush();assert.equal(h.el('export').disabled,true);assert.equal(h.el('status').textContent,'Report error');h.el('retry').fire('click');await accept(h);}
 const call=h.latest();assert.equal(call.params.per_page,20);assert.ok(h.el('wrap').textContent.includes('VS 0'));assert.ok(h.el('wrap').textContent.includes('+25.00 百分点'));
});

test('shared CSV receives all matching ordered columns and rejects dirty/range/stale/detached authority',async()=>{
 const h=fixture();await accept(h);h.el('export').fire('click');assert.equal(h.csv.length,1);assert.equal(h.csv[0].options.parse,false);assert.equal(h.csv[0].params.format,'csv');for(const key of Object.keys(dates))assert.equal(h.csv[0].params[key],dates[key]);assert.equal(h.csv[0].params.columns,metrics.join(','));h.csv[0].resolve(csvResponse());await flush();assert.equal(h.downloads.length,1);
 h.el('export').fire('click');const stale=h.csv.at(-1);change(h,'search','draft','input');stale.resolve(csvResponse());await flush();assert.equal(h.downloads.length,1);assert.equal(h.el('export').disabled,true);h.el('apply').fire('click');await accept(h);h.el('export').fire('click');const rangeCsv=h.csv.at(-1);h.setRange(true);rangeCsv.resolve(csvResponse());await flush();assert.equal(h.downloads.length,1);assert.equal(h.el('export').disabled,true);h.setRange(false);const before=h.calls.length;h.tick(0);assert.equal(h.calls.length,before+1);assert.equal(h.latest().params.q,'draft');await accept(h);h.el('export').fire('click');const detached=h.csv.at(-1);h.detach();detached.resolve(csvResponse());await flush();assert.equal(h.downloads.length,1);
 const replacing=fixture();const oldCall=replacing.latest();replacing.setRange(true);replacing.setRange(false);replacing.remount({...dates,from:'2026-11-01'});replacing.tick(0);assert.equal(replacing.calls.length,2);oldCall.resolve(response(oldCall,{value:'OLD PAGE'}));await flush();assert.equal(replacing.el('wrap').textContent,'');await accept(replacing);assert.equal(replacing.el('export').disabled,false);
});

test('Operations uses five trusted report links with all accepted comparison dates and legacy fallback',async()=>{
 const prefix=operationsTest.slice(0,operationsTest.indexOf('(async()=>{'));
 const labels=['国家/地区','UTM来源','浏览器/APP','域名','设备'];
 const links=['country','utm','browser','domain','device'].map((dimension,index)=>({label:labels[index],url:'http://localhost:8093/wp-admin/admin.php?page=pc-data-dimensions&dimension='+dimension}));
 function ops(config){const sandbox=vm.createContext({require,console,URL,URLSearchParams,__dirname,setImmediate});vm.runInContext(prefix.replace('window.pcDataOperations={strings};','window.pcDataOperations={strings,...'+JSON.stringify(config)+'};')+';globalThis.fixture={harness,main,operations};',sandbox);return sandbox.fixture;}
 const actual=ops({dimensionLinks:links}),h=actual.harness();await actual.main(h,{data:actual.operations(dates)});const nav=h.root.querySelector('.pc-ops-dimensions');assert.ok(nav);const anchors=nav.querySelectorAll('a');assert.equal(anchors.length,5);anchors.forEach((a,i)=>{const u=new URL(a.getAttribute('href'));assert.equal(u.searchParams.get('dimension'),['country','utm','browser','domain','device'][i]);for(const key of Object.keys(dates))assert.equal(u.searchParams.get(key),dates[key]);assert.equal(a.textContent,labels[i]);});
 const escaped=ops({dimensionLinks:links.map((link,index)=>({...link,label:index===0?'<data>&':link.label}))}),safe=escaped.harness();await escaped.main(safe,{data:escaped.operations(dates)});assert.equal(safe.root.querySelector('.pc-ops-dimensions').querySelector('a').textContent,'<data>&');assert.equal(safe.root.querySelector('data'),null);
 const malformed=ops({dimensionLinks:links}),bad=malformed.harness();await malformed.main(bad,{data:malformed.operations({...dates,compare_to:'2026-02-31'})});assert.equal(bad.root.querySelector('.pc-ops-dimensions'),null);
 const legacy=ops({sourcesUrl:'http://localhost:8093/wp-admin/admin.php?page=pc-data-sources'}),old=legacy.harness();await legacy.main(old,{data:legacy.operations(dates)});assert.equal(old.root.querySelector('.pc-ops-dimensions').querySelectorAll('a').length,1);
});
