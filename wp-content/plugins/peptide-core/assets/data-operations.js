/**
 * 运营数据页（DATA-01 第 2 步）的报表：12 个指标卡片、访客转化漏斗、回访用户转化漏斗，每个数字都和对比时间段（VS）一起显示。
 * 数字来自运营数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Operations，这里不自己算），
 * 文字来自 window.pcDataOperations.strings，插入页面的值都经过 ctx.esc() 转义。
 * 两个漏斗下面是九张趋势图（DATA-01 第 7a 步）：访客数（带浏览量）、订单数、销售额、客单数、客单价、复购率、复购订单量、复购成交额、复购客单价，
 * 每张图有本期合计、变化和本期 / 对比期两条折线；数字来自趋势接口（见下面的 TRENDS_PATH，口径在 PeptideCore\Data\Trends）。
 * 趋势图单独请求：失败只影响趋势图那一块，指标卡片和漏斗照常显示。
 * 趋势图下面是十四张排行表（DATA-01 第 7b 步）：热卖商品、热门商品、浏览商品、加购商品、商品类型（件数和金额）、订单来源国家、顾客来源国家、
 * 访客浏览器、订单浏览器、下单支付方式、支付成功率、优惠券使用、设备；每张表有名称、本期、对比期和变化，默认显示前 10 行，“查看更多”展开到最多 50 行。
 * 数字来自排行表接口（见下面的 RANKINGS_PATH，口径在 PeptideCore\Data\Rankings）；同样单独请求，失败只影响排行表那一块。
 * 文件末尾把 render 注册成 operations 页面的报表，由 data.js 的外壳调用，切换时间段时会再调用一次。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/operations';
	var TRENDS_PATH = '/peptide/v1/data/trends';
	var RANKINGS_PATH = '/peptide/v1/data/rankings';
	// 和 Operations::KPIS / LAYERS 的顺序一致（Shopname 的卡片顺序）。
	var KPIS = [ 'sales', 'visitors', 'registered', 'orders', 'conversion', 'aov', 'items', 'net_item_sales', 'refunds', 'avg_duration', 'customers', 'mobile' ];
	var LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'paid' ];
	var DETAIL_LAYERS = [ 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid' ];
	var expanded = { visitor: false, returning: false };
	var MONEY = { sales: true, aov: true, net_item_sales: true, refunds: true };
	// 九张趋势图，顺序 = Shopname 的顺序：key 是趋势接口里的指标，title / help 是 strings 的 key，kind 决定纵轴和数值的格式。
	var TRENDS = [
		{ key: 'visitors', title: 'trendVisitors', help: '', kind: 'count' },
		{ key: 'orders', title: 'trendOrders', help: '', kind: 'count' },
		{ key: 'sales', title: 'trendSales', help: '', kind: 'money' },
		{ key: 'units_per_order', title: 'trendUnits', help: 'helpUnits', kind: 'decimal' },
		{ key: 'aov', title: 'trendAov', help: '', kind: 'money' },
		{ key: 'repurchase_rate', title: 'trendRepRate', help: 'helpRepRate', kind: 'percent' },
		{ key: 'repurchase_orders', title: 'trendRepOrders', help: 'helpRepOrders', kind: 'count' },
		{ key: 'repurchase_sales', title: 'trendRepSales', help: '', kind: 'money' },
		{ key: 'repurchase_aov', title: 'trendRepAov', help: 'helpRepAov', kind: 'money' }
	];
	var TREND_MONEY = { sales: true, aov: true, repurchase_sales: true, repurchase_aov: true };
	// 十四张排行表，顺序 = Rankings::TABLES（Shopname 的页面顺序）；标题是 strings 里的 't_' + key。
	var RANK_TABLES = [ 'products_units', 'products_sales', 'products_viewed', 'products_carted', 'categories_units', 'categories_sales', 'order_countries', 'customer_countries', 'visitor_browsers', 'order_browsers', 'payment_methods', 'payment_success', 'coupons', 'devices' ];
	var RANK_MONEY = { products_sales: true, categories_sales: true };
	// 名称是 unknown 时要翻译的表（商品、分类和优惠券的名称是真实的名字，不翻译）。
	var RANK_UNKNOWN = { order_countries: true, customer_countries: true, visitor_browsers: true, order_browsers: true, payment_methods: true, payment_success: true, devices: true };
	var RANK_DEVICES = { desktop: 'labelDesktop', mobile: 'labelMobile', tablet: 'labelTablet' };
	// 每张表默认显示的行数；更多的行（最多 50）点“查看更多”才显示。
	var RANK_SHOW = 10;

	// 请求计数：切换时间段后，旧请求晚到的结果不再画。
	var requests = 0;
	var disposePreviousRankings = null;

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

	// 链接只接受 http(s) 或站内路径。
	function safeUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) || '/' === s.charAt( 0 ) ? s : '';
	}

	// 从 node 往上找带有某个属性的元素，到 stop 为止。
	function findAttr( node, attr, stop ) {
		while ( node && node !== stop ) {
			if ( 1 === node.nodeType && node.hasAttribute( attr ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
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

	function funnel( title, block, ctx, id ) {
		var esc = ctx.esc;
		var detailed = block.unit === 'visitors' && block.current && block.current.length === 8;
		var keys = detailed && expanded[ id ] ? DETAIL_LAYERS : LAYERS;
		function project( values ) {
			var byKey = {};
			( values || [] ).forEach( function ( value ) { byKey[ value.key ] = value; } );
			return keys.map( function ( key, index ) {
				var value = byKey[ key ] || { count: 0, visitor_rate: null };
				var previous = index ? byKey[ keys[ index - 1 ] ] : null;
				return { count: value.count, visitor_rate: value.visitor_rate, step_rate: previous && previous.count ? Math.round( value.count / previous.count * 10000 ) / 100 : null };
			} );
		}
		var cur = project( block.current );
		var prev = project( block.previous );
		var change = block.change || {};
		var last = cur[ cur.length - 1 ] || {};
		var prevLast = prev[ prev.length - 1 ] || {};

		var bars = keys.map( function ( key, i ) {
			var layer = cur[ i ] || { count: 0, visitor_rate: null };
			var width = isNum( layer.visitor_rate ) ? Math.max( 0, Math.min( 100, Number( layer.visitor_rate ) ) ) : 0;
			return '<div class="pc-ops-step">' +
				'<span class="pc-ops-step__label">' + esc( T[ detailed && key === 'paid' ? 'paidVisitors' : key ] ) + '</span>' +
				'<span class="pc-ops-bar"><span class="pc-ops-bar__fill" style="width:' + esc( width ) + '%"></span></span>' +
				'<span class="pc-ops-step__count">' + esc( ctx.number( layer.count ) ) + '</span>' +
				'<span class="pc-ops-step__rate">' + esc( fill( T.ofVisitors, { '%s': pct( layer.visitor_rate ) } ) ) + '</span>' +
			'</div>';
		} );

		var rows = keys.map( function ( key, i ) {
			return '<tr>' +
				'<th scope="row">' + esc( T[ detailed && key === 'paid' ? 'paidVisitors' : key ] ) + '</th>' +
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
			'<p class="pc-ops-detail-help">' + esc( detailed ? T.detailHelp : T.detailUnavailable ) + '</p>' +
			( detailed ? '<button type="button" class="button pc-ops-detail-toggle" data-funnel-toggle="' + esc( id ) + '" aria-expanded="' + ( expanded[ id ] ? 'true' : 'false' ) + '" aria-controls="pc-ops-detail-' + esc( id ) + '">' + esc( expanded[ id ] ? T.hideDetails : T.viewDetails ) + '</button>' : '' ) +
			'<div id="pc-ops-detail-' + esc( id ) + '"><div class="pc-ops-steps">' + bars.join( '' ) + '</div>' +
			'<div class="pc-ops-table-wrap"><table class="pc-ops-table">' +
				'<thead><tr>' +
					'<th scope="col">' + esc( T.event ) + '</th>' +
					'<th scope="col">' + esc( T.thisPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.previousPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.change ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + rows.join( '' ) + '</tbody>' +
			'</table></div></div>' +
		'</section>';
	}

	// ---------- 趋势图 ----------

	// 趋势图上一个数字的显示值：金额 2 位小数，客单数 2 位小数，复购率带 % 且 2 位小数（没有值显示“-”），其余是整数。
	function trendValue( key, v, ctx ) {
		if ( TREND_MONEY[ key ] ) {
			return ctx.money( v );
		}
		if ( 'units_per_order' === key ) {
			return isNum( v ) ? Number( v ).toFixed( 2 ) : '-';
		}
		if ( 'repurchase_rate' === key ) {
			return isNum( v ) ? Number( v ).toFixed( 2 ) + '%' : '-';
		}
		return ctx.number( v );
	}

	// 一条折线的数值：没有值（复购率没有购买用户的桶）画成 0。
	function series( points, key ) {
		return ( points || [] ).map( function ( p ) {
			return p && isNum( p[ key ] ) ? Number( p[ key ] ) : 0;
		} );
	}

	// 纵轴最多 5 格，刻度取 1 / 2 / 5 乘以 10 的整数次方；次数类的刻度至少为 1 且是整数。
	// 用 Math.log / Math.LN10 算以 10 为底的对数：Math.log10 不是 ES5。
	function niceStep( max, whole ) {
		var raw = max / 5;
		if ( raw <= 0 ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log( raw ) / Math.LN10 ) );
		var n = raw / p;
		var st = ( n <= 1 ? 1 : ( n <= 2 ? 2 : ( n <= 5 ? 5 : 10 ) ) ) * p;
		return whole ? Math.max( 1, Math.ceil( st ) ) : st;
	}

	function axisText( v, kind ) {
		v = Math.round( v * 100 ) / 100;
		if ( 'percent' === kind ) {
			return v + '%';
		}
		return v >= 1000 ? ( Math.round( v / 100 ) / 10 ) + 'k' : String( v );
	}

	function r1( v ) {
		return Math.round( v * 10 ) / 10;
	}

	// 一张趋势图：本期实线、对比期虚线（按下标对应）；纵轴取两条线里最大的值，全是 0 时按 1 画，线贴在底线上。
	function trendChart( t, data, ctx ) {
		var esc = ctx.esc;
		var cur = ( data.current && data.current.points ) || [];
		var prev = ( data.previous && data.previous.points ) || [];
		var cv = series( cur, t.key );
		var pv = series( prev, t.key );
		var w = 560;
		var h = 200;
		var padL = 48;
		var padR = 20;
		var padT = 12;
		var padB = 26;

		var peak = 0;
		cv.concat( pv ).forEach( function ( v ) {
			if ( v > peak ) {
				peak = v;
			}
		} );
		if ( peak <= 0 ) {
			peak = 1;
		}
		var step = niceStep( peak, 'count' === t.kind );
		var ticks = Math.max( 1, Math.ceil( peak / step - 1e-9 ) );
		var max = step * ticks;
		var n = cv.length;
		var x = function ( i ) {
			return padL + ( n > 1 ? ( i * ( w - padL - padR ) ) / ( n - 1 ) : ( w - padL - padR ) / 2 );
		};
		var y = function ( v ) {
			return h - padB - ( v / max ) * ( h - padT - padB );
		};
		// 折线只画前 count 个点（对比期的点数可以和本期不同，只画两边都有的下标）。
		var line = function ( vals, count, cls ) {
			if ( count < 1 ) {
				return '';
			}
			if ( count < 2 ) {
				return '<circle class="pc-ops-dot ' + esc( cls ) + '" cx="' + esc( r1( x( 0 ) ) ) + '" cy="' + esc( r1( y( vals[ 0 ] ) ) ) + '" r="2.5"/>';
			}
			var pts = [];
			var i;
			for ( i = 0; i < count; i++ ) {
				pts.push( r1( x( i ) ) + ',' + r1( y( vals[ i ] ) ) );
			}
			return '<polyline class="pc-ops-line ' + esc( cls ) + '" points="' + esc( pts.join( ' ' ) ) + '"/>';
		};

		var svg = '<svg viewBox="0 0 ' + esc( w ) + ' ' + esc( h ) + '" class="pc-ops-chart" role="img" aria-label="' + esc( T[ t.title ] ) + '">';
		var g;
		for ( g = 0; g <= ticks; g++ ) {
			var gy = r1( y( g * step ) );
			svg += '<line class="pc-ops-grid' + ( g ? ' is-dash' : '' ) + '" x1="' + esc( padL ) + '" x2="' + esc( w - padR ) + '" y1="' + esc( gy ) + '" y2="' + esc( gy ) + '"/>' +
				'<text class="pc-ops-axis" x="' + esc( padL - 6 ) + '" y="' + esc( r1( gy + 4 ) ) + '" text-anchor="end">' + esc( axisText( g * step, t.kind ) ) + '</text>';
		}
		// 横轴标签取本期的点，最多显示 8 个。
		var every = Math.max( 1, Math.ceil( n / 8 ) );
		cur.forEach( function ( p, i ) {
			if ( 0 === i % every ) {
				svg += '<text class="pc-ops-axis" x="' + esc( r1( x( i ) ) ) + '" y="' + esc( h - 8 ) + '" text-anchor="middle">' + esc( p.label ) + '</text>';
			}
		} );
		svg += line( pv, Math.min( n, pv.length ), 'is-prev' );
		svg += line( cv, n, '' );
		// 每个本期的点带一个提示：时间和数值。
		cur.forEach( function ( p, i ) {
			svg += '<circle class="pc-ops-dot" cx="' + esc( r1( x( i ) ) ) + '" cy="' + esc( r1( y( cv[ i ] ) ) ) + '" r="3"><title>' +
				esc( fill( T.totalLine, { '%1$s': p.label, '%2$s': trendValue( t.key, p[ t.key ], ctx ) } ) ) + '</title></circle>';
		} );
		return svg + '</svg>';
	}

	function trendCard( t, data, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var name = T[ t.title ];
		var title = name + ( TREND_MONEY[ t.key ] && code ? ' (' + code + ')' : '' );
		var help = t.help ? T[ t.help ] : '';
		var totals = ( data.current && data.current.totals ) || {};
		var change = data.change || {};

		var html = '<div class="pc-ops-trend">' +
			'<h3 class="pc-ops-trend__title"' + ( help ? ' title="' + esc( help ) + '"' : '' ) + '>' + esc( title ) +
				( help ? ' <span class="pc-ops-help" tabindex="0" role="img" aria-label="' + esc( help ) + '" title="' + esc( help ) + '">' + esc( 'ⓘ' ) + '</span>' : '' ) +
			'</h3>' +
			'<div class="pc-ops-trend__total">' + esc( fill( T.totalLine, { '%1$s': name, '%2$s': trendValue( t.key, totals[ t.key ], ctx ) } ) ) + ' ' +
				changeHtml( change[ t.key ], ctx, 'pc-ops-change' ) + '</div>';
		if ( 'visitors' === t.key ) {
			// 访客数这张图的第二行：浏览量的合计和变化（浏览量不画线）。
			html += '<div class="pc-ops-trend__total pc-ops-trend__sub">' + esc( fill( T.totalLine, { '%1$s': T.trendPv, '%2$s': ctx.number( totals.pageviews ) } ) ) + ' ' +
				changeHtml( change.pageviews, ctx, 'pc-ops-change' ) + '</div>';
		}
		html += trendChart( t, data, ctx ) +
			'<div class="pc-ops-legend">' +
				'<span class="pc-ops-legend__item"><span class="pc-ops-swatch"></span>' + esc( T.thisPeriod ) + '</span>' +
				'<span class="pc-ops-legend__item"><span class="pc-ops-swatch is-prev"></span>' + esc( T.previousPeriod ) + '</span>' +
			'</div>' +
		'</div>';
		return html;
	}

	function trendsView( data, ctx ) {
		return TRENDS.map( function ( t ) {
			return trendCard( t, data, ctx );
		} ).join( '' );
	}

	// ---------- 排行表 ----------

	// 名称的显示：unknown 翻译成“未知”；设备的三个值和浏览器的 Other 翻译，其他原样显示。
	function rankLabel( name, row ) {
		var label = String( row.label === undefined || row.label === null ? '' : row.label );
		if ( RANK_UNKNOWN[ name ] && 'unknown' === label ) {
			return T.labelUnknown;
		}
		if ( 'devices' === name && Object.prototype.hasOwnProperty.call( RANK_DEVICES, label ) ) {
			return T[ RANK_DEVICES[ label ] ];
		}
		if ( ( 'visitor_browsers' === name || 'order_browsers' === name ) && 'Other' === label ) {
			return T.labelOther;
		}
		return label;
	}

	// 排行表上一个数字的显示值：金额 2 位小数，支付成功率带 %，其余是整数；没有值显示“-”。
	function rankValue( name, v, ctx ) {
		if ( ! isNum( v ) ) {
			return '-';
		}
		if ( RANK_MONEY[ name ] ) {
			return ctx.money( v );
		}
		if ( 'payment_success' === name ) {
			return pct( v );
		}
		return ctx.number( v );
	}

	// 一行：名称（商品有编辑链接时是链接）、本期、对比期、变化；第 11 行起默认藏起来。
	function rankRow( name, row, index, ctx ) {
		var esc = ctx.esc;
		var label = rankLabel( name, row );
		var url = safeUrl( row.edit_url );
		var nameHtml = url ?
			'<a href="' + esc( url ) + '">' + esc( label ) + '</a>' :
			'<span>' + esc( label ) + '</span>';
		return '<tr class="pc-ops-rank__row' + ( index >= RANK_SHOW ? ' is-extra' : '' ) + '">' +
			'<th scope="row">' + nameHtml + '</th>' +
			'<td>' + esc( rankValue( name, row.current, ctx ) ) + '</td>' +
			'<td>' + esc( rankValue( name, row.previous, ctx ) ) + '</td>' +
			'<td>' + changeHtml( row.change, ctx, 'pc-ops-change' ) + '</td>' +
		'</tr>';
	}

	// 一张排行表的卡片：标题（金额表带货币代码）、表格、超过 10 行时的“查看更多”按钮。
	function rankCard( name, table, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var title = T[ 't_' + name ] + ( RANK_MONEY[ name ] && code ? ' (' + code + ')' : '' );
		var rows = table && table.rows ? table.rows : [];
		var body = rows.length ?
			rows.map( function ( row, i ) {
				return rankRow( name, row, i, ctx );
			} ).join( '' ) :
			'<tr><td class="pc-ops-rank__empty" colspan="4">' + esc( T.rankEmpty ) + '</td></tr>';
		var more = rows.length > RANK_SHOW ?
			'<button type="button" class="button-link pc-ops-rank__toggle" data-rank-toggle="1" aria-expanded="false">' + esc( T.rankMore ) + '</button>' :
			'';
		return '<div class="pc-ops-rank" data-rank="' + esc( name ) + '">' +
			'<h3 class="pc-ops-rank__title">' + esc( title ) + '</h3>' +
			'<div class="pc-ops-table-wrap"><table class="pc-ops-table">' +
				'<thead><tr>' +
					'<th scope="col">' + esc( T.rankName ) + '</th>' +
					'<th scope="col">' + esc( T.thisPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.previousPeriod ) + '</th>' +
					'<th scope="col">' + esc( T.change ) + '</th>' +
				'</tr></thead>' +
				'<tbody>' + body + '</tbody>' +
			'</table></div>' +
			'<div class="pc-ops-rank__actions">' + more +
				'<span class="pc-ops-rank__count" data-rank-visible="' + esc( rows.length ) + '" data-rank-total="' + esc( table ? table.total_rows : 0 ) + '">' + esc( fill( T.rankShowing, { '%1$d': Math.min( RANK_SHOW, rows.length ), '%2$d': table ? table.total_rows : 0 } ) ) + '</span>' +
				'<button type="button" class="button pc-ops-rank__open" data-rank-open="' + esc( name ) + '" aria-expanded="false" aria-label="' + esc( fill( T.rankOpenLabel, { '%s': title } ) ) + '">' + esc( T.rankOpen ) + '</button>' +
			'</div>' +
		'</div>';
	}

	function rankingsView( data, ctx ) {
		var tables = ( data && data.tables ) || {};
		return '<h2 class="pc-ops-rankings__title">' + ctx.esc( T.rankings ) + '</h2>' +
			RANK_TABLES.map( function ( name ) {
				return rankCard( name, tables[ name ], ctx );
			} ).join( '' ) + '<div class="pc-ranks-panel" hidden></div>';
	}

	// “查看更多” / “收起”：只切换卡片的 is-expanded 类、按钮的 aria-expanded 和文字，不重画卡片。
	function toggleRank( box, e ) {
		var btn = findAttr( e.target, 'data-rank-toggle', box );
		var card = btn ? findAttr( btn, 'data-rank', box ) : null;
		if ( ! card ) {
			return;
		}
		var opening = 'true' !== btn.getAttribute( 'aria-expanded' );
		btn.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		btn.textContent = opening ? T.rankLess : T.rankMore;
		var count = card.querySelector( '[data-rank-total]' );
		if ( count ) { count.textContent = fill( T.rankShowing, { '%1$d': opening ? Number( count.getAttribute( 'data-rank-visible' ) ) : Math.min( RANK_SHOW, Number( count.getAttribute( 'data-rank-visible' ) ) ), '%2$d': Number( count.getAttribute( 'data-rank-total' ) ) } ); }
		if ( opening ) {
			card.classList.add( 'is-expanded' );
		} else {
			card.classList.remove( 'is-expanded' );
		}
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
		html += '<p class="pc-ops-detail-help">' + esc( T.orderVsVisitor ) + '</p>';
		html += '<div data-funnel-slot="visitor">' + funnel( T.visitorFunnel, data.visitor_funnel || data.funnel || {}, ctx, 'visitor' ) + '</div>';
		html += '<div data-funnel-slot="returning">' + funnel( T.returningFunnel, data.returning_visitor_funnel || data.returning || {}, ctx, 'returning' ) + '</div>';
		// 趋势图的位置：趋势数据到了再填（见 render）。
		html += '<section class="pc-ops-trends" aria-live="polite"></section>';
		// 排行表的位置：排行数据到了再填（见 render）。
		html += '<section class="pc-ops-rankings" aria-live="polite"></section>';
		return '<div class="pc-ops">' + html + '</div>';
	}

	function render( bodyEl, range, ctx ) {
		var mine = ++requests;
		if ( disposePreviousRankings ) { disposePreviousRankings(); }
		var rankPanel = null, rangeBusy = false, unsubscribeRange = null;
		disposePreviousRankings = function () { if ( rankPanel ) { rankPanel.dispose(); rankPanel = null; } if ( unsubscribeRange ) { unsubscribeRange(); unsubscribeRange = null; } };
		if ( typeof ctx.onRangeState === 'function' ) { unsubscribeRange = ctx.onRangeState( function ( on ) { rangeBusy = on; if ( rankPanel ) { rankPanel.setRangeBusy( on ); } } ); }
		var d = range.dates || {};
		var params = range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to };
		var failed = function () {
			if ( rankPanel ) { rankPanel.dispose(); rankPanel = null; }
			bodyEl.innerHTML = '<p class="pc-data-empty">' + ctx.esc( ctx.strings.loadError ) + '</p>';
		};
		// 三个请求的结果各自留着：运营数据画好页面（opsData），趋势数据到了（trends：{ data } 或 { error }），排行数据到了（rankings，同样）。
		// 每一块都要等运营数据的页面画好再填，谁先到都一样；运营数据失败时页面被错误提示替换，晚到的结果就不再画。
		var opsData = null;
		var trends = null;
		var rankings = null;
		var trendsFailed = function ( box ) {
			box.innerHTML = '<p class="pc-data-empty">' + ctx.esc( T.trendsError ) + '</p>';
		};
		var rankingsFailed = function ( box ) {
			box.innerHTML = '<p class="pc-data-empty">' + ctx.esc( T.rankingsError ) + '</p>';
		};
		var showRankings = function () {
			var box = opsData && rankings ? bodyEl.querySelector( '.pc-ops-rankings' ) : null;
			if ( ! box ) {
				return;
			}
			if ( rankPanel ) { rankPanel.dispose(); rankPanel = null; }
			if ( rankings.error ) {
				rankingsFailed( box );
				return;
			}
			try {
				box.innerHTML = rankingsView( rankings.data, ctx );
				if ( window.PcDataRankings ) { rankPanel = window.PcDataRankings.mount( box, rankings.data, ctx, { strings: T, rangeBusy: rangeBusy, rankLabel: rankLabel, rankValue: rankValue } ); }
				box.addEventListener( 'click', function ( e ) {
					toggleRank( box, e );
				} );
			} catch ( e ) {
				rankingsFailed( box );
			}
		};
		var showTrends = function () {
			var box = opsData && trends ? bodyEl.querySelector( '.pc-ops-trends' ) : null;
			if ( ! box ) {
				return;
			}
			if ( trends.error ) {
				trendsFailed( box );
				return;
			}
			try {
				box.innerHTML = trendsView( trends.data, ctx );
			} catch ( e ) {
				trendsFailed( box );
			}
		};

		bodyEl.innerHTML = '<p class="pc-data-muted">' + ctx.esc( ctx.strings.loading ) + '</p>';
		ctx.api( PATH, params ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				try {
					bodyEl.innerHTML = view( data, ctx );
					opsData = data;
					var box = bodyEl.querySelector( '.pc-ops' );
					box.addEventListener( 'click', function ( event ) {
						var button = findAttr( event.target, 'data-funnel-toggle', box );
						if ( ! button ) { return; }
						var id = button.getAttribute( 'data-funnel-toggle' );
						if ( id !== 'visitor' && id !== 'returning' ) { return; }
						expanded[ id ] = ! expanded[ id ];
						var slot = box.querySelector( '[data-funnel-slot="' + id + '"]' );
						slot.innerHTML = funnel( id === 'visitor' ? T.visitorFunnel : T.returningFunnel, id === 'visitor' ? data.visitor_funnel : data.returning_visitor_funnel, ctx, id );
						slot.querySelector( '[data-funnel-toggle]' ).focus();
					} );
				} catch ( e ) {
					failed();
					return;
				}
				showTrends();
				showRankings();
			},
			function () {
				if ( mine !== requests ) {
					return;
				}
				failed();
			}
		);
		ctx.api( TRENDS_PATH, params ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				trends = { data: data };
				showTrends();
			},
			function () {
				if ( mine !== requests ) {
					return;
				}
				trends = { error: true };
				showTrends();
			}
		);
		ctx.api( RANKINGS_PATH, params ).then(
			function ( data ) {
				if ( mine !== requests ) {
					return;
				}
				rankings = { data: data };
				showRankings();
			},
			function () {
				if ( mine !== requests ) {
					return;
				}
				rankings = { error: true };
				showRankings();
			}
		);
	}

	PcData.register( 'operations', render );
} )( window.PcData, window.pcDataOperations );
