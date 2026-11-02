/* Presentation only. The supplied adapter must wait for confirmed cart results. */
'use strict';
(() => {
  const CANCELLED = Symbol('cancelled');
  const TIMEOUT = Symbol('timeout');
  const ns = 'http://www.w3.org/2000/svg';

  window.createLiveCartFeedback = function createAddFeedback(options = {}) {
    const buttons = [...new Set(options.buttons || [])].filter(Boolean);
    const counters = [...new Set(options.bagCountElements || [])].filter(Boolean);
    const announce = options.announce || null;
    const timeoutMs = Math.max(100, Number(options.timeoutMs) || 12000);
    const successMs = Number.isFinite(Number(options.successMs)) ? Math.max(0, Number(options.successMs)) : 1400;
    const burstEnabled = options.burstEnabled !== false;
    const bagPulseEnabled = options.bagPulseEnabled !== false;
    const burstDurationMs = Math.min(1000, Math.max(600, Number(options.burstDurationMs) || 850));
    const snapshots = buttons.map(button => ({
      button,
      nodes: [...button.childNodes].map(node => node.cloneNode(true)),
      ariaLabel: button.getAttribute('aria-label'),
      ariaBusy: button.getAttribute('aria-busy'),
      disabled: button.disabled
    }));
    let state = 'idle';
    let destroyed = false;
    let version = 0;
    let pending = null;
    let cancelPending = null;
    let requestController = null;
    let timeoutTimer = 0;
    let resetTimer = 0;
    let countTimer = 0;
    let burstTimer = 0;
    let burst = null;
    let lastResult = null;

    function notifyState() {
      // Consumers may combine feedback state with current server/stock availability.
      try { options.onStateChange?.(state); }
      catch (error) { try { options.onCallbackError?.(error); } catch (_) { /* Presentation remains intact. */ } }
    }

    const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    function attribute(element, name, value) {
      if (value === null) element.removeAttribute(name);
      else element.setAttribute(name, value);
    }
    function clearVisualTimers() {
      clearTimeout(resetTimer);
      clearTimeout(countTimer);
      resetTimer = countTimer = 0;
      counters.forEach(counter => counter.classList.remove('add-feedback-count'));
      clearBurst();
    }
    function clearBurst() {
      clearTimeout(burstTimer);
      burstTimer = 0;
      burst?.remove();
      burst = null;
    }
    function showBurst(triggerButton, confirmedExternal = false) {
      clearBurst();
      if (!burstEnabled || document.hidden || reducedMotion() || !triggerButton || (!confirmedExternal && !buttons.includes(triggerButton)) || triggerButton.isConnected === false) return;
      const rect = triggerButton.getBoundingClientRect();
      const width = window.innerWidth || document.documentElement.clientWidth;
      const height = window.innerHeight || document.documentElement.clientHeight;
      if (rect.width < 1 || rect.height < 1 || rect.bottom <= 0 || rect.top >= height || rect.right <= 0 || rect.left >= width) return;
      const host = triggerButton.closest('dialog[open]') || document.body;
      if (!host) return;
      const size = 104;
      const layer = document.createElement('span');
      layer.className = 'add-feedback-burst';
      layer.setAttribute('aria-hidden', 'true');
      layer.style.left = Math.max(0, Math.min(width - size, rect.right - size / 2 - 4)) + 'px';
      layer.style.top = Math.max(0, Math.min(height - size, rect.top - size / 2 + 8)) + 'px';
      layer.style.setProperty('--add-burst-duration', burstDurationMs + 'ms');
      // Fixed, viewport-clamped overlay beside the clicked button; no layout shift or page overflow.
      for (let i = 0; i < 10; i++) {
        const particle = document.createElement('i');
        const angle = i * Math.PI * 2 / 10 - Math.PI / 2;
        const distance = i % 2 ? 34 : 44;
        particle.style.setProperty('--burst-x', (Math.cos(angle) * distance).toFixed(2) + 'px');
        particle.style.setProperty('--burst-y', (Math.sin(angle) * distance).toFixed(2) + 'px');
        particle.style.setProperty('--burst-angle', (i * 36) + 'deg');
        layer.append(particle);
      }
      burst = layer;
      host.append(layer);
      burstTimer = setTimeout(clearBurst, burstDurationMs);
    }
    function resetButtons() {
      snapshots.forEach(({ button, nodes, ariaLabel, ariaBusy, disabled }) => {
        button.replaceChildren(...nodes.map(node => node.cloneNode(true)));
        button.disabled = disabled;
        attribute(button, 'aria-label', ariaLabel);
        attribute(button, 'aria-busy', ariaBusy);
        button.removeAttribute('data-add-state');
      });
    }
    function report(message, type) {
      if (!announce) return;
      announce.hidden = false;
      announce.textContent = message;
      announce.dataset.addFeedback = type;
      announce.setAttribute('role', 'status');
      announce.setAttribute('aria-live', 'polite');
      announce.setAttribute('aria-atomic', 'true');
    }
    function checkmark() {
      const icon = document.createElementNS(ns, 'svg');
      icon.setAttribute('viewBox', '0 0 24 24');
      icon.setAttribute('width', '24');
      icon.setAttribute('height', '24');
      icon.setAttribute('aria-hidden', 'true');
      icon.setAttribute('focusable', 'false');
      icon.setAttribute('class', 'add-feedback-check');
      const path = document.createElementNS(ns, 'path');
      path.setAttribute('d', 'M5 12.5 9.5 17 19 7');
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', 'currentColor');
      path.setAttribute('stroke-width', '2');
      path.setAttribute('stroke-linecap', 'round');
      path.setAttribute('stroke-linejoin', 'round');
      icon.append(path);
      return icon;
    }
    function setButtonState(next) {
      state = next;
      snapshots.forEach(({ button }) => {
        const content = document.createElement('span');
        content.className = 'add-feedback-content';
        const label = document.createElement('span');
        label.className = 'add-feedback-label';
      label.textContent = next === 'pending' ? (options.pendingText || 'Adding…') : (options.successText || 'Added');
        content.append(label);
        if (next === 'success') content.append(checkmark());
        button.replaceChildren(content);
        button.disabled = true;
        button.dataset.addState = next;
        button.setAttribute('aria-busy', String(next === 'pending'));
        button.setAttribute('aria-label', next === 'pending' ? 'Adding to bag' : 'Added to bag');
      });
      notifyState();
    }
    function leaveSuccess() {
      clearVisualTimers();
      if (state !== 'success') return;
      resetButtons();
      state = 'idle';
      notifyState();
    }
    function stopHiddenEffects() {
      if (!document.hidden) return;
      clearVisualTimers();
      if (state === 'success') leaveSuccess();
    }
    document.addEventListener('visibilitychange', stopHiddenEffects);
    document.addEventListener('scroll', clearBurst, true);
    document.addEventListener('close', clearBurst, true);

    function run({ submit, quantity = 1, productName = '', triggerButton = buttons[0] } = {}) {
      if (destroyed) return Promise.resolve({ ok: false, reason: 'destroyed' });
      if (pending) return pending;
      if (state === 'success') return Promise.resolve({ ok: false, reason: 'busy' });
      if (typeof submit !== 'function') return Promise.resolve({ ok: false, reason: 'missing-adapter' });
      if (snapshots.length && snapshots.every(({ button }) => button.disabled)) {
        return Promise.resolve({ ok: false, reason: 'disabled' });
      }
      const token = ++version;
      // Preserve permission/stock state as it stood immediately before this request.
      snapshots.forEach(snapshot => { snapshot.disabled = snapshot.button.disabled; });
      clearVisualTimers();
      if (announce) { announce.hidden = true; announce.textContent = ''; }
      setButtonState('pending');
      requestController = typeof AbortController === 'function' ? new AbortController() : null;
      const cancellation = new Promise(resolve => { cancelPending = () => resolve(CANCELLED); });
      const deadline = new Promise(resolve => { timeoutTimer = setTimeout(() => resolve(TIMEOUT), timeoutMs); });
      // Promise.resolve starts the adapter after `pending` has been assigned.
      const request = Promise.resolve().then(() => {
        if (destroyed || token !== version) return CANCELLED;
        return submit({ signal: requestController?.signal });
      });
      pending = (async () => {
        try {
          const result = await Promise.race([request, deadline, cancellation]);
          if (destroyed || token !== version || result === CANCELLED) return { ok: false, reason: 'destroyed' };
          if (result === TIMEOUT) {
            requestController?.abort();
            state = 'error';
            resetButtons();
            notifyState();
            report('Could not confirm the update. Check your bag before trying again.', 'error');
            lastResult = { ok: false, reason: 'timeout' };
            return lastResult;
          }
          if (result?.confirmed !== true || !Number.isSafeInteger(result.cartCount) || result.cartCount < 0) {
            state = 'error';
            resetButtons();
            notifyState();
            report('Could not confirm the update. Check your bag before trying again.', 'error');
            lastResult = { ok: false, reason: 'unconfirmed' };
            return lastResult;
          }
          // The server supplies the cart count. Never derive it from requested quantity.
          counters.forEach(counter => {
            counter.textContent = String(result.cartCount);
            counter.hidden = false;
            if (bagPulseEnabled && !document.hidden && !reducedMotion()) counter.classList.add('add-feedback-count');
          });
          const boxes = Number.isSafeInteger(quantity) && quantity > 0 ? quantity : 1;
          const name = String(productName || '').trim();
          report((name ? name + ' · ' : '') + boxes + (boxes === 1 ? ' box added to your bag.' : ' boxes added to your bag.'), 'success');
          setButtonState('success');
          if (document.hidden) leaveSuccess();
          else {
            // A decorative failure must never change a confirmed cart result.
            try { showBurst(triggerButton); } catch (_) { clearBurst(); }
            resetTimer = setTimeout(leaveSuccess, successMs);
            countTimer = setTimeout(() => counters.forEach(counter => counter.classList.remove('add-feedback-count')), 500);
          }
          lastResult = { ok: true, cartCount: result.cartCount, response: result };
          // A consumer's optional callback failure must not turn a confirmed cart update into an error.
          try { options.onSuccess?.(result); }
          catch (error) { try { options.onCallbackError?.(error); } catch (_) { /* Presentation stays successful. */ } }
          return lastResult;
        } catch (error) {
          if (destroyed || token !== version) return { ok: false, reason: 'destroyed' };
          state = 'error';
          resetButtons();
          notifyState();
          // Network rejection can occur after the store accepted the request.
          report('Could not confirm the update. Check your bag before trying again.', 'error');
          lastResult = { ok: false, reason: 'request-failed' };
          return lastResult;
        } finally {
          if (token === version) {
            clearTimeout(timeoutTimer);
            timeoutTimer = 0;
            pending = null;
            cancelPending = null;
            requestController = null;
          }
        }
      })();
      return pending;
    }
    function destroy() {
      if (destroyed) return;
      destroyed = true;
      ++version;
      cancelPending?.();
      requestController?.abort();
      clearTimeout(timeoutTimer);
      clearVisualTimers();
      resetButtons();
      document.removeEventListener('visibilitychange', stopHiddenEffects);
      document.removeEventListener('scroll', clearBurst, true);
      document.removeEventListener('close', clearBurst, true);
      state = 'destroyed';
    }
    // Native Woo adapters call this only after a confirmed added_to_cart event.
    // It does not submit, disable controls, change labels, or infer a cart count.
    function celebrate(triggerButton) {
      if (destroyed) return;
      try { showBurst(triggerButton, true); } catch (_) { clearBurst(); }
    }
    return { run, celebrate, destroy, get state() { return state; }, get lastResult() { return lastResult; } };
  };
})();
