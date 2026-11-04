/* Compact native product metadata without changing variation or purchase behavior. */
(function () {
  'use strict';
  const pricingModels = new WeakMap();
  window.createVcProductMetaStrip = function ({root,summary,form,signal,selectedLabel,productId,unitPricing = null} = {}) {
    if (signal?.aborted || !root || !summary || !form || !root.contains(summary) || !summary.contains(form) || form.querySelector('.vc-product-meta')) return null;
    const strip = document.createElement('div'); strip.className = 'vc-product-meta';
    const specification = document.createElement('span'); specification.className = 'vc-product-meta__spec';
    const selection = document.createElement('span'); selection.className = 'vc-product-meta__selection';
    const unit = document.createElement('span'); unit.className = 'vc-product-meta__unit'; unit.hidden = true;
    selection.append(specification,unit);
    const availability = document.createElement('span'); availability.className = 'vc-product-meta__stock'; availability.hidden = true;
    strip.append(selection,availability);
    const variations = form.querySelector('.variations');
    if (variations) variations.before(strip); else form.prepend(strip);
    const hadClass = root.classList.contains('vc-product-meta-ready'); root.classList.add('vc-product-meta-ready');
    const moved = [];
    let disposed = false, lastVariation = null, lastStock = null, pendingStock = null;
    let pendingPrice = false;
    const attributes = () => Object.fromEntries([...(form.querySelectorAll?.('.variations select') || [])].map(select => [select.name,select.value]));
    let initialAttributes = {};
    try { initialAttributes = JSON.parse(root.dataset?.vcHomeInitial || '{}').attributes || {}; } catch (_) { /* Missing initial data hides the optional price. */ }
    let pricing = pricingModels.get(form);
    if (!pricing && window.VcProductUnitPricing) {
      pricing = window.VcProductUnitPricing.create({productId,variable:!!variations,initial:unitPricing,initialAttributes});
      pricingModels.set(form,pricing);
    }
    const $form = window.jQuery?.(form);
    const cached = $form?.data?.('product_variations');
    if (Array.isArray(cached)) cached.forEach(variation => pricing?.remember(variation));
    const priceNamespace = '.vcProductUnitPricing';
    function foundVariation(_, variation) {
      pricing?.remember(variation);
      // This event can follow an AJAX lookup; only its exact selected attributes
      // may release the pending state. It does not choose or substitute a SKU.
      pendingPrice = !window.VcProductUnitPricing?.exactAttributes(variation?.attributes,attributes());
      update();
    }
    function clearPrice() { pendingPrice = true; update(); }
    function selectionChanged(event) {
      if (event.target?.matches?.('.variations select')) clearPrice();
    }
    form.addEventListener('change',selectionChanged,{capture:true});
    $form?.on?.('found_variation' + priceNamespace,foundVariation);
    $form?.on?.('reset_data' + priceNamespace + ' hide_variation' + priceNamespace,clearPrice);
    function adopt(control) {
      if (!control || strip.contains(control) || moved.some(item => item.control === control)) return;
      const marker = document.createComment('native product metadata'); control.before(marker);
      moved.push({control,marker}); strip.append(control);
    }
    const text = (target,value) => {
      if (target.textContent !== value) target.textContent = value;
      if (target.hidden !== !value) target.hidden = !value;
    };
    function update() {
      if (disposed) return;
      adopt(form.querySelector('.reset_variations'));
      adopt(summary.querySelector('.vc-home-quick__sku'));
      adopt(summary.querySelector('.vc-showcase-quick__details'));
      text(specification,String(selectedLabel?.() || '').trim());
      const variable = !!variations;
      const variation = String(form.querySelector('[name="variation_id"]')?.value || '');
      const equivalent = pricing?.resolve({variationId:Number(variation),attributes:attributes(),pending:pendingPrice});
      text(unit,equivalent?.text || '');
      if (equivalent && unit.title !== equivalent.description) unit.title = equivalent.description;
      const stock = variable ? form.querySelector('.single_variation .stock') : summary.querySelector(':scope > .stock');
      // Woo replaces the availability template after changing variation_id.
      // Do not copy the preceding variation's still-mounted stock during that beat.
      if (variable && lastVariation !== null && variation !== lastVariation && stock && stock === lastStock) pendingStock = stock;
      if (stock !== pendingStock) pendingStock = null;
      const current = !variable || Number(variation) > 0;
      const value = current && stock && stock !== pendingStock && !stock.hidden ? stock.textContent.trim() : '';
      const classes = 'vc-product-meta__stock' + (value && stock.className ? ' ' + stock.className : '');
      if (availability.className !== classes) availability.className = classes;
      text(availability,value); lastVariation = variation; lastStock = stock;
    }
    function destroy() {
      if (disposed) return; disposed = true; signal?.removeEventListener('abort',destroy);
      form.removeEventListener?.('change',selectionChanged,true);
      $form?.off?.(priceNamespace);
      for (const {control,marker} of moved) { if (marker.parentNode) marker.replaceWith(control); }
      strip.remove(); if (!hadClass) root.classList.remove('vc-product-meta-ready');
    }
    signal?.addEventListener('abort',destroy,{once:true}); update();
    return {element:strip,update,destroy};
  };
})();
