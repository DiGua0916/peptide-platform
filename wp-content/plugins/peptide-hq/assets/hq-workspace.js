/* Small enhancements for the VialCove management workspace. */
(function () {
	'use strict';
	if (!document.body.classList.contains('pchq-workspace')) return;
	var disposeCurrent = null;
	function initializeContent() {
		if (disposeCurrent) disposeCurrent();
		var disposed = false;
		var listeners = [];
		var observer = null;
		var menuObserver = null;
		var content = document.getElementById('wpbody-content') || document;
		function listen(target, type, listener, options) {
			target.addEventListener(type, listener, options);
			listeners.push(function () { target.removeEventListener(type, listener, options); });
		}
		// A keyboard focus target is useful for tables that genuinely need horizontal scrolling.
		content.querySelectorAll('.pchq-scroll').forEach(function (region) {
			if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '0');
			region.setAttribute('role', 'region');
			var table = region.querySelector('table');
			if (table && !region.hasAttribute('aria-label')) {
				var heading = content.querySelector('.wrap > h1');
				if (heading) region.setAttribute('aria-label', heading.textContent.trim());
			}
		});
		// Store tools keep their server-rendered forms and enter the native top layer.
		var siteDialogs = Array.from(content.querySelectorAll('dialog.pchq-site-dialog'));
		var activeSiteDialog = null;
		var siteDialogOpener = null;
		function siteDialogFocusables(dialog) {
			return Array.from(dialog.querySelectorAll('a[href],button:not([disabled]),input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function (element) {
				return !element.hidden && element.getClientRects().length > 0;
			});
		}
		function finishSiteDialog(dialog) {
			if (activeSiteDialog !== dialog) return;
			var opener = siteDialogOpener;
			activeSiteDialog = null;
			siteDialogOpener = null;
			document.body.classList.remove('pchq-site-dialog-open');
			dialog.removeAttribute('aria-modal');
			if (opener) {
				opener.setAttribute('aria-expanded', 'false');
				if (!disposed && opener.isConnected) opener.focus({ preventScroll: true });
			}
		}
		function closeSiteDialog(dialog) {
			if (dialog.open && typeof dialog.close === 'function') dialog.close();
			finishSiteDialog(dialog);
		}
		function openSiteDialog(dialog, opener) {
			if (disposed) return;
			if (activeSiteDialog && activeSiteDialog !== dialog) closeSiteDialog(activeSiteDialog);
			// An explicit GET fallback can render open before JavaScript is available.
			if (dialog.open) dialog.close();
			activeSiteDialog = dialog;
			siteDialogOpener = opener || document.activeElement;
			// Cancelled server choices never replace the assignment shown on the next open.
			dialog.querySelectorAll('form[data-pchq-reset-on-open]').forEach(function (form) { form.reset(); });
			dialog.showModal();
			dialog.setAttribute('aria-modal', 'true');
			document.body.classList.add('pchq-site-dialog-open');
			if (opener) opener.setAttribute('aria-expanded', 'true');
			var first = siteDialogFocusables(dialog)[0] || dialog;
			first.focus({ preventScroll: true });
		}
		siteDialogs.forEach(function (dialog) {
			if (typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') return;
			var opener = content.querySelector('[data-pchq-site-tools-open="' + dialog.id + '"]');
			if (opener) listen(opener, 'click', function (event) {
				event.preventDefault();
				openSiteDialog(dialog, opener);
			});
			dialog.querySelectorAll('[data-pchq-site-tools-close]').forEach(function (button) {
				listen(button, 'click', function (event) { event.preventDefault(); closeSiteDialog(dialog); });
			});
			listen(dialog, 'cancel', function (event) { event.preventDefault(); closeSiteDialog(dialog); });
			listen(dialog, 'close', function () {
				// A GET-open dialog is closed then reopened as modal; ignore its queued old close event.
				if (!dialog.open) finishSiteDialog(dialog);
			});
			listen(dialog, 'click', function (event) {
				if (event.target !== dialog) return;
				var rect = dialog.getBoundingClientRect();
				if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeSiteDialog(dialog);
			});
			listen(dialog, 'keydown', function (event) {
				if (activeSiteDialog !== dialog) return;
				if (event.key === 'Escape') { event.preventDefault(); closeSiteDialog(dialog); return; }
				if (event.key !== 'Tab') return;
				var items = siteDialogFocusables(dialog);
				if (!items.length) { event.preventDefault(); dialog.focus({ preventScroll: true }); return; }
				var first = items[0], last = items[items.length - 1];
				if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus({ preventScroll: true }); }
				else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus({ preventScroll: true }); }
			});
			if (dialog.open) openSiteDialog(dialog, opener);
		});
		if (siteDialogs.length) listen(document, 'focusin', function (event) {
			if (activeSiteDialog && !activeSiteDialog.contains(event.target)) {
				var first = siteDialogFocusables(activeSiteDialog)[0] || activeSiteDialog;
				first.focus({ preventScroll: true });
			}
		});
		// Keep date labels legible when the SVG is scaled down; the data table retains every day.
		var charts = Array.from(content.querySelectorAll('.pchq-chart svg'));
		function fitChartLabels() {
			if (disposed) return;
			charts.forEach(function (chart) {
				var labels = Array.from(chart.querySelectorAll('.pchq-axis[text-anchor="middle"]'));
				var compact = chart.getBoundingClientRect().width < 480;
				labels.forEach(function (label, index) {
					label.classList.toggle('pchq-axis--suppressed', compact && index > 0 && index < labels.length - 1 && index % 2 === 1);
				});
			});
		}
		fitChartLabels();
		if (charts.length && typeof ResizeObserver !== 'undefined') {
			observer = new ResizeObserver(fitChartLabels);
			charts.forEach(function (chart) { observer.observe(chart); });
		}
		// Animate only the surface that answers an explicit action. Native toggle/navigation
		// executes immediately; this layer never delays, intercepts or submits the action.
		var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
		var running = new Set();
		function reveal(element, horizontal) {
			if (disposed || !element || reduced.matches || typeof element.animate !== 'function') return;
			var animation = element.animate([
				{ opacity: 0, transform: horizontal ? 'translateX(-8px)' : 'translateY(4px)' },
				{ opacity: 1, transform: 'translate(0, 0)' }
			], { duration: 200, easing: 'cubic-bezier(.22,1,.36,1)' });
			running.add(animation);
			animation.finished.then(function () { running.delete(animation); }, function () { running.delete(animation); });
		}
		listen(document, 'toggle', function (event) {
			var details = event.target;
			if (!(details instanceof HTMLDetailsElement) || !details.open || !details.closest('.pchq')) return;
			Array.from(details.children).forEach(function (child) {
				if (child.tagName !== 'SUMMARY') reveal(child, false);
			});
		}, true);
		var wrap = document.getElementById('wpwrap');
		if (wrap && typeof MutationObserver !== 'undefined') {
			var wasOpen = wrap.classList.contains('wp-responsive-open');
			menuObserver = new MutationObserver(function () {
				if (disposed) return;
				var open = wrap.classList.contains('wp-responsive-open');
				if (open && !wasOpen) reveal(document.getElementById('adminmenuwrap'), true);
				wasOpen = open;
			});
			menuObserver.observe(wrap, { attributes: true, attributeFilter: ['class'] });
		}
		if (typeof reduced.addEventListener === 'function') listen(reduced, 'change', function () {
			if (reduced.matches) running.forEach(function (animation) { animation.cancel(); });
		});
		disposeCurrent = function () {
			if (disposed) return;
			disposed = true;
			listeners.forEach(function (remove) { remove(); });
			listeners.length = 0;
			if (activeSiteDialog) closeSiteDialog(activeSiteDialog);
			siteDialogs.forEach(function (dialog) {
				if (dialog.open && typeof dialog.close === 'function') dialog.close();
				dialog.removeAttribute('aria-modal');
			});
			document.body.classList.remove('pchq-site-dialog-open');
			if (observer) observer.disconnect();
			if (menuObserver) menuObserver.disconnect();
			running.forEach(function (animation) { animation.cancel(); });
			running.clear();
		};
	}
	// The persistent shell owns these hooks; per-content listeners are disposable.
	document.addEventListener('pchq:before-swap', function () {
		if (disposeCurrent) disposeCurrent();
		disposeCurrent = null;
	});
	document.addEventListener('pchq:after-swap', initializeContent);
	initializeContent();
}());
