/* Independent lower realtime panels. The parent owns the only refresh timer. */
( function ( w ) {
	'use strict';
	var KEYS = [ 'viewed', 'carted', 'sold', 'map', 'active_countries', 'popular_countries', 'sources', 'collections', 'pages' ];
	var sequence = 0, PATH = '/peptide/v1/data/realtime/dimensions';
	function mount( host, ctx, strings, mapData ) {
		var T = strings || {}, esc = ctx.esc, number = ctx.number, id = ++sequence;
		var disposed = false, generation = 0, mainBusy = false, rangeBusy = false, pending = false, context = null, accepted = null, coalesce = 0, recovery = 0, unsubscribe = null, panels = {}, windows = {}, stale = false, focusReturn = null, bindings = [], windowsListener = null;
		function on( element, type, handler ) { element.addEventListener( type, handler ); bindings.push( { element: element, type: type, handler: handler } ); }
		function text( key ) { return T[ key ] || key; }
		function hasShare( key ) { return key === 'popular_countries' || key === 'collections'; }
		function labelKey( key ) { return [ 'viewed', 'carted', 'sold' ].indexOf( key ) >= 0 ? 'productLabel' : ( [ 'map', 'active_countries', 'popular_countries' ].indexOf( key ) >= 0 ? 'countryLabel' : ( key === 'sources' ? 'sourceLabel' : ( key === 'collections' ? 'collectionLabel' : 'pageLabel' ) ) ); }
		function placeholder( key, message ) { return '<tr><td colspan="' + ( hasShare( key ) ? 3 : 2 ) + '">' + esc( message ) + '</td></tr>'; }
		function attached() { return ! disposed && document.documentElement.contains( host ); }
		function current( mine, signature ) { return attached() && mine === generation && ! mainBusy && ! rangeBusy && context && context.signature === signature; }
		function fill( value, a, b ) { return value.replace( '%1$d', a ).replace( '%2$d', b ).replace( '%d', a ); }
		function stopTimers() { if ( coalesce ) { w.clearTimeout( coalesce ); coalesce = 0; } if ( recovery ) { w.clearTimeout( recovery ); recovery = 0; } }
		function localUnit( key ) { return [ 'viewed', 'carted' ].indexOf( key ) >= 0 ? 'events' : ( key === 'sold' ? 'units' : ( [ 'pages', 'popular_countries', 'collections' ].indexOf( key ) >= 0 ? 'page_views' : 'visitors' ) ); }
		function periodLabel( data ) { return data.mode === 'history' ? text( 'history' ) + ': ' + data.range.dates.from + '–' + data.range.dates.to + ' · ' + data.range.timezone : new Date( data.anchor_ms ).toISOString(); }
		function controls() { KEYS.forEach( function ( key ) { var history = context && context.mode === 'history'; panels[ key ].select.hidden = !! history; panels[ key ].select.disabled = mainBusy || rangeBusy || ! context || history; panels[ key ].retry.disabled = mainBusy || rangeBusy || pending || ! context; if ( ! accepted ) { panels[ key ].unit.textContent = text( localUnit( key ) ) + ' · ' + ( history ? text( 'history' ) + ': ' + context.from + '–' + context.to : fill( text( 'window' ), windows[ key ] ) ); } } ); }
		function status( message ) { KEYS.forEach( function ( key ) { panels[ key ].status.textContent = message; panels[ key ].root.setAttribute( 'aria-busy', pending || mainBusy || rangeBusy ? 'true' : 'false' ); } ); }
		function invalidate() { ++generation; pending = false; stopTimers(); controls(); }
		function rememberFocus() { var active = document.activeElement; KEYS.some( function ( key ) { if ( active === panels[ key ].select && ! active.disabled ) { focusReturn = active; return true; } return false; } ); }
		function restoreFocus() { var control = focusReturn; focusReturn = null; if ( control && attached() && ! control.disabled && document.documentElement.contains( control ) && ( ! document.activeElement || document.activeElement === document.body ) ) { control.focus(); } }
		function movedFocus( event ) { if ( focusReturn && event.target !== focusReturn && event.target !== document.body && event.target !== document.documentElement ) { focusReturn = null; } }
		document.addEventListener( 'focusin', movedFocus );
		function storage( key ) { try { var value = w.localStorage.getItem( 'pcDataRealtimeDimension_' + key ); return [ '10', '20', '30' ].indexOf( value ) >= 0 ? Number( value ) : 10; } catch ( e ) { return 10; } }
		KEYS.forEach( function ( key ) { windows[ key ] = storage( key ); } );
		host.className = 'pc-rt-dimensions';
		host.innerHTML = '<p class="pc-rt-dim-help">' + esc( text( 'rangeHelp' ) ) + '</p><div class="pc-rt-dim-grid">' + KEYS.map( function ( key ) {
			return '<section class="pc-rt-dim-panel pc-rt-dim-' + key + '" data-panel="' + key + '" aria-labelledby="pc-rt-dim-title-' + id + '-' + key + '"><div class="pc-rt-dim-head"><h2 id="pc-rt-dim-title-' + id + '-' + key + '">' + esc( text( key ) ) + '</h2><select aria-label="' + esc( text( key ) ) + '">' + [ 10, 20, 30 ].map( function ( n ) { return '<option value="' + n + '"' + ( windows[ key ] === n ? ' selected' : '' ) + '>' + esc( fill( text( 'window' ), n ) ) + '</option>'; } ).join( '' ) + '</select></div><p class="pc-rt-dim-unit"></p><p class="pc-rt-dim-status" aria-live="polite"></p><button type="button" class="button pc-rt-dim-retry">' + esc( text( 'retry' ) ) + '</button><div class="pc-rt-dim-map-box"></div><p class="pc-rt-dim-total"></p><p class="pc-rt-dim-coverage"></p><div class="pc-rt-dim-scroll" tabindex="0" role="region" aria-label="' + esc( text( key ) ) + '"><table><thead><tr><th scope="col">' + esc( text( labelKey( key ) ) ) + '</th><th scope="col">' + esc( text( 'count' ) ) + '</th>' + ( hasShare( key ) ? '<th scope="col">' + esc( text( 'share' ) ) + '</th>' : '' ) + '</tr></thead><tbody></tbody></table></div><p class="pc-rt-dim-local-help">' + esc( text( key === 'sources' ? 'sourceHelp' : ( key === 'collections' ? 'collectionHelp' : ( [ 'map', 'active_countries', 'popular_countries' ].indexOf( key ) >= 0 ? 'geoHelp' : 'coverageNA' ) ) ) ) + '</p></section>';
		} ).join( '' ) + '</div>';
		KEYS.forEach( function ( key ) {
			var root = host.querySelector( '[data-panel="' + key + '"]' );
			panels[ key ] = { root: root, select: root.querySelector( 'select' ), retry: root.querySelector( '.pc-rt-dim-retry' ), status: root.querySelector( '.pc-rt-dim-status' ), total: root.querySelector( '.pc-rt-dim-total' ), coverage: root.querySelector( '.pc-rt-dim-coverage' ), unit: root.querySelector( '.pc-rt-dim-unit' ), body: root.querySelector( 'tbody' ) };
			panels[ key ].total.textContent = text( 'unavailable' );
			panels[ key ].coverage.textContent = [ 'viewed', 'carted', 'sold', 'pages' ].indexOf( key ) >= 0 ? text( 'coverageNA' ) : text( 'coverage' ) + ': ' + text( 'unavailable' );
			panels[ key ].body.innerHTML = placeholder( key, text( 'unavailable' ) );
			on( panels[ key ].select, 'change', function () {
				var n = Number( panels[ key ].select.value ); if ( [ 10, 20, 30 ].indexOf( n ) < 0 || mainBusy || rangeBusy || ! context || context.mode === 'history' ) { return; }
				windows[ key ] = n; try { w.localStorage.setItem( 'pcDataRealtimeDimension_' + key, String( n ) ); } catch ( e ) { /* Storage is optional. */ }
				invalidate(); coalesce = w.setTimeout( function () { coalesce = 0; request(); if ( windowsListener ) { windowsListener(); } }, 0 );
			} );
			on( panels[ key ].retry, 'click', function () { if ( ! pending && ! mainBusy && ! rangeBusy && context ) { request(); } } );
		} );
		var mapBox = panels.map.root.querySelector( '.pc-rt-dim-map-box' ), map = null, zoom = 1, panX = 0, panY = 0;
		function mapView() { if ( ! map ) { return; } var width = 720 / zoom, height = 360 / zoom; panX = Math.max( 0, Math.min( 720 - width, panX ) ); panY = Math.max( 0, Math.min( 360 - height, panY ) ); map.setAttribute( 'viewBox', panX + ' ' + panY + ' ' + width + ' ' + height ); }
		if ( mapData && mapData.width === 720 && mapData.height === 360 && mapData.paths ) {
			var paths = Object.keys( mapData.paths ).sort().map( function ( code ) { var path = mapData.paths[ code ]; return /^[A-Z]{2}$/.test( code ) && code.length === 2 && typeof path === 'string' && /^[MLZ0-9.,-]+$/.test( path ) ? '<path data-code="' + code + '" d="' + path + '" fill-rule="evenodd" fill="#e6e6e6" stroke="#fff" stroke-width="0.5"></path>' : ''; } ).join( '' );
			mapBox.innerHTML = '<div class="pc-rt-dim-map-controls"><button type="button" class="button" data-zoom="in" aria-label="' + esc( text( 'zoomIn' ) ) + '">+</button><button type="button" class="button" data-zoom="out" aria-label="' + esc( text( 'zoomOut' ) ) + '">−</button><button type="button" class="button" data-zoom="reset">' + esc( text( 'resetMap' ) ) + '</button></div><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 360" tabindex="0" role="img" aria-label="' + esc( text( 'map' ) + '. ' + text( 'mapHelp' ) ) + '">' + paths + '</svg><p>' + esc( text( 'mapHelp' ) ) + '</p><p class="pc-rt-dim-legend" aria-live="polite"></p>';
			map = mapBox.querySelector( 'svg' );
			mapBox.querySelector( '.pc-rt-dim-legend' ).textContent = text( 'unavailable' );
			on( mapBox, 'click', function ( event ) { var action = event.target.getAttribute( 'data-zoom' ); if ( ! action ) { return; } zoom = action === 'in' ? Math.min( 4, zoom * 1.5 ) : ( action === 'out' ? Math.max( 1, zoom / 1.5 ) : 1 ); if ( action === 'reset' ) { panX = 0; panY = 0; } mapView(); } );
			on( map, 'keydown', function ( event ) { var amount = 30 / zoom; if ( [ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown' ].indexOf( event.key ) < 0 ) { return; } event.preventDefault(); panX += event.key === 'ArrowLeft' ? -amount : ( event.key === 'ArrowRight' ? amount : 0 ); panY += event.key === 'ArrowUp' ? -amount : ( event.key === 'ArrowDown' ? amount : 0 ); mapView(); } );
		}
		function paintMap( panel ) {
			if ( ! map ) { return; } var counts = {}, maximum = 0;
			( panel.rows || [] ).forEach( function ( row ) { counts[ row.code ] = row.count; maximum = Math.max( maximum, row.count ); } );
			Array.prototype.forEach.call( map.querySelectorAll( 'path' ), function ( path ) { var count = counts[ path.getAttribute( 'data-code' ) ] || 0; path.setAttribute( 'fill', count ? ( count > maximum / 2 ? '#23688b' : '#8fbcd0' ) : '#e6e6e6' ); } );
			var legend = text( panel.reason || panel.state );
			if ( maximum > 0 ) { var low = Math.floor( maximum / 2 ), high = low + 1; legend = text( 'visitors' ) + ': 0; ' + ( low ? '1' + ( low > 1 ? '–' + number( low ) : '' ) + '; ' : '' ) + number( high ) + ( high < maximum ? '–' + number( maximum ) : '' ); }
			else if ( panel.coverage && panel.coverage.unknown > 0 && panel.coverage.known === 0 ) { legend = text( 'unknown' ) + ' (' + text( panel.coverage.base_unit ) + '): ' + number( panel.coverage.unknown ); }
			mapBox.querySelector( '.pc-rt-dim-legend' ).textContent = legend;
		}
		function params() { var p = { mode: context.mode }; if ( p.mode === 'live' ) { p.anchor_ms = context.anchor; KEYS.forEach( function ( key ) { p[ key ] = windows[ key ]; } ); } else { p.from = context.from; p.to = context.to; } if ( context.filter ) { Object.keys( context.filter ).forEach( function ( key ) { p[ key ] = context.filter[ key ]; } ); } return p; }
		function validResponse( data, p ) {
			if ( context.filter ? ! w.PcDataRealtimeFilters || ! w.PcDataRealtimeFilters.same( data && data.filter, context.filter ) : data && data.filter !== undefined ) { return false; }
			if ( ! data || data.mode !== p.mode || ! data.panels || ( context.timezone && data.timezone !== context.timezone ) || ( p.mode === 'live' ? data.anchor_ms !== p.anchor_ms : ( ! data.range || ! data.range.dates || data.range.dates.from !== p.from || data.range.dates.to !== p.to || data.range.from !== context.epochFrom || data.range.to !== context.epochTo || data.range.timezone !== context.timezone ) ) ) { return false; }
			function count( value ) { return typeof value === 'number' && isFinite( value ) && value >= 0 && Math.floor( value ) === value; }
			return KEYS.every( function ( key ) {
				var row = data.panels[ key ], legacy = [ 'viewed', 'carted', 'sold', 'pages' ].indexOf( key ) >= 0;
				var unit = [ 'viewed', 'carted' ].indexOf( key ) >= 0 ? 'events' : ( key === 'sold' ? 'units' : ( [ 'pages', 'popular_countries', 'collections' ].indexOf( key ) >= 0 ? 'page_views' : 'visitors' ) );
				var from = p.mode === 'live' ? p.anchor_ms - p[ key ] * 60000 + 1 : context.epochFrom * 1000, to = p.mode === 'live' ? p.anchor_ms + 1 : context.epochTo * 1000;
				if ( ! row || row.unit !== unit || [ 'available', 'partial', 'empty', 'unavailable' ].indexOf( row.state ) < 0 || row.from_ms !== from || row.to_ms !== to || row.window !== ( p.mode === 'live' ? p[ key ] : null ) ) { return false; }
				if ( row.rows === null || row.total_rows === null ) { return row.state === 'unavailable' && row.rows === null && row.total_rows === null && ( legacy ? row.coverage === null : row.coverage && row.coverage.base_count === null ); }
				if ( ! Array.isArray( row.rows ) || ! count( row.total_rows ) || row.total_rows < row.rows.length || ! row.rows.every( function ( item ) { return item && count( item.count ) && ( item.share === undefined || item.share === null || typeof item.share === 'number' && isFinite( item.share ) ); } ) ) { return false; }
				if ( legacy ) { return row.coverage === null; }
				var c = row.coverage; return c && [ 'visits', 'page_views' ].indexOf( c.base_unit ) >= 0 && [ 'base_count', 'known', 'unknown', 'unobserved', 'unavailable' ].every( function ( field ) { return count( c[ field ] ); } ) && c.known + c.unknown + c.unobserved + c.unavailable === c.base_count;
			} );
		}
		function paint( data ) {
			KEYS.forEach( function ( key ) {
				var p = data.panels[ key ], el = panels[ key ], coverage = p.coverage, rows = p.rows || [];
				el.select.hidden = data.mode === 'history'; el.unit.textContent = text( p.unit ) + ' · ' + ( data.mode === 'history' ? periodLabel( data ) : fill( text( 'window' ), p.window ) + ' · ' + periodLabel( data ) );
				el.status.textContent = ( stale ? text( 'stale' ) + ' ' : '' ) + ( p.reason ? text( p.reason ) : text( p.state ) );
				el.total.textContent = p.total_rows === null ? text( 'unavailable' ) : ( key === 'map' ? text( 'allCountries' ) + ': ' + number( p.total_rows ) : fill( text( 'top' ), rows.length, p.total_rows ) );
				el.coverage.textContent = coverage === null ? text( 'coverageNA' ) : ( coverage.base_count === null ? text( 'coverage' ) + ': ' + text( 'unavailable' ) : text( 'coverage' ) + ' — ' + text( 'base' ) + ' (' + text( coverage.base_unit ) + '): ' + number( coverage.base_count ) + ( coverage.base_visitors === undefined ? '' : '; ' + text( 'visitors' ) + ' (UV): ' + number( coverage.base_visitors ) ) + '; ' + [ 'known', 'unknown', 'unobserved', 'unavailable' ].map( function ( statusKey ) { return text( statusKey ) + ': ' + number( coverage[ statusKey ] ); } ).join( '; ' ) );
				el.body.innerHTML = rows.length ? rows.map( function ( row ) { var label = row.name || row.label || row.path || ( row.kind === 'valued' ? row.value : text( row.kind ) ); if ( row.code && ( ! mapData || ! mapData.paths || ! mapData.paths[ row.code ] ) ) { label += ' (' + text( 'noOutline' ) + ')'; } return '<tr><th scope="row">' + esc( label ) + '</th><td>' + esc( number( row.count ) ) + '</td>' + ( hasShare( key ) ? '<td>' + ( row.share === null || row.share === undefined ? '—' : esc( String( Math.round( row.share * 100 ) / 100 ) ) + '%' ) + '</td>' : '' ) + '</tr>'; } ).join( '' ) : placeholder( key, text( p.state === 'empty' ? 'empty' : ( p.reason || p.state ) ) );
				el.root.setAttribute( 'aria-busy', 'false' );
			} ); paintMap( data.panels.map );
		}
		function failed( mine, signature ) { if ( ! current( mine, signature ) ) { return; } pending = false; controls(); status( text( 'error' ) + ( accepted ? ' ' + text( 'stale' ) : '' ) ); restoreFocus(); }
		function request() {
			if ( ! attached() || mainBusy || rangeBusy || ! context ) { return; }
			var mine = ++generation, signature = context.signature, p = params(); pending = true; controls(); status( text( 'loading' ) );
			ctx.api( PATH, p ).then( function ( data ) { if ( ! current( mine, signature ) ) { return; } try { if ( ! validResponse( data, p ) ) { throw new Error( 'Invalid dimensions context' ); } pending = false; paint( data ); accepted = data; controls(); restoreFocus(); } catch ( e ) { failed( mine, signature ); } }, function () { failed( mine, signature ); } );
		}
		function beginMain() { if ( ! attached() ) { return; } rememberFocus(); mainBusy = true; invalidate(); status( text( 'loading' ) ); }
		function acceptMain( selection, data ) {
			if ( ! attached() ) { return; }
			var next;
			if ( selection.mode === 'history' ) { if ( data.mode !== 'history' || ! data.range || ! data.range.dates || ! /^\d{4}-\d{2}-\d{2}$/.test( data.range.dates.from ) || ! /^\d{4}-\d{2}-\d{2}$/.test( data.range.dates.to ) || typeof data.range.from !== 'number' || ! isFinite( data.range.from ) || typeof data.range.to !== 'number' || ! isFinite( data.range.to ) || data.range.to <= data.range.from || typeof data.range.timezone !== 'string' || ! data.range.timezone ) { failMain(); return; } next = { mode: 'history', from: data.range.dates.from, to: data.range.dates.to, epochFrom: data.range.from, epochTo: data.range.to, timezone: data.range.timezone }; }
			else { if ( data.mode === 'history' || typeof data.now_ms !== 'number' || ! isFinite( data.now_ms ) || data.now_ms <= 0 || Math.floor( data.now_ms ) !== data.now_ms ) { failMain(); return; } next = { mode: 'live', anchor: data.now_ms, timezone: typeof data.timezone === 'string' ? data.timezone : null }; }
			if ( selection.filter && w.PcDataRealtimeFilters && w.PcDataRealtimeFilters.active( selection.filter ) ) { if ( ! w.PcDataRealtimeFilters.same( selection.filter, data.filter ) ) { failMain(); return; } next.filter = w.PcDataRealtimeFilters.normalize( selection.filter ); }
			next.signature = JSON.stringify( next ); context = next; mainBusy = false; stale = false; invalidate(); controls(); request();
		}
		function failMain() { if ( ! attached() ) { return; } mainBusy = false; stale = true; invalidate(); if ( context && ! rangeBusy ) { request(); } else { status( text( 'error' ) ); } }
		if ( ctx.onRangeState ) { unsubscribe = ctx.onRangeState( function ( busy ) { var changed = rangeBusy !== !! busy; if ( busy ) { rememberFocus(); } rangeBusy = !! busy; if ( rangeBusy ) { invalidate(); status( text( 'loading' ) ); } else if ( changed && ! mainBusy && context && attached() ) { recovery = w.setTimeout( function () { recovery = 0; request(); }, 0 ); } controls(); } ); }
		controls(); status( text( 'loading' ) );
		return { getWindows: function () { var copy = {}; KEYS.forEach( function ( key ) { copy[ key ] = windows[ key ]; } ); return copy; }, setWindowsListener: function ( fn ) { windowsListener = typeof fn === 'function' ? fn : null; }, beginMain: beginMain, acceptMain: acceptMain, failMain: failMain, dispose: function () { if ( disposed ) { return; } disposed = true; ++generation; stopTimers(); bindings.forEach( function ( binding ) { binding.element.removeEventListener( binding.type, binding.handler ); } ); bindings = []; document.removeEventListener( 'focusin', movedFocus ); if ( unsubscribe ) { unsubscribe(); unsubscribe = null; } focusReturn = null; context = null; accepted = null; windowsListener = null; } };
	}
	w.PcDataRealtimeDimensions = { mount: mount };
}( window ) );
