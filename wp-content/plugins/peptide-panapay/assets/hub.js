/* Reusable account editor: native dialog and ordinary nonce-protected POST. */
(function (factory) {
	'use strict';
	const api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	if (typeof window !== 'undefined' && window.document) {
		const boot = () => { if (!window.pcPanapayHub) window.pcPanapayHub = api.mount(window); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
		else boot();
	}
}(function () {
	'use strict';
	function mountPage(env) {
		const doc = env.document;
		const page = doc.querySelector('.pc-pay-hub');
		const dialog = page?.querySelector('[data-pc-pay-account-dialog]');
		const form = page?.querySelector('#pc-pay-config');
		if (!dialog || !form || typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') return null;

		let disposed = false, opener = null, snapshot = [], sitePickerWasOpen = false, submitting = false;
		const listeners = [];
		const on = (target, type, handler) => {
			target.addEventListener(type, handler);
			listeners.push(() => target.removeEventListener(type, handler));
		};
		const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));
		const labels = new Map(submitButtons.map(button => [button, button.textContent]));
		const fields = () => Array.from(dialog.querySelectorAll('input, select, textarea'));
		const allSites = dialog.querySelector('[data-pc-pay-all-sites]');
		const sitePicker = dialog.querySelector('.pc-pay-site-picker');
		const marker = 'data-pchq-reset-on-open';
		const hadMarker = form.hasAttribute(marker), markerValue = form.getAttribute(marker);
		const wasEnhanced = dialog.classList.contains('is-enhanced');
		// The navigation guard ignores a closed editor whose Cancel restores its draft.
		// While open, ordinary form signatures still protect unsaved payment settings.
		form.setAttribute(marker, '');
		const capture = () => fields().map(field => ({ field, value: field.value, checked: field.checked }));
		const restore = () => {
			snapshot.forEach(({ field, value, checked }) => {
				field.value = value;
				if ('checked' in field) field.checked = checked;
			});
			if (sitePicker && snapshot.length) sitePicker.open = sitePickerWasOpen;
		};
		const resetSubmission = () => {
			if (disposed) return;
			submitting = false;
			form.removeAttribute('aria-busy');
			labels.forEach((label, button) => { button.textContent = label; button.removeAttribute('aria-disabled'); });
			doc.documentElement.classList.toggle('pc-pay-modal-open', !!dialog.open);
		};
		const cancel = () => {
			if (disposed || submitting || !dialog.open) return;
			restore();
			dialog.close('cancel');
		};
		const open = trigger => {
			if (disposed || submitting || dialog.open) return;
			opener = trigger;
			snapshot = capture();
			sitePickerWasOpen = !!sitePicker?.open;
			dialog.showModal();
			doc.documentElement.classList.add('pc-pay-modal-open');
			const first = dialog.querySelector('input[name="account_name"]');
			if (first && !first.disabled) first.focus({ preventScroll: true });
		};

		// A direct edit URL can initially render open. Upgrade it to the modal layer.
		if (dialog.open) dialog.close();
		dialog.classList.add('is-enhanced');
		if (allSites) on(allSites, 'change', () => { if (sitePicker) sitePicker.open = !allSites.checked; });
		page.querySelectorAll('[data-pc-pay-open]').forEach(trigger => {
			on(trigger, 'click', event => { event.preventDefault(); open(trigger); });
		});
		dialog.querySelectorAll('[data-pc-pay-cancel]').forEach(trigger => {
			on(trigger, 'click', event => { event.preventDefault(); cancel(); });
		});
		on(dialog, 'cancel', event => { event.preventDefault(); cancel(); });
		on(dialog, 'close', () => {
			if (disposed || dialog.open) return;
			if (!submitting) restore();
			doc.documentElement.classList.remove('pc-pay-modal-open');
			if (opener?.isConnected) opener.focus({ preventScroll: true });
		});
		on(form, 'submit', event => {
			if (disposed || event.defaultPrevented) return;
			if (submitting) { event.preventDefault(); return; }
			submitting = true;
			form.setAttribute('aria-busy', 'true');
			const button = event.submitter;
			if (button && typeof env.HTMLButtonElement === 'function' && button instanceof env.HTMLButtonElement) {
				// Preserve the successful submitter and native POST; never replay or serialize it.
				button.textContent = '正在保存…';
				button.setAttribute('aria-disabled', 'true');
			}
		});
		if (dialog.dataset.autoOpen === '1') open(page.querySelector('[data-pc-pay-open]'));
		return {
			connected: () => page.isConnected && dialog.isConnected && form.isConnected,
			resume: resetSubmission,
			dispose() {
				if (disposed) return;
				const wasSubmitting = submitting;
				resetSubmission();
				disposed = true;
				listeners.forEach(remove => remove());
				listeners.length = 0;
				if (!wasSubmitting) restore();
				// Remove the old close handler BEFORE closing, so it cannot steal new-page focus.
				if (dialog.open) dialog.close();
				doc.documentElement.classList.remove('pc-pay-modal-open');
				if (hadMarker) form.setAttribute(marker, markerValue || '');
				else form.removeAttribute(marker);
				if (!wasEnhanced) dialog.classList.remove('is-enhanced');
				opener = null; snapshot = []; labels.clear();
			}
		};
	}
	function mount(env) {
		if (!env || !env.document) return null;
		const doc = env.document, listeners = [];
		let current = null, disposed = false;
		const on = (target, type, handler) => {
			target.addEventListener(type, handler);
			listeners.push(() => target.removeEventListener(type, handler));
		};
		const clear = () => { if (current) current.dispose(); current = null; };
		const initialize = () => {
			if (disposed) return;
			clear();
			current = mountPage(env);
		};
		on(doc, 'pchq:before-swap', clear);
		on(doc, 'pchq:after-swap', initialize);
		on(env, 'pageshow', () => {
			if (disposed) return;
			if (current?.connected()) current.resume();
			else initialize();
		});
		initialize();
		return {
			initialize,
			dispose() {
				if (disposed) return;
				disposed = true;
				listeners.forEach(remove => remove());
				listeners.length = 0;
				clear();
			}
		};
	}
	return { mount };
}));

/* 支付方返回参数：点状态展开/收起（2026-11-08） */
(function () {
	if (typeof document === 'undefined') return;
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('[data-pc-pay-detail]') : null;
		if (!button) return;
		var row = document.getElementById(button.getAttribute('aria-controls'));
		if (!row) return;
		var open = button.getAttribute('aria-expanded') !== 'true';
		button.setAttribute('aria-expanded', open ? 'true' : 'false');
		row.hidden = !open;
	});
}());
