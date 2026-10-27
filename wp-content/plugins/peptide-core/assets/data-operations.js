/**
 * 运营数据页（DATA-01 第 2 步）的报表：12 个指标卡片、访客转化漏斗、回访用户转化漏斗，每个数字都和对比时间段（VS）一起显示。
 * 数字来自运营数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Operations，这里不自己算），
 * 文字来自 window.pcDataOperations.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 operations 页面的报表，由 data.js 的外壳调用，切换时间段时会再调用一次。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/operations';
	// 和 Operations::KPIS / LAYERS 的顺序一致（Shopname 的卡片顺序）。
	var KPIS = [ 'sales', 'visitors', 'registered', 'orders', 'conversion', 'aov', 'items', 'net_item_sales', 'refunds', 'avg_duration', 'customers', 'mobile' ];
	var LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'paid' ];
	var MONEY = { sales: true, aov: true, net_item_sales: true, refunds: true };

	// 请求计数：切换时间段后，旧请求晚到的结果不再画。
	var requests = 0;

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

	function duration( seconds ) {
		var s = Math.max( 0, Math.round( Number( seconds ) || 0 ) );
		return fill( T.duration, { '%1$d': Math.floor( s / 60 ), '%2$d': s % 60 } );
	}

	// 一个指标的显示值。
	function value( key, v, ctx ) {
		if ( MONEY[ key ] ) {
			return ctx.money( v );
		}
		if ( 'conversion' === key ) {
			return pct( v );
		}
		if ( 'avg_duration' === key ) {
			return duration( v );
		}
		return ctx.number( v );
	}

	// 变化：文字由 ctx.change() 给出（转化率的变化是百分点，同样带正负号和 %），涨绿跌红。
	function changeHtml( v, ctx, cls ) {
		var dir = isNum( v ) && Number( v ) > 0 ? ' is-up' : ( isNum( v ) && Number( v ) < 0 ? ' is-down' : '' );
		return '<span class="' + cls + dir + '">' + ctx.esc( ctx.change( v ) ) + '</span>';
	}

	function kpis( data, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var cards = KPIS.map( function ( key ) {
			var label = T[ key ] + ( MONEY[ key ] && code ? ' (' + code + ')' : '' );
			return '<div class="pc-ops-kpi">' +
				'<div class="pc-ops-kpi__label">' + esc( label ) + '</div>' +
				'<div class="pc-ops-kpi__value">' + esc( value( key, data.current[ key ], ctx ) ) + '</div>' +
				'<div class="pc-ops-kpi__foot">' +
					'<span class="pc-ops-kpi__prev">' + esc( ctx.strings.vs + ' ' + value( key, data.previous[ key ], ctx ) ) + '</span> ' +
					changeHtml( data.change[ key ], ctx, 'pc-ops-kpi__change' ) +
				'</div>' +
			'</div>';
		} );
		return '<div class="pc-ops-kpis">' + cards.join( '' ) + '</div>';
	}

	// 层数量加上“（占上一层 %）”。
	function countWithStep( layer, ctx ) {
		if ( ! layer ) {
			return '-';
		}
		var text = ctx.number( layer.count );
		if ( isNum( layer.step_rate ) ) {
			text += ' (' + pct( layer.step_rate ) + ')';
		}
		return text;
	}

	function funnel( title, block, ctx ) {
		var esc = ctx.esc;
		var cur = block.current || [];
		var prev = block.previous || [];
		var change = block.change || {};
		var last = cur[ cur.length - 1 ] || {};
		var prevLast = prev[ prev.length - 1 ] || {};

		var bars = LAYERS.map( function ( key, i ) {
			var layer = cur[ i ] || { count: 0, visitor_rate: null };
			var width = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
			return '<div class="pc-ops-step">' +
				'<span class="pc-ops-step__label">' + esc( T[ key ] ) + '</span>' +
				'<span class="pc-ops-bar"><span class="pc-ops-bar__fill" style="width:' + esc( width ) + '%"></span></span>' +
				'<span class="pc-ops-step__count">' + esc( ctx.number( layer.count ) ) + '</span>' +
				'<span class="pc-ops-step__rate">' + esc( fill( T.ofVisitors, { '%s': pct( layer.visitor_rate ) } ) ) + '</span>' +
			'</div>';
		} );

		var rows = LAYERS.map( function ( key, i ) {
			return '<tr>' +
				'<th scope="row">' + esc( T[ key ] ) + '</th>' +
				'<td>' + esc( countWithStep( cur[ i ], ctx ) ) + '</td>' +
				'<td>' + esc( countWithStep( prev[ i ], ctx ) ) + '</td>' +
				'<td>' + changeHtml( change[ key ], ctx, 'pc-ops-change' ) + '</td>' +
			'</tr>';
		} );

		return '<section class="pc-ops-funnel">' +
			'<h2 class="pc-ops-funnel__title">' + esc( title ) +
				' <span class="pc-ops-funnel__rate">' + esc( pct( last.visitor_rate ) ) + '</span>' +
				' <span class="pc-ops-muted">' + esc( ctx.strings.vs + ' ' + pct( prevLast.visitor_rate ) ) + '</span>' +
			'</h2>' +
			'<div class="pc-ops-steps">' + bars.join( '' ) + '</div>' +
			'<div class="pc-ops-table-wrap"><table class="pc-ops-table">' +
				'<thead><tr>' +
					'<th scope="col">' + esc( T.event ) + '</th>' +
					'<th scope="col">' + esc( T.thisPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.previousPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.change ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody>' +
			'</table></div>' +
		'</section>';
	}

	function view( data, ctx ) {
		var esc = ctx.esc;
		var html = '';
		if ( ! data.tracking ) {
			html += '<p class="pc-ops-note">' + esc( T.trackingOff ) + '</p>';
		}
		if ( Number( data.test_orders ) > 0 ) {
			html += '<p class="pc-ops-note">' + esc( fill( T.testOrders, { '%d': Number( data.test_orders ) } ) ) + '</p>';
		}
		html += kpis( data, ctx );
		html += funnel( T.visitorFunnel, data.funnel || {}, ctx );
		html += funnel( T.returningFunnel, data.returning || {}, ctx );
		return '<div class="pc-ops">' + html + '</div>';
	}

	function render( bodyEl, range, ctx ) {
		var mine = ++requests;
		var d = range.dates || {};
		var params = range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to };
		var failed = function () {
			bodyEl.innerHTML = '<p class="pc-data-empty">' + ctx.esc( ctx.strings.loadError ) + '</p>';
		};

		bodyEl.innerHTML = '<p class="pc-data-muted">' + ctx.esc( ctx.strings.loading ) + '</p>';
		ctx.api( PATH, params ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				try {
					bodyEl.innerHTML = view( data, ctx );
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

	PcData.register( 'operations', render );
} )( window.PcData, window.pcDataOperations );
