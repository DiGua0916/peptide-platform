(() => {
  'use strict';
  const config = window.VialCoveCart;
  const dialog = document.getElementById('vc-cart-drawer');
  if (!config || !dialog || typeof dialog.showModal !== 'function') return;
  const desktop = window.matchMedia('(min-width: 768px)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const items = dialog.querySelector('.vc-cart-items');
  const scroll = dialog.querySelector('.vc-cart-scroll');
  const feedback = dialog.querySelector('.vc-cart-feedback');
  const bottom = dialog.querySelector('.vc-cart-bottom');
  const closeButton = dialog.querySelector('.vc-cart-close');
  const checkout = dialog.querySelector('.vc-cart-checkout');
  let cart = null;
  let nonce = '';
  let busy = false;
  let opener = null;
  let closeTimer;
  let oldPadding = '';
  let cartEpoch = 0;
  // Observations only; an optional collector may listen. Woo owns transaction facts.
  const observeAction = (type, data = {}) => document.dispatchEvent(new CustomEvent('vc:visitor-action', {detail: {type, data: {source: 'drawer', ...data}}}));

  const node = (tag, className, text) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  };
  const plain = value => {
    const text = document.createElement('textarea');
    text.innerHTML = String(value || '');
    return text.value.replace(/<[^>]*>/g, '');
  };
  const safeUrl = value => {
    try {
      const url = new URL(value, location.href);
      return /^https?:$/.test(url.protocol) && url.origin === location.origin ? url.href : '';
    } catch { return ''; }
  };
  const money = (value, format) => {
    const amount = Number(value);
    const digits = Number(format.currency_minor_unit);
    if (!Number.isSafeInteger(amount) || amount < 0 || !Number.isInteger(digits) || digits < 0 || digits > 6) return '';
    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'en-US', {
        style: 'currency', currency: format.currency_code,
        minimumFractionDigits: digits, maximumFractionDigits: digits
      }).format(amount / 10 ** digits);
    } catch { return ''; }
  };
  const taxAmount = (totals, amount, tax) => String(Number(totals[amount] || 0) + (config.inclusiveTax ? Number(totals[tax] || 0) : 0));
  const setMessage = (message = '', error = false, retry = false) => {
    feedback.replaceChildren(document.createTextNode(message));
    feedback.classList.toggle('is-error', error);
    if (retry) {
      const button = node('button', '', 'Try again');
      button.type = 'button';
      button.addEventListener('click', load);
      feedback.append(button);
    }
  };
  const setBusy = value => {
    busy = value;
    scroll.setAttribute('aria-busy', String(value));
    dialog.querySelectorAll('[data-cart-action], .vc-cart-quantity input').forEach(el => {
      el.disabled = value || el.dataset.limit === 'true';
    });
    checkout.setAttribute('aria-disabled', String(value));
    checkout.tabIndex = value ? -1 : 0;
  };
  const loading = () => {
    if (cart) return;
    const wrapper = node('div', 'vc-cart-loading');
    wrapper.setAttribute('aria-hidden', 'true');
    for (let i = 0; i < 3; i++) {
      const row = node('div', 'vc-cart-skeleton');
      row.append(node('span'), node('div'));
      wrapper.append(row);
    }
    items.replaceChildren(wrapper);
  };

  async function request(endpoint = '', payload) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    const headers = { Accept: 'application/json' };
    if (config.wpNonce) headers['X-WP-Nonce'] = config.wpNonce;
    if (payload) { headers['Content-Type'] = 'application/json'; headers.Nonce = nonce; }
    try {
      const response = await fetch(config.apiUrl.replace(/\/$/, '') + endpoint, {
        method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
        headers, signal: controller.signal, ...(payload ? { body: JSON.stringify(payload) } : {})
      });
      const freshNonce = response.headers.get('Nonce');
      if (freshNonce) nonce = freshNonce;
      const data = await response.json();
      if (!response.ok) {
        const error = new Error(plain(data.message) || 'Your cart could not be updated. Please try again.');
        error.code = data.code;
        throw error;
      }
      if (!Array.isArray(data.items) || !data.totals) throw new Error('Your cart could not be loaded. Please try again.');
      return data;
    } catch (error) {
      if (error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError) {
        throw new Error('Connection interrupted. Check your connection or open the full cart.');
      }
      throw error;
    } finally { clearTimeout(timeout); }
  }

  function render(data) {
    cart = data;
    const count = Number(data.items_count);
    dialog.querySelector('.vc-drawer-count').textContent = `${count} ${count === 1 ? 'item' : 'items'}`;
    document.querySelectorAll('.vc-cart-count').forEach(el => { el.textContent = String(count); });
    items.replaceChildren();
    bottom.hidden = !data.items.length;
    if (!data.items.length) {
      const empty = node('div', 'vc-cart-empty');
      const bag = document.querySelector('.cart-link svg');
      if (bag) empty.append(bag.cloneNode(true));
      const link = node('a', 'button', 'Explore peptides');
      link.href = config.shopUrl;
      empty.append(node('h3', '', 'Your cart is empty.'), node('p', '', 'Explore the collection and choose a specification to get started.'), link);
      items.append(empty);
      return;
    }
    for (const item of data.items) {
      const row = node('article', 'vc-cart-item');
      row.dataset.key = item.key;
      const photo = node('a', 'vc-cart-photo');
      photo.href = safeUrl(item.permalink) || config.shopUrl;
      photo.tabIndex = -1;
      photo.setAttribute('aria-hidden', 'true');
      const source = item.images?.[0];
      if (source && safeUrl(source.thumbnail || source.src)) {
        const img = node('img');
        img.src = safeUrl(source.thumbnail || source.src);
        img.alt = '';
        img.width = 98; img.height = 98;
        photo.append(img);
      }
      const body = node('div', 'vc-cart-item-body');
      const heading = node('div', 'vc-cart-item-heading');
      const title = node('h3');
      const productLink = node('a', '', plain(item.name));
      productLink.href = photo.href;
      title.append(productLink);
      const remove = node('button', 'vc-cart-remove', '×');
      remove.type = 'button'; remove.dataset.cartAction = 'remove';
      remove.setAttribute('aria-label', `Remove ${plain(item.name)}`);
      heading.append(title, remove);
      body.append(heading);
      const variation = (item.variation || []).map(v => plain(v.value)).filter(Boolean).join(' / ');
      if (variation) body.append(node('p', 'vc-cart-variant', variation));
      const actions = node('div', 'vc-cart-item-actions');
      const quantity = node('div', 'vc-cart-quantity');
      const limits = item.quantity_limits || {};
      const min = Number(limits.minimum) || 1;
      const max = Number.isFinite(Number(limits.maximum)) ? Number(limits.maximum) : 9999;
      const step = Number(limits.multiple_of) || 1;
      const input = node('input');
      input.type = 'number'; input.min = String(min); input.max = String(max); input.step = String(step);
      input.value = String(item.quantity); input.inputMode = 'numeric'; input.required = true;
      input.dataset.cartAction = 'quantity'; input.dataset.limit = String(limits.editable === false);
      input.setAttribute('aria-label', `Quantity for ${plain(item.name)}`);
      const minus = node('button', '', '−');
      minus.type = 'button'; minus.dataset.cartAction = 'minus';
      minus.dataset.limit = String(item.quantity <= min || limits.editable === false);
      minus.setAttribute('aria-label', `Decrease quantity of ${plain(item.name)}`);
      const plus = node('button', '', '+');
      plus.type = 'button'; plus.dataset.cartAction = 'plus';
      plus.dataset.limit = String(item.quantity >= max || limits.editable === false);
      plus.setAttribute('aria-label', `Increase quantity of ${plain(item.name)}`);
      quantity.append(minus, input, plus);
      const prices = node('div', 'vc-cart-item-price');
      const pricing = item.extensions?.peptide_core?.pricing;
      prices.append(node('strong', '', money(pricing?.sale_subtotal ?? taxAmount(item.totals, 'line_subtotal', 'line_subtotal_tax'), item.prices)));
      if (pricing && Number(pricing.sale_savings) > 0) {
        const regular = node('del', '', money(pricing.regular_subtotal, item.prices));
        regular.setAttribute('aria-label', (pricing.compare_is_demo ? 'Demo comparison price ' : 'Regular price ') + regular.textContent);
        prices.append(regular);
      }
      actions.append(quantity, prices);
      body.append(actions); row.append(photo, body); items.append(row);
    }
    const summary = data.extensions?.peptide_core?.sale_savings;
    dialog.querySelector('.vc-cart-total').textContent = money(summary?.sale_subtotal ?? taxAmount(data.totals, 'total_items', 'total_items_tax'), summary || data.totals);
    const regular = dialog.querySelector('.vc-cart-regular');
    const savings = dialog.querySelector('.vc-cart-saving');
    const hasSavings = summary && Number(summary.sale_savings) > 0;
    regular.hidden = !hasSavings; savings.hidden = !hasSavings;
    regular.textContent = hasSavings ? money(summary.regular_subtotal, summary) : '';
    regular.setAttribute('aria-label', (summary?.compare_is_demo ? 'Demo comparison subtotal ' : 'Regular subtotal ') + regular.textContent);
    savings.querySelector('strong').textContent = hasSavings ? money(summary.sale_savings, summary) : '';
    dialog.querySelector('.vc-cart-pricing-note').hidden = !(hasSavings && summary.compare_is_demo);
  }

  const cartShape = value => value && Array.isArray(value.items) && value.totals && typeof value.totals === 'object';
  const recognized = detail => detail && ['drawer', 'showcase'].includes(detail.source);
  const cartEvent = (data, mutation, stale = false) => document.dispatchEvent(new CustomEvent('vc:cart-updated', {detail: {source: 'drawer', cart: data, mutation, stale}}));
  document.addEventListener('vc:cart-mutation-start', event => { if (recognized(event.detail)) cartEpoch++; });
  document.addEventListener('vc:cart-updated', event => {
    const detail = event.detail;
    if (!recognized(detail)) return;
    if (detail.mutation === true) cartEpoch++;
    if (detail.source === 'drawer' || detail.stale || !cartShape(detail.cart)) return;
    render(detail.cart); setBusy(busy);
    if (!busy) setMessage();
  });
  async function synchronize() {
    const epoch = cartEpoch;
    const data = await request();
    if (epoch !== cartEpoch) return false;
    render(data); cartEvent(data, false);
    return true;
  }

  async function load() {
    if (busy) return;
    loading(); setMessage('Loading your cart…'); setBusy(true);
    const epoch = cartEpoch;
    try {
      const data = await request();
      if (epoch !== cartEpoch) { setMessage(cart ? 'Your cart changed. Showing the latest available cart.' : 'Your cart is changing. Please check again.', false, !cart); return; }
      render(data);
      cartEvent(data, false);
      setMessage((data.errors || []).map(e => plain(e.message)).filter(Boolean).join(' '), Boolean(data.errors?.length));
    } catch (error) {
      if (epoch !== cartEpoch && cart) { setMessage('Your cart changed. Showing the latest available cart.'); return; }
      setMessage(error.message, true, true);
      if (!cart) {
        items.replaceChildren();
        const fallback = node('a', 'vc-cart-full', 'Open full cart');
        fallback.href = config.cartUrl; items.append(fallback);
      }
    } finally { setBusy(false); }
  }

  async function changeItem(key, action, quantity) {
    if (busy) return;
    const itemId = cart?.items.find(item => item.key === key)?.id;
    observeAction('cart_change_attempt', {action: action === 'remove' ? 'remove' : 'quantity', item_id: itemId, quantity: action === 'remove' ? 0 : quantity});
    const focusAction = document.activeElement?.dataset.cartAction;
    const oldScroll = scroll.scrollTop;
    setBusy(true); setMessage('Updating your cart…');
    const endpoint = action === 'remove' ? '/remove-item' : '/update-item';
    const payload = action === 'remove' ? { key } : { key, quantity };
    let mutationEpoch = cartEpoch;
    let sent = false;
    const mutate = async () => {
      document.dispatchEvent(new CustomEvent('vc:cart-mutation-start', {detail: {source: 'drawer'}}));
      mutationEpoch = cartEpoch; sent = true;
      return request(endpoint, payload);
    };
    try {
      if (!nonce) await request();
      let data;
      try { data = await mutate(); }
      catch (error) {
        if (!['woocommerce_rest_invalid_nonce', 'woocommerce_rest_missing_nonce'].includes(error.code)) throw error;
        await request();
        data = await mutate();
      }
      const stale = mutationEpoch !== cartEpoch;
      if (stale) {
        cartEvent(data, true, true);
        const synced = await synchronize();
        setMessage(synced ? 'Your cart was updated and synchronized.' : 'Your cart changed again. Check it before continuing.', !synced, !synced);
      } else {
        render(data); cartEvent(data, true);
        setMessage(action === 'remove' ? 'Item removed.' : 'Quantity updated.');
      }
      observeAction('cart_change_response', {action: action === 'remove' ? 'remove' : 'quantity', item_id: itemId, quantity: action === 'remove' ? 0 : data.items.find(item => item.key === key)?.quantity});
      // Existing classic Woo widgets, if present, may refresh their own fragments.
      if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
    } catch (error) {
      // Never retry an uncertain mutation; refresh authoritative state instead.
      if (sent) cartEvent(null, true, true);
      try { await synchronize(); } catch { /* The full cart link remains available. */ }
      setMessage(error.message, true, true);
    } finally {
      setBusy(false); scroll.scrollTop = oldScroll;
      if (dialog.open) {
        const row = Array.from(items.querySelectorAll('.vc-cart-item')).find(el => el.dataset.key === key);
        const control = row && Array.from(row.querySelectorAll('[data-cart-action]')).find(el => el.dataset.cartAction === focusAction && !el.disabled);
        (control || row?.querySelector('h3 a') || closeButton).focus();
      }
    }
  }

  items.addEventListener('click', event => {
    const button = event.target.closest('button[data-cart-action]');
    if (!button || button.disabled || busy) return;
    const row = button.closest('.vc-cart-item');
    const action = button.dataset.cartAction;
    const item = cart.items.find(i => i.key === row.dataset.key);
    if (!item) return;
    const step = Number(item.quantity_limits?.multiple_of) || 1;
    changeItem(item.key, action, Number(item.quantity) + (action === 'plus' ? step : -step));
  });
  items.addEventListener('change', event => {
    const input = event.target.closest('input[data-cart-action=quantity]');
    if (!input || busy) return;
    const key = input.closest('.vc-cart-item').dataset.key;
    const item = cart.items.find(i => i.key === key);
    const value = Number(input.value);
    if (!Number.isInteger(value) || !input.reportValidity()) { input.value = String(item.quantity); return; }
    if (value !== item.quantity) changeItem(key, 'quantity', value);
  });
  checkout.addEventListener('click', event => { if (busy) event.preventDefault(); });

  function finishClose() {
    clearTimeout(closeTimer);
    dialog.classList.remove('is-open');
    if (dialog.open) { dialog.close(); observeAction('cart_close'); }
    document.documentElement.classList.remove('vc-cart-open');
    document.documentElement.style.paddingRight = oldPadding;
    document.querySelectorAll('.cart-link[aria-controls="vc-cart-drawer"]').forEach(el => el.setAttribute('aria-expanded', 'false'));
    if (opener?.isConnected) opener.focus();
  }
  function close(immediate = false) {
    if (!dialog.open) return;
    dialog.classList.remove('is-open');
    clearTimeout(closeTimer);
    if (immediate || reducedMotion.matches) finishClose();
    else closeTimer = setTimeout(finishClose, 380);
  }
  function open(trigger) {
    clearTimeout(closeTimer);
    opener = trigger;
    if (!dialog.open) {
      oldPadding = document.documentElement.style.paddingRight;
      const gap = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
      if (gap) document.documentElement.style.paddingRight = `${gap}px`;
      document.documentElement.classList.add('vc-cart-open');
      dialog.showModal();
      observeAction('cart_open');
    }
    trigger.setAttribute('aria-expanded', 'true');
    dialog.getBoundingClientRect();
    requestAnimationFrame(() => { if (dialog.open) dialog.classList.add('is-open'); });
    closeButton.focus(); load();
  }
  function updateMode() {
    document.querySelectorAll('.cart-link').forEach(link => {
      if (desktop.matches) {
        link.setAttribute('aria-haspopup', 'dialog'); link.setAttribute('aria-controls', dialog.id);
        link.setAttribute('aria-expanded', String(dialog.open));
      } else {
        ['aria-haspopup', 'aria-controls', 'aria-expanded'].forEach(attr => link.removeAttribute(attr));
      }
    });
    if (!desktop.matches) close(true);
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('.cart-link');
    if (!link || !desktop.matches || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault(); open(link);
  });
  closeButton.addEventListener('click', () => close());
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
  });
  dialog.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const controls = Array.from(dialog.querySelectorAll('a[href], button, input, [tabindex="0"]')).filter(el => !el.disabled && el.tabIndex !== -1 && !el.closest('[hidden]'));
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
  desktop.addEventListener('change', updateMode);
  window.addEventListener('pageshow', () => { if (dialog.open) load(); });
  if (window.jQuery) window.jQuery(document.body).on('added_to_cart.vialcoveDrawer removed_from_cart.vialcoveDrawer', () => { if (dialog.open) load(); });
  updateMode();
})();
