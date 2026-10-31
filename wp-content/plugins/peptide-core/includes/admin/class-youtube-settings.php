<?php
/**
 * Non-secret YouTube integration settings. Credentials remain in server configuration.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\YouTube_Metadata;

defined( 'ABSPATH' ) || exit;

final class YouTube_Settings {
	public const ACTION = 'pc_youtube_metadata_settings';
	private const SYNC_NONCE = 'pc_youtube_metadata_sync';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	private static function url(): string {
		return admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG . '&section=youtube' );
	}

	private static function messages(): array {
		return array(
			'saved' => __( '视频信息设置已保存。', 'peptide-core' ),
			'cleared' => __( '视频信息缓存已清除，下次读取会获取最新信息。', 'peptide-core' ),
			'success' => __( '已连接，视频信息读取成功。', 'peptide-core' ),
			'partial' => __( '已读取视频信息；该视频没有返回全部标题、封面或作者信息。', 'peptide-core' ),
			'no_key' => __( '官方数据接口尚未配置。请让服务器管理员完成配置，或选择无需密钥的基础信息。', 'peptide-core' ),
			'manual' => __( '当前仅手动播放。选择基础信息后即可自动读取视频信息。', 'peptide-core' ),
			'invalid_url' => __( '请输入完整、有效的公开视频链接后再测试。', 'peptide-core' ),
			'invalid_key' => __( '服务器接口配置未通过，请让管理员检查服务配置与访问限制。', 'peptide-core' ),
			'denied' => __( '服务拒绝读取，请让管理员检查接口权限与访问限制。', 'peptide-core' ),
			'unavailable' => __( '暂时无法读取这个视频，请检查它是否公开并可访问。', 'peptide-core' ),
			'quota' => __( '视频服务暂时达到调用限额，请稍后重试。', 'peptide-core' ),
			'local_limit' => __( '已达到本站读取上限，请稍后重试或调整每日上限。', 'peptide-core' ),
			'busy' => __( '视频信息正在更新，请稍后重试。', 'peptide-core' ),
			'network' => __( '暂时无法连接视频服务，请稍后重试。', 'peptide-core' ),
			'upstream' => __( '视频服务暂时不可用，请稍后重试。', 'peptide-core' ),
			'invalid_response' => __( '视频服务未返回可用信息，请稍后重试。', 'peptide-core' ),
			'sync_complete' => __( '现有页面同步完成。此操作只登记引用，没有向视频服务发出请求。', 'peptide-core' ),
			'sync_more' => __( '已同步这一批页面，可以继续同步下一批。', 'peptide-core' ),
			'sync_range' => __( '本轮已达到 256 页范围上限。需要时可显式开始下一轮。', 'peptide-core' ),
			'sync_failed' => __( '这一批同步未完成，已保留进度。请重试。', 'peptide-core' ),
			'sync_capacity' => __( '已达到自动更新引用容量。请减少视频引用或联系管理员，再重试。', 'peptide-core' ),
			'sync_expired' => __( '同步进度已过期，请重新开始。', 'peptide-core' ),
		);
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( '只有站点管理员可以修改视频服务设置。', 'peptide-core' ), '', array( 'response' => 403 ) ); }
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		check_admin_referer( 'sync' === $operation ? self::SYNC_NONCE : self::ACTION );
		$status = 'invalid_response';
		if ( 'save' === $operation ) {
			$config = array(
				'provider' => isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '',
				'daily_request_limit' => isset( $_POST['daily_request_limit'] ) ? absint( $_POST['daily_request_limit'] ) : 100,
			);
			$status = YouTube_Metadata::save_config( $config ) ? 'saved' : 'denied';
		} elseif ( 'clear' === $operation ) {
			$status = YouTube_Metadata::clear_cache() ? 'cleared' : 'busy';
		} elseif ( 'test' === $operation ) {
			$url = isset( $_POST['test_url'] ) && is_string( $_POST['test_url'] ) ? trim( wp_unslash( $_POST['test_url'] ) ) : '';
			$result = YouTube_Metadata::test( $url );
			$status = is_wp_error( $result ) ? $result->get_error_message() : ( $result['rows'][0]['status'] ?? 'invalid_response' );
		} elseif ( 'sync' === $operation ) {
			$status = self::sync();
		}
		$messages = self::messages();
		set_transient( 'pc_ytm_admin_notice_' . get_current_user_id(), array( 'status' => isset( $messages[ $status ] ) ? $status : 'invalid_response' ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function form_start( string $operation ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-bc">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'sync' === $operation ? self::SYNC_NONCE : self::ACTION );
	}

	private static function sync(): string {
		$key = 'pc_ytm_admin_sync_' . get_current_user_id();
		$old = get_transient( $key );
		$step = isset( $_POST['sync_step'] ) && is_string( $_POST['sync_step'] ) ? sanitize_key( wp_unslash( $_POST['sync_step'] ) ) : '';
		if ( 'start' === $step ) { $state = array( 'cursor' => 0, 'scanned' => 0, 'matched' => 0, 'status' => 'more' ); }
		elseif ( 'next' === $step && is_array( $old ) && 'range_complete' === ( $old['status'] ?? '' ) ) { $state = array( 'cursor' => (int) $old['cursor'], 'scanned' => 0, 'matched' => 0, 'status' => 'more' ); }
		elseif ( 'continue' === $step && is_array( $old ) && in_array( $old['status'] ?? '', array( 'more', 'failed', 'capacity' ), true ) && (int) ( $old['scanned'] ?? 0 ) < 256 ) { $state = $old; }
		else { return 'sync_expired'; }
		$result = YouTube_Metadata::sync_batch( (int) $state['cursor'], min( 20, 256 - (int) $state['scanned'] ) );
		if ( is_wp_error( $result ) ) { return 'sync_failed'; }
		$state['cursor'] = (int) $result['cursor']; $state['scanned'] += (int) $result['processed']; $state['matched'] += (int) $result['matched'];
		$state['status'] = (int) $state['scanned'] >= 256 && 'more' === $result['status'] ? 'range_complete' : $result['status'];
		set_transient( $key, $state, 1800 );
		return array( 'complete' => 'sync_complete', 'more' => 'sync_more', 'range_complete' => 'sync_range', 'capacity' => 'sync_capacity', 'failed' => 'sync_failed' )[ $state['status'] ] ?? 'sync_failed';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( '视频服务由站点管理员配置；你仍可在装修页面添加视频和关联商品。', 'peptide-core' ) . '</p></div>';
			return;
		}
		$config = YouTube_Metadata::config(); $state = YouTube_Metadata::admin_status();
		$notice_key = 'pc_ytm_admin_notice_' . get_current_user_id(); $notice = get_transient( $notice_key ); delete_transient( $notice_key );
		$messages = self::messages();
		echo '<p class="pc-ss-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . Store_Settings::SYSTEM_SLUG ) ) . '">' . esc_html__( '返回系统设置', 'peptide-core' ) . '</a></p>';
		echo '<h1>' . esc_html__( 'YouTube 视频信息', 'peptide-core' ) . '</h1><p class="pc-ss-desc">' . esc_html__( '自动读取视频标题、封面和作者。在装修页面粘贴视频链接，再选择要关联的商品。', 'peptide-core' ) . '</p>';
		if ( ! empty( $state['recentIndexError'] ) ) {
			$message = 'capacity' === $state['recentIndexError'] ? __( '自动更新的视频引用较多。请减少已发布页面的视频条数，或联系管理员调整容量；手动播放仍保留。', 'peptide-core' ) : __( '最近有页面的自动更新引用未能完成。请重新发布相关装修页面；视频链接和手动播放仍保留。', 'peptide-core' );
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( is_array( $notice ) && isset( $messages[ $notice['status'] ?? '' ] ) ) {
			$ok = in_array( $notice['status'], array( 'saved', 'cleared', 'success', 'partial', 'sync_complete', 'sync_more', 'sync_range' ), true );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'warning' ) . ' inline" role="status"><p>' . esc_html( $messages[ $notice['status'] ] ) . '</p></div>';
		}
		self::form_start( 'save' );
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="pc-yt-provider">' . esc_html__( '信息来源', 'peptide-core' ) . '</label></th><td><select id="pc-yt-provider" name="provider">';
		foreach ( array( 'public_oembed' => __( '基础信息（无需密钥）', 'peptide-core' ), 'data_api' => __( '官方数据接口（需服务器配置）', 'peptide-core' ), 'manual' => __( '仅手动播放', 'peptide-core' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $config['provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( '基础信息不需要登录账户或提供密钥。服务暂时不可用时，已有视频仍可手动播放。', 'peptide-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="pc-yt-limit">' . esc_html__( '每日读取上限', 'peptide-core' ) . '</label></th><td><input type="number" class="small-text" id="pc-yt-limit" name="daily_request_limit" min="1" max="10000" value="' . esc_attr( (string) $config['daily_request_limit'] ) . '"><p class="description">' . esc_html__( '用于控制本站对视频服务的请求次数，并非服务账户的剩余额度。已缓存的信息不重复计次。', 'peptide-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( '官方接口配置', 'peptide-core' ) . '</th><td>' . esc_html( ! empty( $state['configured'] ) ? __( '服务器已配置', 'peptide-core' ) : __( '服务器尚未配置', 'peptide-core' ) ) . '<p class="description">' . esc_html__( '密钥仅在服务器用于连接，不会显示在页面或装修内容中。', 'peptide-core' ) . '</p></td></tr></tbody></table>';
		submit_button( __( '保存设置', 'peptide-core' ) );
		echo '</form><hr><h2>' . esc_html__( '测试视频信息', 'peptide-core' ) . '</h2><p>' . esc_html__( '输入一个你确认公开的视频链接。测试只读取这个视频，不会发布页面或访问整个账户。', 'peptide-core' ) . '</p>';
		self::form_start( 'test' );
		echo '<p><label for="pc-yt-test-url">' . esc_html__( '公开视频链接', 'peptide-core' ) . '</label></p><p><input type="url" class="large-text" id="pc-yt-test-url" name="test_url" required maxlength="2048" placeholder="https://www.youtube.com/watch?v=…" autocomplete="off"></p>';
		submit_button( __( '读取视频信息', 'peptide-core' ), 'secondary' );
		echo '</form><hr><h2>' . esc_html__( '已缓存的视频信息', 'peptide-core' ) . '</h2><p>' . esc_html__( '站点计划任务正常运行时，已发布页面的视频信息会定期更新。清除缓存只影响视频标题、作者和封面，保留页面、视频链接及关联商品。', 'peptide-core' ) . '</p>';
		self::form_start( 'clear' ); submit_button( __( '清除视频信息缓存', 'peptide-core' ), 'secondary' ); echo '</form>';
		echo '<hr><h2>' . esc_html__( '同步现有页面', 'peptide-core' ) . '</h2><p>' . esc_html__( '登记已发布装修页面中的视频引用，每次最多检查 20 页，每轮最多 256 页；不修改页面、不发布内容，也不读取视频服务。', 'peptide-core' ) . '</p>';
		$sync = get_transient( 'pc_ytm_admin_sync_' . get_current_user_id() );
		if ( is_array( $sync ) ) {
			echo '<p role="status">' . esc_html( sprintf( __( '本轮已检查 %1$d 页，找到 %2$d 个含视频的页面。', 'peptide-core' ), (int) $sync['scanned'], (int) $sync['matched'] ) ) . '</p>';
			if ( 'complete' === ( $sync['status'] ?? '' ) ) { echo '<p>' . esc_html__( '同步完成。', 'peptide-core' ) . '</p>'; }
			if ( in_array( $sync['status'] ?? '', array( 'more', 'failed', 'capacity', 'range_complete' ), true ) ) {
				self::form_start( 'sync' );
				echo '<input type="hidden" name="sync_step" value="' . ( 'range_complete' === $sync['status'] ? 'next' : 'continue' ) . '">';
				submit_button( 'range_complete' === $sync['status'] ? __( '从后续页面开始新一轮', 'peptide-core' ) : ( 'more' === $sync['status'] ? __( '继续同步', 'peptide-core' ) : __( '重试同步', 'peptide-core' ) ), 'secondary' );
				echo '</form>';
			}
		}
		self::form_start( 'sync' ); echo '<input type="hidden" name="sync_step" value="start">'; submit_button( is_array( $sync ) ? __( '从头重新同步', 'peptide-core' ) : __( '同步现有页面', 'peptide-core' ), 'secondary' ); echo '</form>';
		echo '<details><summary>' . esc_html__( '服务器配置说明', 'peptide-core' ) . '</summary><p>' . esc_html__( '只有选择官方数据接口时，才需要服务器管理员启用 YouTube Data API v3，并在站点的服务器环境或 Git 之外的 wp-config 配置中设置 PEPTIDE_YOUTUBE_API_KEY。请限制接口与服务器出口 IP；不要把密钥填写在装修内容或聊天里。', 'peptide-core' ) . '</p><p>' . esc_html__( '自动更新需要站点的计划任务正常运行；低访问量站点应由服务器定时驱动。', 'peptide-core' ) . '</p></details>';
	}
}
