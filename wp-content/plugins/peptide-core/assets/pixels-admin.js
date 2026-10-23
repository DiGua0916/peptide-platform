/**
 * 系统设置 → 广告像素（PIXELS-01）：新建 / 编辑 / 删除像素行（弹窗）、统计设置弹窗。
 * 像素行的值放在隐藏字段里，保存时随表单提交；token 只在重新填写时提交，页面上只显示前几位。
 */
( function ( d, cfg ) {
	'use strict';

	var form = d.querySelector( '.pc-px-form' );
	if ( ! form || ! cfg ) {
		return;
	}
	var S = cfg.strings;
	var dlg = form.querySelector( '.pc-px-dialog' );
	var settings = form.querySelector( '.pc-px-settings' );
	var editing = null; // { card, row }

	function field( el, f ) {
		return el.querySelector( '[data-f="' + f + '"]' );
	}
	function show( row, f, text ) {
		var cell = row.querySelector( '[data-show-f="' + f + '"]' );
		if ( cell ) {
			cell.textContent = text;
		}
	}
	function renumber( card ) {
		var key = card.getAttribute( 'data-platform' );
		card.querySelectorAll( '.pc-px-row' ).forEach( function ( row, i ) {
			row.querySelectorAll( 'input[name]' ).forEach( function ( input ) {
				input.name = input.name.replace( /^platforms\[[a-z0-9_]+\]\[pixels\]\[\d+\]/, 'platforms[' + key + '][pixels][' + i + ']' );
			} );
		} );
		var empty = card.querySelector( '.pc-px-empty' );
		if ( empty ) {
			empty.hidden = card.querySelectorAll( '.pc-px-row' ).length > 0;
		}
	}
	function openDialog( card, row ) {
		editing = { card: card, row: row };
		var hasToken = !! card.querySelector( 'template' ) && !! card.querySelector( 'template' ).content.querySelector( '[data-f="token"]' );
		dlg.querySelector( '.pc-px-dialog__token' ).hidden = ! hasToken;
		dlg.querySelector( '.pc-px-dialog__title' ).textContent = card.querySelector( 'h2' ).textContent;
		[ 'id', 'note', 'domain' ].forEach( function ( f ) {
			dlg.querySelector( '[data-d="' + f + '"]' ).value = row ? field( row, f ).value : '';
		} );
		dlg.querySelector( '[data-d="token"]' ).value = '';
		dlg.querySelector( '[data-d="clear_token"]' ).checked = false;
		var had = row && row.querySelector( '[data-show-f="token"]' ) && '1' === row.querySelector( '[data-show-f="token"]' ).getAttribute( 'data-had' );
		dlg.querySelector( '.pc-px-dialog__keep' ).hidden = ! had;
		dlg.querySelector( '.pc-pl-error' ).hidden = true;
		dlg.showModal();
		dlg.querySelector( '[data-d="id"]' ).focus();
	}

	form.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( 'button' );
		if ( ! t ) {
			return;
		}
		var card = t.closest( '.pc-px-card' );
		if ( t.classList.contains( 'pc-px-add' ) ) {
			openDialog( card, null );
		} else if ( t.classList.contains( 'pc-px-edit' ) ) {
			openDialog( card, t.closest( 'tr' ) );
		} else if ( t.classList.contains( 'pc-px-del' ) ) {
			if ( window.confirm( S.confirmDel ) ) {
				t.closest( 'tr' ).remove();
				renumber( card );
			}
		} else if ( t.classList.contains( 'pc-px-settings-open' ) ) {
			settings.showModal();
		} else if ( t.classList.contains( 'pc-pl-close' ) ) {
			var open = t.closest( 'dialog' );
			if ( open ) {
				open.close();
			}
		} else if ( t.classList.contains( 'pc-px-dialog__ok' ) ) {
			var id = dlg.querySelector( '[data-d="id"]' ).value.trim();
			var err = dlg.querySelector( '.pc-pl-error' );
			if ( ! id ) {
				err.textContent = S.needId;
				err.hidden = false;
				return;
			}
			var dup = Array.prototype.some.call( editing.card.querySelectorAll( '.pc-px-row' ), function ( r ) {
				return r !== editing.row && field( r, 'id' ).value.toLowerCase() === id.toLowerCase();
			} );
			if ( dup ) {
				err.textContent = S.duplicate;
				err.hidden = false;
				return;
			}
			var row = editing.row;
			if ( ! row ) {
				var tpl = editing.card.querySelector( 'template' );
				row = tpl.content.querySelector( 'tr' ).cloneNode( true );
				editing.card.querySelector( '.pc-px-table tbody' ).insertBefore( row, editing.card.querySelector( '.pc-px-empty' ) );
			}
			var note = dlg.querySelector( '[data-d="note"]' ).value.trim();
			var domain = dlg.querySelector( '[data-d="domain"]' ).value.trim();
			field( row, 'id' ).value = id;
			field( row, 'note' ).value = note;
			field( row, 'domain' ).value = domain;
			show( row, 'id', id );
			show( row, 'note', note || S.none );
			show( row, 'domain', domain || S.none );
			var tok = field( row, 'token' );
			if ( tok ) {
				var nv = dlg.querySelector( '[data-d="token"]' ).value.trim();
				var clear = dlg.querySelector( '[data-d="clear_token"]' ).checked;
				tok.value = nv;
				field( row, 'clear_token' ).value = clear ? '1' : '';
				var cell = row.querySelector( '[data-show-f="token"]' );
				if ( nv ) {
					cell.textContent = nv.slice( 0, 6 ) + '…••••••';
				} else if ( clear ) {
					cell.textContent = S.none;
				}
			}
			renumber( editing.card );
			dlg.close();
		}
	} );
}( document, window.pcPixelsAdmin ) );
