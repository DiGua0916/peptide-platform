/* 付款链接（2026-11-10）：只在店主开启了分享模式的浏览器里显示“复制付款链接”按钮。
   按钮读当前购物车（Store API），拼成 /?pc_cart=ID x 数量,…&pc_coupon=…，复制给客户；客户打开就是同样的购物车并直接去结账。 */
(function () {
	var cfg = window.pcCartShare;
	if ( ! cfg || document.cookie.indexOf( cfg.cookie + '=1' ) < 0 ) { return; }
	var css = '.pc-share{position:fixed;left:max(16px,env(safe-area-inset-left));bottom:max(16px,env(safe-area-inset-bottom));z-index:99999;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif}' +
		'.pc-share__btn{display:inline-flex;align-items:center;gap:8px;padding:12px 16px;border:0;border-radius:999px;background:#111;color:#fff;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.25);cursor:pointer}' +
		'.pc-share__panel{position:absolute;left:0;bottom:56px;width:min(340px,calc(100vw - 32px));padding:14px;border-radius:12px;background:#fff;color:#111;box-shadow:0 12px 32px rgba(0,0,0,.2)}' +
		'.pc-share__panel p{margin:0 0 8px}.pc-share__panel small{color:#666}.pc-share__panel textarea{width:100%;box-sizing:border-box;min-height:72px;font:12px/1.4 ui-monospace,Menlo,monospace;padding:8px;border:1px solid #ddd;border-radius:8px;resize:none}' +
		'.pc-share__row{display:flex;gap:8px;margin-top:8px}.pc-share__row button{flex:1;padding:8px;border:1px solid #ddd;border-radius:8px;background:#fff;cursor:pointer}.pc-share__row button.is-main{background:#111;color:#fff;border-color:#111}';
	var style = document.createElement( 'style' ); style.textContent = css; document.head.appendChild( style );
	var wrap = document.createElement( 'div' ); wrap.className = 'pc-share';
	wrap.innerHTML = '<div class="pc-share__panel" hidden></div><button type="button" class="pc-share__btn">🔗 复制付款链接</button>';
	document.body.appendChild( wrap );
	var panel = wrap.querySelector( '.pc-share__panel' ), btn = wrap.querySelector( '.pc-share__btn' );
	function esc( s ) { return String( s ).replace( /[&<>"]/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ]; } ); }
	function copy( text, done ) {
		var ok = function () { done( true ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( text ).then( ok, function () { done( false ); } ); } else { done( false ); }
	}
	btn.addEventListener( 'click', function () {
		if ( ! panel.hidden ) { panel.hidden = true; return; }
		panel.hidden = false; panel.innerHTML = '<p>正在读取购物车…</p>';
		fetch( cfg.cart, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( cart ) {
			var items = ( cart.items || [] ).map( function ( i ) { return i.id + 'x' + i.quantity; } );
			if ( ! items.length ) { panel.innerHTML = '<p>购物车是空的。先把要给客户的商品加进购物车，再点这里。</p>'; return; }
			var coupons = ( cart.coupons || [] ).map( function ( c ) { return c.code; } );
			var url = cfg.base + ( cfg.base.indexOf( '?' ) < 0 ? '?' : '&' ) + 'pc_cart=' + encodeURIComponent( items.join( ',' ) ) + ( coupons.length ? '&pc_coupon=' + encodeURIComponent( coupons.join( ',' ) ) : '' );
			var total = cart.totals ? ( Number( cart.totals.total_items ) / Math.pow( 10, cart.totals.currency_minor_unit || 2 ) ) : 0;
			panel.innerHTML = '<p><b>付款链接</b> <small>' + esc( ( cart.items_count || items.length ) + ' 件商品' + ( total ? '，商品金额 ' + ( cart.totals.currency_prefix || '$' ) + total.toFixed( 2 ) : '' ) + ( coupons.length ? '，含优惠码 ' + coupons.join( ', ' ) : '' ) ) + '</small></p><textarea readonly>' + esc( url ) + '</textarea><p><small>客户打开这个链接，会直接看到这些商品并进入结账；价格和库存以打开时为准。</small></p><div class="pc-share__row"><button type="button" class="is-main" data-copy>复制链接</button><button type="button" data-open>自己打开试试</button></div>';
			var ta = panel.querySelector( 'textarea' );
			panel.querySelector( '[data-copy]' ).addEventListener( 'click', function ( e ) {
				var b = e.currentTarget;
				copy( url, function ( ok ) { if ( ! ok ) { ta.focus(); ta.select(); } b.textContent = ok ? '已复制 ✓' : '已选中，按 Ctrl/⌘+C 复制'; } );
			} );
			panel.querySelector( '[data-open]' ).addEventListener( 'click', function () { window.open( url, '_blank', 'noopener' ); } );
		} ).catch( function () { panel.innerHTML = '<p>读取购物车失败，请刷新页面再试。</p>'; } );
	} );
})();
