/**
 * Original geometric icons for the VialCove store design editor.
 * These describe editor controls; they never replace stored storefront icon values.
 *
 * PeptideDesignIcons.render( 'vialcove/promotion-list', { size: 24 } )
 * PeptideDesignIcons.renderAction( 'duplicate', { size: 16 } )
 *
 * render() returns a wp.element element, or null when no element factory is present.
 * Pass createElement for another React-compatible factory. markup() is for static
 * previews and escapes its small allowlisted set of attributes. All SVGs are
 * decorative: the owning button or field must provide its accessible label.
 */
( function ( global ) {
	'use strict';

	var blocks = Object.create( null );
	var actions = Object.create( null );
	var own = Object.prototype.hasOwnProperty;
	var P = function ( d ) { return [ 'path', { d: d } ]; };
	var R = function ( x, y, w, h, r ) { return [ 'rect', { x: x, y: y, width: w, height: h, rx: r === undefined ? 1.2 : r } ]; };
	var C = function ( x, y, r ) { return [ 'circle', { cx: x, cy: y, r: r } ]; };
	var L = function ( x1, y1, x2, y2 ) { return [ 'line', { x1: x1, y1: y1, x2: x2, y2: y2 } ]; };
	var D = function ( x, y, r ) { return [ 'circle', { cx: x, cy: y, r: r || .8, fill: 'currentColor', stroke: 'none' } ]; };
	var frame = function () { return R( 3, 4, 18, 16, 1.6 ); };
	var bag = function ( x, y, w, h ) {
		return [ R( x, y + 3, w, h - 3, 1.2 ), P( 'M' + ( x + w * .28 ) + ' ' + ( y + 4 ) + 'v-2a' + ( w * .22 ) + ' ' + ( w * .22 ) + ' 0 0 1 ' + ( w * .44 ) + ' 0v2' ) ];
	};
	var page = function () { return [ P( 'M6 3h8l4 4v14H6zM14 3v5h4' ) ]; };
	var folder = function () { return P( 'M3 7V5h6l2 2h10v13H3z' ); };
	var bubble = function () { return P( 'M4 4h16v13H10l-5 4v-4H4z' ); };
	var star = function ( x, y, r ) {
		var inner = r * .43, points = [], i;
		for ( i = 0; i < 10; i++ ) {
			var a = -Math.PI / 2 + i * Math.PI / 5, length = i % 2 ? inner : r;
			points.push( ( x + Math.cos( a ) * length ).toFixed( 2 ) + ' ' + ( y + Math.sin( a ) * length ).toFixed( 2 ) );
		}
		return P( 'M' + points.join( 'L' ) + 'Z' );
	};
	var clock = function ( x, y, r ) { return [ C( x, y, r ), P( 'M' + x + ' ' + ( y - r * .55 ) + 'v' + ( r * .62 ) + 'l' + ( r * .4 ) + ' ' + ( r * .23 ) ) ]; };
	var percent = function ( x, y, w ) { return [ L( x, y + w, x + w, y ), C( x + .5, y + .5, .65 ), C( x + w - .5, y + w - .5, .65 ) ]; };

	// Basic blocks: compositions communicate structure rather than sharing one image glyph.
	blocks.carousel = [ R( 5, 5, 14, 13 ), P( 'M2 7v9M22 7v9M8 14l3-3 4 4' ), D( 9, 8.5, .7 ), D( 9, 21, .7 ), D( 12, 21, .7 ), D( 15, 21, .7 ) ];
	blocks.image = [ frame(), C( 8, 9, 1.3 ), P( 'M4 17l5-5 4 4 3-3 4 5' ) ];
	blocks.image_row = [ R( 2, 5, 6, 14 ), R( 9, 5, 6, 14 ), R( 16, 5, 6, 14 ), P( 'M3.5 15l1.5-2 1.5 2M10.5 12l1.5-2 1.5 2M17.5 16l1.5-2 1.5 2' ) ];
	blocks.image_grid = [ R( 3, 3, 8, 8 ), R( 13, 3, 8, 8 ), R( 3, 13, 8, 8 ), R( 13, 13, 8, 8 ), P( 'M5 8l2-2 2 2M15 18l2-2 2 2' ) ];
	blocks.mosaic_3 = [ R( 3, 3, 10, 18 ), R( 15, 3, 6, 8 ), R( 15, 13, 6, 8 ), P( 'M5 16l3-4 3 4' ) ];
	blocks.mosaic_4 = [ R( 3, 3, 11, 11 ), R( 16, 3, 5, 11 ), R( 3, 16, 8, 5 ), R( 13, 16, 8, 5 ), D( 7, 7, 1 ) ];
	blocks.mosaic_5 = [ R( 3, 3, 7, 7 ), R( 12, 3, 9, 7 ), R( 3, 12, 5, 9 ), R( 10, 12, 5, 9 ), R( 17, 12, 4, 9 ) ];
	blocks.media_text = [ R( 3, 4, 9, 16 ), P( 'M5 15l2.5-3 2.5 3M15 6h6M15 10h6M15 14h4M15 18h5' ) ];
	blocks.section_heading = [ P( 'M4 6h16M7 10h10M5 15h14M8 19h8' ), L( 4, 6, 4, 8 ), L( 20, 6, 20, 8 ) ];
	blocks.media_text_list = [ R( 3, 3, 7, 7 ), R( 14, 14, 7, 7 ), P( 'M13 4h8M13 8h6M3 15h8M3 19h6' ) ];
	blocks.card_list = [ R( 2, 4, 9, 16 ), R( 13, 4, 9, 16 ), P( 'M4 11l2.5-3 2.5 3M15 11l2.5-3 2.5 3M4.5 15h4M15.5 15h4M4.5 18h3M15.5 18h3' ) ];
	blocks.video = [ R( 2.5, 4.5, 19, 15, 2 ), P( 'M10 8l6 4-6 4z' ) ];
	blocks.video_text = [ frame(), P( 'M9 7l5 3-5 3zM6 16h12M8 18h8' ) ];
	blocks.slogan = [ C( 5, 8, 2.5 ), C( 12, 8, 2.5 ), C( 19, 8, 2.5 ), P( 'M3 14h4M10 14h4M17 14h4M3 18h4M10 18h4M17 18h4' ) ];
	blocks.counter = [ P( 'M3 8l2-2v10M8 8c0-3 5-3 5 0 0 2-5 4-5 7h5M16 6h4l-3 4c5-1 5 6 0 6h-1' ), L( 3, 20, 21, 20 ) ];
	blocks.gallery = [ R( 2, 3, 15, 14 ), P( 'M7 20h14V8M4 14l4-4 4 3 3-2' ), C( 6, 7, 1 ) ];
	blocks.custom_html = [ frame(), P( 'M8 9l-3 3 3 3M16 9l3 3-3 3M14 8l-4 8' ) ];
	blocks.timeline = [ L( 6, 3, 6, 21 ), C( 6, 6, 2 ), C( 6, 17, 2 ), P( 'M11 4h10M11 8h6M11 15h10M11 19h6' ) ];
	blocks.contact = [ frame(), C( 8, 9, 2 ), P( 'M5 16c0-4 6-4 6 0M14 8h4M14 12h4M14 16h3' ) ];
	blocks.promo_grid = [ R( 2, 4, 12, 16 ), R( 16, 4, 6, 7 ), R( 16, 13, 6, 7 ), P( 'M4 14l3-4 4 5' ), D( 6, 18, .6 ), D( 9, 18, .6 ) ];
	blocks.marquee = [ P( 'M2 6h20M2 18h20M7 9v6M5 9h4M11 9h5M13.5 9v6M18 10l3 2-3 2' ) ];
	blocks.spacer = [ P( 'M3 3h18M3 21h18M12 6v12M8.5 9.5L12 6l3.5 3.5M8.5 14.5L12 18l3.5-3.5' ) ];
	blocks.rich_text = [ P( 'M3 4h10M8 4v10M3 4v2M13 4v2M16 5h5M16 9h5M3 17h18M3 21h13' ) ];
	blocks.compare = [ frame(), L( 12, 4, 12, 20 ), P( 'M5 15l3-4 3 4M14 13l2-3 3 4M9 2h6M9 22h6' ) ];
	blocks.hotspots = [ frame(), P( 'M4 17l4-4 3 3' ), C( 15, 10, 3 ), P( 'M15 8.5v3M13.5 10h3' ), D( 7, 8, .8 ) ];
	blocks.faq = [ bubble(), P( 'M9 8c0-3 6-3 6 0 0 2-3 2-3 4' ), D( 12, 14.5, .7 ) ];
	blocks.product_showcase = [ R( 2, 3, 20, 18 ), P( 'M3 17l5-5 4 3M16 16s-4-4-4-7a4 4 0 0 1 8 0c0 3-4 7-4 7z' ), C( 16, 9, 1.2 ) ];
	blocks.hero_h1 = [ P( 'M3 4v12M10 4v12M3 10h7M14 7l3-3v12M3 21h18' ) ];
	blocks.info_list = [ C( 4.5, 5, 1.5 ), C( 4.5, 12, 1.5 ), C( 4.5, 19, 1.5 ), P( 'M9 5h12M9 12h10M9 19h12' ) ];
	blocks.nav_bar = [ R( 2, 7, 20, 10 ), P( 'M2 10h20M5 13h3M10.5 13h3M16 13h3' ) ];
	blocks.logo_list = [ C( 6, 7, 3 ), R( 14, 4, 7, 6 ), P( 'M3 20l3.5-6 3.5 6z' ), R( 14, 14, 7, 7, 2.7 ) ];
	blocks.product_collection = [ folder(), R( 6, 11, 4, 6, .5 ), R( 14, 11, 4, 6, .5 ), P( 'M7 11v-1M9 11v-1M15 11v-1M17 11v-1' ) ];
	blocks.report_feature = page().concat( [ P( 'M9 12h6M9 16h3' ), C( 18, 17, 3.2 ), P( 'M16.5 17l1 1 2-2' ) ] );
	blocks.cta = [ R( 2, 7, 20, 10, 3 ), P( 'M6 12h11M14 9l3 3-3 3' ) ];
	blocks.text_block = [ P( 'M3 4h18M3 8h18M3 12h12M9 17l3 3 3-3' ), D( 18, 12, .7 ), D( 21, 12, .7 ) ];
	blocks.site_header = [ R( 3, 3, 18, 18, 1.4 ), P( 'M3 9h18M6 6h2M12 6h3M17 6h1' ), R( 6, 12, 12, 6, .5 ) ];
	blocks.site_footer = [ R( 3, 3, 18, 18, 1.4 ), P( 'M3 14h18M6 17h4M14 17h4M6 19h12' ), R( 6, 6, 12, 5, .5 ) ];
	blocks.site_styles = [ P( 'M5 3v6M5 14v7M12 3v11M12 19v2M19 3v2M19 10v11' ), R( 2.5, 9, 5, 5, 1 ), R( 9.5, 14, 5, 5, 1 ), R( 16.5, 5, 5, 5, 1 ) ];
	blocks.context_content = [ frame(), P( 'M3 8h18M7 11H5v6h2M17 11h2v6h-2M10 12h4M10 16h4' ) ];

	// Product blocks: bags, collections and different movement patterns remain distinct.
	blocks.product_carousel = bag( 7, 4, 10, 15 ).concat( [ P( 'M4 9l-2 3 2 3M20 9l2 3-2 3' ), D( 10, 22, .65 ), D( 14, 22, .65 ) ] );
	blocks.product_scroll = [ R( 2, 5, 6, 11 ), R( 10, 5, 6, 11 ), P( 'M18 5h4v11h-4M4 5V3M6 5V3M12 5V3M14 5V3M3 21h18M18 18l3 3-3 2' ) ];
	blocks.product_waterfall = [ R( 3, 5, 8, 7 ), R( 13, 5, 8, 11 ), R( 3, 14, 8, 7 ), R( 13, 18, 8, 3, .5 ), P( 'M5 5V3h4v2M15 5V3h4v2' ) ];
	blocks.product_ranking = [ P( 'M3 20V12h5v8M9.5 20V8h5v12M16 20v-5h5v5M2 21h20' ), star( 12, 3.6, 2.4 ) ];
	blocks.product_comparison = [ R( 3, 5, 7, 12 ), R( 14, 5, 7, 12 ), P( 'M5 5V3h3v2M16 5V3h3v2M7 21h10M9 19l-2 2 2 2M15 19l2 2-2 2' ) ];
	blocks.featured_product = bag( 4, 5, 13, 16 ).concat( [ star( 18, 6, 4 ) ] );
	blocks.video_products = [ R( 2, 5, 10, 14 ), P( 'M5 9l4 3-4 3z' ) ].concat( bag( 15, 5, 7, 14 ) );
	blocks.recent_products = bag( 3, 3, 12, 17 ).concat( clock( 18, 17, 4 ) );
	blocks.category_list = [ folder(), P( 'M6 11h2M11 11h7M6 15h2M11 15h7M6 18h2M11 18h7' ) ];
	blocks.featured_categories = [ R( 3, 3, 8, 8 ), R( 3, 13, 8, 8 ), R( 13, 13, 8, 8 ), star( 17.5, 6.8, 4.1 ) ];
	blocks.category_navigation = [ R( 2, 6, 6, 5 ), R( 10, 6, 6, 5 ), P( 'M18 6h4v5h-4M3 17h18M18 14l3 3-3 3' ) ];
	blocks.product_tabs = [ frame(), P( 'M3 8h18M9 4v4M15 4v4' ) ].concat( bag( 8, 10, 8, 8 ) );
	blocks.category_tabs = [ frame(), P( 'M3 8h18M9 4v4M15 4v4M7 12h4l2 2h5v4H7z' ) ];
	blocks.topic_tabs = [ frame(), P( 'M3 8h18M9 4v4M15 4v4M8 18v-7h8l-2 2 2 2H8' ) ];

	// Marketing/content blocks: the symbol describes the bound entity, not a generic quote.
	blocks.promotion_card = [ P( 'M3 5h11v4a2 2 0 0 0 0 4v6H3v-6a2 2 0 0 0 0-4zM6 8h5M6 16h3' ) ].concat( clock( 18, 7, 4 ) );
	blocks.product_offer = [ R( 3, 8, 18, 4, .6 ), P( 'M5 12v9h14v-9M12 8v13M12 8H7C2 8 5 1 8 4zM12 8h5c5 0 2-7-1-4z' ) ];
	blocks.subscribe = [ R( 2, 5, 18, 14 ), P( 'M3 7l8 6 8-6M19 13v8M15 17h8' ) ];
	blocks.announcement = [ P( 'M3 9h4l10-5v16L7 15H3zM7 15l2 6h4l-2-4M20 8l2-1M20 16l2 1M20 12h2' ) ];
	blocks.coupon_list = [ P( 'M5 3h16v5M3 7h16v4a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4z' ) ].concat( percent( 8, 11, 6 ) );
	blocks.promotion_list = [ P( 'M3 8h6V4h5V2M3 21h18V8h-5v5h-6v4H3zM13 17h5M6 20v-1' ) ];
	blocks.shipping_discount_list = [ P( 'M2 6h12v11H2zM14 10h4l4 4v3h-8M6 10h5M8.5 8v4' ), C( 6, 19, 2 ), C( 18, 19, 2 ) ];
	blocks.text_reviews = [ bubble(), P( 'M7 8h10M7 12h7' ) ];
	blocks.image_reviews = [ bubble(), R( 7, 7, 10, 7, .5 ), P( 'M8 13l3-3 3 3 2-2' ), D( 9, 9, .5 ) ];
	blocks.video_reviews = [ bubble(), P( 'M10 7l6 3.5-6 3.5z' ) ];
	blocks.featured_reviews = [ bubble(), star( 12, 10.5, 4.3 ) ];
	blocks.youtube_list = [ P( 'M6 3h15v14M3 7h14v14H3zM8 11l5 3-5 3z' ) ];
	blocks.social_links = [ C( 5, 12, 3 ), C( 19, 5, 3 ), C( 19, 19, 3 ), P( 'M7.8 10.5l8.5-4M7.8 13.5l8.5 4' ) ];
	blocks.partners = [ P( 'M2 8l4-3 5 2 2-1 5-1 4 4-5 10-3 2-3-2-3-1zM7 7l4 3 3-2 5 5M8 15l6 6M10 12l7 7' ) ];
	blocks.product_search = bag( 3, 3, 12, 17 ).concat( [ C( 17, 15, 4 ), L( 20, 18, 23, 21 ) ] );
	blocks.search_terms = [ C( 7, 8, 4 ), P( 'M10 11l3 3M15 5h7M15 9h5M3 19h7M13 19h8' ) ];
	blocks.blog_posts = page().concat( [ P( 'M9 11h6M9 14h4M9 18l2 2 4-4' ) ] );
	blocks.category_blog = [ folder(), P( 'M7 10h10v8H7zM9 12h6M9 15h4' ) ];
	blocks.blog_categories = [ P( 'M2 5V3h6l2 2h12v4M2 11V9h6l2 2h12v4M2 17v-2h6l2 2h12v5H2z' ) ];
	blocks.campaign_pages = page().concat( [ P( 'M9 18v-7h7l-2 2 2 2H9' ) ] );

	// Action symbols are separate from block symbols, including names that overlap.
	actions.add = [ P( 'M12 4v16M4 12h16' ) ];
	actions.remove = [ P( 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7' ) ];
	actions.duplicate = [ R( 7, 7, 14, 14 ), P( 'M16 3H3v13' ) ];
	actions.drag = [ D( 8, 5, 1.2 ), D( 16, 5, 1.2 ), D( 8, 12, 1.2 ), D( 16, 12, 1.2 ), D( 8, 19, 1.2 ), D( 16, 19, 1.2 ) ];
	actions.show = [ P( 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z' ), C( 12, 12, 3 ) ];
	actions.hide = [ P( 'M4 4l16 16M8 5.7A11 11 0 0 1 12 5c6 0 10 7 10 7a20 20 0 0 1-3 3.5M4.8 7.3A21 21 0 0 0 2 12s4 7 10 7a12 12 0 0 0 4.2-.8M10 10a3 3 0 0 0 4 4' ) ];
	actions.desktop = [ R( 2, 3, 20, 14 ), P( 'M8 21h8M12 17v4' ) ];
	actions.mobile = [ R( 6, 2, 12, 20, 2 ), P( 'M10 5h4' ), D( 12, 19, .7 ) ];
	actions.tablet = [ R( 3, 2, 18, 20, 2 ), D( 12, 19, .7 ) ];
	actions.up = [ P( 'M5 15l7-7 7 7' ) ];
	actions.down = [ P( 'M5 9l7 7 7-7' ) ];
	actions.left = [ P( 'M15 5l-7 7 7 7' ) ];
	actions.right = [ P( 'M9 5l7 7-7 7' ) ];
	actions.back = [ P( 'M20 12H4M10 6l-6 6 6 6' ) ];
	actions.forward = [ P( 'M4 12h16M14 6l6 6-6 6' ) ];
	actions.search = [ C( 10, 10, 6.5 ), L( 15, 15, 21, 21 ) ];
	actions.close = [ P( 'M5 5l14 14M19 5L5 19' ) ];
	actions.save = [ P( 'M3 3h15l3 3v15H3zM7 3v6h10V3M7 21v-8h10v8' ), L( 14, 5, 14, 7 ) ];
	actions.publish = [ P( 'M4 15v6h16v-6M12 16V3M7 8l5-5 5 5' ) ];
	actions.undo = [ P( 'M4 9h10a6 6 0 0 1 0 12h-3M8 4L3 9l5 5' ) ];
	actions.redo = [ P( 'M20 9H10a6 6 0 0 0 0 12h3M16 4l5 5-5 5' ) ];
	actions.reset = [ P( 'M4 10a8 8 0 1 1 1 8M4 4v6h6' ) ];
	actions.edit = [ P( 'M4 16l-1 5 5-1L21 7l-4-4zM14 6l4 4M4 16l4 4' ) ];
	actions.settings = [ P( 'M3 5h18M3 12h18M3 19h18' ), C( 8, 5, 2.2 ), C( 16, 12, 2.2 ), C( 9, 19, 2.2 ) ];
	actions.more = [ D( 5, 12, 1.5 ), D( 12, 12, 1.5 ), D( 19, 12, 1.5 ) ];
	actions.pin = [ P( 'M8 3h8l-1 7 4 4H5l4-4zM12 14v8' ) ];
	actions.link = [ P( 'M10 7l2-2a5 5 0 0 1 7 7l-2 2M14 17l-2 2a5 5 0 0 1-7-7l2-2M8 16l8-8' ) ];
	actions.unlink = [ P( 'M10 6l1-1a5 5 0 0 1 8 6l-1 1M14 18l-1 1a5 5 0 0 1-8-6l1-1M3 3l18 18' ) ];
	actions.open_external = [ P( 'M14 3h7v7M21 3L10 14M10 5H3v16h16v-7' ) ];
	actions.fullscreen = [ P( 'M8 3H3v5M16 3h5v5M3 16v5h5M21 16v5h-5' ) ];
	actions.collapse_fullscreen = [ P( 'M3 8h5V3M21 8h-5V3M8 21v-5H3M16 21v-5h5' ) ];
	actions.folder = [ folder() ];
	actions.document = page().concat( [ P( 'M9 12h6M9 16h6' ) ] );
	actions.home = [ P( 'M2 11L12 3l10 8M5 9v12h14V9M10 21v-7h4v7' ) ];
	actions.refresh = [ P( 'M4 9a8 8 0 0 1 13-5l3 3M20 3v4h-4M20 15a8 8 0 0 1-13 5l-3-3M4 21v-4h4' ) ];
	actions.play = [ P( 'M7 3l14 9-14 9z' ) ];
	actions.pause = [ P( 'M8 4v16M16 4v16' ) ];
	actions.check = [ P( 'M3 12l6 6L21 5' ) ];
	actions.warning = [ P( 'M12 3L2 21h20zM12 9v5' ), D( 12, 17.5, .8 ) ];
	actions.info = [ C( 12, 12, 9 ), P( 'M12 11v6' ), D( 12, 7, .8 ) ];
	actions.help = [ C( 12, 12, 9 ), P( 'M9 8c0-3 6-3 6 0 0 2-3 2-3 5' ), D( 12, 17, .8 ) ];
	actions.lock = [ R( 5, 10, 14, 11 ), P( 'M8 10V6a4 4 0 0 1 8 0v4' ), D( 12, 15, .9 ) ];
	actions.unlock = [ R( 5, 10, 14, 11 ), P( 'M8 10V6a4 4 0 0 1 8 0' ), D( 12, 15, .9 ) ];
	actions.timer = clock( 12, 14, 7.5 ).concat( [ P( 'M9 2h6M12 2v3M18 5l2 2' ) ] );
	actions.calendar = [ R( 3, 5, 18, 16 ), P( 'M3 10h18M8 2v6M16 2v6' ), D( 8, 14 ), D( 12, 14 ), D( 16, 14 ), D( 8, 18 ), D( 12, 18 ) ];
	actions.globe = [ C( 12, 12, 9 ), P( 'M3 12h18M12 3c-5 4-5 14 0 18M12 3c5 4 5 14 0 18' ) ];
	actions.image_upload = [ R( 3, 4, 17, 16 ), P( 'M4 17l5-5 4 4M18 8v8M14 12h8' ), D( 7, 8, 1 ) ];
	actions.video_upload = [ R( 2, 5, 17, 14 ), P( 'M6 9l5 3-5 3zM19 9v8M15 13h8' ) ];
	actions.color = [ P( 'M12 2c-3 4-7 8-7 12a7 7 0 0 0 14 0c0-4-4-8-7-12zM8 15a4 4 0 0 0 4 4' ) ];
	actions.align_left = [ P( 'M3 5h18M3 10h12M3 15h18M3 20h12' ) ];
	actions.align_center = [ P( 'M3 5h18M6 10h12M3 15h18M6 20h12' ) ];
	actions.align_right = [ P( 'M3 5h18M9 10h12M3 15h18M9 20h12' ) ];
	actions.grid = [ R( 3, 3, 7, 7 ), R( 14, 3, 7, 7 ), R( 3, 14, 7, 7 ), R( 14, 14, 7, 7 ) ];
	actions.list = [ D( 4, 5, 1 ), D( 4, 12, 1 ), D( 4, 19, 1 ), P( 'M9 5h12M9 12h12M9 19h12' ) ];
	actions.layers = [ P( 'M2 8l10-5 10 5-10 5zM2 13l10 5 10-5M2 18l10 5 10-5' ) ];
	actions.layout = [ frame(), P( 'M3 9h18M10 9v11' ) ];
	actions.clear = [ P( 'M8 4l13 13-4 4H9L2 14zM6 10l10 10M12 21h10' ) ];
	actions.download = [ P( 'M4 16v5h16v-5M12 3v13M7 11l5 5 5-5' ) ];
	actions.filter = [ P( 'M3 4h18l-7 8v7l-4 2V12z' ) ];
	actions.sort = [ P( 'M7 3v18M3 17l4 4 4-4M14 5h7M14 11h5M14 17h3' ) ];
	actions.block = [ R( 3, 3, 18, 18 ), P( 'M3 10h18M10 10v11' ) ];

	var actionAliases = {
		'plus': 'add', 'plus-alt2': 'add', 'insert': 'add',
		'trash': 'remove', 'delete': 'remove', 'copy': 'duplicate', 'admin-page': 'duplicate',
		'menu': 'drag', 'move': 'drag', 'visibility': 'show', 'visible': 'show', 'eye': 'show', 'hidden': 'hide',
		'smartphone': 'mobile', 'pc': 'desktop', 'arrow-up-alt2': 'up', 'arrow-down-alt2': 'down',
		'arrow-left-alt2': 'left', 'arrow-right-alt2': 'right', 'arrow-left-alt': 'back', 'exit': 'back',
		'no-alt': 'close', 'dismiss': 'close', 'saved': 'save', 'upload': 'publish',
		'edit-page': 'edit', 'admin-generic': 'settings', 'ellipsis': 'more',
		'admin-links': 'link', 'external': 'open_external', 'editor-expand': 'fullscreen',
		'editor-contract': 'collapse_fullscreen', 'admin-post': 'document', 'controls-play': 'play',
		'controls-pause': 'pause', 'yes': 'check', 'yes-alt': 'check', 'update': 'refresh',
		'editor-alignleft': 'align_left', 'editor-aligncenter': 'align_center', 'editor-alignright': 'align_right'
	};
	var blockAliases = {
		'images-alt2': 'carousel', 'format-image': 'image', 'images-alt': 'image_row', 'grid-view': 'image_grid',
		'align-pull-left': 'media_text', 'heading': 'section_heading', 'align-left': 'media_text_list',
		'screenoptions': 'card_list', 'video-alt3': 'video', 'format-video': 'video_text', 'megaphone': 'announcement',
		'chart-bar': 'counter', 'format-gallery': 'gallery', 'editor-code': 'custom_html', 'clock': 'promotion_card',
		'email-alt': 'contact', 'tag': 'promotion_list', 'editor-insertmore': 'marquee', 'image-flip-vertical': 'spacer',
		'editor-paragraph': 'rich_text', 'image-flip-horizontal': 'compare', 'location': 'hotspots', 'editor-help': 'faq',
		'cart': 'product_showcase', 'list-view': 'info_list', 'awards': 'logo_list', 'products': 'featured_product',
		'media-document': 'report_feature', 'text': 'text_block', 'align-wide': 'site_header',
		'align-full-width': 'site_footer', 'admin-appearance': 'site_styles', 'category': 'category_list',
		'index-card': 'product_tabs', 'email': 'subscribe', 'format-quote': 'text_reviews', 'share': 'social_links', 'groups': 'partners'
	};

	function normalize( name ) {
		return String( name || '' ).replace( /^vialcove\//, '' ).replace( /-/g, '_' );
	}

	function resolve( name, kind ) {
		var original = String( name || '' ), normalized = normalize( original ), canonical;
		if ( kind !== 'action' && own.call( blocks, normalized ) ) {
			return { name: normalized, kind: 'block', nodes: blocks[ normalized ] };
		}
		if ( kind !== 'block' ) {
			canonical = own.call( actions, normalized ) ? normalized : ( own.call( actionAliases, original ) ? actionAliases[ original ] : null );
			if ( canonical && own.call( actions, canonical ) ) { return { name: canonical, kind: 'action', nodes: actions[ canonical ] }; }
		}
		canonical = own.call( blockAliases, original ) ? blockAliases[ original ] : null;
		if ( kind !== 'action' && canonical ) { return { name: canonical, kind: 'block', nodes: blocks[ canonical ] }; }
		return null;
	}

	function number( value, fallback, min, max ) {
		var parsed = Number( value );
		return value !== undefined && value !== null && isFinite( parsed ) ? Math.max( min, Math.min( max, parsed ) ) : fallback;
	}

	function attributes( found, options ) {
		return {
			className: 'pc-design-icon' + ( options.className ? ' ' + String( options.className ) : '' ),
			width: number( options.size, 24, 8, 128 ), height: number( options.size, 24, 8, 128 ),
			viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
			strokeWidth: number( options.strokeWidth, 1.6, .75, 3 ), strokeLinecap: 'round', strokeLinejoin: 'round',
			'aria-hidden': 'true', focusable: 'false', role: 'presentation',
			'data-icon': found.name, 'data-icon-kind': found.kind
		};
	}

	function render( name, options ) {
		options = options || {};
		var h = options.createElement || ( global.wp && global.wp.element && global.wp.element.createElement );
		if ( typeof h !== 'function' ) { return null; }
		var found = resolve( name, options.kind ) || { name: 'block', kind: 'fallback', nodes: actions.block };
		var attrs = attributes( found, options );
		if ( options.style ) { attrs.style = options.style; }
		return h( 'svg', attrs, found.nodes.map( function ( node, index ) {
			return h( node[ 0 ], Object.assign( { key: index }, node[ 1 ] ) );
		} ) );
	}

	function escape( value ) {
		return String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function markup( name, options ) {
		options = options || {};
		var found = resolve( name, options.kind ) || { name: 'block', kind: 'fallback', nodes: actions.block };
		var attrs = attributes( found, options ), rename = { className: 'class', strokeWidth: 'stroke-width', strokeLinecap: 'stroke-linecap', strokeLinejoin: 'stroke-linejoin' };
		var text = '<svg xmlns="http://www.w3.org/2000/svg"';
		Object.keys( attrs ).forEach( function ( key ) { text += ' ' + ( rename[ key ] || key ) + '="' + escape( attrs[ key ] ) + '"'; } );
		text += '>';
		found.nodes.forEach( function ( node ) {
			text += '<' + node[ 0 ];
			Object.keys( node[ 1 ] ).forEach( function ( key ) { text += ' ' + key + '="' + escape( node[ 1 ][ key ] ) + '"'; } );
			text += '/>';
		} );
		return text + '</svg>';
	}

	global.PeptideDesignIcons = {
		version: '1.0.0',
		blockTypes: Object.keys( blocks ),
		actionNames: Object.keys( actions ),
		has: function ( name, kind ) { return !! resolve( name, kind ); },
		getDefinition: function ( name, kind ) {
			var found = resolve( name, kind );
			return found ? JSON.parse( JSON.stringify( found ) ) : null;
		},
		render: render,
		renderAction: function ( name, options ) { return render( name, Object.assign( {}, options || {}, { kind: 'action' } ) ); },
		markup: markup
	};
} )( window );
