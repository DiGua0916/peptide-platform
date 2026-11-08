<?php
/**
 * 总后台 → 插件管理（2026-11-08）。
 *
 * 页面按“当前店铺”组织（店铺多了也好用）：顶部搜索 / 选择店铺；
 * - 店铺应用：这个店铺能不能用每个内置应用（开不开由店铺自己决定），每个应用可批量设为全部店铺可用 / 停用；
 * - 第三方插件：上传到插件库，看这个店铺装没装，可一键装到所有店铺；
 * - 店铺系统插件：这个店铺的系统插件，只读。
 * 真正动服务器的是 root 后台任务（插件）和店铺自己的签名接口（应用）；这里只排队、调用、显示结果。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Sites;
use PeptideHq\Store_Plugins;

defined( 'ABSPATH' ) || exit;

final class Plugins_Page {

	public const SLUG = 'pchq-plugins';

	/** 批量设置应用时最多用这么多秒，剩下的店铺提示稍后再点一次（避免页面超时）。 */
	private const BULK_SECONDS = 60;

	/** 应用中心里已知应用的中文名（店铺语言是英文时也显示中文）；新应用直接用店铺给的名称。 */
	private const APP_NAMES = array(
		'postal-assist'      => '邮编辅助',
		'campaign-countdown' => '活动倒计时',
		'first-order-offer'  => '首单 5% 优惠',
		'exit-intent'        => '离开意图增收',
		'product-broadcast'  => '商品播报',
		'activity-listener'  => '活动监听',
		'cart-feedback'      => '加购反馈',
		'recently-viewed'    => '最近浏览',
	);

	private const APP_TEXTS = array(
		'exit-intent' => '访客要离开时弹出优惠券、大转盘或自定义消息，把人留下来下单。',
	);

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '插件管理', '插件管理', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			20
		);
		add_action( 'admin_post_pchq_plugin_upload', array( self::class, 'upload' ) );
		add_action( 'admin_post_pchq_plugin_action', array( self::class, 'action' ) );
		add_action( 'admin_post_pchq_app_switch', array( self::class, 'app_switch' ) );
		add_action( 'admin_post_pchq_app_bulk', array( self::class, 'app_bulk' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	// ---------- 小工具 ----------

	private static function back( int $site = 0, string $anchor = '' ): void {
		$args = array( 'page' => self::SLUG );
		if ( $site > 0 ) {
			$args['site'] = $site;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_plugins_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function return_site(): int {
		return absint( $_POST['return_site'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 各处理函数先校验 nonce。
	}

	private static function label( array $site ): string {
		return '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'];
	}

	/** 店铺名称还只是域名 / 网址（还没起专属名称）。 */
	private static function unnamed( array $site ): bool {
		$name = strtolower( trim( (string) $site['name'] ) );
		$host = strtolower( self::host( $site ) );
		return '' === $name || $name === $host || 'www.' . $name === $host || $name === strtolower( (string) wp_parse_url( (string) $site['url'], PHP_URL_HOST ) ) || str_starts_with( $name, 'http' );
	}

	private static function host( array $site ): string {
		return (string) wp_parse_url( \PeptideHq\Site_Urls::public_url( $site ), PHP_URL_HOST );
	}

	private static function app_text( array $app ): string {
		return self::APP_TEXTS[ (string) ( $app['key'] ?? '' ) ] ?? (string) ( $app['text'] ?? '' );
	}

	private static function app_name( array $app ): string {
		return self::APP_NAMES[ (string) ( $app['key'] ?? '' ) ] ?? (string) ( $app['name'] ?? '' );
	}

	/** 这个店铺上报的某个应用（没有 = 店铺程序较旧或还没同步）。 */
	private static function site_app( array $site, string $key ): ?array {
		foreach ( (array) ( $site['info']['apps'] ?? array() ) as $app ) {
			if ( ( $app['key'] ?? '' ) === $key ) {
				return $app;
			}
		}
		return null;
	}

	private static function app_allowed( array $app ): bool {
		return ! array_key_exists( 'allowed', $app ) || ! empty( $app['allowed'] );
	}

	/** 把连接类错误翻成看得懂的话；店铺给的业务原因（中文）原样显示。 */
	private static function friendly( \WP_Error $e ): string {
		$code = $e->get_error_code();
		$msg  = $e->get_error_message();
		if ( 'pchq_offline' === $code || preg_match( '/cURL error|Could not resolve|timed out|Connection refused|HTTP status 5/i', $msg ) ) {
			return '连不上这个店铺（可能暂时离线或网址有变），这次没有做任何修改。请稍后再试，或到“店铺”页检查连接。';
		}
		if ( 'pchq_denied' === $code ) {
			return '店铺拒绝了总后台的连接密钥，这次没有做任何修改。请到“店铺”页重新连接这个店铺。';
		}
		if ( preg_match( '/No route was found|rest_no_route|HTTP status 404/i', $msg ) ) {
			return '这个店铺的程序版本较旧，还不支持这个操作，这次没有做任何修改。请先更新店铺程序。';
		}
		if ( 'pchq_store_retired' === $code ) {
			return '这个店铺已下线，不能修改。';
		}
		return $msg;
	}

	/** 最近一次连接检查失败的店铺：开关先锁住，避免点了才报错。 */
	private static function unreachable( array $site ): bool {
		return 'online' !== (string) ( $site['status'] ?? '' ) || '' !== (string) ( $site['error'] ?? '' );
	}

	/** 总后台在管的店铺（不含下线的）。 */
	private static function sites(): array {
		return array_values( array_filter( Sites::all(), static fn( array $s ): bool => 'retired' !== ( $s['status'] ?? '' ) && '' !== (string) $s['uuid'] ) );
	}

	/** 店铺上报的应用清单合在一起（新增应用自动出现）。 */
	private static function catalog( array $sites ): array {
		$catalog = array();
		foreach ( $sites as $site ) {
			foreach ( (array) ( $site['info']['apps'] ?? array() ) as $app ) {
				$key = sanitize_key( (string) ( $app['key'] ?? '' ) );
				if ( '' !== $key && ! isset( $catalog[ $key ] ) ) {
					$catalog[ $key ] = $app;
				}
			}
		}
		return $catalog;
	}

	private static function store_apps_saved( int $site_id, array $site, $res ): void {
		if ( is_array( $res ) && is_array( $res['apps'] ?? null ) ) {
			$info         = is_array( $site['info'] ?? null ) ? $site['info'] : array();
			$info['apps'] = $res['apps'];
			Sites::update( $site_id, array( 'info' => (string) wp_json_encode( $info ) ) );
		}
	}

	private static function hidden( array $fields ): string {
		$out = '';
		foreach ( $fields as $name => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		return $out;
	}

	private static function button( string $slug, string $do, string $label, int $site = 0, string $class = 'button', string $confirm = '', int $return = 0 ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>'
			. wp_nonce_field( 'pchq_plugin_action', '_wpnonce', true, false )
			. self::hidden( array( 'action' => 'pchq_plugin_action', 'slug' => $slug, 'do' => $do, 'site' => $site, 'return_site' => $return ) )
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function state_label( ?array $row ): string {
		if ( ! $row || 'removed' === $row['state'] ) {
			return '<span class="pcp-state is-off"><i></i>未安装</span>';
		}
		$map = array(
			'installing' => array( 'is-wait', '安装中…' ),
			'removing'   => array( 'is-wait', '卸载中…' ),
			'active'     => array( 'is-ok', '已启用' . ( '' !== $row['installed_version'] ? ' · ' . $row['installed_version'] : '' ) ),
			'failed'     => array( 'is-bad', '失败' ),
		);
		[ $cls, $text ] = $map[ $row['state'] ] ?? array( 'is-off', $row['state'] );
		$out = '<span class="pcp-state ' . esc_attr( $cls ) . '"><i></i>' . esc_html( $text ) . '</span>';
		if ( 'failed' === $row['state'] && '' !== $row['error'] ) {
			$out .= '<small class="pcp-error">' . esc_html( $row['error'] ) . '</small>';
		}
		return $out;
	}

	private static function ago( int $time ): string {
		$diff = max( 0, time() - $time );
		if ( $diff < 60 ) {
			return '刚刚';
		}
		if ( $diff < 3600 ) {
			return intdiv( $diff, 60 ) . ' 分钟前';
		}
		return $diff < 86400 ? intdiv( $diff, 3600 ) . ' 小时前' : intdiv( $diff, 86400 ) . ' 天前';
	}

	private static function initial( string $name ): string {
		$name = trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name ) );
		return '' === $name ? '?' : mb_strtoupper( mb_substr( $name, 0, 1 ) );
	}

	// ---------- 处理表单 ----------

	public static function upload(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权上传插件。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_plugin_upload' );
		$return = self::return_site();
		if ( empty( $_FILES['plugin_zip'] ) || ! is_array( $_FILES['plugin_zip'] ) ) {
			self::flash( 'error', '没有收到文件。文件太大（超过 32 MB）时也会这样。' );
			self::back( $return, 'plugins' );
		}
		$row = Store_Plugins::upload( $_FILES['plugin_zip'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 在 Store_Plugins::upload/inspect 里校验。
		if ( is_wp_error( $row ) ) {
			self::flash( 'error', $row->get_error_message() );
		} else {
			self::flash( 'success', '已上传：' . $row['name'] . ( '' !== $row['version'] ? ' ' . $row['version'] : '' ) . '。现在可以装到当前店铺，或一键装到所有店铺。' );
		}
		self::back( $return, 'plugins' );
	}

	public static function action(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理插件。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_plugin_action' );
		$slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$site   = absint( $_POST['site'] ?? 0 );
		$return = self::return_site();
		$res    = true;
		if ( in_array( $do, array( 'install', 'uninstall' ), true ) ) {
			$res = Store_Plugins::request( $site, $slug, $do );
			if ( true === $res ) {
				self::flash( 'success', 'install' === $do ? '已提交安装，通常 1 分钟内完成（会先检查首页和购物车能正常打开）。' : '已提交卸载。' );
			}
		} elseif ( 'install_all' === $do ) {
			// 装到所有还没装好的店铺；已在处理中的跳过，单个店铺的原因汇总显示。
			$assign = Store_Plugins::assignments()[ $slug ] ?? array();
			$queued = 0;
			$skip   = 0;
			$errors = array();
			foreach ( self::sites() as $s ) {
				$state = $assign[ (int) $s['id'] ]['state'] ?? 'removed';
				if ( in_array( $state, array( 'active', 'installing', 'removing' ), true ) ) {
					++$skip;
					continue;
				}
				$r = Store_Plugins::request( (int) $s['id'], $slug, 'install' );
				if ( true === $r ) {
					++$queued;
				} else {
					$errors[] = self::label( $s ) . '：' . ( is_wp_error( $r ) ? $r->get_error_message() : '未知原因' );
				}
			}
			self::flash( $errors ? 'error' : 'success', '已提交 ' . $queued . ' 家店铺安装' . ( $skip ? '，' . $skip . ' 家已安装或在处理中' : '' ) . ( $errors ? '；没提交成功：' . implode( '；', array_slice( $errors, 0, 5 ) ) . ( count( $errors ) > 5 ? ' 等 ' . count( $errors ) . ' 家' : '' ) : '' ) . '。' );
			self::back( $return, 'plugins' );
		} elseif ( 'auto_on' === $do || 'auto_off' === $do ) {
			Store_Plugins::set_auto( $slug, 'auto_on' === $do );
			self::flash( 'success', 'auto_on' === $do ? '以后新开的店会自动安装这个插件。' : '已取消新店自动安装。' );
		} elseif ( 'delete' === $do ) {
			$res = Store_Plugins::delete( $slug );
			if ( true === $res ) {
				self::flash( 'success', '已从插件库删除。' );
			}
		}
		if ( is_wp_error( $res ) ) {
			self::flash( 'error', $res->get_error_message() );
		}
		self::back( $return, 'plugins' );
	}

	/** 设置某个店铺能不能使用某个应用；店铺确认后把最新状态写回总后台保存的店铺信息。 */
	public static function app_switch(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理应用。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_app_switch' );
		$site_id = absint( $_POST['site'] ?? 0 );
		$key     = sanitize_key( wp_unslash( $_POST['app'] ?? '' ) );
		$allowed = '1' === ( $_POST['allowed'] ?? '' );
		$site    = Sites::get( $site_id );
		if ( ! $site || '' === $key ) {
			self::flash( 'error', '店铺或应用不存在。' );
			self::back( $site_id, 'apps' );
		}
		$res = \PeptideHq\Client::app_access( $site_id, $key, $allowed );
		if ( is_wp_error( $res ) ) {
			self::flash( 'error', self::label( $site ) . '：' . self::friendly( $res ) );
			self::back( $site_id, 'apps' );
		}
		self::store_apps_saved( $site_id, $site, $res );
		$name = self::APP_NAMES[ $key ] ?? $key;
		self::flash( 'success', $allowed ? self::label( $site ) . ' 现在可以使用「' . $name . '」，开不开由店铺自己决定。' : self::label( $site ) . ' 已停用「' . $name . '」：已关闭，店铺后台看不到也改不了。' );
		self::back( $site_id, 'apps' );
	}

	/** 某个应用批量设为所有店铺可用 / 停用。连接异常、版本较旧、已是目标状态的店铺跳过；限时，超时提示再点一次。 */
	public static function app_bulk(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '无权管理应用。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_app_bulk' );
		$key     = sanitize_key( wp_unslash( $_POST['app'] ?? '' ) );
		$allowed = '1' === ( $_POST['allowed'] ?? '' );
		$return  = self::return_site();
		$start   = time();
		$done    = 0;
		$same    = 0;
		$skipped = array();
		$failed  = array();
		$left    = 0;
		foreach ( self::sites() as $site ) {
			$app = self::site_app( $site, $key );
			if ( null === $app ) {
				$skipped[] = self::label( $site ) . '（版本较旧或未同步）';
				continue;
			}
			if ( self::app_allowed( $app ) === $allowed ) {
				++$same;
				continue;
			}
			if ( self::unreachable( $site ) ) {
				$skipped[] = self::label( $site ) . '（连接异常）';
				continue;
			}
			if ( time() - $start > self::BULK_SECONDS ) {
				++$left;
				continue;
			}
			$res = \PeptideHq\Client::app_access( (int) $site['id'], $key, $allowed );
			if ( is_wp_error( $res ) ) {
				$failed[] = self::label( $site ) . '：' . self::friendly( $res );
				continue;
			}
			self::store_apps_saved( (int) $site['id'], $site, $res );
			++$done;
		}
		$name = self::APP_NAMES[ $key ] ?? $key;
		$msg  = '「' . $name . '」' . ( $allowed ? '设为可用' : '停用' ) . '：' . $done . ' 家已完成' . ( $same ? '，' . $same . ' 家本来就是' : '' );
		if ( $skipped ) {
			$msg .= '；跳过 ' . count( $skipped ) . ' 家：' . implode( '、', array_slice( $skipped, 0, 5 ) ) . ( count( $skipped ) > 5 ? ' 等' : '' );
		}
		if ( $failed ) {
			$msg .= '；没成功 ' . count( $failed ) . ' 家：' . implode( '；', array_slice( $failed, 0, 3 ) ) . ( count( $failed ) > 3 ? ' 等' : '' );
		}
		if ( $left ) {
			$msg .= '；还有 ' . $left . ' 家没来得及处理，请再点一次。';
		}
		$partial = $failed || $left || $skipped;
		self::flash( $partial ? 'error' : 'success', $msg . ( $failed || $left ? '' : '。' ) );
		self::back( $return, 'apps' );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// ---------- 页面 ----------

	/** 顶部：搜索并选择店铺（几十上百家也能快速找到）。 */
	private static function render_picker( array $sites, array $current ): void {
		echo '<section class="pcp-card pcp-picker"><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pcp-picker__form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label class="pcp-picker__label" for="pcp-site">当前店铺</label>';
		echo '<div class="pcp-picker__fields">';
		if ( count( $sites ) > 6 ) {
			echo '<input type="search" class="pcp-picker__search" placeholder="搜索店铺名或域名" aria-label="搜索店铺" autocomplete="off">';
		}
		echo '<select id="pcp-site" name="site" class="pcp-picker__select">';
		foreach ( $sites as $site ) {
			$text = self::label( $site ) . ( self::unnamed( $site ) ? '（未起名）' : ' — ' . self::host( $site ) ) . ( self::unreachable( $site ) ? '（连接异常）' : '' );
			echo '<option value="' . (int) $site['id'] . '"' . selected( (int) $site['id'], (int) $current['id'], false ) . ' data-search="' . esc_attr( strtolower( self::label( $site ) . ' ' . self::host( $site ) ) ) . '">' . esc_html( $text ) . '</option>';
		}
		echo '</select><noscript><button type="submit" class="button">切换</button></noscript></div>';
		echo '<div class="pcp-picker__meta">';
		echo self::unreachable( $current ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			? '<span class="pcp-state is-bad"><i></i>连接异常</span><a class="pcp-link" href="' . esc_url( Menu::action_url( 'pchq_site_refresh', array( 'site' => (int) $current['id'] ) ) ) . '">重新检查</a>'
			: '<span class="pcp-state is-ok"><i></i>连接正常</span>';
		echo '<a class="pcp-link" target="_blank" rel="noopener" href="' . esc_url( Menu::action_url( 'pchq_login', array( 'site' => (int) $current['id'] ) ) ) . '">进入店铺后台</a>';
		echo '</div></form>';
		// 改名：给店铺起专属名称（只改总后台里的名称，不改店铺网站标题）。
		echo '<details class="pcp-rename"' . ( self::unnamed( $current ) ? ' open' : '' ) . '><summary>' . ( self::unnamed( $current ) ? '这个店铺还没起名，给它起个专属名称' : '改名' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-rename__form">';
		wp_nonce_field( 'pchq_site_rename' );
		echo self::hidden( array( 'action' => 'pchq_site_rename', 'site' => (int) $current['id'], 'return' => 'plugins' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label class="screen-reader-text" for="pcp-rename-input">店铺名称</label><input id="pcp-rename-input" type="text" name="name" maxlength="190" required value="' . esc_attr( self::unnamed( $current ) ? '' : (string) $current['name'] ) . '" placeholder="例如：美国站 A · 投手小王"><button type="submit" class="button">保存名称</button>';
		echo '<span class="pcp-hint">只改总后台里的名称，方便区分；不改店铺网站标题。域名：' . esc_html( self::host( $current ) ) . '</span></form></details>';
		echo '</section>';
	}

	/** 店铺应用：当前店铺能不能用每个应用；每个应用显示全部店铺里几家可用，并可批量设置。 */
	private static function render_apps( array $sites, array $current ): void {
		$catalog = self::catalog( $sites );
		echo '<h2 class="pcp-section" id="apps">店铺应用</h2>';
		echo '<p class="pcp-note">决定这个店铺可以使用哪些内置应用。停用后店铺后台看不到这个应用，已开着的会被关掉；开通后由店铺自己决定开不开。以后新增的应用会自动出现在这里（默认可用）。</p>';
		if ( ! is_array( $current['info']['apps'] ?? null ) ) {
			echo '<div class="pcp-flash is-wait">还没有收到这个店铺的应用清单（店铺程序更新后会自动同步）。<a class="pcp-link" href="' . esc_url( Menu::action_url( 'pchq_site_refresh', array( 'site' => (int) $current['id'] ) ) ) . '">立即同步</a></div>';
		}
		if ( ! $catalog ) {
			return;
		}
		$locked = self::unreachable( $current );
		$total  = count( array_filter( $sites, static fn( array $s ): bool => is_array( $s['info']['apps'] ?? null ) ) );
		echo '<ul class="pcp-app-grid">';
		foreach ( $catalog as $key => $sample ) {
			$name    = self::app_name( $sample );
			$app     = self::site_app( $current, $key );
			$allowed = null !== $app && self::app_allowed( $app );
			$count   = 0;
			foreach ( $sites as $site ) {
				$a      = self::site_app( $site, $key );
				$count += null !== $a && self::app_allowed( $a ) ? 1 : 0;
			}
			echo '<li class="pcp-app' . ( $allowed ? ' is-on' : '' ) . '"><div class="pcp-app__top"><span class="pcp-avatar is-sm" aria-hidden="true">' . esc_html( self::initial( $name ) ) . '</span><strong>' . esc_html( $name ) . '</strong>';
			if ( null === $app ) {
				echo '<span class="pcp-hint">店铺版本较旧</span>';
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline pcp-switch-form"' . ( $allowed ? ' data-confirm="' . esc_attr( '确定让 ' . self::label( $current ) . ' 停用「' . $name . '」？如果店铺正开着它，会被关掉。' ) . '"' : '' ) . '>';
				wp_nonce_field( 'pchq_app_switch' );
				echo self::hidden( array( 'action' => 'pchq_app_switch', 'site' => (int) $current['id'], 'app' => $key, 'allowed' => $allowed ? '0' : '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<span class="pcp-switch__text">' . ( $allowed ? '可用' : '不可用' ) . '</span>';
				echo '<button type="submit" class="pcp-switch" role="switch" aria-checked="' . ( $allowed ? 'true' : 'false' ) . '" aria-label="' . esc_attr( self::label( $current ) . ' 可以使用' . $name ) . '"' . ( $locked ? ' disabled title="店铺连接异常，恢复后才能修改"' : '' ) . '><span></span></button></form>';
			}
			echo '</div>';
			if ( '' !== self::app_text( $sample ) ) {
				echo '<p>' . esc_html( self::app_text( $sample ) ) . '</p>';
			}
			echo '<div class="pcp-app__foot"><span class="pcp-hint">全部店铺：' . (int) $count . ' / ' . (int) $total . ' 家可用</span>';
			if ( $total > 1 ) {
				echo '<span class="pcp-bulk">';
				foreach ( array( '1' => '全部可用', '0' => '全部停用' ) as $value => $text ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-inline" data-confirm="' . esc_attr( '确定把「' . $name . '」设为所有店铺' . ( '1' === $value ? '可用？店铺自己决定开不开。' : '停用？正开着的店铺会被关掉。' ) ) . '">';
					wp_nonce_field( 'pchq_app_bulk' );
					echo self::hidden( array( 'action' => 'pchq_app_bulk', 'app' => $key, 'allowed' => $value, 'return_site' => (int) $current['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<button type="submit" class="pcp-link' . ( '0' === $value ? ' is-danger' : '' ) . '">' . esc_html( $text ) . '</button></form>';
				}
				echo '</span>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
	}

	/** 第三方插件：上传；每个插件显示当前店铺的状态、全部店铺里装了几家，可一键装到所有店铺。返回是否有处理中的任务。 */
	private static function render_plugins( array $sites, array $current, array $library, array $assign ): bool {
		$pending = false;
		$cid     = (int) $current['id'];
		echo '<h2 class="pcp-section" id="plugins">第三方插件 <span>' . count( $library ) . '</span></h2>';
		echo '<section class="pcp-card pcp-upload"><div class="pcp-card__head"><h2>上传插件</h2><span class="pcp-hint">.zip · 最大 32 MB</span></div>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pcp-upload__form" data-max="' . (int) ( 32 * 1024 * 1024 ) . '">';
		wp_nonce_field( 'pchq_plugin_upload' );
		echo self::hidden( array( 'action' => 'pchq_plugin_upload', 'return_site' => $cid ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label class="pcp-drop" for="pcp-file"><input id="pcp-file" type="file" name="plugin_zip" accept=".zip,application/zip" required>';
		echo '<span class="pcp-drop__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></span>';
		echo '<span class="pcp-drop__text"><strong data-empty="把插件 .zip 拖到这里，或点击选择文件">把插件 .zip 拖到这里，或点击选择文件</strong><small data-empty="请上传插件作者提供的原始压缩包">请上传插件作者提供的原始压缩包</small></span></label>';
		echo '<div class="pcp-upload__bar"><p class="pcp-hint">同一个插件再次上传会保存为新版本，之后点“更新”使用最新版本。只用正版、可信来源的插件。</p><button type="submit" class="button button-primary pcp-upload__btn" disabled>上传到插件库</button></div>';
		echo '</form></section>';

		if ( ! $library ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有上传第三方插件</strong><p>把购买的插件 .zip 上传后会在这里显示，再选择装到哪些店铺。</p></section>';
		}
		foreach ( $library as $p ) {
			$slug   = (string) $p['slug'];
			$used   = 0;
			$active = 0;
			foreach ( $assign[ $slug ] ?? array() as $row ) {
				$used    += 'removed' !== $row['state'] ? 1 : 0;
				$active  += 'active' === $row['state'] ? 1 : 0;
				$pending  = $pending || in_array( $row['state'], array( 'installing', 'removing' ), true );
			}
			$meta = array( $slug );
			if ( '' !== (string) $p['version'] ) {
				$meta[] = '版本 ' . $p['version'];
			}
			$meta[] = size_format( (int) $p['size'] );
			if ( (int) $p['versions'] > 1 ) {
				$meta[] = '共 ' . (int) $p['versions'] . ' 个版本';
			}
			echo '<section class="pcp-card pcp-plugin"><div class="pcp-plugin__head">';
			echo '<span class="pcp-avatar" aria-hidden="true">' . esc_html( self::initial( (string) $p['name'] ) ) . '</span>';
			echo '<div class="pcp-plugin__title"><h3>' . esc_html( $p['name'] ) . '</h3><p>' . esc_html( implode( ' · ', $meta ) . ' · 已装 ' . $active . ' / ' . count( $sites ) . ' 家' ) . '</p></div>';
			echo '<div class="pcp-plugin__tools">';
			echo (int) $p['auto_new']
				? '<span class="pcp-tag is-on">新店自动安装</span>' . self::button( $slug, 'auto_off', '取消', 0, 'pcp-link', '', $cid ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: self::button( $slug, 'auto_on', '设为新店自动安装', 0, 'pcp-link', '', $cid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( count( $sites ) > 1 && $active < count( $sites ) ) {
				echo self::button( $slug, 'install_all', '装到所有店铺', 0, 'pcp-link', '确定把「' . $p['name'] . '」装到所有还没装的店铺？每家装完都会自动检查首页和购物车。', $cid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( ! $used ) {
				echo self::button( $slug, 'delete', '删除', 0, 'pcp-link is-danger', '确定从插件库删除这个插件的所有版本？', $cid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></div>';
			$row     = $assign[ $slug ][ $cid ] ?? null;
			$state   = $row['state'] ?? 'removed';
			$actions = '';
			if ( ! in_array( $state, array( 'installing', 'removing' ), true ) ) {
				$installed = in_array( $state, array( 'active', 'failed' ), true );
				$newer     = $installed && 'active' === $state && (string) $row['installed_version'] !== (string) $p['version'];
				$actions  .= self::button( $slug, 'install', $installed ? ( $newer ? '更新到 ' . $p['version'] : '重新安装' ) : '安装到这个店铺', $cid, $installed && ! $newer ? 'button' : 'button button-primary', '', $cid );
				if ( $installed ) {
					$actions .= self::button( $slug, 'uninstall', '卸载', $cid, 'button pcp-btn-quiet', '确定在这个店铺停用并卸载这个插件？', $cid );
				}
			}
			echo '<ul class="pcp-stores"><li><div class="pcp-store"><strong>' . esc_html( self::label( $current ) ) . '</strong><small>' . esc_html( self::host( $current ) ) . '</small></div><div class="pcp-store__state">' . self::state_label( $row ) . '</div><div class="pcp-store__actions">' . $actions . '</div></li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</section>';
		}
		return $pending;
	}

	/** 当前店铺的系统插件（只读，折叠）。 */
	private static function render_builtin( array $current, array $library ): void {
		$names = array();
		foreach ( $library as $p ) {
			$names[ strtolower( (string) $p['name'] ) ] = true;
		}
		$info    = is_array( $current['info'] ?? null ) ? $current['info'] : array();
		$plugins = is_array( $info['plugins'] ?? null ) ? $info['plugins'] : array();
		$when    = isset( $info['time'] ) && is_numeric( $info['time'] ) ? self::ago( (int) $info['time'] ) . '同步' : '';
		echo '<details class="pcp-details"><summary>店铺系统插件<span>只读</span></summary>';
		echo '<p class="pcp-note">商城、支付、发信等店铺运行的底座，开新店时自带，随平台版本统一更新。为避免店铺停摆，这里只显示、不提供停用。' . ( '' !== $when ? '（' . esc_html( $when ) . '）' : '' ) . '</p>';
		if ( ! $plugins ) {
			echo '<p class="pcp-note">还没有收到这个店铺的插件信息，稍后刷新。</p></details>';
			return;
		}
		echo '<ul class="pcp-chips">';
		foreach ( $plugins as $plugin ) {
			$name = (string) ( $plugin['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$from = isset( $names[ strtolower( $name ) ] );
			echo '<li><span class="pcp-avatar is-sm" aria-hidden="true">' . esc_html( self::initial( $name ) ) . '</span><span><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( '' !== (string) ( $plugin['version'] ?? '' ) ? '版本 ' . $plugin['version'] : '' ) . '</small></span>' . ( $from ? '<em class="pcp-tag">插件库</em>' : '<em class="pcp-tag is-core">自带</em>' ) . '</li>';
		}
		echo '</ul></details>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		Store_Plugins::sync();
		$flash = get_transient( 'pchq_plugins_flash_' . get_current_user_id() );
		delete_transient( 'pchq_plugins_flash_' . get_current_user_id() );
		$library = Store_Plugins::library();
		$assign  = Store_Plugins::assignments();
		$sites   = self::sites();
		$want    = absint( $_GET['site'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读选择。
		$current = $sites[0] ?? null;
		foreach ( $sites as $site ) {
			if ( (int) $site['id'] === $want ) {
				$current = $site;
			}
		}

		echo '<div class="wrap pchq pchq-plugins pcp">';
		echo '<header class="pcp-head"><div><h1>插件管理</h1><p>管理每个店铺能使用哪些内置应用，以及上传你购买的第三方插件并装到店铺。先在下面选择店铺。</p></div>';
		echo '<dl class="pcp-stats"><div><dt>店铺</dt><dd>' . count( $sites ) . '</dd></div><div><dt>店铺应用</dt><dd>' . count( self::catalog( $sites ) ) . '</dd></div><div><dt>第三方插件</dt><dd>' . count( $library ) . '</dd></div></dl></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Store_Plugins::ready() ) {
			echo '<div class="pcp-flash is-wait">插件服务还没准备好（后台任务尚未创建队列目录）。请一分钟后刷新；如果一直这样，请联系技术人员。</div>';
		}
		if ( null === $current ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有店铺</strong><p>开好店铺后会在这里显示。</p></section></div>';
			return;
		}
		self::render_picker( $sites, $current );
		self::render_apps( $sites, $current );
		$pending = self::render_plugins( $sites, $current, $library, $assign );
		self::render_builtin( $current, $library );
		if ( $pending ) {
			// 有任务在处理时每 15 秒刷新一次页面，直到出结果。
			echo '<p class="pcp-note pcp-live">有插件正在处理，页面会自动刷新。</p><script>setTimeout(function(){location.reload();},15000);</script>';
		}
		echo '</div>';
	}
}
