/* 顶栏看数时区开关（店主 2026-11-26）：点“美西”/“美东”换整个后台的时区，再点一次选中的那个回到店铺时间。
 * 选择存在账号上（服务器），刷新、关浏览器、重新登录都还在。换完只重开内容页（有外框时）或重载当前页。 */
(function () {
  'use strict';
  var cfg = window.pcViewTimezone;
  var box = document.querySelector('.pc-view-tz');
  if (!cfg || !box) return;
  var label = box.querySelector('.pc-view-tz__label');
  var busy = false;
  function paint(zone) {
    box.setAttribute('data-current', zone);
    box.querySelectorAll('.pc-view-tz__btn').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-zone') === zone ? 'true' : 'false'); });
    if (label) label.textContent = (cfg.labels && cfg.labels[zone]) || '';
  }
  function refresh() {
    // 外框（概览 / 数据页）只重开内容页；其他后台页面整页重载，服务器按新时区重新算。
    if (window.pcWorkspaceShell && typeof window.pcWorkspaceShell.reload === 'function') return window.pcWorkspaceShell.reload();
    location.reload();
    return Promise.resolve(true);
  }
  box.addEventListener('click', function (event) {
    var btn = event.target.closest && event.target.closest('.pc-view-tz__btn');
    if (!btn || busy) return;
    var before = box.getAttribute('data-current') || '';
    var clicked = btn.getAttribute('data-zone') || '';
    var next = before === clicked ? '' : clicked;
    busy = true;
    box.classList.add('is-busy');
    paint(next); // 先换按钮状态，点了马上有反应。
    fetch(cfg.endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce},
      body: JSON.stringify({zone: next})
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    }).then(function (data) {
      paint(data && typeof data.zone === 'string' ? data.zone : next);
      return refresh();
    }).catch(function () {
      paint(before);
      if (window.VialCoveDialogs && window.VialCoveDialogs.alert) window.VialCoveDialogs.alert('时区没有切换成功，请稍后再试。');
    }).then(function () {
      busy = false;
      box.classList.remove('is-busy');
    });
  });
}());
