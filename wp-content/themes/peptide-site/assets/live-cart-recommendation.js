/* Optional server-quoted offer recommendation. Local viewing history only suggests a product to revisit. */
(function (global) {
 'use strict';
 const text=v=>typeof v==='string'&&v.trim().length>0&&v.length<=500;
 const id=v=>Number.isSafeInteger(v)&&v>0;
 const minor=(value,signed=false)=>typeof value==='string'&&(signed?/^-?\d{1,16}$/:/^\d{1,16}$/).test(value)&&Number.isSafeInteger(Number(value));
 const money=(v,signed=false)=>v&&minor(v.minor,signed)&&text(v.text);
 const offerToken=value=>typeof value==='string'&&value.length>=40&&value.length<=8192&&/^[A-Za-z0-9_.-]+$/.test(value);
 // Old v1 endpoints reject unknown properties. Only the complete published new
 // config advertises this protocol; never infer it from the theme version.
 function quoteRequest(activity,snapshotKey,candidates,config){
  const body={cartStateKey:activity.stateKey,cartSnapshotKey:snapshotKey,promotionRevision:activity.promotionRevision,configRevision:activity.revision,candidates:candidates.slice(0,20)};
  const coupon=config?.recommendationCoupon;
  if(coupon&&typeof coupon.enabled==='boolean'&&Number.isSafeInteger(coupon.couponId)&&coupon.couponId>=0&&typeof coupon.message==='string')body.supportsAutomaticCoupon=true;
  return body;
 }
 function addPayload(candidate){
  if(candidate?.quantity!==1||!id(candidate.purchasableId))throw new Error('The offer changed. Review your bag.');
  const body={id:candidate.purchasableId,quantity:1};
  if(candidate.automaticCoupon!==undefined){
   if(!offerToken(candidate.offerToken))throw new Error('The offer expired. Review your bag.');
   body.extensions={peptide_core:{activityRecommendation:{offerToken:candidate.offerToken}}};
  }
  return body;
 }
 function addedMessage(candidate,result){
  const base=candidate.name+' · 1 box added to your bag.';
  if(candidate.automaticCoupon===undefined)return base;
  // The operation receipt exists only on this add response, not on a later GET.
  const receipt=result?.rawCart?.extensions?.peptide_core?.activity_recommendation;
  if(receipt?.added!==true)return 'Your bag was updated. Review your bag to confirm the item and current offer.';
  if(candidate.automaticCoupon.enabled&&receipt.couponApplied!==true)return base+' The automatic offer could not be applied. Review your bag before continuing.';
  return candidate.automaticCoupon.enabled?base+' Your automatic offer has been applied.':base;
 }
 function offerUnconfirmed(candidate,result){
  if(candidate.automaticCoupon===undefined)return false;
  const receipt=result?.rawCart?.extensions?.peptide_core?.activity_recommendation;
  return receipt?.added!==true||(candidate.automaticCoupon.enabled&&receipt.couponApplied!==true);
 }
 function safeImage(value){
  if(!text(value))return '';
  try{const url=new URL(value,'http://preview.local/');return ['http:','https:'].includes(url.protocol)&&!url.username&&!url.password?value:'';}catch{return '';}
 }
 function nearNextTier(activity,ratio=.2){
  if(!activity||activity.enabled!==true||activity.visible!==true||activity.completed||activity.basis!=='spend'||!activity.next||!['tracking','tier_reached'].includes(activity.status))return false;
  const gap=Number(activity.next.remaining),threshold=Number(activity.next.threshold);
  return Number.isSafeInteger(gap)&&gap>0&&Number.isSafeInteger(threshold)&&threshold>0&&gap<=threshold*Math.max(0,Math.min(.5,ratio));
 }
 function quotedRecommendation(raw,activity,snapshotKey,now=Date.now()){
  if(!raw||raw.version!==1||raw.visible!==true||raw.reachesNextTier!==true||!activity?.next||activity.completed||activity.enabled!==true||activity.visible!==true||activity.basis!=='spend'||!['tracking','tier_reached'].includes(activity.status))return null;
  if(!text(activity.stateKey)||!text(activity.promotionRevision)||!text(activity.next.key)||raw.targetNodeKey!==activity.next.key||!Number.isSafeInteger(activity.revision)||raw.configRevision!==activity.revision)return null;
  if(raw.cartStateKey!==activity.stateKey||raw.promotionRevision!==activity.promotionRevision||!text(snapshotKey)||activity.cartSnapshotKey!==snapshotKey||raw.cartSnapshotKey!==snapshotKey)return null;
  if(!/^[A-Z]{3}$/.test(activity.currency)||raw.currency!==activity.currency||!Number.isSafeInteger(activity.currencyMinorUnit)||activity.currencyMinorUnit<0||activity.currencyMinorUnit>8||raw.currencyMinorUnit!==activity.currencyMinorUnit)return null;
  const expires=Date.parse(raw.expiresAt);
  if(!Number.isFinite(expires)||expires<=now||expires-now>300000||!id(raw.productId)||!Number.isSafeInteger(raw.variationId)||raw.variationId<0||raw.quantity!==1)return null;
  if(raw.purchasableId!==(raw.variationId||raw.productId))return null;
  const image=safeImage(typeof raw.image==='object'?raw.image?.url:raw.image);
  if(!text(raw.name)||typeof raw.specification!=='string'||raw.specification.length>500||![raw.unitPrice,raw.linePrice,raw.projectedDiscount].every(v=>money(v))||!money(raw.additionalSavings,true)||!minor(activity.actualDiscount?.minor))return null;
  if(raw.automaticCoupon!==undefined){
   const coupon=raw.automaticCoupon;
   if(!coupon||typeof coupon.enabled!=='boolean'||coupon.requiresCodeEntry!==false||typeof coupon.message!=='string'||coupon.message.length>500||!money(coupon.discount)||!offerToken(raw.offerToken))return null;
   if(coupon.enabled&&BigInt(coupon.discount.minor)<=0n)return null;
  }
  const current=BigInt(activity.actualDiscount.minor),projected=BigInt(raw.projectedDiscount.minor),extra=BigInt(raw.additionalSavings.minor);
  // Native line rounding is authoritative; rounded unit price × quantity can differ by a minor unit.
  if(projected-current!==extra)return null;
  const after=raw.afterAdd;
  if(!after||after.calculationStatus!=='quoted'||after.targetReached!==true||String(after.remaining)!=='0'||!minor(activity.next.threshold)||BigInt(activity.next.threshold)<=0n||![after.eligibleSubtotal,after.promotionDiscount,after.allDiscountsBefore,after.allDiscountsAfter].every(v=>money(v))||!money(after.incrementalSavings,true))return null;
  if(BigInt(after.eligibleSubtotal.minor)<BigInt(activity.next.threshold)||BigInt(after.promotionDiscount.minor)!==projected||BigInt(after.incrementalSavings.minor)<=0n||BigInt(after.allDiscountsAfter.minor)-BigInt(after.allDiscountsBefore.minor)!==BigInt(after.incrementalSavings.minor))return null;
  return {...raw,image,mode:'quoted',expires,displaySavings:after.incrementalSavings};
 }
 function viewedSuggestion(activity,frequent,catalog,cartItems=[],ratio=.2){
  if(!nearNextTier(activity,ratio)||!Array.isArray(frequent))return null;
  for(const viewed of frequent){
   const product=catalog.find(p=>p.id===Number(viewed.id));
   if(!product||product.available!==true)continue;
   const prior=cartItems.find(item=>item.productId===product.id);
   const variant=product.variations.find(v=>v.id===prior?.variationId&&v.available===true)||product.variations.find(v=>v.available===true);
   if(variant&&safeImage(variant.image))return {mode:'viewed',productId:product.id,variationId:variant.id,name:product.name,specification:variant.label,image:variant.image,quantity:1};
  }
  return null;
 }
 function createCartRecommendation({mount,onChoose,onAdd,onRefresh}){
  let signature='',current=null,expiryTimer=null,resetTimer=null,destroyed=false,latest=null,feedback=null,operation=null;
  const el=(tag,cls,copy)=>{const n=document.createElement(tag);n.className=cls;if(copy!==undefined)n.textContent=copy;return n;};
  function gapMessage(activity){
   if(typeof global?.cartProgressMessage==='function')return global.cartProgressMessage(activity);
   const reward=activity.nodes?.find(node=>node.key===activity.next?.key)?.rewardText;
   if(!text(activity.next?.remainingText)||!text(reward))return '';
   const lead='Spend '+activity.next.remainingText+' more to unlock ',value=reward.trim();
   return /\boff$/i.test(value)?lead+value+' your order.':lead+'your next order offer: '+value+'.';
  }
  function clear(){clearTimeout(expiryTimer);clearTimeout(resetTimer);operation=null;feedback?.destroy();feedback=null;current=null;signature='';mount.hidden=true;mount.removeAttribute('aria-busy');mount.replaceChildren();}
  function finish(op){if(destroyed||operation!==op)return;const next=latest;clear();if(next)update(next);}
  function failure(op){
   if(destroyed||operation!==op)return;
   op.phase='error';op.button.disabled=true;op.button.setAttribute('aria-busy','false');mount.setAttribute('aria-busy','false');
   op.notice.hidden=false;op.notice.textContent='Could not confirm the update. Check your bag before trying again.';
   hideOfferClaims();reviewButton();
  }
  function hideOfferClaims(){for(const item of mount.querySelectorAll('.offer-suggestion-gap,.offer-suggestion-message--saving,.offer-suggestion-coupon'))item.hidden=true;}
  function reviewButton(){
   if(typeof onRefresh!=='function'||mount.querySelector('.offer-suggestion-review'))return;
   const review=el('button','offer-suggestion-action offer-suggestion-review','Review current offer');review.type='button';
   review.addEventListener('click',()=>{clear();onRefresh();});mount.append(review);
  }
  function begin(candidate,button,notice){
   if(operation||destroyed||current!==candidate||button.disabled)return;
   const op={phase:'pending',candidate,button,notice,feedback,snapshot:latest?.snapshotKey};operation=op;mount.setAttribute('aria-busy','true');
   const submit=()=>{
    // Feedback defers submission by one microtask; recheck the cart at that boundary.
    const activity=latest?.activity;
    if(destroyed||operation!==op||Date.now()>=candidate.expires||!latest?.valid||latest.pending||latest.snapshotKey!==candidate.cartSnapshotKey||activity?.enabled!==true||activity.visible!==true||activity.revision!==candidate.configRevision||activity.promotionRevision!==candidate.promotionRevision)throw new Error('Your bag changed. Review the current offer.');
    return onAdd?.(candidate,button);
   };
   if(feedback){
    feedback.run({submit,quantity:candidate.quantity,productName:candidate.name,triggerButton:button}).then(result=>{
     if(operation===op&&!result.ok)failure(op);
    });
   }else{
    // Conservative standalone fallback: no celebration until a confirmed cart response.
    button.disabled=true;button.textContent='Adding…';button.setAttribute('aria-busy','true');
    Promise.resolve().then(()=>operation===op?submit():null).then(result=>{
     if(destroyed||operation!==op)return;
     if(result?.confirmed!==true||!Number.isSafeInteger(result.cartCount)||result.cartCount<0){failure(op);return;}
     op.phase='success';op.partial=offerUnconfirmed(candidate,result);op.snapshot=latest?.snapshotKey;clearTimeout(expiryTimer);button.textContent='Added';button.setAttribute('aria-busy','false');mount.setAttribute('aria-busy','false');
     notice.hidden=false;notice.textContent=addedMessage(candidate,result);
     if(op.partial){hideOfferClaims();reviewButton();}else resetTimer=setTimeout(()=>finish(op),1400);
    }).catch(()=>failure(op));
   }
  }
  function update(input){
   if(destroyed)return;
   latest=input;const {activity,snapshotKey,frequent=[],catalog=[],cartItems=[],pending=false,valid=false,nearRatio=.2}=input;
   if(operation){
    const op=operation,configChanged=activity&&(!activity.enabled||!activity.visible||activity.revision!==op.candidate.configRevision||activity.promotionRevision!==op.candidate.promotionRevision);
    // A config/coupon can change during the native write. Retire the quote's
    // promises, but keep ownership of that one response (including partial success).
    if(op.phase==='pending'){if(configChanged)hideOfferClaims();return;}
    if(op.phase==='success'&&op.partial&&!pending&&valid&&snapshotKey===op.snapshot){hideOfferClaims();return;}
    if(configChanged){clear();}
    else if(op.phase==='success'&&!pending&&valid&&snapshotKey===op.snapshot)return;
    else if(op.phase==='error'&&!pending&&snapshotKey===op.snapshot&&activity?.recommendation?.expiresAt===op.candidate.expiresAt)return;
    else clear();
   }
   if(!valid||pending){clear();return;}
   // If a server recommendation is explicitly hidden/invalid, never replace it with a local promotion promise.
   const candidate=activity?.recommendation!==undefined?quotedRecommendation(activity.recommendation,activity,snapshotKey):viewedSuggestion(activity,frequent,catalog,cartItems,nearRatio);
   if(!candidate){clear();return;}
   const gapCopy=gapMessage(activity),nextSignature=JSON.stringify([candidate,activity.stateKey,gapCopy]);if(signature===nextSignature)return;
   clear();signature=nextSignature;current=candidate;
   const eyebrow=el('p','offer-suggestion-eyebrow',candidate.mode==='quoted'?'Suggested add-on':'From your browsing');
   const gap=el('p','offer-suggestion-gap',gapCopy);gap.hidden=!gapCopy;
   const row=el('article','offer-suggestion');
   row.setAttribute('aria-label',candidate.mode==='quoted'?'A product for your next offer':'A product you viewed');
   const image=candidate.image?el('img','offer-suggestion-image'):el('span','offer-suggestion-image offer-suggestion-image--empty','No image');
   if(candidate.image){image.src=candidate.image;image.alt=(candidate.name+' '+candidate.specification).trim();image.width=48;image.height=56;}else image.setAttribute('aria-hidden','true');
   const info=el('div','offer-suggestion-info'),title=el('h3','offer-suggestion-name',candidate.name),pack=el('p','offer-suggestion-pack',candidate.specification);
   const copy=candidate.mode==='quoted'?'Add 1 box to unlock '+candidate.displaySavings.text+' in additional savings.':'';
   const message=copy?el('p','offer-suggestion-message offer-suggestion-message--saving',copy):null,button=el('button','offer-suggestion-action',candidate.mode==='quoted'?'Add 1 box · '+candidate.linePrice.text:'View options');button.type='button';
   button.setAttribute('aria-label',button.textContent+' — '+candidate.name+' '+candidate.specification);
   const notice=el('p','offer-suggestion-message');notice.hidden=true;notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');notice.setAttribute('aria-atomic','true');
   button.addEventListener('click',()=>{if(destroyed||current!==candidate)return;if(candidate.mode==='quoted'){if(Date.now()>=candidate.expires){clear();return;}begin(candidate,button,notice);}else onChoose?.(candidate);});
   info.append(title);if(candidate.specification.trim())info.append(pack);
   if(candidate.mode==='quoted')info.append(el('p','offer-suggestion-price',candidate.unitPrice.text+' / box'));
   if(candidate.automaticCoupon?.enabled)info.append(el('p','offer-suggestion-coupon',(candidate.automaticCoupon.message||'Automatic offer')+' · Applied when added'));
   if(message)info.append(message);info.append(button,notice);row.append(image,info);mount.append(eyebrow,gap,row);mount.hidden=false;
   const feedbackConfig=global?.vcLiveCommerce?.cartFeedback?.config||{};
   if(candidate.mode==='quoted'&&typeof global?.createLiveCartFeedback==='function')feedback=global.createLiveCartFeedback({buttons:[button],bagCountElements:[...(document.querySelectorAll?.('.vc-cart-count')||[])],bagPulseEnabled:feedbackConfig.enabled!==false&&feedbackConfig.bagPulseEnabled!==false,announce:notice,timeoutMs:25000,successMs:feedbackConfig.enabled===false?0:feedbackConfig.successDurationMs,burstEnabled:feedbackConfig.enabled!==false&&feedbackConfig.burstEnabled!==false,burstDurationMs:feedbackConfig.burstDurationMs,successText:feedbackConfig.successText,pendingText:feedbackConfig.pendingText,onStateChange:state=>{
    const op=operation;if(!op||op.candidate!==candidate)return;
    if(state==='success'){op.phase='success';op.snapshot=latest?.snapshotKey;clearTimeout(expiryTimer);mount.setAttribute('aria-busy','false');}
    if(state==='error')failure(op);
    if(state==='idle'&&op.phase==='success'&&!op.partial)finish(op);
   },onSuccess:result=>{if(operation?.candidate===candidate){operation.partial=offerUnconfirmed(candidate,result);notice.hidden=false;notice.textContent=addedMessage(candidate,result);if(operation.partial){hideOfferClaims();reviewButton();}}}});
   // Expiration invalidates an unsubmitted offer. A request already in flight
   // still owns its one native response and must retain confirmed feedback.
   if(candidate.mode==='quoted')expiryTimer=setTimeout(()=>{if(operation?.phase!=='pending')clear();},Math.max(1,candidate.expires-Date.now()));
  }
  return {update,clear,destroy(){destroyed=true;latest=null;clear();}};
 }
 const api={nearNextTier,quotedRecommendation,viewedSuggestion,safeImage,createCartRecommendation,quoteRequest,addPayload,addedMessage,offerUnconfirmed};
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(global){global.createCartRecommendation=createCartRecommendation;global.vcRecommendationContract={quoteRequest,addPayload};}
})(typeof window==='undefined'?null:window);
