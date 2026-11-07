/**
 * 应用统计页（DATA-01 第 8 步）的报表：每个已安装的应用一张卡片（应用名称、开关状态、设置链接），卡片里每个指标一格：
 * 本期的数字、对比时间段（VS）的数字和变化。现在只有一个应用：离开意图增收。
 * 数字来自应用统计接口（见下面的 PATH；口径都在 PeptideCore\Data\Apps，这里不自己算），
 * 文字来自 window.pcDataApps.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 apps 页面的报表，由 data.js 的外壳调用，切换时间段时会再调用一次。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/apps';

	// 请求计数：切换时间段后，旧请求晚到的结果不再画。
	var requests = 0;

	function isNum( v ) {
		return v !== undefined && v !== null && ! isNaN( Number( v ) );
	}

	// 百分比（领取率）：没有值显示“-”，最多 2 位小数。
	function pct( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	// 链接只接受 http(s) 或站内路径。
	function safeUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) || '/' === s.charAt( 0 ) ? s : '';
	}

	// 一个指标的显示值：次数是整数，金额是 2 位小数，领取率带 %。
	function value( kind, v, ctx ) {
		if ( 'money' === kind ) {
			return ctx.money( v );
		}
		if ( 'rate' === kind ) {
			return pct( v );
		}
		return ctx.number( v );
	}

	// 变化：文字由 ctx.change() 给出（领取率的变化是百分点，同样带正负号和 %），涨绿跌红。
	function changeHtml( v, ctx ) {
		var dir = isNum( v ) && Number( v ) > 0 ? ' is-up' : ( isNum( v ) && Number( v ) < 0 ? ' is-down' : '' );
		return '<span class="pc-apps-tile__change' + dir + '">' + ctx.esc( ctx.change( v ) ) + '</span>';
	}

	function tile( metric, ctx ) {
		var esc = ctx.esc;
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var label = ( T[ metric.key ] || metric.key ) + ( 'money' === metric.kind && code ? ' (' + code + ')' : '' );
		return '<div class="pc-apps-tile">' +
			'<div class="pc-apps-tile__label">' + esc( label ) + '</div>' +
			'<div class="pc-apps-tile__value">' + esc( value( metric.kind, metric.current, ctx ) ) + '</div>' +
			'<div class="pc-apps-tile__foot">' +
				'<span class="pc-apps-tile__prev">' + esc( ctx.strings.vs + ' ' + value( metric.kind, metric.previous, ctx ) ) + '</span> ' +
				changeHtml( metric.change, ctx ) +
			'</div>' +
		'</div>';
	}

	function card( app, ctx ) {
		var esc = ctx.esc;
		var on = !! app.enabled;
		var settings = safeUrl( app.settings_url );
		var link = settings ?
			'<a class="pc-apps-card__settings" href="' + esc( settings ) + '">' + esc( T.settings ) + '</a>' :
			'';
		// 版式对照参考后台：左卡 = 应用名 + 主指标（大号本期 / 灰色对比期 / 变化）+ 其余指标列表；
		// 右卡 = 主指标的逐日曲线（接口 series；旧接口没有 series 时显示本期 / 对比期对照条）。
		var metrics = app.metrics || [];
		var main = metrics[ 0 ];
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var labelOf = function ( m ) { return ( T[ m.key ] || m.key ) + ( 'money' === m.kind && code ? ' (' + code + ')' : '' ); };
		var rows = metrics.slice( 1 ).map( function ( m ) {
			return '<div class="pc-apps-row"><span class="pc-apps-row__label">' + esc( labelOf( m ) ) + '</span>' +
				'<span class="pc-apps-row__cur">' + esc( value( m.kind, m.current, ctx ) ) + '</span>' +
				'<span class="pc-apps-row__prev"><span class="screen-reader-text">' + esc( ctx.strings.vs + ' ' ) + '</span>' + esc( value( m.kind, m.previous, ctx ) ) + '</span>' +
				changeHtml( m.change, ctx ) + '</div>';
		} ).join( '' );
		var bars = metrics.filter( function ( m ) { return 'rate' !== m.kind; } ).map( function ( m ) {
			var cur = Number( m.current ) || 0, prev = Number( m.previous ) || 0, top = Math.max( cur, prev, 1 );
			return '<div class="pc-apps-bar"><span class="pc-apps-bar__label">' + esc( labelOf( m ) ) + '</span>' +
				'<span class="pc-apps-bar__track"><span class="pc-apps-bar__fill" style="width:' + esc( Math.round( cur / top * 100 ) ) + '%"></span></span><span class="pc-apps-bar__num">' + esc( value( m.kind, m.current, ctx ) ) + '</span>' +
				'<span class="pc-apps-bar__track"><span class="pc-apps-bar__fill is-prev" style="width:' + esc( Math.round( prev / top * 100 ) ) + '%"></span></span><span class="pc-apps-bar__num is-prev">' + esc( value( m.kind, m.previous, ctx ) ) + '</span></div>';
		} ).join( '' );
		// 有逐日数据时画参考后台那样的主指标曲线（本期实线、对比期灰线）；没有时退回本期 / 对比期对照条。
		var series = app.series && app.series.current && app.series.current.length && ctx.lineChart ? app.series : null;
		var seriesMetric = series ? metrics.filter( function ( m ) { return m.key === series.metric; } )[ 0 ] : null;
		var chartTitle = seriesMetric ? labelOf( seriesMetric ) : app.name;
		var chartBody = series ? ctx.lineChart( {
			cur: series.current.map( function ( p ) { return Number( p.value ) || 0; } ),
			prev: ( series.previous || [] ).map( function ( p ) { return Number( p.value ) || 0; } ),
			labels: series.current.map( function ( p ) { return String( p.day || '' ).slice( 5 ); } ),
			w: 640, h: 260, title: chartTitle
		} ) : bars;
		return '<div class="pc-apps-pair"><section class="pc-apps-card">' +
			'<div class="pc-apps-card__head">' +
				'<h2 class="pc-apps-card__name">' + esc( app.name ) + '</h2>' +
				'<span class="pc-apps-badge ' + ( on ? 'is-on' : 'is-off' ) + '">' + esc( on ? T.on : T.off ) + '</span>' +
				link +
			'</div>' +
			( main ? '<div class="pc-apps-main"><div class="pc-apps-main__top"><span class="pc-apps-main__label">' + esc( labelOf( main ) ) + '</span>' + changeHtml( main.change, ctx ) + '</div>' +
				'<div class="pc-apps-main__nums"><span class="pc-apps-main__cur">' + esc( value( main.kind, main.current, ctx ) ) + '</span><span class="pc-apps-main__prev"><span class="screen-reader-text">' + esc( ctx.strings.vs + ' ' ) + '</span>' + esc( value( main.kind, main.previous, ctx ) ) + '</span></div></div>' : '' ) +
			'<div class="pc-apps-rows">' + rows + '</div>' +
		'</section>' +
		'<section class="pc-apps-chart"><div class="pc-apps-chart__head"><h3>' + esc( chartTitle ) + '</h3>' +
			'<span class="pc-apps-legend"><i></i>' + esc( chartTitle ) + '<i class="is-prev"></i>' + esc( chartTitle + ' (' + ( T.previousPeriod || '' ) + ')' ) + '</span></div>' + chartBody + '</section></div>';
	}

	function view( data, ctx ) {
		var apps = data.apps || [];
		if ( ! apps.length ) {
			return '<p class="pc-data-empty">' + ctx.esc( T.noApps ) + '</p>';
		}
		return '<div class="pc-apps-data">' + apps.map( function ( app ) {
			return card( app, ctx );
		} ).join( '' ) + '</div>';
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

	PcData.register( 'apps', render );
} )( window.PcData, window.pcDataApps );
