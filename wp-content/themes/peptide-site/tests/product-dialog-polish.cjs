/* Actual independent module; fake DOM/network boundary, never native cart or browser data. */
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
let checks=0,now=1000,mounted=0,clockUpdates=0,destroyed=0,nativeOpens=0,requests=[],reloads=0,fetchReply=null,nextTimer=1,lastClockData=null;
const intervals=new Map(),timeouts=new Map(),observers=[];
class Target {constructor(){this.events=new Map();}addEventListener(k,cb){if(!this.events.has(k))this.events.set(k,new Set());this.events.get(k).add(cb);}removeEventListener(k,cb){this.events.get(k)?.delete(cb);}dispatchEvent(event){event.target??=this;for(const cb of [...this.events.get(event.type)||[]])cb(event);return true;}}
class Element extends Target{
 constructor(tag='div'){super();this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.attributes={};this.parentElement=null;this.hidden=false;this.disabled=false;this.map={};this.classes=new Set();this.classList={contains:x=>this.classes.has(x),add:(...xs)=>xs.forEach(x=>this.classes.add(x))};this._text='';}
 set className(s){this.classes=new Set(s.split(/\s+/));}get className(){return [...this.classes].join(' ');}get isConnected(){return true;}
 set textContent(s){this._text=String(s);this.children=[];}get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
 append(...nodes){for(const node of nodes){node.remove();node.parentElement=this;this.children.push(node);}}prepend(node){node.remove();node.parentElement=this;this.children.unshift(node);}replaceChildren(...nodes){this.children.forEach(x=>x.parentElement=null);this.children=[];this._text='';this.append(...nodes);}
 remove(){if(this.parentElement){const list=this.parentElement.children;const i=list.indexOf(this);if(i>=0)list.splice(i,1);this.parentElement=null;}}after(node){const parent=this.parentElement;if(!parent)return;node.remove();node.parentElement=parent;parent.children.splice(parent.children.indexOf(this)+1,0,node);}
 get lastElementChild(){return this.children.at(-1);}contains(node){return node===this||this.children.some(x=>x.contains(node));}setAttribute(k,v){this.attributes[k]=v;}focus(){document.activeElement=this;}
 matches(selector){return selector.split(',').some(raw=>{const s=raw.trim();return s==='button'&&this.tagName==='BUTTON'||s.startsWith('.')&&this.classes.has(s.slice(1));});}
 querySelector(selector){return this.map[selector]||this.children.find(x=>selector==='p'&&x.tagName==='P'||selector==='button'&&x.tagName==='BUTTON')||null;}querySelectorAll(selector){return [];}
 click(){if(this.disabled)return;this.dispatchEvent({type:'click'});this.onclick?.();if(this.dataset.vcQuickOpen){nativeOpens++;document.dispatchEvent({type:'vc:product-presented',detail:{root,dialog,productId:Number(this.dataset.vcQuickOpen)}});}}
}
class Observer {constructor(cb){this.cb=cb;this.targets=[];observers.push(this);}observe(node,options){this.targets.push({node,options});}disconnect(){this.targets=[];}}
const document=new Target();document.readyState='complete';document.hidden=false;document.documentElement={lang:'en'};document.activeElement=null;document.createElement=tag=>new Element(tag);document.body=new Element('body');
const main=new Element('main'),root=new Element(),dialog=new Element('dialog'),toolbar=new Element(),heading=new Element('header'),broadcast=new Element('button'),offers=new Element('section'),close=new Element('button'),form=new Element('form');
root.className='vc-product-experience';root.dataset.vcHomeInitial=JSON.stringify({promotions:[{id:37,status:'active',tiers:[{text:'Real tier'}]}]});root.dataset.vcProductId='11306';dialog.open=true;close.className='vc-showcase-quick__close';offers.className='vc-dialog-offers';
const navs=['Overview','Details','COA','Bag'].map(name=>{const e=new Element(name==='Bag'?'a':'button');e.textContent=name;if(name==='Bag')e.className='vc-dialog-bag';return e;});
document.body.append(main,dialog);dialog.append(toolbar,root);root.append(heading,form);heading.append(broadcast);toolbar.append(...navs,close,offers);document.activeElement=navs[1];
root.map['.vc-dialog-offers']=null;root.map['.vc-dialog-heading']=heading;heading.map['[data-vc-product-broadcast]']=broadcast;
dialog.map[':scope>.vc-home-quick__toolbar>.vc-dialog-offers']=offers;dialog.map[':scope>.vc-home-quick__toolbar']=toolbar;dialog.map['.vc-showcase-quick__close']=close;
const template={};root.closest=selector=>selector==='dialog'?dialog:null;document.querySelector=selector=>selector==='main'?main:selector==='dialog.vc-showcase-quick[open] .vc-home-quick-product'?root:selector==='template[data-vc-quick-product="11306"]'?template:null;
const dto={version:1,position:'product',enabled:true,visible:true,state:'active',promotionId:37,title:'Black Friday <script>',tiers:[{min:300,value:25,text:'Spend $300, get $25 off'}],starts:900,ends:2000,target:2000,serverNow:1000};
const config={version:1,productId:0,catalog:{},campaign:dto,release:'a'.repeat(64),releaseUrl:'https://store.test/wp-json/vialcove/v1/storefront-entry',campaignUrl:'https://store.test/wp-json/peptide/v1/campaign-countdown'};
const window=new Target();Object.assign(window,{document,vcStorefrontEntry:config,performance:{now:()=>now,getEntriesByType:()=>[{responseEnd:1000}]},location:{href:'https://store.test/',origin:'https://store.test',pathname:'/',hash:'',reload(){reloads++;}},history:{state:{}},sessionStorage:{getItem(){return null;},setItem(){}},PCCampaignCountdown:{mount(host,data){mounted++;lastClockData=data;return{update(next){clockUpdates++;lastClockData=next;},destroy(){destroyed++;}}}},vcHomeQuickView:{productApi:'https://store.test/wp-json/vialcove/v1/home-product/'}});
const fetch=async(url,options)=>{requests.push({url,options});if(fetchReply)return fetchReply;throw new Error('Synthetic offline');};
const setInterval=(cb,delay)=>{const id=nextTimer++;intervals.set(id,{cb,delay});return id;},clearInterval=id=>intervals.delete(id),setTimeout=(cb,delay)=>{const id=nextTimer++;timeouts.set(id,{cb,delay});return id;},clearTimeout=id=>timeouts.delete(id);
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/product-dialog-polish.js'),'utf8'),{window,fetch,MutationObserver:Observer,AbortController,URL,CustomEvent:class{constructor(type,options={}){this.type=type;Object.assign(this,options);}},Event:class{constructor(type,options={}){this.type=type;Object.assign(this,options);}},setInterval,clearInterval,setTimeout,clearTimeout,console});
const api=window.VialCoveStorefrontEntry;function check(name,okay){assert.ok(okay,name);checks++;}
(async()=>{
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
 const recovery=main.children[0],button=recovery.querySelector('button');check('usable opener remains for native close focus',button&&!button.disabled&&button.dataset.vcQuickOpen==='11306');
 button.click();check('manual reopen invokes one native presenter, no helper recursion',nativeOpens===2);
 const before=nativeOpens;const failed=await api.openProduct(99);check('read failure stays visible retry instead of old PDP redirect',failed===false&&nativeOpens===before&&recovery.querySelector('p').textContent.includes('could not load')&&!button.disabled&&!button.dataset.vcQuickOpen);
 check('all module requests are read-only',requests.every(r=>!r.options.method&&!r.options.body&&r.options.cache==='no-store'));
 fetchReply={ok:true,json:async()=>({version:1,release:'a'.repeat(64)})};await [...window.events.get('pageshow')][0]({persisted:true});check('same release does not reload',reloads===0);
 fetchReply={ok:true,json:async()=>({version:1,release:'b'.repeat(64)})};await [...window.events.get('pageshow')][0]({persisted:true});check('confirmed release mismatch reloads clean public page once',reloads===1);
 const input={closest:()=>form};document.dispatchEvent({type:'input',isTrusted:true,target:input});await [...window.events.get('pageshow')][0]({persisted:true});check('unsaved form is never automatically reloaded',reloads===1&&recovery.querySelector('p').textContent.includes('newer store'));
 window.location.pathname='/checkout/';await [...window.events.get('pageshow')][0]({persisted:true});check('checkout never reloads from this adapter',reloads===1);
 console.log(JSON.stringify({passed:checks,actualBusinessRequests:0,scope:'actual module with isolated DOM/fetch; no browser/native cart'},null,2));
})().catch(error=>{console.error(error);process.exitCode=1;});
