/* Recently viewed is owned by the plugin's signed-cookie service, never localStorage. */
(function () {
  'use strict';
  function safeURL(value) {
    try { const url = new URL(value, location.href); return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.href : ''; }
    catch (_) { return ''; }
  }
  function createRecorder(options) {
    const itemsUrl = safeURL(options.itemsUrl), recordUrl = safeURL(options.recordUrl);
    const transport = options.fetch || window.fetch.bind(window);
    let tail = Promise.resolve(), token = '', expiresAt = 0, stopped = false, active = null;
    async function request(url, init = {}) {
      if (stopped) throw new Error('Stopped');
      const controller = new AbortController(); active = controller;
      const timer = setTimeout(() => controller.abort(), 10000);
      try {
        const headers = {Accept:'application/json', ...(init.headers || {})};
        if (options.wpNonce) headers['X-WP-Nonce'] = options.wpNonce;
        const response = await transport(url, {...init,headers,cache:'no-store',credentials:'same-origin',signal:controller.signal});
        const data = await response.json();
        return {ok:response.ok,status:response.status,json:async()=>data};
      } finally { clearTimeout(timer); if (active === controller) active = null; }
    }
    async function prepare(force = false) {
      if (token && !force && expiresAt > Date.now() + 10000) return true;
      token = ''; expiresAt = 0;
      const response = await request(itemsUrl);
      if (!response.ok) return false;
      const data = await response.json();
      if (data.enabled !== true || typeof data.writeTokens?.record !== 'string' || !data.writeTokens.record) return false;
      token = data.writeTokens.record; expiresAt = Number(data.tokenExpiresAt) * 1000;
      return true;
    }
    async function record(payload) {
      if (!itemsUrl || !recordUrl || !Number.isSafeInteger(payload.productId) || payload.productId < 1 ||
          !Number.isSafeInteger(payload.variationId) || payload.variationId < 0 || !['view','selection'].includes(payload.kind)) return false;
      try {
        if (!await prepare()) return false;
        let response = await request(recordUrl, {method:'POST',headers:{'Content-Type':'application/json','X-PC-Recent-Token':token},body:JSON.stringify(payload)});
        // An explicit rejected authorization has not recorded a view. Refresh once;
        // never replay an ambiguous timeout or a rate-limit/unavailable response.
        if (response.status === 403 && await prepare(true)) response = await request(recordUrl, {method:'POST',headers:{'Content-Type':'application/json','X-PC-Recent-Token':token},body:JSON.stringify(payload)});
        if (!response.ok) return false;
        const result = await response.json();
        const confirmed = result.recorded === true && result.productId === payload.productId && result.variationId === payload.variationId && result.kind === payload.kind;
        if (confirmed && !stopped) options.onUpdate?.();
        return confirmed;
      } catch (_) { return false; }
    }
    return {
      enqueue(payload) { const copy = {productId:payload.productId,variationId:payload.variationId,kind:payload.kind}; const next = tail.then(() => stopped ? false : record(copy)); tail = next.catch(() => false); return next; },
      destroy() { stopped = true; token = ''; active?.abort(); }
    };
  }
  window.createVcRecentRecorder = createRecorder;
  let recorder;
  const sessions = new WeakMap();
  document.addEventListener('vc:product-presented', event => {
    const {root,dialog,productId} = event.detail || {};
    if (!root?.isConnected || !dialog?.open || sessions.has(root) || !Number.isSafeInteger(productId)) return;
    const source = window.vcLiveCommerce?.recentlyViewed;
    if (!source || source.config?.enabled === false) return;
    if (!recorder) recorder = createRecorder({...source,wpNonce:window.vcLiveCommerce?.wpNonce,onUpdate:()=>document.dispatchEvent(new CustomEvent('vc:recently-viewed-updated'))});
    const variationId = Number(root.querySelector('input.variation_id')?.value || 0);
    const session = {variationId,view:recorder.enqueue({productId,variationId,kind:'view'}),selection:Promise.resolve(),timer:0};
    sessions.set(root,session);
    dialog.addEventListener('close',()=>{clearTimeout(session.timer);sessions.delete(root);},{once:true});
  });
  document.addEventListener('vc:product-dialog-selection', event => {
    const {root,dialog,productId,variationId} = event.detail || {}, session = root && sessions.get(root);
    if (!session || !dialog?.open || !Number.isSafeInteger(variationId) || variationId < 1 || variationId === session.variationId) return;
    session.variationId = variationId; clearTimeout(session.timer);
    session.timer = setTimeout(() => {
      if (sessions.get(root) !== session || !dialog.open) return;
      session.selection = session.selection.then(async () => {
        if (!await session.view || sessions.get(root) !== session || !dialog.open) return;
        await recorder.enqueue({productId,variationId,kind:'selection'});
      });
    },200);
  });
  window.addEventListener('pagehide',event=>{if(!event.persisted){recorder?.destroy();recorder=null;}});
})();
