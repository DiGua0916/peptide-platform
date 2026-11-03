'use strict';
const assert = require('node:assert/strict');
const { createVcConfirmedPurchaseReturn } = require('../assets/product-purchase-return.js');
class Events {
  constructor() { this.handlers = new Map(); }
  addEventListener(type, callback) { if (!this.handlers.has(type)) this.handlers.set(type, new Set()); this.handlers.get(type).add(callback); }
  removeEventListener(type, callback) { this.handlers.get(type)?.delete(callback); }
  emit(type, detail) { for (const callback of [...(this.handlers.get(type) || [])]) callback({ type, detail }); }
  get size() { return [...this.handlers.values()].reduce((sum, callbacks) => sum + callbacks.size, 0); }
}
function fixture({ reduced = false, onCurrent, onClose, aborted = false } = {}) {
  const win = new Events(), doc = new Events(), motion = new Events(), dialog = new Events(), signal = new Events(), timers = new Map();
  let serial = 0;
  const owner = { opener: {}, productId: 11306 }, calls = [];
  Object.assign(doc, { hidden: false }); Object.assign(dialog, { open: true }); Object.assign(signal, { aborted }); Object.assign(motion, { matches: reduced });
  Object.assign(win, {
    matchMedia: () => motion,
    clearTimeout: id => timers.delete(id),
    setTimeout(callback, duration) { const id = ++serial; timers.set(id, { callback, duration }); return id; }
  });
  const controller = createVcConfirmedPurchaseReturn({ dialog, signal, window: win, document: doc,
    isCurrent: context => onCurrent ? onCurrent(context) : dialog.open && context.opener === owner.opener && context.productId === owner.productId,
    close: context => { calls.push(context); onClose?.(context); }
  });
  const begin = (extra = {}) => controller.begin({ mode: 'add', ...owner, ...extra });
  const confirm = (ticket, extra = {}) => controller.confirm(ticket, { confirmed: true, stale: false, presentation: { enabled: true }, ...extra });
  const run = () => { const work = [...timers.values()]; timers.clear(); work.forEach(item => item.callback()); };
  return { controller, win, doc, motion, dialog, signal, timers, owner, calls, begin, confirm, run,
    stop() { controller.destroy(); }, listeners() { return win.size + doc.size + motion.size + dialog.size + signal.size; } };
}
let passed = 0;
function check(name, test) { test(); passed++; console.log('ok', name); }
check('confirmed Add waits for the actual default firework and never closes inside the success event stack', () => {
  const f = fixture(), ticket = f.begin(); f.doc.emit('vc:cart-mutation-start', { source: 'showcase' });
  assert.equal(f.controller.state, 'pending'); assert.equal(f.confirm(ticket), true); assert.equal(f.calls.length, 0);
  assert.deepEqual([...f.timers.values()].map(item => item.duration), [850]); f.run(); assert.equal(f.calls.length, 1); assert.equal(f.controller.state, 'idle'); f.stop();
});
check('only an explicit fresh confirmed result can schedule a return', () => {
  for (const result of [{ confirmed: false }, { confirmed: true, stale: true }, { confirmed: true, stale: undefined }]) {
    const f = fixture(), ticket = f.begin(); assert.equal(f.confirm(ticket, result), false); assert.equal(f.timers.size, 0); assert.equal(f.calls.length, 0); f.stop();
  }
});
check('Buy mode and an invalid ticket cannot become an Add return', () => {
  const f = fixture(); assert.equal(f.begin({ mode: 'buy' }), null); assert.equal(f.confirm({}), false); assert.equal(f.controller.state, 'idle');
  const ticket = f.begin(); assert.equal(f.confirm({}), false); assert.equal(f.controller.state, 'pending'); assert.equal(f.confirm(ticket), true); f.stop();
});
check('disabled, absent or burst-disabled feedback closes only in the next task without forcing effects', () => {
  for (const presentation of [undefined, { enabled: false, burstEnabled: true }, { enabled: true, burstEnabled: false }]) {
    const f = fixture(), ticket = f.begin(); assert.equal(f.confirm(ticket, { presentation }), true); assert.equal(f.calls.length, 0);
    assert.deepEqual([...f.timers.values()].map(item => item.duration), [0]); f.run(); assert.equal(f.calls.length, 1); f.stop();
  }
});
check('reduced motion bypasses the firework wait but preserves asynchronous native paint ordering', () => {
  const f = fixture({ reduced: true }), ticket = f.begin(); f.confirm(ticket);
  assert.equal(f.calls.length, 0); assert.deepEqual([...f.timers.values()].map(item => item.duration), [0]); f.run(); assert.equal(f.calls.length, 1); f.stop();
});
check('wait uses the feedback snapshot and the existing renderer lifetime bounds', () => {
  for (const [duration, expected] of [[100, 600], [720, 720], [9999, 1000], [undefined, 850]]) {
    const f = fixture(), ticket = f.begin(), presentation = { enabled: true, burstDurationMs: duration };
    f.confirm(ticket, { presentation }); presentation.burstDurationMs = 9999;
    assert.deepEqual([...f.timers.values()].map(item => item.duration), [expected]); f.stop();
  }
});
check('duplicate success notifications cannot schedule or close twice', () => {
  const f = fixture(), ticket = f.begin(); assert.equal(f.confirm(ticket), true); assert.equal(f.confirm(ticket), false); assert.equal(f.timers.size, 1);
  f.run(); assert.equal(f.confirm(ticket), false); assert.equal(f.calls.length, 1); f.run(); assert.equal(f.calls.length, 1); f.stop();
});
check('a superseding Add invalidates the old timer even if its callback is already queued', () => {
  const f = fixture(), first = f.begin(); f.confirm(first); const oldCallback = [...f.timers.values()][0].callback;
  const second = f.begin(); assert.notEqual(first, second); assert.equal(f.confirm(first), false); oldCallback(); assert.equal(f.calls.length, 0);
  f.confirm(second); f.run(); assert.equal(f.calls.length, 1); f.stop();
});
check('a new Buy intent cancels an Add return without owning checkout navigation', () => {
  const f = fixture(), ticket = f.begin(); f.confirm(ticket); assert.equal(f.begin({ mode: 'buy' }), null);
  f.run(); assert.equal(f.calls.length, 0); assert.equal(f.controller.state, 'idle'); f.stop();
});
check('a changed product or opener cannot be closed by an earlier confirmation or timer', () => {
  for (const changeAt of ['before-confirm', 'before-close']) {
    const f = fixture(), ticket = f.begin(); if (changeAt === 'before-close') f.confirm(ticket);
    f.owner.opener = {}; f.owner.productId = 11408;
    if (changeAt === 'before-confirm') assert.equal(f.confirm(ticket), false); f.run(); assert.equal(f.calls.length, 0); f.stop();
  }
});
check('a new native mutation after confirmation cancels the return across cart surfaces', () => {
  for (const source of ['showcase', 'drawer', 'live-cart']) {
    const f = fixture(), ticket = f.begin(); f.confirm(ticket); const oldCallback = [...f.timers.values()][0].callback;
    f.doc.emit('vc:cart-mutation-start', { source }); oldCallback(); assert.equal(f.controller.state, 'idle'); assert.equal(f.timers.size, 0); assert.equal(f.calls.length, 0); f.stop();
  }
});
check('unrelated mutation sources and the original request start cannot cancel a valid Add', () => {
  const f = fixture(), ticket = f.begin(); f.doc.emit('vc:cart-mutation-start', { source: 'showcase' }); assert.equal(f.controller.state, 'pending');
  f.confirm(ticket); f.doc.emit('vc:cart-mutation-start', { source: 'unrelated' }); f.run(); assert.equal(f.calls.length, 1); f.stop();
});
check('hidden documents and page navigation cancel instead of closing a later resumed product', () => {
  for (const phase of ['visibilitychange', 'pagehide']) {
    const f = fixture(), ticket = f.begin(); f.confirm(ticket);
    if (phase === 'visibilitychange') { f.doc.hidden = true; f.doc.emit(phase); f.doc.hidden = false; } else f.win.emit(phase);
    f.run(); assert.equal(f.calls.length, 0); assert.equal(f.controller.state, 'idle'); f.stop();
  }
});
check('Back, Escape and native Close cancel the scheduled Add return', () => {
  for (const event of ['back', 'cancel', 'close']) {
    const f = fixture(), ticket = f.begin(); f.confirm(ticket);
    if (event === 'back') f.doc.emit('vc:product-restore-cancel'); else { if (event === 'close') f.dialog.open = false; f.dialog.emit(event); }
    f.run(); assert.equal(f.calls.length, 0); assert.equal(f.controller.state, 'idle'); f.stop();
  }
});
check('a late Close event from an older native session does not cancel a reopened current product', () => {
  const f = fixture(), ticket = f.begin(); f.confirm(ticket); f.dialog.emit('close'); f.run(); assert.equal(f.calls.length, 1); f.stop();
});
check('a reduced-motion change settles the wait in a fresh task and invalidates the older callback', () => {
  const f = fixture(), ticket = f.begin(); f.confirm(ticket); const oldCallback = [...f.timers.values()][0].callback;
  f.motion.matches = true; f.motion.emit('change'); assert.equal(f.calls.length, 0); assert.deepEqual([...f.timers.values()].map(item => item.duration), [0]);
  oldCallback(); assert.equal(f.calls.length, 0); f.run(); oldCallback(); assert.equal(f.calls.length, 1); f.stop();
});
check('ownership and close callback failures cannot escape into native cart success handling', () => {
  const unknown = fixture({ onCurrent() { throw new Error('owner gone'); } }); assert.doesNotThrow(() => assert.equal(unknown.begin(), null)); unknown.stop();
  const f = fixture({ onClose() { throw new Error('native close unavailable'); } }), ticket = f.begin(); f.confirm(ticket); assert.doesNotThrow(f.run); assert.equal(f.controller.state, 'idle'); f.stop();
});
check('abort and repeated disposal clear all listeners and queued work', () => {
  const f = fixture(), ticket = f.begin(); f.confirm(ticket); const oldCallback = [...f.timers.values()][0].callback;
  f.signal.aborted = true; f.signal.emit('abort'); oldCallback(); f.stop();
  assert.equal(f.controller.state, 'destroyed'); assert.equal(f.timers.size, 0); assert.equal(f.listeners(), 0); assert.equal(f.calls.length, 0); assert.equal(f.begin(), null);
});
check('an already-aborted lifecycle never accepts a new Add or leaves listeners behind', () => {
  const f = fixture({ aborted: true }); assert.equal(f.begin(), null); assert.equal(f.controller.state, 'destroyed'); assert.equal(f.listeners(), 0); assert.equal(f.calls.length, 0);
});
console.log(`${passed} confirmed purchase return checks passed`);
