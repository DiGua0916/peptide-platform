(() => {
  'use strict';
  const init = () => {
    const groups = [...document.querySelectorAll('.vc-counter-cards .vc-counter')];
    if (!groups.length || !('IntersectionObserver' in window)) return;
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const active = new Map();
    const started = new WeakSet();
    const finishAll = () => active.forEach(finish => finish());
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        const root = entry.target;
        if (!entry.isIntersecting || entry.intersectionRatio < 0.35 || started.has(root) || !root.getClientRects().length || getComputedStyle(root).visibility === 'hidden') return;
        started.add(root);
        observer.unobserve(root);
        if (reduced.matches || document.hidden) return;
        const numbers = [...root.querySelectorAll('[data-vc-count]')].map(node => {
          const target = Number(node.dataset.vcCount);
          const precision = Math.max(0, Math.min(6, Number(node.dataset.countPrecision) || 0));
          return { node, target, finalText: node.textContent, scale: 10 ** precision,
            format: new Intl.NumberFormat(undefined, { minimumFractionDigits: precision, maximumFractionDigits: precision }) };
        }).filter(number => Number.isFinite(number.target) && number.target !== 0);
        if (!numbers.length) return;
        let frame = 0;
        let start = null;
        const finish = () => {
          cancelAnimationFrame(frame);
          numbers.forEach(number => { number.node.textContent = number.finalText; });
          active.delete(root);
        };
        active.set(root, finish);
        const step = time => {
          if (reduced.matches || document.hidden || !root.isConnected) { finish(); return; }
          if (start === null) start = time;
          const progress = Math.min(1, (time - start) / 2400);
          if (progress === 1) { finish(); return; }
          const eased = 1 - (1 - progress) ** 4;
          numbers.forEach(number => {
            // Hold the last increment for the end; preserve the server's exact final text.
            const value = Math.trunc(number.target * eased * number.scale) / number.scale;
            number.node.textContent = number.format.format(value);
          });
          frame = requestAnimationFrame(step);
        };
        frame = requestAnimationFrame(step);
      });
    }, { threshold: 0.35, rootMargin: '0px 0px -5% 0px' });
    groups.forEach(group => observer.observe(group));
    reduced.addEventListener('change', () => { if (reduced.matches) finishAll(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) finishAll(); });
    window.addEventListener('pagehide', finishAll);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
