/* Animate only a newly loaded, authoritative product image. No data or navigation hooks. */
(() => {
  'use strict';
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const painted = new WeakMap(), running = new Map();
  function cancel(image) {
    const animation = running.get(image);
    running.delete(image);
    if (animation) { animation.onfinish = animation.oncancel = null; animation.cancel(); }
  }
  function settle() { for (const image of [...running.keys()]) cancel(image); }
  document.addEventListener('load', event => {
    const image = event.target;
    if (!image?.matches?.('img.vc-dialog-product-image') || !image.closest('dialog[open]') || image.hidden) return;
    const source = image.currentSrc || image.getAttribute('src');
    if (!source || painted.get(image) === source) return;
    painted.set(image, source); cancel(image);
    if (document.hidden || reduced.matches || typeof image.animate !== 'function') return;
    try {
      const animation = image.animate([{opacity: .35}, {opacity: 1}], {duration: 240, easing: 'cubic-bezier(.22,1,.36,1)'});
      running.set(image, animation);
      animation.onfinish = animation.oncancel = () => { if (running.get(image) === animation) running.delete(image); };
    } catch (_) { /* Loaded content is visible even when animation is unsupported. */ }
  }, true);
  document.addEventListener('close', event => {
    for (const image of [...running.keys()]) if (event.target.contains?.(image) || !image.isConnected) cancel(image);
  }, true);
  document.addEventListener('visibilitychange', () => { if (document.hidden) settle(); });
  reduced.addEventListener('change', () => { if (reduced.matches) settle(); });
  window.addEventListener('pagehide', settle);
})();
