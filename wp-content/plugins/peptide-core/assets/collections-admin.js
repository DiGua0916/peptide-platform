/* VialCove collection operations. WooCommerce remains the source of products and terms. */
( function () {
    'use strict';

    var config = window.pcCollections || {}, root = document.getElementById( 'pc-collections-app' );
    if ( ! root ) { return; }
    var state = { collection: null, rows: [], products: [], total: 0, productTotal: 0, page: 1, productPage: 1, perPage: 20, productPerPage: 20, search: '', type: 'all', favorite: 'all', productSearch: '', productMode: 'current', productLayout: 'list', selected: new Set(), productSelected: new Set(), busy: false, dirty: false, editVersion: 0, listRequest: 0, productRequest: 0, importDocument: null, importPreview: null };
    var sortLabels = { 'default': '保持店铺默认排序', manual: '手动排序', created_desc: '按创建时间从新到旧', sales_desc: '按总销量从高到低', price_asc: '按价格从低到高', price_desc: '按价格从高到低', title_asc: '按商品名称排序' };
    var statusLabels = { publish: '已上架', draft: '草稿', pending: '待审核', private: '私密', future: '定时上架' };
    var paths = {
        plus: 'M12 5v14M5 12h14', search: 'm21 21-4.4-4.4M19 10.5a8.5 8.5 0 1 1-17 0 8.5 8.5 0 0 1 17 0Z',
        edit: 'm15 4 5 5M4 20l4-1 13-13a2.1 2.1 0 0 0-3-3L5 16l-1 4Z', products: 'M4 4h16v16H4zM4 10h16M10 4v16',
        eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        copy: 'M8 8h12v12H8zM16 8V4H4v12h4', trash: 'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7',
        star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z',
        upload: 'M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6', download: 'M12 3v13m-5-5 5 5 5-5M4 16v5h16v-5',
        grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z', list: 'M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01',
        up: 'm6 15 6-6 6 6', down: 'm6 9 6 6 6-6', top: 'M5 4h14M12 20V8m-5 5 5-5 5 5', image: 'M3 3h18v18H3zM3 16l5-5 5 5 3-3 5 5M16 7h.01', back: 'M20 12H4m6-6-6 6 6 6', close: 'm6 6 12 12M18 6 6 18'
    };
    function icon( name ) {
        var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
        svg.setAttribute( 'viewBox', '0 0 24 24' ); svg.setAttribute( 'fill', 'none' ); svg.setAttribute( 'stroke', 'currentColor' ); svg.setAttribute( 'stroke-width', '1.7' ); svg.setAttribute( 'stroke-linecap', 'round' ); svg.setAttribute( 'stroke-linejoin', 'round' ); svg.setAttribute( 'aria-hidden', 'true' );
        var path = document.createElementNS( svg.namespaceURI, 'path' ); path.setAttribute( 'd', paths[ name ] || paths.products ); svg.appendChild( path ); return svg;
    }
    function el( tag, attrs, children ) {
        var node = document.createElement( tag );
        Object.keys( attrs || {} ).forEach( function ( key ) {
            var value = attrs[ key ]; if ( value === null || value === undefined || value === false ) { return; }
            if ( key === 'class' ) { node.className = value; }
            else if ( key === 'on' ) { Object.keys( value ).forEach( function ( event ) { node.addEventListener( event, value[ event ] ); } ); }
            else if ( [ 'value', 'checked', 'disabled', 'selected', 'hidden' ].indexOf( key ) !== -1 ) { node[ key ] = value; }
            else { node.setAttribute( key, value === true ? '' : String( value ) ); }
        } );
        ( Array.isArray( children ) ? children : [ children ] ).forEach( function ( child ) { if ( child !== null && child !== undefined ) { node.appendChild( child instanceof Node ? child : document.createTextNode( String( child ) ) ); } } );
        return node;
    }
    function button( label, name, handler, options ) {
        options = options || {};
        var attrs = { type: 'button', class: 'button pc-col-button' + ( options.primary ? ' button-primary' : '' ) + ( options.iconOnly ? ' pc-col-icon-button' : '' ) + ( options.danger ? ' pc-col-danger' : '' ), title: label, 'aria-label': label, disabled: !! options.disabled, on: { click: handler } };
        if ( options.mutate ) { attrs[ 'data-mutate' ] = 'true'; }
        return el( 'button', attrs, [ name ? icon( name ) : null, options.iconOnly ? null : label ] );
    }
    function safeUrl( value ) { try { var url = new URL( String( value || '' ), window.location.href ); return [ 'http:', 'https:' ].indexOf( url.protocol ) !== -1 ? url.href : '#'; } catch ( e ) { return '#'; } }
    function route( id, hash ) { var url = new URL( window.location.href ); url.search = ''; url.searchParams.set( 'page', 'pc-collections' ); if ( id !== null && id !== undefined && id !== 'list' ) { url.searchParams.set( 'collection', String( id ) ); } url.hash = hash || ''; return url.href; }
    function link( label, url, name, extra ) { return el( 'a', Object.assign( { href: safeUrl( url ), class: 'button pc-col-button' }, extra || {} ), [ name ? icon( name ) : null, label ] ); }
    async function navigate( id, hash ) { if ( state.dirty && ! await window.VialCoveDialogs.confirm( '当前专辑有尚未保存的修改，确定离开吗？' ) ) { return; } state.dirty = false; window.location.assign( route( id, hash ) ); }
    function cleanText( value ) { var text = document.createElement( 'textarea' ); text.innerHTML = String( value || '' ); return text.value; }
    function number( value ) { return Math.max( 0, Number.parseInt( value, 10 ) || 0 ); }
    function modifiedTime( value ) { if ( ! value ) { return '尚无修改记录'; } var raw = String( value ), date = new Date( /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( raw ) ? raw.replace( ' ', 'T' ) + 'Z' : raw ); return Number.isNaN( date.getTime() ) ? raw : date.toLocaleString( 'zh-CN', { hour12: false } ); }
    function choice( value, label, selected ) { return el( 'option', { value: value, selected: String( value ) === String( selected ) }, label ); }
    function select( values, value, attrs ) { return el( 'select', attrs || {}, Object.keys( values ).map( function ( key ) { return choice( key, values[ key ], value ); } ) ); }
    function field( label, control, help ) { return el( 'div', { class: 'pc-col-field' }, [ el( 'label', { for: control.id || null }, label ), control, help ? el( 'p', { class: 'pc-col-help' }, help ) : null ] ); }
    function thumb( src, title, large ) { return src ? el( 'img', { src: safeUrl( src ), alt: title || '', class: large ? 'pc-col-cover-image' : 'pc-col-thumbnail', loading: 'lazy' } ) : el( 'span', { class: 'pc-col-image-empty' + ( large ? ' pc-col-image-empty--large' : '' ), role: 'img', 'aria-label': '尚未设置封面' }, icon( 'image' ) ); }
    function normalizeCollection( data ) {
        data = data || {};
        return Object.assign( { id: null, name: '', slug: '', description: '', footer_description: '', thumbnail_id: 0, thumbnail_url: '', favorite: false, type: 'manual', sort: 'default', revision: 0, manual_order: [], seo_title: '', seo_description: '', public_url: '', rules: { match: 'all', conditions: [] } }, data, { name: cleanText( data.name ), rules: data.rules || { match: 'all', conditions: [] } } );
    }
    function notice( message, error ) {
        var target = root.querySelector( '.pc-col-notice' );
        if ( ! target ) { target = el( 'div', { class: 'pc-col-notice', role: 'status', 'aria-live': 'polite' } ); root.prepend( target ); }
        target.classList.toggle( 'is-error', !! error ); target.hidden = ! message; target.textContent = message || ''; target.setAttribute( 'role', error ? 'alert' : 'status' );
    }
    function markDirty() { state.dirty = true; state.editVersion++; var status = root.querySelector( '.pc-col-save-state' ); if ( status ) { status.textContent = '有未保存的修改'; } }
    async function request( operation, payload ) {
        var body = new URLSearchParams(); body.set( 'action', 'pc_collections_request' ); body.set( 'nonce', config.nonce || '' ); body.set( 'operation', operation ); body.set( 'payload', JSON.stringify( payload || {} ) );
        var response = await fetch( config.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() } );
        var json;
        try { json = await response.json(); } catch ( e ) { throw new Error( '服务器没有返回有效结果，请重新加载后重试。' ); }
        if ( ! response.ok || ! json.success ) { throw new Error( typeof json.data === 'string' ? json.data : ( json.data && json.data.message ) || '操作未完成，请重试。' ); }
        return json.data && Object.prototype.hasOwnProperty.call( json.data, 'result' ) ? json.data.result : json.data;
    }
    async function action( handler ) {
        if ( state.busy ) { return; } state.busy = true; root.setAttribute( 'aria-busy', 'true' );
        var controls = Array.from( root.querySelectorAll( 'button,input,select,textarea' ) ).map( function ( item ) { var before = item.disabled; item.disabled = true; return { node: item, disabled: before }; } );
        try { await handler(); } catch ( error ) { notice( error.message || '操作未完成，请重试。', true ); }
        finally { state.busy = false; root.removeAttribute( 'aria-busy' ); controls.forEach( function ( item ) { if ( item.node.isConnected ) { item.node.disabled = item.disabled; } } ); }
    }
    function heading( title, actions, back ) { return el( 'header', { class: 'pc-col-heading' }, [ el( 'div', { class: 'pc-col-heading-title' }, [ back ? button( '返回专辑列表', 'back', function () { navigate( 'list' ); }, { iconOnly: true } ) : null, el( 'h1', {}, title ) ] ), el( 'div', { class: 'pc-col-heading-actions' }, actions || [] ) ] ); }
    function pager( total, page, perPage, onPage ) {
        var pages = Math.max( 1, Math.ceil( number( total ) / perPage ) );
        return el( 'nav', { class: 'pc-col-pagination', 'aria-label': '分页' }, [ el( 'span', {}, '共 ' + number( total ) + ' 条' ), button( '上一页', null, function () { onPage( page - 1 ); }, { disabled: page <= 1 } ), el( 'span', { class: 'pc-col-page-number' }, page + ' / ' + pages ), button( '下一页', null, function () { onPage( page + 1 ); }, { disabled: page >= pages } ) ] );
    }
    function loading( message ) { return el( 'div', { class: 'pc-col-loading', role: 'status' }, [ el( 'span', { class: 'pc-col-loading-line' } ), el( 'p', {}, message ) ] ); }
    function empty( title, text ) { return el( 'div', { class: 'pc-col-empty' }, [ icon( 'products' ), el( 'h3', {}, title ), el( 'p', {}, text ) ] ); }
    function listFilters() {
        var search = el( 'input', { type: 'search', value: state.search, placeholder: '搜索专辑名称', 'aria-label': '搜索专辑名称', maxlength: 250 } );
        var type = select( { all: '全部专辑类型', manual: '手动专辑', automatic: '自动专辑' }, state.type, { 'aria-label': '专辑类型' } );
        var common = select( { all: '全部专辑', yes: '常用专辑', no: '非常用专辑' }, state.favorite, { 'aria-label': '是否常用专辑' } );
        var submit = function ( event ) { event.preventDefault(); state.search = search.value.trim(); state.type = type.value; state.favorite = common.value; state.page = 1; loadList(); };
        type.addEventListener( 'change', submit ); common.addEventListener( 'change', submit );
        return el( 'form', { class: 'pc-col-filters', on: { submit: submit } }, [ type, common, el( 'div', { class: 'pc-col-search' }, [ search, el( 'button', { type: 'submit', class: 'button', 'aria-label': '搜索专辑' }, icon( 'search' ) ) ] ) ] );
    }
    function toggleSelection( set, id, checked ) { checked ? set.add( number( id ) ) : set.delete( number( id ) ); }
    function selectionSummary() { var target = root.querySelector( '.pc-col-selection-count' ); if ( target ) { target.textContent = '已选 ' + state.selected.size + ' 个专辑'; } }
    function rowActions( row ) {
        return el( 'div', { class: 'pc-col-row-actions' }, [
            button( '编辑专辑 ' + cleanText( row.name ), 'edit', function () { navigate( row.id ); }, { iconOnly: true } ),
            button( '管理专辑商品 ' + cleanText( row.name ), 'products', function () { navigate( row.id, 'products' ); }, { iconOnly: true } ),
            link( '', row.public_url, 'eye', { class: 'button pc-col-icon-button', title: '查看专辑页面', 'aria-label': '查看专辑页面 ' + cleanText( row.name ), target: '_blank', rel: 'noopener' } ),
            button( '复制专辑 ' + cleanText( row.name ), 'copy', function () { action( async function () { var result = await request( 'duplicate', { id: row.id, revision: row.revision } ); var collection = result.collection || result; state.dirty = false; window.location.assign( route( collection.id ) ); } ); }, { iconOnly: true, mutate: true } ),
            button( '删除专辑 ' + cleanText( row.name ), 'trash', function () { deleteCollections( [ row ] ); }, { iconOnly: true, danger: true, mutate: true } )
        ] );
    }
    function albumTable() {
        var all = el( 'input', { type: 'checkbox', 'aria-label': '选择本页全部专辑', checked: state.rows.length > 0 && state.rows.every( function ( row ) { return state.selected.has( number( row.id ) ); } ), on: { change: function ( event ) { state.rows.forEach( function ( row ) { toggleSelection( state.selected, row.id, event.target.checked ); } ); renderList(); } } } );
        var headers = [ all, '操作', '专辑名称', '封面', '商品数量', '类型', '排序方式', '访问量', '修改时间' ];
        var table = el( 'table', { class: 'pc-col-table' }, [ el( 'thead', {}, el( 'tr', {}, headers.map( function ( title ) { return el( 'th', { scope: 'col' }, title ); } ) ) ), el( 'tbody', {}, state.rows.map( function ( row ) {
            var name = cleanText( row.name );
            var common = button( row.favorite ? '取消常用专辑 ' + name : '设为常用专辑 ' + name, 'star', function () { action( async function () { await request( 'favorite', { id: row.id, revision: row.revision, value: ! row.favorite } ); await loadList(); } ); }, { iconOnly: true, mutate: true } );
            common.classList.add( 'pc-col-favorite' ); common.classList.toggle( 'is-selected', !! row.favorite ); common.setAttribute( 'aria-pressed', row.favorite ? 'true' : 'false' );
            var cells = [
                el( 'td', { class: 'pc-col-check', 'data-label': '选择' }, el( 'input', { type: 'checkbox', 'aria-label': '选择专辑 ' + name, checked: state.selected.has( number( row.id ) ), on: { change: function ( event ) { toggleSelection( state.selected, row.id, event.target.checked ); selectionSummary(); } } } ) ),
                el( 'td', { class: 'pc-col-actions-cell', 'data-label': '操作' }, rowActions( row ) ),
                el( 'td', { class: 'pc-col-name-cell', 'data-label': '专辑名称' }, el( 'div', { class: 'pc-col-name' }, [ common, el( 'a', { href: route( row.id ), class: 'pc-col-name-link' }, name ) ] ) ),
                el( 'td', { class: 'pc-col-cover-cell', 'data-label': '封面' }, thumb( row.thumbnail_url, name ) ),
                el( 'td', { 'data-label': '商品数量' }, el( 'a', { href: route( row.id, 'products' ), class: 'pc-col-count' }, number( row.count ) ) ),
                el( 'td', { 'data-label': '类型' }, el( 'span', { class: 'pc-col-chip' }, row.type === 'automatic' ? '自动' : '手动' ) ),
                el( 'td', { 'data-label': '排序方式' }, sortLabels[ row.sort ] || sortLabels.default ),
                el( 'td', { 'data-label': '访问量' }, row.views === null || row.views === undefined ? '未统计' : number( row.views ) ),
                el( 'td', { class: 'pc-col-time', 'data-label': '修改时间' }, modifiedTime( row.updated_at ) )
            ];
            return el( 'tr', {}, cells );
        } ) ) ] );
        return el( 'div', { class: 'pc-col-table-wrap' }, table );
    }
    function renderList() {
        root.replaceChildren();
        root.append( heading( '商品专辑', [ button( '导出专辑', 'download', exportCollections, { mutate: true } ), button( '导入专辑', 'upload', chooseImport ), button( '新建专辑', 'plus', function () { navigate( 'new' ); }, { primary: true } ) ] ) );
        root.append( el( 'div', { class: 'pc-col-notice', role: 'status', 'aria-live': 'polite', hidden: true } ) );
        var panel = el( 'section', { class: 'pc-col-panel' }, [ listFilters(), el( 'div', { class: 'pc-col-bulk' }, [ el( 'span', { class: 'pc-col-selection-count' }, '已选 ' + state.selected.size + ' 个专辑' ), button( '导出所选', 'download', exportCollections, { mutate: true } ), button( '删除所选', 'trash', function () { var rows = state.rows.filter( function ( row ) { return state.selected.has( number( row.id ) ); } ); if ( ! rows.length ) { notice( '请先选择要删除的专辑。', true ); return; } deleteCollections( rows ); }, { danger: true, mutate: true } ) ] ) ] );
        panel.append( state.rows.length ? albumTable() : empty( '没有符合条件的专辑', '调整筛选条件，或新建专辑后添加商品。' ) );
        panel.append( pager( state.total, state.page, state.perPage, function ( page ) { state.page = page; loadList(); } ) ); root.append( panel );
        if ( state.importPreview ) { renderImportPreview(); }
    }
    async function loadList() {
        var serial = ++state.listRequest;
        if ( ! root.querySelector( '.pc-col-panel' ) ) { root.replaceChildren( heading( '商品专辑', [] ), loading( '正在读取专辑…' ) ); }
        try {
            var result = await request( 'list', { search: state.search, type: state.type, favorite: state.favorite, page: state.page, per_page: state.perPage } );
            if ( serial !== state.listRequest ) { return; }
            state.rows = result.items || []; state.total = number( result.total ); state.page = number( result.page ) || state.page;
            var lastPage = Math.max( 1, Math.ceil( state.total / state.perPage ) );
            if ( state.page > lastPage ) { state.page = lastPage; return loadList(); }
            state.selected.clear(); renderList();
        } catch ( error ) { notice( error.message, true ); }
    }
    async function deleteCollections( rows ) {
        if ( ! await window.VialCoveDialogs.confirm( '删除这 ' + rows.length + ' 个专辑？专辑中的商品不会被删除，但它们与这些专辑的关联将移除。此操作无法撤销。' ) ) { return; }
        action( async function () { var completed = 0; try { for ( var row of rows ) { await request( 'delete', { id: row.id, revision: row.revision, confirmed: true } ); completed++; } } catch ( error ) { await loadList(); throw new Error( '已删除 ' + completed + ' 个专辑，后续专辑未继续处理。' + error.message ); } await loadList(); notice( '已删除 ' + completed + ' 个专辑，商品保留。' ); } );
    }
    async function exportCollections() {
        action( async function () {
            var result = await request( 'export', { ids: Array.from( state.selected ) } );
            var blob = new Blob( [ JSON.stringify( result.document, null, 2 ) ], { type: 'application/json;charset=utf-8' } ), url = URL.createObjectURL( blob ), anchor = document.createElement( 'a' );
            anchor.href = url; anchor.download = result.filename || 'collections.json'; document.body.appendChild( anchor ); anchor.click(); anchor.remove(); window.setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
            notice( '专辑文件已生成。' );
        } );
    }
    function chooseImport() {
        var input = el( 'input', { type: 'file', accept: '.json,application/json', class: 'pc-col-file-input', 'aria-label': '选择专辑 JSON 文件' } );
        input.addEventListener( 'change', function () { var file = input.files && input.files[ 0 ]; input.remove(); if ( ! file ) { return; } action( async function () { if ( file.size > 1048576 ) { throw new Error( '专辑文件不能超过 1 MB。' ); } var parsed; try { parsed = JSON.parse( await file.text() ); } catch ( e ) { throw new Error( '文件不是有效的专辑 JSON，请使用本页导出的文件。' ); } var preview = await request( 'import_preview', { document: parsed } ); state.importDocument = parsed; state.importPreview = preview; renderList(); } ); } );
        document.body.appendChild( input ); input.click();
    }
    function renderImportPreview() {
        var preview = state.importPreview || {}, count = number( preview.count !== undefined ? preview.count : preview.total ), errors = Array.isArray( preview.errors ) ? preview.errors : [];
        var panel = el( 'section', { class: 'pc-col-panel pc-col-import-preview' }, [ el( 'div', { class: 'pc-col-panel-heading' }, el( 'h2', {}, '导入预览' ) ), el( 'div', { class: 'pc-col-panel-body' }, [ el( 'p', {}, '将导入 ' + count + ' 个专辑。确认前不会写入商品或专辑。' ), errors.length ? el( 'ul', { class: 'pc-col-error-list' }, errors.map( function ( value ) { return el( 'li', {}, typeof value === 'string' ? value : value.message || '文件中有无效记录。' ); } ) ) : null, el( 'div', { class: 'pc-col-inline-actions' }, [ button( '取消导入', null, function () { state.importPreview = null; state.importDocument = null; renderList(); } ), button( '确认导入', 'upload', function () { action( async function () {
            var result = await request( 'import_apply', { document: state.importDocument, confirmed: true } );
            state.importDocument = null; state.importPreview = null; await loadList();
            var failures = Array.isArray( result.errors ) ? result.errors : [], created = number( result.count !== undefined ? result.count : Array.isArray( result.created ) ? result.created.length : 0 );
            if ( result.partial || failures.length ) { notice( '已创建 ' + created + ' 个专辑，部分记录未完成。' + failures.map( function ( value ) { return typeof value === 'string' ? value : value.message || '一项记录导入失败。'; } ).join( '；' ), true ); }
            else { notice( result.message || '已成功导入 ' + created + ' 个专辑。' ); }
        } ); }, { primary: true, disabled: errors.length > 0 || count < 1, mutate: true } ) ] ) ] ) ] );
        root.insertBefore( panel, root.querySelector( '.pc-col-panel' ) );
    }
    function editorValue( id ) { var area = document.getElementById( id ), editor = window.tinymce && window.tinymce.get( id ); return editor && ! editor.isHidden() ? editor.getContent() : area ? area.value : ''; }
    function removeEditors() { if ( window.wp && window.wp.editor && window.wp.editor.remove ) { [ 'pc-col-description', 'pc-col-footer-description' ].forEach( function ( id ) { try { window.wp.editor.remove( id ); } catch ( e ) { /* A plain textarea needs no cleanup. */ } } ); } }
    function initEditors() {
        if ( ! ( window.wp && window.wp.editor && window.wp.editor.initialize ) ) { return; }
        [ 'pc-col-description', 'pc-col-footer-description' ].forEach( function ( id ) {
            window.wp.editor.initialize( id, { tinymce: { height: 260, menubar: false, setup: function ( editor ) { editor.on( 'change input undo redo', markDirty ); } }, quicktags: true, mediaButtons: true } );
        } );
    }
    function editorField( id, label, content, help ) {
        var textarea = el( 'textarea', { id: id, rows: 12, class: 'pc-col-richtext', on: { input: markDirty } }, content || '' );
        return field( label, textarea, help );
    }
    function coverControl() {
        var collection = state.collection;
        var preview = el( 'div', { class: 'pc-col-cover-preview' }, thumb( collection.thumbnail_url, collection.name, true ) );
        var choose = button( collection.thumbnail_id ? '更换封面' : '选择封面', 'image', function () {
            if ( ! ( window.wp && window.wp.media ) ) { notice( '媒体选择器未载入，请重新加载页面。', true ); return; }
            var frame = window.wp.media( { title: config.mediaTitle || '选择专辑封面', button: { text: config.mediaButton || '使用此图片' }, library: { type: 'image' }, multiple: false } );
            frame.on( 'select', function () { var image = frame.state().get( 'selection' ).first().toJSON(); collection.thumbnail_id = number( image.id ); collection.thumbnail_url = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url; preview.replaceChildren( thumb( collection.thumbnail_url, collection.name, true ) ); remove.disabled = false; choose.lastChild.textContent = '更换封面'; markDirty(); } ); frame.open();
        } );
        var remove = button( '移除封面', null, function () { collection.thumbnail_id = 0; collection.thumbnail_url = ''; preview.replaceChildren( thumb( '', '', true ) ); remove.disabled = true; markDirty(); }, { disabled: ! collection.thumbnail_id } );
        return el( 'div', { class: 'pc-col-cover' }, [ preview, el( 'div', { class: 'pc-col-inline-actions' }, [ choose, remove ] ), el( 'p', { class: 'pc-col-help' }, '建议使用正方形图片。图片比例不会改变原始文件。' ) ] );
    }
    function conditionRow( rule, index ) {
        var fields = { title: '商品名称', sku: '商品或规格 SKU', tag: '商品标签', price: '商品价格', stock_status: '库存状态' };
        var operators = rule.field === 'price' ? { equals: '等于', greater_than: '大于', less_than: '小于' } : rule.field === 'stock_status' ? { equals: '等于' } : { contains: '包含', equals: '等于' };
        var fieldSelect = select( fields, rule.field, { 'aria-label': '条件 ' + ( index + 1 ) + ' 字段', on: { change: function ( event ) { rule.field = event.target.value; rule.operator = rule.field === 'price' || rule.field === 'stock_status' ? 'equals' : 'contains'; rule.value = rule.field === 'stock_status' ? 'instock' : ''; markDirty(); renderRules(); } } } );
        var opSelect = select( operators, rule.operator, { 'aria-label': '条件 ' + ( index + 1 ) + ' 比较方式', on: { change: function ( event ) { rule.operator = event.target.value; markDirty(); } } } );
        var value = rule.field === 'stock_status' ? select( { instock: '有库存', outofstock: '缺货', onbackorder: '允许预订' }, rule.value, { 'aria-label': '条件 ' + ( index + 1 ) + ' 库存状态', on: { change: function ( event ) { rule.value = event.target.value; markDirty(); } } } ) : el( 'input', { type: rule.field === 'price' ? 'number' : 'text', min: rule.field === 'price' ? 0 : null, step: rule.field === 'price' ? '0.01' : null, value: rule.value || '', placeholder: rule.field === 'tag' ? '标签名称、别名或 ID' : '输入条件值', 'aria-label': '条件 ' + ( index + 1 ) + ' 值', on: { input: function ( event ) { rule.value = event.target.value; markDirty(); } } } );
        return el( 'div', { class: 'pc-col-rule-row' }, [ fieldSelect, opSelect, value, button( '删除条件 ' + ( index + 1 ), 'close', function () { state.collection.rules.conditions.splice( index, 1 ); markDirty(); renderRules(); }, { iconOnly: true } ) ] );
    }
    function renderRules() {
        var holder = root.querySelector( '.pc-col-rules' ); if ( ! holder ) { return; }
        var rules = state.collection.rules; if ( ! Array.isArray( rules.conditions ) ) { rules.conditions = []; }
        holder.replaceChildren( el( 'p', { class: 'pc-col-help' }, '符合条件的商品会自动加入，不再手动选择。保存规则前会先预览匹配结果。' ), field( '匹配方式', select( { all: '满足全部条件', any: '满足任一条件' }, rules.match, { id: 'pc-col-rule-match', on: { change: function ( event ) { rules.match = event.target.value; markDirty(); } } } ) ), el( 'div', { class: 'pc-col-rule-rows' }, rules.conditions.map( conditionRow ) ), el( 'div', { class: 'pc-col-inline-actions' }, [ button( '添加条件', 'plus', function () { rules.conditions.push( { field: 'title', operator: 'contains', value: '' } ); markDirty(); renderRules(); } ), button( '预览匹配商品', 'eye', function () { action( previewRules ); }, { mutate: true } ) ] ), el( 'div', { class: 'pc-col-rule-preview', role: 'status', 'aria-live': 'polite' } ) );
    }
    async function previewRules( rulesOverride ) {
        var result = await request( 'preview_rules', { id: state.collection.id, rules: rulesOverride || state.collection.rules } ), target = root.querySelector( '.pc-col-rule-preview' );
        if ( target ) { var samples = result.samples || result.items || []; target.replaceChildren( el( 'strong', {}, '匹配 ' + number( result.count !== undefined ? result.count : result.total ) + ' 个商品' ), samples.length ? el( 'ul', {}, samples.slice( 0, 5 ).map( function ( product ) { return el( 'li', {}, cleanText( product.name || product.title || '' ) ); } ) ) : el( 'p', {}, '当前没有符合这些条件的商品。' ) ); }
        return result;
    }
    function renderEditor() {
        removeEditors(); root.replaceChildren(); var collection = state.collection;
        root.append( heading( collection.id ? '编辑专辑' : '新建专辑', collection.id && collection.public_url ? [ link( '查看专辑', collection.public_url, 'eye', { target: '_blank', rel: 'noopener' } ) ] : [], true ) );
        root.append( el( 'div', { class: 'pc-col-notice', role: 'status', 'aria-live': 'polite', hidden: true } ) );
        var name = el( 'input', { id: 'pc-col-name', type: 'text', maxlength: 250, value: collection.name, required: true, on: { input: markDirty } } );
        var slug = el( 'input', { id: 'pc-col-slug', type: 'text', value: collection.slug, on: { input: markDirty } } );
        var type = select( { manual: '手动选择商品', automatic: '按条件自动加入' }, collection.type, { id: 'pc-col-type', on: { change: function ( event ) { collection.type = event.target.value; var rules = root.querySelector( '.pc-col-rules' ); rules.hidden = collection.type !== 'automatic'; markDirty(); renderProducts(); } } } );
        var left = el( 'section', { class: 'pc-col-panel pc-col-main-panel' }, [ el( 'div', { class: 'pc-col-panel-heading' }, el( 'h2', {}, '基本信息' ) ), el( 'div', { class: 'pc-col-panel-body' }, [ field( '专辑名称', name ), field( '链接别名', slug, '留空时根据名称生成。修改已有别名会改变专辑网址。' ), editorField( 'pc-col-description', '专辑顶部描述', collection.description, '显示在专辑商品前的介绍。' ), editorField( 'pc-col-footer-description', '专辑底部描述', collection.footer_description, '显示在专辑商品后的补充内容。' ), field( '商品选择方式', type ), el( 'div', { class: 'pc-col-rules', hidden: collection.type !== 'automatic' } ) ] ) ] );
        var favorite = el( 'input', { id: 'pc-col-favorite', type: 'checkbox', checked: !! collection.favorite, on: { change: markDirty } } );
        var side = el( 'aside', { class: 'pc-col-sidebar' }, [ el( 'section', { class: 'pc-col-panel' }, [ el( 'div', { class: 'pc-col-panel-heading' }, el( 'h2', {}, '基本属性' ) ), el( 'div', { class: 'pc-col-panel-body' }, [ el( 'label', { class: 'pc-col-toggle', for: favorite.id }, [ el( 'span', {}, '设为常用专辑' ), favorite ] ), el( 'h3', { class: 'pc-col-subheading' }, '专辑封面' ), coverControl() ] ) ] ), el( 'section', { class: 'pc-col-panel' }, [ el( 'div', { class: 'pc-col-panel-heading' }, el( 'h2', {}, '搜索引擎信息' ) ), el( 'div', { class: 'pc-col-panel-body' }, [ field( '页面标题', el( 'input', { id: 'pc-col-seo-title', type: 'text', maxlength: 250, value: collection.seo_title || '', on: { input: markDirty } } ) ), field( '页面描述', el( 'textarea', { id: 'pc-col-seo-description', rows: 4, maxlength: 500, on: { input: markDirty } }, collection.seo_description || '' ) ), collection.public_url ? el( 'p', { class: 'pc-col-url' }, collection.public_url ) : el( 'p', { class: 'pc-col-help' }, '保存后生成专辑地址。' ) ] ) ] ) ] );
        root.append( el( 'div', { class: 'pc-col-editor-layout' }, [ left, side ] ) );
        root.append( el( 'section', { class: 'pc-col-panel pc-col-products-panel', id: 'products' } ) );
        root.append( el( 'footer', { class: 'pc-col-savebar' }, [ el( 'span', { class: 'pc-col-save-state', role: 'status', 'aria-live': 'polite' }, state.dirty ? '有未保存的修改' : collection.id ? '所有修改已保存' : '新专辑尚未保存' ), el( 'div', { class: 'pc-col-inline-actions' }, [ button( '返回列表', null, function () { navigate( 'list' ); } ), button( collection.id ? '保存专辑' : '创建专辑', null, function () { action( saveCollection ); }, { primary: true, mutate: true } ) ] ) ] ) );
        renderRules(); renderProducts(); initEditors();
        if ( window.location.hash === '#products' ) { var products = root.querySelector( '#products' ); if ( products ) { products.scrollIntoView( { block: 'start' } ); } }
    }
    async function saveCollection() {
        var collection = state.collection, name = root.querySelector( '#pc-col-name' ).value.trim();
        if ( ! name ) { root.querySelector( '#pc-col-name' ).focus(); throw new Error( '请填写专辑名称。' ); }
        var saveVersion = state.editVersion;
        var payload = { id: collection.id, revision: collection.revision, name: name, slug: root.querySelector( '#pc-col-slug' ).value.trim(), description: editorValue( 'pc-col-description' ), footer_description: editorValue( 'pc-col-footer-description' ), thumbnail_id: collection.thumbnail_id, favorite: root.querySelector( '#pc-col-favorite' ).checked, type: root.querySelector( '#pc-col-type' ).value, sort: root.querySelector( '#pc-col-sort' ).value, rules: JSON.parse( JSON.stringify( collection.rules ) ), seo_title: root.querySelector( '#pc-col-seo-title' ).value, seo_description: root.querySelector( '#pc-col-seo-description' ).value, confirm_rule_replace: false };
        if ( payload.type === 'automatic' ) {
            var preview = await previewRules( payload.rules ), count = number( preview.count !== undefined ? preview.count : preview.total );
            if ( saveVersion !== state.editVersion ) { throw new Error( '预览期间专辑设置有变化，请重新预览并保存。' ); }
            if ( ! await window.VialCoveDialogs.confirm( '保存自动专辑后，将根据当前规则保留 ' + count + ' 个匹配商品，并移出不再匹配的商品。商品本身不会被删除。确认保存？' ) ) { return; }
            payload.confirm_rule_replace = true;
        }
        var result = await request( 'save', payload ), saved = normalizeCollection( result.collection || result );
        if ( saveVersion !== state.editVersion ) {
            state.collection.id = saved.id; state.collection.revision = saved.revision; state.collection.manual_order = saved.manual_order;
            window.history.replaceState( null, '', route( saved.id, window.location.hash.slice( 1 ) ) );
            notice( '本次提交已保存；保存期间的后续修改仍留在表单中，请再次保存。' );
            return;
        }
        state.collection = saved; state.dirty = false;
        window.history.replaceState( null, '', route( state.collection.id, window.location.hash.slice( 1 ) ) );
        renderEditor(); await loadProducts(); notice( '专辑已保存。' );
    }
    async function loadEditor( id ) {
        root.replaceChildren( heading( '商品专辑', [] ), loading( '正在读取专辑和商品…' ) );
        try { var result = await request( 'get', { id: number( id ) } ); state.collection = normalizeCollection( result.collection || result ); state.products = result.products && result.products.items || []; state.productTotal = number( result.products && result.products.total ); renderEditor(); if ( ! result.products ) { await loadProducts(); } }
        catch ( error ) { notice( error.message, true ); root.append( button( '返回专辑列表', 'back', function () { navigate( 'list' ); } ) ); }
    }
    function productToolbar() {
        var collection = state.collection;
        var mode = select( { current: '本专辑商品', available: '选择商品加入专辑' }, state.productMode, { 'aria-label': '商品范围', disabled: collection.type === 'automatic', on: { change: function ( event ) { state.productMode = event.target.value; state.productPage = 1; state.productSelected.clear(); loadProducts(); } } } );
        var search = el( 'input', { type: 'search', value: state.productSearch, placeholder: '搜索商品名称或 SKU', 'aria-label': '搜索商品名称或 SKU' } );
        var form = el( 'form', { class: 'pc-col-product-search', on: { submit: function ( event ) { event.preventDefault(); state.productSearch = search.value.trim(); state.productPage = 1; state.productSelected.clear(); loadProducts(); } } }, [ search, el( 'button', { type: 'submit', class: 'button', 'aria-label': '搜索商品' }, icon( 'search' ) ) ] );
        var layout = el( 'div', { class: 'pc-col-view-switch', role: 'group', 'aria-label': '商品展示方式' }, [ button( '列表展示', 'list', function () { state.productLayout = 'list'; renderProducts(); }, { iconOnly: true } ), button( '网格展示', 'grid', function () { state.productLayout = 'grid'; renderProducts(); }, { iconOnly: true } ) ] );
        Array.from( layout.children ).forEach( function ( child, index ) { child.setAttribute( 'aria-pressed', state.productLayout === ( index ? 'grid' : 'list' ) ? 'true' : 'false' ); } );
        return el( 'div', { class: 'pc-col-product-toolbar' }, [ mode, form, layout, collection.type !== 'automatic' ? button( state.productMode === 'available' ? '加入所选商品' : '移出所选商品', state.productMode === 'available' ? 'plus' : 'close', function () { changeMembership( state.productMode === 'available' ? 'add' : 'remove' ); }, { mutate: true } ) : null ] );
    }
    function orderControls( product ) {
        var collection = state.collection, editable = collection.sort === 'manual' && state.productMode === 'current' && ! state.productSearch, order = ( collection.manual_order || [] ).map( number ), index = order.indexOf( number( product.id ) );
        if ( ! editable ) { return null; }
        return el( 'div', { class: 'pc-col-order-controls' }, [ button( '将 ' + cleanText( product.name ) + ' 置顶', 'top', function () { moveProduct( product.id, 'top' ); }, { iconOnly: true, disabled: index <= 0, mutate: true } ), button( '将 ' + cleanText( product.name ) + ' 上移', 'up', function () { moveProduct( product.id, 'up' ); }, { iconOnly: true, disabled: index <= 0, mutate: true } ), button( '将 ' + cleanText( product.name ) + ' 下移', 'down', function () { moveProduct( product.id, 'down' ); }, { iconOnly: true, disabled: index < 0 || index >= order.length - 1, mutate: true } ) ] );
    }
    function renderProducts() {
        var panel = root.querySelector( '#products' ); if ( ! panel || ! state.collection ) { return; } var collection = state.collection;
        var sort = select( sortLabels, collection.sort, { id: 'pc-col-sort', on: { change: function ( event ) { collection.sort = event.target.value; markDirty(); } } } );
        var memberCount = collection.product_count !== undefined ? collection.product_count : collection.count !== undefined ? collection.count : state.productMode === 'current' ? state.productTotal : 0;
        panel.replaceChildren( el( 'div', { class: 'pc-col-panel-heading pc-col-products-heading' }, [ el( 'h2', {}, '专辑商品 (' + number( memberCount ) + ')' ), field( '专辑展示顺序', sort ) ] ) );
        if ( ! collection.id ) { panel.append( empty( '先保存专辑，再选择商品', '专辑创建后，可手动添加商品或设置自动选择条件。' ) ); return; }
        panel.append( el( 'div', { class: 'pc-col-product-body' }, [ el( 'p', { class: 'pc-col-help' }, collection.type === 'automatic' ? '商品由已保存的规则自动管理。更改规则请使用上方预览并保存。' : '只管理当前专辑的商品关联。移出专辑不会删除商品，也不会影响其他专辑。' ), productToolbar() ] ) );
        var body = panel.lastChild;
        if ( ! state.products.length ) { body.append( empty( state.productMode === 'available' ? '没有可添加的商品' : '本专辑暂无匹配商品', state.productMode === 'available' ? '调整搜索条件，或先在商品管理中添加商品。' : '切换到“选择商品加入专辑”，勾选商品后添加。' ) ); }
        else {
            var all = el( 'input', { type: 'checkbox', 'aria-label': '选择本页全部商品', checked: state.products.every( function ( product ) { return state.productSelected.has( number( product.id ) ); } ), disabled: collection.type === 'automatic', on: { change: function ( event ) { state.products.forEach( function ( product ) { toggleSelection( state.productSelected, product.id, event.target.checked ); } ); renderProducts(); } } } );
            var list = el( 'div', { class: 'pc-col-product-items is-' + state.productLayout }, state.products.map( function ( product ) {
                var price = el( 'span', { class: 'pc-col-product-price' } ); price.innerHTML = product.price_html || '';
                return el( 'article', { class: 'pc-col-product-item' }, [ el( 'input', { type: 'checkbox', 'aria-label': '选择商品 ' + cleanText( product.name ), checked: state.productSelected.has( number( product.id ) ), disabled: collection.type === 'automatic', on: { change: function ( event ) { toggleSelection( state.productSelected, product.id, event.target.checked ); var count = root.querySelector( '.pc-col-product-selection-count' ); if ( count ) { count.textContent = '已选 ' + state.productSelected.size + ' 个商品'; } } } } ), thumb( product.image || product.thumbnail_url, cleanText( product.name ) ), el( 'div', { class: 'pc-col-product-content' }, [ el( 'strong', {}, cleanText( product.name ) ), el( 'span', { class: 'pc-col-product-sku' }, 'SKU：' + ( product.sku || '未设置' ) ), el( 'span', { class: 'pc-col-chip' }, statusLabels[ product.status ] || product.status || '未知状态' ) ] ), price, orderControls( product ) ] );
            } ) );
            body.append( el( 'div', { class: 'pc-col-product-selection' }, [ el( 'label', {}, [ all, '选择本页' ] ), el( 'span', { class: 'pc-col-product-selection-count' }, '已选 ' + state.productSelected.size + ' 个商品' ) ] ), list );
        }
        body.append( pager( state.productTotal, state.productPage, state.productPerPage, function ( page ) { state.productPage = page; state.productSelected.clear(); loadProducts(); } ) );
    }
    async function loadProducts() {
        if ( ! state.collection || ! state.collection.id ) { return; } var serial = ++state.productRequest;
        try { var result = await request( 'products', { id: state.collection.id, search: state.productSearch, page: state.productPage, per_page: state.productPerPage, available: state.productMode === 'available' } ); if ( serial !== state.productRequest ) { return; } state.products = result.items || []; state.productTotal = number( result.total ); state.productPage = number( result.page ) || state.productPage; var lastPage = Math.max( 1, Math.ceil( state.productTotal / state.productPerPage ) ); if ( state.productPage > lastPage ) { state.productPage = lastPage; return loadProducts(); } renderProducts(); }
        catch ( error ) { notice( error.message, true ); }
    }
    async function refreshMembership() { var result = await request( 'get', { id: state.collection.id } ); var updated = normalizeCollection( result.collection || result ); state.collection.revision = updated.revision; state.collection.manual_order = updated.manual_order; state.collection.product_count = updated.product_count; state.collection.count = updated.count; state.productSelected.clear(); await loadProducts(); }
    function changeMembership( mode ) {
        var ids = Array.from( state.productSelected ); if ( ! ids.length ) { notice( '请先勾选商品。', true ); return; }
        if ( state.dirty ) { notice( '请先保存上方专辑设置，再调整商品。', true ); return; }
        action( async function () { await request( 'membership', { id: state.collection.id, revision: state.collection.revision, mode: mode, product_ids: ids } ); await refreshMembership(); notice( mode === 'add' ? '所选商品已加入专辑。' : '所选商品已从本专辑移出，商品本身已保留。' ); } );
    }
    function moveProduct( id, direction ) {
        if ( state.dirty ) { notice( '请先保存专辑设置，再调整商品顺序。', true ); return; }
        var order = ( state.collection.manual_order || [] ).map( number ), index = order.indexOf( number( id ) ); if ( index < 0 ) { return; }
        if ( direction === 'top' ) { order.splice( index, 1 ); order.unshift( number( id ) ); }
        else { var other = index + ( direction === 'up' ? -1 : 1 ); if ( other < 0 || other >= order.length ) { return; } var swap = order[ other ]; order[ other ] = order[ index ]; order[ index ] = swap; }
        action( async function () { await request( 'membership', { id: state.collection.id, revision: state.collection.revision, mode: 'order', product_ids: order } ); await refreshMembership(); notice( '已保存本专辑的商品展示顺序。其他专辑的顺序未改变。' ); } );
    }
    window.addEventListener( 'beforeunload', function ( event ) { if ( state.dirty ) { event.preventDefault(); event.returnValue = ''; } } );
    if ( String( config.collectionId ) === 'new' ) { state.collection = normalizeCollection(); renderEditor(); }
    else if ( number( config.collectionId ) > 0 ) { loadEditor( config.collectionId ); }
    else { loadList(); }
} )();
