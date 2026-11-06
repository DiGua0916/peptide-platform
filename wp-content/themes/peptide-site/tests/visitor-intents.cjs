'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),{webcrypto}=require('node:crypto');
const moduleSource=fs.readFileSync(path.join(__dirname,'../assets/visitor-intents.js'),'utf8');
const nativeSource=fs.readFileSync(path.join(__dirname,'../../../plugins/peptide-core/assets/showcase-quick-view.js'),'utf8');
class Target060 {
 constructor(){this.listeners=new Map();}
 addEventListener(type,fn,capture=false){if(!this.listeners.has(type))this.listeners.set(type,[]);this.listeners.get(type).push({fn,capture:capture===true||capture?.capture===true});}
 removeEventListener(type,fn){this.listeners.set(type,(this.listeners.get(type)||[]).filter(row=>row.fn!==fn));}
 dispatchEvent(event){for(const row of [...(this.listeners.get(event.type)||[])]){if(event.__stopImmediate)break;row.fn(event);}}
}
class Element060 {
 constructor(tag,classes,doc,attrs={}){this.tagName=tag.toUpperCase();this.classes=classes.split(' ').filter(Boolean);this.ownerDocument=doc;this.attrs={...attrs};this.isConnected=true;this.hidden=false;this.disabled=false;this.parentElement=null;this.children=[];this.style={display:'block',visibility:'visible',opacity:'1'};this.rect={left:20,top:20,right:220,bottom:170,width:200,height:150};}
 append(node){this.children.push(node);node.parentElement=this;return node;}
 getAttribute(key){return this.attrs[key]??null;}
 setAttribute(key,value){this.attrs[key]=String(value);}
 removeAttribute(key){delete this.attrs[key];}
 get dataset(){return Object.fromEntries(Object.entries(this.attrs).filter(([key])=>key.startsWith('data-')).map(([key,value])=>[key.slice(5).replace(/-([a-z])/g,(_,letter)=>letter.toUpperCase()),value]));}
 matches(selector){if(selector==='.vc-showcase')return this.classes.includes('vc-showcase');if(selector==='[data-vc-home-product][data-vc-quick-open]')return Object.hasOwn(this.attrs,'data-vc-home-product')&&Object.hasOwn(this.attrs,'data-vc-quick-open');if(selector==='[data-vc-quick-open]')return Object.hasOwn(this.attrs,'data-vc-quick-open');if(selector==='button[data-vc-quick-open]')return this.tagName==='BUTTON'&&Object.hasOwn(this.attrs,'data-vc-quick-open');if(selector==='dialog.vc-showcase-quick')return this.tagName==='DIALOG'&&this.classes.includes('vc-showcase-quick');if(selector==='.vc-showcase-quick__body')return this.classes.includes('vc-showcase-quick__body');if(selector==='.vc-showcase-quick-product[data-vc-product-id]')return this.classes.includes('vc-showcase-quick-product')&&Object.hasOwn(this.attrs,'data-vc-product-id');return false;}
 closest(selector){for(let node=this;node;node=node.parentElement)if(node.matches(selector))return node;return null;}
 querySelector(selector){for(const child of this.children){if(child.matches(selector)||selector==='h2'&&child.tagName==='H2')return child;const nested=child.querySelector(selector);if(nested)return nested;}return null;}
 replaceChildren(...nodes){for(const child of this.children)child.parentElement=null;this.children=[];for(const node of nodes)this.append(node);}
 focus(){}
 contains(target){return this===target||this.children.some(child=>child.contains(target));}
 dispatchEvent(event){event.target=this;deliver060(this.ownerDocument,event,event.bubbles===true);return true;}
 getClientRects(){return this.isConnected&&this.rect.width>0&&this.rect.height>0?[this.rect]:[];}
 getBoundingClientRect(){return this.rect;}
}
function deliver060(document,event,bubbles=false){if(document instanceof Target060){const rows=[...(document.listeners.get(event.type)||[])];for(const row of [...rows.filter(row=>row.capture),...(bubbles?rows.filter(row=>!row.capture):[])]){if(event.__stopImmediate)break;row.fn(event);}}else document.dispatchEvent(event);}
function mount060(window,document,options={}){
 const html=new Element060('html','',document),body=html.append(new Element060('body','',document));document.documentElement=html;document.visibilityState='visible';document.hidden=false;
 const root=body.append(new Element060('section','vc-showcase',document)),opener=(options.outside?body:root).append(new Element060(options.openerTag||'a','vc-showcase__action',document,{'data-vc-quick-open':'101',href:'/product/native-item/'})),sourceRoot=options.outside?opener:root;
 const dialog=body.append(new Element060('dialog','vc-showcase-quick woocommerce',document,{'aria-modal':'true'}));dialog.open=false;const modalBody=dialog.append(new Element060('div','vc-showcase-quick__body',document));const product=modalBody.append(new Element060('div','vc-showcase-quick-product product',document,{'data-vc-product-id':'101'}));
 product.append(new Element060('h2','vc-showcase-quick__title',document));dialog.showModal=()=>{if(options.showFailure)throw Error('native show failed');dialog.open=true;};dialog.close=()=>{dialog.open=false;};
 document.querySelectorAll=selector=>selector==='dialog.vc-showcase-quick'?[dialog].filter(node=>node.matches(selector)):[];
 const frames=new Map(),watchers=[];let nextFrame=0;
 window.requestAnimationFrame=fn=>{frames.set(++nextFrame,fn);return nextFrame;};window.cancelAnimationFrame=id=>frames.delete(id);window.innerWidth=400;window.innerHeight=300;window.getComputedStyle=node=>node.style;
 window.MutationObserver=class {constructor(fn){this.fn=fn;watchers.push(this);}observe(){this.active=true;}disconnect(){this.active=false;}};
 const tasks=new Map();let nextTask=0,activeClick=null;
 if(typeof window.setTimeout!=='function'){window.setTimeout=fn=>{tasks.set(++nextTask,fn);return nextTask;};window.clearTimeout=id=>tasks.delete(id);}
 const deliver=(event,bubbles=false)=>deliver060(document,event,bubbles);
 const beginClick=(target=opener,trust=true,extra={})=>{if(activeClick)activeClick.eventPhase=0;const event={type:'click',target,button:0,isTrusted:trust,eventPhase:1,preventDefault(){this.defaultPrevented=true;},stopImmediatePropagation(){this.__stopImmediate=true;this.cancelBubble=true;},...extra};if(trust==='missing')delete event.isTrusted;activeClick=event;window.dispatchEvent(event);if(!event.cancelBubble)deliver(event,true);return event;};
 const endClick=()=>{if(activeClick)activeClick.eventPhase=0;};
 const state=(open,target=sourceRoot)=>{window.__nativeState(target===opener?null:target,open,opener);if(open===true)endClick();};
 const jq=()=>({});jq.fn={};window.jQuery=jq;window.matchMedia=()=>({addEventListener(){}});window.CustomEvent=class {constructor(type,init){this.type=type;this.bubbles=false;Object.assign(this,init);}};
 window.location ||= new URL('https://native.test/featured/');window.URL=URL;window.AbortController=AbortController;const originalFetch=window.fetch;window.fetch=async(url,init)=>new URL(url,window.location.href).pathname==='/cart-api'?{ok:true,headers:{get:()=>null},json:async()=>({items:[],totals:{},items_count:0})}:originalFetch(url,init);
 const config={cartApi:'/cart-api',addApi:'/cart-add',cartUrl:'/cart',checkoutUrl:'/checkout'};document.querySelector=selector=>selector==='template[data-vc-quick-product="101"]'?{dataset:{vcQuickConfig:JSON.stringify(config)}}:null;
 const ending='})(window.jQuery);';assert.equal(nativeSource.split(ending).length,2);
 const bridge='window.__nativeState=pauseRoot;window.__nativeQuick={open:open,close:close,prepare:function(platform){dialog=platform.dialog;body=platform.body;views.set("101",{element:platform.product,form:null});status={};checkButton={};acknowledge={};cartLink={};closeButton={focus:function(){}};}};';
 vm.runInNewContext(nativeSource.replace(ending,bridge+ending),window);window.__nativeQuick.prepare({dialog,body:modalBody,product});
 return {window,document,root,opener,sourceRoot,dialog,modalBody,product,frames,watchers,tasks,beginClick,endClick,click:(target=opener,trust=true)=>{const event=beginClick(target,trust);event.eventPhase=3;return event;},bubble:(event,fn)=>{event.eventPhase=3;try{fn();}finally{endClick();}},expireTasks:()=>{const pending=[...tasks.values()];tasks.clear();pending.forEach(fn=>fn());},state,paint:()=>{const pending=[...frames.values()];frames.clear();pending.forEach(fn=>fn());},mutate:records=>watchers.filter(w=>w.active).forEach(w=>w.fn(records)),visibility:hidden=>{document.hidden=hidden;document.visibilityState=hidden?'hidden':'visible';document.dispatchEvent({type:'visibilitychange',target:document});},open(){this.click(opener,true);this.state(true);dialog.open=true;this.paint();},close(){dialog.open=false;this.state(false);},nativeOpen(target=opener,paint=true){this.click(target,true);let opened;try{opened=window.__nativeQuick.open(opener);}finally{endClick();}if(paint)this.paint();return opened;},nativeClose(){window.__nativeQuick.close();}};
}
function fixture060(options={}){const document=new Target060(),window=new Target060();Object.assign(window,{window,document,crypto:webcrypto,navigator:{},Uint8Array,Promise});const env=mount060(window,document,options);options.beforeObserve?.(env);vm.runInNewContext(moduleSource,window);env.facts=[];env.api=window.VialCoveVisitorIntent.observe(document,facts=>env.facts.push(facts));return env;}
let passed060=0;const check060=(name,ok)=>{assert.ok(ok,name);passed060++;};
test('native anchor/button resolver and exact outside-showcase state fallback',()=>{
 for(const outside of [false,true])for(const openerTag of ['a','button']){
  const e=fixture060({outside,openerTag});const nested=e.opener.append(new Element060('span','label',e.document));
  e.click(nested,true);e.state(true);e.dialog.open=true;e.paint();assert.equal(e.facts.length,1,`${openerTag} nested click outside=${outside}`);
  const uid=e.facts[0].event_id;e.state(true);e.paint();assert.equal(e.facts.length,1);e.close();e.open();assert.equal(e.facts.length,2);assert.notEqual(e.facts[1].event_id,uid);e.api.stop();
 }
 for(const outside of [false,true]){
  let e=fixture060({outside,openerTag:'span'});e.open();assert.equal(e.facts.length,0,'unsupported attributed span is never an opener');e.api.stop();
  for(const modifier of [{button:1},{metaKey:true},{ctrlKey:true},{shiftKey:true},{altKey:true},{defaultPrevented:true}]){
   e=fixture060({outside});e.beginClick(e.opener,true,modifier);e.state(true);e.dialog.open=true;e.paint();assert.equal(e.facts.length,0,'modified or prevented click');e.api.stop();
  }
 }
 for(const mode of ['no-state','wrong-opener','wrong-root','wrong-parent','wrong-product','reparented','changed-click','false-state','failed-show','detached','suspend','gpc']){
  const e=fixture060({outside:true});e.click();let target=e.opener;
  if(mode==='wrong-opener')target=e.root.parentElement.append(new Element060('a','',e.document,{'data-vc-quick-open':'101',href:'/product/native-item/'}));
  if(mode==='wrong-root')target=e.root;
  if(mode==='wrong-parent')e.opener.attrs['data-vc-quick-open']='202';
  if(mode==='wrong-product')e.product.attrs['data-vc-product-id']='202';
  if(mode==='reparented')e.root.append(e.opener);
  if(mode==='changed-click')e.opener.tagName='SPAN';
  if(mode!=='no-state')e.state(mode!=='false-state',target);
  e.dialog.open=mode!=='failed-show';if(mode==='detached')e.opener.isConnected=false;if(mode==='suspend')e.api.suspend();if(mode==='gpc')e.window.navigator.globalPrivacyControl=true;
  e.paint();assert.equal(e.facts.length,0,mode);e.api.stop();
 }
 for(const mode of ['untrusted','missing','late','expired','hidden']){
  const e=fixture060({outside:true});if(mode==='hidden')e.visibility(true);e.click(e.opener,mode==='untrusted'?false:mode==='missing'?'missing':true);
  if(mode==='late'||mode==='expired')e.endClick();if(mode==='expired')e.expireTasks();e.state(true);e.dialog.open=true;e.paint();assert.equal(e.facts.length,0,mode);e.api.stop();
 }
});
test('actual native open/close uses exact state targets and failed show cancels before frame',()=>{
 for(const outside of [false,true])for(const showFailure of [false,true]){
  const e=fixture060({outside,showFailure}),states=[];
  e.document.addEventListener('vc:showcase-quick-state',event=>states.push({target:event.target,open:event.detail.open,bubbles:event.bubbles}),true);
  const nested=e.opener.append(new Element060('span','label',e.document));assert.equal(e.nativeOpen(nested,false),!showFailure);assert.ok(states[0],'native open publishes its matching state');assert.equal(states[0].target,e.sourceRoot);assert.equal(states[0].open,true);assert.equal(states[0].bubbles,false);
  if(showFailure){assert.equal(states.length,2);assert.equal(states[1].target,e.sourceRoot);assert.equal(states[1].open,false);assert.equal(e.frames.size,0,'show failure cancels before any RAF');e.paint();assert.equal(e.facts.length,0);}
  else{assert.equal(e.frames.size,1);e.paint();assert.equal(e.facts.length,1);e.nativeClose();assert.ok(states[1],'native close publishes its matching false state');assert.equal(states[1].target,e.sourceRoot);assert.equal(states[1].open,false);assert.equal(e.dialog.open,false);assert.equal(e.nativeOpen(),true);assert.equal(e.facts.length,2);}
  e.api.stop();
 }
 const e=fixture060({outside:true});let states=0;e.document.addEventListener('vc:showcase-quick-state',()=>states++,true);e.window.__nativeState(null,true);assert.equal(states,0,'no root/opener is a no-op');e.api.stop();
 for(const outside of [false,true]){const before=fixture060({outside});assert.equal(before.nativeOpen(before.opener,false),true);assert.equal(before.frames.size,1);before.nativeClose();assert.equal(before.frames.size,0,'actual close cancels before any RAF');before.paint();assert.equal(before.facts.length,0);before.api.stop();}
 const failing=fixture060({outside:true});failing.api.stop();const observer=failing.window.VialCoveVisitorIntent.observe(failing.document,()=>{throw Error('optional observer failed');});assert.equal(failing.nativeOpen(),true);assert.equal(failing.dialog.open,true);failing.nativeClose();observer.stop();
});
test('native quick-view observed opening module',async()=>{
 let e=fixture060();check060('nonbubbling native state uses capture',e.document.listeners.get('vc:showcase-quick-state').every(row=>row.capture));e.click();e.state(true);e.paint();check060('preopen state without successful show does not count',e.facts.length===0);e.dialog.open=true;e.paint();check060('no polling after unsuccessful first frame',e.facts.length===0&&e.frames.size===0);e.api.stop();
 e=fixture060();e.click();e.state(true);e.state(false);e.paint();check060('native show failure/close before paint cancel',e.facts.length===0);e.api.stop();
 e=fixture060();e.open();check060('real connected visible native modal emits one parent fact',e.facts.length===1&&e.facts[0].product_id===101&&e.facts[0].variation_id===0&&e.facts[0].kind==='quick_view_open'&&e.facts[0].result==='completed');check060('facts immutable and contain no DOM content or source extras',Object.isFrozen(e.facts[0])&&Object.keys(e.facts[0]).sort().join()==='event_id,kind,product_id,result,variation_id,version'&&!JSON.stringify(e.facts).includes('ignored'));
 const first=e.facts[0].event_id;e.state(true);e.paint();check060('repeated true episode deduplicates',e.facts.length===1);e.close();e.open();check060('actual close/reopen generates fresh stable UID',e.facts.length===2&&e.facts[1].event_id!==first&&/^[A-Za-z0-9-]{8,64}$/.test(first));e.api.stop();
 for(const mode of ['zero','offscreen','bodyclip','hidden','opacity','detached-root','detached-dialog','wrong-id','invalid-id','wrong-dialog','wrong-root']){
  e=fixture060();if(mode==='zero')e.product.rect.width=0;if(mode==='offscreen')e.product.rect={left:500,top:20,right:700,bottom:170,width:200,height:150};if(mode==='bodyclip')e.product.rect={left:230,top:20,right:390,bottom:170,width:160,height:150};if(mode==='hidden')e.product.style.visibility='hidden';if(mode==='opacity')e.modalBody.style.opacity='0';if(mode==='detached-root')e.root.isConnected=false;if(mode==='detached-dialog')e.dialog.isConnected=false;if(mode==='wrong-id')e.product.attrs['data-vc-product-id']='102';if(mode==='invalid-id'){e.product.attrs['data-vc-product-id']='9007199254740993';e.opener.attrs['data-vc-quick-open']='9007199254740993';}if(mode==='wrong-dialog')e.dialog.classes=['other-quick-view'];e.click();e.state(true,mode==='wrong-root'?e.modalBody:e.root);e.dialog.open=true;e.paint();check060('strict native geometry/identity/root '+mode,e.facts.length===0);e.api.stop();
 }
 e=fixture060();e.click();e.state(true);e.dialog.open=true;e.mutate([{removedNodes:[e.root]}]);e.paint();check060('detach/reattach mutation cancels incomplete episode',e.facts.length===0);e.api.stop();
 e=fixture060();e.visibility(true);e.click();e.state(true);e.dialog.open=true;e.paint();check060('initially hidden does not count',e.facts.length===0&&e.frames.size===0);e.visibility(false);e.paint();check060('one foreground check can qualify same still-open hidden episode',e.facts.length===1);e.visibility(true);e.visibility(false);e.paint();check060('visibility toggles do not create new intent',e.facts.length===1);e.api.stop();
 e=fixture060();e.click();e.state(true);e.dialog.open=true;e.window.dispatchEvent({type:'pagehide'});e.paint();e.window.dispatchEvent({type:'pageshow',persisted:true});e.api.resume();e.paint();check060('pagehide/BFCache does not infer another opening',e.facts.length===0);e.close();e.open();check060('new actual open after resume is observed',e.facts.length===1);e.api.stop();
 e=fixture060();e.click();e.state(true);e.dialog.open=true;e.api.stop();e.paint();check060('stop cleans listeners observers and frames',e.facts.length===0&&e.frames.size===0&&e.document.listeners.get('vc:showcase-quick-state').length===0&&!e.watchers.some(w=>w.active));
 e=fixture060();e.window.navigator.globalPrivacyControl=true;e.open();check060('GPC never creates a fact',e.facts.length===0);e.window.navigator.globalPrivacyControl=false;e.state(true);e.paint();check060('privacy return alone does not replay old opening',e.facts.length===0);e.api.stop();
 e=fixture060();e.document.dispatchEvent({type:'vc:cart-updated',detail:{source:'showcase',mutation:true}});e.paint();check060('cart result never fabricates buy-now/favorite/share',e.facts.length===0);e.api.stop();
 e=fixture060();e.dialog.open=true;e.state(true);e.paint();check060('state event without the matching native opener turn is insufficient',e.facts.length===0);e.api.stop();
 e=fixture060();e.click();e.state(true);e.dialog.open=true;const other=e.root.parentElement.append(new Element060('section','vc-showcase',e.document));const otherButton=other.append(new Element060('button','',e.document,{'data-vc-quick-open':'202'}));e.click(otherButton,true);e.state(false);e.product.attrs['data-vc-product-id']='202';e.state(true,other);e.paint();check060('new native root invalidates pending old product episode',e.facts.length===1&&e.facts[0].product_id===202);e.api.stop();
 e=fixture060();e.open();const next=e.root.append(new Element060('button','',e.document,{'data-vc-quick-open':'202'}));e.click(next,true);e.state(false);e.product.attrs['data-vc-product-id']='202';e.state(true);e.paint();check060('reused native dialog observes its next parent product only',e.facts.length===2&&e.facts[1].product_id===202);e.api.stop();
 // A browser checkpoints microtasks after the capture callback, before native bubble opening.
 e=fixture060();const clickEvent=e.beginClick(e.opener,true);check060('capture retains the original trusted event and one expiry task',clickEvent.eventPhase===1&&e.tasks.size===1);await Promise.resolve();e.bubble(clickEvent,()=>{e.state(true);e.dialog.open=true;});check060('native bubble completes with phase zero and consumed timer',clickEvent.eventPhase===0&&e.tasks.size===0);e.paint();check060('trusted native capture checkpoint bubble paint counts exactly once',e.facts.length===1);e.api.stop();
 for(const trust of [false,'missing']){e=fixture060();e.click(e.opener,trust);e.state(true);e.dialog.open=true;e.paint();check060('untrusted or missing trust cannot count an otherwise visible native opening '+trust,e.facts.length===0&&e.tasks.size===0);e.close();e.open();check060('a new trusted opening still qualifies after rejected trust '+trust,e.facts.length===1);e.api.stop();}
 e=fixture060();const late=e.click(e.opener,true);e.endClick();check060('dispatch ended while the zero delay task is still pending',late.eventPhase===0&&e.tasks.size===1);e.state(true);e.dialog.open=true;e.paint();check060('phase zero late state is rejected before timer execution',e.facts.length===0&&e.tasks.size===0);e.api.stop();
 e=fixture060();e.click(e.opener,true);e.endClick();e.expireTasks();e.state(true);e.dialog.open=true;e.paint();check060('task expiry removes unused opening eligibility',e.facts.length===0&&e.tasks.size===0);e.api.stop();
 e=fixture060();e.click(e.opener,true);e.endClick();e.click(e.opener,true);check060('replacement click retires its old expiry timer',e.tasks.size===1);e.api.suspend();check060('suspend clears pending timer and original event eligibility',e.tasks.size===0);e.api.resume();e.state(true);e.dialog.open=true;e.paint();check060('resume cannot revive a suspended opener',e.facts.length===0);e.api.stop();
 e=fixture060();e.click(e.opener,true);check060('unused trusted opener has a bounded timer',e.tasks.size===1);e.api.stop();check060('stop clears unused opener timer',e.tasks.size===0&&e.frames.size===0);
 console.log(JSON.stringify({suite:'native-intent-module',passed:passed060,failed:0}));
});
test('lazy live template retains trusted source until matching presentation, never trusts replay alone',()=>{
 const e=fixture060({outside:true});e.opener.setAttribute('data-vc-home-product','101');
 e.click();e.endClick();
 // Run the original synchronous-turn expiry, not the bounded fetch expiry.
 const [syncId,syncTask]=e.tasks.entries().next().value;e.tasks.delete(syncId);syncTask();
 assert.equal(e.tasks.size,1);e.click(e.opener,false);e.state(true);e.dialog.open=true;e.paint();
 assert.equal(e.facts.length,0,'synthetic replay/state cannot count the product');
 e.product.vcHomeOpener=e.opener;
 e.document.dispatchEvent({type:'vc:product-presented',detail:{root:e.product,dialog:e.dialog,productId:101}});e.paint();
 assert.equal(e.facts.length,1);assert.equal(e.facts[0].product_id,101);assert.equal(e.tasks.size,0);
 e.document.dispatchEvent({type:'vc:product-presented',detail:{root:e.product,dialog:e.dialog,productId:101}});e.paint();
 assert.equal(e.facts.length,1,'duplicate presentation cannot count again');e.api.stop();
});

