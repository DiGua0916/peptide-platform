/**
 * 首页SEO 后台：搜索结果预览随输入更新、字数、关键词标签（回车或逗号添加，点 × 删除）、导入表单的开关。
 */
( function () {
	'use strict';

	var cfg = window.pcSeoAdmin || {};
	var form = document.querySelector( '.pc-seo-form' );

	var toggle = document.querySelector( '.pc-seo-import-toggle' );
	var importForm = document.querySelector( '.pc-seo-import' );
	if ( toggle && importForm ) {
		toggle.addEventListener( 'click', function () {
			importForm.hidden = ! importForm.hidden;
			toggle.setAttribute( 'aria-expanded', importForm.hidden ? 'false' : 'true' );
		} );
	}
	if ( ! form ) {
		return;
	}

	var vars = function ( text ) {
		return text.split( '{current_domain}' ).join( cfg.domain || '' );
	};

	// 预览和字数。
	var title = form.querySelector( '[name="title"]' );
	var desc = form.querySelector( '[name="description"]' );
	var pTitle = document.querySelector( '[data-preview="title"]' );
	var pDesc = document.querySelector( '[data-preview="desc"]' );
	var update = function () {
		if ( pTitle ) {
			pTitle.textContent = title.value.trim() ? vars( title.value ) : cfg.titleHolder;
		}
		if ( pDesc ) {
			pDesc.textContent = desc.value.trim() ? vars( desc.value ) : cfg.descHolder;
		}
		form.querySelectorAll( '[data-count]' ).forEach( function ( el ) {
			var out = el.parentNode.querySelector( '.pc-seo-count' );
			if ( out ) {
				out.textContent = el.value.length + '/' + el.getAttribute( 'maxlength' );
			}
		} );
	};
	title.addEventListener( 'input', update );
	desc.addEventListener( 'input', update );
	update();

	// 关键词标签：隐藏字段里每行一个。
	var box = form.querySelector( '[data-tags]' );
	var input = box.querySelector( 'input' );
	var hidden = form.querySelector( '[name="keywords"]' );
	var keys = hidden.value ? hidden.value.split( '\n' ).filter( Boolean ) : [];
	var draw = function () {
		box.querySelectorAll( '.pc-seo-tag' ).forEach( function ( t ) {
			t.remove();
		} );
		keys.forEach( function ( k, i ) {
			var tag = document.createElement( 'span' );
			tag.className = 'pc-seo-tag';
			tag.textContent = k;
			var x = document.createElement( 'button' );
			x.type = 'button';
			x.setAttribute( 'aria-label', cfg.remove + ' ' + k );
			x.textContent = '×';
			x.addEventListener( 'click', function () {
				keys.splice( i, 1 );
				draw();
			} );
			tag.appendChild( x );
			box.insertBefore( tag, input );
		} );
		hidden.value = keys.join( '\n' );
	};
	var add = function () {
		input.value.split( /[,，]/ ).forEach( function ( part ) {
			var k = part.trim();
			if ( k && keys.map( function ( x ) {
				return x.toLowerCase();
			} ).indexOf( k.toLowerCase() ) === -1 ) {
				keys.push( k );
			}
		} );
		input.value = '';
		draw();
	};
	input.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key || ',' === e.key || '，' === e.key ) {
			e.preventDefault();
			add();
		} else if ( 'Backspace' === e.key && ! input.value && keys.length ) {
			keys.pop();
			draw();
		}
	} );
	input.addEventListener( 'blur', add );
	box.addEventListener( 'click', function ( e ) {
		if ( e.target === box ) {
			input.focus();
		}
	} );
	form.addEventListener( 'submit', add );
	draw();
}() );
