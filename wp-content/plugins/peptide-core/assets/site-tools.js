(() => {
  'use strict';
  const root = document.querySelector('.pc-site-tools');
  if (!root) return;
  const status = root.querySelector('.pc-tools-copy-status');
  const scope = root.querySelector('select[name="scope"]');
  const targets = root.querySelector('[data-selected-pages]');
  const update = () => { if (targets && scope) targets.hidden = scope.value !== 'selected'; };
  if (scope) scope.addEventListener('change', update);
  update();
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-phrase]');
    if (!button) return;
    const area = document.getElementById(button.dataset.copyPhrase);
    if (!area) return;
    let copied = false;
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(area.value); copied = true; }
      else { area.focus(); area.select(); copied = document.execCommand('copy'); }
    } catch (_) { copied = false; }
    if (status) status.textContent = copied ? '话术已复制。' : '复制未完成，请选中正文后手动复制。';
  });
})();
