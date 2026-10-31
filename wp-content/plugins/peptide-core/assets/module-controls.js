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

	function newItem( defaults, nestedDefaults ) {
		var item = { id: uid(), enabled: true, settings: clone( defaults ) };
		Object.keys( nestedDefaults || {} ).forEach( function ( key ) {
			if ( Array.isArray( nestedDefaults[ key ] ) ) {
				item.settings[ key ] = nestedDefaults[ key ].map( function ( values ) { return newItem( values ); } );
			}
		} );
		return item;
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
	function safeRichHtml( value, policy ) {
		if ( policy && policy.color === true ) { return safeColorRichHtml( value, policy.legacy === true ); }
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

	var ColorOperation = ( function () {
  function hex(value) {
    var text=String(value||'').trim(), match;
    if(/^#[0-9a-f]{3}$/i.test(text)) return '#'+text.slice(1).split('').map(function(c){return c+c;}).join('').toLowerCase();
    if(/^#[0-9a-f]{6}$/i.test(text)) return text.toLowerCase();
    match=/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i.exec(text);
    if(match&&match.slice(1).every(function(v){return Number(v)<=255;})) return '#'+match.slice(1).map(function(v){return Number(v).toString(16).padStart(2,'0');}).join('');
    return null;
  }
  function styleColor(value) {
    var match=/^\s*color\s*:\s*([^;]+)\s*;?\s*$/i.exec(String(value||''));
    return match?hex(match[1]):null;
  }
  function offsets(editor,range) {
    if(!range||range.collapsed||!editor.contains(range.startContainer)||!editor.contains(range.endContainer)) return null;
    var prefix=range.cloneRange();prefix.selectNodeContents(editor);prefix.setEnd(range.startContainer,range.startOffset);
    var start=prefix.toString().length;
    return {start:start,end:start+range.toString().length};
  }
  function restore(editor,selection) {
    var walker=document.createTreeWalker(editor,NodeFilter.SHOW_TEXT),node,at=0,start=null,end=null;
    while((node=walker.nextNode())) {
      var next=at+node.nodeValue.length;
      if(!start&&selection.start<=next) start={node:node,offset:Math.max(0,selection.start-at)};
      if(!end&&selection.end<=next) {end={node:node,offset:Math.max(0,selection.end-at)};break;}
      at=next;
    }
    if(!start||!end) return false;
    var range=document.createRange();range.setStart(start.node,start.offset);range.setEnd(end.node,end.offset);
    editor.focus();var current=getSelection();current.removeAllRanges();current.addRange(range);return true;
  }
  function build(editor,selection,color) {
    if(!selection||selection.start<0||selection.end<=selection.start||selection.end>editor.textContent.length) return null;
    if(color!==null&&!hex(color)) return null;
    var at=0,doc=editor.ownerDocument,unsupported=false;
    function zone(offset) {return offset<selection.start?'before':offset>=selection.end?'after':'selected';}
    function walk(node) {
      if(node.nodeType===3) {
        var text=node.nodeValue,start=at,end=at+text.length;at=end;
        var cuts=[0,Math.max(0,Math.min(text.length,selection.start-start)),Math.max(0,Math.min(text.length,selection.end-start)),text.length].filter(function(n,i,a){return a.indexOf(n)===i;}).sort(function(a,b){return a-b;});
        var segments=[];
        for(var i=0;i<cuts.length-1;i++) {
          var left=cuts[i],right=cuts[i+1];if(right===left)continue;
          var selected=start+left>=selection.start&&start+right<=selection.end;
          var ink=selected?color:null,child=doc.createTextNode(text.slice(left,right));
          if(ink) {var span=doc.createElement('span');span.setAttribute('style','color:'+hex(ink));span.appendChild(child);child=span;}
          segments.push({zone:zone(start+left),node:child});
        }
        return segments;
      }
      if(node.nodeType!==1)return [];
      var name=node.nodeName,block=/^(P|DIV|UL|OL|LI|H3|H4)$/.test(name),segments=[];
      Array.prototype.forEach.call(node.childNodes,function(child){segments=segments.concat(walk(child));});
      if(block){var container=node.cloneNode(false);segments.forEach(function(item){container.appendChild(item.node);});return [{zone:'block',node:container}];}
      if(segments.some(function(item){return item.zone==='block';})){unsupported=true;return [];}
      if(!segments.length)segments=[{zone:zone(at),node:doc.createDocumentFragment()}];
      var groups=[];
      segments.forEach(function(item){var last=groups[groups.length-1];if(!last||last.zone!==item.zone){last={zone:item.zone,items:[]};groups.push(last);}last.items.push(item.node);});
      return groups.map(function(group){
        var output=name==='FONT'?doc.createElement('span'):node.cloneNode(false);
        if(name==='FONT'){var old=styleColor(node.getAttribute('style'))||hex(node.getAttribute('color'));if(old)output.setAttribute('style','color:'+old);}
        if(group.zone==='selected'&&(name==='SPAN'||name==='FONT')){output.removeAttribute('style');output.removeAttribute('color');}
        group.items.forEach(function(child){output.appendChild(child);});
        if(output.nodeName==='SPAN'&&!output.attributes.length){var fragment=doc.createDocumentFragment();while(output.firstChild)fragment.appendChild(output.firstChild);output=fragment;}
        return {zone:group.zone,node:output};
      });
    }
    var root=doc.createElement('div');Array.prototype.forEach.call(editor.childNodes,function(node){walk(node).forEach(function(item){root.appendChild(item.node);});});
    return unsupported?null:root.innerHTML;
  }
  function apply(editor,selection,color,sanitize) {
    var html=build(editor,selection,color);if(html===null)return false;
    if(sanitize)html=sanitize(html);
    var all=document.createRange();all.selectNodeContents(editor);editor.focus();var current=getSelection();current.removeAllRanges();current.addRange(all);
    if(!document.execCommand('insertHTML',false,html))return false;
    restore(editor,selection);return true;
  }
		return { hex: hex, styleColor: styleColor, offsets: offsets, restore: restore, build: build, apply: apply };
	} )();

	function colorField( field ) { return field && field.type === 'html' && field.ui === 'richtext'; }
	function hasRichColor( doc ) {
		return Array.prototype.some.call( doc.querySelectorAll( 'span,font' ), function ( node ) { return ColorOperation.styleColor( node.getAttribute( 'style' ) ) || ( node.nodeName === 'FONT' && ColorOperation.hex( node.getAttribute( 'color' ) ) ); } );
	}
	// Completeness guard only; never uses string replacement to parse or rewrite HTML.
	function colorInputTokens( raw ) {
		var at = 0, blocked = [], cursor = 0, pieces = [];
		while ( ( at = raw.indexOf( '<', at ) ) !== -1 ) {
			if ( raw.slice( at, at + 4 ) === '<!--' ) { var comment = raw.indexOf( '-->', at + 4 ); if ( comment < 0 ) { return { complete: false }; } at = comment + 3; continue; }
			var match = /^<(\/?)([a-z][a-z0-9:-]*)/i.exec( raw.slice( at ) );
			if ( ! match ) { at++; continue; }
			var quote = null, end = at + match[ 0 ].length;
			for ( ; end < raw.length; end++ ) { var ch = raw[ end ]; if ( quote ) { if ( ch === quote ) { quote = null; } } else if ( ch === '"' || ch === "'" ) { quote = ch; } else if ( ch === '>' ) { break; } }
			if ( end >= raw.length ) { return { complete: false }; }
			var tag = match[ 2 ].toUpperCase(), closer = !! match[ 1 ];
			if ( /^(SCRIPT|STYLE|IFRAME|TEXTAREA)$/.test( tag ) && ! closer ) {
				var close = new RegExp( '</' + tag + '(?=[\\t\\n\\f\\r />])', 'ig' ); close.lastIndex = end + 1; var found = close.exec( raw ); if ( ! found ) { return { complete: false }; }
				var closeEnd = close.lastIndex, closeQuote = null;
				for ( ; closeEnd < raw.length; closeEnd++ ) { var closingChar = raw[ closeEnd ]; if ( closeQuote ) { if ( closingChar === closeQuote ) { closeQuote = null; } } else if ( closingChar === '"' || closingChar === "'" ) { closeQuote = closingChar; } else if ( closingChar === '>' ) { break; } }
				if ( closeEnd >= raw.length ) { return { complete: false }; }
				if ( ! blocked.length ) { pieces.push( raw.slice( cursor, at ) ); cursor = closeEnd + 1; }
				at = closeEnd + 1; continue;
			}
			if ( /^(EMBED|INPUT|IMG)$/.test( tag ) && ! closer ) { if ( ! blocked.length ) { pieces.push( raw.slice( cursor, at ) ); cursor = end + 1; } }
			else if ( /^(OBJECT|SVG|MATH|FORM|BUTTON|SELECT|TEMPLATE)$/.test( tag ) ) {
				if ( closer ) { if ( blocked[ blocked.length - 1 ] === tag ) { blocked.pop(); if ( ! blocked.length ) { cursor = end + 1; } } }
				else { if ( ! blocked.length ) { pieces.push( raw.slice( cursor, at ) ); } blocked.push( tag ); }
			}
			at = end + 1;
		}
		if ( blocked.length ) { return { complete: false }; }
		pieces.push( raw.slice( cursor ) );
		return { complete: true, html: pieces.join( '' ) };
	}
	function completeColorInput( raw ) { return colorInputTokens( raw ).complete; }
	function safeColorRichHtml( value, legacy ) {
		var raw = String( value || '' );
		if ( ! /color/i.test( raw ) ) { return legacy ? raw : safeRichHtml( raw ); }
		var doc = new window.DOMParser().parseFromString( raw, 'text/html' );
		var hasColor = hasRichColor( doc );
		if ( ! hasColor ) { return legacy ? raw : safeRichHtml( raw ); }
		var tokens = colorInputTokens( raw );
		if ( ! tokens.complete ) { return safeRichHtml( raw ); }
		doc = new window.DOMParser().parseFromString( tokens.html, 'text/html' );
		var allowed = { P: 'p', DIV: 'p', BR: 'br', STRONG: 'strong', B: 'b', EM: 'em', I: 'i', U: 'u', A: 'a', UL: 'ul', OL: 'ol', LI: 'li', H3: 'h3', H4: 'h4', SPAN: 'span', FONT: 'span' };
		var protocols = /^(https?|ftp|ftps|mailto|news|irc|irc6|ircs|gopher|nntp|feed|telnet|mms|rtsp|sms|svn|tel|fax|xmpp|webcal|urn):/i;
		function clean( node ) {
			if ( node.nodeType === 3 ) { return escapeText( node.nodeValue ); }
			if ( node.nodeType !== 1 || /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|SVG|MATH|FORM|INPUT|BUTTON|TEXTAREA|SELECT|TEMPLATE)$/.test( node.nodeName.toUpperCase() ) ) { return ''; }
			var content = Array.prototype.map.call( node.childNodes, clean ).join( '' ), tag = allowed[ node.nodeName ];
			if ( ! tag ) { return content; }
			if ( tag === 'br' ) { return '<br>'; }
			if ( tag === 'a' ) {
				var original = node.getAttribute( 'href' ), href = safeHref( original ), attrs = '';
				if ( ! href && legacy && typeof original === 'string' && ! /[\u0000-\u001f\u007f]/.test( original ) && ( ! original.trim() || protocols.test( original.trim() ) || ! /^[a-z][a-z0-9+.-]*:/i.test( original.trim() ) ) ) { href = original.trim(); }
				if ( ! href && ! ( legacy && ( original === null || original.trim() === '' ) ) ) { return content; }
				if ( original !== null ) { attrs += ' href="' + escapeText( href ) + '"'; }
				if ( node.hasAttribute( 'target' ) ) { attrs += ' target="' + escapeText( node.getAttribute( 'target' ) ) + '"'; }
				var rel = ( node.getAttribute( 'rel' ) || '' ).split( /\s+/ ).filter( function ( token ) { return token && token.toLowerCase() !== 'opener'; } );
				[ 'noopener', 'noreferrer' ].forEach( function ( token ) { if ( ! rel.some( function ( old ) { return old.toLowerCase() === token; } ) ) { rel.push( token ); } } );
				return '<a' + attrs + ' rel="' + escapeText( rel.join( ' ' ) ) + '">' + content + '</a>';
			}
			var attrs = '';
			if ( tag === 'span' ) {
				if ( node.nodeName === 'SPAN' && node.hasAttribute( 'class' ) ) { attrs += ' class="' + escapeText( node.getAttribute( 'class' ) ) + '"'; }
				var color = ColorOperation.styleColor( node.getAttribute( 'style' ) ) || ( node.nodeName === 'FONT' ? ColorOperation.hex( node.getAttribute( 'color' ) ) : null );
				if ( color ) { attrs += ' style="color:' + color + '"'; }
			}
			return '<' + tag + attrs + '>' + content + '</' + tag + '>';
		}
		var html = Array.prototype.map.call( doc.body.childNodes, clean ).join( '' );
		return plainText( html ) ? html : '';
	}


	function mediaRichField( field ) { return colorField( field ) && field.richTextMedia === 'image'; }
	function richImages( value ) { return /<\s*img\b/i.test( String( value || '' ) ); }
	function richDocumentId() {
		var cfg = window.pcDesignEditor, id = cfg && cfg.document && Number( cfg.document.id );
		if ( ! id && wp.data && wp.data.select ) { var store = wp.data.select( 'core/editor' ); id = store && store.getCurrentPostId ? Number( store.getCurrentPostId() ) : 0; }
		return Number.isSafeInteger( id ) && id > 0 ? id : 0;
	}
	function mediaEnvelope( field, value ) {
		var settings = {}; settings[ field.key ] = value;
		var attrs = { moduleId: 'rich-media-check', settings: field.richMediaItem ? {} : settings, items: field.richMediaItem ? [ { id: 'rich-media-item', enabled: true, settings: settings } ] : [] };
		return [ { block: field.richMediaBlock, attrs: attrs } ];
	}
	function requestRichMedia( field, value, documentId ) {
		if ( ! documentId || ! wp.apiFetch || ! mediaRichField( field ) || ! /^vialcove\/[a-z-]+$/.test( field.richMediaBlock || '' ) ) { return Promise.reject( new Error( '请先保存此装修文档，再插入正文图片。' ) ); }
		return wp.apiFetch( { path: '/peptide/v1/layouts/' + documentId + '/preview', method: 'POST', data: { modules: mediaEnvelope( field, value ) } } ).then( function ( response ) {
			if ( ! response || ! response.richMedia || typeof response.richMedia !== 'object' || Array.isArray( response.richMedia ) ) { throw new Error( '图片验证未返回有效结果，请重试。' ); }
			return response.richMedia;
		} );
	}
	// TEMPLATE content is inert: no submitted src/srcset is ever attached to the active DOM.
	// Every emitted IMG is rebuilt from this request's server-resolved map.
	function safeMediaRichHtml( raw, map ) {
		raw = String( raw === null || raw === undefined ? '' : raw );
		if ( ! completeColorInput( raw ) ) { throw new Error( '源码标记不完整，请修正后再应用。' ); }
		var template = document.createElement( 'template' ); template.innerHTML = raw;
		var allowed = { P: 'p', DIV: 'p', BR: 'br', STRONG: 'strong', B: 'b', EM: 'em', I: 'i', U: 'u', A: 'a', UL: 'ul', OL: 'ol', LI: 'li', H3: 'h3', H4: 'h4', SPAN: 'span', FONT: 'span' }, hasImage = false;
		function cleanNode( node ) {
			if ( node.nodeType === 3 ) { return escapeText( node.nodeValue ); }
			if ( node.nodeType !== 1 || /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|SVG|MATH|FORM|INPUT|BUTTON|TEXTAREA|SELECT|TEMPLATE)$/.test( node.nodeName ) ) { return ''; }
			if ( node.nodeName === 'IMG' ) {
				var id = node.getAttribute( 'data-pc-attachment' ), key = node.getAttribute( 'data-pc-asset' );
				if ( ( id !== null ) === ( key !== null ) || id !== null && ! /^[1-9][0-9]{0,9}$/.test( id ) || key !== null && ! /^[a-z0-9][a-z0-9_-]{0,99}$/.test( key ) ) { throw new Error( '正文图片须从媒体库选择，或使用已登记的站点资产。' ); }
				var ref = id !== null ? 'attachment:' + id : 'asset:' + key, image = map && map[ ref ];
				if ( ! image || ! /^https?:\/\//i.test( image.src || '' ) || ! Number.isFinite( image.width ) || ! Number.isFinite( image.height ) || image.width < 1 || image.height < 1 ) { throw new Error( '此图片尚未通过验证，请重新选择。' ); }
				var alt = node.hasAttribute( 'alt' ) ? node.getAttribute( 'alt' ) : image.alt || '';
				alt = String( alt ).replace( /[\u0000-\u001f\u007f]/g, '' ).slice( 0, 200 ); hasImage = true;
				return '<img class="pc-rich-image"' + ( id !== null ? ' data-pc-attachment="' + id + '"' : ' data-pc-asset="' + escapeText( key ) + '"' ) + ' src="' + escapeText( image.src ) + '" width="' + Math.trunc( image.width ) + '" height="' + Math.trunc( image.height ) + '" alt="' + escapeText( alt ) + '">';
			}
			var content = Array.prototype.map.call( node.childNodes, cleanNode ).join( '' ), tag = allowed[ node.nodeName ];
			if ( ! tag ) { return content; } if ( tag === 'br' ) { return '<br>'; }
			var attrs = '';
			if ( tag === 'span' ) {
				if ( node.nodeName === 'SPAN' && node.hasAttribute( 'class' ) ) { attrs += ' class="' + escapeText( node.getAttribute( 'class' ) ) + '"'; }
				var color = ColorOperation.styleColor( node.getAttribute( 'style' ) ) || ( node.nodeName === 'FONT' ? ColorOperation.hex( node.getAttribute( 'color' ) ) : null ); if ( color ) { attrs += ' style="color:' + color + '"'; }
			} else if ( tag === 'a' ) {
				var href = node.getAttribute( 'href' ), match = href && /^([a-z][a-z0-9+.-]*):/i.exec( href.trim() );
				if ( href !== null && ( /[\u0000-\u001f\u007f]/.test( href ) || match && ! /^(https?|ftp|ftps|mailto|news|irc|irc6|ircs|gopher|nntp|feed|telnet|mms|rtsp|sms|svn|tel|fax|xmpp|webcal|urn)$/i.test( match[ 1 ] ) ) ) { return content; }
				if ( href !== null ) { attrs += ' href="' + escapeText( href.trim() ) + '"'; } if ( node.hasAttribute( 'target' ) ) { attrs += ' target="' + escapeText( node.getAttribute( 'target' ) ) + '"'; }
				var rel = ( node.getAttribute( 'rel' ) || '' ).split( /\s+/ ).filter( function ( token ) { return token && token.toLowerCase() !== 'opener'; } );
				[ 'noopener', 'noreferrer' ].forEach( function ( token ) { if ( ! rel.some( function ( old ) { return old.toLowerCase() === token; } ) ) { rel.push( token ); } } ); attrs += ' rel="' + escapeText( rel.join( ' ' ) ) + '"';
			}
			return '<' + tag + attrs + '>' + content + '</' + tag + '>';
		}
		var html = Array.prototype.map.call( template.content.childNodes, cleanNode ).join( '' );
		return hasImage || template.content.textContent.replace( /[\s\uFEFF]/g, '' ) ? html : '';
	}
	function richLegacySource( value, field ) { return mediaRichField( field ) && richImages( value ) ? false : legacyRichSource( value ); }

	function fieldValue( field, values, device ) {
		var value = values[ field.key ];
		if ( ( value === null || value === undefined ) && field.legacyHtml === true && field.legacyHtmlKey ) {
			var oldHtml = effective( values[ field.legacyHtmlKey ], device );
			return typeof oldHtml === 'string' ? oldHtml : '';
		}
		if ( ( value === null || value === undefined ) && field.legacyKey ) {
			var legacy = effective( values[ field.legacyKey ], device );
			return typeof legacy === 'string' ? escapeText( legacy ).replace( /\r?\n/g, '<br>' ) : legacy;
		}
		return value;
	}

	/** Explicit timeline text fields can inherit without serializing the fallback. */
	function InheritedTextField( props ) {
		var inherited = props.value === null || props.value === undefined;
		return el( 'div', { className: 'pc-me-inherited-text' },
			el( C.TextControl, { label: props.field.label, help: props.field.help, value: inherited ? ( props.fallback || '' ) : props.value, onChange: props.onChange } ),
			el( 'div', { className: 'pc-me-device-state' },
				inherited ? el( 'span', null, '沿用原日期' ) : el( C.Button, { variant: 'link', size: 'small', onClick: function () { props.onChange( null ); } }, '沿用原日期' )
			)
		);
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
			part( 'mobile', props.field.mobileImageLabel || S.mobileImage ),
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

	/** Raw code keeps a separate modal draft; only Apply writes the original field. */
	function CodeField( props ) {
		var field = props.field, raw = props.value === null || props.value === undefined ? '' : String( props.value );
		var id = useState( uid() )[ 0 ], open = useState( false ), pending = useState( '' );
		var trigger = useRef( null ), dialog = useRef( null ), input = useRef( null ), session = useRef( null );
		function sameSession() { return session.current && session.current.value === raw && session.current.scope === props.scope && session.current.key === field.key; }
		function finish( restoreFocus ) {
			session.current = null; pending[ 1 ]( '' );
			if ( dialog.current && dialog.current.open ) { dialog.current.close(); }
			open[ 1 ]( false );
			if ( restoreFocus && trigger.current ) { trigger.current.focus( { preventScroll: true } ); }
		}
		useEffect( function () {
			if ( open[ 0 ] && ! sameSession() ) { finish( false ); }
		}, [ raw, props.scope, field.key ] );
		useEffect( function () {
			if ( ! open[ 0 ] || ! dialog.current ) { return; }
			var node = dialog.current;
			node.showModal();
			if ( input.current ) { input.current.focus(); }
			return function () { session.current = null; if ( node.open ) { node.close(); } };
		}, [ open[ 0 ] ] );
		function expand() {
			session.current = { value: raw, scope: props.scope, key: field.key };
			pending[ 1 ]( raw ); open[ 1 ]( true );
		}
		function apply() {
			if ( ! sameSession() ) { finish( true ); return; }
			var next = pending[ 0 ]; finish( true );
			if ( next !== raw ) { props.onChange( next ); }
		}
		function keys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); finish( true ); return; }
			if ( event.key !== 'Tab' || ! dialog.current ) { return; }
			var targets = Array.prototype.filter.call( dialog.current.querySelectorAll( 'button,textarea' ), function ( node ) { return ! node.disabled; } );
			var first = targets[ 0 ], last = targets[ targets.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) { event.preventDefault(); last.focus(); }
			else if ( ! event.shiftKey && document.activeElement === last ) { event.preventDefault(); first.focus(); }
		}
		return el( 'div', { className: 'pc-me-code-field' },
			el( 'div', { className: 'pc-me-code-field__head' },
				el( 'label', { className: 'pc-me-label', htmlFor: id }, field.label ),
				el( 'span', { className: 'pc-me-code-field__badge', 'aria-hidden': true }, '代码' ),
				el( 'button', { type: 'button', ref: trigger, className: 'pc-me-code-expand', 'aria-label': '扩大编辑 ' + field.label, title: '扩大编辑', onClick: expand },
					el( 'svg', { viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false }, el( 'path', { d: 'M8 3H3v5m13-5h5v5m0 8v5h-5M3 16v5h5M3 3l6 6m12-6-6 6m6 12-6-6M3 21l6-6' } ) )
				)
			),
			el( 'textarea', { id: id, className: 'pc-me-code-field__input', rows: 10, spellCheck: false, autoCapitalize: 'off', autoCorrect: 'off', wrap: 'off', value: raw,
				'aria-describedby': field.help ? id + '-help' : undefined, onChange: function ( event ) { props.onChange( event.target.value ); } } ),
			field.help ? el( 'p', { id: id + '-help', className: 'pc-me-help' }, field.help ) : null,
			open[ 0 ] ? el( 'dialog', { ref: dialog, className: 'pc-me-code-dialog', 'aria-labelledby': id + '-dialog-title', 'aria-describedby': id + '-dialog-note', 'aria-modal': true,
				onCancel: function ( event ) { event.preventDefault(); event.stopPropagation(); finish( true ); }, onClose: function () { if ( session.current ) { finish( true ); } }, onKeyDown: keys },
				el( 'div', { className: 'pc-me-code-dialog__head' },
					el( 'h3', { id: id + '-dialog-title' }, '编辑 ' + field.label + ' 代码' ),
					el( 'button', { type: 'button', className: 'pc-me-code-expand', 'aria-label': '关闭并取消', onClick: function () { finish( true ); } },
						el( 'svg', { viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', 'aria-hidden': true, focusable: false }, el( 'path', { d: 'M6 6l12 12M18 6L6 18' } ) )
					)
				),
				el( 'div', { className: 'pc-me-code-dialog__body' },
					el( 'textarea', { ref: input, className: 'pc-me-code-field__input pc-me-code-dialog__input', 'aria-label': field.label + ' 代码', rows: 20, spellCheck: false, autoCapitalize: 'off', autoCorrect: 'off', wrap: 'off', value: pending[ 0 ], onChange: function ( event ) { pending[ 1 ]( event.target.value ); } } ),
					el( 'p', { id: id + '-dialog-note', className: 'pc-me-code-dialog__note' }, '应用后更新此字段；取消或按 Esc 保留原内容。' )
				),
				el( 'div', { className: 'pc-me-code-dialog__actions' },
					el( 'button', { type: 'button', className: 'pc-me-code-dialog__cancel', onClick: function () { finish( true ); } }, '取消' ),
					el( 'button', { type: 'button', className: 'pc-me-code-dialog__apply', onClick: apply }, '应用' )
				)
			) : null
		);
	}

	/** Only the explicit text-block HTML fallback uses this inert source-first wrapper. */
	function LegacyHtmlField( props ) {
		var mode = useRef( null ), container = useRef( null ), resetFocus = useRef( null );
		var inherited = props.inherited === true;
		function sourceMode() { return inherited && typeof props.value === 'string' && props.value !== '' || richLegacySource( props.value, props.field ); }
		if ( ! mode.current ) { mode.current = { value: props.value, scope: props.scope, inherited: inherited, source: sourceMode(), emitted: undefined, generation: 0 }; }
		else if ( mode.current.value !== props.value || mode.current.scope !== props.scope || mode.current.inherited !== inherited ) {
			var own = mode.current.scope === props.scope && mode.current.emitted === props.value;
			if ( ! own ) {
				if ( mode.current.value === props.value && mode.current.inherited !== inherited ) { mode.current.generation++; }
				mode.current.source = sourceMode();
			}
			mode.current.value = props.value; mode.current.scope = props.scope; mode.current.inherited = inherited; mode.current.emitted = undefined;
		}
		function changed( value, source ) { if ( typeof source === 'boolean' ) { mode.current.source = source; } mode.current.emitted = value; props.onChange( value ); }
		useEffect( function () {
			var request = resetFocus.current; resetFocus.current = null;
			if ( ! request || ! inherited || request.scope !== props.scope || request.doc !== richDocumentId() || ! container.current || ! container.current.isConnected ) { return; }
			var input = container.current.querySelector( '.pc-me-richtext__source-input, .pc-me-richtext__input' ); if ( input ) { input.focus( { preventScroll: true } ); }
		}, [ inherited, props.scope ] );
		return el( 'div', { ref: container, className: 'pc-me-legacy-html' },
			el( RichTextField, { field: props.field, value: props.value, scope: props.scope + ':doc-' + richDocumentId() + ':html-' + mode.current.generation, legacySource: mode.current.source, legacyHtmlInherited: inherited, onChange: changed } ),
			inherited ? el( 'p', { className: 'pc-me-help', role: 'status' }, '正在沿用原始HTML；打开与取消不会改写已有内容。' ) : props.allowReset ? el( C.Button, { variant: 'tertiary', onClick: function () { resetFocus.current = { scope: props.scope, doc: richDocumentId() }; mode.current.emitted = null; props.onChange( null ); } }, '沿用原HTML' ) : null
		);
	}

	function HtmlField( props ) {
		var mode = useRef( null );
		if ( ! mode.current ) { mode.current = { value: props.value, scope: props.scope, source: richLegacySource( props.value, props.field ), emitted: undefined }; }
		else if ( mode.current.value !== props.value || mode.current.scope !== props.scope ) {
			// Own keystrokes retain their control and focus; a different incoming
			// module/device value is assessed before it can reach innerHTML.
			if ( mode.current.scope !== props.scope || mode.current.emitted !== props.value ) { mode.current.source = richLegacySource( props.value, props.field ); }
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
		var colorEnabled = colorField( field ), colorState = useState( false ), customState = useState( false ), colorNotice = useState( '' );
		var colorSelection = useRef( null ), colorButton = useRef( null ), customButton = useRef( null ), customDialog = useRef( null ), hexInput = useRef( null ), applyingColor = useRef( false );
		var colorDraft = useState( { hex: '000000', red: '0', green: '0', blue: '0', last: 'hex', error: '' } );
		var applyingMedia = useRef( false );
		var mediaEnabled = mediaRichField( field ), mediaMap = useRef( {} ), mediaGeneration = useRef( 0 ), mediaSession = useRef( null ), mediaButton = useRef( null ), mediaAlive = useRef( true );
		var mediaState = useState( { pending: false, error: '', version: 0 } ), mediaVerified = useRef( null ), mediaLatest = useRef( null );
		var documentId = mediaEnabled ? richDocumentId() : 0;
		mediaLatest.current = { value: props.value, scope: props.scope, doc: documentId, field: field.key };
		function clean( value, legacy ) { return mediaEnabled && richImages( value ) ? safeMediaRichHtml( value, mediaMap.current ) : safeRichHtml( value, colorEnabled ? { color: true, legacy: !! legacy } : null ); }
		function mediaError( error ) { return error && error.message ? error.message : '图片验证失败，原内容已保留，请重试。'; }
		function mediaStatus( pending, error ) { if ( mediaAlive.current ) { mediaState[ 1 ]( function ( old ) { return Object.assign( {}, old, { pending: pending, error: error || '', version: Date.now() } ); } ); } }
		function mediaValid( saved ) { var current = mediaLatest.current; return mediaAlive.current && saved.generation === mediaGeneration.current && current && current.doc === saved.doc && current.scope === saved.scope && current.field === saved.field && current.value === saved.value && richDocumentId() === saved.doc; }
		function mediaTicket() { return Object.assign( { generation: mediaGeneration.current }, mediaLatest.current ); }
		function closeOwnedMedia( invalidate, expected ) {
			var session = mediaSession.current; if ( ! session || expected && session !== expected ) { return; } mediaSession.current = null;
			if ( session.closeTimer !== undefined ) { clearTimeout( session.closeTimer ); session.closeTimer = undefined; }
			if ( invalidate ) { mediaGeneration.current++; }
			if ( session.frame ) { session.frame.off( 'select', session.select ); session.frame.off( 'close', session.close ); if ( session.frame.modal && session.frame.modal.el ) { session.frame.modal.el.classList.remove( 'pc-me-rich-media-window' ); } session.frame.close(); session.frame.remove(); }
		}
		useEffect( function () { mediaAlive.current = true; return function () { mediaAlive.current = false; mediaGeneration.current++; closeOwnedMedia( false ); }; }, [] );

		function openMedia() {
			if ( ! mediaEnabled || mediaState[ 0 ].pending || ! editor.current || typeof wp.media !== 'function' ) { return; }
			closeOwnedMedia( true ); colorState[ 1 ]( false ); customState[ 1 ]( false ); linkState[ 1 ]( false ); helpState[ 1 ]( false );
			var range = selection.current && selection.current.cloneRange();
			if ( ! range ) { range = document.createRange(); range.selectNodeContents( editor.current ); range.collapse( false ); }
			var session = { ticket: mediaTicket(), range: range, selected: false, frame: wp.media( { title: '插入正文图片', multiple: false, library: { type: 'image' } } ) };
			mediaSession.current = session;
			// WordPress confirms with close -> select in the same turn. A close alone is
			// cancellation; choosing a tile is never treated as a confirmation event.
			session.close = function () {
				if ( mediaSession.current !== session || session.selected || session.closeTimer !== undefined ) { return; }
				session.closeTimer = setTimeout( function () {
					session.closeTimer = undefined;
					if ( mediaSession.current !== session || session.selected ) { return; }
					var returnFocus = mediaValid( session.ticket ); closeOwnedMedia( true, session );
					if ( returnFocus && mediaButton.current ) { mediaButton.current.focus(); }
				}, 0 );
			};
			session.select = function () {
				if ( mediaSession.current !== session ) { return; }
				if ( ! mediaValid( session.ticket ) || ! editor.current || ! editor.current.isConnected ) { closeOwnedMedia( true, session ); return; }
				session.selected = true;
				if ( session.closeTimer !== undefined ) { clearTimeout( session.closeTimer ); session.closeTimer = undefined; }
				var chosen = session.frame.state().get( 'selection' ).first(), model = chosen && chosen.toJSON(), attachmentId = model && Number( model.id );
				if ( ! Number.isSafeInteger( attachmentId ) || attachmentId < 1 ) { closeOwnedMedia( true, session ); mediaStatus( false, '请选择一张图片。' ); if ( mediaButton.current ) { mediaButton.current.focus(); } return; }
				var marker = '<img data-pc-attachment="' + attachmentId + '">'; closeOwnedMedia( false, session ); mediaStatus( true, '' );
				requestRichMedia( field, marker, session.ticket.doc ).then( function ( map ) {
					if ( ! mediaValid( session.ticket ) || ! editor.current || ! editor.current.contains( session.range.startContainer ) || ! editor.current.contains( session.range.endContainer ) ) { return; }
					mediaMap.current = Object.assign( {}, mediaMap.current, map ); var image = safeMediaRichHtml( marker, mediaMap.current );
					editor.current.focus(); var current = getSelection(); current.removeAllRanges(); current.addRange( session.range );
					var inserted; applyingMedia.current = true; try { inserted = document.execCommand( 'insertHTML', false, image ); } finally { applyingMedia.current = false; }
					if ( ! inserted ) { throw new Error( '当前选区无法插图，请重新放置光标。' ); }
					changed(); mediaStatus( false, '' );
				} ).catch( function ( error ) { if ( mediaValid( session.ticket ) ) { mediaStatus( false, mediaError( error ) ); if ( mediaButton.current ) { mediaButton.current.focus(); } } } );
			};
			session.frame.on( 'select', session.select ); session.frame.on( 'close', session.close ); session.frame.open();
			if ( session.frame.modal && session.frame.modal.el ) { session.frame.modal.el.classList.add( 'pc-me-rich-media-window' ); }
		}

		useEffect( function () {
			if ( ! customState[ 0 ] || ! customDialog.current ) { return; }
			var node = customDialog.current;
			if ( typeof node.showModal === 'function' && ! node.open ) { node.showModal(); }
			if ( hexInput.current ) { hexInput.current.focus(); }
			return function () { if ( node.open ) { node.close(); } };
		}, [ customState[ 0 ] ] );
		function closeColor() {
			customState[ 1 ]( false ); colorState[ 1 ]( false );
			if ( editor.current && colorSelection.current ) { ColorOperation.restore( editor.current, colorSelection.current ); }
		}
		function openColor() {
			var range = selection.current, selected = editor.current && ColorOperation.offsets( editor.current, range );
			if ( ! selected ) { colorNotice[ 1 ]( '请先选中要设置颜色的文字。' ); return; }
			colorSelection.current = selected; colorNotice[ 1 ]( '' ); linkState[ 1 ]( false ); helpState[ 1 ]( false ); colorState[ 1 ]( ! colorState[ 0 ] );
		}
		function applyColor( color ) {
			if ( ! editor.current || ! colorSelection.current ) { colorNotice[ 1 ]( '请重新选中文字。' ); return; }
			if ( customDialog.current && customDialog.current.open ) { customDialog.current.close(); }
			customState[ 1 ]( false );
			applyingColor.current = true;
			var ok;
			try { ok = ColorOperation.apply( editor.current, colorSelection.current, color, function ( html ) { return clean( html ); } ); }
			finally { applyingColor.current = false; }
			if ( ! ok ) { colorNotice[ 1 ]( '当前选区暂不支持设置颜色，请重新选择文字。' ); return; }
			changed(); closeColor();
		}
		function customDraft( color ) {
			var hex = ColorOperation.hex( color ) || '#000000';
			return { hex: hex.slice( 1 ), red: String( parseInt( hex.slice( 1, 3 ), 16 ) ), green: String( parseInt( hex.slice( 3, 5 ), 16 ) ), blue: String( parseInt( hex.slice( 5, 7 ), 16 ) ), last: 'hex', error: '' };
		}
		function openCustom() {
			if ( editor.current && colorSelection.current ) { ColorOperation.restore( editor.current, colorSelection.current ); remember(); }
			var node = selection.current && selection.current.startContainer;
			if ( node && node.nodeType === 3 ) { node = node.parentElement; }
			colorDraft[ 1 ]( customDraft( node ? getComputedStyle( node ).color : '#000000' ) ); customState[ 1 ]( true );
		}
		function cancelCustom() { if ( customDialog.current && customDialog.current.open ) { customDialog.current.close(); } customState[ 1 ]( false ); if ( customButton.current ) { customButton.current.focus(); } }
		function updateColorHex( value ) {
			var next = Object.assign( {}, colorDraft[ 0 ], { hex: value, last: 'hex', error: '' } );
			if ( /^[0-9a-f]{6}$/i.test( value ) ) { next = customDraft( '#' + value ); }
			colorDraft[ 1 ]( next );
		}
		function updateColorRgb( key, value ) {
			var next = Object.assign( {}, colorDraft[ 0 ] ); next[ key ] = value; next.last = 'rgb'; next.error = '';
			var values = [ next.red, next.green, next.blue ];
			if ( values.every( function ( item ) { return /^\d{1,3}$/.test( item ) && Number( item ) <= 255; } ) ) { next.hex = values.map( function ( item ) { return Number( item ).toString( 16 ).padStart( 2, '0' ); } ).join( '' ); }
			colorDraft[ 1 ]( next );
		}
		function saveCustom() {
			var current = colorDraft[ 0 ], valid = current.last === 'hex' ? /^[0-9a-f]{6}$/i.test( current.hex ) : [ current.red, current.green, current.blue ].every( function ( value ) { return /^\d{1,3}$/.test( value ) && Number( value ) <= 255; } );
			if ( ! valid ) { colorDraft[ 1 ]( Object.assign( {}, current, { error: 'HEX请输入6位十六进制；RGB请输入0–255的整数。' } ) ); return; }
			applyColor( '#' + current.hex );
		}
		function colorUi() {
			if ( ! colorEnabled ) { return null; }
			var swatches = [["Light Green", "#bfedd2"], ["Light Yellow", "#fbeeb8"], ["Light Red", "#f8cac6"], ["Light Purple", "#eccafa"], ["Light Blue", "#c2e0f4"], ["绿色", "#2dc26b"], ["黄色", "#f1c40f"], ["红色", "#e03e2d"], ["紫色", "#b96ad9"], ["蓝色", "#3598db"], ["深蓝绿色", "#169179"], ["橙色", "#e67e23"], ["深红色", "#ba372a"], ["Dark Purple", "#843fa1"], ["Dark Blue", "#236fa1"], ["浅灰色", "#ecf0f1"], ["中灰色", "#ced4d9"], ["灰色", "#95a5a6"], ["深灰色", "#7e8c8d"], ["海军蓝", "#34495e"], ["黑色", "#000000"], ["白色", "#ffffff"]];
			return el( Fragment, null,
				colorState[ 0 ] ? el( 'div', { className: 'pc-me-richtext__colors', role: 'group', 'aria-label': '文字颜色' },
					el( 'div', { className: 'pc-me-richtext__swatches', onKeyDown: function ( event ) {
						var directions = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 8, ArrowUp: -8 }, items = event.currentTarget.querySelectorAll( 'button' ), index = Array.prototype.indexOf.call( items, document.activeElement );
						if ( index >= 0 && directions[ event.key ] ) { event.preventDefault(); items[ ( index + directions[ event.key ] + items.length ) % items.length ].focus(); }
					} }, swatches.map( function ( item ) { return el( 'button', { key: item[ 1 ], type: 'button', className: 'pc-me-richtext__swatch', style: { backgroundColor: item[ 1 ] }, 'aria-label': item[ 0 ] + ' ' + item[ 1 ], title: item[ 0 ] + ' ' + item[ 1 ], onMouseDown: function ( event ) { event.preventDefault(); }, onClick: function () { applyColor( item[ 1 ] ); } } ); } ) ),
					el( 'div', { className: 'pc-me-richtext__color-actions' },
						el( C.Button, { variant: 'tertiary', size: 'small', onMouseDown: function ( event ) { event.preventDefault(); }, onClick: function () { applyColor( null ); } }, '移除颜色' ),
						el( C.Button, { variant: 'secondary', size: 'small', ref: customButton, onClick: openCustom }, '自定义颜色' )
					)
				) : null,
				colorNotice[ 0 ] ? el( 'p', { className: 'pc-me-richtext__color-notice', role: 'status' }, colorNotice[ 0 ] ) : null,
				customState[ 0 ] ? el( 'dialog', { ref: customDialog, className: 'pc-me-richtext__color-dialog', 'aria-labelledby': id + '-color-title', onCancel: function ( event ) { event.preventDefault(); cancelCustom(); } },
					el( 'h3', { id: id + '-color-title' }, '自定义文字颜色' ),
					el( 'div', { className: 'pc-me-richtext__rgb' }, [ [ 'red', 'Red' ], [ 'green', 'Green' ], [ 'blue', 'Blue' ] ].map( function ( item ) { return el( 'label', { key: item[ 0 ] }, item[ 1 ], el( 'input', { type: 'text', inputMode: 'numeric', value: colorDraft[ 0 ][ item[ 0 ] ], onChange: function ( event ) { updateColorRgb( item[ 0 ], event.target.value ); } } ) ); } ) ),
					el( 'label', { className: 'pc-me-richtext__hex-label' }, 'HEX', el( 'span', null, '#', el( 'input', { ref: hexInput, type: 'text', 'aria-label': 'HEX', maxLength: 6, value: colorDraft[ 0 ].hex, onChange: function ( event ) { updateColorHex( event.target.value ); }, onKeyDown: function ( event ) { if ( event.key === 'Enter' ) { event.preventDefault(); saveCustom(); } } } ) ) ),
					el( 'div', { className: 'pc-me-richtext__color-preview', style: { backgroundColor: /^[0-9a-f]{6}$/i.test( colorDraft[ 0 ].hex ) ? '#' + colorDraft[ 0 ].hex : 'transparent' }, 'aria-hidden': true } ),
					colorDraft[ 0 ].error ? el( 'p', { className: 'pc-me-error', role: 'alert' }, colorDraft[ 0 ].error ) : null,
					el( 'div', { className: 'pc-me-richtext__color-actions' }, el( C.Button, { variant: 'secondary', onClick: cancelCustom }, '取消' ), el( C.Button, { variant: 'primary', onClick: saveCustom }, '保存颜色' ) )
				) : null
			);
		}

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
		var mediaLoading = mediaEnabled && richImages( props.value ) && mediaVerified.current !== props.value;
		var html = mediaLoading ? '' : clean( props.value );
		useEffect( function () {
			if ( incoming.current.value !== props.value || incoming.current.scope !== props.scope ) {
				var ownChange = incoming.current.scope === props.scope && emitted.current === props.value;
				incoming.current = { value: props.value, scope: props.scope }; emitted.current = undefined;
				if ( ! ownChange ) {
					if ( mediaEnabled ) { closeOwnedMedia( true ); mediaVerified.current = null; mediaStatus( false, '' ); }
					// A different module/device value must never inherit a pending source draft.
					focusSource.current = false; returnSourceFocus.current = false; returnFullscreenFocus.current = false;
					sourceState[ 1 ]( props.legacySource ? draft( props.value ) : null );
					fullscreenState[ 1 ]( false ); helpState[ 1 ]( false ); linkState[ 1 ]( false ); selection.current = null; colorState[ 1 ]( false ); customState[ 1 ]( false ); colorNotice[ 1 ]( '' ); colorSelection.current = null;
				}
			}
		}, [ props.value, props.scope ] );
		useEffect( function () { if ( mediaEnabled ) { closeOwnedMedia( true ); mediaVerified.current = null; mediaStatus( false, '' ); } }, [ documentId ] );
		useEffect( function () {
			if ( ! mediaEnabled || ! richImages( props.value ) || mediaVerified.current === props.value ) { return; }
			var ticket = mediaTicket(); mediaStatus( true, '' );
			requestRichMedia( field, props.value, documentId ).then( function ( map ) {
				if ( ! mediaValid( ticket ) ) { return; } var merged = Object.assign( {}, mediaMap.current, map ); safeMediaRichHtml( props.value, merged ); mediaMap.current = merged; mediaVerified.current = props.value; mediaStatus( false, '' );
			} ).catch( function ( error ) { if ( mediaValid( ticket ) ) { mediaStatus( false, mediaError( error ) ); } } );
		}, [ props.value, props.scope, documentId, mediaState[ 0 ].retry ] );
		useEffect( function () {
			if ( editor.current && clean( editor.current.innerHTML ) !== html ) { editor.current.innerHTML = html; }
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
				if ( colorEnabled && colorState[ 0 ] && ! customState[ 0 ] ) { colorSelection.current = ColorOperation.offsets( editor.current, selection.current ); }
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
			if ( mediaEnabled ) { mediaGeneration.current++; closeOwnedMedia( false ); mediaVerified.current = value; }
			emitted.current = value; props.onChange( value, sourceMode );
			if ( mediaEnabled ) { mediaStatus( false, '' ); }
		}
		function changed() {
			if ( mediaEnabled && mediaLoading ) { return; }
			if ( applyingColor.current || applyingMedia.current ) { return; }
			try { emit( clean( editor.current.innerHTML ) ); } catch ( error ) { mediaStatus( false, mediaError( error ) ); return; }
			remember();
		}
		function command( name, argument ) {
			if ( mediaEnabled && ( mediaLoading || mediaState[ 0 ].pending ) ) { return; }
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
			if ( mediaEnabled ) { closeOwnedMedia( true ); mediaGeneration.current++; mediaStatus( false, '' ); }
			colorNotice[ 1 ]( '' );
			linkState[ 1 ]( false ); helpState[ 1 ]( false ); colorState[ 1 ]( false ); customState[ 1 ]( false );
			if ( sourceOpen ) { if ( sourceInput.current ) { sourceInput.current.focus(); } return; }
			focusSource.current = true;
			sourceState[ 1 ]( draft( props.value ) );
		}
		function cancelSource() {
			if ( mediaEnabled ) { mediaGeneration.current++; mediaStatus( false, '' ); }
			colorNotice[ 1 ]( '' );
			returnSourceFocus.current = ! props.legacySource;
			sourceState[ 1 ]( props.legacySource ? draft( props.value ) : null );
			if ( props.legacySource && sourceInput.current ) { sourceInput.current.focus(); }
		}
		function applySource() {
			if ( mediaEnabled && richImages( source.value ) ) {
				var ticket = mediaTicket(), raw = source.value; mediaStatus( true, '' );
				requestRichMedia( field, raw, documentId ).then( function ( map ) {
					if ( ! mediaValid( ticket ) ) { return; } if ( ! sourceInput.current || sourceInput.current.value !== raw ) { mediaStatus( false, '' ); return; }
					var merged = Object.assign( {}, mediaMap.current, map ), safe = safeMediaRichHtml( raw, merged ); mediaMap.current = merged;
					if ( raw !== source.initial ) { emit( safe, false ); } else { mediaVerified.current = props.value; }
					returnSourceFocus.current = true; sourceState[ 1 ]( null ); mediaStatus( false, '' );
				} ).catch( function ( error ) { if ( mediaValid( ticket ) ) { mediaStatus( false, mediaError( error ) ); } } ); return;
			}
			if ( colorEnabled && ! completeColorInput( source.value ) && hasRichColor( new window.DOMParser().parseFromString( source.value, 'text/html' ) ) ) { colorNotice[ 1 ]( '源码标记不完整或包含暂不支持的结构，请修正后再应用。' ); return; }
			colorNotice[ 1 ]( '' );
			var preserve = richLegacySource( source.value, field );
			returnSourceFocus.current = ! preserve;
			// Legacy links remain inert text, preserving their original server-cleaned
			// source path. They must never be assigned to contentEditable.innerHTML.
			if ( source.value !== source.initial ) { emit( colorEnabled ? clean( source.value, preserve ) : ( preserve ? source.value : clean( source.value ) ), preserve ); }
			sourceState[ 1 ]( preserve ? draft( source.value ) : null );
			if ( preserve && sourceInput.current ) { sourceInput.current.focus(); }
		}
		function keyboard( event ) {
			if ( event.defaultPrevented || mediaSession.current ) { return; }
			if ( customState[ 0 ] && event.key === 'Tab' ) {
				var colorTargets = customDialog.current && Array.prototype.filter.call( customDialog.current.querySelectorAll( 'input,button' ), function ( node ) { return ! node.disabled && node.getClientRects().length; } );
				if ( colorTargets && colorTargets.length ) {
					var colorFirst = colorTargets[ 0 ], colorLast = colorTargets[ colorTargets.length - 1 ];
					if ( event.shiftKey && document.activeElement === colorFirst || ! event.shiftKey && document.activeElement === colorLast ) { event.preventDefault(); event.stopPropagation(); ( event.shiftKey ? colorLast : colorFirst ).focus(); }
				}
				return;
			}
			if ( event.key === 'Escape' ) {
				if ( customState[ 0 ] ) { event.preventDefault(); event.stopPropagation(); cancelCustom(); }
				else if ( colorState[ 0 ] ) { event.preventDefault(); event.stopPropagation(); closeColor(); }
				else if ( helpState[ 0 ] ) { event.preventDefault(); event.stopPropagation(); helpState[ 1 ]( false ); if ( helpButton.current ) { helpButton.current.focus(); } }
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
					mediaEnabled ? formatButton( '插入正文图片', icon( 'image', '图片' ), openMedia, { ref: mediaButton, disabled: sourceOpen || mediaState[ 0 ].pending || mediaLoading, 'aria-busy': mediaState[ 0 ].pending } ) : null,
					formatButton( '添加链接', icon( 'link', '链接' ), function () { linkState[ 1 ]( ! linkState[ 0 ] ); errorState[ 1 ]( '' ); }, { disabled: sourceOpen } ),
					formatButton( '清除格式', icon( 'clear', '清除格式' ), function () { command( 'removeFormat' ); command( 'unlink' ); }, { disabled: sourceOpen } ),
					el( 'span', { className: 'pc-me-richtext__separator', 'aria-hidden': true } ),
					formatButton( 'HTML 源码', icon( 'custom_html', 'HTML', true ), openSource, { ref: sourceButton, 'aria-pressed': sourceOpen, 'aria-controls': id + '-source' } ),
					colorEnabled ? formatButton( '文字颜色', icon( 'color', '颜色' ), openColor, { ref: colorButton, disabled: sourceOpen, 'aria-expanded': colorState[ 0 ] } ) : null,
					formatButton( fullscreen ? '退出编辑全屏' : '编辑全屏', icon( fullscreen ? 'collapse_fullscreen' : 'fullscreen', fullscreen ? '退出全屏' : '全屏' ), function () { returnFullscreenFocus.current = fullscreen; fullscreenState[ 1 ]( ! fullscreen ); }, { ref: fullscreenButton, 'aria-pressed': fullscreen } ),
					formatButton( '富文本帮助', icon( 'help', '帮助' ), function () { helpState[ 1 ]( ! helpState[ 0 ] ); }, { ref: helpButton, 'aria-expanded': helpState[ 0 ], 'aria-controls': id + '-tool-help' } )
				),
				colorUi(),
				mediaEnabled && ( mediaState[ 0 ].pending || mediaState[ 0 ].error ) ? el( 'div', { className: 'pc-me-richtext__media-status', role: mediaState[ 0 ].error ? 'alert' : 'status' }, mediaState[ 0 ].pending ? '正在验证正文图片…' : mediaState[ 0 ].error, mediaState[ 0 ].error && mediaLoading ? el( 'button', { type: 'button', onClick: function () { mediaState[ 1 ]( Object.assign( {}, mediaState[ 0 ], { retry: Date.now() } ) ); } }, '重新验证' ) : null ) : null,
				helpState[ 0 ] ? el( 'div', { id: id + '-tool-help', className: 'pc-me-richtext__help' },
					el( 'p', null, '支持加粗、斜体和链接；选中文字后使用工具栏，也可使用 Ctrl / ⌘ + B、I。' ),
					el( 'p', null, mediaEnabled ? '正文图片从媒体库插入；打开或取消不改变内容，删除图片不会删除媒体文件。' : '源码先在草稿中编辑，点“应用源码”后更新内容；“取消”保留原内容。图片暂不支持。' ),
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
						'aria-describedby': id + '-source-help', onChange: function ( event ) { if ( mediaEnabled ) { mediaGeneration.current++; mediaStatus( false, '' ); } sourceState[ 1 ]( { initial: source.initial, value: event.target.value } ); } } ),
					el( 'p', { id: id + '-source-help', className: 'pc-me-richtext__source-help', role: props.legacySource ? 'status' : undefined }, props.legacySource ? ( props.legacyHtmlInherited ? '正在保留已有原始HTML；明确应用修改后使用新富文本规范。' : '此内容含已有链接格式，使用源码编辑以保留原格式。转换为支持的链接后，可应用并返回可视化。' ) : ( mediaEnabled ? '应用时验证图片引用；无法验证时保留此源码与原正文。图片请从媒体库选择。' : '应用时仅保留支持的富文本格式；图片暂不支持。' ) ),
					el( 'div', { className: 'pc-me-richtext__source-actions' },
						el( C.Button, { variant: 'primary', size: 'small', onClick: applySource, disabled: mediaEnabled && mediaState[ 0 ].pending }, '应用源码' ),
						el( C.Button, { variant: 'secondary', size: 'small', onClick: cancelSource }, S.cancel || '取消' )
					)
				) : el( 'div', { key: 'visual', id: id, ref: editor, className: 'pc-me-richtext__input' + ( mediaEnabled ? ' pc-me-richtext__input--media' : '' ), contentEditable: ! mediaLoading, suppressContentEditableWarning: true,
					role: 'textbox', 'aria-multiline': true, 'aria-labelledby': id + '-label', 'aria-describedby': field.help ? id + '-help' : undefined,
					onInput: changed, onKeyUp: remember, onMouseUp: remember,
					onBlur: function () { if ( ! editor.current || mediaLoading ) { return; } try { var sanitized = clean( editor.current.innerHTML ); if ( sanitized !== editor.current.innerHTML ) { editor.current.innerHTML = sanitized; } } catch ( error ) { mediaStatus( false, mediaError( error ) ); } },
					onPaste: function ( event ) {
						event.preventDefault();
						var clipboard = event.clipboardData || window.clipboardData;
						if ( ! clipboard ) { return; }
						var pasted = clipboard.getData( 'text/html' );
						if ( mediaEnabled && richImages( pasted ) ) {
							remember(); var range = selection.current && selection.current.cloneRange(), ticket = mediaTicket();
							if ( ! range ) { mediaStatus( false, '请先在正文中放置光标。' ); return; } mediaStatus( true, '' );
							requestRichMedia( field, pasted, documentId ).then( function ( map ) {
								if ( ! mediaValid( ticket ) || ! editor.current || ! editor.current.contains( range.startContainer ) || ! editor.current.contains( range.endContainer ) ) { return; }
								var merged = Object.assign( {}, mediaMap.current, map ), safe = safeMediaRichHtml( pasted, merged ); mediaMap.current = merged;
								editor.current.focus(); var current = getSelection(); current.removeAllRanges(); current.addRange( range );
								var inserted; applyingMedia.current = true; try { inserted = document.execCommand( 'insertHTML', false, safe ); } finally { applyingMedia.current = false; } if ( ! inserted ) { throw new Error( '当前选区无法粘贴，请重新放置光标。' ); } changed(); mediaStatus( false, '' );
							} ).catch( function ( error ) { if ( mediaValid( ticket ) ) { mediaStatus( false, mediaError( error ) ); } } ); return;
						}

						document.execCommand( 'insertHTML', false, pasted ? clean( pasted ) : escapeText( clipboard.getData( 'text/plain' ) ).replace( /\r?\n/g, '<br>' ) ); changed();
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

	/** Only the trusted P12 repeater opts in; choices reuse the existing editor category snapshot. */
	function MultipleCollectionsField( props ) {
		var field = props.field, max = Math.max( 1, Math.min( 32, Number( field.max ) || 32 ) );
		var search = useState( '' ), seen = Object.create( null );
		var rows = ( Array.isArray( props.value ) ? props.value : [] ).filter( function ( row ) {
			if ( ! row || typeof row !== 'object' || ! row.settings || typeof row.settings !== 'object' ) { return false; }
			var id = Number( row.settings.category );
			if ( Number.isInteger( id ) && id > 0 ) { if ( seen[ id ] ) { return false; } seen[ id ] = true; }
			return true;
		} ).slice( 0, max );
		var choiceIds = Object.create( null );
		var choices = ( Array.isArray( data.categories ) ? data.categories : [] ).filter( function ( row ) { var id = row && Number( row.value ); if ( ! Number.isInteger( id ) || id < 1 || choiceIds[ id ] ) { return false; } choiceIds[ id ] = true; return true; } );
		var filtered = choices.filter( function ( row ) { return String( row.label || '' ).toLocaleLowerCase().indexOf( search[ 0 ].trim().toLocaleLowerCase() ) !== -1; } );
		function notify( id ) { if ( typeof props.onSelectItem === 'function' ) { props.onSelectItem( id ); } }
		function label( row ) { var id = Number( row.settings.category ), option = choices.find( function ( item ) { return Number( item.value ) === id; } ); return option ? option.label : id > 0 ? '专辑暂不可用（#' + id + '）' : '专辑暂不可用'; }
		function toggle( option, checked ) {
			var id = Number( option.value ), next = rows.slice(), selected = next.find( function ( row ) { return Number( row.settings.category ) === id; } );
			if ( checked ) { if ( selected || next.length >= max ) { return; } selected = newItem( { category: id } ); next.push( selected ); }
			else { next = next.filter( function ( row ) { return Number( row.settings.category ) !== id; } ); }
			props.onChange( next ); notify( checked ? selected.id : null );
		}
		function move( index, offset ) { var next = rows.slice(), target = index + offset; if ( target < 0 || target >= next.length ) { return; } var other = next[ target ]; next[ target ] = next[ index ]; next[ index ] = other; props.onChange( next ); notify( rows[ index ].id ); }
		function remove( index ) { props.onChange( rows.filter( function ( _, current ) { return current !== index; } ) ); notify( null ); }
		return el( 'div', { className: 'pc-me-collection-multiple' },
			el( 'p', { className: 'pc-me-label' }, field.label ),
			el( 'p', { role: 'status', 'aria-live': 'polite' }, '已选 ' + rows.length + ' / ' + max + ( rows.length >= max ? '，已达上限，请先移除专辑。' : '' ) ),
			el( C.Dropdown, { className: 'pc-me-collection__dropdown', contentClassName: 'pc-me-collection__popover',
				renderToggle: function ( state ) { return el( C.Button, { variant: 'secondary', 'aria-expanded': state.isOpen, onClick: state.onToggle }, '选择多个专辑' ); },
				renderContent: function ( state ) { return el( 'div', { className: 'pc-me-collection__chooser' },
					el( 'h3', { className: 'pc-me-collection__title' }, '选择本组商品专辑' ),
					el( C.TextControl, { label: '搜索本组专辑', value: search[ 0 ], onChange: search[ 1 ] } ),
					el( 'div', { className: 'pc-me-collection__results', role: 'group', 'aria-label': '可多选的商品专辑' }, filtered.length ? filtered.map( function ( option ) {
						var selected = rows.some( function ( row ) { return Number( row.settings.category ) === Number( option.value ); } );
						var count = option.count !== null && option.count !== undefined && Number.isFinite( Number( option.count ) ) ? String( Number( option.count ) ) : '—';
						return el( 'label', { key: option.value, className: 'pc-me-collection__option' },
							el( 'input', { type: 'checkbox', checked: selected, disabled: ! selected && rows.length >= max, 'aria-label': '选择专辑 ' + String( option.label || option.value ), onChange: function ( event ) { toggle( option, event.target.checked ); } } ),
							el( 'span', null, option.label ), el( 'span', { className: 'pc-me-collection__count' }, count ) );
					} ) : el( 'p', { className: 'pc-me-record__empty' }, choices.length ? '没有匹配专辑，请调整搜索。' : '当前没有可选专辑。已有选择仍保留，可移除或稍后刷新编辑器。' ) ),
					el( C.Button, { variant: 'tertiary', onClick: state.onClose }, '关闭' ) ); }
			} ),
			el( 'div', { className: 'pc-me-collection-multiple__selected', 'aria-label': '本组已选专辑' }, rows.map( function ( row, index ) { var name = label( row ); return el( 'div', { key: row.id, className: 'pc-me-record__summary' },
				el( 'div', { className: 'pc-me-record__identity' }, el( C.Button, { variant: 'tertiary', disabled: typeof props.onSelectItem !== 'function', onClick: function () { notify( row.id ); }, label: '在预览中查看 ' + name, style: { whiteSpace: 'normal', height: 'auto', maxWidth: '100%', textAlign: 'left', overflowWrap: 'anywhere' } }, name ) ),
				el( 'div', { className: 'pc-me-record__actions' },
					el( C.Button, { icon: 'arrow-up-alt2', label: '向上移动 ' + name, disabled: index === 0, onClick: function () { move( index, -1 ); } } ),
					el( C.Button, { icon: 'arrow-down-alt2', label: '向下移动 ' + name, disabled: index === rows.length - 1, onClick: function () { move( index, 1 ); } } ),
					el( C.Button, { icon: 'trash', label: '移除 ' + name, onClick: function () { remove( index ); } } ) ) ); } ) ),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null
		);
	}

	/** Only the new P13 background opts into alpha; the existing ColorField stays unchanged. */
	function AlphaColorField( props ) {
		var field = props.field, id = useState( uid() )[ 0 ];
		return el( 'div', { className: 'pc-me-color' },
			el( 'label', { className: 'pc-me-label', htmlFor: id }, field.label ),
			el( C.Dropdown, { className: 'pc-me-color__dropdown', contentClassName: 'pc-me-color__popover',
				renderToggle: function ( state ) { return el( 'button', { id: id, type: 'button', className: 'pc-me-color__trigger', 'aria-expanded': state.isOpen, 'aria-label': field.label + '：' + ( props.value || '默认' ), onClick: state.onToggle }, el( 'span', { className: 'pc-me-color__swatch', style: props.value ? { backgroundColor: props.value } : undefined, 'aria-hidden': true } ), el( 'span', null, props.value || '默认' ) ); },
				renderContent: function () { return el( C.ColorPalette, { colors: PALETTE, value: props.value || undefined, clearable: true, enableAlpha: true, onChange: function ( color ) { props.onChange( color || '' ); } } ); }
			} ), field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null
		);
	}

	/** P13 alone: every lookup is the existing nonce/capability-checked topic resource. */
	function MultipleTopicsField( props ) {
		var field = props.field, max = Math.max( 1, Math.min( 32, Number( field.max ) || 32 ) ), seen = Object.create( null );
		var rows = ( Array.isArray( props.value ) ? props.value : [] ).filter( function ( row ) { if ( ! row || ! row.settings || typeof row.settings !== 'object' ) { return false; } var id = Number( row.settings.topic ); if ( Number.isSafeInteger( id ) && id > 0 ) { if ( seen[ id ] ) { return false; } seen[ id ] = true; } return true; } ).slice( 0, max );
		var open = useState( false ), term = useState( '' ), page = useState( 1 ), retry = useState( 0 );
		var result = useState( { options: [], more: false } ), busy = useState( false ), error = useState( '' ), labels = useState( {} ), request = useRef( 0 );
		var ids = rows.map( function ( row ) { return Number( row.settings.topic ); } ).filter( function ( id ) { return Number.isSafeInteger( id ) && id > 0; } ), idsKey = ids.join( ',' );
		useEffect( function () {
			var subscriptions = ids.map( function ( id ) { return subscribeRecordLabel( 'topic', id, function ( option ) { labels[ 1 ]( function ( prior ) { var next = Object.assign( {}, prior ); next[ id ] = option; return next; } ); }, function () { labels[ 1 ]( function ( prior ) { var next = Object.assign( {}, prior ); next[ id ] = { value: String( id ), label: '专题摘要暂时不可用（#' + id + '）', unavailable: true }; return next; } ); } ); } );
			return function () { subscriptions.forEach( function ( unsubscribe ) { unsubscribe(); } ); };
		}, [ idsKey ] );
		useEffect( function () {
			if ( ! open[ 0 ] ) { return; }
			var alive = true, abort = new AbortController(), ownRequest = ++request.current, deadline = null;
			busy[ 1 ]( true ); error[ 1 ]( '' );
			var timer = setTimeout( function () {
				var cfg = data.recordLookup || {}, url;
				try { url = new URL( cfg.url || window.ajaxurl || 'admin-ajax.php', window.location.href ); if ( url.origin !== window.location.origin || ! /^https?:$/.test( url.protocol ) ) { throw new Error( 'origin' ); } }
				catch ( invalid ) { if ( alive ) { error[ 1 ]( '专题读取地址不可用，请刷新编辑器重试。' ); busy[ 1 ]( false ); } return; }
				var params = { action: 'pc_layout_records', security: cfg.security || '', resource: 'topic', term: term[ 0 ].trim().slice( 0, 100 ), paged: page[ 0 ] };
				Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
				var version = ++recordRequestVersion;
				deadline = setTimeout( function () { if ( alive && request.current === ownRequest ) { error[ 1 ]( '专题读取超时，请重试。' ); busy[ 1 ]( false ); } abort.abort(); }, 20000 );
				fetch( url.href, { credentials: 'same-origin', cache: 'no-store', signal: abort.signal } ).then( function ( response ) {
					if ( response.status === 401 || response.status === 403 ) { throw new Error( '登录或装修权限已失效，请刷新后重试。' ); }
					return response.json().then( function ( payload ) { if ( ! response.ok || ! payload.success || ! payload.data || ! Array.isArray( payload.data.options ) || typeof payload.data.more !== 'boolean' ) { throw new Error( '专题读取失败，请重试。' ); } return payload.data; } );
				} ).then( function ( dataRows ) {
					clearTimeout( deadline ); if ( ! alive || abort.signal.aborted || request.current !== ownRequest ) { return; }
					var keys = Object.create( null ), options = dataRows.options.filter( function ( row ) { var id = row && Number( row.value ); if ( ! Number.isSafeInteger( id ) || id < 1 || typeof row.label !== 'string' || row.unavailable || keys[ id ] ) { return false; } keys[ id ] = true; return true; } ).slice( 0, 20 );
					cacheRecordOptions( 'topic', options, version ); result[ 1 ]( { options: options, more: dataRows.more } ); busy[ 1 ]( false );
				} ).catch( function ( problem ) { clearTimeout( deadline ); if ( alive && request.current === ownRequest && problem.name !== 'AbortError' ) { error[ 1 ]( problem.message === '登录或装修权限已失效，请刷新后重试。' ? problem.message : '专题读取失败，请重试。' ); busy[ 1 ]( false ); } } );
			}, 180 );
			return function () { alive = false; ++request.current; clearTimeout( timer ); clearTimeout( deadline ); abort.abort(); };
		}, [ open[ 0 ], term[ 0 ], page[ 0 ], retry[ 0 ] ] );
		function notify( id ) { if ( typeof props.onSelectItem === 'function' ) { props.onSelectItem( id ); } }
		function name( row ) { var id = Number( row.settings.topic ), option = labels[ 0 ][ id ] || recordLabels[ 'topic:' + id ]; return option ? option.label : id > 0 ? '已选专题（#' + id + '）' : '专题暂不可用'; }
		function toggle( option, checked ) { var id = Number( option.value ), next = rows.slice(), selected = next.find( function ( row ) { return Number( row.settings.topic ) === id; } ); if ( checked ) { if ( selected || next.length >= max || busy[ 0 ] || error[ 0 ] ) { return; } selected = newItem( { topic: id } ); next.push( selected ); } else { next = next.filter( function ( row ) { return Number( row.settings.topic ) !== id; } ); } props.onChange( next ); notify( checked ? selected.id : null ); }
		function move( index, offset ) { var next = rows.slice(), target = index + offset; if ( target < 0 || target >= next.length ) { return; } var other = next[ target ]; next[ target ] = next[ index ]; next[ index ] = other; props.onChange( next ); notify( rows[ index ].id ); }
		function remove( index ) { props.onChange( rows.filter( function ( _, current ) { return current !== index; } ) ); notify( null ); }
		return el( 'div', { className: 'pc-me-topic-multiple', 'data-record-resource': 'topic' },
			el( 'p', { className: 'pc-me-label' }, field.label ), el( 'p', { role: 'status', 'aria-live': 'polite' }, '已选 ' + rows.length + ' / ' + max + ( rows.length >= max ? '，已达上限，请先移除专题。' : '' ) ),
			el( C.Button, { variant: 'secondary', 'aria-expanded': open[ 0 ], onClick: function () { open[ 1 ]( ! open[ 0 ] ); } }, open[ 0 ] ? '关闭专题选择' : '选择多个专题' ),
			open[ 0 ] ? el( 'div', { className: 'pc-me-record__chooser' },
				el( C.TextControl, { label: '搜索本组专题', value: term[ 0 ], maxLength: 100, onChange: function ( value ) { term[ 1 ]( value ); page[ 1 ]( 1 ); } } ),
				el( 'p', { className: 'pc-me-help' }, '条目文字包含发布状态；买家仅显示已发布且无密码的专题。' ),
				busy[ 0 ] ? el( 'p', { role: 'status' }, '正在读取专题…' ) : null,
				! busy[ 0 ] && ! error[ 0 ] ? el( 'div', { className: 'pc-me-record__results', role: 'group', 'aria-label': '可多选的专题' }, result[ 0 ].options.length ? result[ 0 ].options.map( function ( option ) { var selected = rows.some( function ( row ) { return Number( row.settings.topic ) === Number( option.value ); } ); return el( 'label', { key: option.value, className: 'pc-me-record__option' }, el( 'input', { type: 'checkbox', checked: selected, disabled: ! selected && rows.length >= max, 'aria-label': '选择专题 ' + option.label, onChange: function ( event ) { toggle( option, event.target.checked ); } } ), el( 'span', null, option.label ) ); } ) : el( 'p', { className: 'pc-me-record__empty' }, term[ 0 ] ? '没有匹配专题，请调整搜索。' : '当前没有可选专题。可稍后刷新或在专题管理中创建。' ) ) : null,
				error[ 0 ] ? el( 'div', null, el( 'p', { role: 'alert', className: 'pc-me-error' }, error[ 0 ] ), el( C.Button, { variant: 'secondary', onClick: function () { retry[ 1 ]( retry[ 0 ] + 1 ); } }, '重试专题读取' ) ) : null,
				el( 'div', { className: 'pc-me-record__pagination' }, el( C.Button, { variant: 'tertiary', disabled: page[ 0 ] <= 1 || busy[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] - 1 ); } }, '上一页专题' ), el( 'span', null, '第 ' + page[ 0 ] + ' 页' ), el( C.Button, { variant: 'tertiary', disabled: ! result[ 0 ].more || busy[ 0 ] || !! error[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] + 1 ); } }, '下一页专题' ) )
			) : null,
			el( 'div', { 'aria-label': '本组已选专题' }, rows.map( function ( row, index ) { var title = name( row ); return el( 'div', { key: row.id, className: 'pc-me-record__summary' }, el( 'div', { className: 'pc-me-record__identity' }, el( C.Button, { variant: 'tertiary', disabled: typeof props.onSelectItem !== 'function', label: '在预览中查看 ' + title, style: { whiteSpace: 'normal', height: 'auto', maxWidth: '100%', textAlign: 'left', overflowWrap: 'anywhere' }, onClick: function () { notify( row.id ); } }, title ) ), el( 'div', { className: 'pc-me-record__actions' }, el( C.Button, { icon: 'arrow-up-alt2', label: '向上移动 ' + title, disabled: index === 0, onClick: function () { move( index, -1 ); } } ), el( C.Button, { icon: 'arrow-down-alt2', label: '向下移动 ' + title, disabled: index === rows.length - 1, onClick: function () { move( index, 1 ); } } ), el( C.Button, { icon: 'trash', label: '移除 ' + title, onClick: function () { remove( index ); } } ) ) ); } ) ),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null
		);
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

	/** Promo categories keep a local choice until the user confirms this field. */
	function ConfirmCollectionField( props ) {
		var field = props.field, scope = props.scope || 'desktop';
		var id = useState( uid() )[ 0 ], open = useState( false ), pending = useState( '' ), term = useState( '' ), notice = useState( '' );
		var trigger = useRef( null ), dialog = useRef( null ), search = useRef( null ), session = useRef( null ), mounted = useRef( true ), pendingValue = useRef( '' );
		var latest = useRef( null );
		latest.current = { value: props.value, scope: scope, key: field.key, onChange: props.onChange };
		// Invalidate synchronously as well as closing in the effect: a queued old
		// callback must not become valid again after a same-value device round trip.
		if ( session.current && ( session.current.value !== props.value || session.current.scope !== scope || session.current.key !== field.key ) ) { session.current.invalid = true; }
		var renderedSession = session.current;
		function valueKey( value ) { return value === null || value === undefined || value === '' ? '' : String( value ); }
		function rowsNow() { return Array.isArray( data.categories ) ? data.categories : []; }
		function selectable( row ) { return row && /^[1-9]\d*$/.test( String( row.value ) ) && Number.isSafeInteger( Number( row.value ) ) && typeof row.label === 'string'; }
		function option( value ) { return rowsNow().find( function ( row ) { return selectable( row ) && String( row.value ) === valueKey( value ); } ); }
		function sameSession( expected ) {
			var current = latest.current, active = session.current;
			return mounted.current && active && ( expected === undefined || active === expected ) && ! active.invalid && current && active.value === current.value && active.scope === current.scope && active.key === current.key;
		}
		function finish( restoreFocus ) {
			session.current = null; pendingValue.current = '';
			if ( dialog.current && dialog.current.open ) { dialog.current.close(); }
			if ( mounted.current ) { open[ 1 ]( false ); pending[ 1 ]( '' ); term[ 1 ]( '' ); notice[ 1 ]( '' ); }
			if ( restoreFocus && trigger.current && trigger.current.isConnected ) { trigger.current.focus( { preventScroll: true } ); }
		}
		function dismiss( restoreFocus ) { if ( session.current === renderedSession ) { finish( restoreFocus ); } }
		useEffect( function () {
			mounted.current = true;
			return function () { mounted.current = false; session.current = null; pendingValue.current = ''; if ( dialog.current && dialog.current.open ) { dialog.current.close(); } };
		}, [] );
		useEffect( function () { if ( open[ 0 ] && ! sameSession() ) { finish( false ); } }, [ props.value, scope, field.key ] );
		useEffect( function () {
			if ( ! open[ 0 ] || ! dialog.current || ! sameSession( renderedSession ) ) { return; }
			var node = dialog.current;
			if ( ! node.open ) { node.showModal(); }
			if ( search.current ) { search.current.focus(); }
			return function () { if ( node.open ) { node.close(); } };
		}, [ open[ 0 ] ] );
		function choose() {
			var current = latest.current;
			if ( ! mounted.current || ! current || current.value !== props.value || current.scope !== scope || current.key !== field.key ) { return; }
			session.current = { value: current.value, scope: current.scope, key: current.key, invalid: false };
			pendingValue.current = option( current.value ) ? valueKey( current.value ) : '';
			pending[ 1 ]( pendingValue.current ); term[ 1 ]( '' ); notice[ 1 ]( '' ); open[ 1 ]( true );
		}
		function select( value ) {
			if ( ! sameSession( renderedSession ) ) { dismiss( false ); return; }
			if ( ! option( value ) ) { notice[ 1 ]( '此专辑暂不可选，请选择其他专辑。' ); return; }
			pendingValue.current = valueKey( value ); pending[ 1 ]( pendingValue.current ); notice[ 1 ]( '' );
		}
		function confirm() {
			if ( ! sameSession( renderedSession ) ) { dismiss( false ); return; }
			var selected = option( pendingValue.current ), current = latest.current;
			if ( ! selected ) { notice[ 1 ]( '请选择当前可用的商品专辑。' ); return; }
			var next = Number( selected.value ), changed = valueKey( current.value ) !== String( next ), callback = current.onChange;
			finish( true );
			if ( changed ) { callback( next ); }
		}
		function clear() {
			var current = latest.current;
			if ( ! mounted.current || ! current || current.value !== props.value || current.scope !== scope || current.key !== field.key || ! valueKey( current.value ) ) { return; }
			finish( false ); current.onChange( null );
		}
		function keys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); dismiss( true ); return; }
			if ( event.key !== 'Tab' || ! dialog.current ) { return; }
			var targets = Array.prototype.filter.call( dialog.current.querySelectorAll( 'button,input,a[href]' ), function ( node ) { return ! node.disabled && node.tabIndex !== -1 && node.getClientRects().length; } );
			var first = targets[ 0 ], last = targets[ targets.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) { event.preventDefault(); last.focus(); }
			else if ( ! event.shiftKey && document.activeElement === last ) { event.preventDefault(); first.focus(); }
		}
		var selected = option( props.value ), candidate = option( pending[ 0 ] );
		var list = rowsNow().filter( function ( row ) { return selectable( row ) && row.label.toLocaleLowerCase().indexOf( term[ 0 ].trim().toLocaleLowerCase() ) !== -1; } );
		function count( row ) { return row && row.count !== null && row.count !== undefined && Number.isFinite( Number( row.count ) ) && Number( row.count ) >= 0 ? String( Number( row.count ) ) : '—'; }
		return el( 'div', { className: 'pc-me-collection-confirm' },
			el( 'p', { className: 'pc-me-label' }, field.label ),
			el( 'div', { className: 'pc-me-record__summary' },
				el( 'div', { className: 'pc-me-record__identity' },
					el( 'strong', null, valueKey( props.value ) ? ( selected ? selected.label : '已选专辑（#' + valueKey( props.value ) + '）' ) : '尚未选择专辑' ),
					selected ? el( 'span', { className: 'pc-me-record__meta' }, count( selected ) === '—' ? '商品数量未提供' : count( selected ) + ' 件商品' ) : valueKey( props.value ) ? el( 'span', { className: 'pc-me-record__meta', role: 'status' }, '专辑暂不可选，原关联已保留。' ) : null
				),
				el( 'div', { className: 'pc-me-record__actions' },
					el( 'button', { type: 'button', ref: trigger, className: 'components-button is-secondary', 'aria-haspopup': 'dialog', 'aria-expanded': open[ 0 ], onClick: choose }, valueKey( props.value ) ? '替换' : '选择专辑' ),
					valueKey( props.value ) ? el( C.Button, { variant: 'tertiary', size: 'small', onClick: clear }, S.clear || '清除' ) : null
				)
			),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null,
			open[ 0 ] ? el( 'dialog', { ref: dialog, className: 'pc-me-collection-dialog', 'aria-labelledby': id + '-title', 'aria-describedby': id + '-note', 'aria-modal': true,
				onCancel: function ( event ) { event.preventDefault(); event.stopPropagation(); dismiss( true ); }, onClose: function ( event ) { if ( dialog.current === event.currentTarget && ! event.currentTarget.open ) { dismiss( true ); } }, onKeyDown: keys },
				el( 'div', { className: 'pc-me-collection-dialog__head' },
					el( 'h2', { id: id + '-title' }, '选择' + field.label ),
					el( 'button', { type: 'button', className: 'pc-me-collection-dialog__close', 'aria-label': '关闭并取消', onClick: function () { dismiss( true ); } },
						el( 'svg', { viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', 'aria-hidden': true, focusable: false }, el( 'path', { d: 'M6 6l12 12M18 6L6 18' } ) )
					)
				),
				el( 'div', { className: 'pc-me-collection-dialog__search' },
					el( 'label', { htmlFor: id + '-search' }, '搜索专辑' ),
					el( 'input', { id: id + '-search', ref: search, type: 'search', value: term[ 0 ], placeholder: '输入专辑名称', onChange: function ( event ) { if ( sameSession( renderedSession ) ) { term[ 1 ]( event.target.value ); } } } )
				),
				el( 'div', { className: 'pc-me-collection-dialog__columns', 'aria-hidden': true }, el( 'span', null, '专辑' ), el( 'span', null, '商品数量' ) ),
				el( 'div', { className: 'pc-me-collection-dialog__results', role: 'group', 'aria-label': '专辑搜索结果' },
					list.length ? list.map( function ( row ) {
						return el( 'label', { key: row.value, className: 'pc-me-collection-dialog__option' },
							el( 'input', { type: 'radio', name: id + '-category', value: row.value, checked: String( row.value ) === pending[ 0 ], onChange: function () { select( row.value ); } } ),
							el( 'span', null, row.label ), el( 'span', { className: 'pc-me-collection-dialog__count', 'aria-label': count( row ) === '—' ? '商品数量未提供' : count( row ) + ' 件商品' }, count( row ) )
						);
					} ) : el( 'p', { className: 'pc-me-record__empty' }, term[ 0 ] ? '没有找到匹配专辑，请调整关键词。' : '暂无可选商品专辑。' )
				),
				el( 'div', { className: 'pc-me-collection-dialog__foot' },
					el( 'p', { id: id + '-note', role: notice[ 0 ] ? 'status' : undefined, title: notice[ 0 ] || ( candidate ? candidate.label : undefined ) }, notice[ 0 ] || ( candidate ? '已选择：' + candidate.label : '请选择专辑，确定后更新此字段。' ) ),
					el( 'div', null, el( C.Button, { variant: 'secondary', onClick: function () { dismiss( true ); } }, '取消' ), el( C.Button, { variant: 'primary', disabled: ! candidate, onClick: confirm }, '确定' ) )
				)
			) : null
		);
	}

	/** One explicitly opted-in menu field; core REST reads never edit a menu. */
	function MenuPickerField( props ) {
		var field = props.field, scope = props.scope || 'desktop', cfg = data.menuPicker || {};
		var id = useState( uid() )[ 0 ], open = useState( false ), pending = useState( '' ), term = useState( '' ), rows = useState( [] );
		var paging = useState( { page: 1, pages: 0, query: '' } ), loading = useState( false ), checking = useState( false ), notice = useState( '' ), version = useState( 0 );
		var trigger = useRef( null ), dialog = useRef( null ), search = useRef( null ), alive = useRef( true ), session = useRef( null ), latest = useRef( null );
		var cache = useRef( null ), pendingValue = useRef( '' ), listRequest = useRef( null ), labelRequest = useRef( null ), confirmRequest = useRef( null ), generation = useRef( 0 );
		function key( value ) { return value === null || value === undefined || value === '' ? '' : String( value ); }
		function validId( value ) { return /^[1-9]\d*$/.test( key( value ) ) && Number.isSafeInteger( Number( value ) ); }
		function documentKey() { try { return richDocumentId(); } catch ( unavailable ) { return 0; } }
		function label( value ) { var box = document.createElement( 'textarea' ); box.innerHTML = value; return box.value; }
		function option( value ) { return value && validId( value.id ) && typeof value.name === 'string' ? { value: String( value.id ), label: label( value.name ) } : null; }
		if ( ! cache.current ) {
			cache.current = new Map();
			( data.menus || [] ).forEach( function ( row ) { if ( row && validId( row.value ) && typeof row.label === 'string' ) { cache.current.set( key( row.value ), { value: key( row.value ), label: label( row.label ) } ); } } );
		}
		latest.current = { value: props.value, scope: scope, key: field.key, doc: documentKey(), onChange: props.onChange };
		if ( session.current && ( session.current.value !== props.value || session.current.scope !== scope || session.current.key !== field.key || session.current.doc !== latest.current.doc ) ) { session.current.invalid = true; }
		var renderedSession = session.current;
		function same( expected ) {
			var active = session.current, now = latest.current;
			return alive.current && active && ( expected === undefined || expected === active ) && ! active.invalid && now && active.value === now.value && active.scope === now.scope && active.key === now.key && active.doc === now.doc && active.doc === documentKey();
		}
		function abort( ref ) { if ( ref.current ) { ref.current.abort(); ref.current = null; } }
		function finish( restoreFocus ) {
			session.current = null; pendingValue.current = ''; generation.current++; abort( listRequest ); abort( labelRequest ); abort( confirmRequest );
			if ( dialog.current && dialog.current.open ) { dialog.current.close(); }
			if ( alive.current ) { open[ 1 ]( false ); pending[ 1 ]( '' ); term[ 1 ]( '' ); notice[ 1 ]( '' ); loading[ 1 ]( false ); checking[ 1 ]( false ); }
			if ( restoreFocus && trigger.current && trigger.current.isConnected ) { trigger.current.focus( { preventScroll: true } ); }
		}
		function dismiss( restoreFocus ) { if ( session.current === renderedSession ) { finish( restoreFocus ); } }
		function errorText( error ) { return error && typeof error.message === 'string' ? plainText( error.message ) : '菜单读取失败，请刷新重试；原关联没有改动。'; }
		function read( selectedId, query, page, signal ) {
			if ( ! cfg.canRefresh || typeof cfg.path !== 'string' || ! /^\/wp\/v2\/menus\/?$/.test( cfg.path ) || ! wp.apiFetch ) { return Promise.reject( new Error( '菜单读取暂不可用，请稍后重试。' ) ); }
			var path = cfg.path.replace( /\/$/, '' ) + ( selectedId ? '/' + selectedId : '' );
			var params = new URLSearchParams( { context: 'edit', _fields: 'id,name' } );
			if ( ! selectedId ) { params.set( 'per_page', '20' ); params.set( 'page', String( page ) ); params.set( 'orderby', 'name' ); params.set( 'order', 'asc' ); params.set( 'hide_empty', 'false' ); params.set( 'search', query ); }
			return wp.apiFetch( { path: path + '?' + params.toString(), method: 'GET', parse: false, cache: 'no-store', signal: signal } ).then( function ( response ) {
				if ( ! response || typeof response.json !== 'function' ) { throw new Error( '菜单响应无法读取，请刷新重试。' ); }
				return response.json().then( function ( result ) {
					if ( ! response.ok ) { var error = new Error( result && typeof result.message === 'string' ? result.message : '菜单读取失败，请重试。' ); error.status = response.status; throw error; }
					if ( selectedId ) { var row = option( result ); if ( ! row || row.value !== String( selectedId ) ) { throw new Error( '此菜单暂不可选，请重新选择。' ); } return row; }
					if ( ! Array.isArray( result ) || result.some( function ( item ) { return ! option( item ); } ) ) { throw new Error( '菜单列表无法读取，请刷新重试。' ); }
					var pagesHeader = response.headers.get( 'X-WP-TotalPages' ), pages = Number( pagesHeader );
					if ( pagesHeader === null || ! Number.isSafeInteger( pages ) || pages < 0 ) { throw new Error( '菜单分页信息不可用，请刷新重试。' ); }
					return { rows: result.map( option ), page: page, pages: pages, query: query };
				} );
			} );
		}
		function resolveCurrent( expected ) {
			var selected = key( latest.current.value );
			if ( ! validId( selected ) ) { return; }
			abort( labelRequest ); var controller = new AbortController(); labelRequest.current = controller;
			read( selected, '', 1, controller.signal ).then( function ( row ) {
				if ( ! same( expected ) || labelRequest.current !== controller || key( latest.current.value ) !== selected ) { return; }
				cache.current.set( row.value, row ); version[ 1 ]( function ( value ) { return value + 1; } );
			} ).catch( function ( error ) { if ( same( expected ) && labelRequest.current === controller && error.name !== 'AbortError' ) { notice[ 1 ]( error.status === 404 ? '原菜单已不存在；关联暂时保留，请选择其他菜单。' : errorText( error ) ); } } );
		}
		function loadPage( page, query, expected, refreshSelected ) {
			if ( ! same( expected ) || confirmRequest.current ) { return; }
			abort( listRequest ); var controller = new AbortController(), request = ++generation.current; listRequest.current = controller;
			loading[ 1 ]( true ); notice[ 1 ]( '' );
			read( null, query, page, controller.signal ).then( function ( result ) {
				if ( ! same( expected ) || listRequest.current !== controller || request !== generation.current ) { return; }
				result.rows.forEach( function ( row ) { cache.current.set( row.value, row ); } ); rows[ 1 ]( result.rows ); paging[ 1 ]( { page: result.page, pages: result.pages, query: result.query } );
			} ).catch( function ( error ) { if ( same( expected ) && listRequest.current === controller && request === generation.current && error.name !== 'AbortError' ) { notice[ 1 ]( errorText( error ) ); } } ).finally( function () { if ( same( expected ) && listRequest.current === controller ) { listRequest.current = null; loading[ 1 ]( false ); } } );
			if ( refreshSelected ) { resolveCurrent( expected ); }
		}
		useEffect( function () { alive.current = true; return function () { alive.current = false; session.current = null; generation.current++; abort( listRequest ); abort( labelRequest ); abort( confirmRequest ); if ( dialog.current && dialog.current.open ) { dialog.current.close(); } }; }, [] );
		useEffect( function () { if ( open[ 0 ] && ! same() ) { finish( false ); } }, [ props.value, scope, field.key, latest.current.doc ] );
		useEffect( function () {
			if ( ! open[ 0 ] || ! dialog.current || ! same( renderedSession ) ) { return; }
			var node = dialog.current; if ( ! node.open ) { node.showModal(); } search.current && search.current.focus();
			loadPage( 1, '', renderedSession, true );
			return function () { if ( node.open ) { node.close(); } };
		}, [ open[ 0 ] ] );
		function choose() {
			if ( ! alive.current ) { return; }
			var now = latest.current; session.current = { value: now.value, scope: now.scope, key: now.key, doc: now.doc, invalid: false };
			pendingValue.current = key( now.value ); pending[ 1 ]( pendingValue.current ); term[ 1 ]( '' ); notice[ 1 ]( '' ); rows[ 1 ]( [] ); paging[ 1 ]( { page: 1, pages: 0, query: '' } ); open[ 1 ]( true );
		}
		function select( value ) { if ( ! same( renderedSession ) || confirmRequest.current || ! cache.current.has( value ) ) { return; } pendingValue.current = value; pending[ 1 ]( value ); notice[ 1 ]( '' ); }
		function confirm() {
			if ( ! same( renderedSession ) || confirmRequest.current || ! validId( pendingValue.current ) ) { return; }
			var selected = pendingValue.current, expected = renderedSession, controller = new AbortController(); confirmRequest.current = controller; checking[ 1 ]( true ); notice[ 1 ]( '' );
			read( selected, '', 1, controller.signal ).then( function ( row ) {
				if ( ! same( expected ) || confirmRequest.current !== controller || pendingValue.current !== selected ) { return; }
				cache.current.set( row.value, row );
				var now = latest.current, changed = key( now.value ) !== row.value, callback = now.onChange; finish( true ); if ( changed ) { callback( Number( row.value ) ); }
			} ).catch( function ( error ) { if ( same( expected ) && confirmRequest.current === controller && error.name !== 'AbortError' ) { notice[ 1 ]( error.status === 404 ? '此菜单已不存在，请选择其他菜单。' : errorText( error ) ); } } ).finally( function () { if ( same( expected ) && confirmRequest.current === controller ) { confirmRequest.current = null; checking[ 1 ]( false ); } } );
		}
		function clear() { if ( alive.current && latest.current.value === props.value && latest.current.scope === scope && latest.current.key === field.key && latest.current.doc === documentKey() && validId( props.value ) ) { var callback = latest.current.onChange; finish( true ); callback( null ); } }
		function searchNow() { if ( same( renderedSession ) ) { loadPage( 1, term[ 0 ].trim(), renderedSession, false ); } }
		function keys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); dismiss( true ); return; }
			if ( event.key !== 'Tab' || ! dialog.current ) { return; }
			var targets = Array.prototype.filter.call( dialog.current.querySelectorAll( 'button,input,a[href]' ), function ( node ) { return ! node.disabled && node.tabIndex !== -1 && node.getClientRects().length; } ), first = targets[ 0 ], last = targets[ targets.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) { event.preventDefault(); last.focus(); } else if ( ! event.shiftKey && document.activeElement === last ) { event.preventDefault(); first.focus(); }
		}
		var selected = cache.current.get( key( props.value ) ), candidate = cache.current.get( pending[ 0 ] );
		return el( 'div', { className: 'pc-me-menu-picker' },
			el( 'p', { className: 'pc-me-label' }, field.label ),
			el( 'div', { className: 'pc-me-record__summary' }, el( 'div', { className: 'pc-me-record__identity' }, el( 'strong', null, selected ? selected.label : validId( props.value ) ? '已选择菜单（#' + props.value + '）' : '尚未选择菜单' ) ),
				el( 'div', { className: 'pc-me-record__actions' }, el( 'button', { ref: trigger, type: 'button', className: 'components-button is-secondary', 'aria-haspopup': 'dialog', 'aria-expanded': open[ 0 ], onClick: choose }, validId( props.value ) ? '替换菜单' : '选择菜单' ), validId( props.value ) ? el( C.Button, { variant: 'tertiary', onClick: clear }, S.clear || '清除' ) : null ) ),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null,
			open[ 0 ] ? el( 'dialog', { ref: dialog, className: 'pc-me-collection-dialog', style: { width: 'min(440px, calc(100vw - 32px))' }, 'aria-labelledby': id + '-title', 'aria-describedby': id + '-note', 'aria-modal': true,
				onCancel: function ( event ) { event.preventDefault(); event.stopPropagation(); dismiss( true ); }, onClose: function ( event ) { if ( dialog.current === event.currentTarget && ! event.currentTarget.open ) { dismiss( true ); } }, onKeyDown: keys },
				el( 'div', { className: 'pc-me-collection-dialog__head' }, el( 'h2', { id: id + '-title' }, '选择菜单' ), el( 'button', { type: 'button', className: 'pc-me-collection-dialog__close', 'aria-label': '关闭并取消', onClick: function () { dismiss( true ); } }, el( 'svg', { viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, 'aria-hidden': true, focusable: false }, el( 'path', { d: 'M6 6l12 12M18 6L6 18' } ) ) ) ),
				el( 'div', { className: 'pc-me-collection-dialog__search' }, el( 'label', { htmlFor: id + '-search' }, '搜索菜单' ), el( 'input', { ref: search, id: id + '-search', type: 'search', maxLength: 32, value: term[ 0 ], placeholder: '输入菜单名称', onChange: function ( event ) { if ( same( renderedSession ) ) { term[ 1 ]( event.target.value ); } }, onKeyDown: function ( event ) { if ( event.key === 'Enter' ) { event.preventDefault(); searchNow(); } } } ), el( C.Button, { variant: 'secondary', disabled: checking[ 0 ], onClick: searchNow }, '搜索' ) ),
				el( 'div', { className: 'pc-me-collection-dialog__columns', style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px' } }, el( 'span', null, '菜单名称' ), el( 'div', null, cfg.canManage && cfg.manageUrl ? el( 'a', { href: cfg.manageUrl, target: '_blank', rel: 'noopener noreferrer', className: 'components-button is-tertiary' }, '管理菜单' ) : null, el( C.Button, { variant: 'tertiary', disabled: checking[ 0 ], onClick: function () { loadPage( 1, term[ 0 ].trim(), renderedSession, true ); } }, '刷新' ) ) ),
				el( 'div', { className: 'pc-me-collection-dialog__results', role: 'group', 'aria-label': '菜单搜索结果', 'aria-busy': loading[ 0 ] || checking[ 0 ] },
					loading[ 0 ] ? el( 'p', { role: 'status' }, '正在读取菜单…' ) : rows[ 0 ].length ? rows[ 0 ].map( function ( row ) { return el( 'label', { key: row.value, className: 'pc-me-collection-dialog__option', style: { gridTemplateColumns: '20px minmax(0, 1fr)' } }, el( 'input', { type: 'radio', name: id + '-menu', value: row.value, checked: row.value === pending[ 0 ], disabled: checking[ 0 ], onChange: function () { select( row.value ); } } ), el( 'span', null, row.label ) ); } ) : el( 'p', { className: 'pc-me-record__empty' }, notice[ 0 ] ? '菜单列表暂未更新。' : '没有找到菜单，请调整搜索或管理菜单后刷新。' ) ),
				el( 'div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', padding: '8px 16px' } }, el( C.Button, { variant: 'secondary', disabled: loading[ 0 ] || checking[ 0 ] || paging[ 0 ].page <= 1, onClick: function () { loadPage( paging[ 0 ].page - 1, paging[ 0 ].query, renderedSession, false ); } }, '上一页' ), el( 'span', null, paging[ 0 ].pages ? paging[ 0 ].page + ' / ' + paging[ 0 ].pages : '' ), el( C.Button, { variant: 'secondary', disabled: loading[ 0 ] || checking[ 0 ] || paging[ 0 ].page >= paging[ 0 ].pages, onClick: function () { loadPage( paging[ 0 ].page + 1, paging[ 0 ].query, renderedSession, false ); } }, '下一页' ) ),
				el( 'div', { className: 'pc-me-collection-dialog__foot' }, el( 'p', { id: id + '-note', role: 'status' }, notice[ 0 ] || ( candidate ? '已选择：' + candidate.label : '选择菜单，确定后更新此字段。' ) ), el( 'div', null, el( C.Button, { variant: 'secondary', onClick: function () { dismiss( true ); } }, '取消' ), el( C.Button, { variant: 'primary', disabled: checking[ 0 ] || ! validId( pending[ 0 ] ), onClick: confirm }, checking[ 0 ] ? '正在确认…' : '确定' ) ) )
			) : null
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
		var announcement = props.announcementScope && props.announcementScope.announcement_ui === '1' && props.announcementScope.announcement_module === 'announcement' && props.announcementScope.announcement_mode === 'reference' && typeof props.announcementScope.owner === 'string' && props.announcementScope.owner ? props.announcementScope : null, latestDialog = useRef( props ); latestDialog.current = props;
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
				linkRequest( announcementParams( announcement, { type: query.type, term: query.term.trim(), paged: query.page, mode: 'list' } ), abort.signal ).then( function ( payload ) {
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
			linkRequest( announcementParams( announcement, { mode: 'resolve', type: picked.type, id: picked.id } ), abort.signal ).then( function ( payload ) {
				if ( abort.signal.aborted || request !== requestRef.current ) { return; }
				if ( ! linkTarget( payload.target ) || payload.target.key !== picked.key ) { throw new Error( '目标已不可用，请重新选择。' ); }
				if ( announcement && typeof latestDialog.current.onSelectTarget === 'function' ) { latestDialog.current.onSelectTarget( payload.target ); } else { props.onSelect( payload.target.url ); } props.onClose();
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
			el( 'div', { className: 'pc-me-link-dialog__filters' }, el( C.SelectControl, { label: '目标类型', value: query.type, disabled: confirming, options: ( ( data.linkLookup || {} ).types || [ { value: 'route', label: '常用页面' } ] ).concat( announcement ? [ { value: 'variation', label: '商品规格详情' }, { value: 'blog_category', label: '博客专辑详情' } ] : [] ), onChange: function ( v ) { changeQuery( 'type', v ); } } ), el( C.TextControl, { label: '搜索目标', value: query.term, maxLength: 100, disabled: confirming, placeholder: '输入名称；商品也可输入 SKU', onChange: function ( v ) { changeQuery( 'term', v ); } } ) ),
			el( 'p', { className: 'pc-me-link-dialog__hint' }, announcement ? '关联记录后跟随当前公开网址；公告与按钮文案由你填写。' : '仅填入网址，不改变按钮文案。目标网址变更后可重新选择。' ),
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

	function youtubeMetadataId( value ) {
		if ( typeof value !== 'string' || ! value || value.length > 2048 || /[\u0000-\u0020\u007f]/.test( value ) ) { return null; }
		// URL() canonicalizes encoded hosts/backslashes/dot segments that the
		// server reference parser rejects. Preserve the original authority/path.
		var original = value.match( /^https:\/\/(?:youtu\.be|(?:www\.|m\.)?youtube\.com|(?:www\.)?youtube-nocookie\.com)(?::0*443)?(\/[^?#]*)?(?:[?#]|$)/i );
		if ( ! original ) { return null; }
		var url; try { url = new URL( value ); } catch ( error ) { return null; }
		if ( url.protocol !== 'https:' || url.username || url.password || url.port && url.port !== '443' ) { return null; }
		var id = null, match, host = url.hostname.toLowerCase(), path = original[ 1 ] || '';
		if ( host === 'youtu.be' ) { match = path.match( /^\/([A-Za-z0-9_-]{11})\/?$/ ); id = match && match[ 1 ]; }
		else if ( [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ].indexOf( host ) !== -1 ) {
			if ( path === '/watch' && host.indexOf( 'nocookie' ) === -1 ) {
				var values = [];
				for ( var part of url.search.slice( 1 ).split( '&' ) ) {
					var pair = part.split( '=' ), key; try { key = decodeURIComponent( pair[ 0 ] ); } catch ( error ) { return null; }
					if ( /[\[\]]/.test( key ) ) { return null; }
					if ( key === 'v' ) { try { values.push( decodeURIComponent( pair.slice( 1 ).join( '=' ) ) ); } catch ( error ) { return null; } }
				}
				id = values.length === 1 ? values[ 0 ] : null;
			} else {
				match = path.match( /^\/(embed|shorts|live)\/([A-Za-z0-9_-]{11})\/?$/ );
				if ( match && ( host.indexOf( 'nocookie' ) === -1 || match[ 1 ] === 'embed' ) ) { id = match[ 2 ]; }
			}
		}
		return typeof id === 'string' && /^[A-Za-z0-9_-]{11}$/.test( id ) ? id : null;
	}

	function youtubeMetadataThumbnail( value, id ) {
		if ( typeof value !== 'string' || value.length > 2048 || /[\u0000-\u0020\u007f]/.test( value ) ) { return ''; }
		if ( ! /^https:\/\/i\.ytimg\.com\//.test( value ) ) { return ''; }
		var url; try { url = new URL( value ); } catch ( error ) { return ''; }
		return url.protocol === 'https:' && url.hostname === 'i.ytimg.com' && ! url.username && ! url.password && ! url.port && ! url.hash && new RegExp( '^/(?:vi|vi_webp)/' + id + '/[A-Za-z0-9_-]{1,64}\\.(?:jpg|jpeg|webp|png)$' ).test( url.pathname ) ? value : '';
	}

	function YouTubeMetadataField( props ) {
		var ctx = props.context, config = data.youtubeMetadata || {}, value = props.value || '', id = youtubeMetadataId( value );
		var state = useState( { rows: {}, phase: 'idle', provider: config.provider } ), retry = useState( { count: 0, id: '' } );
		var projection = useRef( { owner: '', rows: {}, pending: false } );
		var ids = [];
		( ctx.metadataRows || [] ).slice( 0, 12 ).forEach( function ( item ) {
			var video = ( ! Object.prototype.hasOwnProperty.call( item, 'enabled' ) || !! item.enabled ) && youtubeMetadataId( ( item.settings || {} ).url );
			if ( video && ids.indexOf( video ) === -1 ) { ids.push( video ); }
		} );
		if ( id && ids.indexOf( id ) === -1 ) { ids.unshift( id ); }
		ids = ids.slice( 0, 12 );
		var signature = ids.join( ',' ), documentId = Number( ctx.metadataDocumentId ), row = state[ 0 ].rows[ id ] || {};
		var active = !! id && ctx.itemEnabled !== false && documentId > 0 && !! config.url && !! config.nonce && typeof wp.apiFetch === 'function';
		useEffect( function () {
			if ( ! active ) { return undefined; }
			var cancelled = false, controller, requestTimer, previewTimer, debounceTimer, attempts = 0, deadline = 0;
			var force = retry[ 0 ].id === id && retry[ 0 ].count > 0, queue = force ? [ id ] : ids.slice();
			var allowed = queue.slice(), owner = documentId + ':' + config.url + ':' + signature + ':' + id;
			if ( projection.current.owner !== owner ) { projection.current = { owner: owner, rows: {}, pending: false }; }
			function stop( status ) {
				if ( cancelled ) { return; }
				state[ 1 ]( function ( old ) {
					var rows = Object.assign( {}, old.rows );
					queue.forEach( function ( video ) { rows[ video ] = Object.assign( {}, rows[ video ], { status: status } ); } );
					return { rows: rows, phase: 'idle', provider: old.provider };
				} );
			}
			function next() {
				if ( cancelled ) { return; }
				if ( ! queue.length ) { state[ 1 ]( function ( old ) { return Object.assign( {}, old, { phase: 'idle' } ); } ); return; }
				if ( ++attempts > 12 || Date.now() >= deadline ) { stop( 'timeout' ); return; }
				controller = new AbortController();
				var expired = false;
				requestTimer = setTimeout( function () { expired = true; controller.abort(); stop( 'timeout' ); }, Math.min( 12000, deadline - Date.now() ) );
				var base;
				try { base = new URL( config.url, window.location.href ); if ( base.origin !== window.location.origin ) { throw new Error( 'origin' ); } } catch ( error ) { clearTimeout( requestTimer ); stop( 'error' ); return; }
				Promise.resolve().then( function () {
					if ( cancelled || expired ) { var aborted = new Error( 'Aborted' ); aborted.name = 'AbortError'; throw aborted; }
					return wp.apiFetch( { url: config.url + documentId + '/youtube-metadata', method: 'POST', headers: { 'X-WP-Nonce': config.nonce }, data: { videoIds: queue, force: force }, signal: controller.signal } );
				} ).then( function ( result ) {
					clearTimeout( requestTimer );
					if ( cancelled || expired ) { return; }
					if ( ! result || ! Array.isArray( result.rows ) || ! Array.isArray( result.pendingIds ) ) { stop( 'error' ); return; }
					var received = {}, projections = {};
					result.rows.slice( 0, 12 ).forEach( function ( entry ) {
						if ( ! entry || queue.indexOf( entry.videoId ) === -1 ) { return; }
						var video = entry.videoId;
						received[ video ] = {
							videoId: video, title: typeof entry.title === 'string' ? Array.from( entry.title ).slice( 0, 300 ).join( '' ) : '',
							authorName: typeof entry.authorName === 'string' ? Array.from( entry.authorName ).slice( 0, 300 ).join( '' ) : '',
							thumbnailUrl: youtubeMetadataThumbnail( entry.thumbnailUrl, video ),
							status: typeof entry.status === 'string' && Object.prototype.hasOwnProperty.call( labels, entry.status ) ? entry.status : 'error',
							fetchedAt: Number( entry.fetchedAt ) > 0 ? Number( entry.fetchedAt ) : 0,
						};
						// A cleared/expired projection has fetchedAt=0. Field, provider and
						// state changes still invalidate the preview, even at the same time.
						projections[ video ] = JSON.stringify( [ result.provider || config.provider, received[ video ], entry.stale === true ] );
					} );
					if ( Object.keys( received ).length !== queue.length ) { stop( 'error' ); return; }
					Object.keys( received ).forEach( function ( video ) {
						var old = projection.current.rows[ video ], entry = received[ video ];
						// A first settled empty response can also retire an older SSR cache.
						// Initial empty no-key/manual/queued states need no speculative refresh.
						var firstChange = entry.fetchedAt || entry.title || entry.authorName || entry.thumbnailUrl || [ 'no_key', 'manual', 'queued' ].indexOf( entry.status ) === -1 || result.provider && result.provider !== config.provider;
						if ( old !== undefined ? old !== projections[ video ] : firstChange ) { projection.current.pending = true; }
						projection.current.rows[ video ] = projections[ video ];
					} );
					state[ 1 ]( function ( old ) { return { rows: Object.assign( {}, old.rows, received ), phase: 'loading', provider: result.provider || old.provider }; } );
					if ( projection.current.pending && typeof ctx.onPreviewRefresh === 'function' ) {
						clearTimeout( previewTimer ); previewTimer = setTimeout( function () {
							if ( ! cancelled && projection.current.owner === owner ) { projection.current.pending = false; ctx.onPreviewRefresh(); }
						}, 150 );
					}
					var remaining = result.pendingIds.filter( function ( video, index, list ) { return typeof video === 'string' && queue.indexOf( video ) !== -1 && list.indexOf( video ) === index; } );
					if ( remaining.length >= queue.length && remaining.length ) { stop( 'error' ); return; }
					queue = remaining; next();
				} ).catch( function ( error ) {
					clearTimeout( requestTimer );
					if ( cancelled || expired || error && error.name === 'AbortError' ) { return; }
					stop( error && ( error.code === 'pc_youtube_forbidden' || error.code === 'pc_youtube_document_forbidden' || error.code === 'rest_cookie_invalid_nonce' ) ? 'auth' : 'error' );
				} );
			}
			state[ 1 ]( function ( old ) {
				var rows = {}; allowed.forEach( function ( video ) { rows[ video ] = Object.assign( {}, old.rows[ video ], { status: 'loading' } ); } );
				return { rows: rows, phase: 'loading', provider: old.provider };
			} );
			debounceTimer = setTimeout( function () { deadline = Date.now() + 60000; next(); }, 500 );
			return function () { cancelled = true; clearTimeout( debounceTimer ); clearTimeout( requestTimer ); clearTimeout( previewTimer ); if ( controller ) { controller.abort(); } };
		}, [ signature, id, active, documentId, config.url, config.nonce, config.provider, retry[ 0 ].count ] );

		var labels = {
			loading: '正在读取视频信息…', queued: '等待读取视频信息…', success: '视频信息已更新', partial: '已读取可用信息，部分字段未提供',
			no_key: '官方数据接口尚未配置，可联系管理员或选择无需密钥的基础信息。', manual: '当前仅手动播放，可在视频信息设置启用基础信息。',
			unavailable: '无法读取此视频，请检查链接是否公开可访问。', invalid_key: '服务配置未通过，请联系管理员检查。', denied: '服务拒绝读取，请联系管理员检查。',
			quota: '视频服务暂时达到调用限额，可稍后重试。', local_limit: '已达到本站读取上限，可稍后重试。', busy: '视频信息正在更新，请稍后重试。',
			network: '暂时无法连接视频服务，可重试；手动播放仍保留。', upstream: '视频服务暂时不可用，可重试。', invalid_response: '未能读取有效的视频信息，可重试。',
			error: '暂时无法读取视频信息，请重试。', timeout: '读取超时，请重试。', auth: '登录或编辑权限不可用，请重新登录后重试。',
		};
		var status = ! value ? '添加视频链接后读取标题、封面和作者。' : ! id ? '请输入有效的完整 HTTPS YouTube 视频链接。' : ctx.itemEnabled === false ? '此视频已隐藏。' : ! ( documentId > 0 ) ? '请先保存草稿，再读取视频信息。' : ! active ? '视频信息暂不可用，请刷新后台重试。' : labels[ row.status ] || '准备读取视频信息…';
		return el( 'div', { className: 'pc-me-youtube-metadata' },
			el( C.TextControl, { label: props.field.label, help: props.field.help, type: 'text', placeholder: 'https://www.youtube.com/watch?v=…', value: value, onChange: props.onChange } ),
			el( 'div', { className: 'pc-me-help', role: 'status', 'aria-live': 'polite' },
				row.thumbnailUrl ? el( 'img', { src: row.thumbnailUrl, alt: '', width: 64, height: 36, style: { objectFit: 'cover', float: 'left', marginRight: '8px' }, referrerPolicy: 'no-referrer' } ) : null,
				row.title ? el( 'strong', { style: { display: 'block', overflowWrap: 'anywhere' } }, row.title ) : null,
				row.authorName ? el( 'span', { style: { display: 'block', overflowWrap: 'anywhere' } }, row.authorName ) : null,
				el( 'span', { style: { display: 'block', clear: 'both' } }, status )
			),
			active ? el( C.Button, { variant: 'link', size: 'small', 'aria-disabled': state[ 0 ].phase === 'loading', onClick: function () {
				if ( state[ 0 ].phase !== 'loading' ) { retry[ 1 ]( function ( old ) { return { id: id, count: old.count + 1 }; } ); }
			} }, state[ 0 ].phase === 'loading' ? '读取中…' : '重新读取' ) : null
		);
	}

	// Only the exact server-owned O16 field may select this UI. Persisted values cannot opt in.
	function trustedFulfillmentIcons( field ) {
		var def = data.modules && data.modules[ 'vialcove/order-fulfillment' ];
		return !! ( def && Array.isArray( def.settings ) && def.settings.indexOf( field ) > -1 && field.key === 'shipIcon' && field.type === 'select' && field.ui === 'fulfillment-icons' && field.fulfillmentIcons === true && ! field.device && field.options && Object.keys( field.options ).sort().join( ',' ) === 'box,plane,ship,truck' );
	}
	function FulfillmentIcons( props ) {
		var choices = [ 'box', 'truck', 'plane', 'ship' ], selected = choices.indexOf( props.value ) > -1 ? props.value : 'box';
		function keyboard( event, index ) {
			var next = index;
			if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) { next = ( index + 1 ) % choices.length; }
			else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) { next = ( index + choices.length - 1 ) % choices.length; }
			else if ( event.key === 'Home' ) { next = 0; } else if ( event.key === 'End' ) { next = choices.length - 1; } else { return; }
			event.preventDefault(); props.onChange( choices[ next ] );
			var target = event.currentTarget.parentNode.querySelector( '[data-pc-fulfillment-icon="' + choices[ next ] + '"]' ); if ( target ) { target.focus(); }
		}
		return el( 'fieldset', { className: 'pc-me-fulfillment-icons' }, el( 'legend', null, props.field.label ),
			el( 'div', { role: 'radiogroup', 'aria-label': props.field.label }, choices.map( function ( key, index ) {
				var icons = window.PeptideDesignIcons;
				return el( C.Button, { key: key, type: 'button', role: 'radio', 'data-pc-fulfillment-icon': key, 'aria-checked': selected === key, tabIndex: selected === key ? 0 : -1, onClick: function () { props.onChange( key ); }, onKeyDown: function ( event ) { keyboard( event, index ); } }, icons && icons.renderAction ? icons.renderAction( 'fulfillment_' + key, { size: 24 } ) : null, el( 'span', null, props.field.options[ key ] ) );
			} ) ) );
	}

	// O21 controls require the exact server-owned global field and a separate owner namespace.
	function trustedAnnouncementField( field, ctx, kind ) {
		var def = data.modules && data.modules[ 'vialcove/announcement' ];
		if ( ! def || ! ctx || ctx.announcementReference !== true || ctx.moduleType !== 'announcement' || ! Array.isArray( def.settings ) || def.settings.indexOf( field ) < 0 || field.device || field.group !== 'general' ) { return false; }
		if ( kind === 'icons' ) { return field.key === 'announcementIcon' && field.type === 'select' && field.ui === 'announcement-icons' && field.announcementIcons === true && field.options && Object.keys( field.options ).sort().join( ',' ) === 'bell,clock,gift,megaphone,spark,tag,ticket'; }
		return field.key === 'announcementRows' && field.type === 'repeater' && field.ui === 'announcement-rows' && field.announcementRows === true && Array.isArray( field.fields ) && field.fields.map( function ( f ) { return f.key + ':' + f.type; } ).join( ',' ) === 'pcText:text,phoneText:text,link:link,targetMode:select,targetKind:select,targetId:text';
	}
	function AnnouncementIcons( props ) {
		var choices = [ 'megaphone', 'bell', 'gift', 'tag', 'spark', 'ticket', 'clock' ], selected = choices.indexOf( props.value ) > -1 ? props.value : 'megaphone';
		function keys( event, index ) {
			var next = index;
			if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) { next = ( index + 1 ) % choices.length; }
			else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) { next = ( index + choices.length - 1 ) % choices.length; }
			else if ( event.key === 'Home' ) { next = 0; } else if ( event.key === 'End' ) { next = choices.length - 1; } else { return; }
			event.preventDefault(); props.onChange( choices[ next ] );
			var target = event.currentTarget.parentNode.querySelector( '[data-pc-announcement-icon="' + choices[ next ] + '"]' ); if ( target ) { target.focus(); }
		}
		return el( 'fieldset', { className: 'pc-me-announcement-icons' }, el( 'legend', null, props.field.label ), el( 'div', { role: 'radiogroup', 'aria-label': props.field.label }, choices.map( function ( key, index ) {
			var icons = window.PeptideDesignIcons;
			return el( C.Button, { key: key, type: 'button', role: 'radio', 'data-pc-announcement-icon': key, 'aria-checked': key === selected, tabIndex: key === selected ? 0 : -1, onClick: function () { props.onChange( key ); }, onKeyDown: function ( event ) { keys( event, index ); } }, icons && icons.renderAction ? icons.renderAction( 'announcement_' + key, { size: 22 } ) : null, el( 'span', null, props.field.options[ key ] ) );
		} ) ) );
	}
	var announcementRowCounter = 0;
	function announcementNewItem( values, rows ) { var item = newItem( values ), used = ( rows || [] ).map( function ( row ) { return row.id; } ); do { item.id = uid() + 'a' + ( ++announcementRowCounter ).toString( 36 ); } while ( used.indexOf( item.id ) > -1 ); return item; }
	function announcementScope( owner ) { return { owner: owner, announcement_ui: '1', announcement_module: 'announcement', announcement_mode: 'reference' }; }
	function announcementParams( scope, params ) { return scope ? Object.assign( {}, params, { announcement_ui: '1', announcement_module: 'announcement', announcement_mode: 'reference' } ) : params; }
	function AnnouncementRowTarget( props ) {
		var latest = useRef( props ); latest.current = props;
		var choosing = useState( false ), draft = useState( props.value.link && props.value.link.href || '' ), notice = useState( '' ), refresh = useState( 0 ), inputError = useState( '' ), manualDraft = useRef( false );
		var owner = props.owner, signature = JSON.stringify( [ owner, props.value.targetMode, props.value.targetKind, props.value.targetId, props.value.link && props.value.link.href ] );
		var lifecycle = useRef( null );
		if ( ! lifecycle.current || lifecycle.current.signature !== signature ) { if ( lifecycle.current ) { lifecycle.current.live = false; } lifecycle.current = { signature: signature, owner: owner, live: true, selection: 0 }; }
		var token = lifecycle.current;
		function same( expected ) { return expected.live && lifecycle.current === expected && latest.current.owner === expected.owner; }
		useEffect( function () { token.live = true; if ( ! manualDraft.current ) { draft[ 1 ]( props.value.link && props.value.link.href || '' ); } manualDraft.current = false; choosing[ 1 ]( false ); return function () { token.live = false; }; }, [ signature ] );
		useEffect( function () {
			if ( props.value.targetMode !== 'binding' ) { notice[ 1 ]( '' ); return; }
			var alive = true, abort = new AbortController(), done = false;
			notice[ 1 ]( '正在核验关联目标…' );
			var timer = setTimeout( function () { if ( alive && same( token ) ) { done = true; notice[ 1 ]( '目标核验超时，点击重新核验。' ); abort.abort(); } }, 20000 );
			linkRequest( announcementParams( announcementScope( owner ), { mode: 'resolve', type: props.value.targetKind || '', id: props.value.targetId || '' } ), abort.signal ).then( function ( payload ) {
				if ( ! alive || done || abort.signal.aborted || ! same( token ) ) { return; }
				if ( ! linkTarget( payload.target ) || payload.target.type !== latest.current.value.targetKind || payload.target.id !== latest.current.value.targetId ) { throw new Error( '关联目标无效，请重新选择。' ); }
				notice[ 1 ]( '已关联：' + payload.target.label + '。公开展示使用当前网址。' );
			} ).catch( function ( problem ) { if ( alive && ! done && same( token ) && problem.name !== 'AbortError' ) { notice[ 1 ]( problem.message || '暂时无法核验，点击重新核验。' ); } } ).finally( function () { clearTimeout( timer ); } );
			return function () { alive = false; clearTimeout( timer ); abort.abort(); };
		}, [ signature, refresh[ 0 ] ] );
		function changeURL( value ) {
			manualDraft.current = true; draft[ 1 ]( value ); token.selection += 1; choosing[ 1 ]( false );
			var valid = safeLinkHref( value ); inputError[ 1 ]( valid ? '' : '仅支持 http(s)、站内路径、锚点、mailto 或 tel；当前无效网址不会展示按钮。' );
			latest.current.onPatch( { targetMode: 'manual', targetKind: '', targetId: '', link: { href: valid ? value : '' } } );
		}
		function select( target, expected, selection ) {
			if ( ! same( expected ) || expected.selection !== selection || ! linkTarget( target ) ) { return; }
			inputError[ 1 ]( '' ); latest.current.onPatch( { targetMode: 'binding', targetKind: target.type, targetId: target.id, link: { href: target.url } } );
		}
		var renderedSelection = token.selection;
		return el( 'div', { className: 'pc-me-announcement-target' },
			el( C.TextControl, { label: '按钮网址', placeholder: '/shop/ 或 https://…', value: draft[ 0 ], onChange: changeURL } ),
			el( C.Button, { variant: 'secondary', size: 'small', 'aria-haspopup': 'dialog', 'aria-expanded': choosing[ 0 ], onClick: function () { token.selection += 1; choosing[ 1 ]( true ); } }, props.value.targetMode === 'binding' ? '替换关联目标' : '选择站内目标' ),
			props.value.targetMode === 'binding' ? el( C.Button, { variant: 'link', size: 'small', onClick: function () { refresh[ 1 ]( function ( count ) { return count + 1; } ); } }, '重新核验' ) : null,
			notice[ 0 ] ? el( 'p', { className: 'pc-me-help', role: 'status' }, notice[ 0 ] ) : null,
			inputError[ 0 ] ? el( 'p', { className: 'pc-me-error', role: 'alert' }, inputError[ 0 ] ) : null,
			choosing[ 0 ] ? el( LinkTargetDialog, { key: owner + ':' + renderedSelection, announcementScope: announcementScope( owner ), onClose: function () { if ( same( token ) ) { token.selection += 1; choosing[ 1 ]( false ); } }, onSelectTarget: function ( target ) { select( target, token, renderedSelection ); } } ) : null
		);
	}
	function AnnouncementRowsField( props ) {
		var latest = useRef( props ); latest.current = props;
		var ownerRef = useRef( props.owner ); ownerRef.current = props.owner;
		var rows = Array.isArray( props.value ) ? props.value : [], seen = Object.create( null );
		var validRows = rows.every( function ( row ) { if ( ! row || typeof row.id !== 'string' || ! row.id || seen[ row.id ] ) { return false; } seen[ row.id ] = true; return !! row.settings; } );
		function patch( id, changes, expectedOwner ) {
			if ( ownerRef.current !== expectedOwner ) { return; }
			var current = latest.current, list = Array.isArray( current.value ) ? current.value : [], matches = list.filter( function ( row ) { return row.id === id; } );
			if ( matches.length !== 1 ) { return; }
			current.onChange( list.map( function ( row ) { return row.id === id ? Object.assign( {}, row, { settings: Object.assign( {}, row.settings, changes, Object.prototype.hasOwnProperty.call( changes, 'link' ) ? { link: Object.assign( {}, row.settings.link || {}, changes.link ) } : {} ) } ) : row; } ) );
		}
		function mutate( id, action ) {
			var current = latest.current, list = ( current.value || [] ).slice(), index = list.findIndex( function ( row ) { return row.id === id; } ); if ( index < 0 ) { return; }
			if ( action === 'delete' ) { list.splice( index, 1 ); }
			else if ( action === 'copy' && list.length < ( props.field.max || 12 ) ) { var copied = announcementNewItem( list[ index ].settings, list ); copied.enabled = list[ index ].enabled !== false; list.splice( index + 1, 0, copied ); }
			else if ( action === 'toggle' ) { list[ index ] = Object.assign( {}, list[ index ], { enabled: list[ index ].enabled === false } ); }
			else if ( action === 'up' && index > 0 ) { var previous = list[ index - 1 ]; list[ index - 1 ] = list[ index ]; list[ index ] = previous; }
			else if ( action === 'down' && index < list.length - 1 ) { var next = list[ index + 1 ]; list[ index + 1 ] = list[ index ]; list[ index ] = next; }
			current.onChange( list );
		}
		if ( ! validRows ) { return el( 'p', { className: 'pc-me-error', role: 'alert' }, '公告条目标识无效，无法安全编辑。请删除该块后重新添加，原内容不会自动改写。' ); }
		return el( 'div', { className: 'pc-me-announcement-rows' }, rows.length ? rows.map( function ( row, index ) {
			var value = row.settings || {}, rowOwner = JSON.stringify( [ props.owner, row.id ] ), link = value.link || { label: '', href: '', target: '_self' };
			function update( changes ) { patch( row.id, changes, props.owner ); }
			return el( 'details', { key: row.id, className: 'pc-me-announcement-row', open: true }, el( 'summary', null, '公告 ' + ( index + 1 ), row.enabled === false ? ' · 已隐藏' : '' ),
				el( 'div', { className: 'pc-me-announcement-row__actions' }, [ [ 'up', '上移' ], [ 'down', '下移' ], [ 'copy', '复制' ], [ 'toggle', row.enabled === false ? '显示' : '隐藏' ], [ 'delete', '删除' ] ].map( function ( action ) { return el( C.Button, { key: action[ 0 ], variant: 'tertiary', size: 'small', disabled: action[ 0 ] === 'up' && index === 0 || action[ 0 ] === 'down' && index === rows.length - 1 || action[ 0 ] === 'copy' && rows.length >= ( props.field.max || 12 ), onClick: function () { mutate( row.id, action[ 0 ] ); } }, action[ 1 ] ); } ) ),
				el( C.TextControl, { label: '电脑端公告', value: value.pcText || '', onChange: function ( text ) { update( { pcText: text } ); } } ),
				el( C.TextControl, { label: '手机端公告', help: '留空时沿用电脑端文字。', value: value.phoneText || '', onChange: function ( text ) { update( { phoneText: text } ); } } ),
				el( C.TextControl, { label: '按钮文案', value: link.label || '', onChange: function ( text ) { update( { link: { label: text } } ); } } ),
				el( AnnouncementRowTarget, { key: rowOwner, owner: rowOwner, value: value, onPatch: update } ),
				el( C.ToggleControl, { label: '在新标签页打开', checked: link.target === '_blank', onChange: function ( checked ) { update( { link: { target: checked ? '_blank' : '_self' } } ); } } )
			);
		} ) : el( 'p', { className: 'pc-me-help' }, '添加一条公告，填写电脑与手机文案，并选择按钮目标。' ),
		el( C.Button, { variant: 'secondary', disabled: rows.length >= ( props.field.max || 12 ), onClick: function () { var current = latest.current; current.onChange( ( current.value || [] ).concat( announcementNewItem( defaultsFor( props.field.fields || [] ), current.value || [] ) ) ); } }, '添加公告' ) );
	}


	function single( field, value, onChange, ctx ) {
		var f = field;
		switch ( f.type ) {
			case 'text':
				if ( f.ui === 'date' ) { return el( C.TextControl, { type: 'date', label: f.label, help: f.help, value: value || '', onChange: onChange } ); }

				if ( f.ui === 'inherited-text' && f.legacyTextKey ) { return el( InheritedTextField, { field: f, value: value, fallback: ctx.legacyText, onChange: onChange } ); }
				return el( C.TextControl, { label: f.label, help: f.help, value: value || '', onChange: onChange } );
			case 'textarea':
				return el( C.TextareaControl, { label: f.label, help: f.help, value: value || '', rows: 3, onChange: onChange } );
			case 'html':
				if ( f.legacyHtml === true && f.legacyHtmlKey && f.ui === 'richtext' ) { return el( LegacyHtmlField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', inherited: ctx && ctx.legacyHtmlInherited, allowReset: ! ctx || ctx.device !== 'mobile', onChange: onChange } ); }
				if ( f.ui === 'richtext' || f.ui === 'template' ) { return el( HtmlField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', onChange: onChange } ); }
				return el( C.TextareaControl, { label: f.label, help: f.help || S.htmlHelp, value: value || '', rows: 5, onChange: onChange } );
			case 'code':
				if ( f.ui === 'expanded-code' ) { return el( CodeField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', onChange: onChange } ); }
				return el( C.TextareaControl, { className: 'pc-me-code', label: f.label, help: f.help, value: value || '', rows: 10, onChange: onChange } );
			case 'url':
				if ( f.key === 'url' && f.ui === 'youtube-metadata' && f.youtubeMetadata === true && ctx && ctx.moduleType === 'youtube_list' && ctx.youtubeMode === 'reference' ) { return el( YouTubeMetadataField, { field: f, value: value, onChange: onChange, context: ctx } ); }
				if ( f.ui === 'site-link' ) { return el( SiteLinkInput, { field: f, value: value, onChange: onChange } ); }
				return el( C.TextControl, { label: f.label, help: f.help, type: 'text', placeholder: '/shop/  ·  https://…', value: value || '', onChange: onChange } );
			case 'toggle':
				return el( ToggleField, { field: f, value: value, onChange: onChange } );
			case 'select':
				if ( trustedAnnouncementField( f, ctx, 'icons' ) ) { return el( AnnouncementIcons, { field: f, value: value, onChange: onChange } ); }
				if ( trustedFulfillmentIcons( f ) ) { return el( FulfillmentIcons, { field: f, value: value, onChange: onChange } ); }
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
				if ( f.ui === 'alpha-color' ) { return el( AlphaColorField, { field: f, value: value, onChange: onChange } ); }
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
				if ( f.ui === 'menu-picker' && data.menuPicker && data.menuPicker.canRefresh && data.menuPicker.path && wp.apiFetch ) { return el( MenuPickerField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', onChange: onChange } ); }
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.menus ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'category':
				if ( f.ui === 'collection-confirm' ) { return el( ConfirmCollectionField, { field: f, value: value, scope: ctx && ctx.device || 'desktop', onChange: onChange } ); }
				if ( f.ui === 'collection' ) { return el( CollectionField, { field: f, value: value, onChange: onChange } ); }
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.categories ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'product':
				return el( RecordField, { field: Object.assign( {}, f, { resource: 'product' } ), value: value, onChange: onChange } );
			case 'record':
				if ( trustedBlogCategoryField( f, ctx ) && typeof ctx.blogOwner === 'string' && ctx.blogOwner !== '' ) { return el( BlogSingleCategoryField, { value: value, onChange: onChange, owner: ctx.blogOwner } ); }
				return el( f.resource === 'review' ? ReviewRecordField : RecordField, { field: f, value: value, onChange: onChange } );
			case 'repeater':
				if ( trustedAnnouncementField( f, ctx, 'rows' ) ) { return ctx.announcementOwner ? el( AnnouncementRowsField, { key: ctx.announcementOwner, field: f, value: value, onChange: onChange, owner: ctx.announcementOwner } ) : el( 'p', { role: 'alert', className: 'pc-me-error' }, '当前编辑器缺少稳定模块标识，暂不能安全修改公告。' ); }
				if ( trustedBlogField( f, ctx ) && typeof ctx.blogOwner === 'string' && ctx.blogOwner !== '' ) { return el( BlogReferencesField, { field: f, value: value, onChange: onChange, owner: ctx.blogOwner, scope: ctx.blogModule } ); }
				if ( f.ui === 'site-search-types' && f.siteSearchTypes === true ) { return el( SiteSearchTypesField, { field: f, value: value, onChange: onChange } ); }
				if ( f.ui === 'media-product-multiple' && f.mediaProducts === true && Array.isArray( f.fields ) && f.fields.length === 1 && f.fields[ 0 ].type === 'record' && f.fields[ 0 ].key === 'product' && f.fields[ 0 ].resource === 'product' ) { return el( YouTubeProductsField, { field: f, value: value, onChange: onChange, scope: ctx && ctx.device || 'desktop' } ); }
				if ( f.ui === 'youtube-products' && f.youtubeProducts === true && Array.isArray( f.fields ) && f.fields.length === 1 && f.fields[ 0 ].type === 'record' && f.fields[ 0 ].key === 'product' && f.fields[ 0 ].resource === 'product' ) { return el( YouTubeProductsField, { field: f, value: value, onChange: onChange, scope: ctx && ctx.device || 'desktop' } ); }
				if ( f.ui === 'topic-multiple' && f.previewSelect === true && f.regenerateIdsOnDuplicate === true ) { return el( MultipleTopicsField, { field: f, value: value, onChange: onChange, onSelectItem: ctx.onSelectItem } ); }
				if ( f.ui === 'collection-multiple' && f.previewSelect === true && f.regenerateIdsOnDuplicate === true ) { return el( MultipleCollectionsField, { field: f, value: value, onChange: onChange, onSelectItem: ctx.onSelectItem } ); }
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
						onSelectItem: f.previewSelect === true ? ctx.onSelectItem : undefined,
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

	/** Trusted O03 only: a compact multi-select over the existing repeater protocol. */

	/** Only trusted O08/O09 reference schemas enter this row-scoped record modal. */
	function blogActionIcon( name ) { var icons = window.PeptideDesignIcons; return icons && typeof icons.renderAction === 'function' ? icons.renderAction( name, { size: 16, createElement: el } ) : null; }
	function trustedBlogField( f, ctx ) {
		var type = ctx && ctx.blogModule, resource = type === 'blog_posts' ? 'post' : type === 'blog_categories' ? 'blog_category' : type === 'campaign_pages' ? 'topic' : '';
		return !! resource && ctx.blogReference === true && f.ui === 'blog-records' && f.blogRecords === true && f.blogModule === type && f.key === 'blogEntries' && Array.isArray( f.fields ) && f.fields.length === 1 && f.fields[ 0 ].type === 'record' && f.fields[ 0 ].key === 'entry' && f.fields[ 0 ].resource === resource;
	}
	function trustedBlogCategoryField( f, ctx ) { return ctx && ctx.blogModule === 'category_blog' && ctx.blogReference === true && f.type === 'record' && f.key === 'categoryBlogCategory' && f.ui === 'blog-category-one' && f.blogRecords === true && f.blogModule === 'category_blog' && f.resource === 'blog_category'; }
	function BlogSingleCategoryField( props ) {
		var field = { fields: [ { type: 'record', key: 'entry', resource: 'blog_category' } ] }, rows = [ { id: 'category-blog-binding', enabled: true, settings: { entry: props.value } } ];
		return el( BlogReferencesField, { field: field, value: rows, owner: props.owner, scope: 'category_blog', single: true, onChange: function ( next ) { props.onChange( next.length ? next[ 0 ].settings.entry : null ); } } );
	}
	function BlogReferencesField( props ) {
		var rows = Array.isArray( props.value ) ? props.value : [], resource = props.field.fields[ 0 ].resource;
		var open = useState( false ), term = useState( '' ), page = useState( 1 ), retry = useState( 0 ), draft = useState( null ), options = useState( { options: [], more: false } ), busy = useState( false ), error = useState( '' ), labels = useState( {} );
		var mounted = useRef( false ), session = useRef( null ), version = useRef( 0 ), trigger = useRef( null ), latest = useRef( null );
		var signature = JSON.stringify( rows ); latest.current = { rows: rows, signature: signature, owner: props.owner, onChange: props.onChange, scope: props.scope };
		function number( value ) { var s = String( value || '' ); return /^[1-9]\d*$/.test( s ) && Number.isSafeInteger( Number( s ) ) ? Number( s ) : null; }
		function current( expected ) { var now = latest.current; return mounted.current && expected && session.current === expected && now.owner === expected.owner && now.scope === expected.scope && now.signature === expected.signature && now.rows[ expected.index ] && now.rows[ expected.index ].id === expected.id; }
		function close( focus ) { var old = session.current; session.current = null; version.current++; open[ 1 ]( false ); error[ 1 ]( '' ); if ( focus && old && latest.current.owner === old.owner && trigger.current && trigger.current.isConnected ) { trigger.current.focus( { preventScroll: true } ); } }
		function url( params ) {
			var cfg = data.recordLookup || {}, target = new URL( cfg.url || window.ajaxurl, window.location.href );
			if ( target.origin !== window.location.origin || target.username || target.password ) { throw new Error( 'unsafe_blog_record_url' ); }
			Object.assign( params, { action: 'pc_layout_records', security: cfg.security || '', resource: resource, blog_ui: '1', blog_module: props.scope, blog_mode: 'reference' } );
			Object.keys( params ).forEach( function ( key ) { target.searchParams.set( key, params[ key ] ); } ); return target.href;
		}
		function read( params, signal ) { return fetch( url( params ), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: signal } ).then( function ( response ) { return response.json().then( function ( payload ) { if ( ! response.ok || ! payload || ! payload.success || ! payload.data || ! Array.isArray( payload.data.options ) || typeof payload.data.more !== 'boolean' ) { throw new Error( '记录读取失败，请重试。' ); } return payload.data; } ); } ); }
		useEffect( function () { mounted.current = true; close( false ); return function () { mounted.current = false; session.current = null; version.current++; }; }, [ props.owner, props.scope, signature ] );
		useEffect( function () {
			var ids = rows.map( function ( row ) { return number( row && row.settings && row.settings.entry ); } ).filter( Boolean );
			if ( ! ids.length ) { labels[ 1 ]( {} ); return; }
			var controller = new AbortController(), expected = { owner: props.owner, signature: signature, scope: props.scope };
			read( { metadata_only: '1', selected_ids: ids.join( ',' ) }, controller.signal ).then( function ( payload ) { var now = latest.current; if ( controller.signal.aborted || ! mounted.current || now.owner !== expected.owner || now.signature !== expected.signature || now.scope !== expected.scope ) { return; } var next = {}; payload.options.forEach( function ( row ) { var id = number( row.value ); if ( id && typeof row.label === 'string' ) { next[ id ] = row.label; } } ); labels[ 1 ]( next ); } ).catch( function () {} );
			return function () { controller.abort(); };
		}, [ props.owner, props.scope, signature ] );
		useEffect( function () {
			if ( ! open[ 0 ] || ! current( session.current ) ) { return; }
			var expected = session.current, token = ++version.current, controller = new AbortController(); busy[ 1 ]( true ); error[ 1 ]( '' );
			read( { term: term[ 0 ], paged: page[ 0 ] }, controller.signal ).then( function ( payload ) { if ( ! controller.signal.aborted && token === version.current && current( expected ) ) { options[ 1 ]( payload ); busy[ 1 ]( false ); } } ).catch( function ( e ) { if ( ! controller.signal.aborted && token === version.current && current( expected ) ) { error[ 1 ]( '记录读取失败，请重试。' ); busy[ 1 ]( false ); } } );
			return function () { controller.abort(); };
		}, [ open[ 0 ], term[ 0 ], page[ 0 ], retry[ 0 ], props.owner, props.scope, signature ] );
		function begin( index, event ) { var row = latest.current.rows[ index ]; if ( ! row ) { return; } trigger.current = event.currentTarget; session.current = { owner: props.owner, scope: props.scope, signature: signature, index: index, id: row.id }; draft[ 1 ]( number( row.settings && row.settings.entry ) ); term[ 1 ]( '' ); page[ 1 ]( 1 ); options[ 1 ]( { options: [], more: false } ); error[ 1 ]( '' ); open[ 1 ]( true ); }
		function commit() { var expected = session.current, id = number( draft[ 0 ] ); if ( ! id || ! current( expected ) ) { close( false ); return; } var now = latest.current, next = now.rows.slice(), prior = next[ expected.index ]; next[ expected.index ] = Object.assign( {}, prior, { settings: Object.assign( {}, prior.settings, { entry: id } ) } ); var change = now.onChange; close( true ); change( next ); }
		function move( index, amount ) { var next = rows.slice(), target = index + amount; if ( target < 0 || target >= next.length ) { return; } var row = next.splice( index, 1 )[ 0 ]; next.splice( target, 0, row ); props.onChange( next ); }
		var category = resource === 'blog_category', topic = resource === 'topic', name = category ? '选择专辑' : topic ? '选择专题' : '选择博客';
		return el( 'div', { className: 'pc-me-blog-records', style: { '--wp-components-color-accent': '#111', '--wp-admin-theme-color': '#111' } },
			rows.map( function ( row, index ) { var id = number( row && row.settings && row.settings.entry ); return el( 'div', { key: row.id, style: { padding: '10px 0', borderBottom: '1px solid #ddd' } },
				el( C.Button, { variant: 'secondary', onClick: function ( event ) { begin( index, event ); }, style: { width: '100%', justifyContent: 'flex-start', whiteSpace: 'normal', height: 'auto', minHeight: '36px' } }, id ? labels[ 0 ][ id ] || '已选记录（#' + id + '）' : name ),
				props.single ? el( C.Button, { variant: 'tertiary', onClick: function () { props.onChange( [] ); } }, '清空' ) : el( 'div', { style: { display: 'flex', gap: '4px', paddingTop: '6px', flexWrap: 'wrap' } },
					el( C.Button, { size: 'small', variant: 'tertiary', icon: blogActionIcon( 'up' ), disabled: index === 0, onClick: function () { move( index, -1 ); } }, '上移' ),
					el( C.Button, { size: 'small', variant: 'tertiary', icon: blogActionIcon( 'down' ), disabled: index === rows.length - 1, onClick: function () { move( index, 1 ); } }, '下移' ),
					el( C.Button, { size: 'small', variant: 'tertiary', icon: blogActionIcon( row.enabled ? 'hide' : 'show' ), onClick: function () { var next = rows.slice(); next[ index ] = Object.assign( {}, row, { enabled: ! row.enabled } ); props.onChange( next ); }, 'aria-pressed': ! row.enabled }, row.enabled ? '隐藏' : '显示' ),
					el( C.Button, { size: 'small', variant: 'tertiary', isDestructive: true, icon: blogActionIcon( 'remove' ), onClick: function () { props.onChange( rows.filter( function ( value, i ) { return i !== index; } ) ); } }, '删除' ) ) ); } ),
			props.single ? null : el( C.Button, { variant: 'secondary', icon: blogActionIcon( 'add' ), disabled: rows.length >= 24, onClick: function () { props.onChange( rows.concat( newItem( { entry: null } ) ) ); } }, '添加' ),
			open[ 0 ] ? el( C.Modal, { title: name, onRequestClose: function () { close( true ); }, shouldCloseOnEsc: true, style: { width: 'min(640px, calc(100vw - 32px))', marginInline: 'auto', maxHeight: 'calc(100dvh - 32px)', '--wp-components-color-accent': '#111', '--wp-admin-theme-color': '#111' } },
				el( C.TextControl, { label: category ? '博客专辑名称' : topic ? '专题名称' : '博客标题', value: term[ 0 ], maxLength: 32, onChange: function ( value ) { term[ 1 ]( value.slice( 0, 32 ) ); page[ 1 ]( 1 ); } } ),
				busy[ 0 ] ? el( 'p', { role: 'status' }, '正在读取记录…' ) : null,
				error[ 0 ] ? el( 'div', { role: 'alert' }, el( 'p', null, error[ 0 ] ), el( C.Button, { variant: 'secondary', onClick: function () { retry[ 1 ]( retry[ 0 ] + 1 ); } }, '重试' ) ) : null,
				! busy[ 0 ] && ! error[ 0 ] ? el( 'table', { style: { width: '100%', borderCollapse: 'collapse' } }, el( 'thead', null, el( 'tr', null, el( 'th', { scope: 'col', style: { textAlign: 'start', padding: '10px' } }, category ? '博客专辑名称' : topic ? '专题名称' : '博客标题' ), category || topic ? el( 'th', { scope: 'col' }, category ? '公开博客数量' : '发布状态' ) : null ) ), el( 'tbody', null,
					options[ 0 ].options.length ? options[ 0 ].options.map( function ( row ) { var id = number( row.value ); return id ? el( 'tr', { key: id }, el( 'td', { style: { padding: '10px', borderTop: '1px solid #ddd' } }, el( 'label', null, el( 'input', { type: 'radio', name: 'pc-blog-' + props.scope, value: id, checked: draft[ 0 ] === id, onChange: function () { draft[ 1 ]( id ); } } ), ' ', topic ? row.title || row.label : row.label ) ), category || topic ? el( 'td', { style: { textAlign: 'center', borderTop: '1px solid #ddd' } }, topic ? row.status || '' : Number.isSafeInteger( row.count ) ? row.count : '—' ) : null ) : null; } ) : el( 'tr', null, el( 'td', { colSpan: category || topic ? 2 : 1, style: { padding: '24px', textAlign: 'center' } }, term[ 0 ] ? '没有匹配记录，请调整关键词。' : topic ? '暂无可选专题，请先在活动专题页中添加。' : '暂无可选记录，请先在博客管理中添加。' ) ) ) ) : null,
				el( 'div', { style: { display: 'flex', gap: '8px', justifyContent: 'space-between', marginTop: '16px', flexWrap: 'wrap' } }, el( 'div', null, el( C.Button, { variant: 'tertiary', disabled: page[ 0 ] <= 1 || busy[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] - 1 ); } }, '上一页' ), el( 'span', null, '第 ' + page[ 0 ] + ' 页' ), el( C.Button, { variant: 'tertiary', disabled: ! options[ 0 ].more || busy[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] + 1 ); } }, '下一页' ) ), el( 'div', null, el( C.Button, { variant: 'secondary', onClick: function () { close( true ); } }, '取消' ), el( C.Button, { variant: 'primary', disabled: ! number( draft[ 0 ] ) || busy[ 0 ] || !! error[ 0 ], onClick: commit }, '添加' ) ) )
			) : null
		);
	}


	function SiteSearchTypesField( props ) {
		var field = props.field, rows = Array.isArray( props.value ) ? props.value : [];
		var definition = ( field.fields || [] ).find( function ( f ) { return f.type === 'select' && f.key === 'type'; } );
		var choices = definition && definition.options || {}, keys = Object.keys( choices ).filter( function ( key ) { return [ 'products', 'collections', 'topics', 'blogs', 'pages', 'promotions', 'coupons' ].indexOf( key ) > -1; } );
		var trigger = useRef( null ), menuId = useState( uid() )[ 0 ];
		var selected = keys.filter( function ( key ) { return rows.some( function ( row ) { return row && row.enabled && row.settings && row.settings.type === key; } ); } );
		function focusTrigger() { window.requestAnimationFrame( function () { if ( trigger.current && trigger.current.focus ) { trigger.current.focus(); } } ); }
		function choose( key, enabled ) {
			if ( keys.indexOf( key ) < 0 ) { return; }
			var next = [], seen = {}, ids = {}, found = false;
			rows.forEach( function ( row ) {
				var type = row && row.settings && row.settings.type;
				if ( keys.indexOf( type ) < 0 || seen[ type ] ) { return; }
				seen[ type ] = true;
				var id = typeof row.id === 'string' && /^[a-z0-9][a-z0-9-]{3,39}$/.test( row.id ) && ! ids[ row.id ] ? row.id : uid();
				ids[ id ] = true;
				if ( type === key ) { found = true; }
				next.push( { id: id, enabled: type === key ? enabled : !! row.enabled, settings: { type: type } } );
			} );
			if ( enabled && ! found && next.length < 7 ) { var id = uid(); while ( ids[ id ] ) { id = uid(); } next.push( { id: id, enabled: true, settings: { type: key } } ); }
			props.onChange( next );
		}
		return el( 'div', { className: 'pc-me-site-search-types' },
			el( 'p', { className: 'pc-me-label pc-me-label--strong', id: menuId + '-label' }, field.label ),
			el( C.Dropdown, {
				popoverProps: { placement: 'bottom-start', focusOnMount: 'firstElement' },
				renderToggle: function ( toggle ) {
					return el( 'div', { role: 'group', 'aria-labelledby': menuId + '-label', style: { display: 'flex', alignItems: 'center', gap: '4px', minHeight: '40px', padding: '4px', border: '1px solid #d6d6d6', background: '#fff', borderRadius: '4px' }, onClick: function ( event ) { if ( event.target === event.currentTarget ) { toggle.onToggle(); } } },
						el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px', flex: '1 1 auto', minWidth: 0 } }, selected.length ? selected.map( function ( key ) {
							return el( 'span', { key: key, style: { display: 'inline-flex', alignItems: 'center', gap: '4px', padding: '2px 4px', background: '#f4f4f4', border: '1px solid #e4e4e4', borderRadius: '4px', fontSize: '12px', lineHeight: '16px' } }, choices[ key ], el( 'button', { type: 'button', 'aria-label': '移除' + choices[ key ], style: { padding: 0, border: 0, background: 'transparent', color: '#111', font: 'inherit', cursor: 'pointer' }, onClick: function ( event ) { event.preventDefault(); event.stopPropagation(); choose( key, false ); focusTrigger(); } }, el( 'svg', { 'aria-hidden': true, focusable: 'false', width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', style: { display: 'block' } }, el( 'path', { d: 'M6 6l12 12M18 6 6 18' } ) ) ) );
						} ) : el( 'span', { style: { padding: '4px', fontSize: '12px', color: '#666' } }, '选择搜索类型' ) ),
						el( C.Button, { ref: trigger, variant: 'tertiary', onClick: toggle.onToggle, 'aria-expanded': toggle.isOpen, 'aria-controls': menuId, 'aria-haspopup': 'dialog', 'aria-label': '选择搜索类型', style: { minWidth: '32px', height: '32px', padding: '4px' } }, el( 'svg', { 'aria-hidden': true, focusable: 'false', width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', style: { display: 'block' } }, el( 'path', { d: 'M6 9l6 6 6-6' } ) ) )
					);
				},
				renderContent: function ( popover ) {
					return el( 'div', { id: menuId, role: 'dialog', 'aria-label': field.label, style: { width: 'min(280px, calc(100vw - 48px))', padding: '12px', background: '#fff', color: '#111' }, onKeyDown: function ( event ) { if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); popover.onClose(); focusTrigger(); } } },
						keys.map( function ( key ) { return el( C.CheckboxControl, { key: key, label: choices[ key ], checked: selected.indexOf( key ) > -1, onChange: function ( enabled ) { choose( key, !! enabled ); } } ); } )
					);
				}
			} ),
			field.help ? el( 'p', { className: 'pc-me-hint' }, field.help ) : null
		);
	}

	/** Trusted O04 nested repeater. One native modal holds a cross-page pending product selection. */
	function YouTubeProductsField( props ) {
		var field = props.field, rows = Array.isArray( props.value ) ? props.value : [], max = 20;
		var open = useState( false ), term = useState( '' ), page = useState( 1 ), retry = useState( 0 ), draft = useState( [] );
		var options = useState( { options: [], more: false } ), busy = useState( false ), error = useState( '' ), labels = useState( {} ), metaError = useState( false );
		var trigger = useRef( null ), dialog = useRef( null ), search = useRef( null ), session = useRef( null ), mounted = useRef( true ), version = useRef( 0 );
		var latest = useRef( null ), draftRef = useRef( [] ), id = useState( uid() )[ 0 ];
		latest.current = { rows: rows, scope: props.scope, onChange: props.onChange }; draftRef.current = draft[ 0 ];
		var signature = JSON.stringify( rows );
		function number( value ) { var s = String( value === null || value === undefined ? '' : value ); return /^[1-9]\d*$/.test( s ) && Number.isSafeInteger( Number( s ) ) ? Number( s ) : null; }
		function rowProduct( row ) { return row && row.settings ? number( row.settings.product ) : null; }
		function selected( value ) { var seen = {}; return value.filter( function ( row ) { var n = rowProduct( row ); if ( ! n || ! row.enabled || seen[ n ] ) { return false; } seen[ n ] = true; return true; } ).slice( 0, max ); }
		function label( product ) { return labels[ 0 ][ product ] || '商品（#' + product + '）'; }
		function urlFor( params ) {
			var cfg = data.recordLookup || {}, url = new URL( cfg.url || window.ajaxurl, window.location.href );
			if ( url.origin !== window.location.origin || url.username || url.password ) { throw new Error( 'unsafe_record_url' ); }
			Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
			url.searchParams.set( 'action', 'pc_layout_records' ); url.searchParams.set( 'security', cfg.security || '' ); url.searchParams.set( 'resource', 'product' ); return url.href;
		}
		function read( params, signal ) {
			return fetch( urlFor( params ), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: signal } ).then( function ( response ) {
				return response.json().then( function ( payload ) { if ( ! response.ok || ! payload || ! payload.success || ! payload.data || ! Array.isArray( payload.data.options ) || typeof payload.data.more !== 'boolean' ) { throw new Error( 'record_unavailable' ); } return payload.data; } );
			} );
		}
		function cache( list ) {
			labels[ 1 ]( function ( previous ) { var next = Object.assign( {}, previous ); list.forEach( function ( option ) { var n = number( option.value ); if ( n && typeof option.label === 'string' ) { next[ n ] = option.unavailable ? '记录不可用（#' + n + '）' : option.label; } } ); return next; } );
		}
		function current( expected ) { var active = session.current, now = latest.current; return mounted.current && active === expected && active && active.signature === JSON.stringify( now.rows ) && active.scope === now.scope; }
		function finish( focus ) { session.current = null; version.current++; if ( dialog.current && dialog.current.open ) { dialog.current.close(); } if ( mounted.current ) { open[ 1 ]( false ); error[ 1 ]( '' ); } if ( focus && trigger.current && trigger.current.isConnected ) { trigger.current.focus( { preventScroll: true } ); } }
		function begin() { var next = selected( latest.current.rows ).map( rowProduct ); session.current = { signature: JSON.stringify( latest.current.rows ), scope: latest.current.scope }; draft[ 1 ]( next ); term[ 1 ]( '' ); page[ 1 ]( 1 ); error[ 1 ]( '' ); options[ 1 ]( { options: [], more: false } ); open[ 1 ]( true ); }
		function commit() {
			if ( ! current( session.current ) ) { finish( true ); return; }
			var ids = {}, chosen = {}, next = [];
			draftRef.current.slice( 0, max ).forEach( function ( product ) {
				if ( ! number( product ) || chosen[ product ] ) { return; } chosen[ product ] = true;
				var prior = latest.current.rows.find( function ( row ) { return rowProduct( row ) === product; } );
				var rowId = prior && typeof prior.id === 'string' && /^[a-z0-9][a-z0-9-]{3,39}$/.test( prior.id ) && ! ids[ prior.id ] ? prior.id : uid(); while ( ids[ rowId ] ) { rowId = uid(); } ids[ rowId ] = true;
				next.push( { id: rowId, enabled: true, settings: { product: product } } );
			} );
			var onChange = latest.current.onChange; finish( true ); onChange( next );
		}
		function toggle( product, checked ) { draft[ 1 ]( function ( previous ) { if ( checked ) { return previous.indexOf( product ) === -1 && previous.length < max ? previous.concat( product ) : previous; } return previous.filter( function ( id ) { return id !== product; } ); } ); }
		function move( index, amount ) { var next = latest.current.rows.slice(), to = index + amount; if ( to < 0 || to >= next.length ) { return; } var row = next.splice( index, 1 )[ 0 ]; next.splice( to, 0, row ); props.onChange( next ); }
		function remove( index ) { props.onChange( latest.current.rows.filter( function ( row, i ) { return i !== index; } ) ); window.requestAnimationFrame( function () { trigger.current && trigger.current.focus(); } ); }
		function modalKeys( event ) {
			if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); finish( true ); return; }
			if ( event.key !== 'Tab' ) { return; }
			var nodes = Array.prototype.slice.call( event.currentTarget.querySelectorAll( 'input:not(:disabled),button:not(:disabled),a[href],[tabindex="0"]' ) ).filter( function ( node ) { return node.getClientRects().length > 0; } );
			if ( ! nodes.length ) { return; } var first = nodes[ 0 ], last = nodes[ nodes.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) { event.preventDefault(); last.focus(); }
			else if ( ! event.shiftKey && document.activeElement === last ) { event.preventDefault(); first.focus(); }
		}
		useEffect( function () { mounted.current = true; return function () { mounted.current = false; session.current = null; version.current++; }; }, [] );
		useEffect( function () { if ( open[ 0 ] && ! current( session.current ) ) { finish( false ); } }, [ signature, props.scope ] );
		useEffect( function () { if ( ! open[ 0 ] || ! dialog.current ) { return; } if ( ! dialog.current.open ) { dialog.current.showModal(); } search.current && search.current.focus(); }, [ open[ 0 ] ] );
		useEffect( function () {
			var ids = selected( rows ).map( rowProduct ); if ( ! ids.length ) { metaError[ 1 ]( false ); return; }
			var alive = true, controller = new AbortController(); metaError[ 1 ]( false );
			try { read( { metadata_only: 1, selected_ids: ids.join( ',' ) }, controller.signal ).then( function ( result ) { if ( alive ) { cache( result.options ); } } ).catch( function ( failure ) { if ( alive && failure.name !== 'AbortError' ) { metaError[ 1 ]( true ); } } ); } catch ( failure ) { metaError[ 1 ]( true ); }
			return function () { alive = false; controller.abort(); };
		}, [ signature, retry[ 0 ] ] );
		useEffect( function () {
			if ( ! open[ 0 ] ) { return; }
			var own = ++version.current, active = session.current, controller = new AbortController(); busy[ 1 ]( true ); error[ 1 ]( '' );
			var timer = setTimeout( function () {
				try { read( { term: term[ 0 ], paged: page[ 0 ], selected_ids: draftRef.current.slice( 0, max ).join( ',' ) }, controller.signal ).then( function ( result ) {
					if ( controller.signal.aborted || own !== version.current || ! current( active ) ) { return; }
					var safe = result.options.filter( function ( option ) { return option && number( option.value ) && typeof option.label === 'string'; } ); cache( safe ); options[ 1 ]( { options: safe, more: result.more } ); busy[ 1 ]( false );
				} ).catch( function ( failure ) { if ( own === version.current && current( active ) && failure.name !== 'AbortError' ) { error[ 1 ]( '商品暂时无法读取。已选ID仍保留，请重试。' ); busy[ 1 ]( false ); } } ); } catch ( failure ) { if ( current( active ) ) { error[ 1 ]( '商品暂时无法读取，请重试。' ); busy[ 1 ]( false ); } }
			}, 180 );
			return function () { clearTimeout( timer ); controller.abort(); };
		}, [ open[ 0 ], term[ 0 ], page[ 0 ], retry[ 0 ] ] );
		var activeRows = selected( rows );
		return el( 'div', { className: 'pc-me-youtube-products', 'data-record-resource': 'product' },
			el( 'p', { className: 'pc-me-label pc-me-label--strong' }, field.label ),
			el( 'button', { type: 'button', className: 'components-button is-secondary', ref: trigger, 'aria-haspopup': 'dialog', 'aria-expanded': open[ 0 ], onClick: begin }, '关联商品（' + activeRows.length + '/' + max + '）' ),
			metaError[ 0 ] ? el( 'p', { role: 'status', className: 'pc-me-help' }, '商品摘要暂时不可用，原关联已保留。', el( C.Button, { variant: 'link', onClick: function () { retry[ 1 ]( retry[ 0 ] + 1 ); } }, '重试' ) ) : null,
			el( 'div', { 'aria-label': '本视频已关联商品' }, rows.map( function ( row, index ) { var product = rowProduct( row ), name = product ? label( product ) : '无效关联（原数据保留）'; return el( 'div', { key: row.id || index, className: 'pc-me-record__summary' }, el( 'strong', { style: { minWidth: 0, overflowWrap: 'anywhere' } }, name ), el( 'div', { className: 'pc-me-record__actions' }, el( C.Button, { icon: 'arrow-up-alt2', label: '向上移动 ' + name, disabled: index === 0, onClick: function () { move( index, -1 ); } } ), el( C.Button, { icon: 'arrow-down-alt2', label: '向下移动 ' + name, disabled: index === rows.length - 1, onClick: function () { move( index, 1 ); } } ), el( C.Button, { icon: 'trash', label: '移除 ' + name, onClick: function () { remove( index ); } } ) ) ); } ) ),
			field.help ? el( 'p', { className: 'pc-me-help' }, field.help ) : null,
			open[ 0 ] ? el( 'dialog', { ref: dialog, className: 'pc-me-collection-dialog', 'aria-labelledby': id + '-title', 'aria-describedby': id + '-note', onKeyDown: modalKeys, onCancel: function ( event ) { event.preventDefault(); finish( true ); }, onClick: function ( event ) { if ( event.target === event.currentTarget ) { var r = event.currentTarget.getBoundingClientRect(); if ( event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom ) { finish( true ); } } }, style: { width: 'min(640px, calc(100vw - 32px))', maxHeight: 'min(800px, calc(100dvh - 32px))', padding: '20px', boxSizing: 'border-box', overflow: 'auto', color: '#111', background: '#fff', border: '1px solid #ccc' } },
				el( 'h3', { id: id + '-title', style: { marginTop: 0 } }, '关联商品' ),
				el( 'p', { id: id + '-note', className: 'pc-me-help' }, '可跨页选择，最多20个。确认后更新本视频；取消保留原关联。买家仅看到当前可公开商品。' ),
				el( 'label', { style: { display: 'grid', gap: '6px' } }, '搜索商品', el( 'input', { ref: search, type: 'search', value: term[ 0 ], maxLength: 100, style: { width: '100%', minWidth: 0, minHeight: '40px', fontSize: '16px' }, onChange: function ( event ) { term[ 1 ]( event.target.value ); page[ 1 ]( 1 ); } } ) ),
				el( 'p', { role: 'status' }, '已选 ' + draft[ 0 ].length + '/' + max ),
				busy[ 0 ] ? el( 'p', { role: 'status' }, '正在读取商品…' ) : null,
				error[ 0 ] ? el( 'div', null, el( 'p', { role: 'alert' }, error[ 0 ] ), el( C.Button, { variant: 'secondary', onClick: function () { retry[ 1 ]( retry[ 0 ] + 1 ); } }, '重试商品读取' ) ) : null,
				! busy[ 0 ] && ! error[ 0 ] ? el( 'div', { role: 'group', 'aria-label': '商品搜索结果（含当前已选）', style: { display: 'grid', gap: '4px' } }, options[ 0 ].options.length ? options[ 0 ].options.map( function ( option ) { var product = number( option.value ), checked = draft[ 0 ].indexOf( product ) !== -1; return el( 'label', { key: product, className: 'pc-me-record__option', style: { display: 'flex', gap: '8px', alignItems: 'start', padding: '8px', overflowWrap: 'anywhere' } }, el( 'input', { type: 'checkbox', style: { accentColor: '#111', marginTop: '2px' }, checked: checked, disabled: ! checked && ( draft[ 0 ].length >= max || option.unavailable ), 'aria-label': '选择商品 ' + option.label, onChange: function ( event ) { toggle( product, event.target.checked ); } } ), el( 'span', null, option.unavailable ? '记录不可用（#' + product + '）' : option.label ) ); } ) : el( 'p', null, '没有匹配商品，请调整搜索。' ) ) : null,
				el( 'div', { className: 'pc-me-record__pagination', style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', marginTop: '12px' } }, el( C.Button, { variant: 'tertiary', disabled: page[ 0 ] <= 1 || busy[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] - 1 ); } }, '上一页商品' ), el( 'span', null, '第 ' + page[ 0 ] + ' 页' ), el( C.Button, { variant: 'tertiary', disabled: ! options[ 0 ].more || busy[ 0 ] || !! error[ 0 ], onClick: function () { page[ 1 ]( page[ 0 ] + 1 ); } }, '下一页商品' ) ),
				el( 'div', { style: { display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '16px' } }, el( C.Button, { variant: 'secondary', onClick: function () { finish( true ); } }, '取消' ), el( C.Button, { variant: 'primary', onClick: commit }, '确认关联' ) )
			) : null
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
				var fieldCtx = f.ui === 'inherited-text' && f.legacyTextKey ? Object.assign( {}, ctx, { legacyText: effective( values[ f.legacyTextKey ], ctx.device ) } ) : ctx;
				if ( f.legacyHtml === true && f.legacyHtmlKey ) { fieldCtx = Object.assign( {}, ctx, { legacyHtmlInherited: values[ f.key ] === null || values[ f.key ] === undefined } ); }
				return el(
					'div',
					{ key: f.key, className: 'pc-me-field pc-me-field--' + f.type + ( overridden ? ' is-overridden' : '' ) },
					control( f, fieldValue( f, values, ctx.device ), function ( v ) {
						setValue( f.key, v );
					}, fieldCtx ),
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
			var optionTitle = fields.find( function ( field ) { return field.key === key && field.type === 'select' && field.ui === 'item-title-option' && field.options && typeof field.options === 'object'; } );
			if ( optionTitle && Object.prototype.hasOwnProperty.call( optionTitle.options, effective( values[ key ], device ) ) ) { title = plainText( optionTitle.options[ effective( values[ key ], device ) ] ); return !! title; }

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
		// Only an explicitly opted-in nested repeater notifies the existing preview selection callback.
		if ( controlled && typeof props.onNestedSelect === 'function' && open ) {
			items.some( function ( item ) {
				var owns = ( props.fields || [] ).some( function ( field ) { return field.previewSelect === true && Array.isArray( item.settings && item.settings[ field.key ] ) && item.settings[ field.key ].some( function ( child ) { return child.id === open; } ); } );
				if ( owns ) { open = item.id; }
				return owns;
			} );
		}
		function setOpen( id ) {
			if ( controlled ) { props.onOpen( id ); } else { openState[ 1 ]( id ); }
			if ( typeof props.onSelectItem === 'function' ) { props.onSelectItem( id ); }
		}

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
			// Only trusted field definitions can opt a selected nested repeater into fresh copy IDs.
			( props.fields || [] ).forEach( function ( field ) {
				if ( field.type !== 'repeater' || field.previewSelect !== true || field.regenerateIdsOnDuplicate !== true || ! copy.settings || ! Array.isArray( copy.settings[ field.key ] ) ) { return; }
				copy.settings[ field.key ].forEach( function ( child ) {
					if ( child && typeof child === 'object' && ! Array.isArray( child ) ) { child.id = uid(); }
				} );
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
			var item = newItem( withDefaults( props.defaults, props.editorDefaults ), props.nestedDefaults );
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
							itemFields( props.displayFields || props.fields, props.panels, values, function ( key, v ) {
								var settings = Object.assign( {}, values );
								settings[ key ] = v;
								update( index, Object.assign( {}, item, { settings: settings } ) );
							}, Object.assign( {}, props.metadataContext || {}, { device: props.device, itemEnabled: ! Object.prototype.hasOwnProperty.call( item, 'enabled' ) || !! item.enabled, onSelectItem: typeof props.onNestedSelect === 'function' ? function ( id ) { props.onNestedSelect( id === null ? item.id : id ); } : undefined } ) )
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
		if ( [ 'blog_posts', 'blog_categories', 'category_blog', 'campaign_pages' ].indexOf( def.type ) > -1 ) { ctx.blogModule = def.type; ctx.blogReference = props.settings && props.settings[ def.type === 'campaign_pages' ? 'campaignPagesMode' : def.type === 'category_blog' ? 'categoryBlogMode' : 'blogMode' ] === 'reference'; ctx.blogOwner = typeof props.blogOwner === 'string' && props.blogOwner !== '' ? JSON.stringify( [ props.blogOwner, ctx.device ] ) : ''; }
		if ( def.type === 'announcement' ) { ctx.moduleType = 'announcement'; ctx.announcementReference = props.settings && props.settings.announcementStyle === 'reference'; ctx.announcementOwner = typeof props.blogOwner === 'string' && props.blogOwner ? JSON.stringify( [ 'announcement', props.blogOwner, ctx.device, 'reference' ] ) : ''; }
		var definitions = def.settings || [], groups = data.groups || {}, assigned = {};
		var descriptors = props.panels || def.panels || [];
		if ( ! Array.isArray( descriptors ) ) {
			descriptors = Object.keys( descriptors ).map( function ( key ) { return Object.assign( { id: key }, descriptors[ key ] ); } );
		}
		var sections = descriptors.map( function ( panel ) {
			var keys = panel.fields;
			var fields = Array.isArray( keys ) ? keys.map( function ( key ) { return definitions.find( function ( field ) { return field.key === ( typeof key === 'string' ? key : key.key ); } ); } ).filter( Boolean ) : definitions.filter( function ( field ) { return ( field.panel || field.group || 'content' ) === panel.id; } );
			fields = fields.filter( function ( field ) { if ( assigned[ field.key ] ) { return false; } assigned[ field.key ] = true; return true; } );
			return { id: panel.id, label: panel.label || panel.title || groups[ panel.id ] || panel.id, initialOpen: panel.initialOpen !== undefined ? panel.initialOpen : !! panel.open, fields: fields, ui: panel.ui, plainNumbers: panel.plainNumbers === true };
		} );
		Object.keys( groups ).forEach( function ( group ) {
			var fields = definitions.filter( function ( field ) { return ! assigned[ field.key ] && ( field.group || 'content' ) === group; } );
			if ( fields.length ) { sections.push( { id: 'fallback-' + group, label: groups[ group ], initialOpen: group === 'content', fields: fields } ); fields.forEach( function ( field ) { assigned[ field.key ] = true; } ); }
		} );
		var remaining = definitions.filter( function ( field ) { return ! assigned[ field.key ]; } );
		if ( remaining.length ) { sections.push( { id: 'fallback-other', label: '其他设置', initialOpen: false, fields: remaining } ); }
		var panels = sections
			.map( function ( section ) {
				var fields = section.fields.filter( function ( field ) { return ! ( ctx.announcementReference && [ 'title', 'description', 'mode', 'bindings', 'autoplay', 'intervalMs' ].indexOf( field.key ) > -1 ) && visible( field, props.settings || {}, ctx ); } );
				if ( ! fields.length ) {
					return null;
				}
				return el(
					C.PanelBody,
					{ key: section.id, className: 'pc-me-panel', title: section.label, initialOpen: section.initialOpen, style: ctx.blogModule ? { '--wp-components-color-accent': '#111', '--wp-admin-theme-color': '#111' } : undefined },
					section.ui === 'boxmodel' && fields.length === 4 && ( fields.every( function ( field ) { return field.type === 'number' && field.device; } ) || section.plainNumbers === true && fields.every( function ( field ) { return field.type === 'number' && ! field.device; } ) )
						? el( 'div', { className: 'pc-me-boxmodel', role: 'group', 'aria-label': section.label }, fieldList( fields, props.settings, props.setSetting, ctx ), el( 'span', { className: 'pc-me-boxmodel__unit', 'aria-hidden': true }, 'px' ) )
						: fieldList( fields, props.settings, props.setSetting, ctx )
				);
			} )
			.filter( Boolean );

		if ( def.items && ! ctx.announcementReference && visible( def.items, props.settings || {}, ctx ) ) {
			// Only trusted parent-mode schemas filter the visible inputs; retain full fields for copy IDs.
			var itemDisplayFields, parentMode = def.items.parentMode;
			if ( parentMode && typeof parentMode.setting === 'string' && parentMode.fields && typeof parentMode.fields === 'object' ) {
				var parentValue = ( props.settings || {} )[ parentMode.setting ];
				var visibleKeys = Object.prototype.hasOwnProperty.call( parentMode.fields, parentValue ) ? parentMode.fields[ parentValue ] : parentMode.fields[ parentMode.fallback ];
				if ( Array.isArray( visibleKeys ) ) { itemDisplayFields = def.items.fields.filter( function ( field ) { return visibleKeys.indexOf( field.key ) !== -1; } ); }
			}
			panels.splice(
				1,
				0,
				el(
					C.PanelBody,
					{ key: 'items', className: 'pc-me-panel', title: def.items.label + ' (' + ( props.items || [] ).length + ')', initialOpen: true },
					el( ItemsEditor, {
						fields: def.items.fields,
						displayFields: itemDisplayFields,
						panels: def.items.panels,
						items: props.items || [],
						defaults: def.items.defaults,
						editorDefaults: def.items.editorDefaults,
						nestedDefaults: def.items.nestedDefaults,
						min: def.items.min || 0,
						max: def.items.max || 20,
						titleField: def.items.title_field,
						device: props.device,
						onChange: props.setItems,
						openId: props.openItem,
						onOpen: props.onOpenItem,
						onNestedSelect: def.items.fields.some( function ( field ) { return field.type === 'repeater' && field.previewSelect === true; } ) ? props.onOpenItem : undefined,
						metadataContext: def.type === 'youtube_list' && props.metadataMode === 'reference' ? {
							moduleType: def.type, youtubeMode: 'reference', metadataRows: props.items || [],
							metadataDocumentId: props.metadataDocumentId, onPreviewRefresh: props.onPreviewRefresh,
						} : undefined,
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
		if ( [ 'blog_posts', 'blog_categories', 'campaign_pages' ].indexOf( def.type ) > -1 && init.settings[ def.type === 'campaign_pages' ? 'campaignPagesMode' : 'blogMode' ] === 'reference' && def.blogFactoryRows === 3 ) {
			var blogField = ( def.settings || [] ).find( function ( f ) { return trustedBlogField( f, { blogModule: def.type, blogReference: true } ); } );
			if ( blogField ) { init.settings.blogEntries = []; for ( var blogRow = 0; blogRow < 3; blogRow++ ) { init.settings.blogEntries.push( newItem( defaultsFor( blogField.fields ) ) ); } }
		}
		if ( def === ( data.modules || {} )[ 'vialcove/announcement' ] && def.type === 'announcement' && init.settings.announcementStyle === 'reference' && def.announcementFactoryRows === 2 ) {
			var announcementField = ( def.settings || [] ).find( function ( field ) { return trustedAnnouncementField( field, { moduleType: 'announcement', announcementReference: true }, 'rows' ); } );
			if ( announcementField ) { init.settings.announcementRows = []; for ( var announcementRow = 0; announcementRow < 2; announcementRow++ ) { init.settings.announcementRows.push( announcementNewItem( defaultsFor( announcementField.fields ), init.settings.announcementRows ) ); } }
		}
		if ( def.items && ! ( def.type === 'announcement' && init.settings.announcementStyle === 'reference' ) ) {
			for ( var i = 0; i < ( def.items.default_count || 0 ); i++ ) {
				var row = newItem( withDefaults( def.items.defaults, def.items.editorDefaults ), def.items.nestedDefaults );
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
