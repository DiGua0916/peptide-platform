/** One disposable inline full-table panel. Operations supplies its accepted range and formatters. */
( function () {
	'use strict';
	var mounts = 0;
	var PATH = '/peptide/v1/data/rankings/table';
	window.PcDataRankings = { mount: function ( section, accepted, ctx, options ) {
		var T = options.strings, esc = ctx.esc;
		var id = 'pc-ranks-full-' + ( ++mounts );
		var panel = section.querySelector( '.pc-ranks-panel' );
		var openers = section.querySelectorAll( '[data-rank-open]' );
		var state = { open: false, table: '', q: '', page: 1, perPage: 20, data: null, loading: false, exporting: false, dirty: false, composing: false, rangeBusy: !! options.rangeBusy };
		var queryToken = 0, exportToken = 0, searchTimer = 0, recoveryTimer = 0, disposed = false, opener = null;
		var dates = accepted.range.dates;
		function each( list, fn ) { Array.prototype.forEach.call( list, fn ); }
		function fill( text, map ) { var value = String( text ); Object.keys( map ).forEach( function ( key ) { value = value.split( key ).join( String( map[key] ) ); } ); return value; }
		function attached() { return ! disposed && document.documentElement.contains( panel ); }
		function params( source ) { return { from: source.from, to: source.to, compare_from: source.compare_from, compare_to: source.compare_to, table: state.table, q: state.q, page: state.page, per_page: state.perPage }; }
		function target( node, key ) { while ( node && node !== section ) { if ( node.nodeType === 1 && node.hasAttribute( key ) ) { return node; } node = node.parentNode; } return null; }
		panel.id = id; panel.hidden = true;
		panel.innerHTML = '<div class="pc-ranks-head"><h3 class="pc-ranks-title" tabindex="-1" id="' + esc( id + '-title' ) + '"></h3><button type="button" class="button pc-ranks-close">' + esc( T.rankClose ) + '</button></div>' +
			'<div class="pc-ranks-toolbar"><input type="search" class="pc-ranks-search" maxlength="100" aria-label="' + esc( T.rankSearch ) + '" placeholder="' + esc( T.rankSearch ) + '">' +
			'<label class="pc-ranks-per"><span>' + esc( T.rankPerPage ) + '</span><select class="pc-ranks-size"><option value="20">20</option><option value="50">50</option><option value="100">100</option></select></label>' +
			'<button type="button" class="button pc-ranks-export" title="' + esc( T.rankExportHelp ) + '">' + esc( T.exportCsv ) + '</button><span class="pc-ranks-status" role="status" aria-live="polite"></span></div>' +
			'<div class="pc-ranks-result" aria-live="polite"></div><div class="pc-ranks-foot"></div><button type="button" class="button pc-ranks-retry" hidden>' + esc( T.rankRetry ) + '</button>';
		panel.setAttribute( 'role', 'region' ); panel.setAttribute( 'aria-labelledby', id + '-title' );
		var title = panel.querySelector( '.pc-ranks-title' ), close = panel.querySelector( '.pc-ranks-close' ), search = panel.querySelector( '.pc-ranks-search' ), size = panel.querySelector( '.pc-ranks-size' ), exportButton = panel.querySelector( '.pc-ranks-export' ), status = panel.querySelector( '.pc-ranks-status' ), result = panel.querySelector( '.pc-ranks-result' ), foot = panel.querySelector( '.pc-ranks-foot' ), retry = panel.querySelector( '.pc-ranks-retry' );
		each( openers, function ( button ) { button.id = id + '-open-' + button.getAttribute( 'data-rank-open' ); button.setAttribute( 'aria-controls', id ); button.setAttribute( 'aria-expanded', 'false' ); } );
		function sync() {
			each( openers, function ( button ) { button.disabled = state.rangeBusy; button.setAttribute( 'aria-expanded', state.open && button === opener ? 'true' : 'false' ); } );
			close.disabled = search.disabled = size.disabled = state.rangeBusy;
			exportButton.disabled = state.rangeBusy || state.loading || state.exporting || state.dirty || state.composing || ! state.data;
			exportButton.setAttribute( 'aria-busy', state.exporting ? 'true' : 'false' );
			retry.disabled = state.rangeBusy || state.loading;
			each( foot.querySelectorAll( '[data-ranks-page]' ), function ( button ) { var next = Number( button.getAttribute( 'data-ranks-page' ) ); button.disabled = state.rangeBusy || state.loading || next < 1 || ! state.data || next > Math.max( 1, Math.ceil( state.data.total / state.data.per_page ) ); } );
		}
		function invalidateExport() { ++exportToken; state.exporting = false; status.textContent = ''; }
		function invalidateQuery() { ++queryToken; invalidateExport(); window.clearTimeout( searchTimer ); window.clearTimeout( recoveryTimer ); state.loading = false; state.data = null; }
		function paint( data ) {
			var heading = T[ 't_' + state.table ] + ( data.currency ? ' (' + data.currency + ')' : '' ); title.textContent = heading;
			var body = data.rows.map( function ( row ) {
				var label = options.rankLabel( state.table, row ), url = String( row.edit_url || '' );
				var named = /^https?:\/\//i.test( url ) || '/' === url.charAt( 0 ) ? '<a href="' + esc( url ) + '">' + esc( label ) + '</a>' : esc( label );
				var change = ctx.change( row.change ); if ( data.change_unit === 'percentage_points' ) { change = change.replace( /%$/, '' ); }
				return '<tr><th scope="row">' + named + '</th><td>' + esc( options.rankValue( state.table, row.current, ctx ) ) + '</td><td>' + esc( options.rankValue( state.table, row.previous, ctx ) ) + '</td><td>' + esc( change ) + '</td></tr>';
			} ).join( '' );
			result.innerHTML = data.rows.length ? '<div class="pc-ranks-scroll"><table class="pc-ops-table pc-ranks-table"><thead><tr><th scope="col">' + esc( T.rankName ) + '</th><th scope="col">' + esc( T.thisPeriod ) + '</th><th scope="col">' + esc( T.previousPeriod ) + '</th><th scope="col">' + esc( T.change + ( data.change_unit === 'percentage_points' ? ' (' + T.rankPoints + ')' : ' (%)' ) ) + '</th></tr></thead><tbody>' + body + '</tbody></table></div>' : '<p class="pc-data-empty">' + esc( T.rankEmpty ) + '</p>';
			var pages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
			foot.innerHTML = '<span>' + esc( fill( T.rankTotal, { '%d': ctx.number( data.total ) } ) ) + '</span><span>' + esc( fill( T.rankPage, { '%1$d': data.page, '%2$d': pages } ) ) + '</span><button type="button" class="button" data-ranks-page="' + esc( data.page - 1 ) + '">' + esc( T.rankPrev ) + '</button><button type="button" class="button" data-ranks-page="' + esc( data.page + 1 ) + '">' + esc( T.rankNext ) + '</button>';
		}
		function failed() { state.loading = false; state.data = null; result.innerHTML = '<p class="pc-data-empty" role="alert">' + esc( T.rankingsError ) + '</p>'; foot.innerHTML = ''; retry.hidden = false; sync(); }
		function load( corrected ) {
			if ( ! attached() || ! state.open || state.rangeBusy ) { return; }
			invalidateQuery(); var mine = queryToken; state.loading = true;
			result.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>'; foot.innerHTML = ''; retry.hidden = true; sync();
			ctx.api( PATH, params( dates ) ).then( function ( data ) {
				if ( mine !== queryToken || ! attached() || ! state.open || state.rangeBusy ) { return; }
				try {
					if ( ! corrected && ! data.rows.length && data.total > 0 && data.page > 1 ) { state.page = Math.max( 1, Math.ceil( data.total / data.per_page ) ); load( true ); return; }
					state.loading = false; state.data = data; state.page = data.page; paint( data ); sync();
				} catch ( error ) { failed(); }
			}, function () {
				if ( mine !== queryToken || ! attached() || ! state.open || state.rangeBusy ) { return; }
				failed();
			} );
		}
		function shut( restore ) { invalidateQuery(); state.open = false; panel.hidden = true; sync(); if ( restore && opener && document.documentElement.contains( opener ) ) { opener.focus(); } }
		function open( button ) {
			if ( state.rangeBusy || disposed ) { return; }
			invalidateQuery(); opener = button; state.open = true; state.table = button.getAttribute( 'data-rank-open' ); state.q = ''; state.page = 1; state.perPage = 20; state.dirty = state.composing = false;
			search.value = ''; size.value = '20'; panel.hidden = false; title.textContent = T[ 't_' + state.table ]; sync(); title.focus(); load();
		}
		function delegated( event ) {
			var button = target( event.target, 'data-rank-open' ); if ( button ) { open( button ); return; }
			button = target( event.target, 'data-ranks-page' ); if ( button && ! button.disabled ) { state.page = Number( button.getAttribute( 'data-ranks-page' ) ); load(); }
		}
		section.addEventListener( 'click', delegated );
		close.addEventListener( 'click', function () { shut( true ); } );
		retry.addEventListener( 'click', function () { load(); } );
		function edited() { state.dirty = true; invalidateQuery(); result.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>'; foot.innerHTML = ''; retry.hidden = true; sync(); }
		function searchNow() { window.clearTimeout( searchTimer ); if ( state.rangeBusy || state.composing ) { return; } var q = search.value.replace( /^\s+|\s+$/g, '' ); state.dirty = false; if ( q === state.q && state.data ) { sync(); return; } state.q = q; state.page = 1; load(); }
		search.addEventListener( 'compositionstart', function () { state.composing = true; window.clearTimeout( searchTimer ); edited(); } );
		search.addEventListener( 'compositionend', function () { state.composing = false; edited(); searchTimer = window.setTimeout( searchNow, 400 ); } );
		search.addEventListener( 'input', function () { window.clearTimeout( searchTimer ); edited(); if ( ! state.composing ) { searchTimer = window.setTimeout( searchNow, 400 ); } } );
		search.addEventListener( 'keydown', function ( event ) { if ( ( event.key === 'Enter' || event.keyCode === 13 ) && ! state.composing ) { event.preventDefault(); searchNow(); } } );
		size.addEventListener( 'change', function () { var value = Number( size.value ); if ( [20,50,100].indexOf( value ) === -1 || state.rangeBusy || state.composing ) { return; } state.perPage = value; state.page = 1; state.q = search.value.replace( /^\s+|\s+$/g, '' ); state.dirty = false; load(); } );
		exportButton.addEventListener( 'click', function () {
			if ( exportButton.disabled ) { return; }
			var mine = ++exportToken, query = params( state.data.range.dates ); query.format = 'csv'; state.exporting = true; status.textContent = T.exportBusy; sync();
			function current() { return mine === exportToken && attached() && state.open && ! state.rangeBusy && ! state.dirty && ! state.composing && !! state.data; }
			window.PcDataExport.prepare( PATH, query ).then( function ( file ) { if ( ! current() ) { return; } try { if ( window.PcDataExport.download( file, current ) ) { status.textContent = T.exportDone; } } catch ( e ) { status.textContent = T.exportError; } state.exporting = false; sync(); }, function () { if ( current() ) { status.textContent = T.exportError; state.exporting = false; sync(); } } );
		} );
		function setRangeBusy( on ) {
			if ( disposed || on === state.rangeBusy ) { sync(); return; }
			state.rangeBusy = on;
			if ( on ) { invalidateQuery(); if ( state.open ) { result.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>'; foot.innerHTML = ''; retry.hidden = true; } }
			else if ( state.open ) { recoveryTimer = window.setTimeout( function () { if ( attached() && state.open && ! state.rangeBusy ) { state.composing = false; state.q = search.value.replace( /^\s+|\s+$/g, '' ); state.dirty = false; load(); } }, 0 ); }
			sync();
		}
		sync();
		return { setRangeBusy: setRangeBusy, dispose: function () { if ( disposed ) { return; } shut( false ); disposed = true; section.removeEventListener( 'click', delegated ); } };
	} };
} )();
