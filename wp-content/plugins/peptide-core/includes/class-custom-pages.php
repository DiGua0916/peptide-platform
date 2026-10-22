<?php
/**
 * 自定义页面的补充功能（CUSTOM-PAGES-01），参照 Shopname 的“店铺装修 → 自定义页面”。页面本身就是 WordPress 页面。
 *
 * - 结账条款：页面编辑页右侧勾选“设为结账条款”，页面列表显示“结账条款”标签；
 *   主题用 peptide_core_get_checkout_terms() 取得这些页面，显示在结账页（例如 Shipping / Return / Terms / Privacy）。
 * - 内容变量：在页面内容里写 [pc_var name="store_name"] 等，显示时换成当前的店铺信息，改了邮箱等不用逐页修改。
 * - 生成常见条款：页面列表上方的按钮，按需建好 Shipping policy、Return & refund policy、Terms of service、
 *   Privacy policy、Contact us 的草稿。内容只有标题结构和待填写的提示，必须由店主按真实情况填写、审核后再发布。
 *   已经有同一类页面时不会重复创建。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Custom_Pages {

	public const TERMS_META = '_pc_checkout_terms';
	public const KIND_META  = '_pc_policy_kind';
	public const SHORTCODE  = 'pc_var';

	private const NONCE = 'pc_page_terms_nonce';

	public static function register_hooks(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_page', array( self::class, 'add_meta_box' ) );
			add_action( 'save_post_page', array( self::class, 'save_meta' ), 10, 2 );
			add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
			add_filter( 'views_edit-page', array( self::class, 'add_generator' ) );
			add_action( 'admin_post_pc_generate_policies', array( self::class, 'handle_generate' ) );
			add_action( 'admin_notices', array( self::class, 'generated_notice' ) );
		}
	}

	// ---------- 内容变量 ----------

	/**
	 * 变量名 => [说明, 当前值]。
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function variables(): array {
		$support  = Settings::all()['support'] ?? array();
		$channels = array();
		foreach ( Support_Contacts::channels( is_array( $support ) ? $support : array() ) as $c ) {
			$channels[ $c['type'] ] = $c['value'];
		}
		return array(
			'store_name'     => array( __( 'Store name', 'peptide-core' ), (string) get_bloginfo( 'name' ) ),
			'store_url'      => array( __( 'Store address', 'peptide-core' ), home_url( '/' ) ),
			'store_domain'   => array( __( 'Store domain', 'peptide-core' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'support_email'  => array( __( 'Support email (Settings → support button)', 'peptide-core' ), (string) ( $channels['email'] ?? '' ) ),
			'support_whatsapp' => array( __( 'Support WhatsApp', 'peptide-core' ), (string) ( $channels['whatsapp'] ?? '' ) ),
			'support_telegram' => array( __( 'Support Telegram', 'peptide-core' ), (string) ( $channels['telegram'] ?? '' ) ),
			'company_name'    => array( __( 'Company name (System settings → Basic settings)', 'peptide-core' ), (string) Store_Config::get( 'company_name' ) ),
			'company_phone'   => array( __( 'Company phone', 'peptide-core' ), (string) Store_Config::get( 'company_phone' ) ),
			'company_address' => array( __( 'Company address', 'peptide-core' ), Store_Config::address_line() ),
			'year'           => array( __( 'Current year', 'peptide-core' ), (string) wp_date( 'Y' ) ),
			'today'          => array( __( 'Today’s date', 'peptide-core' ), (string) wp_date( (string) get_option( 'date_format' ) ) ),
		);
	}

	/**
	 * [pc_var name="support_email" link="1"]：link 为 1 时邮箱、网址输出成链接。没有值时输出空字符串。
	 *
	 * @param array|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'name' => '', 'link' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$vars = self::variables();
		$name = sanitize_key( (string) $atts['name'] );
		if ( ! isset( $vars[ $name ] ) || '' === $vars[ $name ][1] ) {
			return '';
		}
		$value = $vars[ $name ][1];
		if ( '' !== $atts['link'] && '0' !== $atts['link'] ) {
			if ( 'support_email' === $name ) {
				return '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			}
			if ( 'store_url' === $name ) {
				return '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
			}
		}
		return esc_html( $value );
	}

	// ---------- 结账条款 ----------

	public static function is_checkout_terms( int $page_id ): bool {
		return '1' === get_post_meta( $page_id, self::TERMS_META, true );
	}

	/**
	 * 设为结账条款并且已发布的页面，按菜单顺序、标题排列。
	 *
	 * @return array<int,array{id:int,title:string,url:string}>
	 */
	public static function checkout_terms(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'meta_key'       => self::TERMS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		) as $p ) {
			$out[] = array(
				'id'    => (int) $p->ID,
				'title' => html_entity_decode( get_the_title( $p ) ),
				'url'   => (string) get_permalink( $p ),
			);
		}
		return $out;
	}

	public static function add_meta_box(): void {
		add_meta_box( 'pc-page-tools', __( 'VialCove page options', 'peptide-core' ), array( self::class, 'render_meta_box' ), 'page', 'side' );
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label><input type="checkbox" name="pc_checkout_terms" value="1" <?php checked( self::is_checkout_terms( $post->ID ) ); ?>> <?php esc_html_e( 'Use as checkout terms', 'peptide-core' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'Published pages marked as checkout terms are linked on the checkout page (for example shipping, returns, terms and privacy).', 'peptide-core' ); ?></p>
		<details>
			<summary><?php esc_html_e( 'How to insert variables', 'peptide-core' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Type a variable into the page content. It shows the current store information, so you do not have to edit every page when it changes.', 'peptide-core' ); ?></p>
			<ul>
				<?php foreach ( self::variables() as $name => [ $label, $value ] ) : ?>
					<li><code>[<?php echo esc_html( self::SHORTCODE ); ?> name="<?php echo esc_html( $name ); ?>"]</code><br><?php echo esc_html( $label ); ?>: <em><?php echo '' !== $value ? esc_html( $value ) : esc_html__( '(not set)', 'peptide-core' ); ?></em></li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Add link="1" to support_email or store_url to show a clickable link.', 'peptide-core' ); ?></p>
		</details>
		<?php
	}

	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['pc_checkout_terms'] ) ) {
			update_post_meta( $post_id, self::TERMS_META, '1' );
		} else {
			delete_post_meta( $post_id, self::TERMS_META );
		}
	}

	/** 页面列表的标题后面显示“结账条款”。 */
	public static function post_states( array $states, \WP_Post $post ): array {
		if ( 'page' === $post->post_type && self::is_checkout_terms( $post->ID ) ) {
			$states['pc_checkout_terms'] = __( 'Checkout terms', 'peptide-core' );
		}
		return $states;
	}

	// ---------- 生成常见条款 ----------

	/**
	 * 常见条款：键 => [标题, 网址别名, 可能已存在的别名, 是否结账条款, 段落标题 => 待填写提示]。
	 */
	private static function policies(): array {
		return array(
			'shipping' => array(
				'Shipping policy',
				'shipping-policy',
				array( 'shipping-policy', 'shipping' ),
				true,
				array(
					'Processing time'      => 'Describe how many business days it takes to prepare an order before it ships.',
					'Shipping methods and rates' => 'List the carriers, services and costs you actually offer.',
					'Delivery areas'       => 'List the countries or states you ship to, and any you do not.',
					'Tracking'             => 'Explain when the tracking number is sent and where customers can see it (My account → Orders).',
					'Lost or damaged parcels' => 'Explain what customers should do and how quickly to contact you.',
				),
			),
			'returns'  => array(
				'Return & refund policy',
				'return-refund-policy',
				array( 'return-refund-policy', 'refund_returns', 'return-policy', 'refund-policy' ),
				true,
				array(
					'Eligibility'          => 'State which items can be returned, in what condition, and within how many days.',
					'How to request a return' => 'Explain the steps and the information customers must provide.',
					'Refunds'              => 'Explain how and when refunds are issued, and to which payment method.',
					'Exceptions'           => 'List items that cannot be returned, if any.',
				),
			),
			'terms'    => array(
				'Terms of service',
				'terms-of-service',
				array( 'terms-of-service', 'terms', 'terms-and-conditions' ),
				true,
				array(
					'About these terms'    => 'Explain who operates the store and that using it means accepting these terms.',
					'Products and intended use' => 'Describe the intended use of your products exactly as your compliance review allows.',
					'Orders and payment'   => 'Explain order acceptance, pricing, taxes and accepted payment methods.',
					'Limitation of liability' => 'Have this section written or reviewed by a qualified lawyer.',
					'Governing law'        => 'State the governing law and jurisdiction after legal review.',
				),
			),
			'privacy'  => array(
				'Privacy policy',
				'privacy-policy',
				array( 'privacy-policy', 'privacy' ),
				true,
				array(
					'Information we collect' => 'List what you actually collect at checkout, in accounts and through analytics.',
					'How we use it'        => 'Explain each purpose (orders, support, legal obligations…).',
					'Sharing'              => 'List the services that receive data (payment, shipping, email).',
					'Your choices and rights' => 'Explain how customers can access, correct or delete their data.',
				),
			),
			'contact'  => array(
				'Contact us',
				'contact-us',
				array( 'contact-us', 'contact' ),
				false,
				array(
					'Customer support'     => 'Email: [pc_var name="support_email" link="1"]. Add your support hours and expected reply time.',
					'Order questions'      => 'Ask customers to include their order number.',
				),
			),
		);
	}

	private static function find_existing( array $slugs ): int {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page && 'trash' !== $page->post_status ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

	/**
	 * 建好选中的常见条款草稿。已有同类页面时跳过。
	 *
	 * @param string[] $keys
	 * @return array{created:int[],existing:int[]}
	 */
	public static function generate( array $keys ): array {
		$created  = array();
		$existing = array();
		foreach ( self::policies() as $key => [ $title, $slug, $slugs, $terms, $sections ] ) {
			if ( ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$found = self::find_existing( $slugs );
			if ( $found ) {
				$existing[] = $found;
				continue;
			}
			$content = '<!-- wp:paragraph --><p><strong>[Draft — replace every bracketed note with your real policy before publishing.]</strong></p><!-- /wp:paragraph -->';
			foreach ( $sections as $heading => $note ) {
				$content .= "\n\n<!-- wp:heading --><h2 class=\"wp-block-heading\">" . esc_html( $heading ) . "</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>[" . $note . ']</p><!-- /wp:paragraph -->';
			}
			$content .= "\n\n<!-- wp:paragraph --><p>Questions? Contact [pc_var name=\"store_name\"] at [pc_var name=\"support_email\" link=\"1\"].</p><!-- /wp:paragraph -->";
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, self::KIND_META, $key );
			if ( $terms ) {
				update_post_meta( $id, self::TERMS_META, '1' );
			}
			$created[] = (int) $id;
		}
		return array(
			'created'  => $created,
			'existing' => $existing,
		);
	}

	/**
	 * 页面列表上方（“全部 / 已发布 / 草稿”那一行）的“生成常见条款”。
	 * 放在这里是因为它在页面列表的表单外面，可以有自己的表单。
	 */
	public static function add_generator( array $views ): array {
		if ( current_user_can( 'publish_pages' ) ) {
			ob_start();
			self::render_generator();
			$views['pc_policies'] = (string) ob_get_clean();
		}
		return $views;
	}

	private static function render_generator(): void {
		?>
		<details class="pc-policy-gen" style="display:inline-block;position:relative;vertical-align:middle">
			<summary class="button"><?php esc_html_e( 'Generate common policies', 'peptide-core' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="position:absolute;z-index:20;top:34px;left:0;width:320px;padding:12px 14px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 12px rgba(0,0,0,.08);white-space:normal">
				<input type="hidden" name="action" value="pc_generate_policies">
				<p class="description" style="margin-top:0"><?php esc_html_e( 'Creates draft pages with a heading outline and notes to fill in. They are not legal advice: write the real policy and have it reviewed before publishing. Existing pages of the same kind are kept.', 'peptide-core' ); ?></p>
				<?php
				wp_nonce_field( 'pc_generate_policies', '_pc_policy_nonce', false );
				foreach ( self::policies() as $key => [ $title, , $slugs ] ) :
					$found = self::find_existing( $slugs );
					?>
					<label style="display:block;margin:4px 0"><input type="checkbox" name="pc_policies[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! $found ); ?> <?php disabled( (bool) $found ); ?>> <?php echo esc_html( $title ); ?><?php echo $found ? ' <span class="description">(' . esc_html__( 'already exists', 'peptide-core' ) . ')</span>' : ''; ?></label>
				<?php endforeach; ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create drafts', 'peptide-core' ); ?></button>
			</form>
		</details>
		<?php
	}

	public static function handle_generate(): void {
		if ( ! current_user_can( 'publish_pages' ) || ! isset( $_POST['_pc_policy_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_pc_policy_nonce'] ) ), 'pc_generate_policies' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		$keys   = isset( $_POST['pc_policies'] ) && is_array( $_POST['pc_policies'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['pc_policies'] ) ) : array();
		$result = self::generate( $keys );
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'page', 'pc_generated' => count( $result['created'] ) ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function generated_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-page' !== $screen->id || ! isset( $_GET['pc_generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$n = absint( $_GET['pc_generated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %d: number of pages */
		$text = $n ? sprintf( _n( 'Created %d draft. Fill in every bracketed note, have it reviewed, then publish.', 'Created %d drafts. Fill in every bracketed note, have them reviewed, then publish.', $n, 'peptide-core' ), $n ) : __( 'No new drafts: those pages already exist.', 'peptide-core' );
		echo '<div class="notice notice-' . ( $n ? 'success' : 'info' ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
