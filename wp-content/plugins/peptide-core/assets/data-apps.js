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
		var tiles = ( app.metrics || [] ).map( function ( metric ) {
			return tile( metric, ctx );
		} ).join( '' );
		return '<section class="pc-apps-card">' +
			'<div class="pc-apps-card__head">' +
				'<h2 class="pc-apps-card__name">' + esc( app.name ) + '</h2>' +
				'<span class="pc-apps-badge ' + ( on ? 'is-on' : 'is-off' ) + '">' + esc( on ? T.on : T.off ) + '</span>' +
				link +
			'</div>' +
			'<div class="pc-apps-tiles">' + tiles + '</div>' +
		'</section>';
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
