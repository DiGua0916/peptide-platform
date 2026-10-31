/**
 * 装修模块默认外观的前台交互（EDIT-BLOCKS-03）：轮播自动播放和圆点、对比图拖动、
 * “阅读更多”展开、时间轴折叠、数字增长、滚动渐显。主题提供自己的模块模板时可以不使用。
 * 遵守系统的“减少动态效果”设置。
 */
( function () {
	'use strict';

	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var motionQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	function mediaChange( query, callback ) {
		if ( ! query ) { return; }
		if ( query.addEventListener ) { query.addEventListener( 'change', callback ); }
		else if ( query.addListener ) { query.addListener( callback ); }
	}
	mediaChange( motionQuery, function ( event ) { reduce = event.matches; } );

	function each( selector, fn ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), fn );
	}

	function carousel( root ) {
		var slides = Array.prototype.filter.call( root.children, function ( c ) {
			return c.classList.contains( 'vc-slide' );
		} );
		if ( slides.length < 2 ) {
			return;
		}
		var index = 0;
		var timer = null;
		var dots = root.querySelector( '.vc-carousel__dots' );
		var hovered = false;
		var focused = false;
		var showcaseHeld = false;
		if ( root.dataset.dots === '1' ) {
			if ( ! dots ) {
				dots = document.createElement( 'div' );
				dots.className = 'vc-carousel__dots';
				root.appendChild( dots );
			}
			dots.innerHTML = '';
			slides.forEach( function ( s, i ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.setAttribute( 'aria-label', String( i + 1 ) );
				b.addEventListener( 'click', function () {
					go( i );
					restart();
				} );
				dots.appendChild( b );
			} );
			dots.removeAttribute( 'aria-hidden' );
		}
		if ( root.dataset.arrows === '1' ) {
			[ -1, 1 ].forEach( function ( direction ) {
				var arrow = document.createElement( 'button' );
				arrow.type = 'button';
				arrow.className = 'vc-carousel__arrow vc-carousel__arrow--' + ( direction < 0 ? 'prev' : 'next' );
				arrow.setAttribute( 'aria-label', direction < 0 ? ( root.dataset.prevLabel || 'Previous slide' ) : ( root.dataset.nextLabel || 'Next slide' ) );
				arrow.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><path d="' + ( direction < 0 ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7' ) + '"/></svg>';
				arrow.addEventListener( 'click', function () { go( index + direction ); restart(); } );
				root.appendChild( arrow );
			} );
		}
		function go( i ) {
			index = ( i + slides.length ) % slides.length;
			slides.forEach( function ( s, n ) {
				s.classList.toggle( 'is-active', n === index );
				s.setAttribute( 'aria-hidden', n === index ? 'false' : 'true' );
				s.inert = n !== index;
			} );
			if ( dots ) {
				Array.prototype.forEach.call( dots.children, function ( d, n ) {
					d.classList.toggle( 'is-active', n === index );
					d.setAttribute( 'aria-pressed', n === index ? 'true' : 'false' );
				} );
			}
		}
		function restart() {
			if ( timer ) {
				clearInterval( timer );
			}
			timer = null;
			if ( root.dataset.autoplay === '1' && ! reduce && ! hovered && ! focused && ! showcaseHeld && ! document.hidden ) {
				timer = setInterval( function () {
					if ( root.getClientRects().length ) { go( index + 1 ); }
				}, Math.max( 1000, parseInt( root.dataset.interval, 10 ) || 3000 ) );
			}
		}
		if ( root.classList.contains( 'vc-showcase' ) ) { root.addEventListener( 'vc:showcase-quick-state', function ( event ) { showcaseHeld = !! ( event.detail && event.detail.open ); restart(); } ); }
		// 装修编辑器选中某一张时：停在这一张，不再自动播放。
		root.addEventListener( 'vc:select-item', function ( e ) {
			var target = e.target && e.target.closest ? e.target.closest( '[data-module-item]' ) : null;
			var at = slides.indexOf( target );
			if ( at === -1 ) {
				return;
			}
			e.preventDefault();
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
			root.dataset.autoplay = '0';
			go( at );
		} );
		root.addEventListener( 'mouseenter', function () {
			hovered = true;
			if ( timer ) {
				clearInterval( timer );
			}
		} );
		root.addEventListener( 'mouseleave', function () { hovered = false; restart(); } );
		root.addEventListener( 'focusin', function () {
			focused = true;
			if ( timer ) {
				clearInterval( timer );
			}
		} );
		root.addEventListener( 'focusout', function ( event ) { focused = !! ( event.relatedTarget && root.contains( event.relatedTarget ) ); restart(); } );
		root.addEventListener( 'keydown', function ( event ) {
			if ( /^(INPUT|TEXTAREA|SELECT)$/.test( event.target.tagName ) ) { return; }
			var next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : null;
			if ( next === null ) { return; }
			event.preventDefault(); go( next ); restart();
		} );
		document.addEventListener( 'visibilitychange', restart );
		mediaChange( motionQuery, restart );
		go( 0 );
		restart();
	}

	/** Preserve narrow legacy column/gap values with discoverable, keyboard-accessible scrolling. */
	function cardGrid( root ) {
		if ( root.dataset.vcCardGridReady === '1' ) { return; }
		root.dataset.vcCardGridReady = '1';
		var hint = document.createElement( 'p' );
		hint.className = 'vc-card-list__scroll-hint';
		hint.textContent = root.dataset.scrollLabel || 'Scroll horizontally to see all cards';
		root.parentNode.insertBefore( hint, root.nextSibling );
		function measure() {
			var scrollable = root.scrollWidth > root.clientWidth + 1;
			root.tabIndex = scrollable ? 0 : -1;
			hint.hidden = ! scrollable;
		}
		if ( window.ResizeObserver ) { new window.ResizeObserver( measure ).observe( root ); }
		else { window.addEventListener( 'resize', measure ); }
		measure();
	}

	/** One-card steps mirror the configured card width; native scrolling remains the no-JS fallback. */
	function cardCarousel( root ) {
		if ( root.dataset.vcCardReady === '1' ) { return; }
		root.dataset.vcCardReady = '1';
		var cards = Array.prototype.slice.call( root.children );
		if ( cards.length < 2 ) { root.tabIndex = -1; return; }
		var controls = document.createElement( 'div' );
		controls.className = 'vc-card-list__controls';
		var pages = document.createElement( 'div' );
		pages.className = 'vc-card-list__pages';
		var status = document.createElement( 'span' );
		status.className = 'vc-card-list__status';
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );
		var stops = [], current = 0, frame = null;
		function arrow( previous ) {
			var button = document.createElement( 'button' );
			button.type = 'button'; button.className = 'vc-card-list__arrow';
			button.setAttribute( 'aria-label', previous ? root.dataset.prevLabel : root.dataset.nextLabel );
			button.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><path d="' + ( previous ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7' ) + '"/></svg>';
			button.addEventListener( 'click', function () { go( current + ( previous ? -1 : 1 ) ); } );
			return button;
		}
		var previous = arrow( true ), next = arrow( false );
		controls.appendChild( previous ); controls.appendChild( pages ); controls.appendChild( next ); controls.appendChild( status );
		root.parentNode.insertBefore( controls, root.nextSibling );
		function update() {
			var at = Math.abs( root.scrollLeft );
			current = 0;
			stops.forEach( function ( point, i ) { if ( Math.abs( point - at ) < Math.abs( stops[ current ] - at ) ) { current = i; } } );
			previous.disabled = current === 0; next.disabled = current === stops.length - 1;
			Array.prototype.forEach.call( pages.children, function ( button, i ) {
				var value = i === current ? 'true' : 'false';
				if ( button.getAttribute( 'aria-current' ) !== value ) { button.setAttribute( 'aria-current', value ); }
			} );
			var message = ( root.dataset.statusLabel || 'Page %d of %t' ).replace( '%d', current + 1 ).replace( '%t', stops.length );
			if ( status.textContent !== message ) { status.textContent = message; }
		}
		function measure() {
			var maximum = Math.max( 0, root.scrollWidth - root.clientWidth );
			var first = cards[ 0 ].getBoundingClientRect();
			stops = [];
			cards.forEach( function ( card ) {
				var point = Math.min( maximum, Math.abs( card.getBoundingClientRect().left - first.left ) );
				if ( ! stops.length || Math.abs( point - stops[ stops.length - 1 ] ) > 1 ) { stops.push( point ); }
			} );
			if ( pages.children.length !== stops.length ) {
				var focused = Array.prototype.indexOf.call( pages.children, document.activeElement );
				pages.textContent = '';
				stops.forEach( function ( point, i ) {
					var button = document.createElement( 'button' );
					button.type = 'button'; button.className = 'vc-card-list__page';
					button.setAttribute( 'aria-label', ( root.dataset.pageLabel || 'Go to page %d' ).replace( '%d', i + 1 ) );
					button.addEventListener( 'click', function () { go( i ); } );
					pages.appendChild( button );
				} );
				if ( focused >= 0 && pages.children.length ) { pages.children[ Math.min( focused, pages.children.length - 1 ) ].focus( { preventScroll: true } ); }
			}
			controls.hidden = maximum <= 1 || stops.length < 2;
			root.tabIndex = controls.hidden ? -1 : 0;
			update();
		}
		function go( index ) {
			var target = stops[ Math.max( 0, Math.min( stops.length - 1, index ) ) ];
			var direction = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			root.scrollTo( { left: target * direction, behavior: reduce ? 'auto' : 'smooth' } );
		}
		root.addEventListener( 'scroll', function () {
			if ( frame !== null ) { return; }
			frame = requestAnimationFrame( function () { frame = null; update(); } );
		}, { passive: true } );
		root.addEventListener( 'keydown', function ( event ) {
			if ( event.target !== root ) { return; }
			var direction = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			var index = event.key === 'ArrowRight' ? current + direction : event.key === 'ArrowLeft' ? current - direction : event.key === 'Home' ? 0 : event.key === 'End' ? stops.length - 1 : null;
			if ( index === null ) { return; }
			event.preventDefault(); go( index );
		} );
		root.addEventListener( 'vc:select-item', function ( event ) {
			var target = event.target && event.target.closest ? event.target.closest( '[data-module-item]' ) : null;
			var index = cards.indexOf( target );
			if ( index < 0 ) { return; }
			event.preventDefault(); go( index );
		} );
		if ( window.ResizeObserver ) { new window.ResizeObserver( measure ).observe( root ); }
		else { window.addEventListener( 'resize', measure ); }
		measure();
	}

	/** Slogan keeps native swipe without JS; phone-only dots scroll the actual item into view. */
	/** Information rows keep all SSR items; only the explicit bar mode gains arrows. */
	function infoList( root ) {
		if ( root.dataset.vcInfoReady === '1' ) { return; }
		root.dataset.vcInfoReady = '1';
		var items = Array.prototype.filter.call( root.children, function ( item ) { return item.tagName === 'LI' && item.hasAttribute( 'data-module-item' ); } );
		var swipe = root.dataset.vcInfoCarousel === '1', phone = window.matchMedia ? window.matchMedia( '(max-width: 767px)' ) : null;
		var current = 0, stops = [], frame = null, measuredWidth = -1, previous, next;
		function active() { return swipe && ( phone ? phone.matches : window.innerWidth < 768 ); }
		function shown() { return root.isConnected && root.getClientRects().length && root.clientWidth > 0; }
		function height() {
			if ( ! active() || ! items[ current ] ) { root.style.height = ''; return; }
			var size = items[ current ].getBoundingClientRect().height;
			if ( size > 0 && root.style.height !== size + 'px' ) { root.style.height = size + 'px'; }
			if ( root.scrollTop !== 0 ) { root.scrollTop = 0; }
		}
		function buttons() {
			if ( ! previous ) { return; }
			var visible = active() && items.length > 1 && root.scrollWidth > root.clientWidth + 1;
			previous.hidden = ! visible; next.hidden = ! visible;
			previous.disabled = current <= 0; next.disabled = current >= items.length - 1;
			root.tabIndex = visible ? 0 : -1;
		}
		function update() {
			if ( active() && stops.length ) {
				var at = Math.abs( root.scrollLeft );
				current = 0;
				stops.forEach( function ( point, i ) { if ( Math.abs( point - at ) < Math.abs( stops[ current ] - at ) ) { current = i; } } );
			}
			height(); buttons();
		}
		function go( index, immediate ) {
			if ( ! active() || ! stops.length || ! shown() ) { return; }
			current = Math.max( 0, Math.min( items.length - 1, index ) );
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			height(); buttons();
			root.scrollTo( { left: stops[ current ] * sign, behavior: immediate || reduce ? 'instant' : 'smooth' } );
		}
		function measure() {
			if ( ! shown() ) { return; }
			measuredWidth = root.getBoundingClientRect().width;
			if ( ! active() ) { root.classList.remove( 'is-enhanced' ); root.style.height = ''; buttons(); return; }
			root.classList.add( 'is-enhanced' );
			var first = items[ 0 ] && items[ 0 ].getBoundingClientRect(), maximum = Math.max( 0, root.scrollWidth - root.clientWidth );
			stops = first ? items.map( function ( item ) { return Math.min( maximum, Math.abs( item.getBoundingClientRect().left - first.left ) ); } ) : [];
			go( current, true ); update();
		}
		if ( swipe ) {
			function arrow( name, direction ) {
				var button = root.ownerDocument.createElement( 'button' );
				button.type = 'button'; button.className = 'vc-info-bar__arrow vc-info-bar__arrow--' + direction;
				button.setAttribute( 'aria-label', root.dataset[ name ] || ( direction === 'next' ? 'Next item' : 'Previous item' ) );
				button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + ( direction === 'next' ? 'm9 5 7 7-7 7' : 'm15 5-7 7 7 7' ) + '"/></svg>';
				button.hidden = true; root.parentNode.appendChild( button ); return button;
			}
			previous = arrow( 'previousLabel', 'previous' ); next = arrow( 'nextLabel', 'next' );
			previous.addEventListener( 'click', function () { go( current - 1 ); } ); next.addEventListener( 'click', function () { go( current + 1 ); } );
			function keys( event ) {
				if ( ! active() || ( event.target !== root && event.target !== previous && event.target !== next ) ) { return; }
				var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
				var target = event.key === 'ArrowRight' ? current + sign : event.key === 'ArrowLeft' ? current - sign : event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : null;
				if ( target !== null ) { event.preventDefault(); go( target ); }
			}
			root.addEventListener( 'keydown', keys ); previous.addEventListener( 'keydown', keys ); next.addEventListener( 'keydown', keys );
			root.addEventListener( 'scroll', function () { if ( frame === null ) { frame = requestAnimationFrame( function () { frame = null; update(); } ); } }, { passive: true } );
			root.addEventListener( 'focusin', function ( event ) { var item = event.target.closest && event.target.closest( '[data-module-item]' ); var index = items.indexOf( item ); if ( index >= 0 ) { go( index, true ); } } );
			if ( 'ResizeObserver' in window ) { var observer = new ResizeObserver( function () { if ( Math.abs( root.getBoundingClientRect().width - measuredWidth ) > .5 ) { measure(); } else { height(); buttons(); } } ); items.forEach( function ( item ) { observer.observe( item ); } ); observer.observe( root ); }
			Array.prototype.forEach.call( root.querySelectorAll( 'img' ), function ( image ) { image.addEventListener( 'load', measure ); } );
			window.addEventListener( 'resize', measure, { passive: true } ); if ( phone ) { mediaChange( phone, measure ); }
			window.addEventListener( 'pagehide', function () { if ( frame !== null ) { cancelAnimationFrame( frame ); frame = null; } } );
		}
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target && event.target.closest ? event.target.closest( '[data-module-item]' ) : null;
			var index = items.indexOf( item );
			if ( index < 0 || item.parentNode !== root || ! shown() || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) ) { return; }
			event.preventDefault();
			if ( active() ) { go( index, true ); } else { item.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } ); }
		} );
		measure();
	}

	function logoList( root ) {
		if ( root.dataset.vcLogoReady === '1' ) { return; }
		root.dataset.vcLogoReady = '1';
		var items = Array.prototype.filter.call( root.children, function ( item ) { return item.hasAttribute( 'data-module-item' ); } );
		var enhanced = !! root.dataset.vcLogoMode, stops = [], current = 0, selected = null, frame = null, width = -1, previous, next, pages, pageButtons = [];
		function shown() { return root.isConnected && root.getClientRects().length && root.clientWidth > 0; }
		function itemAt( node ) { var item = node && node.closest ? node.closest( '[data-module-item]' ) : null; return item && item.parentNode === root && items.indexOf( item ) >= 0 ? item : null; }
		function point( item ) { if ( ! items.length ) { return 0; } return Math.abs( item.getBoundingClientRect().left - items[ 0 ].getBoundingClientRect().left ); }
		function nearest( value ) { var index = 0; stops.forEach( function ( stop, i ) { if ( Math.abs( stop - value ) < Math.abs( stops[ index ] - value ) ) { index = i; } } ); return index; }
		function update() {
			if ( stops.length ) { current = nearest( Math.abs( root.scrollLeft ) ); }
			if ( ! pages ) { return; }
			var overflow = stops.length > 1;
			pages.hidden = ! overflow; previous.hidden = ! overflow; next.hidden = ! overflow;
			previous.disabled = current <= 0; next.disabled = current >= stops.length - 1;
			root.tabIndex = overflow ? 0 : -1;
			pageButtons.forEach( function ( button, index ) { if ( index === current ) { button.setAttribute( 'aria-current', 'true' ); } else { button.removeAttribute( 'aria-current' ); } } );
		}
		function go( index, instant ) {
			if ( ! shown() || ! stops.length ) { return; }
			current = Math.max( 0, Math.min( stops.length - 1, index ) );
			root.scrollTo( { left: stops[ current ] * ( getComputedStyle( root ).direction === 'rtl' ? -1 : 1 ), behavior: instant || reduce ? 'instant' : 'smooth' } );
			if ( instant || reduce ) { update(); }
		}
		function reveal( item ) {
			if ( enhanced && stops.length > 1 ) { go( nearest( point( item ) ), true ); }
			else { item.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } ); }
		}
		function measure() {
			if ( ! shown() ) { return; }
			width = root.getBoundingClientRect().width;
			var focused = itemAt( root.ownerDocument.activeElement ), focusedPage = pageButtons.indexOf( root.ownerDocument.activeElement ), anchor = focused || selected, maximum = Math.max( 0, root.scrollWidth - root.clientWidth );
			stops = [];
			items.forEach( function ( item ) { var value = Math.min( maximum, point( item ) ); if ( ! stops.some( function ( stop ) { return Math.abs( stop - value ) < 1; } ) ) { stops.push( value ); } } );
			stops.sort( function ( a, b ) { return a - b; } );
			if ( ! stops.length ) { stops = [ 0 ]; }
			if ( pages && pageButtons.length !== stops.length ) {
				pages.textContent = ''; pageButtons = stops.map( function ( stop, index ) {
					var button = root.ownerDocument.createElement( 'button' ); button.type = 'button'; button.className = 'vc-logo-page';
					button.setAttribute( 'aria-label', ( root.dataset.pageLabel || 'Logo page' ) + ' ' + ( index + 1 ) );
					button.addEventListener( 'click', function () { go( index ); } ); pages.appendChild( button ); return button;
				} );
			}
			if ( anchor && anchor.isConnected && anchor.getClientRects().length ) { current = nearest( point( anchor ) ); }
			go( current, true ); update();
			if ( focusedPage >= 0 ) { ( stops.length > 1 ? pageButtons[ Math.min( focusedPage, pageButtons.length - 1 ) ] : root ).focus( { preventScroll: true } ); }
		}
		if ( enhanced ) {
			root.classList.add( 'is-enhanced' );
			pages = root.ownerDocument.createElement( 'div' ); pages.className = 'vc-logo-pages'; pages.setAttribute( 'role', 'group' ); pages.setAttribute( 'aria-label', root.dataset.pageLabel || 'Logo pages' ); pages.hidden = true; root.parentNode.appendChild( pages );
			function arrow( direction ) {
				var button = root.ownerDocument.createElement( 'button' ); button.type = 'button'; button.className = 'vc-logo-arrow vc-logo-arrow--' + direction; button.hidden = true;
				button.setAttribute( 'aria-label', root.dataset[ direction === 'next' ? 'nextLabel' : 'previousLabel' ] || ( direction === 'next' ? 'Next logos' : 'Previous logos' ) );
				button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + ( direction === 'next' ? 'm9 5 7 7-7 7' : 'm15 5-7 7 7 7' ) + '"/></svg>';
				button.addEventListener( 'click', function () { go( current + ( direction === 'next' ? 1 : -1 ) ); } ); root.parentNode.appendChild( button ); return button;
			}
			previous = arrow( 'previous' ); next = arrow( 'next' );
			function keys( event ) {
				if ( event.target !== root && event.target !== previous && event.target !== next && pageButtons.indexOf( event.target ) < 0 ) { return; }
				var sign = getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
				var index = event.key === 'ArrowRight' ? current + sign : event.key === 'ArrowLeft' ? current - sign : event.key === 'Home' ? 0 : event.key === 'End' ? stops.length - 1 : null;
				if ( index !== null ) { event.preventDefault(); go( index ); }
			}
			root.addEventListener( 'keydown', keys ); root.parentNode.addEventListener( 'keydown', function ( event ) { if ( ! root.contains( event.target ) ) { keys( event ); } } );
			root.addEventListener( 'focusin', function ( event ) { var item = itemAt( event.target ); if ( item ) { reveal( item ); } } );
			root.addEventListener( 'scroll', function () { if ( frame === null ) { frame = requestAnimationFrame( function () { frame = null; update(); } ); } }, { passive: true } );
			if ( 'ResizeObserver' in window ) { var observer = new ResizeObserver( function () { if ( Math.abs( root.getBoundingClientRect().width - width ) > .5 ) { measure(); } } ); observer.observe( root ); }
			window.addEventListener( 'resize', measure, { passive: true } );
			window.addEventListener( 'pagehide', function () { if ( frame !== null ) { cancelAnimationFrame( frame ); frame = null; } } );
			measure();
		}
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = itemAt( event.target );
			if ( ! item || ! shown() || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) ) { return; }
			event.preventDefault(); selected = item; reveal( item );
		} );
	}

	function textBlock( root ) {
		if ( root.dataset.vcTextReady === '1' ) { return; }
		root.dataset.vcTextReady = '1';
		var body = root.querySelector( '.vc-textblock__body' ), button = root.querySelector( '.vc-textblock__toggle' ), frame = null;
		if ( ! body || ! button ) { return; }
		var height = parseFloat( getComputedStyle( root ).getPropertyValue( '--vc-collapsed' ) );
		if ( ! Number.isFinite( height ) || height <= 0 ) { return; }
		root.classList.add( 'is-enhanced' );
		function keepButtonVisible() {
			if ( button !== root.ownerDocument.activeElement || root.classList.contains( 'is-open' ) || button.hidden ) { return; }
			var rect = button.getBoundingClientRect(); if ( rect.top < 0 || rect.bottom > window.innerHeight ) { button.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'instant' } ); }
		}
		function open( value, instant ) {
			if ( instant ) { root.classList.add( 'is-revealing' ); }
			if ( ! value && body.contains( root.ownerDocument.activeElement ) ) { button.focus( { preventScroll: true } ); }
			root.classList.toggle( 'is-open', value ); button.setAttribute( 'aria-expanded', value ? 'true' : 'false' );
			button.textContent = value ? button.dataset.collapse || button.dataset.expand || '' : button.dataset.expand || '';
			if ( body.scrollTop !== 0 ) { body.scrollTop = 0; }
			if ( instant ) {
				body.getBoundingClientRect(); if ( frame !== null ) { cancelAnimationFrame( frame ); }
				frame = requestAnimationFrame( function () { frame = null; root.classList.remove( 'is-revealing' ); keepButtonVisible(); } );
			} else if ( reduce ) { keepButtonVisible(); }
		}
		function measure() {
			if ( ! root.isConnected || ! body.getClientRects().length ) { return; }
			var natural = body.scrollHeight, canFold = natural > height + 1;
			var wasFocused = root.ownerDocument.activeElement === button;
			if ( root.style.getPropertyValue( '--vc-text-natural' ) !== natural + 'px' ) { root.style.setProperty( '--vc-text-natural', natural + 'px' ); }
			root.classList.toggle( 'is-short', ! canFold ); button.hidden = ! canFold;
			if ( ! canFold && wasFocused ) { body.tabIndex = -1; body.focus( { preventScroll: true } ); }
			if ( canFold && body.contains( root.ownerDocument.activeElement ) ) { revealFocus( root.ownerDocument.activeElement ); }
		}
		function revealFocus( target ) {
			if ( root.classList.contains( 'is-open' ) || button.hidden || ! target || ! body.contains( target ) ) { return; }
			var rect = target.getBoundingClientRect(), box = body.getBoundingClientRect();
			if ( body.scrollTop !== 0 || rect.bottom > box.top + Math.max( 0, body.clientHeight - 60 ) || rect.top < box.top ) { open( true, true ); }
		}
		button.addEventListener( 'click', function () { measure(); open( ! root.classList.contains( 'is-open' ), false ); } );
		body.addEventListener( 'focusin', function ( event ) { revealFocus( event.target ); } );
		body.addEventListener( 'transitionend', function ( event ) { if ( event.target === body && event.propertyName === 'max-height' ) { keepButtonVisible(); } } );
		if ( 'ResizeObserver' in window ) { var observer = new ResizeObserver( measure ); observer.observe( body ); Array.prototype.forEach.call( body.children, function ( child ) { observer.observe( child ); } ); }
		Array.prototype.forEach.call( body.querySelectorAll( 'img' ), function ( image ) { image.addEventListener( 'load', measure ); } );
		window.addEventListener( 'resize', measure, { passive: true } );
		window.addEventListener( 'pagehide', function () { if ( frame !== null ) { cancelAnimationFrame( frame ); frame = null; } } );
		measure();
	}

	function sloganCarousel( root ) {
		if ( root.dataset.vcSloganReady === '1' ) { return; }
		root.dataset.vcSloganReady = '1';
		var items = Array.prototype.slice.call( root.children );
		if ( ! items.length ) { root.tabIndex = -1; return; }
		var phone = window.matchMedia ? window.matchMedia( '(max-width: 767px)' ) : null;
		var pages = document.createElement( 'div' );
		pages.className = 'vc-slogan__pages'; pages.hidden = true;
		root.parentNode.insertBefore( pages, root.nextSibling );
		var current = 0, stops = [], frame = null, measuredWidth = -1;
		function active() { return phone ? phone.matches : window.innerWidth < 768; }
		function height() {
			if ( ! active() ) { root.style.height = ''; return; }
			var value = items[ current ].getBoundingClientRect().height;
			if ( value > 0 && root.style.height !== value + 'px' ) { root.style.height = value + 'px'; }
		}
		function update() {
			if ( ! active() || ! stops.length ) { return; }
			var at = Math.abs( root.scrollLeft );
			current = 0;
			stops.forEach( function ( point, i ) { if ( Math.abs( point - at ) < Math.abs( stops[ current ] - at ) ) { current = i; } } );
			Array.prototype.forEach.call( pages.children, function ( button, i ) { button.setAttribute( 'aria-current', i === current ? 'true' : 'false' ); } );
			height();
		}
		function go( index, immediate ) {
			if ( ! active() || ! stops.length ) { return; }
			var target = Math.max( 0, Math.min( stops.length - 1, index ) );
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			root.scrollTo( { left: stops[ target ] * sign, behavior: immediate || reduce ? 'auto' : 'smooth' } );
		}
		items.forEach( function ( item, i ) {
			var button = document.createElement( 'button' );
			button.type = 'button'; button.className = 'vc-slogan__page';
			button.setAttribute( 'aria-label', ( root.dataset.pageLabel || 'Go to slide %d' ).replace( '%d', i + 1 ) );
			button.addEventListener( 'click', function () { go( i ); } );
			pages.appendChild( button );
		} );
		function measure() {
			measuredWidth = root.getBoundingClientRect().width;
			if ( ! active() ) {
				pages.hidden = true; root.tabIndex = -1; root.style.height = ''; root.classList.remove( 'is-enhanced' ); return;
			}
			root.classList.add( 'is-enhanced' );
			var maximum = Math.max( 0, root.scrollWidth - root.clientWidth );
			var first = items[ 0 ].getBoundingClientRect();
			stops = items.map( function ( item ) { return Math.min( maximum, Math.abs( item.getBoundingClientRect().left - first.left ) ); } );
			pages.hidden = items.length < 2 || maximum <= 1;
			root.tabIndex = pages.hidden ? -1 : 0;
			go( current, true ); update();
		}
		root.addEventListener( 'scroll', function () {
			if ( frame !== null ) { return; }
			frame = requestAnimationFrame( function () { frame = null; update(); } );
		}, { passive: true } );
		function keydown( event ) {
			if ( ! active() || ( event.target !== root && ! pages.contains( event.target ) ) ) { return; }
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			var target = event.key === 'ArrowRight' ? current + sign : event.key === 'ArrowLeft' ? current - sign : event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : null;
			if ( target === null ) { return; }
			event.preventDefault(); go( target );
		}
		root.addEventListener( 'keydown', keydown ); pages.addEventListener( 'keydown', keydown );
		root.addEventListener( 'focusin', function ( event ) {
			var item = event.target.closest && event.target.closest( '.vc-slogan__item' );
			var index = items.indexOf( item );
			if ( index >= 0 ) { go( index, true ); update(); }
		} );
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target.closest && event.target.closest( '[data-module-item]' );
			var index = items.indexOf( item );
			if ( active() && index >= 0 ) { event.preventDefault(); go( index ); }
		} );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( function () { if ( root.getBoundingClientRect().width !== measuredWidth ) { measure(); } else { height(); } } ).observe( root );
			var itemObserver = new window.ResizeObserver( height );
			items.forEach( function ( item ) { itemObserver.observe( item ); } );
		} else { window.addEventListener( 'resize', measure ); root.addEventListener( 'load', height, true ); }
		mediaChange( phone, measure );
		measure();
	}

	/** A free-scrolling gallery with a range control bound to its actual scroll position. */
	function galleryRail( root ) {
		if ( root.dataset.vcGalleryReady === '1' ) { return; }
		root.dataset.vcGalleryReady = '1';
		root.classList.add( 'is-draggable', 'has-scroll-control' );
		var position = document.createElement( 'input' );
		position.type = 'range'; position.min = '0'; position.max = '1000'; position.step = '1'; position.value = '0';
		position.className = 'vc-gallery__position';
		position.setAttribute( 'aria-label', root.dataset.scrollLabel || 'Gallery position' );
		root.parentNode.insertBefore( position, root.nextSibling );
		var pointer = null, dragging = false, ignoreClickUntil = 0;
		function update() {
			var maximum = root.scrollWidth - root.clientWidth;
			var value = maximum > 0 ? Math.min( 1000, Math.round( Math.abs( root.scrollLeft ) / maximum * 1000 ) ) : 0;
			position.value = String( value ); position.setAttribute( 'aria-valuetext', Math.round( value / 10 ) + '%' );
		}
		function measure() {
			var scrollable = root.scrollWidth > root.clientWidth + 1;
			root.tabIndex = scrollable ? 0 : -1; position.hidden = ! scrollable;
			position.style.setProperty( '--vc-gallery-thumb', Math.min( 100, root.clientWidth / Math.max( 1, root.scrollWidth ) * 100 ) + '%' );
			update();
		}
		position.addEventListener( 'input', function () {
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			root.scrollLeft = Number( position.value ) / 1000 * Math.max( 0, root.scrollWidth - root.clientWidth ) * sign;
			update();
		} );
		root.addEventListener( 'scroll', update, { passive: true } );
		function scroll( amount ) { root.scrollBy( { left: amount, behavior: reduce ? 'auto' : 'smooth' } ); }
		root.addEventListener( 'keydown', function ( event ) {
			if ( event.target !== root ) { return; }
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			var distance = root.clientWidth * .8;
			var amount = event.key === 'ArrowRight' ? distance : event.key === 'ArrowLeft' ? -distance : event.key === 'PageDown' ? distance * sign : event.key === 'PageUp' ? -distance * sign : null;
			if ( event.key === 'Home' || event.key === 'End' ) {
				event.preventDefault(); root.scrollTo( { left: event.key === 'Home' ? 0 : ( root.scrollWidth - root.clientWidth ) * sign, behavior: reduce ? 'auto' : 'smooth' } ); return;
			}
			if ( amount !== null ) { event.preventDefault(); scroll( amount ); }
		} );
		root.addEventListener( 'dragstart', function ( event ) { if ( event.target.matches( '.vc-gallery__img' ) ) { event.preventDefault(); } } );
		root.addEventListener( 'pointerdown', function ( event ) {
			if ( event.pointerType !== 'mouse' || event.button !== 0 || ! event.isPrimary || event.target.closest( '.vc-gallery__caption, button, input, select, textarea, [contenteditable]' ) ) { return; }
			var box = root.getBoundingClientRect();
			if ( root.scrollWidth <= root.clientWidth + 1 || event.clientY >= box.top + root.clientHeight ) { return; }
			pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, left: root.scrollLeft }; dragging = false;
		} );
		root.addEventListener( 'pointermove', function ( event ) {
			if ( ! pointer || pointer.id !== event.pointerId ) { return; }
			if ( event.buttons === 0 ) { end( event ); return; }
			var dx = event.clientX - pointer.x;
			if ( ! dragging && Math.abs( dx ) > 6 && Math.abs( dx ) > Math.abs( event.clientY - pointer.y ) ) {
				dragging = true; root.classList.add( 'is-dragging' ); root.setPointerCapture( event.pointerId );
			}
			if ( dragging ) { event.preventDefault(); root.scrollLeft = pointer.left - dx; }
		} );
		function end( event ) {
			if ( ! pointer || pointer.id !== event.pointerId ) { return; }
			if ( dragging ) { ignoreClickUntil = performance.now() + 300; }
			pointer = null; dragging = false; root.classList.remove( 'is-dragging' );
			if ( root.hasPointerCapture( event.pointerId ) ) { root.releasePointerCapture( event.pointerId ); }
		}
		root.addEventListener( 'pointerup', end ); root.addEventListener( 'pointercancel', end ); root.addEventListener( 'lostpointercapture', end );
		root.addEventListener( 'pointerleave', function () { if ( ! dragging ) { pointer = null; } } );
		root.addEventListener( 'click', function ( event ) { if ( performance.now() < ignoreClickUntil ) { event.preventDefault(); } }, true );
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target.closest && event.target.closest( '[data-module-item]' );
			if ( ! item || item.parentNode !== root ) { return; }
			event.preventDefault();
			var itemBox = item.getBoundingClientRect(), box = root.getBoundingClientRect();
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			scroll( sign < 0 ? itemBox.right - box.right : itemBox.left - box.left );
		} );
		if ( window.ResizeObserver ) {
			var observer = new window.ResizeObserver( measure ); observer.observe( root );
			Array.prototype.forEach.call( root.children, function ( child ) { observer.observe( child ); } );
		} else { window.addEventListener( 'resize', measure ); root.addEventListener( 'load', measure, true ); }
		measure();
	}

	function timeline( root ) {
		if ( root.dataset.autoScroll !== '1' ) { return; }
		var desktop = window.matchMedia ? window.matchMedia( '(min-width: 768px)' ) : { matches: true };
		var paused = false, hovered = false, focused = false, visible = true, interacting = false;
		var frame = null, last = null, direction = 1, resumeTimer = null, cursor = null;
		var button = document.createElement( 'button' );
		button.type = 'button'; button.className = 'vc-timeline__autoscroll';
		if ( root.id ) { button.setAttribute( 'aria-controls', root.id ); }
		root.parentNode.insertBefore( button, root.nextSibling );
		function label() {
			button.textContent = paused ? root.dataset.resumeLabel : root.dataset.pauseLabel;
			button.setAttribute( 'aria-pressed', paused ? 'true' : 'false' );
			button.hidden = ! desktop.matches || reduce || root.scrollWidth <= root.clientWidth;
		}
		function stop() { if ( frame !== null ) { cancelAnimationFrame( frame ); } frame = null; last = null; cursor = null; }
		function available() { return ! paused && ! hovered && ! focused && ! interacting && ! resumeTimer && visible && desktop.matches && ! reduce && ! document.hidden && root.getClientRects().length && root.scrollWidth > root.clientWidth; }
		function step( time ) {
			frame = null;
			if ( ! available() ) { last = null; return; }
			var elapsed = last === null ? 0 : Math.min( 80, time - last ); last = time;
			var sign = window.getComputedStyle( root ).direction === 'rtl' ? -1 : 1;
			var max = root.scrollWidth - root.clientWidth;
			var position = ( cursor === null ? Math.abs( root.scrollLeft ) : cursor ) + direction * elapsed * .028;
			if ( position >= max ) { position = max; direction = -1; }
			if ( position <= 0 ) { position = 0; direction = 1; }
			cursor = position;
			root.scrollLeft = position * sign;
			frame = requestAnimationFrame( step );
		}
		function start() { stop(); label(); if ( available() ) { frame = requestAnimationFrame( step ); } }
		function manualPause() { stop(); clearTimeout( resumeTimer ); resumeTimer = setTimeout( function () { resumeTimer = null; start(); }, 5000 ); }
		button.addEventListener( 'click', function () { paused = ! paused; clearTimeout( resumeTimer ); resumeTimer = null; start(); } );
		root.addEventListener( 'mouseenter', function () { hovered = true; stop(); } );
		root.addEventListener( 'mouseleave', function () { hovered = false; start(); } );
		root.addEventListener( 'focusin', function () { focused = true; stop(); } );
		root.addEventListener( 'focusout', function ( event ) { focused = !! ( event.relatedTarget && root.contains( event.relatedTarget ) ); start(); } );
		root.addEventListener( 'wheel', manualPause, { passive: true } );
		root.addEventListener( 'pointerdown', function () { interacting = true; manualPause(); }, { passive: true } );
		window.addEventListener( 'pointerup', function () { if ( interacting ) { interacting = false; manualPause(); } }, { passive: true } );
		window.addEventListener( 'pointercancel', function () { if ( interacting ) { interacting = false; manualPause(); } }, { passive: true } );
		root.addEventListener( 'keydown', manualPause );
		root.addEventListener( 'vc:select-item', function ( event ) { if ( timelineSelectedItem( root, event ) ) { paused = true; start(); } } );
		document.addEventListener( 'visibilitychange', start );
		window.addEventListener( 'resize', start );
		if ( window.ResizeObserver ) { new window.ResizeObserver( start ).observe( root ); }
		mediaChange( desktop, start ); mediaChange( motionQuery, start );
		if ( window.IntersectionObserver ) {
			new window.IntersectionObserver( function ( entries ) { visible = entries[ 0 ].isIntersecting; start(); } ).observe( root );
		}
		start();
	}

	function timelineCollapse( button ) {
		var list = document.getElementById( button.getAttribute( 'aria-controls' ) );
		if ( ! list || ! list.classList.contains( 'vc-timeline--vertical' ) ) { return; }
		var items = Array.prototype.filter.call( list.children, function ( item ) { return item.classList.contains( 'vc-timeline__item' ); } );
		if ( items.length <= 3 ) { return; }
		var collapsed = true;
		function update() {
			if ( collapsed && items.slice( 3 ).some( function ( item ) { return item.contains( document.activeElement ); } ) ) { button.focus(); }
			items.forEach( function ( item, index ) { item.hidden = collapsed && index >= 3; } );
			list.classList.toggle( 'is-collapsed', collapsed );
			button.textContent = collapsed ? button.dataset.expand : button.dataset.collapse;
			button.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			button.hidden = false;
		}
		button.addEventListener( 'click', function () { collapsed = ! collapsed; update(); } );
		list.addEventListener( 'vc:select-item', function ( event ) {
			var item = timelineSelectedItem( list, event );
			if ( item && collapsed && items.indexOf( item ) >= 3 ) { collapsed = false; update(); }
		} );
		update();
	}

	function timelineSelectedItem( root, event ) {
		var item = event.target && event.target.closest ? event.target.closest( '[data-module-item]' ) : null;
		return item && item.parentNode === root && item.classList.contains( 'vc-timeline__item' ) && event.detail && event.detail.item === item.getAttribute( 'data-module-item' ) ? item : null;
	}

	function timelineSelection( root ) {
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = timelineSelectedItem( root, event );
			if ( ! item || item.hidden || item.inert ) { return; }
			event.preventDefault();
			item.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } );
		} );
	}

	function contact( root ) {
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target && event.target.closest ? event.target.closest( '[data-module-item]' ) : null;
			if ( ! item || item.parentNode !== root || ! item.classList.contains( 'vc-contact__item' ) || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) || item.hidden || item.inert ) { return; }
			event.preventDefault();
			item.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } );
		} );
	}

	function showcase( root ) {
		if ( root.dataset.vcShowcaseReady === '1' ) { return; }
		root.dataset.vcShowcaseReady = '1'; root.classList.add( 'is-enhanced' );
		var mobile = window.matchMedia( '(max-width:767px)' ), hover = window.matchMedia( '(hover: hover) and (pointer: fine)' );
		var preview = root.dataset.hoverPreview === '1', queued = null, activeScene = null;
		var scenes = Array.prototype.filter.call( root.children, function ( node ) { return node.classList.contains( 'vc-showcase__scene' ); } );
		var states = [];
		scenes.forEach( function ( scene ) {
			var first = true;
			Array.prototype.forEach.call( scene.querySelectorAll( '.vc-showcase__spot' ), function ( spot ) {
				var saved = spot.dataset.vcDefaultOpen === '1';
				var state = { item: spot, scene: scene, desktop: saved, mobile: saved && first, pointer: false, focused: false };
				if ( saved ) { first = false; }
				states.push( state );
				function pin( value ) {
					if ( mobile.matches && value ) { states.forEach( function ( other ) { if ( other !== state && other.scene === scene ) { other.mobile = other.pointer = other.focused = false; } } ); }
					state[ mobile.matches ? 'mobile' : 'desktop' ] = value; update();
				}
				spot.querySelector( 'summary' ).addEventListener( 'click', function ( event ) { event.preventDefault(); var value = ! state[ mobile.matches ? 'mobile' : 'desktop' ]; if ( ! value ) { state.focused = false; } pin( value ); } );
				spot.addEventListener( 'mouseenter', function () { if ( preview && hover.matches ) { state.pointer = true; update(); } } );
				spot.addEventListener( 'mouseleave', function () { state.pointer = false; update(); } );
				spot.addEventListener( 'focusin', function () { if ( preview ) { state.focused = true; if ( mobile.matches ) { pin( true ); } else { update(); } } } );
				spot.addEventListener( 'focusout', function ( event ) { state.focused = !! ( preview && event.relatedTarget && spot.contains( event.relatedTarget ) ); update(); } );
				spot.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Escape' ) { event.preventDefault(); spot.querySelector( 'summary' ).focus(); state.pointer = state.focused = false; pin( false ); } } );
			} );
		} );
		function position( state ) {
			var spot = state.item, card = spot.querySelector( '.vc-showcase__card' );
			if ( ! spot.open || ! card || state.scene.dataset.vcShowcaseStatic === '1' || state.scene.classList.contains( 'vc-showcase__scene--static' ) || ! spot.getClientRects().length ) { return; }
			var anchor = spot.querySelector( 'summary' ).getBoundingClientRect(), owner = spot.getBoundingClientRect(), width = document.documentElement.clientWidth, height = window.innerHeight;
			// Sidebar reveal may precede the host's smooth scroll. Keep it open and measure again on scroll.
			if ( anchor.bottom < 0 || anchor.top > height || anchor.right < 0 || anchor.left > width ) { return; }
			card.style.maxWidth = Math.max( 1, Math.min( width - 32, state.scene.clientWidth - 16 ) ) + 'px';
			var box = card.getBoundingClientRect(), gap = 10, x = anchor.left + anchor.width / 2 - box.width / 2, y = anchor.top - gap - box.height;
			var side = card.getAttribute( mobile.matches ? 'data-vc-position-m' : 'data-vc-position-d' ) || 'top-center';
			if ( side === 'right' || side === 'left' ) { x = side === 'right' ? anchor.right + gap : anchor.left - gap - box.width; y = anchor.top + anchor.height / 2 - box.height / 2; }
			else { if ( side.indexOf( 'bottom' ) === 0 ) { y = anchor.bottom + gap; } if ( /-left$/.test( side ) ) { x = anchor.right - box.width; } if ( /-right$/.test( side ) ) { x = anchor.left; } }
			x = Math.max( 16, Math.min( width - box.width - 16, x ) ); y = Math.max( 16, Math.min( height - box.height - 16, y ) );
			card.style.left = ( x - owner.left ) + 'px'; card.style.top = ( y - owner.top ) + 'px'; card.style.right = 'auto'; card.style.bottom = 'auto'; card.style.transform = 'none';
		}
		function update() {
			states.forEach( function ( state ) { state.item.open = !! ( state[ mobile.matches ? 'mobile' : 'desktop' ] || state.pointer || state.focused ); } ); schedule();
		}
		function measure() {
			scenes.forEach( function ( scene ) { var image = scene.querySelector( ':scope > picture' ); scene.classList.toggle( 'vc-showcase__scene--static', !! image && image.getBoundingClientRect().height < 44 ); } );
			states.forEach( position );
			var scene = scenes.find( function ( node ) { return node.classList.contains( 'is-active' ); } );
			var overflow = 0;
			if ( scene ) { var end = scene.getBoundingClientRect().bottom; states.forEach( function ( state ) { if ( state.scene === scene && state.item.open ) { overflow = Math.max( overflow, state.item.querySelector( '.vc-showcase__card' ).getBoundingClientRect().bottom - end ); } } ); }
			root.style.setProperty( '--vc-showcase-tail', Math.ceil( Math.max( 0, overflow ) + 44 ) + 'px' );
			if ( scene && activeScene !== scene ) { activeScene = scene; root.dispatchEvent( new CustomEvent( 'vc:showcase-scene', { bubbles: true, detail: { item: scene.getAttribute( 'data-module-item' ) } } ) ); }
		}
		function schedule() { if ( queued !== null ) { return; } queued = requestAnimationFrame( function () { queued = null; measure(); } ); }
		root.addEventListener( 'keydown', function ( event ) { if ( event.target.closest( '.vc-showcase__card' ) && /^(ArrowLeft|ArrowRight|Home|End)$/.test( event.key ) ) { event.stopPropagation(); } }, true );
		root.addEventListener( 'vc:select-item', function ( event ) {
			var target = event.target && event.target.closest ? event.target.closest( '.vc-showcase__spot, .vc-showcase__empty-spot' ) : null;
			if ( ! target || ! event.detail || event.detail.item !== target.getAttribute( 'data-module-item' ) ) { return; }
			var scene = target.closest( '.vc-showcase__scene' );
			if ( scenes.indexOf( scene ) === -1 || target.parentNode !== scene ) { return; }
			if ( target.classList.contains( 'vc-showcase__empty-spot' ) ) {
				event.preventDefault();
				scene.dispatchEvent( new CustomEvent( 'vc:select-item', { bubbles: true, cancelable: true, detail: { item: scene.getAttribute( 'data-module-item' ) } } ) );
				return;
			}
			var state = states.find( function ( row ) { return row.item === target; } );
			if ( ! state ) { return; }
			event.preventDefault();
			state.scene.dispatchEvent( new CustomEvent( 'vc:select-item', { bubbles: true, cancelable: true, detail: { item: state.scene.getAttribute( 'data-module-item' ) } } ) );
			if ( mobile.matches ) { states.forEach( function ( row ) { if ( row.scene === state.scene ) { row.mobile = row.pointer = row.focused = false; } } ); }
			state[ mobile.matches ? 'mobile' : 'desktop' ] = true; update();
		} );
		mediaChange( mobile, function () { states.forEach( function ( state ) { state.pointer = state.focused = false; } ); update(); } );
		mediaChange( hover, function () { if ( ! hover.matches ) { states.forEach( function ( state ) { state.pointer = false; } ); update(); } } );
		window.addEventListener( 'scroll', schedule, true ); window.addEventListener( 'resize', schedule );
		root.querySelectorAll( 'img' ).forEach( function ( image ) { image.addEventListener( 'load', schedule ); } );
		new MutationObserver( schedule ).observe( root, { subtree: true, attributes: true, attributeFilter: [ 'class', 'aria-hidden' ] } );
		update();
	}

	function buttonEffect( button ) {
		// Theme templates own their wrappers; either script may run first without claiming the other.
		if ( button.dataset.vcEffectOwner || button.closest( '.vc-content-rich, [data-vc-button-effect], .vc-m--editor' ) ) { return; }
		var types = [ 'bounce', 'rubberband', 'shake', 'swing', 'wobble', 'pulse', 'shine' ];
		if ( ! types.some( function ( type ) { return button.classList.contains( 'vc-btn--' + type ); } ) ) { return; }
		button.dataset.vcEffectOwner = 'basic';
		var active = null, waiting = null, hovered = false, focused = false, inView = true, suspended = false;
		function stop() { clearTimeout( active ); clearTimeout( waiting ); button.classList.remove( 'is-effect-active' ); }
		function allowed() {
			if ( reduce || document.hidden || suspended || hovered || focused || ! inView || button.isConnected === false || button.disabled || button.getAttribute( 'aria-disabled' ) === 'true' ) { return false; }
			var rect = button.getBoundingClientRect(), style = window.getComputedStyle( button );
			return !! ( rect.width && rect.height && rect.bottom > 0 && rect.top < window.innerHeight && rect.right > 0 && rect.left < window.innerWidth && style.visibility !== 'hidden' && style.display !== 'none' );
		}
		function run() {
			stop();
			if ( ! allowed() ) { return; }
			var style = window.getComputedStyle( button );
			var duration = Math.max( 0, Math.min( 5000, parseFloat( style.getPropertyValue( '--vc-btn-duration' ) ) || 0 ) );
			var interval = Math.max( 0, Math.min( 10000, parseFloat( style.getPropertyValue( '--vc-btn-interval' ) ) || 0 ) );
			if ( duration <= 0 ) { return; }
			// Flush the idle style so an explicitly zero interval still starts a fresh CSS animation.
			button.getBoundingClientRect();
			button.classList.add( 'is-effect-active' );
			active = setTimeout( function () { button.classList.remove( 'is-effect-active' ); if ( allowed() ) { waiting = setTimeout( run, interval ); } }, duration );
		}
		button.addEventListener( 'mouseenter', function () { hovered = true; stop(); } );
		button.addEventListener( 'mouseleave', function () { hovered = false; run(); } );
		button.addEventListener( 'focusin', function () { focused = true; stop(); } );
		button.addEventListener( 'focusout', function ( event ) { focused = !! ( event.relatedTarget && button.contains( event.relatedTarget ) ); run(); } );
		document.addEventListener( 'visibilitychange', run );
		window.addEventListener( 'resize', run, { passive: true } );
		window.addEventListener( 'scroll', run, { passive: true } );
		window.addEventListener( 'pagehide', function () { suspended = true; stop(); } );
		window.addEventListener( 'pageshow', function () { suspended = false; run(); } );
		mediaChange( motionQuery, run );
		if ( window.IntersectionObserver ) {
			new window.IntersectionObserver( function ( entries ) { if ( entries.length ) { inView = entries[ 0 ].isIntersecting; run(); } } ).observe( button );
		}
		run();
	}

	function embeddedVideo( root ) {
		var frame = root.querySelector( 'iframe' ), cover = root.querySelector( '.vc-video__cover' );
		if ( ! frame ) { return; }
		function load( play ) {
			var source = play ? root.dataset.vcVideoPlay : root.dataset.vcVideoSrc;
			if ( frame.getAttribute( 'src' ) !== source ) { frame.setAttribute( 'src', source ); }
			if ( cover ) { cover.hidden = play; }
		}
		function update() {
			if ( reduce || document.hidden ) { load( false ); }
			else if ( root.dataset.vcAutoplay === '1' && root.getClientRects().length ) { load( true ); }
			else if ( frame.getAttribute( 'src' ) === 'about:blank' ) { load( false ); }
		}
		if ( cover ) { cover.addEventListener( 'click', function () { load( true ); } ); }
		mediaChange( motionQuery, update );
		document.addEventListener( 'visibilitychange', update );
		window.addEventListener( 'resize', update );
		update();
	}

	function fileVideo( video ) {
		function update() {
			if ( reduce || document.hidden || ! video.getClientRects().length ) { video.pause(); return; }
			var playing = video.play();
			if ( playing && playing.catch ) { playing.catch( function () {} ); }
		}
		mediaChange( motionQuery, update );
		document.addEventListener( 'visibilitychange', update );
		window.addEventListener( 'resize', update );
		update();
	}

	function manualVideoText( root ) {
		if ( root.dataset.vcVideoTextReady === '1' ) { return; }
		var video = root.querySelector( 'video' ), button = root.querySelector( '.vc-video-text__toggle' );
		var label = root.querySelector( '.vc-video-text__label' ), fallback = root.querySelector( '.vc-video-text__fallback' ), status = root.querySelector( '.vc-video-text__status' );
		if ( ! video || ! button || ! label ) { return; }
		root.dataset.vcVideoTextReady = '1';
		var pending = false;
		function idle() { pending = false; button.removeAttribute( 'aria-disabled' ); button.removeAttribute( 'aria-busy' ); }
		function sync() {
			var playing = ! video.paused && ! video.ended;
			var text = playing ? button.dataset.pauseLabel : button.dataset.playLabel;
			button.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
			button.setAttribute( 'aria-label', text ); label.textContent = text;
		}
		function failed() {
			video.pause(); idle(); sync();
			if ( fallback ) { fallback.hidden = false; }
			if ( status ) { status.textContent = status.dataset.errorLabel; status.hidden = false; }
		}
		button.addEventListener( 'click', function () {
			if ( pending ) { return; }
			if ( ! video.paused && ! video.ended ) { video.pause(); return; }
			if ( status ) { status.hidden = true; status.textContent = ''; }
			if ( fallback ) { fallback.hidden = true; }
			pending = true; button.setAttribute( 'aria-disabled', 'true' ); button.setAttribute( 'aria-busy', 'true' );
			label.textContent = button.dataset.loadingLabel; button.setAttribute( 'aria-label', button.dataset.loadingLabel );
			try {
				var result = video.play();
				if ( result && result.then ) { result.then( function () { idle(); sync(); } ).catch( failed ); }
				else { idle(); sync(); }
			} catch ( error ) { failed(); }
		} );
		video.addEventListener( 'playing', function () { idle(); sync(); if ( fallback ) { fallback.hidden = true; } if ( status ) { status.hidden = true; } } );
		video.addEventListener( 'pause', function () { idle(); sync(); } );
		video.addEventListener( 'ended', function () { idle(); sync(); } );
		video.addEventListener( 'error', failed );
		button.hidden = false;
		if ( fallback ) { fallback.hidden = true; }
		sync();
		if ( video.error ) { failed(); }
	}

	function compare( root ) {
		var range = root.querySelector( '.vc-compare__range' );
		if ( ! range ) { return; }
		function sync() { root.style.setProperty( '--vc-pos', range.value + '%' ); }
		range.addEventListener( 'input', sync );
		range.disabled = false;
		root.classList.add( 'is-interactive' );
		sync();
		// The physical left/right image split stays the same in RTL documents.
		// The native range remains the sole focus and keyboard control.
		if ( ! window.PointerEvent ) { return; }
		var pointer = null;
		function position( event ) {
			var rect = range.getBoundingClientRect();
			if ( ! rect.width ) { return; }
			range.value = String( Math.round( Math.max( 0, Math.min( 100, ( event.clientX - rect.left ) / rect.width * 100 ) ) ) );
			range.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		function release() {
			var current = pointer;
			pointer = null;
			if ( current !== null && range.hasPointerCapture && range.hasPointerCapture( current ) ) { range.releasePointerCapture( current ); }
		}
		range.addEventListener( 'pointerdown', function ( event ) {
			if ( event.isPrimary === false || event.button !== 0 ) { return; }
			event.preventDefault();
			pointer = event.pointerId;
			range.focus( { preventScroll: true } );
			if ( range.setPointerCapture ) { try { range.setPointerCapture( pointer ); } catch ( captureUnavailable ) {} }
			position( event );
		} );
		range.addEventListener( 'pointermove', function ( event ) { if ( pointer === event.pointerId ) { position( event ); } } );
		range.addEventListener( 'pointerup', function ( event ) {
			if ( pointer !== event.pointerId ) { return; }
			position( event ); release(); range.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		range.addEventListener( 'pointercancel', function ( event ) { if ( pointer === event.pointerId ) { release(); } } );
		range.addEventListener( 'lostpointercapture', function ( event ) { if ( pointer === event.pointerId ) { pointer = null; } } );
		range.addEventListener( 'blur', release );
	}

	function hotspots( root ) {
		var plus = root.classList.contains( 'vc-hotspots--plus' );
		var spots = Array.prototype.filter.call( root.children, function ( node ) { return node.tagName === 'DETAILS' && node.classList.contains( 'vc-hotspot' ); } );
		var mobile = window.matchMedia ? window.matchMedia( '(max-width: 767px)' ) : { matches: false };
		var dialog = root.querySelector( '.vc-hotspots__dialog' );
		var card = dialog && dialog.querySelector( '.vc-hotspots__dialog-card' );
		var closeButton = dialog && dialog.querySelector( '.vc-hotspots__dialog-close' );
		var canDialog = !! ( dialog && card && closeButton && typeof dialog.showModal === 'function' );
		var active = null, sidebar = null, pendingSidebar = null, queued = null;
		function shown() { return root.isConnected && root.getClientRects().length && root.clientWidth > 0; }
		function isSpot( node ) { return node && spots.indexOf( node ) !== -1; }
		function summary( item ) { return item.querySelector( 'summary' ); }
		function popup( item ) { return item.querySelector( '.vc-hotspot__pop' ); }
		function restorePopup( previous ) {
			if ( previous.marker.parentNode ) { previous.marker.parentNode.insertBefore( previous.popup, previous.marker ); previous.marker.parentNode.removeChild( previous.marker ); }
			if ( previous.style === null ) { previous.popup.removeAttribute( 'style' ); } else { previous.popup.setAttribute( 'style', previous.style ); }
			previous.item.open = false;
		}
		function closeModal( restoreFocus ) {
			if ( ! active ) { return; }
			var previous = active;
			active = null;
			if ( dialog.open ) { dialog.close(); }
			restorePopup( previous );
			if ( restoreFocus && previous.summary.isConnected && previous.summary.getClientRects().length ) { previous.summary.focus( { preventScroll: true } ); }
		}
		function openModal( item, focus ) {
			if ( ! canDialog || ! shown() || active && active.item === item ) { return; }
			if ( active ) { var previous = active; active = null; restorePopup( previous ); }
			var content = popup( item ), trigger = summary( item );
			if ( ! content || ! trigger ) { return; }
			var marker = root.ownerDocument.createComment( 'hotspot-popup' );
			active = { item: item, popup: content, summary: trigger, marker: marker, style: content.getAttribute( 'style' ) };
			content.parentNode.insertBefore( marker, content );
			card.appendChild( content );
			content.removeAttribute( 'style' );
			dialog.setAttribute( 'aria-label', trigger.getAttribute( 'aria-label' ) || 'Hotspot details' );
			item.open = true;
			if ( ! dialog.open ) { try { dialog.showModal(); } catch ( unavailable ) { closeModal( focus !== false ); return; } }
			if ( focus !== false ) { closeButton.focus( { preventScroll: true } ); }
		}
		function positionPopup( item, inline ) {
			if ( ! plus || mobile.matches && ! inline || ! root.classList.contains( 'is-enhanced' ) || ! item.open || ! shown() ) { return; }
			var content = popup( item ), trigger = summary( item );
			if ( ! content || ! trigger ) { return; }
			var anchor = trigger.getBoundingClientRect(), owner = item.getBoundingClientRect();
			var width = root.ownerDocument.documentElement.clientWidth || window.innerWidth, height = window.innerHeight;
			if ( anchor.bottom < 0 || anchor.top > height || anchor.right < 0 || anchor.left > width ) {
				// The editor may still be scrolling this newly selected point into view.
				if ( pendingSidebar !== item ) { item.open = false; }
				return;
			}
			if ( pendingSidebar === item ) { pendingSidebar = null; }
			content.style.maxWidth = Math.max( 1, width - 32 ) + 'px';
			var size = content.getBoundingClientRect(), gap = 12, edge = 16;
			var left = anchor.left - gap - size.width, right = anchor.right + gap;
			var prefersRight = content.getAttribute( 'data-vc-popup-side' ) === 'right';
			var x = prefersRight ? right : left;
			if ( x < edge && right + size.width <= width - edge ) { x = right; }
			else if ( x + size.width > width - edge && left >= edge ) { x = left; }
			x = Math.max( edge, Math.min( width - size.width - edge, x ) );
			function boundY( value ) { return Math.max( edge, Math.min( height - size.height - edge, value ) ); }
			var y = boundY( anchor.top + anchor.height / 2 - size.height / 2 );
			var markers = spots.filter( function ( other ) { return other !== item && other.getClientRects().length; } ).map( function ( other ) { return summary( other ).getBoundingClientRect(); } );
			var candidates = [ y ];
			markers.forEach( function ( marker ) { if ( marker.right > x && marker.left < x + size.width ) { candidates.push( boundY( marker.top - gap - size.height ), boundY( marker.bottom + gap ) ); } } );
			var score = Infinity, preferredY = y;
			candidates.forEach( function ( candidate ) {
				var covered = markers.filter( function ( marker ) { return marker.right > x && marker.left < x + size.width && marker.bottom > candidate && marker.top < candidate + size.height; } ).length;
				var next = covered * 10000 + Math.abs( candidate - preferredY );
				if ( next < score ) { score = next; y = candidate; }
			} );
			content.style.left = ( x - owner.left ) + 'px'; content.style.right = 'auto';
			content.style.top = ( y - owner.top ) + 'px'; content.style.bottom = 'auto'; content.style.transform = 'none';
		}
		function schedule() {
			if ( queued !== null ) { return; }
			queued = window.requestAnimationFrame( function () { queued = null; if ( shown() ) { spots.forEach( function ( item ) { positionPopup( item, sidebar === item ); } ); } } );
		}
		function mode() {
			closeModal( root.ownerDocument.hasFocus() ); sidebar = null; pendingSidebar = null;
			if ( plus ) { spots.forEach( function ( item ) { item.open = false; } ); root.classList.toggle( 'is-enhanced', ! mobile.matches || canDialog ); }
		}
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target && event.target.closest ? event.target.closest( '.vc-hotspot[data-module-item]' ) : null;
			if ( ! isSpot( item ) || ! shown() || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) ) { return; }
			event.preventDefault();
			if ( sidebar && sidebar !== item && mobile.matches ) { sidebar.open = false; }
			sidebar = item; pendingSidebar = plus ? item : null;
			item.open = true; item.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } );
			if ( active && plus && mobile.matches && canDialog ) { pendingSidebar = null; openModal( item, false ); } else { positionPopup( item, true ); }
		} );
		root.addEventListener( 'click', function ( event ) {
			var trigger = event.target && event.target.closest ? event.target.closest( 'summary' ) : null;
			var item = trigger && trigger.parentNode;
			if ( ! isSpot( item ) || ! plus || ! mobile.matches || ! canDialog ) { return; }
			event.preventDefault();
			if ( sidebar && sidebar !== item ) { sidebar.open = false; }
			sidebar = null; pendingSidebar = null; item.open = true; openModal( item, true );
		} );
		root.addEventListener( 'toggle', function ( event ) {
			var item = event.target;
			if ( ! isSpot( item ) || ! plus ) { return; }
			if ( ! item.open ) { if ( pendingSidebar === item ) { pendingSidebar = null; } if ( active && active.item === item ) { closeModal( false ); } return; }
			if ( mobile.matches && canDialog && sidebar !== item ) { openModal( item, true ); } else { positionPopup( item, sidebar === item ); }
		}, true );
		root.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' || active || event.defaultPrevented ) { return; }
			var item = event.target && event.target.closest ? event.target.closest( '.vc-hotspot' ) : null;
			if ( isSpot( item ) && item.open ) { event.preventDefault(); event.stopPropagation(); item.open = false; summary( item ).focus( { preventScroll: true } ); }
		} );
		if ( ! plus ) { return; }
		if ( canDialog ) {
			closeButton.addEventListener( 'click', function () { closeModal( true ); } );
			dialog.addEventListener( 'cancel', function ( event ) { event.preventDefault(); event.stopPropagation(); closeModal( true ); } );
			dialog.addEventListener( 'close', function () { if ( ! dialog.open ) { closeModal( root.ownerDocument.hasFocus() ); } } );
			dialog.addEventListener( 'click', function ( event ) { var r = dialog.getBoundingClientRect(); if ( event.target === dialog && ( event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom ) ) { closeModal( true ); } } );
		}
		document.addEventListener( 'pointerdown', function ( event ) { if ( root.isConnected && ! mobile.matches && ! root.contains( event.target ) ) { spots.forEach( function ( item ) { item.open = false; } ); } } );
		mediaChange( mobile, mode ); mode();
		window.addEventListener( 'resize', schedule, { passive: true } );
		window.addEventListener( 'scroll', schedule, { passive: true } );
		window.addEventListener( 'pagehide', function () { pendingSidebar = null; closeModal( false ); if ( queued !== null ) { window.cancelAnimationFrame( queued ); queued = null; } } );
	}

	function toggle( button, target, openClass ) {
		button.addEventListener( 'click', function () {
			var open = target.classList.toggle( openClass );
			button.textContent = open ? button.dataset.collapse : button.dataset.expand;
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}

	function counter( el ) {
		var exact = el.hasAttribute( 'data-count-precision' );
		var target = exact ? Number( el.dataset.count ) : ( parseInt( el.dataset.count, 10 ) || 0 );
		if ( ! Number.isFinite( target ) || reduce || ( exact ? target === 0 : target <= 0 ) ) {
			return;
		}
		var start = null;
		var finalText = el.textContent;
		var precision = exact ? Math.max( 0, Math.min( 6, Number( el.dataset.countPrecision ) || 0 ) ) : 0;
		var fmt = exact ? new Intl.NumberFormat( undefined, { minimumFractionDigits: precision, maximumFractionDigits: precision } ) : new Intl.NumberFormat();
		function step( t ) {
			if ( exact && reduce ) { el.textContent = finalText; return; }
			if ( start === null ) {
				start = t;
			}
			var p = Math.min( 1, ( t - start ) / 1200 );
			var value = target * ( 1 - Math.pow( 1 - p, 3 ) );
			el.textContent = exact ? ( p === 1 ? finalText : fmt.format( value ) ) : fmt.format( Math.round( value ) );
			if ( p < 1 ) {
				requestAnimationFrame( step );
			}
		}
		requestAnimationFrame( step );
	}

	/** Full values remain keyboard-scrollable when an intentionally large number or column count needs it. */
	function counterOverflow( root ) {
		function measure() { root.tabIndex = root.scrollWidth > root.clientWidth + 1 ? 0 : -1; }
		if ( window.ResizeObserver ) {
			var observer = new window.ResizeObserver( measure ); observer.observe( root );
			Array.prototype.forEach.call( root.children, function ( child ) { observer.observe( child ); } );
		} else { window.addEventListener( 'resize', measure ); }
		measure();
	}

	/** One accessible text list; nonsequential decorative copies keep real pointer links. */
	function marquee( root ) {
		if ( root.dataset.vcMarqueeReady === '1' ) { return; }
		root.dataset.vcMarqueeReady = '1';
		var viewport = root.querySelector( '.vc-marquee__viewport' );
		var track = root.querySelector( '.vc-marquee__track' );
		var source = track && track.querySelector( '.vc-marquee__group' );
		var button = root.querySelector( '.vc-marquee__toggle' );
		if ( ! viewport || ! source || ! button ) { return; }
		var items = Array.prototype.slice.call( source.children );
		var manual = false, reading = false, editorSelected = false, hovered = false, selectedItem = null;
		var measuredWidth = -1, queued = null;
		function visible() { return root.getClientRects().length > 0 && viewport.clientWidth > 0; }
		function update() {
			root.classList.toggle( 'is-reading', manual || reading || editorSelected );
			root.classList.toggle( 'is-paused', manual || reading || editorSelected || reduce || document.hidden || ( hovered && root.classList.contains( 'vc-marquee--pause' ) ) );
			button.setAttribute( 'aria-pressed', manual || editorSelected ? 'true' : 'false' );
			button.textContent = manual || editorSelected ? root.dataset.resumeLabel : root.dataset.pauseLabel;
		}
		function copy( node ) {
			var clone = node.cloneNode( true );
			clone.setAttribute( 'data-vc-marquee-copy', '' );
			clone.setAttribute( 'aria-hidden', 'true' ); clone.inert = false;
			[ clone ].concat( Array.prototype.slice.call( clone.querySelectorAll( '*' ) ) ).forEach( function ( element ) {
				element.removeAttribute( 'id' ); element.removeAttribute( 'data-module-item' );
				if ( element.matches( 'a,button,input,select,textarea,[tabindex],[contenteditable]' ) ) { element.tabIndex = -1; }
			} );
			clone.addEventListener( 'mousedown', function ( event ) {
				var link = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;
				if ( event.button === 0 && link && clone.contains( link ) ) { event.preventDefault(); }
			} );
			return clone;
		}
		function measure() {
			if ( ! visible() ) { measuredWidth = -1; return; }
			var focused = originalItem( document.activeElement ) ? document.activeElement : null;
			function finish() {
				update();
				if ( focused && originalItem( focused ) && document.activeElement === focused && focused.getClientRects().length ) { reveal( originalItem( focused ), focused ); }
				else if ( editorSelected && selectedItem && originalItem( selectedItem ) ) { reveal( selectedItem ); }
			}
			measuredWidth = viewport.clientWidth;
			source.dir = window.getComputedStyle( root ).direction;
			Array.prototype.forEach.call( track.querySelectorAll( '[data-vc-marquee-copy]' ), function ( node ) { node.remove(); } );
			root.classList.remove( 'is-enhanced', 'is-reading' );
			viewport.scrollLeft = 0; viewport.tabIndex = -1; button.hidden = true;
			if ( reduce || ! items.length ) { finish(); return; }
			root.classList.add( 'is-enhanced' );
			var width = source.getBoundingClientRect().width;
			var count = width > 0 ? Math.ceil( viewport.clientWidth / width ) : 0;
			// Abnormal zero-size or enormous rails remain readable instead of creating unbounded DOM.
			if ( ! count || count > 128 ) { root.classList.remove( 'is-enhanced' ); finish(); return; }
			// Move one original sequence per configured loop, regardless of how wide the viewport is.
			for ( var n = 0; n < count; n++ ) { track.appendChild( copy( source ) ); }
			root.style.setProperty( '--vc-marquee-distance', width + 'px' );
			button.hidden = false;
			finish();
		}
		function schedule() {
			if ( queued !== null ) { return; }
			queued = requestAnimationFrame( function () { queued = null; measure(); } );
		}
		function originalItem( target ) {
			var item = target && target.closest ? target.closest( '[data-module-item]' ) : null;
			return item && item.parentNode === source && items.indexOf( item ) >= 0 && item.isConnected && item.getClientRects().length && ! item.hidden && ! item.inert ? item : null;
		}
		function reveal( item, focused ) {
			if ( ! visible() || ! originalItem( item ) ) { return; }
			update();
			viewport.tabIndex = viewport.scrollWidth > viewport.clientWidth + 1 ? 0 : -1;
			var target = focused && item.contains( focused ) ? focused : item;
			target.scrollIntoView( { block: 'nearest', inline: 'nearest', behavior: 'auto' } );
		}
		button.addEventListener( 'click', function () {
			if ( editorSelected ) { editorSelected = false; selectedItem = null; manual = false; }
			else { manual = ! manual; }
			if ( ! manual && ! reading ) { viewport.scrollLeft = 0; viewport.tabIndex = -1; }
			update();
		} );
		root.addEventListener( 'mouseenter', function () { hovered = true; update(); } );
		root.addEventListener( 'mouseleave', function () { hovered = false; update(); } );
		root.addEventListener( 'focusin', function ( event ) {
			var item = originalItem( event.target );
			if ( ! item ) { return; }
			reading = true; reveal( item, event.target );
		} );
		root.addEventListener( 'focusout', function ( event ) {
			if ( originalItem( event.relatedTarget ) ) { return; }
			reading = false;
			if ( ! editorSelected ) { viewport.scrollLeft = 0; viewport.tabIndex = -1; }
			update();
		} );
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = originalItem( event.target );
			if ( ! item || ! visible() || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) ) { return; }
			event.preventDefault(); editorSelected = true; selectedItem = item; reveal( item );
		} );
		document.addEventListener( 'visibilitychange', update );
		mediaChange( motionQuery, measure );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( function () {
				if ( ! visible() ) { measuredWidth = -1; return; }
				if ( viewport.clientWidth !== measuredWidth ) { schedule(); }
			} ).observe( viewport );
		} else { window.addEventListener( 'resize', schedule ); }
		if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( schedule ); }
		measure();
	}

	/** Sidebar selection reveals a static FAQ row without hiding its siblings or taking editor focus. */
	function faqSelection( root ) {
		root.addEventListener( 'vc:select-item', function ( event ) {
			var item = event.target.closest && event.target.closest( '[data-module-item]' );
			if ( ! item || ! root.contains( item ) || ! event.detail || event.detail.item !== item.getAttribute( 'data-module-item' ) || ! item.getClientRects().length ) { return; }
			event.preventDefault();
			if ( item.tagName === 'DETAILS' ) { item.open = true; }
		} );
	}

	function init() {
		each( '.vc-m--info-list ul.vc-info', infoList );
		each( '.vc-m--logo-list .vc-logos', logoList );
		each( '.vc-m .vc-carousel', carousel );
		each( '.vc-m [data-vc-card-grid]', cardGrid );
		each( '.vc-m [data-vc-card-carousel]', cardCarousel );
		each( '.vc-m [data-vc-slogan-carousel]', sloganCarousel );
		each( '.vc-m [data-vc-gallery-rail]', galleryRail );
		each( '.vc-m [data-vc-counter-overflow]', counterOverflow );
		each( '.vc-m [data-vc-contact-list]', contact );
		each( '.vc-m [data-vc-marquee]', marquee );
		each( '.vc-m .vc-timeline--horizontal', timeline );
		each( '.vc-m .vc-showcase', showcase );
		each( '.vc-m .vc-btn', buttonEffect );
		each( '.vc-m [data-vc-video-src]', embeddedVideo );
		each( '.vc-m video[data-vc-video-autoplay]', fileVideo );
		each( '.vc-m [data-vc-video-text-manual]', manualVideoText );
		each( '.vc-m .vc-compare', compare );
		each( '.vc-m .vc-hotspots', hotspots );
		each( '.vc-m--faq', faqSelection );
		each( '.vc-m--text-block .vc-textblock', textBlock );
		each( '.vc-m .vc-timeline__toggle', timelineCollapse );
		each( '.vc-m .vc-timeline', timelineSelection );

		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		var io = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( e ) {
					if ( ! e.isIntersecting ) {
						return;
					}
					io.unobserve( e.target );
					if ( e.target.hasAttribute( 'data-count' ) ) {
						counter( e.target );
					} else {
						e.target.classList.add( 'is-in' );
					}
				} );
			},
			{ rootMargin: '0px 0px -10% 0px' }
		);
		each( '.vc-m [data-count]', function ( n ) {
			io.observe( n );
		} );
		if ( ! reduce ) {
			each( '.vc-m .vc-reveal', function ( list ) {
				list.classList.add( 'is-pending' );
				Array.prototype.forEach.call( list.children, function ( c ) {
					io.observe( c );
				} );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
