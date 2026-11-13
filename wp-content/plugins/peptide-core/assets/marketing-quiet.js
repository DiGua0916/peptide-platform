/**
 * 营销弹窗“静默”开关（M4，供已购回访 FE-10 等调用）。
 *
 * 静默时：首单券条、离开挽回弹窗、抽奖转盘、活动倒计时不出现；满减、优惠码等价格优惠照常生效。
 * 三种方式任一成立即为静默：
 *   1. 服务端过滤器 pc_marketing_popups_suppressed 返回真（页面里会带 window.pcMarketingQuietServer = true）；
 *   2. 页面脚本调用 window.pcMarketingQuiet.suppress()，或设了 window.pcBuyer.suppressPopups = true；
 *   3. 浏览器里有 pc_marketing_quiet=1 这个 cookie（suppress() 会写，下一页一打开就生效，不闪）。
 *
 * 这段脚本只在静默的请求里输出（Pricing\Popups::print_script），不静默的页面里没有 window.pcMarketingQuiet，
 * 调用前先判断它存在。要在不静默的页面上也带上它：服务端过滤器 pc_marketing_quiet_script 返回 true。
 *
 * window.pcMarketingQuiet：
 *   suppressed()        现在是否静默
 *   suppress({ days })  开始静默；days 不填时只到关闭浏览器为止
 *   release()           取消静默
 *   refresh()           外部改了 pcBuyer.suppressPopups 之后调用，重新套用
 *   onChange(fn)        状态变化时回调 fn(是否静默)；同时在 document 上派发 pc:marketing-quiet 事件
 */
( function ( w, d ) {
	'use strict';
	if ( w.pcMarketingQuiet ) {
		return;
	}
	var COOKIE = 'pc_marketing_quiet';
	var CLASS = 'pc-marketing-quiet';
	/* 只隐藏页面里的条和倒计时；离开挽回 / 转盘是模态弹窗，由 exit-intent.js 自己判断不弹（藏起一个已打开的模态框会让页面点不动）。 */
	var HIDE = '[data-pc-first-order-offer],[data-pc-campaign-countdown]';
	var forced = false;
	var last = null;
	var listeners = [];

	function cookie() {
		return ( ';' + String( d.cookie || '' ).replace( /\s+/g, '' ) + ';' ).indexOf( ';' + COOKIE + '=1;' ) !== -1;
	}
	function suppressed() {
		return forced || true === w.pcMarketingQuietServer || !! ( w.pcBuyer && true === w.pcBuyer.suppressPopups ) || cookie();
	}
	function sync() {
		var on = suppressed();
		if ( d.documentElement && d.documentElement.classList ) {
			d.documentElement.classList.toggle( CLASS, on );
		}
		if ( on !== last ) {
			last = on;
			listeners.forEach( function ( fn ) {
				try {
					fn( on );
				} catch ( e ) {}
			} );
			try {
				d.dispatchEvent( new w.CustomEvent( 'pc:marketing-quiet', { detail: { suppressed: on } } ) );
			} catch ( e ) {}
		}
		return on;
	}
	try {
		var style = d.createElement( 'style' );
		style.textContent = HIDE.split( ',' ).map( function ( s ) {
			return 'html.' + CLASS + ' ' + s;
		} ).join( ',' ) + '{display:none!important}';
		( d.head || d.documentElement ).appendChild( style );
	} catch ( e ) {}

	w.pcMarketingQuiet = {
		suppressed: suppressed,
		suppress: function ( options ) {
			var days = options && Number( options.days );
			forced = true;
			try {
				d.cookie = COOKIE + '=1; path=/; SameSite=Lax' + ( days > 0 ? '; max-age=' + Math.round( days * 86400 ) : '' );
			} catch ( e ) {}
			return sync();
		},
		release: function () {
			forced = false;
			w.pcMarketingQuietServer = false;
			if ( w.pcBuyer ) {
				w.pcBuyer.suppressPopups = false;
			}
			try {
				d.cookie = COOKIE + '=; path=/; max-age=0; SameSite=Lax';
			} catch ( e ) {}
			return sync();
		},
		refresh: sync,
		onChange: function ( fn ) {
			if ( 'function' === typeof fn ) {
				listeners.push( fn );
			}
		}
	};
	sync();
}( window, document ) );
