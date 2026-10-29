(() => {
  'use strict';
  const control = document.querySelector('.vial-interaction');
  if (!control) return;

  const art = control.closest('.hero-art');
  const hint = art.querySelector('#vial-motion-hint');
  const finePointer = matchMedia('(hover: hover) and (pointer: fine)');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  let ready = false;
  let visible = false;
  let autoPlayed = false;
  let closeTimer;
  let startTimer;

  const clearTimers = () => {
    clearTimeout(closeTimer);
    clearTimeout(startTimer);
  };
  const setOpen = open => {
    control.classList.toggle('is-open', open);
    control.dataset.vialState = open ? 'open' : 'closed';
  };
  const reset = () => { clearTimers(); setOpen(false); };
  const play = () => {
    if (!ready || document.hidden) return;
    clearTimers();
    // An explicit tap is still usable with reduced motion, without movement.
    if (reducedMotion.matches) { setOpen(!control.classList.contains('is-open')); return; }
    setOpen(true);
    closeTimer = setTimeout(() => setOpen(false), 1900);
  };
  const maybeAutoplay = () => {
    if (!ready || !visible || autoPlayed || finePointer.matches || reducedMotion.matches || document.hidden) return;
    clearTimeout(startTimer);
    startTimer = setTimeout(() => {
      if (!visible || document.hidden || finePointer.matches || reducedMotion.matches) return;
      autoPlayed = true;
      play();
    }, 450);
  };
  const updateHint = () => {
    hint.textContent = reducedMotion.matches ? 'Tap to open / close' : finePointer.matches ? 'Hover to open · Move away to close' : 'Tap to replay';
  };

  control.addEventListener('pointerenter', event => {
    if (ready && finePointer.matches && event.pointerType !== 'touch' && !reducedMotion.matches) {
      clearTimers(); setOpen(true);
    }
  });
  control.addEventListener('pointerleave', event => {
    if (finePointer.matches && event.pointerType !== 'touch') reset();
  });
  control.addEventListener('click', play);
  control.addEventListener('blur', reset);
  control.addEventListener('keydown', event => {
    if (event.key === 'Escape') reset();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) reset(); else maybeAutoplay();
  });
  window.addEventListener('pagehide', reset);
  for (const query of [finePointer, reducedMotion]) {
    query.addEventListener('change', () => { reset(); updateHint(); maybeAutoplay(); });
  }

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      visible = entries[0].isIntersecting && entries[0].intersectionRatio >= 0.45;
      if (visible) maybeAutoplay(); else reset();
    }, { threshold: [0, 0.45] });
    observer.observe(control);
  } else {
    // Retain manual interaction in older browsers without autoplay guessing.
    visible = false;
  }

  const images = [...control.querySelectorAll('img')];
  const decode = img => typeof img.decode === 'function' ? img.decode() : new Promise((resolve, reject) => {
    if (img.complete) { img.naturalWidth ? resolve() : reject(new Error('Image unavailable')); return; }
    img.addEventListener('load', resolve, { once: true });
    img.addEventListener('error', reject, { once: true });
  });
  Promise.all(images.map(decode)).then(() => {
    ready = true;
    control.disabled = false;
    control.classList.add('is-ready');
    art.classList.add('has-vial-motion');
    updateHint();
    maybeAutoplay();
  }).catch(() => {
    // The original closed-vial illustration remains intact if a layer fails.
    reset();
  });
})();
