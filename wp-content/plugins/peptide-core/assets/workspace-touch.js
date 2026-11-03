/* Native menu state, with an overlay and a focus boundary for touch workspaces. */
(function () {
  'use strict';
  if (window.vcWorkspaceTouch) return;
  const body = document.body;
  if (!body || !body.matches('.pc-admin-workspace,.pchq-workspace') || body.matches('.pc-design-editor-page')) return;
  const wrap = document.getElementById('wpwrap');
  const menu = document.getElementById('adminmenuwrap');
  const content = document.getElementById('wpcontent');
  if (!wrap || !menu || !content) return;
  const hq = body.classList.contains('pchq-workspace');
  const prefix = hq ? 'pchq-mobile-nav' : 'pc-workspace-nav';
  const narrow = window.matchMedia('(max-width: 1024px)');
  const close = document.createElement('button');
  close.type = 'button';
  close.className = prefix + '-close';
  close.setAttribute('aria-label', '关闭导航');
  close.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
  const backdrop = document.createElement('button');
  backdrop.type = 'button';
  backdrop.className = prefix + '-backdrop';
  backdrop.setAttribute('aria-label', '关闭导航');
  backdrop.tabIndex = -1;
  backdrop.hidden = true;
  menu.prepend(close);
  body.append(backdrop);
  let opened = false;
  let opener = null;
  let previousInert = false;
  let previousMenuRole = null;
  let previousMenuLabel = null;
  let previousMenuTabindex = null;
  const cleanup = [];
  function listen(node, event, handler, options) {
    node.addEventListener(event, handler, options);
    cleanup.push(function () { node.removeEventListener(event, handler, options); });
  }
  function triggers() {
    return Array.from(document.querySelectorAll('[data-vc-nav-toggle],#wp-admin-bar-menu-toggle a'));
  }
  function focusable() {
    return Array.from(menu.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),[tabindex]:not([tabindex="-1"])'))
      .filter(function (node) { return !node.hidden && node.getClientRects().length > 0; });
  }
  // WordPress/Woo modals may be ARIA portal surfaces rather than native <dialog>.
  // A visible modal outside the drawer owns focus and Escape until it closes.
  function externalModalOpen() {
    return Array.from(document.querySelectorAll('dialog[open],[role="dialog"][aria-modal="true"],[role="alertdialog"][aria-modal="true"]'))
      .some(function (node) {
        if (menu.contains(node) || node.hidden || node.closest('[hidden],[aria-hidden="true"],[inert]') || !node.getClientRects().length) return false;
        const visibility = window.getComputedStyle(node).visibility;
        return visibility !== 'hidden' && visibility !== 'collapse';
      });
  }
  function restoreAttribute(node, name, value) {
    if (value === null) node.removeAttribute(name);
    else node.setAttribute(name, value);
  }
  function sync() {
    const next = narrow.matches && wrap.classList.contains('wp-responsive-open');
    triggers().forEach(function (trigger) {
      if (trigger.hasAttribute('data-vc-nav-toggle')) trigger.hidden = false;
      trigger.setAttribute('aria-controls', 'adminmenuwrap');
      trigger.setAttribute('aria-expanded', next ? 'true' : 'false');
    });
    if (next === opened) return;
    opened = next;
    backdrop.hidden = !next;
    body.classList.toggle('vc-workspace-nav-open', next);
    if (next) {
      opener = document.activeElement;
      previousInert = content.inert;
      content.inert = true;
      previousMenuRole = menu.getAttribute('role');
      previousMenuLabel = menu.getAttribute('aria-label');
      previousMenuTabindex = menu.getAttribute('tabindex');
      menu.setAttribute('role', 'dialog');
      menu.setAttribute('aria-modal', 'true');
      menu.setAttribute('aria-label', hq ? '管理后台导航' : '客户后台导航');
      menu.tabIndex = -1;
      close.focus({ preventScroll: true });
    } else {
      content.inert = previousInert;
      restoreAttribute(menu, 'role', previousMenuRole);
      restoreAttribute(menu, 'aria-label', previousMenuLabel);
      menu.removeAttribute('aria-modal');
      restoreAttribute(menu, 'tabindex', previousMenuTabindex);
      if (opener && opener.isConnected && !opener.closest('[inert]')) opener.focus({ preventScroll: true });
      opener = null;
    }
  }
  function dismiss() {
    wrap.classList.remove('wp-responsive-open');
    sync();
  }
  listen(document, 'click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    const trigger = target && target.closest('[data-vc-nav-toggle]');
    if (!trigger || !narrow.matches) return;
    event.preventDefault();
    wrap.classList.toggle('wp-responsive-open');
    sync();
  });
  listen(close, 'click', dismiss);
  listen(backdrop, 'click', dismiss);
  listen(menu, 'click', function (event) {
    const target = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!opened || !target || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const href = target.getAttribute('href');
    if (href && href !== '#' && !target.closest('#collapse-menu')) dismiss();
  });
  listen(document, 'keydown', function (event) {
    if (!opened || externalModalOpen()) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      dismiss();
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable();
    const first = items[0] || close;
    const last = items[items.length - 1] || close;
    if (!menu.contains(document.activeElement) || (event.shiftKey && document.activeElement === first)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
  listen(document, 'focusin', function (event) {
    if (opened && !menu.contains(event.target) && !externalModalOpen()) close.focus({ preventScroll: true });
  });
  const observer = new MutationObserver(sync);
  observer.observe(wrap, { attributes: true, attributeFilter: ['class'] });
  const resize = function () {
    if (!narrow.matches) dismiss();
    else sync();
  };
  narrow.addEventListener('change', resize);
  cleanup.push(function () { narrow.removeEventListener('change', resize); });
  window.vcWorkspaceTouch = {
    dismiss: dismiss,
    dispose: function () {
      dismiss();
      observer.disconnect();
      cleanup.splice(0).forEach(function (remove) { remove(); });
      close.remove();
      backdrop.remove();
      delete window.vcWorkspaceTouch;
    }
  };
  sync();
}());
