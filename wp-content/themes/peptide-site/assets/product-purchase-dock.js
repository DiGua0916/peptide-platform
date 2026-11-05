/* Present the original Woo controls; submission, stock and cart state remain native. */
(function () {
  'use strict';
  window.createVcProductPurchaseDock = function ({root,dialog,form,mainAdd,mainBuy,selectedLabel,priceNode,enabled,signal} = {}) {
    if (!enabled || signal?.aborted || !root || !dialog || !form || !mainAdd || !root.contains(form) || !form.contains(mainAdd) || form.querySelector('.vc-purchase-dock')) return null;
    const native = mainAdd.closest('.woocommerce-variation-add-to-cart');
    const element = native && form.contains(native) ? native : document.createElement('div');
    const reused = element === native;
    const candidate = form.querySelector('input.qty[name="quantity"]')?.closest('.quantity');
    const quantity = candidate && form.contains(candidate) ? candidate : null;
    const controls = document.createElement('div'); controls.className = 'vc-purchase-dock__controls';
    const info = document.createElement('div'); info.className = 'vc-purchase-dock__info';
    const prices = document.createElement('div'); prices.className = 'vc-purchase-dock__prices';
    const current = document.createElement('strong'); current.className = 'vc-purchase-dock__current';
    const compare = document.createElement('del'); compare.className = 'vc-purchase-dock__compare';
    const discount = document.createElement('span'); discount.className = 'vc-purchase-dock__discount';
    const specification = document.createElement('span'); specification.className = 'vc-purchase-dock__spec';
    prices.append(current,compare,discount); info.append(prices,specification);
    const moved = [];
    for (const control of [quantity,mainAdd,mainBuy]) {
      if (!control || !root.contains(control) || moved.some(item => item.control === control)) continue;
      const marker = document.createComment('native purchase control');
      control.before(marker); moved.push({control,marker}); controls.append(control);
    }
    element.classList.add('vc-purchase-dock'); element.append(info,controls);
    if (!reused) form.append(element);
    const classOwners = [dialog,root].map(target => ({target,had:target.classList.contains('vc-product-purchase-docked')}));
    for (const {target} of classOwners) target.classList.add('vc-product-purchase-docked');
    const property = '--vc-purchase-dock-height';
    const original = [dialog,root].map(target => ({target,value:target.style.getPropertyValue(property),priority:target.style.getPropertyPriority(property)}));
    let disposed = false, frame = 0, height = '';
    function measure() {
      frame = 0; if (disposed || document.hidden || !dialog.open) return;
      const value = Math.ceil(element.getBoundingClientRect().height) + 'px';
      if (height === value) return;
      height = value; for (const {target} of original) target.style.setProperty(property,value);
    }
    function schedule() { if (!disposed && !frame && !document.hidden && dialog.open) frame = requestAnimationFrame(measure); }
    function visibility() { if (document.hidden) { cancelAnimationFrame(frame); frame = 0; } else schedule(); }
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
    observer?.observe(element);
    window.addEventListener('resize',schedule,{passive:true}); document.addEventListener('visibilitychange',visibility);
    const text = (target,value) => { if (target.textContent !== value) target.textContent = value; if (target.hidden !== !value) target.hidden = !value; };
    function update() {
      if (disposed) return;
      // Copy the selected server-rendered price pair, never calculate a sale or parse money.
      const amount = priceNode?.querySelector('.vc-price-current .woocommerce-Price-amount') || priceNode?.querySelector('.vc-price-current') || priceNode?.querySelector('.woocommerce-Price-amount');
      const previous = priceNode?.querySelector('.vc-price-compare .woocommerce-Price-amount') || priceNode?.querySelector('.vc-price-compare');
      const amountText = (amount?.textContent || '').trim();
      text(current,amountText ? amountText + ' / box' : '');
      text(compare,(previous?.textContent || '').trim());
      text(discount,(priceNode?.querySelector('.vc-sale-badge')?.textContent || '').trim());
      const label = String(selectedLabel?.() || (!amount ? priceNode?.textContent || '' : '')).trim();
      text(specification,window.vcProductSpecification?.format(label) || label); schedule();
    }
    function destroy() {
      if (disposed) return; disposed = true;
      cancelAnimationFrame(frame); observer?.disconnect();
      window.removeEventListener('resize',schedule); document.removeEventListener('visibilitychange',visibility); signal?.removeEventListener('abort',destroy);
      // Placeholders preserve original native ancestry, order, labels and form ownership.
      for (const {control,marker} of moved) { if (marker.parentNode) marker.replaceWith(control); }
      info.remove(); controls.remove(); element.classList.remove('vc-purchase-dock'); if (!reused) element.remove();
      for (const {target,had} of classOwners) { if (!had) target.classList.remove('vc-product-purchase-docked'); }
      for (const {target,value,priority} of original) { if (value) target.style.setProperty(property,value,priority); else target.style.removeProperty(property); }
    }
    signal?.addEventListener('abort',destroy,{once:true}); update();
    return {element,add:mainAdd,buy:mainBuy || null,update,destroy};
  };
})();
