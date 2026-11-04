/* Read-only selected-SKU unit pricing. Woo still sells the native sales unit. */
(function (scope, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (scope) scope.VcProductUnitPricing = api;
})(typeof window === 'object' ? window : null, function () {
  'use strict';
  const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
  const id = value => Number.isSafeInteger(value) && value > 0;
  const sources = new Set(['explicit_sku', 'stored_pack', 'explicit_parent']);
  const money = value => value && typeof value === 'object'
    && typeof value.minor === 'string' && /^(?:0|[1-9]\d*)$/.test(value.minor) && Number.isSafeInteger(Number(value.minor))
    && typeof value.text === 'string' && value.text.trim().length > 0 && value.text.length <= 100;
  function display(dto, {productId, variationId = 0, variable = false, pending = false} = {}) {
    if (pending || !id(productId) || (variable && !id(variationId)) || !dto || dto.version !== 1
      || dto.productId !== productId || dto.variationId !== (variable ? variationId : 0)
      || dto.purchasableId !== (variable ? variationId : productId)
      || !Number.isInteger(dto.vialsPerSalesUnit) || dto.vialsPerSalesUnit < 1 || dto.vialsPerSalesUnit > 10000
      || !sources.has(dto.countSource) || !money(dto.salesUnitPrice) || !money(dto.perVialPrice)
      || typeof dto.approximate !== 'boolean' || !/^[A-Z]{3}$/.test(dto.currency || '')
      || !Number.isInteger(dto.currencyMinorUnit) || dto.currencyMinorUnit < 0 || dto.currencyMinorUnit > 8
      || !['incl', 'excl'].includes(dto.taxDisplay) || dto.basis !== 'catalog_display_price' || dto.includesCoupons !== false
      || dto.isPurchasableAsSingleVial !== (dto.vialsPerSalesUnit === 1)) return null;
    return {
      text: (dto.approximate ? 'Approx. ' : '') + dto.perVialPrice.text.trim() + ' / vial',
      description: 'Equivalent price per vial; sold as a ' + dto.vialsPerSalesUnit + '-vial pack.'
    };
  }
  function exactAttributes(expected, selected) {
    if (!expected || !selected || typeof expected !== 'object' || typeof selected !== 'object') return false;
    const keys = Object.keys(selected);
    return keys.length > 0 && keys.every(key => typeof selected[key] === 'string' && selected[key] !== ''
      && own(expected, key) && expected[key] === selected[key]) && Object.keys(expected).length === keys.length;
  }
  function create({productId, variable = false, initial = null, initialAttributes = {}} = {}) {
    const cache = new Map();
    if (initial && id(initial.variationId)) cache.set(initial.variationId, {dto: initial, attributes: initialAttributes});
    return {
      remember(variation) {
        if (!variation || !id(variation.variation_id)) return;
        // An explicit null/missing native DTO replaces an older value for this SKU.
        cache.set(variation.variation_id, {dto: own(variation, 'pc_unit_pricing') ? variation.pc_unit_pricing : null, attributes: variation.attributes});
      },
      resolve({variationId = 0, attributes = {}, pending = false} = {}) {
        if (!variable) return display(initial, {productId, pending});
        const entry = cache.get(variationId);
        if (!entry || !exactAttributes(entry.attributes, attributes)) return null;
        return display(entry.dto, {productId, variationId, variable, pending});
      }
    };
  }
  return Object.freeze({display, exactAttributes, create});
});
