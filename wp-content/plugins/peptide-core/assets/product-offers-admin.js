jQuery(($) => {
  const form = $('[data-pc-offer-editor]');
  if (!form.length) return;
  const kinds = () => form.find('[data-pc-offer-kind]').each(function () {
    const show = this.dataset.pcOfferKind === form.find('[name=kind]').val();
    $(this).prop('hidden', !show).find(':input').prop('disabled', !show);
  });
  form.on('change', '[name=kind]', kinds); kinds();
  form.on('click', '[data-pc-offer-add-slot]', () => {
    const list = form.find('[data-pc-offer-slots]');
    if (list.children().length >= 12) return;
    const id = Date.now(); const html = form.find('[data-pc-offer-slot-template]').html().replaceAll('9999', String(id));
    list.append(html); $(document.body).trigger('wc-enhanced-select-init');
  });
  form.on('click', '[data-pc-offer-remove]', function () { $(this).closest('[data-pc-offer-slot]').remove(); });
});
