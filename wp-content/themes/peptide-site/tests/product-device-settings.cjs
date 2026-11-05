'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {setMaxListeners}=require('node:events');
const directory=path.join(__dirname,'../assets');
const source=fs.readFileSync(path.join(directory,'product-dialog-experience.js'),'utf8');
const base={enabled:true,mobileDialog:true,reportGalleryEnabled:false,stickyPurchaseEnabled:true,broadcastEnabled:false,whatsappEnabled:false,visibility:{desktop:true,mobile:true}};
function fixture(config={devices:{desktop:base,mobile:base}},mobile=false) {
 const frames=new Map(),timers=new Map(),requests=[],disposed=[],tickerOwners=[];let next=0;
 class Target {
  constructor(){this.listeners=new Map();}
  addEventListener(type,fn,options={}) {
   if(options.signal?.aborted)return;
   const row={fn,once:options.once};this.listeners.set(type,[...(this.listeners.get(type)||[]),row]);
   options.signal?.addEventListener('abort',()=>this.removeEventListener(type,fn),{once:true});
  }
  removeEventListener(type,fn){this.listeners.set(type,(this.listeners.get(type)||[]).filter(row=>row.fn!==fn));}
  dispatchEvent(event){if(!event.target)event.target=this;for(const row of [...(this.listeners.get(event.type)||[])]){row.fn(event);if(row.once)this.removeEventListener(event.type,row.fn);}return true;}
  fire(type,extra={}){return this.dispatchEvent({type,...extra});}
 }
 let document;
 class Element extends Target {
  constructor(tag){super();this.tagName=tag;this.parentNode=null;this.children=[];this.attrs={};this.dataset={};this.classes=new Set();this._text='';this.hidden=false;this.disabled=false;this.inert=false;this.open=false;this.scrollLeft=0;this.scrollTop=0;this.clientHeight=400;this.scrollHeight=1600;this.clientTop=0;this.values=new Map();
   this.classList={add:(...keys)=>keys.forEach(key=>this.classes.add(key)),remove:(...keys)=>keys.forEach(key=>this.classes.delete(key)),contains:key=>this.classes.has(key),toggle:(key,on)=>{if(on===undefined)on=!this.classes.has(key);on?this.classes.add(key):this.classes.delete(key);return on;}};
   this.style={getPropertyValue:key=>this.values.get(key)?.value||'',getPropertyPriority:key=>this.values.get(key)?.priority||'',setProperty:(key,value,priority='')=>this.values.set(key,{value,priority}),removeProperty:key=>this.values.delete(key)};
  }
  set className(value){this.classes=new Set(value.split(/\s+/).filter(Boolean));}get className(){return [...this.classes].join(' ');}
  get childNodes(){return this.children;}get parentElement(){return this.parentNode;}get lastElementChild(){return this.children.at(-1)||null;}get firstElementChild(){return this.children[0]||null;}
  get isConnected(){return this===document.body||!!this.parentNode?.isConnected;}
  get textContent(){return this._text+this.children.map(child=>child.textContent).join('');}set textContent(value){this.replaceChildren();this._text=String(value);}
  set innerHTML(value){this.replaceChildren();this._text=String(value);}get innerHTML(){return this._text;}
  getAttribute(key){if(key==='class')return this.className||null;return this.attrs[key]??null;}
  setAttribute(key,value){if(key==='class')this.className=String(value);else this.attrs[key]=String(value);}
  removeAttribute(key){if(key==='class')this.className='';else delete this.attrs[key];}
  getAttributeNames(){return [...Object.keys(this.attrs),...(this.className?['class']:[])];}hasAttribute(key){return this.getAttribute(key)!==null;}
  set src(value){this.attrs.src=value;}get src(){return this.attrs.src||'';}
  matches(selector){
   if(selector.includes(','))return selector.split(',').some(part=>this.matches(part.trim()));
   const attrs=[...selector.matchAll(/\[([^=\]]+)(?:="([^"]*)")?\]/g)];
   let rest=selector.replace(/\[[^\]]+\]/g,'');const tag=rest.match(/^[\w#-]+/)?.[0];if(tag&&tag.toLowerCase()!==this.tagName.toLowerCase())return false;
   for(const key of rest.matchAll(/\.([\w-]+)/g))if(!this.classes.has(key[1]))return false;
   for(const [,key,value]of attrs){let actual=this.getAttribute(key);if(actual===null&&key.startsWith('data-'))actual=this.dataset[key.slice(5).replace(/-([a-z])/g,(_,x)=>x.toUpperCase())];if(key==='open'&&this.open)actual='';if(actual===null||actual===undefined||(value!==undefined&&String(actual)!==value))return false;}
   return true;
  }
  closest(selector){for(let n=this;n;n=n.parentNode)if(n.matches(selector))return n;return null;}
  contains(node){return node===this||this.children.some(child=>child.contains(node));}
  append(...nodes){for(const n of nodes){n.remove();n.parentNode=this;this.children.push(n);}}
  prepend(...nodes){for(const n of [...nodes].reverse()){n.remove();n.parentNode=this;this.children.unshift(n);}}
  before(n){if(!this.parentNode)return;const p=this.parentNode;n.remove();p.children.splice(p.children.indexOf(this),0,n);n.parentNode=p;}
  after(n){if(!this.parentNode)return;const p=this.parentNode;n.remove();p.children.splice(p.children.indexOf(this)+1,0,n);n.parentNode=p;}
  replaceWith(n){if(!this.parentNode)return;const p=this.parentNode;n.remove();const i=p.children.indexOf(this);p.children.splice(i,1,n);n.parentNode=p;this.parentNode=null;}
  remove(){if(this.parentNode){if(this.contains(document.activeElement))document.activeElement=document.body;this.parentNode.children.splice(this.parentNode.children.indexOf(this),1);this.parentNode=null;}}
  replaceChildren(...nodes){for(const n of [...this.children])n.remove();this._text='';this.append(...nodes);}
  querySelectorAll(selector){
   if(selector.startsWith(':scope > '))return this.children.filter(n=>n.matches(selector.slice(9)));
   const parts=selector.split(/\s+/),found=[];
   const walk=n=>{for(const child of n.children){if(child.matches(parts.at(-1))){let p=child.parentNode,index=parts.length-2;while(index>=0&&p){if(p.matches(parts[index]))index--;p=p.parentNode;}if(index<0)found.push(child);}walk(child);}};walk(this);return found;
  }
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  focus(options){document.activeElement=this;this.focusOptions=options;}scrollTo(options){Object.assign(this,options.left===undefined?{scrollTop:options.top}:{scrollLeft:options.left});}
  getBoundingClientRect(){return {height:120,width:390,top:0,left:0};}showModal(){this.open=true;}close(){this.open=false;this.fire('close');}
 }
 document=Object.assign(new Target(),{hidden:false,activeElement:null,createElement:tag=>new Element(tag),createComment:()=>new Element('#comment')});document.body=new Element('body');document.activeElement=document.body;
 document.querySelectorAll=selector=>document.body.querySelectorAll(selector);document.querySelector=selector=>document.body.querySelector(selector);
 const query=Object.assign(new Target(),{matches:mobile});const reduced=Object.assign(new Target(),{matches:false});
 const window=new Target();window.matchMedia=selector=>selector.includes('820')?query:reduced;
 window.createAnnouncementTicker=({element,config})=>{element.hidden=!config.enabled;const owner={destroyed:false,destroy(){this.destroyed=true;},configure(){},updateContext(){}};tickerOwners.push(owner);return owner;};
 window.createVcConfirmedPurchaseReturn=()=>({begin(){return{};},confirm(){},cancel(){},destroy(){}});
 window.createVcNativeProductFeedback=()=>({state:'idle',sync(){},cancel(){},destroy(){},configure(){}});
 const dialog=new Element('dialog');dialog.open=true;const toolbar=new Element('nav');toolbar.className='vc-home-quick__toolbar';const close=new Element('button');close.className='vc-showcase-quick__close';toolbar.append(close);
 const root=new Element('article');root.dataset={vcExperience:JSON.stringify(config),vcHomeInitial:JSON.stringify({image:{src:'/product.webp',alt:'Product'},attributes:{},promotions:[]}),vcProductId:'7',vcProductName:'Product'};
 const media=new Element('div');media.className='vc-showcase-quick__media';const originalImg=new Element('img');originalImg.src='https://shop.test/product.webp';media.append(originalImg);
 const summary=new Element('div');summary.className='summary';const eyebrow=new Element('p');eyebrow.className='vc-home-quick__eyebrow';const title=new Element('h2');title.className='vc-showcase-quick__title';title.textContent='Product';const price=new Element('p');price.className='price';price.textContent='$55.00';
 const form=new Element('form');form.className='cart';const variations=new Element('table');variations.className='variations';const select=new Element('select');select.value='20mg';select.name='attribute_pack';select.selectedOptions=[{textContent:'20mg × 10 vials'}];variations.append(select);const wrap=new Element('div');wrap.className='single_variation_wrap';const native=new Element('div');native.className='woocommerce-variation-add-to-cart';const qty=new Element('div');qty.className='quantity';const input=new Element('input');input.className='qty';input.setAttribute('name','quantity');input.value='3';qty.append(input);const add=new Element('button');add.className='single_add_to_cart_button';const buy=new Element('button');buy.dataset.vcQuickBuy='';const variation=new Element('input');variation.className='variation_id';variation.setAttribute('name','variation_id');variation.value='71';native.append(qty,add,buy,variation);wrap.append(native);form.append(variations,wrap);summary.append(eyebrow,title,price,form);
 const content=new Element('section');content.className='vc-product-dialog-content';const description=new Element('details');description.dataset.vcDialogTab='description';const questions=new Element('div');questions.className='vc-product-dialog-questions';content.append(description,questions);root.append(media,summary,content);dialog.append(toolbar,root);document.body.append(dialog);
 window.vcHomeQuickView={cartUrl:'/cart/',reportApi:''};window.vcLiveCommerce={};document.addEventListener('vc:product-experience-disposed',e=>disposed.push(e.detail));
 class Controller extends AbortController{constructor(){super();setMaxListeners(0,this.signal);}}
 const context=vm.createContext({window,document,URL,AbortController:Controller,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},location:{href:'https://shop.test/',origin:'https://shop.test'},matchMedia:window.matchMedia,console,
  MutationObserver:class{observe(){}disconnect(){}},ResizeObserver:class{observe(){}disconnect(){}},requestAnimationFrame(fn){const id=++next;frames.set(id,fn);return id;},cancelAnimationFrame(id){frames.delete(id);},setInterval(fn){const id=++next;timers.set(id,fn);return id;},clearInterval(id){timers.delete(id);},setTimeout(fn){const id=++next;timers.set(id,fn);return id;},clearTimeout(id){timers.delete(id);},fetch(url,options){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});requests.push({url,options,resolve,reject});return promise;}});
 for(const file of ['product-purchase-dock.js','product-dialog-meta.js'])vm.runInContext(fs.readFileSync(path.join(directory,file),'utf8'),context);
 vm.runInContext(source,context);
 const api=window.createVcProductExperience(root,dialog);root.vcExperience=api;
 return {window,document,root,dialog,toolbar,close,media,summary,form,native,qty,input,add,buy,title,eyebrow,content,questions,originalImg,api,requests,disposed,tickerOwners,frames,timers,query,
  resize(value){query.matches=value;query.fire('change');},create:window.createVcProductExperience,resolve:window.vcProductDeviceSettings.resolve};
}
const settle=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};

