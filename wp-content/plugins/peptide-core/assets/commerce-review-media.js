jQuery(($) => {
  const box = $('#pc-review-media');
  box.on('click', '[data-pc-review-images], [data-pc-review-video]', function () {
    const photos = this.hasAttribute('data-pc-review-images');
    const frame = wp.media({ library: { type: photos ? 'image' : 'video' }, multiple: photos });
    frame.on('select', () => {
      const items = frame.state().get('selection').toJSON();
      box.find(photos ? '[name=pc_review_images]' : '[name=pc_review_video]').val(photos ? items.slice(0, 12).map(item => item.id).join(',') : items[0]?.id || '');
      if (photos) box.find('[data-pc-review-image-count]').text(Math.min(12, items.length));
    });
    frame.open();
  });
  box.on('click', '[data-pc-review-clear]', () => { box.find('[name=pc_review_images], [name=pc_review_video]').val(''); box.find('[data-pc-review-image-count]').text('0'); });
});
