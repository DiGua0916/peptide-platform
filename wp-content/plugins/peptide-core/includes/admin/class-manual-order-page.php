<?php
/**
 * 订单 → 手动订单（店主 2026-11-10）：选商品、改原价 / 售价、自定义运费、加几笔优惠，生成付款链接。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

defined( 'ABSPATH' ) || exit;

final class Manual_Order_Page {

	public const SLUG = 'pc-manual-order';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function add_menu(): void {
		global $menu;
		$slugs  = array_column( (array) $menu, 2 );
		$parent = in_array( Admin_Menus::PENDING, $slugs, true ) ? Admin_Menus::PENDING : ( in_array( Admin_Menus::ORDERS, $slugs, true ) ? Admin_Menus::ORDERS : 'woocommerce' );
		add_submenu_page( $parent, '手动订单', '手动订单', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) ) {
			return;
		}
		$f = PEPTIDE_CORE_DIR . '/assets/manual-order.js';
		wp_enqueue_script( 'pc-manual-order', plugins_url( 'assets/manual-order.js', PEPTIDE_CORE_FILE ), array( 'wp-api-fetch' ), (string) @filemtime( $f ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		// 打开时带上的内容：弃单恢复（from_order / from_rec）或草稿（draft）。
		$prefill = null;
		$draft   = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['from_order'] ) ) {
			$o = wc_get_order( absint( $_GET['from_order'] ) );
			$prefill = $o instanceof \WC_Order ? \PeptideCore\Manual_Orders::state_from_order( $o ) : null;
		} elseif ( ! empty( $_GET['from_rec'] ) && class_exists( \PeptideCore\Recovery::class ) ) {
			global $wpdb;
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \PeptideCore\Recovery::table() . ' WHERE id = %d', absint( $_GET['from_rec'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$prefill = $r ? \PeptideCore\Manual_Orders::state_from_record( $r ) : null;
		} elseif ( ! empty( $_GET['draft'] ) ) {
			$id = sanitize_key( (string) $_GET['draft'] );
			$d  = \PeptideCore\Manual_Orders::drafts()[ $id ] ?? null;
			if ( $d ) {
				$prefill = $d['state'];
				$draft   = $id;
			}
		}
		// phpcs:enable
		wp_add_inline_script( 'pc-manual-order', 'window.pcManual=' . wp_json_encode( array( 'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ), 'decimals' => wc_get_price_decimals(), 'prefill' => $prefill, 'draft' => $draft ) ) . ';', 'before' );
		$c = PEPTIDE_CORE_DIR . '/assets/manual-order.css';
		wp_enqueue_style( 'pc-manual-order', plugins_url( 'assets/manual-order.css', PEPTIDE_CORE_FILE ), array(), (string) @filemtime( $c ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		?>
<div class="wrap pc-mo" data-pc-manual>
	<h1>手动订单</h1>
	<p class="pc-mo-intro">搜索商品加进来，每件的原价和售价都可以改（只对这一单有效，不改商品本身的价格），自定义运费和优惠，最后生成付款链接发给顾客。顾客打开链接就是这些商品和价格，直接进结账填地址付款。不会叠加店铺的满减活动和优惠券。</p>
	<div class="pc-mo-grid">
		<div class="pc-mo-main">
			<section class="pc-mo-card">
				<h2>商品</h2>
				<div class="pc-mo-search">
					<input type="search" placeholder="搜索商品名称或 SKU" data-mo-q autocomplete="off">
					<div class="pc-mo-results" data-mo-results hidden></div>
				</div>
				<table class="pc-mo-table">
					<thead><tr><th>商品</th><th>数量</th><th>原价</th><th>售价</th><th>小计</th><th></th></tr></thead>
					<tbody data-mo-lines><tr class="pc-mo-empty"><td colspan="6">还没有商品，在上面搜索添加。</td></tr></tbody>
				</table>
			</section>
			<section class="pc-mo-card">
				<h2>运费</h2>
				<label class="pc-mo-radio"><input type="radio" name="mo-ship" value="store" checked data-mo-ship> 按店铺运费（顾客填地址后自动算）</label>
				<label class="pc-mo-radio"><input type="radio" name="mo-ship" value="custom" data-mo-ship> 自定义运费</label>
				<div class="pc-mo-row" data-mo-ship-custom hidden>
					<label>名称（顾客看到）<input type="text" value="Shipping" data-mo-ship-label></label>
					<label>运费<input type="number" min="0" step="0.01" value="0" data-mo-ship-amount></label>
				</div>
			</section>
			<section class="pc-mo-card">
				<h2>优惠</h2>
				<p class="pc-mo-hint">可以加几笔，每笔一个名称（顾客在结账页看到，建议写英文，如 “Loyal customer discount”）和金额。</p>
				<div data-mo-discounts></div>
				<button type="button" class="button" data-mo-add-discount>+ 添加一笔优惠</button>
			</section>
			<section class="pc-mo-card">
				<h2>店铺活动</h2>
				<p class="pc-mo-hint">开了的会自动带进这一单，顾客打开链接直接付款，不用自己填优惠码。不开就只按上面你填的价格和优惠。</p>
				<label class="pc-mo-switch"><input type="checkbox" data-mo-promos><i></i><span><b>店铺满减活动</b><small data-mo-promos-text>正在读取…</small></span></label>
				<label class="pc-mo-switch"><input type="checkbox" data-mo-first><i></i><span><b>首单优惠</b><small data-mo-first-text>正在读取…</small></span></label>
			</section>
			<section class="pc-mo-card">
				<h2>备注（只有你看得到）</h2>
				<input type="text" class="pc-mo-note" maxlength="200" placeholder="例如：给 Luz 的老客价" data-mo-note>
			</section>
		</div>
		<aside class="pc-mo-side">
			<section class="pc-mo-card pc-mo-sum">
				<h2>订单金额</h2>
				<dl data-mo-sum></dl>
				<button type="button" class="button button-primary button-hero" data-mo-create disabled>生成付款链接</button>
				<button type="button" class="button pc-mo-savedraft" data-mo-save-draft>保存草稿</button>
				<p class="pc-mo-hint" data-mo-draft-state></p>
				<p class="pc-mo-err" data-mo-err hidden></p>
			</section>
			<section class="pc-mo-card">
				<h2>草稿</h2>
				<div data-mo-drafts><p class="pc-mo-hint">…</p></div>
			</section>
			<section class="pc-mo-card">
				<h2>最近生成的链接</h2>
				<div data-mo-recent><p class="pc-mo-hint">…</p></div>
			</section>
		</aside>
	</div>
	<dialog class="pc-mo-dialog" data-mo-dialog>
		<h2>付款链接已生成</h2>
		<p>发给顾客，打开就是这一单的商品和价格，直接结账付款。30 天内有效。</p>
		<div class="pc-mo-link"><input type="text" readonly data-mo-link><button type="button" class="button button-primary" data-mo-copy>复制链接</button></div>
		<dl data-mo-dsum></dl>
		<p class="pc-mo-actions"><a class="button" target="_blank" rel="noopener" data-mo-open>打开看看</a><button type="button" class="button" data-mo-new>再做一单</button><button type="button" class="button" data-mo-close>关闭</button></p>
	</dialog>
</div>
		<?php
	}
}
