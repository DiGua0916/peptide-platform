/**
 * 应用：离开意图增收（APP-EXIT-01）。前台弹窗和后台预览共用。
 * - 前台：页面配置 window.pcExitIntent。电脑端鼠标移出页面顶部时触发；手机端按勾选的方式触发（可以多选）：
 *   按返回键、快速上滑、切到别的 App / 标签页后回来、一段时间没有操作、看到页面底部。
 *   每位访客按设置的天数最多弹一次（localStorage，读写失败时只按本次浏览算）。
 * - 抽奖结果由服务端决定，这里只负责把转盘转到服务端给出的奖项。
 * - 后台预览：PeptideExitIntent.render( config, host, { demo: true, weights: [...] } )，不发请求。
 * 所有文字都用 textContent 插入。
 */
( function ( window, document ) {
	'use strict';

	var STORE_KEY = 'pcExitIntent';

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

	function fill( tpl, vars ) {
		return String( tpl || '' ).replace( /\{(prize|code)\}/g, function ( m, k ) {
			return undefined !== vars[ k ] ? vars[ k ] : m;
		} );
	}

	function post( url, data ) {
		var body = Object.keys( data ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( data[ k ] );
		} ).join( '&' );
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	// ---------- 转盘 ----------

	var SVG = 'http://www.w3.org/2000/svg';

	function wheel( labels ) {
		var n = Math.max( 2, labels.length );
		var seg = 360 / n;
		var svg = document.createElementNS( SVG, 'svg' );
		svg.setAttribute( 'viewBox', '-100 -100 200 200' );
		svg.setAttribute( 'class', 'pc-exit__svg' );
		svg.setAttribute( 'aria-hidden', 'true' );
		var g = document.createElementNS( SVG, 'g' );
		g.setAttribute( 'class', 'pc-exit__spinner' );
		labels.forEach( function ( label, i ) {
			// 从正上方开始顺时针，第 i 格是 [i*seg, (i+1)*seg]。
			var a0 = ( i * seg - 90 ) * Math.PI / 180;
			var a1 = ( ( i + 1 ) * seg - 90 ) * Math.PI / 180;
			var p = document.createElementNS( SVG, 'path' );
			p.setAttribute( 'd', 'M0 0 L' + ( 96 * Math.cos( a0 ) ).toFixed( 2 ) + ' ' + ( 96 * Math.sin( a0 ) ).toFixed( 2 ) + ' A96 96 0 ' + ( seg > 180 ? 1 : 0 ) + ' 1 ' + ( 96 * Math.cos( a1 ) ).toFixed( 2 ) + ' ' + ( 96 * Math.sin( a1 ) ).toFixed( 2 ) + ' Z' );
			p.setAttribute( 'class', 'pc-exit__seg ' + ( i % 2 ? 'is-light' : 'is-dark' ) );
			g.appendChild( p );
			var t = document.createElementNS( SVG, 'text' );
			var mid = i * seg + seg / 2;
			t.setAttribute( 'transform', 'rotate(' + mid + ') translate(0 -58) rotate(' + ( mid > 90 && mid < 270 ? 180 : 0 ) + ')' );
			t.setAttribute( 'class', 'pc-exit__label ' + ( i % 2 ? 'is-light' : 'is-dark' ) );
			t.setAttribute( 'text-anchor', 'middle' );
			t.setAttribute( 'dominant-baseline', 'middle' );
			t.setAttribute( 'font-size', n > 6 ? '9' : '11' );
			t.textContent = String( label ).length > 14 ? String( label ).slice( 0, 13 ) + '…' : String( label );
			g.appendChild( t );
		} );
		var hub = document.createElementNS( SVG, 'circle' );
		hub.setAttribute( 'r', '12' );
		hub.setAttribute( 'class', 'pc-exit__hub' );
		svg.appendChild( g );
		svg.appendChild( hub );
		return { svg: svg, spinner: g, seg: seg };
	}

	// ---------- 弹窗内容 ----------

	/**
	 * 生成弹窗内容。返回 { root, onClose }。
	 * opts.demo：后台预览，不发请求；opts.weights：预览时按权重模拟抽奖。
	 */
	function render( cfg, host, opts ) {
		opts = opts || {};
		var S = cfg.strings || {};
		var root = el( 'div', 'pc-exit pc-exit--' + cfg.type );
		root.style.setProperty( '--pc-exit-color', cfg.color || '#1d4ed8' );
		var close = el( 'button', 'pc-exit__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', S.close || 'Close' );
		root.appendChild( close );
		if ( cfg.image && 'wheel' !== cfg.type ) {
			var img = el( 'img', 'pc-exit__image' );
			img.src = cfg.image;
			img.alt = '';
			root.appendChild( img );
		}
		var title = el( 'h2', 'pc-exit__title', cfg.title );
		root.appendChild( title );
		var text = el( 'p', 'pc-exit__text', cfg.text );
		if ( cfg.text ) {
			root.appendChild( text );
		}
		var body = el( 'div', 'pc-exit__body' );
		root.appendChild( body );
		var result = el( 'p', 'pc-exit__result' );
		result.setAttribute( 'aria-live', 'polite' );
		var actions = el( 'div', 'pc-exit__actions' );
		var decline = el( 'button', 'pc-exit__decline', cfg.decline );
		decline.type = 'button';

		function codeBox( code ) {
			var box = el( 'div', 'pc-exit__code' );
			box.appendChild( el( 'code', '', code ) );
			var copy = el( 'button', 'pc-exit__copy', S.copy || 'Copy code' );
			copy.type = 'button';
			copy.addEventListener( 'click', function () {
				var done = function () {
					copy.textContent = S.copied || 'Copied';
				};
				if ( window.navigator.clipboard ) {
					window.navigator.clipboard.writeText( code ).then( done, function () {} );
				}
			} );
			box.appendChild( copy );
			return box;
		}

		function finish( titleText, message, code ) {
			title.textContent = titleText;
			text.remove();
			body.textContent = '';
			if ( code ) {
				body.appendChild( codeBox( code ) );
			}
			result.textContent = message || '';
			actions.textContent = '';
			var go = el( 'button', 'pc-exit__btn', S.shop || 'Continue shopping' );
			go.type = 'button';
			go.addEventListener( 'click', function () {
				root.dispatchEvent( new window.CustomEvent( 'pcexit:close', { detail: { done: true } } ) );
			} );
			actions.appendChild( go );
		}

		var main;
		if ( 'message' === cfg.type ) {
			if ( cfg.button && cfg.link ) {
				main = el( 'a', 'pc-exit__btn', cfg.button );
				main.href = cfg.link;
				main.addEventListener( 'click', function () {
					root.dispatchEvent( new window.CustomEvent( 'pcexit:done' ) );
				} );
				actions.appendChild( main );
			}
		} else if ( 'coupon' === cfg.type ) {
			if ( cfg.code ) {
				body.appendChild( codeBox( cfg.code ) );
			}
			main = el( 'button', 'pc-exit__btn', cfg.button );
			main.type = 'button';
			main.addEventListener( 'click', function () {
				if ( opts.demo ) {
					finish( cfg.title, 'Preview', cfg.code );
					return;
				}
				main.disabled = true;
				post( cfg.ajax.claim, {} ).then( function ( r ) {
					var d = r && r.data ? r.data : {};
					if ( r && r.success ) {
						root.dispatchEvent( new window.CustomEvent( 'pcexit:done' ) );
						finish( cfg.title, d.message, d.code || cfg.code );
					} else {
						result.textContent = d.message || S.error || '';
						main.disabled = false;
					}
				}, function () {
					result.textContent = S.error || '';
					main.disabled = false;
				} );
			} );
			actions.appendChild( main );
		} else {
			var w = wheel( cfg.prizes || [] );
			var stage = el( 'div', 'pc-exit__wheel' );
			stage.appendChild( w.svg );
			stage.appendChild( el( 'span', 'pc-exit__pointer' ) );
			body.appendChild( stage );
			main = el( 'button', 'pc-exit__btn', cfg.spin || 'Spin' );
			main.type = 'button';
			var turned = false;
			var land = function ( d ) {
				var target = 360 * 6 - ( d.index * w.seg + w.seg / 2 );
				w.spinner.style.transform = 'rotate(' + target + 'deg)';
				var shown = false;
				var show = function () {
					if ( shown ) {
						return;
					}
					shown = true;
					root.dispatchEvent( new window.CustomEvent( 'pcexit:done' ) );
					if ( d.won ) {
						finish( fill( cfg.winTitle, { prize: d.label, code: d.code } ), fill( cfg.winText, { prize: d.label, code: d.code } ) + ( d.message ? ' ' + d.message : '' ), d.code );
					} else {
						finish( d.label, cfg.loseText, '' );
					}
				};
				w.spinner.addEventListener( 'transitionend', show, { once: true } );
				window.setTimeout( show, 5200 );
			};
			main.addEventListener( 'click', function () {
				if ( turned ) {
					return;
				}
				turned = true;
				main.disabled = true;
				main.textContent = S.spinning || '…';
				if ( opts.demo ) {
					var ws = opts.weights || [];
					var total = ws.reduce( function ( a, b ) {
						return a + Math.max( 0, Number( b ) || 0 );
					}, 0 );
					var roll = Math.random() * total;
					var idx = 0;
					for ( var i = 0; i < ws.length; i++ ) {
						roll -= Math.max( 0, Number( ws[ i ] ) || 0 );
						if ( roll < 0 ) {
							idx = i;
							break;
						}
					}
					land( { index: idx, label: ( cfg.prizes || [] )[ idx ], won: !! ( opts.wins || [] )[ idx ], code: ( opts.wins || [] )[ idx ] || '' } );
					return;
				}
				post( cfg.ajax.spin, {} ).then( function ( r ) {
					if ( r && r.success ) {
						land( r.data );
					} else {
						result.textContent = ( r && r.data && r.data.message ) || S.error || '';
						turned = false;
						main.disabled = false;
						main.textContent = cfg.spin;
					}
				}, function () {
					result.textContent = S.error || '';
					turned = false;
					main.disabled = false;
					main.textContent = cfg.spin;
				} );
			} );
			actions.appendChild( main );
		}
		root.appendChild( result );
		root.appendChild( actions );
		if ( cfg.decline ) {
			root.appendChild( decline );
		}
		var shut = function () {
			root.dispatchEvent( new window.CustomEvent( 'pcexit:close' ) );
		};
		close.addEventListener( 'click', shut );
		decline.addEventListener( 'click', shut );
		host.appendChild( root );
		return root;
	}

	window.PeptideExitIntent = { render: render };

	// ---------- 前台：触发 ----------

	var cfg = window.pcExitIntent;
	if ( ! cfg || ! window.fetch || ! window.HTMLDialogElement ) {
		return;
	}

	function recently() {
		try {
			if ( window.sessionStorage.getItem( STORE_KEY ) ) {
				return true;
			}
			var t = Number( window.localStorage.getItem( STORE_KEY ) || 0 );
			return cfg.frequency > 0 && t && Date.now() - t < cfg.frequency * 86400000;
		} catch ( e ) {
			return false;
		}
	}
	function remember() {
		try {
			window.sessionStorage.setItem( STORE_KEY, '1' );
			window.localStorage.setItem( STORE_KEY, String( Date.now() ) );
		} catch ( e ) {}
	}

	var fired = false;
	var armed = false;
	var dialog = null;

	/** 弹出；不能弹时（已经弹过、还没到停留时间、已有别的弹窗）返回 false。 */
	function open() {
		if ( fired || ( ! cfg.preview && ( ! armed || recently() ) ) || document.querySelector( 'dialog[open]' ) ) {
			return false;
		}
		fired = true;
		if ( ! cfg.preview ) {
			remember();
		}
		dialog = el( 'dialog', 'pc-exit-dialog' );
		dialog.setAttribute( 'aria-label', cfg.title );
		document.body.appendChild( dialog );
		var root = render( cfg, dialog, {} );
		var closed = false;
		var end = function () {
			if ( closed ) {
				return;
			}
			closed = true;
			if ( dialog.open ) {
				dialog.close();
			}
			dialog.remove();
		};
		var done = false;
		root.addEventListener( 'pcexit:done', function () {
			done = true;
		} );
		root.addEventListener( 'pcexit:close', function () {
			if ( ! done && ! cfg.preview ) {
				post( cfg.ajax.event, { event: 'close' } ).catch( function () {} );
			}
			end();
		} );
		dialog.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			root.dispatchEvent( new window.CustomEvent( 'pcexit:close' ) );
		} );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				root.dispatchEvent( new window.CustomEvent( 'pcexit:close' ) );
			}
		} );
		dialog.showModal();
		if ( ! cfg.preview ) {
			post( cfg.ajax.event, { event: 'show' } ).catch( function () {} );
		}
		return true;
	}

	if ( cfg.preview ) {
		window.setTimeout( open, 600 );
		return;
	}
	if ( recently() ) {
		return;
	}
	window.setTimeout( function () {
		armed = true;
	}, Math.max( 0, cfg.triggers.min ) * 1000 );

	var coarse = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;
	var mobile = [].concat( cfg.triggers.mobile || [] );
	var has = function ( t ) {
		return coarse && mobile.indexOf( t ) !== -1;
	};
	if ( ! coarse && cfg.triggers.desktop ) {
		// 鼠标从页面顶部移出（去点地址栏、标签页或关闭按钮）。
		document.addEventListener( 'mouseout', function ( e ) {
			if ( ! e.relatedTarget && e.clientY <= 0 ) {
				open();
			}
		} );
	}
	if ( has( 'back' ) && window.history && window.history.pushState ) {
		// 按返回键：顾客第一次点页面时，加一条同地址的浏览记录（Chrome 只认有点击之后加的记录）。
		// 按返回时回到原记录，先弹窗；只拦一次，再按返回就正常离开；不能弹时马上放行。
		var pushed = false;
		var caught = false;
		var events = [ 'pointerup', 'touchend', 'click', 'keydown' ];
		var push = function () {
			events.forEach( function ( ev ) {
				window.removeEventListener( ev, push, true );
			} );
			if ( pushed || fired ) {
				return;
			}
			try {
				window.history.pushState( { pcExit: 1 }, '', window.location.href );
				pushed = true;
			} catch ( e ) {}
		};
		events.forEach( function ( ev ) {
			window.addEventListener( ev, push, true );
		} );
		window.addEventListener( 'popstate', function ( e ) {
			if ( ! pushed || caught || ( e.state && e.state.pcExit ) ) {
				return;
			}
			caught = true;
			if ( ! open() ) {
				window.history.back();
			}
		} );
	}
	if ( has( 'scroll' ) ) {
		// 往下看过一段后又快速往上滑（多半是要去地址栏或返回）。
		var maxY = 0;
		var lastY = window.scrollY;
		var lastT = Date.now();
		window.addEventListener( 'scroll', function () {
			var y = window.scrollY;
			var t = Date.now();
			maxY = Math.max( maxY, y );
			if ( maxY > 600 && lastY - y > 120 && t - lastT < 400 ) {
				open();
			}
			if ( t - lastT > 400 || y > lastY ) {
				lastY = y;
				lastT = t;
			}
		}, { passive: true } );
	}
	if ( has( 'return' ) ) {
		// 切到别的 App 或标签页超过 3 秒后又回到这一页。
		var awayAt = 0;
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				awayAt = Date.now();
			} else if ( awayAt && Date.now() - awayAt > 3000 ) {
				awayAt = 0;
				open();
			}
		} );
	}
	if ( has( 'bottom' ) ) {
		// 页面至少两屏长，并且看到了底部。
		window.addEventListener( 'scroll', function () {
			var doc = document.documentElement;
			if ( doc.scrollHeight > window.innerHeight * 2 && window.scrollY + window.innerHeight >= doc.scrollHeight - 200 ) {
				open();
			}
		}, { passive: true } );
	}
	if ( has( 'idle' ) ) {
		var timer = null;
		var reset = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( open, Math.max( 5, cfg.triggers.idle ) * 1000 );
		};
		[ 'touchstart', 'scroll', 'keydown' ].forEach( function ( ev ) {
			window.addEventListener( ev, reset, { passive: true } );
		} );
		reset();
	}
}( window, document ) );
