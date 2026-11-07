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
	var FAMILY_LABEL_KEYS = { woo: 'wooSource', campaign: 'campaignSource', subscription: 'subscriptionSource', loyalty: 'loyaltySource' };

	function familyLabel( family ) {
		return Object.prototype.hasOwnProperty.call( FAMILY_LABEL_KEYS, family ) ? T[ FAMILY_LABEL_KEYS[ family ] ] : T.unknownSource;
	}

	// 请求计数：切换时间段后，旧请求晚到的结果不再画。
	var requests = 0;
	var filters = { family: '', sender: '' };
	try {
		var initial = new window.URL( window.location.href );
		filters.family = initial.searchParams.get( 'mail_family' ) || '';
		filters.sender = initial.searchParams.get( 'mail_sender' ) || '';
	} catch ( e ) {}

	function isNum( v ) {
		return v !== undefined && v !== null && isFinite( Number( v ) );
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

	function observationValue( key, value, ctx ) {
		if ( ! isNum( value ) ) { return '-'; }
		return /_rate$/.test( key ) ? pct( value ) : ctx.number( value );
	}

	function observationTiles( report, ctx ) {
		return [ 'opened', 'open_rate', 'clicked', 'click_rate' ].map( function ( key ) {
			return '<div class="pc-emails-tile"><div class="pc-emails-tile__label">' + ctx.esc( T[ key ] ) + '</div>' +
				'<div class="pc-emails-tile__value">' + ctx.esc( observationValue( key, report.current && report.current[ key ], ctx ) ) + '</div>' +
				'<div class="pc-emails-tile__prev">' + ctx.esc( ctx.strings.vs + ' ' + observationValue( key, report.previous && report.previous[ key ], ctx ) ) + '</div></div>';
		} ).join( '' );
	}

	function observationFilters( report, ctx ) {
		var esc = ctx.esc;
		var option = function ( value, label, current ) { return '<option value="' + esc( value ) + '"' + ( value === current ? ' selected' : '' ) + '>' + esc( label ) + '</option>'; };
		var senders = option( '', T.allSenders, filters.sender );
		var found = ! filters.sender;
		( report.senders || [] ).forEach( function ( sender ) {
			if ( sender.sender_key === filters.sender ) { found = true; }
			senders += option( sender.sender_key, sender.sender, filters.sender );
		} );
		if ( ! found ) { senders += option( filters.sender, T.unobservedSender, filters.sender ); }
		return '<div class="pc-emails-filters"><label>' + esc( T.sourceFamily ) + ' <select class="pc-mail-family">' +
			option( '', T.allSources, filters.family ) + Object.keys( FAMILY_LABEL_KEYS ).map( function ( family ) { return option( family, familyLabel( family ), filters.family ); } ).join( '' ) +
			'</select></label><label>' + esc( T.sender ) + ' <select class="pc-mail-sender">' + senders + '</select></label></div>';
	}

	function observationTable( report, ctx ) {
		var esc = ctx.esc;
		var columns = [ 'attempts', 'accepted', 'failed', 'uncertain', 'pending', 'open_eligible', 'opened', 'open_rate', 'click_eligible', 'clicked', 'click_rate' ];
		var head = '<th scope="col">' + esc( T.sourceFamily ) + '</th><th scope="col">' + esc( T.name ) + '</th><th scope="col">' + esc( T.sender ) + '</th><th scope="col">' + esc( T.coverage ) + '</th>';
		columns.forEach( function ( key ) { head += '<th scope="col">' + esc( T[ key ] ) + '</th>'; } );
		var rows = ( report.rows || [] ).map( function ( row ) {
			return '<tr><td>' + esc( familyLabel( row.family ) ) + '</td><th scope="row">' + esc( row.name || row.source_key ) + '</th><td>' + esc( row.sender || T.unknownSender ) + '</td><td>' + esc( T[ 'coverage_' + row.coverage ] || T.coverageUnknown ) + '<br><small>' + esc( row.mime || '-' ) + '</small></td>' +
				columns.map( function ( key ) { return '<td>' + esc( observationValue( key, row[ key ], ctx ) ) + '</td>'; } ).join( '' ) + '</tr>';
		} ).join( '' );
		if ( ! rows ) { rows = '<tr><td colspan="15">' + esc( T.noObservedMessages ) + '</td></tr>'; }
		return '<div class="pc-emails-table-wrap"><table class="pc-emails-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
	}

	function view( data, ctx ) {
		var esc = ctx.esc;
		var report = data.observations || { status: 'unavailable', current: null, previous: null, rows: [] };
		var logUrl = safeUrl( data.log_url );
		var link = logUrl ?
			' <a class="pc-emails-loglink" href="' + esc( logUrl ) + '">' + esc( T.logLink ) + '</a>' :
			'';
		// 版式对照参考后台：发送数 / 成功率 / 打开 / 点击六个指标排成一条，下面是每天发送数的大曲线，再下面是邮件表。
		// 两组指标的小标题保留在页面里（读屏和口径），视觉上收起。“查看发送记录”放到标题旁。
		if ( logUrl && ctx.setActions ) { ctx.setActions( '<a href="' + esc( logUrl ) + '">' + esc( T.logLink ) + '</a>' ); link = ''; }
		var series = data.series && data.series.current && data.series.current.length > 0 && ctx.lineChart ? data.series : null;
		return '<div class="pc-emails-data">' +
			'<section class="pc-emails-overview"><div class="pc-emails-strip">' +
				'<div class="pc-emails-group"><h2>' + esc( T.legacyTitle ) + '</h2><div class="pc-emails-tiles">' + tile( 'sent', data, ctx ) + tile( 'success_rate', data, ctx ) + '</div></div>' +
				'<div class="pc-emails-group"><h2>' + esc( T.observationTitle ) + '</h2><div class="pc-emails-tiles">' + observationTiles( report, ctx ) + '</div></div>' +
			'</div>' +
			( series ? '<div class="pc-emails-chart">' + ctx.lineChart( {
				cur: series.current.map( function ( p ) { return Number( p.sent ) || 0; } ),
				prev: ( series.previous || [] ).map( function ( p ) { return Number( p.sent ) || 0; } ),
				labels: series.current.map( function ( p ) { return String( p.day || '' ); } ),
				w: 1100, h: 300, title: T.sent
			} ) + '<div class="pc-emails-legend"><span><i></i>' + esc( ( series.current[ 0 ] || {} ).day + ' ~ ' + ( series.current[ series.current.length - 1 ] || {} ).day ) + '</span>' +
				( series.previous && series.previous.length ? '<span><i class="is-prev"></i>' + esc( series.previous[ 0 ].day + ' ~ ' + series.previous[ series.previous.length - 1 ].day ) + '</span>' : '' ) + '</div></div>' : '' ) +
			'</section>' +
			'<p class="pc-data-muted pc-emails-note">' + esc( T.legacyHelp ) + link + '</p>' +
			'<p class="pc-data-muted">' + esc( T.observationHelp ) + '</p>' +
			tableHtml( data.rows || [], ctx ) +
			'<p role="status">' + esc( T[ 'observation_' + report.status ] || T.observation_unavailable ) + '</p>' +
			observationFilters( report, ctx ) + observationTable( report, ctx ) +
		'</div>';
	}

	function render( bodyEl, range, ctx ) {
		var mine = ++requests;
		var d = range.dates || {};
		var params = range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to };
		params.mail_family = filters.family;
		params.mail_sender = filters.sender;
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
					var reload = function () {
						filters.family = bodyEl.querySelector( '.pc-mail-family' ).value;
						filters.sender = bodyEl.querySelector( '.pc-mail-sender' ).value;
						try {
							var url = new window.URL( window.location.href );
							if ( filters.family ) { url.searchParams.set( 'mail_family', filters.family ); } else { url.searchParams['delete']( 'mail_family' ); }
							if ( filters.sender ) { url.searchParams.set( 'mail_sender', filters.sender ); } else { url.searchParams['delete']( 'mail_sender' ); }
							window.history.replaceState( null, '', url.href );
						} catch ( e ) {}
						render( bodyEl, range, ctx );
					};
					bodyEl.querySelector( '.pc-mail-family' ).addEventListener( 'change', reload );
					bodyEl.querySelector( '.pc-mail-sender' ).addEventListener( 'change', reload );
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
