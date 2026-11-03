'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-dialog-experience.js'),'utf8');
function fixture(){
  const frames=new Map(),closes=[];let sequence=0;
  const target=()=>({listeners:new Map(),addEventListener(type,fn,options){const list=this.listeners.get(type)||[];list.push({fn,once:options?.once});this.listeners.set(type,list);},fire(type,extra={}){const event={target:this,detail:1,preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;},...extra};for(const row of [...(this.listeners.get(type)||[])]){row.fn(event);if(row.once)this.listeners.set(type,this.listeners.get(type).filter(item=>item!==row));if(event.stopped)break;}return event;}});
  const document=Object.assign(target(),{hidden:false,activeElement:null});
  const element=(tag='div')=>Object.assign(target(),{tagName:tag,children:[],attrs:{},dataset:{},hidden:false,disabled:false,open:false,scrollTop:0,scrollLeft:0,clientTop:0,clientHeight:400,scrollHeight:1600,isConnected:true,rect:{top:0,left:0,height:60},classList:{values:new Set(),toggle(key,on){on?this.values.add(key):this.values.delete(key);},contains(key){return this.values.has(key);}},append(...items){this.children.push(...items);},prepend(...items){this.children.unshift(...items);},replaceChildren(...items){this.rebuilds=(this.rebuilds||0)+1;this.children=[...items];},setAttribute(key,value){this.attrs[key]=String(value);},getAttribute(key){return this.attrs[key]??null;},hasAttribute(key){return Object.hasOwn(this.attrs,key);},removeAttribute(key){delete this.attrs[key];},getBoundingClientRect(){return this.rect;},focus(options){document.activeElement=this;this.focusOptions=options;},scrollTo(options){this.scrolled=options;},showModal(){this.open=true;},close(){this.open=false;closes.push(()=>this.fire('close'));},remove(){this.isConnected=false;}});
  document.createElement=element;document.body=element('body');
  const window=target(),reduced={matches:false};
  const context=vm.createContext({window,document,URL,location:{href:'https://shop.test/',origin:'https://shop.test'},matchMedia:()=>reduced,requestAnimationFrame(fn){const id=++sequence;frames.set(id,fn);return id;},cancelAnimationFrame(id){frames.delete(id);},console});
  const hooked=source.replace('  window.vcProductReportMatch =','  window.testHooks = {safeURL,createChildDialogs,createSectionNavigation,reportPresentationKey};\n  window.vcProductReportMatch =');
  vm.runInContext(hooked,context);
  return {context,document,window,element,reduced,frames,closes,hooks:window.testHooks,flush(){for(const [id,fn]of [...frames]){frames.delete(id);fn();}}};
}
test('absent, empty and external report/image URLs cannot become local requests',()=>{
  const f=fixture();for(const value of [undefined,null,'','  ',7,{},'https://other.test/report.pdf','javascript:alert(1)','https://user:pass@shop.test/report.pdf'])assert.equal(f.hooks.safeURL(value),'');
  assert.equal(f.hooks.safeURL('/report.pdf?download=0'),'https://shop.test/report.pdf?download=0');assert.equal(f.hooks.safeURL('https://shop.test/image.webp'),'https://shop.test/image.webp');
});
test('queued old PDF close disposes its owner once without touching the new viewer',()=>{
  const f=fixture(),children=f.hooks.createChildDialogs(7);let aDisposed=0,bDisposed=0;
  const a=children.create('report','A');a.showModal();children.attach(a,{dispose(){aDisposed++;}});
  const b=children.create('report','B');b.showModal();children.attach(b,{dispose(){bDisposed++;}});
  assert.equal(aDisposed,1);assert.equal(bDisposed,0);f.closes.shift()();
  assert.equal(aDisposed,1);assert.equal(bDisposed,0);assert.equal(b.open,true);
  a.children[0].fire('click');assert.equal(b.open,true,'old close button is bound to old dialog');
  children.destroy();assert.equal(bDisposed,1);f.closes.shift()();assert.equal(bDisposed,1);
});
function navFixture(){
  const f=fixture(),dialog=f.element('dialog'),toolbar=f.element();dialog.open=true;dialog.rect.top=20;toolbar.rect.height=96;
  const sections=['overview','details','coa'].map((key,index)=>({key,target:f.element('section'),button:f.element('button')}));
  sections.forEach((item,index)=>{item.target.rect.top=120+index*500;});
  const nav=f.hooks.createSectionNavigation(dialog,toolbar,sections,new AbortController().signal);f.flush();return {...f,dialog,toolbar,sections,nav};
}
test('section navigation uses actual toolbar height and changes current section with real scrolling',()=>{
  const f=navFixture();assert.equal(f.sections[0].button.getAttribute('aria-current'),'location');
  f.nav.go('details');assert.equal(f.dialog.scrolled.top,496);assert.equal(f.dialog.scrolled.behavior,'smooth');
  assert.equal(f.document.activeElement,f.sections[1].target);assert.equal(f.sections[1].target.getAttribute('tabindex'),'-1');
  f.dialog.scrollTop=500;f.sections.forEach((item,index)=>{item.target.rect.top=-380+index*500;});f.dialog.fire('scroll');f.flush();
  assert.equal(f.sections[1].button.getAttribute('aria-current'),'location');assert.equal(f.sections[0].button.getAttribute('aria-current'),null);
  f.toolbar.rect.height=64;f.reduced.matches=true;f.nav.go('coa');assert.equal(f.dialog.scrolled.top,1028);assert.equal(f.dialog.scrolled.behavior,'instant');
});
test('section navigation coalesces scroll work, skips hidden COA and cancels on destroy/hidden',()=>{
  const f=navFixture();f.sections[2].target.hidden=true;f.sections[2].button.hidden=true;
  f.dialog.scrollTop=1200;f.dialog.fire('scroll');f.dialog.fire('scroll');assert.equal(f.frames.size,1);f.flush();
  assert.equal(f.sections[1].button.getAttribute('aria-current'),'location');
  f.dialog.fire('scroll');f.document.hidden=true;f.document.fire('visibilitychange');assert.equal(f.frames.size,0);
  f.document.hidden=false;f.document.fire('visibilitychange');assert.equal(f.frames.size,1);f.nav.destroy();assert.equal(f.frames.size,0);
  f.dialog.fire('scroll');assert.equal(f.frames.size,0);
});
function loadLocalFunction(f,name,next){vm.runInContext(source.slice(source.indexOf('    function '+name+'('),source.indexOf(next,source.indexOf('    function '+name+'('))),f.context);}
test('identical selected image keeps its src while a changed specification replaces it once',()=>{
  const f=fixture(),img=f.element('img');let writes=0;Object.defineProperty(img,'src',{set(value){writes++;this.attrs.src=value;}});
  Object.assign(f.context,{safeURL:value=>value||'',productImage:{src:'https://shop.test/tr20.webp',alt:'20mg'},productImg:img,emptyImage:f.element(),productSlide:f.element(),knownImages:new Map(),productName:'Tirzepatide',summary:{querySelector(){return null;}},ribbon:f.element(),caption:f.element(),selectedLabel:()=> '20mg'});
  loadLocalFunction(f,'renderProduct','    function renderReport(');
  vm.runInContext('renderProduct();renderProduct();',f.context);assert.equal(writes,1);
  f.context.productImage={src:'https://shop.test/tr10.webp',alt:'10mg'};vm.runInContext('renderProduct();renderProduct();',f.context);assert.equal(writes,2);assert.equal(img.alt,'10mg');
});
test('authoritative responsive image attributes precede src, avoid duplicate writes and clear on the next specification',()=>{
  const f=fixture(),img=f.element('img'),writes=[];
  img.setAttribute=(key,value)=>{writes.push(key);img.attrs[key]=String(value);};img.removeAttribute=key=>{writes.push('remove:'+key);delete img.attrs[key];};
  Object.defineProperty(img,'src',{set(value){writes.push('src');img.attrs.src=value;}});
  Object.assign(f.context,{safeURL:value=>value||'',productImage:{src:'https://shop.test/tr20.webp',srcset:'https://shop.test/tr20-small.webp 400w, https://shop.test/tr20.webp 1000w',sizes:'(max-width: 767px) calc(100vw - 64px), 500px',width:1000,height:1200},productImg:img,emptyImage:f.element(),productSlide:f.element(),knownImages:new Map(),productName:'Tirzepatide',summary:{querySelector(){return null;}},ribbon:f.element(),caption:f.element(),selectedLabel:()=> '20mg'});
  loadLocalFunction(f,'renderProduct','    function renderReport(');
  vm.runInContext('renderProduct();renderProduct();',f.context);assert.equal(writes.join(','),'sizes,srcset,width,height,src');assert.equal(img.getAttribute('width'),'1000');assert.equal(img.getAttribute('height'),'1200');
  f.context.productImage={src:'https://shop.test/tr10.webp',width:0,height:null};vm.runInContext('renderProduct();renderProduct();',f.context);
  assert.equal(writes.slice(5).join(','),'remove:sizes,remove:srcset,remove:width,remove:height,src');assert.equal(img.hasAttribute('srcset'),false);assert.equal(img.hasAttribute('sizes'),false);assert.equal(img.hasAttribute('width'),false);assert.equal(img.hasAttribute('height'),false);
});
test('unchanged report preserves focused COA DOM; new date/file/variation still refreshes latest report',()=>{
  const f=fixture();let selection='20mg',variation='20',latest={id:1,product:{id:7},specification:'20mg',issued_on:'2026-10-01',view_url:'/1.pdf',download_url:'/1.pdf?download=1',thumbnail_url:'/1.webp',file:{mime:'application/pdf',size_bytes:100}};
  const reportTab=f.element(),documents=f.element(),reportSlide=f.element();let refreshes=0;
  Object.assign(f.context,{settings:{reportGalleryEnabled:true},report:null,reports:[],productId:7,productName:'Tirzepatide',reportSignature:'',latestReport:()=>latest,reportPresentationKey:f.hooks.reportPresentationKey,selectedLabel:()=>selection,root:{querySelector(){return {value:variation};}},reportTab,documents,reportSlide,navigation:{refresh(){refreshes++;}},safeURL:value=>value||'',date:value=>value||'',node:f.document.createElement,button:(cls,label,action)=>{const n=f.element('button');n.className=cls;n.textContent=label;n.addEventListener('click',action);return n;},track:f.element(),openReport(){}});
  loadLocalFunction(f,'renderReport','    async function readReports(');
  vm.runInContext('renderReport()',f.context);const art=documents.children[0],copy=documents.children[1],opener=copy.children[copy.children.length-1];opener.focus();
  latest={...latest};vm.runInContext('renderReport()',f.context);assert.equal(documents.rebuilds,1);assert.equal(documents.children[0],art);assert.equal(documents.children[1],copy);assert.equal(f.document.activeElement,opener);assert.equal(f.context.report,latest);
  latest={...latest,issued_on:'2026-11-01',view_url:'/2.pdf',file:{...latest.file,size_bytes:200}};vm.runInContext('renderReport()',f.context);assert.equal(documents.rebuilds,2);assert.equal(f.context.report.view_url,'/2.pdf');
  variation='21';vm.runInContext('renderReport()',f.context);assert.equal(documents.rebuilds,3);
  latest=null;vm.runInContext('renderReport()',f.context);assert.equal(documents.hidden,true);assert.equal(reportTab.hidden,true);assert.equal(reportSlide.hidden,true);assert.equal(documents.children.length,0);assert.equal(refreshes,4);
});
test('report key includes exact selection, identity, date, file and URL changes',()=>{
  const f=fixture(),base={id:1,product:{id:7},issued_on:'2026-11-01',view_url:'/1.pdf',download_url:'/1.pdf?download=1',thumbnail_url:'/1.webp',file:{mime:'application/pdf',size_bytes:100}};
  const key=f.hooks.reportPresentationKey(base,'20mg','20');
  for(const change of [{id:2},{product:{id:8}},{issued_on:'2026-11-02'},{view_url:'/2.pdf'},{download_url:'/2.pdf?download=1'},{thumbnail_url:'/2.webp'},{file:{mime:'application/pdf',size_bytes:101}}])assert.notEqual(f.hooks.reportPresentationKey({...base,...change},'20mg','20'),key);
  assert.notEqual(f.hooks.reportPresentationKey(base,'10mg','20'),key);assert.notEqual(f.hooks.reportPresentationKey(base,'20mg','21'),key);
});
function galleryFixture(){
  const f=fixture(),track=f.element(),productSlide=f.element('button'),reportSlide=f.element('button');productSlide.rect.left=0;reportSlide.rect.left=390;track.rect.left=0;
  Object.assign(f.context,{track,productSlide,reportSlide,signal:new AbortController().signal});
  const start=source.indexOf('    let pointer = null, dragged = false;');const end=source.indexOf("    document.addEventListener('visibilitychange',()=>{if(!document.hidden && !reportRequest",start);
  vm.runInContext(source.slice(start,end),f.context);return {...f,track,productSlide,reportSlide};
}
test('gallery arrows move focus to the visible report and preserve reduced-motion scrolling',()=>{
  const f=galleryFixture();f.productSlide.focus();f.track.fire('keydown',{key:'ArrowRight'});assert.equal(f.document.activeElement,f.reportSlide);assert.equal(f.track.scrolled.left,390);
  f.reduced.matches=true;f.track.fire('keydown',{key:'ArrowLeft'});assert.equal(f.document.activeElement,f.productSlide);assert.equal(f.track.scrolled.behavior,'instant');
  f.reportSlide.hidden=true;f.track.fire('keydown',{key:'ArrowRight'});assert.equal(f.document.activeElement,f.productSlide);
});
test('cancelled, vertical and other-pointer gestures do not eat clicks; actual horizontal swipe suppresses one pointer click',()=>{
  const f=galleryFixture();const down=()=>f.track.fire('pointerdown',{isPrimary:true,button:0,pointerId:1,clientX:0,clientY:0});
  down();f.track.fire('pointermove',{pointerId:1,clientX:20,clientY:70});assert.equal(f.track.fire('click').prevented,undefined);
  down();f.track.fire('pointermove',{pointerId:2,clientX:50,clientY:0});assert.equal(f.track.fire('click').prevented,undefined);
  down();f.track.fire('pointermove',{pointerId:1,clientX:50,clientY:0});f.track.fire('pointercancel',{pointerId:1});assert.equal(f.track.fire('click').prevented,undefined);
  down();f.track.fire('pointermove',{pointerId:1,clientX:50,clientY:0});f.track.fire('pointerup',{pointerId:1});f.track.fire('lostpointercapture',{pointerId:1});assert.equal(f.track.fire('click').prevented,true);assert.equal(f.track.fire('click').prevented,undefined);
  down();f.track.fire('pointermove',{pointerId:1,clientX:50,clientY:0});assert.equal(f.track.fire('click',{detail:0}).prevented,undefined);
});
