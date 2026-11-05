/* Enhance only genuine public archive View links; native links are fallbacks. */
(function () {
  'use strict';
  const selector = '.pc-coa-library__view,.pc-coa-library__preview a[href]';
  let box = null, viewer = null, opener = null;
  const supported = () => !!(window.HTMLDialogElement?.prototype.showModal && window.createProductReportViewer);
  function address(value) {
    try {
      const url = new URL(value,location.href), route = url.searchParams.get('rest_route') || url.pathname;
      let publicFile = /^\/(?:wp-json\/)?peptide\/v1\/report-library\/[1-9]\d*\/file\/?$/.test(route);
      if (window.vcProductReports?.reportLibraryBase) {
        const base = new URL(window.vcProductReports.reportLibraryBase,location.href), prefix = (base.searchParams.get('rest_route') || base.pathname).replace(/\/?$/,'/');
        publicFile = base.origin === location.origin && route.startsWith(prefix) && /^[1-9]\d*\/file\/?$/.test(route.slice(prefix.length));
      }
      return /^https?:$/.test(url.protocol) && url.origin === location.origin && !url.username && !url.password && publicFile && !url.searchParams.has('_wpnonce') && (!url.searchParams.has('download') || url.searchParams.get('download') === '0') ? url.href : '';
    } catch (_) { return ''; }
  }
  function find(url) { return [...document.querySelectorAll(selector)].find(link => address(link.href) === url); }
  function node(tag,className,text) { const el = document.createElement(tag); el.className = className; if (text) el.textContent = text; return el; }
  function close() { if (box?.open) box.close(); }
  function open(value,trigger,restoreScroll) {
    if (!supported()) return false;
    const url = address(value), link = url ? find(url) : null, card = link?.closest('.pc-coa-library__report');
    // History cannot introduce an arbitrary resource or a now absent report.
    if (!card) return false;
    if (box?.open && box.dataset.vcHistoryReportUrl === url) { if (typeof restoreScroll === 'number') box.scrollTop = restoreScroll; return true; }
    close(); box?.remove(); opener = trigger || link;
    box = node('dialog','vc-product-report-dialog vc-product-child-dialog');
    box.dataset.vcHistoryReportOrigin = 'library'; box.dataset.vcHistoryReportUrl = url;
    box.setAttribute('aria-label','Reference COA');
    const title = card.querySelector('.pc-coa-library__details dd')?.textContent?.trim() || card.querySelector('.pc-coa-library__title')?.textContent?.trim() || 'Reference COA';
    const dismiss = node('button','vc-product-child-close'); dismiss.type = 'button'; dismiss.setAttribute('aria-label','Close reference COA');
    dismiss.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    dismiss.addEventListener('click',close);
    const pdf = node('div','vc-product-pdf');
    pdf.innerHTML = '<div class="pdf-toolbar"><p class="pdf-status" role="status" aria-live="polite"></p><div class="pdf-zoom"><button type="button" data-pdf-zoom="out" aria-label="Zoom out">−</button><span class="pdf-zoom-value">100%</span><button type="button" data-pdf-zoom="in" aria-label="Zoom in">+</button></div></div><div class="pdf-pages"></div><button class="pdf-retry" type="button" hidden>Retry</button>';
    box.append(dismiss,node('h2','',title),node('p','vc-dialog-reference-note','Results apply to the sample named in the report. Not a verified VialCove stock batch certificate.'),pdf);
    const download = card.querySelector('.pc-coa-library__download');
    if (download) { const action = node('a','vc-dialog-pdf-download','Download reference PDF'); action.href = download.href; box.append(action); }
    const own = box, ownOpener = opener;
    let owner = null;
    own.addEventListener('click',event => { if (event.target === own) own.close(); });
    own.addEventListener('close',() => { owner?.dispose(); if (viewer === owner) viewer = null; if (ownOpener?.isConnected && (!box || box === own || !box.open)) ownOpener.focus({preventScroll:true}); });
    document.body.append(own);
    try { own.showModal(); } catch (_) { own.remove(); box = null; return false; }
    owner = window.createProductReportViewer({element:pdf}); viewer = owner;
    Promise.resolve(owner.open({pdf:url,title})).then(() => {
      // Geometry must exist before assigning scroll; assigning it at the first
      // frame would clamp it to zero while a slow PDF is still loading.
      if (own === box && own.open && typeof restoreScroll === 'number') own.scrollTop = restoreScroll;
    });
    return true;
  }
  document.addEventListener('click',event => {
    const link = event.target.closest?.(selector);
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (open(link.href,link)) event.preventDefault();
  });
  // A persisted page restores its layer through the shared history controller;
  // do not leave an open native dialog around an already disposed PDF owner.
  window.addEventListener('pagehide',close);
  window.VialCoveReportLibraryViewer = Object.freeze({open,close});
})();
