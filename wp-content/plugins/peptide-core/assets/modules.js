/**
 * 装修模块默认外观的前台交互（EDIT-BLOCKS-03）：轮播自动播放和圆点、对比图拖动、
 * “阅读更多”展开、时间轴折叠、数字增长、滚动渐显。主题提供自己的模块模板时可以不使用。
 * 遵守系统的“减少动态效果”设置。
 */
( function () {
	'use strict';

	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

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
		function go( i ) {
			index = ( i + slides.length ) % slides.length;
			slides.forEach( function ( s, n ) {
				s.classList.toggle( 'is-active', n === index );
				s.setAttribute( 'aria-hidden', n === index ? 'false' : 'true' );
			} );
			if ( dots ) {
				Array.prototype.forEach.call( dots.children, function ( d, n ) {
					d.classList.toggle( 'is-active', n === index );
				} );
			}
		}
		function restart() {
			if ( timer ) {
				clearInterval( timer );
			}
			if ( root.dataset.autoplay === '1' && ! reduce ) {
				timer = setInterval( function () {
					go( index + 1 );
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
			if ( timer ) {
				clearInterval( timer );
			}
		} );
		root.addEventListener( 'mouseleave', restart );
		root.addEventListener( 'focusin', function () {
			if ( timer ) {
				clearInterval( timer );
			}
		} );
		go( 0 );
		restart();
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
		var target = parseInt( el.dataset.count, 10 ) || 0;
		if ( reduce || target <= 0 ) {
			return;
		}
		var start = null;
		var fmt = new Intl.NumberFormat();
		function step( t ) {
			if ( start === null ) {
				start = t;
			}
			var p = Math.min( 1, ( t - start ) / 1200 );
			el.textContent = fmt.format( Math.round( target * ( 1 - Math.pow( 1 - p, 3 ) ) ) );
			if ( p < 1 ) {
				requestAnimationFrame( step );
			}
		}
		requestAnimationFrame( step );
	}

	function init() {
		each( '.vc-m .vc-carousel', carousel );
		each( '.vc-m .vc-compare', compare );
		each( '.vc-m .vc-textblock__toggle', function ( b ) {
			toggle( b, b.closest( '.vc-textblock' ), 'is-open' );
		} );
		each( '.vc-m .vc-timeline__toggle', function ( b ) {
			var list = b.parentNode.querySelector( '.vc-timeline' );
			if ( list ) {
				list.classList.add( 'is-collapsed' );
				b.addEventListener( 'click', function () {
					var collapsed = list.classList.toggle( 'is-collapsed' );
					b.textContent = collapsed ? b.dataset.expand : b.dataset.collapse;
				} );
			}
		} );

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
