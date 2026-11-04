'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {supportTarget, faqInReadingZone, whatsappTarget, mountSupport} = require('../assets/storefront-utilities.js');

class Events {
 constructor() { this.listeners = new Map(); this.options = new Map(); }
 addEventListener(type, fn, options) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); this.options.set(type, options); }
 removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
 dispatch(type, data = {}) { const event = {type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...data}; for (const fn of [...(this.listeners.get(type) || [])]) fn(event); return event; }
 count() { return [...this.listeners.values()].reduce((sum, entries) => sum + entries.size, 0); }
}
const rect = (top, bottom, width = 375) => ({top, bottom, width, height: bottom - top});
function fixture({href = 'https://shop.example.test/', faq = true, faqTop = 900, navigation = false, header = true, absent = false, intersection = true} = {}) {
 const document = new Events(), window = new Events(), frames = new Map(), observers = [], intersections = [];
 let frameId = 0;
 function node(parent = null) {
  const item = new Events(), classes = new Set(), values = new Map();
  return Object.assign(item, {
   parent, parentElement: parent, attrs: {}, tabIndex: -1, display: 'block', position: 'static', box: rect(0, 48.25), focusCalls: [], nativeClicks: 0,
   classList: {toggle(name, value) { if (value) classes.add(name); else classes.delete(name); }, contains(name) { return classes.has(name); }},
   style: {setProperty(name, value) { values.set(name, value); }, getPropertyValue(name) { return values.get(name); }},
   setAttribute(name, value) { this.attrs[name] = value; }, getAttribute(name) { return this.attrs[name]; },
   focus(options) { document.activeElement = this; this.focusCalls.push(options); },
   contains(target) { for (let current = target; current; current = current.parent) if (current === this) return true; return false; },
   closest(selector) { assert.equal(selector, 'a[href]'); for (let current = this; current; current = current.parent) if (typeof current.href === 'string') return current; return null; },
   getBoundingClientRect() { return this.box; },
   click() { this.nativeClicks++; document.dispatch('click', {target: this}); }
  });
 }
 const widget = node(), trigger = node(widget), contact = node(widget), outside = node(), nav = navigation ? node() : null, faqNode = faq ? node() : null, headerNode = header ? node() : null;
 if (faqNode) faqNode.box = rect(faqTop, faqTop + 1000);
 if (headerNode) { headerNode.box = rect(0, 80); headerNode.position = 'sticky'; }
 contact.href = 'https://wa.me/15555550123?text=Test'; // Synthetic URL, never a real network request.
 widget.querySelector = selector => selector === '.vc-help-float' ? trigger : selector === '.vc-help-contact' ? contact : null;
 trigger.attrs['aria-expanded'] = 'false'; contact.attrs['aria-hidden'] = 'true';
 document.querySelector = selector => selector === '[data-vc-help]' ? (absent ? null : widget) : selector === '.pc-mobile-fixed' ? nav : selector === '#vc-questions' ? faqNode : selector === '[data-vc-demo-header]' ? headerNode : null;
 document.activeElement = outside;
 Object.assign(window, {
  location: new URL(href), innerHeight: 844,
  getComputedStyle(item) { return {display: item.display, position: item.position}; },
  requestAnimationFrame(fn) { const id = ++frameId; frames.set(id, fn); return id; },
  cancelAnimationFrame(id) { frames.delete(id); },
  ResizeObserver: class { constructor(fn) { this.callback = fn; this.disconnected = false; this.targets = new Set(); observers.push(this); } observe(target) { this.targets.add(target); } disconnect() { this.disconnected = true; } }
 });
 if (intersection) window.IntersectionObserver = class { constructor(fn, options) { this.callback = fn; this.options = options; this.disconnected = false; intersections.push(this); } observe(target) { this.target = target; } disconnect() { this.disconnected = true; } };
 const controller = mountSupport(document, window);
 function click(target) { const event = {target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }}; target.dispatch('click', event); return document.dispatch('click', event); }
 const f = {document, window, widget, trigger, contact, outside, nav, faq: faqNode, header: headerNode, observers, intersections, frames, controller, node, click,
  expanded() { return widget.classList.contains('is-expanded'); },
  flush() { const pending = [...frames]; frames.clear(); for (const [, fn] of pending) fn(); },
  placeFAQ(top, bottom = top + 1000) { faqNode.box = rect(top, bottom); },
  scroll() { window.dispatch('scroll'); f.flush(); }
 };
 return f;
}

test('only local FAQ navigation targets are recognized', () => {
 const location = new URL('https://shop.example.test/shop/');
 for (const href of ['#vc-questions', '#vc-contact-support', '/shop/#vc-questions']) assert.equal(supportTarget({href}, location), true);
 for (const href of ['#vc-order-support', '/#vc-questions', '/faq/#vc-questions', 'https://other.example.test/shop/#vc-questions', 'javascript:alert(1)']) assert.equal(supportTarget({href}, location), false);
 assert.equal(supportTarget(null, location), false);
});

