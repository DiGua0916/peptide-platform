'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const recommendationSource=fs.readFileSync(path.join(__dirname,'../assets/live-cart-recommendation.js'),'utf8');
const feedbackSource=fs.readFileSync(path.join(__dirname,'../assets/live-cart-feedback.js'),'utf8');
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
const m=n=>({minor:String(n),text:'$'+(n/100).toFixed(2)});
function fixture({coupon=true,feedbackEnabled=true}={}){
  let now=Date.parse('2026-11-04T01:00:00Z'),serial=0,writes=0,reads=0;const timers=new Map(),requests=[],listeners=new Map();
  class Element{
    constructor(tag){this.tagName=tag.toUpperCase();this.childNodes=[];this._text='';this.className='';this.hidden=false;this.disabled=false;this.parentNode=null;this.dataset={};this.attrs=new Map();this.handlers=new Map();this.style={setProperty(){},getPropertyValue(){return '';},removeProperty(){}};this.classList={add:(...names)=>{const set=new Set(this.className.split(' ').filter(Boolean));names.forEach(n=>set.add(n));this.className=[...set].join(' ');},remove:(...names)=>{this.className=this.className.split(' ').filter(n=>!names.includes(n)).join(' ');},contains:n=>this.className.split(' ').includes(n)};}
    get children(){return this.childNodes;}get isConnected(){return true;}
    get textContent(){return this._text+this.childNodes.map(n=>n.textContent).join('');}set textContent(value){this._text=String(value);this.childNodes=[];}
    append(...nodes){for(const node of nodes){node.remove();node.parentNode=this;this.childNodes.push(node);}}
    replaceChildren(...nodes){for(const node of this.childNodes)node.parentNode=null;this.childNodes=[];this._text='';this.append(...nodes);}
    remove(){if(this.parentNode){this.parentNode.childNodes=this.parentNode.childNodes.filter(n=>n!==this);this.parentNode=null;}}
    setAttribute(k,v){this.attrs.set(k,String(v));}getAttribute(k){return this.attrs.get(k)??null;}removeAttribute(k){this.attrs.delete(k);}
    addEventListener(type,fn){if(!this.handlers.has(type))this.handlers.set(type,new Set());this.handlers.get(type).add(fn);}
    removeEventListener(type,fn){this.handlers.get(type)?.delete(fn);}
    click(){if(!this.disabled)for(const fn of [...(this.handlers.get('click')||[])])fn({target:this});}
    matches(selector){return selector.startsWith('.')?this.classList.contains(selector.slice(1)):this.tagName===selector.toUpperCase();}
    querySelectorAll(selector){const selectors=selector.split(','),found=[];for(const child of this.childNodes){if(selectors.some(s=>child.matches(s)))found.push(child);found.push(...child.querySelectorAll(selector));}return found;}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
    cloneNode(deep){const clone=new Element(this.tagName);clone.className=this.className;clone._text=this._text;if(deep)clone.append(...this.childNodes.map(n=>n.cloneNode(true)));return clone;}
  }
  const document={hidden:false,createElement:tag=>new Element(tag),createElementNS:(_,tag)=>new Element(tag),querySelectorAll:()=>[],addEventListener(type,fn){if(!listeners.has(type))listeners.set(type,new Set());listeners.get(type).add(fn);},removeEventListener(type,fn){listeners.get(type)?.delete(fn);}};
  document.body=new Element('body');
  const window={document,matchMedia:()=>({matches:false}),vcLiveCommerce:{cartFeedback:{config:{enabled:feedbackEnabled,burstEnabled:false,successDurationMs:1400}}}};
  class Clock extends Date{static now(){return now;}}
  const context={window,document,Date:Clock,URL,BigInt,AbortController,Promise,console,setTimeout:(fn,delay)=>{const key=++serial;timers.set(key,{fn,at:now+Number(delay||0)});return key;},clearTimeout:key=>timers.delete(key)};
  vm.runInNewContext(feedbackSource,context);vm.runInNewContext(recommendationSource,context);
  const mount=new Element('section');document.body.append(mount);
  const activity={enabled:true,visible:true,basis:'spend',status:'tracking',completed:false,stateKey:'state',promotionRevision:'promotion',revision:6,currency:'USD',currencyMinorUnit:2,cartSnapshotKey:'snapshot',actualDiscount:m(0),next:{key:'tier300',threshold:'30000',remaining:'4000',remainingText:'$40.00'},nodes:[{key:'tier300',rewardText:'$25 off'}]};
  activity.recommendation={version:1,visible:true,reachesNextTier:true,targetNodeKey:'tier300',cartStateKey:'state',promotionRevision:'promotion',configRevision:6,cartSnapshotKey:'snapshot',currency:'USD',currencyMinorUnit:2,expiresAt:new Date(now+45000).toISOString(),productId:12,variationId:14,purchasableId:14,quantity:1,name:'Product',specification:'20 mg × 10 vials',image:'https://example.test/product.png',unitPrice:m(5500),linePrice:m(5500),projectedDiscount:m(2500),additionalSavings:m(2500),afterAdd:{calculationStatus:'quoted',targetReached:true,remaining:'0',eligibleSubtotal:m(31500),promotionDiscount:m(2500),allDiscountsBefore:m(0),allDiscountsAfter:m(3000),incrementalSavings:m(3000)},offerToken:'x'.repeat(80)+'.'+'a'.repeat(64),automaticCoupon:{enabled:coupon,message:coupon?'Extra order savings':'',requiresCodeEntry:false,discount:m(coupon?500:0)}};
  let input={activity,snapshotKey:'snapshot',valid:true,pending:false};
  const ui=window.createCartRecommendation({mount,onAdd:()=>{writes++;return new Promise((resolve,reject)=>requests.push({resolve,reject}));},onRefresh:()=>{reads++;}});ui.update(input);
  const update=change=>{input={...input,...change};ui.update(input);};
  const changedCart=(overrides={})=>({...activity,cartSnapshotKey:'after',actualDiscount:m(2500),next:null,recommendation:undefined,...overrides});
  return {ui,mount,activity,requests,update,changedCart,button:()=>mount.querySelector('.offer-suggestion-action'),notice:()=>mount.querySelectorAll('.offer-suggestion-message').find(n=>!n.classList.contains('offer-suggestion-message--saving')),get writes(){return writes;},get reads(){return reads;},get timers(){return timers.size;},get listeners(){return [...listeners.values()].reduce((sum,set)=>sum+set.size,0);},
    async tick(ms){const target=now+ms;let guard=0;while(true){const next=[...timers.entries()].filter(([,t])=>t.at<=target).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;if(++guard>1000)throw Error('timer loop');now=next[1].at;timers.delete(next[0]);next[1].fn();await flush();}now=target;await flush();},
    result(couponApplied=true){return {confirmed:true,cartCount:2,rawCart:{extensions:{peptide_core:{activity_recommendation:{requested:true,added:true,couponRequested:coupon,couponApplied}}}}};}
  };
}
test('an already submitted one-box request survives quote expiry and receives its same-201 confirmation',async()=>{
  const f=fixture();await f.tick(40000);f.button().click();await flush();assert.equal(f.writes,1);await f.tick(5000);assert.equal(f.mount.hidden,false);assert.equal(f.button().disabled,true);
  f.update({activity:f.changedCart(),snapshotKey:'after'});f.requests[0].resolve(f.result());await flush();assert.match(f.notice().textContent,/box added.*offer has been applied/);assert.equal(f.mount.hidden,false);assert.equal(f.writes,1);f.ui.destroy();
});
test('partial coupon success remains readable after disabled feedback resets, with no second POST',async()=>{
  const f=fixture({feedbackEnabled:false});const button=f.button();button.click();button.click();await flush();assert.equal(f.writes,1);
  f.update({activity:f.changedCart(),snapshotKey:'after'});f.requests[0].resolve(f.result(false));await flush();await f.tick(50000);
  assert.equal(f.mount.hidden,false);assert.match(f.notice().textContent,/box added.*could not be applied/);assert.equal(f.mount.querySelector('.offer-suggestion-gap').hidden,true);assert.ok(f.mount.querySelector('.offer-suggestion-review'));
  button.click();await flush();assert.equal(f.writes,1);f.ui.destroy();
});
test('config changes after native submission do not discard an added:true coupon_changed response',async()=>{
  const f=fixture();f.button().click();await flush();assert.equal(f.writes,1);
  f.update({activity:f.changedCart({revision:7,promotionRevision:'new-promotion'}),snapshotKey:'after'});
  const result=f.result(false);result.rawCart.extensions.peptide_core.activity_recommendation.reason='coupon_changed';f.requests[0].resolve(result);await flush();await f.tick(1500);
  assert.equal(f.mount.hidden,false);assert.match(f.notice().textContent,/box added.*could not be applied/);assert.ok(f.mount.querySelector('.offer-suggestion-review'));f.ui.destroy();
});
test('a revision change before the feedback submit microtask blocks the stale POST',async()=>{
  const f=fixture();f.button().click();f.update({activity:{...f.activity,revision:7}});await flush();
  assert.equal(f.writes,0);assert.match(f.notice().textContent,/Could not confirm/);assert.ok(f.mount.querySelector('.offer-suggestion-review'));f.ui.destroy();
});
test('failed writes recover through an explicit refresh callback, never replaying the add',async()=>{
  const f=fixture();f.button().click();await flush();f.requests[0].reject(Error('Uncertain network response'));await flush();assert.equal(f.writes,1);assert.match(f.notice().textContent,/Could not confirm/);
  const review=f.mount.querySelector('.offer-suggestion-review');assert.ok(review);assert.equal(f.button().disabled,true);review.click();await flush();assert.equal(f.reads,1);assert.equal(f.writes,1);assert.equal(f.mount.hidden,true);f.ui.destroy();
});
test('a new cart snapshot replaces a completed partial result rather than reusing its old offer',async()=>{
  const f=fixture();f.button().click();await flush();f.update({activity:f.changedCart(),snapshotKey:'after'});f.requests[0].resolve(f.result(false));await flush();assert.equal(f.mount.hidden,false);
  f.update({activity:f.changedCart({cartSnapshotKey:'later'}),snapshotKey:'later'});assert.equal(f.mount.hidden,true);assert.equal(f.writes,1);f.ui.destroy();
});
test('destroy cancels presentation timers/listeners and a late native result cannot restore closed content',async()=>{
  const f=fixture();f.button().click();await flush();assert.ok(f.timers>0);assert.ok(f.listeners>0);f.ui.destroy();f.ui.destroy();assert.equal(f.timers,0);assert.equal(f.listeners,0);assert.equal(f.mount.hidden,true);
  f.requests[0].resolve(f.result());await flush();await f.tick(60000);assert.equal(f.mount.children.length,0);assert.equal(f.mount.hidden,true);assert.equal(f.writes,1);
});
test('compact quoted price and Add remain together, with only a same-quote regular price shown when supplied',()=>{
 const f=fixture();
 let commerce=f.mount.querySelector('.offer-suggestion-commerce');
 assert.equal(f.button().parentNode,commerce);assert.equal(f.button().textContent,'Add 1 box');
 assert.equal(commerce.querySelector('.offer-suggestion-current').textContent,'$55.00 / box');
 assert.equal(commerce.querySelector('.offer-suggestion-regular'),null);
 assert.doesNotMatch(f.mount.textContent,/Add 1 box to unlock|additional savings/);
 assert.match(f.button().getAttribute('aria-label'),/\$55\.00.*Product.*20 mg/);
 f.update({activity:{...f.activity,recommendation:{...f.activity.recommendation,regularUnitPrice:m(7800)}}});
 commerce=f.mount.querySelector('.offer-suggestion-commerce');
 assert.equal(commerce.querySelector('.offer-suggestion-regular').textContent,'$78.00');
 assert.equal(commerce.querySelector('.offer-suggestion-current').classList.contains('offer-suggestion-current--sale'),true);
 assert.equal(f.button().parentNode,commerce);assert.equal(f.writes,0);f.ui.destroy();
});
