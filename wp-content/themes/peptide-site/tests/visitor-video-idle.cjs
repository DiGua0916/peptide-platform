'use strict';
// visitor-video.js must not run its 1 s progress clock on pages without video (mobile battery/CPU).
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/visitor-video.js'), 'utf8');
class HTMLVideoElement { constructor() { this.isConnected = true; this.paused = true; this.ended = false; this.seeking = false; this.readyState = 0; this.listeners = {}; } addEventListener(t, f) { (this.listeners[t] ||= []).push(f); } removeEventListener() {} getAttribute() { return null; } closest() { return null; } }
let intervals = 0, cleared = 0, observerCallback = null;
const videos = [];
const doc = { nodeType: 9, visibilityState: 'visible', addEventListener() {}, removeEventListener() {}, querySelectorAll: sel => (sel === 'video' ? videos : []), contains: () => true };
const view = {
  HTMLVideoElement, performance: { now: () => 0 },
  setInterval: () => ++intervals, clearInterval: () => { cleared++; },
  addEventListener() {}, removeEventListener() {},
  MutationObserver: class { constructor(cb) { observerCallback = cb; } observe() {} disconnect() {} },
};
doc.defaultView = view;
const win = {};
vm.runInNewContext(source, { window: win });

const handle = win.VialCoveVisitorVideo.observe(doc, () => {});
assert.strictEqual(intervals, 0, 'no video → no interval timer');

const video = new HTMLVideoElement(); video.querySelectorAll = () => [];
observerCallback([{ addedNodes: [video] }]);
assert.strictEqual(intervals, 1, 'a video added later starts the clock once');
video.isConnected = false;
observerCallback([{ addedNodes: [] }]);
assert.strictEqual(cleared, 1, 'the clock stops when the last video leaves');
handle.stop();
assert.strictEqual(intervals, 1, 'stop() starts nothing new');
console.log('PASS visitor video idle clock (4 checks)');
