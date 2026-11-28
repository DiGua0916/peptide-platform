/* 总后台顶栏“美西”开关（店主 2026-11-28，照店铺后台 view-timezone.js）：点一下换成美西时间，再点一次回到总后台时间。
 * 选择存在账号上（服务器），刷新、重新登录都还在。换完整页重载，服务器按新时区重新算。 */
(function () {
  'use strict';
  var cfg = window.pchqViewTimezone;
  var busy = false;
  if (!cfg) return;
  function paint(box, zone) {
    box.setAttribute('data-current', zone);
    box.querySelectorAll('.pchq-view-tz__btn').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-zone') === zone ? 'true' : 'false'); });
    var label = box.querySelector('.pchq-view-tz__label');
    if (label) label.textContent = (cfg.labels && cfg.labels[zone]) || '';
  }
  // 委托在 document 上：总后台换页时顶栏可能被重新渲染。
  document.addEventListener('click', function (event) {
    var btn = event.target.closest && event.target.closest('.pchq-view-tz__btn');
    if (!btn || busy) return;
    var box = btn.closest('.pchq-view-tz');
    var before = box.getAttribute('data-current') || '';
    var clicked = btn.getAttribute('data-zone') || '';
    var next = before === clicked ? '' : clicked;
    busy = true;
    box.classList.add('is-busy');
    paint(box, next);
    fetch(cfg.endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce},
      body: JSON.stringify({zone: next})
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    }).then(function () {
      location.reload();
    }).catch(function () {
      paint(box, before);
      busy = false;
      box.classList.remove('is-busy');
      if (window.VialCoveDialogs && window.VialCoveDialogs.alert) window.VialCoveDialogs.alert('时区没有切换成功，请稍后再试。');
    });
  });
}());
