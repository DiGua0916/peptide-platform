(function () {
	'use strict';
	var dialog, lastFocus, pending;
	function decorate(root) {
		(root.querySelectorAll ? root.querySelectorAll('.pc-product-extras .pc-secondary-image') : []).forEach(function(img) {
			var card = img.closest('article,li.product,.pc-commerce-product'), visual = card && card.querySelector('.product-visual,.product-image');
			if (visual) { visual.appendChild(img); }
		});
	}
	decorate(document);
	if (window.MutationObserver) { new MutationObserver(function(records) { records.forEach(function(r) { r.addedNodes.forEach(function(n) { if (n.nodeType === 1) { decorate(n); } }); }); }).observe(document.body,{childList:true,subtree:true}); }
	function text(html) { var box = document.createElement('textarea'); box.innerHTML = String(html || '').replace(/<[^>]*>/g, ' '); return box.value; }
	function close() { if (pending) { pending.abort(); pending = null; } if (dialog && dialog.open) { dialog.close(); } if (lastFocus) { lastFocus.focus(); } }
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-pc-quick-url]');
		if (!button || !window.HTMLDialogElement || !window.fetch) { return; }
		event.preventDefault(); lastFocus = button;
		var url;
		try { url = new URL(button.dataset.pcQuickUrl, window.location.href); if (url.origin !== window.location.origin) { return; } } catch (_) { return; }
		if (!dialog) {
			dialog = document.createElement('dialog'); dialog.className = 'pc-quick-dialog';
			var exit = document.createElement('button'); exit.type = 'button'; exit.className = 'pc-quick-close'; exit.textContent = button.dataset.pcClose || 'Close'; exit.addEventListener('click', close);
			dialog.appendChild(exit); var content = document.createElement('div'); content.className = 'pc-quick-body'; dialog.appendChild(content); document.body.appendChild(dialog);
			dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
			dialog.addEventListener('click', function (e) { if (e.target === dialog) { var r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) { close(); } } });
		}
		var body = dialog.querySelector('.pc-quick-body'); body.replaceChildren(); var loading = document.createElement('div'); loading.className = 'pc-quick-loading'; loading.setAttribute('aria-label', button.dataset.pcLoading || 'Loading product'); body.appendChild(loading);
		if (!dialog.open) { dialog.showModal(); }
		if (pending) { pending.abort(); } pending = new AbortController();
		fetch(url.href, {credentials:'omit', signal:pending.signal, headers:{Accept:'application/json'}}).then(function (res) {
			if (!res.ok) { throw new Error('product'); } return res.json();
		}).then(function (product) {
			body.replaceChildren();
			if (product.images && product.images[0]) { var img = document.createElement('img'); img.src = product.images[0].src; img.alt = product.images[0].alt || product.name; body.appendChild(img); }
			var copy = document.createElement('div'), title = document.createElement('h2'); title.id = 'pc-quick-title'; title.textContent = product.name || ''; dialog.setAttribute('aria-labelledby', title.id); copy.appendChild(title);
			var price = document.createElement('p'), p = product.prices;
			if (p) { var amount = Number(p.price) / Math.pow(10, p.currency_minor_unit || 0); try { price.textContent = new Intl.NumberFormat(undefined,{style:'currency',currency:p.currency_code}).format(amount); } catch (_) { price.textContent = p.currency_symbol + amount.toFixed(p.currency_minor_unit || 0); } copy.appendChild(price); }
			var desc = document.createElement('p'); desc.textContent = text(product.short_description || product.description).slice(0,1200); copy.appendChild(desc);
			var link = document.createElement('a'); link.href = button.dataset.pcProductUrl; link.textContent = button.dataset.pcDetails || 'View product'; copy.appendChild(link); body.appendChild(copy);
		}).catch(function (error) { if (error.name === 'AbortError') { return; } body.replaceChildren(); var p = document.createElement('p'); p.textContent = button.dataset.pcError || 'The product could not be loaded. Open the product page to continue.'; body.appendChild(p); var a = document.createElement('a'); a.href = button.dataset.pcProductUrl; a.textContent = button.dataset.pcDetails || 'View product'; body.appendChild(a); });
	});
})();
