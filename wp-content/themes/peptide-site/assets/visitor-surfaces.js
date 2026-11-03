/* Passive current-content context for real native commerce dialogs.
 * No transport, identity, storage, navigation or invented page views. */
(() => {
  'use strict';
  const selector = 'dialog.vc-showcase-quick,dialog#vc-live-cart-drawer,dialog#vc-cart-drawer,dialog.vc-product-child-dialog';
  const empty = () => Object.freeze({snapshot: () => null, stop() {}, suspend() {}, resume() {}});
  const id = value => typeof value === 'string' && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null;
  const privacyAllows = () => { try { return window.navigator?.globalPrivacyControl !== true; } catch { return false; } };
  function observe(doc, changed) {
    if (doc !== window.document || typeof changed !== 'function' || !privacyAllows() || typeof doc.querySelectorAll !== 'function' || typeof window.MutationObserver !== 'function' || typeof window.requestAnimationFrame !== 'function' || typeof window.cancelAnimationFrame !== 'function' || typeof window.getComputedStyle !== 'function') return empty();
    let stopped = false, suspended = false, frame = null, serial = 0, settleUntil = 0, previous = '', waitingVisual = false;
    const opened = new WeakMap(), verified = new Map(), listeners = [];
    const connected = node => !!node && node.ownerDocument === doc && node.isConnected === true;
    const visible = node => {
      if (!connected(node) || !node.getClientRects().length) return false;
      for (let p = node; p && p !== doc; p = p.parentElement) {
        const style = window.getComputedStyle(p);
        if (p.hidden || p.getAttribute?.('aria-hidden') === 'true' || style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' || Number(style.opacity) === 0) return false;
      }
      const r = node.getBoundingClientRect(), width = Number(window.innerWidth || doc.documentElement?.clientWidth), height = Number(window.innerHeight || doc.documentElement?.clientHeight);
      return r && [r.left, r.top, r.right, r.bottom, r.width, r.height, width, height].every(Number.isFinite) && r.width > 0 && r.height > 0 && Math.min(width, r.right) > Math.max(0, r.left) && Math.min(height, r.bottom) > Math.max(0, r.top);
    };
    function product(dialog) {
      if (!connected(dialog) || !dialog.open || dialog.getAttribute('aria-modal') !== 'true') return null;
      const body = dialog.querySelector('.vc-showcase-quick__body');
      const root = body?.querySelector('.vc-showcase-quick-product[data-vc-product-id]');
      const opener = root?.vcHomeOpener, productId = id(root?.getAttribute('data-vc-product-id'));
      if (!productId || !connected(body) || body.parentElement !== dialog || !connected(root) || root.parentElement !== body || !connected(opener) || !['A', 'BUTTON'].includes(opener.tagName) || id(opener.getAttribute('data-vc-quick-open')) !== productId) return null;
      const heading = root.querySelector('.product_title, .vc-showcase-quick__title');
      const name = typeof heading?.textContent === 'string' ? heading.textContent.replace(/\s+/g, ' ').trim().slice(0, 120) : '';
      if (!name) return null;
      return {dialog, root, body, opener, value: Object.freeze({page_type: 'product', product_id: productId, product_name: name})};
    }
    function read() {
      waitingVisual = false;
      if (stopped || suspended || !privacyAllows()) return null;
      const dialogs = Array.from(doc.querySelectorAll(selector)).filter(d => connected(d) && d.open);
      for (const d of dialogs) if (!opened.has(d)) opened.set(d, ++serial);
      const products = dialogs.filter(d => d.matches('dialog.vc-showcase-quick')).map(product).filter(Boolean);
      // A hidden document retains only previously verified, still-open surfaces.
      // New content is not declared visible merely because a hidden tab mutated.
      if (doc.hidden === true || doc.visibilityState === 'hidden') {
        for (const known of [...verified.values()].sort((a, b) => opened.get(b.dialog) - opened.get(a.dialog))) {
          if (!connected(known.dialog) || !known.dialog.open) { verified.delete(known.dialog); continue; }
          if (known.value.page_type === 'product') {
            const p = products.find(p => p.dialog === known.productDialog);
            if (!p || p.root !== known.productRoot || p.value.product_id !== known.value.product_id) continue;
          }
          return known;
        }
        return null;
      }
      for (const dialog of dialogs.sort((a, b) => opened.get(b) - opened.get(a))) {
        if (!visible(dialog)) {
          if (dialog.id === 'vc-live-cart-drawer' || dialog.id === 'vc-cart-drawer' || products.some(p => p.dialog === dialog) || (dialog.matches('dialog.vc-product-child-dialog') && products.some(p => p.value.product_id === id(dialog.getAttribute('data-vc-product-parent'))))) waitingVisual = true;
          continue;
        }
        if (dialog.id === 'vc-live-cart-drawer' || dialog.id === 'vc-cart-drawer') return {dialog, value: Object.freeze({page_type: 'cart'})};
        if (dialog.matches('dialog.vc-showcase-quick')) {
          const p = products.find(p => p.dialog === dialog);
          if (p && visible(p.body) && visible(p.root)) return {dialog, productDialog: dialog, productRoot: p.root, value: p.value};
          if (p) waitingVisual = true;
        } else if (dialog.matches('dialog.vc-product-child-dialog')) {
          const parentId = id(dialog.getAttribute('data-vc-product-parent'));
          const p = products.find(p => p.value.product_id === parentId);
          if (p && visible(p.body) && visible(p.root)) return {dialog, productDialog: p.dialog, productRoot: p.root, value: p.value};
        }
      }
      return null;
    }
    const signature = value => value ? JSON.stringify(value) : '';
    function snapshot() {
      try {
        const next = read();
        if (next && doc.hidden !== true && doc.visibilityState !== 'hidden') verified.set(next.dialog, next);
        return next?.value || null;
      } catch { return null; }
    }
    function check() {
      frame = null;
      if (stopped || suspended) return;
      if (!privacyAllows()) { verified.clear(); previous = ''; return; }
      const next = snapshot(), key = signature(next);
      if (key !== previous) {
        previous = key;
        if (!next) verified.clear();
        try { changed(next); } catch { /* Optional observation cannot affect the UI. */ }
      }
      // A native drawer may spend its first frame outside the viewport.
      // Follow that short opening transition without a permanent animation loop.
      if (waitingVisual && Date.now() < settleUntil && doc.hidden !== true && doc.visibilityState !== 'hidden') schedule(false);
    }
    function schedule(settle = true) {
      if (stopped || suspended || !privacyAllows()) return;
      if (settle) settleUntil = Date.now() + 700;
      if (frame === null) frame = window.requestAnimationFrame(check);
    }
    const listen = (target, type, fn, options) => {
      target.addEventListener(type, fn, options);
      listeners.push(() => target.removeEventListener(type, fn, options));
    };
    const watcher = new window.MutationObserver(records => {
      let relevant = false;
      for (const record of records) {
        if (record.type === 'attributes' && record.attributeName === 'open' && record.target.matches?.(selector)) {
          verified.delete(record.target);
          if (record.target.open) opened.set(record.target, ++serial); else opened.delete(record.target);
          relevant = true;
        } else if (record.type === 'childList' || record.type === 'characterData' || record.target.closest?.(selector)) relevant = true;
      }
      if (relevant) schedule();
    });
    watcher.observe(doc.documentElement, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['open', 'hidden', 'class', 'style', 'aria-hidden']});
    // Events are only recheck hints; their payload never becomes tracking data.
    for (const type of ['vc:product-presented', 'vc:showcase-quick-state', 'vc:cart-drawer-state', 'close', 'visibilitychange']) listen(doc, type, () => schedule(), true);
    for (const type of ['resize', 'orientationchange']) listen(window, type, () => schedule());
    function suspend() {
      suspended = true;
      if (frame !== null) window.cancelAnimationFrame(frame);
      frame = null;
    }
    function resume() { if (!stopped) { suspended = false; previous = ''; schedule(); } }
    function stop() {
      if (stopped) return;
      stopped = true; suspend(); watcher.disconnect(); listeners.forEach(remove => remove()); verified.clear();
    }
    schedule();
    return Object.freeze({snapshot, stop, suspend, resume});
  }
  window.VialCoveVisitorSurfaces = Object.freeze({observe});
})();
