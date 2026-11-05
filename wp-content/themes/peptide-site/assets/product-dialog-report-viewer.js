/* Render the original PDF; the fit width is a CSS size, never its raster width. */
(function () {
  'use strict';
  let modulePromise;
  const settings = () => window.vcProductReports || window.vcHomeQuickView || {};
  async function library() {
    const base = new URL('product-dialog-pdf/', settings().assetBase);
    if (!modulePromise) modulePromise = (typeof Promise.withResolvers === 'function' ? Promise.resolve() : import(new URL('../product-report-pdf-compat.mjs',base).href))
    .then(() => import(new URL('pdf.mjs', base).href)).then(pdf => {
      // Workers have a separate global. Their bootstrap installs the same
      // feature shim before evaluating the unchanged upstream worker module.
      pdf.GlobalWorkerOptions.workerSrc = new URL('../product-report-pdf-worker.mjs', base).href;
      return pdf;
    }).catch(error => { modulePromise = null; throw error; });
    return modulePromise;
  }
  function sourceURL(value) {
    const url = new URL(value, location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) throw new Error('Unsupported document address.');
    return url;
  }
  // Public archive reads must not inherit a stale REST nonce. Protected batch
  // files retain their caller's nonce and the original, complete resource URL.
  function headers(url) {
    const route = url.searchParams.get('rest_route') || url.pathname;
    let publicFile = /^\/(?:wp-json\/)?peptide\/v1\/report-library\/[1-9]\d*\/file\/?$/.test(route);
    if (settings().reportLibraryBase) {
      const base = sourceURL(settings().reportLibraryBase), prefix = (base.searchParams.get('rest_route') || base.pathname).replace(/\/?$/,'/');
      publicFile = route.startsWith(prefix) && /^[1-9]\d*\/file\/?$/.test(route.slice(prefix.length));
    }
    return !publicFile && settings().wpNonce ? {'X-WP-Nonce':settings().wpNonce} : {};
  }
  window.createProductReportViewer = function ({element}) {
    const pages = element.querySelector('.pdf-pages'), status = element.querySelector('.pdf-status');
    const retry = element.querySelector('.pdf-retry'), zoom = element.querySelector('.pdf-zoom-value');
    const zoomOut = element.querySelector('[data-pdf-zoom="out"]'), zoomIn = element.querySelector('[data-pdf-zoom="in"]');
    let generation = 0, loadingTask = null, renderTask = null, workingCanvas = null, pdfDocument = null, current = null;
    let scale = 100, fitWidth = 0, revision = 0, painting = false, timer = 0, hardTimer = 0, frame = 0, observing = false, firstPainted = false;
    const figures = [], wanted = new Set([1]), visiblePages = new Set(), observedPages = new Set(), stages = new Map();
    function evict(figure) {
      const canvas = figure.querySelector('canvas'); if (canvas) { canvas.remove(); releaseCanvas(canvas); }
      const placeholder = figure.querySelector('.pdf-page-placeholder'); if (placeholder) placeholder.hidden = false;
      delete figure.dataset.rasterKey;
    }
    const pageObserver = window.IntersectionObserver ? new IntersectionObserver(entries => {
      if (!pdfDocument || !current) return;
      let changed = false;
      for (const entry of entries) {
        const number = Number(entry.target.dataset.page);
        if (figures[number - 1] !== entry.target) continue;
        observedPages.add(number); entry.isIntersecting ? visiblePages.add(number) : visiblePages.delete(number);
        if (entry.isIntersecting && !wanted.has(number)) { wanted.add(number); changed = true; }
        else if (!firstPainted && number === 1) continue;
        else if (!entry.isIntersecting && wanted.delete(number)) { evict(entry.target); changed = true; }
      }
      if (!firstPainted && visiblePages.size && !visiblePages.has(1) && wanted.delete(1)) { if (figures[0]) evict(figures[0]); changed = true; }
      if (changed) requestPaint();
    },{root:element.closest('dialog'),rootMargin:'100% 0px'}) : null;
    function clearTimer() { clearTimeout(timer); clearTimeout(hardTimer); timer = hardTimer = 0; }
    function stopStages() { for (const [id,reject] of stages) { clearTimeout(id); reject(new Error('Document stage cancelled.')); } stages.clear(); }
    function terminate(run, deadline = '') {
      if (run !== generation) return;
      if (deadline) window.console?.warn?.('Reference PDF deadline exceeded:', deadline);
      generation++; revision++; clearTimer(); stopStages(); cancelRender(); pdfDocument = null;
      const task = loadingTask; loadingTask = null; fail();
      if (task) Promise.resolve(task.destroy()).catch(() => {});
    }
    function stage(promise,run) {
      let id;
      return new Promise((resolve,reject) => {
        id = setTimeout(() => { stages.delete(id); terminate(run,'page processing'); reject(new Error('Document stage timed out.')); },30000);
        stages.set(id,reject); Promise.resolve(promise).then(resolve,reject);
      }).finally(() => { clearTimeout(id); stages.delete(id); });
    }
    function cancelRender() { const task = renderTask; renderTask = null; if (task) task.cancel(); releaseCanvas(workingCanvas); workingCanvas = null; }
    function releaseCanvas(canvas) { if (canvas) { canvas.width = 0; canvas.height = 0; } }
    function clearPages() { pages.querySelectorAll('canvas').forEach(releaseCanvas); pages.replaceChildren(); figures.length = 0; }
    function fail() {
      clearTimer(); element.dataset.state = 'error'; element.removeAttribute('aria-busy');
      status.textContent = pages.querySelector('canvas') ? 'Some pages could not be loaded. Try again or download the full PDF below.' : 'The document could not be loaded. Try again or download the PDF below.';
      retry.hidden = false;
    }
    function reset() {
      generation++; revision++; clearTimer(); stopStages(); cancelAnimationFrame(frame); frame = 0; cancelRender();
      const task = loadingTask; loadingTask = null; pdfDocument = null;
      if (task) Promise.resolve(task.destroy()).catch(() => {});
      pageObserver?.disconnect(); wanted.clear(); wanted.add(1); visiblePages.clear(); observedPages.clear(); firstPainted = false;
      clearPages(); element.removeAttribute('aria-busy'); element.dataset.state = 'idle'; element.removeAttribute('data-page-count'); current = null;
    }
    function measure() {
      const css = getComputedStyle(pages);
      // clientWidth rounds CSS pixels to an integer. The smaller real rectangle
      // prevents subpixel outward rounding from creating a scrollbar at fit.
      const rectWidth = pages.getBoundingClientRect?.().width;
      const width = Number.isFinite(rectWidth) && rectWidth > 0 ? Math.min(pages.clientWidth, rectWidth) : pages.clientWidth;
      return Math.max(0, Math.floor(width - (parseFloat(css.paddingLeft) || 0) - (parseFloat(css.paddingRight) || 0)));
    }
    async function paint() {
      if (painting || !pdfDocument || !fitWidth) return;
      painting = true;
      const ownerGeneration = generation;
      try {
        let done = -1;
        while (pdfDocument && current && ownerGeneration === generation && done !== revision) {
          const pdf = pdfDocument, run = generation, target = revision;
          const width = fitWidth * scale / 100, rasterKey = fitWidth + ':' + scale;
          for (let number = 1; number <= pdf.numPages; number++) {
            if (run !== generation || target !== revision) break;
            const figure = figures[number - 1];
            if (!figure || (pageObserver && !wanted.has(number)) || figure.dataset.rasterKey === rasterKey) continue;
            let page, canvas;
            try {
              page = await stage(pdf.getPage(number),run);
              if (run !== generation || target !== revision) break;
              const natural = page.getViewport({scale:1});
              figure.querySelector('.pdf-page-placeholder').style.aspectRatio = natural.width + ' / ' + natural.height;
              // Visible pages receive their own 4MP budget. Browsers without
              // IntersectionObserver retain a bounded 12MP document fallback.
              // Keep DPR <= 2 and either raster edge <= 8192px.
              const desired = width / natural.width * Math.min(2, Math.max(1, window.devicePixelRatio || 1));
              const budget = pageObserver ? 4000000 : Math.min(4000000,12000000 / Math.max(1,pdf.numPages));
              const raster = Math.min(desired, 8192 / natural.width, 8192 / natural.height, Math.sqrt(budget / (natural.width * natural.height)));
              const viewport = page.getViewport({scale:raster});
              canvas = document.createElement('canvas'); canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
              canvas.setAttribute('role','img'); canvas.setAttribute('aria-label', String(current.title || 'Report') + ', page ' + number + ' of ' + pdf.numPages);
              workingCanvas = canvas;
              const task = page.render({canvasContext:canvas.getContext('2d'),viewport,background:'#ffffff'}); renderTask = task;
              try { await stage(task.promise,run); } finally { if (renderTask === task) renderTask = null; if (workingCanvas === canvas) workingCanvas = null; }
              if (run !== generation || target !== revision || (pageObserver && !wanted.has(number))) { releaseCanvas(canvas); break; }
              const old = figure.querySelector('canvas'); if (old) { old.remove(); releaseCanvas(old); }
              figure.style.width = width + 'px'; figure.prepend(canvas); figure.dataset.rasterKey = rasterKey;
              figure.querySelector('.pdf-page-placeholder').hidden = true;
              firstPainted = true; clearTimer();
              if (pageObserver && observedPages.has(number) && !visiblePages.has(number)) { wanted.delete(number); evict(figure); }
            } catch (error) {
              releaseCanvas(canvas);
              if (run === generation && target === revision) {
                // Preserve a bounded diagnostic at the actual failing boundary;
                // URLs and document text are not needed to investigate rendering.
                const name = String(error?.name || 'Error').slice(0,80);
                const message = String(error?.message || 'Unknown rendering failure').replace(/https?:\/\/\S+/gi,'[resource]').slice(0,240);
                window.console?.warn?.('Reference PDF rendering failed:', name, message);
                terminate(run); return;
              }
            } finally { page?.cleanup(); }
          }
          if (run === generation && target === revision) {
            done = target;
            // Zoom can queue a frame before the first getPage resolves. An
            // empty geometry pass is not a usable document or a finished load.
            if (firstPainted) {
              element.dataset.state = 'ready'; element.removeAttribute('aria-busy');
              status.textContent = pdf.numPages + (pdf.numPages === 1 ? ' page' : ' pages');
            }
          }
        }
      } finally {
        painting = false;
        // A close/reopen may have used the scheduled frame while the outgoing
        // task was still settling. The new document needs its own paint owner.
        if (pdfDocument && current && ownerGeneration !== generation) requestPaint();
      }
    }
    async function prepare(pdf,run) {
      // Only resolve the first page here. Reading every page's geometry can
      // trigger remote PDF ranges before the reader ever scrolls to them.
      // Other placeholders start with the first ratio; paint resolves their
      // actual portrait/landscape ratio when they enter the viewport budget.
      const page = await stage(pdf.getPage(1),run);
      try {
        if (run !== generation) return;
        const natural = page.getViewport({scale:1});
        for (let number = 1; number <= pdf.numPages; number++) {
          const figure = document.createElement('figure');
          figure.className = 'pdf-page'; figure.dataset.page = String(number); figure.style.width = fitWidth * scale / 100 + 'px';
          const placeholder = document.createElement('div'); placeholder.className = 'pdf-page-placeholder'; placeholder.style.aspectRatio = natural.width + ' / ' + natural.height; placeholder.textContent = 'Page ' + number;
          const caption = document.createElement('figcaption'); caption.textContent = 'Page ' + number + ' of ' + pdf.numPages;
          figure.append(placeholder,caption); figures[number-1] = figure; pages.append(figure); pageObserver?.observe(figure);
        }
        requestPaint();
      } finally { page.cleanup(); }
    }
    function requestPaint() {
      revision++; cancelRender();
      if (!frame) frame = requestAnimationFrame(() => { frame = 0; paint(); });
    }
    function resize() {
      if (!current) return;
      const width = measure(); if (!width || width === fitWidth) return;
      fitWidth = width;
      // Reflow immediately, retaining already painted pages until replaced.
      figures.forEach(figure => { figure.style.width = fitWidth * scale / 100 + 'px'; });
      requestPaint();
    }
    const observer = window.ResizeObserver ? new ResizeObserver(resize) : null;
    function observe() {
      if (observing) return; observing = true; observer?.observe(pages); window.addEventListener('resize',resize); window.addEventListener('pagehide',dispose);
    }
    function setZoom(value) {
      scale = Math.max(100,Math.min(200,value)); zoom.textContent = scale + '%'; zoom.title = '100% fits the available width';
      zoomOut.disabled = scale === 100; zoomIn.disabled = scale === 200;
      figures.forEach(figure => { figure.style.width = fitWidth * scale / 100 + 'px'; });
      if (scale === 100) pages.scrollLeft = 0;
      requestPaint();
    }
    const out = () => setZoom(scale - 50), inside = () => setZoom(scale + 50), again = () => { if (current) open(current); };
    zoomOut.addEventListener('click',out); zoomIn.addEventListener('click',inside); retry.addEventListener('click',again);
    function dispose() {
      reset(); observer?.disconnect(); observing = false; window.removeEventListener('resize',resize); window.removeEventListener('pagehide',dispose);
    }
    async function open(payload) {
      reset(); current = payload; const run = generation; observe(); fitWidth = measure(); setZoom(100);
      element.dataset.state = 'loading'; element.setAttribute('aria-busy','true'); status.textContent = 'Loading document…'; retry.hidden = true;
      timer = setTimeout(() => terminate(run,'initial rendering'),30000);
      // A cold large PDF may still be receiving real bytes at thirty seconds.
      // Renew only that inactivity deadline; the absolute ceiling never moves.
      hardTimer = setTimeout(() => terminate(run,'initial hard limit'),120000);
      try {
        const url = sourceURL(payload.pdf), pdfjs = await library(); if (run !== generation) return;
        const base = new URL('product-dialog-pdf/', settings().assetBase).href;
        const task = pdfjs.getDocument({url:url.href,withCredentials:true,isEvalSupported:false,enableXfa:false,rangeChunkSize:524288,disableAutoFetch:true,disableStream:true,httpHeaders:headers(url),cMapUrl:base+'cmaps/',cMapPacked:true,standardFontDataUrl:base+'standard_fonts/',wasmUrl:base+'wasm/'});
        loadingTask = task;
        let received = 0;
        task.onProgress = progress => {
          if (run !== generation || loadingTask !== task || firstPainted) return;
          const loaded = Number(progress?.loaded), total = Number(progress?.total);
          if (!Number.isSafeInteger(loaded) || loaded <= received) return;
          received = loaded; clearTimeout(timer);
          timer = setTimeout(() => terminate(run,'initial inactivity'),30000);
          status.textContent = Number.isSafeInteger(total) && total > 0 && loaded <= total
            ? 'Loading document… ' + Math.floor(loaded * 100 / total) + '% received'
            : 'Loading document… ' + loaded + ' bytes received';
        };
        const pdf = await task.promise; if (run !== generation) return;
        pdfDocument = pdf; element.dataset.pageCount = String(pdf.numPages); await prepare(pdf,run);
      } catch (error) { if (run === generation) terminate(run); }
    }
    return {open,dispose};
  };
})();
