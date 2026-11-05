/* Actual independent module; fake DOM/network boundary, never native cart or browser data. */
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let checks=0,now=1000,mounted=0,clockUpdates=0,destroyed=0,nativeOpens=0,requests=[],reloads=0,fetchReply=null,nextTimer=1,lastClockData=null,presentNative=true;
const intervals=new Map(),timeouts=new Map(),observers=[];
class Target {constructor(){this.events=new Map();}addEventListener(k,cb){if(!this.events.has(k))this.events.set(k,new Set());this.events.get(k).add(cb);}removeEventListener(k,cb){this.events.get(k)?.delete(cb);}dispatchEvent(event){event.target??=this;for(const cb of [...this.events.get(event.type)||[]])cb(event);return true;}}
class Element extends Target{
 constructor(tag='div'){super();this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.attributes={};this.parentElement=null;this.hidden=false;this.disabled=false;this.map={};this.classes=new Set();this.classList={contains:x=>this.classes.has(x),add:(...xs)=>xs.forEach(x=>this.classes.add(x)),remove:(...xs)=>xs.forEach(x=>this.classes.delete(x))};this._text='';}
 set className(s){this.classes=new Set(s.split(/\s+/));}get className(){return [...this.classes].join(' ');}get isConnected(){return true;}
 set textContent(s){this._text=String(s);this.children=[];}get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
 append(...nodes){for(const node of nodes){node.remove();node.parentElement=this;this.children.push(node);}}prepend(node){node.remove();node.parentElement=this;this.children.unshift(node);}replaceChildren(...nodes){this.children.forEach(x=>x.parentElement=null);this.children=[];this._text='';this.append(...nodes);}
 remove(){if(this.parentElement){const list=this.parentElement.children;const i=list.indexOf(this);if(i>=0)list.splice(i,1);this.parentElement=null;}}after(node){const parent=this.parentElement;if(!parent)return;node.remove();node.parentElement=parent;parent.children.splice(parent.children.indexOf(this)+1,0,node);}
 get lastElementChild(){return this.children.at(-1);}contains(node){return node===this||this.children.some(x=>x.contains(node));}setAttribute(k,v){this.attributes[k]=v;}focus(){document.activeElement=this;}
 matches(selector){return selector.split(',').some(raw=>{const s=raw.trim();return s==='button'&&this.tagName==='BUTTON'||s.startsWith('.')&&this.classes.has(s.slice(1));});}
 querySelector(selector){return this.map[selector]||this.children.find(x=>selector==='p'&&x.tagName==='P'||selector==='button'&&x.tagName==='BUTTON')||null;}querySelectorAll(selector){return [];}
 click(){if(this.disabled)return;this.dispatchEvent({type:'click'});this.onclick?.();if(this.dataset.vcQuickOpen){nativeOpens++;if(presentNative)document.dispatchEvent({type:'vc:product-presented',detail:{root,dialog,productId:Number(this.dataset.vcQuickOpen)}});}}
}
class Observer {constructor(cb){this.cb=cb;this.targets=[];observers.push(this);}observe(node,options){this.targets.push({node,options});}disconnect(){this.targets=[];}}
const document=new Target();document.readyState='complete';document.hidden=false;document.documentElement={lang:'en'};document.activeElement=null;document.createElement=tag=>new Element(tag);document.body=new Element('body');
const main=new Element('main'),root=new Element(),dialog=new Element('dialog'),toolbar=new Element(),heading=new Element('header'),broadcast=new Element('button'),offers=new Element('section'),close=new Element('button'),form=new Element('form');
root.className='vc-product-experience';root.dataset.vcHomeInitial=JSON.stringify({promotions:[{id:37,status:'active',tiers:[{text:'Real tier'}]}]});root.dataset.vcProductId='11306';dialog.open=true;close.className='vc-showcase-quick__close';offers.className='vc-dialog-offers';
const navs=['Overview','Details','COA','Bag'].map(name=>{const e=new Element(name==='Bag'?'a':'button');e.textContent=name;if(name==='Bag')e.className='vc-dialog-bag';return e;});
document.body.append(main,dialog);dialog.append(toolbar,root);root.append(heading,form);heading.append(broadcast);toolbar.append(...navs,close,offers);document.activeElement=navs[1];
root.map['.vc-dialog-offers']=null;root.map['.vc-dialog-heading']=heading;heading.map['[data-vc-product-broadcast]']=broadcast;
dialog.map[':scope>.vc-home-quick__toolbar>.vc-dialog-offers']=offers;dialog.map[':scope>.vc-home-quick__toolbar']=toolbar;dialog.map['.vc-showcase-quick__close']=close;
const template={},templates=new Set(['11306']);root.closest=selector=>selector==='dialog'?dialog:null;document.querySelector=selector=>selector==='main'?main:selector==='dialog.vc-showcase-quick[open] .vc-home-quick-product'?root:templates.has(selector.match(/^template\[data-vc-quick-product="(\d+)"\]$/)?.[1])?template:null;
const dto={version:1,position:'product',enabled:true,visible:true,state:'active',promotionId:37,title:'Black Friday <script>',tiers:[{min:300,value:25,text:'Spend $300, get $25 off'}],starts:900,ends:2000,target:2000,serverNow:1000};
const config={version:1,productId:0,catalog:{},campaign:dto,release:'a'.repeat(64),releaseUrl:'https://store.test/wp-json/vialcove/v1/storefront-entry',campaignUrl:'https://store.test/wp-json/peptide/v1/campaign-countdown'};
const window=new Target();Object.assign(window,{document,vcStorefrontEntry:config,performance:{now:()=>now,getEntriesByType:()=>[{responseEnd:1000}]},location:{href:'https://store.test/',origin:'https://store.test',pathname:'/',hash:'',reload(){reloads++;}},history:{state:{}},sessionStorage:{getItem(){return null;},setItem(){}},PCCampaignCountdown:{mount(host,data){mounted++;lastClockData=data;return{update(next){clockUpdates++;lastClockData=next;},destroy(){destroyed++;}}}},vcHomeQuickView:{productApi:'https://store.test/wp-json/vialcove/v1/home-product/'}});
const fetch=async(url,options)=>{requests.push({url,options});if(fetchReply)return fetchReply;throw new Error('Synthetic offline');};
const setInterval=(cb,delay)=>{const id=nextTimer++;intervals.set(id,{cb,delay});return id;},clearInterval=id=>intervals.delete(id),setTimeout=(cb,delay)=>{const id=nextTimer++;timeouts.set(id,{cb,delay});return id;},clearTimeout=id=>timeouts.delete(id);
const historyCancellation=process.argv.includes('--history-cancellation'),historyOtherLayer=process.argv.includes('--history-other-layer');
if(historyCancellation||historyOtherLayer){config.productId=11306;if(historyOtherLayer)config.productAvailable=false;window.history.state={__vcCommerceLayers:{layers:historyOtherLayer?[{kind:'cart'}]:[{kind:'product',productId:11306}]}};const baseQuery=document.querySelector;document.querySelector=selector=>selector==='dialog.vc-showcase-quick[open] .vc-home-quick-product'?null:baseQuery(selector);}
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/product-dialog-polish.js'),'utf8'),{window,fetch,MutationObserver:Observer,AbortController,URL,CustomEvent:class{constructor(type,options={}){this.type=type;Object.assign(this,options);}},Event:class{constructor(type,options={}){this.type=type;Object.assign(this,options);}},setInterval,clearInterval,setTimeout,clearTimeout,console});
const api=window.VialCoveStorefrontEntry;function check(name,okay){assert.ok(okay,name);checks++;}
if(historyOtherLayer){
 check('a refreshed bag layer is not owned by the stale product deep-link query',main.children.length===0);
 check('stale unavailable product does not create a failure over the newer bag',timeouts.size===0&&document.events.get('vc:product-presented').size===1);
 console.log(JSON.stringify({passed:checks,scope:'actual refreshed bag/stale product query module, isolated DOM'},null,2));
}else if(historyCancellation){(async()=>{
 const transient=main.children[0],oldTimer=[...timeouts.values()].find(item=>item.delay===14500),oldCallbacks=[...document.events.get('vc:product-presented')];
 check('history restoration starts one transient announcement',transient.dataset.entryState==='loading'&&oldTimer&&oldCallbacks.length===2);
 document.dispatchEvent({type:'vc:product-restore-cancel'});await Promise.resolve();
 check('cancelled restoration leaves no catalogue retry card',main.children.length===0);
 check('cancelled history wait releases its listener and timeout immediately',document.events.get('vc:product-presented').size===1&&![...timeouts.values()].some(item=>item.delay===14500));
 const opened=await api.openProduct(11306);check('a subsequent native product intent still opens once',opened&&nativeOpens===1&&main.children[0].hidden);
 oldTimer.cb();oldCallbacks.forEach(callback=>callback({detail:{root,dialog,productId:11306}}));await Promise.resolve();
 check('stale old history callbacks cannot repaint the newer success',main.children[0].hidden&&main.children[0].dataset.entryState==='ready'&&nativeOpens===1);
 document.dispatchEvent({type:'vc:product-experience-disposed',detail:{root,dialog}});
 check('history scenario releases all product timers',intervals.size===0&&timeouts.size===0);
 console.log(JSON.stringify({passed:checks,scope:'actual history cancellation module, isolated presenter/DOM; no browser'},null,2));
})().catch(error=>{console.error(error);process.exitCode=1;});}else(async()=>{
 check('late script startup polishes a dialog restored before presentation listener existed',dialog.classList.contains('vc-dialog-polished')&&mounted===1&&navs.every(e=>!e.parentElement));
 check('exact native selected campaign eligible',api.campaignEligible(root,dto,1000));
 for(const change of [{promotionId:44},{enabled:false},{state:'ended'},{state:'upcoming'},{serverNow:'1000'},{ends:'2000'},{version:2},{tiers:[]}])check('nonmatching/invalid/inactive campaign not advertised',!api.campaignEligible(root,{...dto,...change},1000));
 check('deadline exact boundary hides offers',!api.campaignEligible(root,dto,2000));
 check('untimed real active campaign can show real tiers without timer',api.campaignEligible(root,{...dto,state:'untimed',ends:null},1000));
 const owner=api.bindDialog(root,dialog);await Promise.resolve();await Promise.resolve();
 check('same native close X retained',toolbar.children.includes(close)&&close.parentElement===toolbar);
 check('all four navigation controls removed',navs.every(e=>!e.parentElement));
 check('focus from removed nav reaches original close',document.activeElement===close);
 check('original offer-follow same node retained',owner.offers===offers&&offers.parentElement===toolbar);
 check('real offer title and tier use text content',offers.textContent==='Black Friday <script>Spend $300, get $25 off'&&offers.children[0].tagName==='H2');
 check('purchase form untouched',form.parentElement===root);
 check('counter after broadcast, before body media',heading.children.indexOf(broadcast)===0&&heading.children[1].dataset.pcCampaignCountdown==='product');
 check('one mounted clock',mounted===1);
 owner.sync();owner.sync();check('variation sync never resets known time anchor',clockUpdates===0);
 check('only narrow observer target',observers.every(o=>o.targets.every(t=>t.node===offers&&t.options.childList&&!t.options.attributes)));
 offers.textContent='Order savings Wrong activity';observers[0].cb([]);check('native old writer corrected in same existing node',offers.textContent==='Black Friday <script>Spend $300, get $25 off');
 root.vcHomePromotions=[{id:99,status:'active'}];owner.sync();check('different SKU eligibility hides bound activity',offers.hidden);check('different SKU also suppresses unrelated activity clock',lastClockData.visible===false);
 root.vcHomePromotions=[];owner.sync();check('empty native eligibility cannot show global activity clock',lastClockData.visible===false);
 root.vcHomePromotions=[{id:37,status:'active'}];owner.sync();check('same source returns after eligible SKU selected',!offers.hidden);check('eligible SKU restores same absolute deadline',lastClockData.visible===true&&lastClockData.ends===dto.ends);
 now+=1100000;[...intervals.values()].forEach(v=>v.cb());check('absolute end clears old offer',offers.hidden);
 owner.destroy();check('close lifecycle cleans clock/observer/timer',destroyed===1&&intervals.size===0&&observers[0].targets.length===0);
 const late=api.bindDialog(root,dialog);await Promise.resolve();await Promise.resolve();check('late reopen with failed fresh GET cannot revive expired offer',offers.hidden);check('late clock uses original page anchor and remains expired',lastClockData.serverNow>=dto.ends&&lastClockData.visible===false);late.destroy();
 config.productId=11306;const opened=await api.openProduct(11306);check('deep link uses existing native presenter exactly once',opened&&nativeOpens===1);
 let recovery=main.children[0],button=recovery.querySelector('button');check('successful entry reserves no catalogue card but retains one native opener',recovery.hidden&&button&&!button.disabled&&button.dataset.vcQuickOpen==='11306');
 button.click();check('manual reopen invokes one native presenter, no helper recursion',nativeOpens===2);
 const before=nativeOpens;const failed=await api.openProduct(99);recovery=main.children[0];button=recovery.querySelector('button');check('genuine read failure stays visible retry instead of old PDP redirect',failed===false&&nativeOpens===before&&!recovery.hidden&&recovery.querySelector('p').textContent.includes('could not load')&&!button.disabled&&!button.dataset.vcQuickOpen);
 check('all module requests are read-only',requests.every(r=>!r.options.method&&!r.options.body&&r.options.cache==='no-store'));
 fetchReply={ok:true,json:async()=>({version:1,release:'a'.repeat(64)})};await [...window.events.get('pageshow')][0]({persisted:true});check('same release does not reload',reloads===0);
 fetchReply={ok:true,json:async()=>({version:1,release:'b'.repeat(64)})};await [...window.events.get('pageshow')][0]({persisted:true});check('confirmed release mismatch reloads clean public page once',reloads===1);
 const input={closest:()=>form};document.dispatchEvent({type:'input',isTrusted:true,target:input});await [...window.events.get('pageshow')][0]({persisted:true});check('unsaved form is never automatically reloaded',reloads===1&&recovery.querySelector('p').textContent.includes('newer store'));
 window.location.pathname='/checkout/';await [...window.events.get('pageshow')][0]({persisted:true});check('checkout never reloads from this adapter',reloads===1);
 // A deferred shared GET is deliberately not aborted by a retired subscriber.
 // Its late result may populate the warm template, but may not open or repaint.
 let finishLoad,sharedReads=0;
 window.VialCoveHomeQuickView={loadTemplate:async id=>{sharedReads++;await new Promise(resolve=>{finishLoad=resolve;});templates.add(id);}};
 const cancelled=api.openProduct(100);const loading=main.children[0];
 check('pending entry is scoped and non-layout loading UI',loading.dataset.entryState==='loading'&&!loading.hidden);
 document.dispatchEvent({type:'vc:product-restore-cancel'});const opensBeforeLate=nativeOpens;
 check('Back removes pending recovery immediately',!main.children.includes(loading));
 finishLoad();await cancelled;check('late shared result cannot open or insert failed/cancelled card',nativeOpens===opensBeforeLate&&!main.children.includes(loading)&&sharedReads===1);
 const warm=await api.openProduct(100);check('warm template reopens without another GET',warm&&sharedReads===1);
 let rejectLoad;window.VialCoveHomeQuickView={loadTemplate:()=>new Promise((resolve,reject)=>{rejectLoad=reject;})};
 const escaped=api.openProduct(101);document.dispatchEvent({type:'keydown',key:'Escape'});rejectLoad(new Error('Shared loader aborted after Escape'));await escaped;
 check('Escape invalidates shared-read abort before its rejected promise can display failure',main.children.length===0);
 let releaseNewer;window.VialCoveHomeQuickView={loadTemplate:async id=>{await new Promise(resolve=>{releaseNewer=resolve;});templates.add(id);}};
 const outdated=api.openProduct(102);const opensBeforeOther=nativeOpens;
 document.dispatchEvent({type:'vc:product-presented',detail:{root,dialog,productId:11306}});releaseNewer();await outdated;
 check('a different presented product retires a late automatic deep-link intent',main.children.length===0&&nativeOpens===opensBeforeOther);
 // A cancelled presenter wait releases both its timer and listener immediately.
 presentNative=false;const timerCount=timeouts.size,listenerCount=document.events.get('vc:product-presented').size;
 const awaitingPresenter=api.openProduct(11306);await Promise.resolve();
 check('one active presenter wait has one listener',document.events.get('vc:product-presented').size===listenerCount+1);
 window.dispatchEvent({type:'pagehide'});await awaitingPresenter;
 check('pagehide retires presenter wait with no stale retry',timeouts.size===timerCount&&document.events.get('vc:product-presented').size===listenerCount&&main.children.length===0);
 presentNative=true;
 document.dispatchEvent({type:'vc:product-experience-disposed',detail:{root:{},dialog,reason:'device'}});
 check('stale other-product disposal cannot release the current polish owner',dialog.classList.contains('vc-dialog-polished'));
 const beforeDisposal=destroyed;
 document.dispatchEvent({type:'vc:product-experience-disposed',detail:{root,dialog,reason:'device'}});
 check('disabled device releases campaign clock timer and polished class',destroyed===beforeDisposal+1&&intervals.size===0&&!dialog.classList.contains('vc-dialog-polished'));
 document.dispatchEvent({type:'vc:product-experience-disposed',detail:{root,dialog,reason:'device'}});
 check('repeated disposal is idempotent',destroyed===beforeDisposal+1);
 root.classList.remove('vc-product-experience');document.dispatchEvent({type:'vc:product-presented',detail:{root,dialog}});
 check('native disabled-device presenter cannot revive campaign polish',intervals.size===0&&!dialog.classList.contains('vc-dialog-polished'));
 root.classList.add('vc-product-experience');document.dispatchEvent({type:'vc:product-dialog-selection',detail:{root,dialog}});
 check('returning to enabled device has exactly one new campaign owner',intervals.size===1&&dialog.classList.contains('vc-dialog-polished'));
 document.dispatchEvent({type:'vc:product-experience-disposed',detail:{root,dialog,reason:'destroy'}});
 console.log(JSON.stringify({passed:checks,actualBusinessRequests:0,scope:'actual module with isolated DOM/fetch; no browser/native cart'},null,2));
})().catch(error=>{console.error(error);process.exitCode=1;});
