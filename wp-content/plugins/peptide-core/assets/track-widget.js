/**
 * 右下角物流追踪球（Track_Widget）。配置 window.pcTrack。所有文字用 textContent 插入。
 * 位置：叠在页面右下角已有的悬浮球（客服、购物车）上面，它们移动时跟着重新算。
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.pcTrack || {};
	var ball = document.querySelector( '[data-pc-trk-ball]' );
	var dlg = document.querySelector( '[data-pc-trk]' );
	if ( ! ball || ! dlg ) {
		return;
	}
	var body = dlg.querySelector( '[data-pc-trk-body]' );
	var ORDERS_KEY = 'pcTrackOrders';

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( undefined !== text && null !== text ) {
			n.textContent = String( text );
		}
		return n;
	}
	function saved() {
		try {
			var a = JSON.parse( window.localStorage.getItem( ORDERS_KEY ) || '[]' );
			return Array.isArray( a ) ? a.slice( -5 ) : [];
		} catch ( e ) {
			return [];
		}
	}
	function fmtDate( iso, withTime ) {
		if ( ! iso ) {
			return '';
		}
		// 按字面的日期时间显示（FedEx 给的是扫描地当地时间），不换算成看的人所在时区。
		var m = String( iso ).match( /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/ );
		if ( ! m ) {
			return String( iso );
		}
		var d = new Date( Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +( m[ 4 ] || 0 ), +( m[ 5 ] || 0 ) ) );
		var o = { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' };
		if ( withTime ) {
			o.hour = 'numeric';
			o.minute = '2-digit';
		}
		try {
			return d.toLocaleString( 'en-US', o );
		} catch ( e ) {
			return d.toDateString();
		}
	}

	/* ---- 位置：放在最高的那个悬浮球上面 ---- */
	function place() {
		var top = window.innerHeight - 16;
		Array.prototype.forEach.call( document.querySelectorAll( '.vc-help-widget, .vc-demo-cart-orb' ), function ( n ) {
			var s = window.getComputedStyle( n );
			if ( 'fixed' !== s.position || 'none' === s.display ) {
				return;
			}
			var r = n.getBoundingClientRect();
			if ( r.width && r.height && r.right > window.innerWidth - 120 && r.top < top ) {
				top = r.top;
			}
		} );
		ball.style.bottom = Math.max( 16, Math.round( window.innerHeight - top + 12 ) ) + 'px';
	}
	var raf = 0;
	function schedule() {
		if ( ! raf ) {
			raf = window.requestAnimationFrame( function () {
				raf = 0;
				place();
			} );
		}
	}
	place();
	window.addEventListener( 'resize', schedule );
	window.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'load', schedule );
	document.addEventListener( 'close', schedule, true );
	[ 600, 1500, 3000 ].forEach( function ( t ) {
		window.setTimeout( schedule, t );
	} );
	if ( window.MutationObserver ) {
		new window.MutationObserver( schedule ).observe( document.body, { childList: true } );
	}

	/* ---- 打开 / 关闭 ---- */
	function open() {
		document.documentElement.classList.add( 'pc-trk-lock' );
		if ( 'function' === typeof dlg.showModal ) {
			try {
				dlg.showModal();
			} catch ( e ) {
				dlg.setAttribute( 'open', '' );
			}
		} else {
			dlg.setAttribute( 'open', '' );
		}
		load();
	}
	function close() {
		if ( 'function' === typeof dlg.close && dlg.open ) {
			dlg.close();
		} else {
			dlg.removeAttribute( 'open' );
		}
		document.documentElement.classList.remove( 'pc-trk-lock' );
	}
	ball.addEventListener( 'click', open );
	dlg.querySelector( '[data-pc-trk-close]' ).addEventListener( 'click', close );
	dlg.addEventListener( 'close', function () {
		document.documentElement.classList.remove( 'pc-trk-lock' );
	} );
	dlg.addEventListener( 'click', function ( e ) {
		if ( e.target === dlg ) {
			close();
		}
	} );

	/* ---- 数据 ---- */
	function request( url, opts ) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = opts.headers || {};
		if ( cfg.nonce ) {
			opts.headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		return window.fetch( url, opts ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j && j.message ? j.message : 'Please try again.' );
				}
				return j;
			} );
		} );
	}
	function loading() {
		body.textContent = '';
		body.appendChild( el( 'p', 'pc-trk__muted', 'Loading your orders…' ) );
	}
	function load() {
		loading();
		var p;
		if ( cfg.nonce ) {
			p = request( cfg.mine );
		} else if ( saved().length ) {
			p = request( cfg.lookup, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { orders: saved() } ) } );
		} else {
			showForm();
			return;
		}
		p.then( function ( j ) {
			showOrders( j.orders || [] );
		} ).catch( function () {
			showForm( 'We could not load your orders. Please try again.' );
		} );
	}

	function showForm( msg ) {
		body.textContent = '';
		body.appendChild( el( 'p', 'pc-trk__lead', 'Enter your order number and the email you used at checkout.' ) );
		var f = el( 'form', 'pc-trk__form' );
		f.noValidate = true;
		var l1 = el( 'label', '', 'Order number' );
		var i1 = el( 'input' );
		i1.name = 'order';
		i1.inputMode = 'numeric';
		i1.placeholder = 'e.g. 11843';
		i1.autocomplete = 'off';
		l1.appendChild( i1 );
		var l2 = el( 'label', '', 'Email' );
		var i2 = el( 'input' );
		i2.name = 'email';
		i2.type = 'email';
		i2.autocomplete = 'email';
		i2.inputMode = 'email';
		l2.appendChild( i2 );
		var err = el( 'p', 'pc-trk__err', msg || '' );
		err.hidden = ! msg;
		err.setAttribute( 'role', 'alert' );
		var btn = el( 'button', 'pc-trk__btn pc-trk__btn--primary', 'Track order' );
		btn.type = 'submit';
		f.appendChild( l1 );
		f.appendChild( l2 );
		f.appendChild( err );
		f.appendChild( btn );
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! /\d/.test( i1.value ) || ! /@/.test( i2.value ) ) {
				err.textContent = 'Please enter your order number and email.';
				err.hidden = false;
				return;
			}
			btn.disabled = true;
			err.hidden = true;
			request( cfg.lookup, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { order: i1.value, email: i2.value } ) } ).then( function ( j ) {
				showOrders( j.orders || [], true );
			} ).catch( function ( x ) {
				btn.disabled = false;
				err.textContent = x.message;
				err.hidden = false;
			} );
		} );
		body.appendChild( f );
		var sign = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', 'Sign in to see all your orders' );
		sign.type = 'button';
		sign.addEventListener( 'click', function () {
			close();
			if ( 'function' === typeof window.pcAccountsOpen ) {
				window.pcAccountsOpen( 'login' );
			} else if ( cfg.account ) {
				window.location.href = cfg.account;
			}
		} );
		body.appendChild( sign );
	}

	var STAGES = [ [ 'placed', 'Order placed' ], [ 'paid', 'Payment received' ], [ 'shipped', 'Shipped' ], [ 'delivered', 'Delivered' ] ];
	var REACH = { unpaid: 0, preparing: 1, shipped: 2, delivered: 3, closed: -1 };

	function stageLabel( o ) {
		return { unpaid: 'Awaiting payment', preparing: 'Preparing your shipment', shipped: 'On the way', delivered: 'Delivered', closed: o.status }[ o.stage ] || o.status;
	}

	function showOrders( list, lookedUp ) {
		body.textContent = '';
		if ( ! list.length ) {
			body.appendChild( el( 'p', 'pc-trk__muted', 'You have no orders yet.' ) );
			if ( ! cfg.nonce ) {
				var again = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', 'Look up another order' );
				again.type = 'button';
				again.addEventListener( 'click', function () {
					showForm();
				} );
				body.appendChild( again );
			}
			return;
		}
		list.forEach( function ( o ) {
			var card = el( 'article', 'pc-trk__card' );
			var head = el( 'div', 'pc-trk__cardhead' );
			head.appendChild( el( 'strong', '', 'Order #' + o.number ) );
			head.appendChild( el( 'span', 'pc-trk__chip pc-trk__chip--' + o.stage, stageLabel( o ) ) );
			card.appendChild( head );
			card.appendChild( el( 'p', 'pc-trk__items', ( o.items || [] ).join( ', ' ) + ' · ' + o.total ) );

			// 进度条
			if ( 'closed' !== o.stage ) {
				var reach = REACH[ o.stage ];
				var steps = el( 'ol', 'pc-trk__steps' );
				STAGES.forEach( function ( s, i ) {
					var li = el( 'li', i <= reach ? 'is-done' : '' );
					li.appendChild( el( 'span', 'pc-trk__dot' ) );
					li.appendChild( el( 'span', '', s[ 1 ] ) );
					steps.appendChild( li );
				} );
				card.appendChild( steps );
			}

			if ( ! ( o.shipments || [] ).length && 'preparing' === o.stage ) {
				card.appendChild( el( 'p', 'pc-trk__muted', 'We are preparing your package. The tracking number will appear here as soon as it ships.' ) );
			}

			( o.shipments || [] ).forEach( function ( sh ) {
				var box = el( 'div', 'pc-trk__ship' );
				var line = el( 'div', 'pc-trk__shipline' );
				line.appendChild( el( 'span', 'pc-trk__carrier', sh.carrier || 'Carrier' ) );
				if ( sh.number ) {
					var num = el( 'button', 'pc-trk__num', sh.number );
					num.type = 'button';
					num.title = 'Copy tracking number';
					num.addEventListener( 'click', function () {
						var done = function () {
							num.textContent = 'Copied';
							window.setTimeout( function () {
								num.textContent = sh.number;
							}, 1200 );
						};
						if ( window.navigator.clipboard ) {
							window.navigator.clipboard.writeText( sh.number ).then( done, function () {} );
						}
					} );
					line.appendChild( num );
				}
				box.appendChild( line );
				var live = sh.live;
				if ( live ) {
					if ( live.status ) {
						box.appendChild( el( 'p', 'pc-trk__status', live.status ) );
					}
					if ( live.delivered ) {
						box.appendChild( el( 'p', 'pc-trk__muted', 'Delivered ' + fmtDate( live.delivered, true ) ) );
					} else if ( live.eta ) {
						box.appendChild( el( 'p', 'pc-trk__muted', 'Estimated delivery: ' + fmtDate( live.eta ) ) );
					}
					if ( ( live.events || [] ).length ) {
						var tl = el( 'ol', 'pc-trk__events' );
						live.events.forEach( function ( ev, i ) {
							if ( i >= 6 ) {
								tl.classList.add( 'is-clipped' );
							}
							var li = el( 'li', i >= 6 ? 'is-more' : '' );
							li.appendChild( el( 'strong', '', ev.text ) );
							li.appendChild( el( 'span', '', [ ev.place, fmtDate( ev.time, true ) ].filter( Boolean ).join( ' · ' ) ) );
							tl.appendChild( li );
						} );
						box.appendChild( tl );
						if ( live.events.length > 6 ) {
							var more = el( 'button', 'pc-trk__btn pc-trk__btn--text', 'Show all ' + live.events.length + ' updates' );
							more.type = 'button';
							more.addEventListener( 'click', function () {
								tl.classList.remove( 'is-clipped' );
								more.remove();
							} );
							box.appendChild( more );
						}
					}
				} else if ( sh.shipped ) {
					box.appendChild( el( 'p', 'pc-trk__muted', 'Shipped ' + fmtDate( sh.shipped ) ) );
				}
				if ( sh.url ) {
					var a = el( 'a', 'pc-trk__btn pc-trk__btn--primary', 'Track on ' + ( sh.carrier || 'carrier' ) + ' website' );
					a.href = sh.url;
					a.target = '_blank';
					a.rel = 'noopener';
					box.appendChild( a );
				}
				card.appendChild( box );
			} );
			body.appendChild( card );
		} );
		if ( ! cfg.nonce ) {
			var other = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', lookedUp ? 'Look up another order' : 'Track a different order' );
			other.type = 'button';
			other.addEventListener( 'click', function () {
				showForm();
			} );
			body.appendChild( other );
		}
	}
}( window, document ) );
