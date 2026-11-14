/*
 * FE-02: shareable product and collection addresses while the storefront keeps its dialogs.
 *
 * A product link (/product/x/) or collection link is answered by the server with a redirect
 * to the home document (/?vc_product=ID or /?vc_catalog_category=ID), which opens the product
 * dialog or filters the catalogue. That keeps working exactly as before. This script only
 * changes what the address bar shows afterwards:
 *   - while a product dialog is open: that product's own permalink;
 *   - after a collection landing: the collection's permalink;
 *   - otherwise: the page's own address, without the vc_product entry parameter.
 * Every address it writes is one the server already accepts as an entry, so reloading or
 * sharing it leads back to the same view. It replaces the URL of the current history entry
 * and never adds or removes entries, so the existing back / forward handling of dialog
 * layers (live-commerce-navigation.js) is untouched. Other query parameters (utm_*, click
 * IDs, language) are kept as they are.
 *
 * Loaded only when the store has switched pc_entry_address on (inc/entry-address.php).
 */
( function ( root, factory ) {
	'use strict';
	var api = factory();
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = api;
	}
	if ( root && root.document ) {
		api.start( root );
	}
}( typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	var ENTRY_KEYS = [ 'vc_product', 'vc_catalog_category', 'vc_catalog_q' ];

	// Query string ('' or '?a=b&c') without the named parameters; order and encoding kept.
	function withoutKeys( search, keys ) {
		var query = String( search || '' ).replace( /^\?/, '' );
		var kept = query.split( '&' ).filter( function ( pair ) {
			if ( pair === '' ) {
				return false;
			}
			var name = pair.split( '=' )[ 0 ];
			try {
				name = decodeURIComponent( name.replace( /\+/g, ' ' ) );
			} catch ( e ) {}
			return keys.indexOf( name.toLowerCase() ) === -1;
		} );
		return kept.length ? '?' + kept.join( '&' ) : '';
	}

	function hasKey( search, key ) {
		return withoutKeys( search, [ key ] ) !== withoutKeys( search, [] );
	}

	// Same-origin pathname of a link, or '' for anything else (other origins, javascript:, empty).
	function samePath( href, origin ) {
		var match = /^(https?:\/\/[^\/?#]+)(\/[^?#]*)?/i.exec( String( href || '' ) );
		if ( ! match || match[ 1 ].toLowerCase() !== String( origin || '' ).toLowerCase() ) {
			return '';
		}
		var path = match[ 2 ] || '/';
		return /[\s<>"'\\]/.test( path ) ? '' : path;
	}

	/*
	 * Permalink path of the product whose dialog is open: the first candidate link that is a
	 * same-origin address other than the storefront home. '' when none is usable.
	 */
	function productPath( hrefs, origin, homePath ) {
		for ( var i = 0; i < ( hrefs || [] ).length; i++ ) {
			var path = samePath( hrefs[ i ], origin );
			if ( path && path !== String( homePath || '/' ) ) {
				return path;
			}
		}
		return '';
	}

	/*
	 * The address the bar should show.
	 * input.base: { path, search, hash } of the page as it was loaded.
	 * input.homePath: the storefront home path (entry redirects land there).
	 * input.categoryPath: permalink path of the collection this page was entered through, or ''.
	 * input.productPath: permalink path of the product whose dialog is open, or ''.
	 * input.productId: id of that product (used when its permalink is not known), or 0.
	 */
	function resolve( input ) {
		var base = input.base || {};
		var search = String( base.search || '' );
		var hash = String( base.hash || '' );
		if ( input.productPath ) {
			return input.productPath + withoutKeys( search, ENTRY_KEYS );
		}
		if ( input.productId > 0 ) {
			var rest = withoutKeys( search, ENTRY_KEYS );
			return String( input.homePath || '/' ) + '?vc_product=' + Number( input.productId ) + ( rest ? '&' + rest.slice( 1 ) : '' );
		}
		// No product open. A collection landing shows the collection's own address; a search
		// entry (vc_catalog_q) has no address of its own, so it is left alone.
		if ( input.categoryPath && hasKey( search, 'vc_catalog_category' ) && ! hasKey( search, 'vc_catalog_q' ) ) {
			return input.categoryPath + withoutKeys( search, [ 'vc_product', 'vc_catalog_category' ] ) + hash;
		}
		return String( base.path || '/' ) + withoutKeys( search, [ 'vc_product' ] ) + hash;
	}

	function start( win ) {
		var doc = win.document;
		var history = win.history;
		var location = win.location;
		var config = win.vcEntryAddress || {};
		if ( ! history || ! history.replaceState || ! config.enabled ) {
			return;
		}
		var base = { path: location.pathname, search: location.search, hash: location.hash };
		var timers = [];
		// The native method, not a wrapper another script may have put on the history object:
		// this is a cosmetic change of the current entry, not a navigation.
		var replace = ( win.History && win.History.prototype && win.History.prototype.replaceState ) || history.replaceState;

		function sync() {
			var dialog = doc.querySelector( 'dialog.vc-showcase-quick' );
			var open = !! ( dialog && dialog.open );
			var product = open ? dialog.querySelector( '.vc-showcase-quick-product' ) : null;
			var id = product ? Number( product.getAttribute( 'data-vc-product-id' ) || 0 ) : 0;
			var home = config.home || '/';
			// Where the product's permalink can be read from, most specific first: a details link
			// inside the dialog (not every dialog has one), the product this page was entered
			// through (the server knows its permalink), and the product's card on the page.
			var hrefs = [];
			if ( open && id > 0 ) {
				var details = dialog.querySelector( 'a.vc-showcase-quick__details[href]' );
				if ( details ) {
					hrefs.push( details.href );
				}
				var entered = config.product || null;
				if ( entered && Number( entered.id ) === id && typeof entered.path === 'string' && entered.path.charAt( 0 ) === '/' ) {
					hrefs.push( location.origin + entered.path );
				}
				var cards = doc.querySelectorAll ? doc.querySelectorAll( 'a[data-vc-quick-open="' + id + '"][href], a[data-vc-home-product="' + id + '"][href]' ) : [];
				for ( var i = 0; i < cards.length; i++ ) {
					hrefs.push( cards[ i ].href );
				}
			}
			var want = resolve( {
				base: base,
				homePath: home,
				categoryPath: config.category || '',
				productPath: productPath( hrefs, location.origin, home ),
				productId: id
			} );
			if ( want && want !== location.pathname + location.search + location.hash ) {
				try {
					replace.call( history, history.state, '', want );
				} catch ( e ) {}
			}
		}

		// The dialog layer script settles its own history entries first (it works in a
		// zero-delay timer and, when closing, with history.go); look again afterwards.
		function later() {
			timers.forEach( win.clearTimeout );
			timers = [ 60, 350, 1200 ].map( function ( delay ) {
				return win.setTimeout( sync, delay );
			} );
		}

		doc.addEventListener( 'vc:product-presented', later );
		doc.addEventListener( 'close', later, true ); // <dialog> close does not bubble.
		win.addEventListener( 'popstate', later );
		win.addEventListener( 'pageshow', later );
		later();
	}

	return { withoutKeys: withoutKeys, hasKey: hasKey, samePath: samePath, productPath: productPath, resolve: resolve, start: start };
} ) );
