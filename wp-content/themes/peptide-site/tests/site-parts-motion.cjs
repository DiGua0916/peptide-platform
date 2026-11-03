const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/demo-site-parts.js'), 'utf8');

function fixture(options = {}) {
  const makeTarget = () => ({handlers: new Map(), addEventListener(type, fn) { const list = this.handlers.get(type) || []; list.push(fn); this.handlers.set(type, list); }, fire(type, extra = {}) { const event = {target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra}; for (const fn of this.handlers.get(type) || []) fn(event); return event; }});
  const timers = new Map(); let timerId = 0, focus;
  const classes = (...names) => ({items: new Set(names), contains(name) { return this.items.has(name); }, toggle(name, flag) { if (flag) this.items.add(name); else this.items.delete(name); }});
  const animations = [];
  const node = (name) => Object.assign(makeTarget(), {
    name, dataset: {}, attrs: {}, isConnected: true, classList: classes(), paint: {opacity: '1', transform: 'none'}, focused: 0,
    setAttribute(key, value) { this.attrs[key] = value; }, getAttribute(key) { return this.attrs[key] || null; },
    focus() { focus = this; this.focused++; }, getClientRects() { return [{}]; },
    getBoundingClientRect() { return {left: 10, top: 10, right: 390, bottom: 800}; },
    closest(selector) { return this.closestMap?.[selector] || null; }, matches() { return false; },
    querySelector() { return null; }, querySelectorAll() { return []; },
    animate(frames, settings) { if (options.throwAnimation) throw new Error('Animation unavailable'); const animation = {frames, settings, cancelled: false, onfinish: null, cancel() { this.cancelled = true; }, finish() { this.onfinish?.(); }}; animations.push(animation); return animation; }
  });
  const menu = node('menu'), search = node('search'), opener = node('menu-trigger'), searchOpener = node('search-trigger');
  const close = node('close'), searchClose = node('search-close'), input = node('search-input');
  menu.classList = classes('vc-demo-part-dialog', 'vc-demo-menu-dialog'); search.classList = classes('vc-demo-part-dialog', 'vc-demo-search-dialog');
  const rows = Array.from({length: 8}, (_, i) => node('row-' + i));
  const dialogs = [menu, search]; const nativeCloses = [];
  for (const dialog of dialogs) {
    dialog.open = false; dialog.showCount = 0;
    dialog.showModal = () => { if (options.throwShow) throw new Error('Native modal unavailable'); dialog.open = true; dialog.showCount++; };
    dialog.close = () => { dialog.open = false; nativeCloses.push(dialog); };
  }
  if (options.noAnimation) for (const dialog of dialogs) dialog.animate = undefined;
  menu.querySelector = selector => selector === '[data-vc-demo-close]' ? close : null;
  search.querySelector = selector => selector === 'input[type="search"]' ? input : selector === '[data-vc-demo-close]' ? searchClose : null;
  menu.querySelectorAll = selector => selector === ':scope > nav > .vc-demo-nav-list > li' ? rows : [];
  opener.closestMap = {'[data-vc-demo-menu]': opener}; opener.attrs['aria-controls'] = 'menu';
  searchOpener.closestMap = {'[data-vc-demo-search]': searchOpener}; searchOpener.attrs['aria-controls'] = 'search';
  close.closestMap = {'[data-vc-demo-close]': close, dialog: menu}; searchClose.closestMap = {'[data-vc-demo-close]': searchClose, dialog: search};
  const document = Object.assign(makeTarget(), {
    hidden: false, documentElement: {classList: classes()}, body: node('body'), activeElement: null,
    getElementById(id) { return {menu, search}[id]; },
    querySelector(selector) { if (selector === '.vc-demo-part-dialog[open]') return dialogs.find(d => d.open && d.isConnected) || null; if (selector === '[data-vc-catalog-search]') return options.catalogue || null; return null; },
    querySelectorAll(selector) { if (selector === '.vc-demo-part-dialog') return dialogs.filter(d => d.isConnected); if (selector === '.vc-demo-part-dialog[open]') return dialogs.filter(d => d.open && d.isConnected); if (selector === '.vc-demo-menu-dialog[open]') return menu.open ? [menu] : []; return []; }
  });
  const reduced = Object.assign(makeTarget(), {matches: !!options.reduced}); const mobile = Object.assign(makeTarget(), {matches: true});
  const window = Object.assign(makeTarget(), {
    matchMedia(query) { return query.includes('reduced-motion') ? reduced : mobile; },
    setTimeout(fn, ms) { const id = ++timerId; timers.set(id, {fn, ms}); return id; }, clearTimeout(id) { timers.delete(id); }
  });
  let observer;
  class MutationObserver { constructor(fn) { this.fn = fn; observer = this; } observe() {} disconnect() { this.disconnected = true; } }
  vm.runInNewContext(source, {window, document, MutationObserver, getComputedStyle: element => element.paint});
  return {
    window, document, menu, search, opener, searchOpener, close, input, animations, timers, reduced, mobile, observer, nativeCloses, rows,
    click(target) { return document.fire('click', {target, button: 0}); },
    finish() { const entry = animations.findLast(a => !a.cancelled && a.onfinish); entry?.finish(); },
    tick() { const waiting = [...timers.values()]; timers.clear(); waiting.forEach(t => t.fn()); },
    get focus() { return focus; }, node
  };
}

