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
			return '<li><span>' + esc( S.holidayNames[ d.key ] || d.key ) + '</span><span class="pc-ov-muted">' + esc( d.date ) + '</span><span class="pc-ov-muted">' + esc( d.days ? fmt( S.inDays, d.days ) : S.todayTag ) + '</span></li>';
		} ).join( '' );
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.currency ) + '</h2><span><b>' + esc( summary.currency.code + ' (' + summary.currency.symbol + ')' ) + '</b><span class="pc-ov-tag">' + esc( S.default ) + '</span></span></div>' +
			'<div class="pc-ov-card__head pc-ov-card__head--sub"><h2>' + esc( S.holidays ) + '</h2></div><ul class="pc-ov-holidays">' + h + '</ul></section>';
	}

	// ---------- 第二行 ----------

	// 数字格：固定列数，标签在上、数字在下；有链接的整格可点；warn 为真且数字不为 0 时标橙色。
	function num( label, n, href, warn ) {
		var tag = href ? 'a' : 'div';
		return '<' + tag + ' class="pc-ov-num"' + ( href ? ' href="' + esc( href ) + '"' : '' ) + '><span title="' + esc( label ) + '">' + esc( label ) + '</span><b class="' + ( warn && Number( n ) ? 'is-warn' : '' ) + '">' + esc( n ) + '</b></' + tag + '>';
	}
	function nums( cells ) {
		return '<div class="pc-ov-nums" style="--pc-ov-cols:' + cells.length + '">' + cells.join( '' ) + '</div>';
	}

	function todo() {
		var t = summary.todo;
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.todo ) + '</h2></div>' +
			nums( [ num( S.toShip, t.to_ship, L.orders, true ), num( S.onHold, t.on_hold, L.onHold, true ), num( S.missingBatch, t.missing_batch, L.orders, true ), num( S.noReport, t.batches_no_report, L.batches, true ) ] ) + '</section>';
	}

	function unfinished() {
		var t = summary.todo;
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.unfinished ) + '</h2>' + link( L.recovery, S.recoveryLink ) + '</div>' +
			nums( [ num( S.unpaid, t.unpaid_7d, L.unpaid ), num( S.failed, t.failed_7d, L.failed ) ] ) +
			'<p class="pc-ov-muted pc-ov-foot">' + esc( cfg.recovery ? S.recoveryOn : S.recoveryOff ) + '</p></section>';
	}

	function live( today ) {
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.live ) + '</h2>' + link( L.visitors, S.more ) + '</div>' +
			nums( [ num( S.online, summary.online ), num( S.todayOrders, today.orders ), num( S.todaySales, summary.currency.symbol + money( today.sales ) ) ] ) + '</section>';
	}

	// ---------- 第三行 ----------

	function funnel() {
		var f = range.funnel || [];
		if ( ! f.length ) {
			return '<p class="pc-ov-muted">' + esc( S.noData ) + '</p>';
		}
		var base = f[ 0 ].count;
		// 每行同宽：底色条按占访客的比例填充；名称、人数、占比、上一步转化各占固定一列，上下对齐。
		return '<div class="pc-ov-funnel"><div class="pc-ov-step pc-ov-step--head"><span></span><span class="pc-ov-step__rate">' + esc( S.stepRate ) + '</span></div>' + f.map( function ( s, i ) {
			var prev = i ? f[ i - 1 ].count : s.count;
			var fill = base ? Math.min( 100, ( s.count / base ) * 100 ) : 0;
			return '<div class="pc-ov-step"><div class="pc-ov-step__bar"><i style="width:' + fill + '%"></i><span class="pc-ov-step__name">' + esc( S.stages[ s.stage ] ) + '</span><b>' + esc( s.count ) + '</b><span class="pc-ov-step__share">' + ( i ? esc( fmt( S.ofVisitors, pct( s.count, base ) ) ) : '' ) + '</span></div>' +
				'<span class="pc-ov-step__rate">' + ( i ? esc( pct( s.count, prev ) ) : '' ) + '</span></div>';
		} ).join( '' ) + '</div>';
	}

	// 趋势图：下拉选择指标，鼠标移上去显示竖线、圆点和提示框（时间 + 数值）。
	var METRICS = [ 'visitors', 'page_views', 'orders', 'sales', 'conversion' ];
	var metric = 'visitors';
	try {
		metric = METRICS.indexOf( window.localStorage.getItem( 'pcOvMetric' ) ) > -1 ? window.localStorage.getItem( 'pcOvMetric' ) : metric;
	} catch ( e ) {}
	var geo = null;

	function metricValue( p ) {
		if ( 'conversion' === metric ) {
			return p.visitors ? Math.round( ( p.orders / p.visitors ) * 10000 ) / 100 : 0;
		}
		return Number( p[ metric ] || 0 );
	}
	function metricText( v, p ) {
		if ( 'sales' === metric ) {
			return summary.currency.symbol + money( v );
		}
		if ( 'conversion' === metric ) {
			return p && ! p.visitors ? '—' : v + '%';
		}
		return String( v );
	}
	function metricTotal() {
		switch ( metric ) {
			case 'visitors':
				return fmt( S.totalVisitors, range.visitors );
			case 'page_views':
				return S.metrics.page_views + ': ' + ( range.page_views || 0 );
			case 'orders':
				return S.metrics.orders + ': ' + range.orders;
			case 'sales':
				return S.metrics.sales + ': ' + summary.currency.symbol + money( range.sales );
			default:
				return S.metrics.conversion + ': ' + ( null === range.conversion ? '—' : range.conversion + '%' );
		}
	}
	// 纵轴最多 5 格，刻度取 1 / 2 / 5 × 10ⁿ，次数类指标刻度至少为 1。
	function niceStep( max ) {
		var raw = max / 5;
		if ( raw <= 0 ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		var n = raw / p;
		var st = ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * p;
		return 'sales' === metric || 'conversion' === metric ? st : Math.max( 1, Math.ceil( st ) );
	}
	function axisText( v ) {
		v = Math.round( v * 100 ) / 100;
		if ( 'conversion' === metric ) {
			return v + '%';
		}
		return v >= 1000 ? Math.round( v / 100 ) / 10 + 'k' : String( v );
	}

	function chart() {
		var pts = range.series || [];
		var w = 640;
		var h = 250;
		var pad = { l: 40, r: 12, t: 12, b: 28 };
		var vals = pts.map( metricValue );
		var peak = Math.max.apply( null, [ 0 ].concat( vals ) );
		var step = niceStep( peak );
		var ticks = Math.max( 1, Math.ceil( peak / step - 1e-9 ) );
		var max = step * ticks;
		var x = function ( i ) {
			return pad.l + ( pts.length > 1 ? ( i * ( w - pad.l - pad.r ) ) / ( pts.length - 1 ) : ( w - pad.l - pad.r ) / 2 );
		};
		var y = function ( v ) {
			return h - pad.b - ( v / max ) * ( h - pad.t - pad.b );
		};
		geo = { pts: pts, vals: vals, w: w, h: h, pad: pad, x: x, y: y };
		var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="pc-ov-chart" role="img" aria-label="' + esc( S.metrics[ metric ] ) + '">';
		for ( var g = 0; g <= ticks; g++ ) {
			var gy = y( g * step );
			svg += '<line x1="' + pad.l + '" x2="' + ( w - pad.r ) + '" y1="' + gy + '" y2="' + gy + '" class="pc-ov-grid' + ( g ? ' is-dash' : '' ) + '"/><text x="' + ( pad.l - 6 ) + '" y="' + ( gy + 4 ) + '" text-anchor="end">' + esc( axisText( g * step ) ) + '</text>';
		}
		var every = Math.max( 1, Math.ceil( pts.length / 12 ) );
		pts.forEach( function ( p, i ) {
			if ( 0 === i % every ) {
				svg += '<text x="' + x( i ) + '" y="' + ( h - 8 ) + '" text-anchor="middle">' + esc( p.label ) + '</text>';
			}
		} );
		if ( pts.length ) {
			var line = pts.map( function ( p, i ) {
				return x( i ) + ',' + y( vals[ i ] );
			} ).join( ' ' );
			svg += '<polygon class="pc-ov-area" points="' + x( 0 ) + ',' + y( 0 ) + ' ' + line + ' ' + x( pts.length - 1 ) + ',' + y( 0 ) + '"/>' +
				'<polyline class="pc-ov-line" points="' + line + '"/>';
		}
		svg += '<line class="pc-ov-cross" y1="' + pad.t + '" y2="' + ( h - pad.b ) + '" x1="0" x2="0"/><circle class="pc-ov-dot-pt" r="4" cx="0" cy="0"/></svg>';
		var options = METRICS.map( function ( m ) {
			return '<option value="' + m + '"' + ( m === metric ? ' selected' : '' ) + '>' + esc( S.metrics[ m ] ) + '</option>';
		} ).join( '' );
		return '<div class="pc-ov-chart-head"><h3>' + esc( metricTotal() ) + '</h3><select class="pc-ov-metric" aria-label="' + esc( S.metricPick ) + '">' + options + '</select></div>' +
			'<div class="pc-ov-chart-wrap">' + svg + '<div class="pc-ov-tip" hidden></div></div>';
	}

	function bindChart() {
		var box = root.querySelector( '#pc-ov-trend' );
		if ( ! box ) {
			return;
		}
		box.querySelector( '.pc-ov-metric' ).addEventListener( 'change', function ( e ) {
			metric = e.target.value;
			try {
				window.localStorage.setItem( 'pcOvMetric', metric );
			} catch ( err ) {}
			box.innerHTML = chart();
			bindChart();
		} );
		var wrap = box.querySelector( '.pc-ov-chart-wrap' );
		var svg = wrap.querySelector( 'svg' );
		var cross = svg.querySelector( '.pc-ov-cross' );
		var dot = svg.querySelector( '.pc-ov-dot-pt' );
		var tip = wrap.querySelector( '.pc-ov-tip' );
		var hide = function () {
			wrap.classList.remove( 'is-hover' );
			tip.hidden = true;
		};
		wrap.addEventListener( 'pointermove', function ( e ) {
			var n = geo.pts.length;
			var r = svg.getBoundingClientRect();
			if ( ! n || ! r.width ) {
				return;
			}
			var scale = r.width / geo.w;
			var sx = ( e.clientX - r.left ) / scale;
			var i = n > 1 ? Math.round( ( ( sx - geo.pad.l ) / ( geo.w - geo.pad.l - geo.pad.r ) ) * ( n - 1 ) ) : 0;
			i = Math.max( 0, Math.min( n - 1, i ) );
			var px = geo.x( i );
			var py = geo.y( geo.vals[ i ] );
			cross.setAttribute( 'x1', px );
			cross.setAttribute( 'x2', px );
			dot.setAttribute( 'cx', px );
			dot.setAttribute( 'cy', py );
			wrap.classList.add( 'is-hover' );
			tip.innerHTML = '<span>' + esc( geo.pts[ i ].start ) + '</span><span>' + esc( S.metrics[ metric ] ) + ': <b>' + esc( metricText( geo.vals[ i ], geo.pts[ i ] ) ) + '</b></span>';
			tip.hidden = false;
			// 提示框放在点的右下方，靠右边缘时翻到左边。
			var left = px * scale + 14;
			if ( left + tip.offsetWidth > r.width ) {
				left = px * scale - tip.offsetWidth - 14;
			}
			tip.style.left = Math.max( 0, left ) + 'px';
			tip.style.top = Math.max( 0, Math.min( r.height - tip.offsetHeight, py * scale + 12 ) ) + 'px';
		} );
		wrap.addEventListener( 'pointerleave', hide );
	}

	function products() {
		var list = range.products || [];
		var body = list.length ? '<ol class="pc-ov-products">' + list.map( function ( p ) {
			return '<li>' + ( p.image ? '<img src="' + esc( p.image ) + '" alt="">' : '<span class="pc-ov-noimg"></span>' ) + '<a href="' + esc( p.url ) + '">' + esc( p.name ) + '</a><span>' + esc( fmt( S.sold, p.quantity ) ) + '</span></li>';
		} ).join( '' ) + '</ol>' : '<p class="pc-ov-empty">' + esc( S.noData ) + '</p>';
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( S.topProducts ) + '</h2>' + link( L.products, S.more ) + '</div>' + body + '</section>';
	}

	// ---------- 客户追踪（最近 30 分钟的访客，每 10 秒刷新） ----------

	var liveData = null;

	function ago( ms ) {
		var s = Math.max( 0, Math.round( ms / 1000 ) );
		return fmt( S.ago, s < 60 ? s + 's' : s < 3600 ? Math.floor( s / 60 ) + 'm' : Math.floor( s / 3600 ) + 'h' );
	}

	function visitorsCard() {
		var head = '<div class="pc-ov-card__head"><h2>' + esc( S.liveVisitors ) + '</h2>' + link( L.visitors, S.viewAll ) + '</div>';
		if ( ! summary.tracking ) {
			return head + '<p class="pc-ov-empty">' + esc( S.liveOff ) + '</p>';
		}
		if ( ! liveData ) {
			return head + '<p class="pc-ov-empty">…</p>';
		}
		var list = liveData.visitors || [];
		if ( ! list.length ) {
			return head + '<p class="pc-ov-empty">' + esc( S.liveEmpty ) + '</p>';
		}
		return head + '<ul class="pc-ov-visitors">' + list.slice( 0, 20 ).map( function ( v ) {
			var tab = v.tabs[ 0 ] || {};
			var page = ( S.pageTypes[ tab.page_type ] || tab.page_type || '' ) + ( tab.product_name ? ' · ' + tab.product_name : '' );
			var more = v.tabs.length > 1 ? ' · ' + fmt( S.tabsOpen, v.tabs.length ) : '';
			return '<li><a href="' + esc( L.visitor + v.visitor.id ) + '"><span class="pc-ov-dot is-' + esc( v.status ) + '" title="' + esc( S[ v.status ] ) + '"></span>' +
				'<span class="pc-ov-visitor__who"><b>' + esc( v.visitor.label ) + '</b><small>' + esc( page + more ) + '</small></span>' +
				'<span class="pc-ov-visitor__when">' + esc( S[ v.status ] ) + '<small>' + esc( ago( liveData.now_ms - v.last_signal_ms ) ) + '</small></span></a></li>';
		} ).join( '' ) + '</ul>';
	}

	function refreshVisitors() {
		if ( ! summary.tracking ) {
			return;
		}
		wp.apiFetch( { path: '/peptide/v1/track/admin/live' } ).then( function ( res ) {
			liveData = res;
			var box = document.getElementById( 'pc-ov-visitors' );
			if ( box ) {
				box.innerHTML = visitorsCard();
			}
		} ).catch( function () {} );
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
			note +
			'<div class="pc-ov-grid">' +
			'<div class="pc-ov-main">' + hero() + '<div class="pc-ov-row">' + todo() + unfinished() + '</div>' +
			'<section class="pc-ov-card"><div class="pc-ov-card__head"><div class="pc-ov-tabs">' + tabs() + '</div>' + link( L.conversion, S.more ) + '</div>' +
			'<p class="pc-ov-headline">' + esc( S.conversion ) + ': <b>' + esc( null === range.conversion ? '—' : range.conversion + '%' ) + '</b> · ' + esc( S.sales ) + ': <b>' + esc( money( range.sales ) + ' ' + summary.currency.code ) + '</b> <span class="pc-ov-muted">' + esc( range.from + ' – ' + range.to ) + '</span></p>' +
			'<div class="pc-ov-split"><div><h3>' + esc( S.funnel ) + '</h3>' + funnel() + '</div><div id="pc-ov-trend">' + chart() + '</div></div></section>' +
			'</div>' +
			'<div class="pc-ov-side">' + side() + live( todayData ) + products() + '<section class="pc-ov-card" id="pc-ov-visitors">' + visitorsCard() + '</section></div>' +
			'</div>';
		bindChart();
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
	refreshVisitors();
	setInterval( function () {
		if ( ! document.hidden ) {
			refreshVisitors();
		}
	}, 10000 );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			load( range.range ).catch( schedule );
		}
	} );
} )( window.wp, window.pcOverview );
