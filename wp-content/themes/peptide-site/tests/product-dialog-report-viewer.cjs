'use strict';
// Production source, isolated DOM/PDF boundary peers; not phone or PDF rendering acceptance.
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const {spawnSync} = require('node:child_process');
if (!vm.SyntheticModule) {
  const result = spawnSync(process.execPath,['--experimental-vm-modules',__filename],{stdio:'inherit',env:{...process.env,NODE_NO_WARNINGS:'1'}});
  process.exit(result.status ?? 1);
}
const directory = path.resolve(__dirname,'../assets'), source = fs.readFileSync(path.join(directory,'product-dialog-report-viewer.js'),'utf8');
const deferred = () => { let resolve,reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; };
const settle = async () => { for(let n=0;n<24;n++) await Promise.resolve(); };
class Events {
  constructor(){this.events = new Map();}
  addEventListener(type,fn){this.events.set(type,[...(this.events.get(type)||[]),fn]);}
  removeEventListener(type,fn){this.events.set(type,(this.events.get(type)||[]).filter(item=>item!==fn));}
  fire(type,extra={}){for(const fn of [...(this.events.get(type)||[])])fn({type,target:this,...extra});}
}
class Element extends Events {
  constructor(tag='div',name=''){super();this.tagName=tag;this.className=name;this.children=[];this.parentNode=null;this.dataset={};this.style={};this.attrs={};this.clientWidth=343;this.scrollLeft=0;this.width=0;this.height=0;}
  get isConnected(){return !!this.parentNode || this.connected;}
  append(...items){for(const el of items){el.remove();el.parentNode=this;this.children.push(el);}}
  prepend(...items){for(const el of [...items].reverse()){el.remove();el.parentNode=this;this.children.unshift(el);}}
  remove(){if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(el=>el!==this);this.parentNode=null;}}
  replaceChildren(...items){for(const el of [...this.children])el.remove();this.append(...items);}
  setAttribute(key,value){this.attrs[key]=String(value);}removeAttribute(key){delete this.attrs[key];}
  matches(selector){if(selector==='canvas')return this.tagName==='canvas';if(selector.startsWith('.'))return this.className.split(' ').includes(selector.slice(1));return selector===`[data-pdf-zoom="${this.attrs['data-pdf-zoom']}"]`;}
  querySelectorAll(selector){return this.children.flatMap(el=>[...(el.matches(selector)?[el]:[]),...el.querySelectorAll(selector)]);}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  getContext(){return {canvas:this};}
  closest(){return null;}
}
async function fixture({numPages=1,dimensions=[[612,792]],deferLoad=false,deferRender=false,deferImport=false,deferPage=false,missingResolvers=false,lazy=false}={}){
  const window=new Events(),document={createElement:tag=>new Element(tag)},element=new Element(),pages=new Element('div','pdf-pages');element.connected=true;
  const status=new Element('p','pdf-status'),retry=new Element('button','pdf-retry'),zoom=new Element('span','pdf-zoom-value'),out=new Element('button'),inside=new Element('button');out.attrs['data-pdf-zoom']='out';inside.attrs['data-pdf-zoom']='in';element.append(pages,status,retry,zoom,out,inside);
  const timers=new Map(),frames=new Map(),requests=[],renders=[],canvases=[],observers=[],pageObservers=[];let next=0,imports=0,active=0,maxActive=0;
  window.vcProductReports={assetBase:'https://shop.test/theme/assets/',wpNonce:'STALE_TEST_NONCE'};window.devicePixelRatio=2;
  const importWait=deferred(),pageWait=deferred(),pageReads=[];
  const pdfjs={GlobalWorkerOptions:{},getDocument(options){
    const load=deferred(),request={options,load,destroyed:0,promise:load.promise,destroy(){this.destroyed++;load.reject(new Error('destroyed'));return Promise.resolve();}};requests.push(request);
    const pdf={numPages,async getPage(number){pageReads.push(number);if(deferPage===true||deferPage===number)await pageWait.promise;const [width,height]=dimensions[(number-1)%dimensions.length];return {getViewport:({scale})=>({width:width*scale,height:height*scale}),cleanup(){},render({canvasContext,viewport}){
      const pending=deferred();active++;maxActive=Math.max(maxActive,active);canvases.push(canvasContext.canvas);
      let finished=false;const finish=(error)=>{if(finished)return;finished=true;active--;error?pending.reject(error):pending.resolve();};
      const render={number,viewport,canvas:canvasContext.canvas,promise:pending.promise,cancelled:0,cancel(){this.cancelled++;finish(new Error('cancelled'));},finish};renders.push(render);if(!deferRender)finish();return render;
    }};}};request.pdf=pdf;if(!deferLoad)load.resolve(pdf);return request;
  }};
  class Observer {constructor(fn){this.fn=fn;this.connected=false;observers.push(this);}observe(){this.connected=true;}disconnect(){this.connected=false;}}
  window.ResizeObserver=Observer;
  class PageObserver {constructor(fn){this.fn=fn;this.items=[];pageObservers.push(this);}observe(item){this.items.push(item);}disconnect(){this.items=[];}}
  if(lazy)window.IntersectionObserver=PageObserver;
  const globals={window,document,URL,location:{origin:'https://shop.test',href:'https://shop.test/coa-library/?coa_q=sample'},ResizeObserver:Observer,IntersectionObserver:PageObserver,getComputedStyle:()=>({paddingLeft:'12px',paddingRight:'12px'}),console,
    setTimeout:fn=>{const id=++next;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id),requestAnimationFrame:fn=>{const id=++next;frames.set(id,fn);return id;},cancelAnimationFrame:id=>frames.delete(id)};
  const context=vm.createContext(globals);if(missingResolvers)vm.runInContext('Promise.withResolvers = undefined',context);
  const script=new vm.Script(source,{filename:'product-dialog-report-viewer.js',importModuleDynamically:async specifier=>{
    if(specifier.endsWith('product-report-pdf-compat.mjs')){const m=new vm.SourceTextModule(fs.readFileSync(path.join(directory,'product-report-pdf-compat.mjs'),'utf8'),{context});await m.link(()=>{});await m.evaluate();return m;}
    imports++;if(deferImport)await importWait.promise;
    const m=new vm.SyntheticModule(['GlobalWorkerOptions','getDocument'],function(){this.setExport('GlobalWorkerOptions',pdfjs.GlobalWorkerOptions);this.setExport('getDocument',pdfjs.getDocument);},{context});await m.link(()=>{});await m.evaluate();return m;
  }});script.runInContext(context);
  const api=window.createProductReportViewer({element});
  return {window,element,pages,status,retry,zoom,out,inside,timers,frames,requests,renders,canvases,observers,pageObservers,pdfjs,api,context,importWait,pageWait,pageReads,get imports(){return imports;},get maxActive(){return maxActive;},async frame(){const list=[...frames.values()];frames.clear();list.forEach(fn=>fn());await settle();},resize(width){pages.clientWidth=width;observers[0].fn();},async start(pdf='/wp-json/peptide/v1/report-library/7/file?download=0'){await api.open({pdf,title:'Actual boundary title'});await this.frame();}};
}
test('fit width subtracts actual pane padding, preserves each page ratio and uses DPR independently',async()=>{
  const f=await fixture({numPages:2,dimensions:[[612,792],[792,612]]});await f.start();
  assert.equal(f.pages.children.length,2);assert.equal(f.pages.children[0].style.width,'319px');assert.equal(f.renders[0].viewport.width,638);assert.equal(f.renders[1].viewport.width,638);
  assert.ok(Math.abs(f.renders[0].viewport.height/638-792/612)<1e-9);assert.ok(Math.abs(f.renders[1].viewport.height/638-612/792)<1e-9);assert.equal(f.maxActive,1);f.api.dispose();
  assert.equal(f.requests[0].options.rangeChunkSize,524288);assert.equal(f.requests[0].options.disableAutoFetch,true);assert.equal(f.requests[0].options.disableStream,true);
});
test('resize and zoom repaint an existing PDF without another document request; fit clears horizontal pan',async()=>{
  const f=await fixture();await f.start();const first=f.canvases[0];f.resize(414);await f.frame();assert.equal(f.requests.length,1);assert.equal(f.pages.children[0].style.width,'390px');assert.equal(first.width,0);
  f.inside.fire('click');await f.frame();assert.equal(f.zoom.textContent,'150%');assert.equal(f.pages.children[0].style.width,'585px');f.pages.scrollLeft=60;f.out.fire('click');await f.frame();assert.equal(f.pages.scrollLeft,0);assert.equal(f.pages.children[0].style.width,'390px');assert.equal(f.requests.length,1);f.api.dispose();
});
test('exact public archive paths omit stale nonce; protected, lookalike and query URLs retain their nonce',async()=>{
  const f=await fixture();
  for(const [url,publicFile]of [['/wp-json/peptide/v1/report-library/7/file?download=0',true],['/?rest_route=%2Fpeptide%2Fv1%2Freport-library%2F7%2Ffile&download=0',true],['/wp-json/peptide/v1/reports/7/file?_wpnonce=ORIGINAL',false],['/wp-json/peptide/v1/report-library/7/file/extra',false],['/private/peptide/v1/report-library/7/file',false]]){
    await f.start(url);const options=f.requests.at(-1).options;assert.equal(options.httpHeaders['X-WP-Nonce'],publicFile?undefined:'STALE_TEST_NONCE');assert.equal(options.url,new URL(url,'https://shop.test').href);
  }assert.equal(f.imports,1);f.api.dispose();
});
test('configured WordPress REST base supports subdirectory installs without exempting lookalike paths from nonce',async()=>{
  const f=await fixture();f.window.vcProductReports.reportLibraryBase='https://shop.test/store/custom-rest/peptide/v1/report-library/';await f.start('/store/custom-rest/peptide/v1/report-library/7/file?download=0');assert.equal(f.requests[0].options.httpHeaders['X-WP-Nonce'],undefined);await f.start('/other/peptide/v1/report-library/7/file');assert.equal(f.requests[1].options.httpHeaders['X-WP-Nonce'],'STALE_TEST_NONCE');f.api.dispose();
});
test('close during module loading cancels its timer and cannot start a late request',async()=>{
  const f=await fixture({deferImport:true});const pending=f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();f.api.dispose();assert.equal(f.timers.size,0);f.importWait.resolve();await pending;await f.frame();assert.equal(f.requests.length,0);assert.equal(f.pages.children.length,0);assert.equal(f.observers[0].connected,false);
});
test('slow load timeout retires its generation, aborts once and Retry starts a clean request',async()=>{
  const f=await fixture({deferLoad:true});const pending=f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();assert.equal(f.requests.length,1);[...f.timers.values()][0]();await pending;assert.equal(f.requests[0].destroyed,1);assert.equal(f.element.dataset.state,'error');assert.equal(f.retry.hidden,false);assert.equal(f.timers.size,0);
  f.retry.fire('click');await settle();assert.equal(f.requests.length,2);f.requests[1].load.resolve(f.requests[1].pdf);await settle();await f.frame();assert.equal(f.element.dataset.state,'ready');f.api.dispose();assert.equal(f.timers.size,0);
});
test('slow module import is covered by the deadline and its late completion cannot issue a document request',async()=>{
  const f=await fixture({deferImport:true});const pending=f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();[...f.timers.values()][0]();f.importWait.resolve();await pending;assert.equal(f.requests.length,0);assert.equal(f.element.dataset.state,'error');assert.equal(f.retry.hidden,false);assert.equal(f.timers.size,0);f.api.dispose();
});
test('resolved PDF with hung first-page geometry retains deadline, destroys its owner and exposes Retry',async()=>{
  const f=await fixture({deferPage:true});const pending=f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();assert.equal(f.requests.length,1);assert.ok(f.timers.size>0);[...f.timers.values()][0]();await pending;assert.equal(f.requests[0].destroyed,1);assert.equal(f.element.dataset.state,'error');assert.equal(f.retry.hidden,false);assert.equal(f.timers.size,0);f.pageWait.resolve();await settle();assert.equal(f.renders.length,0);f.api.dispose();
});
test('early zoom frame while first-page geometry is pending cannot mark an empty document ready',async()=>{
  const f=await fixture({deferPage:true});const pending=f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();await f.frame();
  assert.equal(f.pages.children.length,0);assert.equal(f.pages.querySelectorAll('canvas').length,0);assert.equal(f.element.dataset.state,'loading');assert.equal(f.element.attrs['aria-busy'],'true');assert.equal(f.status.textContent,'Loading document…');assert.ok(f.timers.size>0);
  f.inside.fire('click');await f.frame();assert.equal(f.element.dataset.state,'loading');assert.equal(f.element.attrs['aria-busy'],'true');
  f.pageWait.resolve();await pending;await f.frame();assert.equal(f.element.dataset.state,'ready');assert.equal(f.pages.querySelectorAll('canvas').length,1);assert.equal(f.element.attrs['aria-busy'],undefined);f.api.dispose();
});
test('first render and later geometry stage each have a deadline after the PDF document resolves',async()=>{
  const first=await fixture({deferRender:true});await first.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await first.frame();assert.ok(first.timers.size>0);[...first.timers.values()][0]();await settle();assert.equal(first.element.dataset.state,'error');assert.equal(first.renders[0].cancelled,1);assert.equal(first.timers.size,0);first.api.dispose();
  const later=await fixture({numPages:2,deferPage:2});const pending=later.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await settle();await later.frame();assert.equal(later.pages.querySelectorAll('canvas').length,1);assert.ok(later.timers.size>0);[...later.timers.values()][0]();await pending;assert.equal(later.element.dataset.state,'error');assert.equal(later.pages.querySelectorAll('canvas').length,1);assert.equal(later.retry.hidden,false);assert.equal(later.timers.size,0);later.api.dispose();
});
test('resize cancels an in-flight page once and only its newest viewport becomes visible',async()=>{
  const f=await fixture({deferRender:true});await f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await f.frame();assert.equal(f.renders.length,1);f.resize(414);await f.frame();await settle();assert.equal(f.renders[0].cancelled,1);assert.equal(f.renders.length,2);assert.equal(f.renders[0].canvas.width,0);f.renders[1].finish();await settle();assert.equal(f.pages.children[0].style.width,'390px');assert.equal(f.maxActive,1);f.api.dispose();
});
test('close/reopen retires old render ownership and cannot append an old page to the new document',async()=>{
  const f=await fixture({deferRender:true});await f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await f.frame();const old=f.renders[0];f.api.dispose();await f.api.open({pdf:'/wp-json/peptide/v1/report-library/8/file'});await f.frame();assert.equal(old.cancelled,1);assert.equal(f.requests[0].destroyed,1);assert.equal(old.canvas.width,0);assert.equal(f.renders.length,2);f.renders[1].finish();await settle();assert.equal(f.pages.children.length,1);assert.equal(f.requests[1].destroyed,0);f.api.dispose();assert.equal(f.canvases.every(canvas=>canvas.width===0&&canvas.height===0),true);
});
test('large synthetic document fallback has sequential rendering and bounded retained bitmap allocation',async()=>{
  const f=await fixture({numPages:100,dimensions:[[2000,6000]]});f.pages.clientWidth=2000;await f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});for(let n=0;n<20;n++)await f.frame();
  assert.equal(f.pages.children.length,100);assert.equal(f.maxActive,1);const pixels=f.canvases.reduce((sum,canvas)=>sum+canvas.width*canvas.height,0);assert.ok(pixels<12150000,`retained pixels ${pixels}`);assert.equal(f.requests.length,1);f.api.dispose();assert.equal(f.canvases.reduce((sum,canvas)=>sum+canvas.width*canvas.height,0),0);
});
test('render failure retains completed pages and offers explicit retry without a blank ready state',async()=>{
  const f=await fixture({numPages:2,deferRender:true});await f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await f.frame();f.renders[0].finish();await settle();f.renders[1].finish(new Error('render failure'));await settle();assert.equal(f.element.dataset.state,'error');assert.equal(f.pages.querySelectorAll('canvas').length,1);assert.equal(f.retry.hidden,false);f.api.dispose();
});
test('viewport observer renders one initial page of 100, evicts offscreen canvas and renders only the newly visible page',async t=>{
  const f=await fixture({numPages:100,dimensions:[[2000,6000]],lazy:true});f.pages.clientWidth=2000;await f.start();assert.equal(f.pages.children.length,100);assert.equal(f.renders.length,1);assert.equal(f.pages.querySelectorAll('canvas').length,1);
  assert.deepEqual([...new Set(f.pageReads)],[1], 'unseen pages do not request PDF geometry during open');
  const first=f.canvases[0];f.pageObservers[0].fn([{target:f.pages.children[0],isIntersecting:false},{target:f.pages.children[74],isIntersecting:true}]);await f.frame();assert.equal(first.width,0);assert.equal(f.renders.length,2);assert.equal(f.renders[1].number,75);assert.equal(f.pages.querySelectorAll('canvas').length,1);assert.equal(f.requests.length,1);assert.equal(f.maxActive,1);
  t.diagnostic(JSON.stringify({synthetic:true,pages:100,initialRenderCount:1,afterScrollRenderCount:2,documentRequests:1,maxConcurrentRender:1,retainedCanvasCount:1,retainedPixels:f.canvases.reduce((sum,c)=>sum+c.width*c.height,0)}));f.api.dispose();assert.equal(f.pageObservers[0].items.length,0);
});
test('scrolling beyond a pending page cancels its raster and paints only the new viewport page',async()=>{
  const f=await fixture({numPages:3,lazy:true,deferRender:true});await f.api.open({pdf:'/wp-json/peptide/v1/report-library/7/file'});await f.frame();const first=f.renders[0];f.pageObservers[0].fn([{target:f.pages.children[0],isIntersecting:false},{target:f.pages.children[2],isIntersecting:true}]);await f.frame();assert.equal(first.cancelled,1);assert.equal(first.canvas.width,0);assert.equal(f.renders.length,2);assert.equal(f.renders[1].number,3);f.renders[1].finish();await settle();assert.equal(f.pages.querySelectorAll('canvas').length,1);assert.equal(f.maxActive,1);f.api.dispose();
});
test('unseen landscape geometry is deferred until scrolling and retains its actual fit ratio',async()=>{
  const f=await fixture({numPages:2,dimensions:[[612,792],[792,612]],lazy:true,deferPage:2});await f.start();
  assert.equal(f.element.dataset.state,'ready');assert.deepEqual([...new Set(f.pageReads)],[1]);
  f.pageObservers[0].fn([{target:f.pages.children[0],isIntersecting:false},{target:f.pages.children[1],isIntersecting:true}]);await f.frame();
  assert.equal(f.renders.length,1);f.pageWait.resolve();await settle();
  assert.equal(f.renders.at(-1).number,2);assert.equal(f.pages.children[1].querySelector('.pdf-page-placeholder').style.aspectRatio,'792 / 612');
  assert.ok(Math.abs(f.renders.at(-1).viewport.height/f.renders.at(-1).viewport.width-612/792)<1e-9);f.api.dispose();
});

