/**
 * 物流方案 / 税费方案编辑（SHIPPING-PLANS-01 / TAX-PLANS-01）：区域、运费、商品、弹窗。
 * 数据来自页面里的 .pc-pl-data（JSON），提交表单时写回隐藏字段 regions / rates，由服务端清理和校验。
 * 所有文字都经过转义后再插入页面。
 */
( function ( $, cfg ) {
	'use strict';

	if ( ! cfg ) {
		return;
	}
	var S = cfg.strings;
	var TAX = 'tax' === cfg.kind;

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function fmt( tpl, v ) {
		return String( tpl ).replace( /%[sd]/, v );
	}
	function num( v ) {
		return Math.round( Number( v || 0 ) * 100 ) / 100;
	}
	function open( dlg ) {
		if ( dlg && dlg.showModal ) {
			dlg.showModal();
		}
	}
	function close( dlg ) {
		if ( dlg && dlg.open ) {
			dlg.close();
		}
	}

	// 通用：弹窗的取消按钮、列表页的规则弹窗。
	$( document ).on( 'click', '.pc-pl-close', function () {
		close( $( this ).closest( 'dialog' )[ 0 ] );
	} );
	$( document ).on( 'click', '.pc-pl-rules-open', function () {
		open( $( '.pc-pl-rules' )[ 0 ] );
	} );
	$( document ).on( 'click', '.pc-pl-delete', function ( e ) {
		if ( ! window.confirm( S.confirmDel ) ) {
			e.preventDefault();
		}
	} );

	var $form = $( '.pc-pl-form' );
	if ( ! $form.length ) {
		return;
	}
	var data = {};
	try {
		data = JSON.parse( $form.find( '.pc-pl-data' ).text() || '{}' );
	} catch ( e ) {
		data = {};
	}
	var regions = data.regions || [];
	var rates = data.rates || [];
	var icons = data.icons || {};

	// ---------- 区域 ----------

	function stateText( r ) {
		if ( ! r.states || ! r.states.length ) {
			return S.all;
		}
		var names = cfg.states[ r.country ] || {};
		return r.states.map( function ( s ) {
			return names[ s ] || s;
		} ).join( ', ' );
	}

	function renderRegions() {
		var $body = $form.find( '.pc-pl-regions tbody' ).empty();
		$form.find( '.pc-pl-regions-count' ).text( fmt( S.selected, regions.length ) );
		if ( ! regions.length ) {
			$body.append( '<tr><td colspan="' + ( TAX ? 4 : 3 ) + '" class="pc-pl-muted">' + esc( S.noRegions ) + '</td></tr>' );
			return;
		}
		regions.forEach( function ( r, i ) {
			var hasStates = cfg.states[ r.country ] && Object.keys( cfg.states[ r.country ] ).length;
			var act = ( hasStates ? '<button type="button" class="button-link pc-pl-region-edit" data-i="' + i + '" aria-label="' + esc( S.edit ) + '"><span class="dashicons dashicons-edit"></span></button>' : '' ) +
				'<button type="button" class="button-link pc-pl-region-del" data-i="' + i + '" aria-label="' + esc( S.remove ) + '"><span class="dashicons dashicons-trash"></span></button>';
			var rate = TAX ? '<td><input type="number" class="small-text pc-pl-region-rate" data-i="' + i + '" min="0" max="100" step="0.0001" value="' + esc( r.rate === undefined ? '' : r.rate ) + '"> %</td>' : '';
			$body.append( '<tr><td class="pc-pl-col-act">' + act + '</td><td>' + esc( cfg.countries[ r.country ] || r.country ) + '</td><td>' + esc( stateText( r ) ) + '</td>' + rate + '</tr>' );
		} );
	}

	$form.on( 'click', '.pc-pl-region-del', function () {
		regions.splice( Number( $( this ).data( 'i' ) ), 1 );
		renderRegions();
	} );
	$form.on( 'input change', '.pc-pl-region-rate', function () {
		regions[ Number( $( this ).data( 'i' ) ) ].rate = this.value;
	} );
	$form.on( 'click', '.pc-pl-regions-clear', function () {
		if ( regions.length && window.confirm( S.confirmClear ) ) {
			regions = [];
			renderRegions();
		}
	} );
	$form.on( 'click', '.pc-pl-regions-toggle', function () {
		$form.find( '.pc-pl-scroll' ).toggleClass( 'is-collapsed' );
		$( this ).find( '.dashicons' ).toggleClass( 'dashicons-arrow-down-alt2 dashicons-arrow-right-alt2' );
	} );

	// 添加国家。
	var $cDlg = $( '.pc-pl-countries' );
	function renderCountryList() {
		var q = String( $cDlg.find( '.pc-pl-countries-search' ).val() || '' ).toLowerCase();
		var used = {};
		regions.forEach( function ( r ) {
			used[ r.country ] = true;
		} );
		var html = '';
		Object.keys( cfg.countries ).forEach( function ( code ) {
			var name = cfg.countries[ code ];
			if ( used[ code ] || ( q && name.toLowerCase().indexOf( q ) < 0 && code.toLowerCase() !== q ) ) {
				return;
			}
			html += '<label><input type="checkbox" value="' + esc( code ) + '"> ' + esc( name ) + '</label>';
		} );
		$cDlg.find( '.pc-pl-countries-list' ).html( html );
		$cDlg.find( '.pc-pl-countries-all' ).prop( 'checked', false );
	}
	$form.on( 'click', '.pc-pl-regions-add', function () {
		$cDlg.find( '.pc-pl-countries-search' ).val( '' );
		renderCountryList();
		open( $cDlg[ 0 ] );
	} );
	$cDlg.on( 'input', '.pc-pl-countries-search', renderCountryList );
	$cDlg.on( 'change', '.pc-pl-countries-all', function () {
		$cDlg.find( '.pc-pl-countries-list input' ).prop( 'checked', this.checked );
	} );
	$cDlg.on( 'click', '.pc-pl-countries-ok', function () {
		$cDlg.find( '.pc-pl-countries-list input:checked' ).each( function () {
			var r = { country: this.value, states: [] };
			if ( TAX ) {
				r.rate = '';
			}
			regions.push( r );
		} );
		close( $cDlg[ 0 ] );
		renderRegions();
	} );

	// 选择州 / 省。
	var $sDlg = $( '.pc-pl-states' );
	var editing = -1;
	$form.on( 'click', '.pc-pl-region-edit', function () {
		editing = Number( $( this ).data( 'i' ) );
		var r = regions[ editing ];
		var names = cfg.states[ r.country ] || {};
		var html = '';
		Object.keys( names ).forEach( function ( code ) {
			html += '<label><input type="checkbox" value="' + esc( code ) + '"' + ( r.states.indexOf( code ) > -1 ? ' checked' : '' ) + '> ' + esc( names[ code ] ) + '</label>';
		} );
		$sDlg.find( '.pc-pl-states-title' ).text( cfg.countries[ r.country ] || r.country );
		$sDlg.find( '.pc-pl-states-list' ).html( html );
		$sDlg.find( 'input[name="pc_pl_states_mode"][value="' + ( r.states.length ? 'some' : 'all' ) + '"]' ).prop( 'checked', true );
		$sDlg.find( '.pc-pl-states-list' ).toggleClass( 'is-disabled', ! r.states.length );
		open( $sDlg[ 0 ] );
	} );
	$sDlg.on( 'change', 'input[name="pc_pl_states_mode"]', function () {
		$sDlg.find( '.pc-pl-states-list' ).toggleClass( 'is-disabled', 'all' === this.value );
	} );
	$sDlg.on( 'click', '.pc-pl-states-ok', function () {
		var some = 'some' === $sDlg.find( 'input[name="pc_pl_states_mode"]:checked' ).val();
		var picked = some ? $sDlg.find( '.pc-pl-states-list input:checked' ).map( function () {
			return this.value;
		} ).get() : [];
		regions[ editing ].states = picked;
		close( $sDlg[ 0 ] );
		renderRegions();
	} );

	// ---------- 运费（物流方案） ----------

	function ruleText( r ) {
		var unit = 'subtotal' === r.rule ? cfg.currency : 'weight' === r.rule ? cfg.weight : '';
		var label = 'subtotal' === r.rule ? S.subtotal : 'weight' === r.rule ? S.weightRule : S.quantity;
		var max = null === r.max || '' === r.max || undefined === r.max ? S.noLimit : num( r.max );
		return label + ' ' + num( r.min ) + '–' + max + ( unit ? ' ' + unit : '' );
	}

	function renderRates() {
		var $body = $form.find( '.pc-pl-rates tbody' ).empty();
		if ( ! $body.length ) {
			return;
		}
		if ( ! rates.length ) {
			$body.append( '<tr><td colspan="7" class="pc-pl-muted">' + esc( S.noRates ) + '</td></tr>' );
			return;
		}
		rates.forEach( function ( r, i ) {
			var icon = r.icon && icons[ r.icon ] ? '<img class="pc-pl-rate-icon" src="' + esc( icons[ r.icon ] ) + '" alt="">' : '—';
			$body.append(
				'<tr class="' + ( r.enabled ? '' : 'is-off' ) + '"><td class="pc-pl-col-act"><button type="button" class="button-link pc-pl-rate-edit" data-i="' + i + '" aria-label="' + esc( S.edit ) + '"><span class="dashicons dashicons-edit"></span></button>' +
				'<button type="button" class="button-link pc-pl-rate-del" data-i="' + i + '" aria-label="' + esc( S.remove ) + '"><span class="dashicons dashicons-trash"></span></button></td>' +
				'<td><label class="pc-pl-switch"><input type="checkbox" class="pc-pl-rate-toggle" data-i="' + i + '"' + ( r.enabled ? ' checked' : '' ) + '><span></span></label></td>' +
				'<td>' + icon + '</td><td>' + esc( r.name ) + '</td><td>' + esc( r.postcodes || S.anyZip ) + '</td><td>' + esc( ruleText( r ) ) + '</td><td>' + esc( num( r.fee ).toFixed( 2 ) + ' ' + cfg.currency ) + '</td></tr>'
			);
		} );
	}

	var $rDlg = $( '.pc-pl-rate' );
	var rateIndex = -1;
	var rateIcon = 0;
	function showIcon() {
		$rDlg.find( '.pc-pl-icon-img' ).html( rateIcon && icons[ rateIcon ] ? '<img src="' + esc( icons[ rateIcon ] ) + '" alt="">' : '' );
		$rDlg.find( '.pc-pl-icon-clear' ).prop( 'hidden', ! rateIcon );
	}
	function editRate( i ) {
		rateIndex = i;
		var r = i > -1 ? rates[ i ] : { name: '', enabled: true, icon: 0, postcodes: '', rule: 'subtotal', min: 0, max: null, fee: 0 };
		var f = function ( k ) {
			return $rDlg.find( '[data-f="' + k + '"]' );
		};
		f( 'name' ).val( r.name );
		f( 'enabled' ).prop( 'checked', !! r.enabled );
		f( 'postcodes' ).val( r.postcodes );
		f( 'rule' ).val( r.rule ).trigger( 'change' );
		f( 'min' ).val( num( r.min ) );
		f( 'max' ).val( null === r.max || undefined === r.max ? '' : num( r.max ) );
		f( 'fee' ).val( num( r.fee ) );
		rateIcon = Number( r.icon || 0 );
		showIcon();
		$rDlg.find( '.pc-pl-error' ).prop( 'hidden', true );
		open( $rDlg[ 0 ] );
	}
	$rDlg.on( 'change', '[data-f="rule"]', function () {
		$rDlg.find( '.pc-pl-unit' ).text( 'subtotal' === this.value ? cfg.currency : 'weight' === this.value ? cfg.weight : '' );
	} );
	$form.on( 'click', '.pc-pl-rate-add', function () {
		editRate( -1 );
	} );
	$form.on( 'click', '.pc-pl-rate-edit', function () {
		editRate( Number( $( this ).data( 'i' ) ) );
	} );
	$form.on( 'click', '.pc-pl-rate-del', function () {
		rates.splice( Number( $( this ).data( 'i' ) ), 1 );
		renderRates();
	} );
	$form.on( 'change', '.pc-pl-rate-toggle', function () {
		rates[ Number( $( this ).data( 'i' ) ) ].enabled = this.checked;
		renderRates();
	} );
	$rDlg.on( 'click', '.pc-pl-rate-ok', function () {
		var f = function ( k ) {
			return $rDlg.find( '[data-f="' + k + '"]' );
		};
		var name = String( f( 'name' ).val() || '' ).trim();
		var min = num( f( 'min' ).val() );
		var maxRaw = String( f( 'max' ).val() || '' ).trim();
		var max = '' === maxRaw ? null : num( maxRaw );
		var err = ! name ? S.nameNeeded : null !== max && max <= min ? S.rangeBad : '';
		if ( err ) {
			$rDlg.find( '.pc-pl-error' ).text( err ).prop( 'hidden', false );
			return;
		}
		var r = {
			id: rateIndex > -1 ? rates[ rateIndex ].id : '',
			name: name,
			enabled: f( 'enabled' ).is( ':checked' ),
			icon: rateIcon,
			postcodes: String( f( 'postcodes' ).val() || '' ),
			rule: f( 'rule' ).val(),
			min: min,
			max: max,
			fee: Math.max( 0, num( f( 'fee' ).val() ) ),
		};
		if ( rateIndex > -1 ) {
			rates[ rateIndex ] = r;
		} else {
			rates.push( r );
		}
		close( $rDlg[ 0 ] );
		renderRates();
	} );

	// 运费图标：媒体库选图。
	var frame = null;
	$rDlg.on( 'click', '.pc-pl-icon-choose', function () {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		if ( ! frame ) {
			frame = wp.media( { title: S.pickIcon, button: { text: S.useIcon }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				icons[ a.id ] = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				rateIcon = a.id;
				showIcon();
			} );
		}
		frame.open();
	} );
	$rDlg.on( 'click', '.pc-pl-icon-clear', function () {
		rateIcon = 0;
		showIcon();
	} );

	// 复制其他方案的运费。
	var $copy = $( '.pc-pl-copydlg' );
	$form.on( 'click', '.pc-pl-copy', function () {
		var $sel = $copy.find( '.pc-pl-copy-from' ).empty();
		( data.others || [] ).forEach( function ( o ) {
			$sel.append( $( '<option></option>' ).val( o.id ).text( o.name + ' (' + o.rates.length + ')' ) );
		} );
		open( $copy[ 0 ] );
	} );
	$copy.on( 'click', '.pc-pl-copy-ok', function () {
		var id = Number( $copy.find( '.pc-pl-copy-from' ).val() );
		( data.others || [] ).forEach( function ( o ) {
			if ( o.id === id ) {
				o.rates.forEach( function ( r ) {
					var c = $.extend( {}, r );
					c.id = '';
					rates.push( c );
				} );
			}
		} );
		close( $copy[ 0 ] );
		renderRates();
	} );

	// ---------- 商品 ----------

	var productPlans = {};
	try {
		productPlans = JSON.parse( $form.find( '.pc-pl-product-plans' ).text() || '{}' );
	} catch ( e ) {
		productPlans = {};
	}
	$form.on( 'change', '.pc-pl-product-search', function () {
		var id = String( $( this ).val() || '' );
		var text = $( this ).find( 'option:selected' ).text();
		if ( id && ! $form.find( '.pc-pl-products tr[data-id="' + id + '"]' ).length ) {
			var note = productPlans[ id ] ? '<small class="pc-pl-warn">' + esc( fmt( S.moved, productPlans[ id ] ) ) + '</small>' : '';
			$form.find( '.pc-pl-products tbody' ).prepend(
				'<tr data-id="' + esc( id ) + '"><td class="pc-pl-col-act"><button type="button" class="button-link pc-pl-product-del" aria-label="' + esc( S.remove ) + '"><span class="dashicons dashicons-trash"></span></button><input type="hidden" name="products[]" value="' + esc( id ) + '"></td>' +
				'<td><span class="pc-pl-product"><span class="pc-pl-noimg"></span><span><strong>' + esc( text ) + '</strong>' + note + '</span></span></td><td class="pc-pl-col-status">—</td></tr>'
			);
			$form.find( '.pc-pl-products__empty' ).prop( 'hidden', true );
		}
		$( this ).val( null ).trigger( 'change.select2' );
	} );
	$form.on( 'click', '.pc-pl-product-del', function () {
		$( this ).closest( 'tr' ).remove();
		$form.find( '.pc-pl-products__empty' ).prop( 'hidden', $form.find( '.pc-pl-products tr[data-id]' ).length > 0 );
	} );

	// ---------- 提交 ----------

	$form.on( 'submit', function ( e ) {
		if ( TAX ) {
			var bad = regions.some( function ( r ) {
				var v = Number( r.rate );
				return '' === r.rate || undefined === r.rate || isNaN( v ) || v < 0 || v > 100;
			} );
			if ( bad ) {
				e.preventDefault();
				window.alert( S.rateBad );
				return;
			}
		}
		$form.find( 'input[name="regions"]' ).val( JSON.stringify( regions ) );
		$form.find( 'input[name="rates"]' ).val( JSON.stringify( rates ) );
	} );

	renderRegions();
	renderRates();
} )( window.jQuery, window.pcPlans );
