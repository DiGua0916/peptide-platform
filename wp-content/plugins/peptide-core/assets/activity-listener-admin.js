/* Cart promotion listener settings only; customer presentation belongs to the storefront adapter. */
( function ( wp, boot ) {
	'use strict';
	var root = document.getElementById( 'pc-activity-listener' );
	if ( ! root || ! boot ) return;
	var fields = {}, nodes = {}, state = null, defaults = null, busy = false, dirty = false, baseline = '';
	var statuses = { active:'进行中', upcoming:'未开始', ended:'已结束', off:'已停用', draft:'草稿' };
	var types = { spend_amount:'满额减元', spend_percent:'满额打折', qty_amount:'满件减元', qty_percent:'满件打折', nth_percent:'第几件折扣', bundle_price:'满件一口价', buy_x_free_y:'满件免件' };
	function el( tag, className, text ) { var n = document.createElement( tag ); if ( className ) n.className = className; if ( text !== undefined ) n.textContent = String( text ); return n; }
	function btn( label, action, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); b.type = 'button'; b.dataset.action = action; return b; }
	function section( parent, title ) { var n = el( 'section', 'pc-ala-section' ); n.appendChild( el( 'h2', '', title ) ); parent.appendChild( n ); return n; }
	function field( parent, key, label, type, help ) {
		var wrapper = el( 'div', 'pc-ala-field' ), input = el( type === 'select' ? 'select' : 'input' ); input.id = 'pc-ala-' + key; input.name = key;
		if ( type !== 'select' ) input.type = type || 'text';
		var l = el( 'label', '', label ); l.htmlFor = input.id; wrapper.append( l, input );
		if ( help ) { var hint = el( 'p', 'pc-ala-hint', help ); hint.id = input.id + '-hint'; input.setAttribute( 'aria-describedby', hint.id ); wrapper.appendChild( hint ); }
		parent.appendChild( wrapper ); fields[key] = input; return input;
	}
	function toggle( parent, key, label, help ) {
		var wrap = el( 'div', 'pc-ala-toggle' ), l = el( 'label' ), input = el( 'input' ); input.type = 'checkbox'; input.id = 'pc-ala-' + key; input.name = key;
		l.append( input, el( 'span', '', label ) ); wrap.appendChild( l ); if ( help ) wrap.appendChild( el( 'p', 'pc-ala-hint', help ) ); parent.appendChild( wrap ); fields[key] = input;
	}
	function note( text, error ) { nodes.message.textContent = text || ''; nodes.message.className = 'pc-ala-message' + ( error ? ' is-error' : '' ); nodes.message.setAttribute( 'role', error ? 'alert' : 'status' ); }
	function request( path, method, data ) { if ( ! wp || ! wp.apiFetch ) return Promise.reject( { message:'后台请求组件不可用，请刷新页面。' } ); return wp.apiFetch( { path:boot.endpoint + path, method:method || 'GET', headers:{ 'X-WP-Nonce':boot.nonce }, data:data } ); }
	function setBusy( value ) { busy = value; root.setAttribute( 'aria-busy', value ? 'true' : 'false' ); root.querySelectorAll( 'button,input,select' ).forEach( function ( n ) { n.disabled = value || ! state; } ); if ( ! value && ! state ) nodes.reload.disabled = false; }
	function build() {
		root.replaceChildren(); nodes.status = el( 'p', 'pc-ala-status' ); nodes.status.setAttribute( 'aria-live', 'polite' ); nodes.message = el( 'p', 'pc-ala-message' ); nodes.message.setAttribute( 'aria-live', 'polite' );
		var grid = el( 'div', 'pc-ala-grid' ), main = el( 'div', 'pc-ala-main' ), side = el( 'aside', 'pc-ala-side' );
		var selection = section( main, '监听活动' ); toggle( selection, 'enabled', '启用活动监听', '仅用于购物车。没有可用活动或没有合资格商品时，不展示误导性的已达标提示。' );
		field( selection, 'promotionId', '选择优惠活动', 'select', '引用营销活动中的原始规则，不会另建折扣或改写活动。金额活动按合资格金额，件数活动按合资格件数。' );
		nodes.choice = el( 'div', 'pc-ala-choice' ); nodes.choice.setAttribute( 'aria-live', 'polite' ); selection.appendChild( nodes.choice );
		var manage = el( 'a', '', '管理优惠活动' ); manage.href = safeUrl( boot.promotionsUrl ); selection.appendChild( manage );
		field( selection, 'title', '客户看到的标题', 'text', '最多 100 字符，使用纯文本。客户页可沿用店铺语言填写。' ).maxLength = 200;
		var effects = section( main, '节点反馈' ); toggle( effects, 'celebrationEnabled', '达到新节点时播放小烟花', '只在购物车变化后新跨过可生效节点时触发；首次打开和相同状态刷新不应重复庆祝。' ); toggle( effects, 'smileEnabled', '节点达成后显示笑脸', '显示设置交给前端；减少动态效果偏好下保留静态达标提示。' );
		effects.appendChild( el( 'p', 'pc-ala-hint', '此页只管理规则来源与显示配置。客户页进度条、烟花和笑脸由前端接入，后台预览为静态示例。' ) );
		var rules = section( main, '计算口径' ); rules.appendChild( el( 'p', '', '只计算真实优惠引擎分配给所选活动的合资格商品。金额采用商品税前小计，不把运费、税费或其他活动的商品用于凑档。' ) ); rules.appendChild( el( 'p', '', '优惠码替换、客户资格、排除促销商品及活动优先级仍遵守原规则。达到门槛与实际获得优惠是不同状态，监听不会自动修改购物车或增加优惠。' ) );
		var preview = section( side, '后台合成预览' ); preview.appendChild( el( 'p', 'pc-ala-hint', '示例假设商品全部合资格，不校验真实优惠码、范围或顾客资格，不读取或修改客户购物车。' ) );
		field( preview, 'previewAmount', '示例合资格金额', 'text', '店铺币种，使用小数点；不含运费与税费。' ).value = '120.00'; fields.previewAmount.inputMode = 'decimal';
		var q = field( preview, 'previewQuantity', '示例合资格件数', 'number', '仅件数活动使用。' ); q.value = '3'; q.min = '0'; q.max = '10000'; q.step = '1';
		preview.appendChild( btn( '验证并预览', 'preview' ) ); nodes.previewNote = el( 'p', 'pc-ala-hint', '输入示例金额或件数后验证，预览不会保存或应用配置。' );
		nodes.previewResult = el( 'div', 'pc-ala-preview' ); nodes.previewResult.hidden = true; preview.append( nodes.previewNote, nodes.previewResult );
		var saving = section( side, '保存与应用' ); nodes.published = el( 'p', 'pc-ala-published' ); nodes.saved = el( 'p', 'pc-ala-hint' ); saving.append( nodes.published, nodes.saved, btn( '保存并应用', 'publish', true ), btn( '仅保存草稿', 'save' ) );
		saving.appendChild( el( 'p', 'pc-ala-hint', '仅保存草稿不影响客户配置。保存并应用后，客户购物车仍须符合所选活动的实际规则。' ) );
		var actions = el( 'div', 'pc-ala-actions' ); actions.append( btn( '导出当前草稿 JSON', 'backup' ), btn( '恢复默认到草稿', 'defaults' ) ); saving.appendChild( actions );
		var permission = el( 'label', 'pc-ala-check' ); nodes.allowReload = el( 'input' ); nodes.allowReload.type = 'checkbox'; permission.append( nodes.allowReload, el( 'span', '', '允许服务器草稿替换当前编辑内容' ) ); nodes.reload = btn( '重新读取配置', 'load' ); saving.append( permission, nodes.reload, el( 'p', 'pc-ala-hint', '发生多人编辑冲突时，先导出草稿，再允许重新读取；失败会保留你的输入。' ) );
		grid.append( main, side ); root.append( nodes.status, nodes.message, grid ); root.addEventListener( 'click', onClick );
		[ 'input','change' ].forEach( function ( kind ) { root.addEventListener( kind, function ( event ) { if ( ! state || ! event.target.name ) return; if ( ! event.target.name.startsWith( 'preview' ) ) updateDirty(); if ( event.target.name === 'promotionId' ) choiceInfo(); nodes.previewResult.hidden = true; nodes.previewNote.textContent = '示例或配置已修改，请重新验证预览。未保存或应用。'; } ); } );
		window.addEventListener( 'beforeunload', function ( event ) { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } } );
	}
	function safeUrl( value ) { try { var u = new URL( value, window.location.href ); return /^(https?:)$/.test( u.protocol ) ? u.href : ''; } catch ( e ) { return ''; } }
	function configShape( c ) { return !! c && c.version === 1 && typeof c.enabled === 'boolean' && Number.isSafeInteger( c.promotionId ) && c.promotionId >= 0 && typeof c.title === 'string' && typeof c.celebrationEnabled === 'boolean' && typeof c.smileEnabled === 'boolean'; }
	function dtoShape( d ) { return d && Number.isSafeInteger( d.revision ) && d.revision >= 0 && d.draft && configShape( d.draft.config ) && d.published && configShape( d.published.config ) && Number.isSafeInteger( d.published.revision ) && configShape( d.defaults ) && Array.isArray( d.choices ) && d.choices.every( function ( c ) { return c && Number.isSafeInteger( c.id ) && c.id > 0 && typeof c.title === 'string' && typeof c.status === 'string' && typeof c.supported === 'boolean' && Array.isArray( c.tiers ); } ); }
	function collect() { return { version:1, enabled:fields.enabled.checked, promotionId:Number( fields.promotionId.value || 0 ), title:fields.title.value, celebrationEnabled:fields.celebrationEnabled.checked, smileEnabled:fields.smileEnabled.checked }; }
	function selected() { var id = Number( fields.promotionId.value ); return state && state.choices.find( function ( c ) { return c.id === id; } ); }
	function validate( c ) {
		c.title = c.title.normalize( 'NFC' ).replace( /[\s\p{Z}]+/gu, ' ' ).trim();
		if ( Array.from( c.title ).length > 100 || /[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test( c.title ) || /<\s*(?:\/?\s*[a-z][^>]*>|!--|!doctype\b|\?)/i.test( c.title ) ) throw { message:'标题须为 100 字符以内的纯文本。', data:{ fields:{ title:'invalid_format' } } };
		if ( ! Number.isSafeInteger( c.promotionId ) || c.promotionId < 0 ) throw { message:'请选择有效活动。', data:{ fields:{ promotionId:'invalid_format' } } };
		if ( c.enabled ) { var choice = selected(); if ( ! choice || ! choice.supported || [ 'active','upcoming' ].indexOf( choice.status ) < 0 ) throw { message:'启用前请选择进行中或未开始的受支持活动。已结束、草稿和已停用活动不能应用为有效监听。', data:{ fields:{ promotionId:'unavailable' } } }; }
		return c;
	}
	function fill( c ) {
		fields.enabled.checked = c.enabled; fields.title.value = c.title; fields.celebrationEnabled.checked = c.celebrationEnabled; fields.smileEnabled.checked = c.smileEnabled;
		fields.promotionId.replaceChildren(); var empty = el( 'option', '', '请选择已有优惠活动' ); empty.value = '0'; fields.promotionId.appendChild( empty );
		state.choices.forEach( function ( choice ) { var label = choice.title + ' · ' + ( statuses[choice.status] || '状态未知' ) + ( choice.supported ? '' : ' · 暂不支持' ), option = el( 'option', '', label ); option.value = choice.id; option.disabled = ! choice.supported; fields.promotionId.appendChild( option ); } );
		if ( c.promotionId && ! state.choices.some( function ( choice ) { return choice.id === c.promotionId; } ) ) { var missing = el( 'option', '', '已不可用活动 #' + c.promotionId ); missing.value = c.promotionId; fields.promotionId.appendChild( missing ); }
		fields.promotionId.value = String( c.promotionId ); clearInvalid(); choiceInfo(); nodes.previewResult.hidden = true;
	}
	function choiceInfo() {
		nodes.choice.replaceChildren(); var c = selected();
		if ( ! state.choices.length ) { nodes.choice.appendChild( el( 'p', '', '还没有可选择的优惠活动。请先在营销活动中创建活动，再重新读取配置；这里不会自动生成示例活动。' ) ); return; }
		if ( ! c ) { nodes.choice.appendChild( el( 'p', '', Number( fields.promotionId.value ) ? '所选活动已不可用，请重新选择。' : '选择一个活动后，可查看其状态和档位。' ) ); return; }
		nodes.choice.append( el( 'strong', '', c.title ), el( 'p', 'pc-ala-hint', ( types[c.type] || '优惠活动' ) + ' · ' + ( statuses[c.status] || '状态未知' ) + ' · ' + ( c.basis === 'quantity' ? '按合资格件数' : '按合资格金额' ) ) );
		var list = el( 'ol', 'pc-ala-tier-summary' ); c.tiers.forEach( function ( t ) { list.appendChild( el( 'li', '', ( t.thresholdText || '门槛 ' + t.min ) + '：' + ( t.rewardText || '' ) ) ); } ); nodes.choice.appendChild( list );
		if ( ! c.supported ) nodes.choice.appendChild( el( 'p', '', '此活动类型暂不支持，不会生成虚假的购物车进度。' ) );
	}
	function clearInvalid() { root.querySelectorAll( '[aria-invalid]' ).forEach( function ( n ) { n.removeAttribute( 'aria-invalid' ); } ); }
	function updateDirty() { dirty = JSON.stringify( collect() ) !== baseline; nodes.status.textContent = dirty ? '当前编辑内容尚未保存' : '当前草稿已与后台一致'; }
	function accept( data ) {
		if ( ! dtoShape( data ) ) throw { message:'服务器返回的配置不完整，当前输入已保留。' };
		state = data; defaults = JSON.parse( JSON.stringify( data.defaults ) ); fill( data.draft.config ); baseline = JSON.stringify( collect() ); dirty = false; updateDirty(); nodes.allowReload.checked = false;
		nodes.published.textContent = '客户配置：' + ( data.published.config.enabled ? '已启用，活动 #' + data.published.config.promotionId : '已关闭' ) + ' · 修订 ' + data.published.revision;
		nodes.saved.textContent = '草稿保存：' + ( data.draft.updatedAt || '尚未保存' ) + '；应用：' + ( data.published.updatedAt || '尚未应用' );
	}
	function failed( error ) { var message = error && error.message ? String( error.message ) : '操作失败，请重试。当前输入已保留。'; if ( error && error.data && error.data.status === 409 ) message = '其他窗口已更新配置。当前输入已保留；请先导出草稿，再重新读取后处理差异。'; note( message, true ); var fs = error && error.data && error.data.fields, first; if ( fs ) Object.keys( fs ).forEach( function ( p ) { var key = p.replace( /^config\./, '' ).split( '.' )[0]; if ( fields[key] ) { fields[key].setAttribute( 'aria-invalid', 'true' ); first = first || fields[key]; } } ); if ( first ) first.focus(); }
	function run( task, message ) { clearInvalid(); note( message ); setBusy( true ); return Promise.resolve().then( task ).catch( failed ).finally( function () { setBusy( false ); } ); }
	function load( initial ) { if ( dirty && ! nodes.allowReload.checked ) { note( '当前有未保存修改。请先导出草稿，再允许服务器草稿替换当前编辑内容。', true ); nodes.allowReload.focus(); return; } run( function () { return request( '/admin' ).then( function ( d ) { accept( d ); note( initial ? '' : '已读取服务器草稿。' ); } ); }, '正在读取活动监听配置…' ); }
	function save( publish ) { var c; try { c = validate( collect() ); } catch ( e ) { failed( e ); return; } run( function () { return request( publish ? '/publish' : '/admin', publish ? 'POST' : 'PUT', { config:c, expectedRevision:state.revision } ).then( function ( d ) { accept( d ); note( publish ? '已保存并应用。实际进度以购物车中的活动资格为准。' : '草稿已保存，客户配置保持原样。' ); } ); }, publish ? '正在保存并应用…' : '正在保存草稿…' ); }
	function renderPreview( data ) {
		var p = data && data.progress;
		if ( ! data || data.preview !== true || ! p || ! Array.isArray( p.nodes ) || ! Number.isFinite( p.progressPercent ) || p.progressPercent < 0 || p.progressPercent > 100 || ! p.nodes.every( function ( n ) { return n && typeof n.thresholdText === 'string' && typeof n.rewardText === 'string' && typeof n.reached === 'boolean' && Number.isFinite( n.positionPercent ) && n.positionPercent >= 0 && n.positionPercent <= 100; } ) ) throw { message:'预览数据不完整，未替换现有预览。' };
		nodes.previewResult.replaceChildren(); nodes.previewNote.textContent = typeof data.notice === 'string' ? data.notice : '仅后台合成示例，不代表真实购物车优惠。';
		if ( ! p.nodes.length ) { nodes.previewResult.appendChild( el( 'p', '', '示例没有可展示的活动节点。请检查总开关、活动和示例金额。' ) ); nodes.previewResult.hidden = false; return; }
		nodes.previewResult.appendChild( el( 'h3', '', fields.title.value || '活动进度示例' ) );
		if ( typeof p.measureText === 'string' ) nodes.previewResult.appendChild( el( 'p', 'pc-ala-measure', '示例合资格' + ( p.basis === 'quantity' ? '件数：' : '金额：' ) + p.measureText ) );
		var track = el( 'div', 'pc-ala-track' ); track.setAttribute( 'role', 'progressbar' ); track.setAttribute( 'aria-label', '合成示例活动进度' ); track.setAttribute( 'aria-valuemin', '0' ); track.setAttribute( 'aria-valuemax', '100' ); track.setAttribute( 'aria-valuenow', String( p.progressPercent ) );
		var fill = el( 'span', 'pc-ala-track-fill' ); fill.style.width = p.progressPercent + '%'; track.appendChild( fill ); var list = el( 'ol', 'pc-ala-nodes' );
		p.nodes.forEach( function ( n ) { var marker = el( 'span', 'pc-ala-marker' + ( n.reached ? ' is-reached' : '' ) ); marker.style.left = n.positionPercent + '%'; marker.setAttribute( 'aria-hidden', 'true' ); track.appendChild( marker ); var item = el( 'li', n.reached ? 'is-reached' : '' ); item.append( el( 'strong', '', n.thresholdText ), el( 'span', '', n.rewardText ), el( 'small', '', n.reached ? '示例已达门槛' : '示例未达门槛' ) ); list.appendChild( item ); } );
		nodes.previewResult.append( track, list );
		var next = p.next; if ( next && typeof next.message === 'string' ) nodes.previewResult.appendChild( el( 'p', 'pc-ala-next', next.message ) ); else if ( p.completed ) nodes.previewResult.appendChild( el( 'p', 'pc-ala-next', '示例已达到所展示的最高门槛。' ) );
		nodes.previewResult.appendChild( el( 'p', 'pc-ala-hint', '仅演示门槛位置，不表示优惠已实际应用；此处不会播放客户庆祝动画。' ) ); nodes.previewResult.hidden = false;
	}
	function preview() { var c, amount = fields.previewAmount.value.trim(), quantity = Number( fields.previewQuantity.value ); try { c = validate( collect() ); if ( amount.length > 32 || ! /^\d+(?:\.\d+)?$/.test( amount ) || ! Number.isFinite( Number( amount ) ) || Number( amount ) > 10000000 ) throw { message:'示例金额须为 0 至 10,000,000 的十进制数字。', data:{ fields:{ previewAmount:'invalid_format' } } }; if ( fields.previewQuantity.value === '' || ! Number.isSafeInteger( quantity ) || quantity < 0 || quantity > 10000 ) throw { message:'示例件数须为 0 至 10,000 的整数。', data:{ fields:{ previewQuantity:'invalid_format' } } }; } catch ( e ) { failed( e ); return; } run( function () { return request( '/preview', 'POST', { config:c, amount:amount, quantity:quantity } ).then( function ( data ) { renderPreview( data ); note( '合成示例已更新，未保存或应用配置。' ); } ); }, '正在验证合成示例…' ); }
	function backup() { var blob = new Blob( [ JSON.stringify( collect(), null, 2 ) + '\n' ], { type:'application/json;charset=utf-8' } ), url = URL.createObjectURL( blob ), link = el( 'a' ); link.href = url; link.download = 'activity-listener-editing-draft.json'; document.body.appendChild( link ); link.click(); link.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 ); note( '已导出当前编辑草稿，文件可能包含未保存的修改。' ); }
	function onClick( event ) { var b = event.target.closest( 'button[data-action]' ); if ( ! b || ! root.contains( b ) || b.disabled || busy ) return; var action = b.dataset.action; if ( action === 'load' ) { load( false ); return; } if ( ! state ) return; if ( action === 'save' || action === 'publish' ) save( action === 'publish' ); else if ( action === 'preview' ) preview(); else if ( action === 'backup' ) backup(); else if ( action === 'defaults' ) { fill( defaults ); updateDirty(); note( '默认值已回填当前编辑草稿；保存或应用前不改变后台配置。' ); } }
	build(); load( true );
} )( window.wp, window.pcActivityListenerAdmin );
