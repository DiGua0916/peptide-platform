/* 店铺后台 → 邮件：收件人搜索、模板套用、查看正文（2026-11-09） */
( function () {
	var cfg = window.pcMail || {};
	function esc( s ) { return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }

	// ---- 收件人搜索 ----
	var to = document.getElementById( 'pc-mail-to' ), box = document.getElementById( 'pc-mail-suggest' ), orderField = document.getElementById( 'pc-mail-order' );
	if ( to && box ) {
		var timer = 0, active = -1, items = [];
		function term() { var parts = to.value.split( /[,;，；]/ ); return parts[ parts.length - 1 ].trim(); }
		function apply( item ) {
			var parts = to.value.split( /[,;，；]/ ); parts.pop(); parts.push( item.email );
			to.value = parts.map( function ( p ) { return p.trim(); } ).filter( Boolean ).join( ', ' );
			if ( item.order_id && orderField && ! orderField.value ) { orderField.value = item.order_id; }
			box.hidden = true; items = []; active = -1; to.focus();
		}
		function paint() {
			if ( ! items.length ) { box.hidden = true; box.innerHTML = ''; return; }
			box.innerHTML = items.map( function ( it, i ) { return '<button type="button" data-i="' + i + '" class="' + ( i === active ? 'is-active' : '' ) + '"><b>' + esc( it.email ) + '</b>' + ( it.name ? ' ' + esc( it.name ) : '' ) + '<span>' + esc( it.note || '' ) + '</span></button>'; } ).join( '' );
			box.hidden = false;
		}
		to.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = term();
			if ( q.length < 2 ) { items = []; paint(); return; }
			timer = setTimeout( function () {
				fetch( cfg.ajax + '?action=pc_mail_search&nonce=' + encodeURIComponent( cfg.nonce ) + '&q=' + encodeURIComponent( q ), { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) { items = ( j && j.success && Array.isArray( j.data ) ) ? j.data : []; active = -1; paint(); } )
					.catch( function () { items = []; paint(); } );
			}, 220 );
		} );
		to.addEventListener( 'keydown', function ( e ) {
			if ( box.hidden ) { return; }
			if ( e.key === 'ArrowDown' ) { active = Math.min( items.length - 1, active + 1 ); paint(); e.preventDefault(); }
			else if ( e.key === 'ArrowUp' ) { active = Math.max( 0, active - 1 ); paint(); e.preventDefault(); }
			else if ( e.key === 'Enter' && active >= 0 ) { apply( items[ active ] ); e.preventDefault(); }
			else if ( e.key === 'Escape' ) { box.hidden = true; }
		} );
		box.addEventListener( 'mousedown', function ( e ) { var b = e.target.closest( 'button[data-i]' ); if ( b ) { apply( items[ Number( b.dataset.i ) ] ); e.preventDefault(); } } );
		document.addEventListener( 'click', function ( e ) { if ( ! box.contains( e.target ) && e.target !== to ) { box.hidden = true; } } );
	}

	// ---- 模板套用 ----
	var sel = document.getElementById( 'pc-mail-template' ), subject = document.getElementById( 'pc-mail-subject' );
	if ( sel ) {
		sel.addEventListener( 'change', function () {
			var t = ( cfg.templates || [] ).filter( function ( x ) { return x.id === sel.value; } )[ 0 ];
			if ( ! t ) { return; }
			if ( subject && ( ! subject.value || confirm( '用模板的主题覆盖现在的主题？' ) ) ) { subject.value = t.subject || ''; }
			var ed = window.tinymce && window.tinymce.get( 'pc-mail-body' );
			var ta = document.getElementById( 'pc-mail-body' );
			var current = ed ? ed.getContent() : ( ta ? ta.value : '' );
			if ( current.replace( /<[^>]+>/g, '' ).trim() && ! confirm( '用模板正文替换现在写的内容？' ) ) { return; }
			if ( ed ) { ed.setContent( t.body || '' ); } else if ( ta ) { ta.value = t.body || ''; }
		} );
	}

	// ---- 查看正文 ----
	var viewer = document.getElementById( 'pc-mail-viewer' );
	if ( viewer ) {
		var frame = document.getElementById( 'pc-mail-viewer-frame' ), vs = document.getElementById( 'pc-mail-viewer-subject' ), vm = document.getElementById( 'pc-mail-viewer-meta' );
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-mail-view]' );
			if ( ! b ) { return; }
			vs.textContent = '加载中…'; vm.textContent = ''; frame.srcdoc = ''; viewer.hidden = false;
			fetch( cfg.ajax + '?action=pc_mail_body&nonce=' + encodeURIComponent( cfg.nonce ) + '&id=' + encodeURIComponent( b.dataset.mailView ), { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( ! j || ! j.success ) { vs.textContent = '读取失败'; return; }
					var d = j.data;
					vs.textContent = d.subject || '（无主题）';
					vm.textContent = d.time + ' · ' + d.kind + ' · 收件人 ' + d.recipient + ' · ' + ( d.status === 'sent' ? '已发出' : ( d.status === 'failed' ? '失败：' + ( d.error || '' ) : '发送中' ) );
					frame.srcdoc = d.body;
				} )
				.catch( function () { vs.textContent = '读取失败'; } );
		} );
		document.getElementById( 'pc-mail-viewer-close' ).addEventListener( 'click', function () { viewer.hidden = true; } );
		viewer.addEventListener( 'click', function ( e ) { if ( e.target === viewer ) { viewer.hidden = true; } } );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { viewer.hidden = true; } } );
	}
} )();
