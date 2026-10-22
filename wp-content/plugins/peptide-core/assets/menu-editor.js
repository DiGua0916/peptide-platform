/**
 * 菜单编辑器（MENU-NAV-01），界面参照 Shopname 的“菜单导航 → 编辑菜单”。
 *
 * 数据：window.pcMenuEditor（菜单、接口路径、链接类型、可选的页面 / 商品 / 分类、文案）。
 * 保存：POST /peptide/v1/menus/{id}，带上打开时的修改标记，别人改过时返回 409。
 * 结构：一级目录 + 二级目录；每一项有导航名称、链接页面、设置（打开方式、nofollow、title、说明、颜色、图标、字体）。
 */
( function ( wp, cfg ) {
	'use strict';

	if ( ! wp || ! cfg ) {
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

	var OBJECT_TYPES = [ 'category', 'product', 'page', 'post', 'coupon' ];
	var BATCH_TYPES = [ 'category', 'page', 'product', 'post', 'coupon' ];

	var nextKey = 1;
	function withKeys( items ) {
		return ( items || [] ).map( function ( it ) {
			return Object.assign( {}, it, { _k: 'k' + nextKey++, children: withKeys( it.children ) } );
		} );
	}

	function strip( items ) {
		return items.map( function ( it ) {
			var out = Object.assign( {}, it, { children: strip( it.children || [] ) } );
			delete out._k;
			return out;
		} );
	}

	function blankStyle() {
		return { color: '', italic: '', bold: '', decoration: '', icon: 0 };
	}

	function newItem( title, link ) {
		return {
			_k: 'k' + nextKey++,
			id: 0,
			title: title || '',
			description: '',
			link: link || { type: 'none', id: 0, url: '' },
			target: '',
			nofollow: false,
			attrTitle: '',
			style: blankStyle(),
			children: [],
		};
	}

	function typeLabel( value ) {
		for ( var i = 0; i < cfg.types.length; i++ ) {
			if ( cfg.types[ i ].value === value ) {
				return cfg.types[ i ].label;
			}
		}
		return value;
	}

	function optionOf( type, id ) {
		var list = cfg.options[ type ] || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === id ) {
				return list[ i ];
			}
		}
		return null;
	}

	// ---------- 链接页面 ----------

	function LinkPicker( props ) {
		var link = props.link;
		var isObject = OBJECT_TYPES.indexOf( link.type ) !== -1;
		var current = isObject ? optionOf( link.type, link.id ) : null;
		return el(
			'div',
			{ className: 'pc-me-link' },
			el( C.SelectControl, {
				label: S.colLink,
				hideLabelFromVision: true,
				value: link.type,
				options: cfg.types,
				onChange: function ( type ) {
					props.onChange( { type: type, id: 0, url: '' }, null );
				},
				__nextHasNoMarginBottom: true,
			} ),
			isObject
				? el( C.ComboboxControl, {
						label: typeLabel( link.type ),
						hideLabelFromVision: true,
						placeholder: S.search,
						value: link.id ? String( link.id ) : null,
						options: ( cfg.options[ link.type ] || [] ).map( function ( o ) {
							return { value: String( o.id ), label: o.label };
						} ),
						onChange: function ( v ) {
							var id = v ? parseInt( v, 10 ) : 0;
							props.onChange( { type: link.type, id: id, url: '' }, optionOf( link.type, id ) );
						},
						__nextHasNoMarginBottom: true,
				  } )
				: null,
			'custom' === link.type
				? el( C.TextControl, {
						label: S.colLink,
						hideLabelFromVision: true,
						placeholder: S.urlPlaceholder,
						value: link.url || '',
						onChange: function ( v ) {
							props.onChange( { type: 'custom', id: 0, url: v }, null );
						},
						__nextHasNoMarginBottom: true,
				  } )
				: null,
			isObject && link.id && ( link.broken || ! current )
				? el( 'p', { className: 'pc-me-broken' }, S.broken )
				: null
		);
	}

	// ---------- 设置弹窗 ----------

	function SettingsModal( props ) {
		var draftState = useState( function () {
			return {
				target: props.item.target || '',
				nofollow: !! props.item.nofollow,
				attrTitle: props.item.attrTitle || '',
				description: props.item.description || '',
				style: Object.assign( blankStyle(), props.item.style || {} ),
			};
		} );
		var d = draftState[ 0 ];
		function set( patch ) {
			draftState[ 1 ]( Object.assign( {}, d, patch ) );
		}
		function setStyle( patch ) {
			set( { style: Object.assign( {}, d.style, patch ) } );
		}
		function chooseIcon() {
			var frame = wp.media( { title: S.icon, library: { type: 'image' }, multiple: false, button: { text: S.chooseImage } } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				var url = ( a.sizes && a.sizes.thumbnail && a.sizes.thumbnail.url ) || a.url;
				props.onIcon( a.id, url );
				setStyle( { icon: a.id } );
			} );
			frame.open();
		}
		var iconUrl = d.style.icon ? props.iconUrls[ d.style.icon ] : '';
		return el(
			C.Modal,
			{ title: S.settingsTitle, onRequestClose: props.onClose, className: 'pc-me-modal' },
			el( C.SelectControl, {
				label: S.target,
				value: d.target,
				options: [
					{ value: '', label: S.keep },
					{ value: '_self', label: S.sameWindow },
					{ value: '_blank', label: S.newWindow },
				],
				onChange: function ( v ) {
					set( { target: v } );
				},
			} ),
			el( C.SelectControl, {
				label: S.indexing,
				value: d.nofollow ? '1' : '',
				options: [
					{ value: '', label: S.follow },
					{ value: '1', label: S.nofollow },
				],
				onChange: function ( v ) {
					set( { nofollow: '1' === v } );
				},
			} ),
			el( C.TextControl, {
				label: S.attrTitle,
				value: d.attrTitle,
				maxLength: 200,
				onChange: function ( v ) {
					set( { attrTitle: v } );
				},
			} ),
			el( C.TextControl, {
				label: S.description,
				value: d.description,
				maxLength: 200,
				onChange: function ( v ) {
					set( { description: v } );
				},
			} ),
			el(
				'div',
				{ className: 'pc-me-field' },
				el( 'span', { className: 'pc-me-field__label' }, S.color ),
				el( 'input', {
					type: 'color',
					value: d.style.color || '#1d2327',
					onChange: function ( e ) {
						setStyle( { color: e.target.value } );
					},
					'aria-label': S.color,
				} ),
				el( 'code', null, d.style.color || '—' ),
				d.style.color
					? el( C.Button, { variant: 'link', onClick: function () {
							setStyle( { color: '' } );
					  } }, S.clearColor )
					: null
			),
			el(
				'div',
				{ className: 'pc-me-field' },
				el( 'span', { className: 'pc-me-field__label' }, S.icon ),
				iconUrl ? el( 'img', { className: 'pc-me-icon', src: iconUrl, alt: '' } ) : null,
				el( C.Button, { variant: 'secondary', onClick: chooseIcon }, S.chooseImage ),
				d.style.icon
					? el( C.Button, { variant: 'link', isDestructive: true, onClick: function () {
							setStyle( { icon: 0 } );
					  } }, S.removeImage )
					: null,
				el( 'p', { className: 'description' }, S.iconHelp )
			),
			el( C.SelectControl, {
				label: S.fontStyle,
				value: d.style.italic,
				options: [
					{ value: '', label: S.keep },
					{ value: 'normal', label: S.normal },
					{ value: 'italic', label: S.italic },
				],
				onChange: function ( v ) {
					setStyle( { italic: v } );
				},
			} ),
			el( C.SelectControl, {
				label: S.fontWeight,
				value: d.style.bold,
				options: [
					{ value: '', label: S.keep },
					{ value: 'normal', label: S.normal },
					{ value: 'bold', label: S.bold },
				],
				onChange: function ( v ) {
					setStyle( { bold: v } );
				},
			} ),
			el( C.SelectControl, {
				label: S.decoration,
				value: d.style.decoration,
				options: [
					{ value: '', label: S.keep },
					{ value: 'none', label: S.none },
					{ value: 'underline', label: S.underline },
					{ value: 'overline', label: S.overline },
					{ value: 'line-through', label: S.lineThrough },
				],
				onChange: function ( v ) {
					setStyle( { decoration: v } );
				},
			} ),
			el(
				'div',
				{ className: 'pc-me-modal__actions' },
				el( C.Button, { variant: 'tertiary', onClick: props.onClose }, S.cancel ),
				el( C.Button, { variant: 'primary', onClick: function () {
					props.onSave( d );
				} }, S.ok )
			)
		);
	}

	// ---------- 批量添加 ----------

	function BatchModal( props ) {
		var typeState = useState( 'category' );
		var type = typeState[ 0 ];
		var pickedState = useState( {} );
		var picked = pickedState[ 0 ];
		var queryState = useState( '' );
		var query = queryState[ 0 ].toLowerCase();
		var list = ( cfg.options[ type ] || [] ).filter( function ( o ) {
			return ! query || o.label.toLowerCase().indexOf( query ) !== -1;
		} );
		var count = Object.keys( picked ).length;
		function toggle( id, on ) {
			var next = Object.assign( {}, picked );
			if ( on ) {
				next[ id ] = true;
			} else {
				delete next[ id ];
			}
			pickedState[ 1 ]( next );
		}
		return el(
			C.Modal,
			{ title: props.top ? S.batchTitleTop : S.batchTitle, onRequestClose: props.onClose, className: 'pc-me-modal' },
			el( C.SelectControl, {
				label: S.batchType,
				value: type,
				options: BATCH_TYPES.map( function ( t ) {
					return { value: t, label: typeLabel( t ) };
				} ),
				onChange: function ( v ) {
					typeState[ 1 ]( v );
					pickedState[ 1 ]( {} );
				},
			} ),
			el( 'p', { className: 'description' }, S.batchHelp ),
			el( C.TextControl, {
				label: S.search,
				hideLabelFromVision: true,
				placeholder: S.search,
				value: queryState[ 0 ],
				onChange: queryState[ 1 ],
			} ),
			el(
				'div',
				{ className: 'pc-me-batch__bar' },
				el( C.CheckboxControl, {
					label: S.selectAll,
					checked: list.length > 0 && list.every( function ( o ) {
						return picked[ o.id ];
					} ),
					onChange: function ( on ) {
						var next = Object.assign( {}, picked );
						list.forEach( function ( o ) {
							if ( on ) {
								next[ o.id ] = true;
							} else {
								delete next[ o.id ];
							}
						} );
						pickedState[ 1 ]( next );
					},
				} ),
				el( 'span', null, S.selected.replace( '%d', count ) )
			),
			el(
				'div',
				{ className: 'pc-me-batch__list' },
				list.map( function ( o ) {
					return el( C.CheckboxControl, {
						key: o.id,
						label: o.label,
						checked: !! picked[ o.id ],
						onChange: function ( on ) {
							toggle( o.id, on );
						},
					} );
				} )
			),
			el(
				'div',
				{ className: 'pc-me-modal__actions' },
				el( C.Button, { variant: 'tertiary', onClick: props.onClose }, S.cancel ),
				el( C.Button, {
					variant: 'primary',
					disabled: ! count,
					onClick: function () {
						// 按列表顺序添加。
						var chosen = ( cfg.options[ type ] || [] ).filter( function ( o ) {
							return picked[ o.id ];
						} );
						props.onAdd(
							chosen.map( function ( o ) {
								return newItem( o.name || o.label, { type: type, id: o.id, url: '' } );
							} )
						);
					},
				}, S.ok )
			)
		);
	}

	// ---------- 编辑器 ----------

	function App() {
		var menu = cfg.menu;
		var nameState = useState( menu.name );
		var locationState = useState( menu.location || '' );
		var itemsState = useState( function () {
			return withKeys( menu.items );
		} );
		var items = itemsState[ 0 ];
		var dirtyState = useState( false );
		var dirty = dirtyState[ 0 ];
		var savingState = useState( false );
		var noticeState = useState( null );
		var modalState = useState( null );
		var modal = modalState[ 0 ];
		var iconState = useState( cfg.icons || {} );
		var modifiedRef = useRef( menu.modified );

		function change( next ) {
			itemsState[ 1 ]( next );
			dirtyState[ 1 ]( true );
			noticeState[ 1 ]( null );
		}

		// path：[一级序号] 或 [一级序号, 二级序号]。
		function update( path, patch ) {
			change(
				items.map( function ( it, i ) {
					if ( i !== path[ 0 ] ) {
						return it;
					}
					if ( path.length === 1 ) {
						return Object.assign( {}, it, patch );
					}
					return Object.assign( {}, it, {
						children: it.children.map( function ( c, j ) {
							return j === path[ 1 ] ? Object.assign( {}, c, patch ) : c;
						} ),
					} );
				} )
			);
		}

		function remove( path ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( S.confirmDelete ) ) {
				return;
			}
			if ( path.length === 1 ) {
				change( items.filter( function ( it, i ) {
					return i !== path[ 0 ];
				} ) );
				return;
			}
			update( [ path[ 0 ] ], {
				children: items[ path[ 0 ] ].children.filter( function ( c, j ) {
					return j !== path[ 1 ];
				} ),
			} );
		}

		function move( path, delta ) {
			var list = path.length === 1 ? items.slice() : items[ path[ 0 ] ].children.slice();
			var from = path[ path.length - 1 ];
			var to = from + delta;
			if ( to < 0 || to >= list.length ) {
				return;
			}
			var moved = list.splice( from, 1 )[ 0 ];
			list.splice( to, 0, moved );
			if ( path.length === 1 ) {
				change( list );
			} else {
				update( [ path[ 0 ] ], { children: list } );
			}
		}

		function addChildren( index, added ) {
			update( [ index ], { children: items[ index ].children.concat( added ) } );
		}

		function save() {
			savingState[ 1 ]( true );
			noticeState[ 1 ]( null );
			apiFetch( {
				path: cfg.restPath,
				method: 'POST',
				data: { name: nameState[ 0 ], location: locationState[ 0 ], items: strip( items ), modified: modifiedRef.current },
			} )
				.then( function ( res ) {
					modifiedRef.current = res.modified;
					itemsState[ 1 ]( withKeys( res.items ) );
					nameState[ 1 ]( res.name );
					locationState[ 1 ]( res.location || '' );
					dirtyState[ 1 ]( false );
					noticeState[ 1 ]( { status: 'success', text: S.saved } );
				} )
				.catch( function ( err ) {
					noticeState[ 1 ]( { status: 'error', text: err && err.code === 'pc_menu_conflict' ? S.conflict : ( err && err.message ) || String( err ) } );
				} )
				.finally( function () {
					savingState[ 1 ]( false );
				} );
		}

		useEffect(
			function () {
				if ( ! dirty ) {
					return undefined;
				}
				function onLeave( e ) {
					e.preventDefault();
					e.returnValue = '';
				}
				window.addEventListener( 'beforeunload', onLeave );
				return function () {
					window.removeEventListener( 'beforeunload', onLeave );
				};
			},
			[ dirty ]
		);

		function row( item, path, siblings ) {
			var top = path.length === 1;
			var index = path[ path.length - 1 ];
			var actions = [];
			if ( top ) {
				actions.push( el( C.Button, { key: 'b', variant: 'link', onClick: function () {
					modalState[ 1 ]( { kind: 'batch', index: path[ 0 ] } );
				} }, S.batchAdd ) );
				actions.push( el( C.Button, { key: 'c', variant: 'link', onClick: function () {
					addChildren( path[ 0 ], [ newItem( S.newItem ) ] );
				} }, S.addChild ) );
			}
			actions.push( el( C.Button, { key: 's', variant: 'link', onClick: function () {
				modalState[ 1 ]( { kind: 'settings', path: path } );
			} }, S.settings ) );
			actions.push( el( C.Button, { key: 'd', variant: 'link', isDestructive: true, onClick: function () {
				remove( path );
			} }, S.remove ) );
			actions.push( el( C.Button, { key: 'u', icon: 'arrow-up-alt2', label: S.moveUp, size: 'small', disabled: index === 0, onClick: function () {
				move( path, -1 );
			} } ) );
			actions.push( el( C.Button, { key: 'w', icon: 'arrow-down-alt2', label: S.moveDown, size: 'small', disabled: index === siblings - 1, onClick: function () {
				move( path, 1 );
			} } ) );
			var s = item.style || {};
			var styled = s.color || s.italic || s.bold || s.decoration || s.icon || item.target || item.nofollow || item.attrTitle || item.description;
			return el(
				'div',
				{ key: item._k, className: 'pc-me-row' + ( top ? '' : ' pc-me-row--child' ), role: 'row' },
				el(
					'div',
					{ className: 'pc-me-cell pc-me-cell--name', role: 'cell' },
					top ? null : el( 'span', { className: 'pc-me-branch', 'aria-hidden': true }, '└' ),
					el( C.TextControl, {
						label: S.colName,
						hideLabelFromVision: true,
						value: item.title,
						maxLength: 80,
						onChange: function ( v ) {
							update( path, { title: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					styled ? el( 'span', { className: 'pc-me-dot', title: S.settings } ) : null
				),
				el(
					'div',
					{ className: 'pc-me-cell', role: 'cell' },
					el( LinkPicker, {
						link: item.link,
						onChange: function ( link, option ) {
							var patch = { link: link };
							// 名称还是空的、默认名或上一个对象的名称时，跟着换成新对象的名称。
							var prev = optionOf( item.link.type, item.link.id );
							if ( option && ( ! item.title || item.title === S.newItem || ( prev && item.title === prev.name ) ) ) {
								patch.title = option.name || option.label;
							}
							update( path, patch );
						},
					} )
				),
				el( 'div', { className: 'pc-me-cell pc-me-cell--actions', role: 'cell' }, actions )
			);
		}

		var rows = [];
		items.forEach( function ( it, i ) {
			rows.push( row( it, [ i ], items.length ) );
			it.children.forEach( function ( c, j ) {
				rows.push( row( c, [ i, j ], it.children.length ) );
			} );
		} );

		var modalEl = null;
		if ( modal && modal.kind === 'settings' ) {
			var target = modal.path.length === 1 ? items[ modal.path[ 0 ] ] : items[ modal.path[ 0 ] ].children[ modal.path[ 1 ] ];
			modalEl = el( SettingsModal, {
				item: target,
				iconUrls: iconState[ 0 ],
				onIcon: function ( id, url ) {
					var next = Object.assign( {}, iconState[ 0 ] );
					next[ id ] = url;
					iconState[ 1 ]( next );
				},
				onClose: function () {
					modalState[ 1 ]( null );
				},
				onSave: function ( d ) {
					update( modal.path, d );
					modalState[ 1 ]( null );
				},
			} );
		} else if ( modal && modal.kind === 'batch' ) {
			modalEl = el( BatchModal, {
				top: modal.index === -1,
				onClose: function () {
					modalState[ 1 ]( null );
				},
				onAdd: function ( added ) {
					if ( modal.index === -1 ) {
						change( items.concat( added ) );
					} else {
						addChildren( modal.index, added );
					}
					modalState[ 1 ]( null );
				},
			} );
		}

		var notice = noticeState[ 0 ];
		return el(
			Fragment,
			null,
			el( 'a', { className: 'pc-me-back', href: cfg.backUrl }, '← ' + S.back ),
			el( 'h1', { className: 'pc-me-title' }, S.title ),
			notice
				? el( C.Notice, { status: notice.status, isDismissible: true, onRemove: function () {
						noticeState[ 1 ]( null );
				  } }, notice.text )
				: null,
			el(
				'section',
				{ className: 'pc-me-card pc-me-card--meta' },
				el( C.TextControl, {
					label: S.name,
					value: nameState[ 0 ],
					maxLength: 40,
					help: nameState[ 0 ].length + '/40',
					onChange: function ( v ) {
						nameState[ 1 ]( v );
						dirtyState[ 1 ]( true );
					},
				} ),
				el( C.SelectControl, {
					label: S.location,
					value: locationState[ 0 ],
					help: menu.fixed ? S.locHelp + ' ' + S.fixedNote : S.locHelp,
					options: [
						{ value: '', label: S.locNone },
						{ value: 'header', label: S.locHeader },
						{ value: 'footer', label: S.locFooter },
					],
					onChange: function ( v ) {
						locationState[ 1 ]( v );
						dirtyState[ 1 ]( true );
					},
				} )
			),
			el(
				'section',
				{ className: 'pc-me-card' },
				el( 'h2', null, S.structure, el( 'span', { className: 'pc-me-hint' }, S.structureHelp ) ),
				el(
					'div',
					{ className: 'pc-me-table', role: 'table' },
					el(
						'div',
						{ className: 'pc-me-row pc-me-row--head', role: 'row' },
						el( 'div', { className: 'pc-me-cell', role: 'columnheader' }, S.colName ),
						el( 'div', { className: 'pc-me-cell', role: 'columnheader' }, S.colLink ),
						el( 'div', { className: 'pc-me-cell', role: 'columnheader' }, S.colActions )
					),
					rows.length ? rows : el( 'p', { className: 'pc-me-empty' }, S.empty )
				),
				el(
					'div',
					{ className: 'pc-me-add' },
					el( C.Button, { variant: 'secondary', icon: 'plus-alt2', onClick: function () {
						change( items.concat( [ newItem( S.newItem ) ] ) );
					} }, S.addItem ),
					el( C.Button, { variant: 'tertiary', onClick: function () {
						modalState[ 1 ]( { kind: 'batch', index: -1 } );
					} }, S.batchAddTop )
				)
			),
			el(
				'div',
				{ className: 'pc-me-savebar' },
				dirty ? el( 'span', { className: 'pc-me-unsaved' }, S.unsaved ) : null,
				el( C.Button, { variant: 'primary', isBusy: savingState[ 0 ], disabled: savingState[ 0 ], onClick: save }, savingState[ 0 ] ? S.saving : S.save )
			),
			modalEl
		);
	}

	var root = document.getElementById( 'pc-menu-editor' );
	if ( root ) {
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( App ) );
		} else {
			wp.element.render( el( App ), root );
		}
	}
} )( window.wp, window.pcMenuEditor );
