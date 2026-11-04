/* Persistent chrome; every content page keeps its own Window and native lifecycle. */
(function (factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (typeof document !== 'undefined') api.start();
}(function () {
  'use strict';
  const pages = new Set(['pc-overview','pc-data','pc-data-operations','pc-data-products','pc-data-pages','pc-data-page-conversion','pc-data-apps','pc-data-landing','pc-data-sources','pc-data-dimensions','pc-data-conversion','pc-data-emails','pc-data-experiments','pc-visitors','pc-visitors-stats','pc-store-design','pc-promotions','pc-shipping','pc-media','pc-apps','wc-orders']);
  function canonical(value, base) {
    try {
      const url = new URL(value, base), origin = new URL(base);
      if (url.origin !== origin.origin || url.username || url.password || url.hash) return null;
      if (!url.pathname.endsWith('/wp-admin/admin.php') && !url.pathname.endsWith('/wp-admin/edit.php')) return null;
      const seen = new Set();
      for (const [key] of url.searchParams) {
        if (seen.has(key) || !['page','post_type','status','paged','s','range','from','to','compare_from','compare_to','pc_embed'].includes(key)) return null;
        seen.add(key);
      }
      if (url.pathname.endsWith('/edit.php')) {
        if (!['product','post'].includes(url.searchParams.get('post_type') || 'post') || url.searchParams.has('page')) return null;
      } else if (!pages.has(url.searchParams.get('page'))) return null;
      if (url.searchParams.has('post') || url.searchParams.get('status') === 'trash') return null;
      url.searchParams.delete('pc_embed');
      return url;
    } catch (_) { return null; }
  }
  function signature(form) {
    return JSON.stringify(Array.from(form.elements || []).filter(f => !f.disabled && !f.readOnly && !/^(hidden|submit|button|reset|image)$/.test(f.type || '') && f.tagName !== 'BUTTON').map(f => [f.name, f.type, /checkbox|radio/.test(f.type) ? f.checked : f.type === 'file' ? Array.from(f.files || []).map(v => [v.name,v.size,v.lastModified]) : f.multiple ? Array.from(f.selectedOptions).map(o => o.value) : f.value]));
  }
  function start() {
    const host = document.getElementById('pc-workspace-shell');
    if (!host || window.parent !== window) return;
    const status = host.querySelector('.pc-shell-status');
    let active = null, pending = null, timer = 0, generation = 0;
    let activeURL = location.href;
    let snapshots = new Map();
    let edited = false;
    const base = location.href;
    function dirty() {
      if (!active) return false;
      if (edited) return true;
      try {
        for (const [form,value] of snapshots) if (form.isConnected && signature(form) !== value) return true;
        return !!active.contentDocument.querySelector('[data-unsaved="true"],.is-dirty');
      } catch (_) { return true; }
    }
    function clearPending() {
      clearTimeout(timer);
      if (pending) pending.remove();
      pending = null;
      if (active) active.inert = false;
      host.classList.remove('is-loading');
      status.hidden = true;
    }
    function sync(url, doc) {
      document.title = doc.title;
      const heading = doc.querySelector('.pc-workspace-header__context strong');
      const current = document.querySelector('.pc-workspace-header__context strong');
      if (heading && current) current.textContent = heading.textContent;
      document.querySelectorAll('#adminmenu li').forEach(li => li.classList.remove('current','wp-has-current-submenu','wp-menu-open'));
      document.querySelectorAll('#adminmenu a[href]').forEach(a => {
        a.classList.remove('wp-has-current-submenu');
        a.removeAttribute('aria-current');
        const item = canonical(a.href, base);
        if (!item || item.pathname !== url.pathname || item.search !== url.search) return;
        a.closest('li')?.classList.add('current');
        a.setAttribute('aria-current','page');
        const parent = a.closest('#adminmenu > li');
        if (parent) { parent.classList.add('wp-has-current-submenu','wp-menu-open'); parent.classList.remove('wp-not-current-submenu'); }
      });
    }
    function watch(frame) {
      const doc = frame.contentDocument;
      edited = false;
      snapshots = new Map();
      doc.querySelectorAll('form').forEach(form => {
        if ((form.method || 'get').toLowerCase() !== 'get') snapshots.set(form,signature(form));
      });
      const changed = event => {
        const form = event.target.closest && event.target.closest('form');
        if (form && (form.method || 'get').toLowerCase() !== 'get' && !snapshots.has(form)) edited = true;
      };
      doc.addEventListener('input', changed);
      doc.addEventListener('change', changed);
      doc.addEventListener('submit', event => {
        const form = event.target;
        const method = event.submitter?.getAttribute('formmethod') || form.method || 'get';
        if (method.toLowerCase() !== 'get' && !form.target && !event.submitter?.getAttribute('formtarget')) form.target = '_top';
      });
      doc.addEventListener('click', event => {
        const a = event.target.closest && event.target.closest('a[href]');
        if (!a || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || a.target && a.target !== '_self' || a.hasAttribute('download')) return;
        const url = canonical(a.href, base);
        if (!url) {
          // Complex editors/actions remain ordinary document navigations, including
          // WordPress's own beforeunload protection and native security checks.
          if (new URL(a.href,base).origin === location.origin && !a.hash) { event.preventDefault(); location.assign(a.href); }
          return;
        }
        event.preventDefault();
        visit(url, true);
      });
    }
    let visitIntent = 0;
    async function visit(url, push) {
      const intent = ++visitIntent;
      if (dirty() && !await window.VialCoveDialogs.confirm('当前页面有未保存的内容，确定离开吗？')) return false;
      if (intent !== visitIntent) return false;
      clearPending();
      if (active) active.inert = true;
      const id = ++generation;
      const frame = document.createElement('iframe');
      frame.title = '店铺后台内容';
      frame.classList.add('pc-shell-preparing');
      frame.setAttribute('aria-hidden','true');
      frame.setAttribute('referrerpolicy','same-origin');
      const embedded = new URL(url.href); embedded.searchParams.set('pc_embed','1');
      status.textContent = '正在打开页面…'; status.hidden = false;
      host.classList.add('is-loading');
      pending = frame;
      frame.addEventListener('load', () => {
        if (id !== generation || pending !== frame) return;
        try {
          const doc = frame.contentDocument;
          const final = canonical(frame.contentWindow.location.href, base);
          if (!final || !doc.getElementById('wpbody-content') || doc.getElementById('loginform')) throw new Error('native');
          clearTimeout(timer);
          if (active) active.remove(); // Cancels old document timers, requests and listeners.
          active = frame; pending = null;
          frame.classList.remove('pc-shell-preparing'); frame.removeAttribute('aria-hidden'); status.hidden = true; host.classList.remove('is-loading');
          if (typeof frame.animate === 'function' && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            frame.animate([{opacity:.88,transform:'translateY(8px)'},{opacity:1,transform:'none'}],{duration:200,easing:'cubic-bezier(.22,1,.36,1)'});
          }
          activeURL = final.href;
          watch(frame); sync(final,doc);
          if (push) history.pushState({pcShell:true},'',final.href);
          else history.replaceState({pcShell:true},'',final.href);
          frame.focus({preventScroll:true});
        } catch (_) { clearPending(); location.assign(url.href + (url.search ? '&' : '?') + 'pc_embed=1'); }
      });
      frame.src = embedded.href;
      host.append(frame);
      timer = setTimeout(() => {
        if (pending !== frame) return;
        clearPending(); status.textContent='页面暂时没有响应，原页面仍保留，请重试。'; status.hidden=false;
      },20000);
      return true;
    }
    document.addEventListener('click', event => {
      const a = event.target.closest && event.target.closest('#adminmenu a[href],.pc-workspace-brand,.pc-workspace-header__context a');
      if (!a || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || a.target && a.target !== '_self') return;
      const url = canonical(a.href,base);
      if (!url) return;
      event.preventDefault();
      visit(url,true);
    });
    // Parent history stores canonical routes only. No HTML or account data is cached.
    window.addEventListener('popstate', async () => {
      const url = canonical(location.href,base);
      if (!url) { location.assign(location.href); return; }
      if (!await visit(url,false)) history.pushState({pcShell:true},'',activeURL);
    });
    const initial = canonical(location.href,base);
    if (initial) visit(initial,false);
  }
  return {canonical,signature,start};
}));