test('real home capture can stop its first lazy click without hiding that trusted source from window capture',async()=>{
 const home=fs.readFileSync(path.join(__dirname,'../assets/home-quick-view.js'),'utf8');
 const start="  document.addEventListener('click', event => {",end='  // Keep the product selection underneath the live bag';
 const capture=home.slice(home.indexOf(start),home.indexOf(end));assert.ok(capture.includes('event.stopImmediatePropagation()'));
 const pendingSupport=home.slice(home.indexOf('  function clearPending()'),home.indexOf('  function announce('));
 let ready=false,complete,lateDocumentClicks=0,replays=0,presentTurn;
 const request=new Promise(resolve=>{complete=()=>{ready=true;resolve();};});
 const e=fixture060({outside:true,beforeObserve(env){
  env.opener.setAttribute('data-vc-home-product','101');
  const nativeQuery=env.document.querySelector;
  env.document.querySelector=selector=>selector.startsWith('dialog.vc-showcase-quick[open]')?(env.dialog.open?env.product:null):nativeQuery(selector);
  vm.runInNewContext(pendingSupport+capture,{document:env.document,window:env.window,openIntent:0,stagedRestore:null,pendingOpeners:new Map(),loadingStatus:null,homeProduct:'.vc-home-quick-product',canOpen:()=>true,templateFor:()=>ready?{}:null,announce(){},loadTemplate:()=>request,present(root,opener){root.vcHomeOpener=opener;env.document.dispatchEvent({type:'vc:product-presented',detail:{root,dialog:env.dialog,productId:101}});}});
  // This document listener exists before the later visitor observer, matching
  // the production enqueue order. The native lazy capture must suppress it.
  env.document.addEventListener('click',()=>lateDocumentClicks++,true);
 }});
 e.opener.click=()=>{replays++;e.click(e.opener,false);presentTurn=[...e.tasks.entries()].at(-1);e.window.__nativeQuick.open(e.opener);e.endClick();};
 const click=e.click();e.endClick();assert.equal(click.defaultPrevented,true);assert.equal(lateDocumentClicks,0);
 assert.equal(e.window.listeners.get('click')[0].capture,true);assert.equal(e.tasks.size,2,'window retained the original source before document stopped propagation');
 const [syncId,syncTask]=e.tasks.entries().next().value;e.tasks.delete(syncId);syncTask();
 complete();for(let i=0;i<6;i++)await Promise.resolve();assert.equal(replays,1);assert.equal(e.dialog.open,true);assert.equal(e.facts.length,0);
 const [presentId,presentTask]=presentTurn;e.tasks.delete(presentId);presentTask();e.paint();
 assert.equal(e.facts.length,1);assert.equal(e.facts[0].product_id,101);assert.equal(lateDocumentClicks,1,'only the later cached synthetic replay reaches document listeners');
 e.api.stop();assert.equal(e.window.listeners.get('click').length,0);
});

