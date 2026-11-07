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
	var SPANS = [ '15d', 'week', 'month', 'lastweek', 'lastmonth', 'custom' ];
	var COMPARES = [ 'prev', 'yoy', 'custom' ];
	var TODAY = cfg.range && cfg.range.dates ? cfg.range.dates.from : '';
	var compareMode = 'prev';
	try { compareMode = COMPARES.indexOf( window.localStorage.getItem( 'pcDataCompare' ) ) >= 0 ? window.localStorage.getItem( 'pcDataCompare' ) : 'prev'; } catch ( e ) { compareMode = 'prev'; }
	var customMode = false;
	var RANGE_PATH = '/peptide/v1/data/range';
	var URL_KEYS = [ 'range', 'from', 'to', 'compare_from', 'compare_to' ];

	var renderers = {};
	var state = cfg.range;
	var busy = false;
	var started = false;
	var rangeListeners = [];
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
			if ( typeof fn !== 'function' || Object.prototype.hasOwnProperty.call( renderers, key ) ) { return; }
			renderers[ key ] = fn;
			// Footer/deferred scripts may register after DOMContentLoaded.
			if ( started && ! busy && key === cfg.page ) { renderBody(); }
		},
		onRangeState: function ( listener ) {
			var listeners = rangeListeners;
			listeners.push( listener );
			try { listener( busy ); } catch ( e ) {}
			return function () { var index = listeners.indexOf( listener ); if ( index !== -1 ) { listeners.splice( index, 1 ); } };
		},
		api: function ( path, params ) {
			return wp.apiFetch( { path: wp.url.addQueryArgs( path, params || {} ) } );
		},
		esc: esc,
		// 平滑折线图（单调三次样条，不冲出相邻两点）：本期实线 + 浅色面积，对比期灰线。返回 SVG 字符串。
		// o = { cur: [数字], prev: [数字], labels: [横轴文字], w, h, title }
		lineChart: function ( o ) {
			var cur = o.cur || [], prev = o.prev || [], labels = o.labels || [];
			var W = o.w || 640, H = o.h || 240, L = 40, R = 12, T = 12, B = 28, n = Math.max( cur.length, 1 );
			var max = 0;
			cur.concat( prev ).forEach( function ( v ) { max = Math.max( max, Number( v ) || 0 ); } );
			var step = max <= 4 ? 1 : Math.pow( 10, Math.floor( Math.log( max / 4 ) / Math.LN10 ) );
			step = [ 1, 2, 2.5, 5, 10 ].map( function ( m ) { return m * step; } ).filter( function ( u ) { return u * 4 >= max; } )[ 0 ] || step;
			var top = step * 4;
			var x = function ( i ) { return n > 1 ? L + ( W - L - R ) * i / ( n - 1 ) : L + ( W - L - R ) / 2; };
			var y = function ( v ) { return T + ( H - T - B ) * ( 1 - ( Number( v ) || 0 ) / top ); };
			var r1 = function ( v ) { return Math.round( v * 10 ) / 10; };
			var path = function ( vals ) {
				var pts = vals.map( function ( v, i ) { return [ x( i ), y( v ) ]; } ), m = [], d = [], i;
				if ( pts.length < 2 ) { return pts.length ? 'M' + r1( pts[ 0 ][ 0 ] ) + ' ' + r1( pts[ 0 ][ 1 ] ) : ''; }
				for ( i = 0; i < pts.length - 1; i++ ) { d.push( ( pts[ i + 1 ][ 1 ] - pts[ i ][ 1 ] ) / ( pts[ i + 1 ][ 0 ] - pts[ i ][ 0 ] || 1 ) ); }
				m.push( d[ 0 ] ); for ( i = 1; i < pts.length - 1; i++ ) { m.push( d[ i - 1 ] * d[ i ] <= 0 ? 0 : ( d[ i - 1 ] + d[ i ] ) / 2 ); } m.push( d[ d.length - 1 ] );
				for ( i = 0; i < d.length; i++ ) { if ( ! d[ i ] ) { m[ i ] = 0; m[ i + 1 ] = 0; continue; } var a = m[ i ] / d[ i ], b = m[ i + 1 ] / d[ i ], hh = a * a + b * b; if ( hh > 9 ) { var t = 3 / Math.sqrt( hh ); m[ i ] = t * a * d[ i ]; m[ i + 1 ] = t * b * d[ i ]; } }
				var out = 'M' + r1( pts[ 0 ][ 0 ] ) + ' ' + r1( pts[ 0 ][ 1 ] );
				for ( i = 0; i < pts.length - 1; i++ ) { var dx = ( pts[ i + 1 ][ 0 ] - pts[ i ][ 0 ] ) / 3; out += ' C' + r1( pts[ i ][ 0 ] + dx ) + ' ' + r1( pts[ i ][ 1 ] + m[ i ] * dx ) + ' ' + r1( pts[ i + 1 ][ 0 ] - dx ) + ' ' + r1( pts[ i + 1 ][ 1 ] - m[ i + 1 ] * dx ) + ' ' + r1( pts[ i + 1 ][ 0 ] ) + ' ' + r1( pts[ i + 1 ][ 1 ] ); }
				return out;
			};
			var svg = '<svg class="pc-line" viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" aria-label="' + esc( o.title || '' ) + '">', g;
			for ( g = 0; g <= 4; g++ ) {
				var gy = r1( y( step * g ) );
				svg += '<line class="pc-line__grid" x1="' + L + '" x2="' + ( W - R ) + '" y1="' + gy + '" y2="' + gy + '"></line><text class="pc-line__tick" x="' + ( L - 6 ) + '" y="' + r1( gy + 4 ) + '" text-anchor="end">' + esc( number( step * g ) ) + '</text>';
			}
			var every = Math.max( 1, Math.ceil( n / 8 ) );
			labels.forEach( function ( text, i ) { if ( 0 === i % every ) { svg += '<text class="pc-line__label" x="' + r1( x( i ) ) + '" y="' + ( H - 8 ) + '" text-anchor="middle">' + esc( text ) + '</text>'; } } );
			if ( prev.length > 1 ) { svg += '<path class="pc-line__prev" d="' + path( prev.slice( 0, n ) ) + '"></path>'; }
			if ( cur.length > 1 ) { var c = path( cur ); svg += '<path class="pc-line__area" d="' + c + ' L' + r1( x( cur.length - 1 ) ) + ' ' + r1( y( 0 ) ) + ' L' + r1( x( 0 ) ) + ' ' + r1( y( 0 ) ) + ' Z"></path><path class="pc-line__cur" d="' + c + '"></path>'; }
			cur.forEach( function ( v, i ) { svg += '<circle class="pc-line__dot" cx="' + r1( x( i ) ) + '" cy="' + r1( y( v ) ) + '" r="3"><title>' + esc( ( labels[ i ] || '' ) + ': ' + number( v ) ) + '</title></circle>'; } );
			return svg + '</svg>';
		},
		// 分页（对照参考后台：共 N 条 ‹ 1 2 … 9 › 前往 [ ] 页）。页码按钮带相对位移（data-dir = 目标页 − 当前页），
		// 所以各报表原来“当前页 + data-dir”的点击处理不用改；“前往”输入框由外壳换算成同样的相对位移。
		pager: function ( o ) {
			var attr = o.attr || 'data-dir', page = Math.max( 1, Number( o.page ) || 1 ), pages = Math.max( 1, Number( o.pages ) || 1 );
			var btn = function ( dir, text, cls, disabled, label ) {
				return '<button type="button" class="pc-pager__btn' + ( cls ? ' ' + cls : '' ) + '" ' + attr + '="' + dir + '"' + ( disabled ? ' disabled' : '' ) + ( label ? ' aria-label="' + esc( label ) + '"' : '' ) + '>' + esc( text ) + '</button>';
			};
			var nums = [], k;
			for ( k = 1; k <= pages; k++ ) {
				if ( k === 1 || k === pages || Math.abs( k - page ) <= 2 ) { nums.push( k ); } else if ( nums[ nums.length - 1 ] !== '…' ) { nums.push( '…' ); }
			}
			return '<div class="pc-pager">' +
				'<span class="pc-pager__total">' + esc( String( S.pagerTotal || '' ).indexOf( '%d' ) >= 0 ? S.pagerTotal.split( '%d' ).join( number( o.total ) ) : number( o.total ) ) + '</span>' +
				// 上一页 / 下一页在页码前面（各报表的测试按顺序找它们）；显示顺序由 CSS order 排成 ‹ 1 2 … ›。
				btn( -1, '‹', 'pc-pager__prev', page <= 1, S.prevPage || '' ) +
				btn( 1, '›', 'pc-pager__next', page >= pages, S.nextPage || '' ) +
				nums.map( function ( n ) {
					return '…' === n ? '<span class="pc-pager__gap">…</span>' : ( n === page ? '<span class="pc-pager__num is-current" aria-current="page">' + n + '</span>' : btn( n - page, String( n ), 'pc-pager__num', false, '' ) );
				} ).join( '' ) +
				( pages > 1 ? '<label class="pc-pager__jump">' + esc( S.pagerGoto || '' ) + ' <input type="number" min="1" max="' + pages + '" value="' + page + '" data-pager-current="' + page + '" data-pager-attr="' + esc( attr ) + '"> ' + esc( S.pagerUnit || '' ) + '</label>' +
					'<button type="button" class="pc-pager__go" ' + attr + '="0" hidden tabindex="-1"></button>' : '' ) +
			'</div>';
		},
		// 时间工具栏末尾的报表专属控件（运营数据的“请选择域名”）：整块替换，返回容器。
		setToolbarExtra: function ( html ) {
			var slot = root.querySelector ? root.querySelector( '.pc-data-toolbar-extra' ) : null;
			if ( slot ) { slot.innerHTML = html || ''; }
			return slot;
		},
		// 标题右侧的页面操作（导出、更多维度数据、款式详情数据…，对照参考后台）：报表脚本每次 render 时整块替换，返回容器。
		setActions: function ( html ) {
			var slot = root.querySelector ? root.querySelector( '.pc-data-actions' ) : null;
			if ( slot ) { slot.innerHTML = html || ''; }
			return slot;
		},
		// 标题旁的“?”说明：悬停 / 键盘聚焦时显示气泡（样式在 data.css 的 .pc-tip）。
		tip: function ( text ) {
			return text ? '<span class="pc-tip" tabindex="0" role="img" aria-label="' + esc( text ) + '" data-tip="' + esc( text ) + '">?</span>' : '';
		},
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
			// 版式对照参考后台：时间段下拉（今天 / 昨天 / 过去 7、15、30 天 / 本周 / 本月 / 上周 / 上月 / 自定义）+ 一个日期范围框
			// + VS + 对比方式下拉（上一期 / 去年同期 / 自定义）+ 对比日期框。选了就生效，不需要“查询”。
			// 服务器支持的四个预设仍是 data-preset 按钮（地址栏写 range=…）；其余选项在浏览器里换算成日期。
			// 菜单顺序与参考后台一致。
			var presets = [ 'p:today', 'p:yesterday', 'p:7d', 's:15d', 'p:30d', 's:week', 's:month', 's:lastweek', 's:lastmonth', 's:custom' ].map( function ( item ) {
				var k = item.slice( 2 );
				return 'p' === item.charAt( 0 ) ?
					'<button type="button" class="button pc-data-preset pc-dd__item" data-preset="' + esc( k ) + '" aria-pressed="false">' + esc( S[ k ] ) + '</button>' :
					'<button type="button" class="button pc-data-span pc-dd__item" data-span="' + esc( k ) + '" aria-pressed="false">' + esc( S[ 'span_' + k ] || k ) + '</button>';
			} ).join( '' );
			var compares = COMPARES.map( function ( k ) {
				return '<button type="button" class="button pc-data-cmode pc-dd__item" data-cmode="' + esc( k ) + '" aria-pressed="false">' + esc( S[ 'cmp_' + k ] || k ) + '</button>';
			} ).join( '' );
			var clock = '<svg class="pc-data-clock" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="8" cy="8" r="6.3"/><path d="M8 4.6V8l2.4 1.6"/></svg>';
			toolbar =
				'<div class="pc-data-toolbar">' +
					'<div class="pc-dd pc-data-presets"><button type="button" class="pc-dd__trigger" aria-haspopup="true" aria-expanded="false"><span class="pc-dd__label"></span></button>' +
						'<div class="pc-dd__menu" role="group" aria-label="' + esc( S.custom ) + '" hidden>' + presets + '</div></div>' +
					'<div class="pc-data-group pc-data-custom pc-data-rangebox" role="group" aria-label="' + esc( S.custom ) + '">' + clock +
						dateInput( 'pc-data-from', S.startDate ) + '<span class="pc-data-time">00:00:00</span><span class="pc-data-dash">~</span>' + dateInput( 'pc-data-to', S.endDate ) + '<span class="pc-data-time">23:59:59</span>' +
					'</div>' +
					( showCompare ? '<span class="pc-data-vs">' + esc( S.vs ) + '</span>' +
						'<div class="pc-dd pc-data-cmodes"><button type="button" class="pc-dd__trigger" aria-haspopup="true" aria-expanded="false"><span class="pc-dd__label"></span></button>' +
							'<div class="pc-dd__menu" role="group" aria-label="' + esc( S.compareWith ) + '" hidden>' + compares + '</div></div>' +
						'<div class="pc-data-group pc-data-compare pc-data-rangebox" role="group" aria-label="' + esc( S.compareWith ) + '">' + clock +
							dateInput( 'pc-data-cfrom', S.compareWith + ': ' + S.startDate ) + '<span class="pc-data-dash">~</span>' + dateInput( 'pc-data-cto', S.compareWith + ': ' + S.endDate ) +
						'</div>' : '' ) +
					'<div class="pc-data-toolbar-extra"></div>' +
					'<button type="button" class="button button-primary pc-data-apply">' + esc( S.apply ) + '</button>' +
				'</div>';
		}
		// 口径说明默认收起（版式对照参考后台：页面上只留数字和表格）；标题右边的按钮展开 / 收起，选择记在浏览器里。
		var notesOpen = false;
		try { notesOpen = '1' === window.localStorage.getItem( 'pcDataNotes' ); } catch ( e ) { /* 存储不可用时默认收起。 */ }
		var setNotes = function ( on ) {
			if ( root.classList && 'function' === typeof root.classList.add ) {
				if ( on ) { root.classList.add( 'is-notes' ); } else { root.classList.remove( 'is-notes' ); }
			}
		};
		setNotes( notesOpen );
		root.innerHTML =
			'<div class="pc-data-head"><h1 class="pc-data-title">' + esc( cfg.title ) + '</h1><div class="pc-data-actions"></div>' +
			( S.notesShow ? '<button type="button" class="pc-data-notes-toggle" aria-pressed="' + ( notesOpen ? 'true' : 'false' ) + '">' + esc( notesOpen ? S.notesHide : S.notesShow ) + '</button>' : '' ) + '</div>' +
			toolbar +
			'<p class="pc-data-muted pc-data-tz">' + esc( S.storeTime + ': ' + cfg.timezone ) + '</p>' +
			'<p class="pc-data-error" role="alert" hidden></p>' +
			'<div class="pc-data-body"></div>';

		var notesButton = root.querySelector( '.pc-data-notes-toggle' );
		if ( notesButton ) {
			notesButton.addEventListener( 'click', function () {
				notesOpen = ! notesOpen;
				var open = notesOpen;
				setNotes( open );
				notesButton.setAttribute( 'aria-pressed', open ? 'true' : 'false' );
				notesButton.textContent = open ? S.notesHide : S.notesShow;
				try { window.localStorage.setItem( 'pcDataNotes', open ? '1' : '0' ); } catch ( e ) { /* 记不住也不影响使用。 */ }
			} );
		}
		// 分页的“前往 N 页”：回车或改值后，换算成相对位移交给报表自己的翻页处理。
		var jump = function ( input ) {
			var pager = input.closest ? input.closest( '.pc-pager' ) : null;
			var go = pager ? pager.querySelector( '.pc-pager__go' ) : null;
			var attr = input.getAttribute( 'data-pager-attr' ) || 'data-dir';
			var cur = Number( input.getAttribute( 'data-pager-current' ) ), max = Number( input.getAttribute( 'max' ) ), n = Math.round( Number( input.value ) );
			if ( ! go || ! n || n < 1 || n > max || n === cur ) { return; }
			go.setAttribute( attr, String( n - cur ) );
			go.click();
		};
		if ( root.addEventListener ) {
			root.addEventListener( 'change', function ( e ) { if ( e.target && e.target.matches && e.target.matches( '.pc-pager__jump input' ) ) { jump( e.target ); } } );
			root.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key && e.target && e.target.matches && e.target.matches( '.pc-pager__jump input' ) ) { e.preventDefault(); jump( e.target ); } } );
		}
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

		els.spans = root.querySelectorAll( '.pc-data-span' );
		els.cmodes = root.querySelectorAll( '.pc-data-cmode' );
		els.dds = root.querySelectorAll( '.pc-dd' );

		each( els.presets, function ( b ) {
			b.addEventListener( 'click', function () {
				closeMenus();
				var key = b.getAttribute( 'data-preset' );
				// “上一期”时交给服务器的预设（地址栏 range=…）；其他对比方式要把预设换成日期。
				if ( 'prev' === compareMode ) {
					load( { range: key }, false );
				} else {
					var r = presetDates( key );
					if ( r ) { loadDates( r[ 0 ], r[ 1 ] ); }
				}
			} );
		} );
		each( els.spans, function ( b ) {
			b.addEventListener( 'click', function () {
				closeMenus();
				var key = b.getAttribute( 'data-span' );
				if ( 'custom' === key ) {
					customMode = true;
					sync();
					if ( els.from.focus ) { els.from.focus(); }
					return;
				}
				var r = spanDates( key );
				if ( r ) { loadDates( r[ 0 ], r[ 1 ] ); }
			} );
		} );
		each( els.cmodes, function ( b ) {
			b.addEventListener( 'click', function () {
				closeMenus();
				compareMode = b.getAttribute( 'data-cmode' );
				try { window.localStorage.setItem( 'pcDataCompare', compareMode ); } catch ( e ) { /* 可选。 */ }
				sync();
				if ( 'custom' !== compareMode && state && state.dates ) { loadDates( state.dates.from, state.dates.to, state.preset ); }
			} );
		} );
		each( els.dds, function ( dd ) {
			var trigger = dd.querySelector( '.pc-dd__trigger' );
			var menu = dd.querySelector( '.pc-dd__menu' );
			trigger.addEventListener( 'click', function ( e ) {
				if ( e && e.stopPropagation ) { e.stopPropagation(); }
				var open = menu.hidden;
				closeMenus();
				menu.hidden = ! open;
				trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
		if ( document.addEventListener ) {
			document.addEventListener( 'click', function ( e ) { if ( ! e || ! e.target || ! e.target.closest || ! e.target.closest( '.pc-dd' ) ) { closeMenus(); } } );
			document.addEventListener( 'keydown', function ( e ) { if ( e && 'Escape' === e.key ) { closeMenus(); } } );
		}
		// 日期选完就生效（两个日期都合法时）；对比日期只在“自定义”对比时可改。
		[ els.from, els.to, els.cfrom, els.cto ].forEach( function ( input ) {
			if ( input ) { input.addEventListener( 'change', function () { if ( els.from.value && els.to.value ) { applyCustom(); } } ); }
		} );
		els.apply.addEventListener( 'click', applyCustom );
	}

	// ---------- 时间段换算（店铺时区的“今天”来自服务器给的 cfg.range） ----------

	function closeMenus() {
		each( els.dds || [], function ( dd ) {
			var menu = dd.querySelector( '.pc-dd__menu' );
			var trigger = dd.querySelector( '.pc-dd__trigger' );
			if ( menu ) { menu.hidden = true; }
			if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); }
		} );
	}
	function ymd( d ) {
		var m = d.getUTCMonth() + 1, day = d.getUTCDate();
		return d.getUTCFullYear() + '-' + ( m < 10 ? '0' : '' ) + m + '-' + ( day < 10 ? '0' : '' ) + day;
	}
	function parseDay( v ) {
		var p = String( v || '' ).split( '-' );
		return 3 === p.length ? new Date( Date.UTC( Number( p[ 0 ] ), Number( p[ 1 ] ) - 1, Number( p[ 2 ] ) ) ) : null;
	}
	function addDays( d, n ) { return new Date( d.getTime() + n * 86400000 ); }
	function today() { return parseDay( TODAY ) || parseDay( ymd( new Date() ) ); }
	function presetDates( key ) {
		var t = today();
		var map = { today: [ 0, 0 ], yesterday: [ -1, -1 ], '7d': [ -6, 0 ], '30d': [ -29, 0 ] };
		return map[ key ] ? [ ymd( addDays( t, map[ key ][ 0 ] ) ), ymd( addDays( t, map[ key ][ 1 ] ) ) ] : null;
	}
	function spanDates( key ) {
		var t = today(), dow = ( t.getUTCDay() + 6 ) % 7; // 周一 = 0
		var monthStart = new Date( Date.UTC( t.getUTCFullYear(), t.getUTCMonth(), 1 ) );
		switch ( key ) {
			case '15d': return [ ymd( addDays( t, -14 ) ), ymd( t ) ];
			case 'week': return [ ymd( addDays( t, -dow ) ), ymd( t ) ];
			case 'month': return [ ymd( monthStart ), ymd( t ) ];
			case 'lastweek': return [ ymd( addDays( t, -dow - 7 ) ), ymd( addDays( t, -dow - 1 ) ) ];
			case 'lastmonth': return [ ymd( new Date( Date.UTC( t.getUTCFullYear(), t.getUTCMonth() - 1, 1 ) ) ), ymd( addDays( monthStart, -1 ) ) ];
		}
		return null;
	}
	// 去年同期：同月同日减一年（2 月 29 日落到 2 月 28 日）。
	function lastYear( v ) {
		var d = parseDay( v );
		if ( ! d ) { return v; }
		var y = new Date( Date.UTC( d.getUTCFullYear() - 1, d.getUTCMonth(), d.getUTCDate() ) );
		if ( y.getUTCMonth() !== d.getUTCMonth() ) { y = addDays( y, -y.getUTCDate() ); }
		return ymd( y );
	}
	// 按当前对比方式加载一个日期段：上一期交给服务器；去年同期在这里算；自定义用对比日期框里的值。
	function loadDates( from, to, preset ) {
		customMode = false;
		if ( 'prev' === compareMode && preset ) { load( { range: preset }, false ); return; }
		var params = { from: from, to: to };
		if ( showCompare && 'yoy' === compareMode ) {
			params.compare_from = lastYear( from );
			params.compare_to = lastYear( to );
		} else if ( showCompare && 'custom' === compareMode && els.cfrom && els.cfrom.value && els.cto && els.cto.value ) {
			params.compare_from = els.cfrom.value;
			params.compare_to = els.cto.value;
		}
		load( params, false );
	}
	// 当前时间段对应的下拉文字：服务器预设，或者和某个换算选项的日期一致，否则是“自定义”。
	function periodKey() {
		if ( customMode ) { return 'custom'; }
		if ( state && state.preset ) { return 'p:' + state.preset; }
		var d = state && state.dates;
		if ( ! d ) { return 'custom'; }
		var hit = 'custom';
		PRESETS.concat( [] ).forEach( function ( k ) { var r = presetDates( k ); if ( r && r[ 0 ] === d.from && r[ 1 ] === d.to && 'custom' === hit ) { hit = 'p:' + k; } } );
		SPANS.forEach( function ( k ) { var r = spanDates( k ); if ( r && r[ 0 ] === d.from && r[ 1 ] === d.to && 'custom' === hit ) { hit = 's:' + k; } } );
		return hit;
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
		var key = periodKey();
		var mark = function ( b, on ) {
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			if ( on ) { b.classList.add( 'is-active' ); } else { b.classList.remove( 'is-active' ); }
		};
		each( els.presets, function ( b ) { mark( b, 'p:' + b.getAttribute( 'data-preset' ) === key ); } );
		each( els.spans || [], function ( b ) { var k = b.getAttribute( 'data-span' ); mark( b, ( 'custom' === k && 'custom' === key ) || 's:' + k === key ); } );
		each( els.cmodes || [], function ( b ) { mark( b, b.getAttribute( 'data-cmode' ) === compareMode ); } );
		var labels = root.querySelectorAll( '.pc-dd__label' );
		if ( labels[ 0 ] ) { labels[ 0 ].textContent = 'custom' === key ? S.span_custom || S.custom : ( 'p:' === key.slice( 0, 2 ) ? S[ key.slice( 2 ) ] : S[ 'span_' + key.slice( 2 ) ] ); }
		if ( labels[ 1 ] ) { labels[ 1 ].textContent = S[ 'cmp_' + compareMode ] || compareMode; }
		// 对比日期框只在“自定义”对比时可编辑（参考后台：上一期 / 去年同期时是算出来的灰色日期）。
		if ( els.cfrom && els.cto ) { els.cfrom.disabled = 'custom' !== compareMode; els.cto.disabled = 'custom' !== compareMode; }
	}

	function setBusy( on ) {
		busy = on;
		rangeListeners.slice().forEach( function ( listener ) { try { listener( on ); } catch ( e ) {} } );
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
		if ( ! els.from.value || ! els.to.value ) {
			showError( S.rangeError );
			return;
		}
		loadDates( els.from.value, els.to.value );
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
		rangeListeners = [];
		var fn = renderers[ cfg.page ];
		els.body.innerHTML = '';
		if ( 'function' !== typeof fn ) {
			els.body.innerHTML = '<p class="pc-data-empty">' + esc( S.building ) + '</p>';
			return;
		}
		var slot = root.querySelector( '.pc-data-actions' );
		if ( slot ) { slot.innerHTML = ''; }
		try {
			fn( els.body, state, window.PcData );
		} catch ( e ) {
			showError( S.loadError );
		}
		proxyExport();
	}

	// 参考后台把“导出”放在标题旁边：报表自己的导出按钮留在原处（逻辑、状态、测试都不变）但在视觉上收起，
	// 标题旁放一个同名按钮代为点击；原按钮禁用时代理按钮也跟着禁用。
	var EXPORTS = [ '.pc-prod-export', '.pc-pages-export', '.pc-conv-export', '.pc-exp-export', '.pc-sources-export', '.pc-dim-export', '.pc-landing-export' ];
	var exportSync = null;
	function proxyExport() {
		try {
			var slot = root.querySelector( '.pc-data-actions' );
			var real = null;
			if ( ! slot || ! els.body.querySelector || ! document.createElement ) { return; }
			EXPORTS.forEach( function ( sel ) { if ( ! real ) { real = els.body.querySelector( sel ); } } );
			if ( ! real || ! real.classList || real.hidden ) { return; }
			var proxy = document.createElement( 'button' );
			proxy.type = 'button';
			proxy.className = 'pc-data-export-proxy';
			proxy.innerHTML = '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 10.5V2.5M4.8 5.6 8 2.4l3.2 3.2M2.5 10.5v3h11v-3"/></svg>' + esc( S.exportShort || real.textContent );
			proxy.title = real.title || '';
			proxy.addEventListener( 'click', function () { if ( ! real.disabled ) { real.click(); } } );
			slot.appendChild( proxy );
			real.classList.add( 'pc-data-proxied' );
			var sync = function () { proxy.disabled = !! real.disabled; };
			sync();
			if ( exportSync ) { exportSync.disconnect(); }
			if ( window.MutationObserver ) { exportSync = new window.MutationObserver( sync ); exportSync.observe( real, { attributes: true, attributeFilter: [ 'disabled' ] } ); }
		} catch ( e ) { /* 代理按钮只是外观；失败时原导出按钮照常可用。 */ }
	}

	function start() {
		if ( started ) { return; }
		frame();
		started = true;
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
