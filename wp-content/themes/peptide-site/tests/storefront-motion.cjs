'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../assets/storefront-motion.js'), 'utf8');

function fixture() {
  const target = () => ({listeners: new Map(), addEventListener(type, fn) { const list = this.listeners.get(type) || []; list.push(fn); this.listeners.set(type, list); }, fire(type, extra = {}) { const event = {defaultPrevented:false, preventDefault() { this.defaultPrevented = true; }, ...extra}; for (const fn of this.listeners.get(type) || []) fn(event); return event; }});
  const document = Object.assign(target(), {documentElement:{dataset:{}}, hidden:false});
  const reduced = Object.assign(target(), {matches:false});
  const window = Object.assign(target(), {matchMedia:() => reduced});
  const animations = [];
  const image = {hidden:false, currentSrc:'/one.webp', isConnected:true, matches:selector => selector === 'img.vc-dialog-product-image', closest:() => ({}), animate() { const animation = {cancelled:false, cancel() { this.cancelled = true; }}; animations.push(animation); return animation; }};
  vm.runInNewContext(source, {window, document});
  return {window, document, reduced, image, animations};
}

test('touch and mouse focus styling never consumes the native close action; keyboard restores modality', () => {
  const f = fixture();
  assert.equal(f.document.documentElement.dataset.vcInput, undefined);
  f.document.fire('keydown', {key:'Tab'});
  assert.equal(f.document.documentElement.dataset.vcInput, 'keyboard');
  for (const type of ['pointerdown', 'touchstart', 'mousedown']) {
    const event = f.document.fire(type);
    assert.equal(event.defaultPrevented, false);
    assert.equal(f.document.documentElement.dataset.vcInput, 'pointer');
    f.document.fire('keydown', {key:'Shift'});
    assert.equal(f.document.documentElement.dataset.vcInput, 'pointer');
    f.document.fire('keydown', {key:'Tab'});
    assert.equal(f.document.documentElement.dataset.vcInput, 'keyboard');
  }
});

test('an authoritative loaded image fades once per source; closing cancels only its animation', () => {
  const f = fixture();
  f.document.fire('load', {target:f.image});
  f.document.fire('load', {target:f.image});
  assert.equal(f.animations.length, 1);
  f.document.fire('close', {target:{contains:image => image === f.image}});
  assert.equal(f.animations[0].cancelled, true);
  f.image.currentSrc = '/two.webp';
  f.document.fire('load', {target:f.image});
  assert.equal(f.animations.length, 2);
});

test('hidden, reduced-motion and pagehide retire image work without hiding or replacing content', () => {
  const f = fixture();
  f.document.fire('load', {target:f.image});
  f.document.hidden = true; f.document.fire('visibilitychange');
  assert.equal(f.animations[0].cancelled, true);
  f.document.hidden = false; f.reduced.matches = true;
  f.image.currentSrc = '/two.webp'; f.document.fire('load', {target:f.image});
  assert.equal(f.animations.length, 1);
  f.reduced.matches = false; f.image.currentSrc = '/three.webp';
  f.document.fire('load', {target:f.image}); f.window.fire('pagehide');
  assert.equal(f.animations[1].cancelled, true);
  assert.equal(f.image.hidden, false);
  assert.equal(f.image.currentSrc, '/three.webp');
});
