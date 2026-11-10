/* Visitor observer. Inert until the backend-enabled adapter calls start().
 * No endpoint, identity, cookie, storage, form contents or transaction facts here. */
(() => {
  'use strict';
  let active;
  const number = (value, fallback, min, max) => Number.isFinite(Number(value)) ? Math.min(max, Math.max(min, Number(value))) : fallback;
  const id = () => crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const integer = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : undefined;
  const text = (value, max = 120) => typeof value === 'string' ? value.slice(0, max) : undefined;
  const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const host = value => {
    if (typeof value !== 'string' || !value.length || value.length > 253 || /[^\x21-\x7e]/.test(value)) return null;
    const normalized = value.toLowerCase().replace(/\.$/, '');
    return normalized.split('.').every(label => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label)) ? normalized : null;
  };
  // Copy only protocol fields. The callback's objects never become queue storage.
  function environmentSnapshot(input) {
    if (!object(input) || input.version !== 1) return null;
    const result = {version: 1};
    for (const key of ['os', 'screen']) {
      if (!Object.hasOwn(input, key)) continue;
      const fact = input[key];
      if (!object(fact) || !['known', 'unknown', 'unavailable'].includes(fact.status)) return null;
      const normalized = {status: fact.status};
      if (key === 'os') {
        if (fact.status === 'known') {
          if (!['Windows', 'macOS', 'Linux', 'ChromeOS', 'Android', 'iOS'].includes(fact.family)) return null;
          normalized.family = fact.family;
        } else if (Object.hasOwn(fact, 'family')) return null;
      } else if (fact.status === 'known') {
        if (!Number.isInteger(fact.width) || !Number.isInteger(fact.height) || fact.width < 1 || fact.width > 16384 || fact.height < 1 || fact.height > 16384) return null;
        normalized.width = fact.width; normalized.height = fact.height;
      } else if (Object.hasOwn(fact, 'width') || Object.hasOwn(fact, 'height')) return null;
      result[key] = Object.freeze(normalized);
    }
    return Object.keys(result).length > 1 ? Object.freeze(result) : null;
  }
  function searchSnapshot(input) {
    if (!object(input) || input.version !== 1 || !['known', 'empty', 'filtered'].includes(input.status)) return null;
    const result = {version: 1, status: input.status};
    if (input.status === 'known') {
      const term = input.term;
      if (typeof term !== 'string' || !term.length || [...term].length > 80 || term !== term.toLowerCase().replace(/[\p{Z}\s]+/gu, ' ').trim() || !/^[\p{L}\p{M}\p{N} '\-+.]+$/u.test(term) || /[\x00-\x1f\x7f-\x9f@:/\\?&%#<>=]/u.test(term) || (term.match(/\p{N}/gu) || []).length >= 7) return null;
      if (/(?:^|[^\p{L}\p{M}\p{N}])[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[\p{L}]{2,}(?:$|[^\p{L}\p{M}\p{N}])/u.test(term)) return null;
      result.term = term;
    } else if (Object.hasOwn(input, 'term')) return null;
    return Object.freeze(result);
  }
  function metadataSnapshot(key, input) {
    if (!object(input) || input.version !== 1) return null;
    if (key === 'attribution') {
      const domain = host(input.host), query = input.landing_query, ref = input.referrer_host;
      if (!domain || !object(query) || !Object.hasOwn(input, 'referrer_host') || (ref !== null && ref !== '' && !host(ref))) return null;
      const landing = {};
      for (const name of ['utm_source', 'utm_medium']) if (Object.hasOwn(query, name)) {
        const value = query[name];
        if (typeof value !== 'string' || [...value].length > 120 || /[\x00-\x1f\x7f]/.test(value)) return null;
        landing[name] = value;
      }
      // Optional ad names: kept when well formed, otherwise left out without rejecting the snapshot.
      for (const name of ['utm_campaign', 'utm_term', 'utm_content']) if (Object.hasOwn(query, name)) {
        const value = query[name];
        if (typeof value === 'string' && value !== '' && [...value].length <= 120 && !/[\x00-\x1f\x7f]/.test(value)) landing[name] = value;
      }
      return Object.freeze({version: 1, host: domain, landing_query: Object.freeze(landing), referrer_host: ref === null || ref === '' ? ref : host(ref)});
    }
    const kinds = ['home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown'];
    if (!kinds.includes(input.kind)) return null;
    const result = {version: 1, kind: input.kind};
    if (input.kind === 'collection') {
      if (!Number.isSafeInteger(input.term_id) || input.term_id <= 0) return null;
      result.term_id = input.term_id;
    } else if (Object.hasOwn(input, 'term_id')) return null;
    if (Object.hasOwn(input, 'geo')) {
      if (!object(input.geo) || !['known', 'unknown', 'unavailable'].includes(input.geo.status)) return null;
      const geo = {status: input.geo.status};
      if (geo.status === 'known') {
        if (typeof input.geo.country_code !== 'string' || input.geo.country_code.length !== 2 || !/^[A-Za-z]{2}$/.test(input.geo.country_code)) return null;
        geo.country_code = input.geo.country_code.toUpperCase();
      } else if (Object.hasOwn(input.geo, 'country_code')) return null;
      result.geo = Object.freeze(geo);
    }
    if (Object.hasOwn(input, 'search')) {
      if (input.kind !== 'search') return null;
      const search = searchSnapshot(input.search); if (!search) return null; result.search = search;
    }
    if (Object.hasOwn(input, 'environment')) {
      const environment = environmentSnapshot(input.environment);
      if (!environment) return null;
      result.environment = environment;
    }
    return Object.freeze(result);
  }
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
  function pageViewReference(record) {
    return Object.freeze({event_id: record.event_id, page_instance_id: record.page_instance_id, path: record.page.path, page_type: record.page.page_type, occurred_at: record.occurred_at});
  }
  // This private path is deliberately absent from cleanData/the public action allowlist.
  function productSnapshot(input) {
    if (!object(input) || input.version !== 1 || !Number.isSafeInteger(input.product_id) || input.product_id <= 0 || !Number.isSafeInteger(input.variation_id) || input.variation_id < 0 || !Number.isFinite(input.visible_fraction) || input.visible_fraction < 0.4 || input.visible_fraction > 1 || !Number.isInteger(input.visible_ms) || input.visible_ms < 500 || input.visible_ms > 2147483647 || !object(input.context)) return null;
    const source = input.context;
    if (!['home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown'].includes(source.kind)) return null;
    const context = {kind: source.kind};
    if (Object.hasOwn(source, 'entity_id')) {
      if (!['product', 'collection'].includes(source.kind) || !Number.isSafeInteger(source.entity_id) || source.entity_id <= 0) return null;
      context.entity_id = source.entity_id;
    }
    if (Object.hasOwn(source, 'module_id')) {
      if (typeof source.module_id !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9-]{0,39}$/.test(source.module_id)) return null;
      context.module_id = source.module_id;
    }
    return Object.freeze({version: 1, product_id: input.product_id, variation_id: input.variation_id, visible_fraction: input.visible_fraction, visible_ms: input.visible_ms, context: Object.freeze(context)});
  }
  // Explicit source-confirmed product operations only; never infer success from clicks.
  function productActionSnapshot(type, input) {
    if (!['product_favorite', 'product_share', 'product_intent'].includes(type) || !object(input) || input.version !== 1 || typeof input.event_id !== 'string' || !/^[A-Za-z0-9-]{8,64}$/.test(input.event_id) || !Number.isSafeInteger(input.product_id) || input.product_id <= 0) return null;
    const variation = Object.hasOwn(input, 'variation_id') ? input.variation_id : 0;
    if (!Number.isSafeInteger(variation) || variation < 0) return null;
    const data = {version: 1, product_id: input.product_id, variation_id: variation};
    if (type === 'product_favorite') {
      if (input.action !== 'add') return null;
      data.action = 'add';
    } else {
      if (input.result !== 'completed') return null;
      if (type === 'product_share') {
        if (!['native', 'copy_link', 'other'].includes(input.method)) return null;
        data.method = input.method;
      } else {
        if (!['quick_view_open', 'buy_now'].includes(input.kind)) return null;
        data.kind = input.kind;
      }
      data.result = 'completed';
    }
    return {eventId: input.event_id, data: Object.freeze(data)};
  }
  function automatedBrowser() {
    // Headless / driven browsers (crawlers, scanners, test tools) are not shoppers.
    try {
      return navigator.webdriver === true || /HeadlessChrome|PhantomJS|Puppeteer|Playwright|Lighthouse/i.test(navigator.userAgent || '');
    } catch (error) { return false; }
  }
  function start(options = {}) {
    if (typeof options.sendBatch !== 'function' || !options.context || !window.crypto?.getRandomValues) return null;
    if (automatedBrowser()) return null;
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
    // Engagement gate (2026-11-08): nothing is sent until the person interacts (a trusted
    // pointer/touch/key/scroll) or the page has stayed open for engageMs. Crawlers that
    // render one page and leave within seconds therefore never become visitors.
    const engageMs = number(options.engageMs, 6000, 0, 60000);
    let engaged = engageMs === 0, engageTimer;
    let pageId = id(), seq = 0, lastActivity = Date.now(), stopped = false, suspended = false;
    let sending = false, flushTimer, flushAt = Infinity, requestController, requestTimer, heartbeatTimer;
    let videoObserver, productObserver, intentObserver, surfaceObserver, exitController, exitTimer, checkoutBridge;
    const bridgeCall = name => { try { checkoutBridge?.[name]?.(); } catch { try { checkoutBridge?.stop?.(); } catch {} checkoutBridge = null; } };
    const pending = [], listeners = [], cleanups = [];
    // Retain bounded operation tombstones for this observer, including ACKed IDs.
    // A source may repeat its success callback, but must never create a new UID for it.
    const productActions = new Map();
    const stats = {created: 0, acknowledged: 0, dropped: 0, failedBatches: 0};
    const metadataEnabled = typeof options.pageViewMetadata === 'function';
    if (metadataEnabled) Object.assign(stats, {extensionUnsupported: 0, extensionDropped: 0});
    function retire(entry, reason, acknowledgedPageView = false) {
      const index = pending.indexOf(entry);
      if (index < 0) return false;
      pending.splice(index, 1);
      const action = productActions.get(entry.record.event_id);
      if (action) action.retired = reason;
      if (metadataEnabled && ['exhausted', 'overflow', 'stopped'].includes(reason) && ['attribution', 'page_context'].some(key => Object.hasOwn(entry.record.data, key))) stats.extensionDropped++;
      try { options.onEventRetired?.(entry.record.event_id, reason); } catch { /* Retirement cannot affect collection. */ }
      if (acknowledgedPageView && reason === 'acknowledged' && entry.record.type === 'page_view') {
        try { options.onPageViewAcknowledged?.(pageViewReference(entry.record)); } catch { /* Optional source notification. */ }
      }
      return true;
    }
    const listen = (target, type, listener, opts) => {
      target.addEventListener(type, listener, opts);
      listeners.push(() => target.removeEventListener(type, listener, opts));
    };
    const state = () => {
      const hidden = document.visibilityState === 'hidden';
      const age = Math.max(0, Date.now() - lastActivity);
      return {visibility: hidden ? 'hidden' : 'visible', activity: hidden || age >= idleMs ? 'idle' : 'active', last_activity_age_ms: age};
    };
    // The physical page remains the source for views/impressions/actions.
    // Native dialogs only change the current-content envelope of presence events.
    const physicalTypes = new Set(['page_view', 'product_view', 'product_impression']);
    function surfacePage() {
      try {
        if (window.navigator?.globalPrivacyControl === true) return null;
        const surface = surfaceObserver?.snapshot?.();
        if (!object(surface) || !['product', 'cart'].includes(surface.page_type)) return null;
        if (surface.page_type === 'product' && (!integer(surface.product_id) || typeof surface.product_name !== 'string' || !surface.product_name.trim())) return null;
        // Keep the real document pathname; opening a card is not a navigation.
        return pageContext({...surface, path: page.path});
      } catch { return null; }
    }
    const differentPage = value => value && JSON.stringify(value) !== JSON.stringify(page);
    function reserve(count) {
      const removeCount = Math.max(0, pending.length + count - maxQueue);
      const removable = pending.filter(entry => !entry.inFlight).slice(0, removeCount);
      if (removable.length < removeCount) { stats.dropped += count; return false; }
      for (const entry of removable) { retire(entry, 'overflow'); stats.dropped++; }
      return true;
    }
    const currentHeartbeat = (current, occurredAt = new Date().toISOString(), sequence = ++seq) => Object.freeze({
      event_id: id(), page_instance_id: pageId, seq: sequence, type: 'heartbeat', occurred_at: occurredAt,
      page: Object.freeze({...current}), data: Object.freeze(state())
    });
    function engage() {
      if (engaged || stopped) return;
      engaged = true; clearTimeout(engageTimer);
      if (pending.length) schedule(0);
    }
    function schedule(delay = flushMs) {
      if (stopped || suspended || !pending.length || !engaged) return;
      const wait = Math.max(0, delay, retryAt - Date.now());
      const due = Date.now() + wait;
      if (flushTimer && due >= flushAt) return;
      clearTimeout(flushTimer); flushAt = due;
      flushTimer = setTimeout(() => { flushTimer = null; flushAt = Infinity; void flush(); }, wait);
    }
    function emit(type, data = {}) {
      if (stopped || suspended) return;
      const current = surfacePage(), physical = physicalTypes.has(type), restore = physical && differentPage(current);
      if (!reserve(restore ? 2 : 1)) return;
      const record = {event_id: id(), page_instance_id: pageId, seq: ++seq, type, occurred_at: new Date().toISOString(), page: {...(physical ? page : (current || page))}, data};
      if (type === 'page_view' && metadataEnabled) {
        try {
          const metadata = options.pageViewMetadata();
          if (!object(metadata)) stats.extensionUnsupported++;
          else for (const key of ['attribution', 'page_context']) if (Object.hasOwn(metadata, key)) {
            try {
              const snapshot = metadataSnapshot(key, metadata[key]);
              if (snapshot) record.data[key] = snapshot; else stats.extensionUnsupported++;
            } catch { stats.extensionUnsupported++; }
          }
        } catch { stats.extensionUnsupported++; }
      }
      const presenceGroup = restore ? {} : null;
      pending.push({record, attempts: 0, readyAt: 0, inFlight: false, presenceGroup});
      stats.created++;
      // Ingest updates current tab context for every accepted event. Restore it
      // after a physical-source observation so it cannot briefly revert to home.
      if (restore) { pending.push({record: currentHeartbeat(current), attempts: 0, readyAt: 0, inFlight: false, presenceGroup}); stats.created++; }
      if (type === 'page_view') { try { options.onPageViewCreated?.(pageViewReference(record)); } catch { /* Optional source notification. */ } }
      schedule();
    }
    function pushProductAction(type, facts) {
      if (options.productActionsProtocol !== 1 || stopped || suspended) return false;
      try { if (window.navigator?.globalPrivacyControl === true) return false; } catch { return false; }
      let snapshot;
      try { snapshot = productActionSnapshot(type, facts); } catch { return false; }
      if (!snapshot) return false;
      const signature = JSON.stringify({type, data: snapshot.data});
      const previous = productActions.get(snapshot.eventId);
      if (previous) return previous.signature === signature && (!previous.retired || previous.retired === 'acknowledged') ? snapshot.eventId : false;
      // Do not evict tombstones and silently allow a previously used UID again.
      if (productActions.size >= 2000 || pending.some(entry => entry.record.event_id === snapshot.eventId)) return false;
      if (type === 'product_intent' && snapshot.data.kind === 'quick_view_open') {
        // Reserve the source pair and optional presence restoration atomically.
        const current = surfacePage(), restore = differentPage(current), count = restore ? 3 : 2;
        const removeCount = Math.max(0, Math.ceil(pending.length + count - maxQueue));
        const removable = pending.filter(entry => !entry.inFlight).slice(0, removeCount);
        if (removable.length < removeCount) return false;
        let records;
        try {
          const viewId = id();
          if (typeof viewId !== 'string' || !/^[A-Za-z0-9-]{8,64}$/.test(viewId) || viewId === snapshot.eventId || productActions.has(viewId) || pending.some(entry => entry.record.event_id === viewId)) return false;
          const occurredAt = new Date().toISOString(), currentPage = Object.freeze({...page});
          records = [
            Object.freeze({event_id: snapshot.eventId, page_instance_id: pageId, seq: seq + 1, type, occurred_at: occurredAt, page: currentPage, data: snapshot.data}),
            Object.freeze({event_id: viewId, page_instance_id: pageId, seq: seq + 2, type: 'product_view', occurred_at: occurredAt, page: currentPage, data: Object.freeze({product_id: snapshot.data.product_id})})
          ];
          if (restore) records.push(currentHeartbeat(current, occurredAt, seq + 3));
        } catch { return false; }
        for (const entry of removable) { retire(entry, 'overflow'); stats.dropped++; }
        seq += records.length; productActions.set(snapshot.eventId, {signature, retired: null});
        const presenceGroup = restore ? {} : null;
        pending.push(...records.map(record => ({record, attempts: 0, readyAt: 0, inFlight: false, presenceGroup})));
        stats.created += records.length; schedule();
        return snapshot.eventId;
      }
      const current = surfacePage(), restore = differentPage(current);
      if (!reserve(restore ? 2 : 1)) return false;
      const record = Object.freeze({event_id: snapshot.eventId, page_instance_id: pageId, seq: ++seq, type, occurred_at: new Date().toISOString(), page: Object.freeze({...page}), data: snapshot.data});
      productActions.set(snapshot.eventId, {signature, retired: null});
      const presenceGroup = restore ? {} : null;
      pending.push({record, attempts: 0, readyAt: 0, inFlight: false, presenceGroup});
      stats.created++;
      if (restore) { pending.push({record: currentHeartbeat(current), attempts: 0, readyAt: 0, inFlight: false, presenceGroup}); stats.created++; }
      schedule();
      return snapshot.eventId;
    }
    function schedulePending() {
      const waiting = pending.filter(entry => !entry.inFlight);
      if (waiting.length) schedule(Math.max(0, Math.min(...waiting.map(entry => entry.readyAt)) - Date.now()));
    }
    async function flush(hint = {}) {
      if (!engaged) return false;
      if (stopped || (!hint.unload && suspended)) return false;
      if (retryAt > Date.now()) { schedulePending(); return false; }
      if (sending) { if (hint.unload) return flushExit(); return false; }
      clearTimeout(flushTimer); flushTimer = null; flushAt = Infinity;
      const ready = pending.filter(entry => !entry.inFlight && (hint.unload || entry.readyAt <= Date.now()));
      let batch = ready.slice(0, batchMax);
      const boundaryGroup = batch.at(-1)?.presenceGroup;
      if (boundaryGroup && ready[batch.length]?.presenceGroup === boundaryGroup) {
        const groupSize = ready.filter(entry => entry.presenceGroup === boundaryGroup).length;
        // Keep a source observation and its current-state heartbeat in one
        // request whenever the configured cap can accommodate that group.
        // Tiny configured caps still drain in order rather than stall or grow.
        if (groupSize <= batchMax) batch = batch.filter(entry => entry.presenceGroup !== boundaryGroup);
      }
      if (!batch.length) { schedulePending(); return false; }
      sending = true;
      batch.forEach(entry => { entry.inFlight = true; entry.attempts++; });
      requestController = new AbortController();
      const controller = requestController;
      let ack = new Set();
      let discarded = new Set();
      let acceptedPageViews = new Set();
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
        acceptedPageViews = new Set(Array.isArray(result?.pageViewAckIds) ? result.pageViewAckIds : ack);
        if (Number.isFinite(result?.retryAfterMs) && result.retryAfterMs > 0) retryAt = Math.max(retryAt, Date.now() + Math.min(3600000, result.retryAfterMs));
        if (!stopped && batch.some(entry => !ack.has(entry.record.event_id) && !discarded.has(entry.record.event_id))) stats.failedBatches++;
      } catch { if (!stopped) stats.failedBatches++; }
      finally {
        clearTimeout(requestTimer); requestController = null;
        for (const entry of stopped ? [] : batch) {
          entry.inFlight = false;
          if (ack.has(entry.record.event_id) || discarded.has(entry.record.event_id) || entry.attempts >= maxAttempts) {
            const reason = ack.has(entry.record.event_id) ? 'acknowledged' : discarded.has(entry.record.event_id) ? 'discarded' : 'exhausted';
            if (retire(entry, reason, acceptedPageViews.has(entry.record.event_id))) { if (reason === 'acknowledged') stats.acknowledged++; else stats.dropped++; }
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
      if (!engaged || stopped || exitController || retryAt > Date.now()) return false;
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
        const acceptedPageViews = new Set(Array.isArray(result?.pageViewAckIds) ? result.pageViewAckIds : ack);
        if (Number.isFinite(result?.retryAfterMs) && result.retryAfterMs > 0) retryAt = Math.max(retryAt, Date.now() + Math.min(3600000, result.retryAfterMs));
        for (const entry of batch) if (ack.has(entry.record.event_id) || discarded.has(entry.record.event_id)) {
          if (retire(entry, ack.has(entry.record.event_id) ? 'acknowledged' : 'discarded', acceptedPageViews.has(entry.record.event_id))) { if (ack.has(entry.record.event_id)) stats.acknowledged++; else stats.dropped++; }
        }
      }).catch(() => { if (!stopped) stats.failedBatches++; }).finally(() => {
        clearTimeout(exitTimer); exitController = null;
        for (const entry of batch) {
          entry.inFlight = false;
          entry.readyAt = Math.max(retryAt, Date.now() + 1000);
          if (entry.attempts >= maxAttempts && retire(entry, 'exhausted') && !stopped) stats.dropped++;
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
    const activity = event => { if (!event.isTrusted) return; engage(); if (Date.now() - lastActivity >= 1000) lastActivity = Date.now(); };
    if (!engaged) engageTimer = setTimeout(() => { if (!document.hidden) engage(); else listen(document, 'visibilitychange', () => { if (!document.hidden) engage(); }, {once: true}); }, engageMs);
    cleanups.push(() => clearTimeout(engageTimer));
    ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach(type => listen(document, type, activity, {passive: true}));
    listen(document, 'visibilitychange', () => { videoObserver?.flush(); emit('visibility_change', state()); if (document.hidden) void flush(); });
    listen(window, 'pagehide', event => {
      bridgeCall('suspend');
      productObserver?.suspend();
      intentObserver?.suspend();
      videoObserver?.flush();
      emit('page_exit', {persisted: event.persisted, ...state()});
      void flush({unload: true});
      surfaceObserver?.suspend();
      suspended = true; clearInterval(heartbeatTimer); clearTimeout(flushTimer); flushTimer = null; flushAt = Infinity;
    });
    // A background tab is still present, but is neither active nor watched.
    // Keep its lightweight presence signal; pagehide/stop clear this timer.
    // Browser freezing or throttling can still prevent delivery, so the server
    // must retain its timeout fallback rather than infer guaranteed presence.
    const heartbeat = () => { heartbeatTimer = setInterval(() => emit('heartbeat', state()), heartbeatMs); };
    listen(window, 'pageshow', event => {
      if (!event.persisted || stopped) return;
      suspended = false; pageId = id(); seq = 0; lastActivity = Date.now(); surfaceObserver?.resume(); begin('bfcache');
      bridgeCall('reset'); bridgeCall('resume');
      // A restored document has a new page instance; reset its video counters too.
      videoObserver?.stop(); observeVideos();
      productObserver?.stop(); observeProducts();
      intentObserver?.stop(); observeIntents();
      clearInterval(heartbeatTimer); heartbeat();
    });
    listen(window, 'online', () => schedule(0));
    listen(document, 'vc:visitor-action', event => {
      const type = event.detail?.type;
      if (typeof type !== 'string' || !Object.hasOwn(allowed, type) || type.startsWith('video_')) return;
      const data = cleanData(type, event.detail?.data);
      if (data) emit(type, data);
    });
    // Stable module/product references only: no labels, destinations, input or form values.
    listen(document, 'click', event => {
      if (!event.isTrusted || event.button !== 0 || window.navigator?.globalPrivacyControl === true) return;
      const control = event.target.closest?.('a,button,summary,[role="button"]');
      if (!control || control.disabled || control.getAttribute('aria-disabled') === 'true') return;
      const module = control.closest('[data-module]');
      if (module && /^[a-zA-Z0-9_-]{1,60}$/.test(module.id) && /^[a-zA-Z0-9_-]{1,40}$/.test(module.dataset.module || '')) {
        emit('module_click', {module_id:module.id,module_type:module.dataset.module});
      } else {
        const fixed = control.closest('[data-pc-first-order-offer]') ? 'first-order-offer' : control.closest('header') ? 'header' : control.closest('footer') ? 'footer' : null;
        if (fixed) emit('module_click', {module_id:fixed,module_type:fixed});
      }
      const card = control.closest('[data-vc-home-product],[data-vc-quick-open],[data-product_id],[data-product-id],[data-vc-product-id]');
      const product = integer(card?.dataset.vcHomeProduct || card?.dataset.vcQuickOpen || card?.dataset.product_id || card?.dataset.productId || card?.dataset.vcProductId);
      if (product) emit('product_click', {product_id:product});
    }, {capture:true});
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
    function observeProducts() {
      if (options.productObservation?.protocol !== 1 || typeof window.VialCoveVisitorProducts?.observe !== 'function') return;
      productObserver = window.VialCoveVisitorProducts.observe(document, data => {
        const snapshot = productSnapshot(data); if (snapshot) emit('product_impression', snapshot);
      }, options.productObservation);
    }
    function observeIntents() {
      if (options.productActionsProtocol !== 1 || typeof window.VialCoveVisitorIntent?.observe !== 'function') return;
      intentObserver = window.VialCoveVisitorIntent.observe(document, facts => pushProductAction('product_intent', facts));
    }
    function observeSurfaces() {
      if (typeof window.VialCoveVisitorSurfaces?.observe !== 'function') return;
      try {
        surfaceObserver = window.VialCoveVisitorSurfaces.observe(document, () => {
          // The callback is a DOM-validated change notification, never a page view.
          if (!stopped && !suspended) emit('heartbeat', state());
        });
      } catch { surfaceObserver = null; }
    }
    cleanups.push(() => videoObserver?.stop());
    cleanups.push(() => productObserver?.stop());
    cleanups.push(() => intentObserver?.stop());
    cleanups.push(() => surfaceObserver?.stop());
    cleanups.push(() => bridgeCall('stop'));
    function stop() {
      if (stopped) return;
      stopped = true; requestController?.abort(); exitController?.abort();
      clearTimeout(flushTimer); clearTimeout(requestTimer); clearTimeout(exitTimer); clearInterval(heartbeatTimer); sending = false;
      listeners.forEach(remove => remove()); cleanups.forEach(cleanup => cleanup());
      for (const entry of [...pending]) retire(entry, 'stopped');
      if (active === api) active = null;
    }
    const api = {flush, stop, pushProductAction, getStats: () => ({...stats, queued: pending.length, sending, stopped})};
    active = api;
    observeSurfaces(); begin('navigation'); observeVideos(); observeProducts(); observeIntents(); heartbeat();
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