test('native menu opens with bounded compositor animation, list sequence and real focus', () => {
  const f = fixture(); f.click(f.opener);
  assert.equal(f.menu.open, true); assert.equal(f.opener.attrs['aria-expanded'], 'true'); assert.equal(f.focus, f.close);
  assert.equal(f.document.documentElement.classList.contains('vc-demo-part-open'), true);
  assert.equal(f.animations[0].settings.duration, 350); assert.equal(f.animations[0].settings.easing, 'cubic-bezier(.22,1,.36,1)');
  assert.equal(f.animations.length, 7); assert.ok(f.animations.slice(1).every(a => a.settings.duration + a.settings.delay <= 300));
  f.finish(); assert.equal(f.menu.open, true); assert.equal(f.menu.dataset.vcPartMotion, undefined); assert.equal(f.timers.size, 0);
});

test('close keeps native focus protection until exit finishes and restores original opener once', () => {
  const f = fixture(); f.click(f.opener); f.finish(); f.click(f.close);
  assert.equal(f.menu.open, true); assert.equal(f.menu.dataset.vcPartMotion, 'closing'); assert.equal(f.animations.at(-1).settings.duration, 200);
  f.finish(); assert.equal(f.menu.open, false); assert.equal(f.focus, f.opener); assert.equal(f.opener.focused, 1); assert.equal(f.opener.attrs['aria-expanded'], 'false');
  f.menu.fire('close'); assert.equal(f.opener.focused, 1); assert.equal(f.document.documentElement.classList.contains('vc-demo-part-open'), false);
});

test('rapid reversal preserves painted frame and stale finish/native-close cannot close reopened dialog', () => {
  const f = fixture(); f.click(f.opener); f.menu.paint = {opacity: '.7', transform: 'matrix(1,0,0,1,9,0)'};
  f.click(f.close); const closing = f.animations.at(-1), stale = closing.onfinish;
  assert.equal(closing.frames[0].transform, 'matrix(1,0,0,1,9,0)');
  f.menu.paint = {opacity: '.4', transform: 'matrix(1,0,0,1,14,0)'}; f.click(f.opener);
  assert.equal(f.animations.at(-1).frames[0].opacity, '.4'); stale(); f.menu.fire('close');
  assert.equal(f.menu.open, true); assert.equal(f.opener.attrs['aria-expanded'], 'true'); assert.equal(f.menu.showCount, 1);
  f.finish(); assert.equal(f.menu.open, true);
});

test('Escape cancel and outside backdrop request the same bounded exit', () => {
  const f = fixture(); f.click(f.opener); f.finish();
  assert.equal(f.menu.fire('cancel').defaultPrevented, true); assert.equal(f.menu.open, true); f.tick(); assert.equal(f.menu.open, false);
  f.click(f.opener); f.finish(); f.menu.fire('click', {clientX: 0, clientY: 50});
  assert.equal(f.menu.dataset.vcPartMotion, 'closing'); f.finish(); assert.equal(f.menu.open, false);
});

