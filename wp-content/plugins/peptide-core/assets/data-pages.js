/**
 * 页面访问统计页（DATA-01 第 5 步）的报表：每个页面路径一行，访问量、访问用户、跳出率（没有对比时间段），
 * 可以按这三列排序、按路径搜索、选择每页条数（记在浏览器里）、分页。
 * 数字来自页面访问统计接口（见下面的 PATH；口径都在 PeptideCore\Data\Page_Visits，这里不自己算），
 * 文字来自 window.pcDataPages.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 pages 页面的报表，由 data.js 的外壳调用：切换时间段时它清空页面再调用一次，
 * 所以排序、页码、搜索词、每页条数记在这个脚本里（不在页面元素里）；排序 / 翻页 / 搜索只重画表格和分页，不重建工具栏。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/pages';
	var STORE_KEY = 'pcDataPagesPerPage';
	var PER_PAGE_CHOICES = [ 20, 50, 100 ];
	var SEARCH_DELAY = 400;
	// 可以排序的三列，和 Page_Visits::METRICS 一致。
	var SORT_COLUMNS = [ 'views', 'visitors', 'bounce_rate' ];

	// 请求计数：排序 / 翻页 / 搜索 / 切换时间段之后，旧请求晚到的结果不再画。
	var requests = 0;
	var searchTimer = 0;
	// 在两次 render 之间保留的状态。
	var state = {
		sort: 'views',
		order: 'desc',
		page: 1,
		per_page: readPerPage(),
		q: '',
		range: ''
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
		return range.preset ? 'p:' + range.preset : 'd:' + [ d.from, d.to, d.compare_from, d.compare_to ].join( '|' );
	}

	function rangeParams( range ) {
		var d = range.dates || {};
		return range.preset ?
			{ range: range.preset } :
			{ from: d.from, to: d.to, compare_from: d.compare_from, compare_to: d.compare_to };
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

	// 跳出率：保留 2 位小数（ctx.number 会去掉小数），没有（null）显示“-”。
	function rate( v ) {
		return isNum( v ) ? ( Math.round( Number( v ) * 100 ) / 100 ) + '%' : '-';
	}

	// ---------- 表格 ----------

	function headerCell( key, data, ctx ) {
		var esc = ctx.esc;
		var active = key === data.sort;
		var arrow = active ? ( 'asc' === data.order ? ' ▲' : ' ▼' ) : '';
		var aria = active ? ( 'asc' === data.order ? 'ascending' : 'descending' ) : 'none';
		// 跳出率的列头带说明（鼠标悬停显示）和一个小的 ⓘ。
		var help = 'bounce_rate' === key;
		return '<th scope="col" class="pc-pages-th pc-pages-th--num" aria-sort="' + aria + '">' +
			'<button type="button" class="pc-pages-sort' + ( active ? ' is-active' : '' ) + '" data-sort="' + esc( key ) + '"' +
				( help ? ' title="' + esc( T.bounceHelp ) + '"' : '' ) + '>' +
				esc( T[ key ] || key ) +
				( help ? ' <span class="pc-pages-help" aria-hidden="true">ⓘ</span>' : '' ) +
				'<span class="pc-pages-arrow" aria-hidden="true">' + arrow + '</span>' +
			'</button>' +
		'</th>';
	}

	function pageRow( row, ctx ) {
		var esc = ctx.esc;
		var url = String( row.url || '' );
		// 打开页面的链接只接受 http(s)。
		var open = /^https?:\/\//i.test( url ) ?
			' <a class="pc-pages-open" href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + esc( T.open ) + '</a>' :
			'';
		return '<tr class="pc-pages-row">' +
			'<td class="pc-pages-path"><span class="pc-pages-path-text" title="' + esc( url ) + '">' + esc( row.path ) + '</span>' + open + '</td>' +
			'<td class="pc-pages-num">' + esc( ctx.number( row.views ) ) + '</td>' +
			'<td class="pc-pages-num">' + esc( ctx.number( row.visitors ) ) + '</td>' +
			'<td class="pc-pages-num">' + esc( rate( row.bounce_rate ) ) + '</td>' +
		'</tr>';
	}

	function tableHtml( data, ctx ) {
		var esc = ctx.esc;
		var head = '<th scope="col" class="pc-pages-th pc-pages-th--path">' + esc( T.path ) + '</th>';
		SORT_COLUMNS.forEach( function ( key ) {
			head += headerCell( key, data, ctx );
		} );
		var body = ( data.rows || [] ).map( function ( row ) {
			return pageRow( row, ctx );
		} ).join( '' );
		return '<table class="pc-pages-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
	}

	// ---------- 报表 ----------

	function render( bodyEl, range, ctx ) {
		var esc = ctx.esc;
		var view = { data: null };
		var composing = false;

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
		bodyEl.innerHTML = '<div class="pc-pages">' +
			'<div class="pc-pages-toolbar">' +
				'<input type="search" class="pc-pages-search" maxlength="100" placeholder="' + esc( T.search ) + '" aria-label="' + esc( T.search ) + '">' +
				'<label class="pc-pages-per"><span class="pc-pages-per-label">' + esc( T.perPage ) + '</span>' +
					'<select class="pc-pages-perpage">' + options + '</select>' +
				'</label>' +
			'</div>' +
			'<p class="pc-data-muted pc-pages-note">' + esc( T.trackingNote ) + '</p>' +
			'<div class="pc-pages-wrap"></div>' +
			'<div class="pc-pages-foot"></div>' +
		'</div>';
		view.search = bodyEl.querySelector( '.pc-pages-search' );
		view.perPage = bodyEl.querySelector( '.pc-pages-perpage' );
		view.wrap = bodyEl.querySelector( '.pc-pages-wrap' );
		view.foot = bodyEl.querySelector( '.pc-pages-foot' );
		view.search.value = state.q;

		function failed() {
			view.wrap.innerHTML = '<p class="pc-data-empty">' + esc( ctx.strings.loadError ) + '</p>';
			view.foot.innerHTML = '';
		}

		function drawTable() {
			var data = view.data;
			if ( ! data ) {
				return;
			}
			if ( ! Number( data.total ) ) {
				view.wrap.innerHTML = '<p class="pc-data-empty">' + esc( T.noData ) + '</p>';
				return;
			}
			view.wrap.innerHTML = tableHtml( data, ctx );
		}

		// 页脚：共多少个页面，加上分页（页码按接口返回的 per_page 算）。
		function drawFoot() {
			var data = view.data;
			if ( ! data || ! Number( data.total ) ) {
				view.foot.innerHTML = '';
				return;
			}
			var pages = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page || state.per_page ) ) );
			var page = Number( data.page );
			view.foot.innerHTML = '<span class="pc-pages-total">' + esc( fill( T.total, { '%d': ctx.number( data.total ) } ) ) + '</span>' +
				'<span class="pc-pages-pager">' +
					'<span class="pc-pages-page">' + esc( fill( T.page, { '%1$d': page, '%2$d': pages } ) ) + '</span>' +
					'<button type="button" class="button" data-dir="-1"' + ( page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button>' +
					'<button type="button" class="button" data-dir="1"' + ( page >= pages ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>' +
				'</span>';
		}

		function accept( data ) {
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
			drawTable();
			drawFoot();
		}

		function load() {
			var mine = ++requests;
			var params = rangeParams( range );
			params.sort = state.sort;
			params.order = state.order;
			params.page = state.page;
			params.per_page = state.per_page;
			params.q = state.q;

			view.wrap.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>';
			view.foot.innerHTML = '';
			ctx.api( PATH, params ).then(
				function ( data ) {
					if ( mine !== requests ) {
						return;
					}
					try {
						accept( data );
					} catch ( e ) {
						failed();
					}
				},
				function () {
					if ( mine !== requests ) {
						return;
					}
					failed();
				}
			);
		}

		// 搜索：回车马上搜，停止输入 400 毫秒后搜；输入法组字中（拼音）不搜；新的搜索回到第 1 页。
		function applySearch() {
			window.clearTimeout( searchTimer );
			var q = view.search.value.replace( /^\s+|\s+$/g, '' );
			if ( q === state.q ) {
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
			var btn = findAttr( e.target, 'data-dir', view.foot );
			if ( btn && ! btn.disabled ) {
				state.page = Math.max( 1, state.page + Number( btn.getAttribute( 'data-dir' ) ) );
				load();
			}
		} );

		load();
	}

	PcData.register( 'pages', render );
} )( window.PcData, window.pcDataPages );
