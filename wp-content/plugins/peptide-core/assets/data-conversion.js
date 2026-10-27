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

	// 请求计数：改分组 / 切换时间段之后，旧请求晚到的结果不再画。
	var requests = 0;
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

	// 变化：文字由 ctx.change() 给出（转化率的变化是百分点，同样带正负号和 %），涨绿跌红。
	function changeHtml( v, ctx, cls ) {
		var dir = isNum( v ) && Number( v ) > 0 ? ' is-up' : ( isNum( v ) && Number( v ) < 0 ? ' is-down' : '' );
		return '<span class="' + cls + dir + '">' + ctx.esc( ctx.change( v ) ) + '</span>';
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
		var tiles = OVERALL.map( function ( key ) {
			var help = 'conversion' === key;
			return '<div class="pc-conv-tile"' + ( help ? ' title="' + esc( T.conversionHelp ) + '"' : '' ) + '>' +
				'<div class="pc-conv-tile__label">' + esc( T[ key ] ) +
					( help ? ' <span class="pc-conv-info" aria-hidden="true">ⓘ</span>' : '' ) +
				'</div>' +
				'<div class="pc-conv-tile__value">' + esc( overallValue( key, cur[ key ], ctx ) ) + '</div>' +
				'<div class="pc-conv-tile__foot">' +
					'<span class="pc-conv-muted">' + esc( ctx.strings.vs + ' ' + overallValue( key, prev[ key ], ctx ) ) + '</span> ' +
					changeHtml( chg[ key ], ctx, 'pc-conv-tile__change' ) +
				'</div>' +
			'</div>';
		} );
		return '<div class="pc-conv-overall">' + tiles.join( '' ) + '</div>';
	}

	// ---------- 分组卡片 ----------

	function card( group, row, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var funnel = row.funnel || [];
		var title = fill( T.groupRate, { '%s': groupLabel( group, row.key ) } );
		var steps = LAYERS.map( function ( key, i ) {
			var layer = funnel[ i ] || { count: 0, visitor_rate: null };
			var next = funnel[ i + 1 ];
			var width = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
			var html = '<div class="pc-conv-step">' +
				'<span class="pc-conv-step__label">' + esc( T[ key ] ) + '</span>' +
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
			'<h2 class="pc-conv-card__title">' + esc( title ) + ' <span class="pc-conv-card__rate">' + esc( pct( row.conversion ) ) + '</span></h2>' +
			'<p class="pc-conv-card__sales">' + esc( T.groupSales ) + ' <strong>' + esc( ctx.money( row.sales ) + ( code ? ' ' + code : '' ) ) + '</strong></p>' +
			'<div class="pc-conv-steps">' + steps.join( '' ) + '</div>' +
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
		html += '<p class="pc-conv-note">' + esc( T.notYet ) + '</p>';
		html += overall( data, ctx );
		if ( rows.length ) {
			html += '<div class="pc-conv-cards">' + rows.map( function ( row ) {
				return card( group, row, ctx );
			} ).join( '' ) + '</div>';
		} else {
			html += '<p class="pc-data-empty">' + esc( T.noGroups ) + '</p>';
		}
		return html;
	}

	// ---------- 报表 ----------

	function render( bodyEl, range, ctx ) {
		var esc = ctx.esc;
		var options = GROUPS.map( function ( key ) {
			return '<option value="' + esc( key ) + '"' + ( key === state.group ? ' selected' : '' ) + '>' + esc( T[ key ] ) + '</option>';
		} ).join( '' );

		// 工具栏每次 render 只建一次；改分组只重画下面的结果。
		bodyEl.innerHTML = '<div class="pc-conv">' +
			'<div class="pc-conv-toolbar">' +
				'<label class="pc-conv-label" for="pc-conv-group">' + esc( T.groupBy ) + '</label>' +
				'<select id="pc-conv-group" class="pc-conv-group">' + options + '</select>' +
			'</div>' +
			'<div class="pc-conv-result"></div>' +
		'</div>';
		var select = bodyEl.querySelector( '.pc-conv-group' );
		var result = bodyEl.querySelector( '.pc-conv-result' );

		function failed() {
			result.innerHTML = '<p class="pc-data-empty">' + esc( ctx.strings.loadError ) + '</p>';
		}

		function load() {
			var mine = ++requests;
			var params = rangeParams( range );
			params.group = state.group;

			result.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>';
			ctx.api( PATH, params ).then(
				function ( data ) {
					if ( mine !== requests ) {
						return;
					}
					try {
						result.innerHTML = view( data, ctx );
					} catch ( e ) {
						failed();
					}
				},
				function () {
					if ( mine !== requests ) {
						return;
					}
					failed();
				}
			);
		}

		select.addEventListener( 'change', function () {
			var wanted = select.value;
			state.group = GROUPS.indexOf( wanted ) !== -1 ? wanted : GROUPS[ 0 ];
			saveGroup( state.group );
			load();
		} );

		load();
	}

	PcData.register( 'conversion', render );
} )( window.PcData, window.pcDataConversion );
