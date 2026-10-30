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
	var KEY_FUNNEL = 'pcDataRealtimeFunnel';
	var KEY_MINUTES = 'pcDataRealtimeMinutes';
	var REFRESH_MS = 60000;
	// 和 Operations::LAYERS 的顺序一致；对应 strings 里的 key（第一层用 funnelVisitors，避免和卡片的 visitors 混）。
	var LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'paid' ];
	var LAYER_TEXT = { visitors: 'funnelVisitors', viewed: 'viewed', carted: 'carted', checkout: 'checkout', paid: 'paid' };

	var state = { funnel: readWindow( KEY_FUNNEL ), minutes: readWindow( KEY_MINUTES ) };
	var wanted = { mode: 'live', params: {} };
	var urlAction = '';
	var displayedHistory = false;
	var requests = 0;
	var timer = 0;
	var listening = false;
	var painted = false;
	var ctx = null;
	var els = null;

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
	function readWindow( key ) {
		try {
			var n = parseInt( window.localStorage.getItem( key ), 10 );
			return WINDOWS.indexOf( n ) >= 0 ? n : WINDOWS[ 0 ];
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

	function metric( label, value, today ) {
		return '<div class="pc-rt-metric">' +
			'<div class="pc-rt-metric__label">' + esc( label ) + '</div>' +
			'<div class="pc-rt-metric__value">' + esc( value ) +
				( displayedHistory ? '' : ' <span class="pc-rt-metric__today">' + esc( fill( T.today, { '%s': today } ) ) + '</span>' ) +
			'</div>' +
		'</div>';
	}

	function card( title, first, second ) {
		return '<section class="pc-rt-card">' +
			'<h2 class="pc-rt-card__title">' + esc( title ) + ' <span class="pc-rt-muted">' + esc( displayedHistory ? T.period : T.windowNote ) + '</span></h2>' +
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

	function steps( layers ) {
		return LAYERS.map( function ( key, i ) {
			var layer = layers[ i ] || { count: 0, visitor_rate: null };
			var width = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
			return '<div class="pc-rt-step">' +
				'<span class="pc-rt-step__label">' + esc( T[ LAYER_TEXT[ key ] ] ) + '</span>' +
				'<span class="pc-rt-bar"><span class="pc-rt-bar__fill" style="width:' + esc( width ) + '%"></span></span>' +
				'<span class="pc-rt-step__count">' + esc( ctx.number( layer.count ) ) + '</span>' +
				'<span class="pc-rt-step__rate">' + esc( fill( T.ofVisitors, { '%s': pct( layer.visitor_rate ) } ) ) + '</span>' +
			'</div>';
		} ).join( '' );
	}

	// 每分钟访客数量：一列一分钟，最旧的在左；柱高相对最大值（最高 80%，上面留给数字），0 显示一条细线。
	function chart( points ) {
		var max = 0;
		points.forEach( function ( p ) {
			max = Math.max( max, Number( p.visitors ) || 0 );
		} );
		var sparse = points.length > WINDOWS[ 0 ];
		var cols = points.map( function ( p ) {
			var v = Number( p.visitors ) || 0;
			var height = max > 0 ? Math.round( v / max * 80 ) : 0;
			var ago = Number( p.ago ) || 0;
			// 20 / 30 分钟时只给 ago 是偶数的列写标签，这样“现在”（ago 0）一定有标签。
			var label = displayedHistory ? p.label : ( ! sparse || 0 === ago % 2 ? ( 0 === ago ? T.now : fill( T.minutesAgo, { '%d': ago } ) ) : '' );
			return '<div class="pc-rt-col">' +
				'<div class="pc-rt-col__plot">' +
					'<span class="pc-rt-col__count">' + esc( ctx.number( v ) ) + '</span>' +
					'<span class="pc-rt-col__bar' + ( 0 === v ? ' is-zero' : '' ) + '" style="height:' + esc( height ) + '%"></span>' +
				'</div>' +
				'<span class="pc-rt-col__label">' + esc( label ) + '</span>' +
			'</div>';
		} );
		return '<div class="pc-rt-chart">' + cols.join( '' ) + '</div>';
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

	function notes( data ) {
		var html = '';
		if ( ! data.tracking ) {
			html += '<p class="pc-rt-note">' + esc( T.trackingOff ) + '</p>';
		}
		html += '<p class="pc-rt-muted">' + esc( T.notYet ) + '</p>';
		if ( displayedHistory ) {
			html += '<p class="pc-rt-muted">' + esc( T.historyNote ) + '</p>';
		}
		return html;
	}

	// ---------- 页面框架（只建一次） ----------

	function selectHtml( cls, label, current ) {
		var options = WINDOWS.map( function ( n ) {
			return '<option value="' + esc( n ) + '"' + ( n === current ? ' selected' : '' ) + '>' + esc( fill( T.minutesOption, { '%d': n } ) ) + '</option>';
		} ).join( '' );
		return '<select class="pc-rt-select ' + cls + '" aria-label="' + esc( label ) + '">' + options + '</select>';
	}

	function build( bodyEl ) {
		bodyEl.innerHTML = '<div class="pc-rt">' +
			'<form class="pc-rt-range" novalidate>' +
				'<div class="pc-rt-modes" role="group" aria-label="' + esc( T.period ) + '">' +
					'<button type="button" class="button pc-rt-live" aria-pressed="false">' + esc( T.live ) + '</button>' +
					'<button type="button" class="button pc-rt-today" aria-pressed="false">' + esc( T.todayOption ) + '</button>' +
					'<button type="button" class="button pc-rt-yesterday" aria-pressed="false">' + esc( T.yesterday ) + '</button>' +
				'</div>' +
				'<label>' + esc( T.from ) + '<input class="pc-rt-from" type="date" required></label>' +
				'<label>' + esc( T.to ) + '<input class="pc-rt-to" type="date" required></label>' +
				'<button type="submit" class="button pc-rt-apply">' + esc( T.apply ) + '</button>' +
			'</form>' +
			'<p class="pc-rt-period" aria-live="polite"></p>' +
			'<div class="pc-rt-head">' +
				'<span class="pc-rt-updated"></span>' +
				'<button type="button" class="button pc-rt-refresh">' + esc( T.refresh ) + '</button>' +
				'<span class="pc-rt-muted pc-rt-refresh-note"></span>' +
			'</div>' +
			'<p class="pc-rt-error" role="alert" hidden></p>' +
			'<div class="pc-rt-notes"></div>' +
			'<div class="pc-rt-cards-wrap"><p class="pc-rt-muted">' + esc( ctx.strings.loading ) + '</p></div>' +
			'<section class="pc-rt-section">' +
				'<div class="pc-rt-section__head">' +
					'<h2 class="pc-rt-section__title pc-rt-funnel-title">' + esc( T.behaviour ) + '</h2>' +
					selectHtml( 'pc-rt-funnel-select', T.behaviour, state.funnel ) +
				'</div>' +
				'<div class="pc-rt-steps"></div>' +
			'</section>' +
			'<section class="pc-rt-section">' +
				'<div class="pc-rt-section__head">' +
					'<h2 class="pc-rt-section__title pc-rt-chart-title">' + esc( T.perMinute ) + '</h2>' +
					selectHtml( 'pc-rt-minutes-select', T.perMinute, state.minutes ) +
				'</div>' +
				'<div class="pc-rt-chart-wrap" tabindex="0" role="region" aria-label="' + esc( T.historyChart ) + '"></div>' +
			'</section>' +
			'<div class="pc-rt-lists"></div>' +
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
		[ 'range', 'from', 'to', 'live', 'today', 'yesterday', 'period', 'refresh-note', 'funnel-title', 'chart-title' ].forEach( function ( name ) {
			els[ name ] = bodyEl.querySelector( '.pc-rt-' + name );
		} );
		painted = false;

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
				wanted = { mode: 'history', params: { from: els.from.value, to: els.to.value } };
				urlAction = 'push';
				schedule();
				++requests;
				setBusy( false );
				showError( T.rangeError );
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
			var q = new window.URLSearchParams( window.location.search );
			var params = {};
			[ 'range', 'from', 'to' ].forEach( function ( key ) { if ( q.has( key ) ) { params[ key ] = q.get( key ); } } );
			return { mode: Object.keys( params ).length ? 'history' : 'live', params: params };
		} catch ( e ) { return { mode: 'live', params: {} }; }
	}

	function writeUrl( data, action ) {
		if ( ! action || ! window.history || ! window.URL ) { return; }
		try {
			var url = new window.URL( window.location.href );
			[ 'range', 'from', 'to', 'compare_from', 'compare_to' ].forEach( function ( key ) { url.searchParams[ 'delete' ]( key ); } );
			if ( 'history' === data.mode ) {
				var range = data.range;
				if ( range.preset ) { url.searchParams.set( 'range', range.preset ); }
				else { url.searchParams.set( 'from', range.dates.from ); url.searchParams.set( 'to', range.dates.to ); }
			}
			var method = 'push' === action ? 'pushState' : 'replaceState';
			if ( window.history[ method ] && ( 'replace' === action || url.toString() !== window.location.href ) ) {
				window.history[ method ]( null, '', url.toString() );
			}
		} catch ( e ) { /* Browser URL support is optional. */ }
	}

	function select( selection, action ) {
		wanted = selection;
		if ( Object.prototype.hasOwnProperty.call( selection.params, 'from' ) || Object.prototype.hasOwnProperty.call( selection.params, 'to' ) ) {
			els.from.value = selection.params.from || '';
			els.to.value = selection.params.to || '';
		}
		urlAction = action;
		schedule();
		load();
	}

	// 选了新的窗口：只认 10 / 20 / 30，记下来，马上重新取数。
	function choose( which, value ) {
		var n = parseInt( value, 10 );
		if ( WINDOWS.indexOf( n ) < 0 ) {
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
		els.steps.innerHTML = steps( ( data.funnel || {} ).layers || [] );
		els.chart.innerHTML = chart( ( ( displayedHistory ? data.series : data.minutes ) || {} ).points || [] );
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
		if ( ! els ) {
			return;
		}
		var mine = ++requests;
		var selection = wanted;
		var action = urlAction;
		setBusy( true );
		ctx.api( PATH + ( 'history' === selection.mode ? '/history' : '' ), 'history' === selection.mode ? selection.params : { funnel: state.funnel, minutes: state.minutes } ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				setBusy( false );
				try {
					if ( ( 'history' === selection.mode ) !== ( 'history' === data.mode ) ) { throw new Error( 'Unexpected report mode' ); }
					paint( data );
					writeUrl( data, action );
					urlAction = '';
					els.error.textContent = '';
					els.error.hidden = true;
				} catch ( e ) {
					showError();
				}
			},
			function ( error ) {
				if ( mine !== requests ) {
					return;
				}
				setBusy( false );
				showError( error && ( 'pc_invalid_param' === error.code || error.data && 400 === Number( error.data.status ) ) ? T.rangeError : '' );
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
	}

	function render( bodyEl, range, c ) {
		ctx = c;
		// 旧框架上还没回来的请求作废，再建新框架。
		requests++;
		build( bodyEl );
		if ( range && range.dates ) { els.from.value = range.dates.from; els.to.value = range.dates.to; }
		wanted = fromUrl();
		if ( wanted.params.from !== undefined ) { els.from.value = wanted.params.from; }
		if ( wanted.params.to !== undefined ) { els.to.value = wanted.params.to; }
		urlAction = 'replace';
		schedule();
		listen();
		load();
	}

	PcData.register( 'realtime', render );
} )( window.PcData, window.pcDataRealtime );
