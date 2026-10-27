/**
 * 实时数据页（DATA-01 第 6 步）的报表：三张卡片（实时访问、实时销售、实时购买力，最近 10 分钟，每个数字后面带今日合计）、
 * 实时访客行为漏斗、每分钟访客数量（柱状图），以及今日的四个列表（浏览最多、加购最多、热卖商品、热门页面）。
 * 数字都来自实时数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Realtime，这里不自己算），
 * 文字来自 window.pcDataRealtime.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 realtime 页面的报表，由 data.js 的外壳调用（这个页面没有时间段工具栏，range 用不上）。
 *
 * 在脚本里保留的状态：两个窗口选择（漏斗、每分钟图，10 / 20 / 30 分钟，记在浏览器的 localStorage 里）、请求计数（晚到的旧响应不再画）、
 * 唯一的自动刷新定时器。页面框架只在 render() 里建一次，每次刷新只换里面的数字，所以选择框不会在刷新时被重画。
 * 自动刷新：页面可见时每 60 秒取一次数；页面从隐藏变回可见时马上取一次；render() 被调用多次也只有一个定时器、一个 visibilitychange 监听。
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
				' <span class="pc-rt-metric__today">' + esc( fill( T.today, { '%s': today } ) ) + '</span>' +
			'</div>' +
		'</div>';
	}

	function card( title, first, second ) {
		return '<section class="pc-rt-card">' +
			'<h2 class="pc-rt-card__title">' + esc( title ) + ' <span class="pc-rt-muted">' + esc( T.windowNote ) + '</span></h2>' +
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
				T.visits,
				metric( T.visitors, ctx.number( now.visitors ), ctx.number( day.visitors ) ),
				metric( T.pageviews, ctx.number( now.pageviews ), ctx.number( day.pageviews ) )
			) +
			card(
				T.sales,
				metric( T.orders, ctx.number( now.orders ), ctx.number( day.orders ) ),
				metric( withCode( T.salesAmount ), ctx.money( now.sales ), ctx.money( day.sales ) )
			) +
			card(
				T.power,
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
			var label = ! sparse || 0 === ago % 2 ? ( 0 === ago ? T.now : fill( T.minutesAgo, { '%d': ago } ) ) : '';
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
		return box( T.listViewed, l.viewed, 'product', links.products ) +
			box( T.listCarted, l.carted, 'product', links.products ) +
			box( T.listSold, l.sold, 'product', links.products ) +
			box( T.listPages, l.pages, 'page', links.pages );
	}

	function notes( data ) {
		var html = '';
		if ( ! data.tracking ) {
			html += '<p class="pc-rt-note">' + esc( T.trackingOff ) + '</p>';
		}
		html += '<p class="pc-rt-muted">' + esc( T.notYet ) + '</p>';
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
			'<div class="pc-rt-head">' +
				'<span class="pc-rt-updated"></span>' +
				'<button type="button" class="button pc-rt-refresh">' + esc( T.refresh ) + '</button>' +
				'<span class="pc-rt-muted">' + esc( T.autoRefresh ) + '</span>' +
			'</div>' +
			'<p class="pc-rt-error" role="alert" hidden></p>' +
			'<div class="pc-rt-notes"></div>' +
			'<div class="pc-rt-cards-wrap"><p class="pc-rt-muted">' + esc( ctx.strings.loading ) + '</p></div>' +
			'<section class="pc-rt-section">' +
				'<div class="pc-rt-section__head">' +
					'<h2 class="pc-rt-section__title">' + esc( T.behaviour ) + '</h2>' +
					selectHtml( 'pc-rt-funnel-select', T.behaviour, state.funnel ) +
				'</div>' +
				'<div class="pc-rt-steps"></div>' +
			'</section>' +
			'<section class="pc-rt-section">' +
				'<div class="pc-rt-section__head">' +
					'<h2 class="pc-rt-section__title">' + esc( T.perMinute ) + '</h2>' +
					selectHtml( 'pc-rt-minutes-select', T.perMinute, state.minutes ) +
				'</div>' +
				'<div class="pc-rt-chart-wrap"></div>' +
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
		els.updated.textContent = fill( T.updated, { '%s': data.updated || '' } );
		els.notes.innerHTML = notes( data );
		els.cards.innerHTML = cards( data );
		els.steps.innerHTML = steps( ( data.funnel || {} ).layers || [] );
		els.chart.innerHTML = chart( ( data.minutes || {} ).points || [] );
		els.lists.innerHTML = lists( data );
		painted = true;
	}

	function setBusy( on ) {
		els.refresh.disabled = on;
	}

	function showError() {
		// 第一次就失败：把“正在加载”拿掉；以后失败：保留上一次的数字，只显示错误。
		if ( ! painted ) {
			els.cards.innerHTML = '';
		}
		els.error.textContent = ctx.strings.loadError;
		els.error.hidden = false;
	}

	function load() {
		if ( ! els ) {
			return;
		}
		var mine = ++requests;
		setBusy( true );
		ctx.api( PATH, { funnel: state.funnel, minutes: state.minutes } ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				setBusy( false );
				try {
					paint( data );
					els.error.textContent = '';
					els.error.hidden = true;
				} catch ( e ) {
					showError();
				}
			},
			function () {
				if ( mine !== requests ) {
					return;
				}
				setBusy( false );
				showError();
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
		timer = window.setInterval( function () {
			if ( ! attached() ) {
				window.clearInterval( timer );
				timer = 0;
				return;
			}
			if ( 'hidden' !== document.visibilityState ) {
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
			if ( 'hidden' !== document.visibilityState && attached() ) {
				load();
			}
		} );
	}

	function render( bodyEl, range, c ) {
		ctx = c;
		// 旧框架上还没回来的请求作废，再建新框架。
		requests++;
		build( bodyEl );
		schedule();
		listen();
		load();
	}

	PcData.register( 'realtime', render );
} )( window.PcData, window.pcDataRealtime );
