/* Presentation only: retain all tracking table nodes, values and delegated events. */
(function () {
  'use strict';
  function start() {
    var root = document.getElementById('pc-trk-app');
    if (!root || root.dataset.pcTrackingWorkspace) return;
    root.dataset.pcTrackingWorkspace = 'ready';
    var pending = false;
    var previousWidth = -1;

    function enhance() {
      pending = false;
      root.querySelectorAll('table.pc-trk-table').forEach(function (table) {
        var headers = Array.from(table.querySelectorAll('thead tr:first-child th'));
        if (!headers.length) return;
        var labels = headers.map(function (heading, index) {
          return heading.textContent.trim() || (index === headers.length - 1 ? '操作' : '详情');
        });
        Array.from(table.tBodies).forEach(function (body) {
          Array.from(body.rows).forEach(function (row) {
            Array.from(row.cells).forEach(function (cell, index) {
              if (cell.colSpan > 1) return;
              cell.dataset.label = labels[index] || '详情';
            });
          });
        });
        var width = table.parentElement.getBoundingClientRect().width;
        var dense = headers.length >= 5 || table.classList.contains('pc-trk-visits');
        table.classList.add('pc-trk-workspace-table');
        table.classList.toggle('pc-trk-workspace-cards', dense && width < 1040);
        table.classList.toggle('pc-trk-workspace-narrow', dense && width < 520);
      });
    }

    function schedule() {
      if (pending) return;
      pending = true;
      window.requestAnimationFrame(enhance);
    }

    new MutationObserver(schedule).observe(root, {childList: true, subtree: true});
    if ('ResizeObserver' in window) {
      new ResizeObserver(function (entries) {
        var width = entries[0].contentRect.width;
        if (Math.abs(width - previousWidth) < 1) return;
        previousWidth = width;
        schedule();
      }).observe(root);
    } else {
      window.addEventListener('resize', schedule, {passive: true});
    }
    schedule();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
  else start();
}());
