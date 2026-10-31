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
	registry.registerPaymentMethod({
		name: 'pc_panapay',
		label: element.createElement('span', null, data.title),
		content,
		edit: content,
		canMakePayment: () => true,
		ariaLabel: data.title,
		supports: { features: ['products'] },
	});
})();
