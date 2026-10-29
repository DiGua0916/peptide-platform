<?php
/** Real search-engine tools in the store admin. AI copy is explicitly deferred. @package PeptideCore */
namespace PeptideCore\Admin;

use PeptideCore\Content_Tools;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Content_Tools_Page {
	public const SLUG = 'pc-store-design-content-tools';
	public const BLOG_SLUG = 'pc-blog-content-tools';
	private const ACTIONS = array( 'pc_ct_settings', 'pc_ct_indexnow', 'pc_ct_inspect' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 65 );
		add_action( 'admin_init', array( self::class, 'reject_deferred_ai_request' ), 0 );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	/** Reject legacy URLs before WordPress dispatches an unregistered action. */
	public static function reject_deferred_ai_request(): void {
		global $pagenow;
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( (string) $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deny-only gate.
		if ( 'admin-post.php' === $pagenow && in_array( $action, array( 'pc_ct_generate', 'pc_ct_apply' ), true ) ) {
			wp_die( esc_html__( 'AI copy generation is deferred and unavailable.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
	}

	public static function menu(): void {
		add_submenu_page( Store_Design::SLUG, __( 'Search-engine tools', 'peptide-core' ), __( 'Search-engine tools', 'peptide-core' ), Templates::CAP_EDIT, self::SLUG, array( self::class, 'render' ) );
		add_submenu_page( 'edit.php', __( 'Search-engine tools', 'peptide-core' ), __( 'Search-engine tools', 'peptide-core' ), 'edit_posts', self::BLOG_SLUG, array( self::class, 'render' ) );
	}

	public static function url( array $args = array(), string $context = '' ): string {
		$blog = 'post' === $context || ( '' === $context && self::BLOG_SLUG === ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return add_query_arg( $args, admin_url( 'admin.php?page=' . ( $blog ? self::BLOG_SLUG : self::SLUG ) ) );
	}

	public static function entry_link( string $context, int $post_id = 0, string $domain = '' ): string {
		return ''; // AI copy is deferred; legacy callers cannot recreate an entry point.
	}

	private static function flash( string $message, bool $error = false ): void {
		set_transient( 'pc_ct_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 300 );
	}

	private static function open_form( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( in_array( $action, array( 'pc_ct_generate', 'pc_ct_apply' ), true ) ) {
			wp_die( esc_html__( 'AI copy generation is deferred and unavailable.', 'peptide-core' ), '', array( 'response' => 403 ) );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'peptide-core' ), 400 );
		}
		check_admin_referer( $action );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- service validates each field.
		$context = sanitize_key( (string) ( $in['context'] ?? 'home_seo' ) );
		$back = self::url( array(), $context );
		if ( 'pc_ct_settings' === $action ) {
			$result = Content_Tools::save_settings( $in );
			$back = self::url( array( 'tab' => 'settings' ), 'home_seo' );
			$message = __( 'Search-engine configuration saved. OAuth credentials remain on the server.', 'peptide-core' );
		} elseif ( 'pc_ct_indexnow' === $action ) {
			$urls = preg_split( '/\R/u', (string) ( $in['urls'] ?? '' ) );
			$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
			$result = Content_Tools::submit_indexnow( $urls );
			$back = self::url( array( 'tab' => 'index' ), $context );
			$message = ! is_wp_error( $result ) && 'key_pending' === $result['state'] ? __( 'IndexNow received the URLs; ownership verification is pending. This is not an indexing result.', 'peptide-core' ) : __( 'IndexNow received the URLs. Receipt does not guarantee indexing.', 'peptide-core' );
		} else {
			$result = Content_Tools::inspect_url( (string) ( $in['url'] ?? '' ) );
			$back = self::url( array( 'tab' => 'history' ), $context );
			$message = __( 'Search Console returned the stored Google index status. See the history below.', 'peptide-core' );
		}
		self::flash( is_wp_error( $result ) ? $result->get_error_message() : $message, is_wp_error( $result ) );
		wp_safe_redirect( $back );
		exit;
	}

	public static function render(): void {
		$blog = self::BLOG_SLUG === ( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( $blog ? 'edit_posts' : Templates::CAP_EDIT ) ) {
			return;
		}
		$context = sanitize_key( (string) ( $_GET['context'] ?? ( $blog ? 'post' : 'home_seo' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'index' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>' . esc_html__( 'Search-engine tools', 'peptide-core' ) . '</h1>';
		$notice = get_transient( 'pc_ct_notice_' . get_current_user_id() );
		delete_transient( 'pc_ct_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . ( ! empty( $notice['error'] ) ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array( 'index' => __( 'Search-engine tools', 'peptide-core' ), 'history' => __( 'Request history', 'peptide-core' ) );
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Search-engine settings', 'peptide-core' );
		}
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ), $context ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'settings' === $tab && current_user_can( 'manage_options' ) ) {
			self::render_settings();
		} elseif ( 'index' === $tab ) {
			self::render_index( $context );
		} elseif ( 'history' === $tab ) {
			self::render_history();
		} else {
			self::render_index( $context );
		}
		echo '</div>';
	}

	private static function field( string $name, string $label, $value, string $type = 'text', string $note = '' ): void {
		echo '<tr><th scope="row"><label for="pc-ct-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="5" id="pc-ct-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="pc-ct-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( 'password' === $type ? ' autocomplete="new-password"' : '' ) . '>';
		}
		if ( '' !== $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function render_settings(): void {
		$s = Content_Tools::settings();
		echo '<h2>' . esc_html__( 'Search-engine settings', 'peptide-core' ) . '</h2><p>' . esc_html__( 'Google OAuth secrets are encrypted in the database, or supplied through server environment variables. Blank password fields keep the stored value.', 'peptide-core' ) . '</p>';
		self::open_form( 'pc_ct_settings' );
		echo '<table class="form-table">';
		self::field( 'google_verification', __( 'Google site-verification token', 'peptide-core' ), $s['google_verification'], 'text', __( 'Paste only the content token from the verification meta tag, not HTML.', 'peptide-core' ) );
		self::field( 'bing_verification', __( 'Bing verification token', 'peptide-core' ), $s['bing_verification'] );
		echo '<tr><th>IndexNow</th><td><label><input type="checkbox" name="enable_indexnow" value="1"' . checked( '' !== Content_Tools::indexnow_key(), true, false ) . '> ' . esc_html__( 'Enable the public IndexNow ownership text file', 'peptide-core' ) . '</label><p class="description">' . esc_html__( 'An ownership token is generated on save and is intentionally public in the verification file.', 'peptide-core' ) . '</p></td></tr></table><h2>Google Search Console</h2><p>' . esc_html__( 'Enable the Search Console API and obtain OAuth credentials with the webmasters.readonly or webmasters scope for a property you control. This tool exchanges the configured refresh token on the server.', 'peptide-core' ) . '</p><table class="form-table">';
		self::field( 'gsc_site', __( 'Verified Search Console property', 'peptide-core' ), $s['gsc_site'], 'text', __( 'For example https://example.com/ or sc-domain:example.com.', 'peptide-core' ) );
		self::field( 'gsc_client_id', __( 'Google OAuth client ID', 'peptide-core' ), $s['gsc_client_id'] );
		self::secret_field( 'gsc_client_secret', __( 'Google OAuth client secret', 'peptide-core' ) );
		self::secret_field( 'gsc_refresh_token', __( 'Google OAuth refresh token', 'peptide-core' ) );
		echo '</table><p><code>PEPTIDE_GSC_CLIENT_ID / PEPTIDE_GSC_CLIENT_SECRET / PEPTIDE_GSC_REFRESH_TOKEN</code></p>';
		submit_button( __( 'Save search-engine configuration', 'peptide-core' ) );
		echo '</form>';
	}

	private static function secret_field( string $field, string $label ): void {
		self::field( $field, $label, '', 'password', __( 'Credential status:', 'peptide-core' ) . ' ' . self::credential_label( Content_Tools::secret_source( $field ) ) );
		echo '<tr><th></th><td><label><input type="checkbox" name="clear_' . esc_attr( $field ) . '" value="1"> ' . esc_html__( 'Delete the stored credential (environment variables are unchanged)', 'peptide-core' ) . '</label></td></tr>';
	}

	private static function credential_label( string $state ): string {
		return array( 'stored' => __( 'Stored ••••••••', 'peptide-core' ), 'environment' => __( 'Configured on the server ••••••••', 'peptide-core' ), 'missing' => __( 'Not configured', 'peptide-core' ) )[ $state ];
	}

	private static function render_index( string $context ): void {
		echo '<h2>' . esc_html__( 'Search-engine tools', 'peptide-core' ) . '</h2><p>' . esc_html__( 'IndexNow notifies participating search engines about published URLs. A received submission is not a guarantee of crawling or indexing. Google’s Indexing API is not used for ordinary store pages or blog posts.', 'peptide-core' ) . '</p>';
		echo '<p><a target="_blank" rel="noopener noreferrer" href="https://search.google.com/search-console/">Google Search Console</a> · <a target="_blank" rel="noopener noreferrer" href="https://www.bing.com/webmasters/">Bing Webmaster Tools</a> · <a target="_blank" rel="noopener noreferrer" href="' . esc_url( home_url( '/wp-sitemap.xml' ) ) . '">' . esc_html__( 'WordPress sitemap', 'peptide-core' ) . '</a></p>';
		if ( '' !== Content_Tools::indexnow_key_url() ) {
			echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url( Content_Tools::indexnow_key_url() ) . '">' . esc_html__( 'Open the public IndexNow ownership file', 'peptide-core' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'An administrator must enable the IndexNow ownership file first.', 'peptide-core' ) . '</p>';
		}
		self::open_form( 'pc_ct_indexnow' );
		echo '<input type="hidden" name="context" value="' . esc_attr( $context ) . '"><table class="form-table">';
		self::field( 'urls', __( 'Published URLs, one per line', 'peptide-core' ), '', 'textarea', __( 'Up to 100 canonical URLs on this store. Private, draft, password-protected, foreign and uneditable pages are rejected.', 'peptide-core' ) );
		echo '</table>';
		submit_button( __( 'Submit URLs to IndexNow', 'peptide-core' ), 'secondary' );
		echo '</form><details><summary>' . esc_html__( 'Choose from published content', 'peptide-core' ) . '</summary><ul>';
		if ( current_user_can( Templates::CAP_EDIT ) ) {
			echo '<li><code>' . esc_html( home_url( '/' ) ) . '</code></li>';
		}
		$posts = get_posts( array( 'post_type' => array( 'post', 'page', 'product', 'pc_topic' ), 'post_status' => 'publish', 'posts_per_page' => 100, 'has_password' => false, 'orderby' => 'modified', 'order' => 'DESC' ) );
		foreach ( $posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				echo '<li>' . esc_html( get_the_title( $post ) ) . ': <code>' . esc_html( get_permalink( $post ) ) . '</code></li>';
			}
		}
		echo '</ul></details><h2>' . esc_html__( 'Inspect the Google index', 'peptide-core' ) . '</h2><p>' . esc_html__( 'This reports Google’s stored index record, not a live crawl or a submission. Without configured OAuth it is unavailable; use Search Console directly.', 'peptide-core' ) . '</p>';
		self::open_form( 'pc_ct_inspect' );
		echo '<input type="hidden" name="context" value="' . esc_attr( $context ) . '"><table class="form-table">';
		self::field( 'url', __( 'Published URL to inspect', 'peptide-core' ), '', 'url' );
		echo '</table>';
		submit_button( __( 'Inspect with Search Console', 'peptide-core' ), 'secondary', 'submit', true, Content_Tools::configured( 'gsc' ) ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form><p><a target="_blank" rel="noopener noreferrer" href="https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect">' . esc_html__( 'Official URL Inspection API setup', 'peptide-core' ) . '</a> · <a target="_blank" rel="noopener noreferrer" href="https://www.indexnow.org/documentation">IndexNow</a></p>';
	}

	private static function render_history(): void {
		echo '<h2>' . esc_html__( 'Request history', 'peptide-core' ) . '</h2><p>' . esc_html__( 'The latest 100 search-engine events are kept. OAuth credentials are not logged here.', 'peptide-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Tool', 'peptide-core' ) . '</th><th>' . esc_html__( 'Result', 'peptide-core' ) . '</th><th>' . esc_html__( 'Details', 'peptide-core' ) . '</th></tr></thead><tbody>';
		$labels = array( 'draft' => __( 'Draft ready for review', 'peptide-core' ), 'applied' => __( 'Manually applied', 'peptide-core' ), 'received' => __( 'URLs received; indexing unconfirmed', 'peptide-core' ), 'key_pending' => __( 'Ownership verification pending', 'peptide-core' ), 'failed' => __( 'Request failed', 'peptide-core' ), 'inspected' => __( 'Google index record returned', 'peptide-core' ) );
		foreach ( Content_Tools::history() as $event ) {
			if ( 'ai' === $event['kind'] ) { continue; }
			if ( ! current_user_can( 'manage_options' ) && (int) $event['user'] !== get_current_user_id() ) {
				continue;
			}
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', $event['time'] ) ) . '</td><td>' . esc_html( $event['kind'] ) . '</td><td>' . esc_html( $labels[ $event['state'] ] ?? $event['state'] ) . '</td><td><code>' . esc_html( wp_json_encode( $event['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}
}
