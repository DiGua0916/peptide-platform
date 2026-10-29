/* Small, read-only enhancements for the VialCove management workspace. */
(function () {
	'use strict';
	if (!document.body.classList.contains('pchq-workspace')) return;
	// A keyboard focus target is useful for tables that genuinely need horizontal scrolling.
	document.querySelectorAll('.pchq-scroll').forEach(function (region) {
		if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '0');
		region.setAttribute('role', 'region');
		var table = region.querySelector('table');
		if (table && !region.hasAttribute('aria-label')) {
			var heading = document.querySelector('.wrap > h1');
			if (heading) region.setAttribute('aria-label', heading.textContent.trim());
		}
	});
	// Keep date labels legible when the SVG is scaled down; the data table retains every day.
	var charts = Array.from(document.querySelectorAll('.pchq-chart svg'));
	function fitChartLabels() {
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
		var observer = new ResizeObserver(fitChartLabels);
		charts.forEach(function (chart) { observer.observe(chart); });
	}
	// Animate only the surface that answers an explicit action. Native toggle/navigation
	// executes immediately; this layer never delays, intercepts or submits the action.
	var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
	var running = new Set();
	function reveal(element, horizontal) {
		if (!element || reduced.matches || typeof element.animate !== 'function') return;
		var animation = element.animate([
			{ opacity: 0, transform: horizontal ? 'translateX(-8px)' : 'translateY(4px)' },
			{ opacity: 1, transform: 'translate(0, 0)' }
		], { duration: 200, easing: 'cubic-bezier(.22,1,.36,1)' });
		running.add(animation);
		animation.finished.then(function () { running.delete(animation); }, function () { running.delete(animation); });
	}
	document.addEventListener('toggle', function (event) {
		var details = event.target;
		if (!(details instanceof HTMLDetailsElement) || !details.open || !details.closest('.pchq')) return;
		Array.from(details.children).forEach(function (child) {
			if (child.tagName !== 'SUMMARY') reveal(child, false);
		});
	}, true);
	var wrap = document.getElementById('wpwrap');
	if (wrap && typeof MutationObserver !== 'undefined') {
		var wasOpen = wrap.classList.contains('wp-responsive-open');
		var menuObserver = new MutationObserver(function () {
			var open = wrap.classList.contains('wp-responsive-open');
			if (open && !wasOpen) reveal(document.getElementById('adminmenuwrap'), true);
			wasOpen = open;
		});
		menuObserver.observe(wrap, { attributes: true, attributeFilter: ['class'] });
	}
	if (typeof reduced.addEventListener === 'function') reduced.addEventListener('change', function () {
		if (reduced.matches) running.forEach(function (animation) { animation.cancel(); });
	});
}());
