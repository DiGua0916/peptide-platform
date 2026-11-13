/**
 * 实时数据页（DATA-01 第 6 步）的报表：三张卡片（实时访问、实时销售、实时购买力，最近 10 分钟，每个数字后面带今日合计）、
 * 实时访客行为漏斗、每分钟访客数量（柱状图），以及今日的四个列表（浏览最多、加购最多、热卖商品、热门页面）。
 * 数字都来自实时数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Realtime，这里不自己算），
 * 文字来自 window.pcDataRealtime.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 realtime 页面的报表；日期工具栏由本文件管理，保留 data.js 外壳的原有实时页行为。
 *
 * 在脚本里保留的状态：两个窗口选择（漏斗、每分钟图，10 / 20 / 30 分钟，记在浏览器的 localStorage 里）、请求计数（晚到的旧响应不再画）、
 * 唯一的自动刷新定时器。页面框架只在 render() 里建一次，每次刷新只换里面的数字，所以选择框不会在刷新时被重画。
 * 自动刷新仅用于实时模式；历史按查询或手动刷新取数。成功后才同步所选日期和 URL，popstate 恢复时不追加历史。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/realtime';
	var WINDOWS = [ 10, 20, 30 ];
	var FUNNEL_WINDOWS = [ 10, 20, 30, 1440 ]; // 实时访客行为多一个“一天”（店主 2026-11-14）
	var KEY_FUNNEL = 'pcDataRealtimeFunnel';
	var KEY_MINUTES = 'pcDataRealtimeMinutes';
	var REFRESH_MS = 60000;
	// 和 Operations::LAYERS 的顺序一致；对应 strings 里的 key（第一层用 funnelVisitors，避免和卡片的 visitors 混）。
	var LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid' ];
	var LAYER_TEXT = { visitors: 'funnelVisitors', viewed: 'viewed', carted: 'carted', checkout: 'checkout', address: 'address', shipping: 'shipping', payment: 'payment', paid: 'paidVisitors' };

	var state = { funnel: readWindow( KEY_FUNNEL, FUNNEL_WINDOWS ), minutes: readWindow( KEY_MINUTES ) };
	var wanted = { mode: 'live', params: {} };
	var urlAction = '';
	var displayedHistory = false;
	var requests = 0;
	var timer = 0;
	var listening = false;
	var painted = false;
	var ctx = null;
	var els = null;
	var lastPoints = null;
	var resizeTimer = 0;

	// ---------- 小工具 ----------

	function esc( v ) {
		return ctx.esc( v );
	}

	// 把文字里的占位符换成值（用 split / join，不用 replace：值里的 $ 不会被当成特殊符号）。
	function fill( text, map ) {
		var out = String( text );
		Object.keys( map ).forEach( function ( k ) {
			out = out.split( k ).join( String( map[ k ] ) );
		} );
		return out;
	}

	function isNum( v ) {
		return v !== undefined && v !== null && ! isNaN( Number( v ) );
	}

	// 百分比：没有值显示“-”，最多 2 位小数。
	function pct( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	// 固定 2 位小数（客单数）。
	function dec2( v ) {
		return Number( v || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	// 链接只接受 http(s) 或站内路径。
	function safeUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) || '/' === s.charAt( 0 ) ? s : '';
	}

	// 页面链接只接受 http(s)。
	function httpUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) ? s : '';
	}

	// 窗口选择记在 localStorage 里；读写都可能失败（隐私模式、被禁用），失败就用默认的 10。
	function readWindow( key, list ) {
		list = list || WINDOWS;
		try {
			var n = parseInt( window.localStorage.getItem( key ), 10 );
			return list.indexOf( n ) >= 0 ? n : list[ 0 ];
		} catch ( e ) {
			return WINDOWS[ 0 ];
		}
	}

	function saveWindow( key, n ) {
		try {
			window.localStorage.setItem( key, String( n ) );
		} catch ( e ) {
			// 存不进去不影响使用。
		}
	}

	// ---------- 页面各部分的 HTML ----------

	// 标题旁的“?”：说明文字悬停或聚焦时显示（PcData.tip 来自 data.js；读屏软件读 aria-label）。
	function tip( text ) {
		return ctx.tip ? ctx.tip( text ) : '';
	}

	function metric( label, value, today ) {
		return '<div class="pc-rt-metric">' +
			'<div class="pc-rt-metric__label">' + esc( label ) + '</div>' +
			'<div class="pc-rt-metric__value">' + esc( value ) + '</div>' +
			( displayedHistory ? '' : '<div class="pc-rt-metric__today">' + esc( fill( T.today, { '%s': today } ) ) + '</div>' ) +
		'</div>';
	}

	function card( title, first, second ) {
		return '<section class="pc-rt-card">' +
			'<h2 class="pc-rt-card__title">' + esc( title ) + tip( displayedHistory ? T.period : T.windowNote ) + '</h2>' +
			'<div class="pc-rt-card__metrics">' + first + second + '</div>' +
		'</section>';
	}

	function cards( data ) {
		var now = data.cards || {};
		var day = data.today || {};
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var withCode = function ( label ) {
			return label + ( code ? ' (' + code + ')' : '' );
		};
		return '<div class="pc-rt-cards">' +
			card(
				displayedHistory ? T.historyVisits : T.visits,
				metric( T.visitors, ctx.number( now.visitors ), ctx.number( day.visitors ) ),
				metric( T.pageviews, ctx.number( now.pageviews ), ctx.number( day.pageviews ) )
			) +
			card(
				displayedHistory ? T.historySales : T.sales,
				metric( T.orders, ctx.number( now.orders ), ctx.number( day.orders ) ),
				metric( withCode( T.salesAmount ), ctx.money( now.sales ), ctx.money( day.sales ) )
			) +
			card(
				displayedHistory ? T.historyPower : T.power,
				metric( T.unitsPerOrder, dec2( now.units_per_order ), dec2( day.units_per_order ) ),
				metric( withCode( T.aov ), ctx.money( now.aov ), ctx.money( day.aov ) )
			) +
		'</div>';
	}

	// 访客行为：竖向柱状图，一层一根柱子，柱高 = 占访客的比例（0–100%），柱顶写人数，悬停看比例。
	function steps( layers, detailed ) {
		var keys = detailed ? LAYERS : [ 'visitors', 'viewed', 'carted', 'checkout', 'paid' ];
		var axis = '<div class="pc-rt-axis" aria-hidden="true">' + [ 100, 75, 50, 25, 0 ].map( function ( n ) {
			return '<span>' + n + '%</span>';
		} ).join( '' ) + '</div>';
		return '<span class="screen-reader-text pc-rt-funnel-help">' + esc( detailed ? T.detailHelp : T.detailUnavailable ) + '</span>' + axis +
			'<div class="pc-rt-bars">' + keys.map( function ( key, i ) {
				var layer = layers[ i ] || { count: 0, visitor_rate: null };
				var height = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
				var label = T[ detailed ? LAYER_TEXT[ key ] : ( 'paid' === key ? 'paid' : LAYER_TEXT[ key ] ) ];
				var rate = fill( T.ofVisitors, { '%s': pct( layer.visitor_rate ) } );
				return '<div class="pc-rt-step" title="' + esc( label + ' · ' + ctx.number( layer.count ) + ' · ' + rate ) + '">' +
					'<span class="pc-rt-bar"><span class="pc-rt-step__count">' + esc( ctx.number( layer.count ) ) + '</span>' +
						'<span class="pc-rt-bar__fill' + ( 0 === height ? ' is-zero' : '' ) + '" style="height:' + esc( height ) + '%"></span></span>' +
					'<span class="pc-rt-step__label">' + esc( label ) + '</span>' +
					'<span class="pc-rt-step__rate" aria-label="' + esc( rate ) + '">' + esc( pct( layer.visitor_rate ) ) + '</span>' +
				'</div>';
			} ).join( '' ) + '</div>';
	}

	// 纵轴取整：最大值往上取到 1/2/5×10ⁿ 的倍数，分 4 格。
	function niceMax( max ) {
		if ( max <= 4 ) { return 4; }
		var step = Math.pow( 10, Math.floor( Math.log( max / 4 ) / Math.LN10 ) );
		var unit = [ 1, 2, 2.5, 5, 10 ].map( function ( m ) { return m * step; } ).filter( function ( u ) { return u * 4 >= max; } )[ 0 ];
		return unit * 4;
	}

	// 单调三次样条（Fritsch–Carlson）：曲线平滑，但不会冲过相邻两点的范围（不会画出负数或假峰值）。pts = [[x, y], ...]。
	function smoothPath( pts ) {
		var n = pts.length, i, d = [], m = [];
		var r = function ( v ) { return Math.round( v * 10 ) / 10; };
		if ( ! n ) { return ''; }
		if ( n < 3 ) { return 'M' + pts.map( function ( p ) { return r( p[ 0 ] ) + ' ' + r( p[ 1 ] ); } ).join( ' L' ); }
		for ( i = 0; i < n - 1; i++ ) { d.push( ( pts[ i + 1 ][ 1 ] - pts[ i ][ 1 ] ) / ( ( pts[ i + 1 ][ 0 ] - pts[ i ][ 0 ] ) || 1 ) ); }
		m.push( d[ 0 ] );
		for ( i = 1; i < n - 1; i++ ) { m.push( d[ i - 1 ] * d[ i ] <= 0 ? 0 : ( d[ i - 1 ] + d[ i ] ) / 2 ); }
		m.push( d[ n - 2 ] );
		for ( i = 0; i < n - 1; i++ ) {
			if ( 0 === d[ i ] ) { m[ i ] = 0; m[ i + 1 ] = 0; continue; }
			var a = m[ i ] / d[ i ], b = m[ i + 1 ] / d[ i ], h = a * a + b * b;
			if ( h > 9 ) { var t = 3 / Math.sqrt( h ); m[ i ] = t * a * d[ i ]; m[ i + 1 ] = t * b * d[ i ]; }
		}
		var out = 'M' + r( pts[ 0 ][ 0 ] ) + ' ' + r( pts[ 0 ][ 1 ] );
		for ( i = 0; i < n - 1; i++ ) {
			var dx = ( pts[ i + 1 ][ 0 ] - pts[ i ][ 0 ] ) / 3;
			out += ' C' + r( pts[ i ][ 0 ] + dx ) + ' ' + r( pts[ i ][ 1 ] + m[ i ] * dx ) + ' ' + r( pts[ i + 1 ][ 0 ] - dx ) + ' ' + r( pts[ i + 1 ][ 1 ] - m[ i + 1 ] * dx ) + ' ' + r( pts[ i + 1 ][ 0 ] ) + ' ' + r( pts[ i + 1 ][ 1 ] );
		}
		return out;
	}

	// 每分钟访客数量（历史模式按时段）：平滑折线 + 浅色面积，最旧的在左。
	// 宽度按容器实际宽度画（文字不被缩放）；20 / 30 个点时隔几个点写一个标签，“现在”一定有标签。
	function chart( points ) {
		var W = Math.max( 320, Math.round( ( els && els.chart && els.chart.clientWidth ) || 560 ) );
		var H = 240, L = 34, R = 12, TOP = 12, B = 30;
		var max = 0;
		points.forEach( function ( p ) {
			max = Math.max( max, Number( p.visitors ) || 0 );
		} );
		var top = niceMax( max );
		var n = points.length;
		var every = Math.max( 1, Math.ceil( n / 10 ) );
		var x = function ( i ) { return n > 1 ? L + ( W - L - R ) * i / ( n - 1 ) : L + ( W - L - R ) / 2; };
		var y = function ( v ) { return TOP + ( H - TOP - B ) * ( 1 - v / top ); };
		var grid = '';
		for ( var g = 0; g <= 4; g++ ) {
			var gv = top * g / 4;
			grid += '<line class="pc-rt-line__grid" x1="' + L + '" x2="' + ( W - R ) + '" y1="' + y( gv ) + '" y2="' + y( gv ) + '"></line>' +
				'<text class="pc-rt-line__tick" x="' + ( L - 6 ) + '" y="' + ( y( gv ) + 4 ) + '" text-anchor="end">' + esc( ctx.number( gv ) ) + '</text>';
		}
		var line = '', dots = '', labels = '', pts = [];
		points.forEach( function ( p, i ) {
			var v = Number( p.visitors ) || 0;
			var px = x( i ), py = y( v );
			pts.push( [ px, py ] );
			var ago = Number( p.ago ) || 0;
			var text = displayedHistory ? p.label : ( 0 === ago ? T.now : fill( T.minutesAgo, { '%d': ago } ) );
			var show = displayedHistory ? 0 === i % every : 0 === ago % every;
			dots += '<circle class="pc-rt-line__dot' + ( v ? '' : ' is-zero' ) + '" cx="' + px + '" cy="' + py + '" r="3"><title>' + esc( text + ': ' + ctx.number( v ) ) + '</title></circle>';
			if ( show ) {
				labels += '<text class="pc-rt-line__label" x="' + px + '" y="' + ( H - 10 ) + '" text-anchor="middle">' + esc( text ) + '</text>';
			}
		} );
		line = smoothPath( pts );
		var area = n ? line + ' L' + x( n - 1 ) + ' ' + y( 0 ) + ' L' + x( 0 ) + ' ' + y( 0 ) + ' Z' : '';
		return '<svg class="pc-rt-line" viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" aria-label="' + esc( displayedHistory ? T.historyChart : T.perMinute ) + '">' +
			grid +
			( n ? '<path class="pc-rt-line__area" d="' + area + '"></path><path class="pc-rt-line__path" d="' + line + '"></path>' : '' ) +
			dots + labels +
		'</svg>';
	}

	function item( entry, kind ) {
		var count = '<span class="pc-rt-item__count">' + esc( ctx.number( entry.count ) ) + '</span>';
		var name;
		if ( 'page' === kind ) {
			var href = httpUrl( entry.url );
			name = href ?
				'<a class="pc-rt-item__name" href="' + esc( href ) + '" target="_blank" rel="noopener noreferrer">' + esc( entry.path ) + '</a>' :
				'<span class="pc-rt-item__name">' + esc( entry.path ) + '</span>';
		} else {
			var edit = safeUrl( entry.edit_url );
			name = edit ?
				'<a class="pc-rt-item__name" href="' + esc( edit ) + '">' + esc( entry.name ) + '</a>' :
				'<span class="pc-rt-item__name">' + esc( entry.name ) + '</span>';
		}
		return '<li class="pc-rt-item">' + name + count + '</li>';
	}

	function box( title, entries, kind, href ) {
		var link = safeUrl( href );
		var body = entries && entries.length ?
			'<ol class="pc-rt-list">' + entries.map( function ( entry ) {
				return item( entry, kind );
			} ).join( '' ) + '</ol>' :
			'<p class="pc-rt-muted">' + esc( T.empty ) + '</p>';
		return '<section class="pc-rt-box">' +
			'<div class="pc-rt-box__head">' +
				'<h2 class="pc-rt-box__title">' + esc( title ) + '</h2>' +
				( link ? '<a class="pc-rt-box__details" href="' + esc( link ) + '">' + esc( T.details ) + '</a>' : '' ) +
			'</div>' +
			body +
		'</section>';
	}

	function lists( data ) {
		var l = data.lists || {};
		var links = data.links || {};
		return box( displayedHistory ? T.historyViewed : T.listViewed, l.viewed, 'product', links.products ) +
			box( displayedHistory ? T.historyCarted : T.listCarted, l.carted, 'product', links.products ) +
			box( displayedHistory ? T.historySold : T.listSold, l.sold, 'product', links.products ) +
			box( displayedHistory ? T.historyPages : T.listPages, l.pages, 'page', links.pages );
	}

	var dimensions = null, filters = null, acceptedMain = null, rangeBusy = false, rangeUnsubscribe = null, rangeRecovery = 0;
	function notes( data ) {
		var html = '';
		if ( ! data.tracking ) {
			html += '<p class="pc-rt-note">' + esc( T.trackingOff ) + '</p>';
		}
		if ( ! dimensions ) { html += '<p class="pc-rt-muted">' + esc( T.notYet ) + '</p>'; }
		if ( displayedHistory ) {
			html += '<p class="pc-rt-muted">' + esc( T.historyNote ) + '</p>';
		}
		return html;
	}

	// ---------- 页面框架（只建一次） ----------

	function selectHtml( cls, label, current, list ) {
		var options = ( list || WINDOWS ).map( function ( n ) {
			return '<option value="' + esc( n ) + '"' + ( n === current ? ' selected' : '' ) + '>' + esc( 1440 === n ? ( T.dayOption || '一天（最近 24 小时）' ) : fill( T.minutesOption, { '%d': n } ) ) + '</option>';
		} ).join( '' );
		return '<select class="pc-rt-select ' + cls + '" aria-label="' + esc( label ) + '">' + options + '</select>';
	}

	function build( bodyEl ) {
		var pageTitle = ( window.pcData && window.pcData.title ) || '';
		bodyEl.innerHTML = '<div class="pc-rt">' +
			// 页头一行：标题 · 最后统计时间 · 刷新 ……（右侧）实时 / 今天 / 昨天 / 日期 / 查询；下面一行是入口筛选。
			'<div class="pc-rt-top">' +
				'<div class="pc-rt-head">' +
					( pageTitle ? '<h2 class="pc-rt-heading">' + esc( pageTitle ) + '</h2>' : '' ) +
					'<span class="pc-rt-updated"></span>' +
					'<button type="button" class="pc-rt-refresh">' + esc( T.refresh ) + '</button>' +
					'<span class="screen-reader-text pc-rt-refresh-note"></span>' +
					// 入口域名 / UTM 来源两个下拉放在标题这一行（对照参考后台）。
					'<div class="pc-rt-filters-host"></div>' +
				'</div>' +
				'<form class="pc-rt-range" novalidate>' +
					'<div class="pc-rt-modes" role="group" aria-label="' + esc( T.period ) + '">' +
						'<button type="button" class="pc-rt-live" aria-pressed="false">' + esc( T.live ) + '</button>' +
						'<button type="button" class="pc-rt-today" aria-pressed="false">' + esc( T.todayOption ) + '</button>' +
						'<button type="button" class="pc-rt-yesterday" aria-pressed="false">' + esc( T.yesterday ) + '</button>' +
					'</div>' +
					'<span class="pc-rt-dates">' +
						'<label><span class="screen-reader-text">' + esc( T.from ) + '</span><input class="pc-rt-from" type="date" required></label>' +
						'<span class="pc-rt-dash">~</span>' +
						'<label><span class="screen-reader-text">' + esc( T.to ) + '</span><input class="pc-rt-to" type="date" required></label>' +
					'</span>' +
					'<button type="submit" class="button pc-rt-apply">' + esc( T.apply ) + '</button>' +
				'</form>' +
			'</div>' +
			'<p class="pc-rt-period" aria-live="polite"></p>' +
			'<p class="pc-rt-error" role="alert" hidden></p>' +
			'<div class="pc-rt-notes"></div>' +
			'<div class="pc-rt-cards-wrap"><p class="pc-rt-muted">' + esc( ctx.strings.loading ) + '</p></div>' +
			'<div class="pc-rt-charts">' +
				'<section class="pc-rt-section">' +
					'<div class="pc-rt-section__head">' +
						'<h2 class="pc-rt-section__title"><span class="pc-rt-funnel-title">' + esc( T.behaviour ) + '</span><span class="pc-rt-funnel-tip"></span></h2>' +
						selectHtml( 'pc-rt-funnel-select', T.behaviour, state.funnel, FUNNEL_WINDOWS ) +
					'</div>' +
					'<div class="pc-rt-steps"></div>' +
				'</section>' +
				'<section class="pc-rt-section">' +
					'<div class="pc-rt-section__head">' +
						'<h2 class="pc-rt-section__title"><span class="pc-rt-chart-title">' + esc( T.perMinute ) + '</span></h2>' +
						selectHtml( 'pc-rt-minutes-select', T.perMinute, state.minutes ) +
					'</div>' +
					'<div class="pc-rt-chart-wrap" tabindex="0" role="region" aria-label="' + esc( T.historyChart ) + '"></div>' +
				'</section>' +
			'</div>' +
			'<div class="pc-rt-lists"></div><div class="pc-rt-dimensions-host"></div>' +
		'</div>';

		els = {
			root: bodyEl.querySelector( '.pc-rt' ),
			updated: bodyEl.querySelector( '.pc-rt-updated' ),
			refresh: bodyEl.querySelector( '.pc-rt-refresh' ),
			error: bodyEl.querySelector( '.pc-rt-error' ),
			notes: bodyEl.querySelector( '.pc-rt-notes' ),
			cards: bodyEl.querySelector( '.pc-rt-cards-wrap' ),
			steps: bodyEl.querySelector( '.pc-rt-steps' ),
			chart: bodyEl.querySelector( '.pc-rt-chart-wrap' ),
			lists: bodyEl.querySelector( '.pc-rt-lists' ),
			funnelSelect: bodyEl.querySelector( '.pc-rt-funnel-select' ),
			minutesSelect: bodyEl.querySelector( '.pc-rt-minutes-select' )
		};
		[ 'range', 'from', 'to', 'live', 'today', 'yesterday', 'period', 'refresh-note', 'funnel-title', 'funnel-tip', 'chart-title' ].forEach( function ( name ) {
			els[ name ] = bodyEl.querySelector( '.pc-rt-' + name );
		} );
		painted = false;
		if ( window.PcDataRealtimeDimensions ) {
			dimensions = window.PcDataRealtimeDimensions.mount( bodyEl.querySelector( '.pc-rt-dimensions-host' ), ctx, T.dimensions, window.PcDataRealtimeCountries );
			els.lists.hidden = true;
		}

		if ( window.PcDataRealtimeFilters ) {
			filters = window.PcDataRealtimeFilters.mount( bodyEl.querySelector( '.pc-rt-filters-host' ), ctx, T.filters, {
				onChange: changeFilter, onRetry: load,
				getMainWindows: function () { return { funnel: state.funnel, minutes: state.minutes }; },
				getWindows: function () { if ( dimensions && dimensions.getWindows ) { return dimensions.getWindows(); } var values = {}; [ 'viewed', 'carted', 'sold', 'map', 'active_countries', 'popular_countries', 'sources', 'collections', 'pages' ].forEach( function ( key ) { values[ key ] = 10; } ); return values; }
			} );
			if ( dimensions && dimensions.setWindowsListener ) { dimensions.setWindowsListener( function () { if ( filters ) { filters.windowsChanged(); } } ); }
		} else if ( T.filters && typeof T.filters === 'object' ) { bodyEl.querySelector( '.pc-rt-filters-host' ).textContent = T.filters.unavailable; }

		els.refresh.addEventListener( 'click', function () {
			load();
		} );
		els.funnelSelect.addEventListener( 'change', function () {
			choose( 'funnel', els.funnelSelect.value );
		} );
		els.minutesSelect.addEventListener( 'change', function () {
			choose( 'minutes', els.minutesSelect.value );
		} );
		els.live.addEventListener( 'click', function () { select( { mode: 'live', params: {} }, 'push' ); } );
		[ 'today', 'yesterday' ].forEach( function ( preset ) {
			els[ preset ].addEventListener( 'click', function () { select( { mode: 'history', params: { range: preset } }, 'push' ); } );
		} );
		els.range.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! validDates( els.from.value, els.to.value ) ) {
				wanted = { mode: 'history', params: { from: els.from.value, to: els.to.value }, filter: wanted.filter };
				urlAction = 'push';
				schedule();
				++requests;
				setBusy( false );
				showError( T.rangeError );
				if ( filters ) { filters.beginMain(); filters.failMain(); els.root.classList.toggle( 'is-stale', painted ); }
				if ( dimensions ) { dimensions.failMain(); }
				return;
			}
			select( { mode: 'history', params: { from: els.from.value, to: els.to.value } }, 'push' );
		} );
	}

	function validDates( from, to ) {
		function day( value ) {
			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) { return null; }
			var parts = value.split( '-' );
			var date = new Date( 0 );
			date.setUTCFullYear( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
			return date.getUTCFullYear() === Number( parts[ 0 ] ) && date.getUTCMonth() === Number( parts[ 1 ] ) - 1 && date.getUTCDate() === Number( parts[ 2 ] ) ? date.getTime() : null;
		}
		var first = day( from );
		var last = day( to );
		return null !== first && null !== last && last >= first && ( last - first ) / 86400000 < 366;
	}

	function fromUrl() {
		try {
			if ( ! window.URLSearchParams || ! window.location ) { return { mode: 'live', params: {} }; }
			var q = new window.URLSearchParams( window.location.search ), params = {}, result;
			[ 'range', 'from', 'to' ].forEach( function ( key ) { if ( q.has( key ) ) { params[ key ] = q.get( key ); } } );
			result = { mode: Object.keys( params ).length ? 'history' : 'live', params: params };
			if ( filters ) { result.filter = window.PcDataRealtimeFilters.parseUrl( window.location.search ); result.filterError = ! result.filter; }
			return result;
		} catch ( error ) { return filters ? { mode: 'live', params: {}, filterError: true } : { mode: 'live', params: {} }; }
	}

	function writeUrl( data, action ) {
		if ( ! action || ! window.history || ! window.URL ) { return; }
		try {
			var url = new window.URL( window.location.href );
			[ 'range', 'from', 'to', 'compare_from', 'compare_to' ].forEach( function ( key ) { url.searchParams[ 'delete' ]( key ); } );
			if ( 'history' === data.mode ) {
				var range = data.range;
				if ( range.preset && ! filters ) { url.searchParams.set( 'range', range.preset ); }
				else { url.searchParams.set( 'from', range.dates.from ); url.searchParams.set( 'to', range.dates.to ); }
			}
			if ( filters ) {
				[ 'rt_entry_state', 'rt_entry', 'rt_utm_state', 'rt_utm' ].forEach( function ( key ) { url.searchParams[ 'delete' ]( key ); } );
				var value = data.filter;
				if ( value ) { url.searchParams.set( 'rt_entry_state', value.entry_domain_state ); url.searchParams.set( 'rt_utm_state', value.utm_source_state ); if ( value.entry_domain_state === 'value' ) { url.searchParams.set( 'rt_entry', value.entry_domain ); } if ( value.utm_source_state === 'value' ) { url.searchParams.set( 'rt_utm', value.utm_source ); } }
			}

			var method = 'push' === action ? 'pushState' : 'replaceState';
			if ( window.history[ method ] && ( 'replace' === action || url.toString() !== window.location.href ) ) {
				window.history[ method ]( null, '', url.toString() );
			}
		} catch ( e ) { /* Browser URL support is optional. */ }
	}

	function select( selection, action ) {
		if ( filters && ! selection.filterError ) { selection.filter = window.PcDataRealtimeFilters.normalize( selection.filter || wanted.filter || window.PcDataRealtimeFilters.all() ); filters.setDesired( selection.filter ); }
		wanted = selection;
		if ( Object.prototype.hasOwnProperty.call( selection.params, 'from' ) || Object.prototype.hasOwnProperty.call( selection.params, 'to' ) ) {
			els.from.value = selection.params.from || '';
			els.to.value = selection.params.to || '';
		}
		urlAction = action;
		schedule();
		load();
	}

	function changeFilter( value, force ) {
		if ( ! filters || rangeBusy ) { return; }
		if ( ! force && wanted.filter && window.PcDataRealtimeFilters.same( wanted.filter, value ) ) { return; }
		var base = acceptedMain || wanted;
		if ( base.mode === 'history' && ( ! acceptedMain && ( base.params.from !== undefined || base.params.to !== undefined ) && ! validDates( base.params.from, base.params.to ) ) ) { filters.failMain(); showError( T.rangeError ); return; }
		select( { mode: base.mode, params: base.params, filter: value }, 'push' );
	}

	// 选了新的窗口：只认 10 / 20 / 30，记下来，马上重新取数。
	function choose( which, value ) {
		var n = parseInt( value, 10 );
		if ( ( 'funnel' === which ? FUNNEL_WINDOWS : WINDOWS ).indexOf( n ) < 0 ) {
			return;
		}
		state[ which ] = n;
		saveWindow( 'funnel' === which ? KEY_FUNNEL : KEY_MINUTES, n );
		load();
	}

	// ---------- 取数和画 ----------

	function paint( data ) {
		displayedHistory = 'history' === data.mode;
		els.root.classList.toggle( 'is-history', displayedHistory );
		var range = data.range || {};
		els.period.textContent = displayedHistory ? ( ( range.preset === 'today' ? T.todayOption : ( range.preset === 'yesterday' ? T.yesterday : T.custom ) ) + ': ' + range.dates.from + ' – ' + range.dates.to + ' · ' + range.timezone ) : T.live + ' · ' + ( cfg.timezone || '' );
		[ 'live', 'today', 'yesterday' ].forEach( function ( key ) {
			var on = 'live' === key ? ! displayedHistory : displayedHistory && range.preset === key;
			els[ key ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			els[ key ].classList.toggle( 'is-active', on );
		} );
		els.funnelSelect.hidden = displayedHistory;
		els.minutesSelect.hidden = displayedHistory;
		els[ 'funnel-title' ].textContent = displayedHistory ? T.historyFunnel : T.behaviour;
		els[ 'chart-title' ].textContent = displayedHistory ? T.historyChart : T.perMinute;
		els[ 'refresh-note' ].textContent = displayedHistory ? T.manualRefresh : T.autoRefresh;
		if ( displayedHistory ) { els.from.value = range.dates.from; els.to.value = range.dates.to; }
		els.updated.textContent = fill( T.updated, { '%s': data.updated || '' } );
		els.notes.innerHTML = notes( data );
		els.cards.innerHTML = cards( data );
		var detail = data.visitor_funnel && data.visitor_funnel.unit === 'visitors' && data.visitor_funnel.layers && data.visitor_funnel.layers.length === 8;
		if ( els[ 'funnel-tip' ] ) { els[ 'funnel-tip' ].innerHTML = tip( detail ? T.detailHelp : T.detailUnavailable ); }
		els.steps.innerHTML = steps( detail ? data.visitor_funnel.layers : ( data.funnel || {} ).layers || [], detail );
		lastPoints = ( ( displayedHistory ? data.series : data.minutes ) || {} ).points || [];
		els.chart.innerHTML = chart( lastPoints );
		els.lists.innerHTML = lists( data );
		painted = true;
	}

	function setBusy( on ) {
		els.refresh.disabled = on;
	}

	function showError( message ) {
		// 第一次就失败：把“正在加载”拿掉；以后失败：保留上一次的数字，只显示错误。
		if ( ! painted ) {
			els.cards.innerHTML = '';
		}
		els.error.textContent = message || ctx.strings.loadError;
		els.error.hidden = false;
	}

	function load() {
		if ( ! els || rangeBusy ) { return; }
		if ( filters && wanted.filterError ) { ++requests; setBusy( false ); dimensions && dimensions.beginMain(); dimensions && dimensions.failMain(); filters.failMain( true ); showError( T.filters.urlError ); return; }
		var mine = ++requests;
		var selection = wanted;
		var action = urlAction;
		setBusy( true );
		if ( dimensions ) { dimensions.beginMain(); }
		if ( filters ) { filters.beginMain(); els.root.classList.toggle( 'is-stale', painted ); }
		var active = filters && window.PcDataRealtimeFilters.active( selection.filter ), params = 'history' === selection.mode ? selection.params : { funnel: state.funnel, minutes: state.minutes };
		if ( active ) { var filtered = { mode: selection.mode }; Object.keys( params ).forEach( function ( key ) { filtered[ key ] = params[ key ]; } ); Object.keys( selection.filter ).forEach( function ( key ) { filtered[ key ] = selection.filter[ key ]; } ); params = filtered; }
		ctx.api( active ? PATH + '/filtered' : PATH + ( 'history' === selection.mode ? '/history' : '' ), params ).then(
			function ( data ) {
				if ( mine !== requests || ! attached() ) {
					return;
				}
				setBusy( false );
				try {
					if ( ( 'history' === selection.mode ) !== ( 'history' === data.mode ) ) { throw new Error( 'Unexpected report mode' ); }
					if ( dimensions && ( selection.mode === 'live' ? typeof data.now_ms !== 'number' || ! isFinite( data.now_ms ) || data.now_ms <= 0 || Math.floor( data.now_ms ) !== data.now_ms : ! data.range || ! data.range.dates || ! validDates( data.range.dates.from, data.range.dates.to ) || typeof data.range.from !== 'number' || ! isFinite( data.range.from ) || typeof data.range.to !== 'number' || ! isFinite( data.range.to ) || data.range.to <= data.range.from || typeof data.range.timezone !== 'string' || ! data.range.timezone ) ) { throw new Error( 'Invalid accepted realtime context' ); }
					if ( filters && ( active ? ! window.PcDataRealtimeFilters.same( data.filter, selection.filter ) : data.filter !== undefined ) ) { throw new Error( 'Unexpected entry filter echo' ); }
					paint( data );
					if ( filters ) { acceptedMain = { mode: selection.mode, params: selection.mode === 'history' ? { from: data.range.dates.from, to: data.range.dates.to } : {}, filter: window.PcDataRealtimeFilters.normalize( selection.filter ) }; wanted = acceptedMain; filters.acceptMain( acceptedMain, data ); els.root.classList.toggle( 'is-stale', false ); }
					if ( dimensions ) { dimensions.acceptMain( selection, data ); }
					writeUrl( data, action );
					urlAction = '';
					els.error.textContent = '';
					els.error.hidden = true;
				} catch ( e ) {
					showError();
					if ( filters ) { filters.failMain(); }
					if ( dimensions ) { dimensions.failMain(); }
				}
			},
			function ( error ) {
				if ( mine !== requests || ! attached() ) {
					return;
				}
				setBusy( false );
				showError( error && ( 'pc_invalid_param' === error.code || error.data && 400 === Number( error.data.status ) ) ? T.rangeError : '' );
				if ( filters ) { filters.failMain( false, error ); }
				if ( dimensions ) { dimensions.failMain(); }
			}
		);
	}

	// 页面还在文档里才刷新（页面被换掉后旧的框架不再取数）。
	function attached() {
		return !! els && document.documentElement.contains( els.root );
	}

	// 只留一个定时器：先清掉旧的再建新的。页面可见时每 60 秒取一次数。
	function schedule() {
		if ( timer ) {
			window.clearInterval( timer );
			timer = 0;
		}
		if ( 'live' !== wanted.mode ) { return; }
		timer = window.setInterval( function () {
			if ( ! attached() ) {
				window.clearInterval( timer );
				timer = 0;
				return;
			}
			if ( 'live' === wanted.mode && 'hidden' !== document.visibilityState ) {
				load();
			}
		}, REFRESH_MS );
	}

	// visibilitychange 只绑定一次：页面从隐藏变回可见时马上取一次。
	function listen() {
		if ( listening ) {
			return;
		}
		listening = true;
		document.addEventListener( 'visibilitychange', function () {
			if ( 'live' === wanted.mode && 'hidden' !== document.visibilityState && attached() ) {
				load();
			}
		} );
		window.addEventListener( 'popstate', function () { if ( attached() ) { select( fromUrl(), '' ); } } );
		// 折线图按容器宽度画：窗口宽度变了就用上一次的数据重画（不重新取数）。
		window.addEventListener( 'resize', function () {
			if ( resizeTimer ) { window.clearTimeout( resizeTimer ); }
			resizeTimer = window.setTimeout( function () {
				resizeTimer = 0;
				if ( attached() && painted && lastPoints ) { els.chart.innerHTML = chart( lastPoints ); }
			}, 150 );
		} );
	}

	function render( bodyEl, range, c ) {
		if ( rangeRecovery ) { window.clearTimeout( rangeRecovery ); rangeRecovery = 0; }
		if ( rangeUnsubscribe ) { rangeUnsubscribe(); rangeUnsubscribe = null; }
		if ( filters ) { filters.dispose(); filters = null; }
		acceptedMain = null; rangeBusy = false;
		if ( dimensions ) { dimensions.dispose(); dimensions = null; }
		ctx = c;
		// 旧框架上还没回来的请求作废，再建新框架。
		requests++;
		build( bodyEl );
		if ( range && range.dates ) { els.from.value = range.dates.from; els.to.value = range.dates.to; }
		wanted = fromUrl();
		if ( filters && ! wanted.filterError ) { filters.setDesired( wanted.filter ); }
		if ( filters && ctx.onRangeState ) { rangeUnsubscribe = ctx.onRangeState( function ( on ) { var changed = rangeBusy !== !! on; rangeBusy = !! on; if ( rangeRecovery ) { window.clearTimeout( rangeRecovery ); rangeRecovery = 0; } if ( rangeBusy ) { ++requests; setBusy( true ); filters.beginMain(); dimensions && dimensions.beginMain(); els.root.classList.toggle( 'is-stale', painted ); } else if ( changed && attached() ) { rangeRecovery = window.setTimeout( function () { rangeRecovery = 0; if ( ! attached() || rangeBusy ) { return; } if ( acceptedMain ) { wanted = acceptedMain; filters.setDesired( wanted.filter ); load(); } else { setBusy( false ); filters.failMain(); dimensions && dimensions.failMain(); } }, 0 ); } } ); }
		if ( wanted.params.from !== undefined ) { els.from.value = wanted.params.from; }
		if ( wanted.params.to !== undefined ) { els.to.value = wanted.params.to; }
		urlAction = 'replace';
		schedule();
		listen();
		load();
	}

	PcData.register( 'realtime', render );
} )( window.PcData, window.pcDataRealtime );
