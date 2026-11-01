(() => {
  'use strict';
  const initialized = new WeakSet();
  function init(root) {
    if (initialized.has(root)) return;
    const cards = [...root.querySelectorAll('[data-vc-catalog-item]')];
    const search = root.querySelector('[data-vc-catalog-search]');
    const more = root.querySelector('[data-vc-catalog-more]');
    const status = root.querySelector('[data-vc-catalog-status]');
    const toolbar = root.querySelector('[data-vc-catalog-tools]');
    if (!search || !more || !status || !toolbar) return;
    initialized.add(root);
    root.dataset.ready = '1';
    let category = 'all', limit = Number(root.dataset.pageSize) || 8;
    const pageSize = limit;
    const normalize = value => value.toLocaleLowerCase().normalize('NFKC').replace(/\s+/g, ' ').trim();
    const records = cards.map(card => ({card, search: normalize(card.dataset.search || ''), categories: (card.dataset.categories || '').split(',')}));
    function render() {
      const query = normalize(search.value);
      const matches = records.filter(r => (category === 'all' || r.categories.includes(category)) && (!query || query.split(' ').every(word => r.search.includes(word))));
      const shown = new Set(matches.slice(0, limit).map(r => r.card));
      cards.forEach(card => { card.hidden = !shown.has(card); });
      more.hidden = matches.length <= limit;
      status.hidden = !query && category === 'all';
      status.textContent = matches.length ? `${matches.length} ${matches.length === 1 ? 'product' : 'products'} found` : 'No matching products. Try another name or specification.';
    }
    toolbar.hidden = false;
    root.querySelectorAll('[data-vc-category]').forEach(button => button.addEventListener('click', () => {
      category = button.dataset.vcCategory; limit = pageSize;
      root.querySelectorAll('[data-vc-category]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
      render();
    }));
    search.addEventListener('input', () => { limit = pageSize; render(); });
    more.addEventListener('click', () => { const old = limit; limit += pageSize; render(); const visible = cards.filter(c => !c.hidden); visible[old]?.querySelector('a')?.focus({preventScroll: true}); });
    root.querySelectorAll('.vc-demo-catalog__all, [data-vc-catalog-expand]').forEach(link => link.addEventListener('click', event => {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
      const url = new URL(link.href || location.href, location.href);
      const sectionId = root.closest('section[id]')?.id || root.id;
      const isCollectionAnchor = sectionId && url.origin === location.origin && url.pathname === location.pathname && url.hash === `#${sectionId}`;
      // An editable destination such as /shop/ must remain a normal link.
      if (!link.hasAttribute('data-vc-catalog-expand') && !isCollectionAnchor) return;
      event.preventDefault(); category = 'all'; search.value = ''; limit = cards.length;
      root.querySelectorAll('[data-vc-category]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.vcCategory === 'all')));
      render();
    }));
    render();
  }
  function boot(root = document) {
    if (root.matches?.('[data-vc-catalog]')) init(root);
    root.querySelectorAll?.('[data-vc-catalog]').forEach(init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => boot(), {once: true});
  else boot();
  // The builder replaces individual sections; initialize only newly added trees.
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) boot(node); }));
  }).observe(document.documentElement, {childList: true, subtree: true});
})();
