/* 订单 → 手动订单（店主 2026-11-10）。 */
(function () {
	var root = document.querySelector('[data-pc-manual]');
	if (!root || !window.wp || !wp.apiFetch) return;
	var cfg = window.pcManual || { currency: '$', decimals: 2 };
	var $ = function (s) { return root.querySelector(s); };
	var lines = [], discounts = [];

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function num(v) { var n = parseFloat(v); return isNaN(n) || n < 0 ? 0 : n; }
	function money(v) { return cfg.currency + num(v).toFixed(cfg.decimals); }

	// ---- 搜索 ----
	var q = $('[data-mo-q]'), box = $('[data-mo-results]'), t, seq = 0;
	function search() {
		var my = ++seq;
		box.hidden = false; box.innerHTML = '<p class="pc-mo-hint">搜索中…</p>';
		wp.apiFetch({ path: '/peptide/v1/manual-order/search?q=' + encodeURIComponent(q.value.trim()) }).then(function (r) {
			if (my !== seq) return;
			var items = r.items || [];
			box.innerHTML = items.length ? items.map(function (p, i) {
				return '<button type="button" class="pc-mo-res" data-i="' + i + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '<span class="pc-mo-noimg"></span>') +
					'<span class="pc-mo-res__name">' + esc(p.name) + (p.sku ? ' <small>' + esc(p.sku) + '</small>' : '') + (p.stock ? '' : ' <em>缺货</em>') + '</span><span class="pc-mo-res__price">' + money(p.price) + '</span></button>';
			}).join('') : '<p class="pc-mo-hint">没有找到商品。</p>';
			box._items = items;
		}).catch(function () { box.innerHTML = '<p class="pc-mo-hint">搜索失败，请重试。</p>'; });
	}
	q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 250); });
	q.addEventListener('focus', function () { if (!box._items) search(); else box.hidden = false; });
	document.addEventListener('click', function (e) { if (!e.target.closest('.pc-mo-search')) box.hidden = true; });
	box.addEventListener('click', function (e) {
		var b = e.target.closest('.pc-mo-res'); if (!b) return;
		var p = box._items[+b.getAttribute('data-i')];
		var hit = lines.filter(function (l) { return l.id === p.id; })[0];
		if (hit) { hit.qty++; } else { lines.push({ id: p.id, name: p.name, qty: 1, regular: p.regular || p.price, price: p.price }); }
		box.hidden = true; q.value = ''; render();
	});

	// ---- 商品行 ----
	function render() {
		var tb = $('[data-mo-lines]');
		tb.innerHTML = lines.length ? lines.map(function (l, i) {
			return '<tr data-i="' + i + '"><td class="pc-mo-name">' + esc(l.name) + '</td>' +
				'<td><input type="number" min="1" step="1" value="' + l.qty + '" data-f="qty"></td>' +
				'<td><input type="number" min="0" step="0.01" value="' + l.regular + '" data-f="regular"></td>' +
				'<td><input type="number" min="0" step="0.01" value="' + l.price + '" data-f="price"></td>' +
				'<td class="pc-mo-line" data-line>' + money(l.price * l.qty) + '</td>' +
				'<td><button type="button" class="button-link pc-mo-del" data-del aria-label="删除">删除</button></td></tr>';
		}).join('') : '<tr class="pc-mo-empty"><td colspan="6">还没有商品，在上面搜索添加。</td></tr>';
		sum();
	}
	$('[data-mo-lines]').addEventListener('input', function (e) {
		var tr = e.target.closest('tr[data-i]'); if (!tr) return;
		var l = lines[+tr.getAttribute('data-i')], f = e.target.getAttribute('data-f');
		l[f] = 'qty' === f ? Math.max(1, parseInt(e.target.value, 10) || 1) : num(e.target.value);
		tr.querySelector('[data-line]').textContent = money(l.price * l.qty);
		sum();
	});
	$('[data-mo-lines]').addEventListener('click', function (e) {
		if (!e.target.closest('[data-del]')) return;
		lines.splice(+e.target.closest('tr').getAttribute('data-i'), 1); render();
	});

	// ---- 运费 ----
	function shipMode() { var r = root.querySelector('[data-mo-ship]:checked'); return r ? r.value : 'store'; }
	root.querySelectorAll('[data-mo-ship]').forEach(function (r) { r.addEventListener('change', function () { $('[data-mo-ship-custom]').hidden = 'custom' !== shipMode(); sum(); }); });
	$('[data-mo-ship-amount]').addEventListener('input', sum);

	// ---- 优惠 ----
	function renderDisc() {
		$('[data-mo-discounts]').innerHTML = discounts.map(function (d, i) {
			return '<div class="pc-mo-row" data-i="' + i + '"><label>名称（顾客看到）<input type="text" value="' + esc(d.label) + '" data-f="label"></label><label>金额<input type="number" min="0" step="0.01" value="' + d.amount + '" data-f="amount"></label><button type="button" class="button-link pc-mo-del" data-del-d>删除</button></div>';
		}).join('');
		sum();
	}
	$('[data-mo-add-discount]').addEventListener('click', function () { discounts.push({ label: 'Special discount', amount: 0 }); renderDisc(); });
	$('[data-mo-discounts]').addEventListener('input', function (e) {
		var row = e.target.closest('[data-i]'); if (!row) return;
		var d = discounts[+row.getAttribute('data-i')], f = e.target.getAttribute('data-f');
		d[f] = 'amount' === f ? num(e.target.value) : e.target.value; sum();
	});
	$('[data-mo-discounts]').addEventListener('click', function (e) {
		if (!e.target.closest('[data-del-d]')) return;
		discounts.splice(+e.target.closest('[data-i]').getAttribute('data-i'), 1); renderDisc();
	});

	// ---- 金额 ----
	function totals() {
		var reg = 0, sub = 0;
		lines.forEach(function (l) { reg += Math.max(l.regular, l.price) * l.qty; sub += l.price * l.qty; });
		var disc = discounts.reduce(function (a, d) { return a + num(d.amount); }, 0);
		var ship = 'custom' === shipMode() ? num($('[data-mo-ship-amount]').value) : null;
		return { reg: reg, sub: sub, disc: disc, ship: ship, total: Math.max(0, sub - disc) + (ship || 0) };
	}
	function sumHtml(x) {
		return '<dt>原价合计</dt><dd><del>' + money(x.reg) + '</del></dd>' +
			'<dt>商品小计</dt><dd>' + money(x.sub) + '</dd>' +
			(x.disc ? '<dt>优惠</dt><dd class="is-off">−' + money(x.disc) + '</dd>' : '') +
			'<dt>运费</dt><dd>' + (null === x.ship ? '按店铺运费' : money(x.ship)) + '</dd>' +
			'<dt class="is-total">' + (null === x.ship ? '合计（不含运费）' : '合计') + '</dt><dd class="is-total">' + money(x.total) + '</dd>';
	}
	function sum() {
		var x = totals();
		$('[data-mo-sum]').innerHTML = sumHtml(x);
		$('[data-mo-create]').disabled = !lines.length;
	}

	// ---- 生成链接 ----
	var dlg = $('[data-mo-dialog]');
	$('[data-mo-create]').addEventListener('click', function () {
		var btn = this, err = $('[data-mo-err]'); btn.disabled = true; err.hidden = true;
		var body = {
			items: lines.map(function (l) { return { id: l.id, qty: l.qty, regular: l.regular, price: l.price }; }),
			shipping: 'custom' === shipMode() ? { label: $('[data-mo-ship-label]').value, amount: num($('[data-mo-ship-amount]').value) } : null,
			discounts: discounts.filter(function (d) { return num(d.amount) > 0; }),
			note: $('[data-mo-note]').value
		};
		wp.apiFetch({ path: '/peptide/v1/manual-order', method: 'POST', data: body }).then(function (r) {
			$('[data-mo-link]').value = r.url;
			$('[data-mo-open]').href = r.url;
			$('[data-mo-dsum]').innerHTML = sumHtml(totals());
			try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); }
			btn.disabled = false; loadRecent();
		}).catch(function (x) { err.textContent = (x && x.message) || '生成失败，请重试。'; err.hidden = false; btn.disabled = false; });
	});
	$('[data-mo-copy]').addEventListener('click', function () {
		var inp = $('[data-mo-link]'), b = this;
		var done = function () { b.textContent = '已复制'; setTimeout(function () { b.textContent = '复制链接'; }, 1500); };
		if (navigator.clipboard) navigator.clipboard.writeText(inp.value).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
		else { inp.select(); document.execCommand('copy'); done(); }
	});
	$('[data-mo-close]').addEventListener('click', function () { dlg.close(); });
	$('[data-mo-new]').addEventListener('click', function () {
		lines = []; discounts = []; $('[data-mo-note]').value = ''; root.querySelector('[data-mo-ship][value="store"]').checked = true; $('[data-mo-ship-custom]').hidden = true;
		render(); renderDisc(); dlg.close();
	});

	// ---- 最近的链接 ----
	function loadRecent() {
		wp.apiFetch({ path: '/peptide/v1/manual-order' }).then(function (r) {
			var items = r.items || [];
			$('[data-mo-recent]').innerHTML = items.length ? '<ul class="pc-mo-recent">' + items.map(function (c) {
				return '<li><div><b>' + esc(c.total) + '</b> <span class="pc-mo-hint">' + esc(c.created) + '</span></div><div class="pc-mo-recent__items">' + esc(c.items) + '</div>' + (c.note ? '<div class="pc-mo-hint">' + esc(c.note) + '</div>' : '') +
					'<div class="pc-mo-recent__act"><button type="button" class="button-link" data-copy-url="' + esc(c.url) + '">复制链接</button>' + (c.orders.length ? c.orders.map(function (o) { return ' · <a href="' + esc(o.url) + '">订单 #' + esc(o.id) + '</a>'; }).join('') : ' · <span class="pc-mo-hint">还没下单</span>') + '</div></li>';
			}).join('') + '</ul>' : '<p class="pc-mo-hint">还没有。</p>';
		}).catch(function () {});
	}
	$('[data-mo-recent]').addEventListener('click', function (e) {
		var b = e.target.closest('[data-copy-url]'); if (!b) return;
		var u = b.getAttribute('data-copy-url');
		var ok = function () { b.textContent = '已复制'; setTimeout(function () { b.textContent = '复制链接'; }, 1500); };
		if (navigator.clipboard) navigator.clipboard.writeText(u).then(ok, function () { window.prompt('复制链接', u); }); else window.prompt('复制链接', u);
	});

	render(); renderDisc(); loadRecent();
})();