test('reading geometry rejects one-pixel contact and uses the sticky-header-adjusted reading line', () => {
 assert.equal(faqInReadingZone(rect(843, 1843), 844, 80), false);
 assert.equal(faqInReadingZone(rect(348, 1348), 844, 80), false);
 assert.equal(faqInReadingZone(rect(340, 1340), 844, 80), true);
 assert.equal(faqInReadingZone(rect(-700, 300), 844, 80), false);
 assert.equal(faqInReadingZone(rect(0, 81), 844, 80), false);
 assert.equal(faqInReadingZone(rect(400, 1400), 844, 0), false);
 assert.equal(faqInReadingZone(rect(400, 1400), 844, 300), true);
 assert.equal(faqInReadingZone(rect(0, 1000), 844, 844), false);
 assert.equal(faqInReadingZone(rect(0, 1000, 0), 844), false);
 assert.equal(faqInReadingZone(null, 844), false);
});

test('outside FAQ only a valid configured WhatsApp target can be launched', () => {
 const location = new URL('https://shop.example.test/');
 assert.equal(whatsappTarget({href: 'https://wa.me/15555550123?text=Test'}, location), true);
 assert.equal(whatsappTarget({href: 'https://wa.me/1555123'}, location), true, 'match the PHP URL validator seven-digit minimum');
 assert.equal(whatsappTarget({href: 'https://wa.me/123456789012345'}, location), true);
 assert.equal(whatsappTarget({href: 'https://wa.me/1234567890123456'}, location), false);
 for (const href of ['https://other.example.test/15555550123', 'http://wa.me/15555550123', 'https://wa.me/123', 'https://user@wa.me/15555550123', 'https://wa.me:444/15555550123', 'https://wa.me/15555550123#fragment', 'javascript:alert(1)']) assert.equal(whatsappTarget({href}, location), false);
});

test('absent configured markup binds nothing', () => {
 const f = fixture({absent: true, navigation: true});
 assert.equal(f.controller, null);
 assert.equal(f.document.count() + f.window.count() + f.trigger.count(), 0);
 assert.equal(f.observers.length + f.intersections.length, 0);
});

test('without FAQ a stale hash or API cannot expand; ball can click the real contact link', () => {
 const f = fixture({faq: false, header: false, href: 'https://shop.example.test/#vc-questions'});
 assert.ok(f.controller); assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '0px');
 f.controller.setExpanded(true); f.window.dispatch('hashchange'); f.flush(); assert.equal(f.expanded(), false);
 f.click(f.trigger); assert.equal(f.expanded(), false); assert.equal(f.contact.nativeClicks, 1);
 f.contact.href = 'javascript:alert(1)'; f.click(f.trigger); assert.equal(f.contact.nativeClicks, 1);
 assert.equal(f.contact.tabIndex, -1); f.controller.destroy();
});

test('FAQ entry auto expands without a bottom bar and leaving retracts despite stale FAQ hash', () => {
 const f = fixture({href: 'https://shop.example.test/#vc-questions'});
 assert.equal(f.expanded(), false);
 f.placeFAQ(340); f.scroll(); assert.equal(f.expanded(), true);
 assert.equal(f.trigger.getAttribute('aria-expanded'), 'true'); assert.equal(f.contact.getAttribute('aria-hidden'), 'false'); assert.equal(f.contact.tabIndex, 0);
 f.placeFAQ(-800, 200); f.scroll(); assert.equal(f.window.location.hash, '#vc-questions'); assert.equal(f.expanded(), false);
 assert.equal(f.trigger.getAttribute('aria-expanded'), 'false'); assert.equal(f.contact.getAttribute('aria-hidden'), 'true'); assert.equal(f.contact.tabIndex, -1);
 f.controller.destroy();
});

test('FAQ anchor preserves native scrolling and waits for real geometry rather than its hash', () => {
 const f = fixture(), link = f.node(), icon = f.node(link); link.href = '/#vc-questions';
 assert.equal(f.click(icon).defaultPrevented, false); f.flush(); assert.equal(f.expanded(), false);
 f.placeFAQ(120); f.scroll(); assert.equal(f.expanded(), true);
 f.placeFAQ(900); f.scroll();
 for (const href of ['/#vc-order-support', '/faq/#vc-questions', 'https://other.example.test/#vc-questions']) { link.href = href; assert.equal(f.click(icon).defaultPrevented, false); f.flush(); assert.equal(f.expanded(), false); }
 f.controller.destroy();
});

test('manual close remains dismissed during the same FAQ visit and resets after leaving', () => {
 const f = fixture({faqTop: 120}); assert.equal(f.expanded(), true);
 f.click(f.trigger); assert.equal(f.expanded(), false); f.window.dispatch('hashchange'); f.scroll(); assert.equal(f.expanded(), false);
 f.click(f.trigger); assert.equal(f.expanded(), true); f.click(f.trigger); assert.equal(f.expanded(), false);
 f.placeFAQ(-800, 200); f.scroll(); f.placeFAQ(120); f.scroll(); assert.equal(f.expanded(), true);
 f.controller.destroy();
});

