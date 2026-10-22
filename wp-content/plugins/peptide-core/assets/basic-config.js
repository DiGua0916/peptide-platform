/**
 * 系统设置 → 基础配置（BASIC-CONFIG-01）：Favicon 选图、国家切换时更新州 / 省、排序字段拖动。
 */
( function ( $, cfg ) {
	'use strict';

	if ( ! cfg ) {
		return;
	}
	var S = cfg.strings;

	// Favicon：用媒体库选图，只保存附件 ID。
	var frame = null;
	$( document ).on( 'click', '.pc-bc-favicon__pick', function () {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		if ( ! frame ) {
			frame = wp.media( { title: S.pick, button: { text: S.use }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				$( 'input[name="site_icon"]' ).val( a.id );
				$( '.pc-bc-favicon__img' ).empty().append( $( '<img alt="">' ).attr( 'src', url ) );
				$( '.pc-bc-favicon__clear' ).prop( 'hidden', false );
			} );
		}
		frame.open();
	} );
	$( document ).on( 'click', '.pc-bc-favicon__clear', function () {
		$( 'input[name="site_icon"]' ).val( '0' );
		$( '.pc-bc-favicon__img' ).html( '<span class="dashicons dashicons-format-image"></span>' );
		$( this ).prop( 'hidden', true );
	} );

	// 国家有州 / 省列表时用下拉，没有时用文本框。
	$( '#pc-bc-country' ).on( 'change', function () {
		var states = cfg.states[ this.value ] || null;
		var $wrap = $( '.pc-bc-state-wrap' );
		var $ctl;
		if ( states && Object.keys( states ).length ) {
			$ctl = $( '<select class="pc-bc-input" name="state" id="pc-bc-state"></select>' ).append( $( '<option value=""></option>' ).text( S.noState ) );
			Object.keys( states ).forEach( function ( code ) {
				$ctl.append( $( '<option></option>' ).val( code ).text( states[ code ] ) );
			} );
		} else {
			$ctl = $( '<input type="text" class="regular-text pc-bc-input" name="state" id="pc-bc-state">' );
		}
		$wrap.empty().append( $ctl );
	} );

	// 排序字段：拖动调整顺序，提交时按页面上的顺序发送。
	$( '.pc-bc-sort' ).sortable( { handle: '.dashicons-menu', axis: 'y' } );
} )( window.jQuery, window.pcBasicConfig );
