(() => {
  'use strict';
  const form = document.querySelector('#pc-review-editor form');
  if (!form) return;
  const images = form.querySelector('[name="image_ids"]');
  const video = form.querySelector('[name="video_id"]');
  const gallery = form.querySelector('[data-pc-review-images]');
  const movie = form.querySelector('[data-pc-review-video]');
  const updateImages = () => { images.value = [...gallery.querySelectorAll('[data-media-id]')].map(el => el.dataset.mediaId).join(','); };
  gallery.addEventListener('click', event => { const remove = event.target.closest('[data-pc-remove-image]'); if (remove) { remove.closest('[data-media-id]').remove(); updateImages(); } });
  form.querySelector('[data-pc-choose-images]').addEventListener('click', () => {
    const picker = wp.media({ title: '选择评价图片', library: { type: 'image' }, multiple: true });
    picker.on('select', () => {
      const existing = new Set(images.value.split(',').filter(Boolean));
      picker.state().get('selection').each(attachment => {
        const data = attachment.toJSON();
        if (existing.has(String(data.id)) || gallery.children.length >= 5) return;
        const item = document.createElement('span'); item.dataset.mediaId = data.id;
        const img = document.createElement('img'); img.src = data.sizes?.thumbnail?.url || data.url; img.alt = '';
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button-link'; remove.dataset.pcRemoveImage = ''; remove.textContent = '移除';
        item.append(img, remove); gallery.appendChild(item); existing.add(String(data.id));
      }); updateImages();
    }); picker.open();
  });
  form.querySelector('[data-pc-choose-video]').addEventListener('click', () => {
    const picker = wp.media({ title: '选择评价视频', library: { type: 'video' }, multiple: false });
    picker.on('select', () => { const data = picker.state().get('selection').first().toJSON(); video.value = data.id; const el = document.createElement('video'); el.controls = true; el.preload = 'metadata'; el.src = data.url; movie.replaceChildren(el); form.querySelector('[name="pc_review_video"]').value = ''; }); picker.open();
  });
  form.querySelector('[data-pc-remove-video]').addEventListener('click', () => { video.value = ''; movie.replaceChildren(); form.querySelector('[name="pc_review_video"]').value = ''; });
})();
