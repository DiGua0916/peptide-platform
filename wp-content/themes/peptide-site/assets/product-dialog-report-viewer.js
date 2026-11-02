'use strict';
// Render the original PDF in the page; no dependency on an embedded browser PDF plugin.
window.createProductReportViewer = function ({ element }) {
  let modulePromise;
  let generation = 0;
  let loadingTask = null;
  let renderTask = null;
  let current = null;
  const pages = element.querySelector('.pdf-pages');
  const status = element.querySelector('.pdf-status');
  const retry = element.querySelector('.pdf-retry');
  const zoom = element.querySelector('.pdf-zoom-value');
  let scale = 100;

  function setZoom(value) {
    scale = Math.max(100, Math.min(200, value));
    pages.style.setProperty('--pdf-scale', scale + '%');
    zoom.textContent = scale + '%';
    element.querySelector('[data-pdf-zoom="out"]').disabled = scale === 100;
    element.querySelector('[data-pdf-zoom="in"]').disabled = scale === 200;
  }
  element.querySelector('[data-pdf-zoom="out"]').addEventListener('click', () => setZoom(scale - 50));
  element.querySelector('[data-pdf-zoom="in"]').addEventListener('click', () => setZoom(scale + 50));
  retry.addEventListener('click', () => { if (current) open(current); });

  function sourceURL(value) {
    const url = new URL(value, location.href);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== location.origin || url.username || url.password) {
      throw new Error('Unsupported document address.');
    }
    return url.href;
  }
  function dispose() {
    generation += 1;
    renderTask?.cancel();
    renderTask = null;
    const task = loadingTask;
    loadingTask = null;
    if (task) Promise.resolve(task.destroy()).catch(() => {});
    pages.replaceChildren();
    element.removeAttribute('aria-busy');
    element.dataset.state = 'idle';
    element.removeAttribute('data-page-count');
    current = null;
  }
  async function library() {
    const base = new URL('product-dialog-pdf/', window.vcHomeQuickView.assetBase);
    if (!modulePromise) modulePromise = import(new URL('pdf.mjs', base).href).then(pdf => {
      pdf.GlobalWorkerOptions.workerSrc = new URL('pdf.worker.mjs', base).href;
      return pdf;
    }).catch(error => { modulePromise = null; throw error; });
    return modulePromise;
  }
  async function open(payload) {
    dispose();
    current = payload;
    const run = generation;
    setZoom(100);
    element.dataset.state = 'loading';
    element.setAttribute('aria-busy', 'true');
    status.textContent = 'Loading document…';
    retry.hidden = true;
    let timer;
    try {
      const pdfjs = await library();
      if (run !== generation) return;
      const base = new URL('product-dialog-pdf/', window.vcHomeQuickView.assetBase).href;
      const task = pdfjs.getDocument({ url: sourceURL(payload.pdf),
        withCredentials: true, isEvalSupported: false, enableXfa: false,
        httpHeaders: window.vcHomeQuickView.wpNonce ? { 'X-WP-Nonce': window.vcHomeQuickView.wpNonce } : {},
        cMapUrl: base + 'cmaps/', cMapPacked: true,
        standardFontDataUrl: base + 'standard_fonts/', wasmUrl: base + 'wasm/' });
      loadingTask = task;
      timer = setTimeout(() => {
        if (run !== generation) return;
        status.textContent = 'The document could not be loaded. Try again or download the PDF below.';
        element.dataset.state = 'error';
        element.removeAttribute('aria-busy');
        retry.hidden = false;
        task.destroy().catch(() => {});
      }, 30000);
      const pdf = await task.promise;
      clearTimeout(timer);
      if (run !== generation) return;
      element.dataset.pageCount = String(pdf.numPages);
      // Sequential rendering keeps memory predictable on phones and preserves every page.
      for (let number = 1; number <= pdf.numPages; number += 1) {
        const page = await pdf.getPage(number);
        if (run !== generation) return;
        const viewport = page.getViewport({ scale: 1 });
        const width = Math.min(1800, Math.max(900, element.clientWidth * 2));
        const output = page.getViewport({ scale: width / viewport.width });
        const figure = document.createElement('figure');
        figure.className = 'pdf-page';
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(output.width);
        canvas.height = Math.ceil(output.height);
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', String(payload.title || 'Report') + ', page ' + number + ' of ' + pdf.numPages);
        const caption = document.createElement('figcaption');
        caption.textContent = 'Page ' + number + ' of ' + pdf.numPages;
        figure.append(canvas, caption);
        // Detached until painted: never expose an empty canvas as a finished report.
        renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport: output, background: '#ffffff' });
        await renderTask.promise;
        renderTask = null;
        if (run !== generation) return;
        pages.append(figure);
        page.cleanup();
        status.textContent = number < pdf.numPages ? 'Loading page ' + (number + 1) + ' of ' + pdf.numPages + '…'
          : pdf.numPages + (pdf.numPages === 1 ? ' page' : ' pages');
      }
      element.dataset.state = 'ready';
      element.removeAttribute('aria-busy');
    } catch (error) {
      clearTimeout(timer);
      if (run !== generation) return;
      element.dataset.state = 'error';
      element.removeAttribute('aria-busy');
      status.textContent = pages.children.length
        ? 'Some pages could not be loaded. Try again or download the full PDF below.'
        : 'The document could not be loaded. Try again or download the PDF below.';
      retry.hidden = false;
      console.warn('Report viewer:', error?.message || error);
    }
  }
  return { open, dispose };
};