test('queued old observer entries cannot evict a reopened document or schedule work after dispose',async()=>{
  const f=await fixture({lazy:true});await f.start();const old=f.pages.children[0];await f.start('/wp-json/peptide/v1/report-library/8/file');f.pageObservers[0].fn([{target:old,isIntersecting:false}]);await f.frame();assert.equal(f.pages.querySelectorAll('canvas').length,1);f.api.dispose();f.resize(600);assert.equal(f.frames.size,0);
});
test('withResolvers shim runs before main import and supports native preservation/subclasses',async()=>{
  const f=await fixture({missingResolvers:true});await f.start();assert.equal(vm.runInContext('typeof Promise.withResolvers',f.context),'function');assert.equal(await vm.runInContext('class P extends Promise {}; const x=P.withResolvers(); x.resolve(9); x.promise',f.context),9);assert.equal(vm.runInContext('x.promise instanceof P',f.context),true);f.api.dispose();
});
test('genuine unchanged upstream worker imports after shim in a separate realm missing withResolvers',()=>{
  const target=require('node:url').pathToFileURL(path.join(directory,'product-report-pdf-worker.mjs')).href;
  const code=`delete Promise.withResolvers; const worker=await import(${JSON.stringify(target)}); if(typeof Promise.withResolvers!=='function'||!worker.WorkerMessageHandler)throw Error('worker bootstrap missing'); console.log('worker bootstrap ready');`;
  const result=spawnSync(process.execPath,['--input-type=module','-e',code],{encoding:'utf8'});assert.equal(result.status,0,result.stderr);assert.match(result.stdout,/worker bootstrap ready/);
});
