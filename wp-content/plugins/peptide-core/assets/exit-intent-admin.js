/**
 * 应用中心 → 离开意图增收（APP-EXIT-01）：右侧实时预览（用前台同一个 PeptideExitIntent.render，不发请求）、
 * 奖品行的增删和概率、图片选择。字数统计和按类型显示 / 隐藏由 marketing.js 处理。
 */
( function ( $, cfg ) {
	'use strict';

	var $form = $( '.pc-exit-admin form, form.pc-mk-form[data-kind="exit"]' ).first();
	if ( ! $form.length || ! cfg || ! window.PeptideExitIntent ) {
		return;
	}
	var S = cfg.strings;
	var $stage = $( '.pc-exit-admin__stage' );

	function val( name ) {
		var $in = $form.find( '[name="' + name + '"]' );
		return $in.is( ':radio' ) ? $in.filter( ':checked' ).val() : $in.val();
	}

	function prizes() {
		return $form.find( '.pc-exit-prize' ).map( function () {
			var $r = $( this );
			return {
				label: $.trim( $r.find( 'input[type="text"]' ).val() ) || '—',
				coupon: $r.find( 'select' ).val() || '',
				weight: Math.max( 0, Number( $r.find( 'input[type="number"]' ).val() ) || 0 )
			};
		} ).get();
	}

	function chances() {
		var list = prizes();
		var total = list.reduce( function ( a, p ) {
			return a + p.weight;
		}, 0 );
		$form.find( '.pc-exit-prize' ).each( function ( i ) {
			$( this ).find( '.pc-exit-chance' ).text( total ? ( Math.round( 1000 * list[ i ].weight / total ) / 10 ) + '%' : '—' );
		} );
		$form.find( '.pc-exit-prize-add' ).prop( 'disabled', list.length >= cfg.maxPrizes );
		$form.find( '.pc-exit-prize-del' ).prop( 'hidden', list.length <= 2 );
	}

	function draw() {
		var type = val( 'type' );
		var list = prizes();
		var code = String( val( 'coupon' ) || '' ).toUpperCase();
		var config = {
			type: type,
			title: val( 'title' ),
			text: val( 'text' ),
			button: val( 'button' ),
			decline: val( 'decline' ),
			image: $form.find( '.pc-exit-admin__thumb' ).attr( 'data-url' ) || '',
			color: val( 'color' ),
			link: val( 'link' ) || '#',
			code: code,
			prizes: list.map( function ( p ) {
				return p.label;
			} ),
			spin: val( 'spin_button' ),
			winTitle: val( 'win_title' ),
			winText: val( 'win_text' ),
			loseText: val( 'lose_text' ),
			strings: S
		};
		$stage.empty();
		window.PeptideExitIntent.render( config, $stage[ 0 ], {
			demo: true,
			weights: list.map( function ( p ) {
				return p.weight;
			} ),
			wins: list.map( function ( p ) {
				return p.coupon ? p.coupon.toUpperCase() : '';
			} )
		} );
		// 预览里的关闭 / 继续购物只是重新显示。
		$stage.find( '.pc-exit' ).on( 'pcexit:close', function () {
			draw();
		} );
	}

	var timer = null;
	function later() {
		window.clearTimeout( timer );
		timer = window.setTimeout( draw, 200 );
	}
	$form.on( 'input change', 'input, select, textarea', later );

	// 奖品行
	function renumber() {
		$form.find( '.pc-exit-prize' ).each( function ( i ) {
			$( this ).find( '[name^="prizes["]' ).each( function () {
				this.name = this.name.replace( /^prizes\[\d+\]/, 'prizes[' + i + ']' );
			} );
		} );
		chances();
		later();
	}
	$form.on( 'click', '.pc-exit-prize-add', function () {
		var $rows = $form.find( '.pc-exit-prize' );
		if ( $rows.length >= cfg.maxPrizes ) {
			return;
		}
		var $row = $rows.last().clone();
		$row.find( 'input[type="text"]' ).val( '' );
		$row.find( 'select' ).val( '' );
		$row.find( 'input[type="number"]' ).val( '10' );
		$rows.last().after( $row );
		renumber();
	} );
	$form.on( 'click', '.pc-exit-prize-del', function () {
		if ( $form.find( '.pc-exit-prize' ).length > 2 ) {
			$( this ).closest( 'tr' ).remove();
			renumber();
		}
	} );
	$form.on( 'input change', '.pc-exit-prize input, .pc-exit-prize select', chances );

	// 图片
	var frame = null;
	$form.on( 'click', '.pc-exit-image-pick', function () {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		if ( ! frame ) {
			frame = window.wp.media( { title: S.chooseImage, button: { text: S.useImage }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
				$form.find( 'input[name="image"]' ).val( a.id );
				$form.find( '.pc-exit-admin__thumb' ).attr( 'data-url', url ).empty().append( $( '<img alt="">' ).attr( 'src', url ) );
				draw();
			} );
		}
		frame.open();
	} );
	$form.on( 'click', '.pc-exit-image-clear', function () {
		$form.find( 'input[name="image"]' ).val( '0' );
		$form.find( '.pc-exit-admin__thumb' ).attr( 'data-url', '' ).empty();
		draw();
	} );

	chances();
	draw();
}( jQuery, window.pcExitAdmin ) );
