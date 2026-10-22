/**
 * 后台“访客数据”（VISITOR-ANALYTICS-TEST-01）：实时访客、访客详情 / 时间线、转化统计。
 * 数据：/peptide/v1/track/admin/*（wp.apiFetch 自动带 nonce）。所有文字都经过 textContent / 转义，不拼接未转义的 HTML。
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! cfg ) {
		return;
	}
	var S = cfg.strings;
	var root = document.getElementById( 'pc-trk-app' );
	if ( ! root ) {
		return;
	}

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function fmt( tpl, v ) {
		return tpl.replace( /%[sd]/, v );
	}
	function ago( ms ) {
		var s = Math.max( 0, Math.round( ms / 1000 ) );
		if ( s < 60 ) {
			return fmt( S.seconds, s );
		}
		if ( s < 3600 ) {
			return fmt( S.minutes, Math.floor( s / 60 ) );
		}
		return fmt( S.hours, Math.floor( s / 3600 ) );
	}
	function clock( ms, withDate ) {
		var d = new Date( ms );
		var p = function ( n, w ) {
			return String( n ).padStart( w || 2, '0' );
		};
		var t = p( d.getHours() ) + ':' + p( d.getMinutes() ) + ':' + p( d.getSeconds() );
		return withDate ? d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() ) + ' ' + t : t + '.' + p( d.getMilliseconds(), 3 );
	}
	function pct( a, b ) {
		return b ? Math.round( ( a / b ) * 1000 ) / 10 + '%' : '—';
	}
	function dot( status ) {
		return '<span class="pc-trk-dot is-' + esc( status ) + '"></span>' + esc( S[ status ] || status );
	}
	function pageText( t ) {
		var type = S.pageTypes[ t.page_type ] || t.page_type || '';
		var name = t.product_name ? ' · ' + t.product_name : '';
		return '<strong>' + esc( type + name ) + '</strong> <code>' + esc( t.path ) + '</code>';
	}
	function videoText( v ) {
		if ( ! v ) {
			return '';
		}
		return '<div class="pc-trk-video">▶ ' + esc( S.video ) + ' ' + esc( v.video_id || '' ) + ': ' + esc( v.state ) + ( v.position_s !== undefined ? ' @' + esc( Math.round( v.position_s ) ) + 's' : '' ) + ( v.watched_s !== undefined ? ' / ' + esc( Math.round( v.watched_s ) ) + 's' : '' ) + '</div>';
	}
	function load( path ) {
		return wp.apiFetch( { path: path } );
	}
	function fail( err ) {
		root.innerHTML = '<div class="notice notice-error inline"><p>' + esc( fmt( S.error, ( err && err.message ) || err ) ) + '</p></div>';
	}

	// ---------- 实时访客 ----------

	function renderLive( res ) {
		var list = res.visitors || [];
		var count = { active: 0, idle: 0, offline: 0 };
		list.forEach( function ( v ) {
			count[ v.status ]++;
		} );
		var html = '<div class="pc-trk-cards">' + [ 'active', 'idle', 'offline' ].map( function ( k ) {
			return '<div class="pc-trk-card"><span>' + dot( k ) + '</span><b>' + count[ k ] + '</b></div>';
		} ).join( '' ) + '</div>';
		if ( ! list.length ) {
			root.innerHTML = html + '<p class="pc-trk-empty">' + esc( S.noVisitors ) + '</p>';
			return;
		}
		html += '<table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.visitor ) + '</th><th>' + esc( S.tabs ) + '</th><th>' + esc( S.stage ) + '</th><th>' + esc( S.visitStart ) + '</th><th>' + esc( S.lastSignal ) + '</th></tr></thead><tbody>';
		list.forEach( function ( v ) {
			var who = v.visitor;
			html += '<tr><td><div>' + dot( v.status ) + '</div><a href="' + esc( cfg.liveUrl + '&visitor=' + who.id ) + '"><strong>' + esc( who.label ) + '</strong></a>' + '<div class="pc-trk-sub">' + esc( ( S[ who.device ] || who.device ) + ' · ' + who.browser + ' · ' + S.visits + ' ' + who.visits ) + '</div></td><td>';
			v.tabs.forEach( function ( t ) {
				html += '<div class="pc-trk-tab"><span class="pc-trk-tabid">' + esc( t.tab ) + '</span> ' + dot( t.status ) + ' ' + pageText( t ) + videoText( t.video ) + '</div>';
			} );
			var visit = v.visit;
			html += '</td><td>' + ( visit ? esc( S.stages[ visit.stage ] ) + ( visit.order_url ? ' <a href="' + esc( visit.order_url ) + '">#' + esc( visit.order_id ) + '</a>' : '' ) : '—' ) + '</td>';
			html += '<td>' + ( visit ? esc( clock( visit.start_ms, true ) ) + '<div class="pc-trk-sub">' + esc( S.pageviews + ' ' + visit.pageviews ) + '</div>' : '—' ) + '</td>';
			html += '<td>' + esc( fmt( S.ago, ago( res.now_ms - v.last_signal_ms ) ) ) + '</td></tr>';
		} );
		root.innerHTML = html + '</tbody></table>';
	}

	function live() {
		var tick = function () {
			load( '/peptide/v1/track/admin/live' ).then( renderLive ).catch( fail );
		};
		tick();
		// 页面在后台时暂停自动刷新，切回来时立刻刷新一次。
		setInterval( function () {
			if ( ! document.hidden ) {
				tick();
			}
		}, cfg.refreshMs || 5000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				tick();
			}
		} );
	}

	// ---------- 访客详情 ----------

	var hideHeartbeats = true;

	function detailText( e ) {
		var d = e.data || {};
		var parts = [];
		Object.keys( d ).forEach( function ( k ) {
			if ( 'product_name' === k && e.type !== 'add_to_cart' ) {
				return;
			}
			var v = d[ k ];
			parts.push( k + '=' + ( 'boolean' === typeof v ? ( v ? 'yes' : 'no' ) : v ) );
		} );
		if ( e.variation_id ) {
			parts.push( 'variation=' + e.variation_id );
		}
		return parts.join( ', ' );
	}

	function renderTimeline( box, res ) {
		var rows = res.events.filter( function ( e ) {
			return ! hideHeartbeats || e.type !== 'heartbeat';
		} );
		var html = '<h2>' + esc( S.timeline ) + ' · ' + esc( clock( res.visit.start_ms, true ) ) + '</h2>';
		html += '<label class="pc-trk-hb"><input type="checkbox"' + ( hideHeartbeats ? ' checked' : '' ) + '> ' + esc( S.hideHeartbeats ) + '</label>';
		html += '<table class="widefat striped pc-trk-table pc-trk-timeline"><thead><tr><th>' + esc( S.time ) + '</th><th>' + esc( S.tab ) + '</th><th>' + esc( S.event ) + '</th><th>' + esc( S.page ) + '</th><th>' + esc( S.details ) + '</th></tr></thead><tbody>';
		rows.forEach( function ( e ) {
			html += '<tr class="' + ( 'server' === e.source ? 'is-server' : '' ) + '"><td>' + esc( clock( e.at_ms ) ) + '</td><td><span class="pc-trk-tabid">' + esc( e.tab || '—' ) + '</span></td><td>' + esc( S.types[ e.type ] || e.type ) + ( 'server' === e.source ? ' <span class="pc-trk-badge">' + esc( S.server ) + '</span>' : '' ) + '</td><td>' + ( e.path ? pageText( { page_type: e.page_type, path: e.path, product_name: ( e.data || {} ).product_name } ) : '' ) + '</td><td>' + esc( detailText( e ) ) + ( e.order_url ? ' <a href="' + esc( e.order_url ) + '">#' + esc( e.order_id ) + '</a>' : '' ) + '</td></tr>';
		} );
		box.innerHTML = html + '</tbody></table>';
		box.querySelector( '.pc-trk-hb input' ).addEventListener( 'change', function ( ev ) {
			hideHeartbeats = ev.target.checked;
			renderTimeline( box, res );
		} );
	}

	function visitor() {
		load( '/peptide/v1/track/admin/visitors/' + cfg.visitor ).then( function ( res ) {
			var v = res.visitor;
			var html = '<p><a href="' + esc( cfg.liveUrl ) + '">' + esc( S.back ) + '</a></p>';
			html += '<div class="pc-trk-cards"><div class="pc-trk-card"><span>' + esc( S.visitor ) + '</span><b>' + esc( v.label ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.account ) + '</span><b>' + esc( v.user_id ? v.email : S.guest ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.device ) + '</span><b>' + esc( ( S[ v.device ] || v.device ) + ' · ' + v.browser ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.firstSeen ) + '</span><b>' + esc( clock( v.first_ms, true ) ) + '</b></div><div class="pc-trk-card"><span>' + esc( S.visits ) + '</span><b>' + esc( v.visits ) + '</b></div></div>';
			if ( res.others.length ) {
				html += '<p>' + esc( S.otherDevices ) + ': ' + res.others.map( function ( o ) {
					return '<a href="' + esc( cfg.liveUrl + '&visitor=' + o.id ) + '">' + esc( '#' + o.id + ' ' + ( S[ o.device ] || o.device ) + ' · ' + o.browser ) + '</a>';
				} ).join( ', ' ) + '</p>';
			}
			html += '<div class="pc-trk-split"><div><h2>' + esc( S.visits ) + '</h2><table class="widefat pc-trk-table pc-trk-visits"><thead><tr><th>' + esc( S.visitStart ) + '</th><th>' + esc( S.duration ) + '</th><th>' + esc( S.pageviews ) + '</th><th>' + esc( S.stage ) + '</th></tr></thead><tbody>';
			res.visits.forEach( function ( s ) {
				html += '<tr data-visit="' + esc( s.id ) + '" tabindex="0"><td>' + esc( clock( s.start_ms, true ) ) + '<div class="pc-trk-sub"><code>' + esc( s.landing ) + '</code></div></td><td>' + esc( ago( s.last_ms - s.start_ms ) ) + '</td><td>' + esc( s.pageviews ) + '</td><td>' + esc( S.stages[ s.stage ] ) + ( s.order_url ? ' <a href="' + esc( s.order_url ) + '">#' + esc( s.order_id ) + '</a>' : '' ) + '</td></tr>';
			} );
			html += '</tbody></table></div><div id="pc-trk-timeline"><p>' + esc( S.chooseVisit ) + '</p></div></div>';
			root.innerHTML = html;
			var box = document.getElementById( 'pc-trk-timeline' );
			var open = function ( id ) {
				root.querySelectorAll( '.pc-trk-visits tr' ).forEach( function ( tr ) {
					tr.classList.toggle( 'is-selected', tr.getAttribute( 'data-visit' ) === String( id ) );
				} );
				box.innerHTML = '<p>' + esc( S.loading ) + '</p>';
				load( '/peptide/v1/track/admin/visits/' + id ).then( function ( t ) {
					renderTimeline( box, t );
				} ).catch( function ( err ) {
					box.innerHTML = '<p>' + esc( fmt( S.error, err.message ) ) + '</p>';
				} );
			};
			root.querySelectorAll( '.pc-trk-visits tr[data-visit]' ).forEach( function ( tr ) {
				var go = function ( ev ) {
					if ( ev.target.closest( 'a' ) ) {
						return;
					}
					open( tr.getAttribute( 'data-visit' ) );
				};
				tr.addEventListener( 'click', go );
				tr.addEventListener( 'keydown', function ( ev ) {
					if ( 'Enter' === ev.key ) {
						go( ev );
					}
				} );
			} );
			var first = cfg.visit || ( res.visits[ 0 ] && res.visits[ 0 ].id );
			if ( first ) {
				open( first );
			}
		} ).catch( fail );
	}

	// ---------- 转化统计 ----------

	function stats() {
		var today = new Date();
		var iso = function ( d ) {
			return d.toISOString().slice( 0, 10 );
		};
		var week = new Date( today.getTime() - 6 * 86400000 );
		root.innerHTML = '<form class="pc-trk-filter"><label>' + esc( S.from ) + ' <input type="date" name="from" value="' + iso( week ) + '"></label> <label>' + esc( S.to ) + ' <input type="date" name="to" value="' + iso( today ) + '"></label> <select name="device"><option value="">' + esc( S.allDevices ) + '</option><option value="desktop">' + esc( S.desktop ) + '</option><option value="mobile">' + esc( S.mobile ) + '</option><option value="tablet">' + esc( S.tablet ) + '</option></select> <button class="button">' + esc( S.apply ) + '</button></form><div id="pc-trk-stats"></div>';
		var form = root.querySelector( 'form' );
		var out = document.getElementById( 'pc-trk-stats' );
		var run = function () {
			out.innerHTML = '<p>' + esc( S.loading ) + '</p>';
			var q = '?from=' + encodeURIComponent( form.from.value ) + '&to=' + encodeURIComponent( form.to.value ) + '&device=' + encodeURIComponent( form.device.value );
			load( '/peptide/v1/track/admin/stats' + q ).then( function ( r ) {
				var html = '<div class="pc-trk-cards"><div class="pc-trk-card"><span>' + esc( S.visits ) + '</span><b>' + r.visits + '</b></div><div class="pc-trk-card"><span>' + esc( S.visitors ) + '</span><b>' + r.visitors + '</b></div><div class="pc-trk-card"><span>' + esc( S.pageviews ) + '</span><b>' + r.pageviews + '</b></div></div>';
				html += '<h2>' + esc( S.funnel ) + '</h2><div class="pc-trk-funnel">';
				var base = r.funnel[ 0 ].count;
				r.funnel.forEach( function ( f, i ) {
					var prev = i ? r.funnel[ i - 1 ].count : f.count;
					html += '<div class="pc-trk-step"><div class="pc-trk-step__label">' + esc( S.stages[ f.stage ] ) + '</div><div class="pc-trk-step__bar"><span style="width:' + ( base && f.count ? Math.max( 2, ( f.count / base ) * 100 ) : 0 ) + '%"></span></div><div class="pc-trk-step__num"><b>' + f.count + '</b> ' + esc( fmt( S.ofVisits, pct( f.count, base ) ) ) + ( i ? ' · ' + esc( fmt( S.fromPrev, pct( f.count, prev ) ) ) : '' ) + '</div></div>';
				} );
				html += '</div>';
				if ( r.days.length ) {
					html += '<h2>' + esc( S.byDay ) + '</h2><table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.day ) + '</th><th>' + esc( S.visits ) + '</th><th>' + esc( S.carts ) + '</th><th>' + esc( S.orders ) + '</th><th>' + esc( S.paid ) + '</th></tr></thead><tbody>' + r.days.map( function ( d ) {
						return '<tr><td>' + esc( d.day ) + '</td><td>' + d.visits + '</td><td>' + d.carts + '</td><td>' + d.orders + '</td><td>' + d.paid + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
				}
				if ( r.products.length ) {
					html += '<h2>' + esc( S.products ) + '</h2><table class="widefat striped pc-trk-table"><thead><tr><th>' + esc( S.products ) + '</th><th>' + esc( S.views ) + '</th><th>' + esc( S.carts ) + '</th></tr></thead><tbody>' + r.products.map( function ( p ) {
						return '<tr><td>' + esc( p.name || '#' + p.product_id ) + '</td><td>' + p.views + '</td><td>' + p.carts + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
				}
				if ( ! r.visits ) {
					html += '<p class="pc-trk-empty">' + esc( S.none ) + '</p>';
				}
				out.innerHTML = html;
			} ).catch( function ( err ) {
				out.innerHTML = '<p>' + esc( fmt( S.error, err.message ) ) + '</p>';
			} );
		};
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run();
		} );
		run();
	}

	if ( 'stats' === cfg.view ) {
		stats();
	} else if ( 'visitor' === cfg.view && cfg.visitor ) {
		visitor();
	} else {
		live();
	}
} )( window.wp, window.pcTrackingAdmin );
