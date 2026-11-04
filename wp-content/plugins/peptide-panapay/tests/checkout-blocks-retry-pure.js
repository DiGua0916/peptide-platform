/* Execute the shipped Blocks component with a small React hook/event harness.
 * No browser, network, payment request, DOM submission or provider is used.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/checkout.js'), 'utf8');
const checks = [];
const check = (condition, name) => { assert.ok(condition, name); checks.push({ name, passed: true }); };
const intent = 'f'.repeat(32);
const relative = `/checkout/?pc_panapay_new_checkout=${intent}&pc_panapay_checkout_nonce=abc123DEF`;
const origin = 'https://store.example.invalid';
const card = `pc_panapay_${'a'.repeat(32)}`;
let current = null;
const element = {
  createElement: (type, props, ...children) => ({ type, props: props || {}, children: children.flat() }),
  useState(initial) {
    const render = current, index = render.cursor++;
    if (!(index in render.states)) render.states[index] = initial;
    return [render.states[index], value => { render.states[index] = typeof value === 'function' ? value(render.states[index]) : value; }];
  },
  useEffect(effect, dependencies) {
    const render = current, index = render.cursor++, previous = render.effects[index];
    if (!previous || dependencies.length !== previous.dependencies.length || dependencies.some((value, i) => !Object.is(value, previous.dependencies[i]))) {
      render.pending.push(() => {
        if (typeof previous?.cleanup === 'function') previous.cleanup();
        render.effects[index] = { dependencies, cleanup: effect() };
      });
    }
  },
};
function component(type) {
  const render = { states: [], effects: [], pending: [], cursor: 0 };
  return {
    render(props) {
      render.cursor = 0; render.pending = []; current = render;
      let tree;
      try { tree = type(props); } finally { current = null; }
      for (const run of render.pending) run();
      return tree;
    },
    unmount() { for (const effect of render.effects) if (typeof effect?.cleanup === 'function') effect.cleanup(); render.effects = []; },
  };
}
function events() {
  const listeners = new Set(); let removed = 0;
  return {
    registration: { onCheckoutFail(listener) { listeners.add(listener); return () => { if (listeners.delete(listener)) removed++; }; } },
    fail: () => [...listeners].map(listener => listener({ processingResponse: { paymentStatus: 'failure' } })),
    count: () => listeners.size,
    removed: () => removed,
  };
}
function setup(url = relative, names = ['pc_panapay']) {
  const methods = [];
  const data = Object.fromEntries(names.map(name => [name, {
    account_id: name === 'pc_panapay' ? 'default' : name.slice('pc_panapay_'.length),
    title: 'Synthetic payment', description: 'Synthetic description', payment_icons: [], new_checkout_url: url,
  }]));
  const window = {
    location: { href: `${origin}/checkout/`, origin }, pcPanapayMethodNames: names,
    wc: { wcBlocksRegistry: { registerPaymentMethod: method => methods.push(method) }, wcSettings: { getPaymentMethodData: name => data[name] } },
    wp: { element },
  };
  vm.runInNewContext(source, { window, URL, Set }, { filename: 'shipped-checkout.js', timeout: 1000 });
  return methods;
}
function all(tree, predicate) {
  if (!tree || typeof tree !== 'object') return [];
  return [...(predicate(tree) ? [tree] : []), ...tree.children.flatMap(child => all(child, predicate))];
}
const actions = tree => all(tree, node => node.type === 'a');
const propsFor = (event, activePaymentMethod = 'pc_panapay') => ({ eventRegistration: event.registration, activePaymentMethod });

const [method] = setup();
check(!!method && typeof method.content.type === 'function', 'actual_shipped_method_registers_hook_content');
const live = component(method.content.type), event = events();
let tree = live.render(propsFor(event));
check(actions(tree).length === 0 && event.count() === 1, 'first_render_has_no_recovery_action_and_one_failure_subscription');
check(event.fail().every(result => result === true), 'failure_observer_preserves_native_error_by_returning_true');
tree = live.render(propsFor(event));
const [action] = actions(tree);
check(actions(tree).length === 1 && action.props.href === origin + relative, 'failure_renders_one_exact_nonce_bound_checkout_target');
check(all(tree, node => node.props.role === 'status').length === 1, 'recovery_action_is_accessible_status_content');
check(!action.props.onClick && !action.props.target, 'action_uses_normal_same_tab_navigation_without_payment_side_effect');
check(event.count() === 1 && event.removed() === 0, 'normal_rerender_does_not_duplicate_failure_subscription');
check(method.edit.type === 'p' && actions(method.edit).length === 0, 'editor_preview_is_static_and_has_no_live_recovery_action');
live.unmount();
check(event.count() === 0 && event.removed() === 1 && event.fail().length === 0, 'unmount_removes_listener_and_later_events_do_nothing');

const inactive = component(method.content.type), inactiveEvents = events();
tree = inactive.render(propsFor(inactiveEvents, card));
check(inactiveEvents.fail().every(result => result === true), 'inactive_method_observer_also_preserves_native_failure');
tree = inactive.render(propsFor(inactiveEvents, card));
check(actions(tree).length === 0, 'failure_of_another_active_method_does_not_show_this_action');
tree = inactive.render(propsFor(inactiveEvents));
check(inactiveEvents.count() === 1 && inactiveEvents.removed() === 1, 'active_method_change_replaces_old_subscription');
inactiveEvents.fail(); tree = inactive.render(propsFor(inactiveEvents));
check(actions(tree).length === 1, 'newly_selected_method_can_show_its_own_recovery_action');
inactive.unmount();

for (const [name, url] of [
  ['foreign_origin', `https://outside.example.invalid${relative}`],
  ['credential_url', `${origin.replace('https://', 'https://user:pass@')}${relative}`],
  ['missing_intent', '/checkout/?pc_panapay_checkout_nonce=abc123'],
  ['bad_intent', '/checkout/?pc_panapay_new_checkout=invalid&pc_panapay_checkout_nonce=abc123'],
  ['bad_nonce', `/checkout/?pc_panapay_new_checkout=${intent}&pc_panapay_checkout_nonce=bad%20nonce`],
  ['wrong_protocol', `${origin.replace('https://', 'http://')}${relative}`],
  ['javascript_url', 'javascript:alert(1)'],
  ['non_string', null],
]) {
  const [unsafe] = setup(url), render = component(unsafe.content.type), failures = events();
  tree = render.render(propsFor(failures)); failures.fail(); tree = render.render(propsFor(failures));
  check(actions(tree).length === 0 && failures.count() === 0, `${name}_does_not_register_an_action_or_listener`);
  render.unmount();
}
const methods = setup(relative, ['pc_panapay', card, card, 'invalid-method']);
check(methods.length === 2 && methods[0].name !== methods[1].name, 'multiple_valid_accounts_register_once_without_invalid_method_names');
const second = component(methods[1].content.type), secondEvents = events();
second.render(propsFor(secondEvents, card)); secondEvents.fail(); tree = second.render(propsFor(secondEvents, card));
check(actions(tree).length === 1, 'separate_account_recovery_uses_its_registered_active_method'); second.unmount();
console.log(JSON.stringify({ complete: true, assertions: checks, asserted: checks.length, transport: 'none', actualReactBrowserTested: false, productionAccessed: false }));
