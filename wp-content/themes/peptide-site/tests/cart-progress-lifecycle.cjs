'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.join(__dirname, '../assets/live-cart-progress.js');
const { normalize, decision, mascotTier } = require(file);

function state(amount, changes = {}) {
    const thresholds = [30000, 50000, 100000], rewards = [2500, 5500, 12000];
    const discount = amount >= thresholds[0], tier = thresholds.filter(value => amount >= value).length - 1;
    const nodes = thresholds.map((threshold, index) => ({ key: 'native-tier-' + index, threshold: String(threshold), thresholdText: '$' + threshold / 100,
        rewardText: '$' + rewards[index] / 100 + ' off', reached: amount >= threshold, celebrationEligible: discount && amount >= threshold, positionPercent: threshold / 1000 }));
    const next = nodes.find(node => !node.reached);
    return { version: 1, enabled: true, visible: true, status: amount >= 100000 ? 'complete' : discount ? 'tier_reached' : 'tracking', stateKey: 'cart-' + amount,
        promotionRevision: 'native-rule-1', revision: 1, basis: 'spend', measure: String(amount), measureText: '$' + amount / 100,
        progressPercent: Math.min(100, amount / 1000), nodes, promotion: { id: 1 }, celebration: { enabled: true, smileEnabled: true },
        completed: amount >= 100000, discountApplied: discount, actualDiscount: { minor: String(discount ? rewards[tier] : 0), text: '$' + (discount ? rewards[tier] / 100 : 0) },
        next: next ? { ...next, remaining: String(Number(next.threshold) - amount), remainingText: '$' + (Number(next.threshold) - amount) / 100 } : null, ...changes };
}

function harness({ reduced = false, deferredImage = false, context = true } = {}) {
    const listeners = new Map(), docListeners = new Map(), raf = new Map(), timers = new Map(), canvasOps = [];
    let serial = 0, observed = null, resolveImage = null;
    class Element {
        constructor(tag) {
            this.tagName = tag; this.children = []; this.parentElement = null; this.hidden = false; this.dataset = {}; this.attributes = {}; this.className = ''; this._text = ''; this.bounds = null;
            this.style = { setProperty: (key, value) => { this.style[key] = value; } };
            this.classList = { contains: name => this.className.split(' ').includes(name), add: (...names) => { this.className = [...new Set([...this.className.split(' ').filter(Boolean), ...names])].join(' '); }, remove: name => { this.className = this.className.split(' ').filter(value => value !== name).join(' '); }, toggle: (name, force) => { if (force) this.classList.add(name); else this.classList.remove(name); } };
        }
        append(...nodes) { for (const node of nodes) { node.parentElement = this; this.children.push(node); } }
        replaceChildren(...nodes) { this.children.forEach(node => { node.parentElement = null; }); this.children = []; this.append(...nodes); }
        remove() { if (this.parentElement) { this.parentElement.children.splice(this.parentElement.children.indexOf(this), 1); this.parentElement = null; } }
        set textContent(value) { this._text = String(value); this.replaceChildren(); } get textContent() { return this._text + this.children.map(node => node.textContent).join(''); }
        setAttribute(key, value) { this.attributes[key] = String(value); } removeAttribute(key) { delete this.attributes[key]; }
        querySelectorAll(selector) { const matches = node => selector[0] === '.' ? node.classList.contains(selector.slice(1)) : node.tagName === selector; return this.children.flatMap(node => [...(matches(node) ? [node] : []), ...node.querySelectorAll(selector)]); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        getBoundingClientRect() {
            if (this.hidden) return { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 };
            if (this.bounds) return this.bounds;
            const position = parseFloat((this.className.endsWith('__node') ? this : this.parentElement)?.style['--pc-node-position']) || 0;
            if (this.className.endsWith('__node-label')) return { left: position * 3 - 22, right: position * 3 + 22, top: 200, bottom: 240, width: 44, height: 40 };
            return { left: 0, right: 340, top: 100, bottom: 400, width: 340, height: 300 };
        }
        getContext() { return context ? new Proxy({}, { get: (_, name) => name === 'drawImage' ? (...args) => canvasOps.push(args) : () => {} }) : null; }
    }
    const document = { hidden: false, createElement: tag => new Element(tag), createElementNS: (_, tag) => new Element(tag), createTextNode: text => { const node = new Element('#text'); node.textContent = text; return node; }, addEventListener: (name, fn) => docListeners.set(name, fn), removeEventListener: name => docListeners.delete(name) };
    const media = { matches: reduced, addEventListener: (name, fn) => listeners.set('media:' + name, fn), removeEventListener: name => listeners.delete('media:' + name) };
    class Image {
        constructor() { this.complete = !deferredImage; this.naturalWidth = deferredImage ? 0 : 1920; }
        decode() { return new Promise(resolve => { resolveImage = () => { this.complete = true; this.naturalWidth = 1920; this.onload?.(); resolve(); }; }); }
    }
    const window = { VialCoveLiveCartConfig: { mascotUrl: 'https://shop.test/live-cart-bean.png' }, innerWidth: 390, innerHeight: 844, matchMedia: () => media, addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name) };
    const root = new Element('div'); root.className = 'cart-progress';
    vm.runInNewContext(fs.readFileSync(file, 'utf8'), { window, document, location: { href: 'https://shop.test/cart/' }, URL, Image,
        requestAnimationFrame: fn => { raf.set(++serial, fn); return serial; }, cancelAnimationFrame: id => raf.delete(id), setTimeout: fn => { timers.set(++serial, fn); return serial; }, clearTimeout: id => timers.delete(id),
        IntersectionObserver: class { constructor(fn) { observed = fn; } observe() {} disconnect() { observed = null; } }, ResizeObserver: class { observe() {} disconnect() {} } });
    const ui = window.createCartProgress({ mount: root });
    return { ui, root, media, raf, timers, canvasOps, listeners, docListeners, document,
        select: name => root.querySelector('.pc-activity-listener__' + name),
        step: time => { const callbacks = [...raf.values()]; raf.clear(); callbacks.forEach(fn => fn(time)); },
        intersect: value => observed([{ isIntersecting: value }]),
        offscreen: () => { root.bounds = { left: 0, right: 340, top: 1000, bottom: 1400, width: 340, height: 400 }; observed([{ isIntersecting: false }]); },
        reenter: () => { root.bounds = null; observed([{ isIntersecting: true }]); },
        visibility: hidden => { document.hidden = hidden; docListeners.get('visibilitychange')(); },
        resolveImage: () => resolveImage?.() };
}