test('Escape and outside click dismiss this visit and only restore support-owned focus', () => {
 const f = fixture({faqTop: 120}); f.contact.focus(); f.document.dispatch('keydown', {key: 'Escape'});
 assert.equal(f.expanded(), false); assert.equal(f.document.activeElement, f.trigger); assert.deepEqual(f.trigger.focusCalls, [{preventScroll: true}]);
 f.scroll(); assert.equal(f.expanded(), false);
 f.controller.setExpanded(true); f.outside.focus(); f.document.dispatch('keydown', {key: 'Escape'}); assert.equal(f.document.activeElement, f.outside); assert.equal(f.trigger.focusCalls.length, 1);
 f.controller.setExpanded(true); f.click(f.contact); assert.equal(f.expanded(), true); f.click(f.outside); f.scroll(); assert.equal(f.expanded(), false);
 f.controller.destroy();
});

test('initial visible FAQ opens without hash while offscreen initial FAQ hash stays closed', () => {
 const visible = fixture({faqTop: 120}); assert.equal(visible.expanded(), true); visible.controller.destroy();
 const below = fixture({href: 'https://shop.example.test/#vc-questions'}); below.window.dispatch('hashchange'); below.flush(); assert.equal(below.expanded(), false); below.controller.destroy();
});

test('one bounded IntersectionObserver gates distant scroll and near updates use one passive-scroll RAF', () => {
 const f = fixture({navigation: true}); assert.equal(f.intersections.length, 1);
 const io = f.intersections[0]; assert.equal(io.target, f.faq); assert.equal(io.options.threshold, 0);
 io.callback([{target: f.faq, isIntersecting: false}]); f.flush(); f.window.dispatch('scroll'); assert.equal(f.frames.size, 0);
 f.placeFAQ(120); io.callback([{target: f.faq, isIntersecting: true}]); f.window.dispatch('scroll'); f.window.dispatch('resize'); f.observers[0].callback();
 assert.equal(f.frames.size, 1); assert.equal(f.window.options.get('scroll').passive, true); f.flush(); assert.equal(f.expanded(), true);
 f.placeFAQ(-900, 100); f.scroll(); assert.equal(f.expanded(), false); f.controller.destroy(); assert.equal(io.disconnected, true);
});

test('scroll still follows FAQ geometry when IntersectionObserver is unavailable', () => {
 const f = fixture({intersection: false}); f.placeFAQ(120); f.scroll(); assert.equal(f.expanded(), true); f.placeFAQ(900); f.scroll(); assert.equal(f.expanded(), false); f.controller.destroy();
});

test('native navigation height and FAQ/header resize are observed without creating a new menu', () => {
 const f = fixture({navigation: true}); assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '49px'); assert.equal(f.observers.length, 1);
 for (const target of [f.nav, f.faq, f.header]) assert.ok(f.observers[0].targets.has(target));
 f.nav.box.height = 64.1; f.window.dispatch('resize'); f.flush(); assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '65px');
 f.nav.display = 'none'; f.window.dispatch('resize'); f.flush(); assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '0px');
 f.controller.destroy(); assert.equal(f.observers[0].disconnected, true);
});

test('reading follows the real sticky/fixed ancestor when the header itself is relative', () => {
 const f = fixture({faqTop: 400}); assert.equal(f.expanded(), false);
 const sticky = f.node(); sticky.position = 'sticky'; sticky.box = rect(0, 300);
 f.header.position = 'relative'; f.header.parentElement = sticky;
 f.window.dispatch('resize'); f.flush(); assert.equal(f.expanded(), true);
 sticky.position = 'fixed'; sticky.box = rect(0, 80);
 f.window.dispatch('resize'); f.flush(); assert.equal(f.expanded(), false);
 f.controller.destroy();
});

test('BFCache preserves dismissal and listeners; final pagehide disconnects and cancels all work', () => {
 const f = fixture({navigation: true, faqTop: 120}); f.click(f.trigger); assert.equal(f.expanded(), false);
 const counts = [f.document.count(), f.window.count(), f.trigger.count()]; f.window.dispatch('pagehide', {persisted: true}); assert.deepEqual([f.document.count(), f.window.count(), f.trigger.count()], counts);
 assert.equal(f.observers[0].disconnected, false); assert.equal(f.intersections[0].disconnected, false);
 f.window.dispatch('pageshow', {persisted: true}); f.flush(); assert.equal(f.expanded(), false); f.click(f.trigger); assert.equal(f.expanded(), true);
 f.window.dispatch('resize'); assert.equal(f.frames.size, 1); f.window.dispatch('pagehide', {persisted: false}); assert.equal(f.frames.size, 0);
 assert.equal(f.observers[0].disconnected, true); assert.equal(f.intersections[0].disconnected, true); assert.equal(f.document.count() + f.window.count() + f.trigger.count(), 0);
 f.click(f.trigger); assert.equal(f.expanded(), true); f.intersections[0].callback([{target: f.faq, isIntersecting: true}]); assert.equal(f.frames.size, 0);
});
