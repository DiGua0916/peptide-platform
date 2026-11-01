/* Presentation only: cart links remain owned by the native cart-drawer controller. */
(() => {
  'use strict';
  const openers = new WeakMap();
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const syncLock = () => document.documentElement.classList.toggle('vc-demo-part-open', !!document.querySelector('.vc-demo-part-dialog[open]'));

  function openDialog(dialog, opener) {
    if (!dialog || typeof dialog.showModal !== 'function') return false;
    document.querySelectorAll('.vc-demo-part-dialog[open]').forEach(other => { if (other !== dialog) other.close(); });
    openers.set(dialog, opener);
    if (!dialog.open) dialog.showModal();
    opener.setAttribute('aria-expanded', 'true');
    syncLock();
    (dialog.querySelector('input[type="search"]') || dialog.querySelector('[data-vc-demo-close]'))?.focus();
    return true;
  }

  function focusCatalogue() {
    const target = document.querySelector('[data-vc-catalog-search]');
    const input = target?.matches('input') ? target : target?.querySelector('input[type="search"],input');
    if (!input || !input.getClientRects().length) return false;
    input.scrollIntoView({ behavior: reduceMotion.matches ? 'instant' : 'smooth', block: 'center' });
    input.focus({ preventScroll: true });
    return true;
  }

  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const search = event.target.closest('[data-vc-demo-search]');
    if (search) {
      if (focusCatalogue() || openDialog(document.getElementById(search.getAttribute('aria-controls')), search)) event.preventDefault();
      return;
    }
    const menu = event.target.closest('[data-vc-demo-menu]');
    if (menu) { event.preventDefault(); openDialog(document.getElementById(menu.getAttribute('aria-controls')), menu); return; }
    const close = event.target.closest('[data-vc-demo-close]');
    if (close) { close.closest('dialog')?.close(); return; }
    const link = event.target.closest('.vc-demo-menu-dialog a[href]');
    if (link) link.closest('dialog')?.close();
    document.querySelectorAll('.vc-demo-desktop-nav details[open],.vc-demo-secondary details[open]').forEach(details => {
      if (!details.contains(event.target)) details.open = false;
    });
  });

  function initialize(root = document) {
    root.querySelectorAll('.vc-demo-part-dialog:not([data-vc-demo-ready])').forEach(dialog => {
      dialog.dataset.vcDemoReady = 'true';
      dialog.addEventListener('close', () => {
        const opener = openers.get(dialog);
        opener?.setAttribute('aria-expanded', 'false');
        syncLock();
        if (opener?.isConnected) opener.focus({ preventScroll: true });
      });
      dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
      });
      // Native modal focus containment and Escape handling remain browser-owned.
    });
  }
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const group = document.activeElement?.closest('.vc-demo-nav-group[open]');
    if (group) { event.preventDefault(); group.open = false; group.querySelector('summary')?.focus(); }
  });
  const mobile = window.matchMedia('(max-width:767px)');
  mobile.addEventListener('change', () => { if (!mobile.matches) document.querySelectorAll('.vc-demo-menu-dialog[open]').forEach(dialog => dialog.close()); });
  initialize();
  // The editor replaces block previews without a page navigation.
  new MutationObserver(records => {
    if (records.some(record => Array.from(record.addedNodes).some(node => node.nodeType === 1 && (node.matches?.('[data-vc-demo-header]') || node.querySelector?.('[data-vc-demo-header]'))))) initialize();
  }).observe(document.body, { childList: true, subtree: true });
})();
