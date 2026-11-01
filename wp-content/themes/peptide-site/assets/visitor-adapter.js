/* Protocol 1 transport for the explicitly enabled visitor observer.
 * The server owns visitor/visit identity and transaction facts. */
(() => {
  'use strict';
  if (window.VialCoveVisitorTransport) return;
  const STORAGE_KEY = 'vc_tab_id';
  const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
  const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const own = (value, key) => object(value) && Object.hasOwn(value, key);
  function host(value) {
    if (typeof value !== 'string' || !value.length || value.length > 253 || /[^\x21-\x7e]/.test(value)) return null;
    const normalized = value.toLowerCase().replace(/\.$/, '');
    return normalized.split('.').every(label => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label)) ? normalized : null;
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
  function observation(input, searchEnabled = false) {
    if (!object(input) || input.version !== 1 || !object(input.page) || !object(input.context)) return null;
    const types = ['home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content'];
    const source = input.page, context = input.context;
    if (!types.includes(source.page_type) || typeof source.path !== 'string' || !source.path.startsWith('/') || source.path.startsWith('//') || source.path.length > 500 || /[\x00-\x20\x7f\\?#]/.test(source.path)) return null;
    try { const url = new URL(source.path, location.origin); if (url.origin !== location.origin || url.pathname !== source.path) return null; } catch { return null; }
    if (['account', 'order_received', 'order_pay'].includes(source.page_type) && source.path !== (source.page_type === 'account' ? '/my-account/' : '/checkout/')) return null;
    const page = {page_type: source.page_type, path: source.path};
    if (source.page_type === 'product') {
      if (!Number.isSafeInteger(source.product_id) || source.product_id <= 0 || typeof source.product_name !== 'string') return null;
      page.product_id = source.product_id; page.product_name = source.product_name;
    }
    const kinds = [...types, 'collection', 'search', 'unknown'];
    if (context.version !== 1 || !kinds.includes(context.kind)) return null;
    if (context.kind === 'collection' ? source.page_type !== 'shop' : context.kind === 'search' ? source.page_type !== 'content' : context.kind !== 'unknown' && context.kind !== source.page_type) return null;
    const normalized = {version: 1, kind: context.kind};
    if (context.kind === 'collection') {
      if (!Number.isSafeInteger(context.term_id) || context.term_id <= 0) return null;
      normalized.term_id = context.term_id;
    } else if (Object.hasOwn(context, 'term_id')) return null;
    if (Object.hasOwn(context, 'geo')) {
      const geo = context.geo;
      if (!object(geo) || !['known', 'unknown', 'unavailable'].includes(geo.status)) return null;
      normalized.geo = {status: geo.status};
      if (geo.status === 'known') {
        if (typeof geo.country_code !== 'string' || geo.country_code.length !== 2 || !/^[A-Za-z]{2}$/.test(geo.country_code)) return null;
        normalized.geo.country_code = geo.country_code.toUpperCase();
      } else if (Object.hasOwn(geo, 'country_code')) return null;
      Object.freeze(normalized.geo);
    }
    if (searchEnabled && Object.hasOwn(context, 'search')) {
      if (context.kind !== 'search') return null;
      const search = searchSnapshot(context.search); if (!search) return null; normalized.search = search;
    }
    return Object.freeze({page: Object.freeze(page), context: Object.freeze(normalized)});
  }
  function attribution() {
    try {
      const url = new URL(location.href), domain = host(url.hostname), query = {};
      if (!domain) return null;
      const params = url.searchParams;
      for (const key of ['utm_source', 'utm_medium']) {
        const values = params.getAll(key);
        if (values.length > 1) return null;
        if (values.length) {
          if ([...values[0]].length > 120 || /[\x00-\x1f\x7f]/.test(values[0])) return null;
          query[key] = values[0];
        }
      }
      let referrer = null;
      try { const value = document.referrer; referrer = value === '' ? '' : typeof value === 'string' ? host(new URL(value).hostname) : null; } catch { /* Unavailable referrer remains null. */ }
      return {version: 1, host: domain, landing_query: query, referrer_host: referrer};
    } catch { return null; }
  }
  // Low-entropy browser reports only. Called synchronously for a new PV, never a send/retry.
  function clientEnvironment() {
    let os, screen;
    try {
      const nav = window.navigator;
      const values = nav ? [nav.userAgentData?.platform, nav.platform, nav.userAgent].filter(value => typeof value === 'string') : [];
      const text = values.join(' ');
      const specific = [
        [/android/i, 'Android'], [/(?:iPhone|iPad|iPod|\biOS\b)/i, 'iOS'], [/(?:CrOS|Chrome\s?OS)/i, 'ChromeOS']
      ].filter(([pattern]) => pattern.test(text)).map(([, family]) => family);
      const windows = /(?:Windows|Win32|Win64|WinCE)/i.test(text);
      const mac = /(?:Macintosh|MacIntel|MacPPC|Mac OS|macOS)/i.test(text);
      const linux = /Linux/i.test(text);
      let family = null;
      if (!/(?:Windows Phone|KaiOS|Tizen|FreeBSD|OpenBSD)/i.test(text) && specific.length <= 1) {
        if (specific.length === 1) {
          const selected = specific[0];
          if (!windows && !(selected === 'Android' && mac) && !(selected === 'iOS' && linux) && !(selected === 'ChromeOS' && mac)) family = selected;
        } else if ([windows, mac, linux].filter(Boolean).length === 1) family = windows ? 'Windows' : mac ? 'macOS' : 'Linux';
      }
      os = family ? {status: 'known', family} : {status: 'unknown'};
    } catch { os = {status: 'unavailable'}; }
    try {
      const native = window.screen, width = native?.width, height = native?.height;
      screen = Number.isInteger(width) && Number.isInteger(height) && width >= 1 && width <= 16384 && height >= 1 && height <= 16384 ? {status: 'known', width, height} : {status: 'unknown'};
    } catch { screen = {status: 'unavailable'}; }
    return {version: 1, os, screen};
  }
  const terminalCodes = {
    attribution: {applied: ['captured', 'already_captured'], preserved: ['visit_already_captured'], rejected: ['core_rejected', 'wrong_event_type', 'bad_attribution', 'unsupported_version', 'duplicate_without_capture', 'duplicate_payload_mismatch', 'original_visit_missing']},
    page_context: {applied: ['saved_context', 'already_saved'], rejected: ['core_rejected', 'wrong_event_type', 'bad_page_context', 'unsupported_version', 'duplicate_without_context', 'duplicate_payload_mismatch', 'original_visit_missing']}
  };
  // Validate ordered attempt positions before considering any UID-level decision.
  function receipts(events, data, key) {
    const attempts = events.filter(event => own(event.data, key));
    if (!attempts.length) return [];
    const extension = own(data.extensions, key) ? data.extensions[key] : null;
    if (!object(extension) || extension.version !== 1 || !Array.isArray(extension.results) || extension.results.length !== attempts.length) return null;
    const results = extension.results;
    if (!results.every((row, index) => object(row) && Object.keys(row).length === 4 && ['event_id', 'status', 'code', 'retry'].every(name => Object.hasOwn(row, name)) && row.event_id === attempts[index].event_id && ((row.status === 'retry' && row.code === 'server_error' && row.retry === true) || (row.retry === false && Object.hasOwn(terminalCodes[key], row.status) && terminalCodes[key][row.status].includes(row.code))))) return null;
    return results;
  }
  const uuid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
  };
  let active, last;
  function start(config = window.VialCoveVisitorConfig, context = window.VialCoveVisitorPage) {
    if (active) return active;
    if (!config || config.enabled !== true || config.protocol !== 1 || typeof config.endpoint !== 'string' || !config.endpoint.trim() || !context || !window.VialCoveVisitorObserver?.start || !window.crypto?.getRandomValues || !window.fetch) return null;
    let endpoint;
    try {
      endpoint = new URL(config.endpoint, location.href);
      if (endpoint.origin !== location.origin || !['http:', 'https:'].includes(endpoint.protocol) || endpoint.username || endpoint.password) return null;
    } catch { return null; }
    let tabId, stored = false;
    try { tabId = sessionStorage.getItem(STORAGE_KEY); stored = true; } catch { /* Per-page identity remains available at the server. */ }
    const saveTab = () => { try { sessionStorage.setItem(STORAGE_KEY, tabId); } catch { stored = false; } };
    if (stored && !UUID.test(tabId || '')) { tabId = uuid(); saveTab(); }
    if (!stored) tabId = undefined;
    const owner = uuid();
    let channel, bootTimer, observer, stopped = false, ready = false, cooldown = 0;
    let requests = 0, lastStatus = null;
    const protocols = config.metadata_protocols;
    const capabilities = {attribution: own(protocols, 'attribution') && protocols.attribution === 1, page_context: own(protocols, 'page_context') && protocols.page_context === 1};
    const metadataEnabled = capabilities.attribution || capabilities.page_context;
    const productProtocol = config.product_impression_protocol === 1 && typeof window.VialCoveVisitorProducts?.observe === 'function';
    const pageObservation = capabilities.page_context || productProtocol ? observation(config.page_observation, capabilities.page_context && own(config, 'search_terms_protocol') && config.search_terms_protocol === 1) : null;
    const environmentEnabled = capabilities.page_context && own(config, 'client_environment_protocol') && config.client_environment_protocol === 1;
    const productEnabled = productProtocol && pageObservation !== null;
    const actionsEnabled = own(config, 'product_actions_protocol') && config.product_actions_protocol === 1;
    const identityEnabled = metadataEnabled || productEnabled || actionsEnabled;
    const identities = new Map();
    const diagnostics = {extensionUnresolved: 0, extensionRejected: 0};
    const batchMax = Math.max(1, Math.min(20, Math.floor(Number(config.batch_max) || 20)));
    const empty = () => ({ackEventIds: [], discardEventIds: []});
    const merge = (a, b) => ({ackEventIds: [...a.ackEventIds, ...b.ackEventIds], discardEventIds: [...a.discardEventIds, ...b.discardEventIds], retryAfterMs: Math.max(a.retryAfterMs || 0, b.retryAfterMs || 0)});
    const waitResult = () => ({...empty(), retryAfterMs: Math.max(0, cooldown - Date.now())});
    const payload = (events, identity) => JSON.stringify({protocol: 1, ...((identityEnabled ? identity : tabId) ? {tab_id: identityEnabled ? identity : tabId} : {}), sent_at: new Date().toISOString(), events});
    const byteLength = value => new TextEncoder().encode(value).length;
    const retryDelay = (response, data) => {
      const header = response.headers.get('Retry-After');
      let ms = header && /^\d+(\.\d+)?$/.test(header) ? Number(header) * 1000 : Date.parse(header || '') - Date.now();
      if (!Number.isFinite(ms)) ms = (Number(data?.data?.retry_after) || 300) * 1000;
      return Math.max(1000, Math.min(3600000, ms));
    };
    async function sendGroup(events, hint, identity) {
      if (stopped || hint.signal?.aborted) throw new DOMException('Tracking stopped', 'AbortError');
      if (cooldown > Date.now()) return waitResult();
      requests++;
      const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', mode: 'same-origin', redirect: 'error', cache: 'no-store', referrerPolicy: 'no-referrer', headers: {'Content-Type': 'application/json'}, body: payload(events, identity), keepalive: hint.unload === true, signal: hint.signal});
      lastStatus = response.status;
      // Includes disabled, staff and pc_track_declined (GPC / opt-out).
      // stop() clears the entire observer queue and timers; never retry a denial.
      if (response.status === 403) { stop(); return empty(); }
      if (response.status === 413) {
        if (events.length === 1) return {...empty(), discardEventIds: [events[0].event_id]};
        const middle = Math.ceil(events.length / 2);
        const first = await sendGroup(events.slice(0, middle), hint, identity);
        if (first.retryAfterMs) return first;
        try { return merge(first, await sendGroup(events.slice(middle), hint, identity)); }
        catch (error) {
          // A later split can fail after the server has accepted the first half.
          if (!first.ackEventIds.length && !first.discardEventIds.length) throw error;
          return first;
        }
      }
      if (response.status === 400) return {...empty(), discardEventIds: events.map(event => event.event_id)};
      let data;
      try { data = await response.json(); } catch { if (response.status !== 429) throw new Error('Invalid tracking response'); }
      if (response.status === 429) {
        cooldown = Date.now() + retryDelay(response, data);
        return waitResult();
      }
      if (!response.ok || data?.protocol !== 1 || !Array.isArray(data.ack) || !Array.isArray(data.rejected)) throw new Error('Tracking delivery was not acknowledged');
      const sentIds = new Set(events.map(event => event.event_id));
      const acknowledged = [...data.ack, ...data.rejected.filter(item => item?.retry === false).map(item => item.event_id)];
      if (metadataEnabled) {
        const coreAck = new Set(data.ack), coreRejected = new Set(data.rejected.filter(item => item?.retry === false).map(item => item.event_id));
        const parsed = {attribution: receipts(events, data, 'attribution'), page_context: receipts(events, data, 'page_context')};
        const positions = {attribution: 0, page_context: 0}, decisions = new Map();
        for (const event of events) {
          let attempted = false, terminal = true;
          for (const key of ['attribution', 'page_context']) if (own(event.data, key)) {
            attempted = true;
            const receipt = parsed[key]?.[positions[key]++];
            if (!receipt || receipt.retry !== false) terminal = false;
            else if (receipt.status === 'rejected') diagnostics.extensionRejected++;
          }
          const decision = !attempted ? acknowledged.includes(event.event_id) ? 'ack' : 'pending' : coreRejected.has(event.event_id) ? 'discard' : coreAck.has(event.event_id) && terminal ? 'ack' : 'pending';
          if (attempted && coreAck.has(event.event_id) && !terminal) diagnostics.extensionUnresolved++;
          const previous = decisions.get(event.event_id);
          decisions.set(event.event_id, previous === 'pending' || decision === 'pending' ? 'pending' : previous === 'discard' || decision === 'discard' ? 'discard' : decision);
        }
        return {...empty(), ackEventIds: [...decisions].filter(([, value]) => value === 'ack').map(([id]) => id), discardEventIds: [...decisions].filter(([, value]) => value === 'discard').map(([id]) => id)};
      }
      return {...empty(), ackEventIds: [...new Set(acknowledged.filter(value => typeof value === 'string' && sentIds.has(value)))]};
    }
    async function sendBatch(events, hint = {}) {
      if (stopped || hint.signal?.aborted) throw new DOMException('Tracking stopped', 'AbortError');
      if (identityEnabled) for (const event of events) if (!identities.has(event.event_id)) identities.set(event.event_id, tabId);
      if (cooldown > Date.now()) return waitResult();
      const groups = []; let group = [], identity, result = empty();
      for (const event of events) {
        const frozen = identityEnabled ? identities.get(event.event_id) : undefined;
        if (byteLength(payload([event], frozen)) > 60000) { result.discardEventIds.push(event.event_id); continue; }
        if (group.length && (group.length >= batchMax || (identityEnabled && identity !== frozen) || byteLength(payload([...group, event], frozen)) > 60000)) { groups.push({events: group, identity}); group = []; }
        identity = frozen;
        group.push(event);
      }
      if (group.length) groups.push({events: group, identity});
      for (const batch of groups) {
        try { result = merge(result, await sendGroup(batch.events, hint, batch.identity)); }
        catch (error) {
          // Keep confirmed partial ACKs; unconfirmed records use the observer's retry policy.
          if (!result.ackEventIds.length && !result.discardEventIds.length) throw error;
          return result;
        }
        if (stopped || result.retryAfterMs) break;
      }
      return result;
    }
    const post = message => { try { channel?.postMessage({...message, owner, tab_id: tabId}); } catch { /* Tab coordination is best effort. */ } };
    const rotate = () => { tabId = uuid(); saveTab(); post({type: 'probe'}); };
    function openChannel() {
      if (!tabId || !window.BroadcastChannel || stopped) return;
      try {
        channel = new BroadcastChannel('vialcove-test-tracking-tabs');
        channel.onmessage = ({data}) => {
          if (!data || data.owner === owner || data.tab_id !== tabId) return;
          if (data.type === 'occupied' && data.target === owner) { rotate(); return; }
          if (data.type !== 'probe') return;
          // Existing pages keep their ID; simultaneous cloned tabs elect one owner.
          if (ready || owner < data.owner) post({type: 'occupied', target: data.owner});
          else rotate();
        };
        post({type: 'probe'});
      } catch { channel = null; }
    }
    const closeChannel = () => { channel?.close(); channel = null; };
    const restoreChannel = event => { if (event.persisted) openChannel(); };
    function boot() {
      if (stopped) return;
      ready = true;
      observer = window.VialCoveVisitorObserver.start({context: pageObservation?.page || context, heartbeatMs: config.heartbeat_ms, idleMs: config.idle_ms, batchMax, sendBatch,
        ...(metadataEnabled ? {pageViewMetadata: () => ({...(capabilities.attribution ? {attribution: attribution()} : {}), ...(capabilities.page_context ? {page_context: pageObservation ? {...pageObservation.context, ...(environmentEnabled ? {environment: clientEnvironment()} : {})} : null} : {})})} : {}),
        ...(actionsEnabled ? {productActionsProtocol: 1} : {}),
        ...(identityEnabled ? {onEventRetired: eventId => identities.delete(eventId)} : {}),
        ...(productEnabled ? {productObservation: {protocol: 1, page: pageObservation.page, context: pageObservation.context}} : {})});
      if (stopped) { observer?.stop(); identities.clear(); return; }
      if (!observer) stop();
    }
    function stop() {
      if (stopped) { identities.clear(); return; }
      stopped = true; clearTimeout(bootTimer); closeChannel(); observer?.stop();
      identities.clear();
      window.removeEventListener('pagehide', closeChannel);
      window.removeEventListener('pageshow', restoreChannel);
      if (active === api) active = null;
    }
    const api = {stop, pushProductAction: (type, facts) => !stopped && ready && actionsEnabled ? observer?.pushProductAction(type, facts) || false : false, getStats: () => ({...(observer?.getStats() || {queued: 0}), ...(metadataEnabled ? {...diagnostics, metadataPending: identities.size} : {}), stopped, starting: !ready && !stopped, requests, lastStatus, retryAfterMs: Math.max(0, cooldown - Date.now())})};
    active = api;
    last = api;
    window.addEventListener('pagehide', closeChannel);
    window.addEventListener('pageshow', restoreChannel);
    openChannel();
    if (channel) bootTimer = setTimeout(boot, 80); else boot();
    return api;
  }
  window.VialCoveVisitorTransport = {start, pushProductAction: (type, facts) => active?.pushProductAction(type, facts) || false, stop: () => active?.stop(), getStats: () => (active || last)?.getStats() || {stopped: true, starting: false, queued: 0, requests: 0}};
  start();
})();
