/* 测试单（收件信息全为 1）：清掉结账块对邮编 / 邮箱“1”的前端格式报错，服务端会放行。2026-11-09 */
(function () {
	var wpData = window.wp && window.wp.data;
	if (!wpData || typeof wpData.subscribe !== 'function') { return; }
	var FIELDS = ['first_name', 'last_name', 'address_1', 'city'];
	function allOnes(a) {
		return !!a && FIELDS.every(function (k) { return String(a[k] || '').trim() === '1'; });
	}
	function tick() {
		try {
			var cart = wpData.select('wc/store/cart');
			var validation = wpData.select('wc/store/validation');
			if (!cart || !validation || typeof cart.getCustomerData !== 'function') { return; }
			var data = cart.getCustomerData() || {};
			var dispatch = wpData.dispatch('wc/store/validation');
			var test = false;
			[['shipping', data.shippingAddress], ['billing', data.billingAddress]].forEach(function (pair) {
				var id = pair[0] + '_postcode';
				if (allOnes(pair[1])) { test = true; if (validation.getValidationError(id)) { dispatch.clearValidationError(id); } }
			});
			var email = String((data.billingAddress && data.billingAddress.email) || '').trim();
			if (test && email === '1') {
				['email', 'billing_email', 'billing-email'].forEach(function (id) {
					if (validation.getValidationError(id)) { dispatch.clearValidationError(id); }
				});
			}
		} catch (e) { /* 结账块不在页面上时忽略 */ }
	}
	wpData.subscribe(tick);
})();
