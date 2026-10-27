/**
 * 广告像素（PIXELS-01）：按后台设置加载各平台的像素，把插件给出的统一事件换成各平台的写法。
 * - 页面配置 window.pcPixels：platforms（每个平台的 ID、事件名对照、选项）、events（这一页的事件）、user（已加密的邮箱 / 电话）。
 * - 加购、注册、登录发生在请求里：Store API 购物车响应的 extensions.peptide_core.pixel_events，
 *   或经典加购（jQuery added_to_cart）之后请求 wc-ajax=pc_px_queue 取回。
 * - 结账页选择付款方式时发 add_payment_info（每页一次）。
 * - 浏览器发出 GPC（拒绝出售 / 分享）信号时完全不加载。
 * - 其他脚本可以调用 PeptidePixels.track( event ) 发同样格式的事件。
 * - 检查用：浏览器里 localStorage.pcPixelsDebug = '1' 时，每个事件和发给了哪些平台都打印在控制台。
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.pcPixels;
	if ( ! cfg || ( cfg.gpc && w.navigator.globalPrivacyControl ) ) {
		return;
	}
	var host = w.location.hostname.replace( /^www\./, '' ).toLowerCase();
	var user = cfg.user || {};

	function ids( p ) {
		return ( p.ids || [] ).filter( function ( x ) {
			return ! x.domain || x.domain === host;
		} ).map( function ( x ) {
			return x.id;
		} );
	}
	function load( src, id ) {
		if ( id && d.getElementById( id ) ) {
			return;
		}
		var s = d.createElement( 'script' );
		s.async = true;
		s.src = src;
		if ( id ) {
			s.id = id;
		}
		( d.head || d.documentElement ).appendChild( s );
	}
	function val( e, p ) {
		return Number( p && p.itemsValue ? e.items_value : e.value ) || 0;
	}
	function qty( e ) {
		return ( e.items || [] ).reduce( function ( a, i ) {
			return a + ( Number( i.quantity ) || 0 );
		}, 0 );
	}
	function pageType( e ) {
		return { view_home: 'home', view_list: 'category', view_item: 'product', search: 'searchresults', view_cart: 'cart', begin_checkout: 'cart', add_to_cart: 'cart', purchase: 'purchase' }[ e.name ] || 'other';
	}

	// gtag：Google Analytics 和 Google Ads 共用。
	var gtagReady = false;
	function gtagInit( first ) {
		w.dataLayer = w.dataLayer || [];
		if ( ! w.gtag ) {
			w.gtag = function () {
				w.dataLayer.push( arguments );
			};
		}
		if ( ! gtagReady ) {
			gtagReady = true;
			load( 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( first ) );
			w.gtag( 'js', new Date() );
		}
	}
	function gaItems( e ) {
		return ( e.items || [] ).map( function ( i ) {
			return { item_id: i.sku, item_name: i.name, item_category: i.category, item_variant: i.variant, price: i.price, quantity: i.quantity };
		} );
	}

	// ---------- 各平台 ----------

	var A = {};

	A.facebook = {
		init: function ( list ) {
			/* eslint-disable */
			!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(w,d,'script','https://connect.facebook.net/en_US/fbevents.js');
			/* eslint-enable */
			var am = {};
			[ 'em', 'ph', 'external_id' ].forEach( function ( k ) {
				if ( user[ k ] ) {
					am[ k ] = user[ k ];
				}
			} );
			list.forEach( function ( id ) {
				w.fbq( 'init', id, am );
			} );
		},
		custom: { view_list: 'ViewCategory', view_cart: 'ViewCart', place_order: 'PlaceAnOrder', login: 'Login' },
		track: function ( e, name, p ) {
			var items = e.items || [];
			var cid = items.map( function ( i ) {
				return 'sku' === p.contentId ? i.sku : i.spu;
			} );
			var params = {};
			if ( 'page_view' !== e.name ) {
				params = { value: val( e, p ), currency: e.currency };
			}
			if ( items.length ) {
				params.content_ids = cid;
				params.content_type = 'product';
				params.contents = items.map( function ( i, n ) {
					return { id: cid[ n ], quantity: i.quantity, item_price: i.price };
				} );
				params.num_items = qty( e );
				var nm = { internal: 'internal', category: 'category', spu: 'spu' }[ p.contentName ] || 'name';
				params.content_name = items[ 0 ][ nm ];
				params.content_category = items[ 0 ].category;
			}
			if ( e.search ) {
				params.search_string = e.search;
			}
			if ( e.order_id ) {
				params.order_id = e.order_id;
			}
			if ( name ) {
				w.fbq( 'track', name, params, { eventID: e.id } );
			} else if ( p.custom && this.custom[ e.name ] ) {
				w.fbq( 'trackCustom', this.custom[ e.name ], params, { eventID: e.id } );
			}
		}
	};

	A.ga4 = {
		list: [],
		init: function ( list ) {
			this.list = list;
			gtagInit( list[ 0 ] );
			var ud = user.em ? { sha256_email_address: user.em } : null;
			list.forEach( function ( id ) {
				w.gtag( 'config', id, { send_page_view: false } );
			} );
			if ( ud ) {
				w.gtag( 'set', 'user_data', ud );
			}
		},
		track: function ( e, name, p ) {
			var params = { send_to: this.list };
			if ( 'page_view' !== e.name ) {
				params.currency = e.currency;
				params.value = val( e, p );
				params.items = gaItems( e );
			}
			if ( e.search ) {
				params.search_term = e.search;
			}
			if ( e.list ) {
				params.item_list_name = e.list;
			}
			if ( 'purchase' === e.name ) {
				params.transaction_id = e.order_id;
				params.tax = e.tax;
				params.shipping = e.shipping;
			}
			if ( 'sign_up' === e.name || 'login' === e.name ) {
				params = { send_to: this.list, method: 'email' };
			}
			w.gtag( 'event', name, params );
		}
	};

	A.gads = {
		list: [],
		init: function ( list ) {
			this.list = list;
			gtagInit( list[ 0 ] );
			list.forEach( function ( id ) {
				w.gtag( 'config', id, { send_page_view: false } );
			} );
			if ( user.em ) {
				w.gtag( 'set', 'user_data', { sha256_email_address: user.em, sha256_phone_number: user.ph_plus } );
			}
		},
		track: function ( e, name, p ) {
			var items = ( e.items || [] ).map( function ( i ) {
				return { id: i.sku, google_business_vertical: 'retail' };
			} );
			// 再营销事件发给每个 AW ID。
			w.gtag( 'event', name, { send_to: this.list, value: val( e, p ), currency: e.currency, items: items } );
			var labels = [];
			if ( 'purchase' === e.name ) {
				labels = p.purchase || [];
			} else if ( p.labels && p.labels[ e.name ] ) {
				labels = [ p.labels[ e.name ] ];
			}
			labels.forEach( function ( l ) {
				var conv = { send_to: l, value: val( e, p ), currency: e.currency };
				if ( 'purchase' === e.name ) {
					conv.transaction_id = e.order_id;
				}
				w.gtag( 'event', 'conversion', conv );
			} );
		}
	};

	A.gtm = {
		init: function ( list ) {
			w.dataLayer = w.dataLayer || [];
			list.forEach( function ( id ) {
				w.dataLayer.push( { 'gtm.start': new Date().getTime(), event: 'gtm.js' } );
				load( 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent( id ), 'pc-gtm-' + id );
			} );
		},
		track: function ( e, name, p ) {
			var push = { event: name, event_id: e.id };
			if ( 'page_view' !== e.name ) {
				push.ecommerce = { currency: e.currency, value: val( e, p ), items: gaItems( e ) };
				if ( 'purchase' === e.name ) {
					push.ecommerce.transaction_id = e.order_id;
					push.ecommerce.tax = e.tax;
					push.ecommerce.shipping = e.shipping;
				}
				if ( e.search ) {
					push.search_term = e.search;
				}
				w.dataLayer.push( { ecommerce: null } );
			}
			if ( p.userData && ( user.em || user.ph_plus ) ) {
				push.user_data = { sha256_email_address: user.em, sha256_phone_number: user.ph_plus };
			}
			w.dataLayer.push( push );
		}
	};

	A.bing = {
		queues: [],
		init: function ( list ) {
			var self = this;
			list.forEach( function ( id, n ) {
				var q = n ? 'uetq' + n : 'uetq';
				self.queues.push( q );
				/* eslint-disable */
				(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:id,enableAutoSpaTracking:true};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(w,d,"script","//bat.bing.com/bat.js",q);
				/* eslint-enable */
			} );
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				return; // UET 加载时自己记录页面浏览（pageLoad）。
			}
			var params = { ecomm_pagetype: pageType( e ), ecomm_prodid: ( e.items || [] ).map( function ( i ) {
				return i.sku;
			} ), ecomm_totalvalue: val( e, p ), currency: e.currency };
			if ( 'purchase' === e.name ) {
				params.revenue_value = val( e, p );
				params.transaction_id = e.order_id;
			}
			if ( e.search ) {
				params.search_term = e.search;
			}
			this.queues.forEach( function ( q ) {
				w[ q ] = w[ q ] || [];
				w[ q ].push( 'event', name, params );
			} );
		}
	};

	A.pinterest = {
		init: function ( list ) {
			/* eslint-disable */
			!function(e){if(!w.pintrk){w.pintrk=function(){w.pintrk.queue.push(Array.prototype.slice.call(arguments))};var n=w.pintrk;n.queue=[],n.version="3.0";var t=d.createElement("script");t.async=!0,t.src=e;var r=d.getElementsByTagName("script")[0];r.parentNode.insertBefore(t,r)}}("https://s.pinimg.com/ct/core.js");
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.pintrk( 'load', id, user.em ? { em: user.em } : {} );
			} );
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				w.pintrk( 'page' );
				return;
			}
			var params = { event_id: e.id, value: val( e, p ), currency: e.currency, order_quantity: qty( e ), line_items: ( e.items || [] ).map( function ( i ) {
				return { product_id: i.sku, product_name: i.name, product_price: i.price, product_quantity: i.quantity, product_category: i.category };
			} ) };
			if ( e.order_id ) {
				params.order_id = e.order_id;
			}
			if ( e.search ) {
				params.search_query = e.search;
			}
			w.pintrk( 'track', name, params );
		}
	};

	A.tiktok = {
		init: function ( list ) {
			/* eslint-disable */
			!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};}(w,d,'ttq');
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.ttq.load( id );
			} );
			var who = {};
			if ( user.em ) {
				who.email = user.em;
			}
			if ( user.ph_plus ) {
				who.phone_number = user.ph_plus; // TikTok：E.164（带 +）再加密。
			}
			if ( user.external_id ) {
				who.external_id = user.external_id;
			}
			if ( Object.keys( who ).length ) {
				w.ttq.identify( who );
			}
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				w.ttq.page();
				return;
			}
			var params = { value: val( e, p ), currency: e.currency, content_type: 'product', contents: ( e.items || [] ).map( function ( i ) {
				return { content_id: i.spu, content_name: i.name, quantity: i.quantity, price: i.price };
			} ) };
			if ( e.search ) {
				params.query = e.search;
				params.search_string = e.search; // 2025 年 TikTok 改名为 search_string，旧名 query 也收。
			}
			w.ttq.track( name, params, { event_id: e.id } );
		}
	};

	A.snapchat = {
		init: function ( list ) {
			/* eslint-disable */
			(function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function(){a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];var s='script';var r=t.createElement(s);r.async=!0;r.src=n;var u=t.getElementsByTagName(s)[0];u.parentNode.insertBefore(r,u);})(w,d,'https://sc-static.net/scevent.min.js');
			/* eslint-enable */
			var m = {};
			if ( user.em ) {
				m.user_hashed_email = user.em;
			}
			if ( user.ph ) {
				m.user_hashed_phone_number = user.ph;
			}
			list.forEach( function ( id ) {
				w.snaptr( 'init', id, m );
			} );
		},
		track: function ( e, name, p ) {
			var params = { client_dedup_id: e.id };
			if ( 'page_view' !== e.name ) {
				params.price = val( e, p );
				params.currency = e.currency;
				params.item_ids = ( e.items || [] ).map( function ( i ) {
					return i.sku;
				} );
				params.number_items = qty( e );
			}
			if ( e.order_id ) {
				params.transaction_id = e.order_id;
			}
			if ( e.search ) {
				params.search_string = e.search;
			}
			w.snaptr( 'track', name, params );
		}
	};

	A.reddit = {
		standard: [ 'PageVisit', 'ViewContent', 'Search', 'AddToCart', 'AddToWishlist', 'Purchase', 'Lead', 'SignUp' ],
		init: function ( list ) {
			/* eslint-disable */
			!function(w,d){if(!w.rdt){var p=w.rdt=function(){p.sendEvent?p.sendEvent.apply(p,arguments):p.callQueue.push(arguments)};p.callQueue=[];var t=d.createElement("script");t.src="https://www.redditstatic.com/ads/pixel.js",t.async=!0;var s=d.getElementsByTagName("script")[0];s.parentNode.insertBefore(t,s)}}(w,d);
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.rdt( 'init', id, user.em ? { email: user.em, externalId: user.external_id } : {} );
			} );
		},
		track: function ( e, name, p ) {
			var params = { conversionId: e.id };
			if ( 'page_view' !== e.name ) {
				params.value = val( e, p );
				params.currency = e.currency;
				params.itemCount = qty( e );
				params.products = ( e.items || [] ).map( function ( i ) {
					return { id: i.sku, name: i.name, category: i.category };
				} );
			}
			if ( this.standard.indexOf( name ) === -1 ) {
				params.customEventName = name;
				w.rdt( 'track', 'Custom', params );
			} else {
				w.rdt( 'track', name, params );
			}
		}
	};

	A.taboola = {
		list: [],
		init: function ( list ) {
			this.list = list;
			w._tfa = w._tfa || [];
			list.forEach( function ( id ) {
				load( '//cdn.taboola.com/libtrc/unip/' + encodeURIComponent( id ) + '/tfa.js', 'tb_tfa_script_' + id );
			} );
		},
		track: function ( e, name, p ) {
			this.list.forEach( function ( id ) {
				var push = { notify: 'event', name: name, id: id };
				if ( 'page_view' !== e.name ) {
					push.revenue = val( e, p );
					push.currency = e.currency;
					push.quantity = qty( e );
				}
				if ( e.order_id ) {
					push.orderid = e.order_id;
				}
				w._tfa.push( push );
			} );
		}
	};

	A.outbrain = {
		init: function ( list ) {
			/* eslint-disable */
			!function(_window,_document){var OB_ADV_ID=list.length>1?list:list[0];if(_window.obApi){var toArray=function(object){return Object.prototype.toString.call(object)==='[object Array]'?object:[object];};_window.obApi.marketerId=toArray(_window.obApi.marketerId).concat(toArray(OB_ADV_ID));return;}var api=_window.obApi=function(){api.dispatch?api.dispatch.apply(api,arguments):api.queue.push(arguments);};api.version='1.1';api.loaded=true;api.marketerId=OB_ADV_ID;api.queue=[];var tag=_document.createElement('script');tag.async=true;tag.src='//amplify.outbrain.com/cp/obtp.js';tag.type='text/javascript';var script=_document.getElementsByTagName('script')[0];script.parentNode.insertBefore(tag,script);}(w,d);
			/* eslint-enable */
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				w.obApi( 'track', 'PAGE_VIEW' );
				return;
			}
			var params = { orderValue: val( e, p ), currency: e.currency };
			if ( e.order_id ) {
				params.orderId = e.order_id;
			}
			w.obApi( 'track', name, params );
		}
	};

	A.criteo = {
		list: [],
		init: function ( list ) {
			this.list = list;
			w.criteo_q = w.criteo_q || [];
			load( '//dynamic.criteo.com/js/ld/ld.js?a=' + encodeURIComponent( list[ 0 ] ), 'pc-criteo' );
		},
		track: function ( e, name ) {
			var ua = w.navigator.userAgent;
			var site = /iPad/.test( ua ) ? 't' : ( /Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Silk/.test( ua ) ? 'm' : 'd' );
			var items = ( e.items || [] ).map( function ( i ) {
				return { id: i.sku, price: i.price, quantity: i.quantity };
			} );
			var ev = { event: name };
			if ( 'viewItem' === name ) {
				ev.item = items.length ? items[ 0 ].id : '';
			} else if ( 'viewList' === name || 'viewSearchResult' === name ) {
				ev.item = items.map( function ( i ) {
					return i.id;
				} );
				if ( e.search ) {
					ev.keywords = e.search;
				}
			} else if ( 'addToCart' === name || 'viewBasket' === name ) {
				ev.item = items;
			} else if ( 'trackTransaction' === name ) {
				ev.id = e.order_id;
				ev.item = items;
			}
			this.list.forEach( function ( acc ) {
				var q = [ { event: 'setAccount', account: acc }, { event: 'setSiteType', type: site } ];
				if ( user.em ) {
					q.push( { event: 'setEmail', email: user.em, hash_method: 'sha256' } );
				}
				q.push( ev );
				w.criteo_q.push.apply( w.criteo_q, q );
			} );
		}
	};

	A.yandex = {
		list: [],
		init: function ( list ) {
			this.list = list;
			w.yandexDataLayer = w.yandexDataLayer || [];
			/* eslint-disable */
			(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<e.scripts.length;j++){if(e.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(w,d,"script","https://mc.yandex.ru/metrika/tag.js","ym");
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.ym( Number( id ), 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, ecommerce: 'yandexDataLayer' } );
			} );
		},
		track: function ( e, name ) {
			if ( 'page_view' === e.name ) {
				return; // init 时已经记录页面浏览。
			}
			var products = ( e.items || [] ).map( function ( i ) {
				return { id: i.sku, name: i.name, price: i.price, category: i.category, variant: i.variant, quantity: i.quantity };
			} );
			if ( 'detail' === name || 'add' === name ) {
				var o = { currencyCode: e.currency };
				o[ name ] = { products: products };
				w.yandexDataLayer.push( { ecommerce: o } );
			} else if ( 'purchase' === name ) {
				w.yandexDataLayer.push( { ecommerce: { currencyCode: e.currency, purchase: { actionField: { id: e.order_id, revenue: e.value }, products: products } } } );
			} else {
				this.list.forEach( function ( id ) {
					w.ym( Number( id ), 'reachGoal', name );
				} );
			}
		}
	};

	A.yahoo = {
		init: function ( list, p ) {
			this.pixel = list[ 0 ];
			this.project = p.project;
			/* eslint-disable */
			(function(w,d,t,r,u){w[u]=w[u]||[];w[u].push({'projectId':p.project,'properties':{'pixelId':list[0]}});var s=d.createElement(t);s.src=r;s.async=true;s.onload=s.onreadystatechange=function(){var y,rs=this.readyState,c=w[u];if(rs&&rs!="complete"&&rs!="loaded"){return}try{y=YAHOO.ywa.I13N.fireBeacon;w[u]=[];w[u].push=function(p){y([p])};y(c)}catch(e){}};var scr=d.getElementsByTagName(t)[0],par=scr.parentNode;par.insertBefore(s,scr)})(w,d,"script","https://s.yimg.com/wi/ytc.js","dotq");
			/* eslint-enable */
		},
		track: function ( e, name, p ) {
			var items = e.items || [];
			w.dotq = w.dotq || [];
			w.dotq.push( { projectId: this.project, properties: { pixelId: this.pixel, qstrings: { et: 'custom', ea: name, product_id: items.map( function ( i ) {
				return i.sku;
			} ).join( ',' ), gv: val( e, p ), gc: e.currency } } } );
		}
	};

	// 快手 Kwai（docs.qingque.cn 上的 Kwai for Business 文档，不用 fetch 的官方版本）：
	// 每个像素 load 一次，页面浏览 kwaiq.page()，其他事件 kwaiq.instance( id ).track()。金额只支持 BRL / IDR / USD。
	A.kwai = {
		list: [],
		init: function ( list ) {
			this.list = list;
			/* eslint-disable */
			!function(e,t){"object"==typeof exports&&"object"==typeof module?module.exports=t():"function"==typeof define&&define.amd?define([],t):"object"==typeof exports?exports.install=t():e.install=t()}(window,(function(){return function(e){var t={};function n(r){if(t[r])return t[r].exports;var o=t[r]={i:r,l:!1,exports:{}};return e[r].call(o.exports,o,o.exports,n),o.l=!0,o.exports}return n.m=e,n.c=t,n.d=function(e,t,r){n.o(e,t)||Object.defineProperty(e,t,{enumerable:!0,get:r})},n.r=function(e){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},n.t=function(e,t){if(1&t&&(e=n(e)),8&t)return e;if(4&t&&"object"==typeof e&&e&&e.__esModule)return e;var r=Object.create(null);if(n.r(r),Object.defineProperty(r,"default",{enumerable:!0,value:e}),2&t&&"string"!=typeof e)for(var o in e)n.d(r,o,function(t){return e[t]}.bind(null,o));return r},n.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return n.d(t,"a",t),t},n.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},n.p="",n(n.s=0)}([function(e,t,n){"use strict";var r=this&&this.__spreadArray||function(e,t,n){if(n||2===arguments.length)for(var r,o=0,i=t.length;o<i;o++)!r&&o in t||(r||(r=Array.prototype.slice.call(t,0,o)),r[o]=t[o]);return e.concat(r||Array.prototype.slice.call(t))};!function(e){var t=window;t.KwaiAnalyticsObject=e,t[e]=t[e]||[];var n=t[e];n.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];var o=function(e,t){e[t]=function(){var n=Array.from(arguments),o=r([t],n,!0);e.push(o)}};n.methods.forEach((function(e){o(n,e)})),n.instance=function(e){var t=n._i[e]||[];return n.methods.forEach((function(e){o(t,e)})),t},n.load=function(t,r){n._i=n._i||{},n._i[t]=[],n._i[t]._u="https://s1.kwai.net/kos/s101/nlav11187/pixel/custom/events-nr.js",n._t=n._t||{},n._t[t]=+new Date,n._o=n._o||{},n._o[t]=r||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src="https://s1.kwai.net/kos/s101/nlav11187/pixel/custom/events-nr.js?sdkid="+t+"&lib="+e;var i=document.getElementsByTagName("script")[0];i.parentNode.insertBefore(o,i)}}("kwaiq")}])}));
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.kwaiq.load( id );
			} );
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				w.kwaiq.page();
				return;
			}
			var params = {};
			var items = e.items || [];
			if ( items.length ) {
				params.contents = items.map( function ( i ) {
					return { content_id: i.spu, content_type: 'product', content_name: i.name, quantity: i.quantity, price: i.price };
				} );
				params.content_category = items[ 0 ].category;
			}
			if ( 'sign_up' !== e.name && [ 'BRL', 'IDR', 'USD' ].indexOf( e.currency ) !== -1 ) {
				params.value = val( e, p );
				params.currency = e.currency;
			}
			if ( e.search ) {
				params.query = e.search;
			}
			this.list.forEach( function ( id ) {
				w.kwaiq.instance( id ).track( name, params );
			} );
		}
	};

	// BIGO Ads（bigoads.com/help）：一个页面一个像素（用第一个），加载即记录页面浏览；浏览器端事件不带金额。
	A.bigo = {
		init: function ( list ) {
			var id = list[ 0 ];
			w.bgdataLayer = w.bgdataLayer || [];
			if ( ! w.bge ) {
				w.bge = function () {
					w.bgdataLayer.push( arguments );
				};
			}
			w.bge( 'init', id );
			/* eslint-disable */
			!function(e,t){try{if(!e)return;try{"undefined"!=typeof localStorage&&localStorage.setItem("pixel_storage_dm",e)}catch(e){}var n=document.createElement("script");n.src="https://"+e+"/ad/events.js?pixel_id="+t,n.async=!0,n.setAttribute("data-pixel-script-dm",e),(document.head||document.getElementsByTagName("head")[0]||document.body||document.documentElement).appendChild(n)}catch(e){}}(function(e){try{var t=new RegExp("(^|&)"+e+"=([^&]*)(&|$)"),n=window.location.search.substr(1).match(t);return null!=n?decodeURIComponent(n[2].replace(/\+/g," ")):""}catch(e){return""}}("dm")||"tk.mowell.tech",id);
			/* eslint-enable */
		},
		track: function ( e, name ) {
			if ( 'page_view' === e.name ) {
				return; // init 时已经记录页面浏览。
			}
			w.bge( 'event', name );
		}
	};

	// VK Ads（ads.vk.com，代码同官方 WordPress 插件 vk-ads-pixel）：每个像素一条 pageView；
	// 商品事件用 reachGoal，product_id 是商品目录里的编号。
	A.vk = {
		list: [],
		init: function ( list ) {
			this.list = list;
			w._tmr = w._tmr || [];
			/* eslint-disable */
			(function (d, w, id) {
				if (d.getElementById(id)) return;
				var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
				ts.src = "https://top-fwz1.mail.ru/js/code.js";
				var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
				if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
			})(d, w, "tmr-code");
			/* eslint-enable */
		},
		track: function ( e, name, p ) {
			var prod = ( e.items || [] ).map( function ( i ) {
				return String( i.sku );
			} );
			this.list.forEach( function ( id ) {
				if ( 'page_view' === e.name ) {
					w._tmr.push( { id: String( id ), type: 'pageView', start: ( new Date() ).getTime() } );
					return;
				}
				w._tmr.push( { type: 'reachGoal', id: String( id ), value: val( e, p ), goal: name, params: { product_id: 'purchase' === name || prod.length > 1 ? prod : prod[ 0 ] } } );
			} );
		}
	};

	// MediaGo（官方 Google 跟踪代码管理器模板 Baidu-MediaGo/mediago-ecomm-pixel 的写法）：
	// 页面浏览是 pageview；其他事件按转化类型发送，MediaGo 后台用“GTM”方式新建同类型的转化来接收。购买算重要转化。
	A.mediago = {
		list: [],
		init: function ( list ) {
			this.list = list;
			w._megoaa = w._megoaa || [];
			load( 'https://cdn.mediago.io/js/pixel.js?acid=' + encodeURIComponent( list[ 0 ] ), 'pixel_megoaa_script' );
		},
		track: function ( e, name, p ) {
			var items = ( e.items || [] ).map( function ( i ) {
				return { id: i.sku, quantity: i.quantity, category: i.category, name: i.name, price: String( i.price ) };
			} );
			var here = ( cfg.events || [] ).map( function ( x ) {
				return x.name;
			} );
			var pageType = here.indexOf( 'view_home' ) !== -1 ? 0 : here.indexOf( 'view_item' ) !== -1 ? 1 : here.indexOf( 'view_list' ) !== -1 ? 2 : here.indexOf( 'search' ) !== -1 ? 3 : 99;
			this.list.forEach( function ( acid ) {
				if ( 'page_view' === e.name ) {
					w._megoaa.push( { type: 'event', name: 'pageview', acid: acid } );
					return;
				}
				var params = { accountId: acid, pageType: pageType, importantInTotalConversion: 'purchase' === name, currency: e.currency, value: 'sign_up' === e.name ? '' : val( e, p ), conversionType: name, list: items };
				if ( items.length ) {
					params.category = items[ 0 ].category;
					params.productId = items[ 0 ].id;
					params.productName = items[ 0 ].name;
				}
				if ( e.order_id ) {
					params.orderId = e.order_id;
				}
				if ( e.search ) {
					params.query = e.search;
				}
				w._megoaa.push( { type: 'gtm', acid: acid, conversionType: name, importantInTotalConversion: params.importantInTotalConversion, value: params.value, params: params } );
			} );
		}
	};

	// X（help.x.com）：twq( 'config' ) 记录访问；其他事件用广告管理器里建好的事件 ID（tw-像素-事件），
	// 只发属于这一页已加载像素的事件 ID。conversion_id 用来去重。
	A.twitter = {
		list: [],
		init: function ( list ) {
			this.list = list.map( function ( id ) {
				return String( id ).toLowerCase();
			} );
			/* eslint-disable */
			!function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);
			},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',
			a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(w,d,'script');
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.twq( 'config', id );
			} );
		},
		track: function ( e, name, p ) {
			var self = this;
			var evIds = ( ( p.eventIds || {} )[ e.name ] || [] ).filter( function ( x ) {
				return self.list.indexOf( String( x.split( '-' )[ 1 ] || '' ).toLowerCase() ) !== -1;
			} );
			if ( ! evIds.length ) {
				return false;
			}
			var params = { conversion_id: e.id };
			if ( 'page_view' !== e.name && 'sign_up' !== e.name ) {
				params.value = val( e, p );
				params.currency = e.currency;
			}
			var items = e.items || [];
			if ( items.length ) {
				params.contents = items.map( function ( i ) {
					return { content_id: i.sku, content_name: i.name, content_price: i.price, num_items: i.quantity };
				} );
			}
			if ( e.search ) {
				params.search_string = e.search;
			}
			evIds.forEach( function ( id ) {
				w.twq( 'event', id, params );
			} );
		}
	};

	// AppLovin Axon Pixel（support.applovin.com）：填的是 Event Key，一个页面一个。
	A.applovin = {
		init: function ( list ) {
			w.AXON_EVENT_KEY = list[ 0 ];
			/* eslint-disable */
			!function(e,r){var t=["https://s.axon.ai/pixel.js","https://res4.applovin.com/p/l/loader.iife.js"];if(!e.axon){var a=e.axon=function(){a.performOperation?a.performOperation.apply(a,arguments):a.operationQueue.push(arguments)};a.operationQueue=[],a.ts=Date.now(),a.eventKey=list[0];for(var n=r.getElementsByTagName("script")[0],o=0;o<t.length;o++){var i=r.createElement("script");i.async=!0,i.src=t[o],n.parentNode.insertBefore(i,n)}}}(w,d);
			/* eslint-enable */
			w.axon( 'init' );
		},
		track: function ( e, name, p ) {
			var data = {};
			if ( user.external_id ) {
				data.user_data = { user_id: user.external_id };
			}
			if ( 'page_view' !== e.name && 'sign_up' !== e.name && 'login' !== e.name ) {
				data.currency = e.currency;
				data.value = 'purchase' === e.name ? Number( e.items_value ) || 0 : val( e, p ); // 购买：不含税和运费的小计。
				data.items = ( e.items || [] ).map( function ( i ) {
					return { item_id: i.spu, item_variant_id: i.sku, item_name: i.name, price: i.price, quantity: i.quantity };
				} );
			}
			if ( 'search' === e.name ) {
				data.search_term = e.search || '';
			}
			if ( 'sign_up' === e.name ) {
				data.method = 'email';
			}
			if ( 'purchase' === e.name ) {
				data.transaction_id = e.order_id;
				data.shipping = Number( e.shipping ) || 0;
				data.tax = Number( e.tax ) || 0;
				data.is_new_customer = !! e.first_order;
			}
			w.axon( 'track', name, data, { dedupe_id: e.id } );
		}
	};

	// NewsBreak（adhelp.newsbreak.com）：一个页面一个 ID；金额 nb_value，订单号 nb_order。
	A.newsbreak = {
		match: {},
		init: function ( list ) {
			/* eslint-disable */
			!(function(e,n,t,i,p,a,s){e[i]||(((p=e[i]=function(){p.process?p.process.apply(p,arguments):p.queue.push(arguments)}).queue=[]),(p.t=+new Date()),((a=n.createElement(t)).async=1),(a.src='https://static.newsbreak.com/business/tracking/nbpixel.js?t='+864e5*Math.ceil(new Date()/864e5)),(s=n.getElementsByTagName(t)[0]).parentNode.insertBefore(a,s));})(w,d,'script','nbpix');
			/* eslint-enable */
			if ( user.em ) {
				this.match.nb_em = user.em;
			}
			if ( user.ph ) {
				this.match.nb_ph = user.ph;
			}
			w.nbpix( 'init', list[ 0 ] );
		},
		track: function ( e, name, p ) {
			if ( 'page_view' === e.name ) {
				w.nbpix( 'event', name );
				return;
			}
			var params = {};
			var m = this.match;
			Object.keys( m ).forEach( function ( k ) {
				params[ k ] = m[ k ];
			} );
			if ( 'sign_up' !== e.name ) {
				params.nb_value = val( e, p );
			}
			if ( e.order_id ) {
				params.nb_order = e.order_id;
			}
			w.nbpix( 'event', name, params );
		}
	};

	// SmartNews Ads（help-ads.smartnews.com）：每次调用都带像素 ID。
	A.smartnews = {
		list: [],
		init: function ( list ) {
			this.list = list;
			/* eslint-disable */
			!function(){if(w.SmartnewsAds=w.SmartnewsAds||{},!w.SmartnewsAds.p){var e=w.SmartnewsAds.p=function(){e.callMethod?e.callMethod.apply(e,arguments):e.queue.push(arguments)};e.push=e,e.version="1.0.1",e.queue=[];var n=d.createElement("script");n.async=!0,n.src="https://cdn.smartnews-ads.com/i/pixel.js";var s=d.getElementsByTagName("script")[0];s.parentNode.insertBefore(n,s)}}();
			/* eslint-enable */
		},
		track: function ( e, name, p ) {
			var params = null;
			if ( 'page_view' !== e.name ) {
				var items = e.items || [];
				params = { currency: e.currency, value: val( e, p ) };
				if ( items.length ) {
					params.content_ids = items.map( function ( i ) {
						return i.sku;
					} );
					params.content_type = 'product';
					params.contents = items.map( function ( i ) {
						return { id: i.sku, quantity: i.quantity };
					} );
					params.quantity = qty( e );
				}
				if ( e.search ) {
					params.search_string = e.search;
				}
			}
			this.list.forEach( function ( id ) {
				if ( params ) {
					w.SmartnewsAds.p( id, name, params );
				} else {
					w.SmartnewsAds.p( id, name );
				}
			} );
		}
	};

	// OpenAI 广告（developers.openai.com/ads）：金额是以分为单位的整数；可以有多个像素。
	A.openai = {
		init: function ( list ) {
			/* eslint-disable */
			(function(w,d,s,u){if(w.oaiq)return;var q=function(){q.q.push(arguments);};q.q=[];w.oaiq=q;var js=d.createElement(s);js.async=true;js.src=u;var f=d.getElementsByTagName(s)[0];f.parentNode.insertBefore(js,f);})(w,d,"script","https://bzrcdn.openai.com/sdk/oaiq.min.js");
			/* eslint-enable */
			list.forEach( function ( id ) {
				w.oaiq( 'init', { pixelId: id } );
			} );
		},
		track: function ( e, name, p ) {
			var data;
			if ( 'sign_up' === e.name ) {
				data = { type: 'customer_action' };
			} else if ( 'page_view' === e.name ) {
				data = { type: 'contents', contents: [ { id: w.location.pathname, name: d.title, content_type: 'page' } ] };
			} else {
				data = { type: 'contents', amount: Math.round( val( e, p ) * 100 ), currency: e.currency, contents: ( e.items || [] ).map( function ( i ) {
					return { id: i.sku, name: i.name, content_type: 'product', quantity: i.quantity, amount: Math.round( i.price * 100 ), currency: e.currency };
				} ) };
			}
			w.oaiq( 'measure', name, data, { event_id: e.id } );
		}
	};

	// ---------- 调度 ----------

	var active = [];
	var started = false;
	var pending = [];
	var debug = false;
	try {
		debug = '1' === w.localStorage.getItem( 'pcPixelsDebug' );
	} catch ( err ) {}

	function fire( e ) {
		if ( ! e || ! e.name ) {
			return;
		}
		if ( ! started ) {
			pending.push( e );
			return;
		}
		var sent = [];
		active.forEach( function ( a ) {
			var key = a[ 0 ];
			var p = a[ 1 ];
			if ( ( e.skip || [] ).indexOf( key ) !== -1 ) {
				return;
			}
			var adapter = A[ key ];
			var name = p.events[ e.name ];
			if ( ! name && ! ( 'facebook' === key && p.custom ) ) {
				return;
			}
			try {
				if ( false !== adapter.track( e, name, p ) ) {
					sent.push( key + ':' + ( name || 'custom' ) );
				}
			} catch ( err ) {
				// 一个平台出错不影响其他平台和购物流程。
			}
		} );
		if ( debug && w.console ) {
			w.console.info( '[pc-pixels]', e.name, e.id, sent, e );
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		Object.keys( cfg.platforms || {} ).forEach( function ( key ) {
			var p = cfg.platforms[ key ];
			var list = ids( p );
			if ( list.length && A[ key ] ) {
				try {
					A[ key ].init( list, p );
					active.push( [ key, p ] );
				} catch ( err ) {}
			}
		} );
		started = true;
		( cfg.events || [] ).concat( pending ).forEach( fire );
		pending = [];
		listen();
	}

	// Store API 购物车响应里带回的事件（区块购物车、抽屉、商品页的加购）。
	function fromBody( body ) {
		if ( ! body ) {
			return;
		}
		if ( body.responses && body.responses.forEach ) {
			body.responses.forEach( function ( r ) {
				fromBody( r && r.body );
			} );
			return;
		}
		var ev = body.extensions && body.extensions.peptide_core && body.extensions.peptide_core.pixel_events;
		( ev || [] ).forEach( fire );
	}

	function listen() {
		if ( w.fetch && ! w.fetch.pcPixels ) {
			var orig = w.fetch;
			var wrapped = function ( input ) {
				var url = 'string' === typeof input ? input : ( input && input.url ) || '';
				var res = orig.apply( this, arguments );
				if ( url.indexOf( '/wc/store/' ) !== -1 ) {
					res.then( function ( r ) {
						r.clone().json().then( fromBody, function () {} );
					}, function () {} );
				}
				return res;
			};
			wrapped.pcPixels = true;
			w.fetch = wrapped;
		}
		if ( w.jQuery ) {
			w.jQuery( d.body ).on( 'added_to_cart', function () {
				w.fetch( cfg.queue, { method: 'POST', credentials: 'same-origin' } ).then( function ( r ) {
					return r.json();
				} ).then( function ( j ) {
					( j && j.data && j.data.events || [] ).forEach( fire );
				}, function () {} );
			} );
		}
		// 结账页选择付款方式。
		var paid = false;
		d.addEventListener( 'change', function ( ev ) {
			var t = ev.target;
			if ( paid || ! t || ! t.name || ( 'payment_method' !== t.name && t.name.indexOf( 'payment-method' ) === -1 ) ) {
				return;
			}
			paid = true;
			var base = ( cfg.events || [] ).filter( function ( x ) {
				return 'begin_checkout' === x.name;
			} )[ 0 ] || { items: [], value: 0, items_value: 0, currency: cfg.currency };
			fire( { name: 'add_payment_info', id: 'pay_' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 ), items: base.items, value: base.value, items_value: base.items_value, currency: base.currency } );
		}, true );
	}

	w.PeptidePixels = { track: fire, adapters: A };

	if ( 'interaction' === cfg.timing ) {
		var go = function () {
			[ 'pointerdown', 'keydown', 'scroll', 'touchstart' ].forEach( function ( ev ) {
				w.removeEventListener( ev, go, true );
			} );
			start();
		};
		[ 'pointerdown', 'keydown', 'scroll', 'touchstart' ].forEach( function ( ev ) {
			w.addEventListener( ev, go, { capture: true, passive: true } );
		} );
		w.setTimeout( go, 5000 );
	} else {
		start();
	}
}( window, document ) );
