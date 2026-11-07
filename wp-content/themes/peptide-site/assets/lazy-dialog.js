/* Runs the inert product dialog scripts (inc/lazy-dialog.php) in their original order. */
(function () {
  'use strict';
  var started = false, ready = false, held = null;
  var nodes = function () { return Array.prototype.slice.call(document.querySelectorAll('script[data-vc-lazy="dialog"]')); };
  if (!nodes().length) return;

  function finish() {
    ready = true;
    document.documentElement.removeAttribute('data-vc-dialog-loading');
    document.dispatchEvent(new CustomEvent('vc:lazy-dialog-ready'));
    var target = held; held = null;
    if (target && target.isConnected && document.visibilityState !== 'hidden') target.click();
  }

  function run() {
    if (started) return;
    started = true;
    var list = nodes();
    // Fetch everything in parallel; execute strictly one after another, as the page did.
    list.forEach(function (node) {
      var src = node.getAttribute('data-vc-src');
      if (!src) return;
      var link = document.createElement('link');
      link.rel = 'preload'; link.as = 'script'; link.href = src;
      document.head.appendChild(link);
    });
    var i = 0;
    (function next() {
      if (i >= list.length) { finish(); return; }
      var node = list[i++], script = document.createElement('script');
      for (var a = 0; a < node.attributes.length; a++) {
        var name = node.attributes[a].name;
        if (name !== 'type' && name !== 'data-vc-lazy' && name !== 'data-vc-src') script.setAttribute(name, node.attributes[a].value);
      }
      var src = node.getAttribute('data-vc-src');
      if (src) {
        script.async = false;
        script.onload = script.onerror = function () { next(); };
        script.src = src;
        node.parentNode.replaceChild(script, node);
      } else {
        script.text = node.text;
        node.parentNode.replaceChild(script, node); // inline code runs on insertion
        next();
      }
    })();
  }

  // Product taps before the bundle has run: hold the tap, show progress, replay once ready.
  // Window capture runs before every document listener the page registers.
  window.addEventListener('click', function (event) {
    if (ready || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var target = event.target && event.target.closest && event.target.closest('[data-vc-quick-open]');
    if (!target) return;
    event.preventDefault(); event.stopImmediatePropagation();
    held = target;
    document.documentElement.setAttribute('data-vc-dialog-loading', '');
    run();
  }, true);

  // Intent: the first tap, key or focus anywhere starts loading (the click usually follows).
  var intent = function () {
    ['pointerdown', 'touchstart', 'keydown', 'focusin'].forEach(function (type) { window.removeEventListener(type, intent, true); });
    run();
  };
  ['pointerdown', 'touchstart', 'keydown', 'focusin'].forEach(function (type) { window.addEventListener(type, intent, {capture: true, passive: true}); });

  // Back/forward or reload that restores a product layer, or a catalogue anchor: run now.
  var state = window.history.state;
  var layers = state && state.__vcCommerceLayers && state.__vcCommerceLayers.layers;
  if ((layers && layers.length) || window.location.hash === '#vc-products') { run(); return; }

  // Otherwise once the page has loaded and the browser is idle.
  var idle = function () {
    if (window.requestIdleCallback) window.requestIdleCallback(run, {timeout: 2500});
    else window.setTimeout(run, 1200);
  };
  if (document.readyState === 'complete') idle();
  else window.addEventListener('load', idle, {once: true});
})();
