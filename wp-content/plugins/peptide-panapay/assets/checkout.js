/* Hosted payment only: native WooCommerce handles checkout and its redirect. */
(() => {
	'use strict';
	const registry = window.wc?.wcBlocksRegistry;
	const settings = window.wc?.wcSettings;
	const element = window.wp?.element;
	if (!registry?.registerPaymentMethod || !settings?.getPaymentMethodData || !element?.createElement) return;

	const names = Array.isArray(window.pcPanapayMethodNames) ? window.pcPanapayMethodNames : ['pc_panapay'];
	const registered = new Set();
	const allowed = new Set(['american_express', 'mastercard', 'visa', 'discover', 'apple_pay', 'paypal']);
	for (const name of names) {
		if (typeof name !== 'string' || !/^pc_panapay(?:_[a-f0-9]{32})?$/.test(name) || registered.has(name)) continue;
		registered.add(name);
		const data = settings.getPaymentMethodData(name, null);
		const account = name === 'pc_panapay' ? 'default' : name.slice('pc_panapay_'.length);
		if (!data || data.account_id !== account || typeof data.title !== 'string' || !data.title.trim() || typeof data.description !== 'string') continue;
		const content = element.createElement('p', null, data.description);
		const seen = new Set();
		const icons = Array.isArray(data.payment_icons) ? data.payment_icons.filter((icon) => {
			if (!icon || !allowed.has(icon.id) || seen.has(icon.id) || typeof icon.label !== 'string' || typeof icon.url !== 'string') return false;
			seen.add(icon.id);
			return true;
		}) : [];
		const label = element.createElement('span', {
			style: { display: 'inline-flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px' },
		}, element.createElement('span', null, data.title), icons.length ? element.createElement('span', {
			role: 'img', 'aria-label': icons.map((icon) => icon.label).join(', '),
			style: { display: 'inline-flex', flexWrap: 'wrap', alignItems: 'center', gap: '6px' },
		}, icons.map((icon) => element.createElement('img', {
			key: icon.id, src: icon.url, alt: '', width: 48, height: 30,
			style: { display: 'block', width: '48px', height: '30px', margin: 0 },
		}))) : null);
		registry.registerPaymentMethod({
			name,
			label,
			content,
			edit: content,
			canMakePayment: () => true,
			ariaLabel: data.title,
			supports: { features: ['products'] },
		});
	}
})();
