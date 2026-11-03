/* Return from an explicitly confirmed Add operation; never submit, infer success or navigate checkout. */
(function (global) {
  'use strict';
  function createVcConfirmedPurchaseReturn(options = {}) {
    const win = options.window || global, doc = options.document || win?.document, dialog = options.dialog;
    const signal = options.signal, listeners = [];
    let active = null, timer = 0, timerSerial = 0, destroyed = false;
    let motion;
    try { motion = win?.matchMedia?.('(prefers-reduced-motion: reduce)'); } catch (_) { /* No motion capability means no effect wait. */ }
    function listen(target, type, callback) {
      if (typeof target?.addEventListener !== 'function') return;
      target.addEventListener(type, callback); listeners.push(() => target.removeEventListener(type, callback));
    }
    function cancel(ticket) {
      if (ticket !== undefined && active?.ticket !== ticket) return false;
      win?.clearTimeout(timer); timer = 0; timerSerial++; active = null; return true;
    }
    function current(operation) {
      if (destroyed || !operation || doc?.hidden || signal?.aborted) return false;
      try { return options.isCurrent?.(operation.context) === true; } catch (_) { return false; }
    }
    function schedule(operation, duration) {
      win.clearTimeout(timer);
      const serial = ++timerSerial;
      timer = win.setTimeout(() => {
        if (active !== operation || destroyed || serial !== timerSerial) return;
        timer = 0; active = null;
        if (!current(operation)) return;
        // Native success paint runs after added_to_cart. Always leave that stack
        // before invoking the caller's original Close button and history cleanup.
        try { options.close?.(operation.context); } catch (_) { /* Manual close remains available; never change the native cart result. */ }
      }, duration);
    }
    function begin(context = {}) {
      cancel();
      if (context.mode !== 'add') return null;
      const operation = { ticket: Object.freeze({}), context: Object.freeze({ ...context }), state: 'pending' };
      if (!current(operation)) return null;
      active = operation; return operation.ticket;
    }
    function confirm(ticket, result = {}) {
      const operation = active;
      if (!operation || ticket !== operation.ticket || operation.state !== 'pending') return false;
      if (result.confirmed !== true || result.stale !== false || !current(operation)) { cancel(ticket); return false; }
      const presentation = result.presentation;
      const visibleBurst = !!presentation && presentation.enabled !== false && presentation.burstEnabled !== false && motion && !motion.matches;
      // Match the existing feedback renderer's real particle lifetime, not its
      // longer success-label reset. Disabled/missing feedback never gains effects.
      const duration = visibleBurst ? Math.min(1000, Math.max(600, Number(presentation.burstDurationMs) || 850)) : 0;
      operation.state = 'confirmed'; schedule(operation, duration); return true;
    }
    function destroy() {
      if (destroyed) return;
      cancel(); destroyed = true; listeners.splice(0).forEach(remove => remove());
      if (motion?.removeEventListener) motion.removeEventListener('change', onMotion); else motion?.removeListener?.(onMotion);
    }
    function onMotion() { if (motion?.matches && active?.state === 'confirmed') schedule(active, 0); }
    listen(dialog, 'close', () => { if (!dialog.open) cancel(); });
    listen(dialog, 'cancel', () => cancel());
    listen(doc, 'vc:product-restore-cancel', () => cancel());
    listen(doc, 'visibilitychange', () => { if (doc.hidden) cancel(); });
    listen(doc, 'vc:cart-mutation-start', event => {
      // The request's own start precedes confirmation. Only a later operation
      // invalidates a scheduled return, regardless of which native cart surface owns it.
      if (active?.state === 'confirmed' && ['showcase', 'drawer', 'live-cart'].includes(event.detail?.source)) cancel();
    });
    listen(win, 'pagehide', () => cancel());
    listen(signal, 'abort', destroy);
    if (motion?.addEventListener) motion.addEventListener('change', onMotion); else motion?.addListener?.(onMotion);
    if (signal?.aborted) destroy();
    return { begin, confirm, cancel, destroy, get state() { return destroyed ? 'destroyed' : active?.state || 'idle'; } };
  }
  if (typeof module === 'object' && module.exports) module.exports = { createVcConfirmedPurchaseReturn };
  if (global) global.createVcConfirmedPurchaseReturn = createVcConfirmedPurchaseReturn;
})(typeof window === 'undefined' ? null : window);
