/**
 * 后台“概览”数据大盘（OVERVIEW-01），版式参照 Shopname 的概览页。
 * 数据：window.pcOverview（首屏），切换时间段时 GET /peptide/v1/overview?range=…。所有文字都经过转义。
 */
( function ( wp, cfg ) {
	'use strict';

	var root = document.getElementById( 'pc-overview' );
	if ( ! wp || ! wp.apiFetch || ! cfg || ! root ) {
		return;
	}
	var S = cfg.strings;
	var L = cfg.links;
	var summary = cfg.summary;
	var range = cfg.range;
	// 顶部数字和“实时”固定显示今天；下面的漏斗、趋势、商品排名跟着时间段切换。
	var todayData = cfg.range;

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function fmt( tpl, v ) {
		return tpl.replace( /%[sd]/, v );
	}
	function money( n ) {
		return Number( n || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}
	function pct( a, b ) {
		return b ? Math.round( ( a / b ) * 10000 ) / 100 + '%' : '—';
	}
	function link( href, text ) {
		return '<a href="' + esc( href ) + '">' + esc( text ) + ' ›</a>';
	}

	// ---------- 顶部 ----------

	function hero() {
		var cur = summary.currency.code;
		var t = todayData;
		var kpis = [
			[ S.sales + ' (' + cur + ')', money( t.sales ) ],
			[ S.visitors, t.visitors ],
			[ S.registered, t.registered ],
			[ S.orders, t.orders ],
			[ S.conversion, null === t.conversion ? '—' : t.conversion + '%' ],
			[ S.aov + ' (' + cur + ')', money( t.aov ) ],
			[ S.returning, t.returning ],
		];
		return '<section class="pc-ov-hero"><p class="pc-ov-hero__hello">' + esc( fmt( S[ summary.greeting ], summary.store ) ) + '</p>' +
			'<div class="pc-ov-hero__panel"><div class="pc-ov-hero__time"><span>' + esc( S.storeTime + ': ' + summary.tz_label + ' ' + summary.now ) + '</span></div>' +
			'<div class="pc-ov-kpis">' + kpis.map( function ( k ) {
				return '<div class="pc-ov-kpi"><span>' + esc( k[ 0 ] ) + '</span><b>' + esc( k[ 1 ] ) + '</b></div>';
			} ).join( '' ) + '</div></div></section>';
	}

	function side() {
		var h = summary.holidays.map( function ( d ) {
			return '<li><span>' + esc( S.holidayNames[ d.key ] || d.key ) + '</span><span>' + esc( d.date ) + '</span><span class="pc-ov-muted">' + esc( d.days ? fmt( S.inDays, d.days ) : S.todayTag ) + '</span></li>';
		} ).join( '' );
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.currency ) + '</h2></div><p><b>' + esc( summary.currency.code + ' (' + summary.currency.symbol + ')' ) + '</b> <span class="pc-ov-tag">' + esc( S.default ) + '</span></p>' +
			'<div class="pc-ov-card__head"><h2>' + esc( S.holidays ) + '</h2></div><ul class="pc-ov-holidays">' + h + '</ul></section>';
	}

	// ---------- 第二行 ----------

	function todo() {
		var t = summary.todo;
		var cell = function ( label, n, href ) {
			return '<a class="pc-ov-num" href="' + esc( href ) + '"><span>' + esc( label ) + '</span><b class="' + ( n ? 'is-warn' : '' ) + '">' + esc( n ) + '</b></a>';
		};
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.todo ) + '</h2></div><div class="pc-ov-nums">' +
			cell( S.toShip, t.to_ship, L.orders ) + cell( S.onHold, t.on_hold, L.onHold ) + cell( S.missingBatch, t.missing_batch, L.orders ) + cell( S.noReport, t.batches_no_report, L.batches ) + '</div></section>';
	}

	function unfinished() {
		var t = summary.todo;
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.unfinished ) + '</h2></div><div class="pc-ov-nums">' +
			'<a class="pc-ov-num" href="' + esc( L.unpaid ) + '"><span>' + esc( S.unpaid ) + '</span><b>' + esc( t.unpaid_7d ) + '</b></a>' +
			'<a class="pc-ov-num" href="' + esc( L.failed ) + '"><span>' + esc( S.failed ) + '</span><b>' + esc( t.failed_7d ) + '</b></a></div><p class="pc-ov-muted">' + esc( S.unfinishedNote ) + '</p></section>';
	}

	function live( today ) {
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.live ) + '</h2>' + link( L.visitors, S.more ) + '</div><div class="pc-ov-nums">' +
			'<div class="pc-ov-num"><span>' + esc( S.online ) + '</span><b>' + esc( summary.online ) + '</b></div>' +
			'<div class="pc-ov-num"><span>' + esc( S.todayOrders ) + '</span><b>' + esc( today.orders ) + '</b></div>' +
			'<div class="pc-ov-num"><span>' + esc( S.todaySales + ' (' + summary.currency.code + ')' ) + '</span><b>' + esc( money( today.sales ) ) + '</b></div></div></section>';
	}

	// ---------- 第三行 ----------

	function funnel() {
		var f = range.funnel || [];
		if ( ! f.length ) {
			return '<p class="pc-ov-muted">' + esc( S.noData ) + '</p>';
		}
		var base = f[ 0 ].count;
		return '<div class="pc-ov-funnel">' + f.map( function ( s, i ) {
			var prev = i ? f[ i - 1 ].count : s.count;
			var width = 100 - i * 7;
			return '<div class="pc-ov-step"><div class="pc-ov-step__bar" style="width:' + width + '%"><b>' + esc( S.stages[ s.stage ] ) + ': ' + esc( s.count ) + '</b>' + ( i ? ' <span>' + esc( fmt( S.ofVisitors, pct( s.count, base ) ) ) + '</span>' : '' ) + '</div>' + ( i ? '<span class="pc-ov-step__rate">' + esc( pct( s.count, prev ) ) + '</span>' : '' ) + '</div>';
		} ).join( '' ) + '</div>';
	}

	function chart() {
		var pts = range.series || [];
		var w = 640;
		var h = 240;
		var pad = { l: 34, r: 10, t: 12, b: 28 };
		var max = 1;
		pts.forEach( function ( p ) {
			max = Math.max( max, p.visitors, p.orders );
		} );
		var step = Math.max( 1, Math.ceil( max / 4 ) );
		max = step * 4;
		var x = function ( i ) {
			return pad.l + ( pts.length > 1 ? ( i * ( w - pad.l - pad.r ) ) / ( pts.length - 1 ) : 0 );
		};
		var y = function ( v ) {
			return h - pad.b - ( v / max ) * ( h - pad.t - pad.b );
		};
		var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="pc-ov-chart" role="img" aria-label="' + esc( S.trend ) + '">';
		for ( var g = 0; g <= 4; g++ ) {
			var gy = y( g * step );
			svg += '<line x1="' + pad.l + '" x2="' + ( w - pad.r ) + '" y1="' + gy + '" y2="' + gy + '" class="pc-ov-grid"/><text x="' + ( pad.l - 6 ) + '" y="' + ( gy + 4 ) + '" text-anchor="end">' + g * step + '</text>';
		}
		var every = Math.max( 1, Math.ceil( pts.length / 12 ) );
		pts.forEach( function ( p, i ) {
			if ( 0 === i % every ) {
				svg += '<text x="' + x( i ) + '" y="' + ( h - 8 ) + '" text-anchor="middle">' + esc( p.label ) + '</text>';
			}
		} );
		var line = function ( key, cls ) {
			return '<polyline class="' + cls + '" points="' + pts.map( function ( p, i ) {
				return x( i ) + ',' + y( p[ key ] );
			} ).join( ' ' ) + '"/>';
		};
		svg += line( 'visitors', 'pc-ov-line' ) + line( 'orders', 'pc-ov-line pc-ov-line--orders' ) + '</svg>';
		return svg + '<p class="pc-ov-legend"><span class="pc-ov-key"></span>' + esc( S.visitors ) + ' <span class="pc-ov-key pc-ov-key--orders"></span>' + esc( S.orders ) + '</p>';
	}

	function products() {
		var list = range.products || [];
		var body = list.length ? '<ol class="pc-ov-products">' + list.map( function ( p ) {
			return '<li>' + ( p.image ? '<img src="' + esc( p.image ) + '" alt="">' : '<span class="pc-ov-noimg"></span>' ) + '<a href="' + esc( p.url ) + '">' + esc( p.name ) + '</a><span>' + esc( fmt( S.sold, p.quantity ) ) + '</span></li>';
		} ).join( '' ) + '</ol>' : '<p class="pc-ov-empty">' + esc( S.noData ) + '</p>';
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.topProducts ) + '</h2>' + link( L.products, S.more ) + '</div>' + body + '</section>';
	}

	function tabs() {
		return [ 'today', 'yesterday', '7d', '30d' ].map( function ( r ) {
			return '<button type="button" class="pc-ov-tab' + ( r === range.range ? ' is-active' : '' ) + '" data-range="' + r + '">' + esc( S[ r ] ) + '</button>';
		} ).join( '' );
	}

	function render() {
		var note = summary.tracking ? '' : '<div class="notice notice-info inline pc-ov-note"><p>' + esc( S.trackingOff ) + ' ' + link( L.visitors, S.trackingLink ) + '</p></div>';
		var excluded = range.test_orders || todayData.test_orders;
		if ( excluded ) {
			note += '<div class="notice notice-info inline pc-ov-note"><p>' + esc( fmt( S.testOrders, excluded ) ) + '</p></div>';
		}
		root.innerHTML =
			'<div class="pc-ov-top"><a href="' + esc( L.classic ) + '">' + esc( S.classic ) + '</a></div>' + note +
			'<div class="pc-ov-grid">' +
			'<div class="pc-ov-main">' + hero() + '<div class="pc-ov-row">' + todo() + unfinished() + '</div>' +
			'<section class="pc-ov-card"><div class="pc-ov-card__head"><div class="pc-ov-tabs">' + tabs() + '</div>' + link( L.conversion, S.more ) + '</div>' +
			'<p class="pc-ov-headline">' + esc( S.conversion ) + ': <b>' + esc( null === range.conversion ? '—' : range.conversion + '%' ) + '</b> · ' + esc( S.sales ) + ': <b>' + esc( money( range.sales ) + ' ' + summary.currency.code ) + '</b> <span class="pc-ov-muted">' + esc( range.from + ' – ' + range.to ) + '</span></p>' +
			'<div class="pc-ov-split"><div><h3>' + esc( S.funnel ) + '</h3>' + funnel() + '</div><div><h3>' + esc( fmt( S.totalVisitors, range.visitors ) ) + '</h3>' + chart() + '</div></div></section>' +
			'</div>' +
			'<div class="pc-ov-side">' + side() + live( todayData ) + products() + '</div>' +
			'</div>';
		root.querySelectorAll( '.pc-ov-tab' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				b.classList.add( 'is-loading' );
				load( b.getAttribute( 'data-range' ) ).catch( function () {
					b.classList.remove( 'is-loading' );
				} );
			} );
		} );
	}

	function fetchRange( r ) {
		return wp.apiFetch( { path: '/peptide/v1/overview?range=' + encodeURIComponent( r ) } );
	}

	// 切换时间段；顶部的“今天”同时更新。
	function load( r ) {
		var jobs = [ fetchRange( r ) ];
		if ( 'today' !== r ) {
			jobs.push( fetchRange( 'today' ) );
		}
		return Promise.all( jobs ).then( function ( res ) {
			summary = res[ 0 ].summary;
			range = res[ 0 ].range;
			todayData = 'today' === r ? res[ 0 ].range : res[ 1 ].range;
			render();
			schedule();
		} );
	}

	// 数据都是实时从数据库算的：页面开着时每分钟刷新一次，店铺时区 0 点时立刻刷新，开始新的“今天”。
	var timer = null;
	function schedule() {
		clearTimeout( timer );
		var untilMidnight = summary.next_midnight_ms - Date.now() + 1000;
		timer = setTimeout( function () {
			if ( document.hidden && untilMidnight > 60000 ) {
				schedule();
				return;
			}
			load( range.range ).catch( schedule );
		}, Math.max( 1000, Math.min( 60000, untilMidnight ) ) );
	}

	render();
	schedule();
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			load( range.range ).catch( schedule );
		}
	} );
} )( window.wp, window.pcOverview );
