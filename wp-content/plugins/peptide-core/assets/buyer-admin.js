/* 投手版后台（M9）：首页的数字、验证像素、生成和复制投放链接。没有依赖。 */
( function () {
	'use strict';

	var cfg = window.pcBuyerAdmin || {};

	function endpoint( path, params ) {
		var base = String( cfg.rest || '' ) + path;
		var query = params ? new window.URLSearchParams( params ).toString() : '';
		return query ? base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + query : base;
	}

	// 返回 { ok, status, data }；网络错误时 Promise 被拒绝。
	function call( url, body ) {
		var headers = { 'X-WP-Nonce': String( cfg.nonce || '' ), Accept: 'application/json' };
		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		return window.fetch( url, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data || {} };
			} );
		} );
	}

	function say( el, state, text ) {
		if ( ! el ) {
			return;
		}
		el.classList.remove( 'is-ok', 'is-bad' );
		if ( state ) {
			el.classList.add( state );
		}
		el.textContent = text;
	}

	function message( result, fallback ) {
		return result && result.data && typeof result.data.message === 'string' && result.data.message ? result.data.message : fallback;
	}

	// ---------- 验证像素 ----------
	function testPixel( button ) {
		var out = document.querySelector( '[data-pc-by-pixel-result]' );
		button.disabled = true;
		say( out, '', '正在发送测试事件…' );
		call( endpoint( 'admin/pixel-test' ), {} ).then( function ( result ) {
			button.disabled = false;
			if ( result.status === 404 ) {
				say( out, 'is-bad', '验证功能还没上线，请稍后再试，或联系平台负责人。' );
				return;
			}
			if ( ! result.ok ) {
				say( out, 'is-bad', '没发出去：' + message( result, '请稍后再试。' ) );
				return;
			}
			if ( result.data.ok ) {
				say( out, 'is-ok', '已通' );
				if ( button.getAttribute( 'data-advance' ) ) {
					window.setTimeout( function () {
						window.location.href = button.getAttribute( 'data-advance' );
					}, 900 );
				}
				return;
			}
			say( out, 'is-bad', '未通' + ( message( result, '' ) ? '：' + message( result, '' ) : '' ) );
		} ).catch( function () {
			button.disabled = false;
			say( out, 'is-bad', '网络出错了，请再试一次。' );
		} );
	}

	// ---------- 投放链接 ----------
	function makeLink( form ) {
		var out = document.querySelector( '[data-pc-by-link-result]' );
		var row = document.querySelector( '[data-pc-by-link-out]' );
		var input = document.querySelector( '[data-pc-by-link-url]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var fields = form.elements;
		if ( button ) {
			button.disabled = true;
		}
		say( out, '', '正在生成…' );
		call( endpoint( 'admin/ad-link' ), {
			landing: fields.landing ? fields.landing.value : '',
			campaign: fields.campaign ? fields.campaign.value : '',
			source: fields.source ? fields.source.value : 'facebook'
		} ).then( function ( result ) {
			if ( button ) {
				button.disabled = false;
			}
			if ( ! result.ok || ! result.data.url ) {
				say( out, 'is-bad', message( result, '没有生成成功，请再试一次。' ) );
				return;
			}
			say( out, 'is-ok', '已生成，点“复制”后粘贴到广告里。' );
			if ( input && row ) {
				input.value = result.data.url;
				row.hidden = false;
				input.focus();
				input.select();
			}
		} ).catch( function () {
			if ( button ) {
				button.disabled = false;
			}
			say( out, 'is-bad', '网络出错了，请再试一次。' );
		} );
	}

	function copy( button ) {
		var target = document.querySelector( button.getAttribute( 'data-pc-by-copy' ) );
		var text = target ? ( target.value || target.textContent || '' ) : '';
		var label = button.textContent;
		function done() {
			button.textContent = '已复制';
			window.setTimeout( function () {
				button.textContent = label;
			}, 1500 );
		}
		function fallback() {
			if ( target && target.select ) {
				target.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( e ) {}
			}
		}
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			window.navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	}

	// ---------- 首页 ----------
	function initHome( home ) {
		var metrics = home.querySelector( '[data-pc-by-metrics]' );
		var period = home.querySelector( '[data-pc-by-period]' );
		var todos = home.querySelector( '[data-pc-by-todos]' );
		var empty = home.querySelector( '[data-pc-by-todo-empty]' );
		var latest = 0;

		function showTodos( open ) {
			var item = todos ? todos.querySelector( '[data-pc-by-todo="abandoned"]' ) : null;
			if ( item ) {
				if ( open > 0 ) {
					item.querySelector( '[data-pc-by-todo-text]' ).textContent = '有 ' + open + ' 个弃单还没跟进';
				} else {
					item.parentNode.removeChild( item );
				}
			}
			if ( empty && todos ) {
				empty.hidden = todos.children.length > 0;
			}
		}

		function load( range ) {
			var ticket = ++latest;
			if ( metrics ) {
				metrics.setAttribute( 'aria-busy', 'true' );
			}
			call( endpoint( 'admin/buyer-home', { range: range } ) ).then( function ( result ) {
				if ( ticket !== latest ) {
					return; // 已经切到别的时间段。
				}
				if ( metrics ) {
					metrics.removeAttribute( 'aria-busy' );
				}
				if ( ! result.ok || ! result.data.metrics ) {
					if ( period ) {
						period.textContent = '数据暂时读不出来：' + message( result, '请刷新页面再试。' );
					}
					return;
				}
				Object.keys( result.data.metrics ).forEach( function ( key ) {
					var el = home.querySelector( '[data-pc-by-metric="' + key + '"]' );
					if ( el ) {
						el.textContent = result.data.metrics[ key ];
					}
				} );
				if ( period ) {
					period.textContent = '统计时间：' + ( result.data.period || '' );
				}
				showTodos( parseInt( result.data.abandoned_open, 10 ) || 0 );
			} ).catch( function () {
				if ( ticket !== latest ) {
					return;
				}
				if ( metrics ) {
					metrics.removeAttribute( 'aria-busy' );
				}
				if ( period ) {
					period.textContent = '网络出错了，请刷新页面再试。';
				}
			} );
		}

		home.addEventListener( 'click', function ( event ) {
			var tab = event.target.closest( '[data-pc-by-range]' );
			if ( ! tab ) {
				return;
			}
			Array.prototype.forEach.call( home.querySelectorAll( '[data-pc-by-range]' ), function ( other ) {
				var on = other === tab;
				other.classList.toggle( 'is-on', on );
				other.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			load( tab.getAttribute( 'data-pc-by-range' ) );
		} );

		load( home.getAttribute( 'data-range' ) || 'today' );
	}

	document.addEventListener( 'click', function ( event ) {
		var test = event.target.closest( '[data-pc-by-pixel-test]' );
		var copier = event.target.closest( '[data-pc-by-copy]' );
		if ( test ) {
			testPixel( test );
		} else if ( copier ) {
			copy( copier );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-pc-by-link]' );
		if ( form ) {
			event.preventDefault();
			makeLink( form );
		}
	} );

	var home = document.querySelector( '[data-pc-by-home]' );
	if ( home ) {
		initHome( home );
	}
}() );