test('menu real links close immediately without preventing or delaying navigation', () => {
  const f = fixture(); f.click(f.opener); const link = f.node('link'); link.closestMap = {'.vc-demo-menu-dialog a[href]': link, dialog: f.menu};
  const event = f.click(link); assert.equal(event.defaultPrevented, false); assert.equal(f.menu.open, false); assert.equal(f.opener.focused, 0); assert.equal(f.timers.size, 0);
});

test('search focuses actual native input and switching dialogs leaves one modal/one scroll lock', () => {
  const f = fixture(); f.click(f.opener); f.click(f.searchOpener);
  assert.equal(f.menu.open, false); assert.equal(f.search.open, true); assert.equal(f.focus, f.input); assert.equal(f.opener.focused, 0);
  assert.equal(f.opener.attrs['aria-expanded'], 'false'); assert.equal(f.searchOpener.attrs['aria-expanded'], 'true');
  assert.equal(f.document.documentElement.classList.contains('vc-demo-part-open'), true); assert.match(f.animations.at(-1).frames[0].transform, /translateY/);
});

test('reduced motion and unsupported or throwing WAAPI preserve immediate native interaction', () => {
  for (const options of [{reduced: true}, {noAnimation: true}, {throwAnimation: true}]) {
    const f = fixture(options); f.click(f.opener); assert.equal(f.menu.open, true); f.click(f.close); assert.equal(f.menu.open, false); assert.equal(f.timers.size, 0);
  }
  const failed = fixture({throwShow: true}); const event = failed.click(failed.searchOpener); assert.equal(failed.search.open, false); assert.equal(event.defaultPrevented, false);
});

test('hidden/reduced changes settle interrupted state without an offscreen animation loop', () => {
  const f = fixture(); f.click(f.opener); f.document.hidden = true; f.document.fire('visibilitychange');
  assert.equal(f.menu.open, true); assert.equal(f.timers.size, 0); assert.equal(f.menu.dataset.vcPartMotion, undefined);
  f.document.hidden = false; f.click(f.close); f.document.hidden = true; f.document.fire('visibilitychange'); assert.equal(f.menu.open, false); assert.equal(f.opener.focused, 0);
  f.document.hidden = false; f.click(f.opener); f.reduced.matches = true; f.reduced.fire('change'); assert.equal(f.menu.open, true); assert.equal(f.timers.size, 0);
});

test('BFCache settles animations, ordinary pagehide closes and disconnects, removed editor roots are pruned', () => {
  const f = fixture(); f.click(f.opener); f.window.fire('pagehide', {persisted: true}); assert.equal(f.menu.open, true); assert.equal(f.timers.size, 0);
  f.menu.isConnected = false; f.observer.fn([{addedNodes: []}]); assert.equal(f.menu.open, false); assert.equal(f.opener.focused, 0);
  f.click(f.searchOpener); f.window.fire('pagehide', {persisted: false}); assert.equal(f.search.open, false); assert.equal(f.timers.size, 0); assert.equal(f.observer.disconnected, true);
});

test('responsive breakpoint settles mobile menu immediately', () => {
  const f = fixture(); f.click(f.opener); f.mobile.matches = false; f.mobile.fire('change');
  assert.equal(f.menu.open, false); assert.equal(f.timers.size, 0); assert.equal(f.opener.attrs['aria-expanded'], 'false');
});

test('temporarily detached builder header can reopen without duplicate native listeners', () => {
  const f = fixture(); f.click(f.opener); f.menu.isConnected = false; f.observer.fn([{addedNodes: []}]);
  assert.equal(f.menu.open, false); f.menu.isConnected = true; f.click(f.opener);
  assert.equal(f.menu.open, true); assert.equal(f.menu.handlers.get('cancel').length, 1); assert.equal(f.menu.handlers.get('close').length, 1);
  f.menu.fire('cancel'); f.finish(); assert.equal(f.menu.open, false); assert.equal(f.opener.focused, 1);
});