test('restore cancellation and Escape retire lazy eligibility before a synthetic forward restoration',()=>{
 for(const cancellation of [{type:'vc:product-restore-cancel'},{type:'keydown',key:'Escape'}]){
  const e=fixture060({outside:true});e.opener.setAttribute('data-vc-home-product','101');e.click();e.endClick();
  e.document.dispatchEvent(cancellation);assert.equal(e.tasks.size,0);
  e.click(e.opener,false);e.dialog.open=true;e.product.vcHomeOpener=e.opener;
  e.document.dispatchEvent({type:'vc:product-presented',detail:{root:e.product,dialog:e.dialog,productId:101}});e.paint();assert.equal(e.facts.length,0,cancellation.type);
  e.close();e.click();e.endClick();e.dialog.open=true;
  e.document.dispatchEvent({type:'vc:product-presented',detail:{root:e.product,dialog:e.dialog,productId:101}});e.paint();assert.equal(e.facts.length,1,'a later trusted opening still qualifies');e.api.stop();
 }
 const e=fixture060({outside:true});e.opener.setAttribute('data-vc-home-product','101');e.click();e.endClick();e.document.dispatchEvent({type:'keydown',key:'Tab'});
 assert.equal(e.tasks.size,2,'ordinary keys do not cancel pending native reads');e.api.stop();
 assert.equal(e.document.listeners.get('vc:product-restore-cancel').length,0);assert.equal(e.document.listeners.get('keydown').length,0);
});

