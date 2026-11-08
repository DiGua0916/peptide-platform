<?php
/**
 * 总后台 → 店铺复制（2026-11-08）。一键完整复制，或勾选要复制的内容；下方是复制记录。
 * 实际复制见 PeptideHq\Store_Clone 和开店任务的 clone.py。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Store_Clone;

defined( 'ABSPATH' ) || exit;

final class Clone_Page {

	public const SLUG = 'pchq-clone';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '店铺复制', '店铺复制', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			19
		);
		add_action( 'admin_post_pchq_clone_start', array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) );
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true );
		wp_add_inline_script( 'pchq-plugins', "(function(){function sync(f){var custom=f.querySelector('input[name=mode][value=custom]');var box=f.querySelector('.pcp-clone-parts');if(box)box.hidden=!(custom&&custom.checked);var n=f.querySelectorAll('input[name=\"targets[]\"]:checked').length;var c=f.querySelector('[data-pc-clone-count]');if(c)c.textContent='已选 '+n+' 家';var b=f.querySelector('button[type=submit]');if(b)b.disabled=!n;}document.addEventListener('change',function(e){var f=e.target&&e.target.form;if(f&&f.classList.contains('pcp-clone-form'))sync(f);});document.addEventListener('click',function(e){var t=e.target.closest&&e.target.closest('[data-pc-clone-all],[data-pc-clone-none]');if(!t)return;var f=t.form;f.querySelectorAll('input[name=\"targets[]\"]').forEach(function(i){i.checked=t.hasAttribute('data-pc-clone-all');});sync(f);});document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.pcp-clone-form').forEach(sync);});})();" );
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_clone_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权复制店铺。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_clone_start' );
		$source  = (int) ( $_POST['source'] ?? 0 );
		$targets = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['targets'] ?? array() ) ) ) ) );
		$mode    = (string) ( $_POST['mode'] ?? 'all' );
		$parts   = 'custom' === $mode ? array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ) : 'all';
		if ( ! $targets ) {
			self::flash( 'error', '请至少勾选一家目标店铺。' );
		} else {
			$stores = Store_Clone::stores(); $ok = array(); $bad = array();
			foreach ( array_slice( $targets, 0, 20 ) as $t ) {
				$res = Store_Clone::request( $source, $t, $parts );
				if ( is_wp_error( $res ) ) { $bad[] = self::name( $stores, $t ) . '：' . $res->get_error_message(); } else { $ok[] = self::name( $stores, $t ); }
			}
			$msg = $ok ? '已提交 ' . count( $ok ) . ' 家店铺的复制（' . implode( '、', $ok ) . '），会一家接一家进行，每家约 1 分钟，下方记录会显示进度。' : '';
			if ( $bad ) { $msg .= ( $msg ? ' 未提交：' : '' ) . implode( '；', $bad ); }
			self::flash( $ok ? 'success' : 'error', $msg );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function name( array $stores, int $id ): string {
		$s = $stores[ $id ] ?? null;
		if ( ! $s ) {
			return '店铺 #' . $id;
		}
		return '' !== (string) $s['name'] ? (string) $s['name'] : (string) wp_parse_url( (string) $s['url'], PHP_URL_HOST );
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$stores = Store_Clone::stores();
		$main   = Store_Clone::main_id();
		$flash  = get_transient( 'pchq_clone_flash_' . get_current_user_id() );
		delete_transient( 'pchq_clone_flash_' . get_current_user_id() );
		$targets = array_filter( $stores, static fn( $s ): bool => (int) $s['id'] !== $main );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>店铺复制</h1><p>把一家店的商品、图片、页面、装修和设置整套复制到另一家店。订单、顾客和付款记录不会复制；目标店铺自己的域名、后台连接和支付绑定保持不变。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Store_Clone::ready() ) {
			echo '<div class="pcp-flash is-bad">复制服务还没准备好（开店任务每 20 秒检查一次），请稍后刷新。</div>';
		}

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>开始复制</h2><span class="pcp-hint">目标店铺现有的商品、页面和装修会被替换</span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-cdn-form pcp-clone-form" data-confirm="确定复制？勾选的每家目标店铺现有的商品、页面、装修和设置都会被来源店铺的内容替换（订单和顾客不受影响）。复制前会自动备份每家目标店铺。">';
		wp_nonce_field( 'pchq_clone_start' );
		echo '<input type="hidden" name="action" value="pchq_clone_start">';
		echo '<label class="pcp-field"><span>来源店铺（复制谁）</span><select name="source" required>';
		foreach ( $stores as $id => $s ) {
			echo '<option value="' . (int) $id . '"' . selected( (int) $id, $main, false ) . '>' . esc_html( self::name( $stores, (int) $id ) . ( (int) $id === $main ? '（主店）' : '' ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<fieldset class="pcp-clone-targets"><legend>目标店铺（复制到哪，可多选）</legend>';
		if ( ! $targets ) {
			echo '<p class="pcp-hint">还没有其他店铺。</p>';
		} else {
			echo '<div class="pcp-clone-targets__tools"><button type="button" class="button button-small" data-pc-clone-all>全选</button><button type="button" class="button button-small" data-pc-clone-none>清空</button><span class="pcp-hint" data-pc-clone-count>已选 0 家</span></div><div class="pcp-clone-targets__list">';
			foreach ( $targets as $id => $s ) {
				echo '<label class="pcp-check pcp-clone-target"><input type="checkbox" name="targets[]" value="' . (int) $id . '"> <strong>' . esc_html( self::name( $stores, (int) $id ) ) . '</strong><small>' . esc_html( (string) wp_parse_url( (string) $s['url'], PHP_URL_HOST ) ) . '</small></label>';
			}
			echo '</div>';
		}
		echo '</fieldset>';
		echo '<fieldset class="pcp-clone-mode"><legend class="screen-reader-text">复制方式</legend>';
		echo '<label class="pcp-check"><input type="radio" name="mode" value="all" checked> <strong>一键完整复制</strong> — 全部内容，一样不少</label>';
		echo '<label class="pcp-check"><input type="radio" name="mode" value="custom"> 选择要复制的内容</label>';
		echo '<div class="pcp-clone-parts" hidden>';
		foreach ( Store_Clone::PARTS as $key => $label ) {
			echo '<label class="pcp-check"><input type="checkbox" name="parts[]" value="' . esc_attr( $key ) . '" checked> ' . esc_html( $label ) . '</label>';
		}
		echo '</div></fieldset>';
		echo '<div class="pcp-clone-actions"><button type="submit" class="button button-primary"' . ( $targets && Store_Clone::ready() ? '' : ' disabled' ) . '>开始复制</button><span class="pcp-hint">复制前会自动备份目标店铺</span></div></form>';
		echo '<div class="pcp-clone-facts"><div><strong>不会复制</strong><ul><li>订单和付款记录</li><li>顾客账号和访客记录</li><li>优惠码已用次数</li></ul></div><div><strong>目标店铺保留自己的</strong><ul><li>域名、店铺名称</li><li>后台账号、总后台连接</li><li>支付绑定、邮箱</li></ul></div></div></section>';

		$jobs = Store_Clone::jobs();
		echo '<h2 class="pcp-section">复制记录 <span>' . count( $jobs ) . '</span></h2>';
		if ( ! $jobs ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有复制记录</strong><p>提交后会显示在这里。</p></section>';
		} else {
			$st = array( 'queued' => array( '排队中', 'is-wait' ), 'running' => array( '正在复制', 'is-wait' ), 'done' => array( '已完成', 'is-ok' ), 'failed' => array( '失败', 'is-bad' ) );
			echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">时间</th><th scope="col">来源 → 目标</th><th scope="col">内容</th><th scope="col">结果</th></tr></thead><tbody>';
			foreach ( $jobs as $j ) {
				$s2    = $st[ $j['state'] ] ?? array( $j['state'], 'is-off' );
				$parts = 'all' === $j['parts'] ? '完整复制' : implode( '、', array_map( static fn( $p ) => Store_Clone::PARTS[ $p ] ?? $p, (array) $j['parts'] ) );
				$c     = is_array( $j['counts'] ) ? $j['counts'] : array();
				$sum   = $c ? sprintf( '商品 %d · 图片 %d · 页面 %d · 模板 %d', (int) ( $c['product'] ?? 0 ), (int) ( $c['attachment'] ?? 0 ), (int) ( $c['page'] ?? 0 ), (int) ( $c['pc_layout'] ?? 0 ) ) : '';
				echo '<tr><td>' . esc_html( wp_date( 'm-d H:i', (int) $j['at'] ) ) . '</td><td><strong>' . esc_html( self::name( $stores, (int) $j['source_site_id'] ) ) . '</strong> → <strong>' . esc_html( self::name( $stores, (int) $j['target_site_id'] ) ) . '</strong></td><td>' . esc_html( $parts ) . '</td>';
				echo '<td><span class="pcp-state ' . esc_attr( $s2[1] ) . '"><i></i>' . esc_html( $s2[0] ) . '</span>' . ( '' !== $sum ? '<small>' . esc_html( $sum ) . '</small>' : '' ) . ( '' !== (string) $j['message'] ? '<small>' . esc_html( $j['message'] . ( '' !== (string) $j['error_code'] ? '（' . $j['error_code'] . '）' : '' ) ) . '</small>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}
		echo '</div>';
	}
}
