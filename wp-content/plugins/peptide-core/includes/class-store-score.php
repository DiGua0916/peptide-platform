<?php
/**
 * 店铺评分（店铺完善度，店主 2026-11-10）：店主把店铺一项项设置好，分数就往上涨，满分 100。
 * 仪表盘右栏“店铺评分”默认显示这个；切到“运营评分”看 AI 全站分析的健康分。
 * 每项都是实时检查当前设置，不是 AI 判断。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Store_Score {

	/** @return array<int,array{key:string,label:string,points:int,done:bool,url:string,hint:string}> */
	public static function items(): array {
		$out = array();
		$add = static function ( string $key, string $label, int $points, bool $done, string $url, string $hint ) use ( &$out ): void {
			$out[] = array( 'key' => $key, 'label' => $label, 'points' => $points, 'done' => $done, 'url' => $url, 'hint' => $hint );
		};
		$basic   = class_exists( Admin\Basic_Config_Page::class ) ? Admin\Basic_Config_Page::url() : admin_url();
		$contact = class_exists( Admin\Contact_Page::class ) ? Admin\Contact_Page::url() : admin_url();
		$pixels  = class_exists( Admin\Pixels_Page::class ) ? Admin\Pixels_Page::url() : admin_url();

		$add( 'address', '填写店铺实体地址', 15, '' !== trim( (string) get_option( 'woocommerce_store_address', '' ) ), $basic, '没有地址，弃单提醒等营销邮件发不出去（美国法律要求）' );

		$wa = class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::whatsapp_link() : '';
		// 13039444124 是主店（地瓜）的号；子店还在用它就算没设置自己的。
		$own_wa = '' !== $wa && ( false === strpos( $wa, '13039444124' ) || 's0001' === self::store_code() );
		$add( 'whatsapp', '设置自己的客服 WhatsApp', 10, $own_wa, $contact, '付款成功弹窗、追单邮件里的 WhatsApp 按钮用这个号' );

		$phone = class_exists( Store_Config::class ) ? trim( (string) Store_Config::get( 'company_phone' ) ) : '';
		$add( 'phone', '填写客服电话', 5, '' !== $phone, $basic, '付款成功弹窗里会显示' );

		$px = get_option( 'peptide_core_pixels', array() );
		$fb = is_array( $px ) ? array_filter( (array) ( $px['platforms']['facebook']['pixels'] ?? array() ), static fn( $p ) => is_array( $p ) && '' !== trim( (string) ( $p['id'] ?? '' ) ) ) : array();
		$add( 'pixel', '接入 Facebook Pixel', 15, (bool) $fb, $pixels, '广告才能按下单优化，转化数据才准' );

		$to = '';
		if ( function_exists( 'WC' ) ) {
			$emails = WC()->mailer()->get_emails();
			$to     = isset( $emails['WC_Email_New_Order'] ) ? (string) $emails['WC_Email_New_Order']->get_recipient() : '';
		}
		$real = '' !== $to && ! preg_match( '/\.local\b|^1{6,}@|example\.com/i', $to );
		$add( 'notify', '设置收新订单通知的邮箱', 10, $real, admin_url( 'admin.php?page=wc-settings&tab=email&section=wc_email_new_order' ), '有新订单、顾客留联系方式时会发到这个邮箱' );

		$dopt = get_option( 'peptide_core_domains', array() );
		$doms = is_array( $dopt ) ? (array) ( $dopt['items'] ?? array() ) : array();
		$ok   = $doms && ! array_filter( $doms, static fn( $d ) => 'connected' !== ( $d['conn'] ?? '' ) );
		$add( 'domains', '域名全部连通', 10, $ok, class_exists( Admin\Domains_Page::class ) ? Admin\Domains_Page::url() : admin_url(), '有连不上的域名，顾客会打不开店铺' );

		$paid = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 5, 'return' => 'objects' ) );
		$real_paid = array_filter( $paid, static fn( $o ) => ! ( class_exists( Test_Orders::class ) && Test_Orders::is_test_order( $o ) ) && (float) $o->get_total() > 1 );
		$add( 'paid', '完成第一笔真实付款订单', 10, (bool) $real_paid, admin_url( 'admin.php?page=wc-orders' ), '说明从广告到付款整条路是通的' );

		$coupons = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
		$add( 'coupon', '设置优惠券或首单优惠', 5, (bool) $coupons, class_exists( Admin\Coupons_Page::class ) ? Admin\Coupons_Page::url() : admin_url(), '给犹豫的新顾客一个下单理由' );

		$add( 'paid_popup', '开启付款成功弹窗', 5, class_exists( Paid_Popup::class ) && Paid_Popup::settings()['enabled'], admin_url( 'admin.php?page=pc-paid-popup' ), '付款后引导顾客加 WhatsApp，方便跟踪物流和复购' );

		$add( 'ship_preview', '开启运费提前显示', 5, class_exists( Ship_Preview::class ) && Ship_Preview::settings()['enabled'], admin_url( 'admin.php?page=pc-ship-preview' ), '顾客加购前就知道运费和总价，减少结账时流失' );

		$tuned = class_exists( HQ_AI::class ) && ( '' !== HQ_AI::tune( 'customer' ) || '' !== HQ_AI::tune( 'business' ) );
		$add( 'ai_tune', '写 AI 人设调优', 5, $tuned, admin_url( 'admin.php?page=pc-visitors' ), '把 AI 调成你习惯的分析方式' );

		$report = class_exists( HQ_AI::class ) && null !== HQ_AI::latest( 'site', 0 );
		$add( 'ai_report', '生成第一份 AI 全站分析', 5, $report, admin_url( 'admin.php?page=pc-visitors' ), '看清顾客卡在哪、先改什么' );

		return $out;
	}

	// ---------- 一键完善（店主 2026-11-10）：用店里已有的资料把能填的都填上 ----------

	private static function placeholder_email( string $to ): bool {
		return '' === trim( $to ) || (bool) preg_match( '/\.local\b|^1{6,}@|example\.com/i', $to );
	}

	/** 店铺自己的收发信邮箱（发件地址，顾客回信就到这里）。 */
	private static function own_email(): string {
		$from = trim( (string) get_option( 'woocommerce_email_from_address', '' ) );
		if ( '' === $from || self::placeholder_email( $from ) ) {
			$from = class_exists( Emails\Chase_Copy::class ) ? Emails\Chase_Copy::store_email() : '';
		}
		return is_email( $from ) ? $from : '';
	}

	/** @return array<int,array{label:string,value:string}> 将要填的内容（不改任何东西）。 */
	public static function autofill_plan(): array {
		$plan = array();
		$cfg  = get_option( 'peptide_core_store_config', array() );
		$cfg  = is_array( $cfg ) ? $cfg : array();
		$wa   = preg_replace( '/\D+/', '', (string) ( get_option( 'peptide_core_settings', array() )['support']['whatsapp'] ?? '' ) );
		// 子店还在用主店的 WhatsApp（13039444124）时不能拿来当自己的电话。
		$main_wa = '13039444124' === $wa && 's0001' !== self::store_code();
		if ( '' === trim( (string) ( $cfg['company_phone'] ?? '' ) ) && strlen( $wa ) >= 8 && ! $main_wa ) {
			$plan[] = array( 'key' => 'phone', 'label' => '客服电话', 'value' => '+' . $wa );
		}
		$email = self::own_email();
		if ( '' !== $email && function_exists( 'WC' ) ) {
			$emails = WC()->mailer()->get_emails();
			$cur    = isset( $emails['WC_Email_New_Order'] ) ? (string) $emails['WC_Email_New_Order']->get_recipient() : '';
			if ( self::placeholder_email( $cur ) ) {
				$plan[] = array( 'key' => 'notify', 'label' => '新订单 / 取消 / 失败通知邮箱', 'value' => $email );
			}
		}
		return $plan;
	}

	/** 执行一键完善：只填空着的或占位的，已经填好的不动。 */
	public static function autofill(): array {
		$plan = self::autofill_plan();
		$cfg  = get_option( 'peptide_core_store_config', array() );
		$cfg  = is_array( $cfg ) ? $cfg : array();
		$cfg_changed = false;
		foreach ( $plan as $p ) {
			if ( 'phone' === $p['key'] ) {
				$cfg['company_phone'] = $p['value'];
				$cfg_changed          = true;
			} elseif ( 'name' === $p['key'] ) {
				$cfg['company_name'] = $p['value'];
				$cfg_changed         = true;
			} elseif ( 'notify' === $p['key'] ) {
				foreach ( array( 'woocommerce_new_order_settings', 'woocommerce_cancelled_order_settings', 'woocommerce_failed_order_settings' ) as $opt ) {
					$o = get_option( $opt, array() );
					$o = is_array( $o ) ? $o : array();
					if ( self::placeholder_email( (string) ( $o['recipient'] ?? '' ) ) ) {
						$o['recipient'] = $p['value'];
						update_option( $opt, $o );
					}
				}
			}
		}
		if ( $cfg_changed ) {
			update_option( 'peptide_core_store_config', $cfg );
		}
		return $plan;
	}

	private static function store_code(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) === 'www.vialcovepeptides.com' ? 's0001' : '';
	}

	public static function summary(): array {
		$items = self::items();
		$total = array_sum( array_column( $items, 'points' ) );
		$got   = array_sum( array_map( static fn( $i ) => $i['done'] ? $i['points'] : 0, $items ) );
		usort( $items, static fn( $a, $b ) => ( (int) $a['done'] <=> (int) $b['done'] ) ?: ( $b['points'] <=> $a['points'] ) );
		return array( 'score' => $total ? (int) round( 100 * $got / $total ) : 0, 'done' => count( array_filter( $items, static fn( $i ) => $i['done'] ) ), 'count' => count( $items ), 'items' => $items );
	}
}
