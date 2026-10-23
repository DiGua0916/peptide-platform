/**
 * 营销活动（MARKETING-01）：列表页的勾选 / 批量操作 / 分页，编辑页的字数、显示隐藏、优惠层级、商品选择、阶段、随机优惠码。
 * 所有文字都经过转义后再插入页面；提交后由服务端清理和校验。
 */
( function ( $, cfg ) {
	'use strict';

	if ( ! cfg ) {
		return;
	}
	var S = cfg.strings;

	function esc( v ) {
		return String( v === undefined || v === null ? '' : v ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function fmt( tpl, v ) {
		return String( tpl ).replace( /%[sd]/, v );
	}
	function val( name, $scope ) {
		var $in = ( $scope || $( document ) ).find( '[name="' + name + '"]' );
		return $in.is( ':radio' ) ? $in.filter( ':checked' ).val() : $in.val();
	}

	// ---------- 通用 ----------

	$( document ).on( 'input', '.pc-mk-count input', function () {
		$( this ).siblings( 'small' ).text( this.value.length + '/' + this.maxLength );
	} );

	// data-show="字段=值|值"：只在字段等于这些值时显示。
	function syncShow() {
		$( '.pc-mk [data-show]' ).each( function () {
			var spec = String( $( this ).attr( 'data-show' ) ).split( '=' );
			var on = spec[ 1 ].split( '|' ).indexOf( String( val( spec[ 0 ] ) ) ) !== -1;
			this.hidden = ! on;
		} );
	}
	$( document ).on( 'change', '.pc-mk-form input, .pc-mk-form select', syncShow );
	syncShow();

	// 活动时间：长期时没有结束时间。
	$( document ).on( 'change', '.pc-mk-time input[type="radio"]', function () {
		$( this ).closest( '.pc-mk-time' ).find( '.pc-mk-end' ).prop( 'disabled', 'long' === this.value );
	} );

	$( document ).on( 'click', '.pc-pl-close', function () {
		var dlg = $( this ).closest( 'dialog' )[ 0 ];
		if ( dlg && dlg.open ) {
			dlg.close();
		}
	} );
	$( document ).on( 'click', '.pc-mk-settings-open', function () {
		var dlg = $( '.pc-mk-settings' )[ 0 ];
		if ( dlg && dlg.showModal ) {
			dlg.showModal();
		}
	} );

	// ---------- 列表页 ----------

	function syncBulk( $form ) {
		var n = $form.find( 'input[name="ids[]"]:checked' ).length;
		$form.find( '.pc-mk-bulkbar' ).prop( 'hidden', ! n );
		$form.find( '.pc-mk-bulkbar__count' ).text( n );
	}
	$( document ).on( 'change', '.pc-mk-check-all', function () {
		var $form = $( this ).closest( 'form' );
		$form.find( 'input[name="ids[]"]' ).prop( 'checked', this.checked );
		syncBulk( $form );
	} );
	$( document ).on( 'change', '.pc-mk-bulk input[name="ids[]"]', function () {
		syncBulk( $( this ).closest( 'form' ) );
	} );
	$( document ).on( 'submit', '.pc-mk-bulk', function ( e ) {
		var $form = $( this );
		if ( ! $form.find( 'input[name="ids[]"]:checked' ).length ) {
			e.preventDefault();
			window.alert( S.pickFirst );
		} else if ( 'delete' === $form.find( 'select[name="bulk"]' ).val() && ! window.confirm( S.confirmDel ) ) {
			e.preventDefault();
		}
	} );
	$( document ).on( 'change', '.pc-mk-autosubmit', function () {
		$( this ).closest( 'form' ).find( 'input[name="paged"]' ).val( 1 );
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	// ---------- 满减活动：优惠层级 ----------

	var $tiers = $( '.pc-mk-tiers' );
	var templates = $tiers.length ? JSON.parse( $tiers.attr( 'data-templates' ) || '{}' ) : {};
	var currency = $tiers.attr( 'data-currency' ) || cfg.currency;

	function tierRow( i, type, v ) {
		var tpl = templates[ type ] || templates.spend_amount || '';
		var html = '';
		tpl.split( /(\{min\}|\{value\}|\{cur\})/ ).forEach( function ( part ) {
			if ( '{min}' === part || '{value}' === part ) {
				var k = '{min}' === part ? 'min' : 'value';
				html += '<input type="number" min="0" step="0.01" class="pc-mk-num" data-k="' + k + '" name="tiers[' + i + '][' + k + ']" value="' + esc( v[ k ] ) + '">';
			} else if ( '{cur}' === part ) {
				html += '<span class="pc-mk-unit">' + esc( currency ) + '</span>';
			} else if ( part.trim() ) {
				html += '<span class="pc-mk-unit">' + esc( part.trim() ) + '</span>';
			}
		} );
		return '<div class="pc-mk-tier"><div class="pc-mk-tier__head"><strong>' + esc( fmt( S.tier, i + 1 ) ) + '</strong> <button type="button" class="button-link pc-mk-tier-del"' + ( i ? '' : ' hidden' ) + '>' + esc( S.remove ) + '</button></div><div class="pc-mk-tier__row">' + html + '</div></div>';
	}

	function readTiers() {
		return $tiers.find( '.pc-mk-tier' ).map( function () {
			var $r = $( this );
			return { min: $r.find( '[data-k="min"]' ).val(), value: $r.find( '[data-k="value"]' ).val() };
		} ).get();
	}

	function drawTiers( list ) {
		var type = val( 'type' );
		$tiers.html( list.map( function ( v, i ) {
			return tierRow( i, type, v );
		} ).join( '' ) );
		syncTiers();
	}

	function syncTiers() {
		var type = val( 'type' );
		var $unl = $( '.pc-mk-unlimited' );
		var repeatable = String( $unl.attr( 'data-types' ) || '' ).split( ' ' ).indexOf( type ) !== -1;
		var $box = $unl.find( 'input' );
		if ( ! repeatable ) {
			$box.prop( 'checked', false );
		}
		$unl.prop( 'hidden', ! repeatable );
		var one = $box.is( ':checked' );
		var $rows = $tiers.find( '.pc-mk-tier' );
		// 上不封顶时只用第一个层级，其他层级先藏起来（不提交）。
		$rows.each( function ( i ) {
			this.hidden = one && i > 0;
			$( this ).find( 'input' ).prop( 'disabled', one && i > 0 );
		} );
		$( '.pc-mk-tier-add-row' ).prop( 'hidden', one );
		$( '.pc-mk-tier-add' ).prop( 'disabled', $rows.length >= cfg.maxTiers );
	}

	if ( $tiers.length ) {
		$( document ).on( 'change', '.pc-mk-types input', function () {
			drawTiers( readTiers() );
		} );
		$( document ).on( 'change', '.pc-mk-unlimited input', syncTiers );
		$( document ).on( 'click', '.pc-mk-tier-add', function () {
			var list = readTiers();
			if ( list.length < cfg.maxTiers ) {
				list.push( { min: '', value: '' } );
				drawTiers( list );
			}
		} );
		$( document ).on( 'click', '.pc-mk-tier-del', function () {
			var i = $tiers.find( '.pc-mk-tier' ).index( $( this ).closest( '.pc-mk-tier' ) );
			var list = readTiers();
			list.splice( i, 1 );
			drawTiers( list.length ? list : [ { min: '', value: '' } ] );
		} );
		syncTiers();
	}

	// ---------- 适用范围：商品 ----------

	var inOther = {};
	try {
		inOther = JSON.parse( $( '.pc-mk-in-other' ).text() || '{}' );
	} catch ( e ) {
		inOther = {};
	}
	$( document ).on( 'change', '.pc-mk-product-search', function () {
		var $sel = $( this );
		var id = String( $sel.val() || '' );
		var text = $sel.find( 'option:selected' ).text();
		var $box = $sel.closest( '.pc-mk-products' );
		if ( id && ! $box.find( 'tr[data-id="' + id + '"]' ).length ) {
			var note = inOther[ id ] ? '<small class="pc-pl-warn">' + esc( fmt( S.inOther, inOther[ id ] ) ) + '</small>' : '';
			$box.find( 'tbody' ).prepend(
				'<tr data-id="' + esc( id ) + '"><td class="pc-pl-col-act"><button type="button" class="button-link pc-pl-product-del" aria-label="' + esc( S.remove ) + '"><span class="dashicons dashicons-trash"></span></button><input type="hidden" name="products[]" value="' + esc( id ) + '"></td>' +
				'<td><span class="pc-pl-product"><span class="pc-pl-noimg"></span><span><strong>' + esc( text ) + '</strong>' + note + '</span></span></td><td class="pc-pl-col-status">—</td></tr>'
			);
			$box.find( '.pc-pl-products__empty' ).prop( 'hidden', true );
		}
		$sel.val( null ).trigger( 'change.select2' );
	} );
	$( document ).on( 'click', '.pc-mk .pc-pl-product-del', function () {
		var $box = $( this ).closest( '.pc-mk-products' );
		$( this ).closest( 'tr' ).remove();
		$box.find( '.pc-pl-products__empty' ).prop( 'hidden', $box.find( 'tr[data-id]' ).length > 0 );
	} );

	// ---------- 优惠码 ----------

	$( document ).on( 'click', '.pc-mk-random', function () {
		var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		var out = '';
		var buf = new Uint32Array( 8 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( buf );
		} else {
			for ( var j = 0; j < buf.length; j++ ) {
				buf[ j ] = Math.floor( Math.random() * 4294967296 );
			}
		}
		for ( var i = 0; i < buf.length; i++ ) {
			out += chars.charAt( buf[ i ] % chars.length );
		}
		$( '#pc-mk-code' ).val( out ).trigger( 'input' );
	} );

	// 输入框后面的单位跟着选择变：满金额 → USD，满件数 → 件；折扣 → % off，固定金额 → USD。
	$( document ).on( 'change', '.pc-mk-form select', function () {
		var v = this.value;
		$( this ).closest( '.pc-mk-inline' ).find( '.pc-mk-addon__unit' ).each( function () {
			var u = $( this ).attr( 'data-unit-' + v );
			if ( undefined !== u ) {
				$( this ).text( u );
			}
		} );
	} );

	// ---------- 运费折扣 ----------

	function syncKind() {
		var kind = val( 'kind' );
		if ( ! kind ) {
			return;
		}
		$( '.pc-mk-kind-pre' ).each( function () {
			$( this ).text( $( this ).attr( 'data-pre-' + kind ) || '' );
		} );
		$( '.pc-mk-kind-unit' ).each( function () {
			$( this ).text( $( this ).attr( 'data-unit-' + kind ) || '' );
		} );
	}
	$( document ).on( 'change', '.pc-mk-form input[name="kind"]', syncKind );

	function renumberStages() {
		var $rows = $( '.pc-mk-stage-list .pc-mk-stage' );
		$rows.each( function ( i ) {
			$( this ).find( '.pc-mk-stage__title' ).text( fmt( S.stage, i + 1 ) );
			$( this ).find( '[name^="stages["]' ).each( function () {
				this.name = this.name.replace( /^stages\[\d+\]/, 'stages[' + i + ']' );
			} );
			$( this ).find( '.pc-mk-stage-del' ).prop( 'hidden', ! i );
		} );
		var $add = $( '.pc-mk-stage-add' );
		$add.prop( 'disabled', $rows.length >= Number( $add.attr( 'data-max' ) || 5 ) );
	}
	$( document ).on( 'click', '.pc-mk-stage-add', function () {
		var $list = $( '.pc-mk-stage-list' );
		var $row = $list.find( '.pc-mk-stage' ).last().clone();
		$row.find( 'input' ).val( '' );
		$list.append( $row );
		renumberStages();
	} );
	$( document ).on( 'click', '.pc-mk-stage-del', function () {
		$( this ).closest( '.pc-mk-stage' ).remove();
		renumberStages();
	} );
	if ( $( '.pc-mk-stage-list' ).length ) {
		renumberStages();
		syncKind();
	}
}( jQuery, window.pcMarketing ) );
