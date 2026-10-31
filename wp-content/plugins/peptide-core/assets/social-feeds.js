/* Local social widget owners. No vendor resource exists before an instance is clicked. */
( function () {
	'use strict';
	var roots = new Map(), pending = false;
	var labels = { x: 'X', pinterest: 'Pinterest', facebook: 'Facebook' };
	var errorReasons = [ 'x-sdk-load-error', 'x-ready-missing', 'x-timeline-api-missing', 'x-timeline-call-threw' ];
	function config( root ) {
		try {
			var c = JSON.parse( root.getAttribute( 'data-pc-social-config' ) || 'null' );
			if ( ! c || Object.keys( c ).sort().join( ',' ) !== 'account,active,height,provider,tabs,url' || ! Object.prototype.hasOwnProperty.call( labels, c.provider ) || typeof c.account !== 'string' || typeof c.url !== 'string' || ! Number.isInteger( c.height ) || c.height < 60 || c.height > 4000 || typeof c.active !== 'boolean' || typeof c.tabs !== 'string' ) { return null; }
			var patterns = { x: /^[A-Za-z0-9_]{1,15}$/, pinterest: /^[A-Za-z0-9_]{1,64}$/, facebook: /^[A-Za-z0-9.]{1,100}$/ };
			var bases = { x: 'https://x.com/', pinterest: 'https://www.pinterest.com/', facebook: 'https://www.facebook.com/' };
			if ( ! patterns[ c.provider ].test( c.account ) || c.url !== bases[ c.provider ] + c.account + ( c.provider === 'x' ? '' : '/' ) ) { return null; }
			if ( c.provider === 'facebook' ? [ '', 'timeline', 'events', 'messages', 'timeline,events', 'timeline,messages', 'events,messages', 'timeline,events,messages' ].indexOf( c.tabs ) < 0 : c.tabs !== '' ) { return null; }
			return c;
		} catch ( e ) { return null; }
	}
	function random() {
		if ( ! window.crypto || ! window.crypto.getRandomValues ) { throw new Error( 'Secure local owner unavailable' ); }
		var bytes = new Uint8Array( 16 ); window.crypto.getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
	}
	function attr( value ) { return String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
	function safeJSON( value ) { return JSON.stringify( value ).replace( /</g, '\\u003c' ).replace( />/g, '\\u003e' ).replace( /&/g, '\\u0026' ).replace( /\u2028/g, '\\u2028' ).replace( /\u2029/g, '\\u2029' ); }
	function resourceURL( value ) {
		try { var u = new URL( value ); return ( u.protocol === 'https:' || u.protocol === 'http:' ) && ! u.username && ! u.password ? u.href : null; } catch ( e ) { return null; }
	}
	function childBoot( c ) {
		'use strict';
		var stopped = false, observer = null, dimensions = 0;
		function send( stage, height, reason ) {
			if ( stopped ) { return; }
			var message = { kind: 'pc-social-child', owner: c.owner, generation: c.generation, stage: stage };
			if ( height !== undefined ) { message.height = height; }
			if ( reason !== undefined ) { message.reason = reason; }
			parent.postMessage( message, c.parentOrigin );
		}
		function size() {
			if ( stopped || c.provider !== 'pinterest' ) { return; }
			var h = Math.ceil( Math.max( c.height, document.body.scrollHeight, document.documentElement.scrollHeight ) );
			if ( Number.isFinite( h ) && h >= 60 && h <= c.height + 512 && h !== dimensions ) { dimensions = h; send( 'size', h ); }
		}
		var target = document.getElementById( 'social-target' );
		if ( c.provider === 'pinterest' ) {
			var anchor = document.createElement( 'a' );
			anchor.setAttribute( 'data-pin-do', 'embedUser' );
			anchor.setAttribute( 'data-pin-board-width', String( c.width ) );
			anchor.setAttribute( 'data-pin-scale-height', String( c.height ) );
			anchor.setAttribute( 'data-pin-scale-width', '80' );
			anchor.href = c.url; anchor.textContent = 'Pinterest'; anchor.rel = 'noopener noreferrer'; anchor.target = '_blank';
			target.appendChild( anchor );
		}
		if ( c.provider === 'pinterest' && window.ResizeObserver ) { observer = new ResizeObserver( size ); observer.observe( document.body ); }
		var sdk = document.createElement( 'script' ); sdk.async = true; sdk.referrerPolicy = 'no-referrer';
		sdk.src = c.provider === 'x' ? 'https://platform.x.com/widgets.js' : 'https://assets.pinterest.com/js/pinit.js';
		sdk.onload = function () {
			if ( stopped ) { return; }
			send( 'sdk' );
			if ( c.provider === 'x' ) {
				if ( ! window.twttr || typeof window.twttr.ready !== 'function' ) { send( 'error', undefined, 'x-ready-missing' ); return; }
				window.twttr.ready( function ( twttr ) {
					if ( stopped || ! target.isConnected ) { return; }
					if ( ! twttr.widgets || typeof twttr.widgets.createTimeline !== 'function' ) { send( 'error', undefined, 'x-timeline-api-missing' ); return; }
					if ( twttr.events && typeof twttr.events.bind === 'function' ) {
						twttr.events.bind( 'rendered', function ( event ) {
							if ( ! stopped && event && event.target && target.contains( event.target ) && event.target.tagName === 'IFRAME' && event.target.getBoundingClientRect().width > 0 && event.target.getBoundingClientRect().height > 0 ) { send( 'structure' ); }
						} );
					}
					try { twttr.widgets.createTimeline( { sourceType: 'profile', screenName: c.account }, target, { height: c.height } ); } catch ( e ) { send( 'error', undefined, 'x-timeline-call-threw' ); }
				} );
			}
			size();
		};
		sdk.onerror = function () { send( 'error', undefined, c.provider === 'x' ? 'x-sdk-load-error' : undefined ); };
		document.head.appendChild( sdk );
		send( 'boot' ); size();
		window.addEventListener( 'pagehide', function () { stopped = true; if ( observer ) { observer.disconnect(); } sdk.onload = sdk.onerror = null; }, { once: true } );
	}
	function childDocument( c, owner, generation, width ) {
		var nonce = random(), sdk = c.provider === 'x' ? 'https://platform.x.com/widgets.js' : 'https://assets.pinterest.com/js/pinit.js';
		// Same-origin Gutenberg canvas documents can have an about: URL. Window.origin
		// carries their inherited security origin; never fall back to a wildcard target.
		var parentOrigin = new URL( window.origin || window.location.origin );
		if ( [ 'https:', 'http:' ].indexOf( parentOrigin.protocol ) < 0 || parentOrigin.origin === 'null' ) { throw new Error( 'Public document origin unavailable' ); }
		var policy = "default-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-src 'none'; child-src 'none'; connect-src 'none'; img-src 'none'; media-src 'none'; font-src 'none'; script-src 'nonce-" + nonce + "' " + sdk + "; style-src 'unsafe-inline'";
		var data = { provider: c.provider, account: c.account, url: c.url, height: c.height, width: width, owner: owner, generation: generation, parentOrigin: parentOrigin.origin };
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta http-equiv="Content-Security-Policy" content="' + attr( policy ) + '"><style>html,body{margin:0;padding:0;min-width:0;width:100%;background:#fff;color:#171717;font:14px/1.5 sans-serif}#social-target{min-width:0;max-width:100%}#social-target>*{max-width:100%}a{color:inherit}body{min-height:60px}</style></head><body><div id="social-target"></div><script nonce="' + nonce + '">(' + childBoot.toString() + ')(' + safeJSON( data ) + ');<\/script></body></html>';
	}
	function Instance( root, c ) {
		this.root = root; this.config = c; this.signature = root.getAttribute( 'data-pc-social-config' );
		this.target = root.querySelector( '[data-pc-social-target]' ); this.status = root.querySelector( '[data-pc-social-status]' );
		this.load = root.querySelector( '[data-pc-social-load]' ); this.remove = root.querySelector( '[data-pc-social-remove]' );
		this.frame = null; this.generation = 0; this.timer = null; this.failed = false; this.resizeTimer = null; this.width = 0; this.owner = ''; this.consent = false;
		var self = this;
		this.click = function ( event ) {
			if ( event.target.closest( '[data-pc-social-load]' ) === self.load ) { event.preventDefault(); self.start(); }
			if ( event.target.closest( '[data-pc-social-remove]' ) === self.remove ) { event.preventDefault(); self.reset( true ); }
		};
		this.message = function ( event ) { self.receive( event ); };
		root.addEventListener( 'click', this.click ); window.addEventListener( 'message', this.message );
		this.observer = window.ResizeObserver && this.target ? new ResizeObserver( function () { self.resize(); } ) : null;
		if ( this.observer ) { this.observer.observe( this.target ); }
	}
	Instance.prototype.live = function ( generation ) { return this.root.isConnected && this.generation === generation && this.root.getAttribute( 'data-pc-social-config' ) === this.signature; };
	Instance.prototype.visible = function () { return this.root.isConnected && this.root.getBoundingClientRect().width > 0 && this.root.getClientRects().length > 0 && getComputedStyle( this.root ).visibility !== 'hidden'; };
	Instance.prototype.say = function ( message, busy ) { if ( this.status ) { this.status.textContent = message; } if ( this.target ) { this.target.setAttribute( 'aria-busy', busy ? 'true' : 'false' ); } };
	Instance.prototype.fail = function ( message, reason ) {
		if ( this.failed ) { return; }
		this.failed = true; clearTimeout( this.timer ); this.timer = null;
		if ( this.config.provider === 'x' && errorReasons.indexOf( reason ) >= 0 ) { this.root.setAttribute( 'data-pc-social-error-reason', reason ); }
		this.say( message, false );
	};
	Instance.prototype.images = function ( show ) {
		this.root.querySelectorAll( '[data-pc-social-deferred-image]' ).forEach( function ( img ) {
			if ( ! show ) { img.removeAttribute( 'src' ); img.removeAttribute( 'srcset' ); return; }
			var src = resourceURL( img.getAttribute( 'data-pc-social-image-src' ) || '' ), set = img.getAttribute( 'data-pc-social-image-srcset' ) || '';
			img.referrerPolicy = 'no-referrer';
			if ( src ) { img.src = src; }
			if ( set && set.split( ',' ).every( function ( part ) { var m = part.trim().match( /^(\S+)(?:\s+[0-9]+(?:\.[0-9]+)?[wx])?$/ ); return m && resourceURL( m[ 1 ] ); } ) ) { img.srcset = set; }
		} );
	};
	Instance.prototype.clearFrame = function () {
		clearTimeout( this.timer ); this.timer = null;
		if ( this.frame ) {
			var focused = document.activeElement === this.frame; this.frame.onload = this.frame.onerror = null;
			this.frame.remove(); this.frame = null;
			if ( focused && this.root.isConnected && this.load ) { this.load.focus(); }
		}
	};
	Instance.prototype.reset = function ( focus ) {
		this.generation++; this.consent = false; this.failed = false; this.root.removeAttribute( 'data-pc-social-error-reason' ); clearTimeout( this.resizeTimer ); this.resizeTimer = null;
		this.clearFrame(); this.images( false );
		if ( this.remove ) { this.remove.hidden = true; }
		if ( this.load ) { this.load.textContent = '加载 ' + labels[ this.config.provider ] + ' 内容'; if ( focus ) { this.load.focus(); } }
		this.say( '外部内容已移除。再次加载后会连接 ' + labels[ this.config.provider ] + '。', false );
	};
	Instance.prototype.start = function () {
		if ( ! this.target || ! this.load || ! this.config.active || ! this.visible() ) { return; }
		var width = Math.floor( this.target.getBoundingClientRect().width );
		if ( width < ( this.config.provider === 'x' ? 180 : this.config.provider === 'pinterest' ? 130 : 1 ) ) { this.reset( false ); this.say( '此区域太窄，请前往公开主页查看。', false ); return; }
		this.clearFrame(); this.failed = false; this.root.removeAttribute( 'data-pc-social-error-reason' ); var generation = ++this.generation, self = this;
		try { this.owner = random(); } catch ( e ) { this.say( '此浏览器无法安全建立组件，请前往公开主页。', false ); return; }
		this.consent = true; this.width = width; this.images( true );
		this.say( '正在连接 ' + labels[ this.config.provider ] + '…', true ); this.load.textContent = '重新加载 ' + labels[ this.config.provider ] + ' 内容';
		if ( this.remove ) { this.remove.hidden = false; }
		var frame = document.createElement( 'iframe' ); frame.className = 'pc-social__frame'; frame.title = labels[ this.config.provider ] + ' 公开内容'; frame.referrerPolicy = 'no-referrer'; frame.style.height = this.config.height + 'px';
		this.frame = frame;
		if ( this.config.provider === 'facebook' ) {
			var src = new URL( 'https://www.facebook.com/plugins/page.php' );
			var params = { href: this.config.url, tabs: this.config.tabs, width: String( width ), height: String( this.config.height ), small_header: 'false', adapt_container_width: 'true', hide_cover: 'false', show_facepile: 'true', appId: '' };
			Object.keys( params ).forEach( function ( key ) { src.searchParams.set( key, params[ key ] ); } );
			frame.src = src.href;
			frame.onload = function () { if ( self.live( generation ) && ! self.failed ) { self.say( '外部页面已插入；若内容未显示，可重试或前往 Facebook。', false ); } };
		} else {
			frame.setAttribute( 'sandbox', 'allow-scripts allow-popups allow-popups-to-escape-sandbox' );
			try { frame.srcdoc = childDocument( this.config, this.owner, generation, width ); } catch ( e ) { this.frame = null; this.say( '组件无法建立，请重试或前往公开主页。', false ); return; }
		}
		frame.onerror = function () { if ( self.live( generation ) ) { self.fail( '外部内容未完成加载，请重试或前往公开主页。' ); } };
		this.target.appendChild( frame );
		this.timer = setTimeout( function () { if ( self.live( generation ) && ! self.failed ) { self.say( '未能确认内容显示。可重试或前往 ' + labels[ self.config.provider ] + '。', false ); } }, 15000 );
	};
	Instance.prototype.receive = function ( event ) {
		if ( ! this.frame || this.config.provider === 'facebook' || event.source !== this.frame.contentWindow || event.origin !== 'null' ) { return; }
		var d = event.data;
		if ( ! d || typeof d !== 'object' || Array.isArray( d ) || d.kind !== 'pc-social-child' || d.owner !== this.owner || d.generation !== this.generation || ! this.live( d.generation ) || [ 'boot', 'sdk', 'structure', 'size', 'error' ].indexOf( d.stage ) < 0 || Object.keys( d ).some( function ( key ) { return [ 'kind', 'owner', 'generation', 'stage', 'height', 'reason' ].indexOf( key ) < 0; } ) ) { return; }
		if ( Object.prototype.hasOwnProperty.call( d, 'reason' ) && ( this.config.provider !== 'x' || d.stage !== 'error' || errorReasons.indexOf( d.reason ) < 0 ) ) { return; }
		if ( this.failed ) { return; }
		if ( d.stage === 'size' ) {
			if ( this.config.provider === 'pinterest' && Number.isInteger( d.height ) && d.height >= this.config.height && d.height <= this.config.height + 512 ) { this.frame.style.height = d.height + 'px'; }
		} else if ( d.stage === 'error' ) { this.fail( '外部组件未完成加载。可重试或前往公开主页。', Object.prototype.hasOwnProperty.call( d, 'reason' ) ? d.reason : undefined ); }
		else if ( d.stage === 'structure' ) { this.say( '外部组件已插入；若内容未显示，可重试或前往公开主页。', false ); }
		// boot/sdk mean code readiness only; they never claim posts or photos were shown.
	};
	Instance.prototype.resize = function () {
		if ( ! this.consent || ! this.frame ) { return; }
		var self = this; clearTimeout( this.resizeTimer );
		this.resizeTimer = setTimeout( function () {
			if ( ! self.visible() ) { self.reset( false ); return; }
			var width = Math.floor( self.target.getBoundingClientRect().width );
			if ( width > 0 && width !== self.width ) { self.start(); }
		}, 180 );
	};
	Instance.prototype.destroy = function () { this.reset( false ); this.root.removeEventListener( 'click', this.click ); window.removeEventListener( 'message', this.message ); if ( this.observer ) { this.observer.disconnect(); } };
	function reconcile() {
		pending = false;
		roots.forEach( function ( instance, root ) { if ( ! root.isConnected || root.getAttribute( 'data-pc-social-config' ) !== instance.signature ) { instance.destroy(); roots.delete( root ); } else if ( instance.consent && ! instance.visible() ) { instance.reset( false ); } } );
		document.querySelectorAll( '[data-pc-social]' ).forEach( function ( root ) { if ( roots.has( root ) ) { return; } var c = config( root ); if ( c ) { roots.set( root, new Instance( root, c ) ); } } );
	}
	function schedule() { if ( ! pending ) { pending = true; Promise.resolve().then( reconcile ); } }
	function resetAll() { roots.forEach( function ( instance ) { if ( instance.consent ) { instance.reset( false ); } } ); }
	function boot() {
		reconcile();
		new MutationObserver( schedule ).observe( document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'data-pc-social-config', 'class', 'style', 'hidden' ] } );
		window.addEventListener( 'resize', function () { roots.forEach( function ( instance ) { instance.resize(); } ); } );
		window.addEventListener( 'pagehide', resetAll ); window.addEventListener( 'pageshow', function ( event ) { if ( event.persisted ) { resetAll(); schedule(); } } );
		document.addEventListener( 'visibilitychange', function () { if ( document.hidden ) { resetAll(); } } );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', boot, { once: true } ); } else { boot(); }
}() );
