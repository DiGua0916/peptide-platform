/* Adapter for the PeptideCore visitor-cart REST contract. No writes or price calculations. */
(() => {
  'use strict';
  const mapStatus = Object.freeze({active:'ready',empty:'empty',unknown:'unknown',expired:'expired'});
  const labels = Object.freeze({variation:'规格',sku:'SKU',coupon:'优惠券',discount:'优惠券抵扣',
    subtotal:'税前、优惠券折扣前的商品小计（不含运费）。',final:'实际应付金额以客户购物车和结算页为准。'});
  const idOf = value => {
    if (!['number','string'].includes(typeof value) || !/^\d+$/.test(String(value))) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  };
  function invalid() { return new Error('Invalid PeptideCore visitor-cart response'); }
  function readSummary(raw) {
    if (raw === null || raw === undefined) return {status:'unknown',item_count:0};
    if (typeof raw !== 'object' || !Object.hasOwn(mapStatus,raw.status)
      || typeof raw.count !== 'number' || !Number.isFinite(raw.count) || raw.count < 0) throw invalid();
    if ((raw.status === 'active' && raw.count <= 0) || (raw.status === 'empty' && raw.count !== 0)) throw invalid();
    return {status:mapStatus[raw.status],item_count:raw.count};
  }
  function snapshotTime(ms) {
    if (!ms) return null;
    if (typeof ms !== 'number' || !Number.isSafeInteger(ms) || ms < 0) throw invalid();
    const date = new Date(ms);
    if (!Number.isFinite(date.getTime())) throw invalid();
    return date.toISOString();
  }
  function normalize(raw, visitorId, strings) {
    if (!raw || typeof raw !== 'object' || idOf(raw.visitor_id) !== visitorId) throw invalid();
    const info = readSummary(raw);
    const result = {visitor_id:visitorId,status:info.status,item_count:info.item_count,
      updated_at:snapshotTime(raw.updated_ms),items:[],subtotal_text:typeof raw.subtotal_text === 'string' ? raw.subtotal_text : ''};
    // An expired response may contain a historical snapshot. It must not look like a current cart.
    if (info.status !== 'ready') return result;
    if (!Array.isArray(raw.items) || !raw.items.length || typeof raw.subtotal_text !== 'string') throw invalid();
    result.items = raw.items.map(item => {
      if (!item || typeof item.name !== 'string' || typeof item.quantity !== 'number' || !Number.isFinite(item.quantity) || item.quantity <= 0
        || typeof item.unit_price_text !== 'string' || typeof item.line_total_text !== 'string') throw invalid();
      const specifications = [];
      if (typeof item.variation === 'string' && item.variation.trim()) specifications.push({label:strings.variation,value:item.variation});
      if (typeof item.sku === 'string' && item.sku.trim()) specifications.push({label:strings.sku,value:item.sku});
      return {product_id:item.product_id,variation_id:item.variation_id,name:item.name,
        image_url:typeof item.image === 'string' ? item.image : '',specifications,quantity:item.quantity,
        unit_price_text:item.unit_price_text,line_total_text:item.line_total_text};
    });
    const note = [strings.subtotal];
    const coupons = Array.isArray(raw.coupons) ? raw.coupons.filter(code => typeof code === 'string' && code.trim()) : [];
    if (coupons.length) note.push(`${strings.coupon}：${coupons.join('、')}。`);
    if (typeof raw.discount_text === 'string' && raw.discount_text.trim()) note.push(`${strings.discount}：${raw.discount_text}。`);
    note.push(strings.final);
    result.subtotal_note = note.join(' ');
    return result;
  }
  function createLoader(apiFetch, strings) {
    if (typeof apiFetch !== 'function') throw new TypeError('wp.apiFetch is required');
    const copy = Object.assign({},labels,strings || {});
    return async function loadCart(id, {signal} = {}) {
      const visitorId = idOf(id);
      if (!visitorId) throw new TypeError('A valid visitor ID is required');
      const raw = await apiFetch({path:`/peptide/v1/track/admin/visitors/${visitorId}/cart`,method:'GET',signal,cache:'no-store'});
      return normalize(raw,visitorId,copy);
    };
  }
  window.PCVisitorCartWordPress = Object.freeze({createLoader,summary:readSummary});
})();
