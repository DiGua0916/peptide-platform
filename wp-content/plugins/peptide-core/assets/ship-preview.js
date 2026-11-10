/* 运费提前显示（店主 2026-11-10）：商品弹窗加购区显示运费和预计总价。 */
(function () {
	var cfg = window.pcShipPreview;
	if (!cfg || !cfg.url) return;
	var seq = 0;

	function fetchEst(add, qty) {
		var u = cfg.url + (cfg.url.indexOf('?') < 0 ? '?' : '&') + (add ? 'add=' + add + '&qty=' + (qty || 1) : '');
		return fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
	}

	// ---- 商品弹窗 ----
	function dockFor(form) { return form && form.querySelector('.vc-purchase-dock__controls'); }
	function chosen(form) {
		var v = form.querySelector('input[name="variation_id"]');
		var id = v && v.value && v.value !== '0' ? v.value : '';
		if (!id && !form.querySelector('input[name="variation_id"]')) {
			var b = form.querySelector('[name="add-to-cart"]');
			id = b ? (b.value || '') : '';
		}
		var q = form.querySelector('input[name="quantity"]');
		return { id: id, qty: q ? Math.max(1, parseInt(q.value, 10) || 1) : 1 };
	}
	function updateDialog(form) {
		var controls = dockFor(form);
		if (!controls) return;
		var line = form.querySelector('.pc-shipline');
		if (!line) {
			line = document.createElement('p');
			line.className = 'pc-shipline';
			line.setAttribute('aria-live', 'polite');
			controls.parentNode.insertBefore(line, controls);
		}
		var c = chosen(form);
		if (!c.id) { line.hidden = true; return; }
		var my = ++seq;
		fetchEst(c.id, c.qty).then(function (d) {
			if (my !== seq) return;
			if (!d || d.empty || d.unknown) { line.hidden = true; return; }
			line.hidden = false;
			line.innerHTML = '<span>Shipping <b>' + esc(d.shipping_text) + '</b></span><span>' + (d.with_cart ? 'Est. total with your bag' : 'Estimated total') + ' <b>' + esc(d.total_text) + '</b></span>';
		});
	}
	function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

	document.addEventListener('change', function (e) {
		var form = e.target.closest && e.target.closest('form.cart, form.variations_form');
		if (form && dockFor(form)) updateDialog(form);
	});
	document.addEventListener('input', function (e) {
		if (e.target.name !== 'quantity') return;
		var form = e.target.closest('form.cart, form.variations_form');
		if (form && dockFor(form)) { clearTimeout(form._pcShipT); form._pcShipT = setTimeout(function () { updateDialog(form); }, 300); }
	});
	// 弹窗内容是打开后才加载的：新出现的加购区也要算一次（默认选中的规格不会触发 change）。
	if (window.MutationObserver) {
		new MutationObserver(function (muts) {
			for (var i = 0; i < muts.length; i++) {
				for (var j = 0; j < muts[i].addedNodes.length; j++) {
					var n = muts[i].addedNodes[j];
					if (n.nodeType !== 1 || (n.classList && n.classList.contains('pc-shipline'))) continue;
					var forms = n.matches && n.matches('form.cart, form.variations_form') ? [n] : (n.querySelectorAll ? n.querySelectorAll('form.cart, form.variations_form') : []);
					for (var k = 0; k < forms.length; k++) { (function (f) { if (dockFor(f)) setTimeout(function () { updateDialog(f); }, 300); })(forms[k]); }
				}
			}
		}).observe(document.body, { childList: true, subtree: true });
	}
	if (window.jQuery) {
		window.jQuery(document.body).on('found_variation reset_data', 'form.variations_form', function () { var f = this; setTimeout(function () { updateDialog(f); }, 0); });
	}

	// 购物车抽屉（vc-live-cart-drawer）本来就显示运费和应付总额，这里只管商品弹窗。

	var css = '.vc-purchase-dock .pc-shipline{grid-column:1/-1;flex:0 0 100%;order:99;width:100%;max-width:100%;box-sizing:border-box;margin:2px 0 0}.pc-shipline{display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 12px;margin:0 0 10px;padding:8px 12px;border-radius:10px;background:#f1f7f8;color:#334155;font-size:13px;line-height:1.4}.pc-shipline b{color:#0f172a;font-weight:700}.pc-shipline[hidden]{display:none}.pc-shiprows[hidden]{display:none}.pc-shiprow--total strong{font-size:1.05em}';
	var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
})();
