/**
 * 转化率详情页（DATA-01 第 4 步）的报表：整体转化步骤（本期、对比时间段 VS、变化）和按设备或浏览器分组的漏斗卡片。
 * 数字来自转化率详情接口（见下面的 PATH；口径都在 PeptideCore\Data\Conversion，这里不自己算），
 * 文字来自 window.pcDataConversion.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 conversion 页面的报表，由 data.js 的外壳调用：切换时间段时它清空页面再调用一次，
 * 所以选中的分组记在这个脚本里（也存在浏览器的 localStorage 里）；改分组只重新取数据，工具栏不重建。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/conversion';
	var STORE_KEY = 'pcDataConversionGroup';
	// 和 Conversion::OVERALL / GROUPS 以及 Operations::LAYERS 的顺序一致。
	var OVERALL = [ 'conversion', 'paid', 'visitors', 'viewed', 'carted', 'checkout' ];
	var GROUPS = [ 'device', 'browser' ];
	var LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'paid' ];
	var DETAIL_LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid' ];
	var expanded = {};

	// 请求计数：改分组 / 切换时间段之后，旧请求晚到的结果不再画。
	var requests = 0;
	var activeDispose = null;
	// 在两次 render 之间保留的状态：选中的分组。
	var state = { group: readGroup() };

	// ---------- 小工具 ----------

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

	// 百分比（转化率、占比）：没有值显示“-”，最多 2 位小数。
	function pct( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	function rangeParams( range ) {
		var d = range.dates || {};
		return range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to };
	}

	// 浏览器存储可能不可用（隐私模式等），读写都包在 try / catch 里；存的值不在 GROUPS 里就忽略。
	function readGroup() {
		try {
			var saved = window.localStorage.getItem( STORE_KEY );
			if ( saved && GROUPS.indexOf( saved ) !== -1 ) {
				return saved;
			}
		} catch ( e ) {
			// 读不到就用默认分组。
		}
		return GROUPS[ 0 ];
	}

	function saveGroup( group ) {
		try {
			window.localStorage.setItem( STORE_KEY, group );
		} catch ( e ) {
			// 存不进去不影响使用。
		}
	}

	// 分组值的显示名：设备用翻译，浏览器名照原样；Other 和 unknown 用翻译。
	function groupLabel( group, key ) {
		if ( 'unknown' === key ) {
			return T.unknown;
		}
		if ( 'device' === group ) {
			return 'desktop' === key || 'mobile' === key || 'tablet' === key ? T[ key ] : key;
		}
		return 'Other' === key ? T.other : key;
	}

	// 变化：数量用 ctx.change() 显示相对百分比，转化率差值显示百分点；涨绿跌红。
	function changeHtml( v, ctx, cls, points ) {
		var valid = isNum( v ) && ( ! points || isFinite( Number( v ) ) );
		var dir = valid && Number( v ) > 0 ? ' is-up' : ( valid && Number( v ) < 0 ? ' is-down' : '' );
		var text;
		if ( points ) {
			var value = valid ? Math.round( Number( v ) * 100 ) / 100 : null;
			text = value === null ? '-' : ( value > 0 ? '+' : '' ) + value + ' ' + T.percentagePoints;
		} else {
			text = ctx.change( v );
		}
		return '<span class="' + cls + dir + '">' + ctx.esc( text ) + '</span>';
	}

	// 整体步骤的显示值：转化率是百分比，其他是个数。
	function overallValue( key, v, ctx ) {
		return 'conversion' === key ? pct( v ) : ctx.number( v );
	}

	// ---------- 整体转化步骤 ----------

	function overall( data, ctx ) {
		var esc = ctx.esc;
		var o = data.overall || {};
		var cur = o.current || {};
		var prev = o.previous || {};
		var chg = o.change || {};
		var detail = data.visitor_funnel;
		var detailed = detail && detail.unit === 'visitors' && detail.current && detail.current.length === 8;
		if ( detailed ) {
			cur = {}; prev = {}; chg = detail.change || {};
			detail.current.forEach( function ( layer ) { cur[ layer.key ] = layer.count; } );
			detail.previous.forEach( function ( layer ) { prev[ layer.key ] = layer.count; } );
			cur.conversion = detail.current[ 7 ].visitor_rate;
			prev.conversion = detail.previous[ 7 ].visitor_rate;
			// Copy before adding a display-only percentage-point delta.
			var changes = {};
			Object.keys( chg ).forEach( function ( key ) { changes[ key ] = chg[ key ]; } );
			changes.conversion = isNum( cur.conversion ) && isNum( prev.conversion ) ? Math.round( ( cur.conversion - prev.conversion ) * 100 ) / 100 : null;
			chg = changes;
		}
		var keys = detailed ? OVERALL.concat( [ 'address', 'shipping', 'payment' ] ) : OVERALL;
		var tiles = keys.map( function ( key ) {
			var help = 'conversion' === key;
			return '<div class="pc-conv-tile"' + ( help ? ' title="' + esc( T.conversionHelp ) + '"' : '' ) + '>' +
				'<div class="pc-conv-tile__label">' + esc( T[ detailed && key === 'paid' ? 'paidVisitors' : ( detailed && key === 'conversion' ? 'visitorConversion' : key ) ] ) +
					( help ? ' <span class="pc-conv-info" aria-hidden="true">ⓘ</span>' : '' ) +
				'</div>' +
				'<div class="pc-conv-tile__value">' + esc( overallValue( key, cur[ key ], ctx ) ) + '</div>' +
				'<div class="pc-conv-tile__foot">' +
					'<span class="pc-conv-muted">' + esc( ctx.strings.vs + ' ' + overallValue( key, prev[ key ], ctx ) ) + '</span> ' +
					changeHtml( chg[ key ], ctx, 'pc-conv-tile__change', key === 'conversion' ) +
				'</div>' +
			'</div>';
		} );
		return '<p class="pc-conv-note">' + esc( detailed ? T.detailHelp : T.detailUnavailable ) + '</p><div class="pc-conv-overall">' + tiles.join( '' ) + '</div>';
	}

	// ---------- 分组卡片 ----------

	function card( group, row, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var detailed = row.visitor_funnel && row.visitor_funnel.unit === 'visitors' && row.visitor_funnel.layers && row.visitor_funnel.layers.length === 8;
		var token = encodeURIComponent( group + ':' + row.key );
		var all = detailed ? row.visitor_funnel.layers : row.funnel || [];
		var byKey = {};
		all.forEach( function ( layer ) { byKey[ layer.key ] = layer; } );
		var keys = detailed && expanded[ token ] ? DETAIL_LAYERS : LAYERS;
		var funnel = keys.map( function ( key, index ) {
			var layer = byKey[ key ] || { count: 0, visitor_rate: null };
			var previous = index ? byKey[ keys[ index - 1 ] ] : null;
			return { count: layer.count, visitor_rate: layer.visitor_rate, step_rate: previous && previous.count ? Math.round( layer.count / previous.count * 10000 ) / 100 : null };
		} );
		var title = fill( T.groupRate, { '%s': groupLabel( group, row.key ) } );
		var steps = keys.map( function ( key, i ) {
			var layer = funnel[ i ] || { count: 0, visitor_rate: null };
			var next = funnel[ i + 1 ];
			var width = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
			var html = '<div class="pc-conv-step">' +
				'<span class="pc-conv-step__label">' + esc( T[ detailed && key === 'paid' ? 'paidVisitors' : key ] ) + '</span>' +
				'<span class="pc-conv-bar"><span class="pc-conv-bar__fill" style="width:' + esc( width ) + '%"></span></span>' +
				'<span class="pc-conv-step__count">' + esc( ctx.number( layer.count ) ) + '</span>' +
				'<span class="pc-conv-step__rate">' + esc( fill( T.ofVisitors, { '%s': pct( layer.visitor_rate ) } ) ) + '</span>' +
			'</div>';
			// 到下一步的转化率（下一层占上一层的 %）画在两步之间。
			if ( next ) {
				html += '<div class="pc-conv-between"><span aria-hidden="true">↓</span> ' + esc( pct( next.step_rate ) ) + '</div>';
			}
			return html;
		} );
		return '<section class="pc-conv-card">' +
			'<h2 class="pc-conv-card__title">' + esc( title ) + ' <span class="pc-conv-card__rate">' + esc( pct( detailed ? byKey.paid.visitor_rate : row.conversion ) ) + '</span></h2>' +
			'<p class="pc-conv-card__sales">' + esc( T.groupSales ) + ' <strong>' + esc( ctx.money( row.sales ) + ( code ? ' ' + code : '' ) ) + '</strong></p>' +
			( detailed ? '<button type="button" class="button pc-conv-detail-toggle" data-conv-toggle="' + esc( token ) + '" aria-expanded="' + ( expanded[ token ] ? 'true' : 'false' ) + '" aria-controls="pc-conv-detail-' + esc( token ) + '">' + esc( expanded[ token ] ? T.hideDetails : T.viewDetails ) + '</button>' : '<p class="pc-conv-note">' + esc( T.detailUnavailable ) + '</p>' ) +
			'<div id="pc-conv-detail-' + esc( token ) + '" class="pc-conv-steps">' + steps.join( '' ) + '</div>' +
		'</section>';
	}

	function view( data, ctx ) {
		var esc = ctx.esc;
		var group = data.group || state.group;
		var rows = data.rows || [];
		var html = '';
		if ( ! data.tracking ) {
			html += '<p class="pc-conv-note">' + esc( T.trackingOff ) + '</p>';
		}
		html += overall( data, ctx );
		html += checkoutSteps( data.checkout_steps, ctx );
		if ( rows.length ) {
			html += '<div class="pc-conv-cards">' + rows.map( function ( row ) {
				return '<div data-conv-slot="' + esc( encodeURIComponent( group + ':' + row.key ) ) + '">' + card( group, row, ctx ) + '</div>';
			} ).join( '' ) + '</div>';
		} else {
			html += '<p class="pc-data-empty">' + esc( T.noGroups ) + '</p>';
		}
		return html;
	}

	function checkoutSteps( data, ctx ) {
		if ( ! data ) { return '<p class="pc-data-empty">' + ctx.esc( ctx.strings.loadError ) + '</p>'; }
		var esc = ctx.esc;
		var current = data.current || {};
		var previous = data.previous || {};
		var changes = data.change || {};
		var labels = data.labels || {};
		var keys = Object.keys( labels );
		var html = '<section class="pc-conv-checkout"><h2>' + esc( T.checkoutTitle ) + '</h2>';
		if ( data.local_simulation ) { html += '<p class="pc-conv-note pc-conv-local">' + esc( T.localNotice ) + '</p>'; }
		html += '<p class="pc-conv-note">' + esc( T.checkoutHelp ) + '</p>';
		if ( ! keys.some( function ( key ) { return current[ key ] || previous[ key ]; } ) ) {
			html += '<p class="pc-data-empty">' + esc( T.emptyCheckout ) + '</p>';
		}
		html += '<div class="pc-conv-table-wrap" tabindex="0"><table class="pc-conv-checkout-table"><thead><tr><th>' + esc( T.observedStep ) + '</th><th>' + esc( T.currentPeriod ) + '</th><th>' + esc( T.previousPeriod ) + '</th><th>' + esc( T.periodChange ) + '</th></tr></thead><tbody>';
		keys.forEach( function ( key ) {
			html += '<tr><th scope="row">' + esc( labels[ key ] ) + '<small>' + esc( data.sources[ key ] === 'server' ? T.serverFact : T.browserObservation ) + '</small></th><td>' + esc( ctx.number( current[ key ] || 0 ) ) + '</td><td>' + esc( ctx.number( previous[ key ] || 0 ) ) + '</td><td>' + changeHtml( changes[ key ], ctx, 'pc-conv-change' ) + '</td></tr>';
		} );
		html += '</tbody></table></div><h3>' + esc( T.checkoutChannels ) + '</h3><p class="pc-conv-note">' + esc( T.channelHelp ) + '</p>';
		var channels = data.channels || [];
		if ( ! channels.length ) { return html + '<p class="pc-data-empty">' + esc( T.emptyChannels ) + '</p></section>'; }
		var metrics = [ 'initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed', 'success_rate' ];
		html += '<div class="pc-conv-table-wrap" tabindex="0"><table class="pc-conv-channel-table"><thead><tr><th>' + esc( T.paymentChannel ) + '</th>';
		metrics.forEach( function ( key ) { html += '<th>' + esc( T[ key ] ) + '</th>'; } );
		html += '</tr></thead><tbody>';
		channels.forEach( function ( channel ) {
			var title = channel.method === 'pc_local_card' ? T.localCard : ( channel.method === 'pc_local_wallet' ? T.localWallet : channel.method === 'unknown' ? T.unknown : channel.method );
			html += '<tr><th scope="row">' + esc( title ) + '</th>';
			metrics.forEach( function ( key ) {
				var c = channel.current || {}, p = channel.previous || {}, d = channel.change || {};
				var format = key === 'success_rate' ? pct : ctx.number;
				html += '<td><strong>' + esc( format( c[ key ] ) ) + '</strong><small>' + esc( ctx.strings.vs + ' ' + format( p[ key ] ) ) + '</small>' + changeHtml( d[ key ], ctx, 'pc-conv-change', key === 'success_rate' ) + '</td>';
			} );
			html += '</tr>';
		} );
		return html + '</tbody></table></div></section>';
	}

	// ---------- 报表 ----------

	function render( bodyEl, range, ctx ) {
		if ( activeDispose ) { activeDispose(); }
		var esc = ctx.esc;
		var options = GROUPS.map( function ( key ) {
			return '<option value="' + esc( key ) + '"' + ( key === state.group ? ' selected' : '' ) + '>' + esc( T[ key ] ) + '</option>';
		} ).join( '' );

		// 工具栏每次 render 只建一次；改分组只重画下面的结果。
		bodyEl.innerHTML = '<div class="pc-conv">' +
			'<div class="pc-conv-toolbar">' +
				'<label class="pc-conv-label" for="pc-conv-group">' + esc( T.groupBy ) + '</label>' +
				'<select id="pc-conv-group" class="pc-conv-group">' + options + '</select>' +
				'<button type="button" class="button pc-conv-export" disabled title="' + esc( T.exportHelp ) + '">' + esc( T.exportCsv ) + '</button>' +
				'<span class="pc-conv-export-status" role="status" aria-live="polite"></span>' +
			'</div>' +
			'<p class="pc-conv-export-help">' + esc( T.exportHelp ) + '</p>' +
			'<div class="pc-conv-result"></div>' +
		'</div>';
		var select = bodyEl.querySelector( '.pc-conv-group' );
		var result = bodyEl.querySelector( '.pc-conv-result' );
		var loadedData = null;
		var retainedData = null, settled = false, rangeBusy = false, exporting = false;
		var exportToken = 0, disposed = false, unsubscribe = null, resumeTimer = null;
		var exportButton = bodyEl.querySelector( '.pc-conv-export' );
		var exportStatus = bodyEl.querySelector( '.pc-conv-export-status' );
		var acceptedRange = range;
		function attached() { return ! disposed && ( ! document.documentElement || ! document.documentElement.contains || document.documentElement.contains( bodyEl ) ); }
		function datesOf( data ) { var d = data && data.range && data.range.dates; return d && [ 'from', 'to', 'compare_from', 'compare_to' ].every( function ( key ) { return typeof d[ key ] === 'string' && d[ key ]; } ) ? d : null; }
		function eligible( data ) {
			return !! data && !! datesOf( data ) && data.group === state.group && data.visitor_funnel && data.visitor_funnel.unit === 'visitors' && data.visitor_funnel.current && data.visitor_funnel.current.length === 8 && data.visitor_funnel.previous && data.visitor_funnel.previous.length === 8 && !! data.checkout_steps;
		}
		function contextKey( data ) { var d = datesOf( data ); return d ? [ data.group, d.from, d.to, d.compare_from, d.compare_to ].join( '|' ) : ''; }
		function syncExport() { exportButton.disabled = ! attached() || ! settled || ! eligible( loadedData ) || rangeBusy || exporting || ! window.PcDataExport; select.disabled = rangeBusy; exportButton.setAttribute( 'aria-busy', exporting ? 'true' : 'false' ); }
		function cancelExport() { ++exportToken; exporting = false; exportStatus.textContent = ''; syncExport(); }
		activeDispose = function () { disposed = true; ++requests; ++exportToken; if ( resumeTimer !== null ) { window.clearTimeout( resumeTimer ); } if ( unsubscribe ) { unsubscribe(); } };
		exportButton.addEventListener( 'click', function () {
			if ( exportButton.disabled || ! eligible( loadedData ) || ! window.PcDataExport ) { return; }
			var mine = ++exportToken, key = contextKey( loadedData ), dates = datesOf( loadedData );
			var params = { format: 'csv', group: loadedData.group, from: dates.from, to: dates.to, compare_from: dates.compare_from, compare_to: dates.compare_to };
			function current() { return attached() && mine === exportToken && ! rangeBusy && settled && eligible( loadedData ) && contextKey( loadedData ) === key; }
			function failedExport() { if ( current() ) { exporting = false; exportStatus.textContent = T.exportError; syncExport(); } }
			exporting = true; syncExport(); exportStatus.textContent = T.exportBusy;
			try {
				window.PcDataExport.prepare( PATH, params ).then( function ( file ) {
					if ( ! current() ) { return; }
					try { var started = window.PcDataExport.download( file, current ); if ( current() ) { exporting = false; exportStatus.textContent = started ? T.exportDone : T.exportError; syncExport(); } }
					catch ( e ) { failedExport(); }
				}, failedExport );
			} catch ( e ) { failedExport(); }
		} );
		result.addEventListener( 'click', function ( event ) {
			var button = event.target;
			if ( button && button.getAttribute && button.getAttribute( 'data-conv-retry' ) && ! rangeBusy ) { load( true ); return; }
			while ( button && button !== result && ( ! button.getAttribute || ! button.getAttribute( 'data-conv-toggle' ) ) ) { button = button.parentNode; }
			if ( ! loadedData || ! button || button === result ) { return; }
			var token = button.getAttribute( 'data-conv-toggle' );
			var group = loadedData.group || state.group;
			var row = ( loadedData.rows || [] ).filter( function ( item ) { return encodeURIComponent( group + ':' + item.key ) === token; } )[ 0 ];
			if ( ! row ) { return; }
			expanded[ token ] = ! expanded[ token ];
			var slot = result.querySelector( '[data-conv-slot="' + token + '"]' );
			slot.innerHTML = card( group, row, ctx );
			slot.querySelector( '[data-conv-toggle]' ).focus();
		} );

		function failed() {
			loadedData = null; retainedData = null; settled = true; cancelExport();
			result.innerHTML = '<p class="pc-data-empty">' + esc( ctx.strings.loadError ) + '</p><button type="button" class="button" data-conv-retry="1">' + esc( T.retry ) + '</button>';
		}

		function load( explicit ) {
			if ( ! attached() || rangeBusy ) { return; }
			loadedData = null; settled = false; cancelExport();
			var mine = ++requests, wanted = state.group;
			var params = explicit ? { from: acceptedRange.dates.from, to: acceptedRange.dates.to, compare_from: acceptedRange.dates.compare_from, compare_to: acceptedRange.dates.compare_to } : rangeParams( range );
			params.group = wanted;
			result.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>';
			ctx.api( PATH, params ).then( function ( data ) {
				if ( ! attached() || rangeBusy || mine !== requests || wanted !== state.group ) { return; }
				try {
					if ( ! data || ( data.group && data.group !== wanted ) ) { throw new Error( 'Invalid report grouping' ); }
					result.innerHTML = view( data, ctx ); loadedData = data; retainedData = data; settled = true;
					if ( datesOf( data ) ) { acceptedRange = data.range; }
					syncExport();
				} catch ( e ) { failed(); }
			}, function () { if ( attached() && ! rangeBusy && mine === requests && wanted === state.group ) { failed(); } } );
		}

		if ( 'function' === typeof ctx.onRangeState ) {
			var firstRangeNotice = true;
			unsubscribe = ctx.onRangeState( function ( on ) {
				if ( disposed ) { return; }
				var wasBusy = rangeBusy; rangeBusy = on;
				if ( on ) { ++requests; cancelExport(); if ( resumeTimer !== null ) { window.clearTimeout( resumeTimer ); resumeTimer = null; } }
				if ( ! on && wasBusy && ! firstRangeNotice ) {
					if ( settled && eligible( retainedData ) ) { loadedData = retainedData; }
					else { resumeTimer = window.setTimeout( function () { resumeTimer = null; if ( attached() && ! rangeBusy ) { load( true ); } }, 0 ); }
				}
				firstRangeNotice = false; syncExport();
			} );
		}

		select.addEventListener( 'change', function () {
			var wanted = select.value;
			state.group = GROUPS.indexOf( wanted ) !== -1 ? wanted : GROUPS[ 0 ];
			retainedData = null; loadedData = null; cancelExport();
			saveGroup( state.group );
			load();
		} );

		load();
	}

	PcData.register( 'conversion', render );
} )( window.PcData, window.pcDataConversion );
