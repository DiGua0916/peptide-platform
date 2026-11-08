/* Hosted payment only: native WooCommerce handles checkout and its redirect. */
(() => {
	'use strict';
	const registry = window.wc?.wcBlocksRegistry;
	const settings = window.wc?.wcSettings;
	const element = window.wp?.element;
	if (!registry?.registerPaymentMethod || !settings?.getPaymentMethodData || !element?.createElement) return;

	const names = Array.isArray(window.pcPanapayMethodNames) ? window.pcPanapayMethodNames : ['pc_panapay'];
	const registered = new Set();
	const allowed = new Set(['american_express', 'mastercard', 'visa', 'discover', 'apple_pay', 'paypal', 'amazon_pay', 'klarna', 'cashapp']);
	// Woo strips HTML from server notices. Render this action in the native method
	// component instead, without consuming its failure event or retrying payment.
	const newCheckoutUrl = (value) => {
		if (typeof value !== 'string') return null;
		try {
			const url = new URL(value, window.location.href);
			return ['http:', 'https:'].includes(url.protocol)
				&& url.origin === window.location.origin && !url.username && !url.password
				&& /^[a-f0-9]{32}$/.test(url.searchParams.get('pc_panapay_new_checkout') || '')
				&& /^[a-zA-Z0-9]+$/.test(url.searchParams.get('pc_panapay_checkout_nonce') || '')
				? url.href : null;
		} catch (_) { return null; }
	};
	for (const name of names) {
		if (typeof name !== 'string' || !/^pc_panapay(?:_[a-f0-9]{32})?$/.test(name) || registered.has(name)) continue;
		registered.add(name);
		const data = settings.getPaymentMethodData(name, null);
		const account = name === 'pc_panapay' ? 'default' : name.slice('pc_panapay_'.length);
		if (!data || data.account_id !== account || typeof data.title !== 'string' || !data.title.trim() || typeof data.description !== 'string') continue;
		const href = newCheckoutUrl(data.new_checkout_url);
		const Content = (props) => {
			const [failed, setFailed] = element.useState(false);
			const onCheckoutFail = props.eventRegistration?.onCheckoutFail;
			const activeMethod = props.activePaymentMethod;
			element.useEffect(() => {
				if (!href || typeof onCheckoutFail !== 'function') return undefined;
				return onCheckoutFail(() => {
					if (!activeMethod || activeMethod === name) setFailed(true);
					return true; // Preserve Woo's original error and checkout lifecycle.
				});
			}, [onCheckoutFail, activeMethod]);
			return element.createElement('div', null,
				data.description ? element.createElement('p', null, data.description) : null,
				failed ? element.createElement('p', { className: 'pc-panapay-checkout-recovery', role: 'status' },
					'For a separate checkout, ',
					element.createElement('a', { href, className: 'pc-panapay-new-checkout' }, 'start a new order'),
					'.') : null);
		};
		const content = element.createElement(Content, null);
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
			edit: element.createElement('p', null, data.description),
			canMakePayment: () => true,
			ariaLabel: data.title,
			supports: { features: ['products'] },
		});
	}
})();
