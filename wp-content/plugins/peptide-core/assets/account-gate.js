/**
 * 进站 18+ 确认 + 注册弹窗（店主 2026-11-11）。配置 window.pcAccounts（Accounts::assets）。
 * 记录只放 localStorage（不写 cookie，首页静态缓存不受影响）；读写失败时只按本次浏览算。
 * 用原生 <dialog>：离开意图弹窗看到有打开的 dialog 就不弹。
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.pcAccounts || {};
	var AGE_KEY = 'pcAgeOk';
	var SEEN_KEY = 'pcSignupSeen';
	var AGE_DAYS = 30;
	var SKIP_DAYS = 7;
	var memory = {};

	function get( k ) {
		try {
			var v = window.localStorage.getItem( k );
			return null === v ? memory[ k ] || null : v;
		} catch ( e ) {
			return memory[ k ] || null;
		}
	}
	function set( k, v ) {
		memory[ k ] = v;
		try {
			window.localStorage.setItem( k, v );
		} catch ( e ) {}
	}
	function fresh( k, days ) {
		var t = parseInt( get( k ) || '0', 10 );
		return t > 0 && Date.now() - t < days * 864e5;
	}

	function openDialog( d ) {
		if ( ! d ) {
			return;
		}
		document.documentElement.classList.add( 'pc-acc-lock' );
		if ( 'function' === typeof d.showModal ) {
			try {
				d.showModal();
				return;
			} catch ( e ) {}
		}
		d.setAttribute( 'open', '' );
		d.classList.add( 'pc-acc--fallback' );
	}
	function closeDialog( d ) {
		if ( ! d ) {
			return;
		}
		if ( 'function' === typeof d.close && d.open ) {
			d.close();
		} else {
			d.removeAttribute( 'open' );
		}
		if ( ! document.querySelector( 'dialog.pc-acc[open]' ) ) {
			document.documentElement.classList.remove( 'pc-acc-lock' );
		}
	}

	var gate = document.querySelector( '[data-pc-gate]' );
	var signup = document.querySelector( '[data-pc-signup]' );
	var mode = 'signup';

	/* ---- 年龄确认 ---- */
	if ( gate ) {
		gate.addEventListener( 'cancel', function ( e ) {
			e.preventDefault(); // Esc 不能跳过年龄确认。
		} );
		Array.prototype.forEach.call( gate.querySelectorAll( '[data-pc-doc]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var name = b.getAttribute( 'data-pc-doc' );
				Array.prototype.forEach.call( gate.querySelectorAll( '[data-pc-doc-body]' ), function ( body ) {
					var show = body.getAttribute( 'data-pc-doc-body' ) === name && body.hidden;
					body.hidden = ! show;
					var btn = gate.querySelector( '[data-pc-doc="' + body.getAttribute( 'data-pc-doc-body' ) + '"]' );
					if ( btn ) {
						btn.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
					}
					if ( show ) {
						body.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
					}
				} );
			} );
		} );
		gate.querySelector( '[data-pc-gate-yes]' ).addEventListener( 'click', function () {
			set( AGE_KEY, String( Date.now() ) );
			closeDialog( gate );
			maybeSignup();
		} );
		gate.querySelector( '[data-pc-gate-no]' ).addEventListener( 'click', function () {
			window.location.href = 'https://www.google.com/';
		} );
	}

	/* ---- 注册 / 登录 ---- */
	function setMode( m ) {
		if ( ! signup ) {
			return;
		}
		mode = m;
		var isUp = 'signup' === m;
		signup.querySelector( '[data-pc-mode-title]' ).textContent = isUp ? 'Create your account' : 'Welcome back';
		signup.querySelector( '[data-pc-mode-lead]' ).textContent = isUp ? 'Track your orders and shipments in one place, and check out faster next time.' : 'Sign in with your email to see your orders and shipments.';
		signup.querySelector( '[data-pc-submit]' ).textContent = isUp ? 'Create account' : 'Sign in';
		signup.querySelector( '[data-pc-switch]' ).textContent = isUp ? 'I already have an account' : 'Create a new account';
		var only = signup.querySelector( '[data-pc-signup-only]' );
		only.hidden = ! isUp;
		only.querySelector( 'input' ).required = isUp;
		var pw = signup.querySelector( 'input[name="password"]' );
		pw.setAttribute( 'autocomplete', isUp ? 'new-password' : 'current-password' );
		showErr( '' );
	}
	function showErr( msg ) {
		var e = signup.querySelector( '[data-pc-err]' );
		e.textContent = msg;
		e.hidden = ! msg;
	}
	function openSignup( m ) {
		if ( ! signup ) {
			return;
		}
		setMode( m || 'signup' );
		openDialog( signup );
		var first = signup.querySelector( 'input[name="email"]' );
		if ( first && window.matchMedia && window.matchMedia( '(hover: hover)' ).matches ) {
			first.focus();
		}
	}
	function skip() {
		set( SEEN_KEY, String( Date.now() ) );
		closeDialog( signup );
	}
	function maybeSignup() {
		if ( cfg.signup && signup && ! fresh( SEEN_KEY, SKIP_DAYS ) ) {
			openSignup( 'signup' );
		}
	}

	if ( signup ) {
		Array.prototype.forEach.call( signup.querySelectorAll( '[data-pc-signup-skip]' ), function ( b ) {
			b.addEventListener( 'click', skip );
		} );
		signup.addEventListener( 'cancel', function () {
			set( SEEN_KEY, String( Date.now() ) );
			document.documentElement.classList.remove( 'pc-acc-lock' );
		} );
		signup.addEventListener( 'click', function ( e ) {
			if ( e.target === signup ) {
				skip(); // 点遮罩关闭。
			}
		} );
		signup.querySelector( '[data-pc-switch]' ).addEventListener( 'click', function () {
			setMode( 'signup' === mode ? 'login' : 'signup' );
		} );
		var form = signup.querySelector( '[data-pc-signup-form]' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var email = form.email.value.trim();
			var pw = form.password.value;
			if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
				return showErr( 'Please enter a valid email address.' );
			}
			if ( 'signup' === mode ) {
				if ( pw.length < 6 ) {
					return showErr( 'Your password needs at least 6 characters.' );
				}
				if ( pw !== form.password2.value ) {
					return showErr( 'The two passwords do not match.' );
				}
			} else if ( ! pw ) {
				return showErr( 'Please enter your password.' );
			}
			var btn = form.querySelector( '[data-pc-submit]' );
			btn.disabled = true;
			showErr( '' );
			window.fetch( 'signup' === mode ? cfg.register : cfg.login, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { email: email, password: pw, password2: form.password2.value } )
			} ).then( function ( r ) {
				return r.json().then( function ( j ) {
					if ( ! r.ok ) {
						var err = new Error( j && j.message ? j.message : 'Please try again.' );
						err.code = j && j.code;
						throw err;
					}
					return j;
				} );
			} ).then( function ( j ) {
				set( SEEN_KEY, String( Date.now() ) );
				if ( j && j.held ) {
					btn.disabled = false;
					showErr( j.message || 'Please check your email.' );
					return;
				}
				// 注册事件由服务端 Pixels::on_sign_up 统一发（浏览器 + CAPI，去重），这里不再自己发。
				btn.textContent = 'signup' === mode ? 'Account created' : 'Signed in';
				window.setTimeout( function () {
					window.location.reload();
				}, 500 );
			} ).catch( function ( x ) {
				btn.disabled = false;
				showErr( x.message || 'Please try again.' );
				if ( 'pc_acct_exists' === x.code ) {
					setMode( 'login' );
					showErr( x.message );
				}
			} );
		} );
	}

	// 账户页的“New here? Create an account”按钮等。
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest && e.target.closest( '[data-pc-open-signup]' );
		if ( t ) {
			e.preventDefault();
			openSignup( 'signup' );
		}
	} );
	window.pcAccountsOpen = openSignup;

	/* ---- 进站 ---- */
	if ( document.body && document.body.classList.contains( 'logged-in' ) ) {
		return;
	}
	if ( cfg.gate && gate && ! fresh( AGE_KEY, AGE_DAYS ) ) {
		openDialog( gate );
	} else {
		maybeSignup();
	}
}( window, document ) );
