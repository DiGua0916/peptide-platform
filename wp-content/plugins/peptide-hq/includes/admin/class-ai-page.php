<?php
/**
 * 总后台 → AI 设置（2026-11-10）：连接 AI 服务、人设库（粘贴 / 上传导入）、每家店开关和人设。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Ai;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Ai_Page {

	public const SLUG = 'pchq-ai';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', 'AI 设置', 'AI 设置', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			23
		);
		foreach ( array( 'save', 'test', 'persona', 'delete' ) as $do ) {
			add_action( 'admin_post_pchq_ai_' . $do, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) );
		wp_add_inline_style( 'pchq-plugins', '.pcai-table{width:100%;border-collapse:collapse}.pcai-table th,.pcai-table td{text-align:left;padding:10px 12px;border-top:1px solid #eee;vertical-align:middle}.pcai-table th{font-weight:600;color:#555;background:#fafafa}.pcai-persona textarea{width:100%;min-height:260px;font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5}.pcai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 20px}.pcai-grid label{display:grid;gap:4px}.pcai-grid input,.pcai-grid select{width:100%}.pcai-muted{color:#777;font-size:12px}.pcai-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pcai-tag{display:inline-block;padding:1px 8px;border-radius:999px;background:#eef4ff;color:#2457c5;font-size:12px}.pcai-tag.is-biz{background:#fff4e5;color:#b45309}' );
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_ai_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function back( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理 AI 设置。', '', array( 'response' => 403 ) );
		}
		$do = substr( (string) current_action(), strlen( 'admin_post_pchq_ai_' ) );
		check_admin_referer( 'pchq_ai_' . $do );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 各字段在 Ai 里逐个清理。
		if ( 'save' === $do ) {
			Ai::save_settings( $post );
			self::flash( 'success', '已保存。' );
		} elseif ( 'test' === $do ) {
			$r = Ai::complete( '你是连接测试助手。', '请只回复：连接成功' );
			self::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? '测试失败：' . $r->get_error_message() : 'AI 已连通（' . $r['model'] . '）：' . mb_substr( $r['text'], 0, 80 ) );
		} elseif ( 'persona' === $do ) {
			$in = array( 'id' => $post['id'] ?? '', 'name' => $post['name'] ?? '', 'use' => $post['use'] ?? '', 'content' => $post['content'] ?? '' );
			// 上传文件导入：.txt / .md，最大 200KB；没填名字就用文件名。
			$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $file ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, array( 'txt', 'md', 'markdown' ), true ) || (int) $file['size'] > 200 * KB_IN_BYTES || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
					self::flash( 'error', '只能导入 200KB 以内的 .txt 或 .md 文件。' );
					self::back();
				}
				$in['content'] = (string) file_get_contents( (string) $file['tmp_name'] );
				if ( '' === trim( (string) $in['name'] ) ) {
					$in['name'] = pathinfo( (string) $file['name'], PATHINFO_FILENAME );
				}
			}
			$r = Ai::save_persona( $in );
			self::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : '人设「' . $r['name'] . '」已保存。' );
		} elseif ( 'delete' === $do ) {
			Ai::delete_persona( sanitize_key( (string) ( $post['id'] ?? '' ) ) );
			self::flash( 'success', '人设已删除。' );
		}
		self::back();
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$s        = Ai::settings();
		$personas = Ai::personas();
		$edit     = isset( $_GET['edit'] ) ? Ai::persona( sanitize_key( wp_unslash( $_GET['edit'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flash    = get_transient( 'pchq_ai_flash_' . get_current_user_id() );
		delete_transient( 'pchq_ai_flash_' . get_current_user_id() );
		$post     = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>AI 设置</h1><p>所有店铺的 AI 分析都经过这里：密钥只存在总后台，店铺只把访客的行为数据（不含姓名、邮箱、电话）发过来，按这里选的人设分析后把结果返回店铺后台。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}

		// 1. 连接
		$ok = $s['enabled'] && $s['has_key'] && '' !== $s['model'];
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>AI 服务</h2>' . ( $ok ? '<span class="pcp-state is-ok"><i></i>已开启</span>' : '<span class="pcp-state is-bad"><i></i>未开启</span>' ) . '</div>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_save' );
		echo '<input type="hidden" name="action" value="pchq_ai_save">';
		echo '<p><label class="pcp-check"><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 开启 AI 分析</label></p>';
		echo '<div class="pcai-grid">';
		echo '<label><span>服务商</span><select name="provider">';
		foreach ( Ai::PROVIDERS as $k => $p ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['provider'], $k, false ) . '>' . esc_html( $p['label'] ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span>模型</span><input type="text" name="model" value="' . esc_attr( $s['model'] ) . '" placeholder="例如 claude-sonnet-5-5"></label>';
		echo '<label><span>API 密钥</span><input type="password" name="key" value="" autocomplete="new-password" placeholder="' . ( $s['has_key'] ? '已保存 ••••••（留空不改）' : '粘贴 API 密钥' ) . '"></label>';
		echo '<label><span>接口地址（一般不用改）</span><input type="url" name="endpoint" value="' . esc_attr( $s['endpoint'] ) . '"></label>';
		echo '<label><span>回答语言</span><input type="text" name="language" value="' . esc_attr( $s['language'] ) . '"></label>';
		echo '<label><span>每家店每天最多分析几次</span><input type="number" name="daily_cap" min="1" max="5000" value="' . (int) $s['daily_cap'] . '"></label>';
		echo '<label><span>单次回答最长（tokens）</span><input type="number" name="max_tokens" min="200" max="8000" value="' . (int) $s['max_tokens'] . '"></label>';
		echo '</div>';
		if ( $s['has_key'] ) {
			echo '<p><label class="pcp-check"><input type="checkbox" name="clear_key" value="1"> 删除已保存的密钥</label></p>';
		}

		// 3. 店铺（同一个表单一起保存）
		echo '<h3 style="margin-top:24px">各店铺</h3><p class="pcai-muted">新接入的店铺默认开启，用每种用途的第一个人设。</p>';
		echo '<div style="overflow-x:auto"><table class="pcai-table"><thead><tr><th>店铺</th><th>开启</th><th>客户分析人设</th><th>全站经营分析人设</th><th>今天已用</th></tr></thead><tbody>';
		foreach ( Sites::all() as $site ) {
			$id  = (int) $site['id'];
			$row = $s['sites'][ $id ] ?? null;
			$on  = null === $row || ! empty( $row['enabled'] );
			echo '<tr><td><strong>' . esc_html( $site['name'] ) . '</strong><br><span class="pcai-muted">' . esc_html( (string) wp_parse_url( $site['public_url'], PHP_URL_HOST ) ) . '</span></td>';
			echo '<td><input type="hidden" name="sites[' . $id . '][present]" value="1"><input type="checkbox" name="sites[' . $id . '][enabled]" value="1"' . checked( $on, true, false ) . ' aria-label="开启"></td>';
			foreach ( array_keys( Ai::USES ) as $use ) {
				echo '<td><select name="sites[' . $id . '][' . $use . ']"><option value="">默认（第一个）</option>';
				foreach ( $personas as $p ) {
					if ( $p['use'] === $use ) {
						echo '<option value="' . esc_attr( $p['id'] ) . '"' . selected( (string) ( $row[ $use ] ?? '' ), $p['id'], false ) . '>' . esc_html( $p['name'] ) . '</option>';
					}
				}
				echo '</select></td>';
			}
			echo '<td>' . (int) Ai::used_today( $id ) . ' / ' . (int) $s['daily_cap'] . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="pcai-actions"><button type="submit" class="button button-primary">保存</button></p></form>';
		echo '<form method="post" action="' . $post . '" class="pcai-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_test' );
		echo '<input type="hidden" name="action" value="pchq_ai_test"><button type="submit" class="button"' . disabled( $s['has_key'], false, false ) . '>测试连接</button><span class="pcai-muted">会用保存的密钥发一条很短的测试消息。</span></form></section>';

		// 2. 人设库
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>人设库</h2><span class="pcp-hint">' . count( $personas ) . ' 个人设</span></div>';
		echo '<p class="pcai-muted">人设正文就是 AI 的系统提示词。“客户分析”用在访客详情页分析单个客户；“全站经营分析”用在实时访客页分析整体情况。</p>';
		if ( $personas ) {
			echo '<table class="pcai-table"><thead><tr><th>名字</th><th>用途</th><th>长度</th><th>更新时间</th><th>操作</th></tr></thead><tbody>';
			foreach ( $personas as $p ) {
				echo '<tr><td><strong>' . esc_html( $p['name'] ) . '</strong></td><td><span class="pcai-tag' . ( 'business' === $p['use'] ? ' is-biz' : '' ) . '">' . esc_html( Ai::USES[ $p['use'] ] ?? $p['use'] ) . '</span></td><td>' . (int) mb_strlen( $p['content'] ) . ' 字</td><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $p['updated'] ) ) . '</td><td class="pcai-actions">';
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'edit', $p['id'], admin_url( 'admin.php?page=' . self::SLUG ) ) ) . '#pcai-persona">编辑</a>';
				echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				wp_nonce_field( 'pchq_ai_delete' );
				echo '<input type="hidden" name="action" value="pchq_ai_delete"><input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '"><button type="submit" class="button button-small">删除</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>还没有人设。用下面的表单导入你的人设母版：可以直接粘贴正文，也可以上传 .txt / .md 文件。</p>';
		}

		echo '<h3 id="pcai-persona" style="margin-top:24px">' . ( $edit ? '编辑人设：' . esc_html( $edit['name'] ) : '导入 / 新建人设' ) . '</h3>';
		echo '<form method="post" action="' . $post . '" enctype="multipart/form-data" class="pcai-persona">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_persona' );
		echo '<input type="hidden" name="action" value="pchq_ai_persona"><input type="hidden" name="id" value="' . esc_attr( $edit['id'] ?? '' ) . '">';
		echo '<div class="pcai-grid"><label><span>名字</span><input type="text" name="name" maxlength="60" value="' . esc_attr( $edit['name'] ?? '' ) . '" placeholder="例如：客户分析师"></label>';
		echo '<label><span>用途</span><select name="use">';
		foreach ( Ai::USES as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $edit['use'] ?? 'customer', $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span>从文件导入（.txt / .md，可选）</span><input type="file" name="file" accept=".txt,.md,.markdown,text/plain,text/markdown"></label></div>';
		echo '<p><label><span>人设正文</span><textarea name="content" placeholder="把人设母版粘贴到这里；上传了文件就以文件内容为准。">' . esc_textarea( $edit['content'] ?? '' ) . '</textarea></label></p>';
		echo '<p class="pcai-actions"><button type="submit" class="button button-primary">' . ( $edit ? '保存修改' : '导入人设' ) . '</button>' . ( $edit ? '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">取消</a>' : '' ) . '</p></form></section>';
		echo '</div>';
	}
}
