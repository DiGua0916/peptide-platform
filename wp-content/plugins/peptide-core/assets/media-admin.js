/**
 * 素材管理：复制地址、批量删除前确认、只在“更改分组”时显示分组下拉框。
 */
( function () {
	'use strict';

	var cfg = window.pcMediaAdmin || {};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.pc-media-copy' );
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' ) || '';
		var done = function () {
			var old = btn.textContent;
			btn.textContent = cfg.copied || old;
			setTimeout( function () {
				btn.textContent = old;
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var input = btn.parentNode.querySelector( 'input' );
			input.select();
			document.execCommand( 'copy' );
			done();
		}
	} );

	var list = document.querySelector( '.pc-media-list' );
	if ( list ) {
		var op = list.querySelector( '[name="op"]' );
		var target = list.querySelector( '[name="target"]' );
		var sync = function () {
			target.hidden = 'move' !== op.value;
		};
		op.addEventListener( 'change', sync );
		sync();
		list.addEventListener( 'submit', function ( e ) {
			if ( 'delete' === op.value && ! window.confirm( cfg.confirm ) ) {
				e.preventDefault();
			}
		} );
	}
}() );
