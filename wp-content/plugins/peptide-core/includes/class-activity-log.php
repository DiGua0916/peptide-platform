<?php
/**
 * 操作记录（照 YY 后台“后台设置 → 操作记录”，用户 2026-10-27）。后台页面见 Admin\Activity_Page。
 *
 * YY 的做法：按操作人、模块、操作时间筛选；列：操作（查看明细）、操作类型、操作人、操作时间、模块、事件、Trace ID、IP、浏览器、操作设备。
 * 模块有商品、专辑、评论、订单、顾客、营销活动、优惠码、博客、店铺装修、自定义页面、域名管理、活动专题、支付设置、登录、数据、应用、物流设置等。
 *
 * 这里：只记录后台人员（管理员、员工等，不是顾客）的操作，存在自己的表 {prefix}pc_activity 里，保留 365 天。
 * - 登录、退出、登录失败（账号是后台人员时）。
 * - 商品、博客、页面、活动专题、优惠码、检测报告、装修模板的新建 / 编辑 / 上架下架（发布）/ 回收站 / 恢复 / 删除。
 * - 商品专辑、商品标签、博客专辑、素材分组的新建 / 编辑 / 删除；商品评论的审核和删除。
 * - 订单状态、退款、后台编辑订单；顾客和员工账号的新建 / 编辑 / 删除；素材上传和删除；插件启用停用；WooCommerce 设置保存。
 * - 插件自己的后台表单（admin-post 的 pc_ 动作）和装修接口的写请求，按名称归到模块。员工管理自己写更详细的记录。
 * 同一个人对同一个对象做同一件事，10 秒内只记一次（区块编辑器保存时会提交两次）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Activity_Log {

	public const DB_VERSION = '1';
	public const DB_OPTION  = 'peptide_core_activity_db';
	public const CAP        = 'pc_view_activity';
	public const CRON       = 'pc_activity_prune';
	public const KEEP_DAYS  = 365;

	/** 后台人员的角色（不记录顾客和订阅者）。 */
	private const STAFF_ROLES = array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'pc_staff' );

	/** 文章类型 => 模块。 */
	private const POST_TYPES = array(
		'product'     => 'product',
		'post'        => 'blog',
		'page'        => 'page',
		'pc_topic'    => 'topic',
		'shop_coupon' => 'coupon',
		'pc_report'   => 'report',
		'pc_batch'    => 'report',
		'pc_template' => 'design',
		'pc_layout'   => 'design',
		'shop_order'  => 'order',
	);

	/** 分类法 => 模块。 */
	private const TAXONOMIES = array(
		'product_cat'    => 'collection',
		'product_tag'    => 'product',
		'category'       => 'blog',
		'pc_media_group' => 'media',
	);

	/** 插件后台表单（admin-post 动作前缀）=> 模块；没列到的算“系统设置”。 */
	private const ACTION_PREFIXES = array(
		'pc_domain_'           => 'domain',
		'pc_seo_'              => 'design',
		'pc_design_'           => 'design',
		'pc_menu'              => 'design',
		'pc_blog_'             => 'blog',
		'pc_media_'            => 'media',
		'pc_coupon_'           => 'coupon',
		'pc_promo_'            => 'marketing',
		'pc_shipdisc_'         => 'marketing',
		'pc_shipping_'         => 'shipping',
		'pc_tax_'              => 'tax',
		'pc_enable_tax'        => 'tax',
		'pc_app_'              => 'app',
		'pc_pixels_'           => 'app',
		'pc_recovery_'         => 'order',
		'pc_tracking_'         => 'data',
		'pc_approve_user'      => 'customer',
	);

	/** 这次请求的 Trace ID（同一次请求里的记录相同）。 */
	private static string $trace = '';

	private static bool $ready = false;

	/** 这次请求已经写了几条（插件表单的通用记录只在没有更具体的记录时才写）。 */
	private static int $written = 0;

	public static function register_hooks(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_view' ), 10, 4 );
		add_action( self::CRON, array( self::class, 'prune' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}

		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( self::class, 'on_logout' ) );
		add_action( 'wp_login_failed', array( self::class, 'on_login_failed' ) );

		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_delete_post' ), 10, 2 );

		add_action( 'created_term', array( self::class, 'on_created_term' ), 10, 3 );
		add_action( 'edited_term', array( self::class, 'on_edited_term' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'on_delete_term' ), 10, 5 );

		add_action( 'transition_comment_status', array( self::class, 'on_comment_status' ), 10, 3 );
		add_action( 'delete_comment', array( self::class, 'on_delete_comment' ), 10, 2 );

		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order_status' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_refund' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'on_order_edit' ) );
		add_action( 'woocommerce_settings_saved', array( self::class, 'on_wc_settings' ) );

		add_action( 'user_register', array( self::class, 'on_user_register' ) );
		add_action( 'profile_update', array( self::class, 'on_profile_update' ) );
		add_action( 'deleted_user', array( self::class, 'on_deleted_user' ), 10, 3 );

		add_action( 'add_attachment', array( self::class, 'on_add_attachment' ) );
		add_action( 'delete_attachment', array( self::class, 'on_delete_attachment' ) );

		add_action( 'activated_plugin', array( self::class, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( self::class, 'on_plugin_deactivated' ) );

		add_action( 'admin_init', array( self::class, 'watch_admin_post' ) );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'on_rest' ), 10, 3 );
	}

	/** 能管理网站的人（manage_options）都能看操作记录；员工按“后台设置”权限另外授予。 */
	public static function grant_view( $allcaps, $caps, $args, $user ) {
		if ( in_array( self::CAP, (array) $caps, true ) && ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	// ---------- 表 ----------

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_activity';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  user_role varchar(32) NOT NULL DEFAULT '',
  module varchar(32) NOT NULL DEFAULT '',
  event varchar(32) NOT NULL DEFAULT '',
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_label varchar(255) NOT NULL DEFAULT '',
  details text NULL,
  trace_id varchar(36) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  device varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY module (module,created_at),
  KEY user_id (user_id,created_at)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	private static function ready(): bool {
		if ( ! self::$ready ) {
			if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
				self::install();
			}
			self::$ready = true;
		}
		return true;
	}

	// ---------- 名称 ----------

	/** @return array<string,string> 模块 => 名称（和 YY 的模块对应） */
	public static function modules(): array {
		return array(
			'login'      => __( 'Login', 'peptide-core' ),
			'product'    => __( 'Products', 'peptide-core' ),
			'collection' => __( 'Collections', 'peptide-core' ),
			'review'     => __( 'Reviews', 'peptide-core' ),
			'report'     => __( 'Test reports', 'peptide-core' ),
			'order'      => __( 'Orders', 'peptide-core' ),
			'customer'   => __( 'Customers', 'peptide-core' ),
			'marketing'  => __( 'Marketing campaigns', 'peptide-core' ),
			'coupon'     => __( 'Coupon codes', 'peptide-core' ),
			'blog'       => __( 'Blog', 'peptide-core' ),
			'design'     => __( 'Store design', 'peptide-core' ),
			'page'       => __( 'Custom pages', 'peptide-core' ),
			'domain'     => __( 'Domains', 'peptide-core' ),
			'topic'      => __( 'Campaign pages', 'peptide-core' ),
			'payment'    => __( 'Payment settings', 'peptide-core' ),
			'shipping'   => __( 'Shipping settings', 'peptide-core' ),
			'tax'        => __( 'Tax settings', 'peptide-core' ),
			'settings'   => __( 'System settings', 'peptide-core' ),
			'data'       => __( 'Data', 'peptide-core' ),
			'app'        => __( 'Apps', 'peptide-core' ),
			'media'      => __( 'Media library', 'peptide-core' ),
			'staff'      => __( 'Staff', 'peptide-core' ),
		);
	}

	public static function event_label( string $event, string $module = '' ): string {
		if ( 'product' === $module && 'publish' === $event ) {
			return __( 'Put on sale', 'peptide-core' );
		}
		if ( 'product' === $module && 'unpublish' === $event ) {
			return __( 'Taken off sale', 'peptide-core' );
		}
		$labels = array(
			'login'        => __( 'Log in', 'peptide-core' ),
			'logout'       => __( 'Log out', 'peptide-core' ),
			'login_failed' => __( 'Failed login', 'peptide-core' ),
			'create'       => __( 'Create', 'peptide-core' ),
			'edit'         => __( 'Edit', 'peptide-core' ),
			'publish'      => __( 'Publish', 'peptide-core' ),
			'unpublish'    => __( 'Unpublish', 'peptide-core' ),
			'trash'        => __( 'Move to trash', 'peptide-core' ),
			'restore'      => __( 'Restore', 'peptide-core' ),
			'delete'       => __( 'Delete', 'peptide-core' ),
			'status'       => __( 'Change status', 'peptide-core' ),
			'refund'       => __( 'Refund', 'peptide-core' ),
			'save'         => __( 'Save', 'peptide-core' ),
			'export'       => __( 'Export', 'peptide-core' ),
			'import'       => __( 'Import', 'peptide-core' ),
			'upload'       => __( 'Upload', 'peptide-core' ),
			'collect'      => __( 'Collect', 'peptide-core' ),
			'check'        => __( 'Check', 'peptide-core' ),
			'approve'      => __( 'Approve', 'peptide-core' ),
			'unapprove'    => __( 'Unapprove', 'peptide-core' ),
			'spam'         => __( 'Mark as spam', 'peptide-core' ),
			'permissions'  => __( 'Change permissions', 'peptide-core' ),
			'enable'       => __( 'Enable', 'peptide-core' ),
			'disable'      => __( 'Disable', 'peptide-core' ),
			'password'     => __( 'Change password', 'peptide-core' ),
		);
		return $labels[ $event ] ?? $event;
	}

	public static function role_label( string $role ): string {
		if ( 'administrator' === $role ) {
			return __( 'Administrator', 'peptide-core' );
		}
		if ( 'pc_staff' === $role ) {
			return __( 'Staff member', 'peptide-core' );
		}
		$roles = wp_roles()->roles;
		return isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ]['name'] ) : $role;
	}

	// ---------- 写入 ----------

	public static function is_staff( $user ): bool {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		return $user instanceof \WP_User && $user->ID > 0 && (bool) array_intersect( (array) $user->roles, self::STAFF_ROLES );
	}

	/**
	 * 记一条。$user 为空时用当前登录的人；不是后台人员时不记。
	 *
	 * @param array{type?:string,id?:int,label?:string} $object
	 * @param array<string,scalar>                       $details
	 */
	public static function log( string $module, string $event, array $object = array(), array $details = array(), $user = null ): bool {
		$user = null === $user ? wp_get_current_user() : ( $user instanceof \WP_User ? $user : get_userdata( (int) $user ) );
		if ( ! self::is_staff( $user ) || ! self::human_request() ) {
			return false;
		}
		self::ready();
		global $wpdb;
		$t   = self::table();
		$oid = (int) ( $object['id'] ?? 0 );
		$now = current_time( 'mysql', true );
		// 10 秒内同一个人对同一个对象的同一件事只记一次。
		$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND module = %s AND event = %s AND object_id = %d AND created_at >= %s LIMIT 1", $user->ID, $module, $event, $oid, gmdate( 'Y-m-d H:i:s', time() - 10 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $dup && $oid ) {
			return false;
		}
		$ua    = self::user_agent();
		$roles = array_values( array_intersect( self::STAFF_ROLES, (array) $user->roles ) );
		++self::$written;
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'created_at'   => $now,
				'user_id'      => $user->ID,
				'user_login'   => mb_substr( $user->user_login, 0, 60 ),
				'user_role'    => (string) ( $roles[0] ?? '' ),
				'module'       => substr( $module, 0, 32 ),
				'event'        => substr( $event, 0, 32 ),
				'object_type'  => substr( (string) ( $object['type'] ?? '' ), 0, 32 ),
				'object_id'    => $oid,
				'object_label' => mb_substr( wp_strip_all_tags( (string) ( $object['label'] ?? '' ) ), 0, 255 ),
				'details'      => $details ? (string) wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) : null,
				'trace_id'     => self::trace(),
				'ip'           => self::ip(),
				'browser'      => self::browser( $ua ),
				'device'       => self::device( $ua ),
			)
		);
	}

	private static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
	}

	/**
	 * 是人在操作后台：命令行（WP-CLI、测试）和网站自己发出的请求（User-Agent 以 WordPress/ 开头，例如定时任务、检查）不记。
	 * 测试可以用 peptide_core_activity_log_cli 过滤器打开命令行记录。
	 */
	private static function human_request(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return (bool) apply_filters( 'peptide_core_activity_log_cli', false );
		}
		return ! str_starts_with( self::user_agent(), 'WordPress/' );
	}

	public static function trace(): string {
		if ( '' === self::$trace ) {
			self::$trace = strtoupper( wp_generate_uuid4() );
		}
		return self::$trace;
	}

	/** 操作人的 IP。只有请求来自 wp-config 里 PEPTIDE_CORE_TRUSTED_PROXIES 列出的代理时才用转发的地址（和限流一样）。 */
	public static function ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$list   = defined( 'PEPTIDE_CORE_TRUSTED_PROXIES' ) ? array_filter( array_map( 'trim', explode( ',', (string) PEPTIDE_CORE_TRUSTED_PROXIES ) ) ) : array();
		if ( $list && Api\Rest_Controller::ip_in_list( $remote, $list ) ) {
			$fwd = '';
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$fwd = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$fwd = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0] );
			}
			if ( filter_var( $fwd, FILTER_VALIDATE_IP ) ) {
				return $fwd;
			}
		}
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/** 浏览器和主版本号，例如 Chrome 156。 */
	public static function browser( string $ua ): string {
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser/' => 'Samsung', 'CriOS/' => 'Chrome', 'FxiOS/' => 'Firefox', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Version/' => 'Safari' ) as $needle => $name ) {
			if ( preg_match( '#' . preg_quote( $needle, '#' ) . '(\d+)#', $ua, $m ) ) {
				return $name . ' ' . $m[1];
			}
		}
		return '' !== $ua ? 'Other' : '';
	}

	/** 操作设备，例如 Windows 10、macOS、iPhone、Android。 */
	public static function device( string $ua ): string {
		$map = array(
			'/iPhone/'                   => 'iPhone',
			'/iPad/'                     => 'iPad',
			'/Android/'                  => 'Android',
			'/Windows NT 10/'            => 'Windows 10',
			'/Windows NT 6\.[23]/'       => 'Windows 8',
			'/Windows NT 6\.1/'          => 'Windows 7',
			'/Windows/'                  => 'Windows',
			'/Mac OS X|Macintosh/'       => 'macOS',
			'/CrOS/'                     => 'ChromeOS',
			'/Linux/'                    => 'Linux',
		);
		foreach ( $map as $re => $name ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return '' !== $ua ? 'Other' : '';
	}

	// ---------- 登录 ----------

	public static function on_login( $login, $user ): void {
		self::log( 'login', 'login', array( 'type' => 'user', 'id' => $user->ID, 'label' => $user->user_login ), array(), $user );
	}

	public static function on_logout( $user_id = 0 ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			self::log( 'login', 'logout', array( 'type' => 'user', 'id' => $user->ID, 'label' => $user->user_login ), array(), $user );
		}
	}

	/** 登录失败：只记后台人员的账号（顾客输错密码不记）。 */
	public static function on_login_failed( $username ): void {
		$user = get_user_by( 'login', (string) $username );
		if ( ! $user && is_email( (string) $username ) ) {
			$user = get_user_by( 'email', (string) $username );
		}
		if ( $user ) {
			self::log( 'login', 'login_failed', array( 'type' => 'user', 'id' => $user->ID, 'label' => $user->user_login ), array(), $user );
		}
	}

	// ---------- 文章类内容 ----------

	private static function post_module( $post ): string {
		return $post instanceof \WP_Post ? ( self::POST_TYPES[ $post->post_type ] ?? '' ) : '';
	}

	private static function post_object( \WP_Post $post ): array {
		return array(
			'type'  => $post->post_type,
			'id'    => $post->ID,
			'label' => '' !== $post->post_title ? $post->post_title : '#' . $post->ID,
		);
	}

	public static function on_transition( $new, $old, $post ): void {
		$module = self::post_module( $post );
		if ( '' === $module || $new === $old || 'auto-draft' === $new || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( in_array( $old, array( 'new', 'auto-draft' ), true ) ) {
			$event = 'create';
		} elseif ( 'trash' === $new ) {
			$event = 'trash';
		} elseif ( 'trash' === $old ) {
			$event = 'restore';
		} elseif ( 'publish' === $new ) {
			$event = 'publish';
		} elseif ( 'publish' === $old ) {
			$event = 'unpublish';
		} else {
			$event = 'edit';
		}
		self::log( $module, $event, self::post_object( $post ), array( 'from' => $old, 'to' => $new ) );
	}

	public static function on_post_updated( $id, $after, $before ): void {
		$module = self::post_module( $after );
		if ( '' === $module || ! $before instanceof \WP_Post || $after->post_status !== $before->post_status || 'auto-draft' === $after->post_status || wp_is_post_revision( $after ) || wp_is_post_autosave( $after ) ) {
			return;
		}
		self::log( $module, 'edit', self::post_object( $after ) );
	}

	public static function on_delete_post( $id, $post = null ): void {
		$post   = $post instanceof \WP_Post ? $post : get_post( (int) $id );
		$module = self::post_module( $post );
		if ( '' !== $module && 'auto-draft' !== $post->post_status && ! wp_is_post_revision( $post ) ) {
			self::log( $module, 'delete', self::post_object( $post ) );
		}
	}

	// ---------- 分类 ----------

	private static function term_object( $term_id, string $taxonomy, string $name = '' ): array {
		$term = get_term( (int) $term_id, $taxonomy );
		return array(
			'type'  => $taxonomy,
			'id'    => (int) $term_id,
			'label' => $term instanceof \WP_Term ? $term->name : $name,
		);
	}

	public static function on_created_term( $term_id, $tt_id, $taxonomy ): void {
		if ( isset( self::TAXONOMIES[ $taxonomy ] ) ) {
			self::log( self::TAXONOMIES[ $taxonomy ], 'create', self::term_object( $term_id, $taxonomy ) );
		}
	}

	public static function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
		if ( isset( self::TAXONOMIES[ $taxonomy ] ) ) {
			self::log( self::TAXONOMIES[ $taxonomy ], 'edit', self::term_object( $term_id, $taxonomy ) );
		}
	}

	public static function on_delete_term( $term, $tt_id, $taxonomy, $deleted = null, $object_ids = array() ): void {
		if ( isset( self::TAXONOMIES[ $taxonomy ] ) ) {
			self::log(
				self::TAXONOMIES[ $taxonomy ],
				'delete',
				array(
					'type'  => $taxonomy,
					'id'    => (int) $term,
					'label' => $deleted instanceof \WP_Term ? $deleted->name : '',
				)
			);
		}
	}

	// ---------- 评论（商品评论） ----------

	public static function on_comment_status( $new, $old, $comment ): void {
		if ( ! $comment instanceof \WP_Comment || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}
		$map   = array( 'approved' => 'approve', 'unapproved' => 'unapprove', 'spam' => 'spam', 'trash' => 'trash' );
		$event = $map[ $new ] ?? 'edit';
		self::log( 'review', $event, array( 'type' => 'review', 'id' => (int) $comment->comment_ID, 'label' => get_the_title( (int) $comment->comment_post_ID ) ), array( 'author' => $comment->comment_author ) );
	}

	public static function on_delete_comment( $id, $comment = null ): void {
		if ( $comment instanceof \WP_Comment && 'product' === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::log( 'review', 'delete', array( 'type' => 'review', 'id' => (int) $id, 'label' => get_the_title( (int) $comment->comment_post_ID ) ), array( 'author' => $comment->comment_author ) );
		}
	}

	// ---------- 订单 ----------

	private static function order_object( $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		return array(
			'type'  => 'order',
			'id'    => (int) $order_id,
			'label' => $order ? '#' . $order->get_order_number() : '#' . (int) $order_id,
		);
	}

	public static function on_order_status( $order_id, $from, $to ): void {
		self::log( 'order', 'status', self::order_object( $order_id ), array( 'from' => (string) $from, 'to' => (string) $to ) );
	}

	public static function on_refund( $order_id, $refund_id ): void {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		self::log( 'order', 'refund', self::order_object( $order_id ), $refund ? array( 'amount' => (string) $refund->get_amount(), 'reason' => (string) $refund->get_reason() ) : array() );
	}

	public static function on_order_edit( $order_id ): void {
		self::log( 'order', 'edit', self::order_object( $order_id ) );
	}

	public static function on_wc_settings(): void {
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$module = array( 'checkout' => 'payment', 'shipping' => 'shipping', 'tax' => 'tax' )[ $tab ] ?? 'settings';
		$sect   = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::log( $module, 'save', array( 'type' => 'wc_settings', 'label' => 'WooCommerce: ' . $tab . ( '' !== $sect ? ' / ' . $sect : '' ) ) );
	}

	// ---------- 账号 ----------

	private static function user_module( int $user_id ): string {
		return self::is_staff( $user_id ) ? 'staff' : 'customer';
	}

	private static function user_object( $user ): array {
		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );
		return array(
			'type'  => 'user',
			'id'    => $user ? $user->ID : 0,
			'label' => $user ? $user->user_login : '',
		);
	}

	public static function on_user_register( $user_id ): void {
		if ( get_current_user_id() && get_current_user_id() !== (int) $user_id ) {
			self::log( self::user_module( (int) $user_id ), 'create', self::user_object( $user_id ) );
		}
	}

	public static function on_profile_update( $user_id ): void {
		// 区块编辑器会通过接口保存自己的界面偏好（也算更新资料），不记。
		if ( get_current_user_id() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			self::log( self::user_module( (int) $user_id ), 'edit', self::user_object( $user_id ) );
		}
	}

	public static function on_deleted_user( $id, $reassign = null, $user = null ): void {
		$module = $user instanceof \WP_User && array_intersect( (array) $user->roles, self::STAFF_ROLES ) ? 'staff' : 'customer';
		self::log( $module, 'delete', array( 'type' => 'user', 'id' => (int) $id, 'label' => $user instanceof \WP_User ? $user->user_login : '' ) );
	}

	// ---------- 素材、插件 ----------

	public static function on_add_attachment( $id ): void {
		self::log( 'media', 'upload', array( 'type' => 'attachment', 'id' => (int) $id, 'label' => wp_basename( (string) get_attached_file( (int) $id ) ) ) );
	}

	public static function on_delete_attachment( $id ): void {
		self::log( 'media', 'delete', array( 'type' => 'attachment', 'id' => (int) $id, 'label' => wp_basename( (string) get_attached_file( (int) $id ) ) ) );
	}

	public static function on_plugin_activated( $plugin ): void {
		self::log( 'app', 'enable', array( 'type' => 'plugin', 'label' => (string) $plugin ) );
	}

	public static function on_plugin_deactivated( $plugin ): void {
		self::log( 'app', 'disable', array( 'type' => 'plugin', 'label' => (string) $plugin ) );
	}

	// ---------- 插件自己的后台表单和接口 ----------

	/** admin-post.php 的 pc_ 动作：等处理完（没有被拒绝）再记。 */
	public static function watch_admin_post(): void {
		global $pagenow;
		if ( 'admin-post.php' !== $pagenow || ! is_user_logged_in() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! str_starts_with( $action, 'pc_' ) || str_starts_with( $action, 'pc_staff_' ) ) {
			return;
		}
		register_shutdown_function(
			static function () use ( $action ): void {
				// 被拒绝的不记；已经有更具体的记录（例如新建分组、删除域名）时也不再记一条通用的。
				if ( (int) http_response_code() >= 400 || self::$written > 0 ) {
					return;
				}
				$details = self::action_details();
				$label   = (string) ( $details['domain'] ?? $details['name'] ?? $details['domains'] ?? '' );
				if ( '' === $label && ! empty( $details['post'] ) && ctype_digit( (string) $details['post'] ) ) {
					$label = (string) get_the_title( (int) $details['post'] );
				}
				self::log( self::action_module( $action ), self::action_event( $action ), array( 'type' => 'action', 'label' => $label ), array( 'action' => $action ) + $details );
			}
		);
	}

	public static function action_module( string $action ): string {
		foreach ( self::ACTION_PREFIXES as $prefix => $module ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return $module;
			}
		}
		return 'settings';
	}

	public static function action_event( string $action ): string {
		$tokens = explode( '_', $action );
		$checks = array(
			'export'    => array( 'export' ),
			'import'    => array( 'import' ),
			'delete'    => array( 'delete', 'remove', 'trash', 'void', 'purge' ),
			'create'    => array( 'add', 'create', 'new', 'duplicate' ),
			'check'     => array( 'check', 'scan' ),
			'upload'    => array( 'upload' ),
			'collect'   => array( 'collect' ),
			'approve'   => array( 'approve' ),
			'status'    => array( 'toggle', 'sticky', 'primary', 'default', 'enable' ),
		);
		foreach ( $checks as $event => $words ) {
			if ( array_intersect( $words, $tokens ) ) {
				return $event;
			}
		}
		return 'save';
	}

	/** 表单里能看出对象的几个字段（域名、模板、编号等），方便看明细。 */
	private static function action_details(): array {
		$out = array();
		foreach ( array( 'domain', 'domains', 'template', 'id', 'post', 'op', 'target', 'name' ) as $key ) {
			if ( ! isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				continue;
			}
			$v = wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$v = is_array( $v ) ? implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', array_slice( $v, 0, 20 ) ) ) ) : sanitize_text_field( (string) $v );
			if ( '' !== $v ) {
				$out[ $key ] = mb_substr( $v, 0, 200 );
			}
		}
		return $out;
	}

	/** 装修相关接口（区块编辑器、菜单）的写请求。 */
	public static function on_rest( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || is_wp_error( $response ) ) {
			return $response;
		}
		$route = $request->get_route();
		if ( str_starts_with( $route, '/peptide/v1/' ) && preg_match( '#/(layout|template|menu|design)#', $route ) ) {
			self::log( 'design', 'save', array( 'type' => 'rest', 'label' => $route ) );
		}
		return $response;
	}

	// ---------- 读取 ----------

	/**
	 * @param array{user?:string,module?:string,from?:string,to?:string} $f 日期为站点时区的 Y-m-d
	 * @return array{0:array<int,array>,1:int}
	 */
	public static function query( array $f, int $paged = 1, int $per_page = 50 ): array {
		self::ready();
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['user'] ) ) {
			$where[] = 'user_login LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $f['user'] ) . '%';
		}
		if ( ! empty( $f['module'] ) ) {
			$where[] = 'module = %s';
			$args[]  = (string) $f['module'];
		}
		foreach ( array( 'from' => '>=', 'to' => '<' ) as $key => $op ) {
			if ( ! empty( $f[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f[ $key ] ) ) {
				$local   = 'to' === $key ? gmdate( 'Y-m-d', (int) strtotime( $f[ $key ] . ' +1 day' ) ) : $f[ $key ];
				$where[] = "created_at {$op} %s";
				$args[]  = get_gmt_from_date( $local . ' 00:00:00' );
			}
		}
		$sql_where = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}", $args ) : "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$offset    = ( max( 1, $paged ) - 1 ) * $per_page;
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( is_array( $rows ) ? $rows : array(), $total );
	}

	public static function get( int $id ): ?array {
		self::ready();
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/** 每天删掉一年以前的记录。 */
	public static function prune(): int {
		self::ready();
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
