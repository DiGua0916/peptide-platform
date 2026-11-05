/* Runs after the native editor config and before its React factory. */
(function (root, factory) {
 'use strict';
 const apply = factory();
 if (typeof module === 'object' && module.exports) module.exports = apply;
 if (root?.pcDesignEditor && root?.vcCheckoutLayoutPreview) apply(root.pcDesignEditor, root.vcCheckoutLayoutPreview);
})(typeof window === 'object' ? window : null, function () {
 'use strict';
 function url(value) {
  try { const u = new URL(value); return /^https?:$/.test(u.protocol) && !u.username && !u.password ? u : null; } catch (_) { return null; }
 }
 function preview(u, templateId) {
  return u && u.searchParams.get('pc_template_preview') === String(templateId)
   && u.searchParams.get('pc_frame') === '1' && u.searchParams.get('pc_design_document') === 'checkout'
   && /^[A-Za-z0-9]+$/.test(u.searchParams.get('pc_preview_nonce') || '');
 }
 return function apply(cfg, bridge) {
  if (!cfg || !bridge || !Number.isSafeInteger(bridge.documentId) || bridge.documentId < 1
   || !Number.isSafeInteger(bridge.templateId) || bridge.templateId < 1 || cfg.document?.document !== 'checkout'
   || cfg.document.id !== bridge.documentId || cfg.document.template?.id !== bridge.templateId
   || cfg.restPath !== '/peptide/v1/layouts/' + bridge.documentId) return false;
  const old = url(cfg.document.previewBase), next = url(bridge.previewBase), store = url(bridge.storeUrl);
  if (!preview(old, bridge.templateId) || !preview(next, bridge.templateId) || !store || old.origin !== next.origin || next.pathname !== store.pathname
   || old.searchParams.get('pc_preview_nonce') !== next.searchParams.get('pc_preview_nonce')) return false;
  cfg.document.previewBase = bridge.previewBase;
  cfg.storeUrl = bridge.storeUrl;
  return true;
 };
});
