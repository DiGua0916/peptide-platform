(function () {
  'use strict';
  var config = window.pcDesignDetails || {};
  var nav = document.querySelector('.pc-mobile-fixed');
  if (nav) {
    var reserve = function () {
      document.body.style.setProperty('--pc-mobile-nav-reserve', Math.ceil(nav.getBoundingClientRect().height) + 'px');
    };
    reserve();
    if (window.ResizeObserver) { new ResizeObserver(reserve).observe(nav); }
    else { window.addEventListener('resize', reserve, { passive: true }); }
  }
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (!config.transition || reduced.matches) { return; }
  var layer = document.createElement('div');
  layer.className = 'pc-page-transition'; layer.setAttribute('aria-hidden', 'true');
  var duration = [300, 500, 700].indexOf(Number(config.duration)) >= 0 ? Number(config.duration) : 500;
  layer.style.setProperty('--pc-transition-duration', duration + 'ms');
  if (/^#[a-f0-9]{3,8}$/i.test(config.background || '')) { layer.style.setProperty('--pc-transition-background', config.background); }
  if (config.image) { var image = document.createElement('img'); image.src = config.image; image.alt = ''; layer.appendChild(image); }
  document.body.appendChild(layer);
  var moving = false, timer, destination = '';
  var reset = function () { moving = false; clearTimeout(timer); layer.classList.remove('is-active'); };
  window.addEventListener('pageshow', reset);
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || reduced.matches || moving) { return; }
    var link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.closest('[contenteditable=true]')) { return; }
    var url;
    try { url = new URL(link.href, location.href); } catch (_) { return; }
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || (url.pathname === location.pathname && url.search === location.search)) { return; }
    if ((config.exclude || []).some(function (prefix) { var path = String(prefix).replace(/\/$/, ''); return path && (url.pathname === path || url.pathname.indexOf(path + '/') === 0); })) { return; }
    // Do not delay checkout, account actions, admin, AJAX or file downloads.
    if (/\/(?:wp-admin|wp-json|wp-login\.php|cart|checkout|my-account)(?:\/|$)/i.test(url.pathname) || /\.[a-z0-9]{2,8}$/i.test(url.pathname) || /(?:wc-ajax|add-to-cart|download_file|_wpnonce|action)=/i.test(url.search)) { return; }
    event.preventDefault(); moving = true; destination = url.href; layer.classList.add('is-active');
    timer = setTimeout(function () { window.location.assign(url.href); }, duration);
  });
  var motionChanged = function (event) { if (event.matches) { var next = moving ? destination : ''; reset(); if (next) { window.location.assign(next); } } };
  if (reduced.addEventListener) { reduced.addEventListener('change', motionChanged); }
  else if (reduced.addListener) { reduced.addListener(motionChanged); }
})();
