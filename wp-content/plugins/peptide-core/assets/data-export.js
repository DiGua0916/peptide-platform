/** Authenticated CSV preparation; renderers retain authority over the current query. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.apiFetch || ! wp.url ) { return; }
	window.PcDataExport = {
		prepare: function ( path, params ) {
			return wp.apiFetch( { path: wp.url.addQueryArgs( path, params ), parse: false } ).then( function ( response ) {
				if ( ! response.ok || ! /^text\/csv(?:\s*;|$)/i.test( response.headers.get( 'Content-Type' ) || '' ) ) { throw new Error( 'CSV response required' ); }
				var disposition = response.headers.get( 'Content-Disposition' ) || '';
				var match = /filename="([A-Za-z0-9._-]+\.csv)"/i.exec( disposition );
				return response.blob().then( function ( blob ) { return { blob: blob, filename: match ? match[ 1 ] : 'report.csv' }; } );
			} );
		},
		download: function ( file, isCurrent ) {
			if ( ! isCurrent() ) { return false; }
			var url = window.URL.createObjectURL( file.blob );
			var anchor = document.createElement( 'a' );
			try {
				anchor.href = url; anchor.download = file.filename; anchor.hidden = true;
				document.body.appendChild( anchor );
				if ( ! isCurrent() ) { return false; }
				anchor.click();
				return true;
			} finally {
				if ( anchor.parentNode ) { anchor.parentNode.removeChild( anchor ); }
				// Delay revocation until the browser can start reading the object URL.
				window.setTimeout( function () { window.URL.revokeObjectURL( url ); }, 1000 );
			}
		}
	};
} )( window.wp );
