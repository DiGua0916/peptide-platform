/** Single-period received experiment cohorts. Only accepted canonical contexts may draw/export. */
( function ( PcData, cfg ) {
	'use strict';
	if ( ! PcData || ! cfg || ! cfg.strings ) { return; }
	var T = cfg.strings, PATH = '/peptide/v1/data/experiments';
	var METRICS = [ 'exposures', 'exposed_visitors', 'eligible_visitors', 'contaminated_visits', 'viewed_visitors', 'carted_visitors', 'checkout_visitors', 'paid_visitors', 'orders', 'conversion', 'revenue' ];
	var IDENTITIES = [ 'experiment_id', 'landing_path', 'variant_id' ];
	var state = { q: '', sort: 'exposures', order: 'desc', page: 1, per_page: 20, filter: { entry_domain_state: 'all', country_state: 'all' } }, generation = 0, disposePrevious = null;
	function render( body, range, ctx ) {
		if ( disposePrevious ) { disposePrevious(); }
		var esc = ctx.esc, accepted = null, disposed = false, rangeBusy = false, exporting = false, exportToken = 0, unsubscribe = null;
		state.page = 1;
		// 标题旁的一句说明（对照参考后台）。
		if ( ctx.setActions && ctx.strings && ctx.strings.experimentsIntro ) { ctx.setActions( '<span class="pc-data-desc">' + esc( ctx.strings.experimentsIntro ) + '</span>' ); }
		function select( name, values ) { var labels = { all: 'filterAll', unobserved: 'filterUnobserved', unknown: 'filterUnknown', unavailable: 'filterUnavailable', value: 'filterValue' }; return '<select class="pc-exp-' + name + '">' + values.map( function ( key ) { return '<option value="' + key + '">' + esc( Object.prototype.hasOwnProperty.call( labels, key ) ? T[ labels[ key ] ] || key : key ) + '</option>'; } ).join( '' ) + '</select>'; }
		body.innerHTML = '<div class="pc-sources pc-experiments"><div class="pc-sources-toolbar"><label>' + esc( T.filterDomain ) + select( 'domain-state', [ 'all', 'unobserved', 'value' ] ) + '<input class="pc-exp-domain" maxlength="253" aria-label="' + esc( T.filterDomain ) + '"></label><label>' + esc( T.filterCountry ) + select( 'country-state', [ 'all', 'unobserved', 'unknown', 'unavailable', 'value' ] ) + '<input class="pc-exp-country" maxlength="2" aria-label="' + esc( T.filterCountry ) + '"></label><label>' + esc( T.search ) + '<input type="search" class="pc-exp-search" maxlength="100"></label><button type="button" class="button pc-exp-apply">' + esc( T.filterApply ) + '</button><label>' + esc( T.perPage || 'Per page' ) + select( 'perpage', [ '20', '50', '100' ] ) + '</label><button type="button" class="button pc-exp-export" disabled>' + esc( T.exportCsv ) + '</button></div><p class="pc-data-muted">' + esc( T.note ) + '</p><p class="pc-exp-status" role="status" aria-live="polite"></p><div class="pc-sources-wrap pc-exp-wrap"></div><div class="pc-sources-foot pc-exp-foot"></div></div>';
		function el( suffix ) { return body.querySelector( '.pc-exp-' + suffix ); }
		var domainState = el( 'domain-state' ), domain = el( 'domain' ), countryState = el( 'country-state' ), country = el( 'country' ), search = el( 'search' ), apply = el( 'apply' ), perpage = el( 'perpage' ), button = el( 'export' ), status = el( 'status' ), wrap = el( 'wrap' ), foot = el( 'foot' );
		function controls() { domainState.value = state.filter.entry_domain_state; domain.value = state.filter.entry_domain || ''; countryState.value = state.filter.country_state; country.value = state.filter.country || ''; search.value = state.q; perpage.value = String( state.per_page ); }
		controls();
		function wire() { var f = { entry_domain_state: domainState.value, country_state: countryState.value }; if ( f.entry_domain_state === 'value' ) { f.entry_domain = domain.value.replace( /^\s+|\s+$/g, '' ).toLowerCase().replace( /\.$/, '' ); } if ( f.country_state === 'value' ) { f.country = country.value.replace( /^\s+|\s+$/g, '' ).toUpperCase(); } return f; }
		function equal( a, b ) { return a && b && [ 'entry_domain_state', 'entry_domain', 'country_state', 'country' ].every( function ( key ) { return a[ key ] === b[ key ]; } ); }
		function dirty() { return search.value.replace( /^\s+|\s+$/g, '' ) !== state.q || ! equal( wire(), state.filter ); }
		function attached() { return ! disposed && document.documentElement.contains( body ); }
		function current( mine ) { return attached() && ! rangeBusy && mine === generation; }
		function sync() { button.disabled = ! accepted || exporting || rangeBusy || dirty(); button.setAttribute( 'aria-busy', exporting ? 'true' : 'false' ); apply.disabled = rangeBusy; }
		function invalidate() { accepted = null; ++exportToken; exporting = false; sync(); }
		function params() { var q = { from: range.dates.from, to: range.dates.to, q: state.q, sort: state.sort, order: state.order, page: state.page, per_page: state.per_page }; Object.keys( state.filter ).forEach( function ( key ) { q[ key ] = state.filter[ key ]; } ); return q; }
		function draw( data ) {
			if ( ! data || ! data.range || ! data.range.dates || data.range.dates.from !== range.dates.from || data.range.dates.to !== range.dates.to || ! Array.isArray( data.rows ) || data.q !== state.q || data.sort !== state.sort || data.order !== state.order || Number( data.page ) !== state.page || Number( data.per_page ) !== state.per_page || ! equal( data.filter, state.filter ) || data.full_coverage !== false || [ 'received', 'not_observed', 'unavailable' ].indexOf( data.status ) < 0 ) { throw new Error( 'Invalid experiment context' ); }
			if ( dirty() ) { throw new Error( 'Unapplied experiment draft' ); }
			accepted = data.status === 'unavailable' ? null : data; status.textContent = data.status === 'unavailable' ? T.unavailable : T.partial || 'Partial received exposure observations';
			if ( ! data.rows.length ) { wrap.innerHTML = '<p class="pc-data-empty">' + esc( data.status === 'unavailable' ? T.unavailable : T.noData ) + '</p>'; }
			else {
				var html = '<table><thead><tr>'; IDENTITIES.concat( METRICS ).forEach( function ( key ) { html += '<th aria-sort="' + ( key === state.sort ? ( state.order === 'asc' ? 'ascending' : 'descending' ) : 'none' ) + '"><button type="button" class="button-link" data-sort="' + key + '">' + esc( T[ key ] || key ) + '</button></th>'; } ); html += '</tr></thead><tbody>';
				data.rows.forEach( function ( row ) { html += '<tr>'; IDENTITIES.forEach( function ( key ) { html += '<td>' + esc( row[ key ] ) + '</td>'; } ); METRICS.forEach( function ( key ) { var value = row[ key ]; html += '<td>' + esc( value === null || value === undefined || ! isFinite( Number( value ) ) ? '-' : key === 'conversion' ? value + '%' : key === 'revenue' ? Number( value ).toFixed( 2 ) + ' ' + String( data.currency || '' ) : ctx.number( value ) ) + '</td>'; } ); html += '</tr>'; } ); wrap.innerHTML = html + '</tbody></table>';
			}
			var pages = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page ) ) ); foot.innerHTML = '<span>' + esc( data.total ) + ' · ' + esc( data.page ) + '/' + esc( pages ) + '</span><button type="button" class="button" data-dir="-1"' + ( data.page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button><button type="button" class="button" data-dir="1"' + ( data.page >= pages ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>'; sync();
		}
		function failed() { invalidate(); status.textContent = ctx.strings.loadError; wrap.innerHTML = '<button type="button" class="button pc-exp-retry">' + esc( T.retry ) + '</button>'; foot.innerHTML = ''; el( 'retry' ).addEventListener( 'click', load ); }
		function load() { if ( ! attached() || rangeBusy || dirty() ) { return; } var mine = ++generation; invalidate(); status.textContent = ctx.strings.loading; wrap.innerHTML = ''; foot.innerHTML = ''; ctx.api( PATH, params() ).then( function ( data ) { if ( ! current( mine ) ) { return; } try { draw( data ); } catch ( error ) { failed(); } }, function () { if ( current( mine ) ) { failed(); } } ); }
		function submit() { state.q = search.value.replace( /^\s+|\s+$/g, '' ); state.filter = wire(); state.page = 1; controls(); load(); }
		function draft() { ++generation; invalidate(); wrap.innerHTML = '<p class="pc-data-muted">' + esc( T.filterApply ) + '</p>'; foot.innerHTML = ''; }
		apply.addEventListener( 'click', submit ); search.addEventListener( 'input', draft ); search.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Enter' && ! event.isComposing ) { event.preventDefault(); submit(); } } );
		[ domainState, countryState ].forEach( function ( control ) { control.addEventListener( 'change', draft ); } ); [ domain, country ].forEach( function ( control ) { control.addEventListener( 'input', draft ); } );
		perpage.addEventListener( 'change', function () { state.per_page = Number( perpage.value ); state.page = 1; if ( ! dirty() ) { load(); } } );
		function ancestor( node, attribute, stop ) { while ( node && node !== stop ) { if ( node.hasAttribute && node.hasAttribute( attribute ) ) { return node; } node = node.parentNode; } return null; }
		wrap.addEventListener( 'click', function ( event ) { var target = ancestor( event.target, 'data-sort', wrap ); if ( target ) { var key = target.getAttribute( 'data-sort' ); if ( IDENTITIES.concat( METRICS ).indexOf( key ) < 0 ) { return; } state.order = key === state.sort && state.order === 'desc' ? 'asc' : 'desc'; state.sort = key; state.page = 1; load(); } } );
		foot.addEventListener( 'click', function ( event ) { var target = ancestor( event.target, 'data-dir', foot ); if ( target && ! target.disabled ) { state.page = Math.max( 1, state.page + Number( target.getAttribute( 'data-dir' ) ) ); load(); } } );
		button.addEventListener( 'click', function () { if ( button.disabled || ! window.PcDataExport ) { return; } var mine = ++exportToken, acceptedGeneration = generation, query = params(); query.format = 'csv'; exporting = true; sync(); status.textContent = T.exportBusy; function allowed() { return current( acceptedGeneration ) && mine === exportToken && accepted && ! dirty(); } window.PcDataExport.prepare( PATH, query ).then( function ( file ) { if ( ! allowed() ) { return; } try { if ( window.PcDataExport.download( file, allowed ) ) { status.textContent = T.exportDone; } } catch ( error ) { status.textContent = T.exportError; } exporting = false; sync(); }, function () { if ( allowed() ) { exporting = false; status.textContent = T.exportError; sync(); } } ); } );
		if ( typeof ctx.onRangeState === 'function' ) { unsubscribe = ctx.onRangeState( function ( on ) { var wasBusy = rangeBusy; rangeBusy = on; if ( on ) { ++generation; invalidate(); } else if ( wasBusy && attached() ) { load(); } sync(); } ); }
		disposePrevious = function () { disposed = true; ++generation; invalidate(); if ( unsubscribe ) { unsubscribe(); } }; load();
	}
	PcData.register( 'experiments', render );
} )( window.PcData, window.pcDataExperiments );
