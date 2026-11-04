document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-pc-subs-delete]');
  if (!form) return;
  form.querySelector('[data-pc-subs-all]')?.addEventListener('change', event => form.querySelectorAll('[name="ids[]"]').forEach(box => { box.checked = event.target.checked; }));
  form.addEventListener('submit', event => {
    if (!form.querySelector('[name="ids[]"]:checked')) { event.preventDefault(); return; }
    if (!window.VialCoveDialogs.approved(form,event.submitter)) { event.preventDefault(); window.VialCoveDialogs.confirmSubmit(form,event.submitter,form.dataset.confirm); }
  });
});
