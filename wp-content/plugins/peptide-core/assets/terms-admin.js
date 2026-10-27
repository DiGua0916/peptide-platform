/**
 * 系统设置 → 交易条款（TERMS-01）编辑。
 * 页面按 .pc-tm-data 里的设置生成方案和条款；提交时从页面上按显示顺序读回，整份设置写进隐藏字段 data（服务端再清理）。
 * 条款内容是可编辑区域，工具栏有加粗、斜体、链接、代码和文字颜色。
 */
( function ( $, cfg ) {
	'use strict';

	var $form = $( '.pc-tm-form' );
	if ( ! cfg || ! $form.length ) {
		return;
	}
	var S = cfg.strings;
	var data = {};
	try {
		data = JSON.parse( $form.find( '.pc-tm-data' ).text() || '{}' );
	} catch ( e ) {
		data = {};
	}

	function fmt( tpl, v ) {
		return String( tpl ).replace( /%[sd]/, v );
	}
	function counter( $input, max ) {
		$input.siblings( '.pc-tm-count' ).text( String( $input.val() || '' ).length + '/' + max );
	}
	function countedInput( cls, value, max, holder ) {
		var $wrap = $( '<span class="pc-tm-count-wrap"></span>' );
		var $in = $( '<input type="text" class="large-text">' ).addClass( cls ).attr( { maxlength: max, placeholder: holder || '' } ).val( value || '' );
		$wrap.append( $in, '<span class="pc-tm-count"></span>' );
		counter( $in, max );
		$in.on( 'input', function () {
			counter( $in, max );
		} );
		return $wrap;
	}

	function termBox( t ) {
		var $t = $( '<div class="pc-tm-term"></div>' );
		var $head = $( '<div class="pc-tm-term__head"><span class="dashicons dashicons-menu pc-tm-handle" aria-hidden="true"></span><strong class="pc-tm-term__label"></strong></div>' );
		$head.append( $( '<button type="button" class="button-link pc-tm-term-del"><span class="dashicons dashicons-trash"></span></button>' ).attr( 'aria-label', S.remove ) );
		var $tools = $( '<div class="pc-tm-tools"></div>' );
		[ [ 'bold', 'editor-bold' ], [ 'italic', 'editor-italic' ], [ 'link', 'admin-links' ], [ 'code', 'editor-code' ] ].forEach( function ( b ) {
			$tools.append( $( '<button type="button" class="button-link"></button>' ).attr( { 'data-cmd': b[ 0 ], title: S[ b[ 0 ] ], 'aria-label': S[ b[ 0 ] ] } ).append( '<span class="dashicons dashicons-' + b[ 1 ] + '"></span>' ) );
		} );
		$tools.append( $( '<label class="pc-tm-color"><span class="dashicons dashicons-editor-textcolor"></span><input type="color" value="#d63638"></label>' ).attr( 'title', S.color ) );
		var $editor = $( '<div class="pc-tm-editor" contenteditable="true" role="textbox" aria-multiline="true"></div>' ).html( t.content || '' );
		$t.append(
			$head,
			$( '<div class="pc-tm-term__field"></div>' ).append( $( '<label></label>' ).text( S.name ), countedInput( 'pc-tm-name', t.name, cfg.max.name, S.nameHolder ) ),
			$( '<div class="pc-tm-term__field"></div>' ).append( $( '<label></label>' ).text( S.content ), $( '<div class="pc-tm-editor-wrap"></div>' ).append( $tools, $editor ) )
		);
		return $t;
	}

	function planBox( p ) {
		var $p = $( '<div class="pc-tm-plan"></div>' ).attr( 'data-id', p.id || '' );
		var $row = $( '<div class="pc-tm-plan__row"></div>' );
		$row.append( $( '<button type="button" class="button-link pc-tm-toggle" aria-expanded="true"><span class="dashicons dashicons-arrow-up"></span> <span class="pc-tm-plan__label"></span></button>' ).attr( 'title', S.toggle ) );
		$row.append( countedInput( 'pc-tm-desc', p.description, cfg.max.desc, S.descHolder ) );
		var $pages = $( '<span class="pc-tm-pages"></span>' );
		[ 'checkout', 'register', 'login' ].forEach( function ( k ) {
			$pages.append( $( '<label></label>' ).append( $( '<input type="checkbox" class="pc-tm-page">' ).val( k ).prop( 'checked', ( p.pages || [ 'checkout' ] ).indexOf( k ) > -1 ), ' ' + S[ k ] ) );
		} );
		$row.append( $pages );
		$row.append( $( '<span class="pc-tm-actions"></span>' ).append( $( '<label></label>' ).append( $( '<input type="checkbox" class="pc-tm-checked">' ).prop( 'checked', !! p.checked ), ' ' + S.checked ), $( '<button type="button" class="button-link pc-tm-plan-del"></button>' ).text( S.remove ) ) );
		var $terms = $( '<div class="pc-tm-terms"></div>' );
		( p.terms && p.terms.length ? p.terms : [ { name: '', content: '' } ] ).forEach( function ( t ) {
			$terms.append( termBox( t ) );
		} );
		$terms.sortable( { handle: '.pc-tm-handle', axis: 'y', update: renumber } );
		$p.append( $row, $( '<div class="pc-tm-plan__body"></div>' ).append( $terms, $( '<button type="button" class="button pc-tm-add-term"></button>' ).text( S.addTerm ) ) );
		return $p;
	}

	function renumber() {
		$form.find( '.pc-tm-plan' ).each( function ( i ) {
			$( this ).find( '.pc-tm-plan__label' ).text( fmt( S.plan, i + 1 ) );
			$( this ).find( '.pc-tm-term' ).each( function ( j ) {
				$( this ).find( '.pc-tm-term__label' ).text( fmt( S.term, j + 1 ) );
			} );
		} );
	}

	var $plans = $form.find( '.pc-tm-plans' );
	( data.plans && data.plans.length ? data.plans : [ { pages: [ 'checkout' ], terms: [] } ] ).forEach( function ( p ) {
		$plans.append( planBox( p ) );
	} );
	renumber();
	counter( $form.find( '.pc-tm-title' ), cfg.max.title );
	$form.find( '.pc-tm-title' ).on( 'input', function () {
		counter( $( this ), cfg.max.title );
	} );

	$form.on( 'click', '.pc-tm-add-plan', function () {
		$plans.append( planBox( { pages: [ 'checkout' ], terms: [] } ) );
		renumber();
	} );
	$form.on( 'click', '.pc-tm-plan-del', function () {
		if ( window.confirm( S.confirmDel ) ) {
			$( this ).closest( '.pc-tm-plan' ).remove();
			renumber();
		}
	} );
	$form.on( 'click', '.pc-tm-add-term', function () {
		$( this ).siblings( '.pc-tm-terms' ).append( termBox( { name: '', content: '' } ) );
		renumber();
	} );
	$form.on( 'click', '.pc-tm-term-del', function () {
		$( this ).closest( '.pc-tm-term' ).remove();
		renumber();
	} );
	$form.on( 'click', '.pc-tm-toggle', function () {
		var $b = $( this );
		var open = 'true' !== $b.attr( 'aria-expanded' );
		$b.attr( 'aria-expanded', open ? 'true' : 'false' ).find( '.dashicons' ).toggleClass( 'dashicons-arrow-up', open ).toggleClass( 'dashicons-arrow-down', ! open );
		$b.closest( '.pc-tm-plan' ).find( '.pc-tm-plan__body' ).prop( 'hidden', ! open );
	} );

	// 工具栏：作用于同一个条款的编辑区。
	$form.on( 'mousedown', '.pc-tm-tools button', function ( e ) {
		e.preventDefault(); // 保留编辑区里的选区。
	} );
	$form.on( 'click', '.pc-tm-tools button', function () {
		var cmd = $( this ).data( 'cmd' );
		$( this ).closest( '.pc-tm-editor-wrap' ).find( '.pc-tm-editor' ).trigger( 'focus' );
		if ( 'link' === cmd ) {
			var url = window.prompt( S.linkPrompt, 'https://' );
			if ( url && /^https?:\/\//i.test( url ) ) {
				document.execCommand( 'createLink', false, url );
			}
		} else if ( 'code' === cmd ) {
			var text = String( window.getSelection() || '' );
			if ( text ) {
				var code = document.createElement( 'code' );
				code.textContent = text;
				document.execCommand( 'insertHTML', false, code.outerHTML );
			}
		} else {
			document.execCommand( cmd );
		}
	} );
	$form.on( 'change', '.pc-tm-color input', function () {
		$( this ).closest( '.pc-tm-editor-wrap' ).find( '.pc-tm-editor' ).trigger( 'focus' );
		document.execCommand( 'foreColor', false, this.value );
	} );

	// 效果预览、清空。
	$( document ).on( 'click', '.pc-tm-preview-open', function () {
		var d = $( '.pc-tm-preview' )[ 0 ];
		if ( d && d.showModal ) {
			d.showModal();
		}
	} );
	$( document ).on( 'click', '.pc-tm-preview .pc-pl-close', function () {
		$( this ).closest( 'dialog' )[ 0 ].close();
	} );
	$( document ).on( 'click', '.pc-tm-reset', function ( e ) {
		if ( ! window.confirm( this.textContent.trim() + '?' ) ) {
			e.preventDefault();
		}
	} );

	$form.on( 'submit', function ( e ) {
		var plans = [];
		var problem = '';
		$form.find( '.pc-tm-plan' ).each( function () {
			var $p = $( this );
			var terms = $p.find( '.pc-tm-term' ).map( function () {
				return { name: String( $( this ).find( '.pc-tm-name' ).val() || '' ), content: $( this ).find( '.pc-tm-editor' ).html() };
			} ).get();
			var pages = $p.find( '.pc-tm-page:checked' ).map( function () {
				return this.value;
			} ).get();
			var desc = String( $p.find( '.pc-tm-desc' ).val() || '' ).trim();
			var empty = ! desc && ! terms.some( function ( t ) {
				return t.name || $( '<div>' ).html( t.content ).text().trim();
			} );
			if ( empty ) {
				return;
			}
			if ( ! desc ) {
				problem = S.needDesc;
			} else if ( ! pages.length ) {
				problem = S.needPage;
			}
			plans.push( { id: Number( $p.attr( 'data-id' ) ) || 0, description: desc, pages: pages, checked: $p.find( '.pc-tm-checked' ).is( ':checked' ), terms: terms } );
		} );
		if ( problem ) {
			e.preventDefault();
			window.alert( problem );
			return;
		}
		$form.find( 'input[name="data"]' ).val( JSON.stringify( { enabled: $form.find( '.pc-tm-enabled' ).is( ':checked' ), title: String( $form.find( '.pc-tm-title' ).val() || '' ), plans: plans } ) );
	} );
} )( window.jQuery, window.pcTermsAdmin );
