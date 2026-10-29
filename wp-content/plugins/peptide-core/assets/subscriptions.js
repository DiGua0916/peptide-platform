(() => {
  'use strict';
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-pc-subscribe]');
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
