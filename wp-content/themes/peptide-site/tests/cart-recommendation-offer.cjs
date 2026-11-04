'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {quotedRecommendation,quoteRequest,addPayload,addedMessage,offerUnconfirmed,enrichPresentation,productDiscountLabel}=require('../assets/live-cart-recommendation.js');
const m=n=>({minor:String(n),text:'$'+(Number(n)/100).toFixed(2)});
const now=Date.parse('2026-11-04T01:00:00Z');
function fixture(){
 const activity={enabled:true,visible:true,basis:'spend',status:'tracking',completed:false,stateKey:'state',promotionRevision:'promotion',revision:6,currency:'USD',currencyMinorUnit:2,cartSnapshotKey:'snapshot',actualDiscount:m(0),next:{key:'tier300',threshold:'30000'}};
 const quote={version:1,visible:true,reachesNextTier:true,targetNodeKey:'tier300',cartStateKey:'state',promotionRevision:'promotion',configRevision:6,cartSnapshotKey:'snapshot',currency:'USD',currencyMinorUnit:2,expiresAt:new Date(now+45000).toISOString(),productId:12,variationId:14,purchasableId:14,quantity:1,name:'Product',specification:'20 mg × 10 vials',image:'https://example.test/product.png',unitPrice:m(5500),linePrice:m(5500),projectedDiscount:m(2500),additionalSavings:m(2500),afterAdd:{calculationStatus:'quoted',targetReached:true,remaining:'0',eligibleSubtotal:m(31000),promotionDiscount:m(2500),allDiscountsBefore:m(0),allDiscountsAfter:m(3000),incrementalSavings:m(3000)}};
 return {activity,quote};
}
test('renders only one box, preserving authoritative incremental savings',()=>{
 const {activity,quote}=fixture();
 assert.equal(quotedRecommendation(quote,activity,'snapshot',now).displaySavings.minor,'3000');
 for(const quantity of [0,2,3,100,'1'])assert.equal(quotedRecommendation({...quote,quantity},activity,'snapshot',now),null);
});
test('rejects stale quotes, wrong cart/spec, currency and inconsistent savings',()=>{
 const {activity,quote}=fixture();
 for(const change of [{expiresAt:new Date(now-1).toISOString()},{cartSnapshotKey:'stale'},{configRevision:5},{currency:'EUR'},{purchasableId:12},{additionalSavings:m(2600)}])assert.equal(quotedRecommendation({...quote,...change},activity,'snapshot',now),null);
 assert.equal(quotedRecommendation({...quote,afterAdd:{...quote.afterAdd,incrementalSavings:m(3100)}},activity,'snapshot',now),null);
});
test('coupon quote must be usable with no manual code and a server ticket',()=>{
 const {activity,quote}=fixture(),coupon={enabled:true,message:'Extra order savings',requiresCodeEntry:false,discount:m(500)};
 const valid={...quote,offerToken:'x'.repeat(80)+'.'+'a'.repeat(64),automaticCoupon:coupon};
 assert.ok(quotedRecommendation(valid,activity,'snapshot',now));
 for(const change of [{offerToken:null},{automaticCoupon:{...coupon,requiresCodeEntry:true}},{automaticCoupon:{...coupon,discount:m(0)}}])assert.equal(quotedRecommendation({...valid,...change},activity,'snapshot',now),null);
});
test('old endpoint receives no unsupported new key; complete config advertises capability',()=>{
 const {activity}=fixture(),candidates=[{productId:12}];
 const old=quoteRequest(activity,'snapshot',candidates,{recommendation:{enabled:true}});
 assert.equal(Object.hasOwn(old,'supportsAutomaticCoupon'),false);
 assert.equal(quoteRequest(activity,'snapshot',candidates,{recommendationCoupon:{enabled:false,couponId:0,message:''}}).supportsAutomaticCoupon,true);
 assert.equal(Object.hasOwn(quoteRequest(activity,'snapshot',candidates,{recommendationCoupon:{enabled:true}}),'supportsAutomaticCoupon'),false);
});
test('one native mutation contains the ticket; never a separate coupon request',()=>{
 const {quote}=fixture();
 assert.deepEqual(addPayload(quote),{id:14,quantity:1});
 const token='x'.repeat(80)+'.'+'a'.repeat(64);
 assert.deepEqual(addPayload({...quote,offerToken:token,automaticCoupon:{enabled:true}}),{id:14,quantity:1,extensions:{peptide_core:{activityRecommendation:{offerToken:token}}}});
 assert.throws(()=>addPayload({...quote,quantity:2}));
 assert.throws(()=>addPayload({...quote,automaticCoupon:{enabled:true}}));
});
test('only the same mutation receipt confirms coupon; partial success never asks to add again',()=>{
 const {quote}=fixture(),candidate={...quote,automaticCoupon:{enabled:true}};
 const response=couponApplied=>({rawCart:{extensions:{peptide_core:{activity_recommendation:{requested:true,added:true,couponRequested:true,couponApplied}}}}});
 assert.match(addedMessage(candidate,response(true)),/offer has been applied/);
 assert.match(addedMessage(candidate,response(false)),/box added.*could not be applied/);
 assert.doesNotMatch(addedMessage(candidate,response(false)),/try again|add again|failed to add/i);
 assert.match(addedMessage(candidate,{rawCart:{}}),/Review your bag to confirm/);
});
test('an automatic coupon needs its own strict requested-and-applied receipt before claiming savings',()=>{
 const {quote}=fixture(),candidate={...quote,automaticCoupon:{enabled:true}};
 for(const couponRequested of [undefined,false,'true']){
  const result={rawCart:{extensions:{peptide_core:{activity_recommendation:{requested:true,added:true,couponRequested,couponApplied:true}}}}};
  assert.equal(offerUnconfirmed(candidate,result),true);
  assert.match(addedMessage(candidate,result),/box added.*could not be applied/);
  assert.doesNotMatch(addedMessage(candidate,result),/offer has been applied|add again/i);
 }
});
test('missing or false requested confirmation does not become an Added message',()=>{
 const {quote}=fixture(),candidate={...quote,automaticCoupon:{enabled:true}};
 for(const requested of [undefined,false,'true']){
  const result={rawCart:{extensions:{peptide_core:{activity_recommendation:{requested,added:true,couponRequested:true,couponApplied:true}}}}};
  assert.equal(offerUnconfirmed(candidate,result),true);
  assert.match(addedMessage(candidate,result),/Review your bag to confirm/);
  assert.doesNotMatch(addedMessage(candidate,result),/box added|offer has been applied/);
 }
});
test('optional regular unit price decorates the same native quote without turning order rewards into an item discount',()=>{
 const {activity,quote}=fixture();
 assert.equal(quotedRecommendation(quote,activity,'snapshot',now).regularUnitPrice,null);
 const regular=m(7800),priced=quotedRecommendation({...quote,regularUnitPrice:regular},activity,'snapshot',now);
 assert.deepEqual(priced.regularUnitPrice,regular);
 assert.equal(priced.unitPrice.minor,'5500');assert.equal(priced.displaySavings.minor,'3000');
 for(const invalid of [m(5500),m(5400),m(-1),{minor:'7800.5',text:'$78.00'},{minor:'7800',text:''},{minor:'9007199254740992',text:'$78.00'}]){
  const safe=quotedRecommendation({...quote,regularUnitPrice:invalid},activity,'snapshot',now);
  assert.ok(safe);assert.equal(safe.regularUnitPrice,null);
 }
});
function cardFor(quote){
 const native=(price)=>({...price,currency_code:quote.currency,currency_minor_unit:quote.currencyMinorUnit});
 return {id:quote.productId,card:{productId:quote.productId,purchaseId:quote.purchasableId,variationId:quote.variationId,price:native(quote.unitPrice),regularPrice:native(m(7800))}};
}
test('same-SKU display enrichment copies only genuine regular price and keeps native offer fields intact',async()=>{
 const {activity,quote}=fixture();let calls=0;
 const result=await enrichPresentation(quote,activity,'snapshot',{now:()=>now,read:async candidate=>{calls++;assert.equal(candidate,quote);return cardFor(quote);}});
 assert.equal(calls,1);assert.deepEqual(result,{...quote,regularUnitPrice:m(7800)});assert.equal(productDiscountLabel(result),'29% OFF');
 assert.equal(result.additionalSavings.minor,'2500');assert.equal(result.afterAdd.incrementalSavings.minor,'3000');assert.equal(quote.regularUnitPrice,undefined);
});
test('display reads with a different SKU, active price or money metadata never decorate a native quote',async()=>{
 const {activity,quote}=fixture(),exact=cardFor(quote);
 for(const response of [
  {...exact,id:13},{...exact,card:{...exact.card,productId:13}},{...exact,card:{...exact.card,purchaseId:15}},{...exact,card:{...exact.card,variationId:15}},
  {...exact,card:{...exact.card,price:{...exact.card.price,minor:'5600'}}},{...exact,card:{...exact.card,price:{...exact.card.price,currency_code:'EUR'}}},
  {...exact,card:{...exact.card,regularPrice:{...exact.card.regularPrice,currency_code:'EUR'}}},{...exact,card:{...exact.card,price:{...exact.card.price,currency_minor_unit:3}}},
  {...exact,card:{...exact.card,regularPrice:{...exact.card.regularPrice,currency_minor_unit:3}}},{...exact,card:{...exact.card,regularPrice:{...exact.card.regularPrice,minor:'5500'}}},
  {...exact,card:{...exact.card,price:{...exact.card.price,minor:'05500'}}},null,
 ])assert.equal(await enrichPresentation(quote,activity,'snapshot',{now:()=>now,read:async()=>response}),quote);
});
test('an authoritative native regular price skips display GET and is never replaced',async()=>{
 const {activity,quote}=fixture(),native={...quote,regularUnitPrice:m(8100)};let calls=0;
 assert.equal(await enrichPresentation(native,activity,'snapshot',{now:()=>now,read:async()=>{calls++;return cardFor(quote);}}),native);assert.equal(calls,0);
});
test('failed optional display reads preserve the usable native quote',async()=>{
 const {activity,quote}=fixture();assert.equal(await enrichPresentation(quote,activity,'snapshot',{now:()=>now,read:async()=>{throw Error('offline')}}),quote);
});
test('stale generation or expired quote cannot start or complete an optional display enrichment',async()=>{
 const {activity,quote}=fixture();let calls=0,current=true,resolve,clock=now;
 assert.equal(await enrichPresentation(quote,activity,'snapshot',{now:()=>now,isCurrent:()=>false,read:async()=>{calls++;}}),null);assert.equal(calls,0);
 assert.equal(await enrichPresentation(quote,activity,'stale',{now:()=>now,read:async()=>{calls++;}}),null);assert.equal(calls,0);
 const delayed=enrichPresentation(quote,activity,'snapshot',{now:()=>clock,isCurrent:()=>current,read:()=>new Promise(done=>{resolve=done;})});
 current=false;resolve(cardFor(quote));assert.equal(await delayed,null);
 current=true;const expired=enrichPresentation(quote,activity,'snapshot',{now:()=>clock,isCurrent:()=>current,read:()=>new Promise(done=>{resolve=done;})});
 clock=Date.parse(quote.expiresAt);resolve(cardFor(quote));assert.equal(await expired,null);
});
