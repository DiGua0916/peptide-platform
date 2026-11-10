/* 已购顾客回访（M11）：物流卡片和查订单表单。顾客看到的文字都是英文。没有依赖。 */
( function () {
	'use strict';

	var cfg = window.pcBuyerStatus || {};
	var SEEN_KEY = 'pcBuyerCardSeen';

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	// 返回 { ok, status, data }。超过 timeout 毫秒就放弃（Promise 被拒绝）。
	function request( path, body, timeout ) {
		var controller = window.AbortController ? new window.AbortController() : null;
		var timer = controller && timeout ? window.setTimeout( function () {
			controller.abort();
		}, timeout ) : 0;
		var headers = { Accept: 'application/json' };
		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		return window.fetch( String( cfg.rest || '' ) + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined,
			signal: controller ? controller.signal : undefined
		} ).then( function ( res ) {
			if ( timer ) {
				window.clearTimeout( timer );
			}
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data || {} };
			} );
		} );
	}

	function seen( order ) {
		try {
			return window.sessionStorage.getItem( SEEN_KEY ) === order.number + ':' + order.status;
		} catch ( e ) {
			return false;
		}
	}

	function markSeen( order ) {
		try {
			window.sessionStorage.setItem( SEEN_KEY, order.number + ':' + order.status );
		} catch ( e ) {}
	}

	// 订单内容：订单号、状态、商品、运单。全部用 textContent，不拼 HTML。
	function orderBody( order, titleId ) {
		var body = el( 'div', 'pc-ob__body' );
		var shipment = order.shipment || {};
		var items = Array.isArray( order.items ) ? order.items : [];
		var list;

		body.appendChild( el( 'p', 'pc-ob__eyebrow', 'Your order' ) );
		var title = el( 'h2', 'pc-ob__title', 'Order #' + order.number );
		title.id = titleId;
		body.appendChild( title );

		var meta = el( 'p', 'pc-ob__meta' );
		meta.appendChild( el( 'span', 'pc-ob__status is-' + String( order.status || '' ).replace( /[^a-z_]/g, '' ), order.status_label || '' ) );
		if ( order.placed ) {
			meta.appendChild( el( 'span', 'pc-ob__placed', 'Placed ' + order.placed ) );
		}
		body.appendChild( meta );

		if ( items.length ) {
			list = el( 'ul', 'pc-ob__items' );
			items.forEach( function ( item ) {
				var row = el( 'li' );
				row.appendChild( el( 'span', 'pc-ob__item', item.name ) );
				row.appendChild( el( 'span', 'pc-ob__qty', '× ' + item.quantity ) );
				list.appendChild( row );
			} );
			if ( order.more_items > 0 ) {
				list.appendChild( el( 'li', 'pc-ob__more', '+ ' + order.more_items + ' more' ) );
			}
			body.appendChild( list );
		}

		if ( shipment.number ) {
			var ship = el( 'dl', 'pc-ob__ship' );
			if ( shipment.carrier ) {
				ship.appendChild( el( 'dt', '', 'Carrier' ) );
				ship.appendChild( el( 'dd', '', shipment.carrier ) );
			}
			ship.appendChild( el( 'dt', '', 'Tracking number' ) );
			ship.appendChild( el( 'dd', 'pc-ob__tracking', shipment.number ) );
			body.appendChild( ship );
			if ( shipment.url && /^https?:\/\//i.test( shipment.url ) ) {
				var track = el( 'a', 'pc-ob__button', 'Track package' );
				track.href = shipment.url;
				track.target = '_blank';
				track.rel = 'noopener';
				body.appendChild( track );
			}
		} else if ( order.status === 'awaiting_shipment' ) {
			body.appendChild( el( 'p', 'pc-ob__note', 'Tracking details will appear here once your order ships.' ) );
		}
		return body;
	}

	// 进店时浮在页面下方的物流卡片。关掉以后这次浏览不再弹。
	function showCard( order ) {
		if ( seen( order ) || document.querySelector( '.pc-ob--floating' ) ) {
			return;
		}
		var card = el( 'section', 'pc-ob pc-ob--floating' );
		var close = el( 'button', 'pc-ob__close' );
		var foot = el( 'div', 'pc-ob__foot' );
		var keep = el( 'button', 'pc-ob__button pc-ob__button--quiet', 'Continue shopping' );

		function dismiss() {
			markSeen( order );
			document.removeEventListener( 'keydown', onKey );
			if ( card.parentNode ) {
				card.parentNode.removeChild( card );
			}
		}
		function onKey( event ) {
			if ( event.key === 'Escape' ) {
				dismiss();
			}
		}

		card.setAttribute( 'role', 'dialog' );
		card.setAttribute( 'aria-modal', 'false' );
		card.setAttribute( 'aria-labelledby', 'pc-ob-title' );
		close.type = 'button';
		close.setAttribute( 'aria-label', 'Close order details' );
		close.textContent = '×';
		close.addEventListener( 'click', dismiss );
		keep.type = 'button';
		keep.addEventListener( 'click', dismiss );

		card.appendChild( close );
		card.appendChild( orderBody( order, 'pc-ob-title' ) );
		foot.appendChild( keep );
		if ( cfg.lookup ) {
			var other = el( 'a', 'pc-ob__link', 'Look up another order' );
			other.href = cfg.lookup;
			foot.appendChild( other );
		}
		card.appendChild( foot );
		document.body.appendChild( card );
		document.addEventListener( 'keydown', onKey );
		window.requestAnimationFrame( function () {
			card.classList.add( 'is-open' );
		} );
	}

	// ---------- 查订单表单 ----------
	function say( node, text ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text || '';
		node.hidden = ! text;
	}

	function lookup( form ) {
		var message = form.querySelector( '[data-pc-order-lookup-message]' );
		var result = form.parentNode ? form.parentNode.querySelector( '[data-pc-order-lookup-result]' ) : null;
		var button = form.querySelector( 'button[type="submit"]' );
		var email = form.elements.email ? form.elements.email.value.trim() : '';
		var number = form.elements.order ? form.elements.order.value.trim() : '';

		if ( ! email || ! number ) {
			say( message, 'Please enter both your email and your order number.' );
			return;
		}
		say( message, '' );
		if ( result ) {
			result.textContent = '';
		}
		if ( button ) {
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
		}
		function done() {
			if ( button ) {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			}
		}
		request( 'order-lookup', { email: email, order: number }, 15000 ).then( function ( r ) {
			done();
			if ( r.status === 429 ) {
				say( message, 'Too many attempts. Please wait a minute and try again.' );
				return;
			}
			if ( ! r.ok || ! r.data.found || ! r.data.order ) {
				say( message, r.data.message || 'We couldn’t find an order with that email and order number. Please check both and try again.' );
				return;
			}
			if ( result ) {
				var card = el( 'section', 'pc-ob pc-ob--inline' );
				card.setAttribute( 'aria-labelledby', 'pc-ol-title' );
				card.appendChild( orderBody( r.data.order, 'pc-ol-title' ) );
				result.appendChild( card );
				card.tabIndex = -1;
				card.focus();
			}
		} ).catch( function () {
			done();
			say( message, 'Something went wrong. Please check your connection and try again.' );
		} );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target && event.target.closest ? event.target.closest( '[data-pc-order-lookup]' ) : null;
		if ( form ) {
			event.preventDefault();
			lookup( form );
		}
	} );

	// 已购顾客进店：读最近一张订单。800 毫秒内没回来就当新客，什么都不显示。查订单页面上不弹。
	if ( cfg.known && ! document.querySelector( '[data-pc-order-lookup]' ) ) {
		request( 'buyer/status', null, parseInt( cfg.timeout, 10 ) || 800 ).then( function ( r ) {
			if ( r.ok && r.data.buyer && r.data.order ) {
				showCard( r.data.order );
			}
		} ).catch( function () {} );
	}
}() );
