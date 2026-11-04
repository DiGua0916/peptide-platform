/* Disposable read-only operations status controller. Business forms remain native POSTs. */
(function (factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	if (typeof window !== 'undefined' && window.document) {
		var boot = function () { api.install(window); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
		else boot();
	}
}(function () {
	'use strict';
	function endpoint(env, value) {
		try {
			var url = new URL(value, env.location.href), expected = new URL('admin-ajax.php', env.location.href);
			return url.origin === expected.origin && url.pathname === expected.pathname && !url.username && !url.password && !url.search && !url.hash ? url.href : null;
		} catch (error) { return null; }
	}
	function mount(env) {
		var doc = env.document, root = doc.querySelector('[data-pchq-operations]');
		if (!root || !env.AbortController || !env.fetch) return null;
		var ajax = endpoint(env, root.dataset.ajax), nonce = root.dataset.nonce;
		if (!ajax || typeof nonce !== 'string' || !nonce || nonce.length > 128) return null;
		var disposed = false, polling = false, refreshing = false, timer = null, pending = new Map(), listeners = [];
		var buttons = Array.from(root.querySelectorAll('.ops-refresh'));
		var buttonStates = new Map(buttons.map(function (button) { return [button, button.disabled]; }));
		function alive() { return !disposed && root.isConnected; }
		function on(target, type, handler) { target.addEventListener(type, handler); listeners.push(function () { target.removeEventListener(type, handler); }); }
		function message(value) { var live = root.querySelector('.ops-live'); if (alive() && live) live.textContent = value; }
		function active() { var jobs = root.querySelector('#ops-jobs'); return alive() && jobs && jobs.dataset.poll === '1'; }
		function occupied(jobs) { return jobs.querySelector('details[open]') || jobs.contains(doc.activeElement); }
		function schedule(delay) { env.clearTimeout(timer); timer = null; if (active() && !doc.hidden) timer = env.setTimeout(poll, delay); }
		async function request(data) {
			var controller = new env.AbortController(), timeout = env.setTimeout(function () { controller.abort(); }, 25000);
			pending.set(controller, timeout);
			try {
				var body = new URLSearchParams(Object.assign({ action: 'pchq_ops_status', nonce: nonce }, data));
				var response = await env.fetch(ajax, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', body: body, signal: controller.signal });
				if (!alive() || controller.signal.aborted) throw new Error('cancelled');
				if (!response.ok || response.redirected) throw new Error('status');
				var json = await response.json();
				if (!alive() || controller.signal.aborted) throw new Error('cancelled');
				if (!json || json.success !== true || !json.data || typeof json.data !== 'object' || Array.isArray(json.data)) throw new Error('status');
				return json.data;
			} finally { env.clearTimeout(timeout); pending.delete(controller); }
		}
		function jobContent(html) {
			if (typeof html !== 'string' || html.length > 2 * 1024 * 1024) throw new Error('markup');
			var template = doc.createElement('template'); template.innerHTML = html;
			if (template.content.querySelector('script,style,iframe,object,embed,base,meta,link')) throw new Error('markup');
			for (var element of template.content.querySelectorAll('*')) {
				for (var name of element.getAttributeNames()) {
					if (/^on/i.test(name) || name.toLowerCase() === 'srcdoc') throw new Error('markup');
					if (/^(href|src|action|formaction)$/i.test(name) && /^[\s\x00-\x1f]*(?:javascript|data|vbscript):/i.test(element.getAttribute(name))) throw new Error('markup');
				}
			}
			return template.content;
		}
		async function poll() {
			env.clearTimeout(timer); timer = null;
			if (!active() || doc.hidden || polling || refreshing) return;
			var jobs = root.querySelector('#ops-jobs');
			if (occupied(jobs)) { schedule(4000); return; }
			polling = true;
			try {
				var data = await request({ kind: 'jobs' });
				if (!alive()) return;
				if (typeof data.active !== 'boolean') throw new Error('status');
				var content = jobContent(data.html);
				// Focus or expanded logs may have changed while the request was in flight.
				if (!occupied(jobs)) { jobs.replaceChildren(content); jobs.dataset.poll = data.active ? '1' : '0'; message(''); }
			} catch (error) { if (alive()) message('暂时无法更新进度，稍后会再次读取。'); }
			finally { polling = false; if (alive()) schedule(4000); }
		}
		async function refresh(button) {
			if (!alive() || refreshing || !buttons.includes(button) || !/^-?(?:0|[1-9][0-9]*)$/.test(button.dataset.site || '') || !Number.isSafeInteger(Number(button.dataset.site))) return;
			refreshing = true; buttons.forEach(function (item) { item.disabled = true; }); message('正在读取目标状态…');
			try {
				var data = await request({ kind: 'inventory', site: button.dataset.site });
				if (!alive()) return;
				if (data.reload !== true) throw new Error('status');
				if (env.pchqNavigation && typeof env.pchqNavigation.navigate === 'function') {
					// A cancelled/failed navigation retains this form. Never force a second request or reload.
					await env.pchqNavigation.navigate(env.location.href);
					if (alive()) message('店铺状态已读取，可再次打开当前页面查看。');
				} else env.location.reload();
			} catch (error) { if (alive()) message('暂时无法读取目标状态，请稍后重试。'); }
			finally { if (alive()) { refreshing = false; buttons.forEach(function (item) { item.disabled = buttonStates.get(item); }); schedule(4000); } }
		}
		buttons.forEach(function (button) { on(button, 'click', function () { refresh(button); }); });
		on(doc, 'visibilitychange', function () { if (doc.hidden) { env.clearTimeout(timer); timer = null; } else if (active()) poll(); });
		function destroy() {
			if (disposed) return; disposed = true; env.clearTimeout(timer); timer = null;
			listeners.forEach(function (remove) { remove(); }); listeners.length = 0;
			pending.forEach(function (timeout, controller) { env.clearTimeout(timeout); controller.abort(); }); pending.clear();
			buttons.forEach(function (button) { button.disabled = buttonStates.get(button); });
		}
		schedule(2000);
		return { destroy: destroy, poll: poll, refresh: refresh };
	}
	function install(env) {
		if (env.pchqOperationsLifecycle) return env.pchqOperationsLifecycle;
		var current = null, doc = env.document;
		function dispose() { if (current) current.destroy(); current = null; }
		function initialize() { dispose(); current = mount(env); }
		function resume() { if (!current) initialize(); }
		doc.addEventListener('pchq:before-swap', dispose); doc.addEventListener('pchq:after-swap', initialize);
		env.addEventListener('pagehide', dispose); env.addEventListener('pageshow', resume);
		var lifecycle = { destroy: function () {
			dispose(); doc.removeEventListener('pchq:before-swap', dispose); doc.removeEventListener('pchq:after-swap', initialize);
			env.removeEventListener('pagehide', dispose); env.removeEventListener('pageshow', resume); delete env.pchqOperationsLifecycle;
		} };
		env.pchqOperationsLifecycle = lifecycle; initialize(); return lifecycle;
	}
	return { mount: mount, install: install };
}));
