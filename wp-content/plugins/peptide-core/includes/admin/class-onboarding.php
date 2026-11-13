<?php
/**
 * 开店向导（ST-01）和投手的“广告设置”页面（ST-06）。
 *
 * 向导 5 步：1 修改密码 → 2 店铺信息 → 3 广告像素 → 4 验证像素 → 5 上传素材、拿到投放链接（可选）。前 4 步做完才算完成；
 * 没做完时投手的每个页面顶部都有红色提示条（首页的由 Buyer_Home 输出在最上面）。
 *
 * 每一步是否完成，尽量看真实数据，不单独记“点过了”：
 * - 修改密码：账号没有 pc_must_change_password 标记；
 * - 店铺信息：有客服 WhatsApp，并且在向导或店铺信息页保存过一次（选项 pc_onboarding 的 store）。店名只读，不算在内；
 * - 广告像素：第一个 Facebook 像素有 ID 和访问令牌（读 Pixels 的设置）；
 * - 验证像素：最近一次测试通过，而且测的就是现在这个像素（pc_onboarding 的 pixel_test；换了像素要重新验证）；
 *   或者（2026-11-13）像素自动检查（Pixels\Health）显示这个像素最近 24 小时真实回传正常——已经在跑的店不用再手动点测试；
 * - 投放链接：生成过一次（pc_onboarding 的 link）。
 * 向导状态按店铺记（一家店一个投手，管理员替投手做也算），只有“修改密码”按账号。
 *
 * 验证像素调用 peptide/v1/admin/pixel-test（F4 实现，约定见 docs/CONTRACT.md）；这里只读它的返回并记下结果。
 * 接口还没上线时页面提示“验证功能还没上线”，不报错。
 *
 * 广告设置页面就是第 3、4、5 步放在一页里，随时可以改。投手只管第一个 Facebook 像素；其他平台和其余像素在
 * 完整的“广告像素”页面（管理员）里，这里不显示也不改动。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Activity_Log;
use PeptideCore\Pixels;
use PeptideCore\Settings;
use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Onboarding {

	public const SLUG         = Staff_Buyer::PAGE_WIZARD;
	public const ADS_SLUG     = Staff_Buyer::PAGE_ADS;
	public const OPTION       = 'pc_onboarding';
	public const STEPS        = array( 'password', 'store', 'pixel', 'verify', 'link' );
	public const REQUIRED     = array( 'password', 'store', 'pixel', 'verify' );
	public const MIN_PASSWORD = 8;
	public const TEST_ROUTE   = '/peptide/v1/admin/pixel-test';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_pages' ), 62 );
		add_action( 'admin_post_' . Staff_Buyer::ACTION_PASSWORD, array( self::class, 'handle_password' ) );
		add_action( 'admin_post_' . Staff_Buyer::ACTION_ADS, array( self::class, 'handle_ads' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'observe_pixel_test' ), 20, 3 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/** 不出现在原来的菜单里（投手的菜单由 Staff_Buyer 生成）；管理员可以直接打开地址。 */
	public static function add_pages(): void {
		add_submenu_page( '', '开店向导', '开店向导', Staff_Buyer::CAP_VIEW, self::SLUG, array( self::class, 'render' ) );
		// 广告设置页在顶层栏目“广告数据追踪”下（Creatives\Module::add_pages，2026-11-12）；没有素材模块时才单独登记。
		if ( ! class_exists( \PeptideCore\Creatives\Module::class ) ) {
			add_submenu_page( '', '广告设置', '广告设置', Staff_Buyer::CAP_VIEW, self::ADS_SLUG, array( self::class, 'render_ads' ) );
		}
	}

	public static function url( string $step = '' ): string {
		return Staff_Buyer::page_url( self::SLUG, '' !== $step ? array( 'step' => $step ) : array() );
	}

	/** 店里有没有素材（第 5 步算完成）。素材模块没装好时为 false。 */
	private static function has_creative(): bool {
		return class_exists( \PeptideCore\Creatives\Schema::class ) && \PeptideCore\Creatives\Schema::installed() && (bool) \PeptideCore\Creatives\Creatives::codes();
	}

	/**
	 * 像素自动检查是否显示“回传正常”：是就返回那个像素 ID（evaluate() 拿它和当前像素比），否则空字符串。
	 * 同一请求只查一次（向导、首页提示条、广告设置页都会用到）。
	 */
	private static function pixel_live( string $pixel_id ): string {
		static $cache = array();
		if ( '' === $pixel_id || ! class_exists( \PeptideCore\Pixels\Health::class ) ) {
			return '';
		}
		if ( ! array_key_exists( $pixel_id, $cache ) ) {
			$h                    = \PeptideCore\Pixels\Health::current();
			$cache[ $pixel_id ] = ( 'ok' === ( $h['level'] ?? '' ) && $pixel_id === (string) ( $h['pixel'] ?? '' ) ) ? $pixel_id : '';
		}
		return $cache[ $pixel_id ];
	}

	/** @return array<string,string> */
	public static function labels(): array {
		return array(
			'password' => '修改密码',
			'store'    => '店铺信息',
			'pixel'    => '广告像素',
			'verify'   => '验证像素',
			'link'     => '上传素材，拿到投放链接',
		);
	}

	// ---------- 状态 ----------

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function remember( array $patch ): void {
		update_option( self::OPTION, array_merge( self::state(), $patch ), false );
	}

	/** 店铺信息保存过一次（Store_Settings 保存成功后调用）。 */
	public static function mark_store_saved(): void {
		self::remember( array( 'store' => time() ) );
	}

	/**
	 * 主 Facebook 像素：像素列表里的第一个。
	 *
	 * @return array{id:string,has_token:bool,count:int}
	 */
	public static function facebook_pixel(): array {
		$pixels = array_values( (array) ( Pixels::settings()['platforms']['facebook']['pixels'] ?? array() ) );
		$first  = is_array( $pixels[0] ?? null ) ? $pixels[0] : array();
		return array(
			'id'        => (string) ( $first['id'] ?? '' ),
			'has_token' => '' !== (string) ( $first['token'] ?? '' ),
			'count'     => count( $pixels ),
		);
	}

	/** 有没有投手账号还没改密码（按店铺看向导状态时用）。 */
	private static function any_buyer_must_change(): bool {
		return (bool) get_users(
			array(
				'role'        => Staff_Buyer::ROLE,
				'meta_key'    => Staff_Buyer::META_MUST_PASS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
	}

	/**
	 * 判断各步要用到的事实。$user_id 为 0 时按店铺看（任何一个投手账号没改密码都算没完成）。
	 *
	 * @return array<string,mixed>
	 */
	public static function facts( int $user_id = 0 ): array {
		$state   = self::state();
		$support = Settings::all()['support'] ?? array();
		$pixel   = self::facebook_pixel();
		return array(
			'must_change'     => $user_id > 0 ? Staff_Buyer::must_change( $user_id ) : self::any_buyer_must_change(),
			'whatsapp'        => is_array( $support ) ? (string) ( $support['whatsapp'] ?? '' ) : '',
			'store_confirmed' => ! empty( $state['store'] ),
			'pixel_id'        => $pixel['id'],
			'has_token'       => $pixel['has_token'],
			'test'            => is_array( $state['pixel_test'] ?? null ) ? $state['pixel_test'] : array(),
			'live'            => self::pixel_live( $pixel['id'] ),
			// 第 5 步：生成过通用链接，或者已经上传过素材（2026-11-12 起向导这一步就是上传素材）。
			'link'            => ! empty( $state['link'] ) || self::has_creative(),
		);
	}

	/**
	 * 各步是否完成（不读数据库，测试里直接调用）。
	 *
	 * @param array<string,mixed> $facts facts() 的结果。
	 * @return array<string,bool>
	 */
	public static function evaluate( array $facts ): array {
		$pixel_id = (string) ( $facts['pixel_id'] ?? '' );
		$pixel    = '' !== $pixel_id && ! empty( $facts['has_token'] );
		$test     = is_array( $facts['test'] ?? null ) ? $facts['test'] : array();
		return array(
			'password' => empty( $facts['must_change'] ),
			'store'    => '' !== (string) ( $facts['whatsapp'] ?? '' ) && ! empty( $facts['store_confirmed'] ),
			'pixel'    => $pixel,
			'verify'   => $pixel && ( ( ! empty( $test['ok'] ) && (string) ( $test['pixel'] ?? '' ) === $pixel_id ) || (string) ( $facts['live'] ?? '' ) === $pixel_id ),
			'link'     => ! empty( $facts['link'] ),
		);
	}

	/** 还差几步必填项。 */
	public static function remaining( array $done ): int {
		$left = 0;
		foreach ( self::REQUIRED as $step ) {
			$left += empty( $done[ $step ] ) ? 1 : 0;
		}
		return $left;
	}

	/** 现在该做哪一步；全部做完（含可选的投放链接）时是 done。 */
	public static function current_step( array $done ): string {
		foreach ( self::STEPS as $step ) {
			if ( empty( $done[ $step ] ) ) {
				return $step;
			}
		}
		return 'done';
	}

	/**
	 * 向导状态。
	 *
	 * @return array{steps:array<string,bool>,remaining:int,complete:bool,current:string}
	 */
	public static function status( int $user_id = 0 ): array {
		$done = self::evaluate( self::facts( $user_id ) );
		$left = self::remaining( $done );
		return array(
			'steps'     => $done,
			'remaining' => $left,
			'complete'  => 0 === $left,
			'current'   => self::current_step( $done ),
		);
	}

	/** 按店铺看的向导状态（供总后台巡检用）。 */
	public static function store_status(): array {
		return self::status( 0 );
	}

	/** 当前登录的人看到的状态：投手看自己的，管理员看店铺的。 */
	private static function my_status(): array {
		return self::status( Staff_Buyer::current() ? get_current_user_id() : 0 );
	}

	// ---------- 红色提示条 ----------

	/** 向导没做完时的提示条；做完了返回空。$native = 用 WordPress 自带的提示样式（非投手版页面没有加载投手版样式）。 */
	public static function banner( bool $native = false ): string {
		$status = self::my_status();
		if ( $status['complete'] ) {
			return '';
		}
		$labels = self::labels();
		$text   = sprintf( '还差 %d 步就可以投广告', (int) $status['remaining'] );
		$next   = '下一步：' . ( $labels[ $status['current'] ] ?? '' );
		$link   = '<a class="button" href="' . esc_url( self::url() ) . '">继续设置</a>';
		if ( $native ) {
			// 每段整体换行，手机上不会把“店铺信息”断成“店铺信 / 息”。
			$keep = 'display:inline-block;white-space:nowrap;margin:2px 12px 2px 0;vertical-align:middle';
			return '<div class="notice notice-error"><p><strong style="' . $keep . '">' . esc_html( $text ) . '</strong><span style="' . $keep . '">' . esc_html( $next ) . '</span>' . str_replace( 'class="button"', 'class="button" style="vertical-align:middle"', $link ) . '</p></div>';
		}
		return '<div class="pc-by-alert" role="alert"><div><strong>' . esc_html( $text ) . '</strong><span>' . esc_html( $next ) . '</span></div>' . $link . '</div>';
	}

	public static function notice(): void {
		if ( ! Staff_Buyer::current() || Staff_Buyer::on_own_page() ) {
			return; // 投手版自己的页面各自把提示条放在页面最上面。
		}
		echo self::banner( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- banner() 里已转义。
	}

	// ---------- 向导页面 ----------

	public static function render(): void {
		if ( ! Staff_Buyer::can_view() ) {
			return;
		}
		$status = self::my_status();
		$labels = self::labels();
		$step   = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $step, self::STEPS, true ) ) {
			$step = 'done' === $status['current'] ? 'link' : $status['current'];
		}
		$locked = Staff_Buyer::current() && empty( $status['steps']['password'] );
		if ( $locked ) {
			$step = 'password'; // 没改密码之前不能做后面的步骤。
		}

		echo '<div class="wrap pc-by pc-by-wizard"><h1>开店向导</h1>';
		Staff_Buyer::render_flash();
		echo '<p class="pc-by-lead">' . esc_html( $status['complete'] ? '必填的 4 步都完成了，可以开始投广告。下面的内容随时可以回来修改。' : sprintf( '完成下面还差的 %d 步就可以开始投广告。域名、支付、发信邮箱、商品和运费已经由总后台配好，不用你管。', (int) $status['remaining'] ) ) . '</p>';

		echo '<ol class="pc-by-steps">';
		$n = 0;
		foreach ( self::STEPS as $key ) {
			++$n;
			$done    = ! empty( $status['steps'][ $key ] );
			$classes = ( $done ? ' is-done' : '' ) . ( $key === $step ? ' is-current' : '' );
			$inner   = '<span class="pc-by-steps__n" aria-hidden="true">' . ( $done ? '✓' : (int) $n ) . '</span><span class="pc-by-steps__t">' . esc_html( $labels[ $key ] ) . ( 'link' === $key ? '<small>可选</small>' : '' ) . '</span><span class="screen-reader-text">' . ( $done ? '（已完成）' : '（未完成）' ) . '</span>';
			if ( $locked && 'password' !== $key ) {
				echo '<li class="' . esc_attr( trim( $classes . ' is-locked' ) ) . '"><span class="pc-by-steps__a">' . $inner . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '<li class="' . esc_attr( trim( $classes ) ) . '"><a class="pc-by-steps__a" href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $step ? ' aria-current="step"' : '' ) . '>' . $inner . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '</ol>';

		echo '<section class="pc-by-card">';
		switch ( $step ) {
			case 'password':
				self::form_password( 'wizard' );
				break;
			case 'store':
				Store_Settings::buyer_store_form( 'wizard' );
				break;
			case 'pixel':
				self::form_pixel( 'wizard' );
				break;
			case 'verify':
				self::form_verify( true );
				break;
			default:
				if ( class_exists( \PeptideCore\Creatives\Ads_Panel::class ) ) {
					// 2026-11-12：第 5 步就是上传第一个素材，拿到它的专属投放链接（和广告设置页同一个表单）。
					\PeptideCore\Creatives\Ads_Panel::render_upload( false );
					\PeptideCore\Creatives\Ads_Panel::render_links( false );
					\PeptideCore\Creatives\Ads_Panel::assets();
					echo '<p class="pc-by-hint">以后再上传素材、复制链接，都在左侧“广告数据追踪 → 广告设置”。</p>';
				} else {
					self::form_link();
				}
		}
		echo '</section>';

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		echo '<section class="pc-by-card pc-by-ready"><h2>已经就绪</h2><ul><li><span aria-hidden="true">✓</span> 域名：' . esc_html( $host ) . '</li><li><span aria-hidden="true">✓</span> 支付通道和收银台</li><li><span aria-hidden="true">✓</span> 发信邮箱和订单邮件</li><li><span aria-hidden="true">✓</span> 商品、价格、运费和政策页</li></ul><p class="pc-by-hint">这些由总后台统一配置。发现不对请直接联系平台负责人。</p></section>';
		echo '</div>';
	}

	/** 修改密码表单。$from：wizard（向导）或 store（店铺信息页）。 */
	public static function form_password( string $from ): void {
		$forced = Staff_Buyer::must_change( get_current_user_id() );
		echo '<h2>修改密码</h2><p class="pc-by-why">' . esc_html( $forced ? '现在的密码是别人替你设置的。先换成只有你自己知道的密码，再继续后面的步骤。' : '换一个新密码。改完后，其他设备上的登录会失效，需要用新密码重新登录。' ) . '</p>';
		if ( ! Staff_Buyer::current() ) {
			echo '<p class="pc-by-hint">你现在用的是管理员账号。管理员的密码不在这里修改。</p>';
			return;
		}
		echo '<form class="pc-by-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Staff_Buyer::ACTION_PASSWORD ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '">';
		wp_nonce_field( Staff_Buyer::ACTION_PASSWORD );
		if ( ! $forced ) {
			echo '<p><label for="pc-by-pass-now">现在的密码</label><input type="password" id="pc-by-pass-now" name="current_password" autocomplete="current-password" required></p>';
		}
		echo '<p><label for="pc-by-pass-new">新密码</label><input type="password" id="pc-by-pass-new" name="new_password" minlength="' . (int) self::MIN_PASSWORD . '" autocomplete="new-password" required><span class="pc-by-hint">至少 ' . (int) self::MIN_PASSWORD . ' 位。不要用和别人共用过的密码。</span></p>';
		echo '<p><label for="pc-by-pass-again">再输入一次新密码</label><input type="password" id="pc-by-pass-again" name="new_password_again" minlength="' . (int) self::MIN_PASSWORD . '" autocomplete="new-password" required></p>';
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary">' . esc_html( $forced ? '保存密码，下一步' : '保存新密码' ) . '</button></p></form>';
	}

	/** 第 3 步：Facebook 像素 ID 和转化 API 访问令牌。 */
	public static function form_pixel( string $from ): void {
		$pixel = self::facebook_pixel();
		echo '<h2>广告像素</h2><p class="pc-by-why">投广告必须填。Facebook 靠像素和转化 API 知道谁看了商品、谁下了单，才能把广告推给更可能购买的人。</p>';
		echo '<form class="pc-by-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Staff_Buyer::ACTION_ADS ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '">';
		wp_nonce_field( Staff_Buyer::ACTION_ADS );
		echo '<p><label for="pc-by-pixel-id">Facebook 像素 ID</label><input type="text" id="pc-by-pixel-id" name="pixel_id" inputmode="numeric" pattern="[0-9]{5,20}" maxlength="20" value="' . esc_attr( $pixel['id'] ) . '" placeholder="例如 1234567890123456" required><span class="pc-by-hint">Meta 事件管理工具 → 数据源 → 选中像素 → 设置，里面的“数据集编号”，一串数字。</span></p>';
		echo '<p><label for="pc-by-pixel-token">转化 API 访问令牌</label><input type="password" id="pc-by-pixel-token" name="token" autocomplete="new-password"' . ( $pixel['has_token'] ? ' placeholder="已保存。留空表示不修改"' : ' required' ) . '><span class="pc-by-hint">同一个设置页面往下找到“转化 API”，点“生成访问令牌”，整段复制过来。令牌只存在服务器上，保存后页面不再显示。</span></p>';
		if ( $pixel['count'] > 1 ) {
			echo '<p class="pc-by-hint">这家店另外还有 ' . (int) ( $pixel['count'] - 1 ) . ' 个 Facebook 像素，由总后台管理，这里的修改不影响它们。</p>';
		}
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary">' . esc_html( 'wizard' === $from ? '保存，下一步' : '保存' ) . '</button></p></form>';
	}

	/** 第 4 步：发送测试事件。$wizard = 通过后自动进入下一步。 */
	public static function form_verify( bool $wizard ): void {
		$pixel = self::facebook_pixel();
		$test  = self::state()['pixel_test'] ?? array();
		$test  = is_array( $test ) && (string) ( $test['pixel'] ?? '' ) === $pixel['id'] ? $test : array();
		echo '<h2>验证像素</h2><p class="pc-by-why">点一下按钮，系统向 Facebook 发一条测试事件，并直接告诉你通没通，不用自己去 Facebook 里查。</p>';
		if ( '' === $pixel['id'] || ! $pixel['has_token'] ) {
			echo '<p>还没有填像素 ID 和访问令牌。</p><p class="pc-by-actions"><a class="button button-primary" href="' . esc_url( $wizard ? self::url( 'pixel' ) : Staff_Buyer::page_url( self::ADS_SLUG ) ) . '">去填写</a></p>';
			return;
		}
		$class = '';
		$last  = '还没有验证过这个像素。';
		if ( $test ) {
			$when  = wp_date( 'Y-m-d H:i', (int) ( $test['at'] ?? 0 ) );
			$class = ! empty( $test['ok'] ) ? ' is-ok' : ' is-bad';
			$last  = ! empty( $test['ok'] ) ? '已通（' . $when . '）' : '未通（' . $when . '）' . ( '' !== (string) ( $test['message'] ?? '' ) ? '：' . (string) $test['message'] : '' );
		}
		echo '<p>当前像素：<code>' . esc_html( $pixel['id'] ) . '</code></p>';
		echo '<p class="pc-by-actions"><button type="button" class="button button-primary" data-pc-by-pixel-test' . ( $wizard ? ' data-advance="' . esc_url( self::url( 'link' ) ) . '"' : '' ) . '>发送测试事件</button> <span class="pc-by-result' . esc_attr( $class ) . '" data-pc-by-pixel-result role="status">' . esc_html( $last ) . '</span></p>';
		echo '<p class="pc-by-hint">显示“未通”时，先回上一步核对像素 ID 和访问令牌有没有复制完整，再试一次。</p>';
	}

	/** 第 5 步：生成带追踪参数的投放链接。 */
	public static function form_link(): void {
		echo '<h2>生成投放链接</h2><p class="pc-by-why">广告里要用带追踪参数的链接，数据页和素材看板才能按广告系列、广告组、广告和素材分开看效果。</p>';
		echo '<form class="pc-by-form" data-pc-by-link><p><label for="pc-by-landing">落地页</label><select id="pc-by-landing" name="landing">';
		$group = '';
		foreach ( self::landing_pages() as $page ) {
			if ( $page['group'] !== $group ) {
				echo ( '' !== $group ? '</optgroup>' : '' ) . ( '' !== $page['group'] ? '<optgroup label="' . esc_attr( $page['group'] ) . '">' : '' );
				$group = $page['group'];
			}
			echo '<option value="' . esc_attr( $page['url'] ) . '">' . esc_html( $page['label'] ) . '</option>';
		}
		echo ( '' !== $group ? '</optgroup>' : '' ) . '</select></p>';
		echo '<p class="pc-by-hint">链接里带的是 Facebook 的动态参数：投放时自动换成这条广告所在的平台（Facebook / Instagram 等）、版位，以及广告系列、广告组、广告的 ID 和广告名称。不用手填，Facebook 和 Instagram 用同一条链接。</p>';
		echo '<p class="pc-by-actions"><button type="submit" class="button button-primary">生成链接</button> <span class="pc-by-result" data-pc-by-link-result role="status"></span></p></form>';
		echo '<div class="pc-by-copyrow" data-pc-by-link-out hidden><input type="text" readonly aria-label="投放链接" data-pc-by-link-url><button type="button" class="button" data-pc-by-copy="[data-pc-by-link-url]">复制</button></div>';
		echo '<p class="pc-by-hint">把生成的链接粘贴到 Facebook 广告的“网址”一栏，同一条链接可以给多条广告用。广告名称请以素材编号开头（例如 <code>C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1</code>），素材看板才能把广告对到素材上。</p>';
	}

	// ---------- 广告设置页面 ----------

	public static function render_ads(): void {
		if ( ! Staff_Buyer::can_view() ) {
			return;
		}
		echo '<div class="wrap pc-by pc-by-ads"><h1>广告设置</h1>';
		echo self::banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- banner() 里已转义。
		Staff_Buyer::render_flash();
		// 2026-11-12：像素连通后折叠成一行；主角是“上传素材 → 专属投放链接”。
		// 像素状态自动检查（Pixels\Health，读真实事件的回传记录）：出问题时提示放在页面最上面，像素卡片展开。
		$verified = self::evaluate( self::facts() )['verify'];
		$pixel    = self::facebook_pixel();
		$health   = class_exists( \PeptideCore\Pixels\Health::class ) ? \PeptideCore\Pixels\Health::current() : array( 'level' => 'none', 'title' => '', 'detail' => '' );
		$problem  = in_array( $health['level'], array( 'warn', 'bad' ), true );
		if ( $problem ) {
			echo \PeptideCore\Pixels\Health::alert( $health, '#pc-by-pixel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alert() 里已转义。
		}
		if ( ( $verified || 'ok' === $health['level'] ) && ! $problem ) {
			$state = 'ok' === $health['level'] ? $health['title'] : '已连通' . ( 'idle' === $health['level'] ? ' · 24 小时没有事件' : '' );
			echo '<details class="pc-by-card pc-by-pixel-done" id="pc-by-pixel"><summary><strong>广告像素</strong> <span class="pc-by-ok">' . esc_html( $state ) . '</span> <span class="pc-by-hint">像素 ' . esc_html( $pixel['id'] ) . ' · 系统自动检查 · 点开修改</span></summary>';
			self::form_pixel( 'ads' );
			self::form_verify( false );
			echo '</details>';
		} else {
			echo '<section class="pc-by-card" id="pc-by-pixel">';
			self::form_pixel( 'ads' );
			echo '</section><section class="pc-by-card">';
			self::form_verify( false );
			echo '</section>';
		}
		if ( class_exists( \PeptideCore\Creatives\Ads_Panel::class ) ) {
			\PeptideCore\Creatives\Ads_Panel::render_upload();
			\PeptideCore\Creatives\Ads_Panel::render_links();
			\PeptideCore\Creatives\Ads_Panel::assets();
		}
		echo '<details class="pc-by-card pc-by-generic-link"><summary><strong>通用链接（不绑定素材）</strong> <span class="pc-by-hint">只在临时测试时用，数据会算“未登记”</span></summary>';
		self::form_link();
		echo '</details></div>';
	}

	// ---------- 保存 ----------

	/** 表单提交后回到哪里。 */
	private static function back( string $from, string $next_step = '' ): string {
		if ( 'wizard' === $from ) {
			return self::url( $next_step );
		}
		return Staff_Buyer::page_url( 'store' === $from ? Staff_Buyer::PAGE_STORE : self::ADS_SLUG );
	}

	private static function from(): string {
		$from = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 调用处已校验。
		return in_array( $from, array( 'wizard', 'store', 'ads' ), true ) ? $from : 'ads';
	}

	/**
	 * 新密码的检查（不读数据库）。返回错误提示，空 = 可以用。
	 * 传进来的是表单提交上来的原样（WordPress 给引号加了反斜杠、已去掉首尾空格），所以长度和反斜杠按去掉转义后的内容判断。
	 */
	public static function password_error( string $new, string $again ): string {
		$plain = stripslashes( $new );
		if ( strlen( $plain ) < self::MIN_PASSWORD ) {
			return sprintf( '新密码至少要 %d 位。', self::MIN_PASSWORD );
		}
		if ( str_contains( $plain, '\\' ) ) {
			return '密码里不能有反斜杠（\\）。';
		}
		if ( $new !== $again ) {
			return '两次输入的新密码不一样。';
		}
		return '';
	}

	public static function handle_password(): void {
		if ( ! Staff_Buyer::current() ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Staff_Buyer::ACTION_PASSWORD );
		$id     = get_current_user_id();
		$user   = get_userdata( $id );
		$from   = self::from();
		$forced = Staff_Buyer::must_change( $id );
		// 和 WordPress 自己的做法保持一致（wp-admin/includes/user.php 的 edit_user()、wp_signon()）：密码按提交上来的原样
		// 加密和比对，只去掉首尾空格，不做 wp_unslash。否则带引号的密码保存成功却永远登录不上。
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$now   = isset( $_POST['current_password'] ) && is_string( $_POST['current_password'] ) ? trim( $_POST['current_password'] ) : '';
		$new   = isset( $_POST['new_password'] ) && is_string( $_POST['new_password'] ) ? trim( $_POST['new_password'] ) : '';
		$again = isset( $_POST['new_password_again'] ) && is_string( $_POST['new_password_again'] ) ? trim( $_POST['new_password_again'] ) : '';
		// phpcs:enable
		$error = self::password_error( $new, $again );
		if ( '' === $error && ! $forced && ! wp_check_password( $now, $user->user_pass, $id ) ) {
			$error = '现在的密码不对。';
		}
		if ( '' === $error && wp_check_password( $new, $user->user_pass, $id ) ) {
			$error = '新密码不能和现在的密码一样。';
		}
		if ( '' === $error ) {
			// 密码是本人在后台改的，不需要再发“密码已修改”的通知邮件。
			add_filter( 'send_password_change_email', '__return_false' );
			$result = wp_update_user( array( 'ID' => $id, 'user_pass' => $new ) );
			remove_filter( 'send_password_change_email', '__return_false' );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			}
		}
		if ( '' !== $error ) {
			Staff_Buyer::flash( 'error', $error );
			wp_safe_redirect( self::back( $from, 'password' ) );
			exit;
		}
		Staff_Buyer::set_must_change( $id, false );
		Activity_Log::log( 'staff', 'password', array( 'type' => 'user', 'id' => $id, 'label' => (string) $user->user_login ) );
		Staff_Buyer::flash( 'success', '密码已修改。以后用新密码登录。' );
		wp_safe_redirect( self::back( $from ) );
		exit;
	}

	/** 像素 ID 和访问令牌的检查（不读数据库）。$keep_token = 已经保存过令牌，可以留空。 */
	public static function pixel_error( string $pixel_id, string $token, bool $keep_token ): string {
		if ( 1 !== preg_match( '/^[0-9]{5,20}$/', $pixel_id ) ) {
			return '像素 ID 应该是一串数字（5–20 位），请从 Meta 事件管理工具里复制。';
		}
		if ( '' === $token && ! $keep_token ) {
			return '请填写转化 API 访问令牌。';
		}
		if ( '' !== $token && ( strlen( $token ) < 20 || 1 !== preg_match( '/^[A-Za-z0-9_\-\.]+$/', $token ) ) ) {
			return '访问令牌看起来不完整。请在 Meta 事件管理工具里重新生成，并整段复制。';
		}
		return '';
	}

	public static function handle_ads(): void {
		if ( ! Staff_Buyer::can_view() ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Staff_Buyer::ACTION_ADS );
		$from     = self::from();
		$pixel_id = isset( $_POST['pixel_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pixel_id'] ) ) ) : '';
		$token    = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$current  = self::facebook_pixel();
		$error    = self::pixel_error( $pixel_id, $token, $current['has_token'] && $current['id'] === $pixel_id );
		if ( '' !== $error ) {
			Staff_Buyer::flash( 'error', $error );
			wp_safe_redirect( self::back( $from, 'pixel' ) );
			exit;
		}
		self::save_facebook_pixel( $pixel_id, $token );
		Activity_Log::log( 'settings', 'save', array( 'type' => 'option', 'label' => 'Facebook 像素' ), array( 'pixel' => $pixel_id ) );
		Staff_Buyer::flash( 'success', '像素已保存。' . ( 'wizard' === $from ? '下一步：发送测试事件。' : '换了像素或令牌后，请重新发送一次测试事件。' ) );
		wp_safe_redirect( self::back( $from, 'verify' ) );
		exit;
	}

	/**
	 * 保存主 Facebook 像素（列表第一个）：换 ID、换令牌；其余像素、其他平台和全局选项不动。
	 * F4 提供 Pixels::set_facebook_pixel() 之后改为调用它（约定见 docs/CONTRACT.md）。
	 */
	public static function save_facebook_pixel( string $pixel_id, string $token ): void {
		if ( is_callable( array( Pixels::class, 'set_facebook_pixel' ) ) ) {
			Pixels::set_facebook_pixel( $pixel_id, $token );
			return;
		}
		$settings = Pixels::settings();
		$settings['platforms']['facebook']['pixels'] = self::merge_pixel( array_values( (array) $settings['platforms']['facebook']['pixels'] ), Pixels::clean_id( $pixel_id ), $token, Pixels::store_domain() );
		update_option( Pixels::OPTION, $settings, false );
	}

	/**
	 * 把主像素放进像素列表的第一位（不读数据库，测试里直接调用）。
	 * 令牌留空 = 沿用这个 ID 原来的令牌；同一个 ID 在列表里只留一份。
	 *
	 * @param array<int,array<string,string>> $pixels
	 * @return array<int,array<string,string>>
	 */
	public static function merge_pixel( array $pixels, string $pixel_id, string $token, string $domain ): array {
		$first = is_array( $pixels[0] ?? null ) ? $pixels[0] : array();
		$old   = '';
		foreach ( $pixels as $px ) {
			if ( is_array( $px ) && strtolower( (string) ( $px['id'] ?? '' ) ) === strtolower( $pixel_id ) ) {
				$old = (string) ( $px['token'] ?? '' );
			}
		}
		$main = array(
			'id'     => $pixel_id,
			'note'   => (string) ( $first['note'] ?? '' ),
			'domain' => '' !== (string) ( $first['domain'] ?? '' ) ? (string) $first['domain'] : $domain,
			'token'  => '' !== $token ? substr( (string) preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $token ), 0, 512 ) : $old,
		);
		$out = array( $main );
		foreach ( array_slice( $pixels, 1 ) as $px ) {
			if ( is_array( $px ) && strtolower( (string) ( $px['id'] ?? '' ) ) !== strtolower( $pixel_id ) ) {
				$out[] = $px;
			}
		}
		return $out;
	}

	// ---------- 验证像素的结果 ----------

	/**
	 * 记下 peptide/v1/admin/pixel-test 的结果。接口返回 { ok: bool, pixel?: string, message?: string }。
	 * 在服务端看返回值，不依赖浏览器再报一次。
	 */
	public static function observe_pixel_test( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || self::TEST_ROUTE !== $request->get_route() || is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		if ( is_array( $data ) && array_key_exists( 'ok', $data ) ) {
			$pixel = (string) ( $data['pixel'] ?? '' );
			self::remember(
				array(
					'pixel_test' => array(
						'pixel'   => '' !== $pixel ? $pixel : self::facebook_pixel()['id'],
						'ok'      => ! empty( $data['ok'] ),
						'at'      => time(),
						'message' => mb_substr( sanitize_text_field( (string) ( $data['message'] ?? '' ) ), 0, 200 ),
					),
				)
			);
		}
		return $response;
	}

	// ---------- 投放链接 ----------

	/**
	 * 可以选的落地页：首页、有商品的专辑、已上架的商品。
	 *
	 * @return array<int,array{group:string,label:string,url:string}>
	 */
	public static function landing_pages(): array {
		$out   = array( array( 'group' => '', 'label' => '首页', 'url' => home_url( '/' ) ) );
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 50 ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$url = get_term_link( $term );
			if ( ! is_wp_error( $url ) ) {
				$out[] = array( 'group' => '专辑页', 'label' => (string) $term->name, 'url' => (string) $url );
			}
		}
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 100, 'orderby' => 'title', 'order' => 'ASC' ) ) as $product ) {
			$out[] = array( 'group' => '商品页', 'label' => (string) $product->get_name(), 'url' => (string) $product->get_permalink() );
		}
		return $out;
	}

	/**
	 * 投放链接 = 落地页地址 + 追踪参数（不读数据库，测试里直接调用）。
	 * CR 素材归因（2026-11-11）：参数固定用 Contract::URL_TEMPLATE —— 广告系列 / 广告组 / 广告都用 ID（{{campaign.id}} 等），
	 * 平台用 {{site_source_name}}，另带 placement 和 ad_name。动态参数由 Meta 投放时替换，所以不能转义。
	 * $campaign、$source 是旧版（用名称）的参数，保留签名兼容旧调用，不再使用。
	 * $code = 素材编号（2026-11-12）：给了就是这个素材的专属链接（多一个 cr=编号），见 Creatives\Ads_Panel。
	 */
	public static function ad_link( string $landing, string $campaign = '', string $source = '', string $code = '' ): string {
		unset( $campaign, $source );
		$landing = (string) preg_replace( '/#.*$/', '', trim( $landing ) );
		$parts   = explode( '?', $landing, 2 );
		$query   = array();
		$ours    = array_merge( \PeptideCore\Creatives\Contract::PARAMS, array( 'fbclid' ) );
		foreach ( isset( $parts[1] ) ? explode( '&', $parts[1] ) : array() as $pair ) {
			$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );
			if ( '' !== $pair && 1 !== preg_match( '/^utm_[a-z_]+$/', $name ) && ! in_array( $name, $ours, true ) ) {
				$query[] = $pair; // 落地页原有的参数保留，旧的 utm_ 和模板里的参数去掉。
			}
		}
		$query[] = \PeptideCore\Creatives\Contract::creative_query( $code );
		return $parts[0] . '?' . implode( '&', $query );
	}

	public static function register_routes(): void {
		register_rest_route(
			'peptide/v1',
			'/admin/ad-link',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => Staff_Buyer::can_use() ? true : new \WP_Error( 'pc_forbidden', '没有权限。', array( 'status' => rest_authorization_required_code() ) ),
				'callback'            => array( self::class, 'ad_link_route' ),
			)
		);
	}

	/** POST /peptide/v1/admin/ad-link { landing, campaign?, source? } → { url }。落地页必须是本店的地址。 */
	public static function ad_link_route( \WP_REST_Request $request ) {
		$landing = esc_url_raw( (string) $request['landing'] );
		$host    = (string) wp_parse_url( $landing, PHP_URL_HOST );
		if ( '' === $landing || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return new \WP_Error( 'pc_ad_link_landing', '落地页必须是本店的页面。', array( 'status' => 400 ) );
		}
		$campaign = mb_substr( sanitize_text_field( (string) $request['campaign'] ), 0, 80 );
		if ( empty( self::state()['link'] ) ) {
			self::remember( array( 'link' => time() ) ); // 只记第一次：这个选项每写一次，首页缓存都会跟着失效。
		}
		return rest_ensure_response( array( 'url' => self::ad_link( $landing, $campaign, sanitize_key( (string) $request['source'] ) ) ) );
	}
}
