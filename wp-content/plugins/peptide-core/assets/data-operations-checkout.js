/* Existing visit-start observations, loaded independently after the Operations report. */
( function ( w ) {
	'use strict';
	var STEPS = [ 'checkout_view', 'checkout_address_completed', 'checkout_shipping_selected', 'checkout_payment_selected', 'checkout_submit_attempt', 'payment_attempt', 'payment_success' ];
	var METRICS = [ 'initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed', 'success_rate' ];
	var DATES = [ 'from', 'to', 'compare_from', 'compare_to' ];
	function finite( value ) { return typeof value === 'number' && isFinite( value ); }
	function count( value ) { return finite( value ) && value >= 0 && Math.floor( value ) === value; }
	function nullable( value ) { return value === null || finite( value ); }
	function ownKeys( value, keys ) { return value && typeof value === 'object' && ! Array.isArray( value ) && Object.keys( value ).length === keys.length && keys.every( function ( key ) { return Object.prototype.hasOwnProperty.call( value, key ); } ); }
	function validDate( value ) { if ( typeof value !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) { return false; } var date = new Date( value + 'T00:00:00Z' ); return ! isNaN( date.getTime() ) && date.toISOString().slice( 0, 10 ) === value; }
	function safeUrl( value ) { return typeof value === 'string' && /^(?:https?:\/\/|\/(?!\/))/.test( value ) && ! /[\x00-\x20\x7f\\]/.test( value ); }
	function mount( slot, acceptedRange, ctx, options ) {
		options = options || {};
		var T = options.strings || {}, esc = ctx.esc, disposed = false, generation = 0, pending = false, rangeBusy = !! options.rangeBusy, accepted = null, restoreTimer = 0, dates = null;
		function text( key ) { return T[ key ] || ctx.strings.loadError; }
		function attached() { return ! disposed && slot && document.documentElement.contains( slot ); }
		slot.className = 'pc-ops-checkout-slot pc-ops-checkout';
		slot.innerHTML = '<h2>' + esc( text( 'checkoutTitle' ) ) + '</h2><div class="pc-ops-checkout-actions"><a class="pc-ops-checkout-details" aria-disabled="true" tabindex="-1">' + esc( text( 'details' ) ) + '</a><button type="button" class="button pc-ops-checkout-retry">' + esc( text( 'retry' ) ) + '</button></div><p class="pc-ops-checkout-status" role="status" aria-live="polite"></p><div class="pc-ops-checkout-content"></div>';
		var retry = slot.querySelector( '.pc-ops-checkout-retry' ), link = slot.querySelector( '.pc-ops-checkout-details' ), status = slot.querySelector( '.pc-ops-checkout-status' ), content = slot.querySelector( '.pc-ops-checkout-content' );
		if ( acceptedRange && acceptedRange.dates && DATES.every( function ( key ) { return validDate( acceptedRange.dates[ key ] ); } ) && acceptedRange.dates.from <= acceptedRange.dates.to && acceptedRange.dates.compare_from <= acceptedRange.dates.compare_to ) {
			dates = {}; DATES.forEach( function ( key ) { dates[ key ] = acceptedRange.dates[ key ]; } );
		}
		function controls() {
			if ( ! attached() ) { return; }
			// aria-disabled blocks repeated local loads without blurring the focused retry button.
			retry.disabled = rangeBusy || ! dates; retry.setAttribute( 'aria-disabled', pending || rangeBusy || ! dates ? 'true' : 'false' );
			slot.setAttribute( 'aria-busy', pending || rangeBusy ? 'true' : 'false' );
			if ( accepted && ! pending && ! rangeBusy ) { link.setAttribute( 'href', accepted.details_url ); link.setAttribute( 'aria-disabled', 'false' ); link.setAttribute( 'tabindex', '0' ); }
			else { link.removeAttribute( 'href' ); link.setAttribute( 'aria-disabled', 'true' ); link.setAttribute( 'tabindex', '-1' ); }
		}
		function current( mine ) { return mine === generation && attached() && ! rangeBusy; }
		function validResponse( data ) {
			if ( ! data || ! data.range || ! data.range.dates || ! DATES.every( function ( key ) { return data.range.dates[ key ] === dates[ key ]; } ) || typeof data.tracking !== 'boolean' || [ 'blocks', 'unconfirmed' ].indexOf( data.checkout_type ) < 0 || data.checkout_type_scope !== 'current_configuration' || ! safeUrl( data.details_url ) ) { return false; }
			var steps = data.steps;
			if ( ! steps || typeof steps.local_simulation !== 'boolean' || ! ownKeys( steps.labels, STEPS ) || ! ownKeys( steps.sources, STEPS ) || ! ownKeys( steps.current, STEPS ) || ! ownKeys( steps.previous, STEPS ) || ! ownKeys( steps.change, STEPS ) || ! Array.isArray( steps.channels ) ) { return false; }
			if ( ! STEPS.every( function ( key, index ) { return Object.keys( steps.labels )[ index ] === key && typeof steps.labels[ key ] === 'string' && !! steps.labels[ key ] && steps.sources[ key ] === ( index < 5 ? 'client' : 'server' ) && count( steps.current[ key ] ) && count( steps.previous[ key ] ) && nullable( steps.change[ key ] ); } ) ) { return false; }
			return steps.channels.every( function ( channel ) { return channel && typeof channel.method === 'string' && !! channel.method && ownKeys( channel.current, METRICS ) && ownKeys( channel.previous, METRICS ) && ownKeys( channel.change, METRICS ) && METRICS.every( function ( key ) { return ( key === 'success_rate' ? nullable( channel.current[ key ] ) && nullable( channel.previous[ key ] ) : count( channel.current[ key ] ) && count( channel.previous[ key ] ) ) && nullable( channel.change[ key ] ); } ); } );
		}
		function percent( value ) { return value === null ? '-' : ( Math.round( value * 100 ) / 100 ) + '%'; }
		function points( value ) { if ( value === null ) { return '-'; } var n = Math.round( value * 100 ) / 100; return ( n > 0 ? '+' : '' ) + n + ' ' + text( 'points' ); }
		function view( data ) {
			var steps = data.steps, html = '<p class="pc-ops-checkout-type">' + esc( text( data.checkout_type ) ) + '</p><p class="pc-ops-checkout-help">' + esc( text( 'typeHelp' ) ) + '</p>';
			if ( steps.local_simulation ) { html += '<p class="pc-ops-checkout-local">' + esc( text( 'localNotice' ) ) + '</p>'; }
			html += '<p class="pc-ops-checkout-help">' + esc( text( 'checkoutHelp' ) ) + '</p>';
			if ( ! STEPS.some( function ( key ) { return steps.current[ key ] || steps.previous[ key ]; } ) ) { html += '<p>' + esc( text( 'emptyCheckout' ) ) + '</p>'; }
			html += '<div class="pc-ops-checkout-scroll" role="region" tabindex="0" aria-label="' + esc( text( 'checkoutTitle' ) ) + '"><table class="pc-ops-checkout-observations"><thead><tr><th scope="col">' + esc( text( 'observedStep' ) ) + '</th><th scope="col">' + esc( text( 'currentVisits' ) ) + '</th><th scope="col">' + esc( text( 'previousVisits' ) ) + '</th><th scope="col">' + esc( text( 'relativeChange' ) ) + '</th></tr></thead><tbody>';
			STEPS.forEach( function ( key ) { html += '<tr><th scope="row">' + esc( steps.labels[ key ] ) + '<small>' + esc( text( steps.sources[ key ] === 'server' ? 'serverFact' : 'browserObservation' ) ) + '</small></th><td>' + esc( ctx.number( steps.current[ key ] ) ) + '</td><td>' + esc( ctx.number( steps.previous[ key ] ) ) + '</td><td>' + esc( ctx.change( steps.change[ key ] ) ) + '</td></tr>'; } );
			html += '</tbody></table></div><h3>' + esc( text( 'checkoutChannels' ) ) + '</h3><p class="pc-ops-checkout-help">' + esc( text( 'channelHelp' ) ) + '</p>';
			if ( ! steps.channels.length ) { return html + '<p>' + esc( text( 'emptyChannels' ) ) + '</p>'; }
			html += '<div class="pc-ops-checkout-scroll" role="region" tabindex="0" aria-label="' + esc( text( 'checkoutChannels' ) ) + '"><table class="pc-ops-checkout-channels"><thead><tr><th scope="col">' + esc( text( 'paymentChannel' ) ) + '</th>';
			METRICS.forEach( function ( key ) { html += '<th scope="col">' + esc( text( key ) ) + '</th>'; } ); html += '</tr></thead><tbody>';
			steps.channels.forEach( function ( channel ) {
				var method = channel.method === 'pc_local_card' ? text( 'localCard' ) : channel.method === 'pc_local_wallet' ? text( 'localWallet' ) : channel.method === 'unknown' ? text( 'unknown' ) : channel.method;
				html += '<tr><th scope="row">' + esc( method ) + '</th>';
				METRICS.forEach( function ( key ) { var rate = key === 'success_rate', c = channel.current[ key ], p = channel.previous[ key ], delta = channel.change[ key ]; html += '<td><strong>' + esc( text( 'currentPeriod' ) + ': ' + ( rate ? percent( c ) : ctx.number( c ) ) ) + '</strong><small>' + esc( text( 'previousPeriod' ) + ': ' + ( rate ? percent( p ) : ctx.number( p ) ) ) + '</small><small>' + esc( rate ? points( delta ) : ctx.change( delta ) ) + '</small></td>'; } ); html += '</tr>';
			} ); return html + '</tbody></table></div>';
		}
		function error() { if ( ! attached() ) { return; } accepted = null; pending = false; content.innerHTML = ''; status.textContent = text( 'error' ); controls(); }
		function load() {
			if ( ! attached() || rangeBusy || pending ) { return; }
			if ( ! dates ) { error(); return; }
			var mine = ++generation, params = {}; DATES.forEach( function ( key ) { params[ key ] = dates[ key ]; } );
			pending = true; accepted = null; content.innerHTML = ''; status.textContent = text( 'loading' ); controls();
			try { ctx.api( '/peptide/v1/data/checkout-steps', params ).then( function ( data ) {
				if ( ! current( mine ) ) { return; }
				try { if ( ! validResponse( data ) ) { throw new Error( 'Invalid checkout observations' ); } var html = view( data ); if ( ! current( mine ) ) { return; } content.innerHTML = html; accepted = data; pending = false; status.textContent = ''; controls(); }
				catch ( ignored ) { if ( current( mine ) ) { error(); } }
			}, function () { if ( current( mine ) ) { error(); } } ); } catch ( ignored ) { if ( current( mine ) ) { error(); } }
		}
		function retryClick() { if ( ! pending && ! rangeBusy && attached() ) { load(); } }
		function detailsClick( event ) { if ( ! accepted || pending || rangeBusy || ! attached() ) { event.preventDefault(); } }
		retry.addEventListener( 'click', retryClick ); link.addEventListener( 'click', detailsClick );
		controls(); if ( rangeBusy ) { status.textContent = text( 'applyingRange' ); } else { load(); }
		return {
			setRangeBusy: function ( value ) {
				if ( disposed || !! value === rangeBusy ) { return; } rangeBusy = !! value; ++generation; pending = false; accepted = null;
				if ( restoreTimer ) { w.clearTimeout( restoreTimer ); restoreTimer = 0; }
				if ( ! attached() ) { return; } content.innerHTML = ''; controls();
				if ( rangeBusy ) { status.textContent = text( 'applyingRange' ); }
				else { var mine = generation; restoreTimer = w.setTimeout( function () { restoreTimer = 0; if ( current( mine ) ) { load(); } }, 0 ); }
			},
			dispose: function () { if ( disposed ) { return; } disposed = true; ++generation; if ( restoreTimer ) { w.clearTimeout( restoreTimer ); restoreTimer = 0; } retry.removeEventListener( 'click', retryClick ); link.removeEventListener( 'click', detailsClick ); accepted = null; dates = null; slot = null; }
		};
	}
	w.PcDataOperationsCheckout = { mount: mount };
}( window ) );
