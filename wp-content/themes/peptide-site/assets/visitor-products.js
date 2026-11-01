/* Optional browser product visibility, not human attention. No identity, transport or storage.
 * At most 1,000 product/context keys and 2,000 live placements per page instance. */
(() => {
  'use strict';
  const CARD = 'article.product-card[data-vc-observe-product]';
  const GALLERY = '.woocommerce-product-gallery, .vc-product-gallery';
  const KINDS = ['home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown'];
  const id = value => typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : null;
  const attributeId = (value, zero = false) => typeof value === 'string' && /^(0|[1-9][0-9]*)$/.test(value) && Number.isSafeInteger(Number(value)) && (Number(value) > 0 || zero && value === '0') ? Number(value) : null;
  function observe(root, emit, observation) {
    const doc = root?.nodeType === 9 ? root : root?.ownerDocument, view = doc?.defaultView;
    const inert = {stop() {}, suspend() {}, resume() {}};
    const source = observation?.context, page = observation?.page;
    if (!root?.querySelectorAll || !view?.IntersectionObserver || !view?.MutationObserver || typeof view.performance?.now !== 'function' || typeof view.getComputedStyle !== 'function' || typeof emit !== 'function' || !source || !KINDS.includes(source.kind)) return inert;
    const context = {kind: source.kind};
    if (source.kind === 'collection') { if (!id(source.term_id)) return inert; context.entity_id = source.term_id; }
    if (source.kind === 'product') { if (!id(page?.product_id)) return inert; context.entity_id = page.product_id; }
    const records = new Map(), admitted = new Set(), completed = new Set();
    let stopped = false, suspended = false;
    const visible = () => !stopped && !suspended && !doc.hidden && doc.visibilityState === 'visible' && doc.prerendering !== true;
    const clock = () => view.performance.now();
    function cancel(record) { if (record.timer !== null) view.clearTimeout(record.timer); record.timer = null; record.since = null; }
    function descriptor(element) {
      let product, variation = 0;
      if (element.matches?.(CARD)) {
        product = attributeId(element.getAttribute('data-vc-observe-product'));
        const rawVariation = element.getAttribute('data-vc-observe-variation');
        if (rawVariation !== null) { variation = attributeId(rawVariation, true); if (variation === null) return null; }
      } else if (page?.page_type === 'product' && element.matches?.(GALLERY)) product = id(page.product_id);
      if (!product) return null;
      const own = {...context};
      // Renderer::wrap emits vc-<native id>; data-module holds the type, never the identity.
      const module = element.closest?.('section[data-module][id]');
      const moduleId = module?.getAttribute('id');
      if (typeof moduleId === 'string' && moduleId.startsWith('vc-') && /^[A-Za-z0-9][A-Za-z0-9-]{0,39}$/.test(moduleId.slice(3))) own.module_id = moduleId.slice(3);
      return {product_id: product, variation_id: variation, context: Object.freeze(own), key: JSON.stringify([product, variation, own])};
    }
    function geometry(element) {
      if (!visible() || !element.isConnected || !element.getClientRects?.().length) return 0;
      let rect;
      try { rect = element.getBoundingClientRect(); } catch { return 0; }
      const width = rect.right - rect.left, height = rect.bottom - rect.top;
      if (!(width > 0 && height > 0)) return 0;
      let left = Math.max(0, rect.left), top = Math.max(0, rect.top), right = Math.min(view.innerWidth || doc.documentElement.clientWidth, rect.right), bottom = Math.min(view.innerHeight || doc.documentElement.clientHeight, rect.bottom);
      for (let node = element; node?.nodeType === 1; node = node.parentElement) {
        const style = view.getComputedStyle(node);
        if (node.hidden || style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' || style.contentVisibility === 'hidden' || Number(style.opacity) === 0) return 0;
        if (node === element) continue;
        if (/(hidden|clip|scroll|auto)/.test(style.overflowX || style.overflow || '') || /(hidden|clip|scroll|auto)/.test(style.overflowY || style.overflow || '')) {
          const box = node.getBoundingClientRect();
          const x = box.left + (Number(node.clientLeft) || 0), y = box.top + (Number(node.clientTop) || 0);
          const edgeX = Number.isFinite(node.clientWidth) ? x + node.clientWidth : box.right, edgeY = Number.isFinite(node.clientHeight) ? y + node.clientHeight : box.bottom;
          if (/(hidden|clip|scroll|auto)/.test(style.overflowX || style.overflow || '')) { left = Math.max(left, x); right = Math.min(right, edgeX); }
          if (/(hidden|clip|scroll|auto)/.test(style.overflowY || style.overflow || '')) { top = Math.max(top, y); bottom = Math.min(bottom, edgeY); }
        }
      }
      const fraction = Math.max(0, right - left) * Math.max(0, bottom - top) / (width * height);
      return Number.isFinite(fraction) ? Math.min(1, fraction) : 0;
    }
    function current(record) {
      const actual = descriptor(record.element);
      return actual?.key === record.description.key ? actual : null;
    }
    function finish(record) {
      const since = record.since;
      record.timer = null;
      // Drain queued leaves/removals first; current geometry alone cannot prove the previous interval.
      mutations(mutationObserver.takeRecords()); intersections(intersectionObserver.takeRecords());
      if (record.since !== since) return;
      if (!records.has(record.element) || record.since === null || !current(record)) { cancel(record); return; }
      const fraction = Math.min(record.ratio, geometry(record.element)), elapsed = Math.floor(clock() - record.since);
      if (fraction < 0.4 || elapsed < 500 || elapsed > 2147483647 || completed.has(record.description.key)) { cancel(record); return; }
      completed.add(record.description.key);
      for (const candidate of records.values()) if (candidate.description.key === record.description.key) cancel(candidate);
      const data = Object.freeze({version: 1, product_id: record.description.product_id, variation_id: record.description.variation_id, visible_fraction: fraction, visible_ms: elapsed, context: record.description.context});
      try { emit(data); } catch { /* Observation cannot alter storefront behavior. */ }
    }
    function assess(record) {
      if (!current(record)) { remove(record.element); discover(record.element); return; }
      if (completed.has(record.description.key) || record.ratio < 0.4 || geometry(record.element) < 0.4) { cancel(record); return; }
      if (record.since === null) { record.since = clock(); record.timer = view.setTimeout(() => finish(record), 500); }
    }
    function intersections(entries) {
      for (const entry of entries) {
        const record = records.get(entry.target); if (!record) continue;
        record.ratio = entry.isIntersecting === true && Number.isFinite(entry.intersectionRatio) ? Math.max(0, Math.min(1, entry.intersectionRatio)) : 0;
        assess(record);
      }
    }
    const intersectionObserver = new view.IntersectionObserver(intersections, {threshold: [0, 0.4, 1]});
    function add(element) {
      if (records.has(element) || records.size >= 2000 || !element.isConnected) return;
      const description = descriptor(element); if (!description || completed.has(description.key) || !admitted.has(description.key) && admitted.size >= 1000) return;
      admitted.add(description.key); const record = {element, description, ratio: 0, since: null, timer: null}; records.set(element, record); intersectionObserver.observe(element);
    }
    function discover(node) {
      if (stopped || !node) return;
      if (node.nodeType === 1) add(node);
      const selector = page?.page_type === 'product' && id(page.product_id) ? CARD + ', ' + GALLERY : CARD;
      for (const element of node.querySelectorAll?.(selector) || []) add(element);
    }
    function remove(element) { const record = records.get(element); if (!record) return; cancel(record); intersectionObserver.unobserve(element); records.delete(element); }
    function mutations(entries) {
      if (stopped) return;
      const added = new Set(), removed = new Set(), changed = new Set();
      for (const entry of entries) {
        if (entry.type === 'childList') { for (const node of entry.addedNodes) added.add(node); for (const node of entry.removedNodes) removed.add(node); }
        else changed.add(entry.target);
      }
      // At most 2,000 tracked placements: removals and ancestor CSS/context changes never rescan the document.
      for (const record of [...records.values()]) {
        if (!record.element.isConnected || [...removed].some(node => node === record.element || node.contains?.(record.element))) remove(record.element);
        else if ([...changed].some(node => node === record.element || node.contains?.(record.element))) { cancel(record); assess(record); }
      }
      for (const node of added) discover(node);
      for (const node of changed) if (!records.has(node)) add(node);
    }
    const mutationObserver = new view.MutationObserver(mutations);
    mutationObserver.observe(root.nodeType === 9 ? doc.documentElement : root, {subtree: true, childList: true, attributes: true, attributeFilter: ['data-vc-observe-product', 'data-vc-observe-variation', 'id', 'class', 'style', 'hidden']});
    const suspend = () => { suspended = true; for (const record of records.values()) cancel(record); };
    const resume = () => { if (stopped) return; suspended = false; for (const record of records.values()) assess(record); };
    const visibility = () => { for (const record of records.values()) cancel(record); if (visible()) for (const record of records.values()) assess(record); };
    doc.addEventListener('visibilitychange', visibility);
    doc.addEventListener('prerenderingchange', visibility);
    discover(root);
    return {suspend, resume, stop() { if (stopped) return; stopped = true; for (const record of records.values()) cancel(record); records.clear(); admitted.clear(); completed.clear(); intersectionObserver.disconnect(); mutationObserver.disconnect(); doc.removeEventListener('visibilitychange', visibility); doc.removeEventListener('prerenderingchange', visibility); }};
  }
  window.VialCoveVisitorProducts = {observe};
})();
