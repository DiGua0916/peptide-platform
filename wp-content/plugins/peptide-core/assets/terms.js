/**
 * 交易条款（TERMS-01）前台脚本。
 * - 注册页 / 登录页：条款链接（.pc-terms__link）打开服务端输出的弹窗。
 * - 区块结账页：WooCommerce 只渲染复选框文字，这里在每个条款复选框下面加条款链接，点开弹窗看内容。
 *   结账区块会重新渲染，所以用 MutationObserver 在链接丢失时补回。条款内容是后台保存时清理过的 HTML。
 */
( function ( cfg ) {
	'use strict';

	function openDialog( id ) {
		var d = document.getElementById( id );
		if ( d && d.showModal ) {
			d.showModal();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '.pc-terms__link' );
		if ( b ) {
			e.preventDefault();
			openDialog( b.getAttribute( 'data-pc-terms' ) );
		}
	} );

	if ( ! cfg || ! cfg.checkout || ! cfg.checkout.length ) {
		return;
	}

	function dialogFor( id, term ) {
		if ( document.getElementById( id ) ) {
			return;
		}
		var d = document.createElement( 'dialog' );
		d.className = 'pc-terms__dialog';
		d.id = id;
		d.setAttribute( 'aria-label', term.name );
		var body = document.createElement( 'div' );
		body.className = 'pc-terms__dialog-body';
		var h = document.createElement( 'h3' );
		h.textContent = term.name;
		body.appendChild( h );
		var content = document.createElement( 'div' );
		content.innerHTML = term.html;
		body.appendChild( content );
		var form = document.createElement( 'form' );
		form.method = 'dialog';
		var close = document.createElement( 'button' );
		close.className = 'pc-terms__close';
		close.textContent = cfg.close;
		form.appendChild( close );
		d.appendChild( body );
		d.appendChild( form );
		document.body.appendChild( d );
	}

	function attach() {
		cfg.checkout.forEach( function ( plan, p ) {
			if ( ! plan.terms.length ) {
				return;
			}
			// 结账区块把字段 ID 里的“/”换成“-”（order-peptide-core-terms-1），两种写法都找。
			var suffix = plan.field.replace( /^order-/, '' ).replace( /"/g, '' );
			var input = document.querySelector( 'input[id$="' + suffix + '"]' ) || document.querySelector( 'input[id$="' + suffix.replace( /\//g, '-' ) + '"]' );
			if ( ! input ) {
				return;
			}
			var wrap = input.closest( '.wc-block-components-checkbox' ) || input.parentNode;
			if ( wrap.nextElementSibling && wrap.nextElementSibling.getAttribute( 'data-pc-terms-for' ) === suffix ) {
				return;
			}
			var row = document.createElement( 'div' );
			row.className = 'pc-terms__links pc-terms__links--checkout';
			row.setAttribute( 'data-pc-terms-for', suffix );
			plan.terms.forEach( function ( term, i ) {
				var id = 'pc-terms-c' + p + '-' + i;
				dialogFor( id, term );
				if ( i ) {
					row.appendChild( document.createTextNode( ' · ' ) );
				}
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'pc-terms__link';
				b.setAttribute( 'data-pc-terms', id );
				b.textContent = term.name;
				row.appendChild( b );
			} );
			wrap.parentNode.insertBefore( row, wrap.nextSibling );
		} );
	}

	var timer = null;
	new MutationObserver( function () {
		clearTimeout( timer );
		timer = setTimeout( attach, 100 );
	} ).observe( document.body, { childList: true, subtree: true } );
	attach();
} )( window.pcTerms );
