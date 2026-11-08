/* 总后台 → 插件管理：上传区显示选中的文件、本地先检查 .zip 和大小；带确认的按钮。 */
(function () {
	'use strict';
	var form = document.querySelector('.pcp-upload__form');
	if (form) {
		var input = form.querySelector('input[type=file]');
		var zone = form.querySelector('.pcp-drop');
		var title = zone.querySelector('strong');
		var note = zone.querySelector('small');
		var button = form.querySelector('.pcp-upload__btn');
		var max = parseInt(form.getAttribute('data-max'), 10) || 0;
		var size = function (n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };
		var show = function () {
			var file = input.files && input.files[0];
			zone.classList.remove('has-file', 'is-invalid');
			button.disabled = true;
			if (!file) { title.textContent = title.getAttribute('data-empty'); note.textContent = note.getAttribute('data-empty'); return; }
			title.textContent = file.name;
			if (!/\.zip$/i.test(file.name)) { zone.classList.add('is-invalid'); note.textContent = '只能上传 .zip 压缩包。'; return; }
			if (max && file.size > max) { zone.classList.add('is-invalid'); note.textContent = size(file.size) + '，超过 32 MB 上限。'; return; }
			zone.classList.add('has-file');
			note.textContent = size(file.size) + ' · 点击可重新选择';
			button.disabled = false;
		};
		input.addEventListener('change', show);
		['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.add('is-over'); }); });
		['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.remove('is-over'); }); });
		form.addEventListener('submit', function () { button.disabled = true; button.textContent = '上传中…'; });
		show();
	}
	// 店铺选择：选了就切换；店铺多时输入名称或域名过滤下拉选项。
	var picker = document.querySelector('.pcp-picker__form');
	if (picker) {
		var select = picker.querySelector('.pcp-picker__select');
		var search = picker.querySelector('.pcp-picker__search');
		select.addEventListener('change', function () { picker.submit(); });
		if (search) {
			search.addEventListener('input', function () {
				var q = search.value.trim().toLowerCase();
				var first = null;
				Array.prototype.forEach.call(select.options, function (o) {
					var hit = !q || (o.getAttribute('data-search') || '').indexOf(q) !== -1;
					o.hidden = !hit;
					if (hit && !first) { first = o; }
				});
				select.size = q ? Math.min(8, Math.max(2, Array.prototype.filter.call(select.options, function (o) { return !o.hidden; }).length)) : 0;
			});
			search.addEventListener('keydown', function (e) {
				if (e.key !== 'Enter') { return; }
				e.preventDefault();
				var hit = Array.prototype.find.call(select.options, function (o) { return !o.hidden; });
				if (hit) { select.value = hit.value; picker.submit(); }
			});
		}
	}
	// 页面内的确认框（不用浏览器自带弹窗：有些浏览器 / 内置浏览器不显示，点了像没反应）。
	var dialog = null;
	var ask = function (message, onYes) {
		if (!dialog) {
			dialog = document.createElement('div');
			dialog.className = 'pcp-confirm';
			dialog.setAttribute('role', 'dialog');
			dialog.setAttribute('aria-modal', 'true');
			dialog.innerHTML = '<div class="pcp-confirm__box"><p class="pcp-confirm__text"></p><div class="pcp-confirm__actions"><button type="button" class="button" data-no>取消</button><button type="button" class="button button-primary" data-yes>确定</button></div></div>';
			document.body.appendChild(dialog);
			dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.hidden = true; } });
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && dialog && !dialog.hidden) { dialog.hidden = true; } });
		}
		dialog.querySelector('.pcp-confirm__text').textContent = message;
		var yes = dialog.querySelector('[data-yes]');
		var no = dialog.querySelector('[data-no]');
		yes.onclick = function () { dialog.hidden = true; onYes(); };
		no.onclick = function () { dialog.hidden = true; };
		dialog.hidden = false;
		yes.focus();
	};
	var busy = function (f) {
		var b = f.querySelector('button[type=submit], button:not([type])');
		if (b && !b.classList.contains('pcp-switch')) {
			setTimeout(function () { b.disabled = true; b.setAttribute('aria-busy', 'true'); if (!b.classList.contains('pcp-link')) { b.textContent = '处理中…'; } }, 0);
		}
	};
	document.addEventListener('submit', function (event) {
		var f = event.target;
		var message = f && f.getAttribute && f.getAttribute('data-confirm');
		if (message && f.getAttribute('data-confirmed') !== '1') {
			event.preventDefault();
			ask(message, function () {
				f.setAttribute('data-confirmed', '1');
				busy(f);
				if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
			});
			return;
		}
		if (f && f.classList && !f.classList.contains('pcp-switch-form') && !f.classList.contains('pcp-picker__form')) { busy(f); }
		if (f && f.classList && f.classList.contains('pcp-switch-form')) {
			var sw = f.querySelector('.pcp-switch');
			if (sw) {
				var next = sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true';
				sw.setAttribute('aria-checked', next);
				var t = f.querySelector('.pcp-switch__text');
				if (t) { t.textContent = '保存中…'; }
				setTimeout(function () { sw.disabled = true; }, 0);
			}
			return;
		}
		if (f && f.classList && f.classList.contains('pcp-inline')) {
			var b = f.querySelector('button');
			if (b) { setTimeout(function () { b.disabled = true; }, 0); }
		}
	}, true);
}());
