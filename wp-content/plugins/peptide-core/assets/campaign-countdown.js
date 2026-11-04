(function (global) {
 'use strict';
 const instances = new WeakMap();
 const copy = {
  en: { starts: 'Starts in', ends: 'Ends in', days: 'Days', hours: 'Hrs', minutes: 'Min', seconds: 'Sec' },
  es: { starts: 'Comienza en', ends: 'Termina en', days: 'Días', hours: 'Hrs', minutes: 'Min', seconds: 'Seg' }
 };
 const mono = () => global.performance.now();
 function language() { return /^es(?:-|$)/i.test(global.PCStorefrontI18n?.language || global.document?.documentElement.lang || '') ? 'es' : 'en'; }
 function valid(data) {
  return data && data.version === 1 && ['checkout', 'product'].includes(data.position) && typeof data.visible === 'boolean' && Number.isSafeInteger(data.serverNow) && data.serverNow > 0 && (data.target === null || Number.isSafeInteger(data.target)) && (data.ends === null || Number.isSafeInteger(data.ends)) && (data.starts === null || Number.isSafeInteger(data.starts));
 }
 function parts(seconds) {
  const total = Math.max(0, Math.ceil(seconds));
  return [Math.floor(total / 86400), Math.floor(total % 86400 / 3600), Math.floor(total % 3600 / 60), total % 60];
 }
 function phase(data, now) {
  if (!valid(data) || !data.visible || !data.enabled || !['active', 'upcoming'].includes(data.state) || !Number.isSafeInteger(data.ends) || data.ends <= now || (data.starts && data.ends <= data.starts)) return null;
  return data.starts && data.starts > now ? { state: 'upcoming', target: data.starts } : { state: 'active', target: data.ends };
 }
 function mount(host, initial, options = {}) {
  if (!(host instanceof global.HTMLElement)) return null;
  if (instances.has(host)) instances.get(host).destroy();
  let data = valid(initial) ? initial : null;
  let anchor = mono(), server = data?.serverNow || 0, timer = null, disposed = false, requesting = false, aborter = null, lastFetch = 0, nodes = [], lastLanguage = '', lastState = '';
  const endpoint = options.endpoint || host.dataset.countdownEndpoint || '';
  function build(lang, state) {
   host.replaceChildren(); nodes = [];
   const content = global.document.createElement('div'); content.className = 'pc-campaign-countdown__content';
   const text = global.document.createElement('div'); text.className = 'pc-campaign-countdown__copy';
   const title = global.document.createElement('strong'); title.className = 'pc-campaign-countdown__title'; title.textContent = typeof data.title === 'string' ? data.title : '';
   const label = global.document.createElement('span'); label.className = 'pc-campaign-countdown__label'; label.textContent = copy[lang][state === 'upcoming' ? 'starts' : 'ends'];
   text.append(title, label); content.append(text);
   const clock = global.document.createElement('div'); clock.className = 'pc-campaign-countdown__clock'; clock.setAttribute('role', 'timer'); clock.setAttribute('aria-live', 'off'); clock.setAttribute('aria-label', `${title.textContent}. ${label.textContent}`);
   ['days', 'hours', 'minutes', 'seconds'].forEach((key, index) => {
    if (index) { const colon = global.document.createElement('span'); colon.className = 'pc-campaign-countdown__separator'; colon.textContent = ':'; colon.setAttribute('aria-hidden', 'true'); clock.append(colon); }
    const unit = global.document.createElement('span'); unit.className = 'pc-campaign-countdown__unit';
    const number = global.document.createElement('span'); number.className = 'pc-campaign-countdown__number';
    const caption = global.document.createElement('span'); caption.className = 'pc-campaign-countdown__unit-label'; caption.textContent = copy[lang][key];
    unit.append(number, caption); clock.append(unit); nodes.push(number);
   });
   content.append(clock); host.append(content); lastLanguage = lang; lastState = state;
  }
  function draw() {
   if (disposed) return;
   const now = server + (mono() - anchor) / 1000;
   const current = data && phase(data, now);
   host.hidden = !current;
   if (!current) return;
   const lang = language();
   if (!nodes.length || lang !== lastLanguage || current.state !== lastState) build(lang, current.state);
   host.dataset.campaignState = current.state;
   parts(current.target - now).forEach((value, index) => { const formatted = String(value).padStart(2, '0'); if (nodes[index].textContent !== formatted) nodes[index].textContent = formatted; });
  }
  function update(next, requestStarted) {
   if (!valid(next) || next.position !== host.dataset.pcCampaignCountdown) { data = null; host.hidden = true; return false; }
   data = next; anchor = mono(); server = data.serverNow + (Number.isFinite(requestStarted) ? Math.max(0, anchor - requestStarted) / 2000 : 0); nodes = []; draw(); return true;
  }
  async function refresh() {
   if (disposed || requesting || !endpoint || !host.isConnected || global.document.hidden) return;
   let url;
   try { url = new URL(endpoint, global.location.href); if (url.origin !== global.location.origin || !(url.pathname.endsWith('/peptide/v1/campaign-countdown') || url.searchParams.get('rest_route') === '/peptide/v1/campaign-countdown')) return; } catch (_) { return; }
   requesting = true; aborter = new AbortController(); const started = mono(); lastFetch = started;
   const timeout = global.setTimeout(() => aborter?.abort(), 8000);
   try {
    const response = await global.fetch(url.href, { credentials: 'same-origin', cache: 'no-store', signal: aborter.signal, headers: { Accept: 'application/json' } });
    if (response.ok && /application\/json/i.test(response.headers.get('content-type') || '')) { const body = await response.json(); if (!disposed) update(body[host.dataset.pcCampaignCountdown], started); }
   } catch (_) { /* Keep the absolute known deadline; a failed request never restarts it. */ }
   finally { global.clearTimeout(timeout); requesting = false; aborter = null; }
  }
  function tick() { if (disposed) return; if (!host.isConnected) { destroy(); return; } draw(); if (mono() - lastFetch >= 60000) refresh(); }
  function resume() { draw(); refresh(); }
  function destroy() { disposed = true; global.clearInterval(timer); aborter?.abort(); global.document.removeEventListener('visibilitychange', resume); global.removeEventListener('pageshow', resume); instances.delete(host); }
  const api = { update, refresh, destroy };
  instances.set(host, api); update(data); lastFetch = mono();
  timer = global.setInterval(tick, 1000); global.document.addEventListener('visibilitychange', resume); global.addEventListener('pageshow', resume);
  refresh();
  return api;
 }
 function init(scope = global.document) {
  scope.querySelectorAll('[data-pc-campaign-countdown]').forEach(host => { if (instances.has(host)) return; let data; try { data = JSON.parse(host.dataset.countdownConfig || 'null'); } catch (_) { data = null; } mount(host, data); });
 }
 global.PCCampaignCountdown = { mount, init, parts, phase };
 if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', () => init(), { once: true }); else init();
 global.document.addEventListener('pc:campaign-countdown-mount', event => init(event.detail?.root || global.document));
})(window);
