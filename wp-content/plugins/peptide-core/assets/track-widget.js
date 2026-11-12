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
	// 在物流面板里登录后刷新页面，自动再打开面板显示订单。
	try {
		if ( cfg.nonce && '1' === window.sessionStorage.getItem( 'pcTrkOpen' ) ) {
			window.sessionStorage.removeItem( 'pcTrkOpen' );
			window.setTimeout( open, 300 );
		}
	} catch ( x ) {}
	// 付款成功弹窗里的“Track my order”：关掉那个弹窗，打开物流面板。
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest && e.target.closest( '[data-pc-open-track]' );
		if ( ! t ) {
			return;
		}
		e.preventDefault();
		var x = document.querySelector( '[data-pc-paid-close]' );
		if ( x ) {
			x.click();
		}
		open();
	} );
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
			showSignIn();
			return;
		}
		p.then( function ( j ) {
			showOrders( j.orders || [] );
		} ).catch( function () {
			if ( cfg.nonce ) {
				body.textContent = '';
				body.appendChild( el( 'p', 'pc-trk__err', 'We could not load your orders. Please try again.' ) );
			} else {
				showSignIn( 'We could not load your orders. Please sign in.' );
			}
		} );
	}

	function field( label, name, type, autocomplete ) {
		var l = el( 'label', '', label );
		var i = el( 'input' );
		i.name = name;
		i.type = type;
		i.autocomplete = autocomplete;
		if ( 'email' === type ) {
			i.inputMode = 'email';
		}
		l.appendChild( i );
		return l;
	}

	/* 店主 2026-11-11：没登录时，物流面板 = 登录看自己的订单；没有账户的引导注册。按订单号查询只作为没有账户的老订单的备用入口。 */
	function showSignIn( msg ) {
		body.textContent = '';
		body.appendChild( el( 'p', 'pc-trk__lead', 'Sign in to see your orders and shipment tracking.' ) );
		var f = el( 'form', 'pc-trk__form' );
		f.noValidate = true;
		f.appendChild( field( 'Email', 'email', 'email', 'email' ) );
		f.appendChild( field( 'Password', 'password', 'password', 'current-password' ) );
		var err = el( 'p', 'pc-trk__err', msg || '' );
		err.hidden = ! msg;
		err.setAttribute( 'role', 'alert' );
		var btn = el( 'button', 'pc-trk__btn pc-trk__btn--primary', 'Sign in' );
		btn.type = 'submit';
		f.appendChild( err );
		f.appendChild( btn );
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! /@/.test( f.email.value ) || ! f.password.value ) {
				err.textContent = 'Please enter your email and password.';
				err.hidden = false;
				return;
			}
			btn.disabled = true;
			err.hidden = true;
			window.fetch( cfg.login, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { email: f.email.value.trim(), password: f.password.value } ) } )
				.then( function ( r ) {
					return r.json().then( function ( j ) {
						if ( ! r.ok ) {
							throw new Error( j && j.message ? j.message : 'Please try again.' );
						}
						return j;
					} );
				} )
				.then( function () {
					// 登录后刷新页面拿到登录状态，再自动打开物流面板。
					try {
						window.sessionStorage.setItem( 'pcTrkOpen', '1' );
					} catch ( x ) {}
					btn.textContent = 'Signed in';
					window.location.reload();
				} )
				.catch( function ( x ) {
					btn.disabled = false;
					err.textContent = x.message;
					err.hidden = false;
				} );
		} );
		body.appendChild( f );
		if ( cfg.lost ) {
			var lost = el( 'a', 'pc-trk__btn pc-trk__btn--text', 'Forgot password?' );
			lost.href = cfg.lost;
			body.appendChild( lost );
		}
		if ( cfg.signup ) {
			var box = el( 'div', 'pc-trk__new' );
			box.appendChild( el( 'p', 'pc-trk__muted', 'New here? Create an account to track everything you buy in one place.' ) );
			var create = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', 'Create an account' );
			create.type = 'button';
			create.addEventListener( 'click', function () {
				close();
				if ( 'function' === typeof window.pcAccountsOpen ) {
					window.pcAccountsOpen( 'signup' );
				} else if ( cfg.account ) {
					window.location.href = cfg.account;
				}
			} );
			box.appendChild( create );
			body.appendChild( box );
		}
		var guest = el( 'button', 'pc-trk__btn pc-trk__btn--text', 'Track an order without an account' );
		guest.type = 'button';
		guest.addEventListener( 'click', function () {
			showLookup();
		} );
		body.appendChild( guest );
	}

	/* 备用：订单号 + 下单邮箱（账户系统上线前的老订单）。 */
	function showLookup( msg ) {
		body.textContent = '';
		body.appendChild( el( 'p', 'pc-trk__lead', 'Enter your order number and the email you used at checkout.' ) );
		var f = el( 'form', 'pc-trk__form' );
		f.noValidate = true;
		var l1 = field( 'Order number', 'order', 'text', 'off' );
		f.appendChild( l1 );
		f.appendChild( field( 'Email', 'email', 'email', 'email' ) );
		var err = el( 'p', 'pc-trk__err', msg || '' );
		err.hidden = ! msg;
		err.setAttribute( 'role', 'alert' );
		var btn = el( 'button', 'pc-trk__btn pc-trk__btn--primary', 'Track order' );
		btn.type = 'submit';
		f.appendChild( err );
		f.appendChild( btn );
		f.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! /\d/.test( f.order.value ) || ! /@/.test( f.email.value ) ) {
				err.textContent = 'Please enter your order number and email.';
				err.hidden = false;
				return;
			}
			btn.disabled = true;
			err.hidden = true;
			request( cfg.lookup, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { order: f.order.value, email: f.email.value } ) } ).then( function ( j ) {
				showOrders( j.orders || [], true );
			} ).catch( function ( x ) {
				btn.disabled = false;
				err.textContent = x.message;
				err.hidden = false;
			} );
		} );
		body.appendChild( f );
		var back = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', 'Back to sign in' );
		back.type = 'button';
		back.addEventListener( 'click', function () {
			showSignIn();
		} );
		body.appendChild( back );
	}

	var STAGES = [ [ 'placed', 'Order placed' ], [ 'paid', 'Payment received' ], [ 'shipped', 'Shipped' ], [ 'delivered', 'Delivered' ] ];
	var REACH = { unpaid: 0, preparing: 1, shipped: 2, delivered: 3, closed: -1 };

	function stageLabel( o ) {
		return { unpaid: 'Awaiting payment', preparing: 'Preparing your shipment', shipped: 'On the way', delivered: 'Delivered', closed: o.status }[ o.stage ] || o.status;
	}

	function showOrders( list, lookedUp ) {
		body.textContent = '';
		if ( ! list.length ) {
			if ( cfg.nonce ) {
				body.appendChild( el( 'p', 'pc-trk__lead', 'No orders yet.' ) );
				body.appendChild( el( 'p', 'pc-trk__muted', 'Your orders and shipment tracking will show here after you place an order.' ) );
				if ( cfg.shop ) {
					var shopBtn = el( 'a', 'pc-trk__btn pc-trk__btn--primary', 'Start shopping' );
					shopBtn.href = cfg.shop;
					body.appendChild( shopBtn );
				}
			} else {
				showSignIn( lookedUp ? '' : 'We could not find that order. Sign in to see your orders.' );
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
			var other = el( 'button', 'pc-trk__btn pc-trk__btn--ghost', 'Sign in to see all your orders' );
			other.type = 'button';
			other.addEventListener( 'click', function () {
				showSignIn();
			} );
			body.appendChild( other );
		}
	}
}( window, document ) );
