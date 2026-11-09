<?php
/**
 * 费用（2026-11-09）：Cloudflare 流量 / Argo 预估 + 两台 AWS 服务器的 Cost Explorer 费用，加预算和余额提醒。
 *
 * - Cloudflare：用 CDN 页保存的令牌，GraphQL 拉每个域名当月字节数；开了 Argo 的域名按 $5 含 1 GB、之后 $0.10/GB 估算。
 *   账单历史需要令牌有 Billing · Read；分析需要 Zone · Analytics · Read。没权限时页面给出提示，不影响其他部分。
 * - AWS：只读访问密钥（ce:GetCostAndUsage、ce:GetCostForecast、sts:GetCallerIdentity），密钥用 Crypto 加密存库。
 *   Cost Explorer 数据滞后约 24 小时，且每次调用 AWS 收 $0.01，所以每 8 小时自动刷新一次（一次 2 个调用），其余时间看缓存。
 * - 提醒：设了月预算 → 预计月费超预算时开一条“费用提醒”告警并发邮件；填了余额 → 余额不够下个月预计费用时同样提醒。
 *   回到安全线以下自动标记已恢复。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Costs {

	public const OPTION   = 'pchq_costs';
	public const SNAPSHOT = 'pchq_costs_snapshot';
	public const CRON     = 'pchq_costs_refresh';
	public const INTERVAL = 'pchq_costs_8h';

	/** Argo 定价（美元）：前 1 GB 固定 $5，之后每 GB $0.10。 */
	public const ARGO_BASE    = 5.0;
	public const ARGO_INCLUDED = 1.0;
	public const ARGO_PER_GB  = 0.10;

	/** Cloudflare 邮件发送（Workers Paid 内含 3,000 封/月，超出 $0.35 / 1,000 封）。 */
	public const MAIL_INCLUDED = 3000;
	public const MAIL_PER_1000 = 0.35;

	private const CE_HOST  = 'ce.us-east-1.amazonaws.com';
	private const STS_HOST = 'sts.amazonaws.com';

	public static function register_hooks(): void {
		add_filter(
			'cron_schedules',
			static function ( array $s ): array {
				$s[ self::INTERVAL ] = array( 'interval' => 8 * HOUR_IN_SECONDS, 'display' => '每 8 小时' );
				return $s;
			}
		);
		add_action( self::CRON, array( self::class, 'refresh' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 300, self::INTERVAL, self::CRON );
				}
			}
		);
	}

	// ---------- 设置 ----------

	/** 两个 AWS 账号的槽位：前台服务器、总后台服务器。 */
	public const SLOTS = array(
		'web' => '前台服务器账号',
		'hq'  => '总后台服务器账号',
	);

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		$accounts = array();
		foreach ( self::SLOTS as $slot => $label ) {
			$a = is_array( $o['accounts'][ $slot ] ?? null ) ? $o['accounts'][ $slot ] : array();
			$accounts[ $slot ] = array(
				'label'      => $label,
				'key_id'     => (string) ( $a['key_id'] ?? '' ),
				'secret_enc' => (string) ( $a['secret_enc'] ?? '' ),
				'account'    => (string) ( $a['account'] ?? '' ),
				'verified'   => (int) ( $a['verified'] ?? 0 ),
			);
		}
		return array(
			'accounts'   => $accounts,
			'budget'     => (float) ( $o['budget'] ?? 0 ),
			'balance'    => (float) ( $o['balance'] ?? 0 ),
			'balance_at' => (int) ( $o['balance_at'] ?? 0 ),
			'email'      => (string) ( $o['email'] ?? '' ),
		);
	}

	private static function save_settings( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/**
	 * 保存设置。每个槽位的 AWS 密钥留空 = 不换。@return true|\WP_Error
	 */
	public static function update( array $in ) {
		$s = self::settings();
		foreach ( self::SLOTS as $slot => $label ) {
			$key = trim( (string) ( $in[ 'aws_key_id_' . $slot ] ?? '' ) );
			$sec = trim( (string) ( $in[ 'aws_secret_' . $slot ] ?? '' ) );
			if ( '' === $key && '' === $sec ) {
				continue;
			}
			if ( ! preg_match( '/^(AKIA|ASIA)[A-Z0-9]{16}$/D', $key ) ) {
				return new \WP_Error( 'pchq_costs_aws', $label . '：访问密钥 ID 格式不对（AKIA 开头的 20 位大写字母数字）。' );
			}
			if ( strlen( $sec ) < 30 ) {
				return new \WP_Error( 'pchq_costs_aws', $label . '：请同时填写访问密钥 ID 和私有访问密钥。' );
			}
			$who = self::sts_identity( $key, $sec );
			if ( is_wp_error( $who ) ) {
				return new \WP_Error( 'pchq_costs_aws', $label . '：AWS 密钥验证没通过：' . $who->get_error_message() );
			}
			$s['accounts'][ $slot ]['key_id']     = $key;
			$s['accounts'][ $slot ]['secret_enc'] = Crypto::encrypt( $sec );
			$s['accounts'][ $slot ]['account']    = (string) $who;
			$s['accounts'][ $slot ]['verified']   = time();
		}
		$s['budget'] = max( 0, round( (float) str_replace( ',', '', (string) ( $in['budget'] ?? '0' ) ), 2 ) );
		$balance     = max( 0, round( (float) str_replace( ',', '', (string) ( $in['balance'] ?? '0' ) ), 2 ) );
		if ( abs( $balance - $s['balance'] ) > 0.001 || ( $balance > 0 && 0 === $s['balance_at'] ) ) {
			$s['balance_at'] = $balance > 0 ? time() : 0;
		}
		$s['balance'] = $balance;
		$email        = trim( (string) ( $in['email'] ?? '' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'pchq_costs_email', '提醒邮箱格式不对。' );
		}
		$s['email'] = $email;
		self::save_settings( $s );
		return true;
	}

	public static function forget_aws( string $slot ): void {
		$s = self::settings();
		if ( ! isset( $s['accounts'][ $slot ] ) ) {
			return;
		}
		$s['accounts'][ $slot ]['key_id']     = '';
		$s['accounts'][ $slot ]['secret_enc'] = '';
		$s['accounts'][ $slot ]['account']    = '';
		$s['accounts'][ $slot ]['verified']   = 0;
		self::save_settings( $s );
	}

	/** 至少一个账号填了密钥。 */
	public static function has_aws(): bool {
		foreach ( self::settings()['accounts'] as $a ) {
			if ( '' !== $a['secret_enc'] ) {
				return true;
			}
		}
		return false;
	}

	public static function slot_ready( string $slot ): bool {
		return '' !== (string) ( self::settings()['accounts'][ $slot ]['secret_enc'] ?? '' );
	}

	// ---------- 快照 ----------

	public static function snapshot(): array {
		$s = get_option( self::SNAPSHOT, array() );
		return is_array( $s ) ? $s : array();
	}

	/** 拉一遍 Cloudflare + AWS，存快照，算提醒。 */
	public static function refresh(): array {
		$snap = array(
			'at'         => time(),
			'cloudflare' => self::cloudflare(),
			'aws'        => self::aws(),
		);
		$snap['summary'] = self::summary( $snap );
		update_option( self::SNAPSHOT, $snap, false );
		self::check_alerts( $snap );
		return $snap;
	}

	/** 汇总：本月已花、月底预计。 */
	private static function summary( array $snap ): array {
		$cf  = $snap['cloudflare'];
		$aws = $snap['aws'];
		$aws_mtd      = (float) ( $aws['month_to_date'] ?? 0 );
		$aws_forecast = (float) ( $aws['forecast'] ?? 0 );
		$argo         = (float) ( $cf['argo_estimate'] ?? 0 );
		$argo_month   = (float) ( $cf['argo_forecast'] ?? $argo );
		$fixed        = (float) ( $cf['fixed_monthly'] ?? 0 );
		$mail         = (float) ( $cf['mail_estimate'] ?? 0 );
		$mail_month   = (float) ( $cf['mail_forecast'] ?? $mail );
		return array(
			'spent'     => round( $aws_mtd + $argo + $fixed + $mail, 2 ),
			'forecast'  => round( $aws_forecast + $argo_month + $fixed + $mail_month, 2 ),
			'cf_fixed'  => round( $fixed, 2 ),
			'aws_ok'    => empty( $aws['error'] ),
			'cf_ok'     => empty( $cf['error'] ),
		);
	}

	// ---------- Cloudflare ----------

	private static function cloudflare(): array {
		if ( ! Cdn::has_token() ) {
			return array( 'error' => 'CDN 加速页还没有保存 Cloudflare 令牌。', 'zones' => array() );
		}
		$zones = Cdn::request( 'GET', '/zones?per_page=50' );
		if ( is_wp_error( $zones ) ) {
			return array( 'error' => $zones->get_error_message(), 'zones' => array() );
		}
		$from  = gmdate( 'Y-m-01' );
		$to    = gmdate( 'Y-m-d' );
		$day   = (int) gmdate( 'j' );
		$days  = (int) gmdate( 't' );
		$query = 'query($zone:String!,$from:Date!,$to:Date!){viewer{zones(filter:{zoneTag:$zone}){httpRequests1dGroups(limit:31,filter:{date_geq:$from,date_leq:$to},orderBy:[date_ASC]){dimensions{date}sum{bytes requests cachedBytes}}}}}';
		$out   = array( 'zones' => array(), 'analytics_error' => '', 'argo_error' => '', 'argo_estimate' => 0.0, 'argo_forecast' => 0.0, 'bytes_total' => 0 );
		foreach ( $zones as $z ) {
			$row = array( 'name' => (string) $z['name'], 'plan' => (string) ( $z['plan']['name'] ?? '' ), 'bytes' => 0, 'requests' => 0, 'cached' => 0, 'argo' => 'unknown', 'daily' => array() );
			if ( '' === $out['analytics_error'] ) {
				$data = Cdn::graphql( $query, array( 'zone' => $z['id'], 'from' => $from, 'to' => $to ) );
				if ( is_wp_error( $data ) ) {
					$out['analytics_error'] = $data->get_error_message();
				} else {
					foreach ( (array) ( $data['viewer']['zones'][0]['httpRequests1dGroups'] ?? array() ) as $g ) {
						$b = (int) ( $g['sum']['bytes'] ?? 0 );
						$row['bytes']    += $b;
						$row['requests'] += (int) ( $g['sum']['requests'] ?? 0 );
						$row['cached']   += (int) ( $g['sum']['cachedBytes'] ?? 0 );
						$row['daily'][ (string) ( $g['dimensions']['date'] ?? '' ) ] = $b;
					}
				}
			}
			if ( '' === $out['argo_error'] ) {
				$argo = Cdn::request( 'GET', '/zones/' . $z['id'] . '/argo/smart_routing' );
				if ( is_wp_error( $argo ) ) {
					// 1015 = 没订阅 Argo 时接口直接拒绝，不算错误；其他错误记一次。
					$cf_code = (int) ( $argo->get_error_data()['cf_code'] ?? 0 );
					$row['argo'] = 1015 === $cf_code ? 'off' : 'unknown';
					if ( 1015 !== $cf_code ) {
						$out['argo_error'] = $argo->get_error_message();
					}
				} else {
					$row['argo'] = 'on' === ( $argo['value'] ?? '' ) ? 'on' : 'off';
				}
			}
			if ( 'on' === $row['argo'] ) {
				$gb                   = $row['bytes'] / 1073741824;
				$row['argo_estimate'] = round( self::ARGO_BASE + max( 0, $gb - self::ARGO_INCLUDED ) * self::ARGO_PER_GB, 2 );
				$gb_month             = $day > 0 ? $gb / $day * $days : $gb;
				$row['argo_forecast'] = round( self::ARGO_BASE + max( 0, $gb_month - self::ARGO_INCLUDED ) * self::ARGO_PER_GB, 2 );
				$out['argo_estimate'] += $row['argo_estimate'];
				$out['argo_forecast'] += $row['argo_forecast'];
			}
			$out['bytes_total'] += $row['bytes'];
			$out['zones'][]      = $row;
		}
		usort( $out['zones'], static fn( $a, $b ) => $b['bytes'] <=> $a['bytes'] );
		// 账户级固定订阅（Workers Paid、Argo 区域套餐等），按月计。
		$account_id = (string) ( $zones[0]['account']['id'] ?? '' );
		$out['subscriptions'] = array();
		$out['fixed_monthly'] = 0.0;
		$out['has_argo_plan'] = false;
		if ( '' !== $account_id ) {
			$subs = Cdn::request( 'GET', '/accounts/' . $account_id . '/subscriptions' );
			if ( ! is_wp_error( $subs ) ) {
				foreach ( (array) $subs as $sub ) {
					$price = (float) ( $sub['price'] ?? 0 );
					if ( $price <= 0 ) { continue; }
					$plan_id = (string) ( $sub['rate_plan']['id'] ?? '' );
					$name    = (string) ( $sub['rate_plan']['public_name'] ?? $plan_id );
					$zh      = str_contains( $plan_id, 'workers' ) ? 'Workers Paid 套餐（含邮件发送 3,000 封/月）' : ( str_contains( $plan_id, 'argo' ) ? 'Argo 智能路由套餐' : $name );
					$out['subscriptions'][] = array( 'id' => $plan_id, 'name' => $zh, 'price' => $price, 'frequency' => (string) ( $sub['frequency'] ?? 'monthly' ) );
					if ( 'monthly' === ( $sub['frequency'] ?? 'monthly' ) ) { $out['fixed_monthly'] += $price; }
					if ( str_contains( $plan_id, 'argo' ) ) { $out['has_argo_plan'] = true; }
				}
			} else {
				$out['subscriptions_error'] = $subs->get_error_message();
			}
		}
		// Argo 有固定套餐时，底价已在订阅里，只按流量算超出 1 GB 的部分。
		if ( $out['has_argo_plan'] ) {
			$out['argo_estimate'] = round( max( 0.0, $out['argo_estimate'] - self::ARGO_BASE * count( array_filter( $out['zones'], static fn( $z ) => 'on' === $z['argo'] ) ) ), 2 );
			$out['argo_forecast'] = round( max( 0.0, $out['argo_forecast'] - self::ARGO_BASE * count( array_filter( $out['zones'], static fn( $z ) => 'on' === $z['argo'] ) ) ), 2 );
		}
		// 邮件发送：本月经邮件中心发出的封数；Workers Paid 含 3,000 封，超出每 1,000 封 $0.35。
		$month_start = (int) strtotime( gmdate( 'Y-m-01' ) . ' 00:00:00 UTC' );
		$sent        = class_exists( Mail::class ) ? Mail::count_since( $month_start ) : 0;
		$day         = max( 1, (int) gmdate( 'j' ) );
		$days        = (int) gmdate( 't' );
		$out['mail_sent']       = $sent;
		$out['mail_included']   = self::MAIL_INCLUDED;
		$out['mail_estimate']   = round( max( 0, $sent - self::MAIL_INCLUDED ) / 1000 * self::MAIL_PER_1000, 2 );
		$out['mail_forecast']   = round( max( 0, (int) round( $sent / $day * $days ) - self::MAIL_INCLUDED ) / 1000 * self::MAIL_PER_1000, 2 );
		$out['mail_sent_forecast'] = (int) round( $sent / $day * $days );

		$bills = Cdn::request( 'GET', '/user/billing/history?page=1&per_page=12&order=occurred_at&direction=desc' );
		if ( is_wp_error( $bills ) ) {
			$out['billing_error'] = $bills->get_error_message();
			$out['bills']         = array();
		} else {
			$out['bills'] = array();
			foreach ( $bills as $b ) {
				$out['bills'][] = array(
					'at'          => (string) ( $b['occurred_at'] ?? '' ),
					'amount'      => (float) ( $b['amount'] ?? 0 ),
					'currency'    => (string) ( $b['currency'] ?? 'USD' ),
					'description' => '' !== (string) ( $b['description'] ?? '' ) ? (string) $b['description'] : ( ( 'invoice' === ( $b['type'] ?? '' ) ? '发票' : (string) ( $b['type'] ?? '' ) ) . ( '' !== (string) ( $b['receipt_id'] ?? '' ) ? ' ' . $b['receipt_id'] : '' ) . ( 'CLOSED' === ( $b['status'] ?? '' ) ? '（已付）' : ( '' !== (string) ( $b['status'] ?? '' ) ? '（' . $b['status'] . '）' : '' ) ) ),
					'zone'        => (string) ( $b['zone']['name'] ?? '' ),
				);
			}
		}
		return $out;
	}

	// ---------- AWS ----------

	/** 两个账号各拉一遍，再合计。 */
	private static function aws(): array {
		$out = array( 'accounts' => array(), 'month_to_date' => 0.0, 'previous_month' => 0.0, 'forecast' => 0.0, 'daily' => array(), 'services' => array(), 'data_through' => '', 'forecast_source' => 'aws', 'error' => '' );
		$ok  = 0;
		$errors = array();
		foreach ( self::SLOTS as $slot => $label ) {
			if ( ! self::slot_ready( $slot ) ) {
				$out['accounts'][ $slot ] = array( 'label' => $label, 'error' => '还没有填密钥。' );
				continue;
			}
			$r = self::aws_account( $slot );
			$r['label'] = $label;
			$out['accounts'][ $slot ] = $r;
			if ( ! empty( $r['error'] ) ) {
				$errors[] = $label . '：' . $r['error'];
				continue;
			}
			++$ok;
			$out['month_to_date']  += (float) $r['month_to_date'];
			$out['previous_month'] += (float) $r['previous_month'];
			$out['forecast']       += (float) $r['forecast'];
			foreach ( (array) $r['daily'] as $d => $v ) {
				$out['daily'][ $d ] = round( (float) ( $out['daily'][ $d ] ?? 0 ) + (float) $v, 4 );
			}
			foreach ( (array) $r['services'] as $svc => $row ) {
				$k = $label . ' · ' . $svc;
				$out['services'][ $k ] = array( 'month' => (float) $row['month'], 'prev' => (float) $row['prev'], 'service' => $svc, 'slot' => $slot );
			}
			if ( (string) $r['data_through'] > $out['data_through'] ) {
				$out['data_through'] = (string) $r['data_through'];
			}
			if ( 'linear' === $r['forecast_source'] ) {
				$out['forecast_source'] = 'linear';
			}
		}
		ksort( $out['daily'] );
		uasort( $out['services'], static fn( $a, $b ) => $b['month'] <=> $a['month'] ?: $b['prev'] <=> $a['prev'] );
		$out['month_to_date']  = round( $out['month_to_date'], 2 );
		$out['previous_month'] = round( $out['previous_month'], 2 );
		$out['forecast']       = round( $out['forecast'], 2 );
		if ( 0 === $ok ) {
			$out['error'] = $errors ? implode( ' ', $errors ) : '还没有设置 AWS 访问密钥。';
		} elseif ( $errors ) {
			$out['partial_error'] = implode( ' ', $errors );
		}
		return $out;
	}

	private static function aws_account( string $slot ): array {
		$today    = gmdate( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$prev     = gmdate( 'Y-m-01', strtotime( 'first day of last month' ) );
		$month    = gmdate( 'Y-m-01' );
		$next     = gmdate( 'Y-m-01', strtotime( 'first day of next month' ) );

		$usage = self::ce( $slot, 'GetCostAndUsage', array(
			'TimePeriod'  => array( 'Start' => $prev, 'End' => $tomorrow ),
			'Granularity' => 'DAILY',
			'Metrics'     => array( 'UnblendedCost' ),
			'GroupBy'     => array( array( 'Type' => 'DIMENSION', 'Key' => 'SERVICE' ) ),
		) );
		if ( is_wp_error( $usage ) ) {
			return array( 'error' => $usage->get_error_message() );
		}
		$daily    = array();
		$services = array();
		$mtd      = 0.0;
		$prev_sum = 0.0;
		$last_day_with_data = '';
		foreach ( (array) ( $usage['ResultsByTime'] ?? array() ) as $r ) {
			$d   = (string) ( $r['TimePeriod']['Start'] ?? '' );
			$sum = 0.0;
			foreach ( (array) ( $r['Groups'] ?? array() ) as $g ) {
				$svc = (string) ( $g['Keys'][0] ?? '其他' );
				$amt = (float) ( $g['Metrics']['UnblendedCost']['Amount'] ?? 0 );
				$sum += $amt;
				if ( ! isset( $services[ $svc ] ) ) {
					$services[ $svc ] = array( 'month' => 0.0, 'prev' => 0.0 );
				}
				if ( $d >= $month ) {
					$services[ $svc ]['month'] += $amt;
				} else {
					$services[ $svc ]['prev'] += $amt;
				}
			}
			$daily[ $d ] = round( $sum, 4 );
			if ( $d >= $month ) {
				$mtd += $sum;
			} else {
				$prev_sum += $sum;
			}
			if ( $sum > 0 ) {
				$last_day_with_data = $d;
			}
		}
		foreach ( $services as &$s ) {
			$s['month'] = round( $s['month'], 2 );
			$s['prev']  = round( $s['prev'], 2 );
		}
		unset( $s );
		uasort( $services, static fn( $a, $b ) => $b['month'] <=> $a['month'] ?: $b['prev'] <=> $a['prev'] );

		$forecast        = 0.0;
		$forecast_source = 'linear';
		if ( $today < $next ) {
			$fc = self::ce( $slot, 'GetCostForecast', array(
				'TimePeriod'  => array( 'Start' => $today, 'End' => $next ),
				'Metric'      => 'UNBLENDED_COST',
				'Granularity' => 'MONTHLY',
			) );
			if ( ! is_wp_error( $fc ) && isset( $fc['Total']['Amount'] ) ) {
				$forecast        = $mtd + (float) $fc['Total']['Amount'];
				$forecast_source = 'aws';
			}
		}
		if ( 'linear' === $forecast_source ) {
			$elapsed  = max( 1, (int) gmdate( 'j' ) - 1 );
			$forecast = $mtd / $elapsed * (int) gmdate( 't' );
		}
		return array(
			'account'         => (string) ( self::settings()['accounts'][ $slot ]['account'] ?? '' ),
			'month_to_date'   => round( $mtd, 2 ),
			'previous_month'  => round( $prev_sum, 2 ),
			'forecast'        => round( $forecast, 2 ),
			'forecast_source' => $forecast_source,
			'services'        => $services,
			'daily'           => $daily,
			'data_through'    => $last_day_with_data,
		);
	}

	/** Cost Explorer 调用。@return array|\WP_Error */
	private static function ce( string $slot, string $action, array $body ) {
		$a   = self::settings()['accounts'][ $slot ] ?? array();
		$sec = '' !== (string) ( $a['secret_enc'] ?? '' ) ? Crypto::decrypt( (string) $a['secret_enc'] ) : '';
		return self::aws_json( self::CE_HOST, 'ce', 'AWSInsightsIndexService.' . $action, $body, (string) ( $a['key_id'] ?? '' ), $sec );
	}

	/** 验证密钥：STS GetCallerIdentity，返回账号 ID。@return string|\WP_Error */
	private static function sts_identity( string $key, string $secret ) {
		$body    = 'Action=GetCallerIdentity&Version=2011-06-15';
		$headers = self::sign( 'POST', self::STS_HOST, '/', 'sts', 'us-east-1', $body, array( 'content-type' => 'application/x-www-form-urlencoded; charset=utf-8' ), $key, $secret );
		$res     = wp_remote_post( 'https://' . self::STS_HOST . '/', array( 'timeout' => 20, 'headers' => $headers, 'body' => $body ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_costs_network', '连不上 AWS（' . $res->get_error_message() . '）。' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$xml  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			preg_match( '#<Message>(.*?)</Message>#s', $xml, $m );
			return new \WP_Error( 'pchq_costs_aws', trim( html_entity_decode( (string) ( $m[1] ?? ( 'HTTP ' . $code ) ) ) ) );
		}
		preg_match( '#<Account>(\d+)</Account>#', $xml, $m );
		return (string) ( $m[1] ?? '' );
	}

	/** JSON 1.1 风格的 AWS 服务调用（Cost Explorer）。 */
	private static function aws_json( string $host, string $service, string $target, array $body, string $key, string $secret ) {
		if ( '' === $key || '' === $secret ) {
			return new \WP_Error( 'pchq_costs_aws', '还没有设置 AWS 访问密钥。' );
		}
		$payload = wp_json_encode( $body );
		$headers = self::sign( 'POST', $host, '/', $service, 'us-east-1', $payload, array( 'content-type' => 'application/x-amz-json-1.1', 'x-amz-target' => $target ), $key, $secret );
		$res     = wp_remote_post( 'https://' . $host . '/', array( 'timeout' => 30, 'headers' => $headers, 'body' => $payload ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_costs_network', '连不上 AWS（' . $res->get_error_message() . '）。' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$type = (string) ( $data['__type'] ?? '' );
			$msg  = (string) ( $data['message'] ?? $data['Message'] ?? ( 'HTTP ' . $code ) );
			if ( str_contains( $type, 'AccessDenied' ) || str_contains( $msg, 'not authorized' ) ) {
				$msg = '这个密钥没有 Cost Explorer 权限（ce:GetCostAndUsage / ce:GetCostForecast）。' . $msg;
			} elseif ( str_contains( $type, 'DataUnavailable' ) ) {
				$msg = 'Cost Explorer 还没准备好数据。第一次在 AWS 控制台打开 Cost Explorer 后要等约 24 小时。';
			}
			return new \WP_Error( 'pchq_costs_aws', $msg );
		}
		return $data;
	}

	/**
	 * AWS Signature Version 4。
	 *
	 * @param array<string,string> $extra 小写头名 => 值（content-type、x-amz-target 等）。
	 * @return array<string,string> 完整请求头。
	 */
	private static function sign( string $method, string $host, string $path, string $service, string $region, string $payload, array $extra, string $key, string $secret ): array {
		$amz_date = gmdate( 'Ymd\THis\Z' );
		$stamp    = gmdate( 'Ymd' );
		$headers  = $extra + array( 'host' => $host, 'x-amz-date' => $amz_date );
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ':' . trim( preg_replace( '/\s+/', ' ', $v ) ) . "\n";
		}
		$signed    = implode( ';', array_keys( $headers ) );
		$canonical = $method . "\n" . $path . "\n" . '' . "\n" . $canonical_headers . "\n" . $signed . "\n" . hash( 'sha256', $payload );
		$scope     = $stamp . '/' . $region . '/' . $service . '/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$k         = hash_hmac( 'sha256', $stamp, 'AWS4' . $secret, true );
		$k         = hash_hmac( 'sha256', $region, $k, true );
		$k         = hash_hmac( 'sha256', $service, $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k );
		$out       = array();
		foreach ( $extra as $k2 => $v ) {
			$out[ $k2 ] = $v;
		}
		$out['x-amz-date']    = $amz_date;
		$out['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
		return $out;
	}

	// ---------- 提醒 ----------

	private static function check_alerts( array $snap ): void {
		$s        = self::settings();
		$forecast = (float) ( $snap['summary']['forecast'] ?? 0 );
		$ok       = ! empty( $snap['summary']['aws_ok'] );
		$month    = wp_date( 'Y 年 n 月' );
		$fmt      = static fn( float $v ): string => '$' . number_format( $v, 2 );
		$keep     = array();

		if ( $ok && $s['budget'] > 0 ) {
			if ( $forecast > $s['budget'] ) {
				$keep[] = 'budget';
				$msg    = sprintf( '%s 预计总费用 %s，超过预算 %s（AWS 预计 %s + Cloudflare Argo 预估 %s）。', $month, $fmt( $forecast ), $fmt( $s['budget'] ), $fmt( (float) ( $snap['aws']['forecast'] ?? 0 ) ), $fmt( (float) ( $snap['cloudflare']['argo_forecast'] ?? 0 ) ) );
				if ( Alerts::raise( 0, 'cost', 'budget', $msg ) ) {
					self::mail( '预计费用超过预算', $msg );
				}
			}
		}
		if ( $ok && $s['balance'] > 0 ) {
			$spent_since = 0.0;
			// 余额是填写当天的数；从那天起每天的 AWS 费用都从余额里扣。
			foreach ( (array) ( $snap['aws']['daily'] ?? array() ) as $d => $v ) {
				if ( strtotime( $d . ' UTC' ) >= $s['balance_at'] ) {
					$spent_since += (float) $v;
				}
			}
			$remaining = $s['balance'] - $spent_since;
			$need      = $forecast; // 下个月按本月预计算。
			if ( $remaining < $need ) {
				$keep[] = 'balance';
				$msg    = sprintf( '账户余额约剩 %s（填写时 %s，之后已花 %s），不够下个月预计的 %s，请尽快充值。', $fmt( max( 0, $remaining ) ), $fmt( $s['balance'] ), $fmt( $spent_since ), $fmt( $need ) );
				if ( Alerts::raise( 0, 'cost', 'balance', $msg ) ) {
					self::mail( '余额不够下个月，请充值', $msg );
				}
			}
		}
		Alerts::resolve( 0, 'cost', $keep );
	}

	/** 余额估算（给页面用）：填写时余额 − 之后的 AWS 日费用。 */
	public static function remaining_balance( array $snap ): ?float {
		$s = self::settings();
		if ( $s['balance'] <= 0 ) {
			return null;
		}
		$spent = 0.0;
		foreach ( (array) ( $snap['aws']['daily'] ?? array() ) as $d => $v ) {
			if ( strtotime( $d . ' UTC' ) >= $s['balance_at'] ) {
				$spent += (float) $v;
			}
		}
		return round( $s['balance'] - $spent, 2 );
	}

	private static function mail( string $subject, string $body ): void {
		$to = self::settings()['email'];
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) || str_ends_with( $to, '.local' ) ) {
			return;
		}
		wp_mail( $to, '[总后台] ' . $subject, $body . "\n\n" . admin_url( 'admin.php?page=pchq-costs' ) );
	}
}
