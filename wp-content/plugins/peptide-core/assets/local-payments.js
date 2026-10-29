/* Native Woo Blocks payment providers. The server repeats the strict local/opt-in gate. */
(() => {
  'use strict';
  const registry = window.wc?.wcBlocksRegistry;
  const settings = window.wc?.wcSettings;
  const element = window.wp?.element;
  if (!registry?.registerPaymentMethod || !settings?.getPaymentMethodData || !element?.createElement) return;
  for (const name of ['pc_local_card', 'pc_local_wallet']) {
    const data = settings.getPaymentMethodData(name, {});
    if (data.enabled !== true) continue;
    const content = element.createElement('p', null, data.description);
    registry.registerPaymentMethod({name, label: element.createElement('span', null, data.title), content, edit: content,
      canMakePayment: () => data.enabled === true, ariaLabel: data.title, supports: {features: data.supports || ['products']}});
  }
})();
