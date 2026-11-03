/* Passive native showcase opening observations. No transport, storage or business operations. */
(() => {
  'use strict';
  const inert = () => Object.freeze({stop() {}, suspend() {}, resume() {}});
  const parentId = value => typeof value === 'string' && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null;
  const nativeOpener = target => {
    const opener = target?.closest?.('[data-vc-quick-open]');
    return opener && (opener.tagName === 'A' || opener.tagName === 'BUTTON') ? opener : null;
  };
  const sourceRoot = opener => opener?.closest('.vc-showcase') || opener;
  const privacyAllows = () => { try { return window.navigator?.globalPrivacyControl !== true; } catch { return false; } };
  function observe(doc, callback) {
    if (doc !== window.document || typeof callback !== 'function' || typeof window.requestAnimationFrame !== 'function' || typeof window.cancelAnimationFrame !== 'function' || typeof window.getComputedStyle !== 'function' || typeof window.MutationObserver !== 'function' || typeof window.setTimeout !== 'function' || typeof window.clearTimeout !== 'function' || !window.crypto?.getRandomValues) return inert();
    let stopped = false, suspended = false, pendingOpener = null, pendingTimer = null, pendingPresentation = null, presentationTimer = null, episode = null, frame = null;
    const hidden = () => doc.visibilityState !== 'visible' || doc.hidden === true || doc.prerendering === true;
    const connected = node => !!node && node.isConnected === true && node.ownerDocument === doc;
    const watcher = new window.MutationObserver(records => {
      if (!episode || episode.sent) return;
      const targets = [episode.root, episode.opener, episode.dialog, episode.body, episode.product];
      if (targets.some(node => !connected(node)) || !episode.dialog.open || records.some(record => Array.from(record.removedNodes || []).some(node => targets.some(target => node === target || node.contains?.(target))))) cancel();
    });
    function clearPending() { if (pendingTimer !== null) window.clearTimeout(pendingTimer); pendingTimer = null; pendingOpener = null; }
    function clearPresentation() { if (presentationTimer !== null) window.clearTimeout(presentationTimer); presentationTimer = null; pendingPresentation = null; }
    function cancel() { if (frame !== null) window.cancelAnimationFrame(frame); frame = null; watcher.disconnect(); episode = null; }
    function nativeDialog(opener, id) {
      const matches = Array.from(doc.querySelectorAll('dialog.vc-showcase-quick')).map(dialog => {
        if (!connected(dialog) || dialog.getAttribute('aria-modal') !== 'true') return null;
        const body = dialog.querySelector('.vc-showcase-quick__body');
        const product = body?.querySelector('.vc-showcase-quick-product[data-vc-product-id]');
        if (!connected(body) || body.parentElement !== dialog || !connected(product) || product.parentElement !== body || parentId(product.getAttribute('data-vc-product-id')) !== id || parentId(opener.getAttribute('data-vc-quick-open')) !== id) return null;
        return {dialog, body, product};
      }).filter(Boolean);
      return matches.length === 1 ? matches[0] : null;
    }
    function visibleRect(node) {
      if (!connected(node) || !node.getClientRects().length) return null;
      for (let ancestor = node; ancestor && ancestor !== doc; ancestor = ancestor.parentElement) {
        const style = window.getComputedStyle(ancestor);
        if (ancestor.hidden || ancestor.getAttribute?.('aria-hidden') === 'true' || style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' || Number(style.opacity) === 0) return null;
      }
      const rect = node.getBoundingClientRect();
      return rect && ['left', 'top', 'right', 'bottom', 'width', 'height'].every(key => Number.isFinite(rect[key])) && rect.width > 0 && rect.height > 0 ? rect : null;
    }
    function uid() {
      const value = window.crypto.randomUUID?.();
      return value && /^[A-Za-z0-9-]{8,56}$/.test(value) ? 'intent-' + value : 'intent-' + Array.from(window.crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    }
    function check() {
      frame = null;
      const current = episode;
      if (!current || current.sent || stopped || suspended || !privacyAllows()) { cancel(); return; }
      if (![current.root, current.opener, current.dialog, current.body, current.product].every(connected) || !current.dialog.open || nativeOpener(current.opener) !== current.opener || sourceRoot(current.opener) !== current.root || parentId(current.product.getAttribute('data-vc-product-id')) !== current.id || parentId(current.opener.getAttribute('data-vc-quick-open')) !== current.id) { cancel(); return; }
      if (hidden()) { if (current.initiallyHidden && !current.foregroundUsed) current.waitingForVisibility = true; else cancel(); return; }
      const rectangles = [current.dialog, current.body, current.product].map(visibleRect);
      if (rectangles.some(rect => !rect)) { cancel(); return; }
      const width = Number(window.innerWidth || doc.documentElement?.clientWidth), height = Number(window.innerHeight || doc.documentElement?.clientHeight);
      const left = Math.max(0, ...rectangles.map(rect => rect.left)), right = Math.min(width, ...rectangles.map(rect => rect.right));
      const top = Math.max(0, ...rectangles.map(rect => rect.top)), bottom = Math.min(height, ...rectangles.map(rect => rect.bottom));
      if (!(width > 0 && height > 0 && right > left && bottom > top)) { cancel(); return; }
      current.sent = true; watcher.disconnect();
      try { callback(Object.freeze({version: 1, product_id: current.id, variation_id: 0, kind: 'quick_view_open', result: 'completed', event_id: uid()})); } catch { /* Observation failures never affect the native dialog. */ }
    }
    function schedule() { if (frame === null) frame = window.requestAnimationFrame(check); }
    function openerClick(event) {
      if (stopped || suspended || !privacyAllows() || event.isTrusted !== true || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const opener = nativeOpener(event.target);
      const root = sourceRoot(opener);
      const id = parentId(opener?.getAttribute('data-vc-quick-open'));
      if (!id || !connected(opener) || !connected(root) || opener.disabled) return;
      clearPending(); clearPresentation();
      const pending = {opener, root, id, click: event}; pendingOpener = pending;
      // Native input can checkpoint microtasks between capture and bubble listeners.
      // The event's active dispatch phase, not a millisecond window, proves this turn.
      pendingTimer = window.setTimeout(() => { if (pendingOpener === pending) clearPending(); }, 0);
      // The live product controller may fetch its native template before replaying
      // .click(). Keep the original trusted source, never trust that replay itself.
      if (parentId(opener.getAttribute('data-vc-home-product')) === id) {
        pendingPresentation = pending;
        presentationTimer = window.setTimeout(() => { if (pendingPresentation === pending) clearPresentation(); }, 14000);
      }
    }
    function quickState(event) {
      const root = event.target;
      if (!connected(root) || !(root.matches?.('.vc-showcase') || sourceRoot(nativeOpener(root)) === root)) return;
      if (event.detail?.open === false) { if (episode?.root === root) cancel(); if (pendingPresentation?.root === root) clearPresentation(); return; }
      if (event.detail?.open !== true || stopped || suspended || !privacyAllows()) return;
      if (episode?.root === root && !pendingOpener) return;
      const pending = pendingOpener;
      if (!pending || pending.root !== root) return;
      clearPending();
      if (pending.click.isTrusted !== true || ![1, 2, 3].includes(pending.click.eventPhase) || nativeOpener(pending.click.target) !== pending.opener || sourceRoot(pending.opener) !== root) return;
      cancel();
      const native = nativeDialog(pending.opener, pending.id);
      if (!native) return;
      clearPresentation();
      episode = {root: pending.root, opener: pending.opener, id: pending.id, ...native, initiallyHidden: hidden(), foregroundUsed: false, waitingForVisibility: false, sent: false};
      watcher.observe(doc.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'hidden']});
      schedule();
    }
    function presented(event) {
      const pending = pendingPresentation;
      if (!pending || stopped || suspended || !privacyAllows()) return;
      const product = event.detail?.root, dialog = event.detail?.dialog;
      if (!connected(pending.opener) || !connected(pending.root) || sourceRoot(pending.opener) !== pending.root ||
          parentId(pending.opener.getAttribute('data-vc-home-product')) !== pending.id ||
          product?.vcHomeOpener !== pending.opener || Number(event.detail?.productId) !== pending.id) return;
      const native = nativeDialog(pending.opener, pending.id);
      if (!native || native.product !== product || native.dialog !== dialog || !dialog.open) return;
      clearPending(); clearPresentation(); cancel();
      episode = {root: pending.root, opener: pending.opener, id: pending.id, ...native, initiallyHidden: hidden(), foregroundUsed: false, waitingForVisibility: false, sent: false};
      watcher.observe(doc.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'hidden']});
      schedule();
    }
    function visibility() {
      if (!episode || episode.sent) return;
      if (hidden()) { if (!episode.initiallyHidden) cancel(); return; }
      if (episode.waitingForVisibility && !episode.foregroundUsed) { episode.foregroundUsed = true; episode.waitingForVisibility = false; schedule(); }
    }
    function cancelled(event) {
      if (event.type === 'keydown' && event.key !== 'Escape') return;
      clearPending(); clearPresentation();
      if (!episode?.sent) cancel();
    }
    function suspend() { suspended = true; clearPending(); clearPresentation(); cancel(); }
    function resume() { if (!stopped) suspended = false; }
    function stop() { if (stopped) return; stopped = true; suspend(); window.removeEventListener('click', openerClick, true); doc.removeEventListener('vc:showcase-quick-state', quickState, true); doc.removeEventListener('vc:product-presented', presented, true); doc.removeEventListener('vc:product-restore-cancel', cancelled, true); doc.removeEventListener('keydown', cancelled, true); doc.removeEventListener('visibilitychange', visibility); window.removeEventListener('pagehide', suspend); }
    // The native document capture controller stops the first lazy-load click.
    // Observe its trusted source at window capture, before that propagation stop.
    window.addEventListener('click', openerClick, true);
    doc.addEventListener('vc:showcase-quick-state', quickState, true);
    doc.addEventListener('vc:product-presented', presented, true);
    doc.addEventListener('vc:product-restore-cancel', cancelled, true);
    doc.addEventListener('keydown', cancelled, true);
    doc.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', suspend);
    return Object.freeze({stop, suspend, resume});
  }
  window.VialCoveVisitorIntent = Object.freeze({observe});
})();
