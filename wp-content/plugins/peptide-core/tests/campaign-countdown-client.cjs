/* Real client module with deterministic monotonic clock, isolated DOM/fetch boundaries. */
'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict'), path = require('node:path');
let checks = 0, clock = 1000, intervals = new Map(), events = new Map(), nextId = 1, requestCount = 0;
class Element {
 constructor(tag = 'div') { this.tagName = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.hidden = true; this.textContent = ''; this.isConnected = true; }
 append(...children) { this.children.push(...children); }
 replaceChildren(...children) { this.children = children; }
 setAttribute(key, value) { this.attributes[key] = value; }
}
const document = { readyState: 'complete', hidden: false, documentElement: { lang: 'en-US' }, querySelectorAll() { return []; }, createElement(tag) { return new Element(tag); }, addEventListener(k, cb) { events.set(k, cb); }, removeEventListener(k) { events.delete(k); } };
const window = { document, HTMLElement: Element, performance: { now: () => clock }, PCStorefrontI18n: { language: 'en' }, location: { href: 'https://store.test/checkout/', origin: 'https://store.test' }, setInterval(cb) { const id = nextId++; intervals.set(id, cb); return id; }, clearInterval(id) { intervals.delete(id); }, setTimeout() { return 1; }, clearTimeout() {}, addEventListener(k, cb) { events.set(k, cb); }, removeEventListener(k) { events.delete(k); }, fetch: async () => { requestCount++; throw new Error('Synthetic unavailable'); } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/campaign-countdown.js'), 'utf8'), { window, URL, AbortController, Number, console });
const api = window.PCCampaignCountdown;
function check(name, condition) { assert.ok(condition, name); checks++; }
const dto = { version: 1, position: 'checkout', enabled: true, visible: true, state: 'active', promotionId: 37, title: 'Black Friday <script>', tiers: [], starts: 900, ends: 100000, target: 100000, serverNow: 1000 };
const host = new Element(); host.dataset.pcCampaignCountdown = 'checkout';
check('parts split exact seconds', JSON.stringify(api.parts(90061)) === '[1,1,1,1]');
check('ceil prevents early expiry', JSON.stringify(api.parts(0.1)) === '[0,0,0,1]');
check('negative time floor', JSON.stringify(api.parts(-1)) === '[0,0,0,0]');
check('exact deadline no timer', api.phase(dto, dto.ends) === null);
for (const change of [{ visible: false }, { enabled: false }, { state: 'disabled' }, { ends: null }, { ends: NaN }, { ends: '100000' }, { version: 2 }, { position: 'payment' }, { serverNow: 0 }, { starts: 200000 }]) check('untrusted invalid DTO fails closed', api.phase({ ...dto, ...change }, 1000) === null);
const handle = api.mount(host, dto);
check('real module mounts', !host.hidden && host.children.length === 1);
const content = host.children[0], title = content.children[0].children[0], timer = content.children[1];
check('untrusted title is plain text', title.textContent === 'Black Friday <script>' && title.children.length === 0);
check('timer avoids every-second screen reader interruptions', timer.attributes.role === 'timer' && timer.attributes['aria-live'] === 'off');
const firstNumbers = timer.children.filter(c => c.className === 'pc-campaign-countdown__unit').map(c => c.children[0].textContent);
clock += 120000; [...intervals.values()].forEach(cb => cb());
const nextNumbers = host.children[0].children[1].children.filter(c => c.className === 'pc-campaign-countdown__unit').map(c => c.children[0].textContent);
check('monotonic elapsed time changes digits without reset', JSON.stringify(firstNumbers) !== JSON.stringify(nextNumbers));
const up = { ...dto, starts: 1100, target: 1100, state: 'upcoming' };
check('before start uses actual start', api.phase(up, 1000).target === 1100);
check('start boundary naturally uses same activity end', api.phase(up, 1100).target === dto.ends && api.phase(up, 1100).state === 'active');
window.PCStorefrontI18n.language = 'es'; [...intervals.values()].forEach(cb => cb());
check('Spanish label from active frontend language', host.children[0].children[0].children[1].textContent === 'Termina en');
const product = new Element(); product.dataset.pcCampaignCountdown = 'product'; api.mount(product, { ...dto, position: 'product', state: 'upcoming', starts: 1100 });
check('Spanish upcoming label', product.children[0].children[0].children[1].textContent === 'Comienza en');
check('nonmatching placement rejected', handle.update({ ...dto, position: 'product' }) === false && host.hidden);
handle.update(dto); clock += 200000000; [...intervals.values()].forEach(cb => cb());
check('time cannot restart past deadline', host.hidden);
handle.destroy(); check('destroy cancels its timer', intervals.size === 1);
product.isConnected = false; [...intervals.values()].forEach(cb => cb()); check('detached dialog cleans interval', intervals.size === 0);
const external = new Element(); external.dataset.pcCampaignCountdown = 'checkout'; external.dataset.countdownEndpoint = 'https://foreign.test/wp-json/peptide/v1/campaign-countdown';
const x = api.mount(external, dto); x.refresh(); check('foreign endpoints never fetched', requestCount === 0); x.destroy();
console.log(JSON.stringify({ passed: checks, businessRequests: 0, tests: 'real module / fake monotonic DOM and network boundaries' }, null, 2));
