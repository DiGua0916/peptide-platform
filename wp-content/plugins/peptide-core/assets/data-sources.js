/** Single-period source report; only the accepted request may draw or export. */
( function ( PcData, cfg ) {
	'use strict';
	if ( ! PcData || ! cfg || ! cfg.strings ) { return; }
	var T = cfg.strings, PATH = '/peptide/v1/data/sources';
	var METRICS = [ 'uv', 'customers', 'viewed_uv', 'carted_uv', 'paid_uv', 'orders', 'conversion', 'sales', 'aov', 'uv_value' ];
	var AD_GROUPS = { campaign: [ 'utm_campaign' ], adset: [ 'utm_campaign', 'utm_term' ], ad: [ 'utm_campaign', 'utm_term', 'utm_content' ] };
	// 三种广告分组只在服务端说开着（cfg.adGroups）时出现在下拉里；默认没有这一项，下拉和以前一样只有两个选项。
	var AD_OFFERED = cfg.adGroups ? Object.keys( AD_GROUPS ) : [];
	var state = { sort: 'uv', order: 'desc', page: 1, per_page: 20, split_medium: 0, group_by: 'utm', q: '' }, generation = 0, disposePrevious = null;
	function operationsNavigation( data, esc ) {
		var dates = data && data.range && data.range.dates;
		if ( typeof cfg.operationsUrl !== 'string' || ! cfg.operationsUrl || ! dates || typeof dates.from !== 'string' || typeof dates.to !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( dates.from ) || ! /^\d{4}-\d{2}-\d{2}$/.test( dates.to ) || ! T.backToOperations ) { return ''; }
		var href = cfg.operationsUrl + ( cfg.operationsUrl.indexOf( '?' ) < 0 ? '?' : '&' ) + 'from=' + encodeURIComponent( dates.from ) + '&to=' + encodeURIComponent( dates.to );
		return '<a href="' + esc( href ) + '">' + esc( T.backToOperations ) + '</a>';
	}
	function render( body, range, ctx ) {
		if ( disposePrevious ) { disposePrevious(); }
		var esc = ctx.esc, accepted = null, disposed = false, rangeBusy = false, exporting = false, exportToken = 0, unsubscribe = null;
		state.page = 1;
		body.innerHTML = '<div class="pc-sources"><div class="pc-sources-back"></div><div class="pc-sources-toolbar"><label>' + esc( T.groupBy ) + ' <select class="pc-sources-group"><option value="utm">' + esc( T.utm ) + '</option><option value="channel">' + esc( T.channel ) + '</option>' + AD_OFFERED.map( function ( key ) { return '<option value="' + key + '">' + esc( T[ key ] || key ) + '</option>'; } ).join( '' ) + '</select></label><label><input type="checkbox" class="pc-sources-split"' + ( state.split_medium ? ' checked' : '' ) + '> ' + esc( T.split ) + '</label><label>' + esc( T.search ) + ' <input type="search" class="pc-sources-search" maxlength="100"></label><button type="button" class="button pc-sources-apply">' + esc( T.search ) + '</button><button type="button" class="button pc-sources-export" disabled>' + esc( T.exportCsv ) + '</button></div><p class="pc-data-muted pc-sources-note">' + esc( noteText() ) + '</p><p class="pc-sources-status" role="status" aria-live="polite"></p><div class="pc-sources-wrap"></div><div class="pc-sources-foot"></div></div>';
		var split = body.querySelector( '.pc-sources-split' ), search = body.querySelector( '.pc-sources-search' ), apply = body.querySelector( '.pc-sources-apply' ), button = body.querySelector( '.pc-sources-export' ), status = body.querySelector( '.pc-sources-status' ), wrap = body.querySelector( '.pc-sources-wrap' ), foot = body.querySelector( '.pc-sources-foot' );
		var group = body.querySelector( '.pc-sources-group' ), note = body.querySelector( '.pc-sources-note' );
		search.value = state.q; group.value = state.group_by;
		function attached() { return ! disposed && document.documentElement.contains( body ); }
		function current( mine ) { return attached() && ! rangeBusy && mine === generation; }
		function draftMatches() { return search.value === state.q && group.value === state.group_by && Number( split.checked ) === state.split_medium; }
		function sync() { button.disabled = ! accepted || exporting || rangeBusy || ! draftMatches(); button.setAttribute( 'aria-busy', exporting ? 'true' : 'false' ); split.disabled = rangeBusy || state.group_by !== 'utm'; group.disabled = rangeBusy; apply.disabled = rangeBusy; }
		function invalidate() { accepted = null; ++exportToken; exporting = false; sync(); }
		function params() { var d = range.dates, out = { from: d.from, to: d.to, sort: state.sort, order: state.order, page: state.page, per_page: state.per_page, split_medium: state.split_medium, q: state.q }; if ( state.group_by !== 'utm' ) { out.group_by = state.group_by; } return out; }
		// M6“按广告拆分”：广告系列 / 广告组 / 广告三种分组，每种的身份列逐级细分（和服务端 Sources::AD_GROUPS 一致）。
		function identityKeys() { return state.group_by === 'channel' ? [ 'channel' ] : AD_GROUPS[ state.group_by ] ? AD_GROUPS[ state.group_by ] : [ 'utm_source' ].concat( state.split_medium ? [ 'utm_medium' ] : [] ); }
		function noteText() { return state.group_by === 'channel' ? T.channelNote : AD_GROUPS[ state.group_by ] ? ( T.adNote || T.note ) : T.note; }
		function typed( fact ) { return fact.state === 'value' ? String( fact.value ) : String( T[ fact.state ] || fact.state ); }
		function draw( data ) {
			if ( ! data || ! data.range || ! data.range.dates || data.range.dates.from !== range.dates.from || data.range.dates.to !== range.dates.to || ! Array.isArray( data.rows ) || ( data.group_by || 'utm' ) !== state.group_by || ( state.group_by === 'channel' && data.channel_policy !== 'traffic-v1' ) || Number( data.split_medium ) !== state.split_medium || data.sort !== state.sort || data.order !== state.order || data.q !== state.q || Number( data.page ) !== state.page || ! draftMatches() ) { throw new Error( 'Invalid source context' ); }
			data.rows.forEach( function ( row ) { identityKeys().forEach( function ( key ) { var f = row[ key ], allowed = key === 'channel' ? [ 'unobserved', 'unknown', 'value' ] : [ 'unobserved', 'missing', 'empty', 'value' ]; if ( ! f || allowed.indexOf( f.state ) < 0 || ( f.state === 'value' ? typeof f.value !== 'string' || f.value === '' : f.state === 'empty' ? f.value !== '' : f.value !== null ) || ( key === 'channel' && ( typeof row.channel_label !== 'string' || row.channel_label === '' ) ) ) { throw new Error( 'Invalid typed source' ); } } ); } );
			var coverageHtml = '';
			if ( state.group_by === 'channel' ) { [ 'visits', 'orders' ].forEach( function ( unit ) { var c = data.coverage && data.coverage[ unit ]; if ( ! c || c.unit !== unit || [ 'base_count', 'value', 'unknown', 'unobserved' ].some( function ( key ) { return typeof c[ key ] !== 'number' || c[ key ] < 0; } ) || c.value + c.unknown + c.unobserved !== c.base_count ) { throw new Error( 'Invalid channel coverage' ); } var values = { '%v': ctx.number( c.value ), '%n': ctx.number( c.base_count ), '%u': ctx.number( c.unknown ), '%o': ctx.number( c.unobserved ), '%t': T[ unit ] }; coverageHtml += '<p class="pc-data-muted">' + esc( T.channelCoverage.replace( /%[vnuot]/g, function ( token ) { return values[ token ]; } ) ) + '</p>'; } ); }
			accepted = data; status.textContent = '';
			var back = body.querySelector( '.pc-sources-back' ); if ( back ) { back.innerHTML = operationsNavigation( data, esc ); }
			if ( ! data.rows.length ) { wrap.innerHTML = coverageHtml + '<p class="pc-data-empty">' + esc( T.noData ) + '</p>'; }
			else {
				var html = coverageHtml + '<table><thead><tr>' + ( AD_GROUPS[ state.group_by ] ? AD_GROUPS[ state.group_by ].map( function ( key ) { return '<th class="pc-sources-id">' + esc( T[ key ] || key ) + '</th>'; } ).join( '' ) : '<th>' + esc( state.group_by === 'channel' ? T.channel : T.source ) + '</th>' + ( state.split_medium ? '<th>' + esc( T.medium ) + '</th>' : '' ) );
				METRICS.forEach( function ( key ) { html += '<th aria-sort="' + ( key === state.sort ? ( state.order === 'asc' ? 'ascending' : 'descending' ) : 'none' ) + '"><button type="button" class="button-link" data-sort="' + key + '">' + esc( T[ key ] ) + ( key === state.sort ? ( state.order === 'asc' ? ' ▲' : ' ▼' ) : '' ) + '</button></th>'; } );
				html += '</tr></thead><tbody>';
				data.rows.forEach( function ( row ) { html += '<tr>' + ( AD_GROUPS[ state.group_by ] ? AD_GROUPS[ state.group_by ].map( function ( key ) { return '<td class="pc-sources-id">' + esc( typed( row[ key ] ) ) + '</td>'; } ).join( '' ) : '<td>' + esc( state.group_by === 'channel' ? row.channel_label : typed( row.utm_source ) ) + '</td>' + ( state.split_medium ? '<td>' + esc( typed( row.utm_medium ) ) + '</td>' : '' ) ); METRICS.forEach( function ( key ) { var value = row[ key ]; html += '<td>' + esc( value === null ? '-' : [ 'sales', 'aov', 'uv_value' ].indexOf( key ) >= 0 ? Number( value ).toFixed( 2 ) + ' ' + String( ctx.currency && ctx.currency.code || '' ) : key === 'conversion' ? value + '%' : ctx.number( value ) ) + '</td>'; } ); html += '</tr>'; } );
				wrap.innerHTML = html + '</tbody></table>';
			}
			var pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
			foot.innerHTML = ctx.pager ? ctx.pager( { total: data.total, page: data.page, pages: pages } ) : '<span>' + esc( data.total ) + ' · ' + esc( data.page ) + '/' + esc( pages ) + '</span> <button type="button" class="button" data-dir="-1"' + ( data.page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button> <button type="button" class="button" data-dir="1"' + ( data.page >= pages ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>';
			sync();
		}
		function failed() { invalidate(); status.textContent = ctx.strings.loadError; wrap.innerHTML = '<button type="button" class="button pc-sources-retry">' + esc( T.retry ) + '</button>'; foot.innerHTML = ''; var retry = wrap.querySelector( '.pc-sources-retry' ); retry.addEventListener( 'click', load ); }
		function load() { if ( ! attached() || rangeBusy ) { return; } var mine = ++generation; invalidate(); status.textContent = ctx.strings.loading; foot.innerHTML = ''; ctx.api( PATH, params() ).then( function ( data ) { if ( ! current( mine ) ) { return; } try { draw( data ); } catch ( error ) { failed(); } }, function () { if ( current( mine ) ) { failed(); } } ); }
		function submit() { state.q = search.value.replace( /^\s+|\s+$/g, '' ); search.value = state.q; state.page = 1; load(); }
		apply.addEventListener( 'click', submit );
		search.addEventListener( 'input', function () { ++generation; invalidate(); } );
		search.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Enter' && ! event.isComposing ) { event.preventDefault(); submit(); } } );
		split.addEventListener( 'change', function () { if ( state.group_by !== 'utm' ) { split.checked = false; return; } state.split_medium = split.checked ? 1 : 0; state.page = 1; load(); } );
		group.addEventListener( 'change', function () { if ( [ 'utm', 'channel' ].concat( AD_OFFERED ).indexOf( group.value ) < 0 ) { group.value = state.group_by; return; } state.group_by = group.value; if ( state.group_by !== 'utm' ) { state.split_medium = 0; split.checked = false; } state.page = 1; note.textContent = noteText(); wrap.innerHTML = ''; load(); } );
		function ancestor( element, attribute, stop ) { while ( element && element !== stop ) { if ( element.hasAttribute && element.hasAttribute( attribute ) ) { return element; } element = element.parentNode; } return null; }
		wrap.addEventListener( 'click', function ( event ) { var target = ancestor( event.target, 'data-sort', wrap ); if ( target ) { var key = target.getAttribute( 'data-sort' ); state.order = key === state.sort && state.order === 'desc' ? 'asc' : 'desc'; state.sort = key; state.page = 1; load(); } } );
		foot.addEventListener( 'click', function ( event ) { var target = ancestor( event.target, 'data-dir', foot ); if ( target && ! target.disabled ) { state.page = Math.max( 1, state.page + Number( target.getAttribute( 'data-dir' ) ) ); load(); } } );
		button.addEventListener( 'click', function () { if ( button.disabled || ! draftMatches() || ! window.PcDataExport ) { return; } var mine = ++exportToken, acceptedGeneration = generation, query = params(); query.format = 'csv'; exporting = true; sync(); status.textContent = T.exportBusy; function allowed() { return current( acceptedGeneration ) && mine === exportToken && accepted && draftMatches(); } window.PcDataExport.prepare( PATH, query ).then( function ( file ) { if ( ! allowed() ) { return; } try { if ( window.PcDataExport.download( file, allowed ) ) { status.textContent = T.exportDone; } } catch ( error ) { status.textContent = T.exportError; } exporting = false; sync(); }, function () { if ( allowed() ) { exporting = false; status.textContent = T.exportError; sync(); } } ); } );
		if ( typeof ctx.onRangeState === 'function' ) { unsubscribe = ctx.onRangeState( function ( on ) { var wasBusy = rangeBusy; rangeBusy = on; if ( on ) { ++generation; invalidate(); } else if ( wasBusy && attached() ) { load(); } sync(); } ); }
		disposePrevious = function () { disposed = true; ++generation; invalidate(); if ( unsubscribe ) { unsubscribe(); } };
		load();
	}
	PcData.register( 'sources', render );
} )( window.PcData, window.pcDataSources );
