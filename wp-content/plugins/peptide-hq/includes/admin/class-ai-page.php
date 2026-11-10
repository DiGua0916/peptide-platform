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
		foreach ( array( 'save', 'test', 'persona', 'delete', 'models', 'provider_add', 'provider_delete' ) as $do ) {
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
		wp_add_inline_style( 'pchq-plugins', '.pcai-table{width:100%;border-collapse:collapse}.pcai-table th,.pcai-table td{text-align:left;padding:10px 12px;border-top:1px solid #eee;vertical-align:middle}.pcai-table th{font-weight:600;color:#555;background:#fafafa}.pcai-persona textarea{width:100%;min-height:260px;font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5}.pcai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 20px}.pcai-grid label{display:grid;gap:4px}.pcai-grid input,.pcai-grid select{width:100%}.pcai-muted{color:#777;font-size:12px}.pcai-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pcai-tag{display:inline-block;padding:1px 8px;border-radius:999px;background:#eef4ff;color:#2457c5;font-size:12px}.pcai-tag.is-biz{background:#fff4e5;color:#b45309}.pcai-dialog{width:min(880px,94vw);max-height:88vh;overflow:auto;border:0;border-radius:12px;padding:20px 24px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25)}.pcai-dialog::backdrop{background:rgba(15,23,42,.45)}.pcai-dialog__head{display:flex;justify-content:space-between;align-items:center;margin:0 0 6px}.pcai-dialog__head h3{margin:0;font-size:18px}.pcai-dialog__close{border:0;background:none;font-size:26px;line-height:1;cursor:pointer;color:#666;padding:4px 8px;border-radius:6px}.pcai-dialog__close:hover{background:#f0f0f0;color:#111}.pcai-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none}.pcai-switch input{position:absolute;opacity:0;width:1px;height:1px}.pcai-switch i{position:relative;width:38px;height:22px;border-radius:999px;background:#c3c4c7;transition:background .15s;flex:none}.pcai-switch i::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s}.pcai-switch input:checked+i{background:#2271b1}.pcai-switch input:checked+i::after{transform:translateX(16px)}.pcai-switch input:focus-visible+i{outline:2px solid #2271b1;outline-offset:2px}.pcai-switch b{font-weight:500}.pcai-grid{align-items:start}.pcai-grid .pcai-switch{display:inline-flex}.pcp-card__head>.pcai-headrow{display:flex!important;flex-direction:row!important;align-items:center;gap:12px;flex-wrap:nowrap}.pcai-sites select{width:auto;max-width:130px;min-width:0}.pcai-sites td{vertical-align:middle;white-space:nowrap}.pcai-sp__row{display:inline-flex;align-items:center;gap:10px}.pcai-issue{margin-top:12px}.pcai-tuned{color:#15803d;font-weight:600}.pcai-btnrow{margin-top:16px}.pcai-btnrow form{margin:0}.pcai-pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f8;border:1px solid #eee;border-radius:8px;padding:12px;font-size:12px;line-height:1.6;max-height:50vh;overflow:auto;margin:0}' );
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
		if ( 'save' === $do && ! empty( $post['reissue'] ) ) {
			// 店铺表里点“重新下发”：用总后台当前的模板覆盖这家店的人设副本（店主写的调优保留）。
			// 店主 2026-11-10：统一下发——所有店铺、两种人设都用总后台当前选的模板覆盖，店主的人设调优保留。
			Ai::save_settings( $post );
			$n = 0;
			foreach ( Sites::all() as $site ) {
				foreach ( array_keys( Ai::USES ) as $use ) {
					$row  = Ai::settings()['sites'][ (int) $site['id'] ] ?? null;
					$pick = is_array( $row ) ? (string) ( $row[ $use ] ?? '' ) : '';
					$tpl  = '' !== $pick ? Ai::persona( $pick ) : null;
					$n   += Ai::issue( (int) $site['id'], $use, $tpl && $tpl['use'] === $use ? $tpl : null ) ? 1 : 0;
				}
			}
			update_option( 'pchq_ai_last_issue', time(), false );
			self::flash( 'success', '已统一下发 ' . $n . ' 份人设，各店主的人设调优保留。' );
			self::back();
		}
		if ( 'save' === $do ) {
			Ai::save_settings( $post );
			$msg = '已保存。';
			if ( Ai::settings()['has_key'] ) { // 有密钥就顺便拉一次模型列表。
				$m   = Ai::refresh_models();
				$msg = is_wp_error( $m ) ? '已保存，但' . $m->get_error_message() : '已保存，已从接口拉取 ' . count( $m ) . ' 个模型，当前使用：' . Ai::settings()['model'] . '。';
				if ( is_wp_error( $m ) ) {
					self::flash( 'error', $msg );
					self::back();
				}
			}
			self::flash( 'success', $msg );
		} elseif ( 'provider_add' === $do ) {
			$r = Ai::add_provider( $post );
			self::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : '已添加服务商「' . $r['label'] . '」。在上面的服务商里选它，填密钥保存即可。' );
			self::back( array( 'providers' => 1 ) );
		} elseif ( 'provider_delete' === $do ) {
			Ai::delete_provider( sanitize_key( (string) ( $post['id'] ?? '' ) ) );
			self::flash( 'success', '服务商已删除。' );
			self::back( array( 'providers' => 1 ) );
		} elseif ( 'models' === $do ) {
			$m = Ai::refresh_models();
			self::flash( is_wp_error( $m ) ? 'error' : 'success', is_wp_error( $m ) ? $m->get_error_message() : '已拉取 ' . count( $m ) . ' 个模型。' );
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
		echo '<section class="pcp-card"><div class="pcp-card__head"><div class="pcai-headrow"><h2 style="margin:0;width:auto;flex:none;padding:0">AI 服务</h2><button type="button" class="button button-small" data-providers-toggle><span class="dashicons dashicons-admin-generic" style="font-size:16px;width:16px;height:16px;vertical-align:-3px"></span> 服务商设置</button></div>' . ( $ok ? '<span class="pcp-state is-ok"><i></i>已开启</span>' : '<span class="pcp-state is-bad"><i></i>未开启</span>' ) . '</div>';
		echo '<form method="post" action="' . $post . '" id="pcai-main">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_save' );
		echo '<input type="hidden" name="action" value="pchq_ai_save">';
		// 回车提交时用“保存”，不能落到店铺表里的“重新下发”上。
		echo '<button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">保存</button>';
		echo '<p><label class="pcp-check"><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 开启 AI 分析</label></p>';
		// 自动分析（店主 2026-11-10）：不用点按钮，结果打开页面就能看到。
		echo '<div class="pcai-auto" style="display:grid;gap:6px;margin:0 0 16px;padding:12px 14px;border:1px solid #e6e6e6;border-radius:8px;background:#fafafa"><strong>自动分析</strong>';
		echo '<label class="pcp-check"><input type="checkbox" name="auto_customer" value="1"' . checked( $s['auto_customer'], true, false ) . '> 自动分析客户：加购或进了结账但没付款的客户，离开 30 分钟后自动分析（之后又回来会重新分析）</label>';
		echo '<label class="pcp-check"><input type="checkbox" name="auto_site" value="1"' . checked( $s['auto_site'], true, false ) . '> 自动全站分析：每天凌晨 1 点后分析前一天；每周一另外分析上周</label>';
		echo '<span class="pcai-muted">自动分析每 5 分钟检查一次，每次分析一位客户（或一份全站报告），每家店每小时最多 12 次；结果在访客详情和实时访客页直接显示。</span></div>';
		echo '<div class="pcai-grid">';
		echo '<label><span>服务商</span><select name="provider" data-provider>';
		foreach ( Ai::providers() as $k => $p ) {
			echo '<option value="' . esc_attr( $k ) . '" data-endpoint="' . esc_attr( $p['endpoint'] ) . '" data-key="' . ( in_array( $k, $s['key_set'], true ) ? '1' : '0' ) . '"' . selected( $s['provider'], $k, false ) . '>' . esc_html( $p['label'] ) . '</option>';
		}
		echo '</select></label>';
		// 模型：从服务商接口拉取，不用手填。
		$models = Ai::models();
		echo '<label><span>模型' . ( $models['fetched'] ? ' <em class="pcai-muted">（' . count( $models['list'] ) . ' 个，' . esc_html( wp_date( 'm-d H:i', (int) $models['fetched'] ) ) . ' 从接口拉取）</em>' : '' ) . '</span><select name="model" data-model' . disabled( ! $models['list'], true, false ) . '>';
		if ( ! $models['list'] ) {
			echo '<option value="">' . esc_html( $s['has_key'] ? '点“拉取模型列表”' : '填好 API 密钥并保存后自动拉取' ) . '</option>';
		}
		foreach ( $models['list'] as $m ) {
			echo '<option value="' . esc_attr( $m['id'] ) . '"' . selected( $s['model'], $m['id'], false ) . '>' . esc_html( $m['name'] . ( $m['name'] !== $m['id'] ? ' · ' . $m['id'] : '' ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span>API 密钥</span><input type="password" name="key" value="" autocomplete="new-password" data-key-input placeholder="' . ( $s['has_key'] ? '已保存 ••••••（留空不改）' : '粘贴 API 密钥' ) . '"></label>';
		// 接口地址跟着服务商自动切换，只显示不让改；自定义服务商的地址在“服务商设置”里配。
		echo '<label><span>接口地址（随服务商自动切换）</span><input type="text" value="' . esc_attr( $s['endpoint'] ) . '" readonly data-endpoint-input style="background:#f6f7f7;color:#555"></label>';
		echo '<label><span>回答语言</span><select name="language">';
		foreach ( Ai::LANGUAGES as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['language'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span>单次回答最长（tokens）</span><input type="number" name="max_tokens" min="1000" max="32000" value="' . (int) $s['max_tokens'] . '"></label>';
		echo '</div>';
		if ( $s['has_key'] ) {
			echo '<p><label class="pcp-check"><input type="checkbox" name="clear_key" value="1"> 删除已保存的密钥</label></p>';
		}

		// 3. 店铺（同一个表单一起保存）
		echo '<h3 style="margin-top:24px">各店铺</h3><p class="pcai-muted">新接入的店铺默认开启，用每种用途的第一个人设。人设和店铺模板一样，第一次只下发一份给店铺；之后每家店用自己的那份，店主在店铺后台写“人设调优”把它调成自己喜欢的样子，只影响他自己。总后台改模板不影响已下发的店，要更新就点下面的“统一下发”。</p>';
		echo '<div style="overflow-x:auto"><table class="pcai-table pcai-sites"><thead><tr><th>店铺</th><th>开启</th><th>客户分析模板</th><th>本店客户分析</th><th>全站分析师模板</th><th>本店全站分析</th><th>今天已用</th></tr></thead><tbody>';
		$copies  = Ai::site_personas();
		$dialogs = '';
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
				// 这家店自己的人设：下发时间、有没有调优，查看 / 重新下发。
				$c = $copies[ $id ][ $use ] ?? null;
				if ( is_array( $c ) ) {
					$dlg   = 'pcai-sp-' . $id . '-' . $use;
					$tuned = '' !== trim( (string) ( $c['tuning'] ?? '' ) );
					echo '<td class="pcai-sp"><span class="pcai-sp__row">' . ( $tuned ? '<b class="pcai-tuned">已调优</b>' : '<span class="pcai-muted">未调优</span>' ) . '<button type="button" class="button button-small" data-dlg="' . esc_attr( $dlg ) . '">查看</button></span></td>';
					$dialogs .= '<dialog id="' . esc_attr( $dlg ) . '" class="pcai-dialog"><div class="pcai-dialog__head"><h3>' . esc_html( $site['name'] . ' · ' . Ai::USES[ $use ] . '人设' ) . '</h3><button type="button" class="pcai-dialog__close" data-dlg-close aria-label="关闭">×</button></div>'
						. '<h4 style="margin:12px 0 6px">店主的人设调优' . ( $tuned ? '（' . esc_html( wp_date( 'm-d H:i', (int) $c['tuned_at'] ) ) . '）' : '' ) . '</h4>'
						. ( $tuned ? '<pre class="pcai-pre">' . esc_html( (string) $c['tuning'] ) . '</pre>' : '<p class="pcai-muted">店主还没写调优，用的是下发的人设原样。</p>' )
						. '<h4 style="margin:16px 0 6px">下发的人设（' . esc_html( wp_date( 'Y-m-d H:i', (int) $c['issued_at'] ) ) . ' 从模板「' . esc_html( $c['name'] ) . '」下发）</h4><pre class="pcai-pre">' . esc_html( (string) $c['content'] ) . '</pre></dialog>';
				} else {
					echo '<td class="pcai-sp"><span class="pcai-muted">未下发</span></td>';
				}
			}
			echo '<td>' . (int) Ai::used_today( $id ) . ' 次</td></tr>';
		}
		echo '</tbody></table></div>';
		$last = (int) get_option( 'pchq_ai_last_issue', 0 );
		if ( ! $last ) {
			foreach ( $copies as $per ) {
				foreach ( (array) $per as $c ) {
					$last = max( $last, (int) ( $c['issued_at'] ?? 0 ) );
				}
			}
		}
		echo '<p class="pcai-actions pcai-issue"><button type="submit" class="button" name="reissue" value="all">统一下发</button><span class="pcai-muted">' . ( $last ? '上一次下发：' . esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '还没有下发过' ) . ' · 用上面每家店选的模板覆盖所有店铺的人设，店主写的人设调优保留。</span></p>';
		echo '</form>';
		// 店主 2026-11-10：保存、测试连接、拉取模型列表并排放。
		echo '<div class="pcai-actions pcai-btnrow"><button type="submit" form="pcai-main" class="button button-primary">保存</button>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_test' );
		echo '<input type="hidden" name="action" value="pchq_ai_test"><button type="submit" class="button" title="用保存的密钥发一条很短的测试消息"' . disabled( $s['has_key'], false, false ) . '>测试连接</button></form>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_models' );
		echo '<input type="hidden" name="action" value="pchq_ai_models"><button type="submit" class="button" title="服务商新出了模型时点一下"' . disabled( $s['has_key'], false, false ) . '>拉取模型列表</button></form></div>';
		echo $dialogs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面逐项转义。
		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-dlg]");if(b){var d=document.getElementById(b.getAttribute("data-dlg"));if(d){try{d.showModal();}catch(_){d.setAttribute("open","");}}return;}if(e.target.closest("[data-dlg-close]")){var c=e.target.closest("dialog");if(c)c.close();}else if(e.target.tagName==="DIALOG"&&e.target.id.indexOf("pcai-sp-")===0){e.target.close();}});</script>';
		// 换服务商：接口地址换成那家的默认地址，模型清空（保存后自动拉取那家的模型）。
		echo '<script>(function(){var p=document.querySelector("[data-provider]"),e=document.querySelector("[data-endpoint-input]"),m=document.querySelector("[data-model]"),k=document.querySelector("[data-key-input]"),t=document.querySelector("[data-providers-toggle]");function dlg(){return document.getElementById("pcai-providers");}function openDlg(){var d=dlg();if(d&&!d.open){try{d.showModal();}catch(_){d.setAttribute("open","");}}}if(t){t.addEventListener("click",function(ev){ev.preventDefault();openDlg();});}document.addEventListener("click",function(ev){if(ev.target.closest("[data-providers-close]")){var d=dlg();if(d)d.close();}else if(ev.target.id==="pcai-providers"){ev.target.close();}});document.addEventListener("DOMContentLoaded",function(){var d=dlg();if(d&&d.getAttribute("data-open"))openDlg();var c=document.querySelector("[data-compat]");if(c){var sync=function(){document.querySelector("[data-compat-on]").hidden=!c.checked;document.querySelector("[data-compat-off]").hidden=c.checked;document.querySelector("[data-compat-label]").textContent=c.checked?"兼容接口":"原生接口（不兼容）";var h=document.querySelector("[data-endpoint-hint]");if(h)h.placeholder=c.checked?"https://api.deepseek.com/chat/completions":"https://generativelanguage.googleapis.com/v1beta";};c.addEventListener("change",sync);sync();}});if(!p||!e||!m)return;var start=p.value,html=m.innerHTML,dis=m.disabled;p.addEventListener("change",function(){var o=p.options[p.selectedIndex];e.value=o.getAttribute("data-endpoint")||"";if(k)k.placeholder=o.getAttribute("data-key")==="1"?"已保存 ••••••（留空不改）":"粘贴 API 密钥";if(p.value!==start){m.innerHTML="<option value=\\"\\">保存后自动拉取这家服务商的模型</option>";m.disabled=true;}else{m.innerHTML=html;m.disabled=dis;}});})();</script>';
		// 服务商设置：Claude、OpenAI 内置；其他的店主自己加（名字、接口类型、对话接口地址、模型列表地址）。
		$open = ! empty( $_GET['providers'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<dialog id="pcai-providers" class="pcai-dialog" aria-labelledby="pcai-providers-title"' . ( $open ? ' data-open="1"' : '' ) . '><div class="pcai-dialog__head"><h3 id="pcai-providers-title">服务商设置</h3><button type="button" class="pcai-dialog__close" data-providers-close aria-label="关闭">×</button></div><p class="pcai-muted">Claude 和 OpenAI 已经配好，不用改。要用其他服务商在这里添加：支持 OpenAI / Anthropic 格式的（例如 DeepSeek、通义千问）打开“兼容接口”；不兼容的关掉它，用原生接口（目前支持 Google Gemini）。</p>';
		echo '<table class="pcai-table"><thead><tr><th>服务商</th><th>接口类型</th><th>对话接口地址</th><th>密钥</th><th></th></tr></thead><tbody>';
		foreach ( Ai::providers() as $k => $p ) {
			echo '<tr><td><strong>' . esc_html( $p['label'] ) . '</strong>' . ( $p['builtin'] ? ' <span class="pcai-tag">内置</span>' : '' ) . '</td><td>' . esc_html( Ai::format_label( $p['format'] ) ) . '</td><td style="word-break:break-all">' . esc_html( $p['endpoint'] ) . '</td><td>' . ( in_array( $k, $s['key_set'], true ) ? '已保存' : '<span class="pcai-muted">未填</span>' ) . '</td><td>';
			if ( ! $p['builtin'] ) {
				echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				wp_nonce_field( 'pchq_ai_provider_delete' );
				echo '<input type="hidden" name="action" value="pchq_ai_provider_delete"><input type="hidden" name="id" value="' . esc_attr( $k ) . '"><button type="submit" class="button button-small">删除</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h4 style="margin:18px 0 8px">添加服务商</h4><form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_ai_provider_add' );
		echo '<input type="hidden" name="action" value="pchq_ai_provider_add"><div class="pcai-grid">';
		echo '<label><span>名字</span><input type="text" name="label" maxlength="40" placeholder="例如 DeepSeek"></label>';
		// 接口类型：开关。开 = 兼容接口（OpenAI / Anthropic 格式二选一）；关 = 原生接口（不兼容的服务商）。
		echo '<div style="display:grid;gap:6px"><span>接口类型</span><label class="pcai-switch"><input type="checkbox" name="compat" value="1" checked data-compat><i></i><b data-compat-label>兼容接口</b></label>';
		echo '<select name="format" data-compat-on>';
		foreach ( Ai::FORMATS as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select><select name="native" data-compat-off hidden>';
		foreach ( Ai::NATIVE as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		echo '<label><span>对话接口地址</span><input type="url" name="endpoint" placeholder="https://api.deepseek.com/chat/completions" data-endpoint-hint></label>';
		echo '<label><span>模型列表地址（可不填）</span><input type="url" name="models" placeholder="不填就按对话地址推算"></label>';
		echo '</div><p class="pcai-actions"><button type="submit" class="button button-primary">添加</button><button type="button" class="button" data-providers-close>关闭</button></p></form></dialog>';
		echo '</section>';

		// 2. 人设：分两块，客户分析（专门分析单个客户）和全站分析师（站在全盘视角分析全站），各自导入、各自管理。
		$intro = array(
			'customer' => array( '客户分析人设', '专门分析“人”：在访客详情页，根据一位客户的浏览、加购、结账行为，判断他在看什么、有多大可能下单、该怎么跟进。', '例如：客户分析师' ),
			'business' => array( '全站分析师人设', '站在全盘视角分析全站：在实时访客页，根据所选时间段全站的流量、转化、商品和版块数据，找出问题和机会、给出经营建议。', '例如：全站分析师' ),
		);
		foreach ( $intro as $use => [ $title, $desc, $placeholder ] ) {
			$mine = array_values( array_filter( $personas, static fn( $p ) => $p['use'] === $use ) );
			$here = $edit && $edit['use'] === $use ? $edit : null;
			echo '<section class="pcp-card" id="pcai-' . esc_attr( $use ) . '"><div class="pcp-card__head"><h2>' . esc_html( $title ) . '</h2><span class="pcp-hint">' . count( $mine ) . ' 个</span></div>';
			echo '<p class="pcai-muted">' . esc_html( $desc ) . ' 这里是模板：第一次分析时下发一份给店铺，之后店铺用自己的那份加上自己的人设调优；改这里不影响已下发的店（在上面店铺表下点“统一下发”才更新）。有多个时，各店铺默认用第一个，也可以在上面的店铺表里单独选。</p>';
			if ( $mine ) {
				echo '<table class="pcai-table"><thead><tr><th>名字</th><th>长度</th><th>更新时间</th><th>操作</th></tr></thead><tbody>';
				foreach ( $mine as $i => $p ) {
					echo '<tr><td><strong>' . esc_html( $p['name'] ) . '</strong>' . ( 0 === $i ? ' <span class="pcai-tag' . ( 'business' === $use ? ' is-biz' : '' ) . '">默认</span>' : '' ) . '</td><td>' . (int) mb_strlen( $p['content'] ) . ' 字</td><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $p['updated'] ) ) . '</td><td class="pcai-actions">';
					echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'edit', $p['id'], admin_url( 'admin.php?page=' . self::SLUG ) ) ) . '#pcai-' . esc_attr( $use ) . '">编辑</a>';
					echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					wp_nonce_field( 'pchq_ai_delete' );
					echo '<input type="hidden" name="action" value="pchq_ai_delete"><input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '"><button type="submit" class="button button-small">删除</button></form></td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>还没有' . esc_html( $title ) . '。用下面的表单导入：可以直接粘贴正文，也可以上传 .txt / .md 文件。</p>';
			}
			echo '<h3 style="margin-top:20px">' . ( $here ? '编辑：' . esc_html( $here['name'] ) : '导入' . esc_html( $title ) ) . '</h3>';
			echo '<form method="post" action="' . $post . '" enctype="multipart/form-data" class="pcai-persona">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_nonce_field( 'pchq_ai_persona' );
			echo '<input type="hidden" name="action" value="pchq_ai_persona"><input type="hidden" name="use" value="' . esc_attr( $use ) . '"><input type="hidden" name="id" value="' . esc_attr( $here['id'] ?? '' ) . '">';
			echo '<div class="pcai-grid"><label><span>名字</span><input type="text" name="name" maxlength="60" value="' . esc_attr( $here['name'] ?? '' ) . '" placeholder="' . esc_attr( $placeholder ) . '"></label>';
			echo '<label><span>从文件导入（.txt / .md，可选）</span><input type="file" name="file" accept=".txt,.md,.markdown,text/plain,text/markdown"></label></div>';
			echo '<p><label><span>人设正文</span><textarea name="content" placeholder="把人设母版粘贴到这里；上传了文件就以文件内容为准。">' . esc_textarea( $here['content'] ?? '' ) . '</textarea></label></p>';
			echo '<p class="pcai-actions"><button type="submit" class="button button-primary">' . ( $here ? '保存修改' : '导入' ) . '</button>' . ( $here ? '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) . '#pcai-' . $use ) . '">取消</a>' : '' ) . '</p></form></section>';
		}
		echo '</div>';
	}
}
