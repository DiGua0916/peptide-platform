/* Optional homepage motion and report reading. Product/cart behavior remains native Woo. */
(() => {
  'use strict';
  const initialized = new WeakSet();
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function initCounters(section) {
    if (initialized.has(section)) return;
    initialized.add(section);
    const nodes = [...section.querySelectorAll('[data-vc-demo-count]')];
    const entries = nodes.map(node => ({
      node,
      target: Number(node.dataset.vcDemoCount),
      precision: Math.max(0, Math.min(6, Number(node.dataset.vcDemoPrecision) || 0)),
      final: node.textContent
    })).filter(entry => Number.isFinite(entry.target));
    if (!entries.length) return;
    const formatters = entries.map(entry => new Intl.NumberFormat(document.documentElement.lang || 'en', {
      minimumFractionDigits: entry.precision,
      maximumFractionDigits: entry.precision
    }));
    const visibility = new Map(entries.map(entry => [entry.node, false]));
    let elapsed = 0;
    let lastFrame = null;
    let frame = null;
    let completed = false;
    let observer;
    const duration = 2200;

    function pause() {
      if (frame !== null) cancelAnimationFrame(frame);
      frame = null;
      lastFrame = null;
    }
    function finish() {
      completed = true;
      pause();
      entries.forEach(entry => { entry.node.textContent = entry.final; });
      observer?.disconnect();
      document.removeEventListener('visibilitychange', updateRunning);
      motion.removeEventListener?.('change', motionChanged);
    }
    function visible() {
      return !completed && !document.hidden && [...visibility.values()].every(Boolean);
    }
    function tick(now) {
      frame = null;
      if (!visible()) { lastFrame = null; return; }
      if (lastFrame !== null) elapsed += Math.min(100, now - lastFrame);
      lastFrame = now;
      const progress = Math.min(1, elapsed / duration);
      const eased = 1 - Math.pow(1 - progress, 4);
      entries.forEach((entry, index) => {
        const scale = Math.pow(10, entry.precision);
        const value = Math.trunc(entry.target * eased * scale) / scale;
        entry.node.textContent = formatters[index].format(value);
      });
      if (progress === 1) finish();
      else frame = requestAnimationFrame(tick);
    }
    function updateRunning() {
      if (visible()) {
        if (frame === null) frame = requestAnimationFrame(tick);
      } else pause();
    }
    function motionChanged() { if (motion.matches) finish(); }
    if (motion.matches || !('IntersectionObserver' in window)) return;
    entries.forEach(entry => {
      const value = entry.node.closest('strong');
      if (value) {
        value.setAttribute('aria-label', value.textContent);
        entry.node.setAttribute('aria-hidden', 'true');
      }
      entry.node.textContent = formatters[entries.indexOf(entry)].format(0);
    });
    observer = new IntersectionObserver(changes => {
      changes.forEach(change => visibility.set(change.target, change.isIntersecting && change.intersectionRatio >= 0.65));
      updateRunning();
    }, { threshold: [0, 0.65, 1], rootMargin: '0px 0px -24px 0px' });
    entries.forEach(entry => observer.observe(entry.node));
    document.addEventListener('visibilitychange', updateRunning);
    motion.addEventListener?.('change', motionChanged);
  }

  let reportDialog;
  let reportTrigger;
  function createReportDialog() {
    const dialog = document.createElement('dialog');
    dialog.className = 'vc-demo-report-dialog';
    dialog.setAttribute('aria-labelledby', 'vc-demo-report-reader-title');
    dialog.innerHTML = '<div class="vc-demo-report-dialog-head"><div><h2 id="vc-demo-report-reader-title"></h2><p data-report-meta></p></div><button type="button" class="vc-demo-report-close" aria-label="Close report">×</button></div><div class="vc-demo-report-dialog-body"><a target="_blank" rel="noopener noreferrer">Open the original report ↗</a><p data-report-context></p><iframe title="Certificate of analysis PDF" referrerpolicy="no-referrer"></iframe></div>';
    dialog.querySelector('button').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      const rect = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
      dialog.querySelector('iframe').removeAttribute('src');
      reportTrigger?.focus({ preventScroll: true });
    });
    document.body.append(dialog);
    return dialog;
  }
  document.addEventListener('click', event => {
    const link = event.target.closest?.('[data-vc-demo-report]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !window.HTMLDialogElement) return;
    // Keep non-library references as ordinary links; do not embed arbitrary destinations.
    const url = new URL(link.href, location.href);
    const route = url.searchParams.get('rest_route') || url.pathname;
    if (url.origin !== location.origin || !/\/report-library\/\d+\/file\/?$/.test(route)) return;
    event.preventDefault();
    reportDialog ||= createReportDialog();
    reportTrigger = link;
    reportDialog.querySelector('h2').textContent = link.dataset.vcReportTitle || 'Certificate of analysis';
    reportDialog.querySelector('[data-report-meta]').textContent = link.dataset.vcReportMeta || '';
    reportDialog.querySelector('[data-report-context]').textContent = link.dataset.vcReportContext || '';
    reportDialog.querySelector('.vc-demo-report-dialog-body>a').href = link.href;
    reportDialog.querySelector('iframe').src = link.href;
    reportDialog.showModal();
  });

  function boot(root = document) {
    if (root.matches?.('[data-vc-demo-counters]')) initCounters(root);
    root.querySelectorAll?.('[data-vc-demo-counters]').forEach(initCounters);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => boot(), { once: true });
  else boot();
  // The builder can refresh only the edited section. Initialize only new subtrees.
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) boot(node); }));
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
