'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-dialog-experience.js'),'utf8');
const reader=source.slice(source.indexOf('    async function readReports('),source.indexOf('    function openImage('));
const settle=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
function fixture(){
  const requests=[],renders=[],timers=new Map();let sequence=0,selected='20mg';
  const context=vm.createContext({settings:{reportGalleryEnabled:true},destroyed:false,reportRequest:null,reportCursor:{page:0,pages:1,total:null},reports:[],lastReportRead:0,document:{hidden:false},dialog:{open:true},root:{vcHomeSelectionPending:false},productId:7,AbortController,URL,
    safeURL:value=>value,window:{vcHomeQuickView:{reportApi:'https://shop.test/reports'}},selectedLabel:()=>selected,
    latestReport:(items,id,spec)=>items.find(item=>item.product.id===id&&item.specification===spec),renderReport(){renders.push(context.reports.length);},
    setTimeout(fn){const id=++sequence;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id),fetch(url,options){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});requests.push({url,options,resolve(data){resolve({ok:true,json:async()=>data});},reject});return promise;}});
  vm.runInContext(reader,context);return {context,requests,renders,timers,read:options=>context.readReports(options),select:value=>{selected=value;}};
}
const item=(id,spec='5mg')=>({id,product:{id:7},specification:spec,view_url:'/report/'+id+'.pdf',file:{mime:'application/pdf'}});
const page=(index,items)=>({page:index,total_pages:3,total:49,items});
test('newest first page paints immediately and stops history once current exact specification exists',async()=>{
  const f=fixture(),first=f.read();assert.equal(f.requests.length,1);f.requests[0].resolve(page(1,[item(49,'20mg'),...Array.from({length:23},(_,i)=>item(48-i))]));await first;
  assert.deepEqual(f.renders,[24]);assert.equal(f.requests.length,1,'no eager remaining 2 history requests');assert.equal(f.timers.size,0);
  f.select('15mg');const next=f.read({resume:true});assert.equal(new URL(f.requests[1].url).searchParams.get('page'),'2');
  f.requests[1].resolve(page(2,[item(25,'15mg'),...Array.from({length:23},(_,i)=>item(24-i))]));await next;
  assert.equal(f.requests.length,2);assert.deepEqual(f.renders,[24,48]);assert.equal(f.timers.size,0);
  const history=f.read({resume:true,requested:'/report/1.pdf'});f.requests[2].resolve(page(3,[item(1)]));await history;
  assert.equal(f.context.reports.length,49);assert.equal(f.context.reportCursor.page,3);assert.equal(f.timers.size,0);
});
test('a missing specification reads later pages on demand while preserving the first paint on later failure',async()=>{
  const f=fixture(),read=f.read();f.requests[0].resolve(page(1,Array.from({length:24},(_,i)=>item(49-i))));await settle();
  assert.deepEqual(f.renders,[24],'first page is available before next request finishes');assert.equal(f.requests.length,2);assert.equal(f.timers.size,1);
  f.requests[1].reject(new Error('Transient failure'));await read;assert.equal(f.context.reports.length,24);assert.equal(f.context.reportCursor.page,1);assert.equal(f.timers.size,0);
});
test('hidden, pending, completed or destroyed work cannot fetch/render or leak timeout',async()=>{
  const f=fixture();f.context.document.hidden=true;await f.read();assert.equal(f.requests.length,0);f.context.document.hidden=false;f.context.root.vcHomeSelectionPending=true;await f.read();assert.equal(f.requests.length,0);
  f.context.root.vcHomeSelectionPending=false;const read=f.read();f.context.destroyed=true;f.requests[0].resolve(page(1,Array.from({length:24},(_,i)=>item(49-i))));await read;assert.deepEqual(f.renders,[]);assert.equal(f.timers.size,0);
  f.context.destroyed=false;f.context.reportCursor={page:3,pages:3,total:49};await f.read({resume:true});assert.equal(f.requests.length,1);
});
