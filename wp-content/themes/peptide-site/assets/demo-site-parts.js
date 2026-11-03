/* Presentation only: cart links remain owned by the native cart-drawer controller. */
(() => {
  'use strict';
  const openers = new WeakMap();
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const states = new Map();
  const knownStates = new WeakMap();
  const easing = 'cubic-bezier(.22,1,.36,1)';
  const syncLock = () => document.documentElement.classList.toggle('vc-demo-part-open', !!document.querySelector('.vc-demo-part-dialog[open]'));

  function cancelMotion(state) {
    window.clearTimeout(state.timer); state.timer = 0;
    for (const animation of state.animations) {
      animation.onfinish = null;
      try { animation.cancel(); } catch (_) { /* Native content remains usable. */ }
    }
    state.animations = [];
    delete state.dialog.dataset.vcPartMotion;
  }
  function cleanup(state, restoreFocus = true) {
    cancelMotion(state);
    if (!state.session) return;
    state.session = false; state.desired = false;
    const opener = openers.get(state.dialog);
    opener?.setAttribute('aria-expanded', 'false');
    syncLock();
    if (restoreFocus && !document.hidden && opener?.isConnected) opener.focus({ preventScroll: true });
  }
  function settle(state, restoreFocus = true) {
    const open = state.desired;
    cancelMotion(state);
    if (!open) {
      if (state.dialog.open) state.dialog.close();
      cleanup(state, restoreFocus);
    }
  }
  function transition(state, open, fresh = false) {
    const {dialog} = state;
    // Read the current painted state before cancellation, so a rapid reversal
    // continues from the visible position rather than jumping to an endpoint.
    const painted = getComputedStyle(dialog);
    const menu = dialog.classList.contains('vc-demo-menu-dialog');
    const offset = menu ? 'translateX(24px)' : 'translateY(16px) scale(.985)';
    const from = fresh ? {opacity: 0, transform: offset} : {opacity: painted.opacity, transform: painted.transform};
    cancelMotion(state); state.desired = open;
    if (reduceMotion.matches || document.hidden || typeof dialog.animate !== 'function') { settle(state); return; }
    const duration = open ? 350 : 200;
    try {
      const generation = ++state.generation;
      dialog.dataset.vcPartMotion = open ? 'opening' : 'closing';
      const animation = dialog.animate([from, {opacity: open ? 1 : 0, transform: open ? 'none' : offset}], {duration, easing, fill: 'both'});
      state.animations.push(animation);
      if (open && fresh && menu) {
        // The list arrives as a list, with a bounded total sequence. Content is
        // visible without WAAPI and no link is held back from interaction.
        const rows = Array.from(dialog.querySelectorAll(':scope > nav > .vc-demo-nav-list > li')).slice(0, 6);
        rows.forEach((row, index) => {
          if (typeof row.animate === 'function') state.animations.push(row.animate([{opacity: .35, transform: 'translateX(8px)'}, {opacity: 1, transform: 'none'}], {duration: 220, delay: index * 16, easing, fill: 'backwards'}));
        });
      }
      const finish = () => { if (generation === state.generation && state.desired === open) settle(state); };
      animation.onfinish = finish;
      state.timer = window.setTimeout(finish, duration + 80);
    } catch (_) { settle(state); }
  }
  function closeDialog(dialog, instant = false, restoreFocus = true) {
    const state = states.get(dialog);
    if (!state) { if (dialog?.open) dialog.close(); return; }
    if (!dialog.open) { cleanup(state, restoreFocus); return; }
    if (!state.desired && !instant) return;
    state.desired = false;
    if (instant) settle(state, restoreFocus); else transition(state, false);
  }

  function openDialog(dialog, opener) {
    if (!dialog || typeof dialog.showModal !== 'function') return false;
    initialize();
    const state = states.get(dialog); if (!state) return false;
    document.querySelectorAll('.vc-demo-part-dialog[open]').forEach(other => { if (other !== dialog) closeDialog(other, true, false); });
    const fresh = !dialog.open;
    openers.set(dialog, opener);
    try { if (fresh) dialog.showModal(); } catch (_) { return false; }
    state.session = true;
    opener.setAttribute('aria-expanded', 'true');
    syncLock();
    if (fresh || !state.desired) transition(state, true, fresh);
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
    if (menu) {
      event.preventDefault();
      const dialog = document.getElementById(menu.getAttribute('aria-controls'));
      if (dialog?.open && states.get(dialog)?.desired) closeDialog(dialog); else openDialog(dialog, menu);
      return;
    }
    const close = event.target.closest('[data-vc-demo-close]');
    if (close) { closeDialog(close.closest('dialog')); return; }
    const link = event.target.closest('.vc-demo-menu-dialog a[href]');
    // Real link navigation never waits for an exit animation.
    if (link) closeDialog(link.closest('dialog'), true, false);
    document.querySelectorAll('.vc-demo-desktop-nav details[open],.vc-demo-secondary details[open]').forEach(details => {
      if (!details.contains(event.target)) details.open = false;
    });
  });

  function initialize(root = document) {
    root.querySelectorAll('.vc-demo-part-dialog').forEach(dialog => {
      if (states.has(dialog)) return;
      // A builder may temporarily detach then reinsert the same header node.
      // Reuse its native listeners without retaining detached nodes in the Map.
      if (knownStates.has(dialog)) { states.set(dialog, knownStates.get(dialog)); return; }
      dialog.dataset.vcDemoReady = 'true';
      const state = {dialog, desired: dialog.open, session: false, animations: [], timer: 0, generation: 0};
      states.set(dialog, state); knownStates.set(dialog, state);
      dialog.addEventListener('close', () => {
        // A queued native close event from an old session cannot close a
        // rapidly reopened dialog or move focus back to the old opener.
        if (!dialog.open) cleanup(state);
      });
      dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(dialog); });
      dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeDialog(dialog);
      });
      // Native modal focus containment stays browser-owned; cancel adds exit.
    });
  }
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const group = document.activeElement?.closest('.vc-demo-nav-group[open]');
    if (group) { event.preventDefault(); group.open = false; group.querySelector('summary')?.focus(); }
  });
  const mobile = window.matchMedia('(max-width:767px)');
  mobile.addEventListener('change', () => { if (!mobile.matches) document.querySelectorAll('.vc-demo-menu-dialog[open]').forEach(dialog => closeDialog(dialog, true)); });
  const settleAll = (restoreFocus = !document.hidden) => states.forEach(state => settle(state, restoreFocus));
  reduceMotion.addEventListener?.('change', () => { if (reduceMotion.matches) settleAll(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) settleAll(); });
  initialize();
  // The editor replaces block previews without a page navigation.
  const observer = new MutationObserver(records => {
    states.forEach((state, dialog) => {
      if (!dialog.isConnected) { state.desired = false; settle(state, false); states.delete(dialog); }
    });
    if (records.some(record => Array.from(record.addedNodes).some(node => node.nodeType === 1 && (node.matches?.('[data-vc-demo-header]') || node.querySelector?.('[data-vc-demo-header]'))))) initialize();
    syncLock();
  });
  observer.observe(document.body, { childList: true, subtree: true });
  window.addEventListener('pagehide', event => {
    if (event.persisted) settleAll(false);
    else { states.forEach(state => { state.desired = false; settle(state, false); }); observer.disconnect(); states.clear(); }
  });
})();
