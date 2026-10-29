/* VialCove product editing: progressive navigation over native WooCommerce fields. */
(function () {
  'use strict';

  function start() {
    if (!document.body.classList.contains('pc-admin-product')) return;
    var post = document.getElementById('post');
    var stuff = document.getElementById('poststuff');
    if (!post || !stuff || document.querySelector('.pc-product-sections')) return;

    var chinese = /^zh\b/i.test(document.documentElement.lang || '');
    var text = chinese ? {
      navigation: '商品编辑分区', title: '商品名称', description: '商品详情',
      basics: '基本信息', pricing: '价格与规格', excerpt: '简短描述',
      publishing: '发布设置', image: '商品图片'
    } : {
      navigation: 'Product editing sections', title: 'Product name', description: 'Product description',
      basics: 'Basic information', pricing: 'Pricing and variants', excerpt: 'Short description',
      publishing: 'Publishing', image: 'Product image'
    };

    var title = document.getElementById('titlediv');
    if (title && !title.querySelector('.pc-product-field-label')) {
      var label = document.createElement('span');
      label.className = 'pc-product-field-label';
      label.setAttribute('aria-hidden', 'true');
      label.textContent = text.title;
      title.insertBefore(label, title.firstChild);
    }
    var sections = [
      ['titlediv', text.basics], ['postdivrich', text.description],
      ['woocommerce-product-data', text.pricing], ['postexcerpt', text.excerpt],
      ['submitdiv', text.publishing], ['postimagediv', text.image]
    ];
    var nav = document.createElement('nav');
    nav.className = 'pc-product-sections';
    nav.setAttribute('aria-label', text.navigation);
    var tracked = [];
    sections.forEach(function (section) {
      var target = document.getElementById(section[0]);
      if (!target) return;
      var link = document.createElement('a');
      link.href = '#' + section[0];
      link.textContent = section[1];
      link.addEventListener('click', function (event) {
        event.preventDefault();
        if (target.classList.contains('closed')) {
          var toggle = target.querySelector('.handlediv');
          if (toggle) toggle.click();
        }
        target.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
        var input = section[0] === 'titlediv' ? document.getElementById('title') : null;
        if (input) {
          input.focus({preventScroll: true});
        } else {
          if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
          target.classList.add('pc-product-focus-target');
          target.focus({preventScroll: true});
        }
        tracked.forEach(function (item) {
          if (item.link === link) item.link.setAttribute('aria-current', 'location');
          else item.link.removeAttribute('aria-current');
        });
      });
      tracked.push({target: target, link: link});
      nav.appendChild(link);
    });
    if (tracked.length > 1) stuff.parentNode.insertBefore(nav, stuff);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
