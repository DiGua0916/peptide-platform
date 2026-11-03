/* Pure UI models: no WordPress, gateway, HTTP, browser storage or real POST. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.join(__dirname, '../assets/hub.js');
const api = require(file);
const source = fs.readFileSync(file, 'utf8');
const { formSignature } = require('../../peptide-hq/assets/hq-navigation.js');
const checks = [];
function check(name, fn) { fn(); checks.push(name); }
function fixture(options = {}) {
	const queue = [];
	class Element {
		constructor(tag = 'DIV') { this.tagName = tag; this.attrs = {}; this.dataset = {}; this.events = {}; this.isConnected = true; this.focusCalls = 0; this.textContent = ''; this.disabled = false; this.classList = { values: new Set(), contains(v) { return this.values.has(v); }, add(v) { this.values.add(v); }, remove(v) { this.values.delete(v); }, toggle(v, on) { if (on) this.values.add(v); else this.values.delete(v); } }; }
		setAttribute(k, v) { this.attrs[k] = String(v); }
		getAttribute(k) { return Object.hasOwn(this.attrs, k) ? this.attrs[k] : null; }
		hasAttribute(k) { return Object.hasOwn(this.attrs, k); }
		removeAttribute(k) { delete this.attrs[k]; }
		addEventListener(type, fn, opts) { (this.events[type] ||= []).push({ fn, once: !!opts?.once }); }
		removeEventListener(type, fn) { this.events[type] = (this.events[type] || []).filter(l => l.fn !== fn); }
		emit(type, data = {}) { const e = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...data }; for (const listener of [...(this.events[type] || [])]) { if (listener.once) this.removeEventListener(type, listener.fn); listener.fn(e); } return e; }
		focus() { this.focusCalls++; doc.activeElement = this; }
	}
	class Input extends Element { constructor(name, type, value, checked = false) { super('INPUT'); this.name = name; this.type = type; this.value = value; this.checked = checked; } }
	class Button extends Element { constructor(label) { super('BUTTON'); this.type = 'submit'; this.name = 'operation'; this.value = 'save'; this.textContent = label; } }
	const doc = new Element('DOCUMENT'); doc.readyState = 'complete'; doc.documentElement = new Element('HTML');
	const env = new Element('WINDOW'); env.document = doc; env.HTMLButtonElement = Button; env.networkCalls = 0;
	env.fetch = () => { env.networkCalls++; throw Error('No network allowed in UI model'); };
	function payment(settings = {}) {
		const page = new Element(), dialog = new Element('DIALOG'), form = new Element('FORM');
		const opener = new Element('A'), cancel = new Element('A'), x = new Element('A'), picker = new Element('DETAILS');
		const name = new Input('account_name', 'text', settings.name || 'Fixture account');
		const secret = new Input('secret', 'password', '');
		const allSites = new Input('all_sites', 'checkbox', 'yes', true);
		const site = new Input('sites[]', 'checkbox', '11', false);
		const nonce = new Input('_wpnonce', 'hidden', 'fixture-nonce');
		const mode = new Element('SELECT'); mode.name = 'mode'; mode.type = 'select-one'; mode.value = '2';
		const fields = [nonce, name, secret, mode, allSites, site];
		const save = new Button('保存付款方式'), second = new Button('另一个提交');
		const buttons = [save, second]; form.elements = [...fields, ...buttons]; form.method = 'post';
		form.action = 'https://fixture.invalid/wp-admin/admin-post.php';
		form.submit = form.requestSubmit = () => { throw Error('Submission replay forbidden'); };
		if (settings.marker !== undefined) form.setAttribute('data-pchq-reset-on-open', settings.marker);
		dialog.open = !!settings.autoOpen; dialog.dataset.autoOpen = settings.autoOpen ? '1' : '0'; dialog.closeCalls = 0; dialog.showCalls = 0;
		dialog.showModal = function () { this.open = true; this.showCalls++; };
		dialog.close = function (value) { if (!this.open) return; this.open = false; this.closeCalls++; this.returnValue = value; queue.push(() => this.emit('close')); };
		if (settings.unsupported) dialog.showModal = undefined;
		page.querySelector = selector => selector === '[data-pc-pay-account-dialog]' ? dialog : selector === '#pc-pay-config' ? form : selector === '[data-pc-pay-open]' ? opener : null;
		page.querySelectorAll = selector => selector === '[data-pc-pay-open]' ? [opener] : [];
		dialog.querySelector = selector => selector === '[data-pc-pay-all-sites]' ? allSites : selector === '.pc-pay-site-picker' ? picker : selector === 'input[name="account_name"]' ? name : null;
		dialog.querySelectorAll = selector => selector === 'input, select, textarea' ? fields : selector === '[data-pc-pay-cancel]' ? [cancel, x] : [];
		form.querySelectorAll = selector => selector === 'button[type="submit"]' ? buttons : [];
		form.closest = selector => selector === 'dialog' ? dialog : null;
		picker.open = false;
		return { page, dialog, form, opener, cancel, x, picker, name, secret, allSites, site, nonce, mode, save, second, fields };
	}
	let current = options.absent ? null : payment(options);
	doc.querySelector = selector => selector === '.pc-pay-hub' ? current?.page || null : null;
	function replace(settings = {}) { if (current) [current.page, current.dialog, current.form, current.opener].forEach(n => { n.isConnected = false; }); current = settings.absent ? null : payment(settings); return current; }
	return { doc, env, current, replace, flush() { while (queue.length) queue.shift()(); } };
}
check('CommonJS exposes reusable mount', () => assert.equal(typeof api.mount, 'function'));
check('Missing environment exits without side effects', () => assert.equal(api.mount(null), null));
check('Mount marks only enhanced editor for closed-draft dirty guard', () => { const f = fixture(); api.mount(f.env); assert(f.current.form.hasAttribute('data-pchq-reset-on-open')); assert(f.current.dialog.classList.contains('is-enhanced')); });
check('Unsupported dialog retains native edit links and no dirty marker', () => { const f = fixture({ unsupported: true }); api.mount(f.env); assert(!f.current.form.hasAttribute('data-pchq-reset-on-open')); assert(!f.current.opener.emit('click').defaultPrevented); });
check('Open uses original nodes and focuses account name', () => { const f = fixture(); api.mount(f.env); assert(f.current.opener.emit('click').defaultPrevented); assert(f.current.dialog.open); assert(f.doc.documentElement.classList.contains('pc-pay-modal-open')); assert.equal(f.doc.activeElement, f.current.name); });
check('Cancel restores all values and checked states before closing', () => { const f = fixture(); api.mount(f.env); const signature = formSignature(f.current.form); f.current.opener.emit('click'); f.current.name.value = 'Unsaved'; f.current.secret.value = 'synthetic-secret'; f.current.allSites.checked = false; f.current.site.checked = true; f.current.mode.value = '1'; f.current.cancel.emit('click'); f.flush(); assert.equal(formSignature(f.current.form), signature); assert(!f.current.dialog.open); assert.equal(f.doc.activeElement, f.current.opener); assert(!f.doc.documentElement.classList.contains('pc-pay-modal-open')); });
check('X has the same cancellation behavior', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.name.value = 'Draft'; assert(f.current.x.emit('click').defaultPrevented); f.flush(); assert.equal(f.current.name.value, 'Fixture account'); assert(!f.current.dialog.open); });
check('Native Escape cancel restores draft', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.secret.value = 'synthetic-secret'; assert(f.current.dialog.emit('cancel').defaultPrevented); f.flush(); assert.equal(f.current.secret.value, ''); assert(!f.current.dialog.open); });
check('Ordinary native close also discards a non-submitted draft', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.name.value = 'Draft'; f.current.dialog.close(); f.flush(); assert.equal(f.current.name.value, 'Fixture account'); });
check('All-sites toggles picker and Cancel restores picker expansion', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.allSites.checked = false; f.current.allSites.emit('change'); assert(f.current.picker.open); f.current.cancel.emit('click'); assert(!f.current.picker.open); });
check('Direct edit GET upgrades without stale-close stealing focus or clearing lock', () => { const f = fixture({ autoOpen: true }); api.mount(f.env); f.flush(); assert(f.current.dialog.open); assert(f.doc.documentElement.classList.contains('pc-pay-modal-open')); assert.equal(f.doc.activeElement, f.current.name); });
check('First submit remains native with nonce and successful button unchanged', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); const signature = formSignature(f.current.form); const e = f.current.form.emit('submit', { submitter: f.current.save }); assert(!e.defaultPrevented); assert.equal(f.current.nonce.value, 'fixture-nonce'); assert.equal(formSignature(f.current.form), signature); assert.equal(f.current.save.name, 'operation'); assert.equal(f.current.save.value, 'save'); assert(!f.current.save.disabled); assert.equal(f.current.save.getAttribute('aria-disabled'), 'true'); assert.equal(f.current.form.getAttribute('aria-busy'), 'true'); assert.equal(f.env.networkCalls, 0); });
check('Already-prevented submit never enters submitting state', () => { const f = fixture(); api.mount(f.env); f.current.form.emit('submit', { defaultPrevented: true, submitter: f.current.save }); assert(!f.current.form.hasAttribute('aria-busy')); assert(!f.current.form.emit('submit', { submitter: f.current.save }).defaultPrevented); });
check('Repeated submit is prevented without replay or serialization', () => { const f = fixture(); api.mount(f.env); f.current.form.emit('submit', { submitter: f.current.save }); assert(f.current.form.emit('submit', { submitter: f.current.save }).defaultPrevented); assert.equal(f.env.networkCalls, 0); });
check('Cancel cannot mutate a form once native submission starts', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.name.value = 'Submitted value'; f.current.form.emit('submit', { submitter: f.current.save }); f.current.cancel.emit('click'); assert(f.current.dialog.open); assert.equal(f.current.name.value, 'Submitted value'); });
check('Pageshow resumes original labels and permits a later native submit', () => { const f = fixture(); api.mount(f.env); f.current.form.emit('submit', { submitter: f.current.save }); assert.equal(f.current.save.textContent, '正在保存…'); f.env.emit('pageshow'); assert.equal(f.current.save.textContent, '保存付款方式'); assert(!f.current.save.hasAttribute('aria-disabled')); assert(!f.current.form.hasAttribute('aria-busy')); assert(!f.current.form.emit('submit', { submitter: f.current.save }).defaultPrevented); });
check('Pageshow preserves an open user draft and restores its modal lock', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.current.name.value = 'Unsaved browser-back draft'; f.current.secret.value = 'synthetic-draft'; f.env.emit('pageshow'); assert.equal(f.current.name.value, 'Unsaved browser-back draft'); assert.equal(f.current.secret.value, 'synthetic-draft'); assert(f.current.dialog.open); assert(f.doc.documentElement.classList.contains('pc-pay-modal-open')); });
check('Before-swap removes old bindings, closes modal and clears html lock', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.doc.emit('pchq:before-swap'); assert(!f.current.dialog.open); assert(!f.doc.documentElement.classList.contains('pc-pay-modal-open')); assert.equal(f.current.dialog.events.close.length, 0); assert.equal(f.current.form.events.submit.length, 0); assert.equal(f.current.allSites.events.change.length, 0); assert(!f.current.form.hasAttribute('data-pchq-reset-on-open')); });
check('Before-swap does not explicitly refocus old opener', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); const count = f.current.opener.focusCalls; f.doc.emit('pchq:before-swap'); f.flush(); assert.equal(f.current.opener.focusCalls, count); });
check('Fresh payment nodes initialize after swap', () => { const f = fixture(); api.mount(f.env); f.doc.emit('pchq:before-swap'); const next = f.replace({ name: 'Next account' }); f.doc.emit('pchq:after-swap'); assert(next.opener.emit('click').defaultPrevented); assert(next.dialog.open); assert.equal(f.doc.activeElement, next.name); });
check('Old queued close cannot unlock or restore a fresh draft', () => { const f = fixture(); api.mount(f.env); f.current.opener.emit('click'); f.doc.emit('pchq:before-swap'); const next = f.replace(); f.doc.emit('pchq:after-swap'); next.opener.emit('click'); next.name.value = 'New draft'; f.flush(); assert(next.dialog.open); assert.equal(next.name.value, 'New draft'); assert(f.doc.documentElement.classList.contains('pc-pay-modal-open')); });
check('Repeated after-swap keeps one content binding and one global binding', () => { const f = fixture(); api.mount(f.env); for (let i = 0; i < 5; i++) f.doc.emit('pchq:after-swap'); assert.equal(f.current.opener.events.click.length, 1); assert.equal(f.current.form.events.submit.length, 1); assert.equal(f.doc.events['pchq:before-swap'].length, 1); assert.equal(f.doc.events['pchq:after-swap'].length, 1); assert.equal(f.env.events.pageshow.length, 1); });
check('Remounting same submitted DOM restores clean button presentation', () => { const f = fixture(); api.mount(f.env); f.current.form.emit('submit', { submitter: f.current.save }); f.doc.emit('pchq:after-swap'); assert.equal(f.current.save.textContent, '保存付款方式'); assert(!f.current.form.hasAttribute('aria-busy')); assert(!f.current.form.emit('submit', { submitter: f.current.save }).defaultPrevented); });
check('No payment page to payment page and back remains safe', () => { const f = fixture({ absent: true }); api.mount(f.env); const next = f.replace(); f.doc.emit('pchq:after-swap'); assert(next.opener.emit('click').defaultPrevented); f.doc.emit('pchq:before-swap'); f.replace({ absent: true }); f.doc.emit('pchq:after-swap'); f.env.emit('pageshow'); assert(!f.doc.documentElement.classList.contains('pc-pay-modal-open')); });
check('Pageshow remounts only if original nodes were disconnected', () => { const f = fixture(); api.mount(f.env); const old = f.current; const next = f.replace(); f.env.emit('pageshow'); assert.equal(old.form.events.submit.length, 0); assert(next.opener.emit('click').defaultPrevented); assert(next.dialog.open); });
check('Dispose restores pre-existing dirty marker value', () => { const f = fixture({ marker: 'existing-owner' }); const c = api.mount(f.env); c.dispose(); assert.equal(f.current.form.getAttribute('data-pchq-reset-on-open'), 'existing-owner'); });
check('Global dispose is idempotent and removes lifecycle/pageshow listeners', () => { const f = fixture(); const c = api.mount(f.env); c.dispose(); c.dispose(); assert.equal(f.doc.events['pchq:before-swap'].length, 0); assert.equal(f.doc.events['pchq:after-swap'].length, 0); assert.equal(f.env.events.pageshow.length, 0); assert(!f.current.opener.emit('click').defaultPrevented); });
check('Explicit dispose then new mount works without inherited submission state', () => { const f = fixture(); const first = api.mount(f.env); f.current.form.emit('submit', { submitter: f.current.save }); first.dispose(); api.mount(f.env); assert.equal(f.current.form.events.submit.length, 1); assert(!f.current.form.emit('submit', { submitter: f.current.save }).defaultPrevented); });
check('Browser auto boot is singleton across repeated script evaluation', () => { const f = fixture(); const context = { window: f.env, document: f.doc }; vm.runInNewContext(source, context); vm.runInNewContext(source, context); assert(f.env.pcPanapayHub); assert.equal(f.doc.events['pchq:before-swap'].length, 1); assert.equal(f.env.events.pageshow.length, 1); });
check('Browser loading boot waits for DOM ready and still mounts once', () => { const f = fixture(); f.doc.readyState = 'loading'; const context = { window: f.env, document: f.doc }; vm.runInNewContext(source, context); assert(!f.env.pcPanapayHub); f.doc.emit('DOMContentLoaded'); f.doc.emit('DOMContentLoaded'); assert(f.env.pcPanapayHub); assert.equal(f.doc.events['pchq:after-swap'].length, 1); assert.equal(f.current.form.events.submit.length, 1); });
console.log(JSON.stringify({ passed: checks.length, checks, sha256: require('node:crypto').createHash('sha256').update(source).digest('hex'), limits: 'Pure DOM models; no real browser top-layer/focus, native POST, payment API, WordPress or server operations.' }, null, 2));
