(() => {
  'use strict';
  const header = document.querySelector('.site-header');
  const nav = document.querySelector('#primary-nav');
  const menu = document.querySelector('.menu-toggle');
  const groups = [...document.querySelectorAll('.nav-group')];
  const desktop = matchMedia('(min-width: 951px)');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const ease = 'cubic-bezier(.22, 1, .36, 1)';
  const disclosures = new Map();
  let menuAnimation;
  let menuOpen = false;

  // Keep <details> open during the exit animation. On reversal, start at the
  // currently painted height/opacity, rather than restarting from an endpoint.
  const setDisclosure = (details, open, instant = false) => {
    const state = disclosures.get(details);
    if (!state || (state.open === open && !instant)) return;
    const {body, summary} = state;
    const startHeight = details.getBoundingClientRect().height;
    const previousOpen = details.open;
    const painted = getComputedStyle(body);
    const fromOpacity = previousOpen ? painted.opacity : '0';
    const fromTransform = previousOpen ? painted.transform : 'translateY(-8px)';
    state.animations.forEach(animation => { animation.onfinish = null; animation.cancel(); });
    state.animations = [];
    state.open = open;
    summary.setAttribute('aria-expanded', String(open));
    body.inert = !open;
    body.setAttribute('aria-hidden', String(!open));
    details.dataset.expanded = String(open);
    const settle = () => {
      details.open = state.open;
      details.style.overflow = '';
      delete details.dataset.animating;
      state.animations.forEach(animation => { animation.onfinish = null; animation.cancel(); });
      state.animations = [];
    };
    state.settle = settle;
    if (instant || reduced.matches || !body.animate) { settle(); return; }
    const duration = open ? 320 : 220;
    const animateHeight = !details.classList.contains('nav-group') || !desktop.matches;
    let endHeight;
    if (animateHeight) {
      details.open = open;
      endHeight = details.getBoundingClientRect().height;
    }
    details.open = true;
    details.dataset.animating = open ? 'opening' : 'closing';
    if (details.classList.contains('nav-group')) body.style.zIndex = open ? '62' : '61';
    const fade = body.animate([
      {opacity: fromOpacity, transform: fromTransform},
      {opacity: open ? 1 : 0, transform: open ? 'translateY(0)' : 'translateY(-8px)'}
    ], {duration, easing: ease, fill: 'both'});
    state.animations.push(fade);
    if (animateHeight) {
      details.style.overflow = 'clip';
      const expansion = details.animate([{height: `${startHeight}px`}, {height: `${endHeight}px`}], {duration, easing: ease, fill: 'both'});
      state.animations.push(expansion);
      expansion.onfinish = settle;
    } else { fade.onfinish = settle; }
  };

  const closeGroups = (except, instant = false) => groups.forEach(group => {
    if (group !== except) setDisclosure(group, false, instant);
  });

  [...groups, ...document.querySelectorAll('.faq-list details')].forEach((details, index) => {
    const summary = details.querySelector('summary');
    let body = details.querySelector('.nav-panel');
    if (!body) {
      body = document.createElement('div');
      body.className = 'vc-disclosure-body';
      [...details.childNodes].filter(child => child !== summary).forEach(child => body.append(child));
      details.append(body);
    }
    body.id ||= `vc-disclosure-${index}`;
    summary.setAttribute('aria-controls', body.id);
    disclosures.set(details, {summary, body, open: details.open, animations: []});
    setDisclosure(details, details.open, true);
    summary.addEventListener('click', event => {
      event.preventDefault();
      const open = !disclosures.get(details).open;
      if (open && groups.includes(details)) closeGroups(details);
      setDisclosure(details, open);
    });
    if (details.matches('.vc-m--faq details[data-module-item]')) {
      details.addEventListener('vc:select-item', event => {
        if (event.detail?.item !== details.dataset.moduleItem) return;
        setDisclosure(details, true, true);
        event.preventDefault();
      });
    }
  });

  const setMenu = (open, instant = false) => {
    if (!nav || !menu) return;
    if (!instant && menuOpen === open) return;
    const painted = getComputedStyle(nav);
    const wasVisible = nav.classList.contains('is-open');
    const from = {opacity: wasVisible ? painted.opacity : '0', transform: wasVisible ? painted.transform : 'translateY(-8px)', clipPath: wasVisible ? painted.clipPath : 'inset(0 0 14% 0)'};
    if (menuAnimation) { menuAnimation.onfinish = null; menuAnimation.cancel(); }
    menuAnimation = null;
    menuOpen = open;
    menu.setAttribute('aria-expanded', String(open));
    menu.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    nav.inert = !desktop.matches && !open;
    const settle = () => {
      nav.classList.toggle('is-open', menuOpen);
      delete nav.dataset.animating;
      if (menuAnimation) { menuAnimation.onfinish = null; menuAnimation.cancel(); }
      menuAnimation = null;
      if (!menuOpen) closeGroups(null, true);
    };
    if (instant || desktop.matches || reduced.matches || !nav.animate) { settle(); return; }
    nav.classList.add('is-open');
    nav.dataset.animating = open ? 'opening' : 'closing';
    menuAnimation = nav.animate([from, {opacity: open ? 1 : 0, transform: open ? 'translateY(0)' : 'translateY(-8px)', clipPath: open ? 'inset(0 0 0% 0)' : 'inset(0 0 14% 0)'}], {duration: open ? 320 : 220, easing: ease, fill: 'both'});
    menuAnimation.onfinish = settle;
  };
  const closeMenu = () => { if (menuOpen) setMenu(false); else closeGroups(); };
  menu?.addEventListener('click', () => setMenu(!menuOpen));
  nav?.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
  document.addEventListener('click', event => { if (!header?.contains(event.target)) closeMenu(); });
  document.addEventListener('focusin', event => { if (!header?.contains(event.target)) closeMenu(); });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const openGroup = groups.find(group => disclosures.get(group).open);
    if (openGroup) { setDisclosure(openGroup, false); openGroup.querySelector('summary').focus(); }
    else if (menuOpen) { setMenu(false); menu.focus(); }
  });
  desktop.addEventListener('change', () => { closeGroups(null, true); setMenu(false, true); });
  reduced.addEventListener('change', () => {
    if (reduced.matches) {
      disclosures.forEach((state, details) => setDisclosure(details, state.open, true));
      setMenu(menuOpen, true);
    }
  });
  window.addEventListener('resize', () => disclosures.forEach(state => state.settle?.()));
  document.documentElement.classList.add('vc-nav-ready');
  setMenu(false, true);

  document.querySelectorAll('.nav-panel--products').forEach(panel => {
    const search = panel.querySelector('[data-nav-query]');
    const products = [...panel.querySelectorAll('[data-product-search]')];
    panel.querySelector('[data-nav-search]')?.removeAttribute('hidden');
    search?.addEventListener('input', () => {
      const query = search.value.trim().toLocaleLowerCase();
      let count = 0;
      products.forEach(item => { item.hidden = !item.dataset.productSearch.toLocaleLowerCase().includes(query); if (!item.hidden) count++; });
      panel.querySelector('[data-nav-count]').textContent = `${count} ${count === 1 ? 'product' : 'products'}`;
      panel.querySelector('[data-nav-empty]').hidden = count > 0;
    });
  });

  const faqSearch = document.querySelector('#faq-search');
  if (faqSearch) {
    document.querySelector('[data-faq-search]').hidden = false;
    const answers = [...document.querySelectorAll('[data-faq-answer]')];
    faqSearch.addEventListener('input', () => {
      const query = faqSearch.value.trim().toLocaleLowerCase();
      let count = 0;
      answers.forEach(item => {
        item.hidden = !item.textContent.toLocaleLowerCase().includes(query);
        setDisclosure(item, !!query && !item.hidden, true);
        if (!item.hidden) count++;
      });
      document.querySelector('[data-faq-status]').textContent = count ? `${count} answers` : 'No matching answer. Try “report”, “shipping”, “Pack” or “sign-in”.';
    });
  }
})();
