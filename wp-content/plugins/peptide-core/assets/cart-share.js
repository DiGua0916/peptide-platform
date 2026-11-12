/* 付款链接（2026-11-10 店主：不要按钮，购物车页的网址就是付款链接）：
   在购物车页，地址栏自动变成 /cart/?pc_cart=ID x 数量,…（有优惠码再加 &pc_coupon=…），改数量 / 删商品会跟着变。
   直接复制地址栏发给客户，客户打开就是同样的商品并进入结账。页面上不显示任何东西。 */
(function () {
	var cfg = window.pcCartShare;
	if ( ! cfg || ! window.history || ! history.replaceState ) { return; }
	var last = '';
	function apply( cart ) {
		if ( ! cart ) { return; }
		var items = ( cart.items || [] ).map( function ( i ) { return i.id + 'x' + i.quantity; } );
		var coupons = ( cart.coupons || [] ).map( function ( c ) { return c.code; } );
		var url = new URL( location.href );
		url.searchParams.delete( 'pc_cart' );
		url.searchParams.delete( 'pc_coupon' );
		// 手动订单链接来的购物车：地址栏放手动订单链接（刷新、复制都还是店主定的价格），不放普通付款链接。
		if ( cfg.manual && items.length ) {
			url.searchParams.set( 'pc_manual', cfg.manual );
		} else if ( items.length ) {
			url.searchParams.set( 'pc_cart', items.join( ',' ) );
			if ( coupons.length ) { url.searchParams.set( 'pc_coupon', coupons.join( ',' ) ); }
		}
		var next = url.pathname + ( url.search ? url.search.replace( /%2C/gi, ',' ) : '' ) + url.hash;
		if ( next !== last ) { last = next; history.replaceState( history.state, '', next ); }
	}
	var data = window.wp && window.wp.data;
	if ( data && data.select && data.subscribe ) {
		var timer = 0;
		data.subscribe( function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				var s = data.select( 'wc/store/cart' );
				if ( s && s.getCartData && s.hasFinishedResolution && s.hasFinishedResolution( 'getCartData' ) ) { apply( s.getCartData() ); }
			}, 150 );
		} );
	}
	fetch( cfg.cart, { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( apply ).catch( function () {} );
})();
