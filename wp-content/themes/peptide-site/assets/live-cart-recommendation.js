/* Optional server-quoted offer recommendation. Local viewing history only suggests a product to revisit. */
(function (global) {
 'use strict';
 const text=v=>typeof v==='string'&&v.trim().length>0&&v.length<=500;
 const id=v=>Number.isSafeInteger(v)&&v>0;
 const minor=(value,signed=false)=>typeof value==='string'&&(signed?/^-?\d{1,16}$/:/^\d{1,16}$/).test(value)&&Number.isSafeInteger(Number(value));
 const money=(v,signed=false)=>v&&minor(v.minor,signed)&&text(v.text);
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
  if(!Number.isFinite(expires)||expires<=now||expires-now>300000||!id(raw.productId)||!Number.isSafeInteger(raw.variationId)||raw.variationId<0||!Number.isSafeInteger(raw.quantity)||raw.quantity<1||raw.quantity>100)return null;
  if(raw.purchasableId!==(raw.variationId||raw.productId))return null;
  const image=safeImage(typeof raw.image==='object'?raw.image?.url:raw.image);
  if(!text(raw.name)||typeof raw.specification!=='string'||raw.specification.length>500||![raw.unitPrice,raw.linePrice,raw.projectedDiscount].every(v=>money(v))||!money(raw.additionalSavings,true)||!minor(activity.actualDiscount?.minor))return null;
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
 function createCartRecommendation({mount,onChoose,onAdd}){
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
  }
  function begin(candidate,button,notice){
   if(operation||destroyed||current!==candidate||button.disabled)return;
   const op={phase:'pending',candidate,button,notice,feedback,snapshot:latest?.snapshotKey};operation=op;mount.setAttribute('aria-busy','true');
   const submit=()=>{
    // Feedback defers submission by one microtask; recheck the cart at that boundary.
    if(destroyed||operation!==op||Date.now()>=candidate.expires||!latest?.valid||latest.pending||latest.snapshotKey!==candidate.cartSnapshotKey)throw new Error('Your bag changed. Review the current offer.');
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
     op.phase='success';op.snapshot=latest?.snapshotKey;clearTimeout(expiryTimer);button.textContent='Added';button.setAttribute('aria-busy','false');mount.setAttribute('aria-busy','false');
     notice.hidden=false;notice.textContent=candidate.name+' · '+candidate.quantity+(candidate.quantity===1?' box added to your bag.':' boxes added to your bag.');
     resetTimer=setTimeout(()=>finish(op),1400);
    }).catch(()=>failure(op));
   }
  }
  function update(input){
   if(destroyed)return;
   latest=input;const {activity,snapshotKey,frequent=[],catalog=[],cartItems=[],pending=false,valid=false,nearRatio=.2}=input;
   if(operation){
    const op=operation,configChanged=activity&&(!activity.enabled||!activity.visible||activity.revision!==op.candidate.configRevision||activity.promotionRevision!==op.candidate.promotionRevision);
    if(configChanged){clear();}
    else if(op.phase==='pending')return; // Own request: keep a disabled stable target for confirmed feedback.
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
   const copy=candidate.mode==='quoted'?'Add '+candidate.quantity+(candidate.quantity===1?' box':' boxes')+' to save an estimated '+candidate.displaySavings.text+' more.':'';
   const message=copy?el('p','offer-suggestion-message',copy):null,button=el('button','offer-suggestion-action',candidate.mode==='quoted'?'Add '+candidate.quantity+(candidate.quantity===1?' box':' boxes')+' · '+candidate.linePrice.text:'View options');button.type='button';
   button.setAttribute('aria-label',button.textContent+' — '+candidate.name+' '+candidate.specification);
   const notice=el('p','offer-suggestion-message');notice.hidden=true;notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');notice.setAttribute('aria-atomic','true');
   button.addEventListener('click',()=>{if(destroyed||current!==candidate)return;if(candidate.mode==='quoted'){if(Date.now()>=candidate.expires){clear();return;}begin(candidate,button,notice);}else onChoose?.(candidate);});
   info.append(title);if(candidate.specification.trim())info.append(pack);if(message)info.append(message);info.append(button,notice);row.append(image,info);mount.append(eyebrow,gap,row);mount.hidden=false;
   const feedbackConfig=global?.vcLiveCommerce?.cartFeedback?.config||{};
   if(candidate.mode==='quoted'&&typeof global?.createLiveCartFeedback==='function')feedback=global.createLiveCartFeedback({buttons:[button],bagCountElements:[...(document.querySelectorAll?.('.vc-cart-count')||[])],bagPulseEnabled:feedbackConfig.enabled!==false&&feedbackConfig.bagPulseEnabled!==false,announce:notice,timeoutMs:25000,successMs:feedbackConfig.enabled===false?0:feedbackConfig.successDurationMs,burstEnabled:feedbackConfig.enabled!==false&&feedbackConfig.burstEnabled!==false,burstDurationMs:feedbackConfig.burstDurationMs,successText:feedbackConfig.successText,pendingText:feedbackConfig.pendingText,onStateChange:state=>{
    const op=operation;if(!op||op.candidate!==candidate)return;
    if(state==='success'){op.phase='success';op.snapshot=latest?.snapshotKey;clearTimeout(expiryTimer);mount.setAttribute('aria-busy','false');}
    if(state==='error')failure(op);
    if(state==='idle'&&op.phase==='success')finish(op);
   }});
   if(candidate.mode==='quoted')expiryTimer=setTimeout(clear,Math.max(1,candidate.expires-Date.now()));
  }
  return {update,clear,destroy(){destroyed=true;latest=null;clear();}};
 }
 const api={nearNextTier,quotedRecommendation,viewedSuggestion,safeImage,createCartRecommendation};
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(global)global.createCartRecommendation=createCartRecommendation;
})(typeof window==='undefined'?null:window);
