'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {supportTarget, mountSupport} = require('../assets/storefront-utilities.js');

class Events {
    constructor() { this.listeners = new Map(); }
    addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
    removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
    dispatch(type, data = {}) {
        const event = {type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...data};
        for (const fn of [...(this.listeners.get(type) || [])]) fn(event);
        return event;
    }
    count() { return [...this.listeners.values()].reduce((sum, listeners) => sum + listeners.size, 0); }
}

function fixture({href = 'https://shop.example.test/', navigation = false, absent = false} = {}) {
    const document = new Events(), window = new Events(), frames = new Map(), cancelled = [], observers = [];
    let frameId = 0;
    function node(parent = null) {
        const item = new Events(), classes = new Set(), values = new Map();
        return Object.assign(item, {
            parent, attrs: {}, tabIndex: -1, display: 'block', box: {width: 375, height: 48.25}, focusCalls: [],
            classList: {toggle(name, value) { if (value) classes.add(name); else classes.delete(name); }, contains(name) { return classes.has(name); }},
            style: {setProperty(name, value) { values.set(name, value); }, getPropertyValue(name) { return values.get(name); }},
            setAttribute(name, value) { this.attrs[name] = value; }, getAttribute(name) { return this.attrs[name]; },
            focus(options) { document.activeElement = this; this.focusCalls.push(options); },
            contains(target) { for (let current = target; current; current = current.parent) if (current === this) return true; return false; },
            closest(selector) { assert.equal(selector, 'a[href]'); for (let current = this; current; current = current.parent) if (typeof current.href === 'string') return current; return null; },
            getBoundingClientRect() { return this.box; }
        });
    }
    const widget = node(), trigger = node(widget), contact = node(widget), outside = node(), nav = navigation ? node() : null;
    widget.querySelector = selector => selector === '.vc-help-float' ? trigger : selector === '.vc-help-contact' ? contact : null;
    trigger.attrs['aria-expanded'] = 'false'; trigger.attrs['aria-label'] = 'Open WhatsApp support'; contact.attrs['aria-hidden'] = 'true';
    document.querySelector = selector => selector === '[data-vc-help]' ? (absent ? null : widget) : selector === '.pc-mobile-fixed' ? nav : null;
    document.activeElement = outside;
    Object.assign(window, {
        location: new URL(href),
        getComputedStyle(item) { return {display: item.display}; },
        requestAnimationFrame(fn) { const id = ++frameId; frames.set(id, fn); return id; },
        cancelAnimationFrame(id) { cancelled.push(id); frames.delete(id); },
        ResizeObserver: class { constructor(fn) { this.callback = fn; this.disconnected = false; observers.push(this); } observe(target) { this.target = target; } disconnect() { this.disconnected = true; } }
    });
    const controller = mountSupport(document, window);
    function click(target) {
        const event = {target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }};
        target.dispatch('click', event);
        return document.dispatch('click', event);
    }
    return {document, window, widget, trigger, contact, outside, nav, observers, frames, cancelled, controller, node, click,
        expanded() { return widget.classList.contains('is-expanded'); },
        flush() { const pending = [...frames]; frames.clear(); for (const [, fn] of pending) fn(); }
    };
}

test('supportTarget accepts only current-origin/current-path support anchors', () => {
    const location = new URL('https://shop.example.test/shop/');
    for (const href of ['#vc-order-support', '#vc-contact-support', '/shop/#vc-order-support', 'https://shop.example.test/shop/#vc-contact-support']) assert.equal(supportTarget({href}, location), true, href);
    for (const href of ['#vc-questions', '/#vc-order-support', '/faq/#vc-order-support', 'https://other.example.test/shop/#vc-order-support', 'http://shop.example.test/shop/#vc-order-support', 'javascript:alert(1)', 'mailto:support@example.test', 'http://[invalid']) assert.equal(supportTarget({href}, location), false, href);
    assert.equal(supportTarget(null, location), false);
});

test('absence of configured markup adds no support listeners or navigation work', () => {
    const f = fixture({absent: true, navigation: true});
    assert.equal(f.controller, null);
    assert.equal(f.document.count() + f.window.count() + f.trigger.count(), 0);
    assert.equal(f.observers.length, 0);
});

