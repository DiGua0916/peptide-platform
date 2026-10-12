/**
 * 后台确认框与提示框：用页面内对话框代替浏览器原生的 confirm / alert。
 *
 * 原生 confirm 是同步的，页面内对话框是异步的，因此采用“先拦截、确认后重放点击”的方式：
 * 1. 点击发生时（捕获阶段，早于 WordPress / WooCommerce 的处理函数），临时把 window.confirm
 *    换成“记录提示文字并返回 false”的函数，原有操作因此被取消；
 * 2. 本次点击处理完后（setTimeout 0）恢复原生 confirm；如果期间请求过确认，就弹出页面内对话框；
 * 3. 用户点“确定”后，在“放行”状态下重新点击同一个元素，此时 confirm 返回 true，原有操作照常执行。
 * 不是由点击触发的 confirm（极少见）仍使用浏览器原生对话框。
 */
( function () {
	'use strict';

	var i18n = window.pcAdminDialogs || {};
	var nativeConfirm = window.confirm;
	var bypass = false;
	var open = false;

	function buildDialog( message, withCancel, onOk ) {
		open = true;
		var previousFocus = document.activeElement;

		var overlay = document.createElement( 'div' );
		overlay.className = 'pc-dialog-overlay';

		var box = document.createElement( 'div' );
		box.className = 'pc-dialog';
		box.setAttribute( 'role', withCancel ? 'alertdialog' : 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-labelledby', 'pc-dialog-title' );
		box.setAttribute( 'aria-describedby', 'pc-dialog-message' );

		var title = document.createElement( 'h2' );
		title.id = 'pc-dialog-title';
		title.className = 'pc-dialog__title';
		title.textContent = withCancel ? ( i18n.confirmTitle || 'Please confirm' ) : ( i18n.alertTitle || 'Notice' );

		var text = document.createElement( 'p' );
		text.id = 'pc-dialog-message';
		text.className = 'pc-dialog__message';
		text.textContent = String( message == null ? '' : message );

		var actions = document.createElement( 'div' );
		actions.className = 'pc-dialog__actions';

		var cancelBtn = null;
		if ( withCancel ) {
			cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'button';
			cancelBtn.textContent = i18n.cancel || 'Cancel';
			actions.appendChild( cancelBtn );
		}
		var okBtn = document.createElement( 'button' );
		okBtn.type = 'button';
		okBtn.className = 'button button-primary';
		okBtn.textContent = i18n.ok || 'OK';
		actions.appendChild( okBtn );

		box.appendChild( title );
		box.appendChild( text );
		box.appendChild( actions );
		overlay.appendChild( box );
		document.body.appendChild( overlay );

		function close( confirmed ) {
			document.removeEventListener( 'keydown', onKey, true );
			overlay.remove();
			open = false;
			if ( previousFocus && previousFocus.focus ) {
				previousFocus.focus();
			}
			if ( confirmed && onOk ) {
				onOk();
			}
		}
		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				close( false );
			} else if ( 'Tab' === e.key ) {
				// 焦点限制在对话框内。
				var items = cancelBtn ? [ cancelBtn, okBtn ] : [ okBtn ];
				var idx = items.indexOf( document.activeElement );
				e.preventDefault();
				items[ ( idx + ( e.shiftKey ? items.length - 1 : 1 ) ) % items.length ].focus();
			}
		}

		okBtn.addEventListener( 'click', function () {
			close( true );
		} );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				close( false );
			} );
		}
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay && withCancel ) {
				close( false );
			}
		} );
		document.addEventListener( 'keydown', onKey, true );
		okBtn.focus();
	}

	// 1–3：拦截点击期间的 confirm。
	window.addEventListener(
		'click',
		function ( e ) {
			if ( bypass || open || ! e.isTrusted ) {
				return;
			}
			var target = e.target;
			var requested = null;
			window.confirm = function ( message ) {
				if ( null === requested ) {
					requested = message;
				}
				return false;
			};
			setTimeout( function () {
				window.confirm = nativeConfirm;
				if ( null === requested ) {
					return;
				}
				buildDialog( requested, true, function () {
					bypass = true;
					window.confirm = function () {
						return true;
					};
					try {
						if ( target && target.isConnected ) {
							target.click();
						}
					} finally {
						window.confirm = nativeConfirm;
						bypass = false;
					}
				} );
			}, 0 );
		},
		true
	);

	// alert 不需要返回值，直接换成页面内提示框。
	window.alert = function ( message ) {
		buildDialog( message, false, null );
	};
} )();
