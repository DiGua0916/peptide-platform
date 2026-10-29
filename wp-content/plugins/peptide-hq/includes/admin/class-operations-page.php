<?php
namespace PeptideHq\Admin;
use PeptideHq\Operations;
use PeptideHq\Sites;
use PeptideHq\Backup_Schedules;
use PeptideOps\Storage;
use PeptideOps\Runtime;
defined( 'ABSPATH' ) || exit;

final class Operations_Page {
	public const SLUG = 'pchq-operations';
	private const TABS = array( 'tasks' => '任务记录', 'releases' => '升级与回滚', 'create' => '创建新店', 'backups' => '备份与恢复', 'agents' => '部署环境' );
	public static function hooks(): void {
		add_action( 'admin_menu', static function (): void { add_submenu_page( Dashboard_Page::SLUG, '站点运维', '站点运维', Menu::CAP, self::SLUG, array( self::class, 'render' ) ); }, 20 );
		add_action( 'admin_post_pchq_ops', array( self::class, 'handle' ) );
		add_action( 'wp_ajax_pchq_ops_status', array( self::class, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}
	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { return; }
		wp_enqueue_style( 'pchq-operations', plugins_url( 'assets/hq-operations.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION );
		wp_enqueue_script( 'pchq-operations', plugins_url( 'assets/hq-operations.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION, true );
		wp_localize_script( 'pchq-operations', 'pchqOps', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'pchq_ops_status' ) ) );
	}
	public static function url( string $tab = 'tasks', int $site = 0 ): string { return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab, 'site' => $site ), admin_url( 'admin.php' ) ); }
	private static function form( string $operation, int $site = 0, string $tab = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ops-form" enctype="multipart/form-data">';
		wp_nonce_field( 'pchq_ops' );
		foreach ( array( 'action' => 'pchq_ops', 'operation' => $operation, 'site' => $site, 'tab' => $tab ?: 'tasks' ) as $name => $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
	}
	private static function hidden( string $name, string $value ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权访问。', '', array( 'response' => 403 ) ); }
		$tab = sanitize_key( $_GET['tab'] ?? 'tasks' ); if ( ! isset( self::TABS[$tab] ) ) { $tab = 'tasks'; }
		$sites = Sites::all(); $site = (int) ( $_GET['site'] ?? 0 );
		if ( ! $site && in_array( $tab, array( 'backups', 'releases' ), true ) && $sites ) { $site = (int) array_key_first( $sites ); }
		echo '<div class="wrap pchq ops" data-tab="' . esc_attr( $tab ) . '" data-site="' . esc_attr( $site ) . '"><div class="ops-title"><div><h1>站点运维</h1><p>集中管理版本、新店与恢复点。</p></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=pchq-sites' ) ) . '">返回店铺</a></div>';
		Menu::notices();
		if ( ! Operations::ready() ) { echo '<div class="notice notice-error"><p>运维执行器未配置，任务不会执行。请完成服务器部署配置。</p></div></div>'; return; }
		echo '<nav class="ops-tabs" aria-label="运维功能">'; foreach ( self::TABS as $key => $label ) { echo '<a ' . ( $tab === $key ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( self::url( $key, $site ) ) . '">' . esc_html( $label ) . '</a>'; } echo '</nav><p class="ops-live" role="status" aria-live="polite"></p>';
		if ( in_array( $tab, array( 'releases', 'backups' ), true ) ) {
			echo '<div class="ops-toolbar"><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label for="ops-site">店铺</label><select name="site" id="ops-site">';
			foreach ( $sites as $id => $s ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $site, $id, false ) . '>' . esc_html( $s['name'] ) . '</option>'; }
			echo '</select><button class="button">切换店铺</button></form><button type="button" class="button ops-refresh" data-site="' . esc_attr( $site ) . '">刷新店铺状态</button></div>';
		}
		$inventory = get_transient( 'pchq_ops_inventory_' . $site );
		if ( 'tasks' === $tab ) { self::tasks(); }
		elseif ( 'releases' === $tab ) { self::releases( $site, $sites, is_array( $inventory ) ? $inventory : array() ); }
		elseif ( 'backups' === $tab ) { self::backups( $site, $inventory ?: array() ); }
		elseif ( 'create' === $tab ) { self::create( $site ); }
		else { self::agents(); }
		echo '</div>';
	}
	private static function tasks(): void {
		$jobs = Operations::jobs(); echo '<section class="ops-panel"><div class="ops-panel-head"><h2>执行记录</h2><span>后台运行，关闭页面后继续执行</span></div><div id="ops-jobs" data-poll="' . ( count( array_filter( $jobs, static fn( $j ) => in_array( $j['status'], array( 'queued', 'waiting', 'running' ), true ) ) ) ? '1' : '0' ) . '">'; self::jobs_markup( $jobs ); echo '</div></section>';
	}
	public static function jobs_markup( array $jobs ): void {
		if ( ! $jobs ) { echo '<div class="ops-empty">还没有运维任务。可以先为店铺创建一份完整备份。</div>'; return; }
		$names = array( 'backup.create' => '创建备份', 'backup.restore' => '恢复站点', 'backup.delete' => '删除备份', 'release.apply' => '升级版本', 'release.rollback' => '回滚文件', 'site.provision' => '创建新店' );
		$states = array( 'queued' => '排队中', 'waiting' => '等待其他任务', 'running' => '执行中', 'succeeded' => '已完成', 'failed' => '未完成' );
		foreach ( $jobs as $j ) {
			echo '<article class="ops-job" data-job="' . esc_attr( $j['id'] ) . '"><div class="ops-job-top"><div><strong>' . esc_html( $names[$j['task']] ?? $j['task'] ) . '</strong><span>' . esc_html( $j['label'] ) . '</span></div><span class="ops-state">' . esc_html( $states[$j['status']] ?? $j['status'] ) . '</span></div><progress max="100" value="' . esc_attr( $j['progress'] ) . '">' . esc_html( $j['progress'] ) . '%</progress><p>' . esc_html( $j['message'] ) . '</p><div class="ops-job-meta"><time>' . esc_html( wp_date( 'm-d H:i:s', $j['created'] ) ) . '</time><code>' . esc_html( $j['id'] ) . '</code></div>';
			if ( ! empty( $j['result']['url'] ) ) { echo '<p><a href="' . esc_url( $j['result']['url'] ) . '" target="_blank" rel="noopener">打开新店</a> · 可在店铺列表一键登录</p>'; }
			if ( ! empty( $j['result']['rollback_id'] ) ) { echo '<p>保留恢复点：<code>' . esc_html( $j['result']['rollback_id'] ) . '</code></p>'; }
			if ( ! empty( $j['result']['manual_recovery_required'] ) ) { echo '<p>需要管理员通过服务器恢复点检查恢复，已停止自动重试。</p>'; }
			foreach ( $j['result']['warnings'] ?? array() as $warning ) { echo '<p class="ops-note">' . esc_html( $warning ) . '</p>'; }
			if ( ! empty( $j['log'] ) ) { echo '<details><summary>查看执行阶段</summary><ol class="ops-log">'; foreach ( $j['log'] as $l ) { echo '<li><time>' . esc_html( wp_date( 'H:i:s', $l['time'] ) ) . '</time> ' . esc_html( $l['message'] ) . '</li>'; } echo '</ol></details>'; }
			if ( 'failed' === $j['status'] && empty( $j['result']['manual_recovery_required'] ) ) { self::form( 'retry' ); self::hidden( 'job_id', $j['id'] ); echo '<button class="button">查询原任务并重试</button></form>'; }
			echo '</article>';
		}
	}
	private static function releases( int $site, array $sites, array $inv ): void {
		echo '<div class="ops-columns"><section class="ops-panel"><h2>准备升级包</h2><p>上传有版本号的插件或主题 ZIP，系统会核对目录、兼容性与完整性。</p>';
		self::form( 'upload', $site, 'releases' ); echo '<label for="ops-package">升级包</label><input id="ops-package" type="file" name="package" accept=".zip" required><button class="button">上传并检查</button></form>';
		echo '<hr><h2>部署版本</h2>'; self::form( 'release.apply', $site, 'releases' ); echo '<label for="ops-package-id">已检查的升级包</label><select id="ops-package-id" name="package_id" required><option value="">请选择</option>';
		foreach ( glob( Storage::path( 'packages/*.json' ) ) ?: array() as $f ) { $p = json_decode( file_get_contents( $f ), true ); if ( empty( $p['inspect'] ) ) { continue; } $m = $p['inspect']; echo '<option value="' . esc_attr( $p['sha256'] ) . '">' . esc_html( $m['name'] . ' · ' . $m['version'] . ' · ' . $m['slug'] ) . '</option>'; }
		echo '</select><fieldset><legend>升级店铺</legend><div class="ops-checks">'; foreach ( $sites as $id => $s ) { echo '<label><input type="checkbox" name="sites[]" value="' . esc_attr( $id ) . '" ' . checked( $site, $id, false ) . '>' . esc_html( $s['name'] ) . '</label>'; } echo '</div></fieldset><label class="ops-check"><input type="checkbox" required name="confirm" value="1">先创建完整备份，再替换所选组件文件</label><button class="button button-primary">开始升级</button></form></section><section class="ops-panel"><h2>当前版本与回滚点</h2>';
		if ( ! $inv ) { echo '<p>点击“刷新店铺状态”读取当前版本与回滚记录。</p>'; }
		foreach ( $inv['releases'] ?? array() as $item ) { if ( ! is_array( $item ) ) { continue; } echo '<div class="ops-version"><strong>' . esc_html( $item['name'] ?? $item['slug'] ?? '' ) . '</strong><span>' . esc_html( $item['version'] ?? '' ) . ( empty( $item['eligible'] ) ? ' · ' . esc_html( $item['reason'] ?? '受保护' ) : '' ) . '</span></div>'; }
		foreach ( $inv['release_history'] ?? array() as $r ) { echo '<div class="ops-snapshot"><strong>' . esc_html( $r['name'] ) . '</strong><p>' . esc_html( $r['from_version'] . ' → ' . $r['to_version'] . ' · ' . $r['status'] ) . '</p>'; if ( ! empty( $r['can_rollback'] ) ) { self::form( 'release.rollback', $site, 'releases' ); self::hidden( 'release_id', $r['id'] ); echo '<label class="ops-check"><input type="checkbox" name="confirm" value="1" required>确认回滚此组件文件</label><button class="button">回滚到 ' . esc_html( $r['from_version'] ) . '</button></form>'; } echo '</div>'; }
		echo '<p class="ops-note">文件回滚只恢复该组件文件。插件若自行更新数据库，请使用升级前整站备份恢复。服务器断电或任务被强制终止时，可能需要通过服务器命令行恢复。</p></section></div>';
	}
	private static function backups( int $site, array $inv ): void {
		$s = Backup_Schedules::settings( $site );
		echo '<div class="ops-columns"><section class="ops-panel"><h2>完整备份</h2><p>保存站点文件、上传资料和该站数据库，存放在公开网站目录外。</p>'; self::form( 'backup.create', $site ); echo '<label for="ops-backup-label">备份备注</label><input id="ops-backup-label" name="label" maxlength="100" placeholder="例如：上新品前"><button class="button button-primary">立即备份</button></form><hr><h2>定时备份</h2>'; self::form( 'schedule', $site, 'backups' );
		echo '<label class="ops-check"><input name="enabled" type="checkbox" value="1" ' . checked( ! empty( $s['enabled'] ), true, false ) . '>每天自动备份</label><div class="ops-field-row"><label>执行时间<input name="time" type="time" value="' . esc_attr( $s['time'] ?? '03:00' ) . '" required></label><label>时区<select name="timezone">'; foreach ( array( 'Asia/Shanghai' => '北京时间', 'UTC' => 'UTC', 'America/New_York' => '美国东部', 'America/Los_Angeles' => '美国西部' ) as $tz => $label ) { echo '<option value="' . esc_attr( $tz ) . '" ' . selected( $s['timezone'] ?? 'Asia/Shanghai', $tz, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label></div><label>保留最近几份定时备份<input name="retention" type="number" min="2" max="30" value="' . esc_attr( $s['retention'] ?? 7 ) . '"></label><p>手动备份和升级、恢复前的保护备份不会自动清理。</p><button class="button">保存备份计划</button></form><p class="ops-note">下一次：' . esc_html( $s['next_due_local'] ?? '尚未启用' ) . '。定时任务需要管理后台服务器持续运行；正式服务器应配置每5分钟执行 WordPress cron。</p></section><section class="ops-panel"><h2>可用恢复点</h2>';
		if ( empty( $inv['backups'] ) ) { echo '<p>暂无已读取的备份。备份完成后刷新店铺状态。</p>'; }
		foreach ( $inv['backups'] ?? array() as $b ) {
			echo '<div class="ops-snapshot"><strong>' . esc_html( $b['label'] ?: '站点备份' ) . '</strong><p>' . esc_html( $b['created_at'] ?? '' ) . ' · ' . esc_html( size_format( $b['bytes'] ?? 0 ) ) . '</p><code>' . esc_html( $b['id'] ) . '</code>';
			self::form( 'download', $site, 'backups' ); self::hidden( 'backup_id', $b['id'] ); echo '<button class="button">下载完整备份</button></form><details><summary>恢复到此版本</summary><p>恢复会覆盖当前文件与数据库；执行前另建完整恢复点，保留当前后台连接。</p>'; self::form( 'backup.restore', $site ); self::hidden( 'backup_id', $b['id'] ); echo '<label>输入站点编号确认：<code>' . esc_html( $inv['status']['site_uuid'] ?? '' ) . '</code><input name="confirm_uuid" autocomplete="off" required></label><button class="button">备份当前状态并恢复</button></form></details></div>';
		}
		echo '</section></div>';
	}
	private static function create( int $site ): void {
		$target = $site < 0 ? $site : 0; $inv = 0 === $target ? Runtime::inventory() : get_transient( 'pchq_ops_inventory_' . $target );
		echo '<div class="ops-columns"><section class="ops-panel"><h2>创建独立店铺</h2><p>安装空白店铺、独立数据库与账号，并自动接入管理后台。</p><form method="get" class="ops-form"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="create"><label>部署服务器<select name="site">'; foreach ( Operations::agents() as $id => $a ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $target, $id, false ) . '>' . esc_html( $a['label'] ) . '</option>'; } echo '</select></label><button class="button">选择服务器</button></form>'; if ( $target < 0 ) { echo '<button type="button" class="button ops-refresh" data-site="' . esc_attr( $target ) . '">刷新部署环境</button>'; }
		self::form( 'site.provision', $target ); echo '<label>部署环境<select name="profile_id" required><option value="">请选择</option>'; foreach ( $inv['profiles'] ?? array() as $p ) { if ( 'qa' === $p['id'] || empty( $p['enabled'] ) ) { continue; } echo '<option value="' . esc_attr( $p['id'] ) . '">' . esc_html( $p['label'] . ' · ' . $p['environment'] ) . '</option>'; } echo '</select></label><label>店铺名称<input name="title" maxlength="120" required></label><label>店铺标识<input name="slug" pattern="[a-z][a-z0-9-]{2,47}" placeholder="例如：vialcove-us" required></label><label>网址<input name="url" type="url" placeholder="本机环境可留空自动分配"></label><div class="ops-field-row"><label>管理员账号<input name="admin_login" value="store_admin" pattern="[A-Za-z][A-Za-z0-9_.-]{2,47}" required></label><label>管理员邮箱<input name="admin_email" type="email" required></label></div><button class="button button-primary">检查环境并创建新店</button></form></section><aside class="ops-panel"><h2>创建后包含什么</h2><ul><li>WordPress、WooCommerce、店铺组件和主题</li><li>独立数据库、随机登录凭据与站点编号</li><li>管理后台接入和一键登录</li></ul><p>新店不复制旧店的用户、商品、订单或图片。支付、邮件服务和搜索平台保持未配置。</p><hr><h2>正式服务器</h2><p>先在“部署环境”连接服务器代理并配置受控环境。域名解析、HTTPS和服务器映射需要在对应服务器准备好，未配置时会停止创建。</p><p>本机环境用于本地运行，不会自动公开到互联网。</p></aside></div>';
	}
	private static function agents(): void {
		echo '<div class="ops-columns"><section class="ops-panel"><h2>连接部署服务器</h2><p>服务器安装运维代理、配置私有目录和受控部署环境后，在这里验证连接。</p>'; self::form( 'agent', 0, 'agents' ); echo '<label>服务器名称<input name="label" required></label><label>代理网址<input type="url" name="url" placeholder="https://manager.example.com" required></label><label>代理连接密钥<input type="password" name="key" autocomplete="new-password" required></label><button class="button button-primary">验证并保存</button></form></section><section class="ops-panel"><h2>已连接环境</h2>'; foreach ( Operations::agents() as $a ) { echo '<div class="ops-version"><strong>' . esc_html( $a['label'] ) . '</strong><span>' . esc_html( $a['url'] ) . '</span></div>'; } echo '<p class="ops-note">服务器工具、目录、数据库管理连接和域名白名单只保存在服务器私有配置，不通过页面填写执行命令。</p></section></div>';
	}
	public static function ajax(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_send_json_error( array( 'message' => '无权访问。' ), 403 ); }
		check_ajax_referer( 'pchq_ops_status', 'nonce' );
		if ( ! Operations::ready() ) { wp_send_json_error( array( 'message' => '运维执行器未配置，请先完成服务器配置。' ), 503 ); }
		if ( 'inventory' === ( $_POST['kind'] ?? '' ) ) { $site = (int) ( $_POST['site'] ?? 0 ); $v = Operations::inventory( $site ); if ( is_wp_error( $v ) ) { wp_send_json_error( array( 'message' => $v->get_error_message() ), 400 ); } set_transient( 'pchq_ops_inventory_' . $site, $v, 600 ); wp_send_json_success( array( 'reload' => true ) ); }
		$jobs = Operations::jobs(); ob_start(); self::jobs_markup( $jobs ); $html = ob_get_clean(); wp_send_json_success( array( 'html' => $html, 'active' => count( array_filter( $jobs, static fn( $j ) => in_array( $j['status'], array( 'queued', 'waiting', 'running' ), true ) ) ) > 0 ) );
	}
	public static function handle(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权执行。', '', array( 'response' => 403 ) ); } check_admin_referer( 'pchq_ops' );
		$in = wp_unslash( $_POST ); $op = sanitize_text_field( $in['operation'] ?? '' ); $site = (int) ( $in['site'] ?? 0 ); $tab = sanitize_key( $in['tab'] ?? 'tasks' ); $result = null;
		if ( 'upload' === $op ) { $result = Operations::package_upload( $_FILES['package'] ?? array() ); }
		elseif ( 'agent' === $op ) { $result = Operations::save_agent( $in ); }
		elseif ( 'schedule' === $op ) { $in['enabled'] = ! empty( $in['enabled'] ); $result = Backup_Schedules::save( $site, $in ); }
		elseif ( 'retry' === $op ) { $result = Runtime::retry( sanitize_text_field( $in['job_id'] ?? '' ) ); }
		elseif ( 'download' === $op ) {
			$result = Operations::request( $site, 'POST', '/download-ticket', array( 'backup_id' => sanitize_text_field( $in['backup_id'] ?? '' ) ) );
			if ( ! is_wp_error( $result ) ) { $t = Operations::target( $site ); $url = $result['url'] ?? ''; if ( ! is_wp_error( $t ) && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $t['url'], PHP_URL_HOST ) && wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $t['url'], PHP_URL_PORT ) && wp_parse_url( $url, PHP_URL_SCHEME ) === wp_parse_url( $t['url'], PHP_URL_SCHEME ) && false !== strpos( $url, 'peptide-ops' ) ) { wp_redirect( $url ); exit; } $result = new \WP_Error( 'pchq_ops_download', '下载地址验证失败。' ); }
		}
		elseif ( 'release.apply' === $op ) {
			if ( empty( $in['confirm'] ) ) { $result = new \WP_Error( 'pchq_ops_confirm', '请确认升级备份。' ); }
			else { $pkg = sanitize_text_field( $in['package_id'] ?? '' ); $m = \PeptideOps\Releases::inspect( $pkg, false );
				if ( is_wp_error( $m ) ) { $result = $m; } else { $ids = array_unique( array_map( 'absint', (array) ( $in['sites'] ?? array() ) ) ); $result = $ids ? true : new \WP_Error( 'pchq_ops_sites', '请选择升级店铺。' ); foreach ( $ids as $id ) { $r = Operations::enqueue( $id, $op, array( 'package_id' => $pkg, 'type' => $m['type'], 'slug' => $m['slug'] ) ); if ( is_wp_error( $r ) ) { $result = $r; break; } } $tab = 'tasks'; }
			}
		}
		elseif ( 'release.rollback' === $op ) { $result = empty( $in['confirm'] ) ? new \WP_Error( 'pchq_ops_confirm', '请确认文件回滚。' ) : Operations::enqueue( $site, $op, array( 'rollback_id' => sanitize_text_field( $in['release_id'] ?? '' ) ) ); $tab = 'tasks'; }
		elseif ( 'backup.create' === $op ) { $result = Operations::enqueue( $site, $op, array( 'reason' => 'manual', 'label' => sanitize_text_field( $in['label'] ?? '' ) ) ); $tab = 'tasks'; }
		elseif ( 'backup.restore' === $op ) { $t = Operations::target( $site ); $confirm = trim( $in['confirm_uuid'] ?? '' ); $result = is_wp_error( $t ) || $confirm !== $t['uuid'] ? new \WP_Error( 'pchq_ops_confirm', '输入的站点编号不匹配。' ) : Operations::enqueue( $site, $op, array( 'backup_id' => sanitize_text_field( $in['backup_id'] ?? '' ), 'confirm_uuid' => $confirm ) ); $tab = 'tasks'; }
		elseif ( 'site.provision' === $op ) { $data = array_intersect_key( $in, array_flip( array( 'profile_id', 'slug', 'title', 'url', 'admin_login', 'admin_email' ) ) ); $data = array_map( 'sanitize_text_field', $data ); $result = Operations::enqueue( $site, $op, $data ); $tab = 'tasks'; }
		else { $result = new \WP_Error( 'pchq_ops_action', '不支持的操作。' ); }
		Menu::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : ( in_array( $op, array( 'upload', 'schedule', 'agent' ), true ) ? '已保存并通过检查。' : '任务已提交，可查看执行进度。' ) );
		wp_safe_redirect( self::url( isset( self::TABS[$tab] ) ? $tab : 'tasks', $site ) ); exit;
	}
}
