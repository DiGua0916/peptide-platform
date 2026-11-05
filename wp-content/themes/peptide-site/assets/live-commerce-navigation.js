/* A history entry per visible overlay, using the existing Woo controllers. */
(function () {
  'use strict';
  if (!window.history || !window.MutationObserver) return;
  const key = '__vcCommerceLayers', scope = location.origin + location.pathname;
  const selector = 'dialog.vc-showcase-quick,dialog.vc-product-child-dialog,dialog#vc-live-cart-drawer';
  let current = [], applying = false, pending = false, navigating = false, timer = 0, syncTimer = 0, serial = 0;
  const cached = new Map();
  let cancelProductWait = null;
  const openedOrder = new WeakMap(); let openedSerial = 0;
  const copy = value => JSON.parse(JSON.stringify(value));
  const read = state => state?.[key]?.version === 1 && state[key].scope === scope && Array.isArray(state[key].layers) ? state[key] : null;
  const state = layers => ({...(history.state && typeof history.state === 'object' ? history.state : {}), [key]: {version:1, scope, layers:copy(layers)}});
  const productDialog = () => document.querySelector('dialog.vc-showcase-quick');
  const root = () => productDialog()?.querySelector('.vc-home-quick-product');
  function identify(dialog) {
    if (dialog.matches('.vc-showcase-quick')) return {kind:'product', productId:Number(root()?.dataset.vcProductId || 0)};
    if (dialog.id === 'vc-live-cart-drawer') return {kind:'cart'};
    if (dialog.matches('.vc-product-report-dialog') && dialog.dataset.vcHistoryReportOrigin === 'library') return {kind:'library-report',url:dialog.dataset.vcHistoryReportUrl || ''};
    return {kind:dialog.matches('.vc-product-report-dialog') ? 'report' : 'image', productId:Number(dialog.dataset.vcProductParent || root()?.dataset.vcProductId || 0), url:dialog.dataset.vcHistoryReportUrl || dialog.dataset.vcHistoryImageUrl || ''};
  }
  const signature = entry => JSON.stringify([entry.kind,entry.productId || 0,entry.url || '']);
  function capture(dialog, entry) {
    const item = {...entry, scroll:dialog.scrollTop};
    if (entry.kind === 'product') {
      const r = root(); item.attributes = {};
      r?.querySelectorAll('.variations select').forEach(el => {item.attributes[el.name] = el.value;});
      item.quantity = r?.querySelector('input.qty')?.value || '1';
    }
    return item;
  }
  function visible() {
    const dialogs = [...document.querySelectorAll(selector)].filter(d=>d.open);
    dialogs.forEach(d=> {if(!openedOrder.has(d)) openedOrder.set(d,++openedSerial);});
    return dialogs.sort((a,b)=>openedOrder.get(a)-openedOrder.get(b)).map(d=>capture(d,identify(d))).filter(x=>x.kind !== 'product' || x.productId > 0);
  }
  function remember() {
    const value = read(history.state); if (!value || applying || pending || navigating) return;
    const open = visible();
    if (open.map(signature).join('|') === current.map(signature).join('|')) {
      current = open; history.replaceState(state(current),'',location.href);
    }
  }
  function decorate() {
    document.querySelectorAll('[data-vc-quick-open]:not([data-vc-home-product])').forEach(el=> {
      if (/^[1-9]\d*$/.test(el.dataset.vcQuickOpen)) el.dataset.vcHomeProduct = el.dataset.vcQuickOpen;
    });
  }
  function reconcile() {
    decorate();
    if (applying || pending || navigating) return;
    const next = visible();
    const a = current.map(signature), b = next.map(signature);
    if (a.join('|') === b.join('|')) return;
    // Closing one overlay and opening another in one interaction replaces that
    // layer. It must not race an asynchronous history.back with a new push.
    const prefix = a.every((value,i)=>b[i] === value);
    const shorter = b.length < a.length && b.every((value,i)=>a[i] === value);
    if (shorter && read(history.state)) {
      pending = true; history.go(b.length - a.length); return;
    }
    if (prefix && b.length > a.length) {
      if (!read(history.state)) history.replaceState(state(current),'',location.href);
      for (let i=a.length+1;i<=b.length;i++) history.pushState(state(next.slice(0,i)),'',location.href);
    } else history.replaceState(state(next),'',location.href);
    current = next;
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(reconcile,0); }
  function close(dialog) {
    if (!dialog?.open) return;
    if (dialog.id === 'vc-live-cart-drawer') window.VialCoveLiveCart?.close(true);
    else dialog.close();
  }
  function waitProduct(id, item) {
    return new Promise(resolve=> {
      if (productDialog()?.open && Number(root()?.dataset.vcProductId)===id) {resolve(root());return;}
      let releaseRestore = null;
      const finish = r=> {releaseRestore?.();clearTimeout(timeout);document.removeEventListener('vc:product-presented',onPresent);if(cancelProductWait===cancel)cancelProductWait=null;resolve(r);};
      const cancel = ()=>finish(null);
      const onPresent = e=> {if(Number(e.detail?.productId)===id) finish(e.detail.root);};
      const timeout = setTimeout(()=>finish(null),14000);
      cancelProductWait = cancel;
      document.addEventListener('vc:product-presented',onPresent);
      let opener = document.querySelector('[data-vc-home-product="'+id+'"][data-vc-quick-open]');
      if (!opener) {
        opener = document.createElement('button'); opener.type='button'; opener.hidden=true;
        opener.dataset.vcHomeProduct=String(id); opener.dataset.vcQuickOpen=String(id); document.body.append(opener);
      }
      releaseRestore = window.VialCoveHomeQuickView?.stageRestore?.(item, opener);
      opener.click();
    });
  }
  async function apply(layers) {
    applying = true; pending = false; const ticket=++serial;
    // A new history intent owns presentation. Retire the previous waiter now,
    // rather than letting a closed/superseded product hold its deadline/listener.
    cancelProductWait?.();
    try {
      const wanted=layers.map(signature);
      [...document.querySelectorAll(selector)].reverse().forEach(d=> {if(d.open && !wanted.includes(signature(identify(d)))) close(d);});
      for (const item of layers) {
        if (ticket!==serial) return;
        if (item.kind==='product') {
          const r=await waitProduct(item.productId,item); if(!r || ticket!==serial) return;
          for (const el of r.querySelectorAll('.variations select')) {
            const value=item.attributes?.[el.name];
            // Returning from a child layer must not rebuild an unchanged Woo
            // variation form and displace the parent's current content/scroll.
            if (typeof value==='string' && el.value!==value && [...el.options].some(option=>option.value===value)) {
              el.value=value; window.jQuery ? window.jQuery(el).trigger('change') : el.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          const qty=r.querySelector('input.qty');
          if(qty && /^\d+$/.test(item.quantity || '') && qty.value!==String(item.quantity)) {qty.value=item.quantity;qty.dispatchEvent(new Event('change',{bubbles:true}));}
          requestAnimationFrame(()=> {if(ticket===serial && productDialog()?.open) productDialog().scrollTop=Number(item.scroll)||0;});
        } else if (item.kind==='cart') window.VialCoveLiveCart?.open();
        else if (item.kind==='library-report') {
          window.VialCoveReportLibraryViewer?.open(item.url,undefined,Number(item.scroll)||0);
        }
        else {
          const r=root(); if(!r?.vcExperience) continue;
          if(item.kind==='report') await r.vcExperience.openReport(item.url); else r.vcExperience.openImage(item.url);
        }
      }
    } finally {
      if(ticket===serial) {current=visible();applying=false;}
    }
  }
  window.addEventListener('popstate',event=> {
    clearTimeout(timer);
    document.dispatchEvent(new CustomEvent('vc:product-restore-cancel'));
    // Save the last outgoing selection before native close destroys the view.
    for(const item of visible()) cached.set(signature(item),item);
    while(cached.size>30) cached.delete(cached.keys().next().value);
    const target=read(event.state)?.layers || [];
    apply(target.map(item=>cached.get(signature(item)) || item));
  });
  document.addEventListener('click',event=> {
    const a=event.target.closest('a[href]');
    if(!a || event.defaultPrevented || event.button!==0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    let url;try{url=new URL(a.href,location.href);}catch(_){return;}
    const cart=window.VialCoveCommerceNavigation?.cartUrl;
    // A real /cart/ link remains the fallback. A supported bag trigger opens
    // another history layer on both devices and keeps the product below it.
    if(cart && url.origin===location.origin && url.pathname===new URL(cart,location.href).pathname) {
      remember();
      if(!a.matches('.cart-link,[data-vc-open-cart]') || !window.VialCoveLiveCart?.canOpen?.()) navigating=true;
    }
  },true);
  document.addEventListener('change',()=>setTimeout(remember,0));
  document.addEventListener('scroll',()=> {if(!applying && !pending){clearTimeout(syncTimer);syncTimer=setTimeout(remember,150);}},true);
  new MutationObserver(records=> {
    for(const record of records) {
      if(record.type==='attributes' && record.target.matches(selector)) {
        if(record.target.open) openedOrder.set(record.target,++openedSerial);
        else openedOrder.delete(record.target);
      }
    }
    schedule();
  }).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
  window.addEventListener('pagehide',()=> {remember();navigating=true;});
  window.addEventListener('pageshow',event=> {navigating=false;if(event.persisted) apply(read(history.state)?.layers || []);});
  decorate();
  const existing=read(history.state);
  if(existing?.layers.length) apply(existing.layers); else schedule();
})();
