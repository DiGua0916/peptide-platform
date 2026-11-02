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
	}
	private static function root(): string {
		return defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/' ) : '';
	}
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id );
	}
	public static function submit(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权开店。', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'pchq_create_store' );
		$id = (string) wp_unslash( $_POST['operation_id'] ?? '' );
		$title = sanitize_text_field( wp_unslash( $_POST['store_title'] ?? '' ) );
		$login = (string) wp_unslash( $_POST['owner_login'] ?? '' );
		$email = sanitize_email( wp_unslash( $_POST['owner_email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['owner_password'] ?? '' );
		if ( ! self::valid_id( $id ) || '' === $title || mb_strlen( $title ) > 100 || ! preg_match( '/^[a-z][a-z0-9_]{2,31}$/D', $login ) || ! is_email( $email ) || strlen( $password ) < 12 || strlen( $password ) > 128 || preg_match( '/[\x00-\x1f\x7f]/', $password ) ) {
			wp_die( '请填写店名、有效邮箱、3至32位小写字母/数字/下划线账号，以及至少12位密码。' );
		}
		$root = self::root();
		if ( '' === $root || ! is_dir( $root . '/requests' ) || ! is_writable( $root . '/requests' ) ) { wp_die( '开店服务尚未配置。' ); }
		$body = wp_json_encode( array( 'version' => 1, 'operation_id' => $id, 'actor_id' => get_current_user_id(), 'title' => $title, 'login' => $login, 'email' => $email, 'password' => $password ), JSON_UNESCAPED_UNICODE );
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
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&job=' . rawurlencode( $id ) ) ); exit;
	}
	private static function result( string $id ): array {
		$path = self::root() . '/results/' . $id . '.json';
		if ( ! self::valid_id( $id ) || '' === self::root() || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > 8192 ) { return array( 'state' => 'queued', 'message' => '请求已提交，等待开店服务处理。' ); }
		$r = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $r ) ) { return array( 'state' => 'pending', 'message' => '正在读取任务状态。' ); }
		return array_intersect_key( $r, array_flip( array( 'state', 'message', 'slug', 'management_url', 'public_url', 'site_id' ) ) );
	}
	public static function status(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_send_json_error( null, 403 ); }
		check_ajax_referer( 'pchq_create_store_status', 'nonce' );
		$id = (string) wp_unslash( $_GET['job'] ?? '' );
		if ( ! self::valid_id( $id ) ) { wp_send_json_error( null, 400 ); }
		wp_send_json_success( self::result( $id ) );
	}
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) { return; }
		$id = (string) wp_unslash( $_GET['job'] ?? '' );
		$job = self::valid_id( $id ) ? self::result( $id ) : null;
		?>
		<div class="wrap pchq"><h1>自动开店</h1>
		<p>创建独立店铺、后台账号、固定管理地址和顾客地址。商品、订单和顾客数据不会从其他店铺复制；支付默认关闭。</p>
		<?php if ( $job ) : ?>
		<div class="pchq-card"><h2>开店进度</h2><p id="pchq-provision-status" role="status" aria-live="polite"><?php echo esc_html( $job['message'] ?? '' ); ?></p><div id="pchq-provision-links"></div></div>
		<script>
		(() => { const status = document.getElementById('pchq-provision-status'), links = document.getElementById('pchq-provision-links');
		const base = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, job = <?php echo wp_json_encode( $id ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'pchq_create_store_status' ) ); ?>;
		async function refresh() { try { const u = new URL(base); u.search = new URLSearchParams({action:'pchq_create_store_status',job,nonce}); const r = await fetch(u,{credentials:'same-origin',cache:'no-store'}); const data = await r.json(); if(!data.success) throw Error(); const d=data.data; status.textContent=d.message; links.replaceChildren(); if(d.state==='complete') { for(const [key,label] of [['management_url','商家后台'],['public_url','顾客店铺']]) { const a=document.createElement('a'), u=new URL(d[key]); if(u.protocol!=='https:') continue; a.href=u.href; a.textContent=label; a.className='button'; links.append(a,document.createTextNode(' ')); } return; } if(d.state==='failed') return; } catch { status.textContent='暂时无法读取进度，任务不会因此重新创建。'; } setTimeout(refresh,5000); } refresh(); })();
		</script>
		<?php else : ?>
		<?php if ( '' === self::root() ) : ?><div class="notice notice-warning"><p>部署服务尚未配置，暂时不能创建新店。</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pchq-card">
		<input type="hidden" name="action" value="pchq_create_store"><input type="hidden" name="operation_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
		<?php wp_nonce_field( 'pchq_create_store' ); ?>
		<table class="form-table"><tbody>
		<tr><th><label for="store-title">店铺名称</label></th><td><input id="store-title" name="store_title" class="regular-text" maxlength="100" required></td></tr>
		<tr><th><label for="owner-login">后台账号</label></th><td><input id="owner-login" name="owner_login" class="regular-text" pattern="[a-z][a-z0-9_]{2,31}" autocomplete="off" required><p class="description">3至32位，以小写字母开头，可使用数字和下划线。</p></td></tr>
		<tr><th><label for="owner-email">账号邮箱</label></th><td><input id="owner-email" name="owner_email" class="regular-text" type="email" required></td></tr>
		<tr><th><label for="owner-password">后台密码</label></th><td><input id="owner-password" name="owner_password" class="regular-text" type="password" minlength="12" maxlength="128" autocomplete="new-password" required><p class="description">至少12位。创建后页面不回显密码，请自行保存。</p></td></tr>
		</tbody></table><?php submit_button( '创建店铺', 'primary', 'submit', true, '' === self::root() ? array( 'disabled' => 'disabled' ) : array() ); ?></form>
		<?php endif; ?></div>
		<?php
	}
}
