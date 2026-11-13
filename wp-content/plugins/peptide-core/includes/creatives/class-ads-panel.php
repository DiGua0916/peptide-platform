<?php
/**
 * “广告设置”页上的素材部分（2026-11-12）：上传素材 → 得到这个素材的专属投放链接；我的素材链接列表。
 *
 * 一个素材一条链接：落地页 + cr=素材编号 + Meta 动态参数模板（Contract::creative_query）。
 * 这个素材不管投到多少广告系列，都用同一条链接；Meta 投放时把 {{...}} 换成平台、版位、广告系列 / 广告组 / 广告 ID。
 * 换落地页只是换链接前半段，素材编号不变，数据仍算在同一个素材下。
 *
 * 图文素材是主入口（默认选中）；视频素材传封面图，视频文件可选。
 * 保存走素材库同一个 admin-post 动作（Library_Page::handle，字段 from=ads）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Admin\Onboarding;
use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Ads_Panel {

	public static function page_url(): string {
		return Staff_Buyer::page_url( Staff_Buyer::PAGE_ADS );
	}

	/** 某个素材在某个落地页的投放链接。 */
	public static function link( string $landing, string $code ): string {
		return Onboarding::ad_link( $landing, '', '', $code );
	}

	/** 每个素材选的落地页（2026-11-13 店主：上传时就选落地页）：选项 {编号: 地址}，没选 = 首页。 */
	public const LANDINGS = 'pc_cr_landings';

	/** 这个素材的落地页地址（存的地址已不在可选列表里时退回首页）。 */
	public static function landing_of( string $code, ?array $pages = null ): string {
		$saved = (array) get_option( self::LANDINGS, array() );
		$url   = (string) ( $saved[ $code ] ?? '' );
		return '' !== $url && null !== self::landing_label( $url, $pages ) ? $url : home_url( '/' );
	}

	/** 落地页地址对应的名字；不在本店可选落地页里 = null。 */
	public static function landing_label( string $url, ?array $pages = null ): ?string {
		foreach ( $pages ?? Onboarding::landing_pages() as $page ) {
			if ( $page['url'] === $url ) {
				return ( '' !== $page['group'] ? $page['group'] . '：' : '' ) . $page['label'];
			}
		}
		return null;
	}

	/** 记下素材的落地页；不在可选列表里的地址不收。 */
	public static function set_landing( string $code, string $url ): bool {
		if ( null === self::landing_label( $url ) ) {
			return false;
		}
		$saved = (array) get_option( self::LANDINGS, array() );
		if ( home_url( '/' ) === $url ) {
			unset( $saved[ $code ] );
		} else {
			$saved[ $code ] = $url;
		}
		update_option( self::LANDINGS, $saved, false );
		return true;
	}

	/** 落地页下拉（首页 + 专辑页 + 商品页）。 */
	public static function landing_select( string $name, string $id, string $current, ?array $pages = null ): string {
		$h     = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		$group = '';
		foreach ( $pages ?? Onboarding::landing_pages() as $page ) {
			if ( $page['group'] !== $group ) {
				$h    .= ( '' !== $group ? '</optgroup>' : '' ) . ( '' !== $page['group'] ? '<optgroup label="' . esc_attr( $page['group'] ) . '">' : '' );
				$group = $page['group'];
			}
			$h .= '<option value="' . esc_attr( $page['url'] ) . '"' . selected( $current, $page['url'], false ) . '>' . esc_html( $page['label'] ) . '</option>';
		}
		return $h . ( '' !== $group ? '</optgroup>' : '' ) . '</select>';
	}

	// ---------- 上传 ----------

	/** $card = false：放在别的卡片里（开店向导第 5 步），不再套一层卡片边框。 */
	public static function render_upload( bool $card = true ): void {
		Schema::maybe_install();
		Vocab_Sync::maybe_sync();
		$vocab = Schema::vocab();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读参数。
		$from_ad = isset( $_GET['from_ad'] ) ? sanitize_text_field( wp_unslash( $_GET['from_ad'] ) ) : '';
		$from    = '' !== $from_ad ? Creatives::ad( $from_ad ) : null;
		$pre     = $from ? Contract::parse_ad_name( (string) $from['ad_name'] )['attrs'] : array();
		$is_vid  = 'vid' === ( $pre['fmt'] ?? '' );

		echo '<section class="' . ( $card ? 'pc-by-card pc-cr-upload' : 'pc-cr-upload-plain' ) . '" id="pc-cr-upload">';
		echo '<h2>上传素材，生成投放链接</h2>';
		echo '<p class="pc-by-why">一个素材一条链接。这个素材以后不管投到多少个广告系列、广告组，都粘贴同一条链接，数据会自动按素材汇总。</p>';
		if ( $from ) {
			echo '<p class="pc-cr-from">补传素材：广告 <code>' . esc_html( (string) $from['ad_id'] ) . '</code>' . ( '' !== (string) $from['ad_name'] ? '（' . esc_html( (string) $from['ad_name'] ) . '）' : '' ) . '。上传后这条广告之前的数据会归到新素材下。</p>';
		}
		Library_Page::form_open( 'create', true );
		echo '<input type="hidden" name="from" value="ads">';
		if ( $from ) {
			echo '<input type="hidden" name="ad_id" value="' . esc_attr( (string) $from['ad_id'] ) . '">';
		}

		// 素材类型：图文为主。
		echo '<div class="pc-cr-kind" role="radiogroup" aria-label="素材类型">';
		echo '<label class="pc-cr-kind-opt"><input type="radio" name="kind" value="img"' . checked( ! $is_vid, true, false ) . ' data-pc-cr-kind> <span><strong>图文素材</strong><em>单图、轮播图</em></span></label>';
		echo '<label class="pc-cr-kind-opt is-minor"><input type="radio" name="kind" value="vid"' . checked( $is_vid, true, false ) . ' data-pc-cr-kind> <span><strong>视频素材</strong><em>传封面图，视频文件可选</em></span></label>';
		echo '</div>';

		echo '<div class="pc-cr-upload-grid">';
		echo '<label class="pc-cr-drop" for="pc-cr-up-thumb"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><strong data-pc-cr-thumb-label>' . esc_html( $is_vid ? '选择封面图' : '选择素材图片' ) . '</strong><span data-pc-cr-file-name>JPG、PNG、GIF、WebP</span>';
		echo '<input id="pc-cr-up-thumb" type="file" name="thumb" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" required data-pc-cr-file></label>';

		echo '<div class="pc-cr-upload-fields">';
		$by = array();
		foreach ( $vocab as $item ) {
			$by[ $item['attr'] ][] = $item;
		}
		echo '<div class="pc-cr-attr-row">';
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			$req = 'lang' !== $k;
			$cur = (string) ( $pre[ $k ] ?? ( 'fmt' === $k ? ( $is_vid ? 'vid' : 'img' ) : '' ) );
			echo '<p><label for="pc-cr-up-' . esc_attr( $k ) . '">' . esc_html( Creatives::attr_name( $k ) ) . ( $req ? ' <span class="pc-cr-req">*</span>' : '' ) . '</label>';
			echo '<select id="pc-cr-up-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '"' . ( $req ? ' required' : '' ) . ( 'fmt' === $k ? ' data-pc-cr-fmt' : '' ) . '><option value="">' . esc_html( $req ? '请选择' : '不填' ) . '</option>';
			foreach ( $by[ $k ] ?? array() as $item ) {
				echo '<option value="' . esc_attr( $item['value'] ) . '"' . selected( $cur, $item['value'], false ) . '>' . esc_html( $item['label'] ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '</div>';
		echo '<p class="pc-cr-video-row" data-pc-cr-video' . ( $is_vid ? '' : ' hidden' ) . '><label for="pc-cr-up-video">视频文件（可选）</label><input id="pc-cr-up-video" type="file" name="video" accept=".mp4,.mov,.m4v,.webm,video/*"><span class="pc-by-hint">MP4、MOV、M4V、WebM，最大 ' . esc_html( Library_Page::max_upload() ) . '。不传也可以，数据照样统计。</span></p>';
		echo '<p class="pc-cr-landing-row"><label for="pc-cr-up-landing">落地页</label>' . self::landing_select( 'landing', 'pc-cr-up-landing', home_url( '/' ) ) . '<span class="pc-by-hint">客户点广告后进到哪里。选专辑页，客户一进来就停在首页产品区并选好这个分类；选商品页，直接打开这个商品。以后在下面的链接里也能换。</span></p>';
		echo '<p><label for="pc-cr-up-note">备注（可选）</label><input type="text" id="pc-cr-up-note" name="note" maxlength="500" placeholder="例如：黑五主图，蓝底"></p>';
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary button-hero">上传并生成链接</button></p>';
		echo '</div></div></form></section>';
	}

	// ---------- 我的素材链接 ----------

	public static function render_links( bool $card = true ): void {
		$all     = array_filter( Creatives::all(), static fn( array $c ): bool => Creatives::STATUS_ARCHIVED !== $c['status'] );
		$stats   = Creatives::stats();
		$labels  = Creatives::label_map( Schema::vocab() );
		$today   = wp_date( 'Y-m-d' );
		$pages   = Onboarding::landing_pages();
		$unreg   = Creatives::unregistered_ads();
		$library = admin_url( 'admin.php?page=' . Module::PAGE_LIBRARY );

		echo '<section class="' . ( $card ? 'pc-by-card ' : 'pc-cr-links-plain ' ) . 'pc-cr-links" id="pc-cr-links">';
		echo '<h2>我的素材链接（' . count( $all ) . '）</h2>';
		if ( $unreg ) {
			echo '<p class="pc-cr-unbound">有 ' . count( $unreg ) . ' 条广告没用素材链接，数据算“未登记”。<a href="' . esc_url( $library ) . '">去素材库归位</a></p>';
		}
		if ( ! $all ) {
			echo '<p class="pc-by-hint">还没有素材。在上面上传第一个素材，就会在这里得到它的投放链接。</p></section>';
			return;
		}
		echo '<p class="pc-by-hint">复制链接，粘到 Facebook 广告的“网址”一栏。同一个素材的所有广告都用这一条。换落地页后要重新复制，粘到 Meta 里替换旧链接。</p>';
		echo '<ul class="pc-cr-link-list">';
		$badge = Creatives::STATE_LABELS;
		foreach ( $all as $c ) {
			$st    = $stats[ $c['code'] ] ?? array( 'spend_cents' => 0, 'last_day' => '', 'visitors' => 0 );
			$state = Creatives::display_state( $st, $today );
			$land  = self::landing_of( $c['code'], $pages );
			$link  = self::link( $land, $c['code'] );
			$video = $c['video_id'] > 0 || 'vid' === ( $c['attrs']['fmt'] ?? '' );
			$tags  = array();
			foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
				if ( isset( $c['attrs'][ $k ] ) ) {
					$tags[] = $labels[ $k ][ $c['attrs'][ $k ] ] ?? $c['attrs'][ $k ];
				}
			}
			echo '<li class="pc-cr-link-row" id="pc-cr-link-' . esc_attr( strtolower( $c['code'] ) ) . '">';
			echo '<div class="pc-cr-link-thumb">' . ( $c['attachment_id'] ? wp_get_attachment_image( $c['attachment_id'], 'thumbnail' ) : '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>' ) . ( $video ? '<span class="pc-cr-vid-mark" title="视频素材"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span></span>' : '' ) . '</div>';
			echo '<div class="pc-cr-link-main"><p class="pc-cr-link-head"><strong>' . esc_html( $c['code'] ) . '</strong> <span>' . esc_html( implode( ' · ', $tags ) ) . '</span>';
			echo ' <span class="pc-cr-badge is-' . esc_attr( $state ) . '">' . esc_html( $badge[ $state ] ?? $state ) . '</span>';
			echo ' <span class="pc-cr-nums">进站访客 ' . (int) $st['visitors'] . ' · 花费 ' . esc_html( Creatives::money( (int) $st['spend_cents'] ) ) . '</span></p>';
			echo '<div class="pc-cr-landing-line"><span>落地页：<b>' . esc_html( (string) self::landing_label( $land, $pages ) ) . '</b></span>';
			echo '<details class="pc-cr-landing-change"><summary class="button button-small">换落地页</summary>';
			Library_Page::form_open( 'landing' );
			echo '<input type="hidden" name="code" value="' . esc_attr( $c['code'] ) . '">' . self::landing_select( 'landing', 'pc-cr-landing-' . strtolower( $c['code'] ), $land, $pages );
			echo ' <button type="submit" class="button button-primary button-small">保存</button></form></details></div>';
			echo '<div class="pc-cr-copyrow"><input type="text" readonly value="' . esc_attr( $link ) . '" aria-label="' . esc_attr( $c['code'] . ' 的投放链接' ) . '" data-pc-cr-link-out>';
			echo '<button type="button" class="button button-primary" data-pc-cr-copy>复制链接</button>';
			Library_Page::delete_button( $c['code'], $st );
			echo '</div></div></li>';
		}
		echo '</ul>';
		echo '</section>';
	}

	// ---------- 样式和脚本 ----------

	public static function assets(): void {
		?>
<style>
.pc-cr-upload{border:2px solid #2271b1}
.pc-cr-links-plain{margin-top:24px;padding-top:16px;border-top:1px solid #dcdcde}
.pc-cr-from{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px}
.pc-cr-kind{display:flex;gap:10px;margin:12px 0 16px;flex-wrap:wrap}
.pc-cr-kind-opt{display:flex;align-items:center;gap:8px;border:1px solid #c3c4c7;border-radius:6px;padding:10px 14px;cursor:pointer;min-width:200px;background:#fff}
.pc-cr-kind-opt span{display:flex;flex-direction:column}
.pc-cr-kind-opt em{font-style:normal;color:#646970;font-size:12px}
.pc-cr-kind-opt:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f0f6fc}
.pc-cr-kind-opt.is-minor{min-width:170px}
.pc-cr-kind-opt.is-minor strong{font-weight:500}
.pc-cr-upload-grid{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px;align-items:start}
.pc-cr-drop{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;aspect-ratio:1/1;border:2px dashed #8c8f94;border-radius:8px;background:#f6f7f7;cursor:pointer;padding:10px;overflow:hidden}
.pc-cr-drop:hover{border-color:#2271b1;background:#f0f6fc}
.pc-cr-drop .dashicons{font-size:36px;width:36px;height:36px;color:#2271b1}
.pc-cr-drop span[data-pc-cr-file-name]{font-size:12px;color:#646970;word-break:break-all}
.pc-cr-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.pc-cr-drop img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.pc-cr-attr-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px 12px}
.pc-cr-attr-row select{width:100%}
.pc-cr-upload-fields label{display:block;font-weight:600;margin-bottom:2px}
.pc-cr-upload-fields input[type=text]{width:100%;max-width:520px}
.pc-cr-req{color:#d63638}
.pc-cr-unbound{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px}
.pc-cr-link-list{margin:8px 0 0;padding:0;list-style:none}
.pc-cr-link-row{display:grid;grid-template-columns:64px minmax(0,1fr);gap:12px;align-items:center;border:1px solid #dcdcde;border-radius:6px;padding:8px;margin:0 0 8px;background:#fff}
.pc-cr-link-row:target{border-color:#2271b1;box-shadow:0 0 0 2px #72aee6}
.pc-cr-link-thumb{position:relative;width:64px;height:64px;border-radius:4px;background:#f0f0f1;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#8c8f94}
.pc-cr-link-thumb img{width:100%;height:100%;object-fit:cover}
.pc-cr-vid-mark{position:absolute;right:2px;bottom:2px;background:rgba(0,0,0,.6);color:#fff;border-radius:3px;line-height:1}
.pc-cr-link-head{margin:0 0 6px}
.pc-cr-link-head strong{font-family:monospace;font-size:14px;margin-right:6px}
.pc-cr-link-head span{color:#50575e;font-size:12px}
.pc-cr-badge{display:inline-block;white-space:nowrap;border-radius:10px;padding:1px 8px;background:#f0f0f1}
.pc-cr-badge.is-live{background:#edfaef;color:#007017!important}
.pc-cr-badge.is-pending{background:#fcf9e8;color:#8a6d00!important}
.pc-cr-badge.is-new{background:#f0f6fc;color:#2271b1!important}
.pc-cr-nums{margin-left:6px}
.pc-cr-copyrow{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pc-cr-copyrow form{margin:0}
.pc-cr-del:not([disabled]){color:#b32d2e!important;border-color:#b32d2e!important}
.pc-cr-copyrow input{flex:1 1 320px;min-width:0;font-family:monospace;font-size:12px}
.pc-cr-landing-row select{width:100%;max-width:520px}
.pc-cr-landing-row .pc-by-hint{display:block;margin-top:2px}
.pc-cr-landing-line{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 6px;font-size:13px}
.pc-cr-landing-change summary{list-style:none;cursor:pointer}
.pc-cr-landing-change summary::-webkit-details-marker{display:none}
.pc-cr-landing-change[open] summary{margin-bottom:6px}
.pc-cr-landing-change form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pc-cr-landing-change select{max-width:280px}
@media (max-width:782px){.pc-cr-upload-grid{grid-template-columns:1fr}.pc-cr-drop{max-width:220px}.pc-cr-attr-row{grid-template-columns:repeat(2,minmax(0,1fr))}
.pc-cr-link-row{grid-template-columns:48px minmax(0,1fr);align-items:start}.pc-cr-link-thumb{width:48px;height:48px}
.pc-cr-copyrow input{flex:1 1 100%}.pc-cr-landing-change select{flex:1 1 0;min-width:0;max-width:none}.pc-cr-nums{display:block;margin:2px 0 0}}
</style>
<script>
( function () {
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-pc-cr-kind]' ) ) {
			var vid = 'vid' === e.target.value, form = e.target.form;
			form.querySelector( '[data-pc-cr-video]' ).hidden = ! vid;
			form.querySelector( '[data-pc-cr-thumb-label]' ).textContent = vid ? '选择封面图' : '选择素材图片';
			var fmt = form.querySelector( '[data-pc-cr-fmt]' );
			if ( fmt && ( '' === fmt.value || 'vid' === fmt.value || 'img' === fmt.value ) ) { fmt.value = vid ? 'vid' : 'img'; }
			return;
		}
		if ( e.target.matches( '[data-pc-cr-file]' ) ) {
			var drop = e.target.closest( '.pc-cr-drop' ), file = e.target.files && e.target.files[0];
			drop.querySelector( '[data-pc-cr-file-name]' ).textContent = file ? file.name : 'JPG、PNG、GIF、WebP';
			var old = drop.querySelector( 'img' );
			if ( old ) { old.remove(); }
			if ( file && window.URL && /^image\//.test( file.type ) ) {
				var img = document.createElement( 'img' );
				img.alt = '';
				img.src = URL.createObjectURL( file );
				drop.insertBefore( img, e.target );
			}
		}
	} );
	document.addEventListener( 'submit', function ( e ) {
		var d = e.target.querySelector( '[data-pc-cr-del]' );
		if ( d && e.target.closest( '.pc-cr-links' ) && ! window.confirm( '删除 ' + d.getAttribute( 'data-pc-cr-del' ) + '？删除后不能恢复，这个编号也不会再用。图片留在媒体库。' ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-pc-cr-copy]' );
		if ( ! b || ! b.closest( '.pc-cr-links' ) ) { return; }
		var input = b.parentNode.querySelector( '[data-pc-cr-link-out]' ), label = b.textContent;
		var ok = function () { b.textContent = '已复制'; setTimeout( function () { b.textContent = label; }, 1500 ); };
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( ok, function () { input.select(); } );
		} else {
			input.select();
			try { document.execCommand( 'copy' ); ok(); } catch ( err ) {}
		}
	} );
} )();
</script>
		<?php
	}
}
