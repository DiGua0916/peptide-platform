/* Disposable progress reader. It never submits or retries a create-store request. */
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
	var DOMAIN = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)$/;
	function safeLink(value, path) {
		try {
			if (typeof value !== 'string' || /[\x00-\x20\x7f\\]/.test(value)) return null;
			var url = new URL(value);
			return url.protocol === 'https:' && !url.username && !url.password && !url.search && !url.hash && !url.port && url.pathname === path && url.hostname.length <= 249 && DOMAIN.test(url.hostname) ? url.href : null;
		} catch (error) { return null; }
	}
	function mount(env) {
		var doc = env.document, root = doc.querySelector('[data-pchq-provision]');
		if (!root || !env.AbortController || !env.fetch) return null;
		var status = root.querySelector('#pchq-provision-status'), dns = root.querySelector('#pchq-provision-dns'), links = root.querySelector('#pchq-provision-links');
		var job = root.dataset.job, nonce = root.dataset.nonce, endpoint;
		try {
			endpoint = new URL(root.dataset.ajax, env.location.href);
			var expected = new URL('admin-ajax.php', env.location.href);
			if (endpoint.origin !== expected.origin || endpoint.pathname !== expected.pathname || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) return null;
		} catch (error) { return null; }
		if (!status || !dns || !links || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(job || '') || !nonce || nonce.length > 128) return null;
		endpoint.search = new URLSearchParams({ action: 'pchq_create_store_status', job: job, nonce: nonce });
		var disposed = false, finished = false, busy = false, timer = null, pending = null, pendingTimeout = null;
		function alive() { return !disposed && root.isConnected; }
		function schedule() { env.clearTimeout(timer); timer = null; if (alive() && !finished && !doc.hidden) timer = env.setTimeout(refresh, 5000); }
		function showDns(data) {
			dns.replaceChildren();
			if (typeof data.customer_domain !== 'string' || !DOMAIN.test(data.customer_domain) || data.customer_domain.length > 249 || !Array.isArray(data.dns_records) || data.dns_records.length !== 2) return;
			var records = data.dns_records;
			if (!records.every(function (record, index) { return record && record.name === ['@', 'www'][index] && record.type === 'A' && record.ttl === 600 && typeof record.value === 'string' && /^(?:\d{1,3}\.){3}\d{1,3}$/.test(record.value) && record.value.split('.').every(function (part) { return Number(part) <= 255; }); }) || records[0].value !== records[1].value) return;
			var p = doc.createElement('p'); p.textContent = '请在域名服务商为 ' + data.customer_domain + ' 手动添加以下两条解析。无需修改管理后台域名。';
			var table = doc.createElement('table'); table.className = 'widefat striped';
			var head = doc.createElement('tr'), thead = doc.createElement('thead'), body = doc.createElement('tbody');
			['主机记录', '记录类型', '记录值', 'TTL'].forEach(function (label) { var th = doc.createElement('th'); th.textContent = label; head.append(th); }); thead.append(head);
			records.forEach(function (record) { var tr = doc.createElement('tr'); [record.name, record.type, record.value, record.ttl].forEach(function (value) { var td = doc.createElement('td'); td.textContent = String(value); tr.append(td); }); body.append(tr); });
			table.append(thead, body); dns.append(p, table);
		}
		async function refresh() {
			env.clearTimeout(timer); timer = null;
			if (!alive() || finished || busy || doc.hidden) return;
			busy = true; var controller = new env.AbortController(); pending = controller;
			var timeout = env.setTimeout(function () { controller.abort(); }, 25000); pendingTimeout = timeout;
			try {
				var response = await env.fetch(endpoint.href, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal });
				if (!alive() || controller.signal.aborted) return;
				if (!response.ok || response.redirected) throw new Error('status');
				var json = await response.json();
				if (!alive() || controller.signal.aborted) return;
				var data = json && json.data;
				if (!json || json.success !== true || !data || Array.isArray(data) || typeof data.state !== 'string' || !/^[a-z][a-z0-9_-]{0,79}$/.test(data.state) || typeof data.message !== 'string' || data.message.length > 1000) throw new Error('status');
				status.textContent = data.message; links.replaceChildren(); showDns(data);
				if (data.state === 'complete') {
					[['management_url', '商家后台', '/wp-admin/'], ['public_url', '顾客店铺', '/']].forEach(function (item) {
						var href = safeLink(data[item[0]], item[2]); if (!href) return;
						var a = doc.createElement('a'); a.href = href; a.textContent = item[1]; a.className = 'button'; links.append(a, doc.createTextNode(' '));
					}); finished = true;
				} else if (data.state === 'failed') finished = true;
			} catch (error) { if (alive()) status.textContent = '暂时无法读取进度，任务不会因此重新创建。'; }
			finally { env.clearTimeout(timeout); if (pending === controller) { pending = null; pendingTimeout = null; } busy = false; if (alive()) schedule(); }
		}
		function visibility() { if (doc.hidden) { env.clearTimeout(timer); timer = null; } else refresh(); }
		doc.addEventListener('visibilitychange', visibility);
		function destroy() { if (disposed) return; disposed = true; env.clearTimeout(timer); env.clearTimeout(pendingTimeout); timer = null; pendingTimeout = null; doc.removeEventListener('visibilitychange', visibility); if (pending) pending.abort(); pending = null; }
		refresh(); return { destroy: destroy, refresh: refresh };
	}
	function install(env) {
		if (env.pchqProvisionLifecycle) return env.pchqProvisionLifecycle;
		var current = null, doc = env.document;
		function dispose() { if (current) current.destroy(); current = null; }
		function initialize() { dispose(); current = mount(env); }
		function resume() { if (!current) initialize(); }
		doc.addEventListener('pchq:before-swap', dispose); doc.addEventListener('pchq:after-swap', initialize);
		env.addEventListener('pagehide', dispose); env.addEventListener('pageshow', resume);
		var lifecycle = { destroy: function () {
			dispose(); doc.removeEventListener('pchq:before-swap', dispose); doc.removeEventListener('pchq:after-swap', initialize);
			env.removeEventListener('pagehide', dispose); env.removeEventListener('pageshow', resume); delete env.pchqProvisionLifecycle;
		} };
		env.pchqProvisionLifecycle = lifecycle; initialize(); return lifecycle;
	}
	return { mount: mount, install: install, safeLink: safeLink };
}));
