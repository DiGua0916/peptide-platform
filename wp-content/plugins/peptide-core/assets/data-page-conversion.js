/* Page product conversion: reported episodes, explicit periods, ordered columns and disposable generations. */
( function ( PcData, cfg ) {
	'use strict';
	if ( ! PcData || ! cfg || ! cfg.strings ) { return; }
	var T = cfg.strings, STORE = 'pcDataPageConversionColumns';
	var KEYS = [ 'exposed_users', 'viewed_users', 'view_user_rate', 'cart_users', 'cart_user_rate', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate', 'carts', 'cart_rate', 'paid_units', 'paid_rate' ];
	var DEFAULTS = [ 'exposed_users', 'view_user_rate', 'viewed_users', 'cart_user_rate', 'cart_users', 'paid_users', 'paid_user_rate', 'impressions', 'views', 'view_rate' ];
	var KINDS = [ 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content', 'collection', 'search', 'unknown' ];
	var state = { group: 'type', type: '', q: '', sort: 'exposed_users', order: 'desc', page: 1, per_page: 20, columns: null, range: '' };
	var generation = 0, dispose = null;
	var reportFilter = null;
	try { var stored = JSON.parse( window.localStorage.getItem( STORE ) ); if ( Array.isArray( stored ) ) { state.columns = stored; } } catch ( ignore ) {}

	function columns( input ) {
		if ( ! Array.isArray( input ) ) { return DEFAULTS.slice(); }
		var out = []; input.forEach( function ( key ) { if ( KEYS.indexOf( key ) >= 0 && out.indexOf( key ) < 0 ) { out.push( key ); } } );
		return input.length && ! out.length ? DEFAULTS.slice() : out;
	}
	function dates( range ) { var d = range.dates || {}; return { from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to }; }
	function same( a, b ) { return JSON.stringify( a ) === JSON.stringify( b ); }
	function each( list, fn ) { Array.prototype.forEach.call( list, fn ); }
	function find( node, attr, stop ) { while ( node && node !== stop ) { if ( node.nodeType === 1 && node.hasAttribute( attr ) ) { return node; } node = node.parentNode; } return null; }
	function numeric( n ) { return typeof n === 'number' && isFinite( n ); }
	function safeId( n ) { return numeric( n ) && Math.floor( n ) === n && n > 0 && n <= 9007199254740991; }
	function rate( key ) { return /_rate$/.test( key ); }
	function filterToolbar( esc ) {
		function select( key, states ) { return '<select class="pc-report-' + key + '-state">' + states.map( function ( state ) { return '<option value="' + state + '">' + esc( T[ { all: 'filterAll', unobserved: 'filterUnobserved', unknown: 'filterUnknown', unavailable: 'filterUnavailable', value: 'filterValue' }[ state ] ] ) + '</option>'; } ).join( '' ) + '</select>'; }
		return '<div class="pc-report-filters"><label>' + esc( T.filterDomain ) + select( 'domain', [ 'all', 'unobserved', 'value' ] ) + '<input type="text" class="pc-report-domain" maxlength="253" aria-label="' + esc( T.filterDomain ) + '"></label><label>' + esc( T.filterCountry ) + select( 'country', [ 'all', 'unobserved', 'unknown', 'unavailable', 'value' ] ) + '<input type="text" class="pc-report-country" maxlength="2" aria-label="' + esc( T.filterCountry ) + '"></label><button type="button" class="button pc-report-filter-apply">' + esc( T.filterApply ) + '</button><p class="pc-data-muted">' + esc( T.filterHelp ) + '</p><p class="pc-report-coverage" role="status"></p></div>';
	}
	function url( value ) {
		if ( typeof value !== 'string' || ! /^https?:\/\//i.test( value ) || /[\x00-\x20<>"']/.test( value ) ) { return ''; }
		try { if ( window.URL ) { var u = new window.URL( value ), here = new window.URL( window.location.href ); if ( u.origin !== here.origin || u.search || u.hash || u.username || u.password ) { return ''; } } } catch ( ignore ) { return ''; }
		return value;
	}
	function valid( data, query ) {
		if ( ! data || typeof data.tracking !== 'boolean' || ! same( data.metrics, KEYS ) || ! same( data.defaults, DEFAULTS ) || ! Array.isArray( data.rows ) || ! numeric( data.total ) || data.total < 0 || Math.floor( data.total ) !== data.total || ! data.range || ! same( dates( data.range ), { from: query.from, to: query.to, compare_from: query.compare_from, compare_to: query.compare_to } ) ) { return false; }
		if ( data.group !== query.group || data.type !== query.type || data.q !== query.q || data.sort !== query.sort || data.order !== query.order || data.page !== query.page || data.per_page !== query.per_page || data.rows.length > query.per_page ) { return false; }
		if ( query.entry_domain_state !== undefined ) { var wanted = {}; [ 'entry_domain_state', 'entry_domain', 'country_state', 'country' ].forEach( function ( key ) { if ( query[ key ] !== undefined ) { wanted[ key ] = query[ key ]; } } ); if ( ! same( data.filter, wanted ) ) { return false; } }
		var m = data.measurement;
		if ( ! m || m.version !== 1 || m.minimum_visible_fraction !== 0.4 || m.minimum_visible_ms !== 500 || m.attribution !== 'same_visit_parent_after_impression_within_period' || m.source !== 'pushed_observations' ) { return false; }
		return data.rows.every( function ( row ) {
			if ( ! row || typeof row.key !== 'string' || KINDS.indexOf( row.kind ) < 0 || ( row.entity_id !== null && ! safeId( row.entity_id ) ) || typeof row.label !== 'string' || typeof row.path !== 'string' || typeof row.url !== 'string' || row.can_drill !== ( query.group === 'type' ) ) { return false; }
			return [ 'current', 'previous', 'change' ].every( function ( period ) { return row[ period ] && same( Object.keys( row[ period ] ), KEYS ) && KEYS.every( function ( key ) { var n = row[ period ][ key ]; return n === null ? period === 'change' || rate( key ) : numeric( n ); } ); } );
		} );
	}

	function render( body, range, ctx ) {
		if ( dispose ) { dispose(); }
		var esc = ctx.esc, alive = true, busy = false, loading = false, data = null, searchTimer = 0, restoreTimer = 0, unsubscribe = null, composing = false, acceptedDates = dates( range ), backKey = null, filterDirty = false, filterPending = false, acceptedFilter = reportFilter || { entry_domain_state: 'all', country_state: 'all' };
		var rangeKey = JSON.stringify( acceptedDates ); if ( rangeKey !== state.range ) { state.page = 1; state.range = rangeKey; }
		state.columns = columns( state.columns );
		body.innerHTML = '<section class="pc-page-conversion"><p class="pc-data-muted">' + esc( T.note ) + '</p><p class="pc-data-muted">' + esc( T.overlap ) + '</p>' + filterToolbar( esc ) +
			'<div class="pc-pc-toolbar"><button type="button" class="button pc-pc-back">' + esc( T.back ) + '</button><label>' + esc( T.search ) + ' <input type="search" class="pc-pc-search" maxlength="100"></label>' +
			'<label>' + esc( T.pageSize ) + ' <select class="pc-pc-size"><option value="20">20</option><option value="50">50</option><option value="100">100</option></select></label><button type="button" class="button pc-pc-columns" aria-expanded="false">' + esc( T.columns ) + '</button></div>' +
			'<div class="pc-pc-chooser" hidden></div><p class="pc-pc-search-note" hidden>' + esc( T.searchNote ) + '</p><div class="pc-pc-results" aria-live="polite"></div><div class="pc-pc-footer"></div></section>';
		var root = body.querySelector( '.pc-page-conversion' ), search = root.querySelector( '.pc-pc-search' ), size = root.querySelector( '.pc-pc-size' ), chooser = root.querySelector( '.pc-pc-chooser' ), edit = root.querySelector( '.pc-pc-columns' ), back = root.querySelector( '.pc-pc-back' ), results = root.querySelector( '.pc-pc-results' ), foot = root.querySelector( '.pc-pc-footer' ), searchNote = root.querySelector( '.pc-pc-search-note' );
		search.value = state.q; size.value = String( state.per_page );
		var domainState = root.querySelector( '.pc-report-domain-state' ), domainValue = root.querySelector( '.pc-report-domain' ), countryState = root.querySelector( '.pc-report-country-state' ), countryValue = root.querySelector( '.pc-report-country' ), filterApply = root.querySelector( '.pc-report-filter-apply' ), coverage = root.querySelector( '.pc-report-coverage' );
		function writeFilter( value ) { domainState.value = value.entry_domain_state; domainValue.value = value.entry_domain || ''; countryState.value = value.country_state; countryValue.value = value.country || ''; }
		function filterDraft() { var value = { entry_domain_state: domainState.value }; if ( value.entry_domain_state === 'value' ) { value.entry_domain = domainValue.value.toLowerCase().replace( /\.$/, '' ); } value.country_state = countryState.value; if ( value.country_state === 'value' ) { value.country = countryValue.value.toUpperCase(); } return value; }
		writeFilter( acceptedFilter );
		function attached() { return alive && document.documentElement.contains( root ); }
		function closeChooser() { chooser.hidden = true; chooser.innerHTML = ''; edit.setAttribute( 'aria-expanded', 'false' ); }
		function controls() { back.hidden = state.group !== 'page'; back.disabled = busy; search.disabled = busy; size.disabled = busy; edit.disabled = busy || loading || filterDirty || ! data; filterApply.disabled = busy || loading; domainState.disabled = domainValue.disabled = countryState.disabled = countryValue.disabled = busy; searchNote.hidden = state.group !== 'page' || state.type !== 'search'; }
		function invalidate() { ++generation; window.clearTimeout( searchTimer ); window.clearTimeout( restoreTimer ); data = null; closeChooser(); foot.innerHTML = ''; controls(); }
		function value( n, key ) { if ( n === null ) { return '-'; } return rate( key ) ? Number( n ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) + '%' : ctx.number( n ); }
		function delta( n, key ) { if ( n === null ) { return '-'; } if ( ! rate( key ) ) { return ctx.change( n ); } return ( n > 0 ? '+' : '' ) + Number( n === 0 ? 0 : n ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) + ' ' + T.points; }
		function metric( row, key ) { return '<td><div><span>' + esc( T.current ) + '</span> ' + esc( value( row.current[ key ], key ) ) + '</div><div class="pc-data-muted"><span>' + esc( T.previous ) + '</span> ' + esc( value( row.previous[ key ], key ) ) + '</div><div class="pc-data-muted"><span>' + esc( T.change ) + '</span> ' + esc( delta( row.change[ key ], key ) ) + '</div></td>'; }
		function draw() {
			if ( ! attached() || ! data || busy ) { return; }
			var selected = state.columns;
			if ( ! data.rows.length ) { results.innerHTML = '<p class="pc-data-empty">' + esc( T.empty ) + '</p>'; }
			else {
				var head = '<th scope="col">' + esc( T.identity ) + '</th>';
				selected.forEach( function ( key ) { head += '<th scope="col" aria-sort="' + ( state.sort === key ? ( state.order === 'asc' ? 'ascending' : 'descending' ) : 'none' ) + '"><button type="button" data-pc-sort="' + key + '">' + esc( T.metrics[ key ] ) + '</button></th>'; } );
				var html = data.rows.map( function ( row ) {
					var link = url( row.url ), title = row.can_drill ? '<button type="button" data-pc-drill="' + esc( row.kind ) + '" data-pc-key="' + esc( row.key ) + '">' + esc( row.label ) + ' · ' + esc( T.drill ) + '</button>' : ( link ? '<a href="' + esc( link ) + '" target="_blank" rel="noopener noreferrer">' + esc( row.label ) + '</a>' : esc( row.label ) );
					return '<tr><th scope="row">' + title + ( row.path && row.path !== row.label ? '<div class="pc-data-muted">' + esc( row.path ) + '</div>' : '' ) + '</th>' + selected.map( function ( key ) { return metric( row, key ); } ).join( '' ) + '</tr>';
				} ).join( '' );
				results.innerHTML = '<div class="pc-pc-scroll" tabindex="0" role="region" aria-label="' + esc( T.identity ) + '"><table class="widefat pc-pc-table"><thead><tr>' + head + '</tr></thead><tbody>' + html + '</tbody></table></div>';
			}
			results.setAttribute( 'aria-busy', 'false' );
			foot.innerHTML = '<span>' + esc( T.total.split( '%s' ).join( ctx.number( data.total ) ) ) + '</span><button type="button" class="button" data-pc-page="-1"' + ( state.page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button><span>' + esc( ctx.number( state.page ) + ' / ' + ctx.number( Math.max( 1, Math.ceil( data.total / state.per_page ) ) ) ) + '</span><button type="button" class="button" data-pc-page="1"' + ( state.page * state.per_page >= data.total ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>';
			controls();
			if ( backKey !== null && state.group === 'type' ) { var wanted = backKey; backKey = null; each( root.querySelectorAll( '[data-pc-key]' ), function ( button ) { if ( button.getAttribute( 'data-pc-key' ) === wanted && attached() ) { button.focus(); } } ); }
		}
		function error() { if ( ! attached() || busy ) { return; } data = null; loading = false; closeChooser(); results.setAttribute( 'aria-busy', 'false' ); results.innerHTML = '<p role="alert">' + esc( T.error ) + '</p><button type="button" class="button" data-pc-retry="1">' + esc( T.retry ) + '</button>'; foot.innerHTML = ''; controls(); }
		function load() {
			if ( ! attached() || busy ) { return; }
			if ( filterDirty && ! filterPending ) { invalidate(); loading = false; results.innerHTML = '<p class="pc-data-muted">' + esc( T.filterApply ) + '</p>'; controls(); return; }
			invalidate(); loading = true; controls(); var mine = generation, query = {};
			Object.keys( acceptedDates ).forEach( function ( key ) { query[ key ] = acceptedDates[ key ]; } );
			[ 'group', 'type', 'q', 'sort', 'order', 'page', 'per_page' ].forEach( function ( key ) { query[ key ] = state[ key ]; } );
			if ( reportFilter ) { Object.keys( reportFilter ).forEach( function ( key ) { query[ key ] = reportFilter[ key ]; } ); }
			results.setAttribute( 'aria-busy', 'true' ); results.innerHTML = '<p class="pc-data-muted">' + esc( T.loading ) + '</p>';
			ctx.api( '/peptide/v1/data/page-conversion', query ).then( function ( reply ) { if ( mine !== generation || ! attached() || busy ) { return; } if ( ! valid( reply, query ) ) { error(); return; } loading = false; data = reply; acceptedDates = dates( reply.range ); acceptedFilter = reply.filter || { entry_domain_state: 'all', country_state: 'all' }; writeFilter( acceptedFilter ); filterDirty = false; filterPending = false; var parts = []; Object.keys( reply.filter_coverage || {} ).forEach( function ( period ) { var c = reply.filter_coverage[ period ].episodes; if ( c ) { parts.push( ( period === 'current' ? T.filterCurrent : T.filterPrevious ) + ': ' + c.matched_count + '/' + c.base_count ); } } ); coverage.textContent = parts.length ? T.filterCoverage + ' ' + parts.join( '; ' ) : ''; draw(); }, function () { if ( mine === generation && attached() && ! busy ) { error(); } } );
		}
		function saveColumns() { try { window.localStorage.setItem( STORE, JSON.stringify( state.columns ) ); } catch ( ignore ) {} }
		function panel( focusKey ) {
			if ( ! data || busy || loading ) { return; }
			var ordered = state.columns.concat( KEYS.filter( function ( key ) { return state.columns.indexOf( key ) < 0; } ) );
			chooser.innerHTML = ordered.map( function ( key ) { var i = state.columns.indexOf( key ), label = T.metrics[ key ]; return '<div class="pc-pc-choice"><label><input type="checkbox" data-pc-column="' + key + '"' + ( i >= 0 ? ' checked' : '' ) + '> ' + esc( label ) + '</label>' + ( i >= 0 ? '<button type="button" data-pc-move="' + key + '" data-step="-1" aria-label="' + esc( T.moveUp.split( '%s' ).join( label ) ) + '"' + ( i === 0 ? ' disabled' : '' ) + '>↑</button><button type="button" data-pc-move="' + key + '" data-step="1" aria-label="' + esc( T.moveDown.split( '%s' ).join( label ) ) + '"' + ( i === state.columns.length - 1 ? ' disabled' : '' ) + '>↓</button>' : '' ) + '</div>'; } ).join( '' );
			chooser.hidden = false; edit.setAttribute( 'aria-expanded', 'true' );
			if ( focusKey ) { var input = chooser.querySelector( '[data-pc-column="' + focusKey + '"]' ); if ( input ) { input.focus(); } }
		}
		root.addEventListener( 'click', function ( event ) {
			if ( busy ) { return; }
			var button = find( event.target, 'data-pc-sort', root );
			if ( button ) { var key = button.getAttribute( 'data-pc-sort' ); if ( KEYS.indexOf( key ) < 0 ) { return; } state.order = state.sort === key && state.order === 'desc' ? 'asc' : 'desc'; state.sort = key; state.page = 1; load(); return; }
			button = find( event.target, 'data-pc-drill', root );
			if ( button ) { var kind = button.getAttribute( 'data-pc-drill' ); if ( KINDS.indexOf( kind ) < 0 ) { return; } backKey = button.getAttribute( 'data-pc-key' ); state.group = 'page'; state.type = kind; state.page = 1; load(); return; }
			button = find( event.target, 'data-pc-page', root ); if ( button && ! button.disabled ) { state.page = Math.max( 1, state.page + Number( button.getAttribute( 'data-pc-page' ) ) ); load(); return; }
			if ( find( event.target, 'data-pc-retry', root ) ) { load(); return; }
			button = find( event.target, 'data-pc-move', root ); if ( button && data && ! loading && ! button.disabled ) { var moveKey = button.getAttribute( 'data-pc-move' ), at = state.columns.indexOf( moveKey ), next = at + Number( button.getAttribute( 'data-step' ) ); if ( at < 0 || next < 0 || next >= state.columns.length ) { return; } var swap = state.columns[ next ]; state.columns[ next ] = moveKey; state.columns[ at ] = swap; saveColumns(); draw(); panel( moveKey ); }
		} );
		chooser.addEventListener( 'change', function ( event ) { var input = find( event.target, 'data-pc-column', chooser ); if ( ! input || ! data || busy || loading ) { return; } var key = input.getAttribute( 'data-pc-column' ), at = state.columns.indexOf( key ); if ( KEYS.indexOf( key ) < 0 ) { return; } if ( input.checked && at < 0 ) { state.columns.push( key ); } else if ( ! input.checked && at >= 0 ) { state.columns.splice( at, 1 ); } saveColumns(); draw(); panel( key ); } );
		edit.addEventListener( 'click', function () { if ( edit.disabled ) { return; } if ( chooser.hidden ) { panel(); } else { closeChooser(); } } );
		function filterEdited() { filterDirty = ! same( filterDraft(), acceptedFilter ); filterPending = false; invalidate(); loading = false; results.innerHTML = '<p class="pc-data-muted">' + esc( T.filterApply ) + '</p>'; coverage.textContent = T.filterApply; controls(); }
		[ domainState, countryState ].forEach( function ( input ) { input.addEventListener( 'change', filterEdited ); } );
		[ domainValue, countryValue ].forEach( function ( input ) { input.addEventListener( 'input', filterEdited ); } );
		filterApply.addEventListener( 'click', function () { if ( busy || loading ) { return; } reportFilter = filterDraft(); filterDirty = true; filterPending = true; state.page = 1; load(); } );
		back.addEventListener( 'click', function () { if ( busy ) { return; } state.group = 'type'; state.type = ''; state.page = 1; load(); } );
		size.addEventListener( 'change', function () { var n = Number( size.value ); if ( busy || [ 20, 50, 100 ].indexOf( n ) < 0 ) { return; } state.per_page = n; state.page = 1; load(); } );
		function searchChanged( immediate ) { if ( busy ) { return; } invalidate(); loading = false; controls(); results.innerHTML = '<p class="pc-data-muted">' + esc( T.loading ) + '</p>'; if ( composing ) { return; } state.q = search.value.trim(); state.page = 1; if ( immediate ) { load(); } else { searchTimer = window.setTimeout( load, 400 ); } }
		search.addEventListener( 'input', function () { searchChanged( false ); } );
		search.addEventListener( 'compositionstart', function () { composing = true; invalidate(); results.innerHTML = '<p class="pc-data-muted">' + esc( T.loading ) + '</p>'; } );
		search.addEventListener( 'compositionend', function () { composing = false; searchChanged( false ); } );
		search.addEventListener( 'keydown', function ( event ) { if ( ! composing && ( event.key === 'Enter' || event.keyCode === 13 ) ) { event.preventDefault(); searchChanged( true ); } } );
		dispose = function () { if ( ! alive ) { return; } alive = false; ++generation; window.clearTimeout( searchTimer ); window.clearTimeout( restoreTimer ); if ( unsubscribe ) { unsubscribe(); } data = null; closeChooser(); };
		if ( typeof PcData.onRangeState === 'function' ) {
			unsubscribe = PcData.onRangeState( function ( on ) { var was = busy; busy = !! on; window.clearTimeout( restoreTimer ); if ( busy ) { invalidate(); loading = false; results.innerHTML = '<p class="pc-data-muted">' + esc( T.loading ) + '</p>'; controls(); } else if ( was && attached() ) { controls(); var mine = generation; restoreTimer = window.setTimeout( function () { if ( mine === generation && attached() && ! busy ) { load(); } }, 0 ); } } );
		}
		controls(); if ( ! busy ) { load(); }
	}
	PcData.register( 'page_conversion', render );
} )( window.PcData, window.pcDataPageConversion );
