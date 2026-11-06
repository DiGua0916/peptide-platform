/* Optional homepage motion and report reading. Product/cart behavior remains native Woo. */
(() => {
  'use strict';
  const initialized = new WeakSet();
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const touchReports = window.matchMedia('(max-width: 767px) and (hover: none), (max-width: 767px) and (pointer: coarse)');
  const reportAnimations = new Map();

  function initReportMotion(stack) {
    if (reportAnimations.has(stack) || !('IntersectionObserver' in window)) return;
    const papers = [...stack.querySelectorAll('.vc-demo-report-paper')];
    if (papers.length < 2) return;
    let inView = false;
    let timer = null;
    let index = 0;
    let activePaper = null;
    let disposed = false;

    function clearPeek() {
      activePaper?.classList.remove('is-touch-peeking');
      activePaper = null;
    }
    function pause() {
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      clearPeek();
    }
    function allowed() {
      return !disposed && stack.isConnected && inView && touchReports.matches && !motion.matches && !document.hidden && !document.querySelector('dialog.vc-product-report-dialog[open]');
    }
    function peek() {
      timer = null;
      if (!allowed()) { pause(); return; }
      activePaper = papers[index % papers.length];
      index += 1;
      activePaper.classList.add('is-touch-peeking');
      timer = window.setTimeout(() => {
        timer = null;
        clearPeek();
        if (allowed()) timer = window.setTimeout(peek, 2900);
        else pause();
      }, 1100);
    }
    function update() {
      if (!allowed()) { pause(); return; }
      if (timer === null) timer = window.setTimeout(peek, 1400);
    }
    const observer = new IntersectionObserver(changes => {
      const change = changes.find(entry => entry.target === stack);
      if (change) inView = change.isIntersecting && change.intersectionRatio >= 0.35;
      update();
    }, { threshold: [0, 0.35, 1] });
    function destroy() {
      disposed = true;
      pause();
      observer.disconnect();
      document.removeEventListener('visibilitychange', update);
      motion.removeEventListener?.('change', update);
      touchReports.removeEventListener?.('change', update);
      reportAnimations.delete(stack);
    }
    reportAnimations.set(stack, { update, destroy });
    document.addEventListener('visibilitychange', update);
    motion.addEventListener?.('change', update);
    touchReports.addEventListener?.('change', update);
    observer.observe(stack);
  }

  function updateReportMotion() {
    reportAnimations.forEach(animation => animation.update());
  }

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

  // Report links are owned by the shared validated PDF/history bridge.
  // Keep homepage paper motion synchronized without a second iframe reader.
  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-vc-demo-report]')) window.setTimeout(updateReportMotion, 0);
  });
  document.addEventListener('close', event => {
    if (event.target.matches?.('dialog.vc-product-report-dialog')) updateReportMotion();
  }, true);

  function boot(root = document) {
    if (root.matches?.('[data-vc-demo-counters]')) initCounters(root);
    root.querySelectorAll?.('[data-vc-demo-counters]').forEach(initCounters);
    if (root.matches?.('.vc-demo-report-stack')) initReportMotion(root);
    root.querySelectorAll?.('.vc-demo-report-stack').forEach(initReportMotion);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => boot(), { once: true });
  else boot();
  // The builder can refresh only the edited section. Initialize only new subtrees.
  new MutationObserver(records => {
    // Editor previews replace whole sections; release timers and listeners for the old ones.
    if (records.some(record => record.removedNodes.length)) {
      reportAnimations.forEach((animation, stack) => { if (!stack.isConnected) animation.destroy(); });
    }
    records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) boot(node); }));
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
