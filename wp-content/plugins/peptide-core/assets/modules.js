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
			if ( root.dataset.autoplay === '1' && ! reduce && ! hovered && ! focused && ! document.hidden ) {
				timer = setInterval( function () {
					if ( root.getClientRects().length ) { go( index + 1 ); }
				}, Math.max( 1000, parseInt( root.dataset.interval, 10 ) || 3000 ) );
			}
		}
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
		if ( root.dataset.hoverPreview !== '1' ) { return; }
		var hover = window.matchMedia ? window.matchMedia( '(hover: hover) and (pointer: fine)' ) : { matches: false };
		Array.prototype.forEach.call( root.querySelectorAll( '.vc-hotspot' ), function ( spot ) {
			var pinned = spot.open, pointer = false, focused = false;
			function update() { spot.open = pinned || pointer || focused; }
			spot.addEventListener( 'mouseenter', function () { if ( hover.matches ) { pointer = true; update(); } } );
			spot.addEventListener( 'mouseleave', function () { pointer = false; update(); } );
			spot.addEventListener( 'focusin', function () { focused = true; update(); } );
			spot.addEventListener( 'focusout', function ( event ) { focused = !! ( event.relatedTarget && spot.contains( event.relatedTarget ) ); update(); } );
			var summary = spot.querySelector( 'summary' );
			if ( summary ) { summary.addEventListener( 'click', function ( event ) { event.preventDefault(); pinned = ! pinned; update(); } ); }
			spot.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Escape' ) { if ( summary ) { summary.focus(); } pinned = pointer = focused = false; update(); } } );
			mediaChange( hover, function () { if ( ! hover.matches ) { pointer = false; update(); } } );
		} );
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
		if ( range ) {
			range.addEventListener( 'input', function () {
				root.style.setProperty( '--vc-pos', range.value + '%' );
			} );
		}
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

	function init() {
		each( '.vc-m .vc-carousel', carousel );
		each( '.vc-m [data-vc-card-grid]', cardGrid );
		each( '.vc-m [data-vc-card-carousel]', cardCarousel );
		each( '.vc-m [data-vc-slogan-carousel]', sloganCarousel );
		each( '.vc-m [data-vc-gallery-rail]', galleryRail );
		each( '.vc-m [data-vc-counter-overflow]', counterOverflow );
		each( '.vc-m [data-vc-contact-list]', contact );
		each( '.vc-m .vc-timeline--horizontal', timeline );
		each( '.vc-m .vc-showcase', showcase );
		each( '.vc-m .vc-btn', buttonEffect );
		each( '.vc-m [data-vc-video-src]', embeddedVideo );
		each( '.vc-m video[data-vc-video-autoplay]', fileVideo );
		each( '.vc-m [data-vc-video-text-manual]', manualVideoText );
		each( '.vc-m .vc-compare', compare );
		each( '.vc-m .vc-textblock__toggle', function ( b ) {
			toggle( b, b.closest( '.vc-textblock' ), 'is-open' );
		} );
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
