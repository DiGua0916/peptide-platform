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
    let userShipping = '', userPayment = '';
    const removers = [];
    const form = () => document.querySelector('.wc-block-checkout__form');
    function reset() {
      addressReady = false; applicability = ''; lastShipping = null; lastPayment = null;
      userShipping = ''; userPayment = '';
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
        if (!root || !settled()) return;
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
          if (selected.length && (lastShipping !== signature || selected.includes(userShipping))) {
            for (const rate of selected) emit('checkout_shipping_selected', {method: rate, origin: rate === userShipping ? 'user' : lastShipping === null ? 'initial' : 'automatic'});
            lastShipping = signature;
          }
        }
        const active = method(payment.getActivePaymentMethod());
        if (active && (lastPayment !== active || userPayment === active)) {
          emit('checkout_payment_selected', {method: active, origin: userPayment === active ? 'user' : lastPayment === null ? 'initial' : 'automatic'});
          lastPayment = active;
        }
        // Hooks describe a successful final selection. Never retain a stale intention after reconciliation.
        userShipping = ''; userPayment = '';
      } catch { /* Optional analytics must never interrupt checkout. */ }
    }
    function schedule() {
      if (stopped || suspended) return;
      clearTimeout(timer);
      // Redux may notify before the native Promise.then user-origin hook. Wait through that microtask turn.
      timer = setTimeout(() => { timer = undefined; reconcile(); }, 0);
    }
    const actions = {
      'render-checkout-form': schedule,
      'set-billing-address': schedule,
      'set-shipping-address': schedule,
      'set-email-address': schedule,
      'set-selected-shipping-rate': event => { userShipping = method(event?.shippingRateId); schedule(); },
      'set-active-payment-method': event => { userPayment = method(event?.paymentMethodSlug); schedule(); },
      submit: () => {
        if (stopped || suspended) return;
        reconcile();
        emit('checkout_submit_attempt', {});
      }
    };
    function suspend() {
      suspended = true; clearTimeout(timer); timer = undefined;
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
        for (const name of ['input', 'change']) {
          document.addEventListener(name, schedule, {capture: true, passive: true});
          removers.push(() => document.removeEventListener(name, schedule, {capture: true}));
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