test('still mascot uses only a positive applied native eligible tier and respects both toggles', () => {
    assert.equal(mascotTier(normalize(state(50160))), 'native-tier-1');
    for (const value of [state(25080), state(50160, { discountApplied: false }), state(50160, { actualDiscount: { minor: '0', text: '$0' } }),
        state(50160, { celebration: { enabled: false, smileEnabled: true } }), state(50160, { celebration: { enabled: true, smileEnabled: false } })]) assert.equal(mascotTier(normalize(value)), null);
    const noEligible = state(50160); noEligible.nodes.forEach(node => { node.celebrationEligible = false; }); assert.equal(mascotTier(noEligible), null);
    assert.equal(mascotTier(null), null);
});

test('initial attained $500 tier shows a still duck without replaying a threshold event', () => {
    const h = harness(), dto = state(50160); h.ui.update(dto);
    assert.equal(decision(null, dto).celebrate, null); assert.equal(h.select('mascot').hidden, false); assert.equal(h.root.classList.contains('has-mascot'), true);
    assert.equal(h.select('mascot').style['--pc-node-position'], '170px'); assert.equal(h.select('live').textContent, ''); assert.equal(h.raf.size, 0); assert.equal(h.timers.size, 0);
    assert.equal(h.select('confetti').children.length, 0); assert.ok(h.canvasOps.length > 0);
});

