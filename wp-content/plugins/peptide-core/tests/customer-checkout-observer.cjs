/* Actual observer + adapter + native bridge in an isolated VM. Native selectors/hooks are controlled, not replaced runtime code. */
'use strict';
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const {webcrypto} = require('node:crypto');
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; }
const drain = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
class Target {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatchEvent(event) { for (const fn of [...(this.listeners.get(event.type) || [])]) fn(event); }
}
function control(id, required = true, valid = false) {
  const field = {id, name: id === 'email' ? 'billing_email' : id.replace('-', '_'), required, validity: {valid}, type: 'text', disabled: false, hidden: false};
  Object.defineProperty(field, 'value', {get() { throw Error('Analytics read a private value'); }});
  Object.defineProperty(field, 'validationMessage', {get() { throw Error('Analytics read a private validation message'); }});
  return field;
}
function fixture(options = {}) {
  let now = Date.parse('2026-10-30T00:00:00Z'), next = 0;
  const timers = new Map(), calls = [], actions = new Map(), subscriptions = new Set(), responses = [], storage = new Map();
  const state = {mounted: true, resolved: true, customerPending: false, ratePending: false, selecting: false, calculating: false, needsShipping: true, pickup: false, calculated: true, payment: 'pc_local_card', rate: 'flat_rate:1', errors: {}, fields: [control('email', true, true), control('shipping-first_name'), control('shipping-address_1'), control('shipping-country', true, true)], ...options.state};
  const document = new Target(), window = new Target();
  document.visibilityState = 'visible'; document.hidden = false; document.body = {};
  const root = {querySelectorAll: () => state.fields};
  document.querySelector = selector => selector === '.wc-block-checkout__form' && state.mounted ? root : null;
  class ClockDate extends Date { constructor(...args) { super(...(args.length ? args : [now])); } static now() { return now; } }
  const later = (fn, ms, interval = 0) => { const id = ++next; timers.set(id, {fn, due: now + (Number(ms) || 0), interval}); return id; };
  const cart = {getCartData: () => ({}), hasFinishedResolution: () => state.resolved, isCustomerDataUpdating: () => state.customerPending, isAddressFieldsForShippingRatesUpdating: () => state.ratePending, isShippingRateBeingSelected: () => state.selecting, hasPendingItemsOperations: () => false, getNeedsShipping: () => state.needsShipping, getHasCalculatedShipping: () => state.calculated, getShippingRates: () => [{shipping_rates: state.rate ? [{rate_id: state.rate, selected: true}] : []}]};
  const stores = {'wc/store/cart': cart, 'wc/store/checkout': {isCalculating: () => state.calculating, prefersCollection: () => state.pickup}, 'wc/store/validation': {getValidationErrors: () => state.errors}, 'wc/store/payment': {getActivePaymentMethod: () => state.payment}};
  Object.assign(window, {window, document, crypto: webcrypto, location: new URL('https://checkout.test/checkout/?SECRET'), URL, Date: ClockDate, Uint8Array, TextEncoder, AbortController, DOMException,
    setTimeout: (fn, ms) => later(fn, ms), clearTimeout: id => timers.delete(id), setInterval: (fn, ms) => later(fn, ms, ms), clearInterval: id => timers.delete(id),
    sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)},
    VialCoveVisitorPage: {page_type: options.page || 'checkout', path: '/checkout/?SECRET'},
    VialCoveVisitorConfig: {enabled: true, protocol: 1, endpoint: 'https://checkout.test/wp-json/peptide/v1/track/events', batch_max: 20, heartbeat_ms: 15000, ...options.config},
    wp: {data: {select: name => options.noStores ? undefined : stores[name], subscribe: fn => { subscriptions.add(fn); return () => subscriptions.delete(fn); }}, hooks: {addAction: (name, ns, callback) => actions.set(name, callback), removeAction: name => actions.delete(name)}},
    fetch: async (url, init) => {
      const body = JSON.parse(init.body); calls.push({url, body, keepalive: init.keepalive});
      const status = responses.shift() || 200;
      return {status, ok: status === 200, headers: new Headers(), json: async () => status === 403 ? {code: 'pc_track_declined'} : {protocol: 1, ack: body.events.map(event => event.event_id), rejected: []}};
    }
  });
  if (options.noHooks) delete window.wp.hooks;
  const context = vm.createContext(window);
  const plugin = path.resolve(__dirname, '../assets');
  const theme = path.resolve(__dirname, '../../../themes/peptide-site/assets');
  vm.runInContext(fs.readFileSync(path.join(plugin, 'tracking-checkout.js'), 'utf8'), context);
  if (options.throwFactory) window.PeptideCheckoutBridge.create = () => { throw Error('Unavailable integration'); };
  check('Factory evaluation alone creates no hooks/subscriptions/transport', actions.size === 0 && subscriptions.size === 0 && calls.length === 0);
  for (const filename of ['visitor-tracking.js', 'visitor-adapter.js']) vm.runInContext(fs.readFileSync(path.join(theme, filename), 'utf8'), context, {filename});
  async function advance(ms = 300) {
    const end = now + ms; await drain();
    for (let loops = 0; loops < 10000; loops++) {
      const first = [...timers].filter(([, task]) => task.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
      if (!first) { now = end; await drain(); return; }
      const [id, task] = first; now = task.due;
      if (task.interval) task.due += task.interval; else timers.delete(id);
      task.fn(); await drain();
    }
    throw Error('Unbounded timer work');
  }
  const hook = (name, payload = {}) => actions.get('experimental__woocommerce_blocks-checkout-' + name)?.(payload);
  const notify = () => { for (const fn of [...subscriptions]) fn(); };
  const events = type => calls.flatMap(call => call.body.events).filter(event => !type || event.type === type);
  const click = () => {
    const button = {disabled: false, classList: {contains: () => false}, getAttribute: () => null, matches: () => true};
    document.dispatchEvent({type: 'click', isTrusted: true, button: 0, target: {closest: () => button}});
  };
  const radio = (kind, value, options = {}) => {
    const group = options.outside ? '#unrelated' : kind === 'shipping' ? '#shipping-option' : '#payment-method';
    const target = {disabled: false, checked: options.checked !== false, matches: selector => selector === 'input[type="radio"]',
      closest: selector => selector === group ? {} : null, getAttribute: name => { if (options.forbidValueRead) throw Error('Unrelated radio value read'); return name === 'value' ? value : null; }};
    Object.defineProperty(target, 'value', {get() { throw Error('Read static method attribute only'); }});
    document.dispatchEvent({type: options.type || 'change', isTrusted: options.trusted !== false, button: 0, target});
  };
  return {window, document, state, actions, subscriptions, timers, calls, responses, storage, advance, hook, notify, events, click, radio, stop: () => window.VialCoveVisitorTransport.stop()};
}
async function run() {
  const f = fixture({state: {resolved: false}}); await f.advance();
  check('Checkout view uses baseline collector', f.events('checkout_view').length === 1);
  check('Unresolved cart emits no step completion or selections', !f.events().some(event => /selected|completed|applicability/.test(event.type)));
  f.state.resolved = true; f.state.mounted = false; f.notify(); await f.advance();
  check('Absent native form is not readiness', f.events('checkout_step_applicability').length === 0);
  f.state.mounted = true; f.hook('render-checkout-form'); await f.advance();
  check('Invalid/partial address is not complete', f.events('checkout_address_completed').length === 0);
  check('Resolved default shipping is initial, not user', f.events('checkout_shipping_selected')[0].data.origin === 'initial');
  check('Restored/default payment is initial', f.events('checkout_payment_selected')[0].data.origin === 'initial');
  check('Applicability retains strict true booleans', f.events('checkout_step_applicability')[0].data.address_required === true && f.events('checkout_step_applicability')[0].data.shipping_required === true);
  f.state.fields.forEach(field => field.validity.valid = true); f.state.customerPending = true; f.hook('set-shipping-address'); await f.advance();
  check('Pending customer address update delays readiness', f.events('checkout_address_completed').length === 0);
  f.state.customerPending = false; f.state.errors = {shipping_postcode: {hidden: true, message: 'SECRET'}}; f.notify(); await f.advance();
  check('Hidden native validation errors still prevent completion', f.events('checkout_address_completed').length === 0);
  f.state.errors = {}; f.notify(); await f.advance();
  check('Settled native valid address emits one readiness transition', f.events('checkout_address_completed').length === 1);
  for (let i = 0; i < 8; i++) { f.hook('render-checkout-form'); f.notify(); } await f.advance();
  check('Render/store notifications do not duplicate readiness', f.events('checkout_address_completed').length === 1);
  check('Repeated initial state does not duplicate selection', f.events('checkout_payment_selected').length === 1 && f.events('checkout_shipping_selected').length === 1);
  f.hook('set-shipping-address'); f.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:1'}); await f.advance();
  check('Address recalculation hook reselecting same default creates no user event', f.events('checkout_shipping_selected').length === 1);
  f.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_card'}); await f.advance();
  check('Native payment hook alone reselecting default creates no user event', f.events('checkout_payment_selected').length === 1);
  const countryField = control('shipping-state', true, false); f.state.fields.push(countryField); f.notify(); await f.advance();
  check('Country-dependent newly required invalid field invalidates readiness', f.events('checkout_address_completed').length === 1);
  f.state.fields.pop(); f.notify(); await f.advance();
  check('Removed country-inapplicable control allows a new valid transition', f.events('checkout_address_completed').length === 2);
  f.radio('shipping', 'flat_rate:2', {type: 'click'}); f.state.rate = 'flat_rate:2'; f.notify();
  check('Optimistic selection does not synchronously emit', f.events('checkout_shipping_selected').length === 1);
  Promise.resolve().then(() => { f.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:2', address: 'SECRET'}); f.radio('shipping', 'flat_rate:2'); });
  await f.advance();
  check('Pending-false notification before Promise hook retains explicit user origin', f.events('checkout_shipping_selected').at(-1).data.origin === 'user');
  check('Shipping emits only stable bounded method/origin', Object.keys(f.events('checkout_shipping_selected').at(-1).data).sort().join(',') === 'method,origin');
  const beforeFailure = f.events('checkout_shipping_selected').length;
  f.radio('shipping', 'flat_rate:3'); f.state.rate = 'flat_rate:3'; f.notify();
  Promise.reject(Error('Native shipping request failed')).catch(() => { f.state.rate = 'flat_rate:2'; f.notify(); });
  await f.advance();
  check('Rejected/reverted optimistic rate never becomes successful selection', f.events('checkout_shipping_selected').length === beforeFailure && !f.events('checkout_shipping_selected').some(event => event.data.method === 'flat_rate:3'));
  f.state.rate = 'flat_rate:3'; f.notify(); f.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:3'}); await f.advance();
  check('Failed trusted intent cannot label later automatic rate as user', f.events('checkout_shipping_selected').at(-1).data.origin === 'automatic');
  f.radio('shipping', 'flat_rate:4'); f.state.rate = 'flat_rate:4'; f.state.selecting = true; f.notify(); await f.advance();
  check('Native selection pending flag prevents capture', !f.events('checkout_shipping_selected').some(event => event.data.method === 'flat_rate:4'));
  f.state.selecting = false; f.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:4'}); await f.advance();
  check('Only settled successful final hook rate is captured as user', f.events('checkout_shipping_selected').at(-1).data.method === 'flat_rate:4' && f.events('checkout_shipping_selected').at(-1).data.origin === 'user');
  f.radio('payment', 'pc_local_wallet', {type: 'click'}); f.state.payment = 'pc_local_wallet'; f.notify(); f.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_wallet'}); f.radio('payment', 'pc_local_wallet'); await f.advance();
  check('Payment store plus native user hook emits exactly one explicit choice', f.events('checkout_payment_selected').length === 2 && f.events('checkout_payment_selected').at(-1).data.origin === 'user');
  f.state.payment = 'pc_local_card'; f.notify(); await f.advance();
  check('Unprompted native method change is automatic', f.events('checkout_payment_selected').at(-1).data.origin === 'automatic');
  f.document.dispatchEvent({type: 'change', isTrusted: true, target: control('card-number')});
  f.radio('shipping', 'SECRET', {outside: true, forbidValueRead: true}); await f.advance();
  check('Non-radio address/card and unrelated radio values are never inspected', !JSON.stringify(f.calls).includes('SECRET'));
  f.click(); f.state.errors = {billing_email: {hidden: true}}; f.hook('submit'); await f.advance();
  check('Capture click plus native submit produces exactly one attempt', f.events('checkout_submit_attempt').length === 1);
  check('Invalid submit never emits order/payment success', !f.events().some(event => ['order_placed', 'payment_success', 'payment_attempt'].includes(event.type)));
  check('No form values, messages, arbitrary payload or URL secrets reach transport', !JSON.stringify(f.calls).includes('SECRET'));
  check('Bridge shares observer page instance and increasing sequence', new Set(f.events().map(event => event.page_instance_id)).size === 1 && f.events().every((event, index, list) => !index || event.seq > list[index - 1].seq));
  const firstPage = f.events()[0].page_instance_id;
  f.window.dispatchEvent({type: 'pagehide', persisted: true}); await f.advance();
  check('Suspension unregisters hooks/subscriptions', f.actions.size === 0 && f.subscriptions.size === 0);
  const suspendedCount = f.events().length; f.notify(); f.hook('submit'); await f.advance(1000);
  check('Suspended bridge cannot enqueue', f.events().length === suspendedCount);
  f.window.dispatchEvent({type: 'pageshow', persisted: true}); f.state.errors = {}; await f.advance();
  check('bfcache restores one set of hooks and one store subscription', f.actions.size === 7 && f.subscriptions.size === 1);
  check('bfcache uses a new observer page instance', f.events().at(-1).page_instance_id !== firstPage);
  f.hook('submit'); await f.advance();
  check('Restored native submit is observed once', f.events('checkout_submit_attempt').length === 2);
  f.stop(); check('Stop removes bridge listeners/subscriptions/timers', f.actions.size === 0 && f.subscriptions.size === 0 && f.timers.size === 0);
  const stoppedCount = f.events().length; f.notify(); f.window.dispatchEvent({type: 'pageshow', persisted: true}); await f.advance(20000);
  check('Stopped observer never restarts', f.events().length === stoppedCount);

  const automatic = fixture(); await automatic.advance();
  automatic.radio('shipping', 'flat_rate:2', {trusted: false}); automatic.state.rate = 'flat_rate:2'; automatic.notify(); automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:2'}); await automatic.advance();
  check('Untrusted shipping change plus hook remains automatic', automatic.events('checkout_shipping_selected').at(-1).data.origin === 'automatic');
  automatic.radio('payment', 'pc_local_wallet', {trusted: false}); automatic.state.payment = 'pc_local_wallet'; automatic.notify(); automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_wallet'}); await automatic.advance();
  check('Untrusted payment change plus hook remains automatic', automatic.events('checkout_payment_selected').at(-1).data.origin === 'automatic');
  automatic.state.rate = 'flat_rate:3'; automatic.notify(); automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:3'}); await automatic.advance();
  check('Changed shipping with only native hook has automatic origin', automatic.events('checkout_shipping_selected').at(-1).data.origin === 'automatic');
  automatic.state.payment = 'pc_local_card'; automatic.notify(); automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_card'}); await automatic.advance();
  check('Changed payment with only native hook has automatic origin', automatic.events('checkout_payment_selected').at(-1).data.origin === 'automatic');
  automatic.radio('shipping', 'flat_rate:4'); automatic.state.rate = 'flat_rate:4'; automatic.notify(); automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:9'}); await automatic.advance();
  check('Trusted radio without matching successful native hook cannot assert user origin', automatic.events('checkout_shipping_selected').at(-1).data.origin === 'automatic');
  const countAfterMismatch = automatic.events('checkout_shipping_selected').length;
  automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:4'}); await automatic.advance();
  check('Settled hook mismatch clears stale intent before a later matching automatic hook', automatic.events('checkout_shipping_selected').length === countAfterMismatch);
  automatic.radio('payment', 'pc_local_wallet'); automatic.state.payment = 'pc_local_wallet'; automatic.state.customerPending = true; automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_wallet'}); await automatic.advance();
  check('Trusted payment intent is not emitted while native customer work is pending', automatic.events('checkout_payment_selected').at(-1).data.method === 'pc_local_card');
  automatic.state.customerPending = false; automatic.notify(); await automatic.advance();
  check('Trusted matching payment intent survives pending native work', automatic.events('checkout_payment_selected').at(-1).data.origin === 'user');
  const beforeHookFirstPayment = automatic.events('checkout_payment_selected').length;
  automatic.state.payment = 'pc_local_card'; automatic.notify(); automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_card'}); automatic.radio('payment', 'pc_local_card'); await automatic.advance();
  check('Native payment hook before trusted change still matches one user action', automatic.events('checkout_payment_selected').length === beforeHookFirstPayment + 1 && automatic.events('checkout_payment_selected').at(-1).data.origin === 'user');
  const beforeHookFirstShipping = automatic.events('checkout_shipping_selected').length;
  automatic.state.rate = 'flat_rate:6'; automatic.notify(); automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:6'}); automatic.radio('shipping', 'flat_rate:6'); await automatic.advance();
  check('Successful shipping hook before trusted change is correlated once', automatic.events('checkout_shipping_selected').length === beforeHookFirstShipping + 1 && automatic.events('checkout_shipping_selected').at(-1).data.origin === 'user');
  const beforePaymentRevert = automatic.events('checkout_payment_selected').length;
  automatic.radio('payment', 'pc_local_wallet'); automatic.state.payment = 'pc_local_wallet'; automatic.state.customerPending = true; automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_wallet'}); await automatic.advance();
  automatic.state.payment = 'pc_local_card'; automatic.state.customerPending = false; automatic.notify(); await automatic.advance();
  check('Reverted trusted payment choice does not emit a successful selection', automatic.events('checkout_payment_selected').length === beforePaymentRevert);
  automatic.state.payment = 'pc_local_wallet'; automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_wallet'}); automatic.notify(); await automatic.advance();
  check('Reverted payment intent cannot label a later automatic choice as user', automatic.events('checkout_payment_selected').at(-1).data.origin === 'automatic');
  automatic.hook('set-active-payment-method', {paymentMethodSlug: 'pc_local_card'}); await automatic.advance();
  automatic.radio('payment', 'pc_local_card'); automatic.state.payment = 'pc_local_card'; automatic.notify(); await automatic.advance();
  check('Consumed earlier hook cannot confirm a later trusted action without a new hook', automatic.events('checkout_payment_selected').at(-1).data.origin === 'automatic');
  automatic.radio('shipping', 'flat_rate:5'); automatic.state.rate = 'flat_rate:5'; automatic.state.selecting = true; automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:5'}); await automatic.advance();
  automatic.window.dispatchEvent({type: 'pagehide', persisted: true}); automatic.state.selecting = false;
  automatic.window.dispatchEvent({type: 'pageshow', persisted: true}); automatic.hook('set-selected-shipping-rate', {shippingRateId: 'flat_rate:5'}); await automatic.advance();
  check('Suspend/reset clears trusted intent before restored automatic hook', automatic.events('checkout_shipping_selected').at(-1).data.origin === 'initial');
  check('Radio intent adds no fields beyond existing method/origin contract', automatic.events('checkout_payment_selected').every(event => Object.keys(event.data).sort().join(',') === 'method,origin'));
  automatic.stop();

  for (const state of [{needsShipping: false}, {pickup: true}]) {
    const v = fixture({state}); await v.advance();
    check('Virtual/pickup applicability marks shipping unnecessary', v.events('checkout_step_applicability')[0].data.shipping_required === false);
    check('Virtual/pickup never fabricates shipping selected', v.events('checkout_shipping_selected').length === 0); v.stop();
  }
  const minimal = fixture({state: {needsShipping: false, fields: [control('email', true, true)]}}); await minimal.advance();
  check('Native contact-only form exposes address not required', minimal.events('checkout_step_applicability')[0].data.address_required === false);
  check('Inapplicable address never fabricates completion', minimal.events('checkout_address_completed').length === 0); minimal.stop();
  for (const options of [{noStores: true}, {noHooks: true}, {throwFactory: true}]) {
    const absent = fixture(options); absent.click(); await absent.advance();
    check('Absent/failing optional bridge preserves DOM submit fallback', absent.events('checkout_submit_attempt').length === 1);
    check('Absent bridge preserves basic collector', absent.events('page_view').length === 1 && absent.events('checkout_view').length === 1); absent.stop();
  }
  const denied = fixture(); denied.responses.push(403); await denied.advance();
  check('403 tears down native hooks and subscriptions', denied.actions.size === 0 && denied.subscriptions.size === 0 && denied.timers.size === 0);
  denied.notify(); denied.window.dispatchEvent({type: 'pageshow', persisted: true}); denied.hook('submit'); await denied.advance(20000);
  check('403 cannot be revived by native updates/bfcache', denied.calls.length === 1);
  for (const config of [{enabled: false}, {protocol: 2}]) {
    const off = fixture({config}); await off.advance(); check('Disabled/wrong protocol never installs bridge', off.calls.length === 0 && off.actions.size === 0 && off.subscriptions.size === 0);
  }
  const product = fixture({page: 'product'}); await product.advance();
  check('Noncheckout collector remains independent of bridge', product.actions.size === 0 && product.events('page_view').length === 1); product.stop();
  console.log(JSON.stringify({suite: 'customer-checkout-observer', passed: checks, failed: 0}));
}
run().catch(error => { console.error(error); process.exitCode = 1; });
