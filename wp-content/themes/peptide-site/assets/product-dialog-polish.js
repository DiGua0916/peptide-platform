/* Explicit native promotion binding and public-entry restoration. No alternate cart/order flow. */
(function (global) {
 'use strict';
 const document = global.document, config = global.vcStorefrontEntry || {}, owners = new WeakMap();
 const receivedNow=global.performance.now(),responseEnd=global.performance.getEntriesByType?.('navigation')?.[0]?.responseEnd;
 // The page DTO is received once. Reopening a dialog must not restart its server clock.
 const pageCampaignAnchor=Number.isFinite(responseEnd)&&responseEnd>0&&responseEnd<=receivedNow?responseEnd:receivedNow;
 const lang = () => /^es(?:-|$)/i.test(global.PCStorefrontI18n?.language || document.documentElement.lang || '') ? 'es' : 'en';
 const text = key => ({en:{loading:'Loading product…',failed:'Product could not load. Please try again.',cancelled:'Loading cancelled. You can try again.',retry:'Try again',open:'View product',ready:'Product ready.',unavailable:'This product is not currently available.',catalog:'This collection is not available in the current catalogue.',updated:'A newer store version is available.',reload:'Refresh page'},es:{loading:'Cargando producto…',failed:'No se pudo cargar el producto. Inténtalo de nuevo.',cancelled:'Carga cancelada. Puedes volver a intentarlo.',retry:'Reintentar',open:'Ver producto',ready:'Producto disponible.',unavailable:'Este producto no está disponible actualmente.',catalog:'Esta colección no está disponible en el catálogo actual.',updated:'Hay una versión nueva de la tienda.',reload:'Actualizar página'}}[lang()][key]);
 const idValue = value => /^(?:[1-9]\d{0,9})$/.test(String(value)) && Number(value) <= 2147483647 ? Number(value) : 0;
 const parse = value => { try { return JSON.parse(value || '{}'); } catch (_) { return {}; } };
 const localURL = value => { if(typeof value!=='string'||!value.trim())return null;try { const url = new URL(value, global.location.href); return url.origin === global.location.origin && /^https?:$/.test(url.protocol) ? url : null; } catch (_) { return null; } };
 function campaignEligible(root, dto, now = null) {
  if (!dto || dto.version!==1 || dto.enabled !== true || !idValue(dto.promotionId) || !Number.isSafeInteger(dto.serverNow) || dto.serverNow<=0 || !['active','untimed'].includes(dto.state) || !Array.isArray(dto.tiers) || !dto.tiers.length || (dto.ends!==null && (!Number.isSafeInteger(dto.ends)||dto.ends<=0)) || (now !== null && dto.ends !== null && dto.ends <= now)) return false;
  const native = root.vcHomePromotions === undefined ? parse(root.dataset.vcHomeInitial).promotions : root.vcHomePromotions;
  return Array.isArray(native) && native.some(item => Number(item.id) === dto.promotionId && item.status === 'active');
 }
 function bindDialog(root, dialog) {
  if (!root || !dialog?.contains(root) || !root.classList.contains('vc-product-experience')) return null;
  const offers = root.querySelector('.vc-dialog-offers') || dialog.querySelector(':scope>.vc-home-quick__toolbar>.vc-dialog-offers');
  const previous = owners.get(dialog); if (previous?.root === root && previous.offers === offers) { previous.sync(); return previous; } previous?.destroy();
  const toolbar = dialog.querySelector(':scope>.vc-home-quick__toolbar'), close = dialog.querySelector('.vc-showcase-quick__close'), heading = root.querySelector('.vc-dialog-heading');
  if (!toolbar || !close || !heading || !offers) return null;
  const focusRemoved = [...toolbar.children].some(node => node !== close && node !== offers && node.contains(document.activeElement));
  [...toolbar.children].forEach(node => { if (node !== close && node !== offers && (node.matches('button,.vc-dialog-bag,.vc-home-quick__toolbar-name'))) node.remove(); });
  if (focusRemoved) close.focus({preventScroll:true});
  dialog.classList.add('vc-dialog-polished');
  let dto = config.campaign, disposed = false, timer = null, expiry = null, controller = null, clock = null, clockDto = null, clockEligible = null, sourceAnchor = pageCampaignAnchor, sourceServerNow=Number(dto?.serverNow||0), lastRead = 0;
  const host = document.createElement('div'); host.className = 'pc-campaign-countdown pc-campaign-countdown--product'; host.dataset.pcCampaignCountdown = 'product'; host.hidden = true;
  (heading.querySelector('[data-vc-product-broadcast]') || heading.lastElementChild)?.after(host); if (!host.isConnected) heading.append(host);
  const observer = new MutationObserver(() => sync());
  function sync(requestStarted=null) {
   if (disposed) return;
   const currentTime = sourceServerNow + (global.performance.now()-sourceAnchor)/1000;
   const eligible = campaignEligible(root, dto, currentTime);
   clearTimeout(expiry); expiry = null;
   if (eligible && Number.isSafeInteger(dto.ends)) expiry = setTimeout(sync, Math.min(2147483647, Math.max(0, (dto.ends-currentTime)*1000)+1));
   const title = eligible && typeof dto.title === 'string' ? dto.title : '';
   const tiers = eligible ? dto.tiers.filter(tier => typeof tier.text === 'string').map(tier => tier.text) : [];
   const expected = title + tiers.join('');
   observer.disconnect();
   offers.hidden = !eligible;
   if (offers.textContent !== expected) {
    offers.replaceChildren();
    if (eligible) {
     const h2 = document.createElement('h2'); h2.textContent = title; offers.append(h2);
     const list = document.createElement('ul'); list.className = 'vc-dialog-offer-tiers';
     tiers.forEach(value => { const li = document.createElement('li'); li.textContent = value; list.append(li); }); offers.append(list);
    }
   }
   observer.observe(offers, {childList:true,subtree:true});
   if (global.PCCampaignCountdown && (dto !== clockDto || eligible !== clockEligible)) {
    const currentServerTime=sourceServerNow+(global.performance.now()-sourceAnchor)/1000;
    const gated=dto?{...dto,visible:dto.visible&&eligible}:dto;
    const clockData=Number.isFinite(requestStarted)||!gated?gated:{...gated,serverNow:Math.floor(currentServerTime),visible:gated.visible&&(!Number.isSafeInteger(gated.ends)||gated.ends>currentServerTime)};
    if (!clock) {clock=global.PCCampaignCountdown.mount(host,clockData,{endpoint:''});if(Number.isFinite(requestStarted))clock?.update(gated,requestStarted);} else clock.update(clockData,requestStarted);clockDto=dto;clockEligible=eligible;
   }
  }
  async function refresh() {
   if (disposed || controller || !dialog.open || document.hidden) return;
   const url = localURL(config.campaignUrl); if (!url) return;
   controller = new AbortController(); lastRead=global.performance.now(); const active = controller, started=lastRead, timeout = setTimeout(() => active.abort(), 8000);
   try { const response = await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:active.signal,headers:{Accept:'application/json'}}); if (response.ok) { const body = await response.json(); if (!disposed && body.product?.version === 1) { dto = body.product; sourceAnchor=global.performance.now();sourceServerNow=Number(dto.serverNow)+(sourceAnchor-started)/2000;sync(started); } } }
   catch (_) { /* Known real deadline remains absolute; no reset on read failure. */ }
   finally { clearTimeout(timeout); if (controller === active) controller = null; }
  }
  function resume(){if(!document.hidden){sync();refresh();}}
  function destroy() { if (disposed) return; disposed = true; observer.disconnect(); controller?.abort(); clearInterval(timer); clearTimeout(expiry); clock?.destroy(); host.remove(); dialog.classList.remove('vc-dialog-polished'); dialog.removeEventListener('close',destroy);document.removeEventListener('visibilitychange',resume); owners.delete(dialog); }
  const owner = {root,offers,sync,destroy}; owners.set(dialog,owner); dialog.addEventListener('close',destroy,{once:true});document.addEventListener('visibilitychange',resume);sync();refresh();timer=setInterval(()=>{if(!document.hidden){sync();if(global.performance.now()-lastRead>=60000)refresh();}},60000);return owner;
 }
 document.addEventListener('vc:product-presented', event => { postponed=0; bindDialog(event.detail?.root,event.detail?.dialog);if(entryState==='loading'&&Number(event.detail?.productId)!==requestedProductId)cancel(); });
 document.addEventListener('vc:product-dialog-selection', event => { bindDialog(event.detail?.root,event.detail?.dialog); });
 document.addEventListener('vc:product-experience-disposed', event => {
  const previous = owners.get(event.detail?.dialog);
  if (previous && previous.root === event.detail?.root) previous.destroy();
 });

 let intent = 0, request = null, recovery = null, waiting = null, entryState = '', presenting = false, postponed = 0, requestedProductId=idValue(config.productId);
 function panel() {
  if (recovery?.isConnected) return recovery;
  recovery = document.createElement('section'); recovery.className = 'vc-entry-recovery';
  const message = document.createElement('p'); message.setAttribute('role','status');
  const button = document.createElement('button'); button.type='button'; button.textContent=text('retry'); button.addEventListener('click',()=>{if(button.dataset.entryAction==='reload'||button.dataset.vcQuickOpen)return;openProduct(requestedProductId);}); recovery.append(message,button);
  (document.querySelector('[data-vc-catalog]') || document.querySelector('main') || document.body).prepend(recovery); return recovery;
 }
 function status(key, pending=false) { entryState=key;const box=panel();box.dataset.entryState=key;box.hidden=false;box.querySelector('p').textContent=text(key); const button=box.querySelector('button');if(key!=='updated'){delete button.dataset.entryAction;button.onclick=null;}if(key!=='ready'){delete button.dataset.vcQuickOpen;delete button.dataset.vcHomeProduct;}button.disabled=pending;button.hidden=key==='catalog';button.textContent=text(key==='ready'?'open':'retry'); return button; }
 function clearRecovery() { recovery?.remove(); recovery=null; entryState=''; }
 function retireWait() { const previous=waiting;waiting=null;previous?.(); }
 function cancel() {
  postponed=0;++intent; request?.abort();request=null;retireWait();
  // Back, a newer product and page lifecycle exits are user intent, not a
  // failed service. Retire their transient UI instead of leaving a retry card.
  if(entryState==='loading'||entryState==='ready')clearRecovery();
 }
 // A page in the background (the ad link opened in another tab, an in-app browser that loads before
 // it is shown) is neither a failed service nor the visitor changing their mind. Nothing is presented
 // while nobody can see it, but the product is kept and presented the moment the page is looked at.
 // Back, Escape, leaving the page, or any product being presented in between all drop it (cancel()).
 function postpone(id) { cancel(); postponed=id; }
 function resumePostponed() {
  if(document.hidden||!postponed)return;
  const id=postponed;postponed=0;
  if(document.querySelector('dialog.vc-showcase-quick[open] .vc-home-quick-product'))return;
  // Same rule as boot(): a history entry that already holds layers is presented by its controller.
  const layers=global.history.state?.__vcCommerceLayers?.layers;
  if(Array.isArray(layers)&&layers.length)return;
  openProduct(id);
 }
 function waitPresented(id, milliseconds, trigger) {
  retireWait();
  return new Promise(resolve=>{
   let done=false;
   const finish=root=>{if(done)return;done=true;clearTimeout(timeout);document.removeEventListener('vc:product-presented',onShow);if(waiting===cleanup)waiting=null;resolve(root);};
   const cleanup=()=>finish(null),onShow=event=>{if(Number(event.detail?.productId)===id)finish(event.detail.root);};
   const timeout=setTimeout(()=>finish(null),milliseconds);waiting=cleanup;
   document.addEventListener('vc:product-presented',onShow);trigger?.();
  });
 }
 async function openProduct(raw) {
  const id=idValue(raw); if(!id)return false;requestedProductId=id;cancel();const ticket=intent;
  const button=status('loading',true);
  try {
   let template=document.querySelector('template[data-vc-quick-product="'+id+'"]');
   if(!template&&global.VialCoveHomeQuickView?.loadTemplate){await global.VialCoveHomeQuickView.loadTemplate(String(id));template=document.querySelector('template[data-vc-quick-product="'+id+'"]');if(!template)throw new Error('Template unavailable');}
   if(!template){
    const url=localURL(global.vcHomeQuickView?.productApi); if(!url)throw new Error('Unavailable service');
    if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route').replace(/\/?$/,'/')+id);else url.pathname=url.pathname.replace(/\/?$/,'/')+id;
    request=new AbortController();const active=request,timeout=setTimeout(()=>active.abort(),12000);
    try {
     const headers={Accept:'application/json'};if(global.vcHomeQuickView?.wpNonce)headers['X-WP-Nonce']=global.vcHomeQuickView.wpNonce;
     const response=await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:active.signal,headers});if(!response.ok)throw new Error('Read failed');const data=await response.json();
     if(Number(data.id)!==id||typeof data.html!=='string')throw new Error('Wrong product');
     const fragment=document.createElement('template');fragment.innerHTML=data.html;template=fragment.content.firstElementChild;
     if(fragment.content.children.length!==1||template?.tagName!=='TEMPLATE'||template.dataset.vcQuickProduct!==String(id)||template.content.firstElementChild?.dataset.vcProductId!==String(id))throw new Error('Invalid template');
     if(ticket!==intent)return false;document.body.append(template);
    }finally{clearTimeout(timeout);if(request===active)request=null;}
   }
   if(ticket!==intent)return false;
   if(document.hidden){postpone(id);return false;}
   status('ready'); button.dataset.vcHomeProduct=String(id);button.dataset.vcQuickOpen=String(id);
   if(recovery)recovery.hidden=true;
   const opener=[...(document.querySelectorAll?.('[data-vc-home-product="'+id+'"][data-vc-quick-open]')||[])].find(node=>node!==button&&node.getClientRects().length&&!node.closest('[inert]'))||button;
   const presented=await waitPresented(id,1800,()=>{presenting=true;try{opener.click();}finally{presenting=false;}});
   if(ticket!==intent)return false;
   if(!presented)throw new Error('Presenter unavailable');
   // Prefer the visible native product trigger for close focus. The synthetic
   // deep-link opener never reserves a second card in the catalogue.
   if(recovery)recovery.hidden=true;
   const params=new URL(global.location.href).searchParams;
   presented.querySelectorAll('.variations select').forEach(select=>{const value=params.get(select.name);if(value!==null&&[...select.options].some(option=>option.value===value)){select.value=value;global.jQuery?global.jQuery(select).trigger('change'):select.dispatchEvent(new Event('change',{bubbles:true}));}});
   return true;
  }catch(_){if(ticket===intent){if(document.hidden){postpone(id);return false;}const retry=status('failed');delete retry.dataset.vcQuickOpen;delete retry.dataset.vcHomeProduct;}return false;}
 }
 // Entry recovery button must use only one native presentation attempt, not recurse through its own listener.
 document.addEventListener('vc:product-restore-cancel',cancel);global.addEventListener('pagehide',cancel);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)resumePostponed();else if(entryState==='loading'||waiting)postpone(requestedProductId);});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&(entryState==='loading'||waiting))cancel();});
 document.addEventListener('click',event=>{const opener=event.target.closest?.('[data-vc-home-product][data-vc-quick-open]');if(!presenting&&opener&&!recovery?.contains(opener)&&(entryState==='loading'||waiting))cancel();},true);
 function restoreCatalog() {
  const selection=config.catalog||{};if(!selection.query&&!selection.requestedCategory)return;
  const root=document.querySelector('[data-vc-catalog]');if(!root)return;
  if(selection.requestedCategory&&!selection.categoryIds?.length){status('catalog');return;}
  root.dispatchEvent(new CustomEvent('vc:catalog-restore',{detail:{query:selection.query||'',categoryIds:selection.categoryIds||[]}}));
 }
 function boot() {
  // The history controller may have presented a cached native dialog before this script loaded.
  // Reconcile that existing instance once; do not rely only on a past presentation event.
  const restored=document.querySelector('dialog.vc-showcase-quick[open] .vc-home-quick-product');
  if(restored)bindDialog(restored,restored.closest('dialog'));
  restoreCatalog();
  const layers=global.history.state?.__vcCommerceLayers?.layers;
  if(global.location.hash==='#vc-products'&&!config.productId&&!layers?.length)document.querySelector('[data-vc-catalog]')?.scrollIntoView({block:'start',behavior:'instant'});
  if(config.productId){
   // A refreshed history layer may intentionally be a different product or
   // only the bag. The old deep-link query does not own that newer selection.
   if(Array.isArray(layers)&&layers.length&&!layers.some(layer=>layer.kind==='product'&&Number(layer.productId)===idValue(config.productId)))return;
   if(config.productAvailable===false){status('unavailable');return;}
   if(Array.isArray(layers)&&layers.length){
    // Existing history controller owns presentation. Only a genuine failure
    // needs an in-flow recovery action; pending restoration is announced.
    const id=idValue(config.productId),ticket=intent;
    const existing=document.querySelector('dialog.vc-showcase-quick[open] .vc-home-quick-product');if(Number(existing?.dataset.vcProductId)===id)return;
    status('loading',true);waitPresented(id,14500).then(root=>{if(ticket===intent){if(root)clearRecovery();else status('failed');}});return;
   }
   openProduct(config.productId);
  }
 }
 let dirty=false;
 document.addEventListener('input',event=>{if(event.isTrusted&&event.target.closest('form:not([role="search"])'))dirty=true;},true);
 document.addEventListener('change',event=>{if(event.isTrusted&&event.target.closest('form:not([role="search"])'))dirty=true;},true);
 global.addEventListener('pageshow',async event=>{
  if(!event.persisted||/\/(?:cart|checkout|order-pay|order-received)(?:\/|$)/i.test(global.location.pathname))return;
  const url=localURL(config.releaseUrl);if(!url)return;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),8000);
  try{
   const response=await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)return;const body=await response.json();
   if(body.version!==1||!/^[a-f0-9]{64}$/.test(body.release)||body.release===config.release)return;
   const key='vc-entry-reloaded:'+global.location.pathname;let already=false;try{already=global.sessionStorage.getItem(key)===body.release;if(!dirty&&!already)global.sessionStorage.setItem(key,body.release);}catch(_){}
   if(!dirty&&!already){global.location.reload();return;}
   const button=status('updated');delete button.dataset.vcQuickOpen;delete button.dataset.vcHomeProduct;button.dataset.entryAction='reload';button.textContent=text('reload');button.onclick=()=>global.location.reload();
  }catch(_){/* No evidence of a changed release: leave the current page untouched. */}finally{clearTimeout(timeout);}
 });
 global.VialCoveStorefrontEntry={openProduct,bindDialog,campaignEligible};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})(window);
