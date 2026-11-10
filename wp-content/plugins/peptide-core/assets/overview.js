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

	var Y = S.yy;
	// 金额：整数不带小数，否则两位小数（参考后台“0”“1,060.10”）。
	function amt( n ) {
		n = Number( n || 0 );
		return n === Math.round( n ) ? n.toLocaleString() : money( n );
	}
	// 百分比去掉多余的 0：48%、41.67%。
	function pc( v ) {
		return null === v || undefined === v ? '0%' : Math.round( Number( v ) * 100 ) / 100 + '%';
	}
	function more( href, text ) {
		return '<a class="pc-ov-more" href="' + esc( href ) + '">' + esc( text ) + ' &gt;</a>';
	}
	function two( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	function gmt( off ) {
		var sign = off < 0 ? '-' : '+';
		off = Math.abs( off );
		return '(GMT' + sign + two( Math.floor( off / 60 ) ) + ':' + two( off % 60 ) + ')';
	}
	function stamp( d ) {
		return d.getUTCFullYear() + '-' + two( d.getUTCMonth() + 1 ) + '-' + two( d.getUTCDate() ) + ' ' + two( d.getUTCHours() ) + ':' + two( d.getUTCMinutes() ) + ':' + two( d.getUTCSeconds() );
	}
	// 店铺时区的偏移（分钟），取自 tz_label 里的 “UTC-08:00”。
	function storeOffset() {
		var m = /UTC([+-])(\d\d):(\d\d)/.exec( summary.tz_label || '' );
		return m ? ( '-' === m[ 1 ] ? -1 : 1 ) * ( Number( m[ 2 ] ) * 60 + Number( m[ 3 ] ) ) : 0;
	}
	// 时间条：左边店铺时区，右边浏览器所在时区，每秒走一次（参考后台写法“(GMT+08:00) 2026-11-07 10:53:11”）。
	function clocks() {
		var now = Date.now();
		var so = storeOffset();
		var lo = -new Date().getTimezoneOffset();
		return [ gmt( so ) + ' ' + stamp( new Date( now + so * 60000 ) ), gmt( lo ) + ' ' + stamp( new Date( now + lo * 60000 ) ) ];
	}
	function tick() {
		var c = clocks();
		var a = root.querySelector( '.pc-ov-hero__store' );
		var b = root.querySelector( '.pc-ov-hero__local' );
		if ( a ) {
			a.textContent = c[ 0 ];
		}
		if ( b ) {
			b.textContent = c[ 1 ];
		}
	}

	// ---------- 顶部横幅 ----------

	function hero() {
		var cur = summary.currency.code;
		var t = todayData;
		var c = clocks();
		var kpis = [
			[ S.sales + ' (' + cur + ')', amt( t.sales ) ],
			[ Y.visitingUsers, t.visitors ],
			[ S.registered, t.registered ],
			[ Y.orderCount, t.orders ],
			[ S.conversion, null === t.conversion ? '0%' : pc( t.conversion ) ],
			[ S.aov + ' (' + cur + ')', amt( t.aov ) ],
			[ S.returning, t.returning ],
		];
		return '<section class="pc-ov-hero"><p class="pc-ov-hero__hello">' + esc( fmt( S[ summary.greeting ], summary.store ) ) + '</p>' +
			'<div class="pc-ov-hero__panel"><div class="pc-ov-hero__time"><span>' + esc( S.storeTime ) + ': <span class="pc-ov-hero__store">' + esc( c[ 0 ] ) + '</span></span><span class="pc-ov-hero__local">' + esc( c[ 1 ] ) + '</span></div>' +
			'<div class="pc-ov-kpis">' + kpis.map( function ( k ) {
				return '<div class="pc-ov-kpi"><span>' + esc( k[ 0 ] ) + '</span><b>' + esc( k[ 1 ] ) + '</b></div>';
			} ).join( '' ) + '</div></div></section>';
	}

	// ---------- 右栏 ----------

	function side() {
		var c = summary.currency;
		var name = ( c.name || c.code ) + '(' + c.code + ')';
		var d = summary.holidays[ 0 ];
		var h = d ? '<div class="pc-ov-line3"><span>' + esc( S.holidayNames[ d.key ] || d.key ) + '</span><span class="pc-ov-muted">' + esc( d.date ) + '</span><span class="pc-ov-muted">' + esc( d.days ? fmt( Y.startsIn, d.days ) : S.todayTag ) + '</span></div>' : '<p class="pc-ov-muted">' + esc( Y.noData ) + '</p>';
		return '<section class="pc-ov-card pc-ov-cur"><div class="pc-ov-card__head"><h2>' + esc( name ) + ' <span class="pc-ov-tag">' + esc( Y.defaultCur ) + '</span></h2>' + more( L.currency, Y.details ) + '</div>' +
			'<div class="pc-ov-line2"><span class="pc-ov-muted">' + esc( name ) + '</span><span class="pc-ov-muted">' + esc( fmt( Y.rate, '1' ) ) + '</span></div>' +
			'<div class="pc-ov-card__head pc-ov-card__head--sub"><h2>' + esc( Y.holidays ) + '</h2>' + more( L.promotions, Y.details ) + '</div>' + h + '</section>';
	}

	// 数字格：标签在上、数字在下；有链接的整格可点。
	function num( label, n, href, warn ) {
		var tag = href ? 'a' : 'div';
		return '<' + tag + ' class="pc-ov-num"' + ( href ? ' href="' + esc( href ) + '"' : '' ) + '><span title="' + esc( label ) + '">' + esc( label ) + '</span><b class="' + ( warn && Number( n ) ? 'is-warn' : '' ) + '">' + esc( n ) + '</b></' + tag + '>';
	}
	function nums( cells ) {
		return '<div class="pc-ov-nums" style="--pc-ov-cols:' + cells.length + '">' + cells.join( '' ) + '</div>';
	}
	function periodSelect( cls, value, label ) {
		return '<select class="' + cls + '" aria-label="' + esc( label ) + '">' + [ 'today', 'yesterday', '7d', '30d' ].map( function ( r ) {
			return '<option value="' + r + '"' + ( r === value ? ' selected' : '' ) + '>' + esc( S[ r ] ) + '</option>';
		} ).join( '' ) + '</select>';
	}

	// ---------- 第二行：在线支付订单 | 弃单激活 ----------

	function paidOrders() {
		return '<section class="pc-ov-card pc-ov-paid"><div class="pc-ov-card__head"><h2>' + esc( Y.paidOrders ) + '</h2></div>' +
			nums( [ num( Y.toShip, summary.todo.to_ship, L.orders, true ) ] ) + '</section>';
	}

	var recRange = '7d';
	function reactivationBody() {
		var r = ( summary.recovery || {} )[ recRange ] || { emailed: 0, recovered: 0, rate: 0, amount: 0 };
		return nums( [ num( Y.emailed, r.emailed, L.recovery ), num( Y.recovered, r.recovered ), num( Y.recRate, pc( r.rate ) ), num( fmt( Y.recAmount, summary.currency.code ), amt( r.amount ) ) ] );
	}
	function reactivation() {
		return '<section class="pc-ov-card pc-ov-react"><div class="pc-ov-card__head"><h2>' + esc( Y.reactivation ) + '</h2>' + periodSelect( 'pc-ov-sel pc-ov-react-range', recRange, Y.reactivation ) + '</div><div class="pc-ov-react-body">' + reactivationBody() + '</div></section>';
	}

	function live( today ) {
		return '<section class="pc-ov-card"><div class="pc-ov-card__head"><h2>' + esc( Y.live ) + ' <span class="pc-ov-help" tabindex="0" title="' + esc( Y.liveHelp ) + '" aria-label="' + esc( Y.liveHelp ) + '">?</span></h2>' + more( L.realtime, Y.liveMore ) + '</div>' +
			nums( [ num( Y.liveVisitors, summary.online ), num( Y.orderCount, today.orders ), num( fmt( Y.salesCur, summary.currency.code ), amt( today.sales ) ) ] ) + '</section>';
	}

	// ---------- 时间段卡：阶梯漏斗 ----------

	// 八层阶梯（参考后台概览页）：每层一条灰条，越往下越窄；右边折线连到下一层，写“占上一层”的比例。
	function funnel() {
		var f = range.steps || [];
		if ( ! f.length ) {
			return '<p class="pc-ov-empty is-box">' + esc( Y.noData ) + '</p>';
		}
		return '<div class="pc-ov-funnel pc-ov-stairs">' + f.map( function ( s, i ) {
			var next = '';
			if ( i < f.length - 1 ) {
				next = '<i class="pc-ov-stair__next" title="' + esc( S.stepRate ) + '"><span>' + esc( pc( f[ i + 1 ].step_rate ) ) + '</span></i>';
			}
			// 发起结账及之后的层能点：打开“发起结账的客户”，看是谁、走到哪一步、为什么没下单。
			var link = L.dropoff && Number( s.count ) > 0 && [ 'checkout', 'address', 'shipping', 'payment', 'paid' ].indexOf( s.key ) !== -1;
			var tag = link ? 'a href="' + esc( L.dropoff + '&range=' + encodeURIComponent( range.range ) + '&step=' + encodeURIComponent( s.key ) ) + '" title="点击查看这些客户"' : 'div';
			return '<div class="pc-ov-step pc-ov-stair" style="--i:' + i + '"><' + tag + ' class="pc-ov-stair__bar"><b>' + esc( ( Y.steps[ s.key ] || s.key ) + '：' ) + esc( s.count ) + '</b>' + ( i ? '<span>' + esc( fmt( Y.ofVisitors, pc( s.visitor_rate ) ) ) + '</span>' : '' ) + '</' + ( link ? 'a' : 'div' ) + '>' + next + '</div>';
		} ).join( '' ) + '</div>' +
			'<p class="pc-ov-hint">' + esc( Y.tip ).replace( '%s', '<a href="' + esc( L.convDetails ) + '">' + esc( Y.convDetails ) + '</a>' ) + '</p>';
	}

	// ---------- 流量排名 / 商品排名（各自的指标和时间段下拉） ----------

	var ranks = {
		traffic: { metric: 'share', range: 'today', rows: null, total: 0 },
		products: { metric: 'products_units', range: 'today', rows: null },
	};
	function rankValue( kind, row ) {
		var m = ranks[ kind ].metric;
		if ( 'traffic' === kind ) {
			if ( 'share' === m ) {
				// 这个渠道占全部访客的比例，后面带人数。
				var tot = ranks.traffic.total || 0, v = Number( row.visitors || 0 );
				return ( tot ? ( Math.round( v / tot * 1000 ) / 10 ) : 0 ) + '% · ' + v + ' 人';
			}
			if ( 'conversion' === m ) {
				return pc( row.conversion ) + ' · ' + Number( row.visitors || 0 ) + ' 人 / ' + Number( row.paid || 0 ) + ' 单';
			}
			return 'sales' === m ? amt( row.sales ) : String( row[ m ] || 0 );
		}
		return 'products_sales' === m ? amt( row.current ) : String( row.current || 0 );
	}
	function rankList( kind ) {
		var st = ranks[ kind ];
		if ( null === st.rows ) {
			return '<p class="pc-ov-empty">…</p>';
		}
		if ( ! st.rows.length ) {
			return '<p class="pc-ov-empty is-box">' + esc( Y.noData ) + '</p>';
		}
		return ( st.note ? '<p class="pc-ov-rank-note">' + esc( st.note ) + '</p>' : '' ) + '<ol class="pc-ov-products pc-ov-rank">' + st.rows.map( function ( p ) {
			var img = 'products' === kind ? ( p.image ? '<img src="' + esc( p.image ) + '" alt="">' : '<span class="pc-ov-noimg"></span>' ) : '';
			return '<li>' + img + '<span class="pc-ov-rank__name">' + esc( p.label ) + '</span><span>' + esc( rankValue( kind, p ) ) + '</span></li>';
		} ).join( '' ) + '</ol>';
	}
	function rankCard( kind ) {
		var st = ranks[ kind ];
		var opts = 'traffic' === kind ? Y.trafficMetrics : Y.productMetrics;
		var metric = '<select class="pc-ov-sel pc-ov-rank-metric" data-kind="' + kind + '" aria-label="' + esc( 'traffic' === kind ? Y.traffic : Y.productRank ) + '">' + Object.keys( opts ).map( function ( k ) {
			return '<option value="' + k + '"' + ( k === st.metric ? ' selected' : '' ) + '>' + esc( opts[ k ] ) + '</option>';
		} ).join( '' ) + '</select>';
		return '<section class="pc-ov-card pc-ov-rankcard" data-kind="' + kind + '"><div class="pc-ov-card__head"><h2>' + esc( 'traffic' === kind ? Y.traffic : Y.productRank ) + '</h2>' + more( 'traffic' === kind ? L.convDetails : L.productData, Y.details ) + '</div>' +
			'<div class="pc-ov-rank-ctl">' + metric + periodSelect( 'pc-ov-sel pc-ov-rank-range', st.range, S.metricPick ) + '</div><div class="pc-ov-rank-body">' + rankList( kind ) + '</div></section>';
	}
	function fetchRank( kind ) {
		var st = ranks[ kind ];
		var path = 'traffic' === kind ?
			'/peptide/v1/data/conversion?group=channel&range=' + encodeURIComponent( st.range ) :
			'/peptide/v1/data/rankings/table?per_page=10&table=' + encodeURIComponent( st.metric ) + '&range=' + encodeURIComponent( st.range );
		var want = st.metric + '|' + st.range;
		return wp.apiFetch( { path: path } ).then( function ( res ) {
			if ( want !== st.metric + '|' + st.range ) {
				return;
			}
			var rows = res.rows || [];
			if ( 'traffic' === kind ) {
				st.total = rows.reduce( function ( n, r ) { return n + Number( r.visitors || 0 ); }, 0 );
				var key = 'share' === st.metric ? 'visitors' : st.metric;
				rows = rows.filter( function ( r ) {
					return Number( r[ key ] || 0 ) > 0 || ( 'conversion' === st.metric && Number( r.visitors ) > 0 );
				} ).sort( function ( a, b ) {
					return Number( b[ key ] || 0 ) - Number( a[ key ] || 0 );
				} ).slice( 0, 10 );
			}
			// 商品排名：选的是销量 / 销售额但这段时间还没卖出，就自动改看加购，再没有就看浏览（店主 2026-11-10：别空着）。
			if ( 'products' === kind && ! rows.length && ! st.fallback && ( 'products_units' === st.metric || 'products_sales' === st.metric || 'products_carted' === st.metric ) ) {
				st.fallback = 'products_carted' === st.metric ? 'products_viewed' : 'products_carted';
				st.picked = st.picked || st.metric;
				st.metric = st.fallback;
				var sel = root.querySelector( '.pc-ov-rankcard[data-kind="products"] .pc-ov-rank-metric' );
				if ( sel ) { sel.value = st.metric; }
				return fetchRank( kind ).then( function () { st.fallback = null; } );
			}
			st.rows = rows;
			st.note = st.picked && st.picked !== st.metric ? '这段时间还没有' + ( 'products_sales' === st.picked ? '销售额' : 'products_carted' === st.picked ? '加购' : '销量' ) + '，先按' + ( 'products_viewed' === st.metric ? '浏览' : '加购' ) + '排名' : '';
			st.picked = null;
			var body = root.querySelector( '.pc-ov-rankcard[data-kind="' + kind + '"] .pc-ov-rank-body' );
			if ( body ) {
				body.innerHTML = rankList( kind );
			}
		} ).catch( function () {
			st.rows = [];
			var body = root.querySelector( '.pc-ov-rankcard[data-kind="' + kind + '"] .pc-ov-rank-body' );
			if ( body ) {
				body.innerHTML = rankList( kind );
			}
		} );
	}

	// 页面底部一行三张卡（参考后台的公告 / 推荐位），没有内容时显示“暂无数据”。
	// 左栏补齐（2026-11-10 店主：左边下面空着）：现在最可能下单的访客——正在店里或半小时内来过，按购买意向排序；卡片撑满左栏剩余高度。
	var liveRows = null;
	function liveCard() {
		var body;
		if ( null === liveRows ) {
			body = '<p class="pc-ov-empty">…</p>';
		} else if ( ! liveRows.length ) {
			body = '<p class="pc-ov-empty is-box">这会儿店里没有访客。有人进店后，这里会按购买意向排出最可能下单的人。</p>';
		} else {
			var st = { active: '正在看', idle: '停住了', away: '刚离开' };
			body = '<ul class="pc-ov-hot__list">' + liveRows.map( function ( r ) {
				var it = r.intent || {}, v = r.visitor || {}, c = r.cart || {};
				var lv = 'high' === it.level ? 'is-high' : 'mid' === it.level ? 'is-mid' : 'is-low';
				var prod = ( it.products || [] )[0];
				return '<li><a href="' + esc( ( L.visitors || '' ) + '&visitor=' + v.id ) + '"><span class="pc-ov-hot__score ' + lv + '"><b>' + esc( it.score || 0 ) + '</b><small>' + esc( it.level_text || '' ) + '</small></span>' +
					'<span class="pc-ov-hot__main"><b>' + esc( v.label || '' ) + ' <em class="is-' + esc( r.status ) + '">' + esc( st[ r.status ] || '' ) + '</em></b><small>' + esc( [ r.source, ( it.reasons || [] ).slice( 0, 2 ).join( '，' ) ].filter( Boolean ).join( ' · ' ) ) + '</small>' + ( prod ? '<small class="pc-ov-hot__prod">在看：' + esc( prod.name ) + '</small>' : '' ) + '</span>' +
					'<span class="pc-ov-hot__cart">' + ( 'active' === c.status && c.count ? esc( c.count + ' 件 · ' + c.subtotal_text ) : '<i>购物车空</i>' ) + '</span></a></li>';
			} ).join( '' ) + '</ul>';
		}
		return '<section class="pc-ov-card pc-ov-hot"><div class="pc-ov-card__head"><h2>现在最可能下单的访客</h2>' + more( L.visitors, '实时访客' ) + '</div><div class="pc-ov-hot__body">' + body + '</div></section>';
	}
	function loadLive() {
		if ( ! wp.apiFetch || ! summary.tracking ) { liveRows = []; var b0 = root.querySelector( '.pc-ov-hot' ); if ( b0 ) { b0.outerHTML = liveCard(); } return; }
		wp.apiFetch( { path: '/peptide/v1/track/admin/live' } ).then( function ( res ) {
			var now = Number( res.now_ms ) || Date.now();
			liveRows = ( res.visitors || [] ).filter( function ( r ) { return now - Number( r.last_signal_ms || 0 ) < 30 * 60 * 1000; } )
				.sort( function ( a, b ) { return Number( ( b.intent || {} ).score || 0 ) - Number( ( a.intent || {} ).score || 0 ); } ).slice( 0, 8 );
			var box = root.querySelector( '.pc-ov-hot' );
			if ( box ) { box.outerHTML = liveCard(); }
		} ).catch( function () { liveRows = []; } );
	}

	// 2026-11-10 店主：三张卡填上真实内容——待处理、AI 全站分析、最近的客户分析（数据来自 /peptide/v1/overview/extras）。
	var extras = null;
	function ads() {
		var loading = '<p class="pc-ov-empty">…</p>';
		if ( ! extras ) {
			return '<div class="pc-ov-ads"><section class="pc-ov-card pc-ov-ad">' + loading + '</section><section class="pc-ov-card pc-ov-ad">' + loading + '</section><section class="pc-ov-card pc-ov-ad">' + loading + '</section></div>';
		}
		var todo = '<section class="pc-ov-card pc-ov-ad pc-ov-todo"><div class="pc-ov-card__head"><h2>待处理</h2></div><ul class="pc-ov-todo__list">' + ( extras.todo || [] ).map( function ( t ) {
			return '<li><a href="' + esc( t.url ) + '"><span>' + esc( t.label ) + '</span><b class="' + ( t.n ? 'is-hot' : '' ) + '">' + esc( t.n ) + '</b></a></li>';
		} ).join( '' ) + '</ul></section>';
		var a = extras.ai;
		var ai = '<section class="pc-ov-card pc-ov-ad pc-ov-aisum"><div class="pc-ov-card__head"><h2>AI 全站分析</h2>' + ( a ? more( a.url, '查看完整报告' ) : '' ) + '</div>' + ( a ?
			'<div class="pc-ov-aisum__score"><b>' + esc( a.health || '—' ) + '</b><span>健康分' + ( a.state ? ' · ' + esc( a.state ) : '' ) + '</span></div><p class="pc-ov-aisum__text">' + esc( a.summary ) + '</p>' +
			( ( a.stuck || [] ).length ? '<h3>顾客卡在哪</h3><ul class="pc-ov-aisum__list">' + a.stuck.map( function ( x ) { return '<li>' + esc( x ) + '</li>'; } ).join( '' ) + '</ul>' : '' ) +
			( ( a.now || [] ).length ? '<h3>马上做</h3><ul class="pc-ov-aisum__list">' + a.now.map( function ( x ) { return '<li>' + esc( x ) + '</li>'; } ).join( '' ) + '</ul>' : ( a.first ? '<p class="pc-ov-aisum__first"><b>首要建议：</b>' + esc( a.first ) + '</p>' : '' ) ) + '<p class="pc-ov-muted pc-ov-aisum__at">' + esc( a.period + ' · ' + a.at ) + '</p>' :
			'<p class="pc-ov-empty is-box">还没有全站分析。开启 AI 自动分析后，每天凌晨会自动生成前一天的报告。</p>' ) + '</section>';
		var rc = extras.recent || [];
		var recent = '<section class="pc-ov-card pc-ov-ad pc-ov-recent"><div class="pc-ov-card__head"><h2>最近的客户分析</h2></div>' + ( rc.length ? '<ul class="pc-ov-recent__list">' + rc.map( function ( r ) {
			var lv = /高/.test( r.level ) ? 'is-high' : /中/.test( r.level ) ? 'is-mid' : 'is-low';
			return '<li><a href="' + esc( r.url ) + '"><span class="pc-ov-lv ' + lv + '">' + esc( r.level || '—' ) + '</span><span class="pc-ov-recent__main"><b>访客 #' + esc( r.visitor ) + '</b><small>' + esc( r.blocker || r.type || '' ) + '</small></span><span class="pc-ov-muted">' + esc( r.at ) + '</span></a></li>';
		} ).join( '' ) + '</ul>' : '<p class="pc-ov-empty is-box">还没有客户分析。</p>' ) + '</section>';
		return '<div class="pc-ov-ads">' + ai + todo + recent + '</div>';
	}
	// 店铺评分（店主 2026-11-10）：右栏商品排名下面，取 AI 全站分析的健康分、四项评级和上一份的分数。
	function scoreCard() {
		var head = '<div class="pc-ov-card__head"><h2>店铺评分</h2>' + ( extras && extras.ai ? more( extras.ai.url, '查看报告' ) : '' ) + '</div>';
		if ( ! extras ) { return '<section class="pc-ov-card pc-ov-score">' + head + '<p class="pc-ov-empty">…</p></section>'; }
		var a = extras.ai;
		if ( ! a || '' === String( a.health || '' ) ) { return '<section class="pc-ov-card pc-ov-score">' + head + '<p class="pc-ov-empty is-box">还没有评分。AI 全站分析生成报告后会在这里显示。</p></section>'; }
		var n = Math.max( 0, Math.min( 100, parseInt( a.health, 10 ) || 0 ) );
		var tone = n >= 70 ? 'is-good' : n >= 40 ? 'is-mid' : 'is-bad';
		var prev = parseInt( a.prev, 10 );
		var delta = isNaN( prev ) ? '' : ( n - prev > 0 ? '<span class="pc-ov-score__delta is-up">比上次 +' + ( n - prev ) + '</span>' : n - prev < 0 ? '<span class="pc-ov-score__delta is-down">比上次 ' + ( n - prev ) + '</span>' : '<span class="pc-ov-score__delta">和上次持平</span>' );
		var gradeTone = function ( g ) { return /差|弱|低|告警|严重|异常|无/.test( g ) ? 'is-bad' : /中|一般|关注|偏/.test( g ) ? 'is-mid' : /好|强|健康|高|正常|优/.test( g ) ? 'is-good' : ''; };
		var rows = ( a.ratings || [] ).map( function ( r ) {
			return '<li><span class="pc-ov-score__name">' + esc( r.name ) + '</span><span class="pc-ov-score__grade ' + gradeTone( r.grade ) + '">' + esc( r.grade || '—' ) + '</span>' + ( r.reason ? '<small>' + esc( r.reason ) + '</small>' : '' ) + '</li>';
		} ).join( '' );
		return '<section class="pc-ov-card pc-ov-score">' + head +
			'<div class="pc-ov-score__top"><b class="' + tone + '">' + n + '</b><span>/ 100' + ( a.state ? ' · ' + esc( a.state ) : '' ) + '</span>' + delta + '</div>' +
			'<div class="pc-ov-score__bar"><i class="' + tone + '" style="width:' + n + '%"></i></div>' +
			( rows ? '<ul class="pc-ov-score__list">' + rows + '</ul>' : '' ) +
			'<p class="pc-ov-muted pc-ov-score__at">AI 全站分析 · ' + esc( a.period + ' · ' + a.at ) + '</p></section>';
	}
	function loadExtras() {
		if ( ! wp.apiFetch ) { return; }
		wp.apiFetch( { path: '/peptide/v1/overview/extras' } ).then( function ( res ) {
			extras = res || { todo: [] };
			var box = root.querySelector( '.pc-ov-ads' );
			if ( box ) { box.outerHTML = ads(); }
			var sc = root.querySelector( '.pc-ov-score' );
			if ( sc ) { sc.outerHTML = scoreCard(); }
		} ).catch( function () {} );
	}

	// 趋势图：下拉选择指标，鼠标移上去显示竖线、圆点和提示框（时间 + 数值）。
	var METRICS = [ 'visitors', 'orders', 'sales' ];
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

	// Draw the trend at its real pixel width with a fixed height, so it never grows taller
	// (and its labels never grow larger) on wide screens.
	function trendWidth() {
		var box = root.querySelector( '#pc-ov-trend' );
		return box && box.clientWidth ? Math.max( 280, Math.round( box.clientWidth ) ) : 640;
	}

	function chart() {
		var pts = range.series || [];
		var w = trendWidth();
		var h = 300;
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
		var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="pc-ov-chart" role="img" aria-label="' + esc( Y.chartMetrics[ metric ] ) + '">';
		for ( var g = 0; g <= ticks; g++ ) {
			var gy = y( g * step );
			svg += '<line x1="' + pad.l + '" x2="' + ( w - pad.r ) + '" y1="' + gy + '" y2="' + gy + '" class="pc-ov-grid' + ( g ? ' is-dash' : '' ) + '"/><text x="' + ( pad.l - 6 ) + '" y="' + ( gy + 4 ) + '" text-anchor="end">' + esc( axisText( g * step ) ) + '</text>';
		}
		var every = Math.max( 1, Math.ceil( pts.length / 8 ) );
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
			return '<option value="' + m + '"' + ( m === metric ? ' selected' : '' ) + '>' + esc( Y.chartMetrics[ m ] ) + '</option>';
		} ).join( '' );
		return '<div class="pc-ov-chart-head"><h3>' + esc( fmt( Y.totalUsers, range.visitors ) ) + '</h3><select class="pc-ov-metric" aria-label="' + esc( S.metricPick ) + '">' + options + '</select></div>' +
			'<div class="pc-ov-chart-wrap">' + svg + '<div class="pc-ov-tip" hidden></div></div>';
	}

	function bindChart( resized ) {
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
		// First render happens before the box exists; redraw once at the measured width.
		if ( ! resized && Math.abs( trendWidth() - geo.w ) > 2 ) {
			box.innerHTML = chart();
			bindChart( true );
			return;
		}
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
			tip.innerHTML = '<span>' + esc( geo.pts[ i ].start ) + '</span><span>' + esc( Y.chartMetrics[ metric ] ) + ': <b>' + esc( metricText( geo.vals[ i ], geo.pts[ i ] ) ) + '</b></span>';
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
			'<div class="pc-ov-main">' + hero() + '<div class="pc-ov-row">' + paidOrders() + reactivation() + '</div>' +
			'<section class="pc-ov-card pc-ov-period"><div class="pc-ov-card__head"><div class="pc-ov-tabs">' + tabs() + '</div>' + more( L.storeData, Y.storeData ) + '</div>' +
			'<p class="pc-ov-headline">' + esc( Y.visitorConv ) + '：<b>' + esc( null === range.conversion ? '0%' : pc( range.conversion ) ) + '</b><span class="pc-ov-headline__gap"></span>' + esc( Y.salesLabel ) + '：<b>' + esc( amt( range.sales ) ) + '</b>' + esc( summary.currency.code ) + '</p>' +
			'<div class="pc-ov-split"><div>' + funnel() + '</div><div id="pc-ov-trend">' + chart() + '</div></div></section>' +
			liveCard() +
			'</div>' +
			'<div class="pc-ov-side">' + side() + live( todayData ) + rankCard( 'traffic' ) + rankCard( 'products' ) + scoreCard() + '</div>' +
			'</div>' + ads();
		bindChart();
		root.querySelectorAll( '.pc-ov-tab' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				b.classList.add( 'is-loading' );
				load( b.getAttribute( 'data-range' ) ).catch( function () {
					b.classList.remove( 'is-loading' );
				} );
			} );
		} );
		var rr = root.querySelector( '.pc-ov-react-range' );
		if ( rr ) {
			rr.addEventListener( 'change', function () {
				recRange = rr.value;
				root.querySelector( '.pc-ov-react-body' ).innerHTML = reactivationBody();
			} );
		}
		root.querySelectorAll( '.pc-ov-rankcard' ).forEach( function ( card ) {
			var kind = card.getAttribute( 'data-kind' );
			var change = function () {
				ranks[ kind ].metric = card.querySelector( '.pc-ov-rank-metric' ).value;
				ranks[ kind ].range = card.querySelector( '.pc-ov-rank-range' ).value;
				// 流量排名换指标只是重新排序，也重新取一次，数字保持最新。
				ranks[ kind ].rows = null;
				card.querySelector( '.pc-ov-rank-body' ).innerHTML = rankList( kind );
				fetchRank( kind );
			};
			card.querySelector( '.pc-ov-rank-metric' ).addEventListener( 'change', change );
			card.querySelector( '.pc-ov-rank-range' ).addEventListener( 'change', change );
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
			fetchRank( 'traffic' );
			fetchRank( 'products' );
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

	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			var box = root.querySelector( '#pc-ov-trend' );
			if ( box && geo && Math.abs( trendWidth() - geo.w ) > 2 ) {
				box.innerHTML = chart();
				bindChart( true );
			}
		}, 150 );
	} );

	render();
	fetchRank( 'traffic' );
	fetchRank( 'products' );
	loadExtras();
	loadLive();
	setInterval( function () { if ( ! document.hidden ) { loadLive(); } }, 30000 );
	schedule();
	setInterval( tick, 1000 );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			load( range.range ).catch( schedule );
		}
	} );
} )( window.wp, window.pcOverview );