test('without a mobile bottom bar the launcher still mounts and toggles accessible disclosure state', () => {
    const f = fixture();
    assert.ok(f.controller);
    assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '0px');
    f.click(f.trigger);
    assert.equal(f.expanded(), true);
    assert.equal(f.trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(f.trigger.getAttribute('aria-label'), 'Close WhatsApp support');
    assert.equal(f.contact.getAttribute('aria-hidden'), 'false');
    assert.equal(f.contact.tabIndex, 0);
    f.click(f.trigger);
    assert.equal(f.expanded(), false);
    assert.equal(f.trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(f.contact.getAttribute('aria-hidden'), 'true');
    assert.equal(f.contact.tabIndex, -1);
    f.controller.destroy();
});

test('Order support expands without preventing its native scroll; FAQ and unrelated URLs are not intercepted', () => {
    const f = fixture(), link = f.node(), icon = f.node(link);
    link.href = '/#vc-order-support';
    assert.equal(f.click(icon).defaultPrevented, false);
    assert.equal(f.expanded(), true);
    for (const href of ['/#vc-questions', '/faq/#vc-order-support', 'https://other.example.test/#vc-order-support']) {
        f.controller.setExpanded(false); link.href = href;
        assert.equal(f.click(icon).defaultPrevented, false);
        assert.equal(f.expanded(), false, href);
    }
    f.controller.destroy();
});

test('Escape restores trigger focus only when support held focus; outside click collapses', () => {
    const f = fixture();
    f.controller.setExpanded(true); f.contact.focus();
    f.document.dispatch('keydown', {key: 'Escape'});
    assert.equal(f.expanded(), false);
    assert.equal(f.document.activeElement, f.trigger);
    assert.deepEqual(f.trigger.focusCalls, [{preventScroll: true}]);
    f.controller.setExpanded(true); f.outside.focus();
    f.document.dispatch('keydown', {key: 'Escape'});
    assert.equal(f.expanded(), false);
    assert.equal(f.document.activeElement, f.outside);
    assert.equal(f.trigger.focusCalls.length, 1, 'do not steal focus from elsewhere');
    f.controller.setExpanded(true);
    f.click(f.contact);
    assert.equal(f.expanded(), true, 'clicking its own contact link does not fold it prematurely');
    f.click(f.outside);
    assert.equal(f.expanded(), false);
    f.controller.destroy();
});

test('initial and later support hashes expand; unrelated initial hash stays collapsed', () => {
    for (const hash of ['#vc-order-support', '#vc-contact-support']) {
        const f = fixture({href: 'https://shop.example.test/' + hash});
        assert.equal(f.expanded(), true, hash); f.controller.destroy();
    }
    const f = fixture({href: 'https://shop.example.test/#vc-questions'});
    assert.equal(f.expanded(), false);
    f.window.location.hash = '#vc-order-support';
    f.window.dispatch('hashchange');
    assert.equal(f.expanded(), true);
    f.controller.destroy();
});

test('actual mobile navigation is observed and its visible height is reserved without duplicate animation frames', () => {
    const f = fixture({navigation: true});
    assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '49px');
    assert.equal(f.observers.length, 1);
    assert.equal(f.observers[0].target, f.nav);
    f.nav.box.height = 64.1;
    f.window.dispatch('resize'); f.observers[0].callback(); f.window.dispatch('pageshow');
    assert.equal(f.frames.size, 1);
    f.flush();
    assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '65px');
    f.nav.display = 'none'; f.window.dispatch('resize'); f.flush();
    assert.equal(f.widget.style.getPropertyValue('--vc-help-nav-offset'), '0px');
    f.controller.destroy();
    assert.equal(f.observers[0].disconnected, true);
});

test('BFCache pagehide preserves listeners; final pagehide cancels frames, disconnects observer and unbinds', () => {
    const f = fixture({navigation: true});
    const counts = [f.document.count(), f.window.count(), f.trigger.count()];
    f.window.dispatch('pagehide', {persisted: true});
    assert.deepEqual([f.document.count(), f.window.count(), f.trigger.count()], counts);
    assert.equal(f.observers[0].disconnected, false);
    f.click(f.trigger);
    assert.equal(f.expanded(), true, 'the restored BFCache document remains interactive');
    f.window.dispatch('pageshow', {persisted: true});
    assert.equal(f.frames.size, 1);
    f.window.dispatch('pagehide', {persisted: false});
    assert.equal(f.frames.size, 0);
    assert.equal(f.observers[0].disconnected, true);
    assert.equal(f.document.count() + f.window.count() + f.trigger.count(), 0);
    f.click(f.trigger);
    assert.equal(f.expanded(), true, 'destroyed launcher has no click callback');
});
