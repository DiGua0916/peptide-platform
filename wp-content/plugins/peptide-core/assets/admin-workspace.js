/* Progressive presentation only: native forms, names, events and permissions stay in place. */
(function () {
  'use strict';
  function start() {
    if (!document.body.classList.contains('pc-admin-workspace') || document.body.classList.contains('pc-design-editor-page')) return;
    // Native Woo header applications can route with History instead of an anchor or PHP redirect.
    // Only the retired Home/setup destinations are replaced; customer and analytics routes pass through.
    if (window.pcWorkspaceHome) {
      function operationalRoute(value) {
        let url;
        try { url = new URL(value || location.href, location.href); } catch (_) { return false; }
        if (url.origin !== location.origin || !url.pathname.endsWith('/wp-admin/admin.php') || url.searchParams.get('page') !== 'wc-admin') return false;
        const path = (url.searchParams.get('path') || '').split('?')[0].replace(/\/+$/, '');
        if (path !== '' && path !== '/profiler' && path !== '/setup-wizard' && !path.startsWith('/setup-wizard/')) return false;
        location.replace(window.pcWorkspaceHome);
        return true;
      }
      if (operationalRoute()) return;
      ['pushState', 'replaceState'].forEach(function (method) {
        const original = history[method];
        history[method] = function (state, title, url) {
          if (url && operationalRoute(url)) return;
          return original.apply(this, arguments);
        };
      });
      window.addEventListener('popstate', function () { operationalRoute(); });
    }
    const root = document.getElementById('wpbody-content');
    if (!root) return;
    // Woo's own Home breadcrumbs otherwise return to its first-run profiler.
    // Keep real customer, analytics, payment and settings URLs intact.
    if (window.pcWorkspaceHome) document.addEventListener('click', function (event) {
      const anchor = event.target.closest && event.target.closest('a[href]');
      if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const url = new URL(anchor.href, location.href);
      if (url.origin !== location.origin || !url.pathname.endsWith('/wp-admin/admin.php') || url.searchParams.get('page') !== 'wc-admin') return;
      const path = (url.searchParams.get('path') || '').split('?')[0].replace(/\/+$/, '');
      if (path !== '' && path !== '/profiler' && path !== '/setup-wizard' && !path.startsWith('/setup-wizard/')) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      location.assign(window.pcWorkspaceHome);
    }, true);
    // Only server-rendered tables. React owns the WooCommerce application subtree.
    root.querySelectorAll('table.wp-list-table, table.widefat, table.pc-mk-table').forEach(function (table) {
      if (table.closest('.woocommerce-layout, .block-editor, .media-modal, .pc-workspace-table, .pc-customer-scroll') || table.closest('#woocommerce-product-data')) return;
      const wrap = document.createElement('div');
      wrap.className = 'pc-workspace-table';
      wrap.tabIndex = 0;
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('aria-label', (table.getAttribute('aria-label') || document.querySelector('.wrap h1')?.textContent?.trim() || '数据') + '表格，可横向滚动');
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const active = new Set();
    function reveal(node, from, duration) {
      if (reduced.matches || !node || typeof node.animate !== 'function') return;
      const animation = node.animate([from, {opacity: 1, transform: 'none'}], {
        duration: duration || 200, easing: 'cubic-bezier(0.22,1,0.36,1)'
      });
      active.add(animation);
      animation.finished.then(function () { active.delete(animation); }, function () { active.delete(animation); });
    }
    reduced.addEventListener('change', function () {
      if (reduced.matches) active.forEach(function (animation) { animation.cancel(); });
    });
    // Observe the native menu state; never replace its click handler or layout rules.
    const wrap = document.getElementById('wpwrap');
    if (wrap) {
      let wasOpen = wrap.classList.contains('wp-responsive-open');
      new MutationObserver(function () {
        const open = wrap.classList.contains('wp-responsive-open');
        if (open && !wasOpen) reveal(document.getElementById('adminmenuwrap'), {opacity: 0, transform: 'translateX(-10px)'}, 200);
        wasOpen = open;
      }).observe(wrap, {attributes: true, attributeFilter: ['class']});
    }
    const collapse = document.getElementById('collapse-button');
    if (collapse) collapse.addEventListener('click', function () {
      reveal(document.querySelector('.pc-workspace-brand'), {opacity: 0.5, transform: 'translateY(2px)'}, 180);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
  else start();
}());
