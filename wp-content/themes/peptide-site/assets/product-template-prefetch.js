/* Bounded, inert native-template reads; presentation and all writes stay native. */
(function (global) {
  'use strict';
  const document = global.document, selector = '[data-vc-home-product][data-vc-quick-open]';
  const connection = global.navigator?.connection;
  if (!global.jQuery || !global.HTMLDialogElement?.prototype.showModal || !global.fetch || !global.AbortController || !global.vcHomeQuickView?.productApi || !global.VialCoveHomeQuickView?.loadTemplate || connection?.saveData || /^(?:slow-)?2g$/.test(connection?.effectiveType || '') || document.hidden) return;
  const attempted = new Set();
  const unused = new Map(), claimed = new Set();
  const template = id => document.querySelector('template[data-vc-quick-product="' + id + '"]');
  let stopped = false, busy = false, queued = null, debounce = 0, frame = 0, idle = null, fallback = 0, intentLink = null, pendingClick = '';
  function eligible(link) {
    if (!link || !link.isConnected || link.disabled || link.getAttribute('aria-disabled') === 'true' || link.closest('[inert]') || !link.getClientRects().length) return '';
    const id = link.dataset.vcHomeProduct;
    if (!/^[1-9]\d{0,9}$/.test(id || '') || Number(id) > 2147483647 || link.dataset.vcQuickOpen !== id) return '';
    try { const url = new URL(link.href, global.location.href); if (!/^https?:$/.test(url.protocol) || url.origin !== global.location.origin || url.username || url.password) return ''; } catch (_) { return ''; }
    return id;
  }
  function offer(link) {
    if (stopped || document.hidden || attempted.size >= 2) return;
    const id = eligible(link); if (!id || attempted.has(id) || template(id)) return;
    if (busy) { queued = link; return; }
    attempted.add(id); busy = true;
    // No signal is passed: retiring speculation must never abort a shared read
    // that a genuine click is now using. The loader owns validation and timeout.
    let read;
    try { read = global.VialCoveHomeQuickView.loadTemplate(id); } catch (error) { read = Promise.reject(error); }
    Promise.resolve(read).then(() => {
      if (claimed.has(id)) return;
      const element = template(id); if (!element) return;
      const item = {element, expires:Date.now()+30000, timer:0}; unused.set(id,item);
      if (stopped && pendingClick !== id) discard(id); else item.timer = global.setTimeout(() => discard(id),30000);
    }).catch(() => {}).finally(() => {
      busy = false;
      if (stopped) { if (!unused.size) releaseClaims(); return; }
      const next = queued; queued = null; if (next) offer(next);
    });
  }
  function discard(id) {
    const item = unused.get(id); if (!item) return;
    global.clearTimeout(item.timer); unused.delete(id);
    // Never remove another consumer's replacement or a cloned active view.
    if (!claimed.has(id) && template(id) === item.element) item.element.remove();
    if (stopped && !busy && !unused.size) releaseClaims();
  }
  function claim(id, presented = false) {
    if (!attempted.has(id)) return;
    const item = unused.get(id);
    // Timers may be throttled in background tabs. Freshness is authoritative
    // at the window capture boundary, before the native document click handler.
    if (!presented && item && item.expires <= Date.now()) discard(id);
    if (presented) {
      claimed.add(id);
      if (unused.has(id)) { global.clearTimeout(unused.get(id).timer); unused.delete(id); }
      if (pendingClick === id) pendingClick = '';
      if (stopped && !busy && !unused.size) releaseClaims();
    }
  }
  function click(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const id = eligible(event.target.closest?.(selector));
    if (id) { pendingClick = id; claim(id); }
  }
  function presented(event) { claim(String(event.detail?.productId || ''),true); }
  function releaseClaims() {
    global.removeEventListener('click',click,true); document.removeEventListener('vc:product-presented',presented);
  }
  function intent(event) {
    const link = event.target.closest?.(selector);
    if (!eligible(link)) return;
    if (link === intentLink && debounce) return;
    global.clearTimeout(debounce); intentLink = link;
    debounce = global.setTimeout(() => { debounce = 0; intentLink = null; offer(link); }, 180);
  }
  function leave(event) {
    const link = event.target.closest?.(selector);
    if (link !== intentLink || link?.contains(event.relatedTarget)) return;
    global.clearTimeout(debounce); debounce = 0; intentLink = null;
  }
  function stop() {
    if (stopped) return; stopped = true; queued = intentLink = null;
    global.clearTimeout(debounce); global.clearTimeout(fallback); global.cancelAnimationFrame(frame);
    if (idle !== null) global.cancelIdleCallback?.(idle);
    document.removeEventListener('pointerover', intent); document.removeEventListener('focusin', intent);
    document.removeEventListener('pointerout', leave); document.removeEventListener('focusout', leave);
    document.removeEventListener('visibilitychange', visibility); global.removeEventListener('pagehide', stop);
    for (const id of [...unused.keys()]) if (id !== pendingClick) discard(id);
    // Keep only the claim boundary until the shared read settles: a click on a
    // resumed page may now own that same promise, even after speculation exits.
    if (!busy && !unused.size) releaseClaims();
  }
  function visibility() { if (document.hidden) stop(); }
  document.addEventListener('pointerover', intent); document.addEventListener('focusin', intent);
  document.addEventListener('pointerout', leave); document.addEventListener('focusout', leave);
  document.addEventListener('visibilitychange', visibility); global.addEventListener('pagehide', stop);
  global.addEventListener('click',click,true); document.addEventListener('vc:product-presented',presented);
  // Two frames yield the initial paint before idle work. Only the real hero
  // link is considered; absence never becomes an all-catalogue prefetch sweep.
  frame = global.requestAnimationFrame(() => {
    if (stopped) return;
    frame = global.requestAnimationFrame(() => {
      if (stopped) return;
      const hero = () => { if (!busy && !queued) offer(document.querySelector('.vc-demo-hero ' + selector)); };
      if (global.requestIdleCallback) idle = global.requestIdleCallback(hero, {timeout:1200});
      else fallback = global.setTimeout(hero, 1000);
    });
  });
})(window);
