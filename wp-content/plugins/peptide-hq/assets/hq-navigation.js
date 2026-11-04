/* Progressive navigation for explicitly supported native HQ read screens. */
(function (factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	if (typeof window !== 'undefined' && window.document) {
		var boot = function () { if (!window.pchqNavigation) window.pchqNavigation = api.mount(window); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
		else boot();
	}
}(function () {
	'use strict';
	function safeURL(href, base, policy) {
		try {
			var root = new URL(base), url = new URL(href, root);
			if (url.origin !== root.origin || url.pathname !== root.pathname || url.username || url.password) return null;
			var params = url.searchParams, page = params.get('page'), rules = policy[page], seen = new Set();
			if (!Object.prototype.hasOwnProperty.call(policy, page) || !rules) return null;
			for (var pair of params) {
				var key = pair[0], value = pair[1];
				if (seen.has(key)) return null;
				seen.add(key);
				if (key === 'page') continue;
				if (!Object.prototype.hasOwnProperty.call(rules, key)) return null;
				var rule = rules[key];
				if (Array.isArray(rule)) { if (rule.indexOf(value) === -1) return null; }
				else if (rule === 'positiveId') { if (!/^[1-9][0-9]*$/.test(value) || !Number.isSafeInteger(Number(value))) return null; }
				else if (rule === 'nonnegativeId') { if (!/^(?:0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) return null; }
				else if (rule === 'signedId') { if (!/^(?:0|-?[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) return null; }
				else if (rule === 'pageNumber') { if (!/^[1-9][0-9]*$/.test(value) || Number(value) > 100000) return null; }
				else if (rule === 'accountId') { if (!/^(?:default|[a-f0-9]{32})$/.test(value)) return null; }
				else if (rule === 'uuid4') { if (!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value)) return null; }
				else return null;
			}
			if (!seen.has('page') || (params.has('site_tools') && params.has('site_server')) || (params.has('add') && params.has('edit'))) return null;
			if (page === 'pchq-payments' && ((params.has('new_account') && params.has('edit_account')) || (params.has('deleted') && (params.has('new_account') || params.has('edit_account'))))) return null;
			return url;
		} catch (e) { return null; }
	}
	function formURL(form, submitter, base, policy) {
		try {
			if (!form || form.tagName !== 'FORM' || (submitter && submitter.form !== form)) return null;
			var method = (submitter && submitter.getAttribute('formmethod')) || form.method || 'get';
			var target = (submitter && submitter.getAttribute('formtarget')) || form.target;
			var encoding = (submitter && submitter.getAttribute('formenctype')) || form.enctype || 'application/x-www-form-urlencoded';
			if (method.toLowerCase() !== 'get' || (target && target !== '_self') || encoding !== 'application/x-www-form-urlencoded') return null;
			var root = new URL(base), action = (submitter && submitter.getAttribute('formaction')) || form.action || base, url = new URL(action, base);
			if (url.origin !== root.origin || url.pathname !== root.pathname || url.username || url.password) return null;
			if (url.search && !safeURL(url.href, base, policy)) return null;
			// Match a native GET: successful controls replace the action's query string.
			url.search = '';
			var data = new form.ownerDocument.defaultView.FormData(form, submitter || undefined);
			for (var pair of data) { if (typeof pair[1] !== 'string') return null; url.searchParams.append(pair[0], pair[1]); }
			return safeURL(url.href, base, policy);
		} catch (error) { return null; }
	}
	function formSignature(form) {
		return JSON.stringify(Array.from(form.elements || []).filter(function (field) {
			return !field.disabled && !field.readOnly && !/^(hidden|submit|button|reset|image)$/.test(field.type || '') && field.tagName !== 'BUTTON';
		}).map(function (field, index) {
			var value = field.value;
			if (field.type === 'checkbox' || field.type === 'radio') value = field.checked;
			if (field.type === 'file') value = Array.from(field.files || []).map(function (file) { return [file.name, file.size, file.lastModified]; });
			if (field.multiple && field.options) value = Array.from(field.options).filter(function (o) { return o.selected; }).map(function (o) { return o.value; });
			return [index, field.name, field.type, value];
		}));
	}
	function assets(doc) {
		return new Set(Array.from(doc.querySelectorAll('script[src],link[rel="stylesheet"][href]')).map(function (element) {
			return element.tagName + ':' + new URL(element.getAttribute('src') || element.getAttribute('href'), doc.baseURI).href;
		}));
	}
	function readMeta(doc) {
		var elements = doc.querySelectorAll('meta[name="pchq-navigation"]');
		if (elements.length !== 1) return null;
		var element = elements[0];
		try {
			var data = { build: element.content, base: element.dataset.base, page: element.dataset.page, screen: element.dataset.screen, session: element.dataset.session, policy: JSON.parse(element.dataset.policy) };
			if (!data.build || !data.base || !/^[a-zA-Z0-9_-]{1,180}$/.test(data.screen || '') || !data.session || !data.policy || typeof data.policy !== 'object' || !Object.prototype.hasOwnProperty.call(data.policy, data.page)) return null;
			if (data.page === 'pchq' ? data.screen !== 'toplevel_page_pchq' : !data.screen.endsWith('_page_' + data.page)) return null;
			return data;
		} catch (e) { return null; }
	}
	function compatibleDocument(doc, currentMeta, existingAssets, url) {
		var meta = readMeta(doc), target = safeURL(url, currentMeta.base, currentMeta.policy);
		if (!meta || !target || meta.build !== currentMeta.build || meta.session !== currentMeta.session || meta.base !== currentMeta.base || meta.page !== target.searchParams.get('page') || JSON.stringify(meta.policy) !== JSON.stringify(currentMeta.policy)) return null;
		if (!doc.body || !doc.body.classList.contains('pchq-workspace') || !doc.body.classList.contains(meta.screen) || doc.querySelectorAll('#wpbody-content').length !== 1) return null;
		var content = doc.querySelector('#wpbody-content'), location = doc.querySelector('.pchq-workspace-location');
		if (!content.querySelector(meta.page === 'pchq-payments' ? '.wrap.pc-pay-hub' : '.wrap.pchq') || !location || content.querySelector('script,style,iframe,object,embed,base')) return null;
		for (var asset of assets(doc)) { if (!existingAssets.has(asset)) return null; }
		return { content: content, meta: meta, location: location };
	}
	function mount(env, options) {
		options = options || {};
		var doc = env.document, meta = readMeta(doc), content = doc.querySelector('#wpbody-content');
		if (!meta || !content || !env.fetch || !env.AbortController || !env.DOMParser || !env.history.pushState || !doc.body.classList.contains('pchq-workspace') || !doc.body.classList.contains(meta.screen)) return null;
		var initial = safeURL(env.location.href, meta.base, meta.policy);
		if (!initial) return null;
		var existingAssets = assets(doc), currentURL = initial.href, sequence = 0, pending = null, destroyed = false;
		var signatures = new Map(), listeners = [], position = 0, restoringPop = false, discardApproved = false, scrollTimer = null;
		var previousScrollRestoration = env.history.scrollRestoration, nativeSubmission = null;
		var nativeNavigate = options.navigate || function (url, replace) { env.location[replace ? 'replace' : 'assign'](url); };
		var status = doc.createElement('div');
		status.className = 'pchq-navigation-status'; status.hidden = true; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
		var statusText = doc.createElement('span'), fullLink = doc.createElement('a');
		fullLink.textContent = '重新打开'; fullLink.hidden = true; status.append(statusText, fullLink);
		var header = doc.querySelector('.pchq-workspace-head');
		(header || content).insertAdjacentElement('afterend', status);
		function on(target, type, handler) { target.addEventListener(type, handler); listeners.push(function () { target.removeEventListener(type, handler); }); }
		function rememberForms() {
			signatures.clear();
			content.querySelectorAll('form').forEach(function (form) { if ((form.method || 'get').toLowerCase() === 'post') signatures.set(form, formSignature(form)); });
		}
		function dirtyStamp() {
			var changed = [];
			signatures.forEach(function (signature, form) {
				if (!form.isConnected) return;
				var dialog = form.closest('dialog');
				if (form.hasAttribute('data-pchq-reset-on-open') && dialog && !dialog.open) return;
				var value = formSignature(form);
				if (value !== signature) changed.push(value);
			});
			return changed.length ? JSON.stringify(changed) : '';
		}
		function approve(previous) {
			var stamp = dirtyStamp();
			if (stamp && stamp !== previous) return env.VialCoveDialogs.confirm('有尚未保存的修改，离开会丢失这些修改。仍要切换页面吗？').then(ok => ok ? stamp : null);
			return stamp;
		}
		function historyRecord(url, scroll, index) { return { pchqNavigation: { url: url, scroll: scroll, position: index } }; }
		function saveScroll() {
			if (destroyed || env.location.href !== currentURL) return true;
			var state = Object.assign({}, env.history.state || {}, historyRecord(currentURL, { x: env.scrollX || 0, y: env.scrollY || 0 }, position));
			try { env.history.replaceState(state, '', currentURL); return true; }
			catch (error) { return false; }
		}
		function busy(value, label) {
			doc.body.classList.toggle('pchq-is-navigating', value);
			content.inert = value;
			if (value) {
				content.setAttribute('aria-busy', 'true'); status.hidden = false; fullLink.hidden = true; status.classList.remove('is-error'); statusText.textContent = label || '正在打开页面…';
			} else { content.removeAttribute('aria-busy'); }
		}
		function recoverPop(config) {
			if (!config.pop) return;
			var record = (env.history.state || {}).pchqNavigation, delta = record && Number.isSafeInteger(record.position) ? position - record.position : 0;
			if (delta) { restoringPop = true; env.history.go(delta); }
			else env.history.replaceState(historyRecord(currentURL, { x: env.scrollX || 0, y: env.scrollY || 0 }, position), '', currentURL);
		}
		async function hardLoad(url, config, previous) {
			if (await approve(previous) === null) { status.hidden = true; recoverPop(config); return false; }
			discardApproved = true;
			nativeNavigate(url, !!config.pop);
			return false;
		}
		var navigationIntent = 0;
		async function navigate(href, config) {
			var intent = ++navigationIntent;
			config = config || {};
			var url = safeURL(href, meta.base, meta.policy);
			if (!url || destroyed) return false;
			var approved = approve();
			if (approved && typeof approved.then === 'function') approved = await approved;
			if (intent !== navigationIntent || destroyed) return false;
			if (approved === null) { recoverPop(config); return false; }
			if (!config.pop && !saveScroll()) return hardLoad(url.href, config, approved);
			var id = ++sequence;
			if (pending) pending.abort();
			var controller = new env.AbortController(); pending = controller;
			var timedOut = false, committing = false, historyPushed = false, timer = env.setTimeout(function () { timedOut = true; controller.abort(); }, 25000);
			busy(true, '正在打开页面…');
			try {
				var response = await env.fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow', headers: { Accept: 'text/html' }, signal: controller.signal });
				if (id !== sequence || destroyed) return false;
				if (response.status === 401 || response.status === 403) return hardLoad(url.href, config, approved);
				if (!response.ok) throw new Error('http');
				var type = response.headers.get('content-type') || '';
				if (!/^text\/html(?:;|$)/i.test(type) || !response.url || new URL(response.url).href.replace(/#.*$/, '') !== url.href.replace(/#.*$/, '')) return hardLoad(url.href, config, approved);
				var html = await response.text();
				if (id !== sequence || destroyed) return false;
				if (html.length > 2 * 1024 * 1024) return hardLoad(url.href, config, approved);
				var parsed = new env.DOMParser().parseFromString(html, 'text/html');
				// DOMParser has an inert base URI; resolve relative asset URLs against the verified response.
				if (parsed.querySelector('base')) return hardLoad(url.href, config, approved);
				var base = parsed.createElement('base'); base.href = response.url; parsed.head.prepend(base);
				var next = compatibleDocument(parsed, meta, existingAssets, url.href);
				if (!next) return hardLoad(url.href, config, approved);
				if (await approve(approved) === null) { status.hidden = true; recoverPop(config); return false; }
				if (id !== sequence || destroyed) return false;
				var replacement = doc.importNode(next.content, true), location = doc.querySelector('.pchq-workspace-location');
				if (!location) return hardLoad(url.href, config, approved);
				var scroll = config.scroll || { x: 0, y: 0 }, nextPosition = position;
				committing = true;
				if (!config.pop) {
					if (url.href !== currentURL) { nextPosition++; env.history.pushState(historyRecord(url.href, scroll, nextPosition), '', url.href); historyPushed = true; }
					else env.history.replaceState(historyRecord(url.href, scroll, nextPosition), '', url.href);
				} else if (Number.isSafeInteger(config.position)) nextPosition = config.position;
				if (env.screenMeta && env.screenMeta.toggles && typeof env.screenMeta.toggles.off === 'function') env.screenMeta.toggles.off('click', env.screenMeta.toggleEvent);
				doc.dispatchEvent(new env.CustomEvent('pchq:before-swap'));
				content.replaceWith(replacement); content = replacement;
				if (typeof content.animate === 'function' && !(env.matchMedia && env.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
					content.animate([{ opacity: .88, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 200, easing: 'cubic-bezier(.22,1,.36,1)' });
				}
				location.replaceChildren.apply(location, Array.from(next.location.childNodes).map(function (node) { return doc.importNode(node, true); }));
				doc.body.classList.remove(meta.screen); doc.body.classList.add(next.meta.screen);
				meta = next.meta; doc.title = parsed.title;
				var marker = doc.querySelector('meta[name="pchq-navigation"]'); marker.dataset.page = meta.page; marker.dataset.screen = meta.screen;
				doc.querySelectorAll('#toplevel_page_pchq .wp-submenu li').forEach(function (item) {
					var link = item.querySelector('a[href]'), destination = link && safeURL(link.href, meta.base, meta.policy), selected = !!destination && destination.searchParams.get('page') === meta.page;
					item.classList.toggle('current', selected);
					if (link) { link.classList.toggle('current', selected); if (selected) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current'); }
				});
				currentURL = url.href;
				position = nextPosition;
				rememberForms();
				doc.dispatchEvent(new env.CustomEvent('pchq:after-swap'));
				if (env.screenMeta && typeof env.screenMeta.init === 'function') env.screenMeta.init();
				if (env.jQuery) env.jQuery(doc).trigger('wp-notice-added');
				var heading = content.querySelector('h1');
				if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
				env.scrollTo(scroll.x || 0, scroll.y || 0);
				statusText.textContent = '页面已打开'; status.hidden = true;
				return true;
			} catch (error) {
				if (id !== sequence || destroyed) return false;
				if (committing) { discardApproved = true; nativeNavigate(url.href, !!config.pop || historyPushed); return false; }
				recoverPop(config); status.hidden = false; status.classList.add('is-error');
				statusText.textContent = timedOut ? '页面读取超时，当前内容已保留。' : '页面暂时无法读取，当前内容已保留。';
				fullLink.href = url.href; fullLink.hidden = false;
				return false;
			} finally {
				env.clearTimeout(timer);
				if (id === sequence && !destroyed) { pending = null; busy(false); }
			}
		}
		on(doc, 'click', function (event) {
			nativeSubmission = null;
			if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			var link = event.target.closest && event.target.closest('a[href]');
			if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.closest('[data-pchq-no-navigation]')) return;
			if (link === fullLink) { if (approve() === null) event.preventDefault(); else discardApproved = true; return; }
			var url = safeURL(link.href, meta.base, meta.policy);
			if (!url || (url.href.replace(/#.*$/, '') === currentURL.replace(/#.*$/, '') && url.hash)) return;
			event.preventDefault(); navigate(url.href);
		});
		on(env, 'popstate', function (event) {
			if (restoringPop) { restoringPop = false; return; }
			var record = event.state && event.state.pchqNavigation;
			if (!record || !safeURL(env.location.href, meta.base, meta.policy)) { hardLoad(env.location.href, { pop: true }); return; }
			navigate(env.location.href, { pop: true, scroll: record.scroll, position: record.position });
		});
		on(env, 'scroll', function () {
			if (scrollTimer !== null) return;
			scrollTimer = env.setTimeout(function () { scrollTimer = null; saveScroll(); }, 500);
		});
		on(env, 'beforeunload', function (event) {
			if (!discardApproved && !(nativeSubmission && !nativeSubmission.defaultPrevented) && dirtyStamp()) { event.preventDefault(); event.returnValue = ''; }
		});
		// Observe a real validated submit; never prevent, replay or serialize it.
		on(doc, 'submit', function (event) {
			var form = event.target;
			if (!event.defaultPrevented && form && form.tagName === 'FORM' && !form.closest('[data-pchq-no-navigation]')) {
				var destination = formURL(form, event.submitter, meta.base, meta.policy);
				if (destination) { event.preventDefault(); nativeSubmission = null; navigate(destination.href); return; }
			}
			if (event.defaultPrevented || !form || form.tagName !== 'FORM') return;
			var submitter = event.submitter, method = (submitter && submitter.getAttribute('formmethod')) || form.method, target = (submitter && submitter.getAttribute('formtarget')) || form.target;
			if (method.toLowerCase() !== 'post' || (target && target !== '_self')) return;
			try {
				var action = new URL((submitter && submitter.getAttribute('formaction')) || form.action, meta.base), root = new URL(meta.base);
				if (action.origin === root.origin && action.pathname === root.pathname.replace(/admin\.php$/, 'admin-post.php')) nativeSubmission = event;
			} catch (e) { /* Native navigation remains authoritative. */ }
		});
		function edited() { nativeSubmission = null; discardApproved = false; }
		on(doc, 'input', edited); on(doc, 'change', edited);
		on(env, 'pageshow', function () { nativeSubmission = null; discardApproved = false; });
		function destroy() {
			destroyed = true; sequence++; if (pending) pending.abort(); env.clearTimeout(scrollTimer);
			listeners.forEach(function (remove) { remove(); }); signatures.clear(); nativeSubmission = null; busy(false); status.remove();
			env.history.scrollRestoration = previousScrollRestoration;
		}
		on(env, 'pagehide', function (event) {
			if (!event.persisted) { destroy(); return; }
			sequence++; if (pending) pending.abort(); pending = null; busy(false); status.hidden = true;
		});
		var oldRecord = (env.history.state || {}).pchqNavigation;
		if (oldRecord && Number.isSafeInteger(oldRecord.position)) position = oldRecord.position;
		env.history.scrollRestoration = 'manual'; rememberForms();
		if (!saveScroll()) { destroy(); return null; }
		return { navigate: navigate, destroy: destroy, dirty: function () { return !!dirtyStamp(); } };
	}
	return { safeURL: safeURL, formURL: formURL, formSignature: formSignature, assets: assets, readMeta: readMeta, compatibleDocument: compatibleDocument, mount: mount };
}));
