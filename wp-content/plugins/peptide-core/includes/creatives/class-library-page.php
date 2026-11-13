<?php
/**
 * 素材库页面（线 2，pc-creatives）：所有素材的汇总和数据对比（素材卡片：状态、累计花费、进站访客、投放链接），
 * 未登记广告提醒、归到已有素材。
 *
 * 2026-11-12 起，上传素材、生成投放链接都在“广告设置”页（Ads_Panel），这里不再有登记入口；
 * 保存逻辑（handle）两边共用，“补登记”跳到广告设置页的上传表单。
 *
 * 写操作都走 admin-post 动作 Module::ACTION_SAVE（字段 op：create / update / archive / restore / link / landing / delete / vocab），
 * 校验 nonce 和 Staff_Buyer::CAP_VIEW。投手的后台拦着 admin-ajax，媒体库弹窗用不了，
 * 所以缩略图用表单直接上传；有 upload_files 权限的管理员另外可以“从媒体库选”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Library_Page {

	/** 图片 / 视频封面只收这些格式。 */
	private const IMAGE_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/** 视频文件只收这些格式（大小受服务器上传上限限制）。 */
	private const VIDEO_EXT = array( 'mp4', 'mov', 'm4v', 'webm' );

	public static function register_hooks(): void {
		add_action( 'admin_post_' . Module::ACTION_SAVE, array( self::class, 'handle' ) );
		Vocab_Sync::register_hooks();
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . Module::PAGE_LIBRARY ) );
	}

	// ---------- 保存 ----------

	public static function handle(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Module::ACTION_SAVE );
		Schema::maybe_install();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 上面已校验。
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$code = isset( $_POST['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		$ad   = isset( $_POST['ad_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ad_id'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$back = wp_get_referer() ? remove_query_arg( 'from_ad', (string) wp_get_referer() ) : self::url();
		$form = array();
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			$form[ $k ] = isset( $_POST[ $k ] ) ? sanitize_key( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$picked = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$panel  = isset( $_POST['from'] ) && 'ads' === sanitize_key( wp_unslash( $_POST['from'] ) ); // 广告设置页的上传表单：必须有图。
		$landing = isset( $_POST['landing'] ) ? esc_url_raw( wp_unslash( $_POST['landing'] ) ) : ''; // 落地页（2026-11-13）
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$done = static function ( string $type, string $msg, string $to ): void {
			Staff_Buyer::flash( $type, $msg );
			wp_safe_redirect( $to );
			exit;
		};

		if ( 'vocab' === $op ) {
			$r = Vocab_Sync::sync();
			$done( 'failed' === $r['status'] ? 'error' : 'success', '' !== $r['message'] ? $r['message'] : '词表已是最新（第 ' . $r['ver'] . ' 版）。', $back );
		}
		if ( in_array( $op, array( 'archive', 'restore' ), true ) ) {
			if ( ! Creatives::get( $code ) ) {
				$done( 'error', '找不到这个素材。', $back );
			}
			Creatives::set_status( $code, 'archive' === $op ? Creatives::STATUS_ARCHIVED : Creatives::STATUS_ACTIVE );
			$done( 'success', $code . ( 'archive' === $op ? ' 已归档。' : ' 已恢复。' ), $back );
		}
		if ( 'delete' === $op ) {
			$r = Creatives::delete( $code );
			if ( is_wp_error( $r ) ) {
				$done( 'error', $r->get_error_message(), $back );
			}
			$done( 'success', $code . ' 已删除。这个编号以后不会再分给新素材。', remove_query_arg( 'from_ad', $back ) );
		}
		if ( 'landing' === $op ) {
			if ( ! Creatives::get( $code ) ) {
				$done( 'error', '找不到这个素材。', $back );
			}
			if ( ! Ads_Panel::set_landing( $code, $landing ) ) {
				$done( 'error', '这个落地页不在本店的页面里，请重新选。', $back );
			}
			$done( 'success', $code . ' 的落地页已换成“' . Ads_Panel::landing_label( $landing ) . '”。请重新复制链接，到 Meta 里替换这个素材所有广告的网址。', remove_query_arg( 'from_ad', $back ) . '#pc-cr-link-' . strtolower( $code ) );
		}
		if ( 'link' === $op ) {
			if ( ! Creatives::ad( $ad ) || ! Creatives::get( $code ) ) {
				$done( 'error', '广告或素材不存在。', $back );
			}
			Creatives::link_ad( $ad, $code );
			$done( 'success', '广告 ' . $ad . ' 已归到 ' . $code . '。', $back );
		}
		if ( ! in_array( $op, array( 'create', 'update' ), true ) ) {
			$done( 'error', '不认识的操作。', $back );
		}

		$clean = Creatives::clean_attrs( $form, Schema::vocab() );
		if ( $clean['problems'] ) {
			$names = array_map( array( Creatives::class, 'attr_name' ), $clean['problems'] );
			$done( 'error', '请选择：' . implode( '、', $names ) . '。', 'create' === $op && '' !== $ad ? add_query_arg( 'from_ad', $ad, $back ) : $back );
		}
		$thumb = self::thumbnail( $picked );
		if ( is_wp_error( $thumb ) ) {
			$done( 'error', $thumb->get_error_message(), $back );
		}
		if ( 'create' === $op && $panel && $thumb < 1 ) {
			$done( 'error', '请先选择素材图片（视频素材请选封面图）。', $back );
		}
		$video = self::video();
		if ( is_wp_error( $video ) ) {
			$done( 'error', $video->get_error_message(), $back );
		}

		if ( 'update' === $op ) {
			if ( ! Creatives::get( $code ) ) {
				$done( 'error', '找不到这个素材。', $back );
			}
			Creatives::update( $code, $clean['attrs'], $note, $thumb > 0 ? $thumb : null, $video > 0 ? $video : null );
			$done( 'success', $code . ' 已保存。属性改了的话，记得同步改 Meta 里的广告名：' . Contract::build_ad_name( $code, $clean['attrs'] ), $back );
		}

		$from = '' !== $ad ? Creatives::ad( $ad ) : null;
		$want = $from ? Creatives::code_from_ad( (string) $from['code'], (string) $from['ad_name'], Creatives::codes() ) : '';
		$new  = Creatives::create( $clean['attrs'], max( 0, $thumb ), $note, $want, max( 0, $video ) );
		if ( is_wp_error( $new ) ) {
			$done( 'error', $new->get_error_message(), $back );
		}
		if ( '' !== $landing ) {
			Ads_Panel::set_landing( $new, $landing ); // 不在可选列表里就不记，链接用首页。
		}
		$to = $panel ? $back . '#pc-cr-link-' . strtolower( $new ) : $back;
		if ( $from ) {
			Creatives::link_ad( (string) $from['ad_id'], $new );
			$done( 'success', '已登记为 ' . $new . '，广告 ' . $from['ad_id'] . ' 已归位。以后这个素材的广告都用下面 ' . $new . ' 的投放链接。', $to );
		}
		$done( 'success', '已生成 ' . $new . ' 的投放链接，在下面“我的素材链接”里复制，粘到这个素材所有广告的“网址”一栏。', $to );
	}

	/**
	 * 缩略图：先看有没有上传文件，再看管理员从媒体库选的附件。没有 = 0。
	 *
	 * @return int|\WP_Error
	 */
	private static function thumbnail( int $picked ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() 已校验。
		$file = isset( $_FILES['thumb'] ) && is_array( $_FILES['thumb'] ) ? $_FILES['thumb'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $file && '' !== (string) ( $file['name'] ?? '' ) ) {
			if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				return new \WP_Error( 'pc_creative_upload', '图片上传失败，请换一张或稍后再试。' );
			}
			$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::IMAGE_EXT, true ) ) {
				return new \WP_Error( 'pc_creative_upload', '图片只支持 JPG、PNG、GIF、WebP。视频素材请选封面图，视频文件放在“视频文件”一栏。' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$id = media_handle_upload( 'thumb', 0 );
			if ( is_wp_error( $id ) ) {
				return new \WP_Error( 'pc_creative_upload', '图片上传失败：' . $id->get_error_message() );
			}
			return (int) $id;
		}
		if ( $picked > 0 && current_user_can( 'upload_files' ) && wp_attachment_is_image( $picked ) ) {
			return $picked;
		}
		return 0;
	}

	/**
	 * 可选的视频文件（字段 video）。没传 = 0。
	 *
	 * @return int|\WP_Error
	 */
	private static function video() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() 已校验。
		$file = isset( $_FILES['video'] ) && is_array( $_FILES['video'] ) ? $_FILES['video'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $file || '' === (string) ( $file['name'] ?? '' ) ) {
			return 0;
		}
		$err = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( in_array( $err, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new \WP_Error( 'pc_creative_upload', '视频太大，超过了服务器的上传上限（' . size_format( wp_max_upload_size() ) . '）。可以先不传视频，只传封面图。' );
		}
		if ( UPLOAD_ERR_OK !== $err ) {
			return new \WP_Error( 'pc_creative_upload', '视频上传失败，请稍后再试。' );
		}
		if ( ! in_array( strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ), self::VIDEO_EXT, true ) ) {
			return new \WP_Error( 'pc_creative_upload', '视频只支持 MP4、MOV、M4V、WebM。' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'video', 0 );
		return is_wp_error( $id ) ? new \WP_Error( 'pc_creative_upload', '视频上传失败：' . $id->get_error_message() ) : (int) $id;
	}

	/** 上传上限（给表单提示用）。 */
	public static function max_upload(): string {
		return size_format( wp_max_upload_size() );
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-core' ), 403 );
		}
		Schema::maybe_install();
		Vocab_Sync::maybe_sync();
		$vocab  = Schema::vocab();
		$labels = Creatives::label_map( $vocab );
		$all    = Creatives::all();
		$stats  = Creatives::stats();
		$unreg  = Creatives::unregistered_ads();
		$today  = wp_date( 'Y-m-d' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读的筛选参数。
		$filter = array();
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			$filter[ $k ] = isset( $_GET[ 'f_' . $k ] ) ? sanitize_key( wp_unslash( $_GET[ 'f_' . $k ] ) ) : '';
		}
		$status = isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		$ads_page = Ads_Panel::page_url();

		echo '<div class="wrap pc-creatives pc-cr">';
		echo '<h1>素材库</h1>';
		Module::tabs( Module::PAGE_LIBRARY );
		Staff_Buyer::render_flash();
		self::styles();

		// 上传在广告设置页。
		echo '<div class="pc-cr-box pc-cr-howto"><p>这里汇总所有素材的数据，方便对比。上传新素材、复制投放链接请到广告设置。</p><p><a class="button button-primary" href="' . esc_url( $ads_page . '#pc-cr-upload' ) . '">去广告设置上传素材</a></p></div>';

		// 未登记广告。
		if ( $unreg ) {
			echo '<div class="pc-cr-box pc-cr-warn"><h2>未登记的广告（' . count( $unreg ) . '）</h2>';
			echo '<p>这些广告带来了访客或花费，但没用素材投放链接（链接里没有素材编号），统计时算“未登记”。归到已有素材，或去广告设置页补传素材，之前的数据会自动归位。</p>';
			echo '<table class="widefat striped"><thead><tr><th>广告名</th><th>广告编号</th><th>首次出现</th><th>累计花费</th><th>处理</th></tr></thead><tbody>';
			foreach ( $unreg as $u ) {
				echo '<tr><td>' . esc_html( '' !== $u['ad_name'] ? $u['ad_name'] : '（没有广告名）' ) . '</td>';
				echo '<td><code>' . esc_html( $u['ad_id'] ) . '</code></td>';
				echo '<td>' . esc_html( $u['first_seen_ms'] > 0 ? wp_date( 'Y-m-d H:i', (int) floor( $u['first_seen_ms'] / 1000 ) ) : '—' ) . '</td>';
				echo '<td>' . esc_html( Creatives::money( $u['spend_cents'] ) ) . '</td><td class="pc-cr-actions">';
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'from_ad', $u['ad_id'], $ads_page ) . '#pc-cr-upload' ) . '">补传素材</a>';
				if ( $all ) {
					self::form_open( 'link' );
					echo '<input type="hidden" name="ad_id" value="' . esc_attr( $u['ad_id'] ) . '"><select name="code" aria-label="归到哪个素材">';
					foreach ( $all as $c ) {
						echo '<option value="' . esc_attr( $c['code'] ) . '">' . esc_html( $c['code'] ) . '</option>';
					}
					echo '</select> <button class="button button-small">归到这个素材</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		// 筛选。
		echo '<form class="pc-cr-filter" method="get"><input type="hidden" name="page" value="' . esc_attr( Module::PAGE_LIBRARY ) . '">';
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			echo '<select name="f_' . esc_attr( $k ) . '" aria-label="' . esc_attr( Creatives::attr_name( $k ) ) . '"><option value="">' . esc_html( '全部' . Creatives::attr_name( $k ) ) . '</option>';
			foreach ( $labels[ $k ] ?? array() as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $filter[ $k ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>';
		}
		$opts = array( '' => '全部（不含归档）', 'live' => '投放中', 'pending' => '待导入花费', 'new' => '未投放', 'stopped' => '已停', 'archived' => '已归档' );
		echo '<select name="f_status" aria-label="状态">';
		foreach ( $opts as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $status, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <button class="button">筛选</button></form>';

		// 卡片。
		$shown = 0;
		echo '<div class="pc-cr-grid">';
		foreach ( $all as $c ) {
			$st    = $stats[ $c['code'] ] ?? array( 'spend_cents' => 0, 'last_day' => '', 'visitors' => 0 );
			$state = Creatives::STATUS_ARCHIVED === $c['status'] ? 'archived' : Creatives::display_state( $st, $today );
			if ( ( '' === $status && 'archived' === $state ) || ( '' !== $status && $status !== $state ) ) {
				continue;
			}
			foreach ( $filter as $k => $v ) {
				if ( '' !== $v && ( $c['attrs'][ $k ] ?? '' ) !== $v ) {
					continue 2;
				}
			}
			++$shown;
			self::card( $c, $st, $state, $labels, $vocab );
		}
		echo '</div>';
		if ( ! $shown ) {
			echo '<p class="pc-cr-empty">' . esc_html( $all ? '没有符合筛选条件的素材。' : '还没有素材。到“广告设置”页上传第一个素材。' ) . '</p>';
		}

		$sync = Vocab_Sync::state();
		echo '<p class="pc-cr-foot">属性词表第 ' . (int) Vocab_Sync::local_ver() . ' 版' . esc_html( $sync['at'] > 0 ? '，' . wp_date( 'Y-m-d H:i', $sync['at'] ) . ' 检查过' . ( '' !== $sync['message'] && ! $sync['ok'] ? '（' . $sync['message'] . '）' : '' ) : '' ) . '。';
		self::form_open( 'vocab' );
		echo '<button class="button-link">立即同步</button></form></p>';
		echo '</div>';
		self::script();
	}

	public static function form_open( string $op, bool $upload = false ): void {
		echo '<form class="pc-cr-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION_SAVE ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		wp_nonce_field( Module::ACTION_SAVE );
	}

	/** 属性下拉、缩略图、备注。 */
	private static function fields( array $vocab, array $attrs, string $note, int $attachment_id, string $uid ): void {
		$by = array();
		foreach ( $vocab as $item ) {
			$by[ $item['attr'] ][] = $item;
		}
		echo '<div class="pc-cr-fields">';
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			$req = 'lang' !== $k;
			$id  = 'pc-cr-' . $uid . '-' . $k;
			echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( Creatives::attr_name( $k ) ) . ( $req ? ' <span class="pc-cr-req">*</span>' : '（可选）' ) . '</label>';
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $k ) . '"' . ( $req ? ' required' : '' ) . '><option value="">' . esc_html( $req ? '请选择' : '不填' ) . '</option>';
			$cur   = (string) ( $attrs[ $k ] ?? '' );
			$known = false;
			foreach ( $by[ $k ] ?? array() as $item ) {
				$known = $known || $item['value'] === $cur;
				echo '<option value="' . esc_attr( $item['value'] ) . '"' . selected( $cur, $item['value'], false ) . '>' . esc_html( $item['label'] . '（' . $item['value'] . '）' ) . '</option>';
			}
			if ( '' !== $cur && ! $known ) {
				echo '<option value="' . esc_attr( $cur ) . '" selected>' . esc_html( $cur . '（词表里已停用）' ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '</div>';
		echo '<p><label for="pc-cr-' . esc_attr( $uid ) . '-thumb">图片' . ( $attachment_id ? '（不选 = 保留现在的）' : '' ) . '</label> <input id="pc-cr-' . esc_attr( $uid ) . '-thumb" type="file" name="thumb" accept=".jpg,.jpeg,.png,.gif,.webp,image/*"> ';
		if ( current_user_can( 'upload_files' ) ) {
			echo '<input type="hidden" name="attachment_id" value="0" data-pc-cr-media-id><button type="button" class="button" data-pc-cr-media>从媒体库选</button> <span data-pc-cr-media-name></span>';
		}
		echo '<br><span class="description">图文素材传原图，视频素材传封面图。</span></p>';
		echo '<p><label for="pc-cr-' . esc_attr( $uid ) . '-note">备注（可选）</label><textarea id="pc-cr-' . esc_attr( $uid ) . '-note" name="note" rows="2" maxlength="500">' . esc_textarea( $note ) . '</textarea></p>';
	}

	private static function card( array $c, array $st, string $state, array $labels, array $vocab ): void {
		$link  = Ads_Panel::link( Ads_Panel::landing_of( $c['code'] ), $c['code'] );
		$badge = Creatives::STATE_LABELS;
		echo '<div class="pc-cr-card is-' . esc_attr( $state ) . '">';
		echo '<div class="pc-cr-thumb">' . ( $c['attachment_id'] ? wp_get_attachment_image( $c['attachment_id'], 'medium' ) : '<span>无缩略图</span>' ) . '</div>';
		echo '<div class="pc-cr-body"><p class="pc-cr-head"><strong>' . esc_html( $c['code'] ) . '</strong> <span class="pc-cr-badge is-' . esc_attr( $state ) . '">' . esc_html( $badge[ $state ] ?? $state ) . '</span></p>';
		echo '<p class="pc-cr-attrs">';
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
			if ( isset( $c['attrs'][ $k ] ) ) {
				echo '<span>' . esc_html( Creatives::attr_name( $k ) . '：' . ( $labels[ $k ][ $c['attrs'][ $k ] ] ?? $c['attrs'][ $k ] ) ) . '</span>';
			}
		}
		echo '</p><div class="pc-cr-copyrow"><input type="text" readonly value="' . esc_attr( $link ) . '" aria-label="投放链接（首页）"><button type="button" class="button button-small" data-pc-cr-copy="' . esc_attr( $link ) . '">复制链接</button></div>';
		echo '<p class="pc-cr-nums"><span>累计花费 <b>' . esc_html( Creatives::money( (int) $st['spend_cents'] ) ) . '</b></span><span>进站访客 <b>' . (int) $st['visitors'] . '</b></span></p>';
		if ( '' !== $c['note'] ) {
			echo '<p class="pc-cr-note">' . esc_html( $c['note'] ) . '</p>';
		}
		echo '<details><summary>编辑</summary>';
		self::form_open( 'update', true );
		echo '<input type="hidden" name="code" value="' . esc_attr( $c['code'] ) . '">';
		self::fields( $vocab, $c['attrs'], $c['note'], $c['attachment_id'], strtolower( $c['code'] ) );
		echo '<p><button class="button button-primary">保存</button></p></form></details>';
		$arch = Creatives::STATUS_ARCHIVED === $c['status'];
		echo '<div class="pc-cr-card-acts">';
		self::form_open( $arch ? 'restore' : 'archive' );
		echo '<input type="hidden" name="code" value="' . esc_attr( $c['code'] ) . '"><button class="button button-small' . ( $arch ? '' : ' pc-cr-archive' ) . '">' . esc_html( $arch ? '恢复' : '归档' ) . '</button></form>';
		self::delete_button( $c['code'], $st );
		echo '</div>';
		echo '</div></div>';
	}

	/** 删除按钮（2026-11-13）：有数据的素材按钮置灰，提示只能归档。广告设置页的链接行也用。 */
	public static function delete_button( string $code, array $st ): void {
		$why = Creatives::delete_blocker( $code, $st );
		if ( '' !== $why ) {
			echo '<button type="button" class="button button-small pc-cr-del" disabled title="' . esc_attr( $why . '，不能删除，可以归档' ) . '">删除</button>';
			return;
		}
		self::form_open( 'delete' );
		echo '<input type="hidden" name="code" value="' . esc_attr( $code ) . '"><button class="button button-small pc-cr-del" data-pc-cr-del="' . esc_attr( $code ) . '">删除</button></form>';
	}

	private static function styles(): void {
		echo '<style>
.pc-cr-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 16px 12px;margin:16px 0;max-width:1100px}
.pc-cr-box h2{font-size:15px;margin:12px 0 6px;display:inline-block}
.pc-cr-box summary{cursor:pointer}
.pc-cr-warn{border-left:4px solid #dba617}
.pc-cr-copyrow{display:flex;gap:6px;align-items:center;margin:6px 0}
.pc-cr-copyrow input{flex:1;min-width:0;font-family:monospace;font-size:12px}
.pc-cr-fields{display:flex;flex-wrap:wrap;gap:4px 16px}
.pc-cr-form label{display:block;font-weight:600;margin-bottom:2px}
.pc-cr-form textarea{width:100%;max-width:520px}
.pc-cr-req{color:#d63638}
.pc-cr-actions .pc-cr-form{display:inline-flex;gap:4px;margin-left:6px}
.pc-cr-actions .pc-cr-form label{display:none}
.pc-cr-filter{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}
.pc-cr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;max-width:1400px}
.pc-cr-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;display:flex;flex-direction:column}
.pc-cr-card.is-archived{opacity:.65}
.pc-cr-thumb{aspect-ratio:1/1;background:#f0f0f1;display:flex;align-items:center;justify-content:center;color:#787c82}
.pc-cr-thumb img{width:100%;height:100%;object-fit:cover}
.pc-cr-body{padding:8px 12px 12px}
.pc-cr-body p{margin:4px 0}
.pc-cr-head strong{font-size:16px}
.pc-cr-badge{font-size:12px;border-radius:10px;padding:1px 8px;background:#f0f0f1;color:#50575e}
.pc-cr-badge.is-live{background:#edfaef;color:#007017}
.pc-cr-badge.is-pending{background:#fcf9e8;color:#8a6d00}
.pc-cr-badge.is-new{background:#f0f6fc;color:#2271b1}
.pc-cr-attrs span,.pc-cr-nums span{display:inline-block;margin-right:10px;font-size:12px}
.pc-cr-note{color:#50575e;font-size:12px}
.pc-cr-card details{margin:6px 0}
.pc-cr-card .pc-cr-fields{display:block}
.pc-cr-archive{color:#b32d2e}
.pc-cr-card-acts{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
.pc-cr-card-acts form{margin:0}
.pc-cr-del:not([disabled]){color:#b32d2e!important;border-color:#b32d2e!important}
.pc-cr-foot{color:#646970;margin-top:20px}
.pc-cr-foot .pc-cr-form{display:inline}
@media (max-width:782px){
.pc-cr-filter{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.pc-cr-filter select{width:100%;max-width:none;padding-right:28px;text-overflow:ellipsis}
.pc-cr-filter .button{grid-column:1/-1}
.pc-cr-thumb{aspect-ratio:auto;height:180px}
.pc-cr-actions .pc-cr-form{display:flex;margin:6px 0 0}
}
</style>';
	}

	private static function script(): void {
		?>
<script>
( function () {
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-pc-cr-copy]' );
		if ( b ) {
			var text = b.getAttribute( 'data-pc-cr-copy' ), label = b.textContent;
			var ok = function () { b.textContent = '已复制'; setTimeout( function () { b.textContent = label; }, 1500 ); };
			var input = b.parentNode.querySelector( 'input' );
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( ok, function () { if ( input ) { input.select(); } } );
			} else if ( input ) {
				input.select();
				try { document.execCommand( 'copy' ); ok(); } catch ( err ) {}
			}
			return;
		}
		var m = e.target.closest( '[data-pc-cr-media]' );
		if ( m && window.wp && wp.media ) {
			e.preventDefault();
			var frame = wp.media( { title: '选择缩略图', library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				var form = m.closest( 'form' );
				form.querySelector( '[data-pc-cr-media-id]' ).value = a.id;
				form.querySelector( '[data-pc-cr-media-name]' ).textContent = a.filename || ( '#' + a.id );
			} );
			frame.open();
		}
	} );
	document.addEventListener( 'submit', function ( e ) {
		var d = e.target.querySelector( '[data-pc-cr-del]' );
		if ( d && ! window.confirm( '删除 ' + d.getAttribute( 'data-pc-cr-del' ) + '？删除后不能恢复，这个编号也不会再用。图片留在媒体库。' ) ) {
			e.preventDefault();
			return;
		}
		var b = e.target.querySelector( '.pc-cr-archive' );
		if ( b && ! window.confirm( '归档后素材卡片默认不显示，数据保留，可以随时恢复。确定归档？' ) ) {
			e.preventDefault();
		}
	} );
} )();
</script>
		<?php
	}
}
