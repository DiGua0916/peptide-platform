/**
 * 装修模块的字段控件（EDIT-BLOCKS-03 / 04）。装修编辑器（YY 式界面）和区块编辑器共用。
 * 不需要构建，直接使用 WordPress 自带的 wp.* 全局包；导出到 window.pcModuleUI。
 *
 * 每个模块的设置面板按服务端字段定义（window.pcModules）自动生成：
 * - 字段分组成面板（内容、媒体、布局、播放、按钮、颜色、显示与间距）；
 * - device 字段分别编辑电脑 / 手机两个值，手机值为 null 表示沿用电脑；
 * - 重复条目可以添加、复制、删除、上下移动、隐藏。
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	// 媒体库：使用独立的 media-utils 组件，在区块编辑器外也能用。
	var MediaUpload = wp.mediaUtils && wp.mediaUtils.MediaUpload ? wp.mediaUtils.MediaUpload : wp.blockEditor.MediaUpload;
	var C = wp.components;
	var useSelect = wp.data.useSelect;
	var S = data.strings;

	var PALETTE = [
		{ name: 'Black', color: '#000000' },
		{ name: 'White', color: '#ffffff' },
		{ name: 'Ink', color: '#1d2327' },
		{ name: 'Gray', color: '#f3f4f6' },
		{ name: 'Teal', color: '#0f766e' },
		{ name: 'Blue', color: '#2563eb' },
		{ name: 'Orange', color: '#ee7d33' },
	];

	function uid() {
		return 'm' + Math.random().toString( 36 ).slice( 2, 12 ).replace( /[^a-z0-9]/g, '0' ).padEnd( 10, '0' );
	}

	function clone( v ) {
		return v === undefined ? undefined : JSON.parse( JSON.stringify( v ) );
	}

	function fmt( text, n ) {
		return String( text ).replace( '%d', n );
	}

	function isDeviceValue( v ) {
		return v && typeof v === 'object' && Object.prototype.hasOwnProperty.call( v, 'desktop' );
	}

	function hasDeviceFields( fields ) {
		return fields.some( function ( f ) {
			return f.device || ( f.type === 'repeater' && hasDeviceFields( f.fields || [] ) );
		} );
	}

	function newItem( defaults ) {
		return { id: uid(), enabled: true, settings: clone( defaults ) };
	}

	function withDefaults( defaults, values ) {
		return Object.assign( {}, clone( defaults ), values || {} );
	}

	function effective( value, device ) {
		return isDeviceValue( value ) ? ( device === 'mobile' && value.mobile !== null && value.mobile !== undefined ? value.mobile : value.desktop ) : value;
	}

	function visible( field, values, ctx ) {
		if ( field.uiHidden ) {
			return false;
		}
		if ( ! field.show_if ) {
			return true;
		}
		return Object.keys( field.show_if ).every( function ( key ) {
			return effective( values[ key ], ctx && ctx.device ) === field.show_if[ key ];
		} );
	}

	function plainText( value ) {
		if ( typeof value !== 'string' && typeof value !== 'number' ) { return ''; }
		var doc = new window.DOMParser().parseFromString( String( value ), 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function escapeText( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function safeHref( value ) {
		var href = String( value || '' ).trim();
		if ( ! href || /[\u0000-\u0020\u007f]/.test( href ) ) { return ''; }
		try {
			var url = new URL( href, window.location.href );
			return /^(https?:|mailto:|tel:)$/.test( url.protocol ) ? href : '';
		} catch ( error ) { return ''; }
	}

	// The visual editor never inserts arbitrary markup, attributes or executable URLs.
	// Server sanitization remains authoritative when these strings are saved/rendered.
	function safeRichHtml( value ) {
		var doc = new window.DOMParser().parseFromString( String( value || '' ), 'text/html' );
		// Match Fields::HTML_TAGS; DIV is only normalized to an allowed editor paragraph.
		var allowed = { P: 'p', DIV: 'p', BR: 'br', STRONG: 'strong', B: 'b', EM: 'em', I: 'i', U: 'u', A: 'a', UL: 'ul', OL: 'ol', LI: 'li', H3: 'h3', H4: 'h4', SPAN: 'span' };
		function clean( node ) {
			if ( node.nodeType === 3 ) { return escapeText( node.nodeValue ); }
			if ( node.nodeType !== 1 || /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|SVG|MATH|FORM|INPUT|BUTTON|TEXTAREA|SELECT|TEMPLATE)$/.test( node.nodeName ) ) { return ''; }
			var content = Array.prototype.map.call( node.childNodes, clean ).join( '' );
			var tag = allowed[ node.nodeName ];
			if ( ! tag ) { return content; }
			if ( tag === 'br' ) { return '<br>'; }
			if ( tag === 'a' ) {
				var href = safeHref( node.getAttribute( 'href' ) );
				if ( ! href ) { return content; }
				var target = node.hasAttribute( 'target' ) ? ' target="' + escapeText( node.getAttribute( 'target' ) ) + '"' : '';
				var rel = ( node.getAttribute( 'rel' ) || '' ).split( /\s+/ ).filter( function ( token ) { return token && token.toLowerCase() !== 'opener'; } );
				[ 'noopener', 'noreferrer' ].forEach( function ( token ) { if ( ! rel.some( function ( old ) { return old.toLowerCase() === token; } ) ) { rel.push( token ); } } );
				return '<a href="' + escapeText( href ) + '"' + target + ' rel="' + escapeText( rel.join( ' ' ) ) + '">' + content + '</a>';
			}
			if ( tag === 'span' && node.hasAttribute( 'class' ) ) { return '<span class="' + escapeText( node.getAttribute( 'class' ) ) + '">' + content + '</span>'; }
			return '<' + tag + '>' + content + '</' + tag + '>';
		}
		var html = Array.prototype.map.call( doc.body.childNodes, clean ).join( '' );
		return plainText( html ) ? html : '';
	}

	function fieldValue( field, values, device ) {
		var value = values[ field.key ];
		if ( ( value === null || value === undefined ) && field.legacyKey ) {
			var legacy = effective( values[ field.legacyKey ], device );
			return typeof legacy === 'string' ? escapeText( legacy ).replace( /\r?\n/g, '<br>' ) : legacy;
		}
		return value;
	}

	function options( map, withNone ) {
		var out = withNone ? [ { value: '', label: S.none } ] : [];
		Object.keys( map || {} ).forEach( function ( k ) {
			out.push( { value: k, label: map[ k ] } );
		} );
		return out;
	}

	function deviceOptions( field, value, device ) {
		var all = options( field.options ), menus = field.uiOptionsByDevice;
		var own = function ( object, key ) { return Object.prototype.hasOwnProperty.call( object, key ); };
		if ( ! menus || typeof menus !== 'object' || Array.isArray( menus ) || ( device !== 'desktop' && device !== 'mobile' ) || ! own( menus, device ) ) { return all; }
		var menu = menus[ device ];
		if ( ! menu || typeof menu !== 'object' || Array.isArray( menu ) ) { return all; }
		var requested = Object.keys( menu );
		if ( ! requested.length || requested.some( function ( key ) { return typeof menu[ key ] !== 'string'; } ) ) { return all; }
		var schema = field.options || {}, keys = requested.filter( function ( key ) { return own( schema, key ); } );
		if ( ! keys.length ) { return all; }
		// Device menus are only a presentation subset of the original schema.
		// Keep a legal stored or inherited value visible without changing it.
		var current = typeof value === 'string' || typeof value === 'number' ? String( value ) : null;
		if ( current !== null && own( schema, current ) && keys.indexOf( current ) === -1 ) { keys.push( current ); }
		return keys.map( function ( key ) { return { value: key, label: schema[ key ] }; } );
	}

	// ---------- 媒体 ----------

	function MediaThumb( props ) {
		var media = useSelect(
			function ( select ) {
				return props.id ? select( 'core' ).getMedia( props.id ) : null;
			},
			[ props.id ]
		);
		if ( ! props.id ) {
			return null;
		}
		if ( ! media ) {
			return el( C.Spinner );
		}
		if ( props.kind === 'video' ) {
			return el( 'p', { className: 'pc-me-file' }, media.title && media.title.rendered ? media.title.rendered : '#' + props.id );
		}
		var url = media.media_details && media.media_details.sizes && media.media_details.sizes.medium ? media.media_details.sizes.medium.source_url : media.source_url;
		if ( props.focal ) {
			return el( C.FocalPointPicker, {
				url: url,
				value: { x: ( props.focal.xPercent || 50 ) / 100, y: ( props.focal.yPercent || 50 ) / 100 },
				onChange: function ( p ) {
					props.onFocal( { xPercent: Math.round( p.x * 100 ), yPercent: Math.round( p.y * 100 ) } );
				},
				label: S.focal,
			} );
		}
		return el( 'img', { className: 'pc-me-thumb', src: url, alt: '' } );
	}

	function MediaPicker( props ) {
		var kind = props.kind || 'image';
		var id = props.value && props.value.attachmentId ? props.value.attachmentId : null;
		return el(
			'div',
			{ className: 'pc-me-media' },
			props.label ? el( 'p', { className: 'pc-me-label' }, props.label ) : null,
			el( MediaThumb, { id: id, kind: kind, focal: props.focal, onFocal: props.onFocal } ),
			! id && props.value && props.value.assetKey ? el( 'p', { className: 'pc-me-file' }, props.value.assetKey ) : null,
			el(
				Fragment,
				null,
				el( MediaUpload, {
					allowedTypes: [ kind ],
					value: id,
					onSelect: function ( m ) {
						var next = Object.assign( {}, props.value || {}, { attachmentId: m.id, assetKey: null } );
						if ( kind === 'image' && next.alt === '' && m.alt ) {
							next.alt = m.alt;
						}
						props.onChange( next );
					},
					render: function ( o ) {
						return el(
							'div',
							{ className: 'pc-me-row' },
							el( C.Button, { variant: 'secondary', onClick: o.open }, id ? S.replace : ( kind === 'video' ? S.chooseVideo : S.chooseImage ) ),
							id || ( props.value && props.value.assetKey )
								? el(
									C.Button,
									{
										variant: 'tertiary',
										isDestructive: true,
										onClick: function () {
											props.onClear();
										},
									},
									S.clear
								)
								: null
						);
					},
				} )
			),
			props.help ? el( 'p', { className: 'components-base-control__help' }, props.help ) : null
		);
	}

	function emptyImage() {
		return { attachmentId: null, assetKey: null, alt: '' };
	}

	function ImageField( props ) {
		var v = props.value || emptyImage();
		return el(
			Fragment,
			null,
			el( MediaPicker, {
				label: props.field.label,
				help: props.field.help,
				value: v,
				onChange: props.onChange,
				onClear: function () {
					props.onChange( emptyImage() );
				},
			} ),
			v.attachmentId || v.assetKey
				? el( C.TextControl, {
					label: S.alt,
					value: v.alt || '',
					onChange: function ( alt ) {
						props.onChange( Object.assign( {}, v, { alt: alt } ) );
					},
				} )
				: null
		);
	}

	function MediaField( props ) {
		var v = props.value || { desktop: Object.assign( emptyImage(), { fit: 'cover', focalPoint: { xPercent: 50, yPercent: 50 } } ), mobile: null };
		function set( device, next ) {
			var out = Object.assign( {}, v );
			out[ device ] = next;
			props.onChange( out );
		}
		function part( device, label ) {
			var cur = v[ device ];
			var base = cur || { attachmentId: null, assetKey: null, alt: '', fit: v.desktop.fit, focalPoint: { xPercent: 50, yPercent: 50 } };
			return el(
				'div',
				{ className: 'pc-me-media-part' },
				el( MediaPicker, {
					label: label,
					value: base,
					focal: cur && cur.attachmentId ? cur.focalPoint : null,
					onFocal: function ( fp ) {
						set( device, Object.assign( {}, base, { focalPoint: fp } ) );
					},
					onChange: function ( next ) {
						set( device, next );
					},
					onClear: function () {
						set( device, device === 'mobile' ? null : Object.assign( {}, base, emptyImage() ) );
					},
				} ),
				cur && ( cur.attachmentId || cur.assetKey )
					? el( C.TextControl, {
						label: S.alt,
						value: cur.alt || '',
						onChange: function ( alt ) {
							set( device, Object.assign( {}, cur, { alt: alt } ) );
						},
					} )
					: null
			);
		}
		return el(
			'div',
			{ className: 'pc-me-mediafield' },
			el( 'p', { className: 'pc-me-label pc-me-label--strong' }, props.field.label ),
			props.field.help ? el( 'p', { className: 'components-base-control__help' }, props.field.help ) : null,
			part( 'desktop', S.desktopImage ),
			part( 'mobile', S.mobileImage ),
			el( C.SelectControl, {
				label: S.fit,
				value: v.desktop.fit || 'cover',
				options: [
					{ value: 'cover', label: S.fitCover },
					{ value: 'contain', label: S.fitContain },
				],
				onChange: function ( fit ) {
					var out = Object.assign( {}, v, { desktop: Object.assign( {}, v.desktop, { fit: fit } ) } );
					if ( out.mobile ) {
						out.mobile = Object.assign( {}, out.mobile, { fit: fit } );
					}
					props.onChange( out );
				},
			} )
		);
	}

	// ---------- 单个字段 ----------

	function legacyRichSource( value ) {
		var doc = new window.DOMParser().parseFromString( String( value || '' ), 'text/html' );
		// Current WordPress wp_allowed_protocols(); this only chooses a text control,
		// never permits a URL in the visual editor or replaces server sanitization.
		var protocols = /^(https?|ftp|ftps|mailto|news|irc|irc6|ircs|gopher|nntp|feed|telnet|mms|rtsp|sms|svn|tel|fax|xmpp|webcal|urn)$/i;
		return Array.prototype.some.call( doc.querySelectorAll( 'a' ), function ( node ) {
			if ( ! node.hasAttribute( 'href' ) ) { return true; }
			var href = node.getAttribute( 'href' ).trim();
			if ( ! href ) { return true; }
			if ( safeHref( href ) ) { return false; }
			var scheme = /^([a-z][a-z0-9+.-]*):/i.exec( href );
			return scheme ? protocols.test( scheme[ 1 ] ) : ! /[\u0000-\u001f\u007f]/.test( href );
		} );
	}

	function HtmlField( props ) {
		var mode = useRef( null );
		if ( ! mode.current ) { mode.current = { value: props.value, scope: props.scope, source: legacyRichSource( props.value ), emitted: undefined }; }
		else if ( mode.current.value !== props.value || mode.current.scope !== props.scope ) {
			// Own keystrokes retain their control and focus; a different incoming
			// module/device value is assessed before it can reach innerHTML.
			if ( mode.current.scope !== props.scope || mode.current.emitted !== props.value ) { mode.current.source = legacyRichSource( props.value ); }
			mode.current.value = props.value;
			mode.current.scope = props.scope;
			mode.current.emitted = undefined;
		}
		function changed( value, source ) {
			if ( typeof source === 'boolean' ) { mode.current.source = source; }
			mode.current.emitted = value; props.onChange( value );
		}
		return el( RichTextField, { field: props.field, value: props.value, scope: props.scope, legacySource: mode.current.source, onChange: changed } );
	}

	function RichTextField( props ) {
		var field = props.field, editor = useRef( null ), selection = useRef( null ), host = useRef( null ), sourceInput = useRef( null );
		var id = useState( uid() )[ 0 ];
		var linkState = useState( false ), hrefState = useState( '' ), errorState = useState( '' );
		var sourceButton = useRef( null ), fullscreenButton = useRef( null ), helpButton = useRef( null );
		var fullscreenState = useState( false ), helpState = useState( false );
		var incoming = useRef( { value: props.value, scope: props.scope } ), emitted = useRef( undefined ), wasFullscreen = useRef( false );
		var focusSource = useRef( false ), returnSourceFocus = useRef( false ), returnFullscreenFocus = useRef( false );
		function textValue( value ) { return value === null || value === undefined ? '' : String( value ); }
		function draft( value ) { var text = textValue( value ); return { initial: text, value: text }; }
		var sourceState = useState( props.legacySource ? draft( props.value ) : null );
		var source = sourceState[ 0 ] || ( props.legacySource ? draft( props.value ) : null );
		var fullscreen = fullscreenState[ 0 ], sourceOpen = !! source;
		var html = safeRichHtml( props.value );
		useEffect( function () {
			if ( incoming.current.value !== props.value || incoming.current.scope !== props.scope ) {
				var ownChange = incoming.current.scope === props.scope && emitted.current === props.value;
				incoming.current = { value: props.value, scope: props.scope }; emitted.current = undefined;
				if ( ! ownChange ) {
					// A different module/device value must never inherit a pending source draft.
					focusSource.current = false; returnSourceFocus.current = false; returnFullscreenFocus.current = false;
					sourceState[ 1 ]( props.legacySource ? draft( props.value ) : null );
					fullscreenState[ 1 ]( false ); helpState[ 1 ]( false ); linkState[ 1 ]( false ); selection.current = null;
				}
			}
		}, [ props.value, props.scope ] );
		useEffect( function () {
			if ( editor.current && safeRichHtml( editor.current.innerHTML ) !== html ) { editor.current.innerHTML = html; }
		}, [ html, sourceOpen ] );
		useEffect( function () {
			if ( sourceOpen && focusSource.current && sourceInput.current ) { sourceInput.current.focus(); }
			else if ( ! sourceOpen && returnSourceFocus.current && sourceButton.current ) { sourceButton.current.focus(); }
			focusSource.current = false; returnSourceFocus.current = false;
		}, [ sourceOpen ] );
		useEffect( function () {
			if ( ! fullscreen ) {
				if ( wasFullscreen.current && returnFullscreenFocus.current && fullscreenButton.current ) { fullscreenButton.current.focus(); }
				wasFullscreen.current = false;
				returnFullscreenFocus.current = false;
				return;
			}
			wasFullscreen.current = true;
			var overflow = document.body.style.overflow;
			document.body.style.overflow = 'hidden';
			if ( sourceOpen && sourceInput.current ) { sourceInput.current.focus(); }
			else if ( editor.current ) { editor.current.focus(); }
			return function () { document.body.style.overflow = overflow; };
		}, [ fullscreen ] );
		function remember() {
			var current = window.getSelection();
			if ( current && current.rangeCount && editor.current && editor.current.contains( current.anchorNode ) && editor.current.contains( current.focusNode ) ) {
				selection.current = current.getRangeAt( 0 ).cloneRange();
			}
		}
		function restore() {
			if ( ! editor.current ) { return; }
			editor.current.focus();
			if ( selection.current && editor.current.contains( selection.current.commonAncestorContainer ) ) {
				var current = window.getSelection();
				current.removeAllRanges(); current.addRange( selection.current );
			}
		}
		function emit( value, sourceMode ) {
			emitted.current = value; props.onChange( value, sourceMode );
		}
		function changed() {
			emit( safeRichHtml( editor.current.innerHTML ) );
			remember();
		}
		function command( name, argument ) {
			restore();
			document.execCommand( name, false, argument || null );
			changed();
		}
		function formatButton( label, text, action, extra ) {
			return el( 'button', Object.assign( { type: 'button', className: 'pc-me-richtext__tool', 'aria-label': label, title: label,
				onMouseDown: function ( event ) { event.preventDefault(); remember(); }, onClick: action }, extra || {} ), text );
		}
		function icon( name, fallback, block ) {
			var icons = window.PeptideDesignIcons;
			return icons && typeof icons.render === 'function' ? icons.render( name, { kind: block ? 'block' : 'action', size: 16 } ) : fallback;
		}
		function openSource() {
			linkState[ 1 ]( false ); helpState[ 1 ]( false );
			if ( sourceOpen ) { if ( sourceInput.current ) { sourceInput.current.focus(); } return; }
			focusSource.current = true;
			sourceState[ 1 ]( draft( props.value ) );
		}
		function cancelSource() {
			returnSourceFocus.current = ! props.legacySource;
			sourceState[ 1 ]( props.legacySource ? draft( props.value ) : null );
			if ( props.legacySource && sourceInput.current ) { sourceInput.current.focus(); }
		}
		function applySource() {
			var preserve = legacyRichSource( source.value );
			returnSourceFocus.current = ! preserve;
			// Legacy links remain inert text, preserving their original server-cleaned
			// source path. They must never be assigned to contentEditable.innerHTML.
			if ( source.value !== source.initial ) { emit( preserve ? source.value : safeRichHtml( source.value ), preserve ); }
			sourceState[ 1 ]( preserve ? draft( source.value ) : null );
			if ( preserve && sourceInput.current ) { sourceInput.current.focus(); }
		}
		function keyboard( event ) {
			if ( event.defaultPrevented ) { return; }
			if ( event.key === 'Escape' ) {
				if ( helpState[ 0 ] ) { event.preventDefault(); event.stopPropagation(); helpState[ 1 ]( false ); if ( helpButton.current ) { helpButton.current.focus(); } }
				else if ( linkState[ 0 ] ) { event.preventDefault(); event.stopPropagation(); linkState[ 1 ]( false ); restore(); }
				else if ( fullscreen ) { event.preventDefault(); event.stopPropagation(); returnFullscreenFocus.current = true; fullscreenState[ 1 ]( false ); }
				else if ( sourceOpen ) { event.preventDefault(); event.stopPropagation(); cancelSource(); }
			}
			if ( fullscreen && event.key === 'Tab' && host.current ) {
				var focusable = Array.prototype.filter.call( host.current.querySelectorAll( 'button,input,textarea,a[href],[tabindex], [contenteditable="true"]' ), function ( node ) {
					// Native Tab treats the editable host as one stop, skipping its links.
					return ! ( editor.current && node !== editor.current && editor.current.contains( node ) ) && ! node.disabled && ( node.tabIndex !== -1 || node.isContentEditable && ! node.hasAttribute( 'tabindex' ) ) && node.getClientRects().length;
				} );
				var first = focusable[ 0 ], last = focusable[ focusable.length - 1 ];
				if ( first && ( event.shiftKey && document.activeElement === first || ! event.shiftKey && document.activeElement === last ) ) { event.preventDefault(); ( event.shiftKey ? last : first ).focus(); }
			}
		}
		return el( Fragment, null,
			fullscreen ? el( 'div', { className: 'pc-me-richtext__backdrop', 'aria-hidden': true } ) : null,
			el( 'div', { ref: host, className: 'pc-me-richtext' + ( fullscreen ? ' is-fullscreen' : '' ), onKeyDown: keyboard,
				role: fullscreen ? 'dialog' : undefined, 'aria-modal': fullscreen ? true : undefined, 'aria-labelledby': fullscreen ? id + '-label' : undefined },
			el( 'label', { id: id + '-label', className: 'pc-me-label', htmlFor: id }, field.label ),
			el( 'div', { className: 'pc-me-richtext__box' },
				el( 'div', { className: 'pc-me-richtext__toolbar', role: 'toolbar', 'aria-label': field.label + '格式' },
					formatButton( '加粗', el( 'strong', null, 'B' ), function () { command( 'bold' ); }, { disabled: sourceOpen } ),
					formatButton( '斜体', el( 'em', null, 'I' ), function () { command( 'italic' ); }, { disabled: sourceOpen } ),
					formatButton( '添加链接', icon( 'link', '链接' ), function () { linkState[ 1 ]( ! linkState[ 0 ] ); errorState[ 1 ]( '' ); }, { disabled: sourceOpen } ),
					formatButton( '清除格式', icon( 'clear', '清除格式' ), function () { command( 'removeFormat' ); command( 'unlink' ); }, { disabled: sourceOpen } ),
					el( 'span', { className: 'pc-me-richtext__separator', 'aria-hidden': true } ),
					formatButton( 'HTML 源码', icon( 'custom_html', 'HTML', true ), openSource, { ref: sourceButton, 'aria-pressed': sourceOpen, 'aria-controls': id + '-source' } ),
					formatButton( fullscreen ? '退出编辑全屏' : '编辑全屏', icon( fullscreen ? 'collapse_fullscreen' : 'fullscreen', fullscreen ? '退出全屏' : '全屏' ), function () { returnFullscreenFocus.current = fullscreen; fullscreenState[ 1 ]( ! fullscreen ); }, { ref: fullscreenButton, 'aria-pressed': fullscreen } ),
					formatButton( '富文本帮助', icon( 'help', '帮助' ), function () { helpState[ 1 ]( ! helpState[ 0 ] ); }, { ref: helpButton, 'aria-expanded': helpState[ 0 ], 'aria-controls': id + '-tool-help' } )
				),
				helpState[ 0 ] ? el( 'div', { id: id + '-tool-help', className: 'pc-me-richtext__help' },
					el( 'p', null, '支持加粗、斜体和链接；选中文字后使用工具栏，也可使用 Ctrl / ⌘ + B、I。' ),
					el( 'p', null, '源码先在草稿中编辑，点“应用源码”后更新内容；“取消”保留原内容。图片和文字颜色暂不支持。' ),
					el( 'p', null, 'Esc 关闭帮助或退出编辑全屏。普通源码模式下，Esc 取消本次源码修改。' )
				) : null,
				! sourceOpen && linkState[ 0 ] ? el( 'div', { className: 'pc-me-richtext__link' },
					el( 'label', { htmlFor: id + '-href', className: 'screen-reader-text' }, '链接地址' ),
					el( 'input', { id: id + '-href', type: 'text', value: hrefState[ 0 ], placeholder: '/shop/ 或 https://…', onChange: function ( event ) { hrefState[ 1 ]( event.target.value ); errorState[ 1 ]( '' ); } } ),
					el( C.Button, { variant: 'secondary', size: 'small', onClick: function () {
						var href = safeHref( hrefState[ 0 ] );
						if ( ! href ) { errorState[ 1 ]( '请输入有效的网页、邮件或电话链接。' ); return; }
						command( 'createLink', href ); linkState[ 1 ]( false ); hrefState[ 1 ]( '' );
					} }, '应用' ),
					el( C.Button, { variant: 'tertiary', size: 'small', onClick: function () { linkState[ 1 ]( false ); restore(); } }, S.cancel || '取消' ),
					errorState[ 0 ] ? el( 'p', { className: 'pc-me-error', role: 'alert' }, errorState[ 0 ] ) : null
				) : null,
				sourceOpen ? el( 'div', { key: 'source', className: 'pc-me-richtext__source' },
					el( 'label', { htmlFor: id + '-source', className: 'screen-reader-text' }, field.label + ' HTML 源码' ),
					el( 'textarea', { id: id + '-source', ref: sourceInput, className: 'pc-me-richtext__source-input', value: source.value, rows: 7, spellCheck: false,
						'aria-describedby': id + '-source-help', onChange: function ( event ) { sourceState[ 1 ]( { initial: source.initial, value: event.target.value } ); } } ),
					el( 'p', { id: id + '-source-help', className: 'pc-me-richtext__source-help', role: props.legacySource ? 'status' : undefined }, props.legacySource ? '此内容含已有链接格式，使用源码编辑以保留原格式。转换为支持的链接后，可应用并返回可视化。' : '应用时仅保留支持的富文本格式；图片和文字颜色暂不支持。' ),
					el( 'div', { className: 'pc-me-richtext__source-actions' },
						el( C.Button, { variant: 'primary', size: 'small', onClick: applySource }, '应用源码' ),
						el( C.Button, { variant: 'secondary', size: 'small', onClick: cancelSource }, S.cancel || '取消' )
					)
				) : el( 'div', { key: 'visual', id: id, ref: editor, className: 'pc-me-richtext__input', contentEditable: true, suppressContentEditableWarning: true,
					role: 'textbox', 'aria-multiline': true, 'aria-labelledby': id + '-label', 'aria-describedby': field.help ? id + '-help' : undefined,
					onInput: changed, onKeyUp: remember, onMouseUp: remember,
					onBlur: function () { var clean = safeRichHtml( editor.current.innerHTML ); if ( clean !== editor.current.innerHTML ) { editor.current.innerHTML = clean; } },
					onPaste: function ( event ) {
						event.preventDefault();
						var clipboard = event.clipboardData || window.clipboardData;
						if ( ! clipboard ) { return; }
						var pasted = clipboard.getData( 'text/html' );
						document.execCommand( 'insertHTML', false, pasted ? safeRichHtml( pasted ) : escapeText( clipboard.getData( 'text/plain' ) ).replace( /\r?\n/g, '<br>' ) ); changed();
					},
					onDrop: function ( event ) { event.preventDefault(); var text = event.dataTransfer && event.dataTransfer.getData( 'text/plain' ); if ( text ) { document.execCommand( 'insertText', false, text ); changed(); } }
				} )
			),
			field.tokens && field.tokens.length ? el( 'p', { className: 'pc-me-template-tokens' }, '可用变量：', field.tokens.map( function ( token ) { return el( 'code', { key: token }, token ); } ) ) : null,
			field.help ? el( 'p', { id: id + '-help', className: 'pc-me-help' }, field.help ) : null
			)
		);
	}

	function NumberField( props ) {
		var field = props.field, device = props.device || 'desktop', seconds = field.ui === 'seconds';
		var scale = seconds ? 1000 : 1, unit = seconds ? '秒' : ( field.unit || ( /Px$/.test( field.key ) ? 'px' : '' ) );
		var id = useState( uid() )[ 0 ];
		var value = props.value === null || props.value === undefined ? '' : Number( props.value ) / scale;
		var min = field.min === undefined ? undefined : field.min / scale;
		var max = field.max === undefined ? undefined : field.max / scale;
		var step = field.step === undefined ? ( seconds ? 0.1 : 1 ) : field.step / scale;
		var uiMin = field.uiMin === undefined ? min : effective( field.uiMin, device ) / scale;
		var uiMax = field.uiMax === undefined ? max : effective( field.uiMax, device ) / scale;
		var ranged = field.ui === 'range' || field.ui === 'slider' || seconds || ( ! field.ui && ! unit && Number.isInteger( min ) && Number.isInteger( max ) && max - min <= 12 );
		ranged = ranged && Number.isFinite( uiMin ) && Number.isFinite( uiMax );
		function change( raw, commit ) {
			if ( raw === '' ) { if ( props.value !== null && props.value !== undefined ) { props.onChange( null ); } return; }
			var number = Number( raw );
			if ( ! Number.isFinite( number ) ) { return; }
			if ( commit ) { if ( min !== undefined ) { number = Math.max( min, number ); } if ( max !== undefined ) { number = Math.min( max, number ); } }
			var next = seconds ? Math.round( number * scale ) : number;
			if ( next !== props.value ) { props.onChange( next ); }
		}
		return el( 'div', { className: 'pc-me-number' + ( ranged ? ' pc-me-number--range' : '' ) },
			el( 'label', { className: 'pc-me-label', htmlFor: id }, field.label ),
			el( 'div', { className: 'pc-me-number__row' },
				ranged ? el( 'input', { className: 'pc-me-number__range', type: 'range', 'aria-label': field.label + '滑块',
					min: value === '' ? uiMin : Math.min( uiMin, value ), max: value === '' ? uiMax : Math.max( uiMax, value ), step: step,
					value: value === '' ? uiMin : value, onChange: function ( event ) { change( event.target.value, true ); } } ) : null,
				el( 'div', { className: 'pc-me-number__value' },
					el( 'input', { id: id, type: 'number', value: value, min: min, max: max, step: step,
						'aria-describedby': field.help ? id + '-help' : undefined,
						onChange: function ( event ) { change( event.target.value, false ); }, onBlur: function ( event ) { change( event.target.value, true ); } } ),
					unit ? el( 'span', { className: 'pc-me-number__unit' }, unit ) : null
				),
				value !== '' && field.default === null ? el( C.Button, { variant: 'tertiary', size: 'small', className: 'pc-me-number__reset', onClick: function () { props.onChange( null ); } }, '默认' ) : null
			),
			field.help ? el( 'p', { id: id + '-help', className: 'pc-me-help' }, field.help ) : null
		);
	}

	function ToggleField( props ) {
		var id = useState( uid() )[ 0 ];
		return el( 'div', { className: 'pc-me-toggle' },
			el( 'label', { className: 'pc-me-toggle__row', htmlFor: id },
				el( 'span', { className: 'pc-me-label' }, props.field.label ),
				el( 'span', { className: 'pc-me-toggle__switch' },
					el( 'input', { id: id, type: 'checkbox', role: 'switch', checked: !! props.value, 'aria-describedby': props.field.help ? id + '-help' : undefined, onChange: function ( event ) { props.onChange( event.target.checked ); } } ),
					el( 'span', { className: 'pc-me-toggle__track', 'aria-hidden': true } )
				)
			),
			props.field.help ? el( 'p', { id: id + '-help', className: 'pc-me-help' }, props.field.help ) : null
		);
	}

	function ColorField( props ) {
		var field = props.field, id = useState( uid() )[ 0 ];
		return el( 'div', { className: 'pc-me-color' },
			el( 'label', { className: 'pc-me-label', htmlFor: id }, field.label ),
			el( C.Dropdown, { className: 'pc-me-color__dropdown', contentClassName: 'pc-me-color__popover',
				renderToggle: function ( state ) { return el( 'button', { id: id, type: 'button', className: 'pc-me-color__trigger', 'aria-expanded': state.isOpen, 'aria-label': field.label + '：' + ( props.value || '默认' ), onClick: state.onToggle },
					el( 'span', { className: 'pc-me-color__swatch', style: props.value ? { backgroundColor: props.value } : undefined, 'aria-hidden': true } ),
					el( 'span', null, props.value || '默认' )
				); },
				renderContent: function () { return el( C.ColorPalette, { colors: PALETTE, value: props.value || undefined, clearable: true, onChange: function ( color ) { props.onChange( color || '' ); } } ); }
			} ),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null
		);
	}

	var recordLabels = Object.create( null ), recordLabelVersions = Object.create( null ), recordLabelTimes = Object.create( null );
	var recordLabelListeners = Object.create( null ), recordLabelPending = Object.create( null ), recordLabelQueues = Object.create( null );
	var recordRequestVersion = 0;

	function settleRecordLabel( entry, row, error ) {
		if ( entry.settled ) { return; }
		entry.settled = true;
		if ( recordLabelPending[ entry.key ] === entry ) { delete recordLabelPending[ entry.key ]; }
		if ( error ) { entry.reject( error ); } else { entry.resolve( row ); }
	}

	function cacheRecordOptions( resource, rows, version ) {
		( rows || [] ).forEach( function ( row ) {
			var key = resource + ':' + row.value;
			if ( version < ( recordLabelVersions[ key ] || 0 ) ) { return; }
			recordLabels[ key ] = row; recordLabelVersions[ key ] = version; recordLabelTimes[ key ] = Date.now();
			( recordLabelListeners[ key ] || [] ).slice().forEach( function ( listener ) { listener( row ); } );
			if ( recordLabelPending[ key ] ) { settleRecordLabel( recordLabelPending[ key ], row ); }
		} );
	}

	function flushRecordLabels( resource ) {
		var queue = recordLabelQueues[ resource ];
		queue.timer = null;
		var entries = Object.keys( queue.entries ).map( function ( key ) { return queue.entries[ key ]; } ).filter( function ( entry ) { return ! entry.settled && entry.watchers > 0 && recordLabelPending[ entry.key ] === entry; } );
		queue.entries = Object.create( null );
		if ( ! entries.length ) { return; }
		var batch = entries.slice( 0, 100 ), controller = new AbortController(), version = ++recordRequestVersion;
		entries.slice( 100 ).forEach( function ( entry ) { queue.entries[ entry.key ] = entry; } );
		if ( entries.length > 100 ) { queue.timer = setTimeout( function () { flushRecordLabels( resource ); }, 0 ); }
		batch.forEach( function ( entry ) { entry.batch = { controller: controller, entries: batch }; } );
		var cfg = data.recordLookup || {}, url = new URL( cfg.url || window.ajaxurl, window.location.href );
		var params = { action: 'pc_layout_records', security: cfg.security || '', resource: resource, selected_ids: batch.map( function ( entry ) { return entry.id; } ).join( ',' ), metadata_only: 1 };
		Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
		fetch( url.href, { credentials: 'same-origin', signal: controller.signal } ).then( function ( response ) {
			return response.json().then( function ( payload ) { if ( ! response.ok || ! payload.success ) { throw new Error( '记录摘要加载失败。' ); } return payload.data; } );
		} ).then( function ( result ) {
			if ( controller.signal.aborted ) { var cancelled = new Error( 'cancelled' ); cancelled.name = 'AbortError'; throw cancelled; }
			cacheRecordOptions( resource, ( result.options || [] ).filter( function ( row ) {
				return ! batch.some( function ( entry ) { return entry.cancelled && String( entry.id ) === String( row.value ); } );
			} ), version );
			batch.forEach( function ( entry ) {
				if ( ! entry.settled ) { settleRecordLabel( entry, null, new Error( '记录摘要未返回，可重新选择条目重试。' ) ); }
			} );
		} ).catch( function ( error ) { batch.forEach( function ( entry ) { settleRecordLabel( entry, null, error ); } ); } );
	}

	// Coalesce all missing titles mounted in a render into one metadata request per
	// resource. Subscribers share pending work; an individual unmount cannot cancel
	// another title's request. Failed entries are removed, so reopening can retry.
	function loadRecordLabel( resource, id ) {
		var key = resource + ':' + id;
		if ( recordLabels[ key ] && Date.now() - recordLabelTimes[ key ] < 60000 ) { return { promise: Promise.resolve( recordLabels[ key ] ), cancel: function () {} }; }
		var entry = recordLabelPending[ key ];
		if ( ! entry ) {
			entry = { key: key, id: id, watchers: 0, settled: false };
			entry.promise = new Promise( function ( resolve, reject ) { entry.resolve = resolve; entry.reject = reject; } );
			recordLabelPending[ key ] = entry;
			var queue = recordLabelQueues[ resource ] || { entries: Object.create( null ), timer: null };
			recordLabelQueues[ resource ] = queue; queue.entries[ key ] = entry;
			if ( queue.timer === null ) { queue.timer = setTimeout( function () { flushRecordLabels( resource ); }, 0 ); }
		}
		entry.watchers++;
		var active = true;
		return { promise: entry.promise, cancel: function () {
			if ( ! active ) { return; } active = false; entry.watchers--;
			if ( ! entry.settled && entry.watchers === 0 ) {
				entry.cancelled = true;
				var error = new Error( 'cancelled' ); error.name = 'AbortError'; settleRecordLabel( entry, null, error );
				if ( entry.batch && entry.batch.entries.every( function ( item ) { return item.settled || item.watchers === 0; } ) ) { entry.batch.controller.abort(); }
			}
		} };
	}

	function subscribeRecordLabel( resource, id, callback, onError ) {
		var key = resource + ':' + id, active = true;
		var listener = function ( row ) { if ( active ) { callback( row ); } };
		var listeners = recordLabelListeners[ key ] || []; recordLabelListeners[ key ] = listeners; listeners.push( listener );
		var request = loadRecordLabel( resource, id );
		request.promise.then( listener ).catch( function ( error ) { if ( active && onError && error.name !== 'AbortError' ) { onError( error ); } } );
		return function () {
			active = false; request.cancel();
			var current = recordLabelListeners[ key ] || [], index = current.indexOf( listener ); if ( index !== -1 ) { current.splice( index, 1 ); }
			if ( ! current.length ) { delete recordLabelListeners[ key ]; }
		};
	}

	function recordUrl( resource ) {
		var paths = { promotion: 'admin.php?page=pc-promotions', coupon: 'admin.php?page=pc-coupons', shipping_discount: 'admin.php?page=pc-shipping-discounts', product_offer: 'admin.php?page=pc-promotions-product-offers', product: 'edit.php?post_type=product', review: 'edit.php?post_type=product&page=pc-product-reviews', post: 'edit.php', topic: 'edit.php?post_type=pc_topic', blog_category: 'edit-tags.php?taxonomy=category' };
		if ( ! paths[ resource ] ) { return ''; }
		return adminPath( paths[ resource ] );
	}

	function adminPath( path ) {
		var cfg = data.recordLookup || {}, base = new URL( cfg.url || window.ajaxurl || 'admin-ajax.php', window.location.href );
		var url = new URL( path, base );
		return url.origin === window.location.origin ? url.href : '';
	}

	function CollectionField( props ) {
		var termState = useState( '' ), term = termState[ 0 ];
		var name = useState( uid() )[ 0 ];
		var rows = data.categories || [];
		var selected = rows.find( function ( row ) { return String( row.value ) === String( props.value ); } );
		var list = rows.filter( function ( row ) { return String( row.label || '' ).toLocaleLowerCase().indexOf( term.trim().toLocaleLowerCase() ) !== -1; } );
		return el( 'div', { className: 'pc-me-collection' },
			el( 'p', { className: 'pc-me-label' }, props.field.label ),
			el( 'div', { className: 'pc-me-record__summary' },
				el( 'div', { className: 'pc-me-record__identity' },
					el( 'strong', null, props.value ? ( selected ? selected.label : '已选专辑（#' + props.value + '）' ) : '尚未选择专辑' ),
					selected && selected.count !== null && selected.count !== undefined && Number.isFinite( Number( selected.count ) ) ? el( 'span', { className: 'pc-me-record__meta' }, Number( selected.count ) + ' 件商品' ) : null
				),
				el( 'div', { className: 'pc-me-record__actions' },
					el( C.Dropdown, { className: 'pc-me-collection__dropdown', contentClassName: 'pc-me-collection__popover',
						renderToggle: function ( state ) { return el( C.Button, { variant: 'secondary', size: 'small', 'aria-expanded': state.isOpen, onClick: state.onToggle }, props.value ? '替换' : '选择专辑' ); },
						renderContent: function ( state ) {
							return el( 'div', { className: 'pc-me-collection__chooser' },
								el( 'h3', { className: 'pc-me-collection__title' }, '选择商品专辑' ),
								el( C.TextControl, { label: '搜索专辑', value: term, placeholder: '输入专辑名称', onChange: termState[ 1 ] } ),
								el( 'div', { className: 'pc-me-collection__columns', 'aria-hidden': true }, el( 'span', null, '专辑' ), el( 'span', null, '商品数量' ) ),
								el( 'div', { className: 'pc-me-collection__results', role: 'group', 'aria-label': '专辑搜索结果' },
									list.length ? list.map( function ( row ) {
										var knownCount = row.count !== null && row.count !== undefined && Number.isFinite( Number( row.count ) );
										return el( 'label', { key: row.value, className: 'pc-me-collection__option' },
											el( 'input', { type: 'radio', name: name, checked: String( row.value ) === String( props.value ), onChange: function () { props.onChange( Number( row.value ) ); state.onClose(); } } ),
											el( 'span', null, row.label ),
											el( 'span', { className: 'pc-me-collection__count', 'aria-label': knownCount ? Number( row.count ) + ' 件商品' : '商品数量未提供' }, knownCount ? String( Number( row.count ) ) : '—' )
										);
									} ) : el( 'p', { className: 'pc-me-record__empty' }, term ? '没有找到匹配专辑，请调整关键词。' : '暂无商品专辑，可前往管理页面创建。' )
								),
								el( 'div', { className: 'pc-me-collection__foot' }, el( 'a', { href: adminPath( 'edit-tags.php?taxonomy=product_cat&post_type=product' ), target: '_blank', rel: 'noopener noreferrer' }, '管理商品专辑' ), el( C.Button, { variant: 'tertiary', size: 'small', onClick: state.onClose }, '关闭' ) )
							);
						}
					} ),
					props.value ? el( C.Button, { variant: 'tertiary', size: 'small', onClick: function () { props.onChange( null ); } }, S.clear || '清除' ) : null
				)
			),
			props.field.help ? el( 'p', { className: 'pc-me-help' }, props.field.help ) : null
		);
	}

	function safeLinkHref( value ) {
		if ( typeof value !== 'string' || /[\x00-\x1f\x7f\\]/.test( value ) ) { return false; }
		var href = value.trim(), scheme = href.match( /^([a-z][a-z0-9+.-]*):/i );
		return ! /^\/\//.test( href ) && ( ! scheme || /^(https?|mailto|tel)$/i.test( scheme[ 1 ] ) );
	}
	function linkTarget( row ) {
		return row && typeof row.key === 'string' && typeof row.type === 'string' && typeof row.id === 'string' && typeof row.label === 'string' && typeof row.url === 'string' && !! row.url.trim() && safeLinkHref( row.url );
	}
	function linkRequest( params, signal ) {
		var cfg = data.linkLookup || {}, url;
		try { url = new URL( cfg.url, window.location.href ); if ( ! cfg.url || url.origin !== window.location.origin ) { throw new Error(); } }
		catch ( invalid ) { return Promise.reject( new Error( '链接读取地址不可用，请刷新后台。' ) ); }
		params = Object.assign( { action: 'pc_layout_link_targets', security: cfg.security || '' }, params );
		Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
		return fetch( url.href, { credentials: 'same-origin', signal: signal } ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( response.status === 401 || response.status === 403 ) { throw new Error( '登录或装修权限已失效，请刷新后台后重试。' ); }
				if ( ! response.ok || ! payload.success || ! payload.data ) { throw new Error( response.status === 404 ? '目标已删除、下架或不公开，请重新选择。' : '链接读取失败，请重试。' ); }
				return payload.data;
			} );
		} );
	}
	function LinkTargetDialog( props ) {
		var ref = useRef( null ), requestRef = useRef( 0 ), submitRef = useRef( null ), heading = useState( uid() )[ 0 ], radioName = useState( uid() )[ 0 ];
		var queryState = useState( { type: 'route', term: '', page: 1 } ), query = queryState[ 0 ];
		var resultState = useState( { options: [], more: false } ), result = resultState[ 0 ], busyState = useState( true ), busy = busyState[ 0 ];
		var errorState = useState( '' ), error = errorState[ 0 ], pickedState = useState( null ), picked = pickedState[ 0 ], retryState = useState( 0 ), confirmingState = useState( false ), confirming = confirmingState[ 0 ];
		useEffect( function () {
			var dialog = ref.current, previous = document.activeElement;
			if ( dialog ) { if ( dialog.showModal ) { dialog.showModal(); } else { dialog.setAttribute( 'open', '' ); } var first = dialog.querySelector( 'select,input,button' ); if ( first ) { first.focus(); } }
			return function () { ++requestRef.current; if ( submitRef.current ) { submitRef.current.abort(); } if ( dialog && dialog.open && dialog.close ) { dialog.close(); } if ( previous && previous.isConnected ) { previous.focus(); } };
		}, [] );
		useEffect( function () {
			var alive = true, abort = new AbortController(), request = ++requestRef.current, deadline;
			busyState[ 1 ]( true ); errorState[ 1 ]( '' );
			var timer = setTimeout( function () {
				deadline = setTimeout( function () { if ( alive && request === requestRef.current ) { errorState[ 1 ]( '链接读取超时，请重试。' ); busyState[ 1 ]( false ); abort.abort(); } }, 20000 );
				linkRequest( { type: query.type, term: query.term.trim(), paged: query.page, mode: 'list' }, abort.signal ).then( function ( payload ) {
					if ( ! alive || abort.signal.aborted || request !== requestRef.current ) { return; }
					if ( ! Array.isArray( payload.options ) ) { throw new Error( '链接结果无效，请重试。' ); }
					resultState[ 1 ]( { options: payload.options.filter( linkTarget ), more: !! payload.more } ); busyState[ 1 ]( false );
				} ).catch( function ( problem ) { if ( alive && request === requestRef.current && problem.name !== 'AbortError' ) { errorState[ 1 ]( problem.message || '链接读取失败，请重试。' ); busyState[ 1 ]( false ); } } ).finally( function () { clearTimeout( deadline ); } );
			}, 180 );
			return function () { alive = false; ++requestRef.current; clearTimeout( timer ); clearTimeout( deadline ); abort.abort(); };
		}, [ query.type, query.term, query.page, retryState[ 0 ] ] );
		function changeQuery( key, value ) { var next = Object.assign( {}, query, { page: 1 } ); next[ key ] = value; queryState[ 1 ]( next ); pickedState[ 1 ]( null ); busyState[ 1 ]( true ); }
		function move( page ) { queryState[ 1 ]( Object.assign( {}, query, { page: page } ) ); pickedState[ 1 ]( null ); busyState[ 1 ]( true ); }
		function retry() { pickedState[ 1 ]( null ); retryState[ 1 ]( retryState[ 0 ] + 1 ); }
		function confirm() {
			if ( busy || confirming || error || ! linkTarget( picked ) || ! result.options.some( function ( row ) { return row.key === picked.key; } ) ) { return; }
			var abort = new AbortController(), request = ++requestRef.current; submitRef.current = abort; confirmingState[ 1 ]( true );
			var deadline = setTimeout( function () { if ( request === requestRef.current ) { errorState[ 1 ]( '目标核验超时，请重试。' ); confirmingState[ 1 ]( false ); abort.abort(); } }, 20000 );
			linkRequest( { mode: 'resolve', type: picked.type, id: picked.id }, abort.signal ).then( function ( payload ) {
				if ( abort.signal.aborted || request !== requestRef.current ) { return; }
				if ( ! linkTarget( payload.target ) || payload.target.key !== picked.key ) { throw new Error( '目标已不可用，请重新选择。' ); }
				props.onSelect( payload.target.url ); props.onClose();
			} ).catch( function ( problem ) { if ( request === requestRef.current && problem.name !== 'AbortError' ) { errorState[ 1 ]( problem.message || '目标核验失败，请重试。' ); confirmingState[ 1 ]( false ); } } ).finally( function () { clearTimeout( deadline ); } );
		}
		function keys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); props.onClose(); return; }
			if ( event.key !== 'Tab' || ! ref.current ) { return; }
			var stops = Array.prototype.filter.call( ref.current.querySelectorAll( 'button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),[tabindex="0"]' ), function ( node ) { return !! node.getClientRects().length; } ), first = stops[ 0 ], last = stops[ stops.length - 1 ];
			if ( event.shiftKey && document.activeElement === first && last ) { event.preventDefault(); last.focus(); } else if ( ! event.shiftKey && document.activeElement === last && first ) { event.preventDefault(); first.focus(); }
		}
		return el( 'dialog', { ref: ref, className: 'pc-me-link-dialog', 'aria-labelledby': heading, 'aria-modal': true, onCancel: function ( e ) { e.preventDefault(); props.onClose(); }, onKeyDown: keys },
			el( 'header', { className: 'pc-me-link-dialog__head' }, el( 'h2', { id: heading }, '选择站内链接' ), el( C.Button, { variant: 'tertiary', onClick: props.onClose, 'aria-label': '关闭链接选择器' }, '关闭' ) ),
			el( 'div', { className: 'pc-me-link-dialog__filters' }, el( C.SelectControl, { label: '目标类型', value: query.type, disabled: confirming, options: ( data.linkLookup || {} ).types || [ { value: 'route', label: '常用页面' } ], onChange: function ( v ) { changeQuery( 'type', v ); } } ), el( C.TextControl, { label: '搜索目标', value: query.term, maxLength: 100, disabled: confirming, placeholder: '输入名称；商品也可输入 SKU', onChange: function ( v ) { changeQuery( 'term', v ); } } ) ),
			el( 'p', { className: 'pc-me-link-dialog__hint' }, '仅填入网址，不改变按钮文案。目标网址变更后可重新选择。' ),
			el( 'div', { className: 'pc-me-link-dialog__results', 'aria-busy': busy || confirming }, busy ? el( 'p', { role: 'status' }, '正在读取链接…' ) : error ? el( 'div', null, el( 'p', { role: 'alert', className: 'pc-me-error' }, error ), el( C.Button, { variant: 'secondary', onClick: retry }, '重试' ) ) : result.options.length ? result.options.map( function ( row ) { return el( 'label', { className: 'pc-me-link-dialog__row', key: row.key }, el( 'input', { type: 'radio', name: radioName, disabled: confirming, checked: !! picked && picked.key === row.key, onChange: function () { pickedState[ 1 ]( row ); } } ), el( 'span', null, el( 'strong', null, row.label || '未命名目标' ), el( 'small', null, row.url ) ) ); } ) : el( 'p', { role: 'status' }, '本页没有可选择的公开目标，请调整关键词或翻页。' ) ),
			el( 'div', { className: 'pc-me-link-dialog__pagination' }, el( C.Button, { variant: 'tertiary', disabled: query.page <= 1 || busy || confirming || !! error, onClick: function () { move( query.page - 1 ); } }, '上一页' ), el( 'span', null, '第 ' + query.page + ' 页' ), el( C.Button, { variant: 'tertiary', disabled: ! result.more || busy || confirming || !! error, onClick: function () { move( query.page + 1 ); } }, '下一页' ) ),
			el( 'footer', { className: 'pc-me-link-dialog__foot' }, el( 'p', { role: 'status' }, picked ? '已选：' + picked.label : '请选择目标，确认后才会修改链接。' ), el( 'div', null, el( C.Button, { variant: 'secondary', onClick: props.onClose }, '取消' ), el( C.Button, { variant: 'primary', disabled: busy || confirming || !! error || ! linkTarget( picked ), onClick: confirm }, confirming ? '正在核验…' : '使用此链接' ) ) )
		);
	}
	function SiteLinkInput( props ) {
		var choosingState = useState( false ), draftState = useState( props.value || '' ), errorState = useState( '' );
		useEffect( function () { draftState[ 1 ]( props.value || '' ); }, [ props.value ] );
		function change( value ) { draftState[ 1 ]( value ); if ( safeLinkHref( value ) ) { errorState[ 1 ]( '' ); props.onChange( value ); } else { errorState[ 1 ]( '仅支持 http(s)、站内路径、锚点、mailto 或 tel 链接。' ); } }
		return el( 'div', { className: 'pc-me-site-link' }, el( C.TextControl, { label: props.field.label, help: props.field.help, type: 'text', placeholder: '/shop/  ·  https://…', value: draftState[ 0 ], onChange: change } ), errorState[ 0 ] ? el( 'p', { className: 'pc-me-error', role: 'alert' }, errorState[ 0 ] ) : null, el( C.Button, { variant: 'secondary', size: 'small', 'aria-haspopup': 'dialog', 'aria-expanded': choosingState[ 0 ], onClick: function () { choosingState[ 1 ]( true ); } }, '选择站内链接' ), choosingState[ 0 ] ? el( LinkTargetDialog, { onClose: function () { choosingState[ 1 ]( false ); }, onSelect: function ( url ) { errorState[ 1 ]( '' ); draftState[ 1 ]( url ); props.onChange( url ); } } ) : null );
	}

	function reviewTitle( row ) {
		return row && typeof row.product_title === 'string' && row.product_title ? row.product_title : '商品标题未提供';
	}

	function reviewRatingOptions() {
		return [ { value: '0', label: '全部评分' }, { value: '5', label: '五星' }, { value: '4', label: '四星' }, { value: '3', label: '三星' }, { value: '2', label: '二星' }, { value: '1', label: '一星' } ];
	}

	function reviewRating( row ) {
		if ( ! row || row.rating === null || row.rating === undefined || row.rating === '' ) { return '评分未提供'; }
		var rating = Number( row.rating );
		return Number.isFinite( rating ) && rating >= 0 && rating <= 5 ? '评分 ' + rating + ' / 5' : '评分未提供';
	}

	function reviewExcerpt( row ) {
		return row && typeof row.excerpt === 'string' && row.excerpt ? row.excerpt : '评论摘要未提供';
	}

	function selectableReview( row ) {
		return row && ! row.unavailable && /^[1-9][0-9]*$/.test( String( row.value ) ) && Number.isSafeInteger( Number( row.value ) );
	}

	function ReviewDialog( props ) {
		var dialogRef = useRef( null ), requestRef = useRef( 0 ), headingId = useState( uid() )[ 0 ];
		var queryState = useState( { term: '', media: 'all', rating: '0', page: 1 } ), query = queryState[ 0 ];
		var resultState = useState( { options: [], more: false } ), result = resultState[ 0 ];
		var busyState = useState( true ), busy = busyState[ 0 ], errorState = useState( '' ), error = errorState[ 0 ];
		var retryState = useState( 0 ), candidateState = useState( null ), candidate = candidateState[ 0 ];
		var radioName = useState( uid() )[ 0 ], manage = recordUrl( 'review' );
		useEffect( function () {
			var dialog = dialogRef.current, previous = document.activeElement;
			if ( dialog ) {
				if ( typeof dialog.showModal === 'function' ) { dialog.showModal(); } else { dialog.setAttribute( 'open', '' ); }
				var first = dialog.querySelector( 'select, input, button' ); if ( first ) { first.focus(); }
			}
			return function () {
				if ( dialog && dialog.open && typeof dialog.close === 'function' ) { dialog.close(); }
				if ( previous && previous.isConnected && typeof previous.focus === 'function' ) { previous.focus(); }
			};
		}, [] );
		useEffect( function () {
			var alive = true, abort = new AbortController(), request = ++requestRef.current, deadline = null;
			busyState[ 1 ]( true ); errorState[ 1 ]( '' );
			function fail( message ) { clearTimeout( deadline ); if ( alive && request === requestRef.current ) { errorState[ 1 ]( message ); busyState[ 1 ]( false ); } }
			var timer = setTimeout( function () {
				var cfg = data.recordLookup || {}, url;
				try {
					url = new URL( cfg.url || window.ajaxurl || 'admin-ajax.php', window.location.href );
					if ( url.origin !== window.location.origin ) { throw new Error( 'origin' ); }
				} catch ( invalid ) { fail( '评价读取地址不可用，请刷新后台后重试。' ); return; }
				var version = ++recordRequestVersion;
				// Kept separate from metadata_only: a previous ID must never bypass active filters.
				var params = { action: 'pc_layout_records', security: cfg.security || '', resource: 'review', term: query.term.trim(), paged: query.page, review_media: query.media, review_rating: query.rating };
				Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
				deadline = setTimeout( function () { fail( '评价读取超时，请重试。' ); abort.abort(); }, 20000 );
				fetch( url.href, { credentials: 'same-origin', signal: abort.signal } ).then( function ( response ) {
					if ( response.status === 401 || response.status === 403 ) { throw new Error( '权限或登录状态已失效，请重新登录后重试。' ); }
					return response.json().then( function ( payload ) {
						if ( ! response.ok || ! payload.success || ! payload.data || ! Array.isArray( payload.data.options ) ) { throw new Error( '评价加载失败，请重试。' ); }
						return payload.data;
					} );
				} ).then( function ( rows ) {
					clearTimeout( deadline );
					if ( ! alive || abort.signal.aborted || request !== requestRef.current ) { return; }
					var options = rows.options.filter( selectableReview );
					cacheRecordOptions( 'review', options, version );
					resultState[ 1 ]( { options: options, more: !! rows.more } ); busyState[ 1 ]( false );
				} ).catch( function ( problem ) { clearTimeout( deadline ); if ( problem.name !== 'AbortError' ) { fail( problem.message === '权限或登录状态已失效，请重新登录后重试。' ? problem.message : '评价加载失败，请重试。' ); } } );
			}, 180 );
			return function () { alive = false; ++requestRef.current; clearTimeout( timer ); clearTimeout( deadline ); abort.abort(); };
		}, [ query.term, query.media, query.rating, query.page, retryState[ 0 ] ] );
		function filter( key, value ) {
			queryState[ 1 ]( function ( previous ) { var next = Object.assign( {}, previous, { page: 1 } ); next[ key ] = value; return next; } );
			candidateState[ 1 ]( null ); busyState[ 1 ]( true );
		}
		function movePage( page ) { queryState[ 1 ]( Object.assign( {}, query, { page: page } ) ); candidateState[ 1 ]( null ); busyState[ 1 ]( true ); }
		function select( row ) { if ( ! busy && ! error && selectableReview( row ) ) { candidateState[ 1 ]( row ); } }
		function confirm() { if ( ! busy && ! error && selectableReview( candidate ) && result.options.some( function ( row ) { return String( row.value ) === String( candidate.value ); } ) ) { props.onSelect( Number( candidate.value ) ); props.onClose(); } }
		function keys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); props.onClose(); return; }
			if ( event.key !== 'Tab' || ! dialogRef.current ) { return; }
			var stops = Array.prototype.filter.call( dialogRef.current.querySelectorAll( 'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), [tabindex="0"]' ), function ( node ) { return node.getClientRects().length; } );
			var first = stops[ 0 ], last = stops[ stops.length - 1 ];
			if ( event.shiftKey && document.activeElement === first && last ) { event.preventDefault(); last.focus(); }
			else if ( ! event.shiftKey && document.activeElement === last && first ) { event.preventDefault(); first.focus(); }
		}
		return el( 'dialog', { ref: dialogRef, className: 'pc-me-review-dialog', 'aria-labelledby': headingId, 'aria-modal': true, onCancel: function ( event ) { event.preventDefault(); props.onClose(); }, onKeyDown: keys },
			el( 'div', { className: 'pc-me-review-dialog__head' }, el( 'h2', { id: headingId }, '选择商品评论' ), el( C.Button, { variant: 'tertiary', size: 'small', label: '关闭评价选择', onClick: props.onClose }, '关闭' ) ),
			el( 'div', { className: 'pc-me-review-dialog__filters' },
				el( C.SelectControl, { label: '图片 / 视频', value: query.media, options: [ { value: 'all', label: '有图/无图' }, { value: 'with_media', label: '有图' }, { value: 'without_media', label: '无图' } ], onChange: function ( value ) { filter( 'media', value ); } } ),
				el( C.SelectControl, { label: '评分', value: query.rating, options: reviewRatingOptions(), onChange: function ( value ) { filter( 'rating', value ); } } ),
				el( C.TextControl, { label: '商品 SKU / SPU', value: query.term, placeholder: '请输入商品的 SKU / SPU', maxLength: 100, onChange: function ( value ) { filter( 'term', value ); } } )
			),
			el( 'p', { className: 'pc-me-review-dialog__hint' }, '有图：包含图片或视频；无图：两者均无。商品编号按 SKU / SPU 精确匹配。' ),
			el( 'div', { className: 'pc-me-review-dialog__table', 'aria-busy': busy },
				el( 'table', null,
					el( 'thead', null, el( 'tr', null, el( 'th', { scope: 'col', className: 'pc-me-review-dialog__radio' }, el( 'span', { className: 'screen-reader-text' }, '选择' ) ), el( 'th', { scope: 'col' }, '商品标题' ), el( 'th', { scope: 'col' }, '评论内容' ) ) ),
					el( 'tbody', null,
						busy ? el( 'tr', null, el( 'td', { colSpan: 3, className: 'pc-me-review-dialog__state', role: 'status' }, '正在加载商品评论…' ) ) : error ? el( 'tr', null, el( 'td', { colSpan: 3, className: 'pc-me-review-dialog__state' }, el( 'p', { className: 'pc-me-error', role: 'alert' }, error ), el( C.Button, { variant: 'secondary', size: 'small', onClick: function () { retryState[ 1 ]( retryState[ 0 ] + 1 ); } }, '重试' ) ) ) : result.options.length ? result.options.map( function ( row ) {
							var picked = candidate && String( candidate.value ) === String( row.value ), current = String( props.value ) === String( row.value );
							return el( 'tr', { key: row.value, className: picked ? 'is-selected' : '', onClick: function () { select( row ); } },
								el( 'td', { className: 'pc-me-review-dialog__radio' }, el( 'input', { type: 'radio', name: radioName, value: row.value, checked: !! picked, 'aria-label': '选择评价 #' + row.value + '，' + reviewTitle( row ), onChange: function () { select( row ); } } ) ),
								el( 'td', null, el( 'strong', null, reviewTitle( row ) ), el( 'span', { className: 'pc-me-record__meta' }, reviewRating( row ) + ' · #' + row.value ), current ? el( 'span', { className: 'pc-me-review-dialog__current' }, '当前关联' ) : null ),
								el( 'td', null, el( 'p', { className: 'pc-me-review-dialog__excerpt' }, reviewExcerpt( row ) ) )
							);
						} ) : el( 'tr', null, el( 'td', { colSpan: 3, className: 'pc-me-review-dialog__state', role: 'status' }, query.term || query.media !== 'all' || query.rating !== '0' ? '没有符合筛选条件的评价。请核对商品编号或调整筛选。' : '暂无可选择的商品评价。请先在商品评价管理中添加或审核评价。' ) )
					)
				)
			),
			el( 'div', { className: 'pc-me-record__pagination' }, el( C.Button, { variant: 'tertiary', size: 'small', disabled: query.page <= 1 || busy || !! error, onClick: function () { movePage( query.page - 1 ); } }, '上一页' ), el( 'span', null, '第 ' + query.page + ' 页' ), el( C.Button, { variant: 'tertiary', size: 'small', disabled: ! result.more || busy || !! error, onClick: function () { movePage( query.page + 1 ); } }, '下一页' ) ),
			el( 'div', { className: 'pc-me-review-dialog__selection', role: 'status' }, candidate ? '已选：' + reviewTitle( candidate ) + ' · 评价 #' + candidate.value : '请选择一条评价，点击添加后生效。' ),
			el( 'div', { className: 'pc-me-review-dialog__foot' }, manage ? el( 'a', { href: manage, target: '_blank', rel: 'noopener noreferrer' }, '管理商品评价' ) : el( 'span' ), el( 'div', null, el( C.Button, { variant: 'secondary', onClick: props.onClose }, '取消' ), el( C.Button, { variant: 'primary', disabled: busy || !! error || ! selectableReview( candidate ), onClick: confirm }, '添加' ) ) )
		);
	}

	function ReviewRecordField( props ) {
		var choosingState = useState( false ), retryState = useState( 0 ), selectedId = props.value ? String( props.value ) : '';
		var summaryState = useState( { id: '', row: null, loading: false, error: false } ), summary = summaryState[ 0 ];
		useEffect( function () {
			summaryState[ 1 ]( { id: selectedId, row: null, loading: !! selectedId, error: false } );
			if ( ! selectedId ) { return; }
			return subscribeRecordLabel( 'review', selectedId, function ( row ) { summaryState[ 1 ]( { id: selectedId, row: row, loading: false, error: false } ); }, function () { summaryState[ 1 ]( { id: selectedId, row: null, loading: false, error: true } ); } );
		}, [ selectedId, retryState[ 0 ] ] );
		var row = summary.id === selectedId ? summary.row : null, manage = recordUrl( 'review' );
		return el( 'div', { className: 'pc-me-record pc-me-review-record', 'data-record-resource': 'review' },
			el( 'p', { className: 'pc-me-label' }, props.field.label ),
			el( 'div', { className: 'pc-me-record__summary' },
				el( 'div', { className: 'pc-me-record__identity' },
					el( 'strong', null, selectedId ? row && ! row.unavailable ? reviewTitle( row ) : '已选评价 #' + selectedId : '尚未选择评价' ),
					selectedId ? el( 'span', { className: 'pc-me-record__meta' }, '#' + selectedId + ( row && ! row.unavailable ? ' · ' + reviewRating( row ) : '' ) ) : null,
					row && ! row.unavailable ? el( 'p', { className: 'pc-me-review-record__excerpt' }, reviewExcerpt( row ) ) : null,
					row && row.unavailable ? el( 'span', { className: 'pc-me-record__meta' }, '评价不可用，原关联已保留。' ) : null,
					selectedId && summary.id === selectedId && summary.loading ? el( 'span', { className: 'pc-me-record__meta', role: 'status' }, '正在读取评价摘要…' ) : null,
					selectedId && summary.id === selectedId && summary.error ? el( 'span', { className: 'pc-me-error', role: 'status' }, '评价摘要暂时无法读取，原关联已保留。', el( C.Button, { variant: 'link', size: 'small', onClick: function () { retryState[ 1 ]( retryState[ 0 ] + 1 ); } }, '重试摘要' ) ) : null
				),
				el( 'div', { className: 'pc-me-record__actions' }, el( C.Button, { variant: 'secondary', size: 'small', 'aria-haspopup': 'dialog', 'aria-expanded': choosingState[ 0 ], onClick: function () { choosingState[ 1 ]( true ); } }, selectedId ? '替换' : '选择商品评论' ), selectedId ? el( C.Button, { variant: 'tertiary', size: 'small', onClick: function () { props.onChange( null ); } }, S.clear || '清除' ) : null )
			),
			props.field.help ? el( 'p', { className: 'pc-me-help' }, props.field.help ) : null,
			manage ? el( 'a', { className: 'pc-me-record__manage', href: manage, target: '_blank', rel: 'noopener noreferrer' }, '管理商品评价' ) : null,
			choosingState[ 0 ] ? el( ReviewDialog, { value: props.value, onSelect: props.onChange, onClose: function () { choosingState[ 1 ]( false ); } } ) : null
		);
	}

	function RecordField( props ) {
		var field = props.field, resource = field.resource;
		var termState = useState( '' ), term = termState[ 0 ];
		var pageState = useState( 1 ), page = pageState[ 0 ];
		var resultState = useState( { options: [], more: false } ), result = resultState[ 0 ];
		var busyState = useState( true ), errorState = useState( '' );
		var choosingState = useState( ! props.value ), choosing = choosingState[ 0 ];
		var inputName = useState( uid() )[ 0 ];
		useEffect( function () {
			var alive = true, abort = new AbortController();
			busyState[ 1 ]( true ); errorState[ 1 ]( '' );
			var timer = setTimeout( function () {
				var version = ++recordRequestVersion;
				var cfg = data.recordLookup || {}, url = new URL( cfg.url || window.ajaxurl, window.location.href );
				var params = { action: 'pc_layout_records', security: cfg.security || '', resource: resource, term: term, paged: page, selected: props.value || '' };
				Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
				fetch( url.href, { credentials: 'same-origin', signal: abort.signal } ).then( function ( response ) {
					return response.json().then( function ( payload ) { if ( ! response.ok || ! payload.success ) { throw new Error( '记录加载失败，请重试。' ); } return payload.data; } );
				} ).then( function ( rows ) {
					if ( alive ) {
						cacheRecordOptions( resource, rows.options, version );
						resultState[ 1 ]( rows ); busyState[ 1 ]( false );
					}
				} ).catch( function ( error ) { if ( alive && error.name !== 'AbortError' ) { errorState[ 1 ]( '记录加载失败，请重新搜索。' ); busyState[ 1 ]( false ); } } );
			}, 180 );
			return function () { alive = false; clearTimeout( timer ); abort.abort(); };
		}, [ resource, term, page, props.value ] );
		var list = ( result.options || [] ).slice();
		var selected = list.find( function ( row ) { return String( row.value ) === String( props.value ); } ) || recordLabels[ resource + ':' + props.value ];
		var manage = recordUrl( resource );
		function choose( value ) { props.onChange( value ? Number( value ) : null ); choosingState[ 1 ]( false ); }
		return el( 'div', { className: 'pc-me-record', 'data-record-resource': resource },
			el( 'p', { className: 'pc-me-label' }, field.label ),
			el( 'div', { className: 'pc-me-record__summary' + ( selected && selected.unavailable ? ' is-unavailable' : '' ) },
				el( 'div', { className: 'pc-me-record__identity' },
					el( 'strong', null, props.value ? ( selected ? selected.label : '已选记录（#' + props.value + '）' ) : '尚未选择' ),
					selected && selected.status ? el( 'span', { className: 'pc-me-record__meta' }, String( selected.status ) ) : null,
					selected && selected.unavailable ? el( 'span', { className: 'pc-me-record__meta' }, '记录不可用，原关联已保留' ) : null
				),
				el( 'div', { className: 'pc-me-record__actions' },
					el( C.Button, { variant: 'secondary', size: 'small', 'aria-expanded': choosing, onClick: function () { choosingState[ 1 ]( ! choosing ); } }, props.value ? '更换' : '选择' ),
					props.value ? el( C.Button, { variant: 'tertiary', size: 'small', isDestructive: true, onClick: function () { choose( null ); } }, S.clear ) : null
				)
			),
			choosing ? el( 'div', { className: 'pc-me-record__chooser' },
				el( C.TextControl, { label: '搜索' + field.label, value: term, onChange: function ( value ) { termState[ 1 ]( value ); pageState[ 1 ]( 1 ); }, placeholder: '输入名称搜索' } ),
				busyState[ 0 ] ? el( 'div', { className: 'pc-me-record__loading', role: 'status' }, el( 'span', { className: 'screen-reader-text' }, '正在加载记录' ), el( 'i' ), el( 'i' ), el( 'i' ) ) : null,
				! busyState[ 0 ] && ! errorState[ 0 ] ? el( 'div', { className: 'pc-me-record__results', role: 'group', 'aria-label': field.label + '搜索结果' },
					list.length ? list.map( function ( row ) {
						return el( 'label', { key: row.value, className: 'pc-me-record__option' + ( String( props.value ) === String( row.value ) ? ' is-selected' : '' ) },
							el( 'input', { type: 'radio', name: inputName, value: row.value, checked: String( props.value ) === String( row.value ), onChange: function () { choose( row.value ); } } ),
							el( 'span', null, row.label )
						);
					} ) : el( 'p', { className: 'pc-me-record__empty' }, term ? '没有找到匹配记录，请调整关键词。' : '暂无可选记录，可前往管理页面创建。' )
				) : null,
				el( 'div', { className: 'pc-me-record__pagination' },
					el( C.Button, { variant: 'tertiary', size: 'small', disabled: page <= 1 || busyState[ 0 ], onClick: function () { pageState[ 1 ]( page - 1 ); } }, '上一页' ),
					el( 'span', null, '第 ' + page + ' 页' ),
					el( C.Button, { variant: 'tertiary', size: 'small', disabled: ! result.more || busyState[ 0 ], onClick: function () { pageState[ 1 ]( page + 1 ); } }, '下一页' )
				)
			) : null,
			errorState[ 0 ] ? el( 'p', { className: 'pc-me-error', role: 'status' }, errorState[ 0 ] ) : null,
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null,
			manage ? el( 'a', { className: 'pc-me-record__manage', href: manage, target: '_blank', rel: 'noopener noreferrer' }, '管理' + field.label ) : null
		);
	}

	function single( field, value, onChange, ctx ) {
		var f = field;
		switch ( f.type ) {
			case 'text':
				return el( C.TextControl, { label: f.label, help: f.help, value: value || '', onChange: onChange } );
			case 'textarea':
				return el( C.TextareaControl, { label: f.label, help: f.help, value: value || '', rows: 3, onChange: onChange } );
			case 'html':
				if ( f.ui === 'richtext' || f.ui === 'template' ) { return el( HtmlField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', onChange: onChange } ); }
				return el( C.TextareaControl, { label: f.label, help: f.help || S.htmlHelp, value: value || '', rows: 5, onChange: onChange } );
			case 'code':
				return el( C.TextareaControl, { className: 'pc-me-code', label: f.label, help: f.help, value: value || '', rows: 10, onChange: onChange } );
			case 'url':
				if ( f.ui === 'site-link' ) { return el( SiteLinkInput, { field: f, value: value, onChange: onChange } ); }
				return el( C.TextControl, { label: f.label, help: f.help, type: 'text', placeholder: '/shop/  ·  https://…', value: value || '', onChange: onChange } );
			case 'toggle':
				return el( ToggleField, { field: f, value: value, onChange: onChange } );
			case 'select':
				return el( C.SelectControl, { label: f.label, help: f.help, value: value, options: deviceOptions( f, value, ctx.device ), onChange: onChange } );
			case 'icon':
				return el( C.SelectControl, {
					label: f.label,
					value: value || '',
					options: [ { value: '', label: S.none } ].concat(
						data.icons.map( function ( i ) {
							return { value: i, label: i };
						} )
					),
					onChange: onChange,
				} );
			case 'number':
				return el( NumberField, { field: f, value: value, onChange: onChange, device: ctx.device } );
			case 'color':
				return el( ColorField, { field: f, value: value, onChange: onChange } );
			case 'image':
				return el( ImageField, { field: f, value: value, onChange: onChange } );
			case 'media':
				return el( MediaField, { field: f, value: value, onChange: onChange } );
			case 'video':
				return el( MediaPicker, {
					kind: 'video',
					label: f.label,
					value: value || { attachmentId: null },
					onChange: function ( v ) {
						onChange( { attachmentId: v.attachmentId } );
					},
					onClear: function () {
						onChange( { attachmentId: null } );
					},
				} );
			case 'link':
				var link = value || { label: '', href: '', target: '_self' };
				var setLink = function ( key, v ) {
					var next = Object.assign( {}, link );
					next[ key ] = v;
					onChange( next );
				};
				return el(
					'div',
					{ className: 'pc-me-link' },
					el( 'p', { className: 'pc-me-label pc-me-label--strong' }, f.label ),
					el( C.TextControl, { label: S.label, value: link.label || '', onChange: function ( v ) { setLink( 'label', v ); } } ),
					el( SiteLinkInput, { field: { label: S.href }, value: link.href || '', onChange: function ( v ) { setLink( 'href', v ); } } ),
					el( C.ToggleControl, { label: S.newTab, checked: link.target === '_blank', onChange: function ( v ) { setLink( 'target', v ? '_blank' : '_self' ); } } )
				);
			case 'menu':
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.menus ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'category':
				if ( f.ui === 'collection' ) { return el( CollectionField, { field: f, value: value, onChange: onChange } ); }
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.categories ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'product':
				return el( RecordField, { field: Object.assign( {}, f, { resource: 'product' } ), value: value, onChange: onChange } );
			case 'record':
				return el( f.resource === 'review' ? ReviewRecordField : RecordField, { field: f, value: value, onChange: onChange } );
			case 'repeater':
				return el(
					'div',
					{ className: 'pc-me-subitems' },
					el( 'p', { className: 'pc-me-label pc-me-label--strong' }, f.label ),
					el( ItemsEditor, {
						fields: f.fields || [],
						items: value || [],
						defaults: defaultsFor( f.fields || [] ),
						editorDefaults: f.editorDefaults,
						min: 0,
						max: f.max || 20,
						titleField: f.title_field || f.titleField || '',
						device: ctx.device,
						onChange: onChange,
					} )
				);
		}
		return null;
	}

	function defaultsFor( fields ) {
		var out = {};
		fields.forEach( function ( f ) {
			var d = f.default;
			if ( f.type === 'repeater' && d === null ) {
				d = [];
			}
			if ( f.type === 'link' && d === null ) {
				d = { label: '', href: '', target: '_self' };
			}
			if ( f.type === 'image' && d === null ) {
				d = emptyImage();
			}
			if ( f.device && ! isDeviceValue( d ) ) {
				d = { desktop: d, mobile: null };
			}
			out[ f.key ] = d;
		} );
		return out;
	}

	/**
	 * 一个字段（处理电脑 / 手机两个值）。
	 */
	function control( field, value, onChange, ctx ) {
		if ( ! field.device ) {
			return single( field, value, onChange, ctx );
		}
		var paired = false;
		if ( ctx.paired && field.type === 'number' && field.deviceUI === 'paired' && typeof ctx.pairedDeviceSafe === 'function' && value && typeof value === 'object' && ! Array.isArray( value ) && Object.prototype.hasOwnProperty.call( value, 'desktop' ) && Object.prototype.hasOwnProperty.call( value, 'mobile' ) && Object.keys( value ).every( function ( key ) { return key === 'desktop' || key === 'mobile'; } ) ) {
			var numeric = function ( n ) { return ( typeof n === 'number' || ( typeof n === 'string' && n.trim() !== '' ) ) && Number.isFinite( Number( n ) ); };
			try { paired = numeric( value.desktop ) && ( value.mobile === null || numeric( value.mobile ) ) && ctx.pairedDeviceSafe( field.key ) === true; } catch ( invalid ) { paired = false; }
		}
		if ( paired ) {
			var inheritedPair = value.mobile === null;
			function updateSlot( device, n ) { var next = Object.assign( {}, value ); next[ device ] = n; onChange( next ); }
			return el( 'div', { className: 'pc-me-device-pair' },
				el( NumberField, { field: Object.assign( {}, field, { label: field.label + ' · ' + ( S.desktop || '电脑' ) } ), value: Number( value.desktop ), device: 'desktop', onChange: function ( n ) { updateSlot( 'desktop', n ); } } ),
				el( 'div', null, el( NumberField, { field: Object.assign( {}, field, { label: field.label + ' · ' + ( S.mobile || '手机' ) } ), value: Number( inheritedPair ? value.desktop : value.mobile ), device: 'mobile', onChange: function ( n ) { updateSlot( 'mobile', n ); } } ),
					el( 'div', { className: 'pc-me-device-state' }, el( 'span', null, inheritedPair ? S.sameAsDesk : S.mobileOnly ), ! inheritedPair ? el( C.Button, { variant: 'link', size: 'small', onClick: function () { updateSlot( 'mobile', null ); } }, S.sameAsDesk ) : null )
				)
			);
		}
		var pairedSelect = false;
		if ( ctx.paired && field.type === 'select' && field.deviceUI === 'paired' && typeof ctx.pairedDeviceSafe === 'function' && value && typeof value === 'object' && ! Array.isArray( value ) && Object.prototype.hasOwnProperty.call( value, 'desktop' ) && Object.prototype.hasOwnProperty.call( value, 'mobile' ) && Object.keys( value ).every( function ( key ) { return key === 'desktop' || key === 'mobile'; } ) ) {
			var legalChoice = function ( choice ) { return ( typeof choice === 'string' || ( typeof choice === 'number' && Number.isFinite( choice ) ) ) && Object.prototype.hasOwnProperty.call( field.options || {}, String( choice ) ); };
			try { pairedSelect = legalChoice( value.desktop ) && ( value.mobile === null || legalChoice( value.mobile ) ) && ctx.pairedDeviceSafe( field.key ) === true; } catch ( invalid ) { pairedSelect = false; }
		}
		if ( pairedSelect ) {
			var inheritedSelect = value.mobile === null;
			function updateChoiceSlot( device, choice ) { var next = Object.assign( {}, value ); next[ device ] = choice; onChange( next ); }
			return el( 'div', { className: 'pc-me-device-pair pc-me-device-pair--select' },
				single( Object.assign( {}, field, { label: field.label + ' · ' + ( S.desktop || '电脑' ) } ), value.desktop, function ( choice ) { updateChoiceSlot( 'desktop', choice ); }, Object.assign( {}, ctx, { device: 'desktop' } ) ),
				el( 'div', null,
					single( Object.assign( {}, field, { label: field.label + ' · ' + ( S.mobile || '手机' ) } ), inheritedSelect ? value.desktop : value.mobile, function ( choice ) { updateChoiceSlot( 'mobile', choice ); }, Object.assign( {}, ctx, { device: 'mobile' } ) ),
					el( 'div', { className: 'pc-me-device-state' }, el( 'span', null, inheritedSelect ? S.sameAsDesk : S.mobileOnly ), ! inheritedSelect ? el( C.Button, { variant: 'link', size: 'small', onClick: function () { updateChoiceSlot( 'mobile', null ); } }, S.sameAsDesk ) : null )
				)
			);
		}
		var v = isDeviceValue( value ) ? value : { desktop: value, mobile: null };
		var device = ctx.device || 'desktop';
		var inherited = device === 'mobile' && ( v.mobile === null || v.mobile === undefined );
		return el(
			'div',
			{ className: 'pc-me-device-field' + ( inherited ? ' is-inherited' : '' ) },
			single(
				field,
				effective( v, device ),
				function ( n ) {
					var next = Object.assign( {}, v );
					next[ device ] = n;
					onChange( next );
				},
				ctx
			),
			device === 'mobile'
				? el( 'div', { className: 'pc-me-device-state' },
					el( 'span', null, inherited ? S.sameAsDesk : S.mobileOnly ),
					! inherited ? el( C.Button, { variant: 'link', size: 'small', onClick: function () { onChange( Object.assign( {}, v, { mobile: null } ) ); } }, S.sameAsDesk ) : null
				)
				: null
		);
	}

	function fieldList( fields, values, setValue, ctx ) {
		ctx = ctx || { device: 'desktop' };
		values = values || {};
		return fields
			.filter( function ( f ) {
				return visible( f, values, ctx );
			} )
			.map( function ( f ) {
				var overridden = ctx.overridden && ctx.overridden( f.key );
				return el(
					'div',
					{ key: f.key, className: 'pc-me-field pc-me-field--' + f.type + ( overridden ? ' is-overridden' : '' ) },
					control( f, fieldValue( f, values, ctx.device ), function ( v ) {
						setValue( f.key, v );
					}, ctx ),
					! f.device && ctx.device === 'mobile' && ctx.overridden && f.group !== 'general'
						? el(
							'div',
							{ className: 'pc-me-device-state' },
							el( 'span', null, overridden ? S.mobileOnly : S.sameAsDesk ),
							overridden && ctx.reset ? el( C.Button, { variant: 'link', size: 'small', onClick: function () { ctx.reset( f.key ); } }, S.sameAsDesk ) : null
						)
						: null
				);
			} );
	}

	// ---------- 重复条目 ----------

	function ItemTitle( props ) {
		var values = props.values, fields = props.fields || [], device = props.device || 'desktop';
		var keys = [ props.titleField, 'title', 'name', 'label', 'heading', 'text' ].filter( Boolean );
		var title = '';
		keys.some( function ( key ) {
			// A selected review ID is a reference, not the row's human-readable title.
			if ( fields.some( function ( field ) { return field.key === key && field.type === 'record' && field.resource === 'review'; } ) ) { return false; }
			title = plainText( effective( values[ key ], device ) ); return !! title;
		} );
		var recordField = fields.find( function ( field ) { return ( field.type === 'record' || field.type === 'product' ) && visible( field, values, { device: device } ) && effective( values[ field.key ], device ); } );
		var resource = recordField && ( recordField.type === 'product' ? 'product' : recordField.resource );
		var selected = recordField && effective( values[ recordField.key ], device );
		var labelKey = resource + ':' + selected;
		var labelState = useState( { key: '', label: '' } );
		useEffect( function () {
			labelState[ 1 ]( { key: labelKey, label: '' } );
			if ( title || ! resource || ! selected ) { return; }
			return subscribeRecordLabel( resource, selected, function ( row ) { labelState[ 1 ]( { key: labelKey, label: row.label } ); } );
		}, [ title, resource, selected, props.retryKey ] );
		var selectedCategory = fields.find( function ( field ) { return field.type === 'category' && visible( field, values, { device: device } ) && effective( values[ field.key ], device ); } );
		var category = selectedCategory && ( data.categories || [] ).find( function ( row ) { return String( row.value ) === String( effective( values[ selectedCategory.key ], device ) ); } );
		return el( 'span', null, title || ( category && category.label ) || ( labelState[ 0 ].key === labelKey && labelState[ 0 ].label ) || ( selected ? '已关联记录 #' + selected : props.fallback ) );
	}

	function itemFields( fields, panels, values, setValue, ctx ) {
		if ( ! panels || ( Array.isArray( panels ) && ! panels.length ) ) { return fieldList( fields, values, setValue, ctx ); }
		var descriptors = Array.isArray( panels ) ? panels : Object.keys( panels ).map( function ( key ) { return Object.assign( { id: key }, panels[ key ] ); } );
		var assigned = Object.create( null ), sections = [];
		descriptors.forEach( function ( panel ) {
			if ( ! panel || typeof panel !== 'object' ) { return; }
			var definitions = Array.isArray( panel.fields ) ? panel.fields.map( function ( key ) { return fields.find( function ( field ) { return field.key === ( typeof key === 'string' ? key : key && key.key ); } ); } ).filter( Boolean ) : fields.filter( function ( field ) { return ( field.panel || field.group || 'content' ) === panel.id; } );
			definitions = definitions.filter( function ( field ) { if ( assigned[ field.key ] ) { return false; } assigned[ field.key ] = true; return visible( field, values, ctx ); } );
			if ( definitions.length ) { sections.push( el( C.PanelBody, { key: panel.id || sections.length, className: 'pc-me-panel pc-me-item-panel', title: panel.label || panel.title || ( data.groups || {} )[ panel.id ] || panel.id, initialOpen: panel.initialOpen !== undefined ? !! panel.initialOpen : !! panel.open }, fieldList( definitions, values, setValue, ctx ) ) ); }
		} );
		var remaining = fields.filter( function ( field ) { return ! assigned[ field.key ]; } );
		if ( remaining.length ) { sections.push( el( 'div', { key: 'unassigned', className: 'pc-me-item-unassigned' }, fieldList( remaining, values, setValue, ctx ) ) ); }
		return el( 'div', { className: 'pc-me-item-panels' }, sections );
	}

	function ItemsEditor( props ) {
		var items = props.items || [];
		// 展开的条目：传入 openId / onOpen 时由外部控制（装修编辑器据此在预览里高亮对应内容）。
		var openState = useState( null );
		var controlled = typeof props.onOpen === 'function';
		var open = controlled ? props.openId : openState[ 0 ];
		var setOpen = controlled ? props.onOpen : openState[ 1 ];

		function update( index, next ) {
			var list = items.slice();
			list[ index ] = next;
			props.onChange( list );
		}
		function move( index, dir ) {
			var to = index + dir;
			if ( to < 0 || to >= items.length ) {
				return;
			}
			var list = items.slice();
			var tmp = list[ to ];
			list[ to ] = list[ index ];
			list[ index ] = tmp;
			props.onChange( list );
		}
		function duplicate( index ) {
			if ( items.length >= props.max ) {
				return;
			}
			var copy = clone( items[ index ] );
			copy.id = uid();
			( copy.settings && copy.settings.spots ? copy.settings.spots : [] ).forEach( function ( s ) {
				s.id = uid();
			} );
			var list = items.slice();
			list.splice( index + 1, 0, copy );
			props.onChange( list );
			setOpen( copy.id );
		}
		function remove( index ) {
			if ( items.length <= props.min ) {
				return;
			}
			// eslint-disable-next-line no-alert -- 后台已用页面内对话框替换原生确认框。
			if ( ! window.confirm( S.confirmDel ) ) {
				return;
			}
			var list = items.slice();
			list.splice( index, 1 );
			props.onChange( list );
		}
		function add() {
			if ( items.length >= props.max ) {
				return;
			}
			var item = newItem( withDefaults( props.defaults, props.editorDefaults ) );
			props.onChange( items.concat( [ item ] ) );
			setOpen( item.id );
		}

		return el(
			'div',
			{ className: 'pc-me-items' },
			items.map( function ( item, index ) {
				var values = withDefaults( props.defaults, item.settings );
				var isOpen = open === item.id;
				return el(
					'div',
					{ key: item.id || index, className: 'pc-me-item' + ( item.enabled === false ? ' is-hidden' : '' ) + ( isOpen ? ' is-open' : '' ) },
					el(
						'div',
						{ className: 'pc-me-item__head' },
						el(
							'button',
							{
								type: 'button',
								className: 'pc-me-item__title',
								'aria-expanded': isOpen,
								onClick: function () {
									setOpen( isOpen ? null : item.id );
								},
							},
							el( ItemTitle, { values: values, fields: props.fields, titleField: props.titleField, device: props.device, retryKey: isOpen, fallback: fmt( S.item, index + 1 ) } ),
							item.enabled === false ? el( 'em', null, ' (' + S.hidden + ')' ) : null
						),
						el( C.Button, { icon: 'arrow-up-alt2', label: S.moveUp, size: 'small', disabled: index === 0, onClick: function () { move( index, -1 ); } } ),
						el( C.Button, { icon: 'arrow-down-alt2', label: S.moveDown, size: 'small', disabled: index === items.length - 1, onClick: function () { move( index, 1 ); } } ),
						el( C.Button, { icon: 'admin-page', label: S.duplicate, size: 'small', disabled: items.length >= props.max, onClick: function () { duplicate( index ); } } ),
						el( C.Button, {
							icon: item.enabled === false ? 'hidden' : 'visibility',
							label: item.enabled === false ? S.show : S.hide,
							size: 'small',
							onClick: function () {
								update( index, Object.assign( {}, item, { enabled: item.enabled === false } ) );
							},
						} ),
						el( C.Button, { icon: 'trash', label: S.remove, size: 'small', isDestructive: true, disabled: items.length <= props.min, onClick: function () { remove( index ); } } )
					),
					isOpen
						? el(
							'div',
							{ className: 'pc-me-item__body' },
							itemFields( props.fields, props.panels, values, function ( key, v ) {
								var settings = Object.assign( {}, values );
								settings[ key ] = v;
								update( index, Object.assign( {}, item, { settings: settings } ) );
							}, { device: props.device } )
						)
						: null
				);
			} ),
			el(
				'div',
				{ className: 'pc-me-items__foot' },
				el( C.Button, { variant: 'secondary', icon: 'plus-alt2', disabled: items.length >= props.max, onClick: add }, S.add ),
				items.length >= props.max ? el( 'span', { className: 'pc-me-hint' }, fmt( S.maxItems, props.max ) ) : null,
				props.min > 0 && items.length <= props.min ? el( 'span', { className: 'pc-me-hint' }, fmt( S.minItems, props.min ) ) : null
			)
		);
	}

	/**
	 * 一个模块的全部设置面板（按分组）+ 条目面板。
	 * props: def, settings, setSetting(key, value), items, setItems(list), device
	 */
	function settingsPanels( props ) {
		var def = props.def;
		var ctx = { device: props.device || 'desktop', overridden: props.overridden, reset: props.resetOverride, paired: true, pairedDeviceSafe: props.pairedDeviceSafe };
		var definitions = def.settings || [], groups = data.groups || {}, assigned = {};
		var descriptors = props.panels || def.panels || [];
		if ( ! Array.isArray( descriptors ) ) {
			descriptors = Object.keys( descriptors ).map( function ( key ) { return Object.assign( { id: key }, descriptors[ key ] ); } );
		}
		var sections = descriptors.map( function ( panel ) {
			var keys = panel.fields;
			var fields = Array.isArray( keys ) ? keys.map( function ( key ) { return definitions.find( function ( field ) { return field.key === ( typeof key === 'string' ? key : key.key ); } ); } ).filter( Boolean ) : definitions.filter( function ( field ) { return ( field.panel || field.group || 'content' ) === panel.id; } );
			fields = fields.filter( function ( field ) { if ( assigned[ field.key ] ) { return false; } assigned[ field.key ] = true; return true; } );
			return { id: panel.id, label: panel.label || panel.title || groups[ panel.id ] || panel.id, initialOpen: panel.initialOpen !== undefined ? panel.initialOpen : !! panel.open, fields: fields };
		} );
		Object.keys( groups ).forEach( function ( group ) {
			var fields = definitions.filter( function ( field ) { return ! assigned[ field.key ] && ( field.group || 'content' ) === group; } );
			if ( fields.length ) { sections.push( { id: 'fallback-' + group, label: groups[ group ], initialOpen: group === 'content', fields: fields } ); fields.forEach( function ( field ) { assigned[ field.key ] = true; } ); }
		} );
		var remaining = definitions.filter( function ( field ) { return ! assigned[ field.key ]; } );
		if ( remaining.length ) { sections.push( { id: 'fallback-other', label: '其他设置', initialOpen: false, fields: remaining } ); }
		var panels = sections
			.map( function ( section ) {
				var fields = section.fields.filter( function ( field ) { return visible( field, props.settings || {}, ctx ); } );
				if ( ! fields.length ) {
					return null;
				}
				return el(
					C.PanelBody,
					{ key: section.id, className: 'pc-me-panel', title: section.label, initialOpen: section.initialOpen },
					fieldList( fields, props.settings, props.setSetting, ctx )
				);
			} )
			.filter( Boolean );

		if ( def.items && visible( def.items, props.settings || {}, ctx ) ) {
			panels.splice(
				1,
				0,
				el(
					C.PanelBody,
					{ key: 'items', className: 'pc-me-panel', title: def.items.label + ' (' + ( props.items || [] ).length + ')', initialOpen: true },
					el( ItemsEditor, {
						fields: def.items.fields,
						panels: def.items.panels,
						items: props.items || [],
						defaults: def.items.defaults,
						editorDefaults: def.items.editorDefaults,
						min: def.items.min || 0,
						max: def.items.max || 20,
						titleField: def.items.title_field,
						device: props.device,
						onChange: props.setItems,
						openId: props.openItem,
						onOpen: props.onOpenItem,
					} )
				)
			);
		}
		return panels;
	}

	function needsDeviceSwitch( def ) {
		var all = def.settings.concat( def.items ? def.items.fields : [] );
		return hasDeviceFields( all ) || all.some( function ( f ) {
			return f.type === 'media';
		} );
	}

	/**
	 * 新模块的初始属性：ID、默认设置、默认条目（含各条目自己的默认值，例如左、右、左）。
	 */
	function initialAttributes( def ) {
		var init = { moduleId: uid(), enabled: true, settings: Object.assign( {}, clone( def.defaults ), clone( def.editorDefaults || {} ) ), items: [] };
		if ( def.items ) {
			for ( var i = 0; i < ( def.items.default_count || 0 ); i++ ) {
				var row = newItem( withDefaults( def.items.defaults, def.items.editorDefaults ) );
				Object.assign( row.settings, clone( ( def.items.default_items || [] )[ i ] || {} ) );
				init.items.push( row );
			}
		}
		return init;
	}

	window.pcModuleUI = {
		el: el,
		uid: uid,
		clone: clone,
		withDefaults: withDefaults,
		newItem: newItem,
		fieldList: fieldList,
		ItemsEditor: ItemsEditor,
		settingsPanels: settingsPanels,
		needsDeviceSwitch: needsDeviceSwitch,
		initialAttributes: initialAttributes,
	};
} )( window.wp, window.pcModules );
