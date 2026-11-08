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
	document.addEventListener('submit', function (event) {
		var f = event.target;
		var message = f && f.getAttribute && f.getAttribute('data-confirm');
		if (message && !window.confirm(message)) { event.preventDefault(); return; }
		if (f && f.classList && f.classList.contains('pcp-inline')) {
			var b = f.querySelector('button');
			if (b) { setTimeout(function () { b.disabled = true; }, 0); }
		}
	}, true);
}());