test('current device row, not aggregate enabled, controls eligibility; legacy rows remain compatible',()=>{
 const f=fixture({enabled:true,mobileDialog:true,devices:{desktop:{...base,enabled:false},mobile:base}},false);
 assert.equal(f.root.classList.contains('vc-product-experience'),false);assert.equal(f.root.querySelectorAll('.vc-dialog-gallery').length,0);
 f.resize(true);assert.equal(f.root.classList.contains('vc-product-experience'),true);assert.equal(f.root.querySelectorAll('form.cart').length,1);f.api.destroy();
 assert.equal(f.resolve({...base,visibility:{desktop:false,mobile:true}},false).enabled,false);assert.equal(f.resolve(base,true).enabled,true);
 assert.equal(f.resolve({enabled:false,mobileDialog:false,devices:{desktop:base,mobile:base}},true).enabled,true,'aggregate cannot override a complete device row');
 assert.equal(f.resolve({devices:{desktop:base}},true).enabled,false,'incomplete new contract fails closed');
});
test('repeated device switching restores the original native form/buttons/title/media and focus without duplicates',()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,enabled:false}}});
 for(let i=0;i<4;i++){
  f.add.focus();f.resize(true);
  assert.equal(f.root.classList.contains('vc-product-experience'),false);assert.equal(f.dialog.classList.contains('vc-product-dialog'),false);
  assert.equal(f.title.parentNode,f.summary);assert.equal(f.eyebrow.parentNode,f.summary);assert.equal(f.questions.parentNode,f.content);assert.equal(f.add.parentNode,f.native);assert.equal(f.buy.parentNode,f.native);assert.equal(f.qty.parentNode,f.native);assert.equal(f.input.value,'3');
  assert.equal(f.root.querySelector('img'),f.originalImg);assert.equal(f.root.querySelectorAll('form.cart').length,1);assert.equal(f.root.querySelectorAll('.vc-purchase-dock').length,0);assert.equal(f.toolbar.children.length,1);assert.equal(f.document.activeElement,f.add);
  f.resize(false);assert.equal(f.root.querySelectorAll('.vc-dialog-gallery').length,1);assert.equal(f.root.querySelectorAll('.vc-purchase-dock').length,1);assert.equal(f.root.querySelectorAll('.vc-product-meta').length,1);assert.equal(f.form.querySelector('.single_add_to_cart_button'),f.add);assert.equal(f.document.activeElement,f.add);
 }
 assert.equal(f.create(f.root,f.dialog),f.api,'one outer owner per product');const count=f.disposed.length;f.api.destroy();f.api.destroy();assert.equal(f.disposed.length,count+1);assert.equal(f.timers.size,0);assert.equal(f.frames.size,0);
 f.resize(true);assert.equal(f.disposed.length,count+1,'destroy removes breakpoint listener');assert.equal(f.root.vcExperience,null);
});
test('device WhatsApp, dock and feature flags use the selected configuration, preserving native controls',()=>{
 const desktop={...base,whatsappEnabled:true,whatsappNumber:'15550000001',whatsappLabel:'Desk help',whatsappMessage:'Desktop {product}'};
 const mobile={...base,stickyPurchaseEnabled:false,whatsappEnabled:true,whatsappNumber:'15550000002',whatsappLabel:'Phone help',whatsappMessage:'Mobile {product}'};
 const f=fixture({devices:{desktop,mobile}});assert.equal(f.summary.querySelector('.vc-dialog-support').textContent,'Desk help');assert.match(f.summary.querySelector('.vc-dialog-support').href,/15550000001/);assert.equal(f.root.querySelectorAll('.vc-purchase-dock').length,1);
 f.resize(true);assert.equal(f.summary.querySelectorAll('.vc-dialog-support').length,1);assert.equal(f.summary.querySelector('.vc-dialog-support').textContent,'Phone help');assert.match(f.summary.querySelector('.vc-dialog-support').href,/15550000002/);assert.equal(f.root.querySelectorAll('.vc-purchase-dock').length,0);assert.equal(f.add.parentNode,f.native);assert.equal(f.buy.parentNode,f.native);
 f.resize(false);assert.equal(f.root.querySelectorAll('.vc-purchase-dock').length,1);f.api.destroy();assert.equal(f.summary.querySelector('.vc-dialog-support'),null);
});
test('late report and broadcast responses cannot revive the previous device after disabling their features',async()=>{
 const f=fixture({devices:{desktop:{...base,enabled:false},mobile:{...base,reportGalleryEnabled:true,broadcastEnabled:true}}});
 f.window.vcHomeQuickView.reportApi='/reports';f.window.vcLiveCommerce.broadcast={url:'/broadcast',config:{enabled:false}};f.resize(true);
 assert.equal(f.requests.length,2);const pendingOpen=f.api.openReport('/report.pdf');f.resize(false);assert.ok(f.requests.every(r=>r.options.signal.aborted));
 for(const request of f.requests)request.resolve({ok:true,json:async()=>request.url.includes('/reports')?{items:[{id:1,product:{id:7},specification:'20mg',issued_on:'2026-11-05',file:{mime:'application/pdf'},view_url:'/report.pdf',thumbnail_url:'/report.webp'}],total_pages:1,page:1,total:1}:{enabled:true,revision:1,items:['Real configured message']}});
 await settle();assert.equal(await pendingOpen,false);assert.equal(f.document.querySelectorAll('.vc-product-child-dialog').length,0);assert.equal(f.tickerOwners.length,0);assert.equal(f.root.querySelectorAll('.vc-dialog-documents').length,0);assert.equal(f.root.querySelectorAll('[data-vc-product-broadcast]').length,0);assert.equal(await f.api.openReport(),false);assert.equal(f.requests.length,2,'disabled device does not retry reads');f.api.destroy();
});
test('backend disabled broadcast stays disabled on a device that permits it; successful old ticker is disposed on switch',async()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,broadcastEnabled:true}}});f.window.vcLiveCommerce.broadcast={url:'/broadcast'};f.resize(true);f.requests[0].resolve({ok:true,json:async()=>({enabled:false,revision:1,items:['Unavailable']})});await settle();assert.equal(f.tickerOwners.length,0);assert.equal(f.root.querySelector('[data-vc-product-broadcast]').hidden,true);
 f.resize(false);f.resize(true);f.requests[1].resolve({ok:true,json:async()=>({enabled:true,revision:2,items:['Configured']})});await settle();assert.equal(f.tickerOwners.length,1);assert.equal(f.tickerOwners[0].destroyed,false);f.resize(false);assert.equal(f.tickerOwners[0].destroyed,true);assert.equal(f.root.querySelector('[data-vc-product-broadcast]').hidden,true);f.api.destroy();
});
test('identical inherited device settings do not remount; disabled native media follows the exact latest image',()=>{
 const f=fixture();const gallery=f.root.querySelector('.vc-dialog-gallery');f.resize(true);f.resize(false);f.api.update();assert.equal(f.root.querySelector('.vc-dialog-gallery'),gallery);assert.equal(f.disposed.length,0);f.api.destroy();
 const g=fixture({devices:{desktop:{...base,enabled:false},mobile:{...base,enabled:false}}});g.api.updateMedia({src:'/next.webp',alt:'Next',width:800,height:1000});assert.equal(g.media.querySelector('img'),g.originalImg);assert.equal(g.originalImg.src,'https://shop.test/next.webp');assert.equal(g.originalImg.alt,'Next');assert.equal(g.requests.length,0);g.api.destroy();
});
test('close restores one native root; cached reopen gets a fresh owner and removes all old media listeners',()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,enabled:false}}});const original=f.api;f.dialog.close();assert.equal(f.root.vcExperience,null);assert.equal(f.title.parentNode,f.summary);assert.equal(f.root.querySelectorAll('.vc-product-meta').length,0);assert.equal(f.root.querySelectorAll('form.cart').length,1);assert.equal(f.disposed.length,1);assert.equal(f.disposed[0].reason,'destroy');
 f.dialog.open=true;const reopened=f.create(f.root,f.dialog);f.root.vcExperience=reopened;assert.notEqual(reopened,original);assert.equal(f.root.querySelectorAll('.vc-dialog-gallery').length,1);f.resize(true);assert.equal(f.root.querySelectorAll('.vc-dialog-gallery').length,0);assert.equal(f.disposed.length,2);assert.equal(f.disposed[1].reason,'device');original.destroy();assert.equal(f.root.vcExperience,reopened);reopened.destroy();assert.equal(f.query.listeners.get('change').length,0);
});
test('pageshow and focus reconcile a missed media-query change without rebuilding an unchanged device',()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,enabled:false}}});const initial=f.root.querySelector('.vc-dialog-gallery');
 f.window.fire('pageshow',{persisted:true});f.window.fire('focus');assert.equal(f.root.querySelector('.vc-dialog-gallery'),initial);assert.equal(f.disposed.length,0);assert.equal(f.requests.length,0);
 f.query.matches=true;f.window.fire('pageshow',{persisted:true});assert.equal(f.root.classList.contains('vc-product-experience'),false);assert.equal(f.add.parentNode,f.native);assert.equal(f.disposed.length,1);f.window.fire('focus');assert.equal(f.disposed.length,1);
 f.query.matches=false;f.window.fire('focus');const resumed=f.root.querySelector('.vc-dialog-gallery');assert.ok(resumed);assert.notEqual(resumed,initial);assert.equal(f.root.querySelectorAll('form.cart').length,1);f.window.fire('pageshow',{persisted:true});f.window.fire('focus');assert.equal(f.root.querySelector('.vc-dialog-gallery'),resumed);assert.equal(f.disposed.length,1);assert.equal(f.requests.length,0);
 f.api.destroy();assert.equal(f.window.listeners.get('pageshow').length,0);assert.equal(f.window.listeners.get('focus').length,0);f.query.matches=true;f.window.fire('pageshow',{persisted:true});f.window.fire('focus');assert.equal(f.disposed.length,2);
});
test('accepted variation restores its cached report after pending selection used the same label',async()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,reportGalleryEnabled:true}}});f.window.vcHomeQuickView.reportApi='/reports';f.resize(true);
 f.requests[0].resolve({ok:true,json:async()=>({items:[{id:1,product:{id:7},specification:'20mg',issued_on:'2026-11-05',file:{mime:'application/pdf'},view_url:'/report.pdf',thumbnail_url:'/report.webp'}],total_pages:1,page:1,total:1})});await settle();
 const documents=f.root.querySelector('.vc-dialog-documents');assert.equal(documents.hidden,false);
 const select=f.root.querySelector('.variations select');select.value='15mg';select.selectedOptions=[{textContent:'15mg × 10 vials'}];f.root.vcHomeSelectionPending=true;f.api.update();f.root.vcHomeSelectionPending=false;f.api.update();assert.equal(documents.hidden,true);
 select.value='20mg';select.selectedOptions=[{textContent:'20mg × 10 vials'}];f.root.vcHomeSelectionPending=true;f.api.update();assert.equal(documents.hidden,true);
 f.root.vcHomeSelectionPending=false;f.api.update();assert.equal(documents.hidden,false);assert.ok(f.root.querySelector('.vc-dialog-document-art'));assert.equal(f.requests.length,1,'cached report restored without another archive read');f.api.destroy();
});

