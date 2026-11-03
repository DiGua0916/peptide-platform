'use strict';
// Execute the actual PHP-emitted helper against a minimal DOM. No WP, DB or network.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const php = process.argv[2];
assert(php, 'Pass an existing PHP executable path');
const emitted = JSON.parse(execFileSync(php, ['-n', path.join(__dirname, 'workspace-navigation-pure.php'), '--emit-react-script'], {encoding: 'utf8'}));
assert(emitted.script && emitted.html.endsWith('<span id="pc-workspace-content-ready" hidden aria-hidden="true"></span>'));
let checks = 0;
function fixture(options = {}) {
  const elements = new Map();
  const listeners = new Map();
  const timers = new Map();
  const observers = [];
  let nextTimer = 0;
  const root = {isConnected: true, state: options.state || 'empty', childElementCount: options.state === 'tracking' ? 1 : 0,
    querySelector(selector) {
      assert(['.woocommerce-layout .woocommerce-layout__main', '.woocommerce-global-error-boundary'].includes(selector));
      return (selector.endsWith('__main') && this.state === 'layout') || (selector.endsWith('error-boundary') && this.state === 'error') ? {} : null;
    }};
  function element(tag) {
    return {tag, attrs: {}, parentNode: null, relList: {supports: x => x === 'expect' && options.expectSupport !== false},
      setAttribute(k, v) {this.attrs[k] = v;}, appendChild(child) {child.parentNode = this; if (child.id) elements.set(child.id, child); return child;},
      removeChild(child) {if (child.id) elements.delete(child.id); child.parentNode = null;}};
  }
  const body = element('body');
  const classes = new Set(['pc-admin-workspace', 'woocommerce-admin-page']);
  if (options.embedded) classes.add('woocommerce-embed-page');
  if (options.notWoo) classes.delete('woocommerce-admin-page');
  if (options.loading) classes.add('woocommerce-admin-is-loading');
  body.classList = {contains: c => classes.has(c)};
  const head = element('head');
  if (options.appendThrows) head.appendChild = () => {throw Error('synthetic append failure');};
  const document = {body, head, createElement: element, getElementById: id => elements.get(id) || null,
    querySelector(selector) {
      if (selector.startsWith('link[')) return options.noHead ? null : {};
      assert.equal(selector, '#wpbody-content > .wrap > #root');
      return options.noRoot ? null : root;
    }};
  class MutationObserver {
    constructor(callback) {this.callback = callback; this.disconnected = false; observers.push(this);}
    observe(target, config) {
      if (target === root) assert.equal(JSON.stringify(config), '{"childList":true,"subtree":true}');
      else {assert.equal(target, body); assert.equal(JSON.stringify(config), '{"attributes":true,"attributeFilter":["class"]}');}
      this.observing = true;
    }
    disconnect() {this.disconnected = true; this.observing = false;}
  }
  const window = {matchMedia: () => ({matches: !!options.reduced}),
    setTimeout(callback, ms) {assert.equal(ms, 700); timers.set(++nextTimer, callback); return nextTimer;},
    clearTimeout(id) {timers.delete(id);},
    addEventListener(name, fn) {listeners.set(name, fn);}, removeEventListener(name, fn) {if (listeners.get(name) === fn) listeners.delete(name);}};
  const context = vm.createContext({document, window, MutationObserver});
  vm.runInContext(emitted.script, context);
  return {root, elements, timers, listeners, observers, rerun() {vm.runInContext(emitted.script, context);},
    mutate(state) {root.state = state; for (const o of observers) if (o.observing) o.callback();},
    setLoading(value) {if (value) classes.add('woocommerce-admin-is-loading'); else classes.delete('woocommerce-admin-is-loading'); for (const o of observers) if (o.observing) o.callback();},
    timeout() {for (const callback of [...timers.values()]) callback();},
    hide() {listeners.get('pagehide')?.();}};
}
function check(condition, message) {checks++; assert(condition, message);}
function cleaned(f) {
  check(!f.elements.has('pc-workspace-react-expect'), 'render blocker removed');
  check(f.timers.size === 0 && f.listeners.size === 0 && f.observers.every(x => x.disconnected), 'timer/observer/pagehide cleaned');
  check(f.elements.has('pc-workspace-react-ready'), 'bounded marker exists');
}
for (const state of ['empty', 'tracking']) {
  const f = fixture({state});
  check(f.elements.has('pc-workspace-react-expect') && !f.elements.has('pc-workspace-react-ready'), state + ' must not release');
  check(f.timers.size === 1 && f.observers.length === 1, 'only one bounded observer');
  f.rerun();
  check(f.observers.length === 1, 'duplicate inline run does not add gate');
  f.mutate('layout'); cleaned(f);
}
for (const state of ['layout', 'error']) {
  const f = fixture({state});
  check(f.elements.size === 0 && f.timers.size === 0 && f.observers.length === 0, 'already-present ' + state + ' needs no wait');
}
{
  const f = fixture({state:'layout', loading:true});
  check(f.elements.has('pc-workspace-react-expect'), 'hidden initial Woo layout is not paint-ready');
  f.setLoading(false); cleaned(f);
}
{
  const f = fixture({loading:true});
  f.mutate('layout');
  check(f.elements.has('pc-workspace-react-expect'), 'layout commit alone does not release Woo display:none');
  f.setLoading(false); cleaned(f);
}
{
  const f = fixture({state:'error', loading:true});
  check(f.elements.size === 0 && f.timers.size === 0, 'native error boundary is never hidden by the additional gate');
}
for (const done of ['error', 'timeout', 'hide', 'removed']) {
  const f = fixture();
  if (done === 'error') f.mutate('error');
  if (done === 'timeout') f.timeout();
  if (done === 'hide') f.hide();
  if (done === 'removed') {f.root.isConnected = false; f.mutate('empty');}
  cleaned(f);
  f.mutate('layout');
  check(f.timers.size === 0, 'late mutation cannot reactivate completed gate');
}
for (const options of [{embedded:true}, {notWoo:true}, {noRoot:true}, {noHead:true}, {reduced:true}, {expectSupport:false}]) {
  const f = fixture(options);
  check(f.elements.size === 0 && f.timers.size === 0 && f.observers.length === 0, 'excluded context releases through ordinary parser marker');
}
cleaned(fixture({appendThrows:true}));
console.log(JSON.stringify({status:'passed', checks, actual_php_emitted_helper:true, wordpress_bootstrap:false, database:false, real_browser:false}, null, 2));
