/**
 * 在 WordPress 区块编辑器里注册装修模块（EDIT-BLOCKS-03）。字段控件来自 module-controls.js（window.pcModuleUI）。
 * 主要的编辑入口是“店铺装修 → 主题装修 → 装修”打开的 YY 式编辑器；这里保证同一份数据也能在区块编辑器里打开。
 */
( function ( wp, data, ui ) {
	'use strict';

	if ( ! wp || ! data || ! ui || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useCallback = wp.element.useCallback;
	var be = wp.blockEditor;
	var C = wp.components;
	var useSelect = wp.data.useSelect;
	var SSR = wp.serverSideRender;
	var S = data.strings;

	function Edit( props ) {
		var def = data.modules[ props.name ];
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var deviceState = useState( 'desktop' );
		var device = deviceState[ 0 ];
		var metadataPreviewState = useState( 0 );
		var refreshMetadataPreview = useCallback( function () {
			metadataPreviewState[ 1 ]( function ( count ) { return count + 1; } );
		}, [] );

		var others = useSelect(
			function ( select ) {
				var ids = [];
				select( 'core/block-editor' ).getBlocks().forEach( function ( b ) {
					if ( b.clientId !== props.clientId && b.attributes && b.attributes.moduleId ) {
						ids.push( b.attributes.moduleId );
					}
				} );
				return ids.join( ',' );
			},
			[ props.clientId ]
		);
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		// 新插入的模块写入默认值；复制出来的模块换新 ID。
		useEffect(
			function () {
				var taken = others ? others.split( ',' ) : [];
				if ( ! attrs.moduleId ) {
					setAttributes( ui.initialAttributes( def ) );
				} else if ( taken.indexOf( attrs.moduleId ) !== -1 ) {
					setAttributes( {
						moduleId: ui.uid(),
						items: ( attrs.items || [] ).map( function ( it ) {
							return Object.assign( {}, it, { id: ui.uid() } );
						} ),
					} );
				}
			},
			[ others ]
		);

		var settings = ui.withDefaults( def.defaults, attrs.settings );
		var panels = ui.settingsPanels( {
			def: def,
			blogOwner: JSON.stringify( [ 'block', postId, props.clientId, attrs.moduleId ] ),
			pairedDeviceSafe: function ( key ) { return ! Object.prototype.hasOwnProperty.call( ( attrs.mobile || {} ).settings || {}, key ); },
			settings: settings,
			device: device,
			items: attrs.items,
			metadataDocumentId: postId,
			metadataMode: settings.youtubeMode,
			onPreviewRefresh: refreshMetadataPreview,
			setSetting: function ( key, v ) {
				var next = Object.assign( {}, settings );
				next[ key ] = v;
				setAttributes( { settings: next } );
			},
			setItems: function ( list ) {
				setAttributes( { items: list } );
			},
		} );

		var deviceSwitch = ui.needsDeviceSwitch( def )
			? el(
				'div',
				{ className: 'pc-me-devices' },
				el( 'span', null, S.editing ),
				el(
					C.ButtonGroup,
					null,
					el( C.Button, { variant: device === 'desktop' ? 'primary' : 'secondary', size: 'small', icon: 'desktop', onClick: function () { deviceState[ 1 ]( 'desktop' ); } }, S.desktop ),
					el( C.Button, { variant: device === 'mobile' ? 'primary' : 'secondary', size: 'small', icon: 'smartphone', onClick: function () { deviceState[ 1 ]( 'mobile' ); } }, S.mobile )
				)
			)
			: null;

		var previewQuery = { post_id: postId };
		if ( def.type === 'youtube_list' && settings.youtubeMode === 'reference' && metadataPreviewState[ 0 ] ) {
			// The real SSR hook watches path changes and retains its previous content while loading.
			previewQuery.pc_metadata_refresh = metadataPreviewState[ 0 ];
		}
		return el(
			Fragment,
			null,
			el(
				be.BlockControls,
				null,
				el(
					C.ToolbarGroup,
					null,
					el( C.ToolbarButton, {
						icon: attrs.enabled === false ? 'hidden' : 'visibility',
						label: S.hiddenModule,
						isPressed: attrs.enabled === false,
						onClick: function () {
							setAttributes( { enabled: attrs.enabled === false } );
						},
					} )
				)
			),
			el( be.InspectorControls, null, deviceSwitch, panels ),
			el(
				'div',
				be.useBlockProps( { className: 'pc-module-block' + ( attrs.enabled === false ? ' is-hidden-module' : '' ) } ),
				attrs.moduleId
					? el( SSR, { block: props.name, attributes: attrs, httpMethod: 'POST', urlQueryArgs: previewQuery } )
					: el( C.Spinner )
			)
		);
	}

	Object.keys( data.modules ).forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			edit: Edit,
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.pcModules, window.pcModuleUI );
