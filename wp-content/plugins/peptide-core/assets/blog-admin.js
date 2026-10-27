/**
 * 博客专辑的封面：用媒体库选图，或者移除。添加专辑后（WordPress 用 AJAX 提交）清空已选的封面。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.pcBlogAdmin || {};

	$( document ).on( 'click', '.pc-cover-picker__choose', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.pc-cover-picker' );
		var frame = wp.media( {
			title: cfg.choose,
			button: { text: cfg.use },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var img = frame.state().get( 'selection' ).first().toJSON();
			var url = img.sizes && img.sizes.medium ? img.sizes.medium.url : img.url;
			box.find( 'input[name="pc_term_cover"]' ).val( img.id );
			box.find( '.pc-cover-picker__preview' ).empty().append( $( '<img alt="">' ).attr( 'src', url ) );
			box.find( '.pc-cover-picker__remove' ).prop( 'hidden', false );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.pc-cover-picker__remove', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.pc-cover-picker' );
		box.find( 'input[name="pc_term_cover"]' ).val( '' );
		box.find( '.pc-cover-picker__preview' ).empty();
		$( this ).prop( 'hidden', true );
	} );

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( settings && 'string' === typeof settings.data && -1 !== settings.data.indexOf( 'action=add-tag' ) ) {
			$( '#addtag .pc-cover-picker__remove' ).trigger( 'click' );
			$( '#addtag textarea[name="pc_term_top"], #addtag textarea[name="pc_term_bottom"]' ).val( '' );
			$( '#addtag .pc-term-seo input, #addtag .pc-term-seo textarea' ).val( '' );
		}
	} );
}( jQuery ) );
