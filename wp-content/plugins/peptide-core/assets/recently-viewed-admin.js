/* Management only. Browsing and customer presentation are owned by the storefront adapter. */
( function ( wp, boot ) {
	'use strict';
	var root = document.getElementById( 'pc-recently-viewed' );
	if ( ! root || ! boot ) return;
	var fields = {}, nodes = {}, state = null, busy = false, dirty = false, baseline = '';
	function el( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) n.className = cls; if ( text !== undefined ) n.textContent = String( text ); return n; }
	function btn( label, action, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); b.type = 'button'; b.dataset.action = action; return b; }
	function section( parent, title ) { var n = el( 'section', 'pc-rva-section' ); n.appendChild( el( 'h2', '', title ) ); parent.appendChild( n ); return n; }
	function field( parent, key, label, type, help, max ) {
		var w = el( 'div', 'pc-rva-field' ), input = el( 'input' ); input.type = type; input.id = 'pc-rva-' + key; input.name = key;
		if ( max ) { input.min = '1'; input.max = String( max ); input.step = '1'; }
		var l = el( 'label', '', label ); l.htmlFor = input.id; w.append( l, input );
		if ( help ) { var hint = el( 'p', 'pc-rva-hint', help ); hint.id = input.id + '-hint'; input.setAttribute( 'aria-describedby', hint.id ); w.appendChild( hint ); }
		parent.appendChild( w ); fields[key] = input; return input;
	}
	function toggle( parent, key, label, help ) { var w = el( 'div', 'pc-rva-toggle' ), l = el( 'label' ), input = el( 'input' ); input.type = 'checkbox'; input.name = key; input.id = 'pc-rva-' + key; l.append( input, el( 'span', '', label ) ); w.appendChild( l ); if ( help ) w.appendChild( el( 'p', 'pc-rva-hint', help ) ); parent.appendChild( w ); fields[key] = input; }
	function note( text, error ) { nodes.message.textContent = text || ''; nodes.message.className = 'pc-rva-message' + ( error ? ' is-error' : '' ); nodes.message.setAttribute( 'role', error ? 'alert' : 'status' ); }
	function request( path, method, data ) { if ( ! wp || ! wp.apiFetch ) return Promise.reject( { message:'后台请求组件不可用，请刷新。' } ); return wp.apiFetch( { path:boot.endpoint + path, method:method || 'GET', headers:{ 'X-WP-Nonce':boot.nonce }, data:data } ); }
	function setBusy( value ) { busy = value; root.setAttribute( 'aria-busy', String( value ) ); root.querySelectorAll( 'button,input' ).forEach( function ( n ) { n.disabled = value || ! state; } ); if ( ! value && ! state ) nodes.reload.disabled = false; }
	function collect() { return { version:1, enabled:fields.enabled.checked, title:fields.title.value, maxVisible:Number( fields.maxVisible.value ), maxStored:Number( fields.maxStored.value ), retentionDays:Number( fields.retentionDays.value ), excludeCartProducts:fields.excludeCartProducts.checked }; }
	function configShape( c ) { return !! c && c.version === 1 && typeof c.enabled === 'boolean' && typeof c.title === 'string' && typeof c.excludeCartProducts === 'boolean' && [ 'maxVisible','maxStored','retentionDays' ].every( function ( k ) { return Number.isSafeInteger( c[k] ) && c[k] >= 1 && c[k] <= ( { maxVisible:2, maxStored:20, retentionDays:30 } )[k]; } ); }
	function fill( c ) { fields.enabled.checked = c.enabled; fields.title.value = c.title; fields.excludeCartProducts.checked = c.excludeCartProducts; [ 'maxVisible','maxStored','retentionDays' ].forEach( function ( k ) { fields[k].value = String( c[k] ); } ); }
	function clearInvalid() { root.querySelectorAll( '[aria-invalid]' ).forEach( function ( n ) { n.removeAttribute( 'aria-invalid' ); } ); }
	function updateDirty() { dirty = JSON.stringify( collect() ) !== baseline; nodes.status.textContent = dirty ? '当前编辑内容尚未保存' : '当前草稿已与后台一致'; }
	function accept( d ) {
		if ( ! d || ! Number.isSafeInteger( d.revision ) || d.revision < 0 || ! d.draft || ! d.published || ! Number.isSafeInteger( d.published.revision ) || ! configShape( d.draft.config ) || ! configShape( d.published.config ) || ! configShape( d.defaults ) ) throw { message:'服务器配置不完整，当前输入已保留。' };
		state = d; fill( d.draft.config ); baseline = JSON.stringify( collect() ); dirty = false; updateDirty(); nodes.allowReload.checked = false;
		nodes.published.textContent = '客户配置：' + ( d.published.config.enabled ? '已启用' : '已关闭' ) + ' · 修订 ' + d.published.revision;
		nodes.saved.textContent = '草稿保存：' + ( d.draft.updatedAt || '尚未保存' ) + '；应用：' + ( d.published.updatedAt || '尚未应用自定义配置，使用默认值' );
	}
	function fail( error ) { var message = error && error.message ? String( error.message ) : '操作失败，当前输入已保留。'; if ( error && error.data && error.data.status === 409 ) message = '其他窗口已更新配置。当前输入已保留；请先导出草稿，再重新读取处理差异。'; note( message, true ); var map = error && error.data && error.data.fields, first; if ( map ) Object.keys( map ).forEach( function ( k ) { if ( fields[k] ) { fields[k].setAttribute( 'aria-invalid', 'true' ); first = first || fields[k]; } } ); if ( first ) first.focus(); }
	function run( task, text ) { clearInvalid(); note( text ); setBusy( true ); return Promise.resolve().then( task ).catch( fail ).finally( function () { setBusy( false ); } ); }
	function load() { if ( dirty && ! nodes.allowReload.checked ) { note( '请先导出未保存草稿，再允许服务器草稿替换当前编辑内容。', true ); nodes.allowReload.focus(); return; } run( function () { return request( '/admin' ).then( function ( d ) { accept( d ); note( '' ); } ); }, '正在读取最近浏览配置…' ); }
	function validate( c ) {
		c.title = c.title.normalize( 'NFC' ).replace( /[\s\p{Z}]+/gu, ' ' ).trim();
		if ( Array.from( c.title ).length > 100 || /[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test( c.title ) || /<\s*(?:\/?\s*[a-z][^>]*>|!--|!doctype\b|\?)/i.test( c.title ) ) throw { message:'标题须为 100 字符以内的纯文本。', data:{ fields:{ title:'invalid' } } };
		[ 'maxVisible','maxStored','retentionDays' ].forEach( function ( key ) { var max = ( { maxVisible:2, maxStored:20, retentionDays:30 } )[key]; if ( ! Number.isSafeInteger( c[key] ) || c[key] < 1 || c[key] > max ) { var fs = {}; fs[key] = 'invalid_range'; throw { message:'请检查展示数量、保存数量与保留天数的范围。', data:{ fields:fs } }; } } ); return c;
	}
	function save( publish ) { var c; try { c = validate( collect() ); } catch ( e ) { fail( e ); return; } run( function () { return request( publish ? '/publish' : '/admin', publish ? 'POST' : 'PUT', { config:c, expectedRevision:state.revision } ).then( function ( d ) { accept( d ); note( publish ? '已保存并应用。客户页仍只展示其浏览器内符合条件的实际商品。' : '草稿已保存，客户配置保持原样。' ); } ); }, publish ? '正在保存并应用…' : '正在保存草稿…' ); }
	function backup() { var url = URL.createObjectURL( new Blob( [ JSON.stringify( collect(), null, 2 ) + '\n' ], { type:'application/json;charset=utf-8' } ) ), a = el( 'a' ); a.href = url; a.download = 'recently-viewed-editing-draft.json'; document.body.appendChild( a ); a.click(); a.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 ); note( '已导出当前编辑草稿，可能包含尚未保存的修改。' ); }
	function build() {
		root.replaceChildren(); nodes.status = el( 'p', 'pc-rva-status' ); nodes.status.setAttribute( 'aria-live', 'polite' ); nodes.message = el( 'p', 'pc-rva-message' ); nodes.message.setAttribute( 'aria-live', 'polite' );
		var grid = el( 'div', 'pc-rva-grid' ), main = el( 'div', 'pc-rva-main' ), side = el( 'aside', 'pc-rva-side' );
		var display = section( main, '客户展示' ); toggle( display, 'enabled', '启用最近浏览', '仅在客户实际浏览商品后展示，无浏览记录时整块隐藏。关闭后停止记录和展示，客户仍可清除已有浏览记录。' );
		field( display, 'title', '客户看到的标题', 'text', '纯文本，最多 100 个字符。' ).maxLength = 200;
		field( display, 'maxVisible', '最多展示商品数', 'number', '最多 2 个。不足时按实际数量展示，不填充推荐商品。', 2 );
		toggle( display, 'excludeCartProducts', '排除购物车中已有商品', '按主商品排除，包括同一商品的其他规格。' );
		var storage = section( main, '浏览器保存' ); field( storage, 'maxStored', '最多保存商品数', 'number', '最多 20 个主商品。同一商品重复浏览或切换规格只保留一条。', 20 ); field( storage, 'retentionDays', '浏览记录保留天数', 'number', '1 至 30 天。到期后自动不再展示，缩短此值会即时过滤过期记录。', 30 );
		var privacy = section( main, '记录与隐私' ); privacy.appendChild( el( 'p', '', '最近浏览保存在当前浏览器签名 Cookie 中，不存入客户资料，不关联姓名、邮箱、IP 或订单，不影响购物车内容。' ) ); privacy.appendChild( el( 'p', '', '商品弹窗只有成功加载后才由前端记录；悬停、预加载和卡片曝光不算浏览。价格、商品公开状态和可购规格每次从服务器重新读取。' ) ); privacy.appendChild( el( 'p', 'pc-rva-hint', '本应用默认启用。当前配置只管理应用行为；客户页组件和 View options 按钮由前端接入。这里不会生成或浏览任何客户历史记录。' ) );
		var saving = section( side, '保存与应用' ); nodes.published = el( 'p', 'pc-rva-published' ); nodes.saved = el( 'p', 'pc-rva-hint' ); saving.append( nodes.published, nodes.saved, btn( '保存并应用', 'publish', true ), btn( '仅保存草稿', 'save' ) ); saving.appendChild( el( 'p', 'pc-rva-hint', '仅保存草稿不影响客户配置。应用新限制不会修改商品、订单或购物车。' ) );
		var actions = el( 'div', 'pc-rva-actions' ); actions.append( btn( '导出当前草稿 JSON', 'backup' ), btn( '恢复默认到草稿', 'defaults' ) ); saving.appendChild( actions );
		var label = el( 'label', 'pc-rva-check' ); nodes.allowReload = el( 'input' ); nodes.allowReload.type = 'checkbox'; label.append( nodes.allowReload, el( 'span', '', '允许服务器草稿替换当前编辑内容' ) ); nodes.reload = btn( '重新读取配置', 'load' ); saving.append( label, nodes.reload );
		grid.append( main, side ); root.append( nodes.status, nodes.message, grid );
		root.addEventListener( 'click', function ( e ) { var b = e.target.closest( 'button[data-action]' ); if ( ! b || ! root.contains( b ) || b.disabled || busy ) return; var action = b.dataset.action; if ( action === 'load' ) { load(); return; } if ( ! state ) return; if ( action === 'save' || action === 'publish' ) save( action === 'publish' ); else if ( action === 'backup' ) backup(); else if ( action === 'defaults' ) { fill( state.defaults ); updateDirty(); note( '默认值已回填当前编辑草稿，保存或应用前不会改变后台配置。' ); } } );
		[ 'input','change' ].forEach( function ( kind ) { root.addEventListener( kind, function ( e ) { if ( state && e.target.name ) updateDirty(); } ); } );
		window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
	}
	build(); load();
} )( window.wp, window.pcRecentlyViewedAdmin );
