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

	// ---------- 上传 ----------

	public static function render_upload(): void {
		Schema::maybe_install();
		Vocab_Sync::maybe_sync();
		$vocab = Schema::vocab();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读参数。
		$from_ad = isset( $_GET['from_ad'] ) ? sanitize_text_field( wp_unslash( $_GET['from_ad'] ) ) : '';
		$from    = '' !== $from_ad ? Creatives::ad( $from_ad ) : null;
		$pre     = $from ? Contract::parse_ad_name( (string) $from['ad_name'] )['attrs'] : array();
		$is_vid  = 'vid' === ( $pre['fmt'] ?? '' );

		echo '<section class="pc-by-card pc-cr-upload" id="pc-cr-upload">';
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
		echo '<p><label for="pc-cr-up-note">备注（可选）</label><input type="text" id="pc-cr-up-note" name="note" maxlength="500" placeholder="例如：黑五主图，蓝底"></p>';
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary button-hero">上传并生成链接</button></p>';
		echo '</div></div></form></section>';
	}

	// ---------- 我的素材链接 ----------

	public static function render_links(): void {
		$all     = array_filter( Creatives::all(), static fn( array $c ): bool => Creatives::STATUS_ARCHIVED !== $c['status'] );
		$stats   = Creatives::stats();
		$labels  = Creatives::label_map( Schema::vocab() );
		$today   = wp_date( 'Y-m-d' );
		$home    = home_url( '/' );
		$unreg   = Creatives::unregistered_ads();
		$library = admin_url( 'admin.php?page=' . Module::PAGE_LIBRARY );

		echo '<section class="pc-by-card pc-cr-links" id="pc-cr-links">';
		echo '<h2>我的素材链接（' . count( $all ) . '）</h2>';
		if ( $unreg ) {
			echo '<p class="pc-cr-unbound">有 ' . count( $unreg ) . ' 条广告没用素材链接，数据算“未登记”。<a href="' . esc_url( $library ) . '">去素材库归位</a></p>';
		}
		if ( ! $all ) {
			echo '<p class="pc-by-hint">还没有素材。在上面上传第一个素材，就会在这里得到它的投放链接。</p></section>';
			return;
		}
		echo '<p class="pc-by-hint">复制链接，粘到 Facebook 广告的“网址”一栏。同一个素材的所有广告都用这一条；要换落地页，先在右边选落地页再复制。</p>';
		echo '<ul class="pc-cr-link-list">';
		$badge = Creatives::STATE_LABELS;
		foreach ( $all as $c ) {
			$st    = $stats[ $c['code'] ] ?? array( 'spend_cents' => 0, 'last_day' => '', 'visitors' => 0 );
			$state = Creatives::display_state( $st, $today );
			$link  = self::link( $home, $c['code'] );
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
			echo '<div class="pc-cr-copyrow"><input type="text" readonly value="' . esc_attr( $link ) . '" aria-label="' . esc_attr( $c['code'] . ' 的投放链接' ) . '" data-pc-cr-link-out>';
			echo '<select aria-label="落地页" data-pc-cr-landing data-query="' . esc_attr( Contract::creative_query( $c['code'] ) ) . '"><option value="' . esc_attr( $home ) . '">落地页：首页</option></select>';
			echo '<button type="button" class="button button-primary" data-pc-cr-copy>复制链接</button></div>';
			echo '</div></li>';
		}
		echo '</ul>';
		// 落地页选项只输出一份，脚本复制到每一行（没有脚本时只有首页）。
		echo '<template data-pc-cr-landings>';
		$group = '';
		foreach ( Onboarding::landing_pages() as $page ) {
			if ( $page['url'] === $home ) {
				continue;
			}
			if ( $page['group'] !== $group ) {
				echo ( '' !== $group ? '</optgroup>' : '' ) . '<optgroup label="' . esc_attr( $page['group'] ) . '">';
				$group = $page['group'];
			}
			echo '<option value="' . esc_attr( $page['url'] ) . '">' . esc_html( $page['label'] ) . '</option>';
		}
		echo ( '' !== $group ? '</optgroup>' : '' ) . '</template>';
		echo '</section>';
	}

	// ---------- 样式和脚本 ----------

	public static function assets(): void {
		?>
<style>
.pc-cr-upload{border:2px solid #2271b1}
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
.pc-cr-badge{border-radius:10px;padding:1px 8px;background:#f0f0f1}
.pc-cr-badge.is-live{background:#edfaef;color:#007017!important}
.pc-cr-badge.is-pending{background:#fcf9e8;color:#8a6d00!important}
.pc-cr-badge.is-new{background:#f0f6fc;color:#2271b1!important}
.pc-cr-nums{margin-left:6px}
.pc-cr-copyrow{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pc-cr-copyrow input{flex:1 1 320px;min-width:0;font-family:monospace;font-size:12px}
.pc-cr-copyrow select{max-width:220px}
@media (max-width:782px){.pc-cr-upload-grid{grid-template-columns:1fr}.pc-cr-drop{max-width:220px}.pc-cr-attr-row{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<script>
( function () {
	var tpl = document.querySelector( '[data-pc-cr-landings]' );
	document.querySelectorAll( '[data-pc-cr-landing]' ).forEach( function ( sel ) {
		if ( tpl ) { sel.appendChild( tpl.content.cloneNode( true ) ); }
	} );
	function build( landing, query ) {
		var base = String( landing ).split( '#' )[0];
		return base + ( base.indexOf( '?' ) >= 0 ? '&' : '?' ) + query;
	}
	document.addEventListener( 'change', function ( e ) {
		var sel = e.target.closest( '[data-pc-cr-landing]' );
		if ( sel ) {
			sel.parentNode.querySelector( '[data-pc-cr-link-out]' ).value = build( sel.value, sel.getAttribute( 'data-query' ) );
			return;
		}
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
