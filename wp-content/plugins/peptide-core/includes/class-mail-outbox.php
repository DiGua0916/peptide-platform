<?php
/**
 * 店铺发件箱（2026-11-09）：记录这家店发出的每一封邮件，并提供手动发信。
 *
 * - 所有 wp_mail()（WooCommerce 订单通知、弃单提醒、注册邮件、后台手动写的信）都会进这张表：
 *   收件人、主题、正文、类型（哪封系统邮件 / 手动）、结果（已发出 / 失败 + 原因）、时间、操作人。
 * - 结果来自 wp_mail_succeeded / wp_mail_failed：经总后台邮件中心代发时，邮件中心拒收也会记成失败并带原因。
 * - 正文保留 90 天，其余字段 180 天。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Mail_Outbox {

	public const DB_OPTION  = 'peptide_core_mail_outbox_db';
	public const DB_VERSION = '1';
	public const KEEP_DAYS  = 180;
	public const BODY_DAYS  = 90;
	public const TEMPLATES  = 'pc_mail_templates';

	/** 当前这封 wp_mail 的记录编号（wp_mail 过滤器里建，succeeded/failed 里更新）。 */
	private static int $current = 0;
	/** 手动发信时由 Mail_Page 设置，记在“类型”里。 */
	private static string $manual_kind = '';
	private static int $manual_order = 0;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_mail_outbox';
	}

	public static function register_hooks(): void {
		add_filter( 'wp_mail', array( self::class, 'on_wp_mail' ), 1 );
		add_action( 'wp_mail_succeeded', array( self::class, 'on_succeeded' ), 10, 1 );
		add_action( 'wp_mail_failed', array( self::class, 'on_failed' ), 10, 1 );
		// WooCommerce 邮件发完会触发 woocommerce_email_sent，用它把刚才那条记录标成具体类型。
		add_action( 'woocommerce_email_sent', array( self::class, 'on_wc_sent' ), 10, 3 );
		// 顾客看到的店名统一用英文品牌名（vialcoveplus.com → VialCovePlus），店铺后台里的中文店名（投手名）不进邮件。
		add_filter( 'woocommerce_email_format_string', array( self::class, 'brand_in_string' ) );
		add_filter( 'woocommerce_email_footer_text', array( self::class, 'brand_in_string' ) );
		add_filter( 'pre_option_blogname', array( self::class, 'brand_while_rendering' ) );
	}

	/** 顾客看到的品牌名：按店铺公开域名推（vialcoveplus.com → VialCovePlus），认不出来用主题里的品牌名。 */
	public static function brand(): string {
		static $brand = null;
		if ( null !== $brand ) {
			return $brand;
		}
		$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$label = (string) strtok( preg_replace( '/^www\./', '', $host ), '.' );
		if ( str_starts_with( $label, 'vialcove' ) ) {
			$brand = 'peptides' === substr( $label, 8 ) ? 'VialCove' : 'VialCove' . ucfirst( substr( $label, 8 ) ); // 主店 vialcovepeptides.com 的品牌就叫 VialCove
		} else {
			$brand = function_exists( 'vc_brand_name' ) ? vc_brand_name() : wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		}
		return $brand;
	}

	public static function brand_in_string( $string ) {
		$raw = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		return '' !== $raw && is_string( $string ) ? str_replace( array( $raw, esc_html( $raw ) ), self::brand(), $string ) : $string;
	}

	/** 邮件头到邮件尾之间读到的站点名都换成品牌名（模板里 get_bloginfo('name') 的位置）。 */
	public static function brand_while_rendering( $pre ) {
		if ( doing_action( 'woocommerce_email_header' ) || doing_action( 'woocommerce_email_footer' ) || did_action( 'woocommerce_email_header' ) > did_action( 'woocommerce_email_footer' ) ) {
			return self::brand();
		}
		return $pre;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(64) NOT NULL DEFAULT '',
  recipient varchar(255) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'queued',
  error varchar(255) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_ms bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY created (created_ms),
  KEY kind_time (kind,created_ms),
  KEY recipient (recipient(64))
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function installed(): bool {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	private static function ready(): bool {
		if ( self::installed() ) {
			return true;
		}
		try {
			self::install();
		} catch ( \Throwable $e ) {
			return false;
		}
		return self::installed();
	}

	/** 手动发信前调用，让这封信记成“手动”并关联订单。 */
	public static function mark_manual( string $kind = 'manual', int $order_id = 0 ): void {
		self::$manual_kind  = $kind;
		self::$manual_order = $order_id;
	}

	/** @param array $atts to/subject/message/headers/attachments */
	public static function on_wp_mail( $atts ) {
		if ( ! is_array( $atts ) || ! self::ready() ) {
			return $atts;
		}
		global $wpdb;
		$to = $atts['to'] ?? '';
		$to = is_array( $to ) ? implode( ', ', array_map( 'strval', $to ) ) : (string) $to;
		$kind = '' !== self::$manual_kind ? self::$manual_kind : 'system';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'kind'       => substr( $kind, 0, 64 ),
				'recipient'  => mb_substr( $to, 0, 255 ),
				'subject'    => mb_substr( wp_strip_all_tags( (string) ( $atts['subject'] ?? '' ) ), 0, 255 ),
				'body'       => (string) ( $atts['message'] ?? '' ),
				'status'     => 'queued',
				'order_id'   => self::$manual_order,
				'user_id'    => '' !== self::$manual_kind ? get_current_user_id() : 0,
				'created_ms' => (int) floor( microtime( true ) * 1000 ),
			)
		);
		self::$current     = (int) $wpdb->insert_id;
		self::$manual_kind = '';
		self::$manual_order = 0;
		if ( 1 === wp_rand( 1, 200 ) ) {
			self::prune();
		}
		return $atts;
	}

	public static function on_succeeded( $atts ): void {
		self::finish( 'sent', '' );
	}

	public static function on_failed( $error ): void {
		$msg = $error instanceof \WP_Error ? $error->get_error_message() : '发送失败';
		self::finish( 'failed', self::plain_error( $msg ) );
	}

	/** WooCommerce 自己记的结果（比 wp_mail_succeeded 晚一点），补上类型。 */
	public static function on_wc_sent( $ok, $id, $email = null ): void {
		if ( ! self::$current || ! self::installed() ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'kind' => 'wc:' . substr( (string) $id, 0, 60 ), 'order_id' => $email instanceof \WC_Email && $email->object instanceof \WC_Order ? $email->object->get_id() : 0 ), array( 'id' => self::$current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function finish( string $status, string $error ): void {
		if ( ! self::$current || ! self::installed() ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => $status, 'error' => mb_substr( $error, 0, 255 ) ), array( 'id' => self::$current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// 记录编号留着，woocommerce_email_sent 还要用它补类型；下一封 wp_mail 会覆盖。
	}

	/** 把技术报错翻成店主看得懂的话。 */
	private static function plain_error( string $msg ): string {
		$m = strtolower( $msg );
		if ( str_contains( $m, 'daily sending limit' ) || str_contains( $m, '每日发送上限' ) ) {
			return '今天发信量已到上限，明天自动恢复';
		}
		if ( str_contains( $m, 'sending too fast' ) || str_contains( $m, '每小时' ) ) {
			return '这家店一小时内发得太多，稍后再发';
		}
		if ( str_contains( $m, 'not available' ) || str_contains( $m, '503' ) || str_contains( $m, '未连接' ) ) {
			return '总后台的发信邮箱没连上';
		}
		if ( str_contains( $m, 'invalid address' ) || str_contains( $m, 'invalid recipients' ) ) {
			return '收件地址不对';
		}
		if ( str_contains( $m, 'curl' ) || str_contains( $m, 'timed out' ) || str_contains( $m, 'could not resolve' ) ) {
			return '连不上总后台邮件中心';
		}
		return mb_substr( str_replace( 'Mail center: ', '邮件中心：', $msg ), 0, 200 );
	}

	public static function prune(): void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_ms < %d", (int) ( ( time() - self::KEEP_DAYS * DAY_IN_SECONDS ) * 1000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET body = NULL WHERE body IS NOT NULL AND created_ms < %d", (int) ( ( time() - self::BODY_DAYS * DAY_IN_SECONDS ) * 1000 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 查询 ----------

	/**
	 * @param array{kind?:string,status?:string,q?:string,order_id?:int} $f
	 * @return array{items:array,total:int}
	 */
	public static function query( array $f, int $page = 1, int $per = 40 ): array {
		if ( ! self::ready() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['kind'] ) ) {
			if ( 'auto' === $f['kind'] ) {
				$where[] = "kind LIKE 'wc:%'";
			} elseif ( 'manual' === $f['kind'] ) {
				$where[] = "kind = 'manual'";
			} elseif ( 'system' === $f['kind'] ) {
				$where[] = "kind = 'system'";
			} else {
				$where[] = 'kind = %s';
				$args[]  = (string) $f['kind'];
			}
		}
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( ! empty( $f['order_id'] ) ) {
			$where[] = 'order_id = %d';
			$args[]  = (int) $f['order_id'];
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$where[] = '(recipient LIKE %s OR subject LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql   = 'WHERE ' . implode( ' AND ', $where );
		$count = $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} {$sql}", $args ) : "SELECT COUNT(*) FROM {$t} {$sql}"; // phpcs:ignore WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $count ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$off   = max( 0, ( $page - 1 ) * $per );
		$list  = $wpdb->prepare( "SELECT id,kind,recipient,subject,status,error,order_id,user_id,created_ms,(body IS NOT NULL) AS has_body FROM {$t} {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $off ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'items' => (array) $wpdb->get_results( $list, ARRAY_A ), 'total' => $total ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function get( int $id ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/** @return array{today:int,week:int,failed_week:int,manual_week:int} */
	public static function stats(): array {
		if ( ! self::ready() ) {
			return array( 'today' => 0, 'week' => 0, 'failed_week' => 0, 'manual_week' => 0 );
		}
		global $wpdb;
		$t     = self::table();
		$day   = (int) ( strtotime( 'today midnight', time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) * 1000;
		$week  = ( time() - 7 * DAY_IN_SECONDS ) * 1000;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status='sent' AND created_ms>=%d) today, SUM(status='sent' AND created_ms>=%d) week, SUM(status='failed' AND created_ms>=%d) failed_week, SUM(kind='manual' AND created_ms>=%d) manual_week FROM {$t}", $day, $week, $week, $week ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'today' => (int) ( $row['today'] ?? 0 ), 'week' => (int) ( $row['week'] ?? 0 ), 'failed_week' => (int) ( $row['failed_week'] ?? 0 ), 'manual_week' => (int) ( $row['manual_week'] ?? 0 ) );
	}

	/** 类型编号 → 中文名（wc:customer_processing_order → 订单处理中通知）。 */
	public static function kind_label( string $kind ): string {
		if ( 'manual' === $kind ) {
			return '手动发送';
		}
		if ( 'system' === $kind || '' === $kind ) {
			return '系统邮件';
		}
		$id = str_starts_with( $kind, 'wc:' ) ? substr( $kind, 3 ) : $kind;
		$map = array(
			'new_order'                 => '新订单（给店主）',
			'cancelled_order'           => '订单取消（给店主）',
			'failed_order'              => '订单失败（给店主）',
			'customer_on_hold_order'    => '订单保留通知',
			'customer_processing_order' => '付款成功 · 订单处理中',
			'customer_completed_order'  => '订单已完成',
			'customer_refunded_order'   => '退款通知',
			'customer_partially_refunded_order' => '部分退款通知',
			'customer_invoice'          => '账单 / 付款链接',
			'customer_note'             => '订单备注通知',
			'customer_reset_password'   => '重置密码',
			'customer_new_account'      => '新账户欢迎',
			'customer_failed_order'     => '付款失败通知',
			'customer_cancelled_order'  => '订单已取消（给顾客）',
			'pc_cart_recovery'          => '弃单提醒',
			'pc_cart_recovery_1'        => '弃单提醒 ①',
			'pc_cart_recovery_2'        => '弃单提醒 ②',
			'pc_cart_recovery_3'        => '弃单提醒 ③',
			'pc_browse_reminder'        => '浏览提醒',
			'pc_register_verify'        => '注册验证',
			'pc_register_invite'        => '注册邀请',
			'pc_admin_abandoned'        => '弃单通知（给店主）',
			'pc_admin_duplicate'        => '重复下单提醒（给店主）',
			'pc_admin_offline'          => '离线付款提醒（给店主）',
		);
		if ( isset( $map[ $id ] ) ) {
			return $map[ $id ];
		}
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( WC()->mailer()->get_emails() as $e ) {
				if ( $e->id === $id ) {
					return wp_strip_all_tags( (string) $e->get_title() );
				}
			}
		}
		return $id;
	}

	// ---------- 模板 ----------

	/** 内置模板版本：升级时把内置的（id 以 vc- 开头）换成新话术，店主自己建的保留。 */
	public const TEMPLATES_VERSION = '2026-11-09c';
	private const TEMPLATES_VER_OPTION = 'pc_mail_templates_version';

	/** @return array<int,array{id:string,name:string,subject:string,body:string}> */
	public static function templates(): array {
		$t = get_option( self::TEMPLATES, null );
		if ( ! is_array( $t ) || self::TEMPLATES_VERSION !== get_option( self::TEMPLATES_VER_OPTION ) ) {
			$custom = array();
			foreach ( is_array( $t ) ? $t : array() as $row ) {
				$id = (string) ( $row['id'] ?? '' );
				if ( '' !== $id && ! str_starts_with( $id, 'vc-' ) && ! in_array( $id, array( 'payment', 'shipped', 'followup', 'blank' ), true ) ) {
					$custom[] = $row;
				}
			}
			$t = array_merge( self::default_templates(), $custom );
			update_option( self::TEMPLATES, $t, false );
			update_option( self::TEMPLATES_VER_OPTION, self::TEMPLATES_VERSION, false );
		}
		return array_values( $t );
	}

	public static function save_template( array $in ): array {
		$list = self::templates();
		$id   = sanitize_key( (string) ( $in['id'] ?? '' ) );
		$row  = array(
			'id'      => '' !== $id ? $id : 't' . substr( md5( uniqid( '', true ) ), 0, 8 ),
			'name'    => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 60 ),
			'subject' => mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, 200 ),
			'body'    => wp_kses_post( (string) ( $in['body'] ?? '' ) ),
		);
		$found = false;
		foreach ( $list as $i => $t ) {
			if ( $t['id'] === $row['id'] ) {
				$list[ $i ] = $row;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $row;
		}
		update_option( self::TEMPLATES, $list, false );
		return $row;
	}

	public static function delete_template( string $id ): void {
		$list = array_values( array_filter( self::templates(), static fn( $t ) => $t['id'] !== $id ) );
		update_option( self::TEMPLATES, $list, false );
	}

	/**
	 * 内置模板 = 用户 2026-11-09 的追单母版（VialCove-chase-pack）：
	 * 01 弃单跟进、02 支付问题、成交未留 WhatsApp 感谢、03 物流追踪、04 WhatsApp 联系。
	 * 发件邮箱换成各店自己的 orders@ 地址（{store_email}），WhatsApp 号与口号照母版。黑五段按母版默认保留，不用时整段删掉。
	 */
	private static function default_templates(): array {
		$wa     = '{whatsapp}'; // 发送时换成店铺后台「客服联系方式」里的 WhatsApp 号。
		$wa_btn = '<p style="margin:24px 0"><a href="https://wa.me/13039444124" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:6px">Chat with us on WhatsApp → ' . $wa . '</a></p>';
		$sign   = '<p>Best regards,<br>VialCove Team<br>WhatsApp: ' . $wa . '<br>Email: {store_email}</p><p style="color:#6b7280;font-size:13px">Peptides are our focus. Your experience is our priority.</p>';
		$bf     = '<p>We\'re also running a Black Friday promotion, with special pricing on many peptide products. If you\'re still considering an order, we\'d be happy to share the available offers and help you review your selection.</p>';
		$care   = '<p>Your experience with VialCove matters to us. If you encountered any difficulties during checkout or have questions about your order, please let us know—we\'re happy to help.</p>';
		return array(
			array(
				'id'      => 'vc-followup',
				'name'    => '01 弃单跟进（未付款 · 已填地址）',
				'subject' => 'VialCove | A Quick Follow-Up on Your Order',
				'body'    => '<p>Dear {customer_name},</p><p>We\'re getting in touch to see whether you need any help completing your order.</p><p>We tried reaching you on WhatsApp but haven\'t been able to get in touch, so we\'re following up by email.</p>' . $care . $bf . '<p>Could you please reply to this email or message us on WhatsApp at ' . $wa . '? If your WhatsApp number has changed, please let us know the best number to reach you.</p><p>For your reference, your order {order_number} (order total: {order_total}) is still waiting for you: <a href="{pay_url}">complete your order here</a>.</p>' . $wa_btn . '<p>We look forward to hearing from you and assisting with your order.</p>' . $sign,
			),
			array(
				'id'      => 'vc-payment',
				'name'    => '02 支付问题（支付失败 · PayPal）',
				'subject' => 'VialCove | Help Completing Your Payment',
				'body'    => '<p>Dear {customer_name},</p><p>We\'re following up on your recent order {order_number} to see whether you need any help completing payment.</p><p>Could you let us know whether the lack of a PayPal option at checkout was the reason you did not complete your order?</p><p>If you prefer to pay with PayPal, please contact us on WhatsApp at ' . $wa . ', and we can manually create an order with a PayPal payment option for you.</p><p>We do not currently offer PayPal directly on our website due to past instances of payment misuse. Thank you for your understanding.</p><p>Your experience with VialCove matters to us. If you have any questions about checkout or your order, please let us know—we\'re happy to help.</p>' . $bf . '<p>For your reference, the order total is {order_total}. You can also retry the payment here: <a href="{pay_url}">{pay_url}</a>.</p>' . $wa_btn . '<p>We look forward to hearing from you and helping you complete your order.</p>' . $sign,
			),
			array(
				'id'      => 'vc-thanks-whatsapp',
				'name'    => '成交感谢 · 请加 WhatsApp（已付款未留号）',
				'subject' => 'VialCove | Thanks for Your Order — Add Us on WhatsApp',
				'body'    => '<p>Dear {customer_name},</p><p>Thank you for your order {order_number} (order total: {order_total}). We\'ve received your payment and will process shipment shortly.</p><p>For faster updates on shipping and any questions about your peptides, please add us on WhatsApp at ' . $wa . ' and send a quick hello. That is the easiest way for us to reach you with tracking and support.</p>' . $wa_btn . '<p>We look forward to chatting with you on WhatsApp.</p><p>Best regards,<br>VialCove Team<br>WhatsApp: ' . $wa . '<br>Email: {store_email}</p>',
			),
			array(
				'id'      => 'vc-tracking',
				'name'    => '03 物流追踪（个人关怀）',
				'subject' => 'VialCove | A Personal Thank-You for Your Order',
				'body'    => '<p>Hi {customer_name} 😊</p><p>I saw your order on our website and wanted to reach out personally to thank you for choosing VialCove. I really appreciate your trust and support.</p><p>I\'ll personally keep a close eye on your order until it reaches you. As soon as I have any shipping or tracking updates, I\'ll send them to you right away.</p><p>If you have any questions or need help along the way, please feel free to message me anytime. You can reply to this email or contact me directly on WhatsApp at ' . $wa . '. I\'m here to help and make sure you feel well looked after.</p><p>Your order: {order_number} · Tracking number: {tracking}</p>' . $wa_btn . '<p>Thank you again for placing your trust in VialCove. I\'ll be here to help every step of the way.</p>' . $sign,
			),
			array(
				'id'      => 'vc-whatsapp',
				'name'    => '04 WhatsApp 联系（下单后联系不上）',
				'subject' => 'VialCove | Let\'s Stay in Touch About Your Order',
				'body'    => '<p>Hi {customer_name},</p><p>Thank you for placing your order with VialCove. I truly appreciate your trust and wanted to make sure you have an easy way to reach me.</p><p>I wasn\'t able to reach you on WhatsApp, so I\'m getting in touch by email to share my contact details.</p><p>If WhatsApp is convenient for you, please save our number, ' . $wa . ', and send me a message whenever you\'re ready. If you don\'t currently use WhatsApp, you\'re always welcome to reply to this email—whichever works best for you.</p><p>Your shopping experience matters to us. If you have any questions about your order or need a hand, please let me know. I\'m here to help and make sure you feel well looked after.</p>' . $wa_btn . '<p>Thank you again for choosing VialCove. I look forward to staying in touch.</p>' . $sign,
			),
			array(
				'id'      => 'vc-blank',
				'name'    => '空白（带签名）',
				'subject' => '',
				'body'    => '<p>Dear {customer_name},</p><p></p>' . $sign,
			),
		);
	}

	// ---------- 手动发送 ----------

	/**
	 * 替换占位符并发出。@return true|\WP_Error
	 *
	 * @param string[] $to
	 */
	public static function send_manual( array $to, string $subject, string $body, int $order_id = 0 ) {
		$to = array_values( array_unique( array_filter( array_map( 'sanitize_email', $to ), 'is_email' ) ) );
		if ( ! $to ) {
			return new \WP_Error( 'pc_mail_to', '请填写至少一个有效的收件邮箱。' );
		}
		if ( count( $to ) > 20 ) {
			return new \WP_Error( 'pc_mail_to', '一次最多发给 20 个收件人；要发更多请用“营销活动 → 邮件活动”。' );
		}
		$subject = trim( $subject );
		$body    = trim( $body );
		if ( '' === $subject || '' === wp_strip_all_tags( $body ) ) {
			return new \WP_Error( 'pc_mail_empty', '主题和正文都要填。' );
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		$vars  = self::vars( $order instanceof \WC_Order ? $order : null, $to[0] );
		$subject = strtr( $subject, $vars );
		$body    = strtr( $body, $vars );
		$html    = self::wrap( $subject, wpautop( $body ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = sanitize_email( (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		if ( is_email( $reply ) && ! str_ends_with( $reply, '.local' ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		self::mark_manual( 'manual', $order instanceof \WC_Order ? $order->get_id() : 0 );
		$ok = wp_mail( $to, $subject, $html, $headers );
		if ( ! $ok ) {
			$row = self::$current ? self::get( self::$current ) : null;
			self::$current = 0;
			return new \WP_Error( 'pc_mail_failed', '没发出去：' . ( $row['error'] ?? '发送失败' ) );
		}
		return true;
	}

	/** @return array<string,string> 占位符。 */
	public static function vars( ?\WC_Order $order, string $to ): array {
		$store = self::brand();
		$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$vars  = array(
			'{store_name}'    => $store,
			'{store_url}'     => home_url( '/' ),
			'{store_email}'   => class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::store_email() : 'orders@' . preg_replace( '/^www\./', '', $host ),
			'{whatsapp}'      => class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::whatsapp() : '',
			'{customer_name}' => '',
			'{order_number}'  => '',
			'{order_total}'   => '',
			'{pay_url}'       => '',
			'{tracking}'      => '',
		);
		if ( $order ) {
			$name = trim( $order->get_billing_first_name() ?: $order->get_shipping_first_name() );
			$vars['{customer_name}'] = '' !== $name ? $name : 'there';
			$vars['{order_number}']  = '#' . $order->get_order_number();
			$vars['{order_total}']   = wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total() ) );
			$vars['{pay_url}']       = $order->needs_payment() ? $order->get_checkout_payment_url() : $order->get_view_order_url();
			$tracking                = (string) $order->get_meta( '_pc_tracking_number', true );
			$vars['{tracking}']      = '' !== $tracking ? $tracking : '—';
		} else {
			$user = get_user_by( 'email', $to );
			$vars['{customer_name}'] = $user ? ( $user->first_name ?: $user->display_name ) : 'there';
		}
		return $vars;
	}

	/** 套上店铺邮件模板（和订单邮件同一套头尾）。 */
	public static function wrap( string $heading, string $content ): string {
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer = WC()->mailer();
			return $mailer->wrap_message( $heading, $content );
		}
		return '<html><body>' . $content . '</body></html>';
	}

	/** 收件人搜索：顾客账号和最近订单里的邮箱。 */
	public static function search_recipients( string $q, int $limit = 12 ): array {
		global $wpdb;
		$q = trim( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$out  = array();
		$users = get_users( array( 'search' => '*' . $q . '*', 'search_columns' => array( 'user_email', 'display_name', 'user_login' ), 'number' => $limit, 'fields' => array( 'ID', 'user_email', 'display_name' ) ) );
		foreach ( $users as $u ) {
			$out[ strtolower( $u->user_email ) ] = array( 'email' => $u->user_email, 'name' => $u->display_name, 'note' => '顾客账号' );
		}
		$t = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT billing_email, id, status, total_amount FROM {$t} WHERE type='shop_order' AND (billing_email LIKE %s OR id = %d) ORDER BY id DESC LIMIT %d", $like, (int) ltrim( $q, '#' ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$e = strtolower( (string) $r['billing_email'] );
				if ( '' === $e ) {
					continue;
				}
				$order = wc_get_order( (int) $r['id'] );
				$name  = $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '';
				if ( ! isset( $out[ $e ] ) ) {
					$out[ $e ] = array( 'email' => $r['billing_email'], 'name' => $name, 'note' => '最近订单 #' . $r['id'], 'order_id' => (int) $r['id'] );
				} elseif ( empty( $out[ $e ]['order_id'] ) ) {
					$out[ $e ]['order_id'] = (int) $r['id'];
					$out[ $e ]['note']    .= ' · 订单 #' . $r['id'];
				}
			}
		}
		return array_slice( array_values( $out ), 0, $limit );
	}
}
