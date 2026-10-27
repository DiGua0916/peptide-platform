(() => {
  'use strict';
  const root = document.querySelector('[data-carousel]');
  if (!root) return;
  const slides = [...root.querySelectorAll('.hero-slide')];
  const status = root.querySelector('.hero-carousel-status');
  const track = root.querySelector('.hero-slides');
  if (!slides.length || !track) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const duration = 6500;
  const listeners = new AbortController();
  const listen = (target, event, handler, options = {}) => target?.addEventListener(event, handler, { ...options, signal: listeners.signal });
  let index = Math.max(0, slides.findIndex(slide => slide.classList.contains('is-active')));
  let automatic = !reduced.matches;
  let focused = false;
  let visible = false;
  let suspended = false;
  let generation = 0;
  let fallbackTimer;
  let remaining = duration;
  let startedAt = null;
  let focusTimer;
  let visibilityFrame;
  let observer;
  let gesture;
  const hasBlockingFocus = () => document.activeElement === root
    ? root.matches(':focus-visible')
    : root.contains(document.activeElement);

  // No visible controls are required. Keyboard focus pauses the slideshow.
  const canPlay = () => automatic && !reduced.matches && visible && !focused &&
    !document.hidden && !suspended;
  const updatePlayback = () => {
    root.dataset.autoplay = automatic ? 'enabled' : 'paused';
    root.dataset.playback = canPlay() ? 'playing' : 'paused';
  };

  // One timer, independent of presentation elements; pauses keep remaining time.
  const stopClock = () => {
    if (startedAt !== null) {
      remaining = Math.max(0, remaining - (performance.now() - startedAt));
      startedAt = null;
    }
    clearTimeout(fallbackTimer);
    fallbackTimer = undefined;
  };
  const resetClock = () => {
    stopClock();
    ++generation;
    remaining = duration;
  };
  const schedule = () => {
    updatePlayback();
    if (!canPlay()) { stopClock(); return; }
    if (fallbackTimer !== undefined) return;
    const token = generation;
    startedAt = performance.now();
    fallbackTimer = setTimeout(() => {
      fallbackTimer = undefined;
      remaining = 0;
      startedAt = null;
      if (token === generation && canPlay()) show(index + 1, false);
    }, remaining);
  };
  const show = (next, manual = true) => {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      const active = i === index;
      slide.classList.toggle('is-active', active);
      slide.inert = !active;
      if (active) slide.removeAttribute('aria-hidden'); else slide.setAttribute('aria-hidden', 'true');
      if (active) slide.querySelectorAll('img').forEach(img => { img.loading = 'eager'; });
    });
    root.dataset.slideIndex = String(index);
    if (manual && status) status.textContent = `${index + 1} of ${slides.length}: ${slides[index].dataset.slideName}`;
    resetClock();
    schedule();
  };

  listen(root, 'focusin', () => {
    focused = hasBlockingFocus();
    schedule();
  });
  listen(root, 'focusout', () => {
    clearTimeout(focusTimer);
    focusTimer = setTimeout(() => { focused = hasBlockingFocus(); schedule(); }, 0);
  });
  listen(root, 'pointerup', () => { focused = hasBlockingFocus(); schedule(); });
  listen(root, 'keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault(); show(index + (event.key === 'ArrowLeft' ? -1 : 1));
    }
  });

  listen(track, 'pointerdown', event => {
    if (event.pointerType !== 'touch' || !event.isPrimary || event.target.closest('a,button')) return;
    gesture = { id: event.pointerId, x: event.clientX, y: event.clientY };
    track.setPointerCapture(event.pointerId);
  });
  listen(track, 'pointerup', event => {
    if (!gesture || event.pointerId !== gesture.id) return;
    const dx = event.clientX - gesture.x;
    const dy = event.clientY - gesture.y;
    gesture = null;
    if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.3) show(index + (dx < 0 ? 1 : -1));
  });
  listen(track, 'pointercancel', () => { gesture = null; });
  listen(track, 'lostpointercapture', () => { gesture = null; });

  const measureVisibility = () => {
    const rect = root.getBoundingClientRect();
    const height = Math.max(0, Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0));
    const width = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(rect.left, 0));
    visible = rect.height > 0 && rect.width > 0 && (height * width) / (rect.height * rect.width) >= 0.25;
    schedule();
  };
  if ('IntersectionObserver' in window) {
    observer = new IntersectionObserver(entries => {
      visible = entries[0].isIntersecting && entries[0].intersectionRatio >= 0.25;
      schedule();
    }, { threshold: [0, 0.25] });
    observer.observe(root);
  } else {
    const queueVisibility = () => {
      if (visibilityFrame) return;
      visibilityFrame = requestAnimationFrame(() => { visibilityFrame = undefined; measureVisibility(); });
    };
    listen(window, 'scroll', queueVisibility, { passive: true });
    listen(window, 'resize', queueVisibility, { passive: true });
  }
  listen(document, 'visibilitychange', schedule);
  listen(window, 'pagehide', event => {
    suspended = true;
    schedule();
    observer?.disconnect();
    clearTimeout(focusTimer);
    cancelAnimationFrame(visibilityFrame);
    visibilityFrame = undefined;
    gesture = null;
    if (!event.persisted) {
      generation++;
      listeners.abort();
    }
  });
  listen(window, 'pageshow', () => {
    suspended = false;
    focused = hasBlockingFocus();
    observer?.observe(root);
    measureVisibility();
  });
  listen(reduced, 'change', () => {
    automatic = !reduced.matches;
    schedule();
  });
  show(index, false);
  measureVisibility();
})();
