/* O17 only: UI lifetime and truthful feedback; no alternate request or subscription state model. */
(() => {
  'use strict';
  const pending = new WeakMap(), active = new Set();
  let observer = null;
  const current = state => pending.get(state.form) === state && state.form.isConnected &&
    state.button.isConnected && state.form.querySelector('[type=submit]') === state.button &&
    state.form.dataset.endpoint === state.endpoint && state.form.querySelector('[name=context]')?.value === state.context &&
    state.form.querySelector('[name=other_contact]') === state.contact;
  const finish = state => {
    clearTimeout(state.timer); state.timer = null; active.delete(state);
    if (pending.get(state.form) === state) {
      pending.delete(state.form); state.button.disabled = state.disabled; state.form.removeAttribute('aria-busy');
    }
    if (!active.size && observer) { observer.disconnect(); observer = null; }
  };
  const retire = state => {
    if (state.retired) return;
    state.retired = true; finish(state);
    try { state.controller.abort(); } catch (_) { /* UI retirement does not depend on transport cancellation. */ }
  };
  const watch = () => {
    if (observer || typeof MutationObserver !== 'function') return;
    observer = new MutationObserver(() => { for (const state of active) if (!current(state)) retire(state); });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  };
  window.addEventListener('pagehide', () => { for (const state of Array.from(active)) retire(state); });
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-pc-subscribe-reference]');
    if (!form || !window.fetch || typeof AbortController !== 'function') return;
    event.preventDefault();
    if (pending.has(form)) return;
    const button = form.querySelector('[type=submit]'), feedback = form.querySelector('[role=status]');
    const email = form.querySelector('[name=email]'), consent = form.querySelector('[name=consent]'), contact = form.querySelector('[name=other_contact]');
    if (!button || button.disabled || !feedback || !email || !consent) return;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    let endpoint;
    try { endpoint = new URL(form.dataset.endpoint, window.location.href); if (endpoint.origin !== window.location.origin || endpoint.username || endpoint.password || endpoint.hash) throw new Error('invalid_endpoint'); }
    catch (_) { feedback.textContent = form.dataset.error; feedback.dataset.state = 'error'; return; }
    const state = { form, button, feedback, controller: new AbortController(), endpoint: form.dataset.endpoint,
      context: form.querySelector('[name=context]')?.value, disabled: button.disabled, email: email.value, contact, contactValue: contact?.value,
      supplement: form.dataset.supplement || '', timedOut: false, retired: false };
    const body = new FormData(form);
    pending.set(form, state); active.add(state); watch();
    button.disabled = true; form.setAttribute('aria-busy', 'true'); feedback.textContent = ''; delete feedback.dataset.state;
    state.timer = setTimeout(() => {
      if (current(state) && !state.retired) {
        state.timedOut = true;
        feedback.textContent = form.dataset.timeout || form.dataset.error; feedback.dataset.state = 'error';
      }
      retire(state);
    }, 20000);
    try {
      const response = await fetch(endpoint.href, { method: 'POST', credentials: 'same-origin', body,
        headers: { Accept: 'application/json', 'X-WP-Nonce': form.dataset.restNonce }, signal: state.controller.signal });
      const result = await response.json();
      if (!current(state) || state.retired) return;
      const message = result && !Array.isArray(result) && typeof result.message === 'string' && result.message.trim() !== '' ? result.message : '';
      const ok = response.ok && message !== '';
      feedback.textContent = message || form.dataset.error; feedback.dataset.state = ok ? 'success' : 'error';
      if (ok) {
        if (email.value === state.email) { email.value = ''; consent.checked = false; if (contact && contact.value === state.contactValue) contact.value = ''; }
        if (state.supplement) { const extra = document.createElement('span'); extra.className = 'pc-subscribe-supplement'; extra.textContent = state.supplement; feedback.appendChild(extra); }
      }
    } catch (_) {
      if (current(state) && !state.retired) {
        feedback.textContent = state.timedOut ? (form.dataset.timeout || form.dataset.error) : form.dataset.error;
        feedback.dataset.state = 'error';
      }
    } finally { finish(state); }
  });
})();

(() => {
  'use strict';
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-pc-subscribe]');
    if (form && form.hasAttribute('data-pc-subscribe-reference')) return;
    if (!form || !window.fetch) return;
    event.preventDefault();
    const button = form.querySelector('[type=submit]');
    const feedback = form.querySelector('[role=status]');
    button.disabled = true; form.setAttribute('aria-busy', 'true'); feedback.textContent = '';
    try {
      const response = await fetch(form.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { Accept: 'application/json', 'X-WP-Nonce': form.dataset.restNonce } });
      const result = await response.json();
      feedback.textContent = result.message || form.dataset.error;
      if (response.ok) { form.querySelector('[name=email]').value = ''; form.querySelector('[name=consent]').checked = false; }
    } catch (_) { feedback.textContent = form.dataset.error; }
    finally { button.disabled = false; form.removeAttribute('aria-busy'); }
  });
})();
