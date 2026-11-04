/* Native navigation feedback. Never intercept, prefetch or replay business requests. */
(function () {
  'use strict';
  if (!document.body.classList.contains('pc-admin-workspace')) return;
  const menu = document.getElementById('adminmenu');
  if (!menu) return;
  const indicator = document.createElement('div');
  indicator.className = 'pc-navigation-progress';
  indicator.hidden = true;
  indicator.setAttribute('role', 'status');
  indicator.setAttribute('aria-live', 'polite');
  const label = document.createElement('span');
  label.className = 'screen-reader-text';
  label.textContent = '正在打开页面';
  indicator.append(label);
  document.body.append(indicator);
  let timer = 0;
  let pending = null;
  function clear() {
    window.clearTimeout(timer);
    indicator.hidden = true;
    if (pending) pending.classList.remove('pc-navigation-pending');
    pending = null;
  }
  document.addEventListener('click', function (event) {
    const anchor = event.target.closest && event.target.closest('#adminmenu a[href]');
    if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target && anchor.target !== '_self' || anchor.hasAttribute('download')) return;
    let url;
    try { url = new URL(anchor.href, window.location.href); } catch (_) { return; }
    if (url.origin !== location.origin || !url.pathname.startsWith('/wp-admin/') || url.hash || /(?:nonce|action|logout)/i.test(url.search)) return;
    // A cancelled native beforeunload prompt cannot leave a permanent blocker.
    // Let every click retain its ordinary link and unsaved-form behavior.
    clear();
    pending = anchor;
    pending.classList.add('pc-navigation-pending');
    indicator.hidden = false;
    timer = window.setTimeout(clear, 12000);
  });
  window.addEventListener('pageshow', clear);
  window.addEventListener('pagehide', clear);
  window.addEventListener('focus', clear);
  document.addEventListener('visibilitychange', function () { if (document.hidden) clear(); });
}());
