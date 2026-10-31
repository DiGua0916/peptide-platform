/**
 * VialCove 装修工作台：真实预览、积木导航与属性编辑。
 *
 * 数据：window.pcDesignEditor（文档、接口路径、积木库、文案）；window.pcModules / window.pcModuleUI（字段定义与控件）。
 * 保存：POST /peptide/v1/layouts/{id}，带上打开时的修改时间，别人改过时返回 409。
 * 预览：修改后约 0.5 秒生成短期预览地址，加载成功后替换画布并保留滚动位置。
 */
( function ( wp, cfg, data, ui ) {
	'use strict';

	if ( ! wp || ! cfg || ! data || ! ui ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useCallback = wp.element.useCallback;
	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var S = cfg.strings;

	var HISTORY_LIMIT = 60;
	var COALESCE_MS = 800;

	function defOf( m ) {
		return data.modules[ m.block ];
	}

	function titleOf( m ) {
		var def = defOf( m );
		var s = ( m.attrs && m.attrs.settings ) || {};
		var custom = s.title || s.leftTitle;
		return ( def ? def.title : m.block ) + ( custom ? ' · ' + custom : '' );
	}

	function newModule( block ) {
		return { block: block, attrs: ui.initialAttributes( data.modules[ block ] ) };
	}

	function copyModule( m ) {
		var c = ui.clone( m );
		var map = {};
		function renew( list ) {
			( list || [] ).forEach( function ( it ) {
				map[ it.id ] = map[ it.id ] || ui.uid();
				it.id = map[ it.id ];
				[ 'spots', 'highlights', 'facts', 'products' ].forEach( function ( key ) {
					( ( it.settings && it.settings[ key ] ) || [] ).forEach( function ( sub ) {
						map[ sub.id ] = map[ sub.id ] || ui.uid();
						sub.id = map[ sub.id ];
					} );
				} );
			} );
		}
		c.attrs.moduleId = ui.uid();
		renew( c.attrs.items );
		// 手机版条目和电脑版用同一套编号，复制后仍然一一对应。
		if ( c.attrs.mobile && c.attrs.mobile.items ) {
			renew( c.attrs.mobile.items );
		}
		return c;
	}

	function mobileOf( m ) {
		var mob = ( m.attrs && m.attrs.mobile ) || {};
		return { settings: mob.settings || {}, items: Array.isArray( mob.items ) ? mob.items : null };
	}

	function mobileCount( m ) {
		var mob = mobileOf( m );
		return Object.keys( mob.settings ).length + ( mob.items ? 1 : 0 );
	}

	function isTyping( target ) {
		return target && ( /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) || target.isContentEditable );
	}

	function Icon( props ) {
		var icons = window.PeptideDesignIcons;
		var options = { size: props.size || 18, className: 'pc-de-icon' + ( props.className ? ' ' + props.className : '' ), createElement: el };
		if ( icons ) {
			return props.block ? icons.render( props.block, options ) : icons.renderAction( props.name, options );
		}
		// Keep controls usable if an asset was interrupted; never depend on an icon font.
		return el( 'svg', { viewBox: '0 0 24 24', width: options.size, height: options.size, className: options.className, fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, 'aria-hidden': true, focusable: false }, el( 'rect', { x: 4, y: 4, width: 16, height: 16, rx: 2 } ) );
	}

	function App() {
		var modulesState = useState( cfg.document.modules || [] );
		var modules = modulesState[ 0 ];
		var setModulesRaw = modulesState[ 1 ];
		var latestModulesRef = useRef( modules );
		latestModulesRef.current = modules;
		var selectedState = useState( /^(header|footer|global)$/.test( cfg.document.document ) && cfg.document.modules.length ? cfg.document.modules[ 0 ].attrs.moduleId : null );
		var selected = selectedState[ 0 ];
		var setSelected = selectedState[ 1 ];
		var tabState = useState( 'modules' );
		var tab = tabState[ 0 ];
		var deviceState = useState( 'desktop' );
		var device = deviceState[ 0 ];
		var dirtyState = useState( false );
		var dirty = dirtyState[ 0 ];
		var leaveState = useState( null );
		var leaveRef = useRef( { approved: false, started: false, timer: null } );
		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var noticeState = useState(
			cfg.document.lockedBy ? { type: 'warning', text: S.lockedBy.replace( '%s', cfg.document.lockedBy ) } : null
		);
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];
		var modifiedRef = useRef( cfg.document.modified );
		var revisionRef = useRef( cfg.document.revision );
		var statusState = useState( cfg.document.status || { published: false, changed: true } );
		var status = statusState[ 0 ];
		var menuState = useState( false );
		var menuOpen = menuState[ 0 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		// Two native-size frames keep the last successful preview visible while the next loads.
		var frameEntriesState = useState( [ { url: cfg.document.previewBase, request: 0 }, null ] );
		var frameEntries = frameEntriesState[ 0 ];
		var activeFrameState = useState( 0 );
		var activeFrame = activeFrameState[ 0 ];
		var activeFrameRef = useRef( 0 );
		var activeFrameRequestRef = useRef( 0 );
		var framesRef = useRef( [ null, null ] );
		var previewRequestRef = useRef( 0 );
		var pendingFrameRef = useRef( null );
		var previewTimeoutRef = useRef( null );
		var initialFrameReadyRef = useRef( false );
		var previewPhaseState = useState( 'loading' );
		var previewPhase = previewPhaseState[ 0 ];
		var loading = previewPhase === 'loading' || previewPhase === 'requesting';
		var previewErrorState = useState( null );
		var previewError = previewErrorState[ 0 ];
		var previewRetryState = useState( 0 );
		var refreshMetadataPreview = useCallback( function () {
			previewRetryState[ 1 ]( function ( count ) { return count + 1; } );
		}, [] );
		var paneState = useState( 'preview' );
		var workspaceRef = useRef( null );
		var narrowState = useState( false );
		var libraryQueryState = useState( '' );
		var libraryQuery = libraryQueryState[ 0 ];
		var dragState = useState( { from: null, over: null } );
		var drag = dragState[ 0 ];
		var setDrag = dragState[ 1 ];
		var historyRef = useRef( { past: [], future: [], last: 0 } );
		var frameRef = useRef( null );
		var scrollRef = useRef( 0 );
		var fromPreviewRef = useRef( false );
		// 设置面板里展开的条目（例如第 2 张轮播），预览里会高亮并切换到它。
		var openItemState = useState( null );
		var openItem = openItemState[ 0 ];
		var pendingItemRef = useRef( null );
		var hlTargetRef = useRef( null );
		var dragJustEndedRef = useRef( false );
		var commitDragRef = useRef( function () {} );
		var firstRef = useRef( true );
		var historyTick = useState( 0 );

		// Back uses a page-level choice; closing/reloading still uses beforeunload.
		function resetLeave() {
			clearTimeout( leaveRef.current.timer );
			leaveRef.current = { approved: false, started: false, timer: null };
		}
		function confirmLeave() {
			if ( ! leaveState[ 0 ] || leaveRef.current.started ) { return; }
			var url = leaveState[ 0 ];
			leaveRef.current.started = true;
			leaveRef.current.approved = true;
			leaveState[ 1 ]( null );
			// Authorization covers one immediate attempt, never a later close/refresh.
			leaveRef.current.timer = setTimeout( resetLeave, 1000 );
			try { window.location.assign( url ); }
			catch ( error ) { resetLeave(); setNotice( { type: 'error', text: S.leaveFailed || '无法返回，请继续编辑或重试。' } ); }
		}
		useEffect( function () { return resetLeave; }, [] );

		function openDocument( key ) {
			var url = cfg.documentLinks && cfg.documentLinks[ key ];
			if ( ! url ) { return; }
			if ( cfg.document.document === key ) {
				tabState[ 1 ]( 'modules' );
				if ( modules.length ) { setSelected( modules[ 0 ].attrs.moduleId ); }
				return;
			}
			window.location.assign( url );
		}

		function setModules( next, coalesce ) {
			var h = historyRef.current;
			var now = Date.now();
			if ( ! coalesce || now - h.last > COALESCE_MS ) {
				h.past.push( modules );
				if ( h.past.length > HISTORY_LIMIT ) {
					h.past.shift();
				}
			}
			h.last = now;
			h.future = [];
			setModulesRaw( next );
			dirtyState[ 1 ]( true );
			historyTick[ 1 ]( now );
		}

		function undo() {
			var h = historyRef.current;
			if ( ! h.past.length ) {
				return;
			}
			h.future.push( modules );
			setModulesRaw( h.past.pop() );
			h.last = 0;
			dirtyState[ 1 ]( true );
			historyTick[ 1 ]( Date.now() );
		}

		function redo() {
			var h = historyRef.current;
			if ( ! h.future.length ) {
				return;
			}
			h.past.push( modules );
			setModulesRaw( h.future.pop() );
			h.last = 0;
			dirtyState[ 1 ]( true );
			historyTick[ 1 ]( Date.now() );
		}

		function indexOf( id ) {
			for ( var i = 0; i < modules.length; i++ ) {
				if ( modules[ i ].attrs.moduleId === id ) {
					return i;
				}
			}
			return -1;
		}

		function updateModule( id, patch, coalesce ) {
			setModules(
				modules.map( function ( m ) {
					return m.attrs.moduleId === id ? { block: m.block, attrs: Object.assign( {}, m.attrs, patch ) } : m;
				} ),
				coalesce
			);
		}

		function insert( block ) {
			if ( data.modules[ block ] && data.modules[ block ].multiple === false && modules.some( function ( m ) { return m.block === block; } ) ) {
				setNotice( { type: 'warning', text: S.singleton } ); return;
			}
			var m = newModule( block );
			var at = indexOf( selected );
			var list = modules.slice();
			list.splice( at === -1 ? list.length : at + 1, 0, m );
			setModules( list );
			setSelected( m.attrs.moduleId );
			paneState[ 1 ]( 'settings' );
		}

		function duplicate( index ) {
			if ( defOf( modules[ index ] ) && defOf( modules[ index ] ).multiple === false ) { setNotice( { type: 'warning', text: S.singleton } ); return; }
			var c = copyModule( modules[ index ] );
			var list = modules.slice();
			list.splice( index + 1, 0, c );
			setModules( list );
			setSelected( c.attrs.moduleId );
		}

		function toggle( index ) {
			var m = modules[ index ];
			updateModule( m.attrs.moduleId, { enabled: m.attrs.enabled === false } );
		}

		function remove( index ) {
			// 后台已用页面内对话框替换原生确认框（admin-dialogs.js）。
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.confirmDelete ) ) {
				return;
			}
			var list = modules.slice();
			var removed = list.splice( index, 1 )[ 0 ];
			setModules( list );
			if ( removed && selected === removed.attrs.moduleId ) {
				setSelected( null );
			}
		}

		function move( from, to ) {
			if ( from === null || to === null || from === to || from < 0 || to < 0 || from >= modules.length || to >= modules.length ) {
				return;
			}
			var list = modules.slice();
			var item = list.splice( from, 1 )[ 0 ];
			list.splice( to, 0, item );
			setModules( list );
		}

		function showError( err ) {
			setNotice( {
				type: 'error',
				text: ( err && err.message ) || 'Error',
				reload: err && err.code === 'pc_layout_conflict',
			} );
		}

		// 保存草稿；返回 Promise，发布前会先调用。
		function save( quiet ) {
			if ( saving ) {
				return Promise.reject( { message: S.saving } );
			}
			savingState[ 1 ]( true );
			var savedModules = modules;
			return apiFetch( { path: cfg.restPath, method: 'POST', data: { modules: modules, modified: modifiedRef.current, revision: revisionRef.current } } )
				.then( function ( doc ) {
					modifiedRef.current = doc.modified;
					revisionRef.current = doc.revision;
					statusState[ 1 ]( doc.status );
					// Typing while a save is pending must not mark those newer edits as saved.
					dirtyState[ 1 ]( latestModulesRef.current !== savedModules );
					if ( ! quiet ) {
						setNotice( { type: 'success', text: S.saved } );
					}
					return doc;
				} )
				.finally( function () {
					savingState[ 1 ]( false );
				} );
		}

		function onSave() {
			save( false ).catch( showError );
		}

		// 发布：有未保存的修改时先保存，再发布。
		function publish() {
			menuState[ 1 ]( false );
			if ( ! cfg.canPublish ) {
				setNotice( { type: 'warning', text: S.publishDenied } );
				return;
			}
			// eslint-disable-next-line no-alert -- 后台已用页面内对话框替换原生确认框。
			if ( ! window.confirm( S.publishConfirm ) ) {
				return;
			}
			busyState[ 1 ]( true );
			( dirty ? save( true ) : Promise.resolve() )
				.then( function () {
					return apiFetch( { path: cfg.restPath + '/publish', method: 'POST', data: { modified: modifiedRef.current, revision: revisionRef.current } } );
				} )
				.then( function ( doc ) {
					statusState[ 1 ]( doc.status );
					setNotice( { type: 'success', text: S.published } );
					revisionRef.current = doc.revision;
				} )
				.catch( showError )
				.finally( function () {
					busyState[ 1 ]( false );
				} );
		}

		function unpublish() {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.unpublishConfirm ) ) {
				return;
			}
			menuState[ 1 ]( false );
			busyState[ 1 ]( true );
			apiFetch( { path: cfg.restPath + '/unpublish', method: 'POST', data: { revision: revisionRef.current } } )
				.then( function ( doc ) {
					statusState[ 1 ]( doc.status );
					setNotice( { type: 'success', text: S.unpublished } );
					revisionRef.current = doc.revision;
				} )
				.catch( showError )
				.finally( function () {
					busyState[ 1 ]( false );
				} );
		}

		function discard() {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.discardConfirm ) ) {
				return;
			}
			menuState[ 1 ]( false );
			busyState[ 1 ]( true );
			apiFetch( { path: cfg.restPath + '/discard', method: 'POST', data: { revision: revisionRef.current } } )
				.then( function ( doc ) {
					modifiedRef.current = doc.modified;
					revisionRef.current = doc.revision;
					statusState[ 1 ]( doc.status );
					historyRef.current = { past: [], future: [], last: 0 };
					setModulesRaw( doc.modules );
					dirtyState[ 1 ]( false );
					setSelected( null );
					setNotice( { type: 'success', text: S.discarded } );
				} )
				.catch( showError )
				.finally( function () {
					busyState[ 1 ]( false );
				} );
		}

		function failPreview( message ) {
			clearTimeout( previewTimeoutRef.current );
			pendingFrameRef.current = null;
			previewPhaseState[ 1 ]( 'error' );
			previewErrorState[ 1 ]( message || S.previewFailed || '预览暂时无法更新，已保留上次成功预览。' );
		}

		// Every edit invalidates the previous request, including its staged iframe.
		useEffect( function () {
			if ( firstRef.current ) {
				firstRef.current = false;
				return undefined;
			}
			var request = ++previewRequestRef.current;
			var controller = window.AbortController ? new window.AbortController() : null;
			var disposed = false;
			var expired = false;
			pendingFrameRef.current = null;
			previewErrorState[ 1 ]( null );
			previewPhaseState[ 1 ]( 'pending' );
			var timer = setTimeout( function () {
				previewPhaseState[ 1 ]( 'requesting' );
				previewTimeoutRef.current = setTimeout( function () {
					if ( disposed || request !== previewRequestRef.current ) { return; }
					expired = true;
					if ( controller ) { controller.abort(); }
					failPreview( S.previewTimeout || '预览加载超时，已保留上次成功预览。请重试。' );
				}, 30000 );
				var options = { path: cfg.restPath + '/preview', method: 'POST', data: { modules: modules } };
				if ( controller ) { options.signal = controller.signal; }
				apiFetch( options ).then( function ( result ) {
					if ( disposed || expired || request !== previewRequestRef.current ) { return; }
					if ( ! result || ! result.url ) { throw new Error( S.previewInvalid || '预览地址未返回，请重试。' ); }
					var slot = 1 - activeFrameRef.current;
					var entry = { url: result.url, request: request };
					pendingFrameRef.current = { slot: slot, entry: entry };
					frameEntriesState[ 1 ]( function ( entries ) {
						var next = entries.slice();
						next[ slot ] = entry;
						return next;
					} );
				} ).catch( function ( error ) {
					if ( disposed || expired || request !== previewRequestRef.current || ( error && error.name === 'AbortError' ) ) { return; }
					failPreview( ( S.previewFailed || '预览暂时无法更新，已保留上次成功预览。' ) + ( error && error.message ? ' ' + error.message : '' ) );
				} );
			}, previewRetryState[ 0 ] ? 120 : 500 );
			return function () {
				disposed = true;
				clearTimeout( timer );
				clearTimeout( previewTimeoutRef.current );
				if ( controller ) { controller.abort(); }
			};
		}, [ modules, previewRetryState[ 0 ] ] );

		useEffect( function () {
			var timer = setTimeout( function () {
				if ( previewRequestRef.current === 0 && ! initialFrameReadyRef.current ) {
					failPreview( S.previewTimeout || '预览加载超时，请重试。' );
				}
			}, 30000 );
			return function () { clearTimeout( timer ); };
		}, [] );

		// Resize the composition, not the iframe contents. No transform scaling or clipping.
		useEffect( function () {
			var workspace = workspaceRef.current;
			function measure() { narrowState[ 1 ]( !! workspace && workspace.getBoundingClientRect().width < 1060 ); }
			measure();
			if ( window.ResizeObserver && workspace ) {
				var observer = new window.ResizeObserver( measure );
				observer.observe( workspace );
				return function () { observer.disconnect(); };
			}
			window.addEventListener( 'resize', measure );
			return function () { window.removeEventListener( 'resize', measure ); };
		}, [] );

		useEffect( function () {
			if ( ! menuOpen ) { return undefined; }
			var publishRoot = document.querySelector( '.pc-de-publish' );
			if ( ! publishRoot ) { return undefined; }
			var toggleButton = publishRoot.querySelector( '[aria-haspopup="menu"]' );
			var buttons = Array.prototype.filter.call( publishRoot.querySelectorAll( '[role="menuitem"]' ), function ( button ) { return ! button.disabled; } );
			var timer = setTimeout( function () { if ( buttons.length ) { buttons[ 0 ].focus(); } }, 0 );
			function onOutside( event ) { if ( ! publishRoot.contains( event.target ) ) { menuState[ 1 ]( false ); } }
			function onMenuKey( event ) {
				if ( event.key === 'Escape' ) {
					event.preventDefault();
					menuState[ 1 ]( false );
					if ( toggleButton ) { toggleButton.focus(); }
				} else if ( buttons.length && /^(ArrowDown|ArrowUp|Home|End)$/.test( event.key ) ) {
					event.preventDefault();
					var at = buttons.indexOf( document.activeElement );
					var next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : ( at + ( event.key === 'ArrowDown' ? 1 : -1 ) + buttons.length ) % buttons.length;
					buttons[ next ].focus();
				}
			}
			document.addEventListener( 'pointerdown', onOutside );
			document.addEventListener( 'keydown', onMenuKey );
			return function () {
				clearTimeout( timer );
				document.removeEventListener( 'pointerdown', onOutside );
				document.removeEventListener( 'keydown', onMenuKey );
			};
		}, [ menuOpen ] );

		function frameDoc() {
			try {
				return frameRef.current ? frameRef.current.contentDocument : null;
			} catch ( e ) {
				return null;
			}
		}

		// 高亮框：在预览页面上单独画一个框，不受主题样式（背景、层级、overflow）影响。
		function overlay( doc ) {
			var box = doc.getElementById( 'pc-de-highlight' );
			if ( ! box ) {
				box = doc.createElement( 'div' );
				box.id = 'pc-de-highlight';
				box.setAttribute( 'aria-hidden', 'true' );
				box.innerHTML = '<span></span>';
				doc.body.appendChild( box );
			}
			return box;
		}

		function placeOverlay() {
			var doc = frameDoc();
			var target = hlTargetRef.current;
			if ( ! doc || ! doc.body ) {
				return;
			}
			var box = overlay( doc );
			if ( ! target || ! target.isConnected ) {
				box.style.display = 'none';
				return;
			}
			var r = target.getBoundingClientRect();
			var win = doc.defaultView;
			box.style.display = r.width || r.height ? 'block' : 'none';
			box.style.top = r.top + win.scrollY + 'px';
			box.style.left = r.left + win.scrollX + 'px';
			box.style.width = r.width + 'px';
			box.style.height = r.height + 'px';
		}

		function moduleEl( doc, id ) {
			return id ? doc.getElementById( 'vc-' + id ) : null;
		}

		function itemEl( doc, moduleId, itemId ) {
			var root = moduleEl( doc, moduleId );
			if ( ! root || ! itemId ) {
				return null;
			}
			var list = root.querySelectorAll( '[data-module-item]' );
			var first = null;
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[ i ].getAttribute( 'data-module-item' ) !== itemId ) {
					continue;
				}
				// 电脑版和手机版各有一份：优先当前看得见的那一份。
				if ( list[ i ].closest( '.vc-v' ) && 'none' === list[ i ].ownerDocument.defaultView.getComputedStyle( list[ i ].closest( '.vc-v' ) ).display ) {
					continue;
				}
				first = first || list[ i ];
			}
			return first;
		}

		// 轮播等一次只显示一项的积木：暂停自动播放并切换到这一项。
		// 1. 先发 vc:select-item 事件，主题脚本处理后调用 preventDefault() 即可；
		// 2. 没人处理时：Codex 的轮播（[data-vc-module-carousel]）用 focusin 暂停、方向键切换；
		// 3. 仍然看不见时，直接切换 is-active / hidden。
		function revealItem( root, el ) {
			var id = el.getAttribute( 'data-module-item' );
			var win = el.ownerDocument.defaultView;
			var ev = null;
			try {
				ev = new win.CustomEvent( 'vc:select-item', { bubbles: true, cancelable: true, detail: { item: id } } );
				el.dispatchEvent( ev );
			} catch ( e ) {}
			if ( ev && ev.defaultPrevented ) {
				return;
			}
			var car = el.closest( '[data-vc-module-carousel]' );
			if ( car ) {
				var slides = Array.prototype.filter.call( car.querySelectorAll( '[data-module-item]' ), function ( n ) {
					return n.closest( '[data-vc-module-carousel]' ) === car;
				} );
				var to = slides.indexOf( el );
				try {
					car.dispatchEvent( new win.FocusEvent( 'focusin', { bubbles: true } ) );
					car.dispatchEvent( new win.KeyboardEvent( 'keydown', { key: 'Home', bubbles: true } ) );
					for ( var k = 0; k < to; k++ ) {
						car.dispatchEvent( new win.KeyboardEvent( 'keydown', { key: 'ArrowRight', bubbles: true } ) );
					}
				} catch ( e ) {}
			}
			var r = el.getBoundingClientRect();
			if ( r.width && r.height && ! el.hidden && el.classList.contains( 'is-active' ) !== false ) {
				return;
			}
			Array.prototype.forEach.call( el.parentNode ? el.parentNode.children : [], function ( sib ) {
				if ( sib.hasAttribute && sib.hasAttribute( 'data-module-item' ) ) {
					var on = sib === el;
					sib.classList.toggle( 'is-active', on );
					sib.hidden = ! on;
					sib.inert = ! on;
					if ( on ) {
						sib.removeAttribute( 'aria-hidden' );
					}
				}
			} );
		}

		function highlight( scroll ) {
			var doc = frameDoc();
			if ( ! doc || ! doc.body ) {
				return;
			}
			var target = null;
			var label = '';
			if ( selected === 'header' ) {
				target = doc.querySelector( '.site-header, body > header, header' );
				label = S.header;
			} else if ( selected === 'footer' ) {
				target = doc.querySelector( '.site-footer, body > footer, footer' );
				label = S.footer;
			} else if ( selected && selected !== 'library' ) {
				var m = modules[ indexOf( selected ) ];
				target = moduleEl( doc, selected );
				label = m ? titleOf( m ) : '';
				if ( target && openItem ) {
					var it = itemEl( doc, selected, openItem );
					if ( it ) {
						revealItem( target, it );
						target = it;
						var items = ( m && m.attrs.items ) || [];
						for ( var i = 0; i < items.length; i++ ) {
							if ( items[ i ].id === openItem ) {
								label += ' · ' + S.itemN.replace( '%d', i + 1 );
							}
						}
					}
				}
			}
			hlTargetRef.current = target;
			var box = overlay( doc );
			box.firstChild.textContent = label;
			placeOverlay();
			if ( target && scroll ) {
				target.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			}
		}

		// 预览加载完成：恢复滚动、绑定点击选中、画高亮框。
		function onFrameLoad() {
			var frame = frameRef.current;
			var doc = frameDoc();
			if ( ! doc ) {
				return;
			}
			var style = doc.createElement( 'style' );
			style.textContent =
				'.vc-m{cursor:pointer}.vc-m:hover{outline:1px dashed rgba(24,24,24,.55);outline-offset:-1px}' +
				'.site-header:hover,.site-footer:hover{outline:1px dashed rgba(24,24,24,.55);outline-offset:-1px}' +
				'html{scrollbar-width:thin;scrollbar-color:#b5b5b5 #f4f4f4}' +
				'[data-pc-drag]{cursor:move}' +
				'#pc-de-highlight{position:absolute;z-index:2147483646;pointer-events:none;border:1px solid #181818;border-radius:2px;display:none}' +
				'#pc-de-highlight>span{position:absolute;left:-2px;top:-24px;background:#181818;color:#fff;font:600 12px/16px Manrope,system-ui,sans-serif;padding:4px 8px;border-radius:2px 2px 0 0;white-space:nowrap;max-width:60vw;overflow:hidden;text-overflow:ellipsis}';
			doc.head.appendChild( style );
			frame.contentWindow.scrollTo( 0, scrollRef.current );
			frame.contentWindow.addEventListener( 'scroll', placeOverlay, { passive: true } );
			frame.contentWindow.addEventListener( 'resize', placeOverlay );
			// 图片加载、字体加载后位置会变，稍后再校正几次。
			[ 200, 800, 2000 ].forEach( function ( ms ) {
				setTimeout( placeOverlay, ms );
			} );
			// 拖动浮动卡片（[data-pc-drag]）：在所在区域（[data-pc-drag-area]）里按百分比定位，松手后保存到当前设备。
			doc.addEventListener(
				'mousedown',
				function ( e ) {
					var card = e.target.closest && e.target.closest( '[data-pc-drag]' );
					if ( ! card || e.button !== 0 ) {
						return;
					}
					var area = card.closest( '[data-pc-drag-area]' ) || card.offsetParent;
					if ( ! area ) {
						return;
					}
					e.preventDefault();
					var a = area.getBoundingClientRect();
					var r = card.getBoundingClientRect();
					var dx = e.clientX - r.left;
					var dy = e.clientY - r.top;
					var moved = false;
					var pos = null;
					function onMove( ev ) {
						moved = true;
						var x = Math.max( 0, Math.min( 100, ( ( ev.clientX - dx - a.left ) / a.width ) * 100 ) );
						var y = Math.max( 0, Math.min( 100, ( ( ev.clientY - dy - a.top ) / a.height ) * 100 ) );
						pos = { x: Math.round( x ), y: Math.round( y ) };
						card.style.position = 'absolute';
						card.style.left = pos.x + '%';
						card.style.top = pos.y + '%';
						card.style.zIndex = '20';
						placeOverlay();
					}
					function onUp() {
						doc.removeEventListener( 'mousemove', onMove );
						doc.removeEventListener( 'mouseup', onUp );
						if ( moved && pos ) {
							dragJustEndedRef.current = true;
							commitDragRef.current( card.getAttribute( 'data-module-item' ), pos );
						}
					}
					doc.addEventListener( 'mousemove', onMove );
					doc.addEventListener( 'mouseup', onUp );
				},
				true
			);
			doc.addEventListener(
				'click',
				function ( e ) {
					var t = e.target;
					if ( dragJustEndedRef.current ) {
						dragJustEndedRef.current = false;
						e.preventDefault();
						return;
					}
					// 预览里不跳转、不提交，只用来选中积木。
					e.preventDefault();
					var m = t.closest && t.closest( '.vc-m' );
					if ( m && m.id ) {
						var item = t.closest( '[data-module-item]' );
						fromPreviewRef.current = true;
						pendingItemRef.current = item && m.contains( item ) ? item.getAttribute( 'data-module-item' ) : null;
						tabState[ 1 ]( 'modules' );
						setSelected( m.id.replace( /^vc-/, '' ) );
						paneState[ 1 ]( 'settings' );
						// 点同一个积木里不属于任何条目的地方：取消锁定条目，回到整个积木。
						openItemState[ 1 ]( pendingItemRef.current );
						return;
					}
					if ( t.closest && t.closest( 'footer' ) ) {
						setSelected( 'footer' );
					} else if ( t.closest && t.closest( 'header' ) ) {
						setSelected( 'header' );
					} else {
						clearRef.current();
					}
				},
				true
			);
			doc.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key ) {
					clearRef.current();
				}
			} );
			highlight( false );
		}

		function acceptFrame( slot, entry ) {
			var pending = pendingFrameRef.current;
			var initial = slot === activeFrameRef.current && entry.request === 0 && activeFrameRequestRef.current === 0;
			if ( ! initial && ( ! pending || pending.slot !== slot || pending.entry !== entry || entry.request !== previewRequestRef.current ) ) { return; }
			var frame = framesRef.current[ slot ];
			var doc;
			try { doc = frame && frame.contentDocument; } catch ( error ) { doc = null; }
			if ( ! doc || ! doc.body || ( doc.getElementById( 'loginform' ) && doc.body.classList && doc.body.classList.contains( 'login' ) ) || doc.querySelector( '.wp-die-message' ) ) {
				failPreview( S.previewSession || '无法打开预览，请确认后台仍已登录后重试。' );
				return;
			}
			if ( ! initial ) {
				try { scrollRef.current = frameRef.current.contentWindow.scrollY; } catch ( error ) { scrollRef.current = 0; }
				clearTimeout( previewTimeoutRef.current );
				pendingFrameRef.current = null;
				activeFrameRef.current = slot;
				activeFrameRequestRef.current = entry.request;
				activeFrameState[ 1 ]( slot );
				// A successful replacement is now the last good preview. Unmount older
				// hidden browsing contexts, checking latest state so queued cleanup
				// cannot remove a newer candidate or a subsequently activated frame.
				frameEntriesState[ 1 ]( function ( entries ) {
					var currentSlot = activeFrameRef.current;
					var current = entries[ currentSlot ];
					if ( ! current ) { return entries; }
					var changed = false;
					var next = entries.map( function ( candidate, index ) {
						if ( ! candidate || index === currentSlot || candidate.request >= current.request ) { return candidate; }
						changed = true;
						return null;
					} );
					return changed ? next : entries;
				} );
				previewPhaseState[ 1 ]( 'idle' );
				previewErrorState[ 1 ]( null );
			} else if ( previewRequestRef.current === 0 ) {
				previewPhaseState[ 1 ]( 'idle' );
			}
			if ( initial ) { initialFrameReadyRef.current = true; }
			frameRef.current = frame;
			onFrameLoad();
			window.requestAnimationFrame( function () { highlight( false ); } );
		}

		// 拖动卡片后保存位置：在当前设备的条目里找到这张卡片（例如轮播某一张的 highlights），写入 x / y 的当前设备值。
		commitDragRef.current = function ( cardId, pos ) {
			var dev = device === 'mobile' ? 'mobile' : 'desktop';
			var found = false;
			var next = modules.map( function ( m ) {
				var mob = mobileOf( m );
				var useMobile = dev === 'mobile' && mob.items;
				var list = ui.clone( useMobile ? mob.items : m.attrs.items || [] );
				var hitItem = null;
				list.forEach( function ( it ) {
					Object.keys( it.settings || {} ).forEach( function ( key ) {
						var subs = it.settings[ key ];
						if ( ! Array.isArray( subs ) ) {
							return;
						}
						subs.forEach( function ( sub ) {
							if ( sub && sub.id === cardId ) {
								sub.settings = sub.settings || {};
								[ 'x', 'y' ].forEach( function ( axis ) {
									var cur = sub.settings[ axis ] && typeof sub.settings[ axis ] === 'object' ? sub.settings[ axis ] : { desktop: null, mobile: null };
									var v = Object.assign( {}, cur );
									v[ dev ] = pos[ axis ];
									sub.settings[ axis ] = v;
								} );
								hitItem = it.id;
							}
						} );
					} );
				} );
				if ( ! hitItem ) {
					return m;
				}
				found = true;
				fromPreviewRef.current = true;
				pendingItemRef.current = hitItem;
				if ( selected !== m.attrs.moduleId ) {
					setSelected( m.attrs.moduleId );
				} else {
					openItemState[ 1 ]( hitItem );
				}
				var patch = useMobile ? { mobile: { settings: mob.settings, items: list } } : { items: list };
				return { block: m.block, attrs: Object.assign( {}, m.attrs, patch ) };
			} );
			if ( found ) {
				setModules( next );
			}
		};

		// 点空白处 / 按 Esc：取消选中，高亮框消失。
		function clearSelection() {
			pendingItemRef.current = null;
			setSelected( null );
			openItemState[ 1 ]( null );
		}
		var clearRef = useRef( clearSelection );
		clearRef.current = clearSelection;

		useEffect( function () {
			function onKey( e ) {
				if ( 'Escape' === e.key && ! e.defaultPrevented && ! document.querySelector( '.components-modal__frame, .media-modal, .pc-de-publish__menu' ) ) {
					clearRef.current();
				}
			}
			document.addEventListener( 'keydown', onKey );
			return function () {
				document.removeEventListener( 'keydown', onKey );
			};
		}, [] );

		// 换了积木：关闭之前展开的条目（从预览里点某一项时保留那一项）。
		useEffect(
			function () {
				openItemState[ 1 ]( pendingItemRef.current );
				pendingItemRef.current = null;
			},
			[ selected ]
		);

		useEffect(
			function () {
				highlight( ! fromPreviewRef.current );
				fromPreviewRef.current = false;
			},
			[ selected, openItem ]
		);

		// 快捷键：Ctrl+S 保存，Ctrl+Z / Ctrl+Shift+Z 撤销重做（输入框里保留浏览器自己的撤销）。
		useEffect( function () {
			function onKey( e ) {
				var mod = e.ctrlKey || e.metaKey;
				if ( mod && e.key.toLowerCase() === 's' ) {
					e.preventDefault();
					onSave();
				} else if ( mod && e.key.toLowerCase() === 'z' && ! isTyping( e.target ) ) {
					e.preventDefault();
					if ( e.shiftKey ) {
						redo();
					} else {
						undo();
					}
				}
			}
			window.addEventListener( 'keydown', onKey );
			return function () {
				window.removeEventListener( 'keydown', onKey );
			};
		} );

		useEffect(
			function () {
				function onLeave( e ) {
					if ( leaveRef.current.approved ) { leaveRef.current.approved = false; return; }
					if ( dirty ) {
						e.preventDefault();
						e.returnValue = '';
					}
				}
				window.addEventListener( 'beforeunload', onLeave );
				return function () {
					window.removeEventListener( 'beforeunload', onLeave );
				};
			},
			[ dirty ]
		);

		// ---------- 顶部 ----------

		function deviceButton( key, icon, label ) {
			return el(
				'button',
				{
					type: 'button',
					className: 'pc-de-device' + ( device === key ? ' is-active' : '' ),
					'aria-pressed': device === key,
					title: label,
					'aria-label': label,
					onClick: function () {
						deviceState[ 1 ]( key );
						paneState[ 1 ]( 'preview' );
					},
				},
				el( Icon, { name: icon } )
			);
		}

		var top = el(
			'header',
			{ className: 'pc-de-top' },
			el(
				'div',
				{ className: 'pc-de-top__left' },
				el(
					'a',
					{ className: 'pc-de-top__btn', href: cfg.backUrl, title: S.back, 'aria-label': S.back, onClick: function ( event ) {
						if ( ! dirty || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) { return; }
						event.preventDefault();
						if ( ! leaveRef.current.started ) { leaveState[ 1 ]( cfg.backUrl ); }
					} },
					el( Icon, { name: 'exit' } )
				),
				el(
					'label',
					{ className: 'pc-de-page' },
					el( 'span', { className: 'screen-reader-text' }, S.page ),
					el(
						'select',
						{ value: cfg.document.document, onChange: function ( e ) { openDocument( e.target.value ); } },
						cfg.pages.map( function ( p ) {
							return el( 'option', { key: p.value, value: p.value, disabled: ! p.enabled }, p.label );
						} )
					)
				),
				el( 'span', { className: 'pc-de-top__template' }, cfg.document.template.name ),
				el(
					'span',
					{ className: 'pc-de-status' + ( status.published ? ( status.changed || dirty ? ' is-changed' : ' is-live' ) : '' ) },
					status.published
						? ( status.changed || dirty ? S.statusChanged : S.statusLive.replace( '%s', status.at || '' ) )
						: S.statusDraft
				)
			),
			el(
				'div',
				{ className: 'pc-de-top__center', role: 'group', 'aria-label': S.previewDevice || '预览设备' },
				deviceButton( 'desktop', 'desktop', S.desktop ),
				deviceButton( 'mobile', 'smartphone', S.mobile ),
				deviceButton( 'full', 'editor-expand', S.full )
			),
			el(
				'div',
				{ className: 'pc-de-top__right' },
				el( 'button', { type: 'button', className: 'pc-de-top__btn', disabled: ! historyRef.current.past.length, onClick: undo, title: S.undo, 'aria-label': S.undo }, el( Icon, { name: 'undo' } ), el( 'span', null, S.undo ) ),
				el( 'button', { type: 'button', className: 'pc-de-top__btn', disabled: ! historyRef.current.future.length, onClick: redo, title: S.redo, 'aria-label': S.redo }, el( Icon, { name: 'redo' } ), el( 'span', null, S.redo ) ),
				el( 'a', { className: 'pc-de-top__btn', href: cfg.storeUrl, target: '_blank', rel: 'noopener', title: S.viewStore, 'aria-label': S.viewStore }, el( Icon, { name: 'visibility' } ), el( 'span', null, S.viewStore ) ),
				el(
					'button',
					{ type: 'button', className: 'pc-de-top__btn pc-de-top__save' + ( dirty ? ' is-dirty' : '' ), onClick: onSave, disabled: saving || busy, title: saving ? S.saving : S.save, 'aria-label': saving ? S.saving : S.save },
					el( Icon, { name: 'saved' } ),
					el( 'span', null, saving ? S.saving : S.save )
				),
				el(
					'div',
					{ className: 'pc-de-publish' },
					el(
						'button',
						{ type: 'button', className: 'pc-de-top__publish', onClick: publish, disabled: busy || saving || ! cfg.canPublish, title: ! cfg.canPublish ? S.publishDenied : S.publish, 'aria-label': busy ? S.working : S.publish },
						el( Icon, { name: 'upload' } ),
						el( 'span', null, busy ? S.working : S.publish )
					),
					el(
						'button',
						{
							type: 'button',
							className: 'pc-de-top__publish pc-de-top__publish--more',
							'aria-label': S.publishMenu,
							'aria-expanded': menuOpen,
							'aria-haspopup': 'menu',
							'aria-controls': 'pc-de-publish-menu',
							title: S.publishMenu,
							onClick: function () {
								menuState[ 1 ]( ! menuOpen );
							},
						},
						el( Icon, { name: 'arrow-down-alt2' } )
					),
					menuOpen
						? el(
							'div',
							{ id: 'pc-de-publish-menu', className: 'pc-de-publish__menu', role: 'menu' },
							el( 'button', { type: 'button', role: 'menuitem', disabled: ! status.published, onClick: discard }, S.discard ),
							el( 'button', { type: 'button', role: 'menuitem', disabled: ! status.published || ! cfg.canPublish, onClick: unpublish, className: 'is-danger' }, S.unpublish )
						)
						: null
				)
			)
		);

		// ---------- 积木列表 ----------

		function pinned( key, label ) {
			return el(
				'button',
				{
					type: 'button',
					className: 'pc-de-row pc-de-row--pinned' + ( selected === key ? ' is-selected' : '' ),
					onClick: function () {
						openDocument( key );
					},
				},
				el( Icon, { block: key === 'header' ? 'site_header' : 'site_footer', className: 'pc-de-row__pin', size: 16 } ),
				el( 'span', { className: 'pc-de-row__title' }, label )
			);
		}

		var list = el(
			'div',
			{
				className: 'pc-de-list',
				role: 'list',
				'aria-label': S.tabModules,
				// 点积木列表下方的空白：取消选中。
				onClick: function ( e ) {
					if ( e.target === e.currentTarget ) {
						clearSelection();
					}
				},
			},
			el( 'div', { className: 'pc-de-list__heading' }, el( 'span', null, S.pageBlocks || '页面积木' ), el( 'span', { className: 'pc-de-list__count' }, modules.length ) ),
			pinned( 'header', S.header ),
			modules.length
				? null
				: el( 'p', { className: 'pc-de-list__empty' }, S.emptyList ),
			modules.map( function ( m, i ) {
				var id = m.attrs.moduleId;
				var hidden = m.attrs.enabled === false;
				var settings = m.attrs.settings || {};
				var partial = settings.showDesktop === false || settings.showMobile === false;
				return el(
					'div',
					{
						key: id,
						role: 'listitem',
						className:
							'pc-de-row' +
							( selected === id ? ' is-selected' : '' ) +
							( hidden ? ' is-hidden' : '' ) +
							( drag.over === i && drag.from !== null && drag.from !== i ? ' is-drop-target' : '' ),
						draggable: true,
						onDragStart: function ( e ) {
							e.dataTransfer.effectAllowed = 'move';
							e.dataTransfer.setData( 'text/plain', id );
							setDrag( { from: i, over: i } );
						},
						onDragOver: function ( e ) {
							e.preventDefault();
							if ( drag.over !== i ) {
								setDrag( { from: drag.from, over: i } );
							}
						},
						onDrop: function ( e ) {
							e.preventDefault();
							move( drag.from, i );
							setDrag( { from: null, over: null } );
						},
						onDragEnd: function () {
							setDrag( { from: null, over: null } );
						},
					},
					el( 'span', { className: 'pc-de-row__handle', title: S.dragHint }, el( Icon, { name: 'menu' } ) ),
					el(
						'button',
						{
							type: 'button',
							className: 'pc-de-row__title',
							'aria-pressed': selected === id,
							title: titleOf( m ),
							onClick: function () {
								tabState[ 1 ]( 'modules' );
								setSelected( id );
								paneState[ 1 ]( 'settings' );
							},
						},
						el( Icon, { block: m.block, size: 15 } ),
						el( 'span', null, titleOf( m ) )
					),
					hidden || partial ? el( 'span', { className: 'pc-de-row__flag', title: S.hide }, el( Icon, { name: 'hidden' } ) ) : null,
					el(
						'span',
						{ className: 'pc-de-row__actions', 'aria-label': S.blockActions || '积木操作' },
						el( 'button', { type: 'button', title: S.moveUp || '上移', 'aria-label': S.moveUp || '上移', disabled: i === 0, onClick: function () { move( i, i - 1 ); } }, el( Icon, { name: 'up', size: 14 } ) ),
						el( 'button', { type: 'button', title: S.moveDown || '下移', 'aria-label': S.moveDown || '下移', disabled: i === modules.length - 1, onClick: function () { move( i, i + 1 ); } }, el( Icon, { name: 'down', size: 14 } ) ),
						el( 'button', { type: 'button', title: S.duplicate, 'aria-label': S.duplicate, onClick: function () { duplicate( i ); } }, el( Icon, { name: 'admin-page' } ) ),
						el( 'button', { type: 'button', title: hidden ? S.show : S.hide, 'aria-label': hidden ? S.show : S.hide, onClick: function () { toggle( i ); } }, el( Icon, { name: hidden ? 'visibility' : 'hidden' } ) ),
						el( 'button', { type: 'button', title: S.remove, 'aria-label': S.remove, className: 'is-danger', onClick: function () { remove( i ); } }, el( Icon, { name: 'trash' } ) )
					)
				);
			} ),
			el(
				'button',
				{
					type: 'button',
					className: 'pc-de-row pc-de-row--add' + ( selected === 'library' ? ' is-selected' : '' ),
					onClick: function () {
						setSelected( 'library' );
						paneState[ 1 ]( 'settings' );
					},
				},
				el( Icon, { name: 'plus-alt2' } ),
				el( 'span', { className: 'pc-de-row__title' }, S.addModule )
			),
			pinned( 'footer', S.footer )
		);

		// ---------- 设置面板 ----------

		var settingsDevice = device === 'mobile' ? 'mobile' : 'desktop';
		var panel;
		var current = selected && selected !== 'header' && selected !== 'footer' && selected !== 'library' ? modules[ indexOf( selected ) ] : null;
		if ( tab === 'global' ) {
			panel = el( 'div', { className: 'pc-de-note' }, el( 'button', { type: 'button', className: 'button button-primary', onClick: function () { openDocument( 'global' ); } }, S.tabGlobal ) );
		} else if ( selected === 'library' ) {
			panel = el(
				'div',
				{ className: 'pc-de-library' },
				el( 'div', { className: 'pc-de-library__head' },
					el( 'h2', null, S.library ),
					el( 'label', { className: 'pc-de-search' },
						el( Icon, { name: 'search', size: 16 } ),
						el( 'span', { className: 'screen-reader-text' }, S.searchBlocks || '搜索积木' ),
						el( 'input', { type: 'search', value: libraryQuery, placeholder: S.searchBlocks || '搜索积木', onChange: function ( event ) { libraryQueryState[ 1 ]( event.target.value ); } } )
					)
				),
				cfg.library.some( function ( group ) {
					return group.cards.some( function ( card ) { return ( card.title + ' ' + card.block + ' ' + group.label ).toLowerCase().indexOf( libraryQuery.trim().toLowerCase() ) !== -1; } );
				} ) ? null : el( 'p', { className: 'pc-de-hint', role: 'status' }, S.noMatchingBlocks || '未找到匹配的积木，请换个关键词。' ),
				cfg.library.map( function ( group ) {
					var cards = group.cards.filter( function ( card ) { return ( card.title + ' ' + card.block + ' ' + group.label ).toLowerCase().indexOf( libraryQuery.trim().toLowerCase() ) !== -1; } );
					if ( ! cards.length ) { return null; }
					return el(
						'section',
						{ key: group.label },
						el( 'h3', null, group.label ),
						el(
							'div',
							{ className: 'pc-de-library__grid' },
							cards.map( function ( card ) {
								return el(
									'button',
									{
										key: card.block,
										type: 'button',
										className: 'pc-de-card',
										title: card.title,
										'aria-label': ( S.addModule || '添加积木' ) + ' · ' + card.title,
										disabled: !! ( data.modules[ card.block ] && data.modules[ card.block ].multiple === false && modules.some( function ( module ) { return module.block === card.block; } ) ),
										onClick: function () {
											insert( card.block );
										},
									},
									el( Icon, { block: card.block, size: 28 } ),
									el( 'span', null, card.title )
								);
							} )
						)
					);
				} )
			);
		} else if ( selected === 'header' || selected === 'footer' ) {
			panel = el( 'div', { className: 'pc-de-note' }, el( 'button', { type: 'button', className: 'button button-primary', onClick: function () { openDocument( selected ); } }, selected === 'header' ? S.header : S.footer ) );
		} else if ( current && defOf( current ) ) {
			var def = defOf( current );
			var isMobile = settingsDevice === 'mobile';
			var mob = mobileOf( current );
			var baseValues = ui.withDefaults( def.defaults, current.attrs.settings );
			// Single-render blocks share content; only their explicit device fields vary by viewport.
			// Keep legacy mobile attributes intact without presenting values the renderer does not use.
			var values = isMobile && ! def.singleRender ? Object.assign( {}, baseValues, mob.settings ) : baseValues;
			var shownItems = isMobile && ! def.singleRender && mob.items ? mob.items : current.attrs.items;
			var fieldDefs = {};
			def.settings.forEach( function ( f ) {
				fieldDefs[ f.key ] = f;
			} );
			var perDevice = function ( key ) {
				if ( def.singleRender ) { return true; }
				var f = fieldDefs[ key ];
				return ! f || f.device || ( f.group || 'content' ) === 'general';
			};
			var setMobile = function ( next ) {
				var m = { settings: next.settings, items: next.items };
				if ( ! Object.keys( m.settings ).length && ! m.items ) {
					m = {};
				}
				updateModule( current.attrs.moduleId, { mobile: m }, true );
			};
			var count = mobileCount( current );
			var mobileBar = isMobile && ! def.singleRender
				? el( 'div', { className: 'pc-de-mobilebar' + ( count ? ' has-changes' : '' ) },
					el( Icon, { name: 'smartphone', size: 14 } ),
					el( 'span', null, count ? ( S.mobileOverrideCount || '手机已单独设置 %d 项' ).replace( '%d', count ) : ( S.mobileInherited || '手机沿用电脑设置' ) ),
					count ? el( 'button', { type: 'button', className: 'pc-de-text-button', onClick: function () { updateModule( current.attrs.moduleId, { mobile: {} } ); } }, S.mobileResetShort || '恢复一致' ) : null
				)
				: ( isMobile && def.singleRender ? el( 'div', { className: 'pc-de-mobilebar' }, el( 'span', null, S.sharedDeviceSettings || '内容和绑定共用；带设备选项的布局分别生效。' ) ) : null );
			panel = el(
				'div',
				{ className: 'pc-de-settings__inner', key: current.attrs.moduleId },
				el( 'h2', { className: 'pc-de-settings__title' }, el( Icon, { block: current.block, size: 19 } ), el( 'span', null, def.title ) ),
				ui.needsDeviceSwitch( def )
					? el(
						'div',
						{ className: 'pc-me-devices' },
						el( 'span', null, S.editing ),
						el(
							C.ButtonGroup,
							null,
							el( C.Button, { variant: settingsDevice === 'desktop' ? 'primary' : 'secondary', size: 'small', icon: el( Icon, { name: 'desktop', size: 15 } ), onClick: function () { deviceState[ 1 ]( 'desktop' ); } }, data.strings.desktop ),
							el( C.Button, { variant: settingsDevice === 'mobile' ? 'primary' : 'secondary', size: 'small', icon: el( Icon, { name: 'smartphone', size: 15 } ), onClick: function () { deviceState[ 1 ]( 'mobile' ); } }, data.strings.mobile )
						)
					)
					: null,
				mobileBar,
				el(
					C.Panel,
					null,
					ui.settingsPanels( {
						def: def,
						blogOwner: JSON.stringify( [ 'design', cfg.document.id, cfg.document.document, current.attrs.moduleId ] ),
						pairedDeviceSafe: function ( key ) { return ! Object.prototype.hasOwnProperty.call( mob.settings, key ); },
						panels: def.panels,
						settings: values,
						device: settingsDevice,
						items: shownItems,
						metadataDocumentId: cfg.document.id,
						metadataMode: baseValues.youtubeMode,
						onPreviewRefresh: refreshMetadataPreview,
						setSetting: function ( key, v ) {
							if ( isMobile && ! perDevice( key ) ) {
								var ms = Object.assign( {}, mob.settings );
								ms[ key ] = v;
								setMobile( { settings: ms, items: mob.items } );
								return;
							}
							var next = Object.assign( {}, baseValues );
							next[ key ] = v;
							updateModule( current.attrs.moduleId, { settings: next }, true );
						},
						setItems: function ( items ) {
							if ( isMobile && ! def.singleRender ) {
								setMobile( { settings: mob.settings, items: items } );
								return;
							}
							updateModule( current.attrs.moduleId, { items: items }, true );
						},
						overridden: isMobile && ! def.singleRender
							? function ( key ) {
								return Object.prototype.hasOwnProperty.call( mob.settings, key );
							}
							: null,
						resetOverride: def.singleRender ? null : function ( key ) {
							var ms = Object.assign( {}, mob.settings );
							delete ms[ key ];
							setMobile( { settings: ms, items: mob.items } );
						},
						openItem: openItem,
						onOpenItem: function ( id ) {
							openItemState[ 1 ]( id );
						},
					} )
				)
			);
		} else if ( current ) {
			panel = el( 'div', { className: 'pc-de-note' }, el( Icon, { name: 'warning' } ), el( 'p', null, ( S.unknownBlock || '此积木暂不可编辑，内容已保留：' ) + ' ' + current.block ) );
		} else {
			panel = el( 'div', { className: 'pc-de-note' }, el( Icon, { name: 'arrow-left-alt' } ), el( 'p', null, S.selectHint ) );
		}

		var side = el(
			'aside',
			{ id: 'pc-de-inspector', className: 'pc-de-side', 'aria-label': S.editorSettings || '装修设置' },
			el(
				'div',
				{ className: 'pc-de-tabs', role: 'tablist' },
				el( 'button', { type: 'button', role: 'tab', 'aria-selected': tab === 'modules', className: tab === 'modules' ? 'is-active' : '', onClick: function () { tabState[ 1 ]( 'modules' ); } }, S.tabModules ),
					el( 'button', { type: 'button', role: 'tab', 'aria-selected': cfg.document.document === 'global', className: cfg.document.document === 'global' ? 'is-active' : '', onClick: function () { openDocument( 'global' ); } }, S.tabGlobal )
			),
			el( 'div', { className: 'pc-de-cols' }, tab === 'modules' ? list : null, el( 'div', { className: 'pc-de-settings', role: 'region', 'aria-label': S.blockSettings || '积木属性' }, panel ) )
		);

		var previewFeedback = previewError ? el( 'div', { className: 'pc-de-preview__error', role: 'alert' },
			el( Icon, { name: 'warning', size: 17 } ),
			el( 'span', null, previewError ),
			el( 'button', { type: 'button', className: 'pc-de-text-button', onClick: function () { previewRetryState[ 1 ]( function ( count ) { return count + 1; } ); } }, S.retryPreview || '重试预览' )
		) : null;

		var preview = el(
			'main',
			{
				id: 'pc-de-canvas',
				className: 'pc-de-preview',
				'aria-label': S.livePreview || '真实页面预览',
				// 点预览四周的灰色空白（不是预览页面本身）：取消选中。
				onClick: function ( e ) {
					if ( ! e.target.closest( 'iframe, button, a' ) ) {
						clearSelection();
					}
				},
			},
			el(
				'div',
				{ className: 'pc-de-frame pc-de-frame--' + device, 'aria-busy': loading },
				frameEntries.map( function ( entry, slot ) {
					if ( ! entry ) { return null; }
					return el( 'iframe', {
						key: slot,
						className: slot === activeFrame ? 'is-active' : 'is-staging',
						ref: function ( frame ) { framesRef.current[ slot ] = frame; if ( slot === activeFrameRef.current ) { frameRef.current = frame; } },
						src: entry.url,
						title: S.previewNote,
						'aria-hidden': slot !== activeFrame,
						tabIndex: slot === activeFrame ? 0 : -1,
						onLoad: function () { acceptFrame( slot, entry ); },
						onError: function () { if ( slot === activeFrameRef.current || ( pendingFrameRef.current && pendingFrameRef.current.entry === entry ) ) { failPreview(); } },
					} );
				} ),
				loading ? el( 'div', { className: 'pc-de-frame__loading', role: 'status' }, el( C.Spinner ), S.loadingPreview ) : null
			),
			el( 'div', { className: 'pc-de-preview__footer' },
				el( 'p', { className: 'pc-de-preview__note', role: 'status', 'aria-live': 'polite' },
					el( 'span', { className: 'pc-de-preview__state' + ( previewPhase === 'error' ? ' is-error' : '' ) } ),
					previewPhase === 'error' ? ( S.previewErrorStatus || '预览更新失败' ) : previewPhase === 'pending' ? ( S.previewPending || '修改后将自动更新预览' ) : loading ? S.loadingPreview : dirty ? S.unsaved : ( S.previewReady || '预览已更新' )
				),
				el( 'span', { className: 'pc-de-preview__device' }, device === 'mobile' ? ( S.mobile + ' · 390 px' ) : S.desktop )
			)
		);

		return el(
			'div',
			{ ref: workspaceRef, className: 'pc-de__app pane-' + paneState[ 0 ] + ( narrowState[ 0 ] ? ' is-narrow' : '' ) + ( device === 'full' ? ' is-full' : '' ) },
			top,
			device !== 'full' ? el( 'nav', { className: 'pc-de-pane-switch', 'aria-label': S.workspaceView || '工作区视图' },
				el( 'button', { type: 'button', 'aria-pressed': paneState[ 0 ] === 'preview', 'aria-controls': 'pc-de-canvas', onClick: function () { paneState[ 1 ]( 'preview' ); } }, el( Icon, { name: 'visibility', size: 16 } ), S.livePreview || '真实预览' ),
				el( 'button', { type: 'button', 'aria-pressed': paneState[ 0 ] === 'settings', 'aria-controls': 'pc-de-inspector', onClick: function () { paneState[ 1 ]( 'settings' ); } }, el( Icon, { name: 'settings', size: 16 } ), S.editorSettings || '装修设置' )
			) : null,
			notice
				? el(
					C.Notice,
					{
						className: 'pc-de-notice',
						status: notice.type,
						onRemove: function () {
							setNotice( null );
						},
						actions: notice.reload
							? [
								{
									label: S.conflict,
									onClick: function () {
										window.location.reload();
									},
								},
							]
							: [],
					},
					notice.text
				)
				: null,
			leaveState[ 0 ] ? el( C.Modal, {
				title: S.leaveTitle || '离开装修编辑器？', focusOnMount: 'firstContentElement', shouldCloseOnClickOutside: false,
				aria: { describedby: 'pc-de-leave-message' }, onRequestClose: function () { leaveState[ 1 ]( null ); }
			},
				el( 'p', { id: 'pc-de-leave-message' }, S.leaveMessage || '还有未保存的修改。离开会放弃这些修改；已保存草稿和发布版本不变。需要保留时，请取消并先保存草稿。' ),
				el( 'div', null,
					el( C.Button, { variant: 'secondary', onClick: function () { leaveState[ 1 ]( null ); } }, S.keepEditing || '继续编辑' ), ' ',
					el( C.Button, { variant: 'primary', isDestructive: true, onClick: confirmLeave }, S.leaveWithoutSaving || '不保存并离开' )
				)
			) : null,
			previewFeedback,
			el( 'div', { className: 'pc-de-body' }, preview, device === 'full' ? null : side )
		);
	}

	function mount() {
		var root = document.getElementById( 'pc-design-editor' );
		if ( ! root ) {
			return;
		}
		root.removeAttribute( 'aria-busy' );
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( App ) );
		} else {
			wp.element.render( el( App ), root );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp, window.pcDesignEditor, window.pcModules, window.pcModuleUI );
