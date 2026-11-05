/* Theme-only movement of the native offer rail. The activity and its tiers stay server-owned. */
(function (global) {
  'use strict';
  const marker = 'data-vc-campaign-copy';
  function decorativeCopy(original, first) {
    const copy = original.cloneNode(true);
    for (const node of [copy, ...copy.querySelectorAll('*')]) {
      for (const attribute of [...node.attributes]) {
        if (/^(?:id|name|for|form|list|headers|itemref|popovertarget|commandfor|href|xlink:href|usemap|tabindex|autofocus|contenteditable|action|formaction|target|download)$/i.test(attribute.name) || /^(?:aria-|data-|on)/i.test(attribute.name)) node.removeAttribute(attribute.name);
      }
      if (node.matches('button,input,select,textarea,fieldset,option')) node.disabled = true;
      if (node.matches('a,button,input,select,textarea,summary,audio,video')) node.setAttribute('tabindex', '-1');
      node.removeAttribute('autoplay'); node.removeAttribute('controls');
    }
    copy.querySelectorAll('script,iframe,object,embed').forEach(node => node.remove());
    copy.setAttribute(marker, ''); copy.setAttribute('aria-hidden', 'true'); copy.setAttribute('inert', ''); copy.inert = true;
    if (first) copy.classList.add('vc-campaign-loop-start');
    return copy;
  }
  function create(root, win = global) {
    if (root.vcCampaignMotion) return root.vcCampaignMotion;
    const doc = win.document, rail = root.querySelector('.pc-campaign__tiers');
    if (!rail || !win.requestAnimationFrame || !win.matchMedia) return null;
    const reduced = win.matchMedia('(prefers-reduced-motion: reduce)'), removers = [];
    let clones = [], cycle = 0, position = 0, frame = 0, resizeFrame = 0, last = null, hovered = false, touching = false, hold = 0;
    let disposed = false, suspended = false, inView = !win.IntersectionObserver, track = null, animation = null, layoutKey = '';
    const on = (node, type, callback, options) => { node.addEventListener(type, callback, options); removers.push(() => node.removeEventListener(type, callback, options)); };
    function stop() { if (frame) win.cancelAnimationFrame(frame); frame = 0; last = null; animation?.pause(); }
    // Native scrollLeft animation fires the native rail's layout-reading scroll
    // handler on every frame. A compositor track retains the same tier nodes,
    // while touch/keyboard interaction hands the exact phase back to the rail.
    function manual() {
      if (!animation) return;
      position = ((Number(animation.currentTime) || 0) * .026) % cycle;
      animation.cancel(); animation = null; rail.scrollLeft = position;
    }
    const focused = () => !!doc.activeElement && root.contains(doc.activeElement);
    const allowed = () => !disposed && !suspended && !doc.hidden && inView && !reduced.matches && !hovered && !touching && !hold && !focused() && cycle > 0;
    function tick(time) {
      frame = 0;
      if (!root.isConnected || !rail.isConnected || !root.contains(rail)) { destroy(); return; }
      if (!allowed()) { last = null; return; }
      if (last === null) position = rail.scrollLeft % cycle;
      else {
        // Constant, restrained 26 px/s; a resumed background frame cannot jump.
        // Keep fractional progress even when a browser rounds scrollLeft reads.
        const distance = Math.min(64, Math.max(0, time - last)) * 0.026;
        position = (position + distance) % cycle;
        rail.scrollLeft = position;
      }
      last = time; frame = win.requestAnimationFrame(tick);
    }
    function sync() {
      if (!allowed()) { stop(); return; }
      if (track) {
        if (!animation) {
          position = rail.scrollLeft % cycle;
          animation = track.animate([{ transform:'translateX(0)' }, { transform:'translateX(-' + cycle + 'px)' }], { duration:cycle / .026, iterations:Infinity, easing:'linear' });
          animation.pause(); animation.currentTime = position / .026; rail.scrollLeft = 0;
        }
        if (animation.playState !== 'running') animation.play();
      } else if (!frame) { last = null; frame = win.requestAnimationFrame(tick); }
    }
    function removeCopies() { clones.forEach(node => node.remove()); clones = []; cycle = 0; }
    function refresh() {
      if (disposed) return;
      if (!root.isConnected || !rail.isConnected || !root.contains(rail)) { destroy(); return; }
      const originals = [...(track || rail).children].filter(node => !node.hasAttribute(marker));
      const nextKey = [rail.clientWidth, reduced.matches, ...originals.map(node => node.getBoundingClientRect().width + ':' + node.textContent)].join('|');
      if (layoutKey === nextKey) { sync(); return; }
      layoutKey = nextKey;
      stop(); manual();
      const position = cycle ? rail.scrollLeft % cycle : rail.scrollLeft;
      removeCopies();
      if (reduced.matches || originals.length < 1 || rail.clientWidth <= 0 || rail.scrollWidth <= rail.clientWidth + 2) { rail.scrollLeft = Math.max(0, position); return; }
      if (!track && doc.createElement) {
        const candidate = doc.createElement('div');
        if (typeof candidate.animate === 'function') {
          candidate.className = 'vc-campaign-track'; candidate.append(...originals); rail.append(candidate); track = candidate;
        }
      }
      clones = originals.map((node, index) => decorativeCopy(node, index === 0));
      (track || rail).append(...clones);
      const first = originals[0].getBoundingClientRect().left, repeated = clones[0].getBoundingClientRect().left;
      cycle = Number.isFinite(first) && Number.isFinite(repeated) ? repeated - first : clones[0].offsetLeft - originals[0].offsetLeft;
      if (!Number.isFinite(cycle) || cycle <= rail.clientWidth) { removeCopies(); rail.scrollLeft = Math.max(0, position); return; }
      rail.scrollLeft = position % cycle;
      sync();
    }
    function queueRefresh() {
      if (disposed || resizeFrame) return;
      resizeFrame = win.requestAnimationFrame(() => { resizeFrame = 0; refresh(); });
    }
    function releaseLater() {
      if (hold) win.clearTimeout(hold);
      hold = win.setTimeout(() => { hold = 0; sync(); }, 2000);
      stop();
    }
    function checkViewport() {
      const rect = root.getBoundingClientRect();
      inView = rect.bottom > 0 && rect.top < win.innerHeight;
      sync();
    }
    const intersection = win.IntersectionObserver ? new win.IntersectionObserver(entries => {
      for (const entry of entries) if (entry.target === root) { inView = entry.isIntersecting; sync(); }
    }, { threshold: 0.01 }) : null;
    const resize = win.ResizeObserver ? new win.ResizeObserver(queueRefresh) : null;
    function destroy() {
      if (disposed) return;
      disposed = true; stop(); manual();
      if (resizeFrame) win.cancelAnimationFrame(resizeFrame);
      if (hold) win.clearTimeout(hold);
      resizeFrame = hold = 0; removeCopies();
      if (track) { rail.append(...track.children); track.remove(); track = null; }
      intersection?.disconnect(); resize?.disconnect(); removers.forEach(remove => remove());
      if (root.vcCampaignMotion === controller) delete root.vcCampaignMotion;
    }
    const controller = { refresh, destroy };
    root.vcCampaignMotion = controller;
    on(root, 'pointerenter', event => { if (event.pointerType !== 'touch') { hovered = true; sync(); } });
    on(root, 'pointerleave', event => { if (event.pointerType !== 'touch') { hovered = false; sync(); } });
    on(root, 'focusin', () => { manual(); sync(); });
    on(root, 'focusout', event => { if (!event.relatedTarget || !root.contains(event.relatedTarget)) { stop(); queueRefresh(); } });
    on(rail, 'touchstart', () => { touching = true; manual(); if (hold) win.clearTimeout(hold); hold = 0; sync(); }, { passive: true });
    on(rail, 'touchend', () => { touching = false; releaseLater(); }, { passive: true });
    on(rail, 'touchcancel', () => { touching = false; releaseLater(); }, { passive: true });
    on(rail, 'wheel', () => { manual(); releaseLater(); }, { passive: true });
    on(doc, 'visibilitychange', sync);
    on(win, 'resize', queueRefresh);
    on(win, 'pagehide', event => { suspended = true; stop(); if (!event.persisted) destroy(); });
    on(win, 'pageshow', () => { suspended = false; queueRefresh(); });
    const motionChange = () => refresh();
    if (reduced.addEventListener) { reduced.addEventListener('change', motionChange); removers.push(() => reduced.removeEventListener('change', motionChange)); }
    else { reduced.addListener(motionChange); removers.push(() => reduced.removeListener(motionChange)); }
    intersection?.observe(root);
    if (resize) { resize.observe(rail); [...rail.children].forEach(node => resize.observe(node)); }
    if (!intersection) { on(win, 'scroll', checkViewport, { passive: true }); checkViewport(); }
    refresh();
    return controller;
  }
  const api = { create, decorativeCopy };
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (!global.document) return;
  if (global.VialCoveCampaignAnnouncement) { global.VialCoveCampaignAnnouncement.init(); return; }
  const init = () => global.document.querySelectorAll('[data-vc-campaign-strip]').forEach(root => create(root)?.refresh());
  global.VialCoveCampaignAnnouncement = { ...api, init };
  if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
  global.document.fonts?.ready.then(init).catch(() => {});
})(typeof window === 'object' ? window : globalThis);
