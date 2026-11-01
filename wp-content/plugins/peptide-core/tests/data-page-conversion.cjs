/* Actual unmodified data.js and report scripts. Reuse the existing DOM/API/clock boundary harness. */
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const KEYS = ['exposed_users','viewed_users','view_user_rate','cart_users','cart_user_rate','paid_users','paid_user_rate','impressions','views','view_rate','carts','cart_rate','paid_units','paid_rate'];
const DEFAULTS = ['exposed_users','view_user_rate','viewed_users','cart_user_rate','cart_users','paid_users','paid_user_rate','impressions','views','view_rate'];
const STORE = 'pcDataPageConversionColumns';
let passed=0;const check=(name,ok)=>{assert.ok(ok,name);++passed;};const flush=()=>new Promise(resolve=>setImmediate(resolve));
let harnessSource=fs.readFileSync(path.join(__dirname,'data-landing-attribution.cjs'),'utf8').split('const dates=')[0];
// Harness configuration only. Both production scripts are read in full and passed unchanged to VM.
harnessSource=harnessSource.replace("blurTiming='sync')", "blurTiming='sync',seed={})")
 .replace('storage={},','storage={...seed},')
 .replace('window.pcDataLanding={strings};',"strings.metrics=Object.fromEntries(PC_KEYS.map(k=>[k,k]));strings.total='Matching %s';strings.points='pp';strings.empty='Empty exposures';strings.loading='Loading';window.pcDataPageConversion={strings};")
 .replace('getItem:key=>storage[key]??null',"getItem:key=>{if(seed.blockedStorage)throw Error('blocked');return storage[key]??null;}")
 .replace('setItem:(key,value)=>storage[key]=value',"setItem:(key,value)=>{if(seed.blockedStorage)throw Error('blocked');storage[key]=value;}")
 .replace("['data','data-'+report]","['data','data-page-conversion']")
 .replace("const prefix='landing'","const prefix='pc'");
