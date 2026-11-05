'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-dialog-experience.js'),'utf8');
const reader=source.slice(source.indexOf('    async function readReports('),source.indexOf('    function openImage('));
const refresher=source.slice(source.lastIndexOf('    function refresh()'),source.indexOf('    let pointer = null'));
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
test('history restored pending selection starts the previously skipped first report read on native acceptance',async()=>{
 const f=fixture();f.select('15mg');
 Object.assign(f.context,{report:null,reportReadPromise:null,lastSelection:'',track:{scrollLeft:0},renderProduct(){},syncSticky(){},renderOffers(){},navigation:null,ticker:null,support:null,context:()=>({}),CustomEvent:class{},document:{hidden:false,dispatchEvent(){}},root:{vcHomeSelectionPending:true,querySelector(){return {value:'15'};}}});
 vm.runInContext(refresher,f.context);
 await f.read();f.context.refresh();assert.equal(f.requests.length,0,'pending initial SKU must not read or expose a report');
 f.context.root.vcHomeSelectionPending=false;f.context.refresh();assert.equal(f.requests.length,1,'accepted restored SKU starts page1 without waiting for visibility or another SKU');
 f.context.refresh();assert.equal(f.requests.length,1,'inflight first-page read deduplicates repeated refresh');
 f.requests[0].resolve({page:1,total_pages:1,total:1,items:[item(11617,'15mg')]});await f.context.reportReadPromise;
 assert.equal(f.context.reports[0].id,11617);assert.deepEqual(f.renders,[0,0,0,1]);f.context.refresh();assert.equal(f.requests.length,1,'completed cursor does not reload on every refresh');
});
test('only exact known public report collection omits stale nonce, including configured and plain REST bases',async()=>{
  for(const [api,configured,publicCollection]of [
    ['https://shop.test/wp-json/peptide/v1/report-library/',null,true],
    ['https://shop.test/?rest_route=%2Fpeptide%2Fv1%2Freport-library',null,true],
    ['https://shop.test/store/custom-rest/peptide/v1/report-library','https://shop.test/store/custom-rest/peptide/v1/report-library/',true],
    ['https://shop.test/wp-json/peptide/v1/reports/7/file',null,false],
    ['https://shop.test/wp-json/peptide/v1/report-library/7/file',null,false],
    ['https://shop.test/private/peptide/v1/report-library',null,false]
  ]){
    const f=fixture();f.context.window.vcHomeQuickView={reportApi:api,wpNonce:'STALE_FIXTURE_NONCE'};
    if(configured)f.context.window.vcProductReports={reportLibraryBase:configured};
    const read=f.read();assert.equal(f.requests[0].options.headers['X-WP-Nonce'],publicCollection?undefined:'STALE_FIXTURE_NONCE',api);
    f.requests[0].resolve({page:1,total_pages:1,total:0,items:[]});await read;
  }
});
