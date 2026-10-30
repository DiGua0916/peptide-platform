/**
 * 落地页转化页（DATA-01 第 9 步）的报表：每个落地页路径一行，UV、顾客数、查看 UV、加购 UV、付款 UV、订单数、转化率、销售额、客单价、UV 价值（没有对比时间段），
 * 可以按这十列排序、按路径搜索、选择每页条数（记在浏览器里）、分页。
 * 数字来自落地页转化接口（见下面的 PATH；口径都在 PeptideCore\Data\Landing，这里不自己算），
 * 文字来自 window.pcDataLanding.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 landing 页面的报表，由 data.js 的外壳调用：切换时间段时它清空页面再调用一次，
 * 所以排序、页码、搜索词、每页条数记在这个脚本里（不在页面元素里）；排序 / 翻页 / 搜索只重画表格和分页，不重建工具栏。
 * 路径为空的一行显示“未知落地页”（订单没有访问记录，或下单那次访问不是从页面开始的）。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/landing';
	var STORE_KEY = 'pcDataLandingPerPage';
	var PER_PAGE_CHOICES = [ 20, 50, 100 ];
	var SEARCH_DELAY = 400;
	// 可以排序的十列，顺序和 Landing::METRICS 一致，也是表格列的顺序。
	var SORT_COLUMNS = [ 'uv', 'customers', 'viewed_uv', 'carted_uv', 'paid_uv', 'orders', 'conversion', 'sales', 'aov', 'uv_value' ];
	// 金额列：表头带货币代码；转化率和 UV 价值的表头带说明。
	var MONEY_COLUMNS = [ 'sales', 'aov', 'uv_value' ];
	var HELP = { conversion: 'conversionHelp', uv_value: 'uvValueHelp' };

	// 请求计数：排序 / 翻页 / 搜索 / 切换时间段之后，旧请求晚到的结果不再画。
	var requests = 0;
	var searchTimer = 0;
	var disposePrevious = null;
	// 在两次 render 之间保留的状态。
	var state = {
		sort: 'uv',
		order: 'desc',
		page: 1,
		per_page: readPerPage(),
		q: '',
		range: '',
		show_params: 0
	};

	// ---------- 小工具 ----------

	function isNum( v ) {
		return v !== undefined && v !== null && ! isNaN( Number( v ) );
	}

	// 把文字里的占位符换成值（用 split / join，不用 replace：值里的 $ 不会被当成特殊符号）。
	function fill( text, map ) {
		var out = String( text );
		Object.keys( map ).forEach( function ( k ) {
			out = out.split( k ).join( String( map[ k ] ) );
		} );
		return out;
	}

	// 从 node 往上找带有某个属性的元素，到 stop 为止。
	function findAttr( node, attr, stop ) {
		while ( node && node !== stop ) {
			if ( 1 === node.nodeType && node.hasAttribute( attr ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	// 时间段的标识：变了就回到第 1 页。
	function rangeKey( range ) {
		var d = range.dates || {};
		return [ range.preset || '', range.from, range.to, d.from, d.to ].join( '|' );
	}

	function rangeParams( range ) {
		var d = range.dates || {};
		return range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to };
	}
	function explicitRange( range ) {
		var d = range && range.dates;
		if ( ! d || ! /^\d{4}-\d{2}-\d{2}$/.test( d.from ) || ! /^\d{4}-\d{2}-\d{2}$/.test( d.to ) ) { return null; }
		return { from: d.from, to: d.to };
	}

	// 浏览器存储可能不可用（隐私模式等），读写都包在 try / catch 里；只认 20 / 50 / 100。
	function readPerPage() {
		try {
			var saved = Number( window.localStorage.getItem( STORE_KEY ) );
			if ( PER_PAGE_CHOICES.indexOf( saved ) !== -1 ) {
				return saved;
			}
		} catch ( e ) {
			// 读不到就用默认的每页条数。
		}
		return PER_PAGE_CHOICES[ 0 ];
	}

	function savePerPage( n ) {
		try {
			window.localStorage.setItem( STORE_KEY, String( n ) );
		} catch ( e ) {
			// 存不进去不影响使用。
		}
	}

	// 转化率：保留 2 位小数（ctx.number 会去掉小数），没有（null）显示“-”。
	function rate( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	// 一个单元格的文字：转化率带 %，金额两位小数，没有（null）显示“-”，其余是整数。
	function cellText( key, row, ctx ) {
		var v = row[ key ];
		if ( 'conversion' === key ) {
			return rate( v );
		}
		if ( MONEY_COLUMNS.indexOf( key ) !== -1 ) {
			return isNum( v ) ? ctx.money( v ) : '-';
		}
		return ctx.number( v );
	}

	// ---------- 表格 ----------

	function headerCell( key, data, ctx ) {
		var esc = ctx.esc;
		var active = key === data.sort;
		var arrow = active ? ( 'asc' === data.order ? ' ▲' : ' ▼' ) : '';
		var aria = active ? ( 'asc' === data.order ? 'ascending' : 'descending' ) : 'none';
		var code = ctx.currency && ctx.currency.code ? ctx.currency.code : '';
		var label = ( T[ key ] || key ) + ( MONEY_COLUMNS.indexOf( key ) !== -1 && code ? ' (' + code + ')' : '' );
		// 转化率和 UV 价值的列头带说明（鼠标悬停显示）和一个小的 ⓘ。
		var help = HELP[ key ] ? T[ HELP[ key ] ] : '';
		return '<th scope="col" class="pc-landing-th pc-landing-th--num" aria-sort="' + aria + '">' +
			'<button type="button" class="pc-landing-sort' + ( active ? ' is-active' : '' ) + '" data-sort="' + esc( key ) + '" aria-label="' + esc( label ) + '"' +
				( help ? ' title="' + esc( help ) + '"' : '' ) + '>' +
				esc( label ) +
				( help ? ' <span class="pc-landing-help" aria-hidden="true">ⓘ</span>' : '' ) +
				'<span class="pc-landing-arrow" aria-hidden="true">' + arrow + '</span>' +
			'</button>' +
		'</th>';
	}

	function landingRow( row, ctx ) {
		var esc = ctx.esc;
		var url = String( row.url || '' );
		var unknown = '' === String( row.path || '' );
		var label = unknown ? T.unknown : ( row.display_path !== undefined ? row.display_path : row.path );
		if ( 'unknown' === row.attribution_status ) { label += ' (' + T.sourceUnknown + ')'; }
		if ( 'captured' === row.attribution_status && row.host ) { label += ' — ' + row.host; }
		// 打开页面的链接只接受 http(s)；路径为空的一行没有链接。
		var open = /^https?:\/\//i.test( url ) ?
			' <a class="pc-landing-open" href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + esc( T.open ) + '</a>' :
			'';
		var cells = SORT_COLUMNS.map( function ( key ) {
			return '<td class="pc-landing-num">' + esc( cellText( key, row, ctx ) ) + '</td>';
		} ).join( '' );
		return '<tr class="pc-landing-row">' +
			'<td class="pc-landing-path"><span class="pc-landing-path-text' + ( unknown ? ' is-unknown' : '' ) + '" title="' + esc( url ) + '">' + esc( label ) + '</span>' + open + '</td>' +
			cells +
		'</tr>';
	}

	function tableHtml( data, ctx ) {
		var esc = ctx.esc;
		var head = '<th scope="col" class="pc-landing-th pc-landing-th--path">' + esc( T.path ) + '</th>';
		SORT_COLUMNS.forEach( function ( key ) {
			head += headerCell( key, data, ctx );
		} );
		var body = ( data.rows || [] ).map( function ( row ) {
			return landingRow( row, ctx );
		} ).join( '' );
		if ( ! body ) {
			body = '<tr><td class="pc-landing-empty" colspan="11">' + esc( T.noData ) + '</td></tr>';
		}
		return '<table class="pc-landing-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
	}

	// ---------- 报表 ----------

	function render( bodyEl, range, ctx ) {
		if ( disposePrevious ) { disposePrevious(); }
		var esc = ctx.esc;
		var view = { data: null };
		var composing = false;
		var disposed = false;
		var rangeBusy = false;
		var loading = false;
		var restoreTimer = 0;
		var unsubscribe = null;
		var focusReturn = null;
		var knownRange = explicitRange( range );
		function focusMoved( event ) {
			if ( focusReturn && event.target !== focusReturn.element && event.target !== document.body && event.target !== document.documentElement ) { focusReturn = null; }
		}
		document.addEventListener( 'focusin', focusMoved, true );
		function attached() { return ! disposed && ( ! document.documentElement || ! document.documentElement.contains || document.documentElement.contains( bodyEl ) ); }
		function dispose() {
			if ( disposed ) { return; }
			disposed = true; ++requests;
			focusReturn = null;
			document.removeEventListener( 'focusin', focusMoved, true );
			window.clearTimeout( searchTimer ); window.clearTimeout( restoreTimer );
			if ( unsubscribe ) { unsubscribe(); unsubscribe = null; }
			view.data = null;
		}
		disposePrevious = dispose;

		// 上一次画的页面已经被 data.js 清掉了：它的搜索计时器不能再触发。
		window.clearTimeout( searchTimer );
		var key = rangeKey( range );
		if ( key !== state.range ) {
			state.range = key;
			state.page = 1;
		}

		var options = PER_PAGE_CHOICES.map( function ( n ) {
			return '<option value="' + esc( n ) + '"' + ( n === state.per_page ? ' selected' : '' ) + '>' + esc( n ) + '</option>';
		} ).join( '' );

		// 工具栏、说明、表格容器、页脚每次 render 只建一次；后面只改表格容器和页脚的内容。
		bodyEl.innerHTML = '<div class="pc-landing">' +
			'<div class="pc-landing-toolbar">' +
				'<label><input type="checkbox" class="pc-landing-params"' + ( state.show_params ? ' checked' : '' ) + '> ' + esc( T.showParams ) + '</label>' +
				'<input type="search" class="pc-landing-search" maxlength="100" placeholder="' + esc( T.search ) + '" aria-label="' + esc( T.search ) + '">' +
				'<label class="pc-landing-per"><span class="pc-landing-per-label">' + esc( T.perPage ) + '</span>' +
					'<select class="pc-landing-perpage">' + options + '</select>' +
				'</label>' +
			'</div>' +
			'<p class="pc-data-muted pc-landing-note">' + esc( T.note ) + '</p>' +
			'<div class="pc-landing-wrap"></div>' +
			'<div class="pc-landing-foot"></div>' +
		'</div>';
		view.search = bodyEl.querySelector( '.pc-landing-search' );
		view.params = bodyEl.querySelector( '.pc-landing-params' );
		view.perPage = bodyEl.querySelector( '.pc-landing-perpage' );
		view.wrap = bodyEl.querySelector( '.pc-landing-wrap' );
		view.foot = bodyEl.querySelector( '.pc-landing-foot' );
		view.note = bodyEl.querySelector( '.pc-landing-note' );
		view.search.value = state.q;
		function controls() {
			view.note.textContent = state.show_params ? T.paramsNote : T.note;
			view.params.disabled = rangeBusy || loading;
			view.search.disabled = rangeBusy;
			view.perPage.disabled = rangeBusy || loading;
		}
		function restoreControlFocus( mine ) {
			var pending = focusReturn;
			if ( ! pending || pending.generation !== mine ) { return; }
			focusReturn = null;
			var active = document.activeElement;
			if ( mine === requests && attached() && ! rangeBusy && ! loading && ! pending.element.disabled && ( ! active || active === document.body || active === document.documentElement ) ) { pending.element.focus(); }
		}

		function failed() {
			view.data = null; loading = false; controls();
			view.wrap.innerHTML = '<p class="pc-data-empty">' + esc( ctx.strings.loadError ) + '</p><button type="button" class="button" data-landing-retry>' + esc( T.retry ) + '</button>';
			view.foot.innerHTML = '';
		}

		function drawTable() {
			var data = view.data;
			if ( ! data ) {
				return;
			}
			view.wrap.innerHTML = tableHtml( data, ctx );
		}

		// 页脚：共多少个落地页，加上分页（页码按接口返回的 per_page 算）。
		function drawFoot() {
			var data = view.data;
			if ( ! data ) {
				view.foot.innerHTML = '';
				return;
			}
			var pages = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page || state.per_page ) ) );
			var page = Number( data.page );
			view.foot.innerHTML = '<span class="pc-landing-total">' + esc( fill( T.total, { '%d': ctx.number( data.total ) } ) ) + '</span>' +
				( Number( data.total ) > 0 ? '<span class="pc-landing-pager">' +
					'<span class="pc-landing-page">' + esc( fill( T.page, { '%1$d': page, '%2$d': pages } ) ) + '</span>' +
					'<button type="button" class="button" data-dir="-1"' + ( page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button>' +
					'<button type="button" class="button" data-dir="1"' + ( page >= pages ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>' +
				'</span>' : '' );
		}

		function accept( data ) {
			if ( ! data || ! Array.isArray( data.rows ) || Number( data.show_params || 0 ) !== state.show_params ) { throw new Error( 'Invalid Landing context' ); }
			var accepted = explicitRange( data.range );
			if ( accepted ) { knownRange = accepted; }
			view.data = data;
			// 页码超出了最后一页（数据变少了）：回到最后一页重新取。
			if ( ! ( data.rows || [] ).length && Number( data.total ) > 0 && Number( data.page ) > 1 ) {
				state.page = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page || state.per_page ) ) );
				load();
				return;
			}
			state.sort = data.sort;
			state.order = data.order;
			state.page = Number( data.page );
			loading = false; controls();
			drawTable();
			drawFoot();
		}

		function load( useExplicit ) {
			if ( ! attached() || rangeBusy ) { return; }
			if ( useExplicit && ! knownRange ) { failed(); return; }
			var mine = ++requests;
			var initiating = document.activeElement;
			var keepFocus = ( initiating === view.params || initiating === view.perPage ) && ! initiating.disabled;
			focusReturn = keepFocus ? { element: initiating, generation: mine } : null;
			var params = useExplicit && knownRange ? { from: knownRange.from, to: knownRange.to } : rangeParams( range );
			params.sort = state.sort;
			params.order = state.order;
			params.page = state.page;
			params.per_page = state.per_page;
			params.q = state.q;
			if ( state.show_params ) { params.show_params = 1; }
			view.data = null; loading = true; controls();

			view.wrap.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>';
			view.foot.innerHTML = '';
			ctx.api( PATH, params ).then(
				function ( data ) {
					if ( mine !== requests || ! attached() || rangeBusy ) {
						return;
					}
					try {
						accept( data );
					} catch ( e ) {
						failed();
					}
					restoreControlFocus( mine );
				},
				function () {
					if ( mine !== requests || ! attached() || rangeBusy ) {
						return;
					}
					failed();
					restoreControlFocus( mine );
				}
			);
		}

		// 搜索：回车马上搜，停止输入 400 毫秒后搜；输入法组字中（拼音）不搜；新的搜索回到第 1 页。
		function applySearch() {
			if ( ! attached() || rangeBusy ) { return; }
			window.clearTimeout( searchTimer );
			var q = view.search.value.replace( /^\s+|\s+$/g, '' );
			if ( q === state.q ) {
				if ( ! view.data ) { load( true ); }
				return;
			}
			state.q = q;
			state.page = 1;
			load();
		}
		view.search.addEventListener( 'compositionstart', function () {
			composing = true;
			window.clearTimeout( searchTimer );
		} );
		view.search.addEventListener( 'compositionend', function () {
			composing = false;
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( applySearch, SEARCH_DELAY );
		} );
		view.search.addEventListener( 'input', function () {
			if ( rangeBusy ) { return; }
			++requests; view.data = null;
			window.clearTimeout( searchTimer );
			if ( ! composing ) {
				searchTimer = window.setTimeout( applySearch, SEARCH_DELAY );
			}
		} );
		view.search.addEventListener( 'keydown', function ( e ) {
			if ( ( 'Enter' === e.key || 13 === e.keyCode ) && ! composing ) {
				e.preventDefault();
				applySearch();
			}
		} );

		// 每页条数：只认 20 / 50 / 100，记在浏览器里，改了回到第 1 页。
		view.perPage.addEventListener( 'change', function () {
			if ( rangeBusy || loading ) { return; }
			var n = Number( view.perPage.value );
			if ( PER_PAGE_CHOICES.indexOf( n ) === -1 ) {
				return;
			}
			state.per_page = n;
			savePerPage( n );
			state.page = 1;
			load();
		} );

		// 表头排序：第一次点是从大到小，再点当前的一列反过来。
		view.wrap.addEventListener( 'click', function ( e ) {
			if ( rangeBusy || loading ) { return; }
			if ( findAttr( e.target, 'data-landing-retry', view.wrap ) ) { load( true ); return; }
			var sortBtn = findAttr( e.target, 'data-sort', view.wrap );
			if ( sortBtn ) {
				var k = sortBtn.getAttribute( 'data-sort' );
				if ( k === state.sort ) {
					state.order = 'desc' === state.order ? 'asc' : 'desc';
				} else {
					state.sort = k;
					state.order = 'desc';
				}
				state.page = 1;
				load();
			}
		} );

		// 上一页 / 下一页。
		view.foot.addEventListener( 'click', function ( e ) {
			if ( rangeBusy || loading ) { return; }
			var btn = findAttr( e.target, 'data-dir', view.foot );
			if ( btn && ! btn.disabled ) {
				state.page = Math.max( 1, state.page + Number( btn.getAttribute( 'data-dir' ) ) );
				load();
			}
		} );

		view.params.addEventListener( 'change', function () {
			if ( rangeBusy || loading ) { return; }
			state.show_params = view.params.checked ? 1 : 0;
			state.page = 1; load( true );
		} );
		if ( 'function' === typeof PcData.onRangeState ) {
			unsubscribe = PcData.onRangeState( function ( on ) {
				var wasBusy = rangeBusy; rangeBusy = !! on;
				window.clearTimeout( restoreTimer );
				if ( rangeBusy ) {
					focusReturn = null;
					++requests; window.clearTimeout( searchTimer ); view.data = null; loading = false; controls();
					view.wrap.innerHTML = '<p class="pc-data-muted">' + esc( T.applyingRange ) + '</p>'; view.foot.innerHTML = '';
				} else if ( wasBusy && attached() ) {
					controls();
					var mine = requests;
					restoreTimer = window.setTimeout( function () { if ( mine === requests && attached() && ! rangeBusy ) { load( true ); } }, 0 );
				}
			} );
		}
		load();
	}

	PcData.register( 'landing', render );
} )( window.PcData, window.pcDataLanding );