test('an already opened report is disposed when its device disables the gallery, while unchanged updates preserve its DOM',async()=>{
 const f=fixture({devices:{desktop:base,mobile:{...base,reportGalleryEnabled:true}}});let viewers=0,closed=0;f.window.createProductReportViewer=()=>({open(){viewers++;},dispose(){closed++;}});f.window.vcHomeQuickView.reportApi='/reports';f.resize(true);
 f.requests[0].resolve({ok:true,json:async()=>({items:[{id:1,product:{id:7},specification:'20mg',issued_on:'2026-11-05',file:{mime:'application/pdf'},view_url:'/report.pdf',thumbnail_url:'/report.webp'}],total_pages:1,page:1,total:1})});await settle();
 const art=f.root.querySelector('.vc-dialog-document-art');assert.ok(art);f.api.update();f.api.update();assert.equal(f.root.querySelector('.vc-dialog-document-art'),art);assert.equal(f.requests.length,1);assert.equal(await f.api.openReport(),true);assert.equal(viewers,1);assert.equal(f.document.querySelectorAll('.vc-product-child-dialog').length,1);
 f.resize(false);assert.equal(closed,1);assert.equal(f.document.querySelectorAll('.vc-product-child-dialog').length,0);assert.equal(f.root.querySelector('.vc-dialog-documents').hidden,true);assert.equal(f.root.querySelectorAll('form.cart').length,1);f.api.destroy();assert.equal(closed,1);
});
