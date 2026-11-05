'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { create, decorativeCopy } = require('../assets/campaign-announcement.js');
class Events {
  constructor() { this.handlers = new Map(); }
  addEventListener(type, callback) { if (!this.handlers.has(type)) this.handlers.set(type, new Set()); this.handlers.get(type).add(callback); }
  removeEventListener(type, callback) { this.handlers.get(type)?.delete(callback); if (!this.handlers.get(type)?.size) this.handlers.delete(type); }
  fire(type, event = {}) { for (const callback of [...(this.handlers.get(type) || [])]) callback(event); }
}
class Element extends Events {
  constructor(tag = 'div', attrs = {}, text = '', width = 150) {
    super(); this.tagName = tag.toUpperCase(); this.attrs = new Map(Object.entries(attrs)); this.children = []; this.parentNode = null; this.text = text; this.width = width; this.scrollLeft = 0; this.clientWidth = 200;
    this.classList = { add: name => this.attrs.set('class', ((this.attrs.get('class') || '') + ' ' + name).trim()) };
  }
  get attributes() { return [...this.attrs].map(([name, value]) => ({ name, value })); }
  get isConnected() { return this.parentNode ? this.parentNode.isConnected : !!this.connected; }
  get layoutWidth() { return this.className === 'vc-campaign-track' ? this.children.reduce((sum, node) => sum + node.layoutWidth, 0) : this.width; }
  get offsetLeft() { if (!this.parentNode) return 0; const siblings = this.parentNode.children; return siblings.slice(0, siblings.indexOf(this)).reduce((sum, node) => sum + node.layoutWidth, 0); }
  get scrollWidth() { return this.children.reduce((sum, node) => sum + node.layoutWidth, 0); }
  get textContent() { return this.text + this.children.map(node => node.textContent).join(''); }
  setAttribute(key, value) { this.attrs.set(key, String(value)); }
  removeAttribute(key) { this.attrs.delete(key); }
  hasAttribute(key) { return this.attrs.has(key); }
  append(...nodes) { for (const node of nodes) { node.remove(); node.parentNode = this; this.children.push(node); } }
  remove() { if (!this.parentNode) return; this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1); this.parentNode = null; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) { return selector.split(',').some(part => part.startsWith('.') ? (this.attrs.get('class') || '').split(/\s+/).includes(part.slice(1)) : part.toUpperCase() === this.tagName); }
  querySelectorAll(selector) { return this.children.flatMap(child => [ ...(selector === '*' || child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector) ]); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  cloneNode(deep) { const copy = new Element(this.tagName, Object.fromEntries(this.attrs), this.text, this.width); if (deep) copy.append(...this.children.map(child => child.cloneNode(true))); return copy; }
  getBoundingClientRect() { return { top: this.top ?? 0, bottom: (this.top ?? 0) + 36, width:this.layoutWidth }; }
}
function fixture({ reduced = false, width = 200, intersection = true, legacyMotion = false, compositor = false, implementation = create } = {}) {
  const root = new Element('section', { 'data-vc-campaign-strip': '' }); root.connected = true;
  const title = new Element('h2', {}, 'Black Friday'), rail = new Element('div', { class: 'pc-campaign__tiers', tabindex: '0', 'aria-label': 'Promotion offers' }); rail.clientWidth = width;
  const originals = ['$25.00 off · Spend $300.00', '$55.00 off · Spend $500.00', '$120.00 off · Spend $1,000.00'].map((text, index) => {
    const tier = new Element('div', { class: 'pc-campaign__tier', id: 'tier-' + index, 'data-module-item': String(index) }, text);
    return tier;
  });
  rail.append(...originals); root.append(title, rail);
  const document = Object.assign(new Events(), { hidden: false, activeElement: null, readyState: 'complete', querySelectorAll: () => [root] });
  const frames = new Map(), timers = new Map(), durations = new Map(), intersections = [], resizes = [], animations = [];
  if(compositor) document.createElement=tag=>{const element=new Element(tag);element.animate=(keyframes,options)=>{const animation={keyframes,options,currentTime:0,playState:'running',pause(){this.playState='paused';},play(){this.playState='running';},cancel(){this.playState='idle';}};animations.push(animation);return animation;};return element;};
  let serial = 0;
  const motion = Object.assign(new Events(), { matches: reduced });
  if (legacyMotion) { motion.addListener = callback => Events.prototype.addEventListener.call(motion, 'change', callback); motion.removeListener = callback => Events.prototype.removeEventListener.call(motion, 'change', callback); motion.addEventListener = motion.removeEventListener = undefined; }
  const window = Object.assign(new Events(), { document, innerHeight: 800, matchMedia: () => motion,
    requestAnimationFrame(callback) { const id = ++serial; frames.set(id, callback); return id; }, cancelAnimationFrame(id) { frames.delete(id); },
    setTimeout(callback, duration) { const id = ++serial; timers.set(id, callback); durations.set(id, duration); return id; }, clearTimeout(id) { timers.delete(id); durations.delete(id); },
    ResizeObserver: class { constructor(callback) { this.callback = callback; this.nodes = []; resizes.push(this); } observe(node) { this.nodes.push(node); } disconnect() { this.disconnected = true; } },
  });
  if (intersection) window.IntersectionObserver = class { constructor(callback) { this.callback = callback; intersections.push(this); } observe(node) { this.node = node; } disconnect() { this.disconnected = true; } };
  const controller = implementation(root, window);
  return { root, title, rail, originals, document, window, motion, frames, timers, durations, controller, intersections, resizes,
    animations,advance(milliseconds){animations.forEach(animation=>{if(animation.playState==='running')animation.currentTime+=milliseconds;});},
    copies: () => (rail.children[0]?.className==='vc-campaign-track'?rail.children[0].children:rail.children).filter(node => node.hasAttribute('data-vc-campaign-copy')),
    visible(value) { intersections.forEach(observer => observer.callback([{ target: root, isIntersecting: value }])); },
    step(time = 0) { const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback(time)); },
    finish() { const pending = [...timers.values()]; timers.clear(); durations.clear(); pending.forEach(callback => callback()); },
    resize() { resizes.forEach(observer => observer.callback([])); },
  };
}
test('only the right native rail moves at 26px/s while the title stays in place', () => {
  const f = fixture(); f.visible(true); f.step(0);
  for (let time = 20; time <= 1000; time += 20) f.step(time);
  assert.ok(Math.abs(f.rail.scrollLeft - 26) < 0.0001);
  assert.equal(f.title.parentNode, f.root); assert.equal(f.title.textContent, 'Black Friday'); assert.equal(f.title.scrollLeft, 0);
  assert.deepEqual(f.rail.children.slice(0, 3), f.originals); f.controller.destroy();
});
test('rounded browser scroll reads cannot stall the fractional speed or double it', () => {
  const f = fixture(); let actual = 0;
  Object.defineProperty(f.rail, 'scrollLeft', { get: () => actual, set: value => { actual = Math.round(value); } });
  f.visible(true); f.step(0); for (let time = 20; time <= 1000; time += 20) f.step(time);
  assert.equal(f.rail.scrollLeft, 26); f.controller.destroy();
});
test('one decorative set gives a continuous wrap without duplicating the real tiers', () => {
  const f = fixture(); f.visible(true); assert.equal(f.copies().length, 3);
  f.rail.scrollLeft = 449; f.step(100); f.step(160);
  assert.ok(Math.abs(f.rail.scrollLeft - 0.56) < 0.001);
  assert.equal(create(f.root, f.window), f.controller); f.controller.refresh(); f.controller.refresh();
  assert.equal(f.copies().length, 3); assert.equal(f.rail.children.length, 6); f.controller.destroy();
});
test('content that fits stays static; resize adds or removes just one decorative set', () => {
  const f = fixture({ width: 600 }); f.visible(true); assert.equal(f.copies().length, 0); assert.equal(f.frames.size, 0);
  f.rail.clientWidth = 200; f.resize(); f.step(); assert.equal(f.copies().length, 3); assert.equal(f.frames.size, 1);
  f.rail.clientWidth = 600; f.resize(); f.step(20); assert.equal(f.copies().length, 0); assert.equal(f.frames.size, 0);
  f.controller.destroy();
});
test('hover and keyboard focus pause immediately and cannot override one another', () => {
  const f = fixture(); f.visible(true);
  f.root.fire('pointerenter', { pointerType: 'mouse' }); assert.equal(f.frames.size, 0);
  f.document.activeElement = f.rail; f.root.fire('focusin'); f.root.fire('pointerleave', { pointerType: 'mouse' }); assert.equal(f.frames.size, 0);
  f.document.activeElement = null; f.root.fire('focusout', { relatedTarget: null }); f.step(); assert.equal(f.frames.size, 1);
  f.root.fire('pointerenter', { pointerType: 'mouse' }); f.root.fire('focusout', { relatedTarget: null }); f.step(); assert.equal(f.frames.size, 0);
  f.controller.destroy();
});
test('touch scrolling stays manual, ignores synthetic hover and resumes after a reading pause', () => {
  const f = fixture(); f.visible(true); f.root.fire('pointerenter', { pointerType: 'touch' }); assert.equal(f.frames.size, 1);
  f.rail.fire('touchstart'); assert.equal(f.frames.size, 0); f.rail.scrollLeft = 100;
  f.rail.fire('touchend'); assert.deepEqual([...f.durations.values()], [2000]); assert.equal(f.frames.size, 0);
  f.finish(); f.step(2000); f.step(2020); assert.ok(Math.abs(f.rail.scrollLeft - 100.52) < 0.001); f.controller.destroy();
});
test('wheel interaction also pauses rather than competing with a manual trackpad scroll', () => {
  const f = fixture(); f.visible(true); f.rail.fire('wheel'); assert.equal(f.frames.size, 0); assert.equal(f.timers.size, 1);
  f.rail.fire('wheel'); assert.equal(f.timers.size, 1); f.finish(); assert.equal(f.frames.size, 1); f.controller.destroy();
});
test('page-hidden and offscreen states stop frames, then resume with no catch-up jump', () => {
  const f = fixture(); assert.equal(f.frames.size, 0); f.visible(true); f.step(0); f.step(20); const position = f.rail.scrollLeft;
  f.document.hidden = true; f.document.fire('visibilitychange'); assert.equal(f.frames.size, 0);
  f.document.hidden = false; f.document.fire('visibilitychange'); f.step(900000); assert.equal(f.rail.scrollLeft, position);
  f.visible(false); assert.equal(f.frames.size, 0); f.visible(true); f.step(910000); assert.equal(f.rail.scrollLeft, position);
  f.step(910020); assert.ok(f.rail.scrollLeft > position); f.controller.destroy();
});
test('reduced motion removes decorative repeats and keeps the original static manual rail', () => {
  const f = fixture({ reduced: true }); f.visible(true); assert.equal(f.copies().length, 0); assert.equal(f.frames.size, 0);
  f.motion.matches = false; f.motion.fire('change'); assert.equal(f.copies().length, 3); assert.equal(f.frames.size, 1);
  f.motion.matches = true; f.motion.fire('change'); assert.equal(f.copies().length, 0); assert.equal(f.frames.size, 0); assert.equal(f.rail.children[0].attrs.get('id'), 'tier-0'); f.controller.destroy();
});
test('the decorative copies are inert, hidden, unlinked and free of IDs or native event hooks', () => {
  const original = new Element('div', { id: 'offer', class: 'pc-campaign__tier', 'data-pc-campaign-slide': '0', 'aria-describedby': 'terms', itemref: 'terms' }, '$25.00');
  const link = new Element('a', { id: 'link', href: '/offer/', 'aria-labelledby': 'offer', onclick: 'unsafe()', target: '_blank' }, 'Terms');
  const button = new Element('button', { name: 'buy', formaction: '/cart/', form: 'cart', 'data-vc-quick-open': '1', autofocus: '' }, 'Action');
  original.append(link, button, new Element('script'), new Element('iframe', { src: 'https://third-party.example/' }));
  const copy = decorativeCopy(original, true);
  assert.equal(copy.inert, true); assert.equal(copy.attrs.get('aria-hidden'), 'true'); assert.ok(copy.hasAttribute('inert'));
  assert.equal(copy.querySelectorAll('script,iframe').length, 0);
  for (const node of [copy, ...copy.querySelectorAll('*')]) for (const { name } of node.attributes) assert.ok(!/^(?:id|href|name|form|formaction|itemref|onclick|aria-describedby|aria-labelledby|data-pc-|data-vc-quick)/.test(name), name);
  assert.equal(copy.querySelector('button').disabled, true); assert.equal(copy.querySelector('a').attrs.get('tabindex'), '-1');
  assert.equal(original.attrs.get('id'), 'offer'); assert.equal(link.attrs.get('href'), '/offer/'); assert.equal(button.hasAttribute('form'), true);
});
test('repeat refreshes never accumulate copies, observers or native control mutations', () => {
  const f = fixture(); f.visible(true);
  for (let i = 0; i < 8; i++) { f.resize(); f.step(i * 20); }
  assert.equal(f.copies().length, 3); assert.equal(f.intersections.length, 1); assert.equal(f.resizes.length, 1);
  assert.equal(f.rail.attrs.get('tabindex'), '0'); assert.equal(f.rail.attrs.get('aria-label'), 'Promotion offers'); f.controller.destroy();
});
test('destroy cancels all frames, holds, listeners and observers and leaves the real DOM intact', () => {
  const f = fixture(); f.visible(true); f.rail.fire('touchend'); f.resize(); const old = [...f.frames.values()];
  f.controller.destroy(); old.forEach(callback => callback(500));
  assert.equal(f.frames.size, 0); assert.equal(f.timers.size, 0); assert.equal(f.copies().length, 0); assert.equal(f.root.vcCampaignMotion, undefined);
  assert.equal(f.root.handlers.size + f.rail.handlers.size + f.document.handlers.size + f.window.handlers.size + f.motion.handlers.size, 0);
  assert.equal(f.intersections[0].disconnected, true); assert.equal(f.resizes[0].disconnected, true); assert.deepEqual(f.rail.children, f.originals);
});
test('BFCache suspension preserves one controller, while ordinary page exit disposes it', () => {
  const f = fixture(); f.visible(true); f.window.fire('pagehide', { persisted: true }); assert.equal(f.frames.size, 0); assert.equal(f.root.vcCampaignMotion, f.controller);
  f.window.fire('pageshow'); f.step(1000); assert.equal(f.frames.size, 1); assert.equal(f.copies().length, 3);
  f.window.fire('pagehide', { persisted: false }); assert.equal(f.root.vcCampaignMotion, undefined); assert.equal(f.frames.size, 0); assert.equal(f.copies().length, 0);
});
test('legacy motion listeners are respected and detached', () => {
  const f = fixture({ legacyMotion: true }); f.visible(true); f.motion.matches = true; f.motion.fire('change'); assert.equal(f.frames.size, 0); f.controller.destroy(); assert.equal(f.motion.handlers.size, 0);
});
test('without IntersectionObserver the real viewport still pauses an offscreen strip', () => {
  const f = fixture({ intersection: false }); assert.equal(f.frames.size, 1);
  f.root.top = -100; f.window.fire('scroll'); assert.equal(f.frames.size, 0);
  f.root.top = 0; f.window.fire('scroll'); assert.equal(f.frames.size, 1); f.controller.destroy();
});
test('the full production bootstrap can run twice without duplicate loops or observers', () => {
  const f = fixture(); f.controller.destroy(); const before = f.intersections.length;
  const source = fs.readFileSync(require.resolve('../assets/campaign-announcement.js'), 'utf8');
  const context = vm.createContext({ window: f.window }); vm.runInContext(source, context); vm.runInContext(source, context);
  assert.equal(f.intersections.length, before + 1); assert.equal(f.copies().length, 3); f.root.vcCampaignMotion.destroy();
});
test('compositor loop preserves the native tier nodes and performs no steady animation-frame or native-scroll writes',()=>{
 const f=fixture({compositor:true});let writes=0,left=f.rail.scrollLeft;
 Object.defineProperty(f.rail,'scrollLeft',{get:()=>left,set:value=>{writes++;left=value;}});
 f.visible(true);const baseline=writes;f.advance(60000);
 assert.equal(f.frames.size,0);assert.equal(writes,baseline);assert.equal(f.animations.length,1);
 assert.deepEqual(f.rail.children[0].children.slice(0,3),f.originals);assert.equal(f.copies().length,3);
 assert.equal(f.animations[0].options.duration,450/.026);assert.equal(f.animations[0].keyframes[1].transform,'translateX(-450px)');
 const copies=f.copies();for(let i=0;i<10;i++){f.controller.refresh();f.resize();f.step();}
 assert.deepEqual(f.copies(),copies);assert.equal(f.animations.length,1,'unchanged width/font notifications retain phase and copies');
 f.controller.destroy();assert.deepEqual(f.rail.children,f.originals);assert.equal(f.animations[0].playState,'idle');
});
test('compositor phase passes exactly to touch scrolling and resumes from the manual position',()=>{
 const f=fixture({compositor:true});f.visible(true);f.advance(1000);f.rail.fire('touchstart');
 assert.equal(f.rail.scrollLeft,26);assert.equal(f.animations[0].playState,'idle');
 f.rail.scrollLeft=100;f.rail.fire('touchend');f.finish();
 assert.equal(f.animations.length,2);assert.equal(f.animations[1].currentTime,100/.026);assert.equal(f.rail.scrollLeft,0);
 f.advance(1000);f.document.activeElement=f.rail;f.root.fire('focusin');assert.ok(Math.abs(f.rail.scrollLeft-126)<.00001);
 f.controller.destroy();
});
if(process.env.VC_CAMPAIGN_BENCHMARK){
 const {execFileSync}=require('node:child_process');
 const baselineSource=execFileSync('git',['show','94f11c1:wp-content/themes/peptide-site/assets/campaign-announcement.js'],{encoding:'utf8'}),baselineModule={exports:{}};
 vm.runInNewContext(baselineSource,{module:baselineModule});
 const sample=implementation=>{
  const f=fixture({implementation,compositor:true});let scrollWrites=0,frames=0,copyRebuilds=0,left=f.rail.scrollLeft;
  Object.defineProperty(f.rail,'scrollLeft',{get:()=>left,set:value=>{scrollWrites++;left=value;}});
  for(const original of f.originals){const clone=original.cloneNode.bind(original);original.cloneNode=deep=>{copyRebuilds++;return clone(deep);};}
  f.visible(true);f.step(0);scrollWrites=0;
  for(let step=1;step<=3600;step++){frames+=f.frames.size;f.step(step*1000/60);f.advance(1000/60);}
  const steady={durationSeconds:60,simulatedFrames:3600,mainThreadAnimationCallbacks:frames,nativeScrollLeftWrites:scrollWrites};
  scrollWrites=0;copyRebuilds=0;for(let i=0;i<10;i++)f.controller.refresh();
  const unchangedRefresh={calls:10,decorativeNodesRebuilt:copyRebuilds,nativeScrollLeftWrites:scrollWrites,copiesRemaining:f.copies().length};
  f.controller.destroy();return{steady,unchangedRefresh,afterDispose:{frames:f.frames.size,timers:f.timers.size,copies:f.copies().length}};
 };
 console.log('VC_CAMPAIGN_BENCHMARK '+JSON.stringify({scope:'Isolated real JS module with model DOM and mocked Web Animations; operation counts only, not browser timing, long tasks or mobile FPS',baselineCommit:'94f11c1',before:sample(baselineModule.exports.create),after:sample(create)}));
}
test('compositor background pause retains phase and reduced motion leaves only native manual tiers',()=>{
 const f=fixture({compositor:true});f.visible(true);f.advance(1000);const animation=f.animations[0];
 f.document.hidden=true;f.document.fire('visibilitychange');f.advance(900000);assert.equal(animation.currentTime,1000);
 f.document.hidden=false;f.document.fire('visibilitychange');assert.equal(f.animations[0],animation);assert.equal(animation.currentTime,1000);
 f.motion.matches=true;f.motion.fire('change');assert.equal(f.copies().length,0);assert.equal(animation.playState,'idle');assert.equal(f.rail.scrollLeft,26);assert.equal(f.frames.size,0);
 f.controller.destroy();assert.deepEqual(f.rail.children,f.originals);
});
