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
    appendChild(child){child.parentNode=this;this.children.push(child);}
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
  const strings=new Proxy({exportCsv:'Export CSV',exportBusy:'Preparing',exportDone:'Downloaded',exportError:'Retry <error>',exportHelp:'All matching <rows>',product:'Product',views:'Views',sales:'Sales',carts:'Carts',moveUp:'Move %s up',moveDown:'Move %s down',noData:'Empty',page:'%1$d/%2$d',sku:'SKU %s'}, {get:(obj,key)=>obj[key]||String(key)});
  const window={pcData:{page:report,strings:common,range:initial,title:'Report',timezone:'UTC',currency:{code:'USD'},showCompare:report==='products'},location:{href:'http://localhost:8093/wp-admin/admin.php?page=pc-data-'+report,search:''},history:{replaceState(){}},URL:class extends URL{},URLSearchParams,localStorage:{getItem:key=>storage[key]??null,setItem:(key,value)=>storage[key]=value},setTimeout:(fn,ms)=>{timers.set(++timer,{fn,ms});return timer;},clearTimeout:id=>timers.delete(id)};
  window.URL.createObjectURL=blob=>{const url='blob:owned-'+created.length;created.push({blob,url});return url;};window.URL.revokeObjectURL=url=>revoked.push(url);
  window.wp={apiFetch:options=>new Promise((resolve,reject)=>{const u=new URL(options.path,'http://localhost');calls.push({options,path:u.pathname,params:Object.fromEntries(u.searchParams),resolve,reject});}),url:{addQueryArgs:(route,params)=>{const u=new URL(route,'http://localhost');for(const [key,value]of Object.entries(params))if(value!==undefined)u.searchParams.set(key,value);return u.pathname+u.search;}}};
  window['pcData'+(report==='products'?'Products':'Pages')]={strings};
  const context=vm.createContext({window,document,console});for(const name of ['data','data-export','data-'+report])vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/'+name+'.js'),'utf8'),context);
  document.events.DOMContentLoaded();const prefix=report==='products'?'prod':'pages';const el=suffix=>root.querySelector('.pc-'+prefix+'-'+suffix);
  const tick=ms=>{for(const [id,item] of [...timers])if(item.ms===ms){timers.delete(id);item.fn();}};
  const last=()=>calls.at(-1);
  return {report,root,document,window,calls,downloads,created,revoked,tick,last,el,strings,range:preset=>root.querySelector('button[data-preset="'+preset+'"]').fire('click')};
}
function data(report,name='CURRENT',dates={from:'2026-10-30',to:'2026-10-30',compare_from:'2026-10-29',compare_to:'2026-10-29'}) {
  const common={range:{preset:'today',dates},sort:'views',order:'desc',q:'',page:1,per_page:20,total:41};
  return report==='products'?{...common,metrics:['views','sales','carts'],defaults:['views','sales'],rows:[{product:{id:1,name},current:{views:3,sales:10},previous:{views:2,sales:20},change:{views:50,sales:-50},variations:[]}]}:{...common,rows:[{path:'/'+name,url:'http://localhost:8093/'+name,views:3,visitors:1,bounce_rate:0}]};
}
function raw({ok=true,type='text/csv; charset=UTF-8',blobError=false}={}) {return {ok,headers:{get:key=>key==='Content-Type'?type:'attachment; filename="safe.csv"'},blob:()=>blobError?Promise.reject(Error('blob')):Promise.resolve({csv:true})};}
(async()=>{
  for(const report of ['products','pages']) {
    const h=harness(report),prefix=report+': ';
    check(prefix+'initial unresolved report disables export',h.el('export').disabled);
    h.last().resolve(data(report,'<CURRENT>'));await flush();check(prefix+'accepted response enables export and escapes tooltip',!h.el('export').disabled&&h.el('export').getAttribute('title')==='All matching <rows>'&&!h.root.innerHTML.includes('<CURRENT>'));
    h.el('export').fire('click');check(prefix+'authenticated raw fetch uses parse false and explicit accepted dates',h.last().options.parse===false&&h.last().params.format==='csv'&&h.last().params.from==='2026-10-30'&&h.last().params.to==='2026-10-30'&&h.last().params.compare_from==='2026-10-29'&&!Object.hasOwn(h.last().params,'range')&&h.el('export').disabled);
    if(report==='products')check(prefix+'selected metric order sent',h.last().params.columns==='views,sales');
    h.last().resolve(raw());await flush();check(prefix+'valid CSV starts only one download and cleans temporary anchor',h.downloads.length===1&&h.downloads[0].name==='safe.csv'&&h.document.body.children.length===1&&h.created.length===1&&h.revoked.length===0);h.tick(1000);check(prefix+'object URL revoked after browser download opportunity',h.revoked.length===1&&!h.el('export').disabled);
    for(const failure of ['response-reject','bad-status','json','blob']) {
      h.el('export').fire('click');const pending=h.last();if(failure==='response-reject')pending.reject({status:403,ok:false});else pending.resolve(raw({ok:failure!=='bad-status',type:failure==='json'?'application/json':'text/csv',blobError:failure==='blob'}));await flush();
      check(prefix+failure+' produces inline retry with no file',h.downloads.length===1&&!h.el('export').disabled&&h.el('export-status').textContent==='Retry <error>');
    }
    h.el('export').fire('click');const oldCsv=h.last();h.range('yesterday');const pendingRange=h.last();check(prefix+'actual shell range gap disables retained report immediately',pendingRange.path.endsWith('/range')&&h.el('export').disabled&&h.el('wrap').innerHTML.includes('CURRENT'));
    oldCsv.resolve(raw());await flush();check(prefix+'CSV completion during unresolved shell range cannot download',h.downloads.length===1&&h.el('export').disabled);
    pendingRange.reject({code:'network'});await flush();check(prefix+'failed range recovers retained accepted report while shell error remains',!h.el('export').disabled&&!h.root.querySelector('.pc-data-error').hidden);
    h.el('export').fire('click');const afterFailure=h.last();h.range('yesterday');h.last().reject(Error('range'));await flush();afterFailure.resolve(raw());await flush();check(prefix+'pre-range CSV remains invalid even after range failure restores the report',h.downloads.length===1&&!h.el('export').disabled);
    h.el('export').fire('click');h.last().resolve(raw());await flush();check(prefix+'range failure permits retry using original accepted date',h.downloads.length===2&&h.last().params.from==='2026-10-30');h.tick(1000);
    h.el('export').fire('click');const beforeRemount=h.last();h.range('yesterday');h.last().resolve({preset:'yesterday',dates:{from:'2026-10-29',to:'2026-10-29',compare_from:'2026-10-28',compare_to:'2026-10-28'}});await flush();check(prefix+'successful range remount waits for new report',h.el('export').disabled&&h.last().path.endsWith('/'+report));
    beforeRemount.resolve(raw());await flush();check(prefix+'old CSV cannot survive successful remount',h.downloads.length===2);
    const accepted={from:'2026-10-28',to:'2026-10-28',compare_from:'2026-10-27',compare_to:'2026-10-27'};h.last().resolve(data(report,'ACCEPTED',accepted));await flush();h.el('export').fire('click');check(prefix+'accepted response dates override later relative-preset resolution',h.last().params.from==='2026-10-28'&&h.last().params.compare_from==='2026-10-27'&&!Object.hasOwn(h.last().params,'range'));h.last().resolve(raw());await flush();h.tick(1000);
    h.el('export').fire('click');const beforeSearch=h.last();h.el('search').value='new query';h.el('search').fire('input');check(prefix+'search edit disables export before debounce',h.el('export').disabled);beforeSearch.resolve(raw());await flush();check(prefix+'late pre-edit CSV suppressed',h.downloads.length===3);h.tick(400);h.last().resolve(data(report,'SEARCH'));await flush();check(prefix+'accepted search reenables export',!h.el('export').disabled);
    h.el('export').fire('click');const beforeIme=h.last();h.el('search').fire('compositionstart');h.el('search').value='中文';h.el('search').fire('input');beforeIme.resolve(raw());await flush();check(prefix+'IME immediately suppresses pending download',h.downloads.length===3&&h.el('export').disabled);h.el('search').fire('compositionend');h.tick(400);h.last().resolve(data(report,'IME'));await flush();
    h.el('export').fire('click');const beforeSort=h.last();h.el('wrap').querySelector('button[data-sort]').fire('click');beforeSort.resolve(raw());await flush();check(prefix+'sort reload suppresses pending CSV',h.downloads.length===3&&h.el('export').disabled);h.last().resolve(data(report));await flush();
    if(report==='products') {
      h.el('export').fire('click');const beforeColumn=h.last();h.el('columns').querySelector('button[data-move-key="sales"]').fire('click');beforeColumn.resolve(raw());await flush();check(prefix+'column reorder invalidates in-flight download',h.downloads.length===3&&!h.el('export').disabled);h.el('export').fire('click');check(prefix+'next export snapshots newly ordered visible keys',h.last().params.columns==='sales,views');h.last().resolve(raw());await flush();h.tick(1000);
      for(const key of ['sales','views']){const box=h.el('columns').querySelector('input[data-key="'+key+'"]');box.checked=false;box.fire('change');}h.el('export').fire('click');check(prefix+'identity-only selection sends explicit empty columns',Object.hasOwn(h.last().params,'columns')&&h.last().params.columns==='');h.last().resolve(raw());await flush();h.tick(1000);
    } else {
      h.el('export').fire('click');const beforeSize=h.last();h.el('perpage').value='50';h.el('perpage').fire('change');beforeSize.resolve(raw());await flush();check(prefix+'page-size reload invalidates download',h.downloads.length===3);h.last().resolve(data(report));await flush();
    }
    const count=h.downloads.length;h.el('export').fire('click');const beforePage=h.last();const footer=h.el(report==='products'?'pager':'foot');footer.querySelector('button[data-dir="1"]').fire('click');beforePage.resolve(raw());await flush();check(prefix+'pagination refresh invalidates export',h.downloads.length===count&&h.el('export').disabled);h.last().reject(Error('report failure'));await flush();check(prefix+'failed report cannot export stale context',h.el('export').disabled&&h.el('wrap').innerHTML.includes('Failed'));
    h.el('search').value='recover';h.el('search').fire('keydown',{key:'Enter'});h.last().resolve(data(report));await flush();
    let notifications=0;const unsubscribe=h.window.PcData.onRangeState(()=>{++notifications;});h.window.PcData.onRangeState(()=>{throw Error('subscriber');});h.range('today');check(prefix+'range subscribers immediate and isolated',notifications===2&&h.last().path.endsWith('/range'));h.last().reject(Error('range'));await flush();check(prefix+'failed range notifies false',notifications===3);unsubscribe();h.range('today');h.last().resolve({preset:'today',dates:{from:'2026-10-30',to:'2026-10-30'}});await flush();h.last().resolve(data(report));await flush();h.range('yesterday');h.last().reject(Error('range'));await flush();check(prefix+'unsubscribe and repeated remounts leave no old listeners',notifications===3&&!h.el('export').disabled);
    h.el('export').fire('click');const removed=h.last();h.root.innerHTML='';removed.resolve(raw());await flush();check(prefix+'removed DOM cannot trigger late download',h.downloads.length===count);
  }
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
