/**
 * 后台“数据”板块的共用外壳（DATA-01），九个数据页面都用：标题、时间段（今天 / 昨天 / 最近 7 天 / 最近 30 天 / 自定义）、
 * 对比时间段（VS）、店铺时区说明，下面是报表内容。时间段由服务器按店铺时区换算（GET /peptide/v1/data/range），这里不自己算日期。
 * 数据：window.pcData（首屏，今天），所有文字都来自 pcData.strings，插入页面的值都经过转义。
 *
 * 后面的报表脚本（依赖脚本句柄 peptide-core-data，在这个脚本之后加载）这样把报表画进页面：
 *   PcData.register( '页面 key', function ( bodyEl, range, ctx ) { ... } );
 * range 就是接口返回的时间段（from / to / compare_from / compare_to 时间戳、days、bucket、dates）；
 * 数字自己用 ctx.api( '/peptide/v1/data/…', { from: range.from, … } ) 去取。切换时间段时会再调用一次。
 * 没有注册报表的页面显示“正在开发中”。
 * 页面可以不要时间段工具栏（实时数据页）：pcData.showRange 为 false 时只画标题、时区说明和报表，
 * 不读地址栏里的时间段，直接画报表；没有这个键时和以前一样（有工具栏）。
 * pcData.showCompare 为 false 时只画本期日期，忽略地址栏里的对比期；没有这个键时保留对比期。
 */
