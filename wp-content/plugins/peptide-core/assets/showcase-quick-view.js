(function ($) {
 'use strict';
 var dialog, body, status, closeButton, checkButton, acknowledge, cartLink, active = null, operation = null;
 var inlineViews = new Map(), pageActive = true;
 var views = new Map(), sequence = 0, nonce = '', mutationGeneration = 0, lastCart = null, warm = null;
 var nonceErrors = ['woocommerce_rest_missing_nonce', 'woocommerce_rest_invalid_nonce'];
 function plain(value) { return String(value || '').replace(/<[^>]*>/g, ' ').trim(); }
 function available() { return window.HTMLDialogElement && HTMLDialogElement.prototype.showModal && window.fetch && window.AbortController && $; }
 function sameOrigin(value) { var url = new URL(value, location.href); if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) { throw new Error('The product service is unavailable. Open the product page to continue.'); } return url.href; }
 function cartShape(value) { return value && Array.isArray(value.items) && value.totals && typeof value.totals === 'object'; }
 function blocked() { return operation && ['pending', 'checking', 'unknown'].indexOf(operation.state) >= 0; }
 function current(op) {
  if (op.view?.inline) { return pageActive && op.view.token === op.token && op.view.element.isConnected && !!op.view.element.getClientRects().length && op.view.id === op.parentId; }
  return active && dialog.open && active.token === op.token && active.id === op.parentId;
 }
 function ready(view) { var form = view.form; return !!form && (view.element.dataset.vcVariable !== '1' || Number(form.querySelector('[name="variation_id"]')?.value) > 0 && !form.querySelector('.single_add_to_cart_button.disabled')); }
 function paint() {
  inlineViews.forEach(paintInline);
  views.forEach(function (view) { if (view.form) { view.form.inert = !!blocked(); view.form.setAttribute('aria-busy', operation && operation.state === 'pending' ? 'true' : 'false'); }
   var buy = view.element.querySelector('[data-vc-quick-buy]'); if (buy) { buy.disabled = !!blocked() || !ready(view); }
  });
  if (!active) { return; }
  var show = operation && (current(operation) || blocked());
  status.textContent = show ? operation.message : '';
  checkButton.hidden = !(operation && operation.state === 'unknown' && !operation.synced);
  acknowledge.hidden = !(operation && operation.state === 'unknown' && operation.synced);
  cartLink.hidden = !show || !lastCart && operation.state !== 'unknown';
  cartLink.href = active.config.cartUrl;
 }
 function paintInline(view) {
  if (view.form) { view.form.inert = !!blocked(); view.form.setAttribute('aria-busy', operation && ['pending', 'checking'].indexOf(operation.state) >= 0 ? 'true' : 'false'); }
  var buy = view.element.querySelector('[data-vc-quick-buy]'); if (buy) { buy.disabled = !!blocked() || !ready(view); }
  var show = operation && (operation.view === view || blocked());
  view.status.textContent = show ? operation.message : '';
  view.feedback.dataset.state = show ? operation.state : '';
  view.check.hidden = !(operation && operation.state === 'unknown' && !operation.synced);
  view.acknowledge.hidden = !(operation && operation.state === 'unknown' && operation.synced);
  view.cart.hidden = !show || !lastCart && operation.state !== 'unknown';
 }
 function invalidateInlineIntent() {
  if (operation?.view?.inline && operation.state === 'pending') {
   operation.view.token = ++sequence;
   if (!operation.sent) { operation.cancelled = true; }
  }
 }
 function mountInline(root) {
  var key = root.dataset.vcInlinePurchase, id = root.dataset.vcProductId;
  if (!available() || !key || !/^[1-9]\d*$/.test(id) || inlineViews.has(key)) { return; }
  var template = root.querySelector(':scope > template[data-vc-inline-form]'), config;
  if (!template) { return; }
  try { config = JSON.parse(root.dataset.vcInlineConfig); ['cartApi', 'addApi', 'cartUrl', 'checkoutUrl'].forEach(function (name) { config[name] = sameOrigin(config[name]); }); } catch (_) { return; }
  var element = template.content.cloneNode(true).firstElementChild;
  if (!element || root.dataset.vcVariable === '1' && !$.fn.wc_variation_form) { return; }
  var feedback = root.querySelector('[data-vc-inline-feedback]');
  var view = {inline: true, id: id, key: key, token: ++sequence, config: config, element: root, form: element.querySelector('form.cart'), feedback: feedback,
   status: root.querySelector('[data-vc-inline-status]'), check: root.querySelector('[data-vc-inline-check]'), acknowledge: root.querySelector('[data-vc-inline-acknowledge]'), cart: root.querySelector('[data-vc-inline-cart]')};
  if (!view.feedback || !view.status || !view.check || !view.acknowledge || !view.cart) { return; }
  namespaceIds(element); root.insertBefore(element, feedback); inlineViews.set(key, view); root.dataset.vcInlineReady = '1';
  view.check.addEventListener('click', function () { if (operation?.state === 'unknown') { reconcile(operation); } });
  view.acknowledge.addEventListener('click', function () { if (operation?.state === 'unknown' && operation.synced) { operation = null; paint(); } });
  if (view.form) {
   view.form.addEventListener('submit', function (event) { event.preventDefault(); submit(view, 'add'); });
   root.querySelector('[data-vc-quick-buy]')?.addEventListener('click', function () { submit(view, 'buy'); });
   $(view.form).on('found_variation.vcFeatured reset_data.vcFeatured hide_variation.vcFeatured show_variation.vcFeatured', function () { Promise.resolve().then(paint); });
   $(view.form).on('wc_variation_form.vcFeatured', function () { Promise.resolve().then(function () { namespaceIds(view.element); paint(); }); });
   if (root.dataset.vcVariable === '1') { $(view.form).wc_variation_form(); }
  }
  paint();
 }
 function applyCart(cart, isAdded, trigger) {
  if (!cartShape(cart)) { throw new Error('Your cart response could not be confirmed.'); }
  lastCart = cart;
  var count = Number(cart.items_count);
  if (!Number.isFinite(count)) { count = cart.items.reduce(function (sum, item) { return sum + Number(item.quantity || 0); }, 0); }
  document.querySelectorAll('.vc-cart-count').forEach(function (node) { node.textContent = String(count); });
  document.dispatchEvent(new CustomEvent('vc:cart-updated', {detail: {cart: cart, source: 'showcase', mutation: !!isAdded, stale: false}}));
  if (isAdded) { $(document.body).trigger('added_to_cart', [{}, '', $(trigger)]); $(document.body).trigger('wc_fragment_refresh'); }
 }
 async function jsonRequest(url, config, payload, controller) {
  var own = controller || new AbortController(), timer = setTimeout(function () { own.abort(); }, 20000);
  var headers = {Accept: 'application/json'};
  if (config.wpNonce) { headers['X-WP-Nonce'] = config.wpNonce; }
  if (payload) { headers['Content-Type'] = 'application/json'; headers.Nonce = nonce; }
  try {
   var response = await fetch(url, {method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: own.signal, headers: headers, ...(payload ? {body: JSON.stringify(payload)} : {})});
   var fresh = response.headers.get('Nonce'); if (fresh) { nonce = fresh; }
   var data = await response.json(); return {response: response, data: data};
  } finally { clearTimeout(timer); }
 }
 async function readCart(config, controller) {
  var generation = mutationGeneration, result = await jsonRequest(config.cartApi, config, null, controller);
  if (!result.response.ok || !cartShape(result.data)) { throw new Error(plain(result.data?.message) || 'Your cart could not be synchronized. Open your cart to check it.'); }
  if (generation === mutationGeneration) { applyCart(result.data, false); }
  return {cart: result.data, applied: generation === mutationGeneration};
 }
 function pauseRoot(root, value) { if (root) { root.dispatchEvent(new CustomEvent('vc:showcase-quick-state', {detail: {open: value}})); } }
 function close() {
  if (warm) { warm.abort(); warm = null; }
  if (operation && !operation.view?.inline && operation.state === 'pending' && !operation.sent) { operation.cancelled = true; }
  var previous = active; active = null; sequence++;
  if (dialog?.open) { dialog.close(); }
  if (previous) {
   pauseRoot(previous.root, false);
   body.replaceChildren(); // View remains in the pool; its native form is never initialized twice.
   if (previous.opener.isConnected && previous.opener.getClientRects().length && !previous.opener.closest('[inert]')) { previous.opener.focus({preventScroll: true}); }
  }
 }
 function ensureDialog() {
  if (dialog) { return; }
  dialog = document.createElement('dialog'); dialog.className = 'vc-showcase-quick woocommerce'; dialog.setAttribute('aria-modal', 'true');
  closeButton = document.createElement('button'); closeButton.type = 'button'; closeButton.className = 'vc-showcase-quick__close'; closeButton.textContent = 'Close'; closeButton.addEventListener('click', close);
  body = document.createElement('div'); body.className = 'vc-showcase-quick__body';
  var feedback = document.createElement('div'); feedback.className = 'vc-showcase-quick__feedback';
  status = document.createElement('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  checkButton = document.createElement('button'); checkButton.type = 'button'; checkButton.textContent = 'Retry cart check'; checkButton.hidden = true;
  acknowledge = document.createElement('button'); acknowledge.type = 'button'; acknowledge.textContent = "I've checked my cart"; acknowledge.hidden = true;
  cartLink = document.createElement('a'); cartLink.textContent = 'View cart'; cartLink.hidden = true;
  checkButton.addEventListener('click', function () { if (operation?.state === 'unknown') { reconcile(operation); } });
  acknowledge.addEventListener('click', function () { if (operation?.state === 'unknown' && operation.synced) { operation = null; paint(); } });
  feedback.append(status, cartLink, checkButton, acknowledge); dialog.append(closeButton, body, feedback); document.body.append(dialog);
  dialog.addEventListener('close', function () { if (!dialog.open && active) { close(); } });
  dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
  dialog.addEventListener('click', function (event) { if (event.target !== dialog) { return; } var r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) { close(); } });
 }
 function namespaceIds(element) {
  var map = new Map(), prefix = 'vc-quick-' + (++sequence) + '-';
  element.querySelectorAll('[id]').forEach(function (node) { var old = node.id; var next = prefix + old; map.set(old, next); node.id = next; });
  element.querySelectorAll('[for],[aria-labelledby],[aria-describedby],[aria-controls]').forEach(function (node) {
   ['for', 'aria-labelledby', 'aria-describedby', 'aria-controls'].forEach(function (attr) { if (node.hasAttribute(attr)) { node.setAttribute(attr, node.getAttribute(attr).split(/\s+/).map(function (id) { return map.get(id) || id; }).join(' ')); } });
  });
 }
 function viewFor(id, template) {
  if (views.has(id)) { return views.get(id); }
  var fragment = template.content.cloneNode(true), element = fragment.firstElementChild;
  if (!element || element.dataset.vcProductId !== id) { return null; }
  namespaceIds(element);
  var view = {element: element, form: element.querySelector('form.cart'), initialized: false};
  if (view.form) {
   view.form.addEventListener('submit', function (event) { event.preventDefault(); submit(view, 'add'); });
   element.querySelector('[data-vc-quick-buy]')?.addEventListener('click', function () { submit(view, 'buy'); });
   $(view.form).on('found_variation.vcShowcase reset_data.vcShowcase hide_variation.vcShowcase show_variation.vcShowcase', function () { Promise.resolve().then(paint); });
   $(view.form).on('wc_variation_form.vcShowcase', function () { Promise.resolve().then(function () { namespaceIds(view.element); if (active && active.view === view && dialog.open) { dialog.setAttribute('aria-labelledby', view.element.querySelector('h2').id); } paint(); }); });
  }
  views.set(id, view); return view;
 }
 function open(button) {
  var id = button.dataset.vcQuickOpen;
  if (!/^[1-9]\d*$/.test(id)) { return false; }
  var template = document.querySelector('template[data-vc-quick-product="' + id + '"]');
  if (!template) { return false; }
  var config;
  try { config = JSON.parse(template.dataset.vcQuickConfig); ['cartApi', 'addApi', 'cartUrl', 'checkoutUrl'].forEach(function (key) { config[key] = sameOrigin(config[key]); }); } catch (_) { return false; }
  var view = viewFor(id, template);
  if (!view || view.element.dataset.vcVariable === '1' && !$.fn.wc_variation_form) { return false; }
  ensureDialog();
  invalidateInlineIntent();
  if (active) { close(); }
  active = {id: id, view: view, token: ++sequence, opener: button, root: button.closest('.vc-showcase'), config: config};
  body.replaceChildren(view.element);
  var title = view.element.querySelector('h2'); if (!title.id) { title.id = 'vc-quick-title-' + sequence; } dialog.setAttribute('aria-labelledby', title.id);
  pauseRoot(active.root, true);
  try { dialog.showModal(); } catch (_) { close(); return false; }
  if (view.form) {
   view.form.reset();
   if (view.element.dataset.vcVariable === '1') {
    if (!view.initialized) { view.initialized = true; $(view.form).wc_variation_form(); }
    else { $(view.form).find('.variations select').trigger('change'); }
   }
  }
  closeButton.focus({preventScroll: true}); paint();
  if (!blocked()) { var reading = new AbortController(); warm = reading; readCart(config, reading).catch(function () { /* Submit retries the official read; product-page fallback remains usable. */ }).finally(function () { if (warm === reading) { warm = null; } }); }
  return true;
 }
 function snapshot(view, mode) {
  var form = view.form;
  var host = view.inline ? view : active;
  if (!host || !pageActive || (view.inline ? !view.element.isConnected || !view.element.getClientRects().length : active.view !== view || !dialog.open) || !form || blocked()) { return null; }
  if (!form.reportValidity()) { return null; }
  if (!ready(view)) { (view.inline ? view.status : status).textContent = 'Choose an available specification before adding this product.'; view.element.querySelector('.vc-variation-option:not(:disabled),.variations select')?.focus(); return null; }
  var quantity = Number(form.querySelector('[name="quantity"]')?.value || 1), variable = view.element.dataset.vcVariable === '1';
  if (!Number.isFinite(quantity) || quantity <= 0) { (view.inline ? view.status : status).textContent = 'Enter a valid quantity.'; return null; }
  var id = variable ? Number(form.querySelector('[name="variation_id"]').value) : Number(host.id), attrs = [];
  form.querySelectorAll('.variations select[name^="attribute_"]').forEach(function (select) { attrs.push({attribute: select.name, value: select.value}); });
  return {parentId: host.id, name: view.element.dataset.vcProductName, token: host.token, trigger: view.inline ? view.form.querySelector('.single_add_to_cart_button') : active.opener, config: {...host.config}, ...(view.inline ? {view: view} : {}), payload: {id: id, quantity: quantity, variation: attrs}, mode: mode, state: 'pending', sent: false, cancelled: false, message: 'Adding ' + view.element.dataset.vcProductName + '…'};
 }
 async function reconcile(op) {
  if (operation !== op) { return; }
  document.dispatchEvent(new CustomEvent('vc:cart-updated', {detail: {source: 'showcase', cart: null, mutation: true, stale: true}}));
  op.state = 'checking'; op.message = 'Checking your cart. The previous request may have completed…'; paint();
  try { var checked = await readCart(op.config); op.synced = checked.applied; op.message = op.confirmed ? op.name + (op.synced ? ' was added. Your cart is synchronized; review it before continuing.' : ' was added, but your cart changed again. Check it before continuing.') : "We couldn't confirm whether " + op.name + (op.synced ? ' was added. Your cart is synchronized. Check it before adding this item again.' : ' was added. Your cart changed again; check it before retrying.'); }
  catch (_) { op.synced = false; op.message = op.confirmed ? op.name + ' was added, but your cart could not be synchronized. Open your cart before continuing.' : "We couldn't confirm whether " + op.name + ' was added, or synchronize your cart. Open your cart before retrying.'; }
  op.state = 'unknown'; paint();
 }
 async function submit(view, mode) {
  var op = snapshot(view, mode); if (!op) { return; } operation = op; paint();
  try {
   if (!nonce) { await readCart(op.config); }
   if (op.cancelled) { op.state = 'cancelled'; op.message = ''; paint(); return; }
   var result;
   for (var attempt = 0; attempt < 2; attempt++) {
    op.sent = true; document.dispatchEvent(new CustomEvent('vc:cart-mutation-start', {detail: {source: 'showcase'}})); op.epoch = mutationGeneration;
    result = await jsonRequest(op.config.addApi, op.config, op.payload);
    if (!result.response.ok && nonceErrors.indexOf(result.data?.code) >= 0 && attempt === 0) { await readCart(op.config); continue; }
    break;
   }
   var confirmed = result.response.status === 201 && cartShape(result.data) && result.data.items.some(function (item) { return Number(item.id) === op.payload.id && Number.isFinite(Number(item.quantity)) && Number(item.quantity) > 0; });
   if (confirmed) {
    op.confirmed = true;
    var stale = op.epoch !== mutationGeneration;
    if (stale) {
     document.dispatchEvent(new CustomEvent('vc:cart-updated', {detail: {source: 'showcase', cart: result.data, mutation: true, stale: true}}));
     var synced;
     try { synced = await readCart(op.config); } catch (_) { synced = null; }
     op.state = 'unknown'; op.synced = !!(synced && synced.applied); op.message = op.name + ' was added, but your cart changed meanwhile. Review your cart before continuing.'; paint();
     $(document.body).trigger('added_to_cart', [{}, '', $(op.trigger)]); $(document.body).trigger('wc_fragment_refresh');
    } else {
     applyCart(result.data, true, op.trigger); op.state = 'success'; op.message = op.name + ' was added to your cart.'; paint();
     if (op.mode === 'buy' && current(op)) { location.assign(op.config.checkoutUrl); }
    }
   } else if (result.response.status >= 400 && result.response.status < 500 && result.data && typeof result.data.code === 'string') {
    op.state = 'failed'; op.message = plain(result.data.message) || 'This product could not be added. Check the selected specification and quantity.'; paint();
   } else { await reconcile(op); }
  } catch (_) {
   if (!op.sent) { op.state = 'failed'; op.message = 'Your cart could not be loaded. Please try again or open the product page.'; paint(); }
   else { await reconcile(op); }
  }
 }
 document.addEventListener('vc:cart-mutation-start', function (event) { if (event.detail && ['drawer', 'showcase'].indexOf(event.detail.source) >= 0) { mutationGeneration++; } });
 document.addEventListener('vc:cart-updated', function (event) {
  var detail = event.detail;
  if (!detail || ['drawer', 'showcase'].indexOf(detail.source) < 0) { return; }
  if (detail.mutation === true) { mutationGeneration++; }
  if (detail.source === 'showcase' || detail.stale || !cartShape(detail.cart)) { return; }
  lastCart = detail.cart;
 });
 document.addEventListener('click', function (event) {
  var button = event.target.closest?.('[data-vc-quick-open]');
  if (!button || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !available()) { return; }
  if (open(button)) { event.preventDefault(); }
 });
 document.addEventListener('vc:showcase-scene', function (event) { if (active && active.root === event.target && active.opener.closest('.vc-showcase__scene')?.dataset.moduleItem !== event.detail?.item) { close(); } });
 window.addEventListener('pagehide', function () { pageActive = false; invalidateInlineIntent(); close(); });
 window.addEventListener('pageshow', function () { pageActive = true; });
 var inlineDevice = window.matchMedia('(max-width:767px)');
 if (inlineDevice.addEventListener) { inlineDevice.addEventListener('change', invalidateInlineIntent); }
 // Woo's own ready callback runs first while these forms are still inert templates.
 $(function () { document.querySelectorAll('[data-vc-inline-purchase]').forEach(mountInline); });
})(window.jQuery);
