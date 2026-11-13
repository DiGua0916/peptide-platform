/*
 * FE-03: "N people viewed this in the last M minutes" / "N added to bag in the last H hours"
 * in the product dialog.
 *
 * Real numbers only. They come from the data module's endpoint
 * (GET peptide/v1/product-activity?product_id=ID, see docs/CONTRACT.md); this script never
 * invents, rounds up or pads a number. Nothing is shown when the endpoint is missing, slow,
 * fails or answers with anything unexpected, and a number below the store's minimum is left
 * out, so a quiet product simply shows no line.
 *
 * Loaded only when the store has switched pc_product_activity on (inc/product-activity.php).
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

	var MAX = 1000000;

	// A whole, non-negative count as the endpoint must send it; anything else is not a count.
	function count( value ) {
		return typeof value === 'number' && Number.isInteger( value ) && value >= 0 && value <= MAX ? value : null;
	}

	function span( value, min, max ) {
		return typeof value === 'number' && Number.isInteger( value ) && value >= min && value <= max ? value : null;
	}

	/*
	 * The sentences to show for one product. data: the endpoint's answer; productId: the product
	 * the dialog shows; min: the store's minimum (a number below it is not shown).
	 * The wording states the window the server actually counted, taken from the answer itself.
	 */
	function lines( data, productId, min ) {
		var out = [];
		if ( ! data || typeof data !== 'object' || data.product_id !== productId ) {
			return out;
		}
		var floor = span( min, 1, MAX ) || 1;
		var windowInfo = data.window && typeof data.window === 'object' ? data.window : {};
		var viewing = count( data.viewing );
		var viewingMinutes = span( windowInfo.viewing_minutes, 1, 60 );
		if ( viewing !== null && viewing >= floor && viewingMinutes !== null ) {
			out.push( viewing + ( viewing === 1 ? ' person' : ' people' ) + ' viewed this in the last ' + viewingMinutes + ( viewingMinutes === 1 ? ' minute' : ' minutes' ) );
		}
		var added = count( data.added );
		var addedHours = span( windowInfo.added_hours, 1, 168 );
		if ( added !== null && added >= floor && addedHours !== null ) {
			out.push( added + ' added to bag in the last ' + addedHours + ( addedHours === 1 ? ' hour' : ' hours' ) );
		}
		return out;
	}

	// Same-origin endpoint address for one product, or '' when the configured address is unusable.
	function endpoint( base, productId, origin ) {
		var match = /^(https?:\/\/[^\/?#]+)(\/[^#]*)?$/i.exec( String( base || '' ) );
		if ( ! match || match[ 1 ].toLowerCase() !== String( origin || '' ).toLowerCase() || ! ( productId > 0 ) ) {
			return '';
		}
		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + 'product_id=' + Number( productId );
	}

	function start( win ) {
		var doc = win.document;
		var config = win.vcProductActivity || {};
		if ( ! config.enabled || ! config.url || typeof win.fetch !== 'function' ) {
			return;
		}
		var cache = {}; // productId → { at, data }
		var current = 0;

		function place( dialogRoot, texts ) {
			var old = dialogRoot.querySelector( '.vc-product-activity' );
			if ( old ) {
				old.parentNode.removeChild( old );
			}
			if ( ! texts.length ) {
				return;
			}
			var line = doc.createElement( 'p' );
			line.className = 'vc-product-activity';
			texts.forEach( function ( text ) {
				var part = doc.createElement( 'span' );
				part.textContent = text; // textContent: the numbers and words are never parsed as HTML.
				line.appendChild( part );
			} );
			var content = dialogRoot.querySelector( '.vc-product-dialog-content' );
			if ( content ) {
				content.insertBefore( line, content.firstChild );
			} else {
				dialogRoot.appendChild( line );
			}
		}

		function load( productId ) {
			var hit = cache[ productId ];
			if ( hit && Date.now() - hit.at < 60000 ) {
				return Promise.resolve( hit.data );
			}
			var url = endpoint( config.url, productId, win.location.origin );
			if ( ! url ) {
				return Promise.resolve( null );
			}
			var controller = typeof win.AbortController === 'function' ? new win.AbortController() : null;
			var timer = win.setTimeout( function () {
				if ( controller ) {
					controller.abort();
				}
			}, 2500 );
			return win.fetch( url, { credentials: 'omit', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined } )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					cache[ productId ] = { at: Date.now(), data: data };
					return data;
				} )
				.catch( function () {
					return null;
				} )
				.then( function ( data ) {
					win.clearTimeout( timer );
					return data;
				} );
		}

		doc.addEventListener( 'vc:product-presented', function ( event ) {
			var detail = event.detail || {};
			var productId = Number( detail.productId );
			var dialogRoot = detail.root;
			if ( ! ( productId > 0 ) || ! dialogRoot || typeof dialogRoot.querySelector !== 'function' ) {
				return;
			}
			current = productId;
			load( productId ).then( function ( data ) {
				// Another product may have been opened while this answer was on its way.
				if ( current === productId && dialogRoot.isConnected !== false ) {
					place( dialogRoot, lines( data, productId, Number( config.min ) ) );
				}
			} );
		} );
	}

	return { count: count, lines: lines, endpoint: endpoint, start: start };
} ) );
