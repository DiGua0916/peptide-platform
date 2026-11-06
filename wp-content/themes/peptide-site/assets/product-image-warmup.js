/* Warm only native variation images; never cache or request business metadata. */
(function (global) {
  'use strict';
  const sizes = '(max-width: 767px) calc(100vw - 64px), 500px';
  const cached = new Map();
  let current = null;
  function local(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try { const url = new URL(value, global.location.href); return /^https?:$/.test(url.protocol) && url.origin === global.location.origin && !url.username && !url.password ? url.href : ''; } catch (_) { return ''; }
  }
  function image(row) {
    const dto = Object.prototype.hasOwnProperty.call(row, 'vc_home_image') ? row.vc_home_image : row.image;
    if (!dto || typeof dto !== 'object') return null;
    const src = local(dto.src); if (!src) return null;
    let srcset = '';
    if (dto.srcset) {
      if (typeof dto.srcset !== 'string') return null;
      const parts = dto.srcset.split(',').map(part => part.trim().split(/\s+/));
      if (parts.some(part => part.length !== 2 || !local(part[0]) || !/^(?:[1-9]\d*w|(?:\d+(?:\.\d+)?)x)$/.test(part[1]))) return null;
      srcset = parts.map(part => local(part[0]) + ' ' + part[1]).join(', ');
    }
    return {src, srcset, key:JSON.stringify([src, srcset, sizes])};
  }
  function start(form, dialog) {
    if (current?.form === form && current.dialog === dialog) return current.stop;
    current?.stop();
    const connection = global.navigator?.connection;
    if (!form || !dialog?.open || global.document.hidden || connection?.saveData || /^(?:slow-)?2g$/.test(connection?.effectiveType || '')) return () => {};
    let rows; try { rows = JSON.parse(form.getAttribute('data-product_variations') || 'null'); } catch (_) { return () => {}; }
    if (!Array.isArray(rows)) return () => {};
    const queue = [], seen = new Set();
    for (const row of rows) {
      if (!row || typeof row !== 'object') continue;
      const dto = image(row);
      if (!dto || seen.has(dto.key)) continue;
      seen.add(dto.key); if (!cached.has(dto.key)) queue.push(dto);
      if (seen.size >= 12) break;
    }
    let stopped = false, idle = null, fallback = null, waitingImage = null;
    const active = new Map();
    function detachVisible() {
      waitingImage?.removeEventListener('load', visibleReady);
      waitingImage?.removeEventListener('error', visibleReady);
      waitingImage = null;
    }
    function visibleReady() { detachVisible(); schedule(); }
    function schedule() {
      if (stopped) return;
      if (global.requestIdleCallback) idle = global.requestIdleCallback(pump, {timeout:1200});
      else fallback = global.setTimeout(pump, 400);
    }
    function stop() {
      if (stopped) return; stopped = true; queue.length = 0;
      detachVisible();
      if (idle !== null) global.cancelIdleCallback?.(idle);
      global.clearTimeout(fallback);
      for (const [img, task] of active) { global.clearTimeout(task.timer); img.onload = img.onerror = null; img.removeAttribute('srcset'); img.removeAttribute('src'); }
      active.clear(); dialog.removeEventListener('close', stop); global.removeEventListener('pagehide', stop); global.document.removeEventListener('visibilitychange', visibility);
      if (current?.stop === stop) current = null;
    }
    function visibility() { if (global.document.hidden) stop(); }
    function pump() {
      if (stopped) return;
      if (!dialog.open || global.document.hidden) { stop(); return; }
      // CPU idle is not network idle. A cold selected image must get the link
      // before two speculative SKU downloads, especially on mobile connections.
      const visible = dialog.querySelector?.('.vc-dialog-product-image, .vc-showcase-quick__media img');
      if (visible && !visible.complete) {
        if (waitingImage !== visible) {
          detachVisible(); waitingImage = visible;
          visible.addEventListener('load', visibleReady);
          visible.addEventListener('error', visibleReady);
        }
        return;
      }
      detachVisible();
      while (active.size < 2 && queue.length) {
        const dto = queue.shift(); if (cached.has(dto.key)) continue;
        const img = new global.Image();
        const finish = success => {
          const task = active.get(img); if (!task) return;
          global.clearTimeout(task.timer); active.delete(img); img.onload = img.onerror = null;
          if (!success) { img.removeAttribute('srcset'); img.removeAttribute('src'); }
          if (success) { cached.delete(dto.key); cached.set(dto.key, true); while (cached.size > 96) cached.delete(cached.keys().next().value); }
          pump();
        };
        active.set(img, {timer:global.setTimeout(() => finish(false), 10000)});
        img.onload = () => finish(true); img.onerror = () => finish(false);
        img.fetchPriority = 'low'; img.sizes = sizes;
        if (dto.srcset) img.srcset = dto.srcset;
        img.src = dto.src;
      }
    }
    current = {form, dialog, stop};
    dialog.addEventListener('close', stop); global.addEventListener('pagehide', stop); global.document.addEventListener('visibilitychange', visibility);
    // The visible selected image is assigned by the presenter before this idle work.
    schedule();
    return stop;
  }
  global.VialCoveProductImageWarmup = Object.freeze({start});
})(window);
