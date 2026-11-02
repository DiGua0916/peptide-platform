/* Merchant configuration only; this script never submits or changes a cart. */
( function ( wp, boot ) {
	'use strict';
	var root = document.getElementById( 'pc-cart-feedback' );
	if ( ! root || ! boot ) return;
	var fields = {}, nodes = {}, state = null, defaults = null, busy = false, dirty = false, baseline = '';
	function el( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) n.className = cls; if ( text !== undefined ) n.textContent = String( text ); return n; }
	function button( text, action, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), text ); b.type = 'button'; b.dataset.action = action; return b; }
	function section( parent, title ) { var s = el( 'section', 'pc-cfa-section' ); s.appendChild( el( 'h2', '', title ) ); parent.appendChild( s ); return s; }
	function field( parent, key, label, type, help ) {
		var wrap = el( 'div', 'pc-cfa-field' ), l = el( 'label', '', label ), input = el( 'input' ); input.id = 'pc-cfa-' + key; input.name = key; input.type = type; l.htmlFor = input.id; wrap.append( l, input );
		var hint = el( 'p', 'pc-cfa-hint', help ); hint.id = input.id + '-hint'; input.setAttribute( 'aria-describedby', hint.id ); wrap.appendChild( hint ); parent.appendChild( wrap ); fields[key] = input; return input;
	}
	function toggle( parent, key, label, help ) { var wrap = el( 'div', 'pc-cfa-toggle' ), l = el( 'label' ), input = el( 'input' ); input.type = 'checkbox'; input.id = 'pc-cfa-' + key; input.name = key; l.append( input, el( 'span', '', label ) ); wrap.append( l, el( 'p', 'pc-cfa-hint', help ) ); parent.appendChild( wrap ); fields[key] = input; }
	function note( text, error ) { nodes.message.textContent = text || ''; nodes.message.className = 'pc-cfa-message' + ( error ? ' is-error' : '' ); nodes.message.setAttribute( 'role', error ? 'alert' : 'status' ); }
	function request( suffix, method, data ) { if ( ! wp || ! wp.apiFetch ) return Promise.reject( { message:'后台请求组件不可用，请刷新页面。' } ); return wp.apiFetch( { path:boot.endpoint + suffix, method:method || 'GET', headers:{ 'X-WP-Nonce':boot.nonce }, data:data } ); }
	function setBusy( value ) { busy = value; root.setAttribute( 'aria-busy', value ? 'true' : 'false' ); root.querySelectorAll( 'button,input' ).forEach( function ( n ) { n.disabled = value || ! state; } ); if ( ! value && ! state ) nodes.reload.disabled = false; }
	function build() {
		root.replaceChildren(); nodes.status = el( 'p', 'pc-cfa-status' ); nodes.status.setAttribute( 'aria-live', 'polite' ); nodes.message = el( 'p', 'pc-cfa-message' ); nodes.message.setAttribute( 'aria-live', 'polite' );
		var grid = el( 'div', 'pc-cfa-grid' ), main = el( 'div', 'pc-cfa-main' ), side = el( 'aside', 'pc-cfa-side' );
		var settings = section( main, '反馈设置' ); toggle( settings, 'enabled', '启用加购反馈', '默认开启。仅配置真实加购过程的状态反馈，不会默认加购或跳转到购物车。' );
		var duration = field( settings, 'successDurationMs', '成功提示时长（毫秒）', 'number', '800 至 2200 毫秒；仅在 WooCommerce 确认本次加购成功后使用。' ); duration.min = '800'; duration.max = '2200'; duration.step = '1';
		toggle( settings, 'bagPulseEnabled', '购物袋数量更新时轻微强调', '客户使用减少动态效果时，前端保留静态状态和正确数量，不播放强调动画。' );
		toggle( settings, 'burstEnabled', '加购成功时播放小烟花', '仅在 WooCommerce 确认本次加购成功后播放；客户使用减少动态效果时保持静态反馈。' );
		var burstDuration = field( settings, 'burstDurationMs', '小烟花时长（毫秒）', 'number', '600 至 1000 毫秒；关闭小烟花时保留此设置。' ); burstDuration.min = '600'; burstDuration.max = '1000'; burstDuration.step = '1';
		field( settings, 'pendingText', '处理中提示', 'text', '1 至 60 个字符，使用纯文本。' ).maxLength = 120;
		field( settings, 'successText', '成功提示', 'text', '1 至 60 个字符，使用纯文本。' ).maxLength = 120;
		var safety = section( main, '固定行为' ), list = el( 'ul', 'pc-cfa-rules' );
		[ '尊重减少动态效果偏好；成功后保持当前弹窗、规格和滚动位置。', '失败或超时必须显示错误并恢复按钮，运营设置不能隐藏错误。', '前端围绕既有 WooCommerce 请求接入，不得再发一次加购请求；超时不能自动重试写请求。', '购物袋数量统一使用 WooCommerce items_count。修改数量、删除商品、读取购物车和过期响应不能当作本次加购成功。' ].forEach( function ( t ) { list.appendChild( el( 'li', '', t ) ); } ); safety.append( list, el( 'p', 'pc-cfa-hint', '此页只管理配置。客户页按钮状态、动画及请求关联由前端按既有 WooCommerce 结果接入。' ) );
		var save = section( side, '保存与应用' ); nodes.published = el( 'p', 'pc-cfa-published' ); nodes.saved = el( 'p', 'pc-cfa-hint' ); save.append( nodes.published, nodes.saved, button( '保存并应用', 'publish', true ), button( '仅保存草稿', 'save' ), el( 'p', 'pc-cfa-hint', '草稿不会进入公开配置。保存并应用后，前端下一次读取时使用新配置。' ) );
		var actions = el( 'div', 'pc-cfa-actions' ); actions.append( button( '导出当前草稿 JSON', 'backup' ), button( '恢复默认到草稿', 'defaults' ) ); save.appendChild( actions );
		var allow = el( 'label', 'pc-cfa-check' ); nodes.allowReload = el( 'input' ); nodes.allowReload.type = 'checkbox'; allow.append( nodes.allowReload, el( 'span', '', '允许服务器草稿替换当前编辑内容' ) ); nodes.reload = button( '重新读取配置', 'load' ); save.append( allow, nodes.reload, el( 'p', 'pc-cfa-hint', '多人编辑冲突时先导出草稿，再重新读取。请求失败会保留当前输入。' ) );
		grid.append( main, side ); root.append( nodes.status, nodes.message, grid );
		root.addEventListener( 'click', onClick ); [ 'input', 'change' ].forEach( function ( kind ) { root.addEventListener( kind, function ( event ) { if ( state && event.target.name ) updateDirty(); } ); } );
		window.addEventListener( 'beforeunload', function ( event ) { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } } );
	}
	function collect() { return { version:1, enabled:fields.enabled.checked, successDurationMs:Number( fields.successDurationMs.value ), bagPulseEnabled:fields.bagPulseEnabled.checked, burstEnabled:fields.burstEnabled.checked, burstDurationMs:Number( fields.burstDurationMs.value ), successText:fields.successText.value, pendingText:fields.pendingText.value }; }
	function configShape( c ) { return c && c.version === 1 && typeof c.enabled === 'boolean' && Number.isSafeInteger( c.successDurationMs ) && c.successDurationMs >= 800 && c.successDurationMs <= 2200 && typeof c.bagPulseEnabled === 'boolean' && typeof c.burstEnabled === 'boolean' && Number.isSafeInteger( c.burstDurationMs ) && c.burstDurationMs >= 600 && c.burstDurationMs <= 1000 && typeof c.successText === 'string' && typeof c.pendingText === 'string'; }
	function dtoShape( d ) { return d && Number.isSafeInteger( d.revision ) && d.revision >= 0 && d.draft && configShape( d.draft.config ) && d.published && configShape( d.published.config ) && Number.isSafeInteger( d.published.revision ) && d.published.revision >= 0 && d.published.revision <= d.revision && configShape( d.defaults ); }
	function validate( c ) {
		if ( fields.successDurationMs.value === '' || ! Number.isSafeInteger( c.successDurationMs ) || c.successDurationMs < 800 || c.successDurationMs > 2200 ) throw { message:'成功提示时长须为 800 至 2200 毫秒的整数。', data:{ fields:{ successDurationMs:'out_of_range' } } };
		if ( fields.burstDurationMs.value === '' || ! Number.isSafeInteger( c.burstDurationMs ) || c.burstDurationMs < 600 || c.burstDurationMs > 1000 ) throw { message:'小烟花时长须为 600 至 1000 毫秒的整数。', data:{ fields:{ burstDurationMs:'out_of_range' } } };
		[ 'successText', 'pendingText' ].forEach( function ( key ) { var value = c[key].normalize( 'NFC' ).replace( /[\s\p{Z}]+/gu, ' ' ).trim(); if ( Array.from( value ).length < 1 || Array.from( value ).length > 60 || /[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test( value ) || /<\s*(?:\/?\s*[a-z][^>]*>|!--|!doctype\b|\?)/i.test( value ) ) { var invalid = {}; invalid[key] = 'invalid_format'; throw { message:'提示文案须为 1 至 60 个字符的纯文本。', data:{ fields:invalid } }; } c[key] = value; } ); return c;
	}
	function fill( c ) { fields.enabled.checked = c.enabled; fields.bagPulseEnabled.checked = c.bagPulseEnabled; fields.burstEnabled.checked = c.burstEnabled; fields.successDurationMs.value = String( c.successDurationMs ); fields.burstDurationMs.value = String( c.burstDurationMs ); fields.successText.value = c.successText; fields.pendingText.value = c.pendingText; clearInvalid(); }
	function updateDirty() { dirty = JSON.stringify( collect() ) !== baseline; nodes.status.textContent = dirty ? '当前编辑内容尚未保存' : '当前草稿已与后台一致'; }
	function clearInvalid() { root.querySelectorAll( '[aria-invalid]' ).forEach( function ( n ) { n.removeAttribute( 'aria-invalid' ); } ); }
	function accept( data ) {
		if ( ! dtoShape( data ) ) throw { message:'服务器返回的配置不完整，当前输入已保留。' };
		state = data; defaults = JSON.parse( JSON.stringify( data.defaults ) ); fill( data.draft.config ); baseline = JSON.stringify( collect() ); dirty = false; updateDirty(); nodes.allowReload.checked = false;
		nodes.published.textContent = '客户配置：' + ( data.published.config.enabled ? '已启用' : '已关闭' ) + ' · 修订 ' + data.published.revision;
		nodes.saved.textContent = '草稿保存：' + ( data.draft.updatedAt || '尚未保存' ) + '；应用：' + ( data.published.updatedAt || '使用默认配置' );
	}
	function failed( error ) { var message = error && error.message ? String( error.message ) : '操作失败，请重试。当前输入已保留。'; if ( error && error.data && error.data.status === 409 ) message = '其他窗口已更新配置。当前输入已保留，请先导出草稿，再重新读取后处理差异。'; note( message, true ); var errors = error && error.data && error.data.fields, first; if ( errors ) Object.keys( errors ).forEach( function ( path ) { var key = path.replace( /^config\./, '' ).split( '.' )[0]; if ( fields[key] ) { fields[key].setAttribute( 'aria-invalid', 'true' ); first = first || fields[key]; } } ); if ( first ) first.focus(); }
	function run( task, message ) { clearInvalid(); note( message ); setBusy( true ); return Promise.resolve().then( task ).catch( failed ).finally( function () { setBusy( false ); } ); }
	function load( initial ) { if ( dirty && ! nodes.allowReload.checked ) { note( '当前有未保存修改，请先导出草稿，再允许服务器草稿替换编辑内容。', true ); nodes.allowReload.focus(); return; } run( function () { return request( '/admin' ).then( function ( data ) { accept( data ); note( initial ? '' : '已读取服务器草稿。' ); } ); }, '正在读取加购反馈配置…' ); }
	function save( publish ) { var c; try { c = validate( collect() ); } catch ( error ) { failed( error ); return; } run( function () { return request( publish ? '/publish' : '/admin', publish ? 'POST' : 'PUT', { config:c, expectedRevision:state.revision } ).then( function ( data ) { accept( data ); note( publish ? '已保存并应用。客户反馈仍须等待真实 WooCommerce 加购结果。' : '草稿已保存，公开配置保持原样。' ); } ); }, publish ? '正在保存并应用…' : '正在保存草稿…' ); }
	function backup() { var blob = new Blob( [ JSON.stringify( collect(), null, 2 ) + '\n' ], { type:'application/json;charset=utf-8' } ), url = URL.createObjectURL( blob ), link = el( 'a' ); link.href = url; link.download = 'cart-feedback-editing-draft.json'; document.body.appendChild( link ); link.click(); link.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 ); note( '已导出当前编辑草稿，包含尚未保存的修改。' ); }
	function onClick( event ) { var b = event.target.closest( 'button[data-action]' ); if ( ! b || ! root.contains( b ) || b.disabled || busy ) return; var action = b.dataset.action; if ( action === 'load' ) { load( false ); return; } if ( ! state ) return; if ( action === 'save' || action === 'publish' ) save( action === 'publish' ); else if ( action === 'backup' ) backup(); else if ( action === 'defaults' ) { fill( defaults ); updateDirty(); note( '默认值已回填当前草稿，保存或应用前不改变后台配置。' ); } }
	build(); load( true );
} )( window.wp, window.pcCartFeedbackAdmin );
