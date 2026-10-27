/**
 * 邮件详情数据页（DATA-01 第 8 步）的报表：两个合计（发送数、成功率），每个都和对比时间段（VS）一起显示；
 * 下面是一张表，每种邮件一行：邮件名称、发送数、成功率（只有本期）。
 * 邮件的打开数、打开率、点击数、点击率现在没有统计，页面上用一句话说明，并给出发送记录的链接。
 * 数字来自邮件详情数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Emails，这里不自己算），
 * 文字来自 window.pcDataEmails.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 emails 页面的报表，由 data.js 的外壳调用，切换时间段时会再调用一次。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/emails';

	// 请求计数：切换时间段后，旧请求晚到的结果不再画。
	var requests = 0;

	function isNum( v ) {
		return v !== undefined && v !== null && ! isNaN( Number( v ) );
	}

	// 成功率：没有值（发送数是 0）显示“-”，最多 2 位小数。
	function pct( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	// 链接只接受 http(s) 或站内路径。
	function safeUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) || '/' === s.charAt( 0 ) ? s : '';
	}

	// 变化：文字由 ctx.change() 给出（成功率的变化和 Shopname 一样是相对百分比），涨绿跌红。
	function changeHtml( v, ctx ) {
		var dir = isNum( v ) && Number( v ) > 0 ? ' is-up' : ( isNum( v ) && Number( v ) < 0 ? ' is-down' : '' );
		return '<span class="pc-emails-tile__change' + dir + '">' + ctx.esc( ctx.change( v ) ) + '</span>';
	}

	// 一个合计：key 是 sent 或 success_rate。成功率那一格带说明（鼠标悬停显示）和一个小的 ⓘ。
	function tile( key, data, ctx ) {
		var esc = ctx.esc;
		var rate = 'success_rate' === key;
		var cur = data.current || {};
		var prev = data.previous || {};
		var change = data.change || {};
		var show = function ( v ) {
			return rate ? pct( v ) : ctx.number( v );
		};
		return '<div class="pc-emails-tile"' + ( rate ? ' title="' + esc( T.successHelp ) + '"' : '' ) + '>' +
			'<div class="pc-emails-tile__label">' + esc( T[ key ] ) +
				( rate ? ' <span class="pc-emails-help" aria-hidden="true">ⓘ</span>' : '' ) +
			'</div>' +
			'<div class="pc-emails-tile__value">' + esc( show( cur[ key ] ) ) + '</div>' +
			'<div class="pc-emails-tile__foot">' +
				'<span class="pc-emails-tile__prev">' + esc( ctx.strings.vs + ' ' + show( prev[ key ] ) ) + '</span> ' +
				changeHtml( change[ key ], ctx ) +
			'</div>' +
		'</div>';
	}

	function tableHtml( rows, ctx ) {
		var esc = ctx.esc;
		if ( ! rows.length ) {
			return '<p class="pc-data-empty">' + esc( T.noEmails ) + '</p>';
		}
		var body = rows.map( function ( row ) {
			return '<tr>' +
				'<th scope="row" class="pc-emails-name">' + esc( row.name ) + '</th>' +
				'<td>' + esc( ctx.number( row.sent ) ) + '</td>' +
				'<td>' + esc( pct( row.success_rate ) ) + '</td>' +
			'</tr>';
		} ).join( '' );
		return '<div class="pc-emails-table-wrap"><table class="pc-emails-table">' +
			'<thead><tr>' +
				'<th scope="col">' + esc( T.name ) + '</th>' +
				'<th scope="col">' + esc( T.sent ) + '</th>' +
				'<th scope="col">' + esc( T.success_rate ) + '</th>' +
			'</tr></thead>' +
			'<tbody>' + body + '</tbody>' +
		'</table></div>';
	}

	function view( data, ctx ) {
		var esc = ctx.esc;
		var logUrl = safeUrl( data.log_url );
		var link = logUrl ?
			' <a class="pc-emails-loglink" href="' + esc( logUrl ) + '">' + esc( T.logLink ) + '</a>' :
			'';
		return '<div class="pc-emails-data">' +
			'<div class="pc-emails-tiles">' + tile( 'sent', data, ctx ) + tile( 'success_rate', data, ctx ) + '</div>' +
			'<p class="pc-data-muted pc-emails-note">' + esc( T.notTracked ) + link + '</p>' +
			tableHtml( data.rows || [], ctx ) +
		'</div>';
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

	PcData.register( 'emails', render );
} )( window.PcData, window.pcDataEmails );
