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
	// 按店铺运费：问后台这一单按店铺规则（美国）大概多少运费（店主 2026-11-10：直接显示出来）。
	var storeShip = null, shipT, shipSeq = 0, promoInfo = { promos: [], active_promos: [], first: null };
	var swFirst = $('[data-mo-first]'), picked = [], restoreNote = '';
	// 满减活动选择框（店主 2026-11-11：店铺可能有多个活动，按单选；多的话在小弹窗里勾）。
	var pop = $('[data-mo-pop]'), pickBtn = $('[data-mo-pick-btn]');
	function promoGot(id) { var h = (promoInfo.promos || []).filter(function (x) { return +x.id === +id; })[0]; return h ? num(h.discount) : 0; }
	function pickText() {
		var act = promoInfo.active_promos || [];
		if (!act.length) { $('[data-mo-pick-text]').textContent = '店铺现在没有进行中的满减活动'; pickBtn.disabled = true; return; }
		pickBtn.disabled = false;
		var names = act.filter(function (a) { return picked.indexOf(+a.id) > -1; }).map(function (a) { return a.title + (lines.length ? '（' + (promoGot(a.id) ? '−' + money(promoGot(a.id)) : '未达门槛') + '）' : ''); });
		$('[data-mo-pick-text]').textContent = names.length ? names.join('、') : '不使用（店铺有 ' + act.length + ' 个活动）';
	}
	function popList() {
		$('[data-mo-pop-list]').innerHTML = (promoInfo.active_promos || []).map(function (a) {
			var g = promoGot(a.id);
			return '<label class="pc-mo-pop__item"><input type="checkbox" value="' + a.id + '"' + (picked.indexOf(+a.id) > -1 ? ' checked' : '') + '><span><b>' + esc(a.title) + '</b>' + (a.rules ? '<small>' + esc(a.rules) + '</small>' : '') + '<em class="' + (g ? 'is-on' : '') + '">' + (lines.length ? (g ? '这一单可减 ' + money(g) : '这一单还没达到门槛') : '') + '</em></span></label>';
		}).join('');
	}
	pickBtn.addEventListener('click', function (e) { e.stopPropagation(); var open = pop.hidden; if (open) popList(); pop.hidden = !open; pickBtn.setAttribute('aria-expanded', String(open)); });
	pop.addEventListener('click', function (e) { e.stopPropagation(); });
	pop.addEventListener('change', function (e) { var id = +e.target.value; if (e.target.checked) { if (picked.indexOf(id) < 0) picked.push(id); } else { picked = picked.filter(function (x) { return x !== id; }); } pickText(); paint(); });
	$('[data-mo-pick-none]').addEventListener('click', function () { picked = []; popList(); pickText(); paint(); });
	$('[data-mo-pick-done]').addEventListener('click', function () { pop.hidden = true; pickBtn.setAttribute('aria-expanded', 'false'); });
	document.addEventListener('click', function () { if (!pop.hidden) { pop.hidden = true; pickBtn.setAttribute('aria-expanded', 'false'); } });
	function promoTexts() {
		var p = promoInfo;
		pickText(); if (!pop.hidden) popList();
		$('[data-mo-first-text]').textContent = p.first ? (p.first.code + ' · 商品打 ' + (100 - p.first.percent) / 10 + ' 折（' + p.first.percent + '% off），结账时会核对是不是这位顾客的第一单') : '店铺没有开启首单优惠';
		swFirst.disabled = !p.first;
	}
	swFirst.addEventListener('change', paint);
	function loadStoreShip() {
		clearTimeout(shipT);
		if (!lines.length) { storeShip = null; promoInfo.promos = []; }
		shipT = setTimeout(function () {
			var my = ++shipSeq;
			wp.apiFetch({ path: '/peptide/v1/manual-order/shipping', method: 'POST', data: { items: lines.map(function (l) { return { id: l.id, qty: l.qty, price: l.price }; }) } }).then(function (r) {
				if (my !== shipSeq) return;
				storeShip = r && null !== r.shipping && undefined !== r.shipping ? num(r.shipping) : null;
				promoInfo = { promos: r.promos || [], active_promos: r.active_promos || [], first: r.first || null };
				promoTexts(); paint();
			}).catch(function () {});
		}, 300);
	}
	function totals() {
		var reg = 0, sub = 0;
		lines.forEach(function (l) { reg += Math.max(l.regular, l.price) * l.qty; sub += l.price * l.qty; });
		var disc = discounts.reduce(function (a, d) { return a + num(d.amount); }, 0);
		var ship = 'custom' === shipMode() ? num($('[data-mo-ship-amount]').value) : storeShip;
		var promo = picked.reduce(function (a, id) { return a + promoGot(id); }, 0);
		var first = swFirst.checked && promoInfo.first ? Math.round(sub * promoInfo.first.percent) / 100 : 0;
		disc += promo + first;
		return { reg: reg, sub: sub, disc: disc, ship: ship, total: Math.max(0, sub - disc) + (ship || 0) };
	}
	function sumHtml(x) {
		return '<dt>原价合计</dt><dd><del>' + money(x.reg) + '</del></dd>' +
			'<dt>商品小计</dt><dd>' + money(x.sub) + '</dd>' +
			(x.disc ? '<dt>优惠' + (picked.length || swFirst.checked ? '（含店铺活动）' : '') + '</dt><dd class="is-off">−' + money(x.disc) + '</dd>' : '') +
			'<dt>运费' + ('store' === shipMode() ? '（店铺运费，按美国）' : '') + '</dt><dd>' + (null === x.ship ? ('store' === shipMode() && lines.length ? '计算中…' : '—') : money(x.ship)) + '</dd>' +
			'<dt class="is-total">合计</dt><dd class="is-total">' + money(x.total) + '</dd>';
	}
	function paint() {
		$('[data-mo-sum]').innerHTML = sumHtml(totals());
		$('[data-mo-create]').disabled = !lines.length;
	}
	function sum() { paint(); loadStoreShip(); }

	// ---- 生成链接 ----
	var dlg = $('[data-mo-dialog]');
	$('[data-mo-create]').addEventListener('click', function () {
		var btn = this, err = $('[data-mo-err]'); btn.disabled = true; err.hidden = true;
		var body = {
			items: lines.map(function (l) { return { id: l.id, qty: l.qty, regular: l.regular, price: l.price }; }),
			shipping: 'custom' === shipMode() ? { label: $('[data-mo-ship-label]').value, amount: num($('[data-mo-ship-amount]').value) } : null,
			discounts: discounts.filter(function (d) { return num(d.amount) > 0; }),
			note: restoreNote,
			promo_ids: picked,
			first_order: swFirst.checked,
			days: +$('[data-mo-days]').value
		};
		wp.apiFetch({ path: '/peptide/v1/manual-order', method: 'POST', data: body }).then(function (r) {
			$('[data-mo-link]').value = r.url;
			$('[data-mo-valid]').textContent = r.expires ? r.expires + ' 前有效。' : '';
			$('[data-mo-open]').href = r.url;
			$('[data-mo-dsum]').innerHTML = sumHtml(totals());
			try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); }
			btn.disabled = false; loadRecent();
			// 这一单的链接生成了，草稿就用完了，自动删掉。
			if (draftId) { wp.apiFetch({ path: '/peptide/v1/manual-order/drafts/' + draftId, method: 'DELETE' }).then(function () { draftId = ''; loadDrafts(); }).catch(function () {}); }
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
		lines = []; discounts = []; restoreNote = ''; picked = []; pickText(); swFirst.checked = false; $('[data-mo-days]').value = '30'; draftId = ''; $('[data-mo-save-new]').hidden = true; root.querySelector('[data-mo-ship][value="store"]').checked = true; $('[data-mo-ship-custom]').hidden = true;
		render(); renderDisc(); dlg.close();
	});

	// ---- 最近的链接 ----
	function loadRecent() {
		wp.apiFetch({ path: '/peptide/v1/manual-order' }).then(function (r) {
			var items = r.items || [];
			$('[data-mo-recent]').innerHTML = items.length ? '<ul class="pc-mo-recent">' + items.map(function (c) {
				// 店主 2026-11-11：最近的链接做精简——一行金额和时间，一行商品（超长省略，悬停看全部），一行操作。
				return '<li class="pc-mo-mini"><div class="pc-mo-mini__top"><b>' + esc(c.total) + '</b><span class="pc-mo-hint">' + esc(c.created) + (c.expires ? ' · ' + esc(c.expires) + ' 到期' : '') + '</span></div><div class="pc-mo-mini__items" title="' + esc(c.items) + '">' + esc(c.items) + '</div>' +
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

	// ---- 草稿（店主 2026-11-11） ----
	var draftId = cfg.draft || '';
	function state() {
		return {
			lines: lines, discounts: discounts, ship_mode: shipMode(),
			ship_label: $('[data-mo-ship-label]').value, ship_amount: num($('[data-mo-ship-amount]').value),
			note: restoreNote, promo_ids: picked, first_order: swFirst.checked, days: +$('[data-mo-days]').value
		};
	}
	function load(st) {
		if (!st) return;
		lines = (st.lines || []).map(function (l) { return { id: +l.id, name: l.name, qty: +l.qty || 1, regular: num(l.regular), price: num(l.price) }; });
		discounts = (st.discounts || []).map(function (d) { return { label: d.label, amount: num(d.amount) }; });
		var custom = 'custom' === st.ship_mode;
		root.querySelector('[data-mo-ship][value="' + (custom ? 'custom' : 'store') + '"]').checked = true;
		$('[data-mo-ship-custom]').hidden = !custom;
		$('[data-mo-ship-label]').value = st.ship_label || 'Shipping';
		$('[data-mo-ship-amount]').value = st.ship_amount || 0;
		restoreNote = st.note || '';
		picked = (st.promo_ids || []).map(Number); swFirst.checked = !!st.first_order;
		$('[data-mo-days]').value = String(st.days || 30);
		pickText();
		render(); renderDisc();
	}
	// 保存草稿：正在编辑某个草稿就更新它；“另存为新草稿”再多存一份（可以存很多个，最多 50 个）。
	function saveDraft(asNew, b) {
		var st = $('[data-mo-draft-state]'); b.disabled = true; st.textContent = '保存中…';
		wp.apiFetch({ path: '/peptide/v1/manual-order/drafts', method: 'POST', data: { id: draftId, as_new: asNew ? 1 : 0, state: state() } }).then(function (r) {
			draftId = r.id; st.textContent = (asNew ? '已另存为新草稿' : '草稿已保存') + '（' + r.saved + '），在右边“草稿”里可以随时打开继续做。'; b.disabled = false; $('[data-mo-save-new]').hidden = false; loadDrafts();
		}).catch(function (x) { st.textContent = (x && x.message) || '保存失败'; b.disabled = false; });
	}
	$('[data-mo-save-draft]').addEventListener('click', function () { saveDraft(false, this); });
	$('[data-mo-save-new]').addEventListener('click', function () { saveDraft(true, this); });
	function loadDrafts() {
		wp.apiFetch({ path: '/peptide/v1/manual-order/drafts' }).then(function (r) {
			var items = r.items || [];
			$('[data-mo-drafts]').innerHTML = items.length ? '<ul class="pc-mo-recent">' + items.map(function (d) {
				return '<li' + (d.id === draftId ? ' class="is-current"' : '') + '><div><b>' + esc(d.title) + '</b> <span class="pc-mo-hint">' + esc(d.saved) + '</span></div>' + (d.items ? '<div class="pc-mo-recent__items">' + esc(d.items) + ' · ' + esc(d.sub) + '</div>' : '') +
					'<div class="pc-mo-recent__act"><button type="button" class="button-link" data-open-draft="' + esc(d.id) + '">' + (d.id === draftId ? '正在编辑' : '打开继续做') + '</button> · <button type="button" class="button-link pc-mo-del" data-del-draft="' + esc(d.id) + '">删除</button></div></li>';
			}).join('') + '</ul>' : '<p class="pc-mo-hint">还没有草稿。做到一半点“保存草稿”就会出现在这里。</p>';
		}).catch(function () {});
	}
	$('[data-mo-drafts]').addEventListener('click', function (e) {
		var o = e.target.closest('[data-open-draft]'), d = e.target.closest('[data-del-draft]');
		if (o) {
			var id = o.getAttribute('data-open-draft');
			wp.apiFetch({ path: '/peptide/v1/manual-order/drafts/' + id }).then(function (r) { draftId = r.id; load(r.state); $('[data-mo-save-new]').hidden = false; $('[data-mo-draft-state]').textContent = '已打开草稿，改完可以再次保存。'; loadDrafts(); window.scrollTo(0, 0); });
		} else if (d) {
			var did = d.getAttribute('data-del-draft');
			wp.apiFetch({ path: '/peptide/v1/manual-order/drafts/' + did, method: 'DELETE' }).then(function () { if (did === draftId) draftId = ''; loadDrafts(); });
		}
	});

	render(); renderDisc(); loadRecent(); loadDrafts();
	if (cfg.prefill) {
		load(cfg.prefill);
		$('[data-mo-draft-state]').textContent = cfg.draft ? '已打开草稿，改完可以再次保存。' : '已按弃单把原来的商品和价格带进来，可以直接改。';
	}
})();
