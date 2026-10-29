(() => {
  'use strict';
  document.querySelectorAll('.pc-review-upload').forEach(box => {
    const form = box.closest('form');
    if (!form) return;
    form.enctype = 'multipart/form-data';
    const output = box.querySelector('.pc-review-result');
    const photos = box.querySelector('[name="pc_review_photos[]"]');
    const video = box.querySelector('[name="pc_review_video"]');
    const consent = box.querySelector('[name="media_consent"]');
    const summary = document.createElement('p');
    summary.className = 'pc-review-file-summary';
    box.insertBefore(summary, output);
    const message = (text, error = false) => {
      output.textContent = text;
      output.classList.toggle('is-error', error);
    };
    const validate = () => {
      const images = [...photos.files], videos = [...video.files];
      summary.textContent = [...images, ...videos].map(f => f.name).join(', ');
      consent.required = images.length + videos.length > 0;
      if (images.length > 5) return 'Choose at most five review photos.';
      if (images.some(f => f.size > 5 * 1024 * 1024) || videos.some(f => f.size > 25 * 1024 * 1024)) return 'A review file exceeds the size limit.';
      if ([...images, ...videos].reduce((total, f) => total + f.size, 0) > 30 * 1024 * 1024) return 'The combined review uploads exceed 30 MB.';
      return '';
    };
    [photos, video].forEach(input => input.addEventListener('change', () => message(validate(), true)));
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const error = validate();
      if (error) { message(error, true); return; }
      if (!form.reportValidity()) return;
      const button = form.querySelector('[type="submit"]');
      if (button.disabled) return;
      const data = new FormData(form);
      button.disabled = true;
      message('Submitting your review…');
      try {
        const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'The review could not be submitted. Please try again.');
        message(result.message);
        form.querySelector('[name="comment"]').value = '';
        photos.value = ''; video.value = ''; consent.checked = false; consent.required = false; summary.textContent = '';
      } catch (failure) {
        message(failure.message || 'The review could not be submitted. Please try again.', true);
      } finally { button.disabled = false; }
    });
  });
})();