const harnessContext={require,__dirname,console,URL,URLSearchParams,setImmediate,PC_KEYS:KEYS};
vm.runInNewContext(harnessSource+'\nthis.make=harness;',harnessContext);
const make=(seed={},withoutHook=false)=>harnessContext.make('page_conversion',withoutHook,'sync',seed);
function reply(h,label='/<safe>&/',extra={}) {
 const p=h.last().params;
 const current={exposed_users:2,viewed_users:1,view_user_rate:50,cart_users:2,cart_user_rate:100,paid_users:1,paid_user_rate:50,impressions:3,views:5,view_rate:166.67,carts:4,cart_rate:133.33,paid_units:-1,paid_rate:-33.33};
 const previous=Object.fromEntries(KEYS.map(k=>[k,k.endsWith('_rate')?null:0]));
 const change=Object.fromEntries(KEYS.map(k=>[k,null]));
 const kind=p.type||'shop',can_drill=p.group==='type';
 const row={key:encodeURIComponent(JSON.stringify(['type',kind])),kind,entity_id:null,path:can_drill?'':'/Case/',label,url:can_drill?'':'http://localhost:8093/Case/',can_drill,current,previous,change};
 return {range:{dates:{from:p.from,to:p.to,compare_from:p.compare_from,compare_to:p.compare_to}},tracking:true,group:p.group,type:p.type,q:p.q,sort:p.sort,order:p.order,page:Number(p.page),per_page:Number(p.per_page),total:1,metrics:KEYS,defaults:DEFAULTS,rows:[row],measurement:{version:1,minimum_visible_fraction:0.4,minimum_visible_ms:500,attribution:'same_visit_parent_after_impression_within_period',source:'pushed_observations'},...extra};
}
async function accept(h,label,extra){h.last().resolve(reply(h,label,extra));await flush();}
const checkOrder=(h,expected)=>expected.every((k,i)=>h.el('results').innerHTML.indexOf('data-pc-sort="'+k+'"')>=0&&(i===0||h.el('results').innerHTML.indexOf('data-pc-sort="'+expected[i-1]+'"')<h.el('results').innerHTML.indexOf('data-pc-sort="'+k+'"')));
(async()=>{
 const h=make();check('actual shell immediate false notification causes one request',h.calls.length===1&&h.last().path.endsWith('/data/page-conversion'));
 check('initial pending has no usable chooser or old rows',h.el('columns').disabled&&h.el('results').innerHTML.includes('Loading'));
 check('initial request has four accepted dates and exact default grouping',h.last().params.group==='type'&&h.last().params.type===''&&h.last().params.from==='2026-10-30'&&!Object.hasOwn(h.last().params,'range'));
 await accept(h);check('authoritative default ten columns retain order',checkOrder(h,DEFAULTS)&&h.el('results').querySelectorAll('[data-pc-sort]').length===10);
 check('identity labels escaped with no object coercion',h.el('results').innerHTML.includes('&lt;safe&gt;&amp;')&&!h.el('results').innerHTML.includes('/<safe>&/')&&!h.el('results').innerHTML.includes('[object Object]'));
 check('each visible metric has current previous and change labels',h.el('results').innerHTML.includes('current')&&h.el('results').innerHTML.includes('previous')&&h.el('results').innerHTML.includes('change'));
 check('uncapped view rate and undefined comparison remain readable',h.el('results').innerHTML.includes('166.67%')&&h.el('results').innerHTML.includes('-'));
 h.el('columns').fire('click');check('chooser has exactly fourteen authoritative keys',h.el('chooser').querySelectorAll('[data-pc-column]').length===14);
 check('first selected up and last selected down disabled',h.el('chooser').querySelector('[data-pc-move="exposed_users"]').disabled&&h.el('chooser').querySelectorAll('[data-pc-move="view_rate"]')[1].disabled);
 const move=h.el('chooser').querySelector('[data-pc-move="view_user_rate"]');move.fire('click');
 check('selected neighbor moves without duplication and restores checkbox focus',JSON.parse(h.storage[STORE])[0]==='view_user_rate'&&h.document.activeElement.getAttribute('data-pc-column')==='view_user_rate');
 let input=h.el('chooser').querySelector('[data-pc-column="paid_units"]');input.checked=true;input.fire('change');
 check('checking appends a new key at the end',JSON.parse(h.storage[STORE]).at(-1)==='paid_units');
 check('negative net paid units and paid rate are not clamped',h.el('results').innerHTML.includes('-1'));
 for(const key of JSON.parse(h.storage[STORE])){input=h.el('chooser').querySelector('[data-pc-column="'+key+'"]');input.checked=false;input.fire('change');}
 check('explicit empty preference is saved and retains identity only',h.storage[STORE]==='[]'&&h.el('results').querySelectorAll('[data-pc-sort]').length===0&&h.el('results').innerHTML.includes('data-pc-drill'));
 const empty=make({[STORE]:'[]'});await accept(empty);check('empty preference survives fresh script evaluation',empty.el('results').querySelectorAll('[data-pc-sort]').length===0);
 const obsolete=make({[STORE]:'["obsolete",17]'});await accept(obsolete);check('nonempty wholly obsolete preference falls back to defaults',checkOrder(obsolete,DEFAULTS));
 const mixed=make({[STORE]:'["paid_rate","obsolete","views","paid_rate",null]'});await accept(mixed);check('mixed obsolete duplicate preference retains valid original order',checkOrder(mixed,['paid_rate','views'])&&mixed.el('results').querySelectorAll('[data-pc-sort]').length===2);
 const blocked=make({blockedStorage:true});await accept(blocked);blocked.el('columns').fire('click');let blockedInput=blocked.el('chooser').querySelector('[data-pc-column="paid_units"]');blockedInput.checked=true;blockedInput.fire('change');check('blocked storage read and write preserve working in-memory column controls',blocked.el('results').innerHTML.includes('data-pc-sort="paid_units"'));
 const beforeSearch=h.calls.length;h.el('search').value='first';h.el('search').fire('input');
 check('editing search immediately clears table and closes chooser',!h.el('results').innerHTML.includes('data-pc-drill')&&h.el('columns').disabled&&h.el('chooser').hidden);
 h.tick(400);check('search debounces once and resets page',h.calls.length===beforeSearch+1&&h.last().params.q==='first'&&h.last().params.page==='1');
 const stale=h.last();h.el('search').value='second';h.el('search').fire('input');h.el('search').fire('keydown',{key:'Enter'});const current=h.last();stale.resolve(reply(h,'/STALE/'));await flush();check('late older JSON cannot restore rows',!h.el('results').innerHTML.includes('STALE'));
 current.reject(Error('network'));await flush();check('failure clears data, disables chooser and offers retry',h.el('columns').disabled&&!!h.el('results').querySelector('[data-pc-retry]')&&!h.el('footer').innerHTML);
 h.el('results').querySelector('[data-pc-retry]').fire('click');await accept(h,'/recovered/');check('retry restores the same explicit query context',h.last().params.q==='second'&&h.el('results').innerHTML.includes('recovered'));
 h.el('search').fire('compositionstart');h.el('search').value='中';h.el('search').fire('input');const ime=h.calls.length;h.tick(400);check('IME never issues an intermediate query',h.calls.length===ime);
 h.el('search').fire('compositionend');h.tick(400);check('IME completion issues one final query',h.calls.length===ime+1&&h.last().params.q==='中');await accept(h);
 h.el('size').value='50';h.el('size').fire('change');check('page size keeps search and resets page',h.last().params.per_page==='50'&&h.last().params.q==='中'&&h.last().params.page==='1');await accept(h,'/paged/',{total:61});
 h.el('footer').querySelectorAll('[data-pc-page]')[1].fire('click');check('pagination requests next page without losing cohort',h.last().params.page==='2'&&h.last().params.per_page==='50');await accept(h,'/next/',{total:61});
 check('filtered total is shown and next disabled at final page',h.el('footer').innerHTML.includes('61')&&h.el('footer').querySelectorAll('[data-pc-page]')[1].disabled);
 const drill=h.el('results').querySelector('[data-pc-drill]'),key=drill.getAttribute('data-pc-key');drill.focus();drill.fire('click');check('drill requests exact selected kind and page one',h.last().params.group==='page'&&h.last().params.type==='shop'&&h.last().params.page==='1');await accept(h,'Page');
 check('page drill has safe configured-origin link and accessible Back',!!h.el('results').querySelector('a')&&!h.el('back').hidden);
 h.el('back').fire('click');await accept(h);check('Back restores summary and an attached matching opener focus',h.last().params.group==='type'&&h.last().params.type===''&&h.document.activeElement.getAttribute('data-pc-key')===key&&h.document.documentElement.contains(h.document.activeElement));
 h.el('search').value='late';h.el('search').fire('input');h.tick(400);const preRange=h.last();h.range('yesterday');const gap=h.last();
 check('real data.js pending range gap immediately disables old toolbar and data',gap.path.endsWith('/range')&&h.el('search').disabled&&h.el('columns').disabled&&!h.el('results').innerHTML.includes('data-pc-drill'));
 preRange.resolve(reply(h,'/LATE-GAP/'));await flush();check('JSON resolving during shell gap stays invalid',!h.el('results').innerHTML.includes('LATE-GAP'));
 const gapCalls=h.calls.length;gap.reject(Error('range'));await flush();check('range failure schedules recovery without immediate duplicate call',h.calls.length===gapCalls);h.tick(0);
 check('range failure reloads once with last accepted explicit dates',h.calls.length===gapCalls+1&&h.last().params.from==='2026-10-30');h.tick(0);check('recovery timer cannot repeat',h.calls.length===gapCalls+1);await accept(h,'/gap recovered/');
 h.range('yesterday');const successful=h.last(),count=h.calls.length;successful.resolve({preset:'yesterday',dates:{from:'2026-10-29',to:'2026-10-29',compare_from:'2026-10-28',compare_to:'2026-10-28'}});await flush();
 check('successful range remount creates one current report request',h.calls.length===count+1&&h.last().params.from==='2026-10-29');h.tick(0);check('old remount recovery timer was disposed',h.calls.length===count+1);await accept(h,'/new period/');
 h.el('size').value='100';h.el('size').fire('change');const wrong=reply(h);wrong.measurement.version=2;h.last().resolve(wrong);await flush();check('malformed response becomes retryable error',!!h.el('results').querySelector('[data-pc-retry]')&&h.el('columns').disabled);
 h.el('results').querySelector('[data-pc-retry]').fire('click');await accept(h,'',{total:0,rows:[]});check('legitimate empty period remains usable without invented exposure rows',h.el('results').innerHTML.includes('Empty exposures')&&!h.el('columns').disabled);
 h.el('search').value='detached';h.el('search').fire('keydown',{key:'Enter'});const removed=h.last();h.document.body.removeChild(h.root);removed.resolve(reply(h,'/DETACHED/'));await flush();check('detached report receives no late rows',!h.el('results').innerHTML.includes('DETACHED'));
 const legacy=make({},true);await accept(legacy);check('optional hook absence still renders in older harnesses',!!legacy.el('results').querySelector('[data-pc-drill]'));
 console.log('TOTAL '+passed+' PASS, 0 FAIL');
})().catch(error=>{console.error(error);process.exitCode=1;});
