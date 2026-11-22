(() => {
  'use strict';
  const initialized = new WeakSet();

  const ALIASES = {
    tirz: 'tirzepatide', sema: 'semaglutide', reta: 'retatrutide', cagri: 'cagrilintide',
    glp: 'tirzepatide semaglutide retatrutide', glp1: 'semaglutide tirzepatide',
    bpc: 'bpc 157', bpc157: 'bpc 157', tb500: 'tb-500', ghk: 'ghk-cu', ghkcu: 'ghk-cu',
    nad: 'nad+', ipam: 'ipamorelin', tesam: 'tesamorelin', cjc: 'cjc-1295', mt2: 'melanotan'
  };

  function editDistance(a, b) {
    if (a === b) return 0;
    if (Math.abs(a.length - b.length) > 2) return 3;
    const prev = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
      let diag = prev[0];
      prev[0] = i;
      let rowMin = prev[0];
      for (let j = 1; j <= b.length; j++) {
        const tmp = prev[j];
        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
        prev[j] = Math.min(prev[j] + 1, prev[j - 1] + 1, diag + cost);
        diag = tmp;
        if (prev[j] < rowMin) rowMin = prev[j];
      }
      if (rowMin > 2) return 3;
    }
    return prev[b.length];
  }

  function fuzzyWordMatch(word, record) {
    if (record.search.includes(word)) return true;
    const compactWord = word.replace(/[^a-z0-9]/g, '');
    if (compactWord && record.compact.includes(compactWord)) return true;
    const alias = ALIASES[compactWord];
    if (alias && alias.split(' ').some(t => record.search.includes(t))) return true;
    if (compactWord.length < 4) return false;
    const maxDist = compactWord.length >= 6 ? 2 : 1;
    return record.tokens.some(tok => {
      if (tok.startsWith(compactWord.slice(0, 4)) && Math.abs(tok.length - compactWord.length) <= 2) return true;
      return editDistance(compactWord, tok) <= maxDist;
    });
  }

  function init(root) {
    if (initialized.has(root)) return;
    const grid = root.querySelector('[data-vc-catalog-grid]') || root.querySelector('.vc-demo-catalog__grid');
    const cards = [...root.querySelectorAll('[data-vc-catalog-item]')];
    const search = root.querySelector('[data-vc-catalog-search]');
    const sortSel = root.querySelector('[data-vc-catalog-sort]');
    const more = root.querySelector('[data-vc-catalog-more]');
    const status = root.querySelector('[data-vc-catalog-status]');
    const toolbar = root.querySelector('[data-vc-catalog-tools]');
    if (!search || !more || !status || !toolbar) return;
    initialized.add(root);
    root.dataset.ready = '1';
    let category = 'all', sortMode = 'featured', limit = Number(root.dataset.pageSize) || 8;
    const pageSize = limit;
    const normalize = value => value.toLocaleLowerCase().normalize('NFKC').replace(/\s+/g, ' ').trim();
    const records = cards.map((card, idx) => {
      const s = normalize(card.dataset.search || '');
      const tokens = [...new Set(s.split(/[^a-z0-9]+/).filter(t => t.length >= 3))];
      return {
        card,
        search: s,
        compact: s.replace(/[^a-z0-9]/g, ''),
        tokens,
        order: Number(card.dataset.order ?? idx),
        price: Number(card.dataset.price || 0),
        title: card.dataset.title || s,
        categories: (card.dataset.categories || '').split(',')
      };
    });

    function render() {
      const query = normalize(search.value);
      const words = query ? query.split(' ').filter(Boolean) : [];
      const matches = records.filter(r =>
        (category === 'all' || (Array.isArray(category) ? category.some(id => r.categories.includes(id)) : r.categories.includes(category))) &&
        (!words.length || words.every(w => fuzzyWordMatch(w, r)))
      );

      matches.sort((a, b) => {
        if (sortMode === 'price_asc') return a.price - b.price || a.order - b.order;
        if (sortMode === 'price_desc') return b.price - a.price || a.order - b.order;
        if (sortMode === 'name_asc') return a.title.localeCompare(b.title);
        return a.order - b.order;
      });

      if (grid) matches.forEach(r => grid.appendChild(r.card));

      const shown = new Set(matches.slice(0, limit).map(r => r.card));
      cards.forEach(card => {
        const wasHidden = card.hidden;
        card.hidden = !shown.has(card);
        if (wasHidden && !card.hidden) {
          card.querySelectorAll('img[loading="lazy"]').forEach(img => { img.loading = 'eager'; });
        }
      });
      more.hidden = matches.length <= limit;
      status.hidden = !query && category === 'all' && sortMode === 'featured';
      status.textContent = matches.length ? `${matches.length} ${matches.length === 1 ? 'product' : 'products'} found` : 'No matching products. Try another name or specification.';
    }

    toolbar.hidden = false;
    root.querySelectorAll('[data-vc-category]').forEach(button => button.addEventListener('click', () => {
      category = button.dataset.vcCategory; limit = pageSize;
      root.querySelectorAll('[data-vc-category]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
      render();
    }));
    search.addEventListener('input', () => { limit = pageSize; render(); });
    sortSel?.addEventListener('change', () => { sortMode = sortSel.value || 'featured'; render(); });
    root.addEventListener('vc:catalog-restore', event => {
      const value = event.detail;
      if (!value || typeof value.query !== 'string' || value.query.length > 200 || !Array.isArray(value.categoryIds) || value.categoryIds.length > 300 || !value.categoryIds.every(id => typeof id === 'string' && /^[1-9]\d*$/.test(id))) return;
      search.value = value.query;
      category = value.categoryIds.length ? value.categoryIds : 'all'; limit = pageSize;
      root.querySelectorAll('[data-vc-category]').forEach(button => button.setAttribute('aria-pressed', String(category === 'all' ? button.dataset.vcCategory === 'all' : button.dataset.vcCategory === value.categoryIds[0])));
      render();
    });
    more.addEventListener('click', () => { const old = limit; limit += pageSize; render(); const visible = cards.filter(c => !c.hidden); visible[old]?.querySelector('a')?.focus({ preventScroll: true }); });
    root.querySelectorAll('.vc-demo-catalog__all, [data-vc-catalog-expand]').forEach(link => link.addEventListener('click', event => {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
      const url = new URL(link.href || location.href, location.href);
      const sectionId = root.closest('section[id]')?.id || root.id;
      const isCollectionAnchor = sectionId && url.origin === location.origin && url.pathname === location.pathname && url.hash === `#${sectionId}`;
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
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => boot(), { once: true });
  else boot();
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) boot(node); }));
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
