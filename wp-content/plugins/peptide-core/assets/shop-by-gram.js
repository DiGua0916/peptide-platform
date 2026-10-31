/* O24 gallery lifecycle. No library, background request, cart action or generated product data. */
(() => {
  'use strict';
  const instances = new Map();
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  let active = null;
  let suspended = false;
  const focusables = node => [...node.querySelectorAll('a[href],button:not([disabled]),video[controls],iframe[src],input,[tabindex]')].filter(el => el.tabIndex >= 0 && !el.hidden && !el.closest('[hidden]') && el.getClientRects().length);
  const pause = media => {
    media.querySelectorAll('video').forEach(video => video.pause());
    media.querySelectorAll('iframe[data-pc-gram-src]').forEach(frame => { if (frame.hasAttribute('src')) frame.removeAttribute('src'); });
  };
  const play = (media, force = false) => {
    if (document.hidden || (!force && reduced.matches)) return;
    media.querySelectorAll('video').forEach(video => { video.muted = true; const attempt = video.play(); if (attempt && attempt.catch) attempt.catch(() => {}); });
    media.querySelectorAll('iframe[data-pc-gram-src]').forEach(frame => { if (!frame.hasAttribute('src')) frame.setAttribute('src', frame.dataset.pcGramSrc); });
  };
  function mount(root) {
    if (instances.has(root)) {
      const previous = instances.get(root);
      if (previous.sameCards()) return;
      previous.cleanup();
    }
    const overlay = root.querySelector('[data-pc-gram-overlay]');
    const body = overlay && overlay.querySelector('[data-pc-gram-body]');
    const dialog = overlay && overlay.querySelector('[role="dialog"]');
    if (!overlay || !body || !dialog) return;
    const listeners = [];
    const cards = [...root.querySelectorAll('.pc-gram__card')];
    if (!cards.length) return;
    const visible = new Set();
    let opener = null;
    let openedCard = null;
    let oldOverflow = null;
    let inertSiblings = [];
    let disposed = false;
    const on = (node, event, handler) => { node.addEventListener(event, handler); listeners.push(() => node.removeEventListener(event, handler)); };
    const updateTiles = () => cards.forEach(card => {
      const tile = card.querySelector('.pc-gram__tile');
      if (!active && !suspended && !document.hidden && !reduced.matches && visible.has(card) && card.dataset.autoplay === '1') play(tile); else pause(tile);
    });
    const select = (index, moveFocus) => {
      body.querySelectorAll('[data-pc-gram-spot]').forEach(spot => spot.setAttribute('aria-pressed', spot.dataset.pcGramSpot === index ? 'true' : 'false'));
      body.querySelectorAll('[data-pc-gram-product]').forEach(product => {
        const selected = product.dataset.pcGramProduct === index;
        product.classList.toggle('is-selected', selected);
        if (selected && moveFocus) {
          product.scrollIntoView({block:'nearest',inline:'nearest',behavior:reduced.matches ? 'auto' : 'smooth'});
          const link = product.querySelector('a'); if (link) link.focus({preventScroll:true});
        }
      });
    };
    const close = (restoreFocus = true) => {
      if (overlay.hidden) return;
      pause(body); overlay.hidden = true; body.replaceChildren();
      inertSiblings.forEach(([node, previous]) => { node.inert = previous; }); inertSiblings = [];
      if (oldOverflow !== null) document.body.style.overflow = oldOverflow;
      oldOverflow = null; openedCard = null;
      if (active === api) active = null;
      if (restoreFocus && opener && opener.isConnected) opener.focus({preventScroll:true});
      opener = null;
      instances.forEach(instance => instance.updateTiles());
    };
    const open = button => {
      const card = button.closest('.pc-gram__card');
      const template = card && card.querySelector('template[data-pc-gram-content]');
      if (!template) return;
      if (active) active.close(false);
      opener = button; openedCard = card; active = api;
      body.replaceChildren(template.content.cloneNode(true));
      dialog.setAttribute('aria-label', button.getAttribute('aria-label') || 'Shop this media');
      body.querySelectorAll('[data-pc-gram-product]').forEach(product => { product.id = overlay.id + '-product-' + product.dataset.pcGramProduct; });
      body.querySelectorAll('[data-pc-gram-spot]').forEach(spot => { spot.setAttribute('aria-controls', overlay.id + '-product-' + spot.dataset.pcGramSpot); });
      body.querySelectorAll('iframe').forEach(frame => { frame.tabIndex = 0; });
      oldOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
      [...document.body.children].forEach(node => { if (node !== overlay) { inertSiblings.push([node, node.inert]); node.inert = true; } });
      overlay.hidden = false;
      instances.forEach(instance => instance.updateTiles());
      select('0', false);
      overlay.querySelector('[data-pc-gram-close]').focus({preventScroll:true});
      if (!suspended && card.dataset.autoplay === '1' && !reduced.matches) play(body);
    };
    const keydown = event => {
      if (active !== api || overlay.hidden) return;
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key === 'Tab') {
        const list = focusables(dialog), first = list[0], last = list[list.length - 1];
        if (!first) { event.preventDefault(); dialog.focus(); }
        else if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
      }
    };
    const motionChange = () => { updateTiles(); if (!overlay.hidden) { pause(body); if (openedCard && !reduced.matches && openedCard.dataset.autoplay === '1') play(body); } };
    const cleanup = () => {
      if (disposed) return; disposed = true;
      close(false); cards.forEach(card => pause(card));
      observer && observer.disconnect(); listeners.splice(0).forEach(remove => remove());
      if (reduced.removeEventListener) reduced.removeEventListener('change', motionChange); else reduced.removeListener(motionChange);
      const replacement = root.querySelector('[data-pc-gram-overlay]');
      if (replacement && replacement !== overlay) overlay.remove(); else root.appendChild(overlay);
      instances.delete(root);
    };
    const sameCards = () => {
      const current = [...root.querySelectorAll('.pc-gram__card')];
      return current.length === cards.length && current.every((card, i) => card === cards[i]);
    };
    const api = {close, cleanup, updateTiles, sameCards};
    instances.set(root, api);
    // Portal escapes block-level overflow/transform; carry only this module's sanitized CSS variables.
    overlay.setAttribute('style', root.getAttribute('style') || '');
    document.body.appendChild(overlay);
    on(root, 'click', event => { const button = event.target.closest('[data-pc-gram-open]'); if (button && root.contains(button)) open(button); });
    on(overlay, 'click', event => {
      if (event.target === overlay || event.target.closest('[data-pc-gram-close]')) close();
      const spot = event.target.closest('[data-pc-gram-spot]'); if (spot) select(spot.dataset.pcGramSpot, true);
      const trigger = event.target.closest('[data-pc-gram-play]'); if (trigger) play(trigger.closest('.pc-gram__youtube'), true);
    });
    on(body, 'focusin', event => { const product = event.target.closest('[data-pc-gram-product]'); if (product) select(product.dataset.pcGramProduct, false); });
    on(document, 'keydown', keydown);
    on(document, 'focusin', event => { if (active === api && !dialog.contains(event.target)) overlay.querySelector('[data-pc-gram-close]').focus(); });
    on(document, 'visibilitychange', () => { updateTiles(); if (document.hidden) pause(body); });
    if (reduced.addEventListener) reduced.addEventListener('change', motionChange); else reduced.addListener(motionChange);
    const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
      entries.forEach(entry => { if (entry.isIntersecting) visible.add(entry.target); else visible.delete(entry.target); }); updateTiles();
    }, {threshold:0.15}) : null;
    if (observer) cards.forEach(card => observer.observe(card));
    // Without IntersectionObserver autoplay stays off; native controls and manual modal play remain available.
    updateTiles();
  }
  const init = () => { if (!suspended) document.querySelectorAll('[data-pc-gram]').forEach(mount); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
  new MutationObserver(records => {
    if (!records.some(record => record.removedNodes.length || record.addedNodes.length)) return;
    [...instances].forEach(([root, instance]) => {
      if (!root.isConnected) instance.cleanup();
      else if (!instance.sameCards()) { instance.cleanup(); mount(root); }
    });
    if (records.some(record => record.addedNodes.length)) init();
  }).observe(document.documentElement, {childList:true,subtree:true});
  window.addEventListener('pagehide', () => { suspended = true; [...instances.values()].forEach(instance => instance.cleanup()); });
  window.addEventListener('pageshow', () => { suspended = false; init(); });
})();
