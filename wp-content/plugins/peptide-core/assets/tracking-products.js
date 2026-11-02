/* Read-only product browsing reports. Shares no state with tracking-admin.js. */
( function ( wp, config ) {
	'use strict';
	var root = document.getElementById( 'pc-tracking-products' );
	if ( ! root || ! config ) return;
	var strings = config.strings || {};
	var sorts = [ 'views', 'viewers', 'repeat_viewers', 'returning_viewers', 'last_view_ms' ];
	var devices = [ '', 'desktop', 'mobile', 'tablet' ];
	var state, sequence = 0, controller, listPage = 1, busy = false;
	var filters = {}, nodes = {};
	function text( key ) { return strings[ key ] || key; }
	function element( tag, className, value ) {
		var node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( value !== undefined ) node.textContent = String( value );
		return node;
	}
	function integer( value ) { if ( value === null || value === undefined || value === '' || typeof value === 'boolean' ) return null; var n = Number( value ); return Number.isSafeInteger( n ) && n >= 0 ? n : null; }
	function count( value ) { var n = integer( value ); return n === null ? '—' : n.toLocaleString( 'zh-CN' ); }
	function positive( value ) { var n = integer( value ); return n && n > 0 ? n : 0; }
	function safeUrl( value ) {
		if ( typeof value !== 'string' || ! value ) return '';
		try { var url = new URL( value, window.location.href ); return /^(https?:)$/.test( url.protocol ) ? url.href : ''; } catch ( error ) { return ''; }
	}
	function readState() {
		var query = new URL( window.location.href ).searchParams;
		function date( key, fallback ) { var value = query.get( key ); return /^\d{4}-\d{2}-\d{2}$/.test( value || '' ) ? value : fallback; }
		return { from: date( 'from', config.weekAgo ), to: date( 'to', config.today ),
			device: devices.indexOf( query.get( 'device' ) || '' ) >= 0 ? query.get( 'device' ) || '' : '',
			q: ( query.get( 'q' ) || '' ).slice( 0, 100 ),
			sort: sorts.indexOf( query.get( 'sort' ) ) >= 0 ? query.get( 'sort' ) : 'views',
			page: Math.max( 1, Math.min( 100000, positive( query.get( 'report_page' ) ) || 1 ) ),
			product: positive( query.get( 'product_id' ) ) };
	}
	function writeLocation() {
		var url = new URL( config.pageUrl, window.location.href );
		[ 'from', 'to', 'device', 'q', 'sort' ].forEach( function ( key ) { if ( state[ key ] ) url.searchParams.set( key, state[ key ] ); } );
		if ( state.page > 1 ) url.searchParams.set( 'report_page', state.page );
		if ( state.product ) url.searchParams.set( 'product_id', state.product );
		window.history.pushState( null, '', url );
	}
	function options( select, values ) {
		values.forEach( function ( option ) { var node = element( 'option', '', text( option[ 1 ] ) ); node.value = option[ 0 ]; select.appendChild( node ); } );
	}
	function field( name, label, type ) {
		var wrapper = element( 'label', 'pc-tp-field pc-tp-field--' + name );
		wrapper.appendChild( element( 'span', '', text( label ) ) );
		var input = element( type === 'select' ? 'select' : 'input' );
		if ( type !== 'select' ) input.type = type;
		input.name = name;
		if ( type === 'date' ) input.required = true;
		if ( name === 'q' ) { input.maxLength = 100; input.placeholder = text( 'searchPlaceholder' ); }
		wrapper.appendChild( input ); filters[ name ] = input;
		nodes.form.appendChild( wrapper );
		return input;
	}
	function button( label, action, className ) {
		var node = element( 'button', 'button ' + ( className || '' ), text( label ) );
		node.type = 'button'; node.dataset.action = action;
		return node;
	}
	function build() {
		root.replaceChildren();
		nodes.form = element( 'form', 'pc-tp-filters' );
		nodes.form.setAttribute( 'aria-label', text( 'apply' ) );
		field( 'from', 'from', 'date' ); field( 'to', 'to', 'date' );
		options( field( 'device', 'device', 'select' ), [ [ '', 'allDevices' ], [ 'desktop', 'desktop' ], [ 'mobile', 'mobile' ], [ 'tablet', 'tablet' ] ] );
		field( 'q', 'search', 'search' );
		options( field( 'sort', 'sort', 'select' ), [ [ 'views', 'sortViews' ], [ 'viewers', 'sortViewers' ], [ 'repeat_viewers', 'sortRepeat' ], [ 'returning_viewers', 'sortReturning' ], [ 'last_view_ms', 'sortLast' ] ] );
		var actions = element( 'div', 'pc-tp-filter-actions' );
		var submit = button( 'apply', 'apply', 'button-primary' ); submit.type = 'submit';
		actions.append( submit, button( 'refresh', 'refresh' ) ); nodes.form.appendChild( actions );
		nodes.message = element( 'p', 'pc-tp-message' ); nodes.message.setAttribute( 'role', 'status' ); nodes.message.setAttribute( 'aria-live', 'polite' );
		nodes.body = element( 'div', 'pc-tp-results' );
		var rules = element( 'details', 'pc-tp-rules' );
		rules.append( element( 'summary', '', text( 'rulesTitle' ) ), element( 'p', '', text( 'rules' ) ), element( 'p', '', text( 'privacy' ) ) );
		root.append( nodes.form, nodes.message, nodes.body, rules );
		nodes.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( busy || ! nodes.form.reportValidity() ) return;
			if ( filters.from.value > filters.to.value ) { nodes.message.textContent = text( 'badDates' ); filters.to.focus(); return; }
			Object.keys( filters ).forEach( function ( key ) { state[ key ] = filters[ key ].value; } );
			state.page = 1; listPage = 1; writeLocation(); load();
		} );
		root.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( 'button[data-action]' );
			if ( ! target || ! root.contains( target ) || target.disabled ) return;
			var action = target.dataset.action;
			if ( action === 'apply' ) return;
			if ( action === 'detail' ) { listPage = state.page; state.product = positive( target.dataset.product ); state.page = 1; }
			else if ( action === 'back' ) { state.product = 0; state.page = listPage; }
			else if ( action === 'previous' ) state.page = Math.max( 1, state.page - 1 );
			else if ( action === 'next' ) state.page += 1;
			if ( action !== 'refresh' && action !== 'retry' ) writeLocation();
			load();
		} );
	}
	function setBusy( value ) {
		busy = value; root.setAttribute( 'aria-busy', value ? 'true' : 'false' );
		root.querySelectorAll( 'button,input,select' ).forEach( function ( node ) {
			if ( value ) { if ( node.dataset.wasDisabled === undefined ) node.dataset.wasDisabled = node.disabled ? '1' : '0'; node.disabled = true; }
			else { node.disabled = node.dataset.wasDisabled === '1'; delete node.dataset.wasDisabled; }
		} );
	}
	function syncFilters() {
		Object.keys( filters ).forEach( function ( key ) { filters[ key ].value = state[ key ]; } );
		filters.q.parentNode.hidden = !! state.product; filters.sort.parentNode.hidden = !! state.product;
	}
	function dateTime( value, timezone ) {
		var ms = Number( value );
		if ( ! Number.isFinite( ms ) || ms <= 0 ) return text( 'noTime' );
		var zone = timezone || config.timezone, offset = /^([+-])(\d{2}):(\d{2})$/.exec( zone || '' );
		if ( offset ) { ms += ( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] ) ) * ( offset[ 1 ] === '+' ? 60000 : -60000 ); zone = 'UTC'; }
		try { return new Intl.DateTimeFormat( 'zh-CN', { timeZone: zone, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23' } ).format( new Date( ms ) ); }
		catch ( error ) { return new Date( ms ).toISOString().replace( 'T', ' ' ).slice( 0, 19 ) + ' UTC'; }
	}
	function cell( row, label, value, className ) {
		var td = element( 'td', className || '' ); td.dataset.label = text( label ); td.setAttribute( 'role', 'cell' );
		if ( value && value.nodeType ) td.appendChild( value ); else td.textContent = value;
		row.appendChild( td ); return td;
	}
	function link( url, label ) {
		var safe = safeUrl( url );
		if ( ! safe ) return element( 'span', '', label );
		var node = element( 'a', '', label ); node.href = safe; return node;
	}
	function table( labels ) {
		var wrapper = element( 'div', 'pc-tp-table-wrap' ), node = element( 'table', 'widefat striped pc-tp-table' );
		node.setAttribute( 'role', 'table' );
		var caption = element( 'caption', 'screen-reader-text', text( state.product ? 'visitorDetail' : 'ranking' ) );
		var head = element( 'thead' ), tr = element( 'tr' ); tr.setAttribute( 'role', 'row' );
		labels.forEach( function ( key ) { var th = element( 'th', '', text( key ) ); th.scope = 'col'; th.setAttribute( 'role', 'columnheader' ); tr.appendChild( th ); } );
		head.appendChild( tr ); var body = element( 'tbody' );
		node.append( caption, head, body ); wrapper.appendChild( node );
		return { wrapper:wrapper, body:body };
	}
	function productCell( item ) {
		var node = element( 'div', 'pc-tp-product' ), imageUrl = safeUrl( item.image_url );
		if ( imageUrl ) { var image = element( 'img' ); image.src = imageUrl; image.alt = ''; image.width = 40; image.height = 40; image.loading = 'lazy'; image.addEventListener( 'error', function () { image.remove(); }, { once:true } ); node.appendChild( image ); }
		var name = element( 'div' ); name.append( link( item.product_url, item.name || text( 'product' ) + ' #' + item.product_id ), element( 'span', 'pc-tp-muted', text( 'productId' ) + '：' + item.product_id ) ); node.appendChild( name );
		return node;
	}
	function summary( data ) {
		var node = element( 'dl', 'pc-tp-summary' ), values = data.summary || {};
		( state.product ? [ 'views', 'viewers' ] : [ 'products', 'views', 'viewers' ] ).forEach( function ( key ) {
			var item = element( 'div' ); item.append( element( 'dt', '', text( key ) ), element( 'dd', '', count( values[ key ] ) ) ); node.appendChild( item );
		} ); return node;
	}
	function render( data ) {
		nodes.body.replaceChildren();
		if ( data.tracking === false ) { if ( state.product ) nodes.body.appendChild( button( 'back', 'back' ) ); nodes.body.appendChild( element( 'p', 'pc-tp-empty', text( 'unavailable' ) ) ); return; }
		var heading = element( 'div', 'pc-tp-result-heading' );
		if ( state.product ) heading.appendChild( button( 'back', 'back' ) );
		var product = data.product || {};
		heading.appendChild( element( 'h2', '', state.product ? ( product.name || text( 'product' ) + ' #' + state.product ) : text( 'ranking' ) ) );
		nodes.body.append( heading, summary( data ) );
		var note = text( 'timezone' ) + '：' + ( data.timezone || config.timezone ) + '。';
		if ( typeof data.collection_enabled === 'boolean' ) note += ' ' + text( data.collection_enabled ? 'collecting' : 'notCollecting' ) + '。';
		nodes.body.appendChild( element( 'p', 'pc-tp-meta', note ) );
		if ( ! data.rows.length ) { nodes.body.appendChild( element( 'p', 'pc-tp-empty', text( state.product ? 'noVisitors' : 'none' ) ) ); return; }
		var view = table( state.product ? [ 'visitor', 'accountType', 'views', 'visits', 'lastView', 'actions' ] : [ 'product', 'views', 'viewers', 'repeat', 'returning', 'lastView', 'actions' ] );
		data.rows.forEach( function ( item ) {
			var row = element( 'tr' ); row.setAttribute( 'role', 'row' );
			if ( state.product ) {
				var fallback = text( 'visitor' ) + ' #' + item.visitor_id;
				var label = typeof item.label === 'string' && item.label.indexOf( '@' ) < 0 ? item.label : fallback;
				cell( row, 'visitor', label || fallback, 'pc-tp-identity' );
				cell( row, 'accountType', text( item.account_type === 'registered' ? 'registered' : 'guest' ) );
				cell( row, 'views', count( item.views ), 'pc-tp-number' ); cell( row, 'visits', count( item.visits ), 'pc-tp-number' );
				cell( row, 'lastView', dateTime( item.last_view_ms, data.timezone ), 'pc-tp-time' );
				var visitorUrl = safeUrl( item.visitor_url );
				if ( ! visitorUrl && positive( item.visitor_id ) ) { var url = new URL( config.visitorUrl, window.location.href ); url.searchParams.set( 'visitor', item.visitor_id ); if ( positive( item.visit_id ) ) url.searchParams.set( 'visit', item.visit_id ); visitorUrl = url.href; }
				cell( row, 'actions', link( visitorUrl, text( 'viewJourney' ) ), 'pc-tp-actions' );
			} else {
				cell( row, 'product', productCell( item ), 'pc-tp-identity' );
				[ [ 'views','views' ], [ 'viewers','viewers' ], [ 'repeat','repeat_viewers' ], [ 'returning','returning_viewers' ] ].forEach( function ( metric ) { cell( row, metric[ 0 ], count( item[ metric[ 1 ] ] ), 'pc-tp-number' ); } );
				cell( row, 'lastView', dateTime( item.last_view_ms, data.timezone ), 'pc-tp-time' );
				var details = button( 'viewVisitors', 'detail' ); details.dataset.product = positive( item.product_id ); details.disabled = ! positive( item.product_id ); cell( row, 'actions', details, 'pc-tp-actions' );
			}
			view.body.appendChild( row );
		} );
		nodes.body.appendChild( view.wrapper );
		var total = integer( data.total ), pages = Math.max( 1, Math.ceil( total / ( positive( data.per_page ) || 20 ) ) );
		var pager = element( 'nav', 'pc-tp-pagination' ); pager.setAttribute( 'aria-label', text( 'page' ) );
		pager.appendChild( element( 'span', 'pc-tp-total', count( total ) + ' ' + text( state.product ? 'totalVisitors' : 'totalProducts' ) ) );
		var prev = button( 'previous', 'previous' ), next = button( 'next', 'next' ); prev.disabled = state.page <= 1; next.disabled = state.page >= pages;
		pager.append( prev, element( 'span', 'pc-tp-page', state.page + ' / ' + pages + ' ' + text( 'page' ) ), next ); nodes.body.appendChild( pager );
	}
	function skeleton() {
		var node = element( 'div', 'pc-tp-skeleton' ); node.setAttribute( 'aria-hidden', 'true' );
		for ( var i = 0; i < 4; i++ ) node.appendChild( element( 'div' ) );
		nodes.body.replaceChildren( node );
	}
	function load() {
		if ( controller ) controller.abort();
		var requestId = ++sequence; controller = typeof AbortController === 'function' ? new AbortController() : null;
		syncFilters(); setBusy( true ); nodes.message.textContent = text( 'loading' ); skeleton();
		var query = new URLSearchParams( { from:state.from, to:state.to, device:state.device, q:state.q, sort:state.sort, page:String( state.page ), per_page:'20' } );
		if ( state.product ) query.set( 'product_id', state.product );
		if ( ! wp || ! wp.apiFetch ) { failed( null, requestId ); return; }
		wp.apiFetch( { path:config.endpoint + '?' + query.toString(), method:'GET', headers:{ 'X-WP-Nonce':config.nonce }, signal:controller ? controller.signal : undefined } )
			.then( function ( data ) {
				if ( requestId !== sequence ) return;
				if ( ! data || ! Array.isArray( data.rows ) || ( data.tracking !== false && integer( data.total ) === null ) ) throw new Error( 'invalid_response' );
				setBusy( false ); nodes.message.textContent = ''; render( data );
			} ).catch( function ( error ) { if ( requestId === sequence && ( ! error || error.name !== 'AbortError' ) ) failed( error, requestId ); } );
	}
	function failed( error, requestId ) {
		if ( requestId !== sequence ) return;
		setBusy( false ); nodes.message.textContent = '';
		var note = element( 'div', 'pc-tp-error' ); note.setAttribute( 'role', 'alert' );
		if ( state.product ) note.appendChild( button( 'back', 'back' ) );
		note.append( element( 'p', '', text( error && error.data && ( error.data.status === 401 || error.data.status === 403 ) ? 'forbidden' : 'error' ) ), button( 'retry', 'retry' ) ); nodes.body.replaceChildren( note );
	}
	state = readState(); build(); load();
	window.addEventListener( 'popstate', function () { state = readState(); load(); } );
} )( window.wp, window.pcTrackingProducts );
