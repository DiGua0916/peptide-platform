/* Progressive feedback only: native GET filters, private report URLs and PDF downloads remain authoritative. */
(function () {
    'use strict';
    const boundForms = new WeakSet();
    const boundImages = new WeakSet();
    function resetPending(form) {
        form.removeAttribute('aria-busy');
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = false;
        const status = form.querySelector('[data-pc-coa-pending]');
        if (status) status.textContent = '';
    }
    function markImageFailure(image) {
        const preview = image.closest('[data-pc-coa-preview]');
        const fallback = preview?.querySelector('[data-pc-coa-fallback]');
        if (!fallback) return;
        image.hidden = true;
        fallback.hidden = false;
    }
    function initialize(doc, view) {
        const libraries = Array.from(doc.querySelectorAll('[data-pc-coa-library]'));
        libraries.forEach(library => {
            library.querySelectorAll('[data-pc-coa-form]').forEach(form => {
                if (boundForms.has(form)) return;
                boundForms.add(form);
                form.addEventListener('submit', event => {
                    if (event.defaultPrevented || typeof form.checkValidity === 'function' && !form.checkValidity()) return;
                    form.setAttribute('aria-busy', 'true');
                    const button = form.querySelector('button[type="submit"]');
                    if (button) button.disabled = true;
                    const status = form.querySelector('[data-pc-coa-pending]');
                    if (status) status.textContent = status.getAttribute('data-message') || '';
                    // No preventDefault/fetch/router: the browser submits the original GET form.
                });
            });
            library.querySelectorAll('[data-pc-coa-image]').forEach(image => {
                if (boundImages.has(image)) return;
                boundImages.add(image);
                image.addEventListener('error', () => markImageFailure(image), {once: true});
                if (image.complete && image.currentSrc && image.naturalWidth === 0) markImageFailure(image);
            });
        });
        return () => libraries.forEach(library => library.querySelectorAll('[data-pc-coa-form]').forEach(resetPending));
    }
    if (typeof module === 'object' && module.exports) module.exports = {initialize, resetPending, markImageFailure};
    if (typeof window !== 'object' || typeof document !== 'object') return;
    const ready = () => {
        const reset = initialize(document, window);
        window.addEventListener('pageshow', reset);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, {once: true});
    else ready();
}());