test('below-tier, disabled, unavailable canvas or not-yet-loaded mascot has no reserved duck space', () => {
    for (const [options, dto] of [[{}, state(25080)], [{ context: false }, state(50160)], [{ deferredImage: true }, state(50160)], [{}, state(50160, { celebration: { enabled: true, smileEnabled: false } })]]) {
        const h = harness(options); h.ui.update(dto); assert.equal(h.root.classList.contains('has-mascot'), false); assert.equal(h.select('mascot').hidden, true);
    }
    const css = fs.readFileSync(path.join(__dirname, '../assets/live-cart-progress.css'), 'utf8');
    assert.match(css, /__milestones\s*\{[^}]*margin: 12px 12px 0 0;/s);
    assert.match(css, /\.has-mascot \.pc-activity-listener__milestones\s*\{ margin-top: 76px;/);
});

test('genuine changed qualifying state animates then returns to a persistent still marker', () => {
    const h = harness(); h.ui.update(state(29900)); h.intersect(true); h.ui.update(state(50160));
    assert.equal(h.root.dataset.celebrating, 'true'); assert.equal(h.raf.size, 1); h.step(0); h.step(700); assert.equal(h.select('confetti').children.length, 16);
    h.step(2900); assert.equal(h.raf.size, 0); assert.equal(h.root.dataset.celebrating, 'false'); assert.equal(h.select('mascot').hidden, false); assert.equal(h.root.classList.contains('has-mascot'), true);
    h.ui.update(state(50160)); assert.equal(h.raf.size, 0);
});

test('highest native tier, consecutive changed states and qualified decrease preserve original decisions', () => {
    assert.equal(mascotTier(state(120000)), 'native-tier-2');
    for (const [a, b, tier] of [[50160, 55000, 1], [55000, 52000, 1], [29900, 120000, 2], [120000, 50160, 1]]) assert.equal(decision(state(a), state(b)).celebrate, 'native-tier-' + tier);
});

test('pending write pauses effects while preserving the last confirmed marker', () => {
    const h = harness(); h.ui.update(state(29900)); h.ui.update(state(50160)); h.ui.pause();
    assert.equal(h.raf.size, 0); assert.equal(h.select('confetti').children.length, 0); assert.equal(h.root.dataset.celebrating, 'false'); assert.equal(h.select('mascot').hidden, false);
    h.ui.update(state(25080)); assert.equal(h.select('mascot').hidden, true); assert.equal(h.root.classList.contains('has-mascot'), false);
});

test('offscreen and hidden lifecycles stop animations; returning restores only the native still marker', () => {
    const h = harness(); h.ui.update(state(29900)); h.ui.update(state(50160)); h.offscreen(); assert.equal(h.raf.size, 0); assert.equal(h.select('mascot').hidden, true);
    h.reenter(); assert.equal(h.select('mascot').hidden, false); assert.equal(h.raf.size, 0);
    h.visibility(true); assert.equal(h.select('mascot').hidden, true); h.visibility(false); assert.equal(h.select('mascot').hidden, false); assert.equal(h.raf.size, 0);
    h.listeners.get('pagehide')(); assert.equal(h.select('mascot').hidden, true); h.listeners.get('pageshow')(); assert.equal(h.select('mascot').hidden, false); assert.equal(h.raf.size, 0);
});

test('reduced motion keeps static duck without animation frames or particles', () => {
    const h = harness({ reduced: true }); h.ui.update(state(29900)); h.ui.update(state(50160));
    assert.equal(h.raf.size, 0); assert.equal(h.select('mascot').hidden, false); assert.equal(h.select('confetti').children.length, 0);
    [...h.timers.values()].forEach(fn => fn()); assert.equal(h.select('mascot').hidden, false);
});

test('late image decode cannot revive a downgraded or cleared native tier', async () => {
    for (const mode of ['downgrade', 'clear', 'destroy']) {
        const h = harness({ deferredImage: true }); h.ui.update(state(29900)); h.ui.update(state(50160));
        if (mode === 'downgrade') h.ui.update(state(25080)); else h.ui[mode]();
        h.resolveImage(); await Promise.resolve(); assert.equal(h.raf.size, 0); assert.equal(h.root.classList.contains('has-mascot'), false);
        if (mode !== 'destroy') assert.equal(h.select('mascot').hidden, true);
    }
});

test('removed eligibility clears an old marker and destroy releases listeners, timers and DOM', () => {
    const h = harness(); h.ui.update(state(50160)); const noEligible = state(50160, { stateKey: 'rules-no-eligible' }); noEligible.nodes.forEach(node => { node.celebrationEligible = false; });
    h.ui.update(noEligible); assert.equal(h.select('mascot').hidden, true); assert.equal(h.root.classList.contains('has-mascot'), false);
    h.ui.destroy(); assert.equal(h.root.children.length, 0); assert.equal(h.listeners.size, 0); assert.equal(h.docListeners.size, 0); assert.equal(h.raf.size, 0); assert.equal(h.timers.size, 0);
});