( function ( wp, cfg ) {
	'use strict';

	var root = document.getElementById( 'pc-data' );
	if ( ! wp || ! wp.apiFetch || ! wp.url || ! cfg || ! root ) {
		return;
	}
	var S = cfg.strings;
	// 没有 showRange 这个键就是要工具栏（其他数据页不变）；只有明确是 false（实时数据页）才不要。
	var showRange = cfg.showRange !== false;
	// 没有 showCompare 时保留对比期；落地页转化页只有本期。
	var showCompare = cfg.showCompare !== false;
	var PRESETS = [ 'today', 'yesterday', '7d', '30d' ];
	var RANGE_PATH = '/peptide/v1/data/range';
	var URL_KEYS = [ 'range', 'from', 'to', 'compare_from', 'compare_to' ];

	var renderers = {};
	var state = cfg.range;
	var busy = false;
	var els = {};

	// ---------- 小工具（报表脚本也会用到） ----------

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function money( n ) {
		return Number( n || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}
	function number( n ) {
		return Number( n || 0 ).toLocaleString( undefined, { maximumFractionDigits: 0 } );
	}
	// 变化百分比：没有（上一个时间段是 0）显示“-”，否则带正负号，最多 2 位小数，去掉多余的 0。
	function change( v ) {
		if ( v === undefined || v === null || isNaN( Number( v ) ) ) {
			return '-';
		}
		var n = Math.round( Number( v ) * 100 ) / 100;
		return ( n > 0 ? '+' : '' ) + n + '%';
	}
	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	// 先把 PcData 挂出去，再画页面：后面加载的报表脚本要用它注册。
	window.PcData = {
		register: function ( key, fn ) {
			renderers[ key ] = fn;
		},
		api: function ( path, params ) {
			return wp.apiFetch( { path: wp.url.addQueryArgs( path, params || {} ) } );
		},
		esc: esc,
		money: money,
		number: number,
		change: change,
		strings: cfg.strings,
		currency: cfg.currency
	};

	// ---------- 外壳 ----------

	function dateInput( cls, label ) {
		return '<input type="date" class="pc-data-date ' + cls + '" aria-label="' + esc( label ) + '" title="' + esc( label ) + '">';
	}

	function frame() {
		var toolbar = '';
		if ( showRange ) {
			var presets = PRESETS.map( function ( p ) {
				return '<button type="button" class="button pc-data-preset" data-preset="' + esc( p ) + '" aria-pressed="false">' + esc( S[ p ] ) + '</button>';
			} ).join( '' );
			toolbar =
				'<div class="pc-data-toolbar">' +
					'<div class="pc-data-group pc-data-presets" role="group">' + presets + '</div>' +
					'<div class="pc-data-group pc-data-custom" role="group" aria-label="' + esc( S.custom ) + '">' +
						'<span class="pc-data-label">' + esc( S.custom ) + '</span>' +
						dateInput( 'pc-data-from', S.startDate ) + '<span class="pc-data-dash">–</span>' + dateInput( 'pc-data-to', S.endDate ) +
					'</div>' +
					( showCompare ? '<div class="pc-data-group pc-data-compare" role="group" aria-label="' + esc( S.compareWith ) + '">' +
						'<span class="pc-data-vs">' + esc( S.vs ) + '</span>' +
						dateInput( 'pc-data-cfrom', S.compareWith + ': ' + S.startDate ) + '<span class="pc-data-dash">–</span>' + dateInput( 'pc-data-cto', S.compareWith + ': ' + S.endDate ) +
					'</div>' : '' ) +
					'<button type="button" class="button button-primary pc-data-apply">' + esc( S.apply ) + '</button>' +
				'</div>';
		}
		root.innerHTML =
			'<h1 class="pc-data-title">' + esc( cfg.title ) + '</h1>' +
			toolbar +
			'<p class="pc-data-muted pc-data-tz">' + esc( S.storeTime + ': ' + cfg.timezone ) + '</p>' +
			'<p class="pc-data-error" role="alert" hidden></p>' +
			'<div class="pc-data-body"></div>';

		els.error = root.querySelector( '.pc-data-error' );
		els.body = root.querySelector( '.pc-data-body' );
		if ( ! showRange ) {
			// 没有工具栏：没有预设按钮、日期框和“应用”，也就没有要绑定的事件。
			return;
		}
		els.presets = root.querySelectorAll( '.pc-data-preset' );
		els.from = root.querySelector( '.pc-data-from' );
		els.to = root.querySelector( '.pc-data-to' );
		els.cfrom = showCompare ? root.querySelector( '.pc-data-cfrom' ) : null;
		els.cto = showCompare ? root.querySelector( '.pc-data-cto' ) : null;
		els.apply = root.querySelector( '.pc-data-apply' );

		each( els.presets, function ( b ) {
			b.addEventListener( 'click', function () {
				load( { range: b.getAttribute( 'data-preset' ) }, false );
			} );
		} );
		els.apply.addEventListener( 'click', applyCustom );
	}

	// 日期框和预设按钮跟着当前时间段走。
	function sync() {
		if ( ! showRange ) {
			return;
		}
		var d = state.dates;
		els.from.value = d.from;
		els.to.value = d.to;
		if ( showCompare && els.cfrom && els.cto ) {
			els.cfrom.value = d.compare_from;
			els.cto.value = d.compare_to;
		}
		each( els.presets, function ( b ) {
			var on = !! state.preset && b.getAttribute( 'data-preset' ) === state.preset;
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			if ( on ) {
				b.classList.add( 'is-active' );
			} else {
				b.classList.remove( 'is-active' );
			}
		} );
	}

	function setBusy( on ) {
		busy = on;
		if ( ! showRange ) {
			return;
		}
		each( els.presets, function ( b ) {
			b.disabled = on;
		} );
		els.apply.disabled = on;
	}

	function showError( msg ) {
		els.error.textContent = msg;
		els.error.hidden = false;
	}
	function hideError() {
		els.error.textContent = '';
		els.error.hidden = true;
	}

	function applyCustom() {
		if ( busy ) {
			return;
		}
		var params = { from: els.from.value, to: els.to.value };
		if ( ! params.from || ! params.to ) {
			showError( S.rangeError );
			return;
		}
		if ( showCompare && els.cfrom && els.cto && els.cfrom.value && els.cto.value ) {
			params.compare_from = els.cfrom.value;
			params.compare_to = els.cto.value;
		}
		load( params, false );
	}

	// 把选择写进地址栏（刷新后保持）：预设写 range，自定义写 from / to / compare_from / compare_to，其余的去掉。
	function writeUrl( params ) {
		if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
			return;
		}
		try {
			var u = new window.URL( window.location.href );
			URL_KEYS.forEach( function ( k ) {
				u.searchParams.delete( k );
			} );
			Object.keys( params ).forEach( function ( k ) {
				u.searchParams.set( k, params[ k ] );
			} );
			window.history.replaceState( null, '', u.toString() );
		} catch ( e ) {
			// 地址栏写不进去不影响使用。
		}
	}

	// 地址栏里已有的选择：range，或者 from + to（可带对比）。没有就返回 null。
	function paramsFromUrl() {
		if ( ! window.URLSearchParams ) {
			return null;
		}
		var q = new window.URLSearchParams( window.location.search );
		var params = {};
		if ( q.get( 'range' ) ) {
			params.range = q.get( 'range' );
		} else if ( q.get( 'from' ) && q.get( 'to' ) ) {
			params.from = q.get( 'from' );
			params.to = q.get( 'to' );
			if ( showCompare && q.get( 'compare_from' ) && q.get( 'compare_to' ) ) {
				params.compare_from = q.get( 'compare_from' );
				params.compare_to = q.get( 'compare_to' );
			}
		} else {
			return null;
		}
		return params;
	}

	// 向服务器换算时间段；成功后换掉当前时间段、更新页面和地址栏、重画报表。first = 第一次打开（失败时也要画出报表）。
	function load( params, first ) {
		if ( busy ) {
			return;
		}
		setBusy( true );
		window.PcData.api( RANGE_PATH, params ).then(
			function ( r ) {
				setBusy( false );
				state = r;
				sync();
				writeUrl( params );
				hideError();
				renderBody();
			},
			function ( e ) {
				setBusy( false );
				var bad = e && ( 'pc_invalid_param' === e.code || ( e.data && 400 === Number( e.data.status ) ) );
				showError( bad ? S.rangeError : S.loadError );
				if ( first ) {
					renderBody();
				}
			}
		);
	}

	// ---------- 报表内容 ----------

	function renderBody() {
		var fn = renderers[ cfg.page ];
		els.body.innerHTML = '';
		if ( 'function' !== typeof fn ) {
			els.body.innerHTML = '<p class="pc-data-empty">' + esc( S.building ) + '</p>';
			return;
		}
		try {
			fn( els.body, state, window.PcData );
		} catch ( e ) {
			showError( S.loadError );
		}
	}

	function start() {
		frame();
		sync();
		// 没有工具栏的页面不读地址栏里的时间段（也没有地方显示它）。
		var wanted = showRange ? paramsFromUrl() : null;
		if ( wanted ) {
			load( wanted, true );
		} else {
			renderBody();
		}
	}

	// 等页面解析完再画：后面加载的报表脚本这时已经注册好了。
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.wp, window.pcData );
