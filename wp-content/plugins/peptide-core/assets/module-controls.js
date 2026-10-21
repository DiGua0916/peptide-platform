/**
 * 装修模块的字段控件（EDIT-BLOCKS-03 / 04）。装修编辑器（YY 式界面）和区块编辑器共用。
 * 不需要构建，直接使用 WordPress 自带的 wp.* 全局包；导出到 window.pcModuleUI。
 *
 * 每个模块的设置面板按服务端字段定义（window.pcModules）自动生成：
 * - 字段分组成面板（内容、媒体、布局、播放、按钮、颜色、显示与间距）；
 * - device 字段分别编辑电脑 / 手机两个值，手机值为 null 表示沿用电脑；
 * - 重复条目可以添加、复制、删除、上下移动、隐藏。
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	// 媒体库：使用独立的 media-utils 组件，在区块编辑器外也能用。
	var MediaUpload = wp.mediaUtils && wp.mediaUtils.MediaUpload ? wp.mediaUtils.MediaUpload : wp.blockEditor.MediaUpload;
	var C = wp.components;
	var useSelect = wp.data.useSelect;
	var S = data.strings;

	var PALETTE = [
		{ name: 'Black', color: '#000000' },
		{ name: 'White', color: '#ffffff' },
		{ name: 'Ink', color: '#1d2327' },
		{ name: 'Gray', color: '#f3f4f6' },
		{ name: 'Teal', color: '#0f766e' },
		{ name: 'Blue', color: '#2563eb' },
		{ name: 'Orange', color: '#ee7d33' },
	];

	function uid() {
		return 'm' + Math.random().toString( 36 ).slice( 2, 12 ).replace( /[^a-z0-9]/g, '0' ).padEnd( 10, '0' );
	}

	function clone( v ) {
		return v === undefined ? undefined : JSON.parse( JSON.stringify( v ) );
	}

	function fmt( text, n ) {
		return String( text ).replace( '%d', n );
	}

	function isDeviceValue( v ) {
		return v && typeof v === 'object' && Object.prototype.hasOwnProperty.call( v, 'desktop' );
	}

	function hasDeviceFields( fields ) {
		return fields.some( function ( f ) {
			return f.device || ( f.type === 'repeater' && hasDeviceFields( f.fields || [] ) );
		} );
	}

	function newItem( defaults ) {
		return { id: uid(), enabled: true, settings: clone( defaults ) };
	}

	function withDefaults( defaults, values ) {
		return Object.assign( {}, clone( defaults ), values || {} );
	}

	function visible( field, values ) {
		if ( ! field.show_if ) {
			return true;
		}
		return Object.keys( field.show_if ).every( function ( key ) {
			return values[ key ] === field.show_if[ key ];
		} );
	}

	function options( map, withNone ) {
		var out = withNone ? [ { value: '', label: S.none } ] : [];
		Object.keys( map || {} ).forEach( function ( k ) {
			out.push( { value: k, label: map[ k ] } );
		} );
		return out;
	}

	// ---------- 媒体 ----------

	function MediaThumb( props ) {
		var media = useSelect(
			function ( select ) {
				return props.id ? select( 'core' ).getMedia( props.id ) : null;
			},
			[ props.id ]
		);
		if ( ! props.id ) {
			return null;
		}
		if ( ! media ) {
			return el( C.Spinner );
		}
		if ( props.kind === 'video' ) {
			return el( 'p', { className: 'pc-me-file' }, media.title && media.title.rendered ? media.title.rendered : '#' + props.id );
		}
		var url = media.media_details && media.media_details.sizes && media.media_details.sizes.medium ? media.media_details.sizes.medium.source_url : media.source_url;
		if ( props.focal ) {
			return el( C.FocalPointPicker, {
				url: url,
				value: { x: ( props.focal.xPercent || 50 ) / 100, y: ( props.focal.yPercent || 50 ) / 100 },
				onChange: function ( p ) {
					props.onFocal( { xPercent: Math.round( p.x * 100 ), yPercent: Math.round( p.y * 100 ) } );
				},
				label: S.focal,
			} );
		}
		return el( 'img', { className: 'pc-me-thumb', src: url, alt: '' } );
	}

	function MediaPicker( props ) {
		var kind = props.kind || 'image';
		var id = props.value && props.value.attachmentId ? props.value.attachmentId : null;
		return el(
			'div',
			{ className: 'pc-me-media' },
			props.label ? el( 'p', { className: 'pc-me-label' }, props.label ) : null,
			el( MediaThumb, { id: id, kind: kind, focal: props.focal, onFocal: props.onFocal } ),
			! id && props.value && props.value.assetKey ? el( 'p', { className: 'pc-me-file' }, props.value.assetKey ) : null,
			el(
				Fragment,
				null,
				el( MediaUpload, {
					allowedTypes: [ kind ],
					value: id,
					onSelect: function ( m ) {
						var next = Object.assign( {}, props.value || {}, { attachmentId: m.id, assetKey: null } );
						if ( kind === 'image' && next.alt === '' && m.alt ) {
							next.alt = m.alt;
						}
						props.onChange( next );
					},
					render: function ( o ) {
						return el(
							'div',
							{ className: 'pc-me-row' },
							el( C.Button, { variant: 'secondary', onClick: o.open }, id ? S.replace : ( kind === 'video' ? S.chooseVideo : S.chooseImage ) ),
							id || ( props.value && props.value.assetKey )
								? el(
									C.Button,
									{
										variant: 'tertiary',
										isDestructive: true,
										onClick: function () {
											props.onClear();
										},
									},
									S.clear
								)
								: null
						);
					},
				} )
			),
			props.help ? el( 'p', { className: 'components-base-control__help' }, props.help ) : null
		);
	}

	function emptyImage() {
		return { attachmentId: null, assetKey: null, alt: '' };
	}

	function ImageField( props ) {
		var v = props.value || emptyImage();
		return el(
			Fragment,
			null,
			el( MediaPicker, {
				label: props.field.label,
				help: props.field.help,
				value: v,
				onChange: props.onChange,
				onClear: function () {
					props.onChange( emptyImage() );
				},
			} ),
			v.attachmentId || v.assetKey
				? el( C.TextControl, {
					label: S.alt,
					value: v.alt || '',
					onChange: function ( alt ) {
						props.onChange( Object.assign( {}, v, { alt: alt } ) );
					},
				} )
				: null
		);
	}

	function MediaField( props ) {
		var v = props.value || { desktop: Object.assign( emptyImage(), { fit: 'cover', focalPoint: { xPercent: 50, yPercent: 50 } } ), mobile: null };
		function set( device, next ) {
			var out = Object.assign( {}, v );
			out[ device ] = next;
			props.onChange( out );
		}
		function part( device, label ) {
			var cur = v[ device ];
			var base = cur || { attachmentId: null, assetKey: null, alt: '', fit: v.desktop.fit, focalPoint: { xPercent: 50, yPercent: 50 } };
			return el(
				'div',
				{ className: 'pc-me-media-part' },
				el( MediaPicker, {
					label: label,
					value: base,
					focal: cur && cur.attachmentId ? cur.focalPoint : null,
					onFocal: function ( fp ) {
						set( device, Object.assign( {}, base, { focalPoint: fp } ) );
					},
					onChange: function ( next ) {
						set( device, next );
					},
					onClear: function () {
						set( device, device === 'mobile' ? null : Object.assign( {}, base, emptyImage() ) );
					},
				} ),
				cur && ( cur.attachmentId || cur.assetKey )
					? el( C.TextControl, {
						label: S.alt,
						value: cur.alt || '',
						onChange: function ( alt ) {
							set( device, Object.assign( {}, cur, { alt: alt } ) );
						},
					} )
					: null
			);
		}
		return el(
			'div',
			{ className: 'pc-me-mediafield' },
			el( 'p', { className: 'pc-me-label pc-me-label--strong' }, props.field.label ),
			props.field.help ? el( 'p', { className: 'components-base-control__help' }, props.field.help ) : null,
			part( 'desktop', S.desktopImage ),
			part( 'mobile', S.mobileImage ),
			el( C.SelectControl, {
				label: S.fit,
				value: v.desktop.fit || 'cover',
				options: [
					{ value: 'cover', label: S.fitCover },
					{ value: 'contain', label: S.fitContain },
				],
				onChange: function ( fit ) {
					var out = Object.assign( {}, v, { desktop: Object.assign( {}, v.desktop, { fit: fit } ) } );
					if ( out.mobile ) {
						out.mobile = Object.assign( {}, out.mobile, { fit: fit } );
					}
					props.onChange( out );
				},
			} )
		);
	}

	// ---------- 单个字段 ----------

	function single( field, value, onChange, ctx ) {
		var f = field;
		switch ( f.type ) {
			case 'text':
				return el( C.TextControl, { label: f.label, help: f.help, value: value || '', onChange: onChange } );
			case 'textarea':
				return el( C.TextareaControl, { label: f.label, help: f.help, value: value || '', rows: 3, onChange: onChange } );
			case 'html':
				return el( C.TextareaControl, { label: f.label, help: f.help || S.htmlHelp, value: value || '', rows: 5, onChange: onChange } );
			case 'code':
				return el( C.TextareaControl, { className: 'pc-me-code', label: f.label, help: f.help, value: value || '', rows: 10, onChange: onChange } );
			case 'url':
				return el( C.TextControl, { label: f.label, help: f.help, type: 'text', placeholder: '/shop/  ·  https://…', value: value || '', onChange: onChange } );
			case 'toggle':
				return el( C.ToggleControl, { label: f.label, help: f.help, checked: !! value, onChange: onChange } );
			case 'select':
				return el( C.SelectControl, { label: f.label, help: f.help, value: value, options: options( f.options ), onChange: onChange } );
			case 'icon':
				return el( C.SelectControl, {
					label: f.label,
					value: value || '',
					options: [ { value: '', label: S.none } ].concat(
						data.icons.map( function ( i ) {
							return { value: i, label: i };
						} )
					),
					onChange: onChange,
				} );
			case 'number':
				if ( f.max !== undefined && f.min !== undefined && f.max - f.min <= 20000 ) {
					return el( C.RangeControl, {
						label: f.label,
						help: f.help,
						value: value === null || value === undefined ? undefined : value,
						min: f.min,
						max: f.max,
						step: f.step || 1,
						allowReset: f.default === null,
						resetFallbackValue: undefined,
						onChange: function ( n ) {
							onChange( n === undefined || n === null || isNaN( n ) ? null : n );
						},
					} );
				}
				return el( C.TextControl, {
					label: f.label,
					type: 'number',
					value: value === null || value === undefined ? '' : String( value ),
					onChange: function ( n ) {
						onChange( n === '' ? null : Number( n ) );
					},
				} );
			case 'color':
				return el(
					C.BaseControl,
					{ label: f.label, help: f.help, className: 'pc-me-color' },
					el( C.ColorPalette, {
						colors: PALETTE,
						value: value || undefined,
						clearable: true,
						onChange: function ( c ) {
							onChange( c || '' );
						},
					} )
				);
			case 'image':
				return el( ImageField, { field: f, value: value, onChange: onChange } );
			case 'media':
				return el( MediaField, { field: f, value: value, onChange: onChange } );
			case 'video':
				return el( MediaPicker, {
					kind: 'video',
					label: f.label,
					value: value || { attachmentId: null },
					onChange: function ( v ) {
						onChange( { attachmentId: v.attachmentId } );
					},
					onClear: function () {
						onChange( { attachmentId: null } );
					},
				} );
			case 'link':
				var link = value || { label: '', href: '', target: '_self' };
				var setLink = function ( key, v ) {
					var next = Object.assign( {}, link );
					next[ key ] = v;
					onChange( next );
				};
				return el(
					'div',
					{ className: 'pc-me-link' },
					el( 'p', { className: 'pc-me-label pc-me-label--strong' }, f.label ),
					el( C.TextControl, { label: S.label, value: link.label || '', onChange: function ( v ) { setLink( 'label', v ); } } ),
					el( C.TextControl, { label: S.href, placeholder: '/shop/  ·  https://…', value: link.href || '', onChange: function ( v ) { setLink( 'href', v ); } } ),
					el( C.ToggleControl, { label: S.newTab, checked: link.target === '_blank', onChange: function ( v ) { setLink( 'target', v ? '_blank' : '_self' ); } } )
				);
			case 'menu':
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.menus ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'category':
				return el( C.SelectControl, { label: f.label, help: f.help, value: value ? String( value ) : '', options: [ { value: '', label: S.none } ].concat( data.categories ), onChange: function ( v ) { onChange( v ? Number( v ) : null ); } } );
			case 'product':
				return el( C.ComboboxControl, {
					label: f.label,
					value: value ? String( value ) : null,
					options: data.products,
					placeholder: S.searchProduct,
					onChange: function ( v ) {
						onChange( v ? Number( v ) : null );
					},
				} );
			case 'repeater':
				return el(
					'div',
					{ className: 'pc-me-subitems' },
					el( 'p', { className: 'pc-me-label pc-me-label--strong' }, f.label ),
					el( ItemsEditor, {
						fields: f.fields || [],
						items: value || [],
						defaults: defaultsFor( f.fields || [] ),
						min: 0,
						max: f.max || 20,
						titleField: '',
						device: ctx.device,
						onChange: onChange,
					} )
				);
		}
		return null;
	}

	function defaultsFor( fields ) {
		var out = {};
		fields.forEach( function ( f ) {
			var d = f.default;
			if ( f.type === 'repeater' && d === null ) {
				d = [];
			}
			if ( f.type === 'link' && d === null ) {
				d = { label: '', href: '', target: '_self' };
			}
			if ( f.type === 'image' && d === null ) {
				d = emptyImage();
			}
			if ( f.device && ! isDeviceValue( d ) ) {
				d = { desktop: d, mobile: null };
			}
			out[ f.key ] = d;
		} );
		return out;
	}

	/**
	 * 一个字段（处理电脑 / 手机两个值）。
	 */
	function control( field, value, onChange, ctx ) {
		if ( ! field.device ) {
			return single( field, value, onChange, ctx );
		}
		var v = isDeviceValue( value ) ? value : { desktop: value, mobile: null };
		var device = ctx.device;
		if ( device === 'mobile' && ( v.mobile === null || v.mobile === undefined ) ) {
			return el(
				'div',
				{ className: 'pc-me-inherit' },
				el( 'p', { className: 'pc-me-label' }, field.label ),
				el( 'p', { className: 'pc-me-inherit__note' }, S.sameAsDesk ),
				el(
					C.Button,
					{
						variant: 'secondary',
						size: 'small',
						onClick: function () {
							onChange( { desktop: v.desktop, mobile: clone( v.desktop ) } );
						},
					},
					S.mobile + ' ✎'
				)
			);
		}
		return el(
			'div',
			{ className: 'pc-me-device-field' },
			single(
				Object.assign( {}, field, { label: field.label + ' · ' + ( device === 'mobile' ? S.mobile : S.desktop ) } ),
				v[ device ],
				function ( n ) {
					var next = Object.assign( {}, v );
					next[ device ] = n;
					onChange( next );
				},
				ctx
			),
			device === 'mobile'
				? el(
					C.Button,
					{
						variant: 'link',
						onClick: function () {
							onChange( { desktop: v.desktop, mobile: null } );
						},
					},
					S.sameAsDesk
				)
				: null
		);
	}

	function fieldList( fields, values, setValue, ctx ) {
		return fields
			.filter( function ( f ) {
				return visible( f, values );
			} )
			.map( function ( f ) {
				var overridden = ctx.overridden && ctx.overridden( f.key );
				return el(
					'div',
					{ key: f.key, className: 'pc-me-field pc-me-field--' + f.type + ( overridden ? ' is-overridden' : '' ) },
					control( f, values[ f.key ], function ( v ) {
						setValue( f.key, v );
					}, ctx ),
					overridden
						? el(
							'p',
							{ className: 'pc-me-override' },
							el( 'span', null, S.mobileOnly ),
							el( C.Button, { variant: 'link', onClick: function () { ctx.reset( f.key ); } }, S.sameAsDesk )
						)
						: null
				);
			} );
	}

	// ---------- 重复条目 ----------

	function ItemsEditor( props ) {
		var items = props.items || [];
		// 展开的条目：传入 openId / onOpen 时由外部控制（装修编辑器据此在预览里高亮对应内容）。
		var openState = useState( null );
		var controlled = typeof props.onOpen === 'function';
		var open = controlled ? props.openId : openState[ 0 ];
		var setOpen = controlled ? props.onOpen : openState[ 1 ];

		function update( index, next ) {
			var list = items.slice();
			list[ index ] = next;
			props.onChange( list );
		}
		function move( index, dir ) {
			var to = index + dir;
			if ( to < 0 || to >= items.length ) {
				return;
			}
			var list = items.slice();
			var tmp = list[ to ];
			list[ to ] = list[ index ];
			list[ index ] = tmp;
			props.onChange( list );
		}
		function duplicate( index ) {
			if ( items.length >= props.max ) {
				return;
			}
			var copy = clone( items[ index ] );
			copy.id = uid();
			( copy.settings && copy.settings.spots ? copy.settings.spots : [] ).forEach( function ( s ) {
				s.id = uid();
			} );
			var list = items.slice();
			list.splice( index + 1, 0, copy );
			props.onChange( list );
			setOpen( copy.id );
		}
		function remove( index ) {
			if ( items.length <= props.min ) {
				return;
			}
			// eslint-disable-next-line no-alert -- 后台已用页面内对话框替换原生确认框。
			if ( ! window.confirm( S.confirmDel ) ) {
				return;
			}
			var list = items.slice();
			list.splice( index, 1 );
			props.onChange( list );
		}
		function add() {
			if ( items.length >= props.max ) {
				return;
			}
			var item = newItem( props.defaults );
			props.onChange( items.concat( [ item ] ) );
			setOpen( item.id );
		}

		return el(
			'div',
			{ className: 'pc-me-items' },
			items.map( function ( item, index ) {
				var values = withDefaults( props.defaults, item.settings );
				var title = props.titleField && values[ props.titleField ] ? values[ props.titleField ] : fmt( S.item, index + 1 );
				var isOpen = open === item.id;
				return el(
					'div',
					{ key: item.id || index, className: 'pc-me-item' + ( item.enabled === false ? ' is-hidden' : '' ) + ( isOpen ? ' is-open' : '' ) },
					el(
						'div',
						{ className: 'pc-me-item__head' },
						el(
							'button',
							{
								type: 'button',
								className: 'pc-me-item__title',
								'aria-expanded': isOpen,
								onClick: function () {
									setOpen( isOpen ? null : item.id );
								},
							},
							el( 'span', null, title ),
							item.enabled === false ? el( 'em', null, ' (' + S.hidden + ')' ) : null
						),
						el( C.Button, { icon: 'arrow-up-alt2', label: S.moveUp, size: 'small', disabled: index === 0, onClick: function () { move( index, -1 ); } } ),
						el( C.Button, { icon: 'arrow-down-alt2', label: S.moveDown, size: 'small', disabled: index === items.length - 1, onClick: function () { move( index, 1 ); } } ),
						el( C.Button, { icon: 'admin-page', label: S.duplicate, size: 'small', disabled: items.length >= props.max, onClick: function () { duplicate( index ); } } ),
						el( C.Button, {
							icon: item.enabled === false ? 'hidden' : 'visibility',
							label: item.enabled === false ? S.show : S.hide,
							size: 'small',
							onClick: function () {
								update( index, Object.assign( {}, item, { enabled: item.enabled === false } ) );
							},
						} ),
						el( C.Button, { icon: 'trash', label: S.remove, size: 'small', isDestructive: true, disabled: items.length <= props.min, onClick: function () { remove( index ); } } )
					),
					isOpen
						? el(
							'div',
							{ className: 'pc-me-item__body' },
							fieldList( props.fields, values, function ( key, v ) {
								var settings = Object.assign( {}, values );
								settings[ key ] = v;
								update( index, Object.assign( {}, item, { settings: settings } ) );
							}, { device: props.device } )
						)
						: null
				);
			} ),
			el(
				'div',
				{ className: 'pc-me-items__foot' },
				el( C.Button, { variant: 'secondary', icon: 'plus-alt2', disabled: items.length >= props.max, onClick: add }, S.add ),
				items.length >= props.max ? el( 'span', { className: 'pc-me-hint' }, fmt( S.maxItems, props.max ) ) : null,
				props.min > 0 && items.length <= props.min ? el( 'span', { className: 'pc-me-hint' }, fmt( S.minItems, props.min ) ) : null
			)
		);
	}

	/**
	 * 一个模块的全部设置面板（按分组）+ 条目面板。
	 * props: def, settings, setSetting(key, value), items, setItems(list), device
	 */
	function settingsPanels( props ) {
		var def = props.def;
		var ctx = { device: props.device, overridden: props.overridden, reset: props.resetOverride };
		var panels = Object.keys( data.groups )
			.map( function ( group ) {
				var fields = def.settings.filter( function ( f ) {
					return ( f.group || 'content' ) === group;
				} );
				if ( ! fields.length ) {
					return null;
				}
				return el(
					C.PanelBody,
					{ key: group, title: data.groups[ group ], initialOpen: group === 'content' },
					fieldList( fields, props.settings, props.setSetting, ctx )
				);
			} )
			.filter( Boolean );

		if ( def.items ) {
			panels.splice(
				1,
				0,
				el(
					C.PanelBody,
					{ key: 'items', title: def.items.label + ' (' + ( props.items || [] ).length + ')', initialOpen: true },
					el( ItemsEditor, {
						fields: def.items.fields,
						items: props.items || [],
						defaults: def.items.defaults,
						min: def.items.min || 0,
						max: def.items.max || 20,
						titleField: def.items.title_field,
						device: props.device,
						onChange: props.setItems,
						openId: props.openItem,
						onOpen: props.onOpenItem,
					} )
				)
			);
		}
		return panels;
	}

	function needsDeviceSwitch( def ) {
		var all = def.settings.concat( def.items ? def.items.fields : [] );
		return hasDeviceFields( all ) || all.some( function ( f ) {
			return f.type === 'media';
		} );
	}

	/**
	 * 新模块的初始属性：ID、默认设置、默认条目（含各条目自己的默认值，例如左、右、左）。
	 */
	function initialAttributes( def ) {
		var init = { moduleId: uid(), enabled: true, settings: clone( def.defaults ), items: [] };
		if ( def.items ) {
			for ( var i = 0; i < ( def.items.default_count || 0 ); i++ ) {
				var row = newItem( def.items.defaults );
				Object.assign( row.settings, clone( ( def.items.default_items || [] )[ i ] || {} ) );
				init.items.push( row );
			}
		}
		return init;
	}

	window.pcModuleUI = {
		el: el,
		uid: uid,
		clone: clone,
		withDefaults: withDefaults,
		newItem: newItem,
		fieldList: fieldList,
		ItemsEditor: ItemsEditor,
		settingsPanels: settingsPanels,
		needsDeviceSwitch: needsDeviceSwitch,
		initialAttributes: initialAttributes,
	};
} )( window.wp, window.pcModules );
