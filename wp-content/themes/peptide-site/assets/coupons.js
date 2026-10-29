(() => {
  'use strict';
  const timers = new WeakMap();
  const labels = new WeakMap();
  const copyText = async text => {
    if (navigator.clipboard?.writeText) {
      try { await navigator.clipboard.writeText(text); return true; } catch (_) { /* HTTP or denied permission: try local selection. */ }
    }
    const field = document.createElement('textarea');
    field.value = text;
    field.readOnly = true;
    field.setAttribute('aria-label', 'Coupon code');
    Object.assign(field.style, {position: 'fixed', left: '-10000px', top: '0', opacity: '0'});
    document.body.append(field);
    let copied = false;
    try {
      field.focus({preventScroll: true});
      field.select();
      copied = document.execCommand('copy');
    } catch (_) { copied = false; }
    finally { field.remove(); }
    return copied;
  };
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-vc-coupons] [data-pc-copy]');
    if (!button || button.disabled) return;
    const code = button.dataset.pcCopy;
    const status = button.closest('[data-vc-coupon]')?.querySelector('[data-vc-copy-status]');
    if (!code || !status) return;
    const hadFocus = document.activeElement === button;
    if (!labels.has(button)) labels.set(button, button.textContent);
    clearTimeout(timers.get(button));
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    status.textContent = '';
    const copied = await copyText(code);
    button.disabled = false;
    button.removeAttribute('aria-busy');
    if (hadFocus && document.activeElement === document.body) button.focus({preventScroll: true});
    button.textContent = copied ? (button.dataset.copied || 'Copied') : labels.get(button);
    status.textContent = copied ? 'Code copied. Apply it in your cart.' : 'Copy was unavailable. Select the code above and copy it manually.';
    status.dataset.state = copied ? 'success' : 'error';
    timers.set(button, setTimeout(() => { button.textContent = labels.get(button); }, 3000));
  });
})();
