/* Protocol 1 transport for the explicitly enabled visitor observer.
 * The server owns visitor/visit identity and transaction facts. */
(() => {
  'use strict';
  if (window.VialCoveVisitorTransport) return;
  const STORAGE_KEY = 'vc_tab_id';
  const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
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
    const batchMax = Math.max(1, Math.min(20, Math.floor(Number(config.batch_max) || 20)));
    const empty = () => ({ackEventIds: [], discardEventIds: []});
    const merge = (a, b) => ({ackEventIds: [...a.ackEventIds, ...b.ackEventIds], discardEventIds: [...a.discardEventIds, ...b.discardEventIds], retryAfterMs: Math.max(a.retryAfterMs || 0, b.retryAfterMs || 0)});
    const waitResult = () => ({...empty(), retryAfterMs: Math.max(0, cooldown - Date.now())});
    const payload = events => JSON.stringify({protocol: 1, ...(tabId ? {tab_id: tabId} : {}), sent_at: new Date().toISOString(), events});
    const byteLength = value => new TextEncoder().encode(value).length;
    const retryDelay = (response, data) => {
      const header = response.headers.get('Retry-After');
      let ms = header && /^\d+(\.\d+)?$/.test(header) ? Number(header) * 1000 : Date.parse(header || '') - Date.now();
      if (!Number.isFinite(ms)) ms = (Number(data?.data?.retry_after) || 300) * 1000;
      return Math.max(1000, Math.min(3600000, ms));
    };
    async function sendGroup(events, hint) {
      if (stopped || hint.signal?.aborted) throw new DOMException('Tracking stopped', 'AbortError');
      if (cooldown > Date.now()) return waitResult();
      requests++;
      const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', mode: 'same-origin', redirect: 'error', cache: 'no-store', referrerPolicy: 'no-referrer', headers: {'Content-Type': 'application/json'}, body: payload(events), keepalive: hint.unload === true, signal: hint.signal});
      lastStatus = response.status;
      // Includes disabled, staff and pc_track_declined (GPC / opt-out).
      // stop() clears the entire observer queue and timers; never retry a denial.
      if (response.status === 403) { stop(); return empty(); }
      if (response.status === 413) {
        if (events.length === 1) return {...empty(), discardEventIds: [events[0].event_id]};
        const middle = Math.ceil(events.length / 2);
        const first = await sendGroup(events.slice(0, middle), hint);
        if (first.retryAfterMs) return first;
        try { return merge(first, await sendGroup(events.slice(middle), hint)); }
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
      return {...empty(), ackEventIds: [...new Set(acknowledged.filter(value => typeof value === 'string' && sentIds.has(value)))]};
    }
    async function sendBatch(events, hint = {}) {
      if (cooldown > Date.now()) return waitResult();
      const groups = []; let group = [], result = empty();
      for (const event of events) {
        if (byteLength(payload([event])) > 60000) { result.discardEventIds.push(event.event_id); continue; }
        if (group.length >= batchMax || byteLength(payload([...group, event])) > 60000) { groups.push(group); group = []; }
        group.push(event);
      }
      if (group.length) groups.push(group);
      for (const batch of groups) {
        try { result = merge(result, await sendGroup(batch, hint)); }
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
      observer = window.VialCoveVisitorObserver.start({context, heartbeatMs: config.heartbeat_ms, idleMs: config.idle_ms, batchMax, sendBatch});
      if (!observer) stop();
    }
    function stop() {
      if (stopped) return;
      stopped = true; clearTimeout(bootTimer); closeChannel(); observer?.stop();
      window.removeEventListener('pagehide', closeChannel);
      window.removeEventListener('pageshow', restoreChannel);
      if (active === api) active = null;
    }
    const api = {stop, getStats: () => ({...(observer?.getStats() || {queued: 0}), stopped, starting: !ready && !stopped, requests, lastStatus, retryAfterMs: Math.max(0, cooldown - Date.now())})};
    active = api;
    last = api;
    window.addEventListener('pagehide', closeChannel);
    window.addEventListener('pageshow', restoreChannel);
    openChannel();
    if (channel) bootTimer = setTimeout(boot, 80); else boot();
    return api;
  }
  window.VialCoveVisitorTransport = {start, stop: () => active?.stop(), getStats: () => (active || last)?.getStats() || {stopped: true, starting: false, queued: 0, requests: 0}};
  start();
})();