test('lazy product observation refuses missing, stale, failed and mismatched source confirmation',()=>{
 for(const mode of ['no-click','untrusted','expired','suspended','stopped','wrong-opener','wrong-product','closed','detached','hidden']){
  const e=fixture060({outside:true});e.opener.setAttribute('data-vc-home-product','101');
  if(mode!=='no-click'){e.click(e.opener,mode!=='untrusted');e.endClick();}
  if(mode==='expired')e.expireTasks();
  if(mode==='suspended')e.api.suspend();
  if(mode==='stopped')e.api.stop();
  e.dialog.open=mode!=='closed';e.product.vcHomeOpener=mode==='wrong-opener'?e.root:e.opener;
  if(mode==='detached')e.product.isConnected=false;
  if(mode==='hidden')e.product.style.display='none';
  e.document.dispatchEvent({type:'vc:product-presented',detail:{root:e.product,dialog:e.dialog,productId:mode==='wrong-product'?202:101}});e.paint();
  assert.equal(e.facts.length,0,mode+' never produces product intent');e.api.stop();
 }
});

for(const outside of [false,true]) test('actual native '+(outside?'outside-root':'showcase')+' anchor with056 collector/adapter queues canonical intent and immutable retry',async()=>{
 const original=fs.readFileSync(path.join(__dirname,'visitor-product-actions.cjs'),'utf8');let source=original.split('async function run()')[0];
 source=source.replace("const context = vm.createContext(window);","if (options.intentSetup) options.intentSetup(window, document);\n  const context = vm.createContext(window);").replace("['visitor-tracking.js', 'visitor-adapter.js']","['visitor-intents.js', 'visitor-tracking.js', 'visitor-adapter.js']");
 source=source.replace("vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});","vm.runInContext(name === 'visitor-intents.js' ? intentModuleSource : fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'), context, {filename: name});");
 const scope={require,__dirname,console,URL,Headers,AbortController,DOMException,TextEncoder,Uint8Array,intentModuleSource:moduleSource};vm.runInNewContext(source+';this.fixture=fixture;this.drain=drain;',scope);
 const page={page_type:'home',path:'/featured/?private=SECRET#SECRET'};let env;
 const f=scope.fixture({product_actions_protocol:1},{page,intentSetup:(window,doc)=>{env=mount060(window,doc,{outside});}});f.responses.push({network:true});assert.equal(env.nativeOpen(),true);await f.advance(300);
 let all=f.calls.flatMap(call=>call.body.events),records=all.filter(row=>row.type==='product_intent'),views=all.filter(row=>row.type==='product_view');
 check060('actual helper delegates exactly one canonical queued intent',records.length===1&&JSON.stringify(records[0].data)==='{"version":1,"product_id":101,"variation_id":0,"kind":"quick_view_open","result":"completed"}');const first=JSON.stringify(records[0]),firstView=JSON.stringify(views[0]);
 check060('actual native preview pairs one exact parent view on nonproduct page',views.length===1&&JSON.stringify(views[0].data)==='{"product_id":101}'&&views[0].page.page_type==='home'&&views[0].page.path==='/featured/');
 check060('native pair has same observation time and consecutive sequence',views[0].occurred_at===records[0].occurred_at&&views[0].seq===records[0].seq+1&&views[0].page_instance_id===records[0].page_instance_id&&views[0].event_id!==records[0].event_id);
 check060('native preview does not create a page view',all.filter(row=>row.type==='page_view').length===1);
 f.window.dispatchEvent({type:'pagehide',persisted:true});await scope.drain();f.window.dispatchEvent({type:'pageshow',persisted:true});await f.advance(1100);all=f.calls.flatMap(call=>call.body.events);records=all.filter(row=>row.type==='product_intent');views=all.filter(row=>row.type==='product_view');
 check060('lost ACK retry/BFCache keeps exact original UID and payload',records.length>=2&&records.every(row=>JSON.stringify(row)===first));check060('BFCache alone has no second intent UID',new Set(records.map(row=>row.event_id)).size===1);
 check060('view half also retries unchanged across BFCache',views.length>=2&&views.every(row=>JSON.stringify(row)===firstView));
 env.nativeClose();env.nativeOpen();await f.advance(300);all=f.calls.flatMap(call=>call.body.events);
 check060('real native close/reopen queues a second view episode',new Set(all.filter(row=>row.type==='product_view').map(row=>row.event_id)).size===2&&new Set(all.filter(row=>row.type==='product_intent').map(row=>row.event_id)).size===2);f.stop();
 check060('actual collector stop removes helper state listener',f.document.listeners.get('vc:showcase-quick-state').size===0);
 for(const config of [{enabled:false,product_actions_protocol:1},{product_actions_protocol:'1'},{product_actions_protocol:1}]){let candidate;const privacy=config.enabled!==false&&config.product_actions_protocol===1;const off=scope.fixture(config,{page,navigator:privacy?{globalPrivacyControl:true}:{},intentSetup:(window,doc)=>{candidate=mount060(window,doc);}});candidate.open();await off.advance(300);check060('disabled wrong-protocol/GPC starts no intent queue',!off.calls.flatMap(call=>call.body.events).some(row=>row.type==='product_intent'));check060('disabled wrong-protocol/GPC starts no mirrored view',!off.calls.flatMap(call=>call.body.events).some(row=>row.type==='product_view'));off.stop();}
 for(const mode of ['failed','untrusted','hidden']){let candidate;const rejected=scope.fixture({product_actions_protocol:1},{page,intentSetup:(window,doc)=>{candidate=mount060(window,doc);}});
  if(mode==='hidden')candidate.visibility(true);candidate.click(candidate.opener,mode!=='untrusted');candidate.state(true);candidate.dialog.open=mode!=='failed';candidate.paint();await rejected.advance(300);
  check060('unsuccessful untrusted or invisible native opening adds no pair '+mode,!rejected.calls.flatMap(call=>call.body.events).some(row=>['product_intent','product_view'].includes(row.type)));rejected.stop();
 }
 console.log(JSON.stringify({suite:'native-intent-integration',cumulative_assertions:passed060,failed:0}));
});
