/* Actual shell + export helper + both report scripts, with DOM/API/clock boundaries. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
const check = (label, condition) => { assert.ok(condition,label); ++passed; };
const flush = () => new Promise(resolve=>setImmediate(resolve));
const decode = value => value.replace(/&(?:amp|lt|gt|quot|#39);/g,e=>({'&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&#39;':"'"}[e]));
function harness(report) {
  const document = {activeElement:null,readyState:'loading',events:{},addEventListener(key,fn){this.events[key]=fn;}};
  const downloads=[], created=[], revoked=[], calls=[], timers=new Map(), storage={}; let timer=0;
  class Element {
    constructor(tag='div',attrs={}) {this.tagName=tag;this.attrs=attrs;this.children=[];this.events={};this.nodeType=1;this.value='';this.checked=Object.hasOwn(attrs,'checked');this.disabled=Object.hasOwn(attrs,'disabled');this.hidden=Object.hasOwn(attrs,'hidden');this.textContent='';this.classes=new Set((attrs.class||'').split(' '));this.classList={add:k=>this.classes.add(k),remove:k=>this.classes.delete(k)};}
    set innerHTML(html) {for(const child of this.children)child.parentNode=null;this.children=[];this.html=html;const stack=[this];for(const match of html.matchAll(/<\/?([a-z][\w-]*)([^>]*)>/gi)){if(match[0][1]==='/'){if(stack.length>1)stack.pop();continue;}const attrs={};for(const a of match[2].matchAll(/([\w-]+)(?:="([^"]*)")?/g))attrs[a[1]]=decode(a[2]||'');const child=new Element(match[1],attrs);stack.at(-1).appendChild(child);if(!['input','img','br','hr'].includes(match[1]))stack.push(child);}}
    get innerHTML(){return this.html||'';}
    appendChild(child){child.parentNode=this;this.children.push(child);if(this.tagName==='select'&&child.tagName==='option'&&(child.hasAttribute('selected')||this.children.length===1))this.value=child.getAttribute('value');}
    removeChild(child){this.children.splice(this.children.indexOf(child),1);child.parentNode=null;}
    contains(child){for(let node=child;node;node=node.parentNode)if(node===this)return true;return false;}
    setAttribute(key,value){this.attrs[key]=String(value);if(key==='hidden')this.hidden=true;}
    getAttribute(key){return this.attrs[key]??null;}
    hasAttribute(key){return Object.hasOwn(this.attrs,key);}
    removeAttribute(key){delete this.attrs[key];if(key==='hidden')this.hidden=false;}
    querySelectorAll(selector){const m=selector.match(/^(?:\.([\w-]+)|([\w-]+))?(?:\[([\w-]+)(?:="([^"]*)")?\])?$/);assert.ok(m,selector);const results=[];const visit=n=>{for(const c of n.children){if((!m[1]||c.classes.has(m[1]))&&(!m[2]||c.tagName===m[2])&&(!m[3]||c.hasAttribute(m[3])&&(m[4]===undefined||c.getAttribute(m[3])===m[4])))results.push(c);visit(c);}};visit(this);return results;}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
    addEventListener(key,fn){this.events[key]=fn;}
    focus(){document.activeElement=this;}
    click(){if(this.tagName==='a'){downloads.push({href:this.href,name:this.download});return;}this.fire('click');}
    fire(key,extra={}){if(key==='click'&&this.disabled)return;const e={target:this,preventDefault(){},...extra};for(let n=this;n;n=n.parentNode)if(n.events[key])n.events[key](e);}
  }
  document.body=new Element('body');document.documentElement=document.body;const root=new Element();document.body.appendChild(root);document.getElementById=id=>id==='pc-data'?root:null;document.createElement=tag=>new Element(tag);
  const initial={preset:'today',dates:{from:'2026-10-30',to:'2026-10-30',compare_from:'2026-10-29',compare_to:'2026-10-29'}};
  const common=new Proxy({loading:'Loading',loadError:'Failed',rangeError:'Invalid dates',vs:'VS'}, {get:(obj,key)=>obj[key]||String(key)});
  const strings=new Proxy({exportCsv:'Export CSV',exportBusy:'Preparing',exportDone:'Downloaded',exportError:'Retry <error>',exportHelp:'Selected grouping <rows>',retry:'Retry',groupRate:'%s rate',ofVisitors:'%s of visitors',product:'Product',views:'Views',sales:'Sales',carts:'Carts',moveUp:'Move %s up',moveDown:'Move %s down',noData:'Empty',page:'%1$d/%2$d',sku:'SKU %s'}, {get:(obj,key)=>obj[key]||String(key)});
  const window={pcData:{page:report,strings:common,range:initial,title:'Report',timezone:'UTC',currency:{code:'USD'},showCompare:true},location:{href:'http://localhost:8093/wp-admin/admin.php?page=pc-data-'+report,search:''},history:{replaceState(){}},URL:class extends URL{},URLSearchParams,localStorage:{getItem:key=>storage[key]??null,setItem:(key,value)=>storage[key]=value},setTimeout:(fn,ms)=>{timers.set(++timer,{fn,ms});return timer;},clearTimeout:id=>timers.delete(id)};
  window.URL.createObjectURL=blob=>{const url='blob:owned-'+created.length;created.push({blob,url});return url;};window.URL.revokeObjectURL=url=>revoked.push(url);
  window.wp={apiFetch:options=>new Promise((resolve,reject)=>{const u=new URL(options.path,'http://localhost');calls.push({options,path:u.pathname,params:Object.fromEntries(u.searchParams),resolve,reject});}),url:{addQueryArgs:(route,params)=>{const u=new URL(route,'http://localhost');for(const [key,value]of Object.entries(params))if(value!==undefined)u.searchParams.set(key,value);return u.pathname+u.search;}}};
  window.pcDataConversion={strings};
  const context=vm.createContext({window,document,console});for(const name of ['data','data-export','data-'+report])vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/'+name+'.js'),'utf8'),context);
  document.events.DOMContentLoaded();const prefix='conv';const el=suffix=>root.querySelector('.pc-'+prefix+'-'+suffix);
  const tick=ms=>{for(const [id,item] of [...timers])if(item.ms===ms){timers.delete(id);item.fn();}};
  const last=()=>calls.at(-1);
  return {report,root,document,window,calls,downloads,created,revoked,tick,last,el,strings,range:preset=>root.querySelector('button[data-preset="'+preset+'"]').fire('click')};
}
const keys=['visitors','viewed','carted','checkout','address','shipping','payment','paid'];
const dates={from:'2026-10-28',to:'2026-10-28',compare_from:'2026-10-27',compare_to:'2026-10-27'};
function data(group='device',accepted=dates,label='<CURRENT>') {
  const layers=keys.map((key,i)=>({key,count:[4,3,3,2,0,1,1,1][i],visitor_rate:[100,75,75,50,0,25,25,25][i],step_rate:null}));
  const observed=['checkout_view','checkout_address_completed','checkout_shipping_selected','checkout_payment_selected','checkout_submit_attempt','payment_attempt','payment_success'];
  return {range:{preset:'today',dates:accepted},tracking:true,group,groups:['device','browser'],overall:{current:{conversion:50,paid:2,visitors:4,viewed:3,carted:3,checkout:2},previous:{},change:{}},visitor_funnel:{unit:'visitors',current:layers,previous:layers,change:{}},rows:[{key:label,sales:30,paid:2,conversion:50,funnel:[],visitor_funnel:{unit:'visitors',layers}}],checkout_steps:{labels:Object.fromEntries(observed.map(k=>[k,k])),sources:Object.fromEntries(observed.map(k=>[k,k.startsWith('payment_')?'server':'client'])),current:{},previous:{},change:{},channels:[],local_simulation:true}};
}
function raw({ok=true,type='text/csv; charset=UTF-8',blobError=false,blob}={}) {return {ok,headers:{get:key=>key==='Content-Type'?type:'attachment; filename="conversion.csv"'},blob:()=>blob?blob:blobError?Promise.reject(Error('blob')):Promise.resolve({csv:true})};}
(async()=>{
  const h=harness('conversion');
  check('initial false range notice does not double-load',h.calls.length===1&&h.last().path.endsWith('/conversion')&&h.el('export').disabled);
  h.last().resolve(data());await flush();
  check('accepted report enables export with escaped scope and labels',!h.el('export').disabled&&h.el('export').getAttribute('title')==='Selected grouping <rows>'&&!h.el('result').innerHTML.includes('<CURRENT>'));
  h.el('export').fire('click');let pending=h.last();
  check('CSV uses explicit accepted dates/group rather than preset or later day',pending.options.parse===false&&pending.params.format==='csv'&&pending.params.group==='device'&&pending.params.from===dates.from&&pending.params.compare_to===dates.compare_to&&!Object.hasOwn(pending.params,'range')&&Object.keys(pending.params).length===6&&h.el('export').disabled);
  h.el('result').querySelector('button[data-conv-toggle]').fire('click');
  check('detail toggle keeps export context and keyboard focus',h.last()===pending&&h.document.activeElement.getAttribute('aria-expanded')==='true');
  pending.resolve(raw());await flush();
  check('real helper starts one file and cleans temporary anchor',h.downloads.length===1&&h.created.length===1&&h.document.body.children.length===1&&h.el('export-status').textContent==='Downloaded'&&!h.el('export').disabled);
  h.tick(1000);check('real helper revokes object URL after download opportunity',h.revoked.length===1);
  for(const error of ['raw-response','bad-status','json','blob']) {
    h.el('export').fire('click');pending=h.last();if(error==='raw-response')pending.reject({status:403,ok:false});else pending.resolve(raw({ok:error!=='bad-status',type:error==='json'?'application/json':'text/csv',blobError:error==='blob'}));await flush();
    check(error+' gives retry without a file or unsafe HTML',h.downloads.length===1&&!h.el('export').disabled&&h.el('export-status').textContent==='Retry <error>');
  }
  const download=h.window.PcDataExport.download;h.window.PcDataExport.download=()=>false;h.el('export').fire('click');h.last().resolve(raw());await flush();
  check('download false never reports success and permits retry',h.downloads.length===1&&h.el('export-status').textContent==='Retry <error>'&&!h.el('export').disabled);h.window.PcDataExport.download=download;
  h.el('export').fire('click');const beforeGroup=h.last();h.el('group').value='browser';h.el('group').fire('change');const browser=h.last();beforeGroup.resolve(raw());await flush();
  check('group edit immediately suppresses old download and clears qualification',h.downloads.length===1&&h.el('export').disabled&&browser.params.group==='browser');
  h.el('group').value='device';h.el('group').fire('change');const oldGroup=h.last();h.el('group').value='browser';h.el('group').fire('change');const newGroup=h.last();oldGroup.resolve(data('device',dates,'OLD'));browser.resolve(data('browser',dates,'OLDER'));await flush();
  check('out of order group replies cannot repaint or reenable',h.el('export').disabled&&!h.el('result').innerHTML.includes('OLDER'));
  newGroup.resolve(data('browser'));await flush();check('latest selected grouping alone qualifies',!h.el('export').disabled&&h.el('group').value==='browser');
  h.el('group').fire('change');h.last().reject(Error('report'));await flush();check('report failure clears accepted data but retains retry/group controls',h.el('export').disabled&&!h.el('group').disabled&&!!h.el('result').querySelector('button[data-conv-retry]'));
  h.el('result').querySelector('button[data-conv-retry]').fire('click');check('report retry snapshots last known explicit range',h.last().params.from===dates.from&&!Object.hasOwn(h.last().params,'range'));h.last().resolve(data('browser'));await flush();check('report retry restores accepted context',!h.el('export').disabled);
  h.el('group').fire('change');h.last().resolve(null);await flush();check('malformed fulfilled report becomes local retry and no export',h.el('export').disabled&&h.el('result').innerHTML.includes('Failed'));h.el('result').querySelector('button[data-conv-retry]').fire('click');h.last().resolve(data('browser'));await flush();
  h.el('export').fire('click');const beforeRange=h.last();h.range('yesterday');const rangeRequest=h.last();
  check('actual unresolved shell range gap disables both export and group',rangeRequest.path.endsWith('/range')&&h.el('export').disabled&&h.el('group').disabled);beforeRange.resolve(raw());await flush();check('CSV completion inside range gap cannot create a file',h.downloads.length===1&&h.el('export').disabled);
  rangeRequest.reject(Error('range'));await flush();check('range rejection restores retained settled same-group report',!h.el('export').disabled&&!h.el('group').disabled&&!h.root.querySelector('.pc-data-error').hidden);
  h.el('export').fire('click');const invalidated=h.last();h.range('today');h.last().reject(Error('range'));await flush();invalidated.resolve(raw());await flush();check('invalidated CSV stays invalid after range rejection',h.downloads.length===1&&!h.el('export').disabled);
  h.el('export').fire('click');h.last().resolve(raw());await flush();check('fresh retry after range failure downloads original accepted context',h.downloads.length===2&&h.last().params.group==='browser'&&h.last().params.from===dates.from);h.tick(1000);
  let resolveBlob;h.el('export').fire('click');h.last().resolve(raw({blob:new Promise(r=>{resolveBlob=r;})}));await flush();h.range('yesterday');const blobRange=h.last();resolveBlob({csv:true});await flush();check('late blob in range gap creates no object URL',h.downloads.length===2&&h.created.length===2);blobRange.reject(Error('range'));await flush();
  h.el('group').value='device';h.el('group').fire('change');const lateJson=h.last();h.range('yesterday');const pendingRange=h.last();lateJson.resolve(data('device',dates,'INVALIDATED'));await flush();check('range start invalidates an unaccepted group JSON',h.el('export').disabled&&!h.el('result').innerHTML.includes('INVALIDATED'));
  const countBefore=h.calls.length;pendingRange.reject(Error('range'));await flush();h.tick(0);check('range rejection with no accepted group reloads old explicit range exactly once',h.calls.length===countBefore+1&&h.last().path.endsWith('/conversion')&&h.last().params.group==='device'&&h.last().params.from===dates.from&&!Object.hasOwn(h.last().params,'range'));h.tick(0);check('recovery timer cannot reload twice',h.calls.length===countBefore+1);h.last().resolve(data());await flush();
  h.el('group').value='browser';h.el('group').fire('change');const discardedGroup=h.last();h.range('yesterday');const successfulRange=h.last();const afterRange={from:'2026-10-29',to:'2026-10-29',compare_from:'2026-10-28',compare_to:'2026-10-28'};successfulRange.resolve({preset:'yesterday',dates:afterRange});await flush();const newRequest=h.last(),callsAfterRemount=h.calls.length;h.tick(0);
  check('false notification before synchronous remount cancels old zero-delay recovery',h.calls.length===callsAfterRemount&&newRequest.path.endsWith('/conversion')&&newRequest.params.group==='browser');discardedGroup.resolve(data('browser',dates,'STALE'));await flush();check('old JSON cannot repaint the successful remount',h.el('export').disabled&&!h.el('result').innerHTML.includes('STALE'));newRequest.resolve(data('browser',afterRange));await flush();
  h.el('export').fire('click');check('new mount export snapshots only its accepted dates/group',h.last().params.from===afterRange.from&&h.last().params.group==='browser');const oldMountedCsv=h.last();h.range('today');h.last().resolve({preset:'today',dates});await flush();h.last().resolve(data('browser'));await flush();oldMountedCsv.resolve(raw());await flush();check('old renderer CSV cannot survive new mounted report',h.downloads.length===2);
  let notices=0;h.window.PcData.onRangeState(()=>{++notices;});h.range('yesterday');h.last().resolve({preset:'yesterday',dates:afterRange});await flush();h.last().resolve(data('browser',afterRange));await flush();const oldNotices=notices;h.range('today');h.last().reject(Error('range'));await flush();check('repeated remounts clear prior subscriptions and keep one usable renderer',notices===oldNotices&&!h.el('export').disabled);
  h.el('export').fire('click');const detached=h.last();h.root.innerHTML='';detached.resolve(raw());await flush();check('detached DOM suppresses the final download',h.downloads.length===2);
  const fresh=harness('conversion');const firstJson=fresh.last();fresh.range('yesterday');const firstRange=fresh.last();firstJson.resolve(data());await flush();firstRange.reject(Error('range'));await flush();fresh.tick(0);check('initial pending JSON plus rejected range reloads once using shell explicit dates',fresh.calls.length===3&&fresh.last().params.from==='2026-10-30'&&!Object.hasOwn(fresh.last().params,'range'));fresh.last().resolve(data());await flush();
  const legacy=data();delete legacy.visitor_funnel;legacy.rows[0].funnel=[];delete legacy.rows[0].visitor_funnel;fresh.el('group').fire('change');fresh.last().resolve(legacy);await flush();check('legacy display fallback retained without granting detailed export',fresh.el('export').disabled&&fresh.el('result').innerHTML.includes('detailUnavailable'));
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
