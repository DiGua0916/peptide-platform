/* Compact native product metadata without changing variation or purchase behavior. */
(function () {
  'use strict';
  function specificationLabel(value) {
    const label = String(value || '').trim();
    const match = label.match(/^(.+?)\s*[×x*]\s*(\d+)\s*vials?\s*$/i);
    if (!match) return label;
    const strength = match[1].trim().replace(/(\d)\s*(mg|mcg|µg|μg|ug|g|ml|iu)\b/gi, '$1 $2');
    return strength + ' per vial · ' + match[2] + (Number(match[2]) === 1 ? ' vial per box' : ' vials per box');
  }
  window.vcProductSpecification = Object.freeze({format:specificationLabel});
  window.createVcProductMetaStrip = function ({root,summary,form,signal,selectedLabel,productId,unitPricing = null} = {}) {
    if (signal?.aborted || !root || !summary || !form || !root.contains(summary) || !summary.contains(form) || form.querySelector('.vc-product-meta')) return null;
    const strip = document.createElement('div'); strip.className = 'vc-product-meta';
    const specification = document.createElement('span'); specification.className = 'vc-product-meta__spec';
    const selection = document.createElement('span'); selection.className = 'vc-product-meta__selection';
    selection.append(specification);
    const availability = document.createElement('span'); availability.className = 'vc-product-meta__stock'; availability.hidden = true;
    strip.append(selection,availability);
    const variations = form.querySelector('.variations');
    if (variations) variations.before(strip); else form.prepend(strip);
    const hadClass = root.classList.contains('vc-product-meta-ready'); root.classList.add('vc-product-meta-ready');
    const suppressed = new Map();
    let disposed = false, lastVariation = null, lastStock = null, pendingStock = null;
    function suppress(control) {
      if (!control) return;
      if (!suppressed.has(control)) suppressed.set(control,control.hidden);
      if (!control.hidden) control.hidden = true;
    }
    const text = (target,value) => {
      if (target.textContent !== value) target.textContent = value;
      if (target.hidden !== !value) target.hidden = !value;
    };
    function update() {
      if (disposed) return;
      suppress(form.querySelector('.reset_variations'));
      suppress(summary.querySelector('.vc-home-quick__sku'));
      suppress(summary.querySelector('.vc-showcase-quick__details'));
      text(specification,specificationLabel(selectedLabel?.()));
      const variable = !!variations;
      const variation = String(form.querySelector('[name="variation_id"]')?.value || '');
      const stock = variable ? form.querySelector('.single_variation .stock') : summary.querySelector(':scope > .stock');
      // Woo replaces the availability template after changing variation_id.
      // Do not copy the preceding variation's still-mounted stock during that beat.
      if (variable && lastVariation !== null && variation !== lastVariation && stock && stock === lastStock) pendingStock = stock;
      if (stock !== pendingStock) pendingStock = null;
      const current = !root.vcHomeSelectionPending && (!variable || Number(variation) > 0);
      const value = current && stock && stock !== pendingStock && !stock.hidden ? stock.textContent.trim() : '';
      const classes = 'vc-product-meta__stock' + (value && stock.className ? ' ' + stock.className : '');
      if (availability.className !== classes) availability.className = classes;
      text(availability,value); lastVariation = variation; lastStock = stock;
    }
    // WooCommerce draws the chosen variation's availability 300 ms after found_variation
    // (add-to-cart-variation.js waits before $singleVariation.html()). The dialog's own refreshes
    // can all land before that, leaving the stock line empty. Read it again on Woo's own
    // "variation drawn / cleared" events; only the timing changes, not the text or its style.
    const $ = window.jQuery;
    const wooDrawn = 'show_variation.vcProductMeta hide_variation.vcProductMeta reset_data.vcProductMeta';
    const onWooDrawn = () => update();
    if (variations && $) $(form).on(wooDrawn, onWooDrawn);
    function destroy() {
      if (disposed) return; disposed = true; signal?.removeEventListener('abort',destroy);
      if (variations && $) $(form).off('.vcProductMeta');
      for (const [control,hidden] of suppressed) control.hidden = hidden;
      strip.remove(); if (!hadClass) root.classList.remove('vc-product-meta-ready');
    }
    signal?.addEventListener('abort',destroy,{once:true}); update();
    return {element:strip,update,destroy};
  };
})();
