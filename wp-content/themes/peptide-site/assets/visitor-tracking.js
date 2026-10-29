/* Visitor observer. Inert until the backend-enabled adapter calls start().
 * No endpoint, identity, cookie, storage, form contents or transaction facts here. */
(() => {
  'use strict';
  let active;
  const number = (value, fallback, min, max) => Number.isFinite(Number(value)) ? Math.min(max, Math.max(min, Number(value))) : fallback;
  const id = () => crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const integer = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : undefined;
  const text = (value, max = 120) => typeof value === 'string' ? value.slice(0, max) : undefined;
  const allowed = {
    cart_open: ['source'], cart_close: ['source'],
    cart_change_attempt: ['source', 'action', 'item_id', 'quantity'],
    cart_change_response: ['source', 'action', 'item_id', 'quantity'],
    variant_select: ['product_id', 'attribute', 'value'],
    checkout_submit_attempt: [],
    checkout_address_completed: ['source'],
    checkout_shipping_selected: ['method', 'origin'], checkout_payment_selected: ['method', 'origin'],
    checkout_step_applicability: ['address_required', 'shipping_required'],
    video_play: [], video_pause: [], video_progress: [], video_seek: [], video_end: []
  };
  function cleanData(type, input = {}) {
    const fields = type.startsWith('video_')
      ? ['video_id', 'module_id', 'position_s', 'duration_s', 'watched_s', 'autoplay', 'playback_origin']
      : allowed[type];
    if (!fields) return null;
    const result = {};
    for (const key of fields) {
      const value = input[key];
      if (value === undefined || value === null) continue;
      if (['item_id', 'product_id'].includes(key)) { if (integer(value)) result[key] = integer(value); }
      else if (key.endsWith('_s') || key === 'quantity') { if (Number.isFinite(Number(value)) && Number(value) >= 0) result[key] = Math.min(1e7, Number(value)); }
      else if (key === 'autoplay') result[key] = value === true;
      else if (key === 'address_required' || key === 'shipping_required') { if (typeof value === 'boolean') result[key] = value; }
      else if (key === 'origin') { if (['initial', 'user', 'automatic'].includes(value)) result[key] = value; }
      else if (key === 'source' && type === 'checkout_address_completed') { if (value === 'native_blocks') result[key] = value; }
      else if (key === 'method') { if (typeof value === 'string') result[key] = value.slice(0, 80); }
      else if (typeof value === 'string') result[key] = value.slice(0, 120);
    }
    return result;
  }
  function pageContext(context) {
    const types = ['home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content'];
    let path = '/';
    try { const url = new URL(context.path || '/', location.origin); if (url.origin === location.origin) path = url.pathname.slice(0, 500); } catch { /* No raw URLs in events. */ }
    const page = {page_type: types.includes(context.page_type) ? context.page_type : 'content', path};
    if (['account', 'order_received', 'order_pay'].includes(page.page_type)) page.path = page.page_type === 'account' ? '/my-account/' : '/checkout/';
    if (page.page_type === 'product' && integer(context.product_id)) {
      page.product_id = integer(context.product_id);
      page.product_name = text(context.product_name) || '';
    }
    return page;
  }
  function start(options = {}) {
    if (typeof options.sendBatch !== 'function' || !options.context || !window.crypto?.getRandomValues) return null;
    active?.stop();
    const page = pageContext(options.context);
    const heartbeatMs = number(options.heartbeatMs, 15000, 1000, 60000);
    const idleMs = number(options.idleMs, 60000, 1000, 600000);
    const flushMs = number(options.flushMs, 250, 0, 5000);
    const timeoutMs = number(options.timeoutMs, 8000, 100, 20000);
    const maxAttempts = number(options.maxAttempts, 3, 1, 5);
    const maxQueue = number(options.maxQueue, 100, 10, 200);
    const batchMax = Math.floor(number(options.batchMax, 10, 1, 20));
    let retryAt = 0;
    let pageId = id(), seq = 0, lastActivity = Date.now(), stopped = false, suspended = false;
    let sending = false, flushTimer, flushAt = Infinity, requestController, requestTimer, heartbeatTimer;
    let videoObserver, exitController, exitTimer, checkoutBridge;
    const bridgeCall = name => { try { checkoutBridge?.[name]?.(); } catch { try { checkoutBridge?.stop?.(); } catch {} checkoutBridge = null; } };
    const pending = [], listeners = [], cleanups = [];
    const stats = {created: 0, acknowledged: 0, dropped: 0, failedBatches: 0};
    const listen = (target, type, listener, opts) => {
      target.addEventListener(type, listener, opts);
      listeners.push(() => target.removeEventListener(type, listener, opts));
    };
    const state = () => {
      const hidden = document.visibilityState === 'hidden';
      const age = Math.max(0, Date.now() - lastActivity);
      return {visibility: hidden ? 'hidden' : 'visible', activity: hidden || age >= idleMs ? 'idle' : 'active', last_activity_age_ms: age};
    };
    function schedule(delay = flushMs) {
      if (stopped || suspended || !pending.length) return;
      const wait = Math.max(0, delay, retryAt - Date.now());
      const due = Date.now() + wait;
      if (flushTimer && due >= flushAt) return;
      clearTimeout(flushTimer); flushAt = due;
      flushTimer = setTimeout(() => { flushTimer = null; flushAt = Infinity; void flush(); }, wait);
    }
    function emit(type, data = {}) {
      if (stopped || suspended) return;
      if (pending.length >= maxQueue) {
        const removable = pending.findIndex(entry => !entry.inFlight);
        if (removable < 0) { stats.dropped++; return; }
        pending.splice(removable, 1); stats.dropped++;
      }
      pending.push({record: {event_id: id(), page_instance_id: pageId, seq: ++seq, type, occurred_at: new Date().toISOString(), page: {...page}, data}, attempts: 0, readyAt: 0, inFlight: false});
      stats.created++;
      schedule();
    }
    function schedulePending() {
      const waiting = pending.filter(entry => !entry.inFlight);
      if (waiting.length) schedule(Math.max(0, Math.min(...waiting.map(entry => entry.readyAt)) - Date.now()));
    }
    async function flush(hint = {}) {
      if (stopped || (!hint.unload && suspended)) return false;
      if (retryAt > Date.now()) { schedulePending(); return false; }
      if (sending) { if (hint.unload) return flushExit(); return false; }
      clearTimeout(flushTimer); flushTimer = null; flushAt = Infinity;
      const batch = pending.filter(entry => !entry.inFlight && (hint.unload || entry.readyAt <= Date.now())).slice(0, batchMax);
      if (!batch.length) { schedulePending(); return false; }
      sending = true;
      batch.forEach(entry => { entry.inFlight = true; entry.attempts++; });
      requestController = new AbortController();
      const controller = requestController;
      let ack = new Set();
      let discarded = new Set();
      try {
        const timeout = new Promise((_, reject) => {
          controller.signal.addEventListener('abort', () => reject(new Error('Observer transport cancelled')), {once: true});
          requestTimer = setTimeout(() => { controller.abort(); reject(new Error('Observer transport timeout')); }, timeoutMs);
        });
        // Adapter owns authentication, serialization and HTTP. An ACK means server acceptance.
        const result = await Promise.race([Promise.resolve().then(() => {
          if (stopped) throw new Error('Observer stopped');
          return options.sendBatch(batch.map(entry => ({...entry.record, page: {...entry.record.page}, data: {...entry.record.data}})), {unload: hint.unload === true, signal: controller.signal});
        }), timeout]);
        if (Array.isArray(result?.ackEventIds)) ack = new Set(result.ackEventIds);
        if (Array.isArray(result?.discardEventIds)) discarded = new Set(result.discardEventIds);
        if (Number.isFinite(result?.retryAfterMs) && result.retryAfterMs > 0) retryAt = Math.max(retryAt, Date.now() + Math.min(3600000, result.retryAfterMs));
        if (!stopped && batch.some(entry => !ack.has(entry.record.event_id) && !discarded.has(entry.record.event_id))) stats.failedBatches++;
      } catch { if (!stopped) stats.failedBatches++; }
      finally {
        clearTimeout(requestTimer); requestController = null;
        for (const entry of stopped ? [] : batch) {
          entry.inFlight = false;
          if (ack.has(entry.record.event_id) || discarded.has(entry.record.event_id) || entry.attempts >= maxAttempts) {
            const index = pending.indexOf(entry); if (index >= 0) pending.splice(index, 1);
            if (ack.has(entry.record.event_id)) stats.acknowledged++; else stats.dropped++;
          } else entry.readyAt = Math.max(retryAt, Date.now() + Math.min(20000, 1000 * 2 ** (entry.attempts - 1)));
        }
        sending = false;
        if (!stopped) schedulePending();
      }
      return true;
    }
    function flushExit() {
      // The normal request may still be pending. Attempt the latest unsent events
      // synchronously during pagehide instead of trapping them behind that request.
      if (stopped || exitController || retryAt > Date.now()) return false;
      const batch = pending.filter(entry => !entry.inFlight && entry.attempts < maxAttempts).slice(-batchMax);
      if (!batch.length) return false;
      const controller = new AbortController(); exitController = controller;
      batch.forEach(entry => { entry.inFlight = true; entry.attempts++; });
      let delivery;
      try { delivery = options.sendBatch(batch.map(entry => ({...entry.record, page: {...entry.record.page}, data: {...entry.record.data}})), {unload: true, signal: controller.signal}); }
      catch { delivery = Promise.reject(new Error('Exit delivery failed')); }
      const timeout = new Promise((_, reject) => {
        controller.signal.addEventListener('abort', () => reject(new Error('Exit delivery cancelled')), {once: true});
        exitTimer = setTimeout(() => controller.abort(), timeoutMs);
      });
      Promise.race([Promise.resolve(delivery), timeout]).then(result => {
        if (stopped) return;
        const ack = new Set(Array.isArray(result?.ackEventIds) ? result.ackEventIds : []);
        const discarded = new Set(Array.isArray(result?.discardEventIds) ? result.discardEventIds : []);
        if (Number.isFinite(result?.retryAfterMs) && result.retryAfterMs > 0) retryAt = Math.max(retryAt, Date.now() + Math.min(3600000, result.retryAfterMs));
        for (const entry of batch) if (ack.has(entry.record.event_id) || discarded.has(entry.record.event_id)) {
          const index = pending.indexOf(entry); if (index >= 0) { pending.splice(index, 1); if (ack.has(entry.record.event_id)) stats.acknowledged++; else stats.dropped++; }
        }
      }).catch(() => { if (!stopped) stats.failedBatches++; }).finally(() => {
        clearTimeout(exitTimer); exitController = null;
        for (const entry of batch) {
          entry.inFlight = false;
          entry.readyAt = Math.max(retryAt, Date.now() + 1000);
          const index = pending.indexOf(entry);
          if (index >= 0 && entry.attempts >= maxAttempts) { pending.splice(index, 1); if (!stopped) stats.dropped++; }
        }
        schedulePending();
      });
      return true;
    }
    function begin(cause) {
      emit('page_view', {cause, ...state()});
      if (page.page_type === 'product') emit('product_view', {product_id: page.product_id});
      if (page.page_type === 'cart') emit('cart_view', {source: 'page'});
      if (page.page_type === 'checkout') emit('checkout_view');
    }
    const activity = event => { if (event.isTrusted && Date.now() - lastActivity >= 1000) lastActivity = Date.now(); };
    ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach(type => listen(document, type, activity, {passive: true}));
    listen(document, 'visibilitychange', () => { videoObserver?.flush(); emit('visibility_change', state()); if (document.hidden) void flush(); });
    listen(window, 'pagehide', event => {
      bridgeCall('suspend');
      videoObserver?.flush();
      emit('page_exit', {persisted: event.persisted, ...state()});
      void flush({unload: true});
      suspended = true; clearInterval(heartbeatTimer); clearTimeout(flushTimer); flushTimer = null; flushAt = Infinity;
    });
    // A background tab is still present, but is neither active nor watched.
    // Keep its lightweight presence signal; pagehide/stop clear this timer.
    // Browser freezing or throttling can still prevent delivery, so the server
    // must retain its timeout fallback rather than infer guaranteed presence.
    const heartbeat = () => { heartbeatTimer = setInterval(() => emit('heartbeat', state()), heartbeatMs); };
    listen(window, 'pageshow', event => {
      if (!event.persisted || stopped) return;
      suspended = false; pageId = id(); seq = 0; lastActivity = Date.now(); begin('bfcache');
      bridgeCall('reset'); bridgeCall('resume');
      // A restored document has a new page instance; reset its video counters too.
      videoObserver?.stop(); observeVideos();
      clearInterval(heartbeatTimer); heartbeat();
    });
    listen(window, 'online', () => schedule(0));
    listen(document, 'vc:visitor-action', event => {
      const type = event.detail?.type;
      if (typeof type !== 'string' || !Object.hasOwn(allowed, type) || type.startsWith('video_')) return;
      const data = cleanData(type, event.detail?.data);
      if (data) emit(type, data);
    });
    const attempts = new WeakMap();
    const addAttempt = (form, button) => {
      const target = form || button;
      if (!target || Date.now() - (attempts.get(target) || 0) < 500) return;
      const product = integer(form?.dataset.product_id || form?.querySelector('[name="product_id"], [name="add-to-cart"]')?.value || button?.dataset.product_id || button?.value || page.product_id);
      if (!product) return;
      attempts.set(target, Date.now());
      const data = {product_id: product, quantity: number(form?.querySelector('[name="quantity"]')?.value || button?.dataset.quantity, 1, 1, 100000)};
      const variation = integer(form?.querySelector('[name="variation_id"]')?.value);
      if (variation) data.variation_id = variation;
      emit('add_to_cart_attempt', data);
    };
    // Capture the intent before Woo Blocks disables the submit button or stops
    // propagation in its React handler. Completion remains a server-side fact.
    listen(document, 'click', event => {
      if (!event.isTrusted || event.button !== 0) return;
      const button = event.target.closest?.('.single_add_to_cart_button, .ajax_add_to_cart, .wc-block-components-checkout-place-order-button');
      if (!button || button.disabled || button.classList.contains('disabled') || button.getAttribute('aria-disabled') === 'true') return;
      if (button.matches('.wc-block-components-checkout-place-order-button')) {
        if (page.page_type === 'checkout' && checkoutBridge?.nativeSubmit !== true) emit('checkout_submit_attempt');
      } else addAttempt(button.closest('form.cart'), button);
    }, {capture: true});
    listen(document, 'submit', event => { if (event.isTrusted && event.target.matches('form.cart')) addAttempt(event.target, event.submitter); });
    listen(document, 'change', event => {
      if (!event.isTrusted || !event.target.matches?.('.variations select')) return;
      const select = event.target;
      emit('variant_select', cleanData('variant_select', {product_id: select.closest('form')?.dataset.product_id || page.product_id, attribute: select.name, value: select.value}));
    });
    function observeVideos() {
      if (!window.VialCoveVisitorVideo?.observe) return;
      videoObserver = window.VialCoveVisitorVideo.observe(document, (type, data) => {
        if (!Object.hasOwn(allowed, type) || !type.startsWith('video_')) return;
        emit(type, cleanData(type, data));
      });
    }
    cleanups.push(() => videoObserver?.stop());
    cleanups.push(() => bridgeCall('stop'));
    function stop() {
      if (stopped) return;
      stopped = true; requestController?.abort(); exitController?.abort();
      clearTimeout(flushTimer); clearTimeout(requestTimer); clearTimeout(exitTimer); clearInterval(heartbeatTimer); sending = false;
      listeners.forEach(remove => remove()); cleanups.forEach(cleanup => cleanup());
      pending.length = 0;
      if (active === api) active = null;
    }
    const api = {flush, stop, getStats: () => ({...stats, queued: pending.length, sending, stopped})};
    active = api;
    begin('navigation'); observeVideos(); heartbeat();
    if (page.page_type === 'checkout') {
      try {
        checkoutBridge = window.PeptideCheckoutBridge?.create((type, input) => {
          if (!Object.hasOwn(allowed, type) || !type.startsWith('checkout_')) return;
          const data = cleanData(type, input); if (data) emit(type, data);
        });
      } catch { checkoutBridge = null; }
    }
    return api;
  }
  window.VialCoveVisitorObserver = {start};
})();
