(() => {
  'use strict';
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const header = document.querySelector('.site-header');
  let frame = 0;
  const updateHeader = () => {
    frame = 0;
    header?.classList.toggle('has-depth', window.scrollY > 24);
  };
  const onScroll = () => { if (!frame) frame = requestAnimationFrame(updateHeader); };
  window.addEventListener('scroll', onScroll, { passive: true });
  updateHeader();

  // Observe just the three story photographs and the documentation sheet.
  // Nothing is hidden before JavaScript, or while waiting for the observer.
  const animations = new Map();
  let observer;
  const reveal = element => {
    if (reduced.matches || !element.animate) return;
    const photograph = element.matches('.network-image');
    const target = photograph ? element.querySelector('img') : element;
    if (!target) return;
    const frames = photograph
      ? [{ clipPath: 'inset(0 0 18% 0)', transform: 'scale(1.045)' }, { clipPath: 'inset(0)', transform: 'scale(1)' }]
      : [{ opacity: .65, transform: 'translateY(20px)' }, { opacity: 1, transform: 'translateY(0)' }];
    const animation = target.animate(frames, { duration: photograph ? 950 : 760, easing: 'cubic-bezier(.22,1,.36,1)' });
    animations.set(target, animation);
    animation.onfinish = () => animations.delete(target);
    animation.oncancel = () => animations.delete(target);
  };
  if (document.body.classList.contains('home') && 'IntersectionObserver' in window) {
    observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        reveal(entry.target);
      });
    }, { threshold: .18 });
    document.querySelectorAll('.network-image, .report-preview').forEach(element => observer.observe(element));
  }
  const settle = () => { for (const animation of animations.values()) animation.cancel(); animations.clear(); };
  reduced.addEventListener('change', () => { if (reduced.matches) settle(); });
  document.addEventListener('focusin', event => {
    for (const [element, animation] of animations) if (element.contains(event.target)) animation.cancel();
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) settle(); });
  window.addEventListener('pagehide', () => {
    cancelAnimationFrame(frame); frame = 0; settle();
  });
  window.addEventListener('pageshow', updateHeader);
})();
