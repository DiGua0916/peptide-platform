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
      // 行为详情直接显示（2026-11-09）：以前折叠在“查看行为细节”后面，店主每行都要点开才知道发生了什么。
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
        headers.forEach(function (heading,index) {
          var numeric = /^(浏览量|浏览次数|访问次数|商品浏览|商品点击|点击次数|成功加购|Pageviews|Page views|Visits)$/i.test(heading.textContent.trim());
          heading.classList.toggle('pc-cell-numeric',numeric);
          Array.from(table.tBodies).forEach(function(body){Array.from(body.rows).forEach(function(row){if(row.cells[index] && row.cells[index].colSpan===1)row.cells[index].classList.toggle('pc-cell-numeric',numeric);});});
        });
        var width = table.parentElement.getBoundingClientRect().width;
        var lifecycle = table.closest('.pc-lifecycle');
        if (lifecycle && !table.parentElement.classList.contains('pc-lifecycle-scroll')) {
          var scroll = document.createElement('div'); scroll.className = 'pc-lifecycle-scroll'; scroll.tabIndex = 0;
          scroll.setAttribute('role','region'); scroll.setAttribute('aria-label',table.classList.contains('pc-trk-visits') ? '历次访问列表，可滚动' : '访问行为明细，可滚动');
          table.parentNode.insertBefore(scroll,table); scroll.appendChild(table);
        }
        var dense = !lifecycle && (headers.length >= 5 || table.classList.contains('pc-trk-visits'));
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
