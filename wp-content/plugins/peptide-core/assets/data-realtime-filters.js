/* Native, typed entry facets. The Realtime parent alone owns main selection and refresh. */
( function ( w ) {
	'use strict';
	var FACETS = [ 'entry_domain', 'utm_source' ], KEYS = [ 'entry_domain_state', 'entry_domain', 'utm_source_state', 'utm_source' ];
	var WINDOWS = [ 'viewed', 'carted', 'sold', 'map', 'active_countries', 'popular_countries', 'sources', 'collections', 'pages' ];
	function all() { return { entry_domain_state: 'all', utm_source_state: 'all' }; }
	function normalize( input ) {
		if ( ! input || typeof input !== 'object' || Array.isArray( input ) || Object.keys( input ).some( function ( key ) { return KEYS.indexOf( key ) < 0; } ) ) { return null; }
		var out = {}, valid = true;
		FACETS.forEach( function ( key ) {
			var stateKey = key + '_state', state = Object.prototype.hasOwnProperty.call( input, stateKey ) ? input[ stateKey ] : 'all', value = input[ key ];
			var allowed = key === 'entry_domain' ? [ 'all', 'unobserved', 'value' ] : [ 'all', 'unobserved', 'missing', 'empty', 'value' ];
			if ( typeof state !== 'string' || allowed.indexOf( state ) < 0 ) { valid = false; return; } out[ stateKey ] = state;
			if ( state !== 'value' ) { if ( Object.prototype.hasOwnProperty.call( input, key ) ) { valid = false; } return; }
			if ( typeof value !== 'string' || ! value ) { valid = false; return; }
			if ( key === 'entry_domain' ) {
				if ( value.length > 253 ) { valid = false; return; } value = value.toLowerCase().replace( /\.$/, '' );
				if ( /[^a-z0-9.-]/.test( value ) || ! value.split( '.' ).every( function ( label ) { return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test( label ); } ) ) { valid = false; return; }
			} else {
				try { encodeURIComponent( value ); } catch ( error ) { valid = false; return; }
				if ( /[\x00-\x1f\x7f]/.test( value ) || value.replace( /[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_' ).length > 120 ) { valid = false; return; }
			} out[ key ] = value;
		} ); return valid ? out : null;
	}
	function same( a, b ) { var x = normalize( a ), y = normalize( b ); return !! x && !! y && Object.keys( a ).length === Object.keys( x ).length && Object.keys( b ).length === Object.keys( y ).length && KEYS.every( function ( key ) { return a[ key ] === x[ key ] && b[ key ] === y[ key ] && x[ key ] === y[ key ]; } ); }
	function active( value ) { return value.entry_domain_state !== 'all' || value.utm_source_state !== 'all'; }
	function parseUrl( search ) {
		var query = new w.URLSearchParams( search ), input = {}, map = { rt_entry_state: 'entry_domain_state', rt_entry: 'entry_domain', rt_utm_state: 'utm_source_state', rt_utm: 'utm_source' };
		Object.keys( map ).forEach( function ( key ) { if ( query.has( key ) ) { if ( query.getAll( key ).length !== 1 ) { input.invalid = true; } input[ map[ key ] ] = query.get( key ); } } ); return normalize( input );
	}
	function mount( host, ctx, strings, options ) {
		var T = strings || {}, esc = ctx.esc, disposed = false, generation = 0, pending = false, busy = false, failedMain = false, dependencyUnavailable = false, context = null, desired = all(), elements = {}, bindings = [], debounce = 0, focusReturn = null, focusSelection = null;
		var pages = { entry_domain: 1, utm_source: 1 }, searches = { entry_domain: '', utm_source: '' }, lastPeriod = '';
		function text( key ) { return T[ key ] || key; }
		function attached() { return ! disposed && document.documentElement.contains( host ); }
		function on( el, type, fn ) { el.addEventListener( type, fn ); bindings.push( [ el, type, fn ] ); }
		function selected( key, filter ) { var item = { state: filter[ key + '_state' ] }; if ( item.state === 'value' ) { item.value = filter[ key ]; } return item; }
		function label( choice ) { return ( choice.state === 'value' ? choice.value : text( choice.state ) ) + ( choice.observed === false ? ' (' + text( 'notObservedOption' ) + ')' : '' ); }
		function paintChoices( key, choices ) { var el = elements[ key ]; el.select.innerHTML = choices.map( function ( choice ) { var item = { state: choice.state }; if ( item.state === 'value' ) { item.value = choice.value; } return '<option value="' + esc( JSON.stringify( item ) ) + '"' + ( choice.disabled ? ' disabled' : '' ) + '>' + esc( label( choice ) ) + '</option>'; } ).join( '' ); el.select.value = JSON.stringify( selected( key, desired ) ); }
		function initialChoices() { FACETS.forEach( function ( key ) { var choices = ( key === 'entry_domain' ? [ 'all', 'unobserved' ] : [ 'all', 'unobserved', 'missing', 'empty' ] ).map( function ( state ) { return { state: state }; } ); if ( desired[ key + '_state' ] === 'value' ) { choices.push( { state: 'value', value: desired[ key ], observed: false } ); } paintChoices( key, choices ); } ); }
		host.className = 'pc-rt-filters'; host.innerHTML = '<div class="pc-rt-filter-grid">' + FACETS.map( function ( key ) {
			return '<section data-facet="' + key + '"><label>' + esc( text( key ) ) + '<select class="pc-rt-filter-select"></select></label><details class="pc-rt-filter-details"><summary>' + esc( text( 'search' ) ) + '</summary><div class="pc-rt-filter-extra"><label>' + esc( text( 'search' ) ) + '<input class="pc-rt-filter-search" type="search" maxlength="240"></label><div class="pc-rt-filter-pages"><button type="button" class="button pc-rt-filter-previous">' + esc( text( 'previous' ) ) + '</button><span class="pc-rt-filter-page"></span><button type="button" class="button pc-rt-filter-more">' + esc( text( 'more' ) ) + '</button></div><p class="pc-rt-filter-coverage"></p><p class="pc-rt-filter-help">' + esc( text( 'help' ) ) + '</p></div></details><p class="pc-rt-filter-missing"></p></section>';
		} ).join( '' ) + '<div class="pc-rt-filter-actions"><button type="button" class="button pc-rt-filter-reset">' + esc( text( 'reset' ) ) + '</button><button type="button" class="button pc-rt-filter-retry">' + esc( text( 'retry' ) ) + '</button></div></div><p class="pc-rt-filter-status" role="status" aria-live="polite"></p><p class="pc-rt-filter-accepted"></p>';
		var status = host.querySelector( '.pc-rt-filter-status' ), accepted = host.querySelector( '.pc-rt-filter-accepted' ), reset = host.querySelector( '.pc-rt-filter-reset' ), retry = host.querySelector( '.pc-rt-filter-retry' );
		FACETS.forEach( function ( key ) {
			var root = host.querySelector( '[data-facet="' + key + '"]' ); elements[ key ] = { select: root.querySelector( 'select' ), search: root.querySelector( 'input' ), more: root.querySelector( '.pc-rt-filter-more' ), previous: root.querySelector( '.pc-rt-filter-previous' ), page: root.querySelector( '.pc-rt-filter-page' ), coverage: root.querySelector( '.pc-rt-filter-coverage' ), missing: root.querySelector( '.pc-rt-filter-missing' ), hasMore: false, composing: false };
			var el = elements[ key ];
			on( el.select, 'change', function () { if ( busy || dependencyUnavailable || ! attached() ) { return; } var choice; try { choice = JSON.parse( el.select.value ); } catch ( error ) { return; } var input = {}; KEYS.forEach( function ( k ) { if ( Object.prototype.hasOwnProperty.call( desired, k ) ) { input[ k ] = desired[ k ]; } } ); input[ key + '_state' ] = choice.state; delete input[ key ]; if ( choice.state === 'value' ) { input[ key ] = choice.value; } var next = normalize( input ); if ( next && ! same( next, desired ) ) { desired = next; pages.entry_domain = pages.utm_source = 1; invalidate(); options.onChange( next ); } } );
			function search() { if ( el.composing || busy || ! context ) { return; } searches[ key ] = el.search.value; pages[ key ] = 1; invalidate(); debounce = w.setTimeout( function () { debounce = 0; request(); }, 400 ); }
			on( el.search, 'input', search ); on( el.search, 'compositionstart', function () { el.composing = true; invalidate(); } ); on( el.search, 'compositionend', function () { el.composing = false; search(); } );
			on( el.search, 'keydown', function ( event ) { if ( event.key === 'Enter' && ! el.composing && ! busy ) { event.preventDefault(); searches[ key ] = el.search.value; pages[ key ] = 1; invalidate(); request(); } } );
			on( el.more, 'click', function () { if ( ! busy && ! pending && el.hasMore ) { ++pages[ key ]; request(); } } ); on( el.previous, 'click', function () { if ( ! busy && ! pending && pages[ key ] > 1 ) { --pages[ key ]; request(); } } );
		} );
		function remember() { var el = document.activeElement; FACETS.some( function ( key ) { if ( ( el === elements[ key ].select || el === elements[ key ].search ) && ! el.disabled ) { focusReturn = el; focusSelection = typeof el.selectionStart === 'number' ? { start: el.selectionStart, end: el.selectionEnd, direction: el.selectionDirection } : null; return true; } return false; } ); }
		function moved( event ) { if ( focusReturn && event.target !== focusReturn && event.target !== document.body && event.target !== document.documentElement ) { focusReturn = null; focusSelection = null; } }
		document.addEventListener( 'focusin', moved );
		function restore() { var el = focusReturn, selection = focusSelection; focusReturn = null; focusSelection = null; if ( el && attached() && ! el.disabled && ( ! document.activeElement || document.activeElement === document.body ) ) { el.focus(); if ( selection && el.setSelectionRange ) { try { el.setSelectionRange( selection.start, selection.end, selection.direction ); } catch ( error ) { /* Some native search inputs do not expose ranges. */ } } } }
		function controls() { FACETS.forEach( function ( key ) { var el = elements[ key ]; el.select.disabled = busy || dependencyUnavailable; el.search.disabled = busy || dependencyUnavailable || ! context || failedMain; el.previous.disabled = busy || dependencyUnavailable || pending || failedMain || ! context || pages[ key ] <= 1; el.more.disabled = busy || dependencyUnavailable || pending || failedMain || ! context || ! el.hasMore; } ); reset.disabled = busy; retry.disabled = busy || pending || ! context && ! failedMain; host.setAttribute( 'aria-busy', busy || pending ? 'true' : 'false' ); }
		function invalidate() { ++generation; pending = false; if ( debounce ) { w.clearTimeout( debounce ); debounce = 0; } controls(); }
		function params() { var p = { mode: context.mode, funnel: options.getMainWindows().funnel, minutes: options.getMainWindows().minutes, entry_page: pages.entry_domain, utm_page: pages.utm_source, entry_search: searches.entry_domain, utm_search: searches.utm_source, per_page: 100 }; Object.keys( context.filter ).forEach( function ( key ) { p[ key ] = context.filter[ key ]; } ); if ( p.mode === 'history' ) { p.from = context.from; p.to = context.to; } else { p.anchor_ms = context.anchor; var windows = options.getWindows(); WINDOWS.forEach( function ( key ) { p[ key ] = windows[ key ]; } ); } return p; }
		function current( mine, signature ) { return attached() && ! busy && ! failedMain && context && context.signature === signature && generation === mine; }
		function validResponse( data, p ) {
			if ( ! data || ! same( data.filter, context.filter ) || ! data.context || ! data.facets ) { return false; } var c = data.context;
			if ( c.mode !== p.mode || c.funnel !== Number( p.funnel ) || c.minutes !== Number( p.minutes ) || context.timezone && c.timezone !== context.timezone ) { return false; }
			if ( p.mode === 'live' ? c.anchor_ms !== p.anchor_ms || ! c.windows || ! WINDOWS.every( function ( key ) { return c.windows[ key ] === p[ key ]; } ) : ! c.range || ! c.range.dates || c.range.dates.from !== context.from || c.range.dates.to !== context.to || c.range.from !== context.epochFrom || c.range.to !== context.epochTo || c.range.timezone !== context.timezone ) { return false; }
			function count( value ) { return typeof value === 'number' && isFinite( value ) && value >= 0 && Math.floor( value ) === value; }
			return FACETS.every( function ( key ) {
				var facet = data.facets[ key ], prefix = key === 'entry_domain' ? 'entry' : 'utm', want = selected( key, context.filter );
				if ( ! facet || ! facet.selected || Object.keys( facet.selected ).length !== Object.keys( want ).length || facet.selected.state !== want.state || facet.selected.value !== want.value || facet.page !== p[ prefix + '_page' ] || ! count( facet.page ) || facet.page < 1 || facet.search !== p[ prefix + '_search' ] || facet.per_page !== p.per_page || ! count( facet.total_values ) || typeof facet.has_more !== 'boolean' || facet.has_more !== ( facet.page * facet.per_page < facet.total_values ) || ! Array.isArray( facet.choices ) || ! facet.coverage || facet.coverage.base_unit !== 'distinct_visits' ) { return false; }
				var seen = {}, hasSelected = false;
				if ( ! facet.choices.every( function ( choice ) {
					var filter = all(), token = JSON.stringify( [ choice.state, choice.value ] ); filter[ key + '_state' ] = choice.state; if ( choice.state === 'value' ) { filter[ key ] = choice.value; }
					if ( seen[ token ] || ! same( filter, normalize( filter ) ) || typeof choice.observed !== 'boolean' || typeof choice.disabled !== 'boolean' || ( choice.state === 'all' ? choice.count !== null : ! count( choice.count ) ) ) { return false; }
					seen[ token ] = true; if ( choice.state === want.state && choice.value === want.value ) { hasSelected = true; } return true;
				} ) || ! hasSelected || ! seen[ JSON.stringify( [ 'all', undefined ] ) ] || ! seen[ JSON.stringify( [ 'unobserved', undefined ] ) ] ) { return false; }
				return [ 'total_visits', 'captured', 'v0', 'late_capture', 'unlinked_events', 'unlinked_orders' ].every( function ( field ) { return count( facet.coverage[ field ] ); } ) && facet.coverage.captured + facet.coverage.v0 + facet.coverage.late_capture === facet.coverage.total_visits;
			} );
		}
		function unavailable( error ) { return !! error && ( error.code === 'pc_realtime_filter_unavailable' || error.data && Number( error.data.status ) === 503 ); }
		function failure( mine, signature, error ) { if ( ! current( mine, signature ) ) { return; } pending = false; if ( unavailable( error ) ) { dependencyUnavailable = true; } controls(); status.textContent = text( dependencyUnavailable ? 'unavailable' : 'optionsError' ); restore(); }
		function request() {
			if ( ! attached() || busy || failedMain || ! context ) { return; } var mine = ++generation, signature = context.signature, p = params(); pending = true; controls(); status.textContent = text( 'loading' );
			ctx.api( '/peptide/v1/data/realtime/filter-options', p ).then( function ( data ) { if ( ! current( mine, signature ) ) { return; } try { if ( ! validResponse( data, p ) ) { throw new Error( 'Invalid entry options context' ); } FACETS.forEach( function ( key ) { var facet = data.facets[ key ], el = elements[ key ]; paintChoices( key, facet.choices ); el.hasMore = facet.has_more; el.page.textContent = String( facet.page ) + ' · ' + String( facet.total_values ); el.coverage.textContent = text( 'coverage' ) + ': ' + facet.coverage.total_visits + '; ' + [ 'captured', 'v0', 'late_capture', 'unlinked_events', 'unlinked_orders' ].map( function ( field ) { return text( field ) + ': ' + facet.coverage[ field ]; } ).join( '; ' ); el.missing.textContent = facet.choices.some( function ( item ) { return item.state === 'value' && item.value === desired[ key ] && ! item.observed; } ) ? text( 'notObserved' ) : ''; } ); pending = false; dependencyUnavailable = false; controls(); status.textContent = ''; restore(); } catch ( error ) { failure( mine, signature ); } }, function ( error ) { failure( mine, signature, error ); } );
		}
		on( reset, 'click', function () { if ( busy ) { return; } desired = all(); failedMain = false; pages.entry_domain = pages.utm_source = 1; initialChoices(); invalidate(); options.onChange( desired, true ); } );
		on( retry, 'click', function () { if ( busy || pending ) { return; } if ( failedMain ) { options.onRetry(); } else { request(); } } );
		initialChoices(); controls();
		return {
			setDesired: function ( value ) { desired = normalize( value ) || all(); initialChoices(); },
			beginMain: function () { if ( ! attached() ) { return; } remember(); busy = true; failedMain = false; invalidate(); status.textContent = text( 'loading' ) + ( context ? ' ' + text( 'stale' ) : '' ); },
			acceptMain: function ( selection, data ) { if ( ! attached() ) { return; } var filter = normalize( selection.filter || all() ); if ( ! filter || ( active( filter ) ? ! same( data.filter, filter ) : data.filter !== undefined ) ) { this.failMain(); return; } var next = data.mode === 'history' ? { mode: 'history', from: data.range.dates.from, to: data.range.dates.to, epochFrom: data.range.from, epochTo: data.range.to, timezone: data.range.timezone, filter: filter } : { mode: 'live', anchor: data.now_ms, timezone: typeof data.timezone === 'string' ? data.timezone : null, filter: filter }; var period = JSON.stringify( [ next.mode, next.from, next.to, next.filter ] ); if ( period !== lastPeriod ) { pages.entry_domain = pages.utm_source = 1; } lastPeriod = period; next.signature = JSON.stringify( next ); context = next; desired = filter; busy = false; failedMain = false; invalidate(); accepted.textContent = FACETS.map( function ( key ) { return text( key ) + ': ' + label( selected( key, filter ) ); } ).join( '; ' ); request(); },
			failMain: function ( invalidUrl, error ) { if ( ! attached() ) { return; } busy = false; failedMain = true; if ( unavailable( error ) ) { dependencyUnavailable = true; } invalidate(); status.textContent = text( invalidUrl ? 'urlError' : ( dependencyUnavailable ? 'unavailable' : 'error' ) ) + ( context ? ' ' + text( 'stale' ) : '' ); restore(); },
			windowsChanged: function () { if ( attached() && ! busy && context ) { invalidate(); request(); } },
			dispose: function () { if ( disposed ) { return; } disposed = true; invalidate(); bindings.forEach( function ( binding ) { binding[0].removeEventListener( binding[1], binding[2] ); } ); bindings = []; document.removeEventListener( 'focusin', moved ); focusReturn = null; focusSelection = null; context = null; }
		};
	}
	w.PcDataRealtimeFilters = { all: all, normalize: normalize, same: same, active: active, parseUrl: parseUrl, mount: mount };
}( window ) );
