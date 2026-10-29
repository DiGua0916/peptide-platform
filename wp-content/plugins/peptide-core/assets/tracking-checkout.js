/* Passive native Blocks bridge. Evaluation registers only a factory; the observer owns its lifecycle. */
(() => {
  'use strict';
  const prefix = 'experimental__woocommerce_blocks-checkout-';
  const namespace = 'peptide-core/visitor-checkout';
  const method = value => typeof value === 'string' ? value.slice(0, 80) : '';

  function create(emit) {
    const data = window.wp?.data, hooks = window.wp?.hooks;
    if (typeof emit !== 'function' || !data?.select || !data?.subscribe || !hooks?.addAction || !hooks?.removeAction) return null;
    let cart, checkout, validation, payment;
    try {
      cart = data.select('wc/store/cart'); checkout = data.select('wc/store/checkout');
      validation = data.select('wc/store/validation'); payment = data.select('wc/store/payment');
      if (!cart?.getCartData || !cart?.hasFinishedResolution || !cart?.getShippingRates || !cart?.getNeedsShipping ||
          !cart?.isCustomerDataUpdating || !cart?.isAddressFieldsForShippingRatesUpdating || !cart?.isShippingRateBeingSelected ||
          !checkout?.isCalculating || !validation?.getValidationErrors || !payment?.getActivePaymentMethod) return null;
    } catch { return null; }
    let stopped = false, suspended = true, timer, unsubscribe, mutation;
    let addressReady = false, applicability = '', lastShipping = null, lastPayment = null;
    const intents = {shipping: '', payment: ''}, confirmations = {shipping: '', payment: ''};
    const clearIntents = () => { intents.shipping = ''; intents.payment = ''; confirmations.shipping = ''; confirmations.payment = ''; };
    const removers = [];
    const form = () => document.querySelector('.wc-block-checkout__form');
    function reset() {
      addressReady = false; applicability = ''; lastShipping = null; lastPayment = null;
      clearIntents();
    }
    function settled() {
      return cart.hasFinishedResolution('getCartData', []) && !cart.isCustomerDataUpdating() &&
        !cart.isAddressFieldsForShippingRatesUpdating() && !cart.isShippingRateBeingSelected() &&
        !cart.hasPendingItemsOperations?.() && !cart.isApplyingCoupon?.() && !cart.isRemovingCoupon?.() && !checkout.isCalculating();
    }
    function reconcile() {
      if (stopped || suspended) return;
      try {
        const root = form();
        if (!root) { clearIntents(); return; }
        if (!settled()) return;
        // Inspect native validity/required flags only. Never read values, validation messages or address objects.
        const controls = Array.from(root.querySelectorAll('input,select,textarea')).filter(control => {
          const name = control.name || '', id = control.id || '';
          return !control.disabled && control.type !== 'hidden' && !control.hidden &&
            (/^(billing_|shipping_)/.test(name) || /^(billing-|shipping-)/.test(id) || id === 'email' || id === 'contact-email');
        });
        const addressRequired = controls.some(control => control.required === true && control.id !== 'email' && control.id !== 'contact-email' && control.name !== 'billing_email');
        const shippingRequired = cart.getNeedsShipping() === true && checkout.prefersCollection?.() !== true;
        const applicable = JSON.stringify([addressRequired, shippingRequired]);
        if (applicability !== applicable) {
          applicability = applicable;
          emit('checkout_step_applicability', {address_required: addressRequired, shipping_required: shippingRequired});
        }
        const errors = Object.keys(validation.getValidationErrors() || {}).some(key => /^(billing_|shipping_)/.test(key));
        const ready = addressRequired && !errors && controls.length > 0 && controls.every(control => control.validity?.valid === true);
        if (ready && !addressReady) emit('checkout_address_completed', {source: 'native_blocks'});
        addressReady = ready;

        if (shippingRequired && cart.getHasCalculatedShipping?.() === true) {
          const selected = [...new Set(cart.getShippingRates().flatMap(pack => (pack.shipping_rates || []).filter(rate => rate.selected === true).map(rate => method(rate.rate_id))).filter(Boolean))].sort();
          const signature = JSON.stringify(selected);
          const userShipping = intents.shipping && confirmations.shipping === intents.shipping ? intents.shipping : '';
          if (selected.length && (lastShipping !== signature || selected.includes(userShipping))) {
            for (const rate of selected) emit('checkout_shipping_selected', {method: rate, origin: rate === userShipping ? 'user' : lastShipping === null ? 'initial' : 'automatic'});
            lastShipping = signature;
          }
        }
        const active = method(payment.getActivePaymentMethod());
        const userPayment = intents.payment && confirmations.payment === intents.payment ? intents.payment : '';
        if (active && (lastPayment !== active || userPayment === active)) {
          emit('checkout_payment_selected', {method: active, origin: userPayment === active ? 'user' : lastPayment === null ? 'initial' : 'automatic'});
          lastPayment = active;
        }
        // Settled failure/revert/mismatch must not lend stale human intent to a later automatic hook.
        clearIntents();
      } catch { clearIntents(); /* Optional analytics must never interrupt checkout. */ }
    }
    function schedule() {
      if (stopped || suspended) return;
      clearTimeout(timer);
      // Redux may notify before the native Promise.then user-origin hook. Wait through that microtask turn.
      timer = setTimeout(() => { timer = undefined; reconcile(); }, 0);
    }
    function observeIntent(event) {
      if (stopped || suspended) return;
      const radio = event.target;
      // Only static method identifiers from actual native radio actions. Address/card controls are never read.
      if (event.isTrusted === true && radio?.matches?.('input[type="radio"]') && !radio.disabled && radio.checked === true) {
        const kind = radio.closest?.('#shipping-option') ? 'shipping' : radio.closest?.('#payment-method') ? 'payment' : '';
        if (kind) {
          const value = radio.getAttribute('value');
          if (typeof value === 'string' && /^[A-Za-z0-9_:-]{1,80}$/.test(value)) {
            // A native change can follow React's click handler/hook; retain independent matching hook evidence.
            intents[kind] = value;
          }
        }
      }
      schedule();
    }
    function confirm(kind, value) {
      if (stopped || suspended) return;
      confirmations[kind] = method(value);
      schedule();
    }
    const actions = {
      'render-checkout-form': schedule,
      'set-billing-address': schedule,
      'set-shipping-address': schedule,
      'set-email-address': schedule,
      'set-selected-shipping-rate': event => confirm('shipping', event?.shippingRateId),
      'set-active-payment-method': event => confirm('payment', event?.paymentMethodSlug),
      submit: () => {
        if (stopped || suspended) return;
        reconcile();
        emit('checkout_submit_attempt', {});
      }
    };
    function suspend() {
      suspended = true; clearTimeout(timer); timer = undefined;
      clearIntents();
      unsubscribe?.(); unsubscribe = undefined;
      mutation?.disconnect(); mutation = undefined;
      removers.splice(0).forEach(remove => remove());
    }
    function resume() {
      if (stopped || !suspended) return;
      suspended = false;
      try {
        for (const [name, callback] of Object.entries(actions)) {
          hooks.addAction(prefix + name, namespace, callback);
          removers.push(() => hooks.removeAction(prefix + name, namespace));
        }
        unsubscribe = data.subscribe(schedule);
        for (const name of ['input', 'change', 'click']) {
          const listener = name === 'input' ? schedule : observeIntent;
          document.addEventListener(name, listener, {capture: true, passive: true});
          removers.push(() => document.removeEventListener(name, listener, {capture: true}));
        }
        if (window.MutationObserver && document.body) {
          mutation = new window.MutationObserver(schedule);
          mutation.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['required', 'disabled', 'hidden']});
        }
        schedule();
      } catch { stop(); }
    }
    function stop() { if (!stopped) { suspend(); stopped = true; } }
    resume();
    return stopped ? null : {nativeSubmit: true, suspend, resume, reset, stop};
  }
  window.PeptideCheckoutBridge = {create};
})();
