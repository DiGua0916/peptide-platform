/**
 * 后台“访客数据”（VISITOR-ANALYTICS-TEST-01）：实时访客、访客详情 / 时间线、转化统计。
 * 数据：/peptide/v1/track/admin/*（wp.apiFetch 自动带 nonce）。所有文字都经过 textContent / 转义，不拼接未转义的 HTML。
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! cfg ) {
		return;
	}
	var S = cfg.strings;
	var root = document.getElementById( 'pc-trk-app' );
	if ( ! root ) {
		return;
	}

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function fmt( tpl, v ) {
		return tpl.replace( /%[sd]/, v );
	}
	function ago( ms ) {
		var s = Math.max( 0, Math.round( ms / 1000 ) );
		if ( s < 60 ) {
			return fmt( S.seconds, s );
		}
		if ( s < 3600 ) {
			return fmt( S.minutes, Math.floor( s / 60 ) );
		}
		return fmt( S.hours, Math.floor( s / 3600 ) );
	}
	// 按店铺时区显示时间。
	var zoned = null;
	try {
		if ( cfg.timezone && cfg.timezone.indexOf( '/' ) > 0 ) {
			zoned = new Intl.DateTimeFormat( 'sv-SE', { timeZone: cfg.timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false } );
		}
	} catch ( e ) {
		zoned = null;
	}
	function clock( ms, withDate ) {
		var d = new Date( ms );
		var p = function ( n, w ) {
			return String( n ).padStart( w || 2, '0' );
		};
		var full = zoned ? zoned.format( d ) : d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() ) + ' ' + p( d.getHours() ) + ':' + p( d.getMinutes() ) + ':' + p( d.getSeconds() );
		return withDate ? full : full.slice( 11 ) + '.' + p( d.getMilliseconds(), 3 );
	}
	function pct( a, b ) {
		return b ? Math.round( ( a / b ) * 1000 ) / 10 + '%' : '—';
	}
	function dot( status ) {
		return '<span class="pc-trk-dot is-' + esc( status ) + '"></span>' + esc( S[ status ] || status );
	}
	function pageText( t ) {
		var type = S.pageTypes[ t.page_type ] || t.page_type || '';
		var name = t.product_name ? ' · ' + t.product_name : '';
		return '<strong>' + esc( type + name ) + '</strong> <code>' + esc( t.path ) + '</code>';
	}
	function videoText( v ) {
		if ( ! v ) {
			return '';
		}
		return '<div class="pc-trk-video">▶ ' + esc( S.video ) + ' ' + esc( v.video_id || '' ) + ': ' + esc( v.state ) + ( v.position_s !== undefined ? ' @' + esc( Math.round( v.position_s ) ) + 's' : '' ) + ( v.watched_s !== undefined ? ' / ' + esc( Math.round( v.watched_s ) ) + 's' : '' ) + '</div>';
	}
	function load( path ) {
		return wp.apiFetch( { path: path } );
	}
	function fail( err ) {
		root.innerHTML = '<div class="notice notice-error inline"><p>' + esc( fmt( S.error, ( err && err.message ) || err ) ) + '</p></div>';
	}

	var timezoneNote = document.createElement('p'); timezoneNote.className = 'pc-trk-timezone';
    timezoneNote.textContent = '时间显示：' + (zoned ? cfg.timezone + '（店铺时区）' : '浏览器本地时区');
    if (root.insertAdjacentElement) root.insertAdjacentElement('beforebegin',timezoneNote);

    // ---------- 实时访客 ----------

	function applyLiveHTML( html ) {
		var opened = [];
		root.querySelectorAll( '[data-live-visitor] details[open]' ).forEach( function ( d ) { opened.push( d.closest( '[data-live-visitor]' ).dataset.liveVisitor ); } );
		root.innerHTML = html;
		opened.forEach( function ( id ) { var d = root.querySelector( '[data-live-visitor="' + id + '"] details' ); if ( d ) { d.open = true; } } );
	}
	// 实时访客：卡片式（2026-11-10 店主要求“整洁、方块式、看得出谁最可能下单”）。
	function liveCard( v, now ) {
		var who = v.visitor, it = v.intent || { score: 0, level: 'low', level_text: '', reasons: [], products: [], advice: '' };
		var tab = v.tabs && v.tabs[ 0 ];
		var visit = v.visit;
		var stage = visit ? ( S.stages[ visit.stage ] || '' ) + ( visit.order_url ? ' <a href="' + esc( visit.order_url ) + '">#' + esc( visit.order_id ) + '</a>' : '' ) : '—';
		var prods = it.products && it.products.length ? it.products.map( function ( p ) {
			return '<li><span class="pc-lv-prod">' + esc( p.name ) + '</span>' + ( p.carted ? '<em class="pc-lv-tag is-cart">已加购</em>' : '' ) + ( p.dwell ? '<small>' + esc( p.dwell ) + '</small>' : '' ) + '</li>';
		} ).join( '' ) : '<li class="pc-lv-muted">还没看具体商品' + ( it.module ? '，常点「' + esc( it.module ) + '」' : '' ) + '</li>';
		var reasons = ( it.reasons || [] ).map( function ( r ) { return '<span class="pc-lv-chip">' + esc( r ) + '</span>'; } ).join( '' );
		var live = 'active' === v.status;
		return '<article class="pc-lv-card is-' + esc( it.level ) + ( live ? ' is-live' : '' ) + '" data-live-visitor="' + esc( who.id ) + '">' +
			( live ? '<div class="pc-lv-live"><i></i>正在看 · ' + ( tab ? pageText( tab ) : '' ) + '</div>' : '' ) +
			'<header class="pc-lv-head"><div class="pc-lv-who">' + dot( v.status ) +
				'<a href="' + esc( cfg.liveUrl + '&visitor=' + who.id ) + '"><strong>' + esc( who.label ) + '</strong></a>' +
				'<span class="pc-lv-sub">' + esc( ( S[ who.device ] || who.device ) + ' · ' + who.browser + ( who.visits > 1 ? ' · 第 ' + who.visits + ' 次来' : '' ) ) + '</span></div>' +
				'<div class="pc-lv-score" title="购买意向分（0–100），按本店记录的行为计算"><b>' + esc( it.score ) + '</b><span>' + esc( it.level_text ) + '</span></div></header>' +
			'<div class="pc-lv-bar"><i style="width:' + Math.max( 3, Number( it.score ) || 0 ) + '%"></i></div>' +
			'<section class="pc-lv-block"><h4>最关注</h4><ul class="pc-lv-prods">' + prods + '</ul></section>' +
			'<dl class="pc-lv-facts"><div><dt>走到哪一步</dt><dd>' + stage + '</dd></div><div><dt>来源</dt><dd>' + esc( v.source || '—' ) + '</dd></div>' +
				'<div><dt>购物车</dt><dd>' + cartCell( who, v.cart ) + '</dd></div><div><dt>最后动作</dt><dd>' + esc( fmt( S.ago, ago( now - v.last_signal_ms ) ) ) + '</dd></div></dl>' +
			( reasons ? '<div class="pc-lv-chips">' + reasons + '</div>' : '' ) +
			( it.advice ? '<p class="pc-lv-advice">' + esc( it.advice ) + '</p>' : '' ) +
			'<footer class="pc-lv-foot"><details><summary>' + esc( '正在看：' ) + ( tab ? pageText( tab ) : '—' ) + ( v.tabs.length > 1 ? ' <small>（共 ' + v.tabs.length + ' 个页面）</small>' : '' ) + '</summary>' +
				v.tabs.map( function ( t, i ) { return '<div class="pc-trk-tab"><span class="pc-trk-tabid">' + esc( '页面 ' + ( i + 1 ) ) + '</span> ' + dot( t.status ) + ' ' + pageText( t ) + videoText( t.video ) + '</div>'; } ).join( '' ) +
			'</details><a class="pc-lv-link" href="' + esc( cfg.liveUrl + '&visitor=' + who.id ) + '">查看轨迹 →</a></footer></article>';
	}
	function renderLive( res ) {
		var list = res.visitors || [];
		var count = { active: 0, idle: 0, away: 0 }, hot = 0;
		( res.all || list ).forEach( function ( v ) { count[ v.status ]++; if ( v.intent && 'high' === v.intent.level ) { hot++; } } );
		var html = '<div class="pc-lv-stats">' + [ 'active', 'idle', 'away' ].map( function ( k ) {
			return '<div class="pc-lv-stat"><span>' + dot( k ) + '</span><b>' + count[ k ] + '</b></div>';
		} ).join( '' ) + '<div class="pc-lv-stat is-hot"><span>很可能下单</span><b>' + hot + '</b></div></div>';
		if ( ! list.length ) {
			applyLiveHTML( html + '<p class="pc-trk-empty">' + esc( res.filtered === 'active' ? '现在没有人正在看店铺页面。后台挂着、刚离开的访客可以在“在线状态”里切换查看。' : S.noVisitors ) + '</p>' );
			return;
		}
		html += '<p class="pc-lv-note">' + ( res.filtered === 'active' ? '只显示正在看店铺的真人（页面在前台、最近有操作）。' : '' ) + '按购买意向从高到低排列。意向分只用本店记录的浏览、加购、结账行为计算，不会发给外部服务。</p>';
		html += '<div class="pc-lv-grid">' + list.map( function ( v ) { return liveCard( v, res.now_ms ); } ).join( '' ) + '</div>';
		applyLiveHTML( html );
	}

	// ---------- 购物车（VISITOR-CART-01） ----------
	// 弹窗用 Codex 的组件（visitor-cart.js + visitor-cart-adapter.js）：挂在 body，列表重绘不关窗；
	// 请求有代次 / 可取消，旧响应不会覆盖新窗口；关窗后焦点回到（重绘后的）同一访客按钮。
	var carts = window.PCVisitorCart && window.PCVisitorCartWordPress ? window.PCVisitorCart.mount( {
		root: root,
		timeZone: cfg.timezone,
		refreshMs: 5000,
		loadCart: window.PCVisitorCartWordPress.createLoader( wp.apiFetch ),
	} ) : null;

	function cartCell( who, c ) {
		if ( ! carts ) {
			return '—';
		}
		var summary;
		try {
			summary = window.PCVisitorCartWordPress.summary( c );
		} catch ( e ) {
			summary = { status: 'unknown', item_count: 0 };
		}
		var sub = c && 'active' === c.status && c.subtotal_text ? '<div class="pc-trk-sub">' + esc( c.subtotal_text ) + '</div>' : '';
		return window.PCVisitorCart.buttonHTML( { id: who.id, label: who.label }, summary ) + sub;
	}

	function live() {
        var controls = document.createElement('div'); controls.className = 'pc-trk-live-tools';
        controls.innerHTML = '<label>查找客户<input type="search" name="visitor_search" placeholder="访客编号、设备或页面…" autocomplete="off"></label><label>在线状态<select name="visitor_status"><option value="active" selected>只看正在浏览的真人</option><option value="">全部（含后台挂着、刚离开）</option><option value="idle">只看后台挂着的</option><option value="away">只看刚离开的</option></select></label><button class="button" type="button" aria-pressed="false">暂停刷新</button><span role="status" aria-live="polite"></span>';
        root.insertAdjacentElement('beforebegin',controls);
        var search = controls.querySelector('input'), filter = controls.querySelector('select'), pause = controls.querySelector('button'), status = controls.querySelector('[role="status"]');
        var cached = null, paused = false, pending = false, controller = null, stopped = false;
        function paint() {
            if (!cached) return;
            var q = search.value.trim().toLowerCase();
            var rows = cached.visitors.filter(function(item) {
                if (filter.value && item.status !== filter.value) return false;
                var text = [item.visitor.id,item.visitor.label,item.visitor.device,item.visitor.browser].concat(item.tabs.map(function(tab){return tab.path;})).join(' ').toLowerCase();
                return !q || text.indexOf(q) !== -1;
            });
            var counts = Object.assign({},cached,{visitors:rows,filtered:filter.value,all:cached.visitors});
            renderLive(counts);
            status.textContent = (paused ? '已暂停 · ' : '') + '显示 ' + rows.length + ' 位访客';
        }
        function tick() {
            if (pending || paused || stopped || document.hidden) return;
            pending = true; controller = new AbortController(); var current = controller;
            var timeout = setTimeout(function(){current.abort();},15000);
            wp.apiFetch({path:'/peptide/v1/track/admin/live',signal:current.signal}).then(function(data){
                if (stopped || paused) return;
                cached = data; paint();
            }).catch(function(){if (!stopped && !paused) status.textContent='更新失败，当前列表已保留，下次自动重试。';}).finally(function(){clearTimeout(timeout);pending=false;});
        }
        search.addEventListener('input',paint); filter.addEventListener('change',paint);
        pause.addEventListener('click',function(){paused=!paused;pause.textContent=paused?'继续刷新':'暂停刷新';pause.setAttribute('aria-pressed',String(paused));if(paused){controller?.abort();paint();}else tick();});
        var timer=setInterval(tick,cfg.refreshMs||5000);
        document.addEventListener('visibilitychange',function(){if(!document.hidden)tick();});
        window.addEventListener('pagehide',function(){stopped=true;controller?.abort();clearInterval(timer);});
        window.addEventListener('pageshow',function(event){if(event.persisted){stopped=false;timer=setInterval(tick,cfg.refreshMs||5000);tick();}});
        tick();
	}

	// ---------- 访客详情 ----------

	var hideHeartbeats = true;
	var contextKinds = [ 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown' ];
	function contextDetails( value ) {
		if ( ! value || value.version !== 1 || contextKinds.indexOf( value.kind ) < 0 ) { return null; }
		if ( value.kind === 'collection' ? ( typeof value.term_id !== 'number' || value.term_id <= 0 || Math.floor( value.term_id ) !== value.term_id ) : Object.prototype.hasOwnProperty.call( value, 'term_id' ) ) { return null; }
		var geo = value.geo, parts = [ ( S.pageKind || 'Page kind' ) + '=' + value.kind ];
		if ( value.kind === 'collection' ) { parts.push( ( S.collectionId || 'Collection ID' ) + '=#' + value.term_id ); }
		if ( Object.prototype.hasOwnProperty.call( value, 'geo' ) ) {
			if ( ! geo || [ 'known', 'unknown', 'unavailable' ].indexOf( geo.status ) < 0 ) { return null; }
			if ( geo.status === 'known' ? ( typeof geo.country_code !== 'string' || geo.country_code.length !== 2 || ! /^[A-Z]{2}$/.test( geo.country_code ) ) : Object.prototype.hasOwnProperty.call( geo, 'country_code' ) ) { return null; }
			parts.push( ( S.countryObservation || 'Country observation' ) + '=' + geo.status + ( geo.status === 'known' ? ':' + geo.country_code : '' ) );
		} else { parts.push( ( S.countryObservation || 'Country observation' ) + '=unobserved' ); }
		parts.push( S.sourceReported || 'Source reported' );
		return parts.join( ', ' );
	}
	function attributionDetails( value ) {
		function host( h ) { return typeof h === 'string' && h.length > 0 && h.length <= 253 && ! /\s/.test( h ) && h.split( '.' ).every( function ( label ) { return label.length > 0 && label.length <= 63 && /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test( label ); } ); }
		if ( ! value || value.version !== 1 || ! host( value.host ) || ! value.landing_query || typeof value.landing_query !== 'object' || ( Array.isArray( value.landing_query ) && value.landing_query.length !== 0 ) || ( value.referrer_host !== null && value.referrer_host !== '' && ! host( value.referrer_host ) ) ) { return null; }
		var parts = [ ( S.entryHost || 'Entry host' ) + '=' + value.host ], valid = true;
		[ 'utm_source', 'utm_medium' ].forEach( function ( key ) { var present = Object.prototype.hasOwnProperty.call( value.landing_query, key ), text = value.landing_query[ key ]; if ( present && ( typeof text !== 'string' || /[\x00-\x1f\x7f]/.test( text ) || text.replace( /[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_' ).length > 120 ) ) { valid = false; return; } parts.push( key + '=' + ( ! present ? ( S.notObservedKey || 'Key absent' ) : ( text === '' ? ( S.observedEmpty || 'Observed empty' ) : text ) ) ); } );
		if ( ! valid ) { return null; }
		parts.push( ( S.referrerHost || 'Referrer host' ) + '=' + ( value.referrer_host === null ? ( S.unavailable || 'Unavailable' ) : ( value.referrer_host === '' ? ( S.observedEmpty || 'Observed empty' ) : value.referrer_host ) ) );
		return parts.join( ', ' );
	}

	// 把一条追踪记录翻成店主看得懂的一句话（2026-11-09）。查不到的字段仍按 键=值 兜底。
	function secondsText( ms ) { var sec = Math.round( Number( ms || 0 ) / 100 ) / 10; return ( sec >= 60 ? Math.floor( sec / 60 ) + ' 分 ' + Math.round( sec % 60 ) + ' 秒' : sec + ' 秒' ); }
	function moduleName( type, id ) { var L = cfg.moduleLabels || {}; return L[ type ] || L[ id ] || type || id || '模块'; }
	function productName( e ) { var d = e.data || {}; return e.product_name || d.product_name || ( e.product_id ? '#' + e.product_id : '' ); }
	function rateName( id ) { id = String( id || '' ); var L = cfg.shippingLabels || {}; var key = id.split( '-' )[ 0 ]; return L[ id ] || L[ key ] || id; }
	function gatewayName( id ) { id = String( id || '' ); var L = cfg.paymentLabels || {}; if ( L[ id ] ) { return L[ id ]; } return id.indexOf( 'pc_panapay' ) === 0 ? '在线支付' : id; }
	function statusName( st ) { st = String( st || '' ).replace( /^wc-/, '' ); var L = cfg.orderStatuses || {}; return L[ st ] || st; }
	function money( v, cur ) { var n = Number( v ); if ( isNaN( n ) ) { return String( v ); } return ( cur === 'USD' || ! cur ? '$' : cur + ' ' ) + n.toFixed( 2 ); }
	function typeLabel( type ) { var Z = cfg.typeLabelsZh || {}; return Z[ type ] || ( S.types && S.types[ type ] ) || type; }
	function originText( o ) { return o === 'user' ? '顾客自己选的' : o === 'automatic' ? '系统自动切换' : o === 'initial' ? '页面默认选中，不是顾客操作' : ''; }
	// 时间线里的标签页编号：同一个标签页哈希固定显示成“标签页 1 / 2 / 3”。
	var tabIndex = {};
	function tabLabel( tab ) { if ( ! tab ) { return '—'; } if ( ! ( tab in tabIndex ) ) { tabIndex[ tab ] = '标签页 ' + ( Object.keys( tabIndex ).length + 1 ); } return tabIndex[ tab ]; }
	function detailText( e ) {
		var d = e.data || {};
		var name = productName( e );
		var oid = e.order_url ? '' : oid;
		switch ( e.type ) {
			case 'product_impression':
				return '看到商品「' + name + '」' + ( typeof d.visible_fraction === 'number' ? '，露出 ' + Math.round( d.visible_fraction * 100 ) + '%' : '' ) + ( d.visible_ms ? '，停留 ' + secondsText( d.visible_ms ) : '' );
			case 'product_click': return '点击了商品「' + name + '」';
			case 'product_view': return '打开商品详情「' + name + '」';
			case 'product_intent': return '对商品「' + name + '」表现出兴趣（展开 / 悬停）';
			case 'variant_select': return '选择了商品「' + name + '」的规格' + ( e.variation_id ? '（规格 #' + e.variation_id + '）' : '' );
			case 'add_to_cart_attempt': return '点击了「加入购物车」：' + name;
			case 'add_to_cart': return '已加入购物车：' + name + ( d.quantity ? ' × ' + d.quantity : '' );
			case 'module_click': return '点击了「' + moduleName( d.module_type, d.module_id ) + '」里的按钮或链接';
			case 'visibility_change': return d.visibility === 'hidden' ? '切到了别的标签页或把浏览器收起来了（在结账页时多半是跳去了支付页）' : '回到了这个页面';
			case 'page_exit': return '离开了这个页面';
			case 'page_view': {
				var parts = [ '打开了这个页面' ], ctx = d.page_context || {}, att = d.attribution || {}, q = att.landing_query || {};
				if ( ctx.geo && ctx.geo.status === 'known' && ctx.geo.country_code ) { parts.push( '来自 ' + ctx.geo.country_code ); }
				if ( att.referrer_host ) { parts.push( '从 ' + att.referrer_host + ' 跳转过来' ); } else if ( att.referrer_host === '' ) { parts.push( '直接输入网址或书签进来' ); }
				var utm = [ 'utm_source', 'utm_medium', 'utm_campaign' ].filter( function ( k ) { return typeof q[ k ] === 'string' && q[ k ]; } ).map( function ( k ) { return q[ k ]; } );
				if ( utm.length ) { parts.push( '广告标记：' + utm.join( ' / ' ) ); }
				if ( q.fbclid || d.fbclid ) { parts.push( '带 Facebook 点击参数' ); }
				return parts.join( '，' );
			}
			case 'heartbeat': return ( d.visibility === 'visible' ? '页面在前台' : '页面在后台' ) + ( d.activity === 'active' ? '，正在操作' : '，无操作' );
			case 'cart_open': return '打开了购物车抽屉';
			case 'cart_close': return '关闭了购物车抽屉';
			case 'cart_view': return '打开了购物车页';
			case 'checkout_view': return '打开了结账页';
			case 'checkout_step_applicability': return '这单结账需要：' + ( [ d.address_required ? '填写地址' : '', d.shipping_required ? '选择配送方式' : '' ].filter( Boolean ).join( '、' ) || '直接付款' );
			case 'checkout_shipping_selected': return '配送方式：' + rateName( d.method ) + ( originText( d.origin ) ? '（' + originText( d.origin ) + '）' : '' );
			case 'checkout_payment_selected': return '支付方式：' + gatewayName( d.method ) + ( originText( d.origin ) ? '（' + originText( d.origin ) + '）' : '' );
			case 'checkout_address_completed': return '收货地址已经填完整，可以下单了';
			case 'checkout_submit_attempt': return '点击了「下单」按钮（接下来如果没有“订单已生成”，说明下单没成功）';
			case 'payment_attempt': return '点了下单后，系统开始向支付通道申请付款页' + ( oid ? '，订单' + oid : '' );
			case 'order_placed': return '订单已生成' + oid + '：' + ( d.items ? d.items + ' 件商品，' : '' ) + '合计 ' + money( d.total, d.currency ) + '，支付方式「' + gatewayName( d.method ) + '」，当前状态「' + statusName( d.status ) + '」（还没付款）';
			case 'order_status': {
				var to = statusName( d.to ), from = statusName( d.from );
				var why = d.to === 'cancelled' && d.from === 'pending' ? '——超过付款时限没付款，系统自动取消' : d.to === 'processing' ? '——已付款，等待发货' : d.to === 'completed' ? '——已完成' : d.to === 'failed' ? '——支付通道回报付款失败' : d.to === 'on-hold' ? '——等待确认' : '';
				return '订单' + oid + '从「' + from + '」变成「' + to + '」' + why;
			}
			case 'payment_success': return '付款成功' + ( oid ? '，订单' + oid : '' ) + ( d.total ? '，' + money( d.total, d.currency ) : '' ) + ( d.method ? '，用的「' + gatewayName( d.method ) + '」' : '' );
			case 'payment_failed': return '付款失败' + ( oid ? '，订单' + oid : '' ) + ( d.method ? '，用的「' + gatewayName( d.method ) + '」' : '' );
			case 'login': return '登录了账号' + ( d.user_id ? '（用户 #' + d.user_id + '）' : '' );
			case 'register': return '注册了新账号' + ( d.user_id ? '（用户 #' + d.user_id + '）' : '' );
			case 'cart_change_attempt': return '修改了购物车' + ( name ? '：' + name : '' ) + ( d.quantity !== undefined ? '，数量改成 ' + d.quantity : '' );
			case 'cart_change_response': return '购物车已更新' + ( d.item_count !== undefined ? '，现在 ' + d.item_count + ' 件' : '' );
			case 'video_play': return '开始播放视频' + ( d.position_s !== undefined ? '（从第 ' + Math.round( d.position_s ) + ' 秒）' : '' );
			case 'video_pause': return '暂停视频' + ( d.position_s !== undefined ? '（第 ' + Math.round( d.position_s ) + ' 秒' + ( d.duration_s ? ' / 共 ' + Math.round( d.duration_s ) + ' 秒' : '' ) + '）' : '' );
			case 'video_progress': return '视频看到第 ' + Math.round( d.position_s || 0 ) + ' 秒' + ( d.duration_s ? '（共 ' + Math.round( d.duration_s ) + ' 秒）' : '' );
			case 'video_seek': return '拖动了视频进度';
			case 'video_end': return '看完了视频';
			case 'visit_classification': return '系统判定来源：' + ( d.source || d.channel || d.result || '直接访问' );
			default: break;
		}
		var out = [];
		Object.keys( d ).forEach( function ( k ) {
			if ( k === 'product_name' ) { return; }
			var v = d[ k ];
			out.push( k + '=' + ( 'boolean' === typeof v ? ( v ? '是' : '否' ) : ( typeof v === 'object' ? JSON.stringify( v ) : v ) ) );
		} );
		if ( e.variation_id ) { out.push( '规格 #' + e.variation_id ); }
		return out.join( '，' );
	}

	// 连续的“看到商品”合并成一行：同一标签页、3 秒内。
	function groupEvents( events ) {
		var out = [];
		events.forEach( function ( e ) {
			var last = out[ out.length - 1 ];
			if ( e.type === 'product_impression' && last && last.type === 'product_impression' && last.tab === e.tab && Math.abs( last.at_ms - e.at_ms ) <= 3000 ) {
				last.group = last.group || [ last ];
				last.group.push( e );
				return;
			}
			out.push( Object.assign( {}, e ) );
		} );
		return out;
	}
	function groupText( g ) {
		var names = []; g.forEach( function ( e ) { var n = productName( e ); if ( n && names.indexOf( n ) < 0 ) { names.push( n ); } } );
		return '一眼看到了 ' + names.length + ' 个商品：' + names.slice( 0, 12 ).join( '、' ) + ( names.length > 12 ? ' 等' : '' );
	}

	function eventRow( e ) {
		var typeText = e.group ? '看到商品（' + e.group.length + ' 条）' : typeLabel( e.type );
		var detail = e.group ? groupText( e.group ) : detailText( e );
		return '<tr class="' + ( 'server' === e.source ? 'is-server' : '' ) + '"><td>' + esc( clock( e.at_ms ) ) + '</td><td><span class="pc-trk-tabid">' + esc( tabLabel( e.tab ) ) + '</span></td><td>' + esc( typeText ) + ( 'server' === e.source ? ' <span class="pc-trk-badge">' + esc( cfg.typeLabelsZh ? '服务器记录' : S.server ) + '</span>' : '' ) + '</td><td>' + ( e.path ? pageText( { page_type: e.page_type, path: e.path, product_name: e.product_name || ( e.data || {} ).product_name } ) : '' ) + '</td><td>' + esc( detail ) + ( e.order_url ? ' <a href="' + esc( e.order_url ) + '">#' + esc( e.order_id ) + '</a>' : '' ) + '</td></tr>';
	}

	// 时间线分页：心跳在服务器端过滤，“加载更多”接着往后翻，不会漏掉末尾的事件。
	var timelineToken = 0;
	function openTimeline( box, id, quiet ) {
		if ( quiet && box.dataset.loading === 'true' ) return;
        var token = quiet ? timelineToken : ++timelineToken;
        box.dataset.visitId = String(id); box.dataset.loading = 'true';
		var path = function ( cursor ) {
			return '/peptide/v1/track/admin/visits/' + id + '?limit=50&order=desc&heartbeats=' + ( hideHeartbeats ? '0' : '1' ) + ( cursor ? '&cursor=' + encodeURIComponent( cursor ) : '' );
		};
		if ( ! quiet ) { box.innerHTML = '<p>' + esc( S.loading ) + '</p>'; tabIndex = {}; }
		load( path( null ) ).then( function ( t ) {
			if ( token !== timelineToken ) {
				return;
			}
			if ( quiet ) {
                var region=box.querySelector('.pc-lifecycle-scroll');
                if ((region && region.scrollTop>7) || box.dataset.loadedOlder==='true' || box.querySelector('details[open]') || box.contains(document.activeElement)) {
                    document.dispatchEvent(new CustomEvent('pc:timeline-pending'));return;
                }
            }
            box.dataset.visitId = String( id ); box.dataset.latestId = String( ( t.events[0] || {} ).id || 0 ); box.dataset.loadedOlder = 'false'; box.dataset.heartbeats = hideHeartbeats ? '0' : '1';
			box.innerHTML = '<h2>' + esc( S.timeline ) + ' · ' + esc( clock( t.visit.start_ms, true ) ) + '</h2>' +
				'<p class="pc-timeline-order">最新动作在上 · 继续加载更早记录</p><p class="pc-lifecycle-mobile-hint">左右滑动查看页面及行为详情</p><label class="pc-trk-hb"><input type="checkbox"' + ( hideHeartbeats ? ' checked' : '' ) + '> ' + esc( S.hideHeartbeats ) + '</label>' +
				'<table class="widefat striped pc-trk-table pc-trk-timeline"><thead><tr><th>' + esc( S.time ) + '</th><th>' + esc( S.tab ) + '</th><th>' + esc( S.event ) + '</th><th>' + esc( S.page ) + '</th><th>' + esc( S.details ) + '</th></tr></thead><tbody>' + groupEvents( t.events ).map( eventRow ).join( '' ) + '</tbody></table>' +
				'<p class="pc-trk-more"></p>';
			var more = box.querySelector( '.pc-trk-more' );
			var cursor = t.next_cursor;
			var button = function () {
				more.innerHTML = cursor ? '<button type="button" class="button">加载更早记录</button>' : '';
			};
			button();
			more.addEventListener( 'click', function ( ev ) {
				var b = ev.target.closest( 'button' );
				if ( ! b || ! cursor ) {
					return;
				}
				box.dataset.loadedOlder = 'true';
                b.disabled = true;
				load( path( cursor ) ).then( function ( next ) {
					if ( token !== timelineToken ) {
						return;
					}
					box.dataset.loadedOlder = 'true';
					box.querySelector( '.pc-trk-timeline tbody' ).insertAdjacentHTML( 'beforeend', groupEvents( next.events ).map( eventRow ).join( '' ) );
					cursor = next.next_cursor;
					button();
				} ).catch( function () {
					b.disabled = false;
				} );
			} );
			box.querySelector( '.pc-trk-hb input' ).addEventListener( 'change', function ( ev ) {
				hideHeartbeats = ev.target.checked;
				openTimeline( box, id );
			} );
		} ).catch( function ( err ) {
			if ( token === timelineToken && !quiet ) {
				box.innerHTML = '<p>' + esc( fmt( S.error, ( err && err.message ) || err ) ) + '</p>';
			}
		} ).finally(function(){if(token===timelineToken && box.dataset.visitId===String(id))box.dataset.loading='false';});
	}

	function visitRow( s ) {
		return '<tr data-visit="' + esc( s.id ) + '" tabindex="0"><td>' + esc( clock( s.start_ms, true ) ) + '<div class="pc-trk-sub"><code>' + esc( s.landing ) + '</code></div></td><td>' + esc( ago( s.last_ms - s.start_ms ) ) + '</td><td>' + esc( s.pageviews ) + '</td><td>' + esc( S.stages[ s.stage ] ) + ( s.order_url ? ' <a href="' + esc( s.order_url ) + '">#' + esc( s.order_id ) + '</a>' : '' ) + '</td></tr>';
	}

	function visitor() {
		load( '/peptide/v1/track/admin/visitors/' + cfg.visitor ).then( function ( res ) {
			var v = res.visitor;
			var html = '<p class="pc-trk-backlinks"><a href="' + esc( cfg.liveUrl ) + '">' + esc( S.back ) + '</a><a href="' + esc( cfg.archiveUrl ) + '">' + esc( S.archive ) + '</a></p>';
			html += '<div class="pc-trk-cards"><div class="pc-trk-card"><span>' + esc( S.visitor ) + '</span><b>' + esc( v.label ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.status ) + '</span><b>' + dot( res.presence ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.account ) + '</span><b>' + esc( v.user_id ? v.email : S.guest ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.device ) + '</span><b>' + esc( ( S[ v.device ] || v.device ) + ' · ' + v.browser ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.firstSeen ) + '</span><b>' + esc( clock( v.first_ms, true ) ).replace( ' ', '<br>' ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.visits ) + '</span><b>' + esc( v.visits ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.cart ) + '</span><b>' + cartCell( v, res.cart ) + '</b></div></div>';
			if ( res.others.length ) {
				html += '<p>' + esc( S.otherDevices ) + ': ' + res.others.map( function ( o ) {
					return '<a href="' + esc( cfg.liveUrl + '&visitor=' + o.id ) + '">' + esc( '#' + o.id + ' ' + ( S[ o.device ] || o.device ) + ' · ' + o.browser ) + '</a>';
				} ).join( ', ' ) + '</p>';
			}
			html += '<div class="pc-lifecycle-tabs" role="tablist" aria-label="客户详情"><button type="button" role="tab" id="pc-life-tab" aria-controls="pc-life-panel" aria-selected="true" tabindex="0">访问历程</button><button type="button" role="tab" id="pc-interest-tab" aria-controls="pc-interest-panel" aria-selected="false" tabindex="-1">页面停留与兴趣</button></div><section id="pc-life-panel" class="pc-lifecycle" role="tabpanel" aria-labelledby="pc-life-tab"><h2>客户生命周期 · 历次访问</h2><p>点击一次访问，查看该次页面停留统计及下方行为时间线。可加载更早访问和更多事件；仅展示保留期内实际采集的记录。</p><div class="pc-trk-split"><div><h2>' + esc( S.visits ) + '</h2><table class="widefat pc-trk-table pc-trk-visits"><thead><tr><th>' + esc( S.visitStart ) + '</th><th>' + esc( S.duration ) + '</th><th>' + esc( S.pageviews ) + '</th><th>' + esc( S.stage ) + '</th></tr></thead><tbody>' + res.visits.map( visitRow ).join( '' ) + '</tbody></table><p class="pc-trk-more"></p></div><div id="pc-trk-timeline"><p>' + esc( S.chooseVisit ) + '</p></div></div></section><section id="pc-interest-panel" role="tabpanel" aria-labelledby="pc-interest-tab" hidden></section>';
			root.innerHTML = html;
			var insights = document.getElementById( 'pc-trk-insights' );
			if ( insights ) root.querySelector( '#pc-interest-panel' ).appendChild( insights );
            var tabs = root.querySelectorAll( '.pc-lifecycle-tabs [role=tab]' );
            function activateTab( tab ) {
                tabs.forEach( function ( item ) { var selected = item === tab; item.setAttribute( 'aria-selected', String( selected ) ); item.tabIndex = selected ? 0 : -1; root.querySelector( '#' + item.getAttribute( 'aria-controls' ) ).hidden = ! selected; } );
            }
            tabs.forEach( function ( tab, index ) {
                tab.addEventListener( 'click', function () { activateTab( tab ); } );
                tab.addEventListener( 'keydown', function ( event ) { var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : event.key === 'ArrowRight' ? ( index + 1 ) % tabs.length : event.key === 'ArrowLeft' ? ( index + tabs.length - 1 ) % tabs.length : -1; if ( next < 0 ) return; event.preventDefault(); activateTab( tabs[next] ); tabs[next].focus(); } );
            } );
			var box = document.getElementById( 'pc-trk-timeline' );
			var list = root.querySelector( '.pc-trk-visits' );
            if (document.addEventListener) {
                document.addEventListener('pc:timeline-refresh',function(event){if(box.dataset.visitId)openTimeline(box,Number(box.dataset.visitId),!(event.detail && event.detail.force));});
                document.addEventListener('pc:visitor-snapshot',function(event){
                    var data=event.detail;if(!data||!Array.isArray(data.visits))return;
                    data.visits.slice().reverse().forEach(function(visit){
                        var old=list.querySelector('tr[data-visit="'+Number(visit.id)+'"]');
                        if(old && old.contains(document.activeElement))return;
                        var holder=document.createElement('tbody');holder.innerHTML=visitRow(visit);var row=holder.firstElementChild;
                        if(old){row.classList.toggle('is-selected',old.classList.contains('is-selected'));if(old.innerHTML!==row.innerHTML)old.replaceWith(row);}
                        else list.querySelector('tbody').prepend(row);
                    });
                    var cards=root.querySelectorAll('.pc-trk-card b');
                    if(cards[1])cards[1].innerHTML=dot(data.presence);
                    if(cards[5])cards[5].textContent=data.visitor.visits;
                });
            }
			var open = function ( id ) {
				list.querySelectorAll( 'tr' ).forEach( function ( tr ) {
					tr.classList.toggle( 'is-selected', tr.getAttribute( 'data-visit' ) === String( id ) );
				} );
				openTimeline( box, id );
                document.dispatchEvent( new CustomEvent( 'pc:visit-selected', { detail: Number( id ) } ) );
			};
			// 点访问行（含“加载更多”追加的行）打开它的时间线。
			list.addEventListener( 'click', function ( ev ) {
				var tr = ev.target.closest( 'tr[data-visit]' );
				if ( tr && ! ev.target.closest( 'a' ) ) {
					open( tr.getAttribute( 'data-visit' ) );
				}
			} );
			list.addEventListener( 'keydown', function ( ev ) {
				var tr = ev.target.closest( 'tr[data-visit]' );
				if ( tr && 'Enter' === ev.key ) {
					open( tr.getAttribute( 'data-visit' ) );
				}
			} );
			var more = root.querySelector( '.pc-trk-more' );
			var cursor = res.next_cursor;
			var button = function () {
				more.innerHTML = cursor ? '<button type="button" class="button">' + esc( S.loadMore ) + '</button>' : '';
			};
			button();
			more.addEventListener( 'click', function ( ev ) {
				var b = ev.target.closest( 'button' );
				if ( ! b || ! cursor ) {
					return;
				}
				b.disabled = true;
				load( '/peptide/v1/track/admin/visitors/' + cfg.visitor + '/visits?limit=25&cursor=' + encodeURIComponent( cursor ) ).then( function ( page ) {
					list.querySelector( 'tbody' ).insertAdjacentHTML( 'beforeend', page.items.map( visitRow ).join( '' ) );
					cursor = page.next_cursor;
					button();
				} ).catch( function () {
					b.disabled = false;
				} );
			} );
			var first = cfg.visit || ( res.visits[ 0 ] && res.visits[ 0 ].id );
			if ( first ) {
				list.querySelectorAll( 'tr' ).forEach( function ( tr ) { tr.classList.toggle( 'is-selected', tr.getAttribute( 'data-visit' ) === String( first ) ); } );
                openTimeline( box, first );
			}
		} ).catch( fail );
	}

	// ---------- 访客归档（VISITOR-ARCHIVE-01） ----------

	function archiveRow( it ) {
		var who = it.visitor;
		var visit = it.visit;
		var page = it.last_page;
		return '<tr><td><a href="' + esc( cfg.liveUrl + '&visitor=' + who.id + '&visit=' + visit.id ) + '"><strong>' + esc( who.label ) + '</strong></a><div class="pc-trk-sub">' + esc( ( S[ who.device ] || who.device ) + ' · ' + who.browser + ' · ' + S.visits + ' ' + who.visits ) + '</div></td>' +
			'<td>' + esc( clock( visit.start_ms, true ) ) + '<div class="pc-trk-sub">→ ' + esc( clock( it.left_ms, true ) ) + ' · ' + esc( ago( visit.last_ms - visit.start_ms ) ) + '</div></td>' +
			( 'ongoing' === it.reason ? '<td>' + ( 'active' === it.presence ? '<span class="pc-trk-ongoing">正在浏览</span><div class="pc-trk-sub">页面在前台、最近有操作</div>' : 'idle' === it.presence ? '<span class="pc-trk-ongoing is-idle">停住了</span><div class="pc-trk-sub">页面开着但在后台或没操作</div>' : '<span class="pc-trk-ongoing is-away">刚离开</span><div class="pc-trk-sub">没满 30 分钟，还没算结束</div>' ) + '</td>' : '<td>' + esc( 'exit_signal' === it.reason ? S.reasonExit : S.reasonTimeout ) + '<div class="pc-trk-sub">' + esc( S.leftEstimated ) + '</div></td>' ) +
			'<td>' + ( page ? pageText( page ) : '—' ) + '</td>' +
			'<td>' + esc( visit.pageviews ) + '</td>' +
			'<td>' + esc( S.stages[ visit.stage ] ) + ( visit.order_url ? ' <a href="' + esc( visit.order_url ) + '">#' + esc( visit.order_id ) + '</a>' : '' ) + '</td>' +
			'<td>' + cartCell( who, it.cart ) + '</td>' +
			'<td><a class="button button-small" href="' + esc( cfg.liveUrl + '&visitor=' + who.id + '&visit=' + visit.id ) + '">' + esc( S.timeline ) + '</a></td></tr>';
	}

	function archive() {
		root.innerHTML = '<form class="pc-trk-filter"><label>' + esc( S.from ) + ' <input type="date" name="from"></label> <label>' + esc( S.to ) + ' <input type="date" name="to"></label> <select name="device"><option value="">' + esc( S.allDevices ) + '</option><option value="desktop">' + esc( S.desktop ) + '</option><option value="mobile">' + esc( S.mobile ) + '</option><option value="tablet">' + esc( S.tablet ) + '</option></select> <input type="search" name="q" placeholder="' + esc( S.searchVisitor ) + '"> <button class="button">' + esc( S.apply ) + '</button></form>' +
			'<p class="description">' + esc( S.archiveNote ) + '</p><div id="pc-trk-archive"></div>';
		var form = root.querySelector( 'form' );
		var out = document.getElementById( 'pc-trk-archive' );
		var token = 0;
		var query = function ( cursor ) {
			return '/peptide/v1/track/admin/archive?limit=25&from=' + encodeURIComponent( form.from.value ) + '&to=' + encodeURIComponent( form.to.value ) + '&device=' + encodeURIComponent( form.device.value ) + '&q=' + encodeURIComponent( form.q.value ) + ( cursor ? '&cursor=' + encodeURIComponent( cursor ) : '' );
		};
		var expanded = false; // 点过“加载更多”后不自动刷新，免得打断往下翻。
		var run = function ( quiet ) {
			var mine = ++token;
			if ( ! quiet ) {
				out.innerHTML = '<p>' + esc( S.loading ) + '</p>';
				expanded = false;
			}
			load( query( null ) ).then( function ( r ) {
				if ( mine !== token ) {
					return;
				}
				if ( ! r.items.length ) {
					out.innerHTML = '<p class="pc-trk-empty">' + esc( S.archiveEmpty ) + '</p>';
					return;
				}
				out.innerHTML = '<table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.visitor ) + '</th><th>' + esc( S.visitTime ) + '</th><th>' + esc( S.leftReason ) + '</th><th>' + esc( S.lastPage ) + '</th><th>' + esc( S.pageviews ) + '</th><th>' + esc( S.stage ) + '</th><th>' + esc( S.cart ) + '</th><th></th></tr></thead><tbody>' + r.items.map( archiveRow ).join( '' ) + '</tbody></table><p class="pc-trk-more"></p>';
				var more = out.querySelector( '.pc-trk-more' );
				var cursor = r.next_cursor;
				var button = function () {
					more.innerHTML = cursor ? '<button type="button" class="button">' + esc( S.loadMore ) + '</button>' : '';
				};
				button();
				more.addEventListener( 'click', function ( ev ) {
					var b = ev.target.closest( 'button' );
					if ( ! b || ! cursor ) {
						return;
					}
					b.disabled = true;
					expanded = true;
					load( query( cursor ) ).then( function ( next ) {
						if ( mine !== token ) {
							return;
						}
						out.querySelector( 'tbody' ).insertAdjacentHTML( 'beforeend', next.items.map( archiveRow ).join( '' ) );
						cursor = next.next_cursor;
						button();
					} ).catch( function () {
						b.disabled = false;
					} );
				} );
			} ).catch( function ( err ) {
				if ( mine === token && ! quiet ) {
					out.innerHTML = '<p>' + esc( fmt( S.error, ( err && err.message ) || err ) ) + '</p>';
				}
			} );
		};
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );
		run();
		// 2026-11-10：第一页每 20 秒自动更新，新来的访客自动出现在最上面（页面在后台或已往下翻时不刷新）。
		setInterval( function () {
			if ( ! document.hidden && ! expanded ) {
				run( true );
			}
		}, 20000 );
	}

	// ---------- 转化统计 ----------

	function stats() {
		root.innerHTML = '<form class="pc-trk-filter"><label>' + esc( S.from ) + ' <input type="date" name="from" value="' + esc( cfg.weekAgo ) + '"></label> <label>' + esc( S.to ) + ' <input type="date" name="to" value="' + esc( cfg.today ) + '"></label> <select name="device"><option value="">' + esc( S.allDevices ) + '</option><option value="desktop">' + esc( S.desktop ) + '</option><option value="mobile">' + esc( S.mobile ) + '</option><option value="tablet">' + esc( S.tablet ) + '</option></select> <button class="button">' + esc( S.apply ) + '</button></form><div id="pc-trk-stats"></div>';
		var form = root.querySelector( 'form' );
		var out = document.getElementById( 'pc-trk-stats' );
		var run = function () {
			out.innerHTML = '<p>' + esc( S.loading ) + '</p>';
			var q = '?from=' + encodeURIComponent( form.from.value ) + '&to=' + encodeURIComponent( form.to.value ) + '&device=' + encodeURIComponent( form.device.value );
			load( '/peptide/v1/track/admin/stats' + q ).then( function ( r ) {
				var html = '<div class="pc-trk-cards"><div class="pc-trk-card"><span>' + esc( S.visits ) + '</span><b>' + r.visits + '</b></div><div class="pc-trk-card"><span>' + esc( S.visitors ) + '</span><b>' + r.visitors + '</b></div><div class="pc-trk-card"><span>' + esc( S.pageviews ) + '</span><b>' + r.pageviews + '</b></div></div>';
				html += '<h2>' + esc( S.funnel ) + '</h2><div class="pc-trk-funnel">';
				var base = r.funnel[ 0 ].count;
				r.funnel.forEach( function ( f, i ) {
					var prev = i ? r.funnel[ i - 1 ].count : f.count;
					html += '<div class="pc-trk-step"><div class="pc-trk-step__label">' + esc( S.stages[ f.stage ] ) + '</div><div class="pc-trk-step__bar"><span style="width:' + ( base && f.count ? Math.max( 2, ( f.count / base ) * 100 ) : 0 ) + '%"></span></div><div class="pc-trk-step__num"><b>' + f.count + '</b> ' + esc( fmt( S.ofVisits, pct( f.count, base ) ) ) + ( i ? ' · ' + esc( fmt( S.fromPrev, pct( f.count, prev ) ) ) : '' ) + '</div></div>';
				} );
				html += '</div>';
				if ( r.days.length ) {
					html += '<h2>' + esc( S.byDay ) + '</h2><table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.day ) + '</th><th>' + esc( S.visits ) + '</th><th>' + esc( S.carts ) + '</th><th>' + esc( S.orders ) + '</th><th>' + esc( S.paid ) + '</th></tr></thead><tbody>' + r.days.map( function ( d ) {
						return '<tr><td>' + esc( d.day ) + '</td><td>' + d.visits + '</td><td>' + d.carts + '</td><td>' + d.orders + '</td><td>' + d.paid + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
				}
				if ( r.products.length ) {
					html += '<h2>' + esc( S.products ) + '</h2><table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.products ) + '</th><th>' + esc( S.views ) + '</th><th>' + esc( S.carts ) + '</th></tr></thead><tbody>' + r.products.map( function ( p ) {
						return '<tr><td>' + esc( p.name || '#' + p.product_id ) + '</td><td>' + p.views + '</td><td>' + p.carts + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
				}
				if ( ! r.visits ) {
					html += '<p class="pc-trk-empty">' + esc( S.none ) + '</p>';
				}
				out.innerHTML = html;
			} ).catch( function ( err ) {
				out.innerHTML = '<p>' + esc( fmt( S.error, err.message ) ) + '</p>';
			} );
		};
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );
		run();
	}

	if ( 'stats' === cfg.view ) {
		stats();
	} else if ( 'archive' === cfg.view ) {
		archive();
	} else if ( 'visitor' === cfg.view && cfg.visitor ) {
		visitor();
	} else {
		live();
	}
} )( window.wp, window.pcTrackingAdmin );
