(() => {
  'use strict';
  // WooCommerce Blocks owns the cart; mirror its live count in the header.
  const syncCart = () => {
    if (!document.querySelector('.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout')) return;
    const data = window.wp?.data;
    const store = window.wc?.wcBlocksData?.cartStore;
    if (!data || !store) return;
    let lastCount;
    const update = () => {
      const selector = data.select(store);
      if (!selector?.hasFinishedResolution('getCartData')) return;
      const count = selector.getCartData()?.itemsCount;
      if (!Number.isInteger(count) || count === lastCount) return;
      lastCount = count;
      document.querySelectorAll('.vc-cart-count').forEach(el => { el.textContent = String(count); });
    };
    update();
    const unsubscribe = data.subscribe(update, store);
    window.addEventListener('pagehide', event => { if (!event.persisted) unsubscribe(); });
  };
  document.readyState === 'complete' ? syncCart() : window.addEventListener('load', syncCart, { once: true });

  const form = document.querySelector('#batch-lookup');
  if (!form || typeof VialCove === 'undefined') return;
  const input = document.querySelector('#batch-number');
  const status = document.querySelector('#lookup-status');
  const result = document.querySelector('#lookup-result');
  const button = form.querySelector('button[type=submit]');
  const stateLabels = { available: 'Report available', restricted: 'Report access required', pending: 'Report pending', none: 'No published report' };
  let activeRequest;
  let sequence = 0;
  const node = (tag, className, text) => { const el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el; };
  // Report links are accepted only from the same WordPress origin.
  const reportUrl = value => {
    if (typeof value !== 'string' || !value) return null;
    try { const url = new URL(value, location.href); return /^https?:$/.test(url.protocol) && url.origin === location.origin ? url.href : null; } catch { return null; }
  };
  const renderBatch = batch => {
    const card = node('section', 'batch-card');
    const heading = node('div', 'batch-heading');
    const title = node('div');
    title.append(node('span', 'eyebrow', batch.is_demo ? 'BATCH · DEMO RECORD' : 'BATCH'), node('h3', '', batch.batch_no));
    heading.append(title, node('span', 'status-pill', stateLabels[batch.report_state] || 'Report data unavailable'));
    card.append(heading, node('p', '', batch.product?.name || 'Product details unavailable'));
    if (batch.manufactured_on) card.append(node('p', 'muted', `Manufactured: ${batch.manufactured_on}`));
    if (batch.retest_or_expiry_on) card.append(node('p', 'muted', `Retest / expiry: ${batch.retest_or_expiry_on}`));
    if (batch.origin_note) card.append(node('p', 'muted', batch.origin_note));
    if (batch.is_demo) card.append(node('p', 'muted', 'Synthetic demonstration batch. This is not a real test record.'));
    for (const report of batch.reports || []) {
      const row = node('div', 'report-row');
      const name = node('div', 'report-name');
      const words = node('div');
      words.append(node('strong', '', report.title), node('span', '', `Version ${report.version}${report.is_demo ? ' · Demonstration report' : ''}`));
      name.append(words); row.append(name);
      const url = report.can_download ? reportUrl(report.download_url) : null;
      if (url) { const link = node('a', 'text-link', 'Download report →'); link.href = url; row.append(link); }
      else if (report.locked_reason === 'login_required') { const link = node('a', 'text-link', 'Sign in to view →'); link.href = VialCove.accountUrl; row.append(link); }
      else { row.append(node('span', 'muted', report.can_download ? 'Download unavailable. Please try again later.' : 'Access requires approval')); }
      card.append(row);
    }
    if (!batch.reports?.length) card.append(node('p', 'muted', batch.report_state === 'pending' ? 'The report is being prepared. Check back for the published document.' : 'No report is currently available.'));
    result.replaceChildren(card);
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    input.value = input.value.trim().toUpperCase();
    if (!form.reportValidity()) return;
    activeRequest?.abort();
    const controller = new AbortController();
    activeRequest = controller;
    const requestId = ++sequence;
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 15000);
    result.replaceChildren(); result.setAttribute('aria-busy', 'true');
    status.classList.remove('is-error'); status.textContent = 'Finding your batch…';
    button.disabled = true;
    try {
      const url = new URL(VialCove.lookupUrl, location.href);
      url.searchParams.set('batch_no', input.value);
      const headers = { Accept: 'application/json' };
      if (VialCove.nonce) headers['X-WP-Nonce'] = VialCove.nonce;
      const response = await fetch(url, { credentials: 'same-origin', headers, signal: controller.signal, cache: 'no-store' });
      let data;
      try { data = await response.json(); } catch { throw new Error('The report service returned an unexpected response. Please try again.'); }
      if (!response.ok) {
        const messages = {
          400: 'Please enter a valid batch number: 3–40 letters, numbers or hyphens.',
          401: 'Please sign in to view this record.',
          403: 'Your session or access has changed. Refresh the page, sign in and try again.',
          404: 'No public batch matches that number. Check the code and try again.',
          410: 'This report is no longer available.',
          429: `Too many lookups. Try again in ${Number.isFinite(Number(data.data?.retry_after)) ? Math.max(1, Math.ceil(Number(data.data.retry_after) / 60)) : 'a few'} minute(s).`,
        };
        throw new Error(messages[response.status] || 'The report service is temporarily unavailable. Please try again later.');
      }
      if (!data.batch_no || !Array.isArray(data.reports)) throw new Error('The batch record could not be read. Please try again.');
      if (requestId !== sequence) return;
      renderBatch(data); status.textContent = 'Batch found. Available documentation is shown below.';
    } catch (error) {
      if (requestId !== sequence || (error.name === 'AbortError' && !timedOut)) return;
      status.classList.add('is-error');
      status.textContent = timedOut ? 'The lookup took too long. Please try again.' : error instanceof TypeError ? 'Unable to connect. Check your connection and try again.' : error.message;
    } finally {
      clearTimeout(timeout);
      if (requestId === sequence) { result.setAttribute('aria-busy', 'false'); button.disabled = false; }
    }
  });
  document.querySelectorAll('[data-demo-batch]').forEach(example => example.addEventListener('click', () => { input.value = example.dataset.demoBatch; form.requestSubmit(); }));
})();
