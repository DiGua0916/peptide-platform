/* Keep the same native homepage announcement below the measured sticky header. */
(function (global) {
  'use strict';
  function mount(root, win = global) {
    if (!root || !win?.document || !root.hasAttribute('data-vc-campaign-sticky')) return null;
    if (root.vcCampaignSticky) return root.vcCampaignSticky;
    const wrapper = root.closest('.vc-m');
    if (!wrapper || wrapper.classList.contains('vc-m--editor')) return null;
    const doc = win.document, page = doc.documentElement, removers = [], property = '--vc-campaign-sticky-top', anchorProperty = '--vc-campaign-anchor-offset';
    let disposed = false, frame = 0, header = null;
    const previous = wrapper.style.getPropertyValue(property), priority = wrapper.style.getPropertyPriority(property);
    const anchorPrevious = page.style.getPropertyValue(anchorProperty), anchorPriority = page.style.getPropertyPriority(anchorProperty), pageHadClass = page.classList.contains('vc-campaign-pin-page');
    const on = (node, name, callback, options) => { node?.addEventListener(name, callback, options); removers.push(() => node?.removeEventListener(name, callback, options)); };
    const resize = win.ResizeObserver ? new win.ResizeObserver(queue) : null;
    function measure() {
      frame = 0;
      if (disposed) return;
      if (!root.isConnected || !wrapper.contains(root)) { destroy(); return; }
      const next = [...doc.querySelectorAll('[data-vc-demo-header].vc-demo-header--sticky')].map(node => node.closest('.vc-m--site-header')).find(node => {
        if (!node) return false;
        const style = win.getComputedStyle(node), rect = node.getBoundingClientRect();
        return ['sticky', 'fixed'].includes(style.position) && style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      }) || null;
      if (next !== header) { if (header) resize?.unobserve(header); header = next; if (header) resize?.observe(header); }
      let offset = 0;
      if (header) {
        const style = win.getComputedStyle(header), rect = header.getBoundingClientRect();
        if (['sticky', 'fixed'].includes(style.position) && rect.height > 0 && style.display !== 'none') {
          const top = Number.parseFloat(style.top);
          offset = Math.max(0, Number.isFinite(top) ? top : 0) + rect.height;
        }
      }
      const topValue = Math.max(0, offset).toFixed(2) + 'px';
      if (wrapper.style.getPropertyValue(property) !== topValue) wrapper.style.setProperty(property, topValue);
      const height = wrapper.getBoundingClientRect().height;
      const anchorValue = (Math.max(0, offset) + Math.max(0, Number.isFinite(height) ? height : 0) + 12).toFixed(2) + 'px';
      if (page.style.getPropertyValue(anchorProperty) !== anchorValue) page.style.setProperty(anchorProperty, anchorValue);
    }
    function queue() { if (!disposed && !frame) frame = win.requestAnimationFrame(measure); }
    function destroy() {
      if (disposed) return;
      disposed = true; if (frame) win.cancelAnimationFrame(frame); frame = 0;
      resize?.disconnect(); removers.forEach(remove => remove());
      wrapper.classList.remove('vc-campaign-pin');
      if (previous) wrapper.style.setProperty(property, previous, priority); else wrapper.style.removeProperty(property);
      if (!pageHadClass) page.classList.remove('vc-campaign-pin-page');
      if (anchorPrevious) page.style.setProperty(anchorProperty, anchorPrevious, anchorPriority); else page.style.removeProperty(anchorProperty);
      if (root.vcCampaignSticky === controller) delete root.vcCampaignSticky;
    }
    const controller = { refresh: queue, destroy };
    root.vcCampaignSticky = controller;
    wrapper.classList.add('vc-campaign-pin');
    page.classList.add('vc-campaign-pin-page');
    // Unrelated images changing document height do not change either sticky
    // region. Observe their actual boxes instead of the entire page.
    resize?.observe(wrapper);
    const adminBar = doc.getElementById('wpadminbar'); if (adminBar) resize?.observe(adminBar);
    on(win, 'resize', queue, { passive: true }); on(win.visualViewport, 'resize', queue, { passive: true });
    on(win, 'pageshow', queue); on(win, 'pagehide', event => { if (!event.persisted) destroy(); });
    doc.fonts?.ready.then(queue).catch(() => {});
    measure();
    return controller;
  }
  const api = { mount };
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (!global?.document) return;
  const init = () => global.document.querySelectorAll('[data-vc-campaign-sticky]').forEach(root => mount(root)?.refresh());
  if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})(typeof window === 'object' ? window : null);
