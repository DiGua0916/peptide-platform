document.addEventListener('submit', event => {
  if (!event.target.matches('[data-pc-campaign-control]')) return;
  const op = event.submitter?.value;
  if (['schedule', 'resume', 'retry'].includes(op) && !event.target.querySelector('[name=confirm_sending]').checked) {
    event.preventDefault(); const consent = event.target.querySelector('[name=confirm_sending]');
    consent.setCustomValidity(consent.closest('label').textContent.trim()); consent.reportValidity();
    consent.addEventListener('change', () => consent.setCustomValidity(''), { once: true });
  }
});
