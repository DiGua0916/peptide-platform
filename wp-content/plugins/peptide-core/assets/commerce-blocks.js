(() => {
  'use strict';
  const init = (root = document) => {
    root.querySelectorAll('[data-pc-cb-tabs]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const tabs = [...group.querySelectorAll(':scope > [role=tablist] > [role=tab]')];
      const select = tab => {
        tabs.forEach(item => {
          const active = item === tab;
          item.setAttribute('aria-selected', String(active));
          item.tabIndex = active ? 0 : -1;
          const panel = document.getElementById(item.getAttribute('aria-controls'));
          if (panel) panel.hidden = !active;
        });
      };
      tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('pointerenter', event => { if (group.dataset.switchMode === 'hover' && event.pointerType !== 'touch') select(tab); });
        tab.addEventListener('keydown', event => {
          let next = i;
          if (event.key === 'ArrowRight') next = (i + 1) % tabs.length;
          else if (event.key === 'ArrowLeft') next = (i + tabs.length - 1) % tabs.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = tabs.length - 1;
          else return;
          event.preventDefault(); select(tabs[next]); tabs[next].focus();
        });
      });
      group.addEventListener('vc:select-item', event => {
        const panel = event.target.closest('[role=tabpanel]');
        const tab = panel && tabs.find(item => item.getAttribute('aria-controls') === panel.id);
        if (tab) { select(tab); event.preventDefault(); }
      });
    });
    root.querySelectorAll('[data-pc-cb-rail]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const rail = group.querySelector('.pc-cb-rail');
      if (!rail) return;
      const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
      const move = direction => {
        const max = rail.scrollWidth - rail.clientWidth;
        const behavior = reducedMotion() ? 'auto' : 'smooth';
        if (direction > 0 && rail.scrollLeft >= max - 2) rail.scrollTo({ left: 0, behavior });
        else rail.scrollBy({ left: direction * rail.clientWidth, behavior });
      };
      group.querySelector('[data-pc-cb-prev]')?.addEventListener('click', () => move(-1));
      group.querySelector('[data-pc-cb-next]')?.addEventListener('click', () => move(1));
      let userPaused = false;
      group.addEventListener('vc:select-item', event => {
        const item = event.target.closest('[data-module-item]');
        if (!item || !rail.contains(item)) return;
        userPaused = true;
        rail.scrollTo({ left: item.offsetLeft - rail.offsetLeft, behavior: 'instant' });
        event.preventDefault();
      });
      const toggle = group.querySelector('[data-pc-cb-toggle]');
      toggle?.addEventListener('click', () => {
        userPaused = !userPaused; toggle.setAttribute('aria-pressed', String(userPaused));
        toggle.textContent = userPaused ? toggle.dataset.play : toggle.dataset.pause;
      });
      if (group.dataset.autoplay === '1' && !reducedMotion()) {
        let hoverPaused = false;
        let focusPaused = false;
        group.addEventListener('mouseenter', () => { hoverPaused = true; });
        group.addEventListener('mouseleave', () => { hoverPaused = false; });
        group.addEventListener('focusin', () => { focusPaused = true; });
        group.addEventListener('focusout', event => { focusPaused = group.contains(event.relatedTarget); });
        const timer = setInterval(() => {
          if (!group.isConnected) { clearInterval(timer); return; }
          if (!hoverPaused && !focusPaused && !userPaused && !document.hidden && !reducedMotion()) move(1);
        }, Math.max(2000, Math.min(20000, Number(group.dataset.interval) || 5000)));
      }
    });
    root.querySelectorAll('[data-pc-cb-countdown]:not([data-pc-ready])').forEach(output => {
      output.dataset.pcReady = '1';
      const update = () => {
        const seconds = Math.max(0, Math.floor(Number(output.dataset.pcCbCountdown) - Date.now() / 1000));
        if (!seconds) { output.textContent = output.dataset.ended; return false; }
        const days = Math.floor(seconds / 86400);
        const clock = [Math.floor(seconds / 3600) % 24, Math.floor(seconds / 60) % 60, seconds % 60].map(n => String(n).padStart(2, '0')).join(':');
        output.textContent = (days ? days + 'd ' : '') + clock; return true;
      };
      if (update()) { const timer = setInterval(() => { if (!output.isConnected || !update()) clearInterval(timer); }, 1000); }
    });
    root.querySelectorAll('[data-pc-campaign-countdown]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const label = group.querySelector('.pc-campaign__countdown-label');
      const output = group.querySelector('.pc-campaign__clock');
      const campaign = group.closest('[data-pc-campaign]');
      if (!label || !output) return;
      const update = () => {
        const now = Math.floor(Date.now() / 1000), start = Number(group.dataset.start) || 0, end = Number(group.dataset.end) || 0;
        const upcoming = start > now, deadline = upcoming ? start : end;
        if (campaign) campaign.dataset.state = upcoming ? 'upcoming' : (end && end <= now ? 'ended' : 'active');
        if (end && !upcoming && end <= now) {
          label.textContent = group.dataset.endedLabel || 'Offer ended'; output.hidden = true; return false;
        }
        if (!deadline) { group.hidden = true; return false; }
        group.hidden = false;
        const deadlineLabel = new Date(deadline * 1000).toLocaleString();
        if (output.getAttribute('aria-label') !== deadlineLabel) output.setAttribute('aria-label', deadlineLabel);
        const text = upcoming ? group.dataset.upcomingLabel : group.dataset.activeLabel;
        if (label.textContent !== text) label.textContent = text || '';
        const seconds = Math.max(0, deadline - now);
        const parts = group.dataset.format === 'hms'
          ? [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
          : [Math.floor(seconds / 86400), Math.floor(seconds / 3600) % 24, Math.floor(seconds / 60) % 60, seconds % 60];
        let digits = [...output.querySelectorAll('.pc-campaign__digit')];
        if (digits.length !== parts.length) {
          output.replaceChildren();
          parts.forEach((part, index) => {
            if (index) { const separator = document.createElement('span'); separator.className = 'pc-campaign__separator'; separator.setAttribute('aria-hidden', 'true'); separator.textContent = ':'; output.append(separator); }
            const digit = document.createElement('span'); digit.className = 'pc-campaign__digit'; output.append(digit);
          });
          digits = [...output.querySelectorAll('.pc-campaign__digit')];
        }
        parts.forEach((part, index) => { const value = String(part).padStart(2, '0'); if (digits[index].textContent !== value) digits[index].textContent = value; });
        return true;
      };
      if (update()) { const timer = setInterval(() => { if (!group.isConnected || !update()) clearInterval(timer); }, 1000); }
    });
    root.querySelectorAll('[data-pc-campaign-rail]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const rail = group.querySelector('.pc-campaign__tiers');
      const controls = group.querySelector('.pc-campaign__rail-controls');
      if (!rail || !controls || !rail.children.length) return;
      const cards = [...rail.children];
      const dots = [...group.querySelectorAll('[data-pc-campaign-slide]')];
      const previous = group.querySelector('[data-pc-campaign-prev]'), next = group.querySelector('[data-pc-campaign-next]');
      if (!previous || !next) return;
      const mobile = matchMedia('(max-width:767px)'), reduced = matchMedia('(prefers-reduced-motion:reduce)');
      let selected = 0;
      const carousel = () => (mobile.matches ? group.dataset.mobileLayout : group.dataset.desktopLayout) === 'carousel';
      const indexAt = () => cards.reduce((best, card, index) => Math.abs(card.offsetLeft - cards[0].offsetLeft - rail.scrollLeft) < Math.abs(cards[best].offsetLeft - cards[0].offsetLeft - rail.scrollLeft) ? index : best, 0);
      const sync = () => {
        const overflow = rail.scrollWidth > rail.clientWidth + 2;
        controls.hidden = !carousel() || !overflow;
        selected = indexAt();
        dots.forEach((dot, index) => dot.setAttribute('aria-current', String(index === selected)));
        previous.disabled = rail.scrollLeft <= 2;
        next.disabled = rail.scrollLeft >= rail.scrollWidth - rail.clientWidth - 2;
      };
      const moveTo = index => {
        if (!carousel() || !cards.length) return;
        const target = Math.max(0, Math.min(cards.length - 1, index));
        rail.scrollTo({left:cards[target].offsetLeft - cards[0].offsetLeft,behavior:reduced.matches ? 'auto' : 'smooth'});
      };
      previous.addEventListener('click', () => moveTo(selected - 1));
      next.addEventListener('click', () => moveTo(selected + 1));
      dots.forEach((dot, index) => dot.addEventListener('click', () => moveTo(index)));
      rail.addEventListener('scroll', sync, {passive:true});
      rail.addEventListener('keydown', event => {
        if (!carousel() || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault(); moveTo(event.key === 'Home' ? 0 : event.key === 'End' ? cards.length - 1 : selected + (event.key === 'ArrowRight' ? 1 : -1));
      });
      rail.tabIndex = 0;
      rail.setAttribute('aria-label', 'Promotion offers');
      if (typeof ResizeObserver === 'function') { const observer = new ResizeObserver(() => { if (!group.isConnected) observer.disconnect(); else sync(); }); observer.observe(rail); }
      mobile.addEventListener('change', () => { rail.scrollLeft = 0; sync(); });
      sync();
    });
    root.querySelectorAll('[data-pc-campaign-effect]:not([data-pc-ready])').forEach(button => {
      button.dataset.pcReady = '1';
      const effect = button.dataset.pcCampaignEffect;
      if (!['bounce','rubberband','shake','swing','wobble','pulse','shine'].includes(effect)) return;
      const duration = Math.max(1000, Math.min(5000, Number(button.dataset.effectDuration) || 1000));
      const interval = Math.max(500, Math.min(5000, Number(button.dataset.effectInterval) || 1500));
      const reduced = matchMedia('(prefers-reduced-motion:reduce)');
      let timer, visible = true;
      const stopInvisible = () => { if(document.hidden || reduced.matches) button.classList.remove('is-animating'); };
      document.addEventListener('visibilitychange',stopInvisible);reduced.addEventListener('change',stopInvisible);
      const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => { visible = entries[0].isIntersecting; }) : null;
      if (observer) observer.observe(button);
      const run = () => {
        if (!button.isConnected) { clearTimeout(timer); observer?.disconnect();document.removeEventListener('visibilitychange',stopInvisible);reduced.removeEventListener('change',stopInvisible);return; }
        if (reduced.matches || document.hidden || !visible) { button.classList.remove('is-animating'); timer = setTimeout(run, interval); return; }
        button.classList.add('is-animating');
        timer = setTimeout(() => { button.classList.remove('is-animating'); timer = setTimeout(run, interval); }, duration);
      };
      timer = setTimeout(run, interval);
    });
    root.querySelectorAll('form.pc-cb-search:not([data-pc-ready])').forEach(form => {
      form.dataset.pcReady = '1';
      form.addEventListener('formdata', event => {
        form.querySelectorAll('[name=min_price],[name=max_price]').forEach(input => { if (input.value.trim() === '') event.formData.delete(input.name); });
      });
    });
    root.querySelectorAll('[data-pc-cb-copy]:not([data-pc-ready])').forEach(button => {
      button.dataset.pcReady = '1';
      button.addEventListener('click', async () => {
        if (button.disabled) return;
        const feedback = button.closest('[data-pc-coupon]')?.querySelector('[data-pc-copy-feedback]');
        let copied = false;
        try { if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(button.dataset.pcCbCopy); copied = true; } } catch (_) {}
        if (!copied) {
          const active = document.activeElement, input = document.createElement('textarea');
          input.value = button.dataset.pcCbCopy; input.readOnly = true; input.setAttribute('aria-hidden', 'true');
          Object.assign(input.style, { position: 'fixed', left: '-9999px', top: '0' });
          document.body.appendChild(input);
          try { input.select(); copied = document.execCommand('copy'); } catch (_) {} finally { input.remove(); active?.focus({ preventScroll: true }); }
        }
        if (copied) {
          if (feedback) { feedback.textContent = button.dataset.copied || 'Code copied'; button.dataset.copyState = 'copied'; }
          else button.textContent = button.dataset.copied;
        } else {
          const code = button.closest('article')?.querySelector('code');
          if (code) { const range = document.createRange(); range.selectNodeContents(code); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); }
          const message = button.dataset.copyFallback || 'Select and copy the code';
          if (feedback) { feedback.textContent = message; button.dataset.copyState = 'failed'; }
          else button.textContent = message;
        }
      });
    });
    root.querySelectorAll('[data-pc-coupon-expiry]:not([data-pc-expiry-ready])').forEach(coupon => {
      coupon.dataset.pcExpiryReady = '1';
      const expiry = Number(coupon.dataset.pcCouponExpiry);
      if (!expiry) return;
      const update = () => {
        if (!coupon.isConnected) return false;
        if (Date.now() < expiry * 1000) return true;
        coupon.classList.add('is-expired');
        const copy = coupon.querySelector('[data-pc-cb-copy]'), feedback = coupon.querySelector('[data-pc-copy-feedback]');
        if (copy) copy.disabled = true;
        if (feedback) feedback.textContent = 'This coupon has expired.';
        return false;
      };
      if (update()) { const timer = setInterval(() => { if (!update()) clearInterval(timer); }, 1000); }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init()); else init();
  new MutationObserver(records => { if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1))) init(); }).observe(document.documentElement, { childList: true, subtree: true });
})();
