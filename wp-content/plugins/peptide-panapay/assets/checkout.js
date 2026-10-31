/* Hosted payment only: native WooCommerce handles checkout and its redirect. */
(() => {
	'use strict';
	const registry = window.wc?.wcBlocksRegistry;
	const settings = window.wc?.wcSettings;
	const element = window.wp?.element;
	if (!registry?.registerPaymentMethod || !settings?.getPaymentMethodData || !element?.createElement) return;

	const data = settings.getPaymentMethodData('pc_panapay', null);
	if (!data || typeof data.title !== 'string' || !data.title.trim() || typeof data.description !== 'string') return;
	const content = element.createElement('p', null, data.description);
	const allowed = new Set(['american_express', 'mastercard', 'visa', 'discover', 'apple_pay', 'paypal']);
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
		name: 'pc_panapay',
		label,
		content,
		edit: content,
		canMakePayment: () => true,
		ariaLabel: data.title,
		supports: { features: ['products'] },
	});
})();
