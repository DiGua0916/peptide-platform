/**
 * 装修编辑器（EDIT-BLOCKS-04），界面参照 YY（Shopname）的装修页。
 *
 * 数据：window.pcDesignEditor（文档、接口路径、积木库、文案）；window.pcModules / window.pcModuleUI（字段定义与控件）。
 * 保存：POST /peptide/v1/layouts/{id}，带上打开时的修改时间，别人改过时返回 409。
 * 预览：每次改动后约 0.6 秒，把当前内容发到 /preview 生成短期预览地址，刷新左侧 iframe（保留滚动位置）。
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
		var custom = m.attrs && m.attrs.settings && ( m.attrs.settings.title || m.attrs.settings.leftTitle );
		return ( def ? def.title : m.block ) + ( custom ? ' · ' + custom : '' );
	}

	function newModule( block ) {
		return { block: block, attrs: ui.initialAttributes( data.modules[ block ] ) };
	}

	function copyModule( m ) {
		var c = ui.clone( m );
		c.attrs.moduleId = ui.uid();
		( c.attrs.items || [] ).forEach( function ( it ) {
			it.id = ui.uid();
			( ( it.settings && it.settings.spots ) || [] ).forEach( function ( s ) {
				s.id = ui.uid();
			} );
		} );
		return c;
	}

	function isTyping( target ) {
		return target && ( /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) || target.isContentEditable );
	}

	function Icon( props ) {
		return el( 'span', { className: 'dashicons dashicons-' + props.name + ( props.className ? ' ' + props.className : '' ), 'aria-hidden': 'true' } );
	}

	function App() {
		var modulesState = useState( cfg.document.modules || [] );
		var modules = modulesState[ 0 ];
		var setModulesRaw = modulesState[ 1 ];
		var selectedState = useState( null );
		var selected = selectedState[ 0 ];
		var setSelected = selectedState[ 1 ];
		var tabState = useState( 'modules' );
		var tab = tabState[ 0 ];
		var deviceState = useState( 'desktop' );
		var device = deviceState[ 0 ];
		var dirtyState = useState( false );
		var dirty = dirtyState[ 0 ];
		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var noticeState = useState(
			cfg.document.lockedBy ? { type: 'warning', text: S.lockedBy.replace( '%s', cfg.document.lockedBy ) } : null
		);
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];
		var modifiedRef = useRef( cfg.document.modified );
		var statusState = useState( cfg.document.status || { published: false, changed: true } );
		var status = statusState[ 0 ];
		var menuState = useState( false );
		var menuOpen = menuState[ 0 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var previewState = useState( cfg.document.previewBase );
		var previewUrl = previewState[ 0 ];
		var loadingState = useState( true );
		var loading = loadingState[ 0 ];
		var dragState = useState( { from: null, over: null } );
		var drag = dragState[ 0 ];
		var setDrag = dragState[ 1 ];
		var historyRef = useRef( { past: [], future: [], last: 0 } );
		var frameRef = useRef( null );
		var scrollRef = useRef( 0 );
		var fromPreviewRef = useRef( false );
		var firstRef = useRef( true );
		var historyTick = useState( 0 );

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
			var m = newModule( block );
			var at = indexOf( selected );
			var list = modules.slice();
			list.splice( at === -1 ? list.length : at + 1, 0, m );
			setModules( list );
			setSelected( m.attrs.moduleId );
		}

		function duplicate( index ) {
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
			if ( from === null || to === null || from === to ) {
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
			return apiFetch( { path: cfg.restPath, method: 'POST', data: { modules: modules, modified: modifiedRef.current } } )
				.then( function ( doc ) {
					modifiedRef.current = doc.modified;
					statusState[ 1 ]( doc.status );
					dirtyState[ 1 ]( false );
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
					return apiFetch( { path: cfg.restPath + '/publish', method: 'POST', data: { modified: modifiedRef.current } } );
				} )
				.then( function ( doc ) {
					statusState[ 1 ]( doc.status );
					setNotice( { type: 'success', text: S.published } );
				} )
				.catch( showError )
				.finally( function () {
					busyState[ 1 ]( false );
				} );
		}

		function unpublish() {
			menuState[ 1 ]( false );
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.unpublishConfirm ) ) {
				return;
			}
			busyState[ 1 ]( true );
			apiFetch( { path: cfg.restPath + '/unpublish', method: 'POST' } )
				.then( function ( doc ) {
					statusState[ 1 ]( doc.status );
					setNotice( { type: 'success', text: S.unpublished } );
				} )
				.catch( showError )
				.finally( function () {
					busyState[ 1 ]( false );
				} );
		}

		function discard() {
			menuState[ 1 ]( false );
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.discardConfirm ) ) {
				return;
			}
			busyState[ 1 ]( true );
			apiFetch( { path: cfg.restPath + '/discard', method: 'POST' } )
				.then( function ( doc ) {
					modifiedRef.current = doc.modified;
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

		// 预览：改动后稍等一下再刷新。
		useEffect(
			function () {
				if ( firstRef.current ) {
					firstRef.current = false;
					return undefined;
				}
				var timer = setTimeout( function () {
					apiFetch( { path: cfg.restPath + '/preview', method: 'POST', data: { modules: modules } } )
						.then( function ( r ) {
							var frame = frameRef.current;
							try {
								scrollRef.current = frame && frame.contentWindow ? frame.contentWindow.scrollY : 0;
							} catch ( e ) {
								scrollRef.current = 0;
							}
							loadingState[ 1 ]( true );
							previewState[ 1 ]( r.url );
						} )
						.catch( function () {} );
				}, 600 );
				return function () {
					clearTimeout( timer );
				};
			},
			[ modules ]
		);

		// 预览加载完成：恢复滚动、绑定点击选中、高亮当前积木。
		function onFrameLoad() {
			loadingState[ 1 ]( false );
			var frame = frameRef.current;
			var doc;
			try {
				doc = frame.contentDocument;
			} catch ( e ) {
				return;
			}
			if ( ! doc ) {
				return;
			}
			var style = doc.createElement( 'style' );
			style.textContent =
				'.vc-m{cursor:pointer}.vc-m:hover{outline:1px dashed #ee7d33;outline-offset:-1px}' +
				'.vc-m.pc-de-selected{outline:2px solid #ee7d33;outline-offset:-2px}' +
				'body > header:hover, body > footer:hover, .site-header:hover, .site-footer:hover{outline:1px dashed #ee7d33;outline-offset:-1px}';
			doc.head.appendChild( style );
			frame.contentWindow.scrollTo( 0, scrollRef.current );
			doc.addEventListener(
				'click',
				function ( e ) {
					var t = e.target;
					// 预览里不跳转、不提交，只用来选中积木。
					e.preventDefault();
					var m = t.closest && t.closest( '.vc-m' );
					if ( m && m.id ) {
						fromPreviewRef.current = true;
						tabState[ 1 ]( 'modules' );
						setSelected( m.id.replace( /^vc-/, '' ) );
						return;
					}
					if ( t.closest && t.closest( 'footer' ) ) {
						setSelected( 'footer' );
					} else if ( t.closest && t.closest( 'header' ) ) {
						setSelected( 'header' );
					}
				},
				true
			);
			highlight( selected, false );
		}

		function highlight( id, scroll ) {
			var frame = frameRef.current;
			var doc;
			try {
				doc = frame && frame.contentDocument;
			} catch ( e ) {
				return;
			}
			if ( ! doc ) {
				return;
			}
			Array.prototype.forEach.call( doc.querySelectorAll( '.pc-de-selected' ), function ( n ) {
				n.classList.remove( 'pc-de-selected' );
			} );
			var target = null;
			if ( id === 'header' ) {
				target = doc.querySelector( 'body > header, .site-header, header' );
			} else if ( id === 'footer' ) {
				target = doc.querySelector( 'body > footer, .site-footer, footer' );
			} else if ( id ) {
				target = doc.getElementById( 'vc-' + id );
			}
			if ( target ) {
				target.classList.add( 'pc-de-selected' );
				if ( scroll ) {
					target.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}
			}
		}

		useEffect(
			function () {
				highlight( selected, ! fromPreviewRef.current );
				fromPreviewRef.current = false;
			},
			[ selected ]
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
					{ className: 'pc-de-top__btn', href: cfg.backUrl, title: S.back, 'aria-label': S.back },
					el( Icon, { name: 'exit' } )
				),
				el(
					'label',
					{ className: 'pc-de-page' },
					el( 'span', { className: 'screen-reader-text' }, S.page ),
					el(
						'select',
						{ value: 'home', onChange: function () {} },
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
				{ className: 'pc-de-top__center' },
				deviceButton( 'desktop', 'desktop', S.desktop ),
				deviceButton( 'mobile', 'smartphone', S.mobile ),
				deviceButton( 'full', 'editor-expand', S.full )
			),
			el(
				'div',
				{ className: 'pc-de-top__right' },
				el( 'button', { type: 'button', className: 'pc-de-top__btn', disabled: ! historyRef.current.past.length, onClick: undo, title: S.undo }, el( Icon, { name: 'undo' } ), el( 'span', null, S.undo ) ),
				el( 'button', { type: 'button', className: 'pc-de-top__btn', disabled: ! historyRef.current.future.length, onClick: redo, title: S.redo }, el( Icon, { name: 'redo' } ), el( 'span', null, S.redo ) ),
				el( 'a', { className: 'pc-de-top__btn', href: cfg.storeUrl, target: '_blank', rel: 'noopener' }, el( Icon, { name: 'visibility' } ), el( 'span', null, S.viewStore ) ),
				el(
					'button',
					{ type: 'button', className: 'pc-de-top__btn pc-de-top__save' + ( dirty ? ' is-dirty' : '' ), onClick: onSave, disabled: saving || busy },
					el( Icon, { name: 'saved' } ),
					el( 'span', null, saving ? S.saving : S.save )
				),
				el(
					'div',
					{ className: 'pc-de-publish' },
					el(
						'button',
						{ type: 'button', className: 'pc-de-top__publish', onClick: publish, disabled: busy || saving },
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
							{ className: 'pc-de-publish__menu', role: 'menu' },
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
						setSelected( key );
					},
				},
				el( Icon, { name: 'admin-post', className: 'pc-de-row__pin' } ),
				el( 'span', { className: 'pc-de-row__title' }, label )
			);
		}

		var list = el(
			'div',
			{ className: 'pc-de-list', role: 'list' },
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
							onClick: function () {
								tabState[ 1 ]( 'modules' );
								setSelected( id );
							},
						},
						titleOf( m )
					),
					hidden || partial ? el( 'span', { className: 'pc-de-row__flag', title: S.hide }, el( Icon, { name: 'hidden' } ) ) : null,
					el(
						'span',
						{ className: 'pc-de-row__actions' },
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
			panel = el( 'div', { className: 'pc-de-note' }, el( Icon, { name: 'admin-appearance' } ), el( 'p', null, S.globalNote ) );
		} else if ( selected === 'library' ) {
			panel = el(
				'div',
				{ className: 'pc-de-library' },
				el( 'h2', null, S.library ),
				el( 'p', { className: 'pc-de-hint' }, S.libraryHint ),
				cfg.library.map( function ( group ) {
					return el(
						'section',
						{ key: group.label },
						el( 'h3', null, group.label ),
						el(
							'div',
							{ className: 'pc-de-library__grid' },
							group.cards.map( function ( card ) {
								return el(
									'button',
									{
										key: card.block,
										type: 'button',
										className: 'pc-de-card',
										onClick: function () {
											insert( card.block );
										},
									},
									el( Icon, { name: card.icon } ),
									el( 'span', null, card.title )
								);
							} )
						)
					);
				} )
			);
		} else if ( selected === 'header' || selected === 'footer' ) {
			panel = el( 'div', { className: 'pc-de-note' }, el( Icon, { name: 'info-outline' } ), el( 'p', null, selected === 'header' ? S.headerNote : S.footerNote ) );
		} else if ( current ) {
			var def = defOf( current );
			var values = ui.withDefaults( def.defaults, current.attrs.settings );
			panel = el(
				'div',
				{ className: 'pc-de-settings__inner', key: current.attrs.moduleId },
				el( 'h2', { className: 'pc-de-settings__title' }, def.title ),
				ui.needsDeviceSwitch( def )
					? el(
						'div',
						{ className: 'pc-me-devices' },
						el( 'span', null, S.editing ),
						el(
							C.ButtonGroup,
							null,
							el( C.Button, { variant: settingsDevice === 'desktop' ? 'primary' : 'secondary', size: 'small', icon: 'desktop', onClick: function () { deviceState[ 1 ]( 'desktop' ); } }, data.strings.desktop ),
							el( C.Button, { variant: settingsDevice === 'mobile' ? 'primary' : 'secondary', size: 'small', icon: 'smartphone', onClick: function () { deviceState[ 1 ]( 'mobile' ); } }, data.strings.mobile )
						)
					)
					: null,
				el(
					C.Panel,
					null,
					ui.settingsPanels( {
						def: def,
						settings: values,
						device: settingsDevice,
						items: current.attrs.items,
						setSetting: function ( key, v ) {
							var next = Object.assign( {}, values );
							next[ key ] = v;
							updateModule( current.attrs.moduleId, { settings: next }, true );
						},
						setItems: function ( items ) {
							updateModule( current.attrs.moduleId, { items: items }, true );
						},
					} )
				)
			);
		} else {
			panel = el( 'div', { className: 'pc-de-note' }, el( Icon, { name: 'arrow-left-alt' } ), el( 'p', null, S.selectHint ) );
		}

		var side = el(
			'aside',
			{ className: 'pc-de-side' },
			el(
				'div',
				{ className: 'pc-de-tabs', role: 'tablist' },
				el( 'button', { type: 'button', role: 'tab', 'aria-selected': tab === 'modules', className: tab === 'modules' ? 'is-active' : '', onClick: function () { tabState[ 1 ]( 'modules' ); } }, S.tabModules ),
				el( 'button', { type: 'button', role: 'tab', 'aria-selected': tab === 'global', className: tab === 'global' ? 'is-active' : '', onClick: function () { tabState[ 1 ]( 'global' ); } }, S.tabGlobal )
			),
			el( 'div', { className: 'pc-de-cols' }, tab === 'modules' ? list : null, el( 'div', { className: 'pc-de-settings' }, panel ) )
		);

		var preview = el(
			'main',
			{ className: 'pc-de-preview' },
			el(
				'div',
				{ className: 'pc-de-frame pc-de-frame--' + device },
				el( 'iframe', { ref: frameRef, src: previewUrl, title: S.previewNote, onLoad: onFrameLoad } ),
				loading ? el( 'div', { className: 'pc-de-frame__loading' }, el( C.Spinner ), S.loadingPreview ) : null
			),
			el( 'p', { className: 'pc-de-preview__note' }, dirty ? S.unsaved + ' ' + S.previewNote : S.previewNote )
		);

		return el(
			'div',
			{ className: 'pc-de__app' + ( device === 'full' ? ' is-full' : '' ) },
			top,
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
