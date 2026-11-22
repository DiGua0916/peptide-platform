<?php
/**
 * 总后台 → Ad Brain 接口（2026-11-22）。给每家店一键生成 / 重新生成 / 关闭接口密钥，并给出交给 Ad Brain 的整份配置。
 * 投手后台不显示任何设置。实际下发见 PeptideHq\Adbrain_Keys。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Adbrain_Keys;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Adbrain_Page {

	public const SLUG = 'pchq-adbrain';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', 'Ad Brain 接口', 'Ad Brain 接口', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			23
		);
		foreach ( array( 'issue', 'revoke', 'issue_all' ) as $do ) {
			add_action( 'admin_post_pchq_adbrain_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script(
			'pchq-plugins',
			"document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-pchq-copy]');if(!b)return;var t=document.getElementById(b.getAttribute('data-pchq-copy'));if(!t)return;var v=t.value!==undefined?t.value:t.textContent;(navigator.clipboard?navigator.clipboard.writeText(v):Promise.reject()).then(function(){b.textContent='已复制';},function(){if(t.select){t.hidden=false;t.select();}b.textContent='请按 Ctrl+C 复制';});});"
		);
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_adbrain_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function name( array $site ): string {
		return '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理 Ad Brain 接口。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_adbrain_' ) );
		check_admin_referer( 'pchq_adbrain_' . $do );
		$sites = Sites::all();
		if ( 'issue_all' === $do ) {
			$ok  = array();
			$bad = array();
			foreach ( $sites as $id => $site ) {
				if ( null !== Adbrain_Keys::record( (int) $id ) ) {
					continue; // 已有的不动，避免 Ad Brain 那边的密钥失效。
				}
				$res = Adbrain_Keys::issue( (int) $id );
				if ( is_wp_error( $res ) ) {
					$bad[] = self::name( $site ) . '：' . $res->get_error_message();
				} else {
					$ok[] = self::name( $site );
				}
			}
			$msg = $ok ? '已开启：' . implode( '、', $ok ) . '。' : '没有需要新开的店铺。';
			self::flash( $bad ? 'error' : 'success', $msg . ( $bad ? ' 没成功：' . implode( '；', $bad ) : '' ) );
		} else {
			$id   = (int) ( $_POST['site_id'] ?? 0 );
			$site = $sites[ $id ] ?? null;
			if ( ! $site ) {
				self::flash( 'error', '找不到这家店铺。' );
			} else {
				$res = 'issue' === $do ? Adbrain_Keys::issue( $id ) : Adbrain_Keys::revoke( $id );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', self::name( $site ) . '：' . $res->get_error_message() );
				} else {
					self::flash( 'success', self::name( $site ) . ( 'issue' === $do ? '：新密钥已生成并下发。旧密钥立即失效，记得把新配置交给 Ad Brain。' : '：已关闭，接口不再响应。' ) );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function button( string $do, int $site_id, string $label, string $class = 'button', string $confirm = '' ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$html .= wp_nonce_field( 'pchq_adbrain_' . $do, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="pchq_adbrain_' . esc_attr( $do ) . '">';
		if ( $site_id ) {
			$html .= '<input type="hidden" name="site_id" value="' . (int) $site_id . '">';
		}
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_adbrain_flash_' . get_current_user_id() );
		delete_transient( 'pchq_adbrain_flash_' . get_current_user_id() );
		$sites = Sites::all();
		$state_class = array( 'on' => 'is-ok', 'off' => 'is-off', 'unknown' => 'is-wait', 'constant' => 'is-wait' );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>Ad Brain 接口</h1><p>Ad Brain 通过每家店自己的接口读写素材和拉数据，每家店一把密钥。密钥只在这里生成和下发，投手后台不用做任何设置。没开启的店铺，接口一律不响应。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>一键开启</h2></div>';
		echo '<p>给所有还没开启的店铺生成密钥并下发。已经开启的不动。</p><p>' . self::button( 'issue_all', 0, '给所有店铺开启', 'button button-primary' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h2 class="pcp-section">各店铺 <span>' . count( $sites ) . '</span></h2>';
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">店铺</th><th scope="col">接口地址</th><th scope="col">状态</th><th scope="col">密钥</th><th scope="col">操作</th></tr></thead><tbody>';
		foreach ( $sites as $id => $site ) {
			$id     = (int) $id;
			$local  = Adbrain_Keys::record( $id );
			$old    = ! \PeptideHq\Client::supports( $id, Adbrain_Keys::CAPABILITY );
			$cmp    = $old ? array( 'state' => 'unknown', 'label' => '店铺程序还不支持，先发布最新版本' ) : Adbrain_Keys::compare( Adbrain_Keys::remote( $id ), $local );
			$secret = null !== $local ? Adbrain_Keys::secret( $id ) : '';
			echo '<tr><td><strong>' . esc_html( self::name( $site ) ) . '</strong></td>';
			echo '<td><code>' . esc_html( Adbrain_Keys::endpoint( $site ) ) . '</code></td>';
			echo '<td><span class="pcp-state ' . esc_attr( $state_class[ $cmp['state'] ] ?? 'is-bad' ) . '"><i></i>' . esc_html( $cmp['label'] ) . '</span>' . ( $local ? '<small>生成于 ' . esc_html( wp_date( 'Y-m-d H:i', $local['at'] ) ) . '</small>' : '' ) . '</td>';
			echo '<td>';
			if ( '' !== $secret ) {
				echo '<details><summary>显示</summary><code id="pchq-adbrain-k' . $id . '" style="word-break:break-all">' . esc_html( $secret ) . '</code></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '—';
			}
			echo '</td><td>';
			if ( ! $old ) {
				if ( null === $local && 'on' !== $cmp['state'] ) {
					echo self::button( 'issue', $id, '开启', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo self::button( 'issue', $id, '重新生成', 'button', '重新生成后旧密钥立即失效，Ad Brain 要换上新密钥才能继续用。确定？' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo ' ' . self::button( 'revoke', $id, '关闭', 'button', '关闭后 Ad Brain 立即不能访问这家店。确定？' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		$export = Adbrain_Keys::export( $sites );
		echo '<h2 class="pcp-section">交给 Ad Brain 的配置 <span>' . count( $export ) . '</span></h2>';
		if ( ! $export ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有开启的店铺</strong><p>开启后这里会出现一整份配置，复制给 Ad Brain 即可。</p></section>';
		} else {
			$json = (string) wp_json_encode( array( 'stores' => $export ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			echo '<section class="pcp-card"><p>每家店：<code>base_url</code> 是接口地址，请求头 <code>X-AdBrain-Site</code> 填 <code>site</code>，用 <code>secret</code> 签名。里面有密钥，只发给 Ad Brain 的负责人，不要贴到群里。</p>';
			echo '<details><summary>显示整份配置</summary><textarea id="pchq-adbrain-export" readonly rows="' . (int) min( 30, 3 + 6 * count( $export ) ) . '" style="width:100%;font-family:monospace">' . esc_textarea( $json ) . '</textarea></details>';
			echo '<p><button type="button" class="button button-primary" data-pchq-copy="pchq-adbrain-export">复制整份配置</button></p></section>';
		}
		echo '</div>';
	}
}
