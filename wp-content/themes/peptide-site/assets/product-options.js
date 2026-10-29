/* Present WooCommerce attributes as buttons; native selects remain the source
 * of truth for matching, stock, price, variation_id and cart submission. */
(($) => {
  'use strict';
  const enhance = form => {
    if (form.dataset.vcOptionsReady) return;
    const selects = [...form.querySelectorAll('.variations select')];
    if (!selects.length) return;
    form.dataset.vcOptionsReady = 'true';
    const controls = [];
    selects.forEach((select, index) => {
      const label = form.querySelector(`label[for="${CSS.escape(select.id)}"]`);
      const attributeName = label?.textContent.trim() || 'Specification';
      const group = document.createElement('div');
      group.className = 'vc-variation-options';
      group.setAttribute('role', 'radiogroup');
      group.setAttribute('aria-label', `Choose ${attributeName}`);
      const status = document.createElement('p');
      status.className = 'vc-option-status';
      status.id = `vc-option-status-${form.dataset.product_id}-${index}`;
      status.setAttribute('aria-live', 'polite');
      group.setAttribute('aria-describedby', status.id);
      const buttons = new Map();
      const choose = value => {
        if (select.value === value) return;
        $(select).val(value).trigger('change');
        document.dispatchEvent(new CustomEvent('vc:visitor-action', {detail: {
          type: 'variant_select', data: {product_id: form.dataset.product_id, attribute: select.name, value}
        }}));
      };
      const addOptions = () => [...select.options].filter(option => option.value).forEach(option => {
        if (buttons.has(option.value)) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'vc-variation-option';
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', 'false');
        button.dataset.value = option.value;
        button.textContent = option.textContent;
        button.addEventListener('click', () => choose(option.value));
        group.append(button);
        buttons.set(option.value, button);
      });
      const sync = () => {
        addOptions();
        const available = new Map([...select.options].map(option => [option.value, option]));
        let selected;
        buttons.forEach((button, value) => {
          const option = available.get(value);
          const checked = select.value === value;
          button.disabled = select.disabled || !option || option.disabled;
          button.setAttribute('aria-checked', String(checked));
          button.tabIndex = -1;
          button.classList.toggle('is-selected', checked);
          if (checked) selected = button;
        });
        const focusable = selected && !selected.disabled ? selected : [...buttons.values()].find(button => !button.disabled);
        if (focusable) focusable.tabIndex = 0;
        status.textContent = selected ? `Selected: ${selected.textContent}` : `Choose a ${attributeName.toLocaleLowerCase()} option`;
      };
      group.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        const enabled = [...buttons.values()].filter(button => !button.disabled);
        if (!enabled.length) return;
        event.preventDefault();
        const current = enabled.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1 : (current + (['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 1) + enabled.length) % enabled.length;
        enabled[next].focus();
        choose(enabled[next].dataset.value);
      });
      addOptions();
      if (!buttons.size) return;
      select.after(group, status);
      // Enhance only after usable controls exist; no-JS retains the real select.
      select.classList.add('vc-native-variation');
      select.tabIndex = -1;
      select.setAttribute('aria-hidden', 'true');
      label?.addEventListener('click', event => { event.preventDefault(); group.querySelector('[tabindex="0"]')?.focus(); });
      controls.push(sync);
      $(select).on('change.vialcove', sync);
      new MutationObserver(sync).observe(select, {childList: true, subtree: true, attributes: true, attributeFilter: ['disabled']});
      sync();
    });
    form.classList.add('vc-options-ready');
    const price = form.closest('.summary')?.querySelector(':scope > .price');
    const originalPrice = price?.innerHTML;
    let priceAnimation;
    const syncAll = () => controls.forEach(sync => sync());
    const resetPrice = () => {
      priceAnimation?.cancel();
      if (price) price.innerHTML = originalPrice;
      form.classList.remove('vc-price-mirrored');
      syncAll();
    };
    $(form).on('woocommerce_update_variation_values.vialcove', syncAll);
    $(form).on('reset_data.vialcove hide_variation.vialcove', resetPrice);
    $(form).on('found_variation.vialcove', (event, variation) => {
      syncAll();
      if (price && variation?.price_html) {
        // Server-formatted markup supplied through Woo's native variation
        // response; its public price pair also carries the demo disclosure.
        priceAnimation?.cancel();
        price.innerHTML = variation.price_html;
        price.setAttribute('aria-live', 'polite');
        price.setAttribute('aria-atomic', 'true');
        form.classList.add('vc-price-mirrored');
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          priceAnimation = price.animate([{opacity: .5}, {opacity: 1}], {duration: 180, easing: 'ease-out'});
        }
      } else {
        resetPrice();
      }
    });
  };
  document.querySelectorAll('.variations_form').forEach(enhance);
  $(document).on('wc_variation_form.vialcove', '.variations_form', function () { enhance(this); });
})(jQuery);
