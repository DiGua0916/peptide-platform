/**
 * 商品详情数据页（DATA-01 第 3 步）的报表：每个商品一行（可展开到款式），查看、加购、付款的数字和对比时间段（VS）并排显示，
 * 可以按任意一列排序、搜索商品、分页，“编辑表头”选择要显示的列（记在浏览器里）。
 * 数字来自商品详情数据接口（见下面的 PATH；口径都在 PeptideCore\Data\Products，这里不自己算），
 * 文字来自 window.pcDataProducts.strings，插入页面的值都经过 ctx.esc() 转义。
 * 文件末尾把 render 注册成 products 页面的报表，由 data.js 的外壳调用：切换时间段时它清空页面再调用一次，
 * 所以排序、页码、搜索词、显示的列记在这个脚本里（不在页面元素里）；排序 / 翻页 / 搜索 / 改列只重画表格和分页，不重建工具栏。
 */
( function ( PcData, cfg ) {
	'use strict';

	if ( ! PcData || ! cfg || ! cfg.strings ) {
		return;
	}
	var T = cfg.strings;
	var PATH = '/peptide/v1/data/products';
	var STORE_KEY = 'pcDataProductsColumns';
	var PER_PAGE = 20;
	var SEARCH_DELAY = 400;
	// 和 Products::METRICS 里的金额、比率一致。
	var MONEY = { sales: true, net_sales: true };
	var RATES = { cart_rate: true, conversion: true, exposure_view_user_rate: true, exposure_view_rate: true, exposure_cart_user_rate: true, exposure_cart_rate: true, exposure_paid_user_rate: true, exposure_paid_rate: true, cart_user_rate: true, paid_user_rate: true };

	// 请求计数：排序 / 翻页 / 搜索 / 切换时间段之后，旧请求晚到的结果不再画。
	var requests = 0;
	var searchTimer = 0;
	// 在两次 render 之间保留的状态；columns 为 null 表示用接口给的默认列。
	var state = {
		sort: 'views',
		order: 'desc',
		page: 1,
		q: '',
		columns: readColumns(),
		range: '',
		expanded: {}
	};

	var reportFilter = null;
	function addReportFilter( params, value ) { if ( value ) { Object.keys( value ).forEach( function ( key ) { params[ key ] = value[ key ]; } ); } }
	function checkReportFilter( data ) { if ( reportFilter && JSON.stringify( data.filter ) !== JSON.stringify( reportFilter ) ) { throw new Error( 'Invalid report filter context' ); } }
	function drawReportCoverage( data, bodyEl ) {
		var parts = [], coverage = data.filter_coverage || {};
		Object.keys( coverage ).forEach( function ( period ) { Object.keys( coverage[ period ] ).forEach( function ( unit ) { var c = coverage[ period ][ unit ], g = c.country || {}; parts.push( ( period === 'current' ? T.filterCurrent : T.filterPrevious ) + ' ' + ( T[ { events: 'filterEvents', visits: 'filterVisits', orders: 'filterOrders', episodes: 'filterEpisodes' }[ unit ] ] || unit ) + ': ' + Number( c.matched_count ) + '/' + Number( c.base_count ) + ' · ' + [ g.value, g.unknown, g.unavailable, g.unobserved ].map( Number ).join( '/' ) ); } ); } );
		bodyEl.querySelector( '.pc-report-coverage' ).textContent = parts.length ? T.filterCoverage + ' ' + parts.join( '; ' ) : '';
	}

	function filterToolbar( esc ) {
		function select( key, states ) { return '<select class="pc-report-' + key + '-state">' + states.map( function ( state ) { return '<option value="' + state + '">' + esc( T[ { all: 'filterAll', unobserved: 'filterUnobserved', unknown: 'filterUnknown', unavailable: 'filterUnavailable', value: 'filterValue' }[ state ] ] ) + '</option>'; } ).join( '' ) + '</select>'; }
		return '<div class="pc-report-filters"><label>' + esc( T.filterDomain ) + select( 'domain', [ 'all', 'unobserved', 'value' ] ) + '<input type="text" class="pc-report-domain" maxlength="253" aria-label="' + esc( T.filterDomain ) + '"></label><label>' + esc( T.filterCountry ) + select( 'country', [ 'all', 'unobserved', 'unknown', 'unavailable', 'value' ] ) + '<input type="text" class="pc-report-country" maxlength="2" aria-label="' + esc( T.filterCountry ) + '"></label><button type="button" class="button pc-report-filter-apply">' + esc( T.filterApply ) + '</button><p class="pc-data-muted pc-report-filter-help">' + esc( T.filterHelp ) + '</p><p class="pc-report-coverage" role="status"></p></div>';
	}

	// ---------- 小工具 ----------

	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

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

	// 链接只接受 http(s) 或站内路径。
	function safeUrl( u ) {
		var s = String( u || '' );
		return /^https?:\/\//i.test( s ) || '/' === s.charAt( 0 ) ? s : '';
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

	// 浏览器存储可能不可用（隐私模式等），读写都包在 try / catch 里。
	function readColumns() {
		try {
			var saved = JSON.parse( window.localStorage.getItem( STORE_KEY ) );
			if ( Array.isArray( saved ) ) {
				return saved.filter( function ( k ) {
					return 'string' === typeof k;
				} );
			}
		} catch ( e ) {
			// 读不到就用默认列。
		}
		return null;
	}

	function saveColumns( cols ) {
		try {
			window.localStorage.setItem( STORE_KEY, JSON.stringify( cols ) );
		} catch ( e ) {
			// 存不进去不影响使用。
		}
	}

	// Validate keys against the response, deduplicate without changing wanted order.
	function normalizeColumns( wanted, metrics ) {
		var columns = [];
		( wanted || [] ).forEach( function ( key ) {
			if ( 'string' === typeof key && metrics.indexOf( key ) !== -1 && columns.indexOf( key ) === -1 ) { columns.push( key ); }
		} );
		return columns;
	}

	// A stored [] is deliberate; a nonempty entirely obsolete choice uses defaults.
	function allowedColumns( data ) { return data.columns || data.metrics || []; }
	function numericCount( columns, data ) { return columns.filter( function ( key ) { return ( data.metrics || [] ).indexOf( key ) !== -1; } ).length; }
	function visibleColumns( data ) {
		var metrics = allowedColumns( data );
		var wanted = null === state.columns ? data.defaults || [] : state.columns;
		var cols = normalizeColumns( wanted, metrics );
		// 存的列全部过期（指标改名了）时回到默认列；用户主动一列都不选（存的是空数组）则保持为空。
		if ( ! cols.length && wanted.length ) {
			cols = normalizeColumns( data.defaults || [], metrics );
		}
		return cols;
	}

	// 一个指标的显示值：没有（null）显示“-”。
	function value( key, v, ctx ) {
		if ( ! isNum( v ) ) {
			return '-';
		}
		if ( MONEY[ key ] ) {
			return ctx.money( v );
		}
		if ( RATES[ key ] ) {
			return ( Math.round( Number( v ) * 100 ) / 100 ) + '%';
		}
		return ctx.number( v );
	}

	// 一个指标的单元格：本期值、对比期值（灰色，前面加 VS）、变化（涨绿跌红；比率的变化是百分点，文字同样由 ctx.change() 给出）。
	function metricCell( key, cur, prev, chg, ctx ) {
		var esc = ctx.esc;
		var c = chg ? chg[ key ] : null;
		var dir = isNum( c ) && Number( c ) > 0 ? ' is-up' : ( isNum( c ) && Number( c ) < 0 ? ' is-down' : '' );
		var change = ctx.change( c );
		if ( RATES[ key ] ) { change = isNum( c ) ? ( Number( c ) > 0 ? '+' : '' ) + ( Math.round( Number( c ) * 100 ) / 100 ) + ' ' + ( T.points || 'Percentage points' ) : '-'; }
		return '<td class="pc-prod-metric">' +
			'<div class="pc-prod-cur">' + esc( value( key, cur ? cur[ key ] : null, ctx ) ) + '</div>' +
			'<div class="pc-prod-prev"><span class="screen-reader-text">' + esc( ctx.strings.vs + ' ' ) + '</span>' + esc( value( key, prev ? prev[ key ] : null, ctx ) ) + '</div>' +
			'<div class="pc-prod-chg' + dir + '">' + esc( change ) + '</div>' +
		'</td>';
	}

	// ---------- 表格 ----------

	function headerCell( key, data, ctx ) {
		var esc = ctx.esc;
		if ( 'created_at' === key ) { return '<th scope="col" class="pc-prod-th">' + esc( T.created_at || key ) + '</th>'; }
		var active = key === data.sort;
		var arrow = active ? ( 'asc' === data.order ? ' ▲' : ' ▼' ) : '';
		var aria = active ? ( 'asc' === data.order ? 'ascending' : 'descending' ) : 'none';
		return '<th scope="col" class="pc-prod-th" aria-sort="' + aria + '">' +
			'<button type="button" class="pc-prod-sort' + ( active ? ' is-active' : '' ) + '" data-sort="' + esc( key ) + '">' +
				esc( T[ key ] || key ) + '<span class="pc-prod-arrow" aria-hidden="true">' + arrow + '</span>' +
			'</button>' +
		'</th>';
	}

	function createdCell( info, ctx ) {
		return '<td class="pc-prod-created">' + ctx.esc( info.created_at_label || '-' ) + '</td>';
	}

	function productRow( row, cols, ctx ) {
		var esc = ctx.esc;
		var p = row.product || {};
		var id = Number( p.id );
		var open = !! state.expanded[ id ];
		var image = safeUrl( p.image );
		var edit = safeUrl( p.edit_url );
		var vars = row.variations || [];
		var thumb = image ?
			'<img class="pc-prod-thumb" src="' + esc( image ) + '" alt="" width="40" height="40" loading="lazy">' :
			'<span class="pc-prod-thumb pc-prod-thumb--empty" aria-hidden="true"></span>';
		var name = edit ?
			'<a class="pc-prod-name" href="' + esc( edit ) + '">' + esc( p.name ) + '</a>' :
			'<span class="pc-prod-name">' + esc( p.name ) + '</span>';
		var sku = p.sku ? '<div class="pc-prod-sku">' + esc( fill( T.sku, { '%s': p.sku } ) ) + '</div>' : '';
		var toggle = vars.length ?
			'<button type="button" class="button-link pc-prod-toggle" data-toggle="' + esc( id ) + '" aria-expanded="' + ( open ? 'true' : 'false' ) + '">' + esc( T.variations ) + '</button>' :
			'';
		var html = '<tr class="pc-prod-row">' +
			'<td class="pc-prod-product"><div class="pc-prod-cell">' + thumb +
				'<div class="pc-prod-info">' + name + sku + toggle + '</div>' +
			'</div></td>';
		cols.forEach( function ( key ) {
			html += 'created_at' === key ? createdCell( p, ctx ) : metricCell( key, row.current, row.previous, row.change, ctx );
		} );
		html += '</tr>';

		// 款式行紧跟在商品行下面，默认收起。
		vars.forEach( function ( v ) {
			var info = v.variation || {};
			var vsku = info.sku ? '<div class="pc-prod-sku">' + esc( fill( T.sku, { '%s': info.sku } ) ) + '</div>' : '';
			html += '<tr class="pc-prod-var" data-parent="' + esc( id ) + '"' + ( open ? '' : ' hidden' ) + '>' +
				'<td class="pc-prod-product"><div class="pc-prod-info pc-prod-info--var"><span class="pc-prod-name">' + esc( info.name ) + '</span>' + vsku + '</div></td>';
			cols.forEach( function ( key ) {
				html += 'created_at' === key ? createdCell( info, ctx ) : metricCell( key, v.current, v.previous, v.change, ctx );
			} );
			html += '</tr>';
		} );
		return html;
	}

	function tableHtml( data, ctx ) {
		var esc = ctx.esc;
		var cols = visibleColumns( data );
		var head = '<th scope="col" class="pc-prod-th pc-prod-th--product">' + esc( T.product ) + '</th>';
		cols.forEach( function ( key ) {
			head += headerCell( key, data, ctx );
		} );
		var body = ( data.rows || [] ).map( function ( row ) {
			return productRow( row, cols, ctx );
		} ).join( '' );
		var caption = cols.indexOf( 'created_at' ) === -1 ? '' : '<caption>' + esc( fill( T.createdTimezone || 'Creation times are shown in %s. CSV uses UTC.', { '%s': data.report_timezone || '-' } ) ) + '</caption>';
		return '<table class="pc-prod-table">' + caption + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
	}

	// ---------- 报表 ----------

	function render( bodyEl, range, ctx ) {
		var esc = ctx.esc;
		var view = { data: null };
		var composing = false;
		var exportToken = 0, exporting = false, rangeBusy = false, searchDirty = false;

		// 上一次画的页面已经被 data.js 清掉了：它的搜索计时器不能再触发。
		window.clearTimeout( searchTimer );
		var key = rangeKey( range );
		if ( key !== state.range ) {
			state.range = key;
			state.page = 1;
		}

		// 工具栏、列面板、表格容器、分页每次 render 只建一次；后面只改表格容器和分页的内容。
		bodyEl.innerHTML = '<div class="pc-prod">' + filterToolbar( esc ) + '<p class="pc-data-muted">' + esc( T.exposureNote || 'Exposure metrics use received visibility observations for parent products only. They do not measure complete physical reach; variations have no separate exposure measurement.' ) + '</p>' + '<p class="pc-data-muted">' + esc( T.userRatioNote || 'Cart and viewing users count observed visitor IDs. Paid users count native buyers (account or normalized guest email) in the order-created period. Both ratios divide by viewing users. These ratios mix identities and time windows; they are not an owner-bound visitor funnel or a verified Shopname formula.' ) + '</p>' + '<p class="pc-data-muted">' + esc( T.minColumns || 'Keep at least five selected numeric columns. Existing saved choices are retained.' ) + '</p>' +
			'<p class="pc-data-muted pc-product-action-note">' + esc( T.actionNote ) + '</p>' +
			'<div class="pc-prod-toolbar">' +
				'<input type="search" class="pc-prod-search" maxlength="100" placeholder="' + esc( T.search ) + '" aria-label="' + esc( T.search ) + '">' +
				'<button type="button" class="button pc-prod-columns-btn" aria-expanded="false">' + esc( T.columns ) + '</button>' +
				'<button type="button" class="button pc-prod-export" title="' + esc( T.exportHelp ) + '" disabled>' + esc( T.exportCsv ) + '</button>' +
				'<span class="pc-prod-export-status" role="status" aria-live="polite"></span>' +
			'</div>' +
			'<div class="pc-prod-columns" hidden></div>' +
			'<div class="pc-prod-wrap"></div>' +
			'<div class="pc-prod-pager"></div>' +
		'</div>';
		view.search = bodyEl.querySelector( '.pc-prod-search' );
		view.button = bodyEl.querySelector( '.pc-prod-columns-btn' );
		view.panel = bodyEl.querySelector( '.pc-prod-columns' );
		view.wrap = bodyEl.querySelector( '.pc-prod-wrap' );
		view.pager = bodyEl.querySelector( '.pc-prod-pager' );
		view.search.value = state.q;

		var domainState = bodyEl.querySelector( '.pc-report-domain-state' ), domainValue = bodyEl.querySelector( '.pc-report-domain' ), countryState = bodyEl.querySelector( '.pc-report-country-state' ), countryValue = bodyEl.querySelector( '.pc-report-country' ), filterApply = bodyEl.querySelector( '.pc-report-filter-apply' );
		var filterDirty = false, filterApplyPending = false;
		var acceptedFilter = reportFilter || { entry_domain_state: 'all', country_state: 'all' };
		function filterDraft() {
			var draft = { entry_domain_state: domainState.value };
			if ( draft.entry_domain_state === 'value' ) { draft.entry_domain = domainValue.value.toLowerCase().replace( /\.$/, '' ); }
			draft.country_state = countryState.value;
			if ( draft.country_state === 'value' ) { draft.country = countryValue.value.toUpperCase(); }
			return draft;
		}
		function writeFilterControls( filter ) { domainState.value = filter.entry_domain_state; domainValue.value = filter.entry_domain || ''; countryState.value = filter.country_state; countryValue.value = filter.country || ''; }
		writeFilterControls( acceptedFilter );
		function acceptFilterControls( data ) {
			acceptedFilter = data.filter || { entry_domain_state: 'all', country_state: 'all' };
			if ( filterApplyPending || ! filterDirty ) { writeFilterControls( acceptedFilter ); }
			filterDirty = JSON.stringify( filterDraft() ) !== JSON.stringify( acceptedFilter );
			filterApplyPending = false;
		}
		function showFilterDraft() {
			if ( ! filterDirty ) { return; }
			view.wrap.innerHTML = '<p class="pc-data-muted">' + esc( T.filterApply ) + '</p>';
			( view.pager || view.foot ).innerHTML = '';
			bodyEl.querySelector( '.pc-report-coverage' ).textContent = T.filterApply;
		}
		function filterEdited() { filterDirty = JSON.stringify( filterDraft() ) !== JSON.stringify( acceptedFilter ); filterApplyPending = false; ++requests; view.data = null; if ( typeof cancelExport === 'function' ) { cancelExport(); } showFilterDraft(); }
		[ domainState, countryState ].forEach( function ( element ) { element.addEventListener( 'change', filterEdited ); } );
		[ domainValue, countryValue ].forEach( function ( element ) { element.addEventListener( 'input', filterEdited ); } );
		filterApply.addEventListener( 'click', function () {
			if ( rangeBusy ) { return; }
			reportFilter = filterDraft(); filterApplyPending = true; filterDirty = true;
			state.page = 1; load();
		} );

		view.exportButton = bodyEl.querySelector( '.pc-prod-export' );
		view.exportStatus = bodyEl.querySelector( '.pc-prod-export-status' );
		function syncExport() {
			view.exportButton.disabled = ! view.data || filterDirty || searchDirty || composing || rangeBusy || exporting;
			view.exportButton.setAttribute( 'aria-busy', exporting ? 'true' : 'false' );
		}
		function cancelExport() {
			++exportToken; exporting = false;
			view.exportStatus.textContent = '';
			syncExport();
		}
		if ( 'function' === typeof ctx.onRangeState ) {
			ctx.onRangeState( function ( on ) { rangeBusy = on; if ( on ) { cancelExport(); } syncExport(); } );
		}
		view.exportButton.addEventListener( 'click', function () {
			if ( view.exportButton.disabled || ! window.PcDataExport ) { return; }
			var mine = ++exportToken;
			var dates = view.data.range.dates;
			var params = { from: dates.from, to: dates.to, compare_from: dates.compare_from, compare_to: dates.compare_to };
			params.format = 'csv'; params.sort = view.data.sort; params.order = view.data.order;
			params.page = view.data.page; params.per_page = view.data.per_page; params.q = view.data.q;
			addReportFilter( params, view.data.filter );
			params.columns = visibleColumns( view.data ).join( ',' );
			exporting = true; syncExport(); view.exportStatus.textContent = T.exportBusy;
			function current() { return mine === exportToken && ! filterDirty && ! rangeBusy && ! searchDirty && ! composing && !! view.data && document.documentElement.contains( view.exportButton ); }
			window.PcDataExport.prepare( PATH, params ).then( function ( file ) {
				if ( ! current() ) { return; }
				try { if ( window.PcDataExport.download( file, current ) ) { view.exportStatus.textContent = T.exportDone; } }
				catch ( e ) { view.exportStatus.textContent = T.exportError; }
				exporting = false; syncExport();
			}, function () { if ( current() ) { exporting = false; syncExport(); view.exportStatus.textContent = T.exportError; } } );
		} );


		function failed() {
			invalidate();
			view.wrap.innerHTML = '<p class="pc-data-empty">' + esc( ctx.strings.loadError ) + '</p>';
			view.pager.innerHTML = '';
		}

		function invalidate() {
			view.data = null;
			cancelExport();
			view.button.disabled = true;
			view.button.setAttribute( 'aria-expanded', 'false' );
			view.panel.setAttribute( 'hidden', '' );
			view.panel.innerHTML = '';
		}

		function focusColumn( key ) {
			each( view.panel.querySelectorAll( 'input[data-key]' ), function ( box ) {
				if ( box.getAttribute( 'data-key' ) === key ) { box.focus(); }
			} );
		}

		function drawPanel() {
			var data = view.data;
			if ( ! data ) { return; }
			var shown = visibleColumns( data );
			var unselected = normalizeColumns( allowedColumns( data ), allowedColumns( data ) ).filter( function ( key ) { return shown.indexOf( key ) === -1; } );
			view.panel.innerHTML = shown.concat( unselected ).map( function ( k ) {
				var index = shown.indexOf( k );
				var label = T[ k ] || k;
				var controls = index < 0 ? '' : '<span class="pc-prod-columns__moves">' +
					'<button type="button" class="pc-prod-columns__move" data-move-key="' + esc( k ) + '" data-move-dir="-1" aria-label="' + esc( fill( T.moveUp, { '%s': label } ) ) + '"' + ( index === 0 ? ' disabled' : '' ) + '>↑</button>' +
					'<button type="button" class="pc-prod-columns__move" data-move-key="' + esc( k ) + '" data-move-dir="1" aria-label="' + esc( fill( T.moveDown, { '%s': label } ) ) + '"' + ( index === shown.length - 1 ? ' disabled' : '' ) + '>↓</button></span>';
				return '<div class="pc-prod-columns__item"><label class="pc-prod-columns__label"><input type="checkbox" data-key="' + esc( k ) + '"' +
					( index !== -1 ? ' checked' : '' ) + ( index !== -1 && ( data.metrics || [] ).indexOf( k ) !== -1 && numericCount( shown, data ) <= 5 && ( data.metrics || [] ).length >= 5 ? ' disabled' : '' ) + '><span>' + esc( label ) + '</span></label>' + controls + '</div>';
			} ).join( '' );
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

		function drawPager() {
			var data = view.data;
			if ( ! data || ! Number( data.total ) ) {
				view.pager.innerHTML = '';
				return;
			}
			var pages = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page || PER_PAGE ) ) );
			var page = Number( data.page );
			view.pager.innerHTML = '<span class="pc-prod-page">' + esc( fill( T.page, { '%1$d': page, '%2$d': pages } ) ) + '</span>' +
				'<button type="button" class="button" data-dir="-1"' + ( page <= 1 ? ' disabled' : '' ) + '>' + esc( T.prev ) + '</button>' +
				'<button type="button" class="button" data-dir="1"' + ( page >= pages ? ' disabled' : '' ) + '>' + esc( T.next ) + '</button>';
		}

		function accept( data ) {
			checkReportFilter( data );
			acceptFilterControls( data );
			drawReportCoverage( data, bodyEl );
			view.data = data;
			// 页码超出了最后一页（数据变少了）：回到最后一页重新取。
			if ( ! ( data.rows || [] ).length && Number( data.total ) > 0 && Number( data.page ) > 1 ) {
				state.page = Math.max( 1, Math.ceil( Number( data.total ) / Number( data.per_page || PER_PAGE ) ) );
				load();
				return;
			}
			state.sort = data.sort;
			state.order = data.order;
			state.page = Number( data.page );
			syncExport();
			drawPanel();
			drawTable();
			drawPager();
			showFilterDraft();
			view.button.disabled = false;
		}

		function load() {
			var mine = ++requests;
			invalidate();
			var params = rangeParams( range );
			params.sort = state.sort;
			params.order = state.order;
			params.page = state.page;
			params.per_page = PER_PAGE;
			params.q = state.q;
			addReportFilter( params, reportFilter );

			view.wrap.innerHTML = '<p class="pc-data-muted">' + esc( ctx.strings.loading ) + '</p>';
			view.pager.innerHTML = '';
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

		// 搜索：回车马上搜，停止输入 400 毫秒后搜；输入法组字中（拼音）不搜。
		function applySearch() {
			window.clearTimeout( searchTimer );
			searchDirty = false; syncExport();
			var q = view.search.value.replace( /^\s+|\s+$/g, '' );
			if ( q === state.q ) {
				return;
			}
			state.q = q;
			state.page = 1;
			load();
		}
		view.search.addEventListener( 'compositionstart', function () {
			composing = true; cancelExport();
			window.clearTimeout( searchTimer );
		} );
		view.search.addEventListener( 'compositionend', function () {
			composing = false; searchDirty = true; cancelExport();
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( applySearch, SEARCH_DELAY );
		} );
		view.search.addEventListener( 'input', function () {
			searchDirty = true; cancelExport();
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

		// 编辑表头：面板开关；勾选改变后立刻重画表格并记住。
		view.button.addEventListener( 'click', function () {
			if ( ! view.data ) { return; }
			var opening = view.panel.hasAttribute( 'hidden' );
			if ( opening ) {
				view.panel.removeAttribute( 'hidden' );
			} else {
				view.panel.setAttribute( 'hidden', '' );
			}
			view.button.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		} );
		view.panel.addEventListener( 'change', function ( event ) {
			if ( ! view.data ) { return; }
			var box = findAttr( event.target, 'data-key', view.panel );
			var key = box ? box.getAttribute( 'data-key' ) : null;
			if ( ! box || allowedColumns( view.data ).indexOf( key ) === -1 ) { return; }
			var cols = visibleColumns( view.data );
			if ( ! box.checked && cols.indexOf( key ) >= 0 && ( view.data.metrics || [] ).indexOf( key ) !== -1 && numericCount( cols, view.data ) <= 5 && ( view.data.metrics || [] ).length >= 5 ) { box.checked = true; return; }
			if ( box.checked && cols.indexOf( key ) === -1 ) { cols.push( key ); }
			if ( ! box.checked ) { cols = cols.filter( function ( k ) { return k !== key; } ); }
			state.columns = cols;
			cancelExport();
			saveColumns( cols );
			drawPanel();
			drawTable();
			focusColumn( key );
		} );
		view.panel.addEventListener( 'click', function ( event ) {
			if ( ! view.data ) { return; }
			var button = findAttr( event.target, 'data-move-key', view.panel );
			if ( ! button || button.disabled ) { return; }
			var key = button.getAttribute( 'data-move-key' );
			var direction = Number( button.getAttribute( 'data-move-dir' ) );
			if ( direction !== -1 && direction !== 1 ) { return; }
			var cols = visibleColumns( view.data );
			var index = cols.indexOf( key );
			var next = index + direction;
			if ( index < 0 || next < 0 || next >= cols.length ) { return; }
			cols[ index ] = cols[ next ]; cols[ next ] = key;
			state.columns = cols;
			cancelExport();
			saveColumns( cols );
			drawPanel();
			drawTable();
			focusColumn( key );
		} );

		// 表头排序、款式展开 / 收起。
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
				return;
			}
			var toggle = findAttr( e.target, 'data-toggle', view.wrap );
			if ( toggle ) {
				var id = toggle.getAttribute( 'data-toggle' );
				var opening = 'true' !== toggle.getAttribute( 'aria-expanded' );
				toggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
				state.expanded[ id ] = opening;
				each( view.wrap.querySelectorAll( 'tr[data-parent="' + id + '"]' ), function ( tr ) {
					if ( opening ) {
						tr.removeAttribute( 'hidden' );
					} else {
						tr.setAttribute( 'hidden', '' );
					}
				} );
			}
		} );

		// 上一页 / 下一页。
		view.pager.addEventListener( 'click', function ( e ) {
			var btn = findAttr( e.target, 'data-dir', view.pager );
			if ( btn && ! btn.disabled ) {
				state.page = Math.max( 1, state.page + Number( btn.getAttribute( 'data-dir' ) ) );
				load();
			}
		} );

		load();
	}

	PcData.register( 'products', render );
} )( window.PcData, window.pcDataProducts );
