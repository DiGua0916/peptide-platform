<?php
namespace PeptideHq\Admin;
defined( 'ABSPATH' ) || exit;

/** Submit bounded requests to a separate privileged worker, never execute commands in PHP. */
final class Provision_Page {
	public const SLUG = 'pchq-create-store';
	public static function hooks(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page( 'pchq', '自动开店', '自动开店', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
		} );
		add_action( 'admin_post_pchq_create_store', array( self::class, 'submit' ) );
		add_action( 'wp_ajax_pchq_create_store_status', array( self::class, 'status' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}
	public static function assets(): void {
		if ( self::SLUG === ( $_GET['page'] ?? '' ) ) { self::workspace_assets(); }
	}
	/** Safe to enqueue on every supported workspace route; mounts only a progress root. */
	public static function workspace_assets(): void {
		wp_enqueue_script( 'pchq-provision', plugins_url( 'assets/hq-provision.js', PEPTIDE_HQ_FILE ), array(), (string) filemtime( dirname( PEPTIDE_HQ_FILE ) . '/assets/hq-provision.js' ), true );
	}
	private static function root(): string {
		return defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/' ) : '';
	}
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id );
	}
	/** ASCII DNS name supplied by the customer; no registrable-domain guessing or DNS API. */
	public static function customer_domain( string $value ): string {
		if ( preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { return ''; }
		$host = strtolower( trim( $value ) );
		if ( str_starts_with( $host, 'www.' ) ) { $host = substr( $host, 4 ); }
		if ( strlen( $host ) > 249 || str_starts_with( $host, 'www.' ) || ! preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)\z/D', $host ) || filter_var( $host, FILTER_VALIDATE_IP ) ) { return ''; }
		return $host;
	}
	public static function submit(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权开店。', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'pchq_create_store' );
		$id = (string) wp_unslash( $_POST['operation_id'] ?? '' );
		$root = self::root();
		if ( ! self::valid_id( $id ) || '' === $root || ! is_dir( $root . '/requests' ) || ! is_writable( $root . '/requests' ) ) { wp_die( '开店服务尚未配置或请求编号无效。' ); }
		// A completed v1 result is immutable, including retries from a previously opened form.
		if ( 'complete' === ( self::result( $id )['state'] ?? '' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&job=' . rawurlencode( $id ) ) ); exit;
		}
		$title = sanitize_text_field( wp_unslash( $_POST['store_title'] ?? '' ) );
		$login = (string) wp_unslash( $_POST['owner_login'] ?? '' );
		$email = sanitize_email( wp_unslash( $_POST['owner_email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['owner_password'] ?? '' );
		$domain = self::customer_domain( is_string( $_POST['customer_domain'] ?? null ) ? wp_unslash( $_POST['customer_domain'] ) : '' );
		if ( '' === $title || mb_strlen( $title ) > 100 || ! preg_match( '/^[a-z][a-z0-9_]{2,31}$/D', $login ) || ! is_email( $email ) || strlen( $password ) < 12 || strlen( $password ) > 128 || preg_match( '/[\x00-\x1f\x7f]/', $password ) || '' === $domain ) {
			wp_die( '请填写客户根域名（例如 example.com 或 www.example.com）、店名、有效邮箱、3至32位小写字母/数字/下划线账号，以及至少12位密码。域名请使用 ASCII 或 Punycode，不要填写网址路径。' );
		}
		$body = wp_json_encode( array( 'version' => 2, 'operation_id' => $id, 'actor_id' => get_current_user_id(), 'title' => $title, 'login' => $login, 'email' => $email, 'password' => $password, 'customer_domain' => $domain ), JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $body ) || strlen( $body ) > 4096 ) { wp_die( '开店请求无效。' ); }
		// Exclusive creation makes browser retries idempotent. Passwords never enter URLs/results.
		if ( ! is_file( $root . '/results/' . $id . '.json' ) ) {
			$handle = @fopen( $root . '/requests/' . $id . '.json', 'x' );
			if ( $handle ) {
				chmod( $root . '/requests/' . $id . '.json', 0600 );
				$written = fwrite( $handle, $body ); fflush( $handle ); fclose( $handle );
				if ( strlen( $body ) !== $written ) { wp_die( '提交失败，请联系管理员检查。' ); }
			}
		}
		// CDN 自动配置：开店服务写出解析记录后尽快开始，不用等 5 分钟的定时检查。
		if ( \PeptideHq\Cdn::settings()['enabled'] ) { wp_schedule_single_event( time() + 60, 'pchq_cdn_tick_now' ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&job=' . rawurlencode( $id ) ) ); exit;
	}
	private static function result( string $id ): array {
		$path = self::root() . '/results/' . $id . '.json';
		if ( ! self::valid_id( $id ) || '' === self::root() || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > 8192 ) { return array( 'state' => 'queued', 'message' => '请求已提交，等待开店服务处理。' ); }
		$r = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $r ) ) { return array( 'state' => 'pending', 'message' => '正在读取任务状态。' ); }
		return self::safe_result( $r );
	}
	/** Only public progress and validated manual DNS instructions may leave the private queue. */
	public static function safe_result( array $r ): array {
		$out = array();
		foreach ( array( 'state', 'message', 'slug' ) as $key ) { if ( is_string( $r[ $key ] ?? null ) ) { $out[ $key ] = mb_substr( $r[ $key ], 0, 'message' === $key ? 1000 : 80 ); } }
		if ( is_int( $r['site_id'] ?? null ) && $r['site_id'] > 0 ) { $out['site_id'] = $r['site_id']; }
		foreach ( array( 'management_url' => '/wp-admin/', 'public_url' => '/' ) as $key => $path ) {
			$url = $r[ $key ] ?? null;
			if ( ! is_string( $url ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { continue; }
			$p = wp_parse_url( $url );
			if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) || ( isset( $p['port'] ) && 443 !== $p['port'] ) || $path !== ( $p['path'] ?? '/' ) || '' === self::customer_domain( (string) ( $p['host'] ?? '' ) ) ) { continue; }
			$out[ $key ] = $url;
		}
		$domain = is_string( $r['customer_domain'] ?? null ) ? self::customer_domain( $r['customer_domain'] ) : '';
		if ( '' !== $domain ) { $out['customer_domain'] = $domain; }
		$records = $r['dns_records'] ?? null;
		if ( '' !== $domain && is_array( $records ) && array_is_list( $records ) && 2 === count( $records ) ) {
			$clean = array();
			foreach ( array( '@', 'www' ) as $i => $name ) {
				$v = $records[ $i ];
				if ( ! is_array( $v ) || 'A' !== ( $v['type'] ?? '' ) || $name !== ( $v['name'] ?? '' ) || 600 !== ( $v['ttl'] ?? null ) || ! is_string( $v['value'] ?? null ) || ! filter_var( $v['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { return $out; }
				$clean[] = array( 'type' => 'A', 'name' => $name, 'value' => $v['value'], 'ttl' => 600 );
			}
			if ( $clean[0]['value'] === $clean[1]['value'] ) { $out['dns_records'] = $clean; }
		}
		return $out;
	}
	/** 开店步骤：已提交 → 等待域名解析 → 创建店铺 → 完成（2026-11-08）。 */
	public const STEPS = array( 1 => '已提交', 2 => '等待域名解析', 3 => '创建店铺', 4 => '开店完成' );

	public static function step( string $state ): int {
		return array( 'queued' => 1, 'waiting_dns' => 2, 'running' => 3, 'pending' => 3, 'waiting_capacity' => 3, 'complete' => 4 )[ $state ] ?? 0;
	}

	public static function state_label( string $state ): array {
		return array(
			'queued' => array( '等待开店服务接收', 'is-wait' ), 'waiting_dns' => array( '等待域名解析', 'is-wait' ), 'running' => array( '正在创建店铺', 'is-wait' ),
			'pending' => array( '自动重试中', 'is-wait' ), 'waiting_capacity' => array( '开店名额已满', 'is-bad' ), 'complete' => array( '已完成', 'is-ok' ), 'failed' => array( '已失败 / 已取消', 'is-bad' ),
		)[ $state ] ?? array( $state, 'is-off' );
	}

	/** 最近的开店任务（读结果文件，新的在前）。 */
	public static function jobs( int $limit = 20 ): array {
		$root = self::root();
		if ( '' === $root || ! is_dir( $root . '/results' ) ) { return array(); }
		$rows = array();
		foreach ( (array) glob( $root . '/results/*.json' ) as $path ) {
			$id = basename( (string) $path, '.json' );
			if ( ! self::valid_id( $id ) || is_link( $path ) ) { continue; }
			$rows[] = array( 'id' => $id, 'at' => (int) @filemtime( $path ) ) + self::result( $id );
		}
		usort( $rows, static fn( $a, $b ) => $b['at'] <=> $a['at'] );
		return array_slice( $rows, 0, $limit );
	}

	private static function render_steps( array $job ): void {
		$state = (string) ( $job['state'] ?? '' );
		$cur   = self::step( $state );
		echo '<ol class="pchq-steps" aria-label="开店步骤">';
		foreach ( self::STEPS as $n => $label ) {
			$cls = 'failed' === $state ? 'is-off' : ( $n < $cur || 4 === $cur ? 'is-done' : ( $n === $cur ? 'is-current' : 'is-todo' ) );
			echo '<li class="' . esc_attr( $cls ) . '"><span>' . (int) $n . '</span>' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';
		$domain = (string) ( $job['customer_domain'] ?? '' );
		$cdn    = '' !== $domain ? ( \PeptideHq\Cdn::domains()[ $domain ] ?? null ) : null;
		if ( is_array( $cdn ) ) {
			echo '<p class="pchq-step-note">CDN / 域名解析：' . esc_html( ( \PeptideHq\Cdn::STAGES[ $cdn['stage'] ?? '' ] ?? ( $cdn['stage'] ?? '' ) ) . ( '' !== (string) ( $cdn['message'] ?? '' ) ? ' — ' . $cdn['message'] : '' ) ) . '</p>';
		}
	}

	private static function render_jobs(): void {
		$jobs = self::jobs();
		echo '<div class="pchq-card"><h2>开店记录</h2>';
		if ( ! $jobs ) { echo '<p>还没有开店记录。</p></div>'; return; }
		echo '<table class="widefat striped pchq-jobs"><thead><tr><th>域名</th><th>店铺编号</th><th>当前进度</th><th>更新时间</th><th></th></tr></thead><tbody>';
		foreach ( $jobs as $j ) {
			[ $label, $cls ] = self::state_label( (string) ( $j['state'] ?? '' ) );
			$step = self::step( (string) ( $j['state'] ?? '' ) );
			echo '<tr><td><strong>' . esc_html( (string) ( $j['customer_domain'] ?? '—' ) ) . '</strong></td><td>' . esc_html( (string) ( $j['slug'] ?? '—' ) ) . '</td><td><span class="pchq-badge ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>' . ( $step && 'complete' !== ( $j['state'] ?? '' ) ? ' <small>第 ' . (int) $step . ' / 4 步</small>' : '' ) . '</td><td>' . esc_html( $j['at'] ? wp_date( 'm-d H:i', $j['at'] ) : '—' ) . '</td><td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&job=' . rawurlencode( $j['id'] ) ) ) . '">查看进度</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function status(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_send_json_error( null, 403 ); }
		check_ajax_referer( 'pchq_create_store_status', 'nonce' );
		$id = (string) wp_unslash( $_GET['job'] ?? '' );
		if ( ! self::valid_id( $id ) ) { wp_send_json_error( null, 400 ); }
		wp_send_json_success( self::result( $id ) );
	}
	private static function styles(): void {
		echo '<style>.pchq-steps{display:flex;flex-wrap:wrap;gap:8px 24px;margin:8px 0 4px;padding:0;list-style:none}.pchq-steps li{display:flex;align-items:center;gap:8px;color:#8a8a8a;font-weight:500}.pchq-steps li span{display:grid;place-items:center;width:24px;height:24px;border-radius:50%;border:1px solid #d0d0d0;font-size:12px;background:#fff}.pchq-steps li.is-done{color:#1d6b3a}.pchq-steps li.is-done span{background:#1d6b3a;border-color:#1d6b3a;color:#fff}.pchq-steps li.is-current{color:#111}.pchq-steps li.is-current span{border-color:#111;background:#111;color:#fff}.pchq-step-note{margin:12px 0 0;color:#555}.pchq-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:#f0f0f0;color:#444}.pchq-badge.is-ok{background:#e6f4ea;color:#1d6b3a}.pchq-badge.is-wait{background:#fff4e0;color:#8a5a00}.pchq-badge.is-bad{background:#fdecea;color:#b42318}.pchq-jobs td{vertical-align:middle}.pchq-jobs small{color:#777}</style>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) { return; }
		$id = (string) wp_unslash( $_GET['job'] ?? '' );
		$job = self::valid_id( $id ) ? self::result( $id ) : null;
		?>
		<div class="wrap pchq"><h1>自动开店</h1>
		<p>填写客户自有域名，创建独立店铺和后台账号。顾客使用 www 地址；管理地址仍由平台固定分配。商品、订单和顾客数据不会从其他店铺复制；支付默认关闭。</p>
		<?php self::styles(); ?>
		<?php if ( $job ) : ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">← 返回开店记录 / 开新店</a></p>
		<div class="pchq-card"><h2>开店步骤<?php echo isset( $job['customer_domain'] ) ? ' · ' . esc_html( $job['customer_domain'] ) : ''; ?><?php echo isset( $job['slug'] ) ? ' · ' . esc_html( $job['slug'] ) : ''; ?></h2><?php self::render_steps( $job ); ?></div>
		<div class="pchq-card" data-pchq-provision data-state="<?php echo esc_attr( (string) ( $job['state'] ?? '' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-job="<?php echo esc_attr( $id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pchq_create_store_status' ) ); ?>"><h2>开店进度</h2><p id="pchq-provision-status" role="status" aria-live="polite"><?php echo esc_html( $job['message'] ?? '' ); ?></p><div id="pchq-provision-dns"></div><div id="pchq-provision-links"></div></div>
		<?php else : ?>
		<?php if ( '' === self::root() ) : ?><div class="notice notice-warning"><p>部署服务尚未配置，暂时不能创建新店。</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pchq-card">
		<input type="hidden" name="action" value="pchq_create_store"><input type="hidden" name="operation_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
		<?php wp_nonce_field( 'pchq_create_store' ); ?>
		<table class="form-table"><tbody>
		<tr><th><label for="store-title">店铺名称</label></th><td><input id="store-title" name="store_title" class="regular-text" maxlength="100" required></td></tr>
		<tr><th><label for="customer-domain">客户自有域名</label></th><td><input id="customer-domain" name="customer_domain" class="regular-text" maxlength="253" placeholder="example.com" autocomplete="off" spellcheck="false" required><p class="description">填写根域名，例如 example.com 或 www.example.com（不要填写 https://、路径或端口）。顾客地址将使用 www；<?php echo \PeptideHq\Cdn::settings()['enabled'] ? '已开启 CDN 自动配置：系统会自动在 Cloudflare 建好 @ 和 www 两条解析，开店完成后自动开启 CDN（进度见“CDN 加速”）。' : '下一步会给出 @ 和 www 两条 A 记录及记录值，由你手动解析；也可以在“CDN 加速”里开启自动配置。'; ?></p></td></tr>
		<tr><th><label for="owner-login">后台账号</label></th><td><input id="owner-login" name="owner_login" class="regular-text" pattern="[a-z][a-z0-9_]{2,31}" autocomplete="off" required><p class="description">3至32位，以小写字母开头，可使用数字和下划线。</p></td></tr>
		<tr><th><label for="owner-email">账号邮箱</label></th><td><input id="owner-email" name="owner_email" class="regular-text" type="email" required></td></tr>
		<tr><th><label for="owner-password">后台密码</label></th><td><input id="owner-password" name="owner_password" class="regular-text" type="password" minlength="12" maxlength="128" autocomplete="new-password" required><p class="description">至少12位。创建后页面不回显密码，请自行保存。</p></td></tr>
		</tbody></table><?php submit_button( '创建店铺', 'primary', 'submit', true, '' === self::root() ? array( 'disabled' => 'disabled' ) : array() ); ?></form>
		<?php self::render_jobs(); ?>
		<?php endif; ?></div>
		<?php
	}
}
