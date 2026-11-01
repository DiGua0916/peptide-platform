/* Progressive account editing: native dialog, ordinary nonce-protected POST. */
(() => {
	'use strict';
	const page = document.querySelector('.pc-pay-hub');
	const dialog = page?.querySelector('[data-pc-pay-account-dialog]');
	const form = page?.querySelector('#pc-pay-config');
	if (!dialog || !form || typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') return;

	let opener = null;
	let snapshot = [];
	let sitePickerWasOpen = false;
	let submitting = false;
	const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));
	const labels = new Map(submitButtons.map(button => [button, button.textContent]));
	const fields = () => Array.from(dialog.querySelectorAll('input, select, textarea'));
	const allSites = dialog.querySelector('[data-pc-pay-all-sites]');
	const sitePicker = dialog.querySelector('.pc-pay-site-picker');
	allSites?.addEventListener('change', () => { if (sitePicker) sitePicker.open = !allSites.checked; });
	const capture = () => fields().map(field => ({ field, value: field.value, checked: field.checked }));
	const restore = () => snapshot.forEach(({ field, value, checked }) => {
		field.value = value;
		if ('checked' in field) field.checked = checked;
	});
	const cancel = () => {
		if (submitting || !dialog.open) return;
		restore();
		if (sitePicker) sitePicker.open = sitePickerWasOpen;
		dialog.close('cancel');
	};
	const open = trigger => {
		if (submitting || dialog.open) return;
		opener = trigger;
		snapshot = capture();
		sitePickerWasOpen = !!sitePicker?.open;
		dialog.showModal();
		document.documentElement.classList.add('pc-pay-modal-open');
		const first = dialog.querySelector('input[name="account_name"]');
		if (first && !first.disabled) first.focus({ preventScroll: true });
	};

	// The server renders an inline, open form for no-JavaScript use. Enhance only
	// after native dialog support has been confirmed, so the fallback remains usable.
	dialog.close();
	dialog.classList.add('is-enhanced');
	page.querySelectorAll('[data-pc-pay-open]').forEach(trigger => {
		trigger.addEventListener('click', event => { event.preventDefault(); open(trigger); });
	});
	dialog.querySelectorAll('[data-pc-pay-cancel]').forEach(trigger => {
		trigger.addEventListener('click', event => { event.preventDefault(); cancel(); });
	});
	dialog.addEventListener('cancel', event => { event.preventDefault(); cancel(); });
	dialog.addEventListener('close', () => {
		if (dialog.open) return;
		document.documentElement.classList.remove('pc-pay-modal-open');
		if (opener?.isConnected) opener.focus({ preventScroll: true });
	});
	form.addEventListener('submit', event => {
		if (submitting) { event.preventDefault(); return; }
		submitting = true;
		form.setAttribute('aria-busy', 'true');
		const button = event.submitter;
		if (button instanceof HTMLButtonElement) {
			// Native POST remains authoritative; do not remove its successful submitter.
			button.textContent = '正在保存…';
			button.setAttribute('aria-disabled', 'true');
		}
	});
	window.addEventListener('pageshow', () => {
		submitting = false;
		form.removeAttribute('aria-busy');
		labels.forEach((label, button) => { button.textContent = label; button.removeAttribute('aria-disabled'); });
	});
	if (dialog.dataset.autoOpen === '1') open(page.querySelector('[data-pc-pay-open]'));
})();
