/* Store configuration editor only. Customer display and animation belong to the theme adapter. */
( function ( wp, boot ) {
	'use strict';
	var root = document.getElementById( 'pc-product-broadcast' );
	if ( ! root || ! boot ) return;
	var fields = {}, nodes = {}, state = null, defaults = null, busy = false, dirty = false, baseline = '', previewConfig = null, previewIndex = 0;
	var limits = { enterMs:[150,2000], holdMs:[1500,15000], exitMs:[150,2000], gapMs:[0,2000] };
	var known = [ 'name', 'product', 'specification', 'price', 'sku' ];
	function el( tag, className, text ) { var n = document.createElement( tag ); if ( className ) n.className = className; if ( text !== undefined ) n.textContent = String( text ); return n; }
	function btn( text, action, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), text ); b.type = 'button'; b.dataset.action = action; return b; }
	function clone( value ) { return JSON.parse( JSON.stringify( value ) ); }
	function chars( value ) { return Array.from( value ).length; }
	function lines( value ) { return value.split( /\r\n|\r|\n/ ).filter( function ( v ) { return v.trim() !== ''; } ); }
	function note( text, type ) { nodes.message.className = 'pc-bra-message' + ( type ? ' is-' + type : '' ); nodes.message.textContent = text || ''; nodes.message.setAttribute( 'role', type === 'error' ? 'alert' : 'status' ); }
	function warningText( item ) { return typeof item === 'string' ? item : item && item.message ? item.message : ''; }
	function warnings( list ) { nodes.warnings.replaceChildren(); ( Array.isArray( list ) ? list : [] ).forEach( function ( w ) { var t = warningText( w ); if ( t ) nodes.warnings.appendChild( el( 'p', '', t ) ); } ); }
	function setBusy( value ) {
		busy = value; root.setAttribute( 'aria-busy', value ? 'true' : 'false' );
		root.querySelectorAll( 'button,input,textarea,select' ).forEach( function ( n ) { n.disabled = value || ! state; } );
		if ( ! value && ! state ) nodes.retry.disabled = false;
	}
	function request( suffix, method, data ) {
		if ( ! wp || ! wp.apiFetch ) return Promise.reject( { message:'后台请求组件不可用，请刷新页面。' } );
		return wp.apiFetch( { path:boot.endpoint + suffix, method:method || 'GET', headers:{ 'X-WP-Nonce':boot.nonce }, data:data } );
	}
	function field( parent, key, label, type, help ) {
		var wrapper = el( 'div', 'pc-bra-field pc-bra-field--' + key );
		var input = el( type === 'textarea' ? 'textarea' : 'input' ); input.id = 'pc-bra-' + key; input.name = key;
		if ( type !== 'textarea' ) input.type = type || 'text';
		var l = el( 'label', '', label ); l.htmlFor = input.id;
		wrapper.append( l, input );
		if ( help ) { var hint = el( 'p', 'pc-bra-hint', help ); hint.id = input.id + '-hint'; input.setAttribute( 'aria-describedby', hint.id ); wrapper.appendChild( hint ); }
		parent.appendChild( wrapper ); fields[ key ] = input;
		return input;
	}
	function section( parent, title ) { var s = el( 'section', 'pc-bra-section' ); s.appendChild( el( 'h2', '', title ) ); parent.appendChild( s ); return s; }
	function toggle( parent, key, label, help ) {
		var wrap = el( 'div', 'pc-bra-toggle' ), labelNode = el( 'label' ), input = el( 'input' ); input.type = 'checkbox'; input.id = 'pc-bra-' + key; input.name = key;
		labelNode.append( input, el( 'span', '', label ) ); wrap.appendChild( labelNode );
		if ( help ) wrap.appendChild( el( 'p', 'pc-bra-hint', help ) );
		parent.appendChild( wrap ); fields[ key ] = input; return input;
	}
	function build() {
		root.replaceChildren();
		nodes.message = el( 'p', 'pc-bra-message' ); nodes.message.setAttribute( 'aria-live', 'polite' );
		nodes.warnings = el( 'div', 'pc-bra-warnings' ); nodes.warnings.setAttribute( 'aria-live', 'polite' );
		nodes.status = el( 'div', 'pc-bra-status' ); nodes.status.setAttribute( 'aria-live', 'polite' );
		nodes.retry = btn( '重新读取配置', 'load' );
		var grid = el( 'div', 'pc-bra-grid' ), main = el( 'div', 'pc-bra-main' ), side = el( 'aside', 'pc-bra-side' );
		var content = section( main, '播报内容' );
		var switches = el( 'div', 'pc-bra-switches' );
		toggle( switches, 'enabled', '启用商品播报', '应用后生效；文案为空时不会保留空白位置。' );
		toggle( switches, 'badgeEnabled', '显示火苗 HOT 标签', '关闭后只保留播报文案。' ); content.appendChild( switches );
		var name = field( content, 'name', '统一姓名', 'text', '选填。使用 {name} 的文案必须填写姓名；姓名是编辑内容，不代表真实购买客户。' ); name.maxLength = 240;
		var items = field( content, 'items', '有序文案', 'textarea', '一行一条，最多 1,000 条，每条最多 500 字符。去除空行、合并多余空白并按大小写无关去重，保留首次出现顺序。' ); items.rows = 13; items.spellcheck = false;
		nodes.count = el( 'p', 'pc-bra-count' ); content.appendChild( nodes.count );
		var keywords = field( content, 'keywords', '重点词', 'textarea', '一行一个，命中部分加粗。可用普通词或变量；最多 1,000 个，每个最多 500 字符。' ); keywords.rows = 3; keywords.spellcheck = false;
		var tokens = el( 'details', 'pc-bra-details' ); tokens.appendChild( el( 'summary', '', '可用变量与播放规则' ) );
		tokens.appendChild( el( 'p', '', '{name} 统一姓名；{product} 当前商品名称；{specification} 所选完整规格；{price} 当前规格实际价格；{sku} 当前规格 SKU。' ) );
		tokens.appendChild( el( 'p', '', '从第 1 条依次播完全部文案后再循环，不随机抽取。长文按每字符 45 毫秒延长停留，最长 15 秒；短句使用设置的停留时间。变量使用当前商品数据，不在这里维护价格、库存或交易记录。' ) );
		tokens.appendChild( el( 'p', '', '客户展示的暂停、动画和手机单行效果由前端接入；此处预览为静态示例，不代表已完成客户页面的动画联调。' ) ); content.appendChild( tokens );
		var playback = section( main, '播放时间' ), timeGrid = el( 'div', 'pc-bra-times' );
		[ [ 'enterMs','进入（毫秒）' ], [ 'holdMs','停留（毫秒）' ], [ 'exitMs','退出（毫秒）' ], [ 'gapMs','间隔（毫秒）' ] ].forEach( function ( pair ) { var range = limits[pair[0]], i = field( timeGrid, pair[0], pair[1], 'number', range[0].toLocaleString( 'zh-CN' ) + ' 至 ' + range[1].toLocaleString( 'zh-CN' ) + ' 毫秒' ); i.min = range[0]; i.max = range[1]; i.step = '1'; i.required = true; } ); playback.appendChild( timeGrid );
		var imports = section( main, '导入与备份' );
		imports.appendChild( el( 'p', 'pc-bra-hint', '导入和恢复默认只修改当前编辑草稿，保存后才写入后台。TXT、CSV、JSON 均须为 UTF-8，单文件不超过 1 MiB。' ) );
		var importGrid = el( 'div', 'pc-bra-import-row' );
		var modeWrap = el( 'label', 'pc-bra-import-mode', '导入方式' ); nodes.mode = el( 'select' ); nodes.mode.id = 'pc-bra-import-mode';
		[ [ 'append','追加到当前文案' ], [ 'replace','替换当前文案' ] ].forEach( function ( pair ) { var o = el( 'option', '', pair[1] ); o.value = pair[0]; nodes.mode.appendChild( o ); } ); modeWrap.appendChild( nodes.mode );
		var fileWrap = el( 'label', 'pc-bra-file', '选择文案文件' ); nodes.file = el( 'input' ); nodes.file.type = 'file'; nodes.file.accept = '.txt,.csv,.json,text/plain,text/csv,application/json'; fileWrap.appendChild( nodes.file );
		importGrid.append( modeWrap, fileWrap, btn( '导入到草稿', 'import' ) ); imports.appendChild( importGrid );
		var restoreWrap = el( 'label', 'pc-bra-check' ); nodes.restore = el( 'input' ); nodes.restore.type = 'checkbox';
		restoreWrap.append( nodes.restore, el( 'span', '', '完整 JSON 配置恢复，包含开关和播放时间' ) ); imports.append( restoreWrap, el( 'p', 'pc-bra-hint', '默认只导入文案及 JSON 中提供的重点词、姓名和标签开关，不覆盖播放时间。完整恢复需选择 JSON 文件并勾选上项。' ) );
		nodes.importReport = el( 'p', 'pc-bra-import-report' ); nodes.importReport.setAttribute( 'aria-live', 'polite' ); imports.appendChild( nodes.importReport );
		var exampleRow = el( 'div', 'pc-bra-actions pc-bra-examples' ); exampleRow.appendChild( el( 'span', '', '下载示例' ) );
		[ [ 'txt','TXT' ], [ 'csv','CSV' ], [ 'csv-names','带姓名 CSV' ], [ 'json','JSON' ] ].forEach( function ( pair ) { var b = btn( pair[1], 'example' ); b.dataset.format = pair[0]; exampleRow.appendChild( b ); } ); imports.appendChild( exampleRow );
		var backup = el( 'div', 'pc-bra-actions' ); backup.append( btn( '导出当前编辑草稿 JSON', 'export-current' ), btn( '导出已应用配置 JSON', 'export-published' ), btn( '恢复默认到草稿', 'defaults' ) ); imports.appendChild( backup );
		var preview = section( side, '草稿预览' );
		preview.appendChild( el( 'p', 'pc-bra-hint', '仅后台示例，不读取或改写真实商品，不会保存或发布。修改后点击“验证并预览”更新。' ) );
		var context = el( 'div', 'pc-bra-context' );
		[ [ 'previewProduct','商品名称','示例商品 A' ], [ 'previewSpecification','规格','20mg · 10 瓶' ], [ 'previewPrice','格式化价格','$49.00' ], [ 'previewSku','SKU','EXAMPLE-20' ] ].forEach( function ( pair ) { var i = field( context, pair[0], pair[1], 'text' ); i.value = pair[2]; i.maxLength = 200; } ); preview.appendChild( context );
		preview.appendChild( btn( '验证并预览', 'preview' ) );
		nodes.previewEmpty = el( 'p', 'pc-bra-hint', '验证后显示一条静态播报，可按顺序切换文案。' );
		nodes.previewRow = el( 'div', 'pc-bra-preview-row' ); nodes.previewRow.hidden = true;
		var icon = el( 'span', 'dashicons dashicons-visibility' ); icon.setAttribute( 'aria-hidden', 'true' );
		nodes.previewText = el( 'span', 'pc-bra-preview-text' ); nodes.badge = el( 'span', 'pc-bra-badge', 'HOT' ); nodes.previewRow.append( icon, nodes.previewText, nodes.badge );
		var previewNav = el( 'div', 'pc-bra-actions pc-bra-preview-nav' ); previewNav.append( btn( '上一条', 'preview-prev' ) ); nodes.previewPosition = el( 'span', 'pc-bra-preview-position' ); previewNav.append( nodes.previewPosition, btn( '下一条', 'preview-next' ) );
		preview.append( nodes.previewEmpty, nodes.previewRow, previewNav );
		var apply = section( side, '保存与应用' );
		nodes.published = el( 'p', 'pc-bra-published' ); nodes.lastSaved = el( 'p', 'pc-bra-hint' );
		apply.append( nodes.published, nodes.lastSaved, btn( '保存并应用', 'publish', true ), btn( '仅保存草稿', 'save' ) );
		apply.appendChild( el( 'p', 'pc-bra-hint', '保存并应用会让客户读取当前配置。仅保存草稿保留线上版本。装修积木只引用本店同一份配置。' ) );
		var reloadPermission = el( 'label', 'pc-bra-check' ); nodes.allowReload = el( 'input' ); nodes.allowReload.type = 'checkbox'; reloadPermission.append( nodes.allowReload, el( 'span', '', '允许服务器草稿替换当前编辑内容' ) );
		apply.append( reloadPermission, nodes.retry, el( 'p', 'pc-bra-hint', '多人编辑冲突时，先导出当前草稿备份，再允许重新读取；不会自动覆盖你的输入。' ) );
		grid.append( main, side ); root.append( nodes.status, nodes.message, nodes.warnings, grid );
		root.addEventListener( 'input', function ( event ) { if ( ! state ) return; if ( event.target.name && ! event.target.name.startsWith( 'preview' ) ) { updateDirty(); previewStale(); } if ( event.target.name === 'items' ) updateCount(); } );
		root.addEventListener( 'click', onClick );
		window.addEventListener( 'beforeunload', function ( event ) { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } } );
	}
	function collect() {
		var c = { version:1, enabled:fields.enabled.checked, badgeEnabled:fields.badgeEnabled.checked, name:fields.name.value, items:lines( fields.items.value ), keywords:lines( fields.keywords.value ) };
		Object.keys( limits ).forEach( function ( key ) { c[key] = fields[key].value === '' ? '' : Number( fields[key].value ); } ); return c;
	}
	function validate( config ) {
		config.name = config.name.normalize( 'NFC' ).trim();
		if ( chars( config.name ) > 120 ) throw { message:'统一姓名最多 120 字符。', data:{ fields:{ name:'too_long' } } };
		if ( /<\/?[a-z][^>]*>/i.test( config.name ) ) throw { message:'姓名须为纯文本，不能包含 HTML 标签。', data:{ fields:{ name:'html_not_allowed' } } };
		[ 'items','keywords' ].forEach( function ( key ) {
			var seen = new Set(), result = [];
			config[key].forEach( function ( t, i ) {
				var clean = t.normalize( 'NFC' ).replace( /\s+/gu, ' ' ).trim(), path = {}, label = key === 'items' ? '文案' : '重点词'; path[key + '.' + i] = 'too_long';
				if ( chars( clean ) > 500 ) throw { message:label + '第 ' + ( i + 1 ) + ' 条超过 500 字符。', data:{ fields:path } };
				if ( /<\/?[a-z][^>]*>/i.test( clean ) ) { path[key + '.' + i] = 'html_not_allowed'; throw { message:label + '第 ' + ( i + 1 ) + ' 条包含 HTML 标签，请改用纯文本。', data:{ fields:path } }; }
				if ( clean && ! seen.has( clean.toLowerCase() ) ) { seen.add( clean.toLowerCase() ); result.push( clean ); }
			} );
			if ( result.length > 1000 ) { var countFields = {}; countFields[key] = 'too_many'; throw { message:( key === 'items' ? '文案' : '重点词' ) + '去重后最多 1,000 条。', data:{ fields:countFields } }; }
			config[key] = result;
		} );
		if ( ! config.name.trim() ) { var missing = config.items.findIndex( function ( t ) { return t.indexOf( '{name}' ) >= 0; } ); if ( missing >= 0 ) throw { message:'文案第 ' + ( missing + 1 ) + ' 条使用了 {name}，请填写统一姓名。', data:{ fields:{ name:'name_required' } } }; }
		Object.keys( limits ).forEach( function ( key ) { if ( ! Number.isInteger( config[key] ) || config[key] < limits[key][0] || config[key] > limits[key][1] ) { var timeFields = {}; timeFields[key] = 'out_of_range'; throw { message:'请在规定范围内填写整数播放时间。', data:{ fields:timeFields } }; } } );
		return config;
	}
	function fill( config ) {
		[ 'enabled','badgeEnabled' ].forEach( function ( key ) { fields[key].checked = config[key] === true; } );
		fields.name.value = typeof config.name === 'string' ? config.name : '';
		[ 'items','keywords' ].forEach( function ( key ) { fields[key].value = Array.isArray( config[key] ) ? config[key].join( '\n' ) : ''; } );
		Object.keys( limits ).forEach( function ( key ) { fields[key].value = config[key]; } );
		clearInvalid(); updateCount(); previewStale();
	}
	function updateCount() { var list = lines( fields.items.value ); nodes.count.textContent = '当前 ' + list.length.toLocaleString( 'zh-CN' ) + ' 条非空文案'; }
	function updateDirty() { dirty = JSON.stringify( collect() ) !== baseline; nodes.status.textContent = dirty ? '当前编辑内容尚未保存' : '当前草稿已与后台一致'; }
	function previewStale() { previewConfig = null; nodes.previewRow.hidden = true; nodes.previewEmpty.hidden = false; nodes.previewEmpty.textContent = '修改后请验证并预览。这里不会自动发布。'; nodes.previewPosition.textContent = ''; }
	function clearInvalid() { root.querySelectorAll( '[aria-invalid]' ).forEach( function ( f ) { f.removeAttribute( 'aria-invalid' ); } ); }
	function failed( error ) {
		var message = error && error.message ? String( error.message ) : '操作失败，请重试。当前编辑内容已保留。';
		if ( error && error.data && error.data.status === 409 ) message = '其他窗口已更新配置。当前输入仍保留；请先导出草稿备份，再重新读取配置后处理差异。';
		note( message, 'error' );
		var errors = error && error.data && error.data.fields, first;
		if ( errors && typeof errors === 'object' ) Object.keys( errors ).forEach( function ( path ) { var key = path.split( '.' )[0]; if ( fields[key] ) { fields[key].setAttribute( 'aria-invalid', 'true' ); if ( ! first ) first = fields[key]; } } );
		if ( first ) first.focus();
	}
	function configShape( c ) {
		return c && c.version === 1 && typeof c.enabled === 'boolean' && typeof c.badgeEnabled === 'boolean' && typeof c.name === 'string' &&
			[ 'items','keywords' ].every( function ( key ) { return Array.isArray( c[key] ) && c[key].every( function ( t ) { return typeof t === 'string'; } ); } ) &&
			Object.keys( limits ).every( function ( key ) { return Number.isInteger( c[key] ) && c[key] >= limits[key][0] && c[key] <= limits[key][1]; } );
	}
	function validDto( data ) { return data && Number.isSafeInteger( data.revision ) && data.revision >= 0 && data.draft && configShape( data.draft.config ) && data.published && Number.isSafeInteger( data.published.revision ) && configShape( data.published.config ) && configShape( data.defaults ); }
	function accept( data ) {
		if ( ! validDto( data ) ) throw { message:'服务器返回的配置不完整，当前编辑内容已保留。' };
		state = data; defaults = clone( data.defaults ); fill( data.draft.config ); baseline = JSON.stringify( collect() ); dirty = false; updateDirty();
		var p = data.published, active = p.config.enabled === true && Array.isArray( p.config.items ) && p.config.items.length > 0;
		nodes.published.textContent = '客户版本：' + ( active ? '已启用' : '已关闭或无文案' ) + ' · 修订 ' + p.revision;
		nodes.lastSaved.textContent = '草稿保存时间：' + ( data.draft.updatedAt || '尚未保存' ) + '；应用时间：' + ( p.updatedAt || '尚未应用' );
		nodes.allowReload.checked = false; warnings( data.warnings );
	}
	function run( operation, loading ) {
		clearInvalid(); note( loading || '正在处理…' ); setBusy( true );
		return Promise.resolve().then( operation ).catch( failed ).finally( function () { setBusy( false ); } );
	}
	function load( initial ) {
		if ( dirty && ! nodes.allowReload.checked ) { note( '当前有未保存修改。请先导出备份，再勾选允许服务器草稿替换当前内容。', 'error' ); nodes.allowReload.focus(); return; }
		run( function () { return request( '/admin' ).then( function ( data ) { accept( data ); note( initial ? '' : '已读取服务器草稿。' ); } ); }, '正在读取后台配置…' );
	}
	function save( publish ) {
		var candidate; try { candidate = validate( collect() ); } catch ( error ) { failed( error ); return; }
		run( function () { return request( publish ? '/publish' : '/admin', publish ? 'POST' : 'PUT', { config:candidate, expectedRevision:state.revision } ).then( function ( data ) { accept( data ); note( publish ? '已保存并应用。客户将读取新的公开配置。' : '草稿已保存，客户仍使用原来已应用的版本。' ); } ); }, publish ? '正在保存并应用…' : '正在保存草稿…' );
	}
	function renderValue( text, context ) { return text.replace( /\{([^{}]+)\}/g, function ( token, key ) { return known.indexOf( key ) >= 0 && typeof context[key] === 'string' ? context[key] : token; } ); }
	function showPreview() {
		if ( ! previewConfig ) return;
		var config = previewConfig, items = config.items || [], count = items.length;
		nodes.previewPosition.textContent = count ? ( previewIndex + 1 ) + ' / ' + count : '0 / 0';
		if ( ! config.enabled || ! count ) { nodes.previewRow.hidden = true; nodes.previewEmpty.hidden = false; nodes.previewEmpty.textContent = ! config.enabled ? '总开关关闭，客户页将隐藏整块播报。' : '文案为空，客户页将隐藏整块播报。'; return; }
		var context = { name:config.name, product:fields.previewProduct.value, specification:fields.previewSpecification.value, price:fields.previewPrice.value, sku:fields.previewSku.value };
		var text = renderValue( items[previewIndex], context ); nodes.previewText.textContent = text; nodes.previewText.title = text;
		nodes.badge.hidden = ! config.badgeEnabled; nodes.previewEmpty.hidden = true; nodes.previewRow.hidden = false;
	}
	function preview() {
		var candidate; try { candidate = validate( collect() ); } catch ( error ) { failed( error ); return; }
		run( function () { return request( '/preview', 'POST', { config:candidate } ).then( function ( data ) { if ( ! data || ! configShape( data.config ) ) throw { message:'预览配置无效，请重试。' }; previewConfig = data.config; previewIndex = 0; warnings( data.warnings ); showPreview(); note( '草稿验证通过。下方仅为后台静态示例，未保存或应用。' ); } ); }, '正在验证草稿…' );
	}
	function download( content, filename, mime ) {
		var safe = String( filename || 'product-broadcast.json' ).replace( /[\\/\x00-\x1f\x7f]/g, '_' );
		var blob = new Blob( [ content ], { type:typeof mime === 'string' && /^(?:application\/json|text\/plain|text\/csv)(?:;.*)?$/.test( mime ) ? mime : 'application/octet-stream' } );
		var url = URL.createObjectURL( blob ), link = el( 'a' ); link.href = url; link.download = safe; document.body.appendChild( link ); link.click(); link.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
	}
	function importFile() {
		var file = nodes.file.files && nodes.file.files[0];
		if ( ! file ) { note( '请先选择 TXT、CSV 或 JSON 文件。', 'error' ); nodes.file.focus(); return; }
		if ( file.size > 1024 * 1024 ) { note( '文件超过 1 MiB，当前草稿未改变。', 'error' ); return; }
		if ( ! /\.(txt|csv|json)$/i.test( file.name ) ) { note( '只支持 TXT、CSV、JSON 文件。', 'error' ); return; }
		if ( nodes.restore.checked && ! /\.json$/i.test( file.name ) ) { note( '完整配置恢复只支持 JSON 文件。', 'error' ); return; }
		var current = collect(), mode = nodes.mode.value, restore = nodes.restore.checked;
		run( function () { return file.arrayBuffer().then( function ( buffer ) { var content; try { content = new TextDecoder( 'utf-8', { fatal:true } ).decode( buffer ); } catch ( e ) { throw { message:'文件不是有效 UTF-8，请另存为 UTF-8 后导入。' }; } return request( '/import', 'POST', { content:content, filename:file.name, mode:mode, restore:restore, config:current } ); } ).then( function ( data ) {
			if ( ! data || ! configShape( data.config ) || ! data.report ) throw { message:'导入结果不完整，当前草稿未改变。' };
			fill( data.config ); updateDirty(); warnings( data.report.warnings ); var r = data.report;
			nodes.importReport.textContent = '有效 ' + r.validCount + ' 条，合并重复 ' + r.duplicateCount + ' 条，新增 ' + r.addedCount + ' 条；当前共 ' + data.config.items.length + ' 条。';
			note( ( restore ? '完整配置' : '文案' ) + '已导入当前编辑草稿，尚未保存或应用。' );
		} ); }, '正在检查并导入文件…' );
	}
	function onClick( event ) {
		var target = event.target.closest( 'button[data-action]' ); if ( ! target || ! root.contains( target ) || target.disabled || busy ) return;
		var action = target.dataset.action;
		if ( action === 'load' ) { load( false ); return; }
		if ( ! state ) return;
		if ( action === 'save' || action === 'publish' ) { save( action === 'publish' ); return; }
		if ( action === 'preview' ) { preview(); return; }
		if ( action === 'preview-prev' || action === 'preview-next' ) { if ( previewConfig && previewConfig.items.length ) { previewIndex = ( previewIndex + ( action === 'preview-next' ? 1 : -1 ) + previewConfig.items.length ) % previewConfig.items.length; showPreview(); } return; }
		if ( action === 'defaults' ) { fill( defaults ); updateDirty(); warnings( [] ); note( '默认内容已回填当前草稿；保存或应用前，后台与客户版本均不改变。' ); return; }
		if ( action === 'import' ) { importFile(); return; }
		if ( action === 'export-current' ) { download( JSON.stringify( collect(), null, 2 ) + '\n', 'product-broadcast-editing-draft.json', 'application/json;charset=utf-8' ); note( '已导出当前编辑草稿，文件可能包含尚未保存的修改。' ); return; }
		if ( action === 'example' || action === 'export-published' ) run( function () { return request( action === 'example' ? '/examples?format=' + encodeURIComponent( target.dataset.format ) : '/export?source=published' ).then( function ( file ) { if ( ! file || typeof file.content !== 'string' ) throw { message:'下载内容不可用，请重试。' }; download( file.content, file.filename, file.mime ); note( action === 'example' ? '示例文件已准备下载。' : '已应用配置已准备下载。' ); } ); }, '正在准备下载…' );
	}
	build(); load( true );
} )( window.wp, window.pcProductBroadcastAdmin );
