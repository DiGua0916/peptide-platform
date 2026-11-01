<?php
/**
 * 前端事件接收（VISITOR-ANALYTICS-TEST-01）：POST /peptide/v1/track/events。
 *
 * 请求（application/json，离页时也可以用 text/plain 的 sendBeacon / fetch keepalive）：
 *   {
 *     "protocol": 1,
 *     "tab_id": "<sessionStorage 里的标签页编号，8–64 位字母数字和短横线；可省略>",
 *     "sent_at": "<发送时浏览器的 ISO 时间，用来校正浏览器时钟；可省略>",
 *     "events": [ { "event_id", "page_instance_id", "seq", "type", "occurred_at", "page": {...}, "data": {...} }, … ]  // 最多 20 条
 *   }
 * 响应 200：{ "protocol": 1, "ack": [event_id…], "rejected": [ {"event_id", "code", "retry"} … ], "server_time": "<ISO>" }
 *   - ack：已经保存的事件，包括以前收过的重复事件。可以从队列删除。
 *   - rejected：retry=false 的永远不会被接受（格式不对、时间离谱、类型未知），也应该从队列删除；retry=true 时可以重试。
 * 整批错误：400 pc_track_bad_request、403 pc_track_disabled / pc_track_staff、413 pc_track_too_large、
 *   429 pc_track_rate_limited（带 retry_after 秒）。
 *
 * 只保存白名单字段，字符串截断；不保存 IP、表单内容、搜索词、订单号。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Ingest {

	public const MAX_EVENTS = 20;
	public const MAX_BYTES  = 65536;

	/** 每个访客 5 分钟内最多 600 条，每个 IP 最多 3000 条。 */
	private const RATE_WINDOW  = 300;
	private const RATE_VISITOR = 600;
	private const RATE_IP      = 3000;

	private const ID_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

	private const PAGE_TYPES = array( 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'order_received', 'order_pay', 'content' );

	/** 事件类型 => data 字段 => 类型。 */
	private const STATE = array(
		'visibility'           => 'enum:visible|hidden',
		'activity'             => 'enum:active|idle',
		'last_activity_age_ms' => 'int',
	);

	private const VIDEO = array(
		'video_id'        => 'str:120',
		'module_id'       => 'str:60',
		'position_s'      => 'num',
		'duration_s'      => 'num',
		'watched_s'       => 'num',
		'autoplay'        => 'bool',
		'playback_origin' => 'enum:user|autoplay|unknown',
	);

	public static function types(): array {
		$cart = array( 'source' => 'str:40', 'action' => 'str:40', 'item_id' => 'int', 'quantity' => 'num' );
		return array(
			'page_view'               => array_merge( array( 'cause' => 'str:20' ), self::STATE ),
			'product_view'            => array( 'product_id' => 'int' ),
			'product_impression'      => array(),
			'product_favorite' => array(), 'product_share' => array(), 'product_intent' => array(),
			'cart_view'               => array( 'source' => 'str:40' ),
			'checkout_view'           => array(),
			'visibility_change'       => self::STATE,
			'heartbeat'               => self::STATE,
			'page_exit'               => array_merge( array( 'persisted' => 'bool' ), self::STATE ),
			'variant_select'          => array( 'product_id' => 'int', 'attribute' => 'str:60', 'value' => 'str:120' ),
			'add_to_cart_attempt'     => array( 'product_id' => 'int', 'variation_id' => 'int', 'quantity' => 'num' ),
			'checkout_submit_attempt' => array(),
			'checkout_address_completed' => array( 'source' => 'enum:native_blocks' ),
			'checkout_shipping_selected' => array( 'method' => 'str:80', 'origin' => 'enum:initial|user|automatic' ),
			'checkout_payment_selected' => array( 'method' => 'str:80', 'origin' => 'enum:initial|user|automatic' ),
			'checkout_step_applicability' => array( 'address_required' => 'bool', 'shipping_required' => 'bool' ),
			'cart_open'               => array( 'source' => 'str:40' ),
			'cart_close'              => array( 'source' => 'str:40' ),
			'cart_change_attempt'     => $cart,
			'cart_change_response'    => $cart,
			'video_play'              => self::VIDEO,
			'video_pause'             => self::VIDEO,
			'video_progress'          => self::VIDEO,
			'video_seek'              => self::VIDEO,
			'video_end'               => self::VIDEO,
		);
	}

	/** 解析请求体：JSON 对象，或 text/plain 里的 JSON 字符串。 */
	public static function parse( string $raw ) {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_Error( 'pc_track_too_large', __( 'Batch is too large.', 'peptide-core' ), array( 'status' => 413 ) );
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || ! isset( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return new \WP_Error( 'pc_track_bad_request', __( 'Expected a JSON object with an events array.', 'peptide-core' ), array( 'status' => 400 ) );
		}
		if ( count( $body['events'] ) > self::MAX_EVENTS ) {
			/* translators: %d: maximum events */
			return new \WP_Error( 'pc_track_too_large', sprintf( __( 'At most %d events per batch.', 'peptide-core' ), self::MAX_EVENTS ), array( 'status' => 413 ) );
		}
		return $body;
	}

	/** 毫秒时间：ISO 字符串或毫秒数字。无效时返回 0。 */
	public static function ms( $value ): int {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? (int) $value : 0;
		}
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 40 ) {
			return 0;
		}
		try {
			$d = new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return 0;
		}
		return (int) $d->format( 'Uv' );
	}

	private static function clean_value( $value, string $kind ) {
		if ( null === $value ) {
			return null;
		}
		if ( 'int' === $kind ) {
			return is_numeric( $value ) && (int) $value >= 0 && (int) $value <= PHP_INT_MAX ? (int) $value : null;
		}
		if ( 'num' === $kind ) {
			return is_numeric( $value ) && (float) $value >= 0 ? min( 1e7, round( (float) $value, 3 ) ) : null;
		}
		if ( 'bool' === $kind ) {
			return is_bool( $value ) ? $value : null;
		}
		if ( str_starts_with( $kind, 'enum:' ) ) {
			return is_string( $value ) && in_array( $value, explode( '|', substr( $kind, 5 ) ), true ) ? $value : null;
		}
		if ( str_starts_with( $kind, 'str:' ) ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$v = sanitize_text_field( (string) $value );
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, (int) substr( $kind, 4 ) ) : substr( $v, 0, (int) substr( $kind, 4 ) );
		}
		return null;
	}

	public static function clean_data( string $type, $data ): array {
		$spec = self::types()[ $type ] ?? array();
		$out  = array();
		foreach ( $spec as $key => $kind ) {
			$v = self::clean_value( is_array( $data ) ? ( $data[ $key ] ?? null ) : null, $kind );
			if ( 'method' === $key && in_array( $type, array( 'checkout_shipping_selected', 'checkout_payment_selected' ), true ) && ( ! is_string( $v ) || ! preg_match( '/^[A-Za-z0-9_:-]{1,80}$/D', $v ) ) ) { continue; }
			if ( null !== $v ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	public static function clean_page( $page ): array {
		$page = is_array( $page ) ? $page : array();
		$type = in_array( $page['page_type'] ?? '', self::PAGE_TYPES, true ) ? (string) $page['page_type'] : 'content';
		$path = is_string( $page['path'] ?? null ) ? (string) wp_parse_url( $page['path'], PHP_URL_PATH ) : '/';
		$path = '' !== $path && str_starts_with( $path, '/' ) ? substr( sanitize_text_field( $path ), 0, 500 ) : '/';
		$out  = array( 'page_type' => $type, 'path' => $path );
		if ( 'product' === $type && is_numeric( $page['product_id'] ?? null ) && (int) $page['product_id'] > 0 ) {
			$out['product_id']   = (int) $page['product_id'];
			$out['product_name'] = (string) self::clean_value( $page['product_name'] ?? '', 'str:120' );
		}
		return $out;
	}

	/** 限流：超过时返回需要等待的秒数，否则 0。 */
	private static function rate_limited( string $key, int $limit, int $count ): int {
		$name  = 'pc_trk_' . md5( $key );
		$state = get_transient( $name );
		$now   = time();
		if ( ! is_array( $state ) || $state[0] + self::RATE_WINDOW <= $now ) {
			$state = array( $now, 0 );
		}
		if ( $state[1] + $count > $limit ) {
			return max( 1, $state[0] + self::RATE_WINDOW - $now );
		}
		$state[1] += $count;
		set_transient( $name, $state, self::RATE_WINDOW );
		return 0;
	}

	/**
	 * 处理一批事件。
	 *
	 * @return array|\WP_Error
	 */
	public static function handle( array $body, int $now_ms ) {
		global $wpdb;
		$user_id = Tracker::cookie_user_id();
		if ( Tracker::is_staff( $user_id ) ) {
			return new \WP_Error( 'pc_track_staff', __( 'Staff accounts are not tracked.', 'peptide-core' ), array( 'status' => 403 ) );
		}
		$events = array_values( $body['events'] );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wait   = self::rate_limited( 'ip|' . wp_hash( $ip ), self::RATE_IP, count( $events ) );
		$vid    = Tracker::current_vid();
		if ( ! $wait && '' !== $vid ) {
			$wait = self::rate_limited( 'v|' . $vid, self::RATE_VISITOR, count( $events ) );
		}
		if ( $wait ) {
			return new \WP_Error( 'pc_track_rate_limited', __( 'Too many events. Try again later.', 'peptide-core' ), array( 'status' => 429, 'retry_after' => $wait ) );
		}
		if ( '' === $vid ) {
			$vid = Tracker::issue_vid();
		}

		$tab_batch = is_string( $body['tab_id'] ?? null ) && preg_match( self::ID_PATTERN, $body['tab_id'] ) ? $body['tab_id'] : '';
		$sent_ms   = self::ms( $body['sent_at'] ?? null );
		$offset    = $sent_ms && abs( $now_ms - $sent_ms ) < DAY_IN_SECONDS * 1000 ? $now_ms - $sent_ms : 0;
		$types     = self::types();

		$ack      = array();
		$rejected = array();
		$clean    = array();
		$attempts = array();
		$contexts = array();
		// Indexed by request position, never UID: duplicate UIDs still receive separate receipts.
		foreach ( $events as $index => $e ) {
			$eid = is_array( $e ) && is_string( $e['event_id'] ?? null ) ? $e['event_id'] : '';
			$attempted = is_array( $e ) && is_array( $e['data'] ?? null ) && array_key_exists( 'attribution', $e['data'] );
			if ( $attempted ) { $attempts[ $index ] = Attribution::receipt( preg_match( self::ID_PATTERN, $eid ) ? $eid : '', 'rejected', 'core_rejected' ); }
			$context_attempted = is_array( $e ) && is_array( $e['data'] ?? null ) && array_key_exists( 'page_context', $e['data'] );
			if ( $context_attempted ) { $contexts[ $index ] = Page_Context::receipt( preg_match( self::ID_PATTERN, $eid ) ? $eid : '', 'rejected', 'core_rejected' ); }
			if ( ! preg_match( self::ID_PATTERN, $eid ) ) {
				$rejected[] = array( 'event_id' => is_scalar( $e['event_id'] ?? null ) ? substr( (string) $e['event_id'], 0, 64 ) : '', 'code' => 'bad_event_id', 'retry' => false );
				continue;
			}
			$pid  = is_string( $e['page_instance_id'] ?? null ) && preg_match( self::ID_PATTERN, $e['page_instance_id'] ) ? $e['page_instance_id'] : '';
			$type = is_string( $e['type'] ?? null ) ? $e['type'] : '';
			if ( '' === $pid ) {
				$rejected[] = array( 'event_id' => $eid, 'code' => 'bad_page_instance_id', 'retry' => false );
				continue;
			}
			if ( ! isset( $types[ $type ] ) ) {
				$rejected[] = array( 'event_id' => $eid, 'code' => 'unknown_type', 'retry' => false );
				continue;
			}
			$at = self::ms( $e['occurred_at'] ?? null );
			if ( ! $at ) {
				$rejected[] = array( 'event_id' => $eid, 'code' => 'bad_time', 'retry' => false );
				continue;
			}
			// 按发送时间校正浏览器时钟，校正后不能晚于服务器收到的时间，也不能早于 24 小时前。
			$at = min( $now_ms, $at + $offset );
			if ( $at < $now_ms - DAY_IN_SECONDS * 1000 ) {
				$rejected[] = array( 'event_id' => $eid, 'code' => 'too_old', 'retry' => false );
				continue;
			}
			$data = self::clean_data( $type, $e['data'] ?? null );
			if ( 'product_impression' === $type ) {
				$observation = Impressions::clean( $e['data'] ?? null );
				if ( ! $observation['ok'] ) { $rejected[] = array( 'event_id' => $eid, 'code' => $observation['code'], 'retry' => false ); continue; }
				$data = $observation['value'];
			}
			if ( Product_Actions::is_type( $type ) ) {
				$observation = Product_Actions::clean( $type, $e['data'] ?? null );
				if ( ! $observation['ok'] || Impressions::path( $e['page']['path'] ?? null ) === null ) { $rejected[] = array( 'event_id' => $eid, 'code' => $observation['ok'] ? 'bad_product_action' : $observation['code'], 'retry' => false ); continue; }
				$data = $observation['value'];
			}
			if ( $attempted ) {
				if ( 'page_view' !== $type ) {
					$attempts[ $index ] = Attribution::receipt( $eid, 'rejected', 'wrong_event_type' );
				} else {
					$metadata = Attribution::clean( $e['data']['attribution'] );
					if ( $metadata['ok'] ) {
						$data['attribution'] = $metadata['value'];
						$attempts[ $index ] = Attribution::receipt( $eid, 'retry', 'server_error', true );
					} else { $attempts[ $index ] = Attribution::receipt( $eid, 'rejected', $metadata['code'] ); }
				}
			}
			if ( $context_attempted ) {
				if ( 'page_view' !== $type ) { $contexts[ $index ] = Page_Context::receipt( $eid, 'rejected', 'wrong_event_type' ); }
				else {
					$context = Page_Context::clean( $e['data']['page_context'] );
					if ( $context['ok'] ) { $data['page_context'] = $context['value']; $contexts[ $index ] = Page_Context::receipt( $eid, 'retry', 'server_error', true ); }
					else { $contexts[ $index ] = Page_Context::receipt( $eid, 'rejected', $context['code'] ); }
				}
			}
			$canonical_page = self::clean_page( $e['page'] ?? null );
			if ( Product_Actions::is_type( $type ) ) { $canonical_page['path'] = $e['page']['path']; }
			$clean[] = array(
				'index'            => $index,
				'event_id'         => $eid,
				'page_instance_id' => $pid,
				'tab_id'           => '' !== $tab_batch ? $tab_batch : $pid,
				'seq'              => is_numeric( $e['seq'] ?? null ) ? max( 0, min( 1000000, (int) $e['seq'] ) ) : 0,
				'type'             => $type,
				'at'               => $at,
				'page'             => $canonical_page,
				'data'             => $data,
			);
		}
		if ( ! $clean ) {
			return self::result( $ack, $rejected, 0, 0, $attempts, $contexts );
		}

		// 按发生时间处理，同一页面按序号。
		usort( $clean, static fn( $a, $b ) => array( $a['at'], $a['page_instance_id'], $a['seq'] ) <=> array( $b['at'], $b['page_instance_id'], $b['seq'] ) );

		$impressions = array_values( array_filter( $clean, static fn( $c ) => ( $c['type'] === 'product_impression' || Product_Actions::is_type( $c['type'] ) ) ) );
		$legacy = array_values( array_filter( $clean, static fn( $c ) => ( $c['type'] !== 'product_impression' && ! Product_Actions::is_type( $c['type'] ) ) ) );
		$impression_saved = array(); $impression_catalog = array(); $impression_error = false;
		if ( $impressions && ! $legacy ) {
			// Read current identity without allocating rows for invalid or duplicate-only batches.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id FROM ' . Schema::table( 'visitors' ) . ' WHERE vid = %s', $vid ), ARRAY_A );
			$impression_error = (bool) $wpdb->last_error;
			$visitor = array( 'id' => (int) ( $row['id'] ?? 0 ), 'user_id' => (int) ( $row['user_id'] ?? 0 ) );
		} else { $visitor = Tracker::visitor( $vid, $now_ms ); }
		if ( $legacy && ! $visitor['id'] ) {
			foreach ( $clean as $c ) {
				$rejected[] = array( 'event_id' => $c['event_id'], 'code' => 'server_error', 'retry' => true );
			}
			return self::result( $ack, $rejected, 0, 0, $attempts, $contexts );
		}
		if ( $impressions && ! $impression_error ) {
			$uids = array_column( $impressions, 'event_id' );
			$impression_saved = Impressions::winners( $visitor['id'], $uids );
			if ( $impression_saved === null ) { $impression_saved = array(); $impression_error = true; }
			else {
				$candidates = array_values( array_filter( $impressions, static fn( $c ) => ! isset( $impression_saved[$c['event_id']] ) ) );
				$impression_catalog = Impressions::catalog( $candidates );
				if ( $impression_catalog === null ) { $impression_catalog = array(); $impression_error = true; }
				else {
					$invalid = array(); foreach ( $candidates as $c ) { if ( ! $impression_catalog[$c['index']] ) { $invalid[] = $c['event_id']; } }
					// A concurrent winner may precede a catalog delete/draft, even without an INSERT attempt.
					if ( ! $visitor['id'] && $invalid ) {
						// First-visit races can create the identity after our initial miss. Read only;
						// never allocate an identity just to decide a catalog-invalid candidate.
						$identity = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id FROM ' . Schema::table( 'visitors' ) . ' WHERE vid = %s', $vid ), ARRAY_A );
						if ( $wpdb->last_error ) { $impression_error = true; }
						elseif ( $identity ) { $visitor = array( 'id' => (int) $identity['id'], 'user_id' => (int) $identity['user_id'] ); }
					}
					$reread = $impression_error ? null : Impressions::winners( $visitor['id'], $invalid );
					if ( $reread === null ) { $impression_error = true; } else { $impression_saved = array_replace( $impression_saved, $reread ); }
					$needs_visitor = false; foreach ( $candidates as $c ) { if ( ! isset( $impression_saved[$c['event_id']] ) && $impression_catalog[$c['index']] ) { $needs_visitor = true; } }
					if ( ! $visitor['id'] && $needs_visitor && ! $impression_error ) {
						$visitor = Tracker::visitor( $vid, $now_ms );
						if ( ! $visitor['id'] ) { $impression_error = true; }
						else { $reread = Impressions::winners( $visitor['id'], $uids ); if ( $reread === null ) { $impression_error = true; } else { $impression_saved = array_replace( $impression_saved, $reread ); } }
					}
				}
			}
		}
		// Pure extension replays belong to their saved visit, even after session rollover.
		// Legacy batches retain their exact visit creation/update behavior.
		$saved = array();
		$only_replays = (bool) ( $attempts || $contexts );
		foreach ( $legacy as $c ) {
			if ( ! isset( $attempts[ $c['index'] ] ) && ! isset( $contexts[ $c['index'] ] ) ) { $only_replays = false; continue; }
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'events' ) . ' WHERE visitor_id = %d AND event_uid = %s', $visitor['id'], $c['event_id'] ), ARRAY_A );
			if ( $wpdb->last_error ) { $only_replays = false; }
			if ( $row ) { $saved[ $c['event_id'] ] = $row; } else { $only_replays = false; }
		}
		$visit = $legacy ? ( $only_replays ? (int) $saved[ $legacy[0]['event_id'] ]['visit_id'] : Tracker::visit( $visitor['id'], $now_ms, $legacy[0]['page']['path'] ) ) : 0;
		if ( $legacy && $user_id && $user_id !== $visitor['user_id'] ) {
			Tracker::link_user( $visitor['id'], $user_id, $visit );
		}

		$events_table = Schema::table( 'events' );
		$classifier_new = array();
		$new          = 0;
		$views        = 0;
		$stage        = 0;
		$impression_visit = $legacy && ! $only_replays ? $visit : 0;
		$impression_visit_attempted = (bool) $impression_visit;
		$impression_visit_checked = false; $impression_visit_ready = false; $impression_visit_error = false;
		$impression_new = 0; $impression_results = array(); $impression_pending = array(); $impression_replays = array();
		foreach ( $clean as $c ) {
			$page = $c['page'];
			$data = $c['data'];
			if ( ( $c['type'] === 'product_impression' || Product_Actions::is_type( $c['type'] ) ) ) {
				$index = $c['index']; $uid = $c['event_id'];
				// Placeholders retain the legacy core ordering while lost-race reads coalesce.
				$ack[] = array( 'impression_index' => $index ); $rejected[] = array( 'impression_index' => $index );
				if ( isset( $impression_saved[$uid] ) ) { $impression_results[$index] = self::observation_replay( $visitor['id'], $c, $impression_saved[$uid] ); $impression_replays[$index] = (int) $impression_saved[$uid]['visit_id']; continue; }
				if ( $impression_error ) { $impression_results[$index] = 'server_error'; continue; }
				if ( isset( $impression_pending[$uid] ) ) { $impression_results[$index] = 'pending'; continue; }
				if ( ! ( $impression_catalog[$index] ?? false ) ) { $impression_results[$index] = Product_Actions::is_type( $c['type'] ) ? 'bad_product_action' : 'bad_product_impression'; continue; }
				if ( ! $impression_visit_attempted ) { $impression_visit_attempted = true; $impression_visit = Tracker::visit( $visitor['id'], $now_ms, $page['path'] ); $impression_visit_error = (bool) $wpdb->last_error; }
				if ( ! $impression_visit || $impression_visit_error ) { $impression_results[$index] = 'server_error'; continue; }
				if ( ! $impression_visit_checked ) {
					$impression_visit_checked = true;
					$valid_visit = $wpdb->get_var( $wpdb->prepare( 'SELECT s.id FROM ' . Schema::table( 'visits' ) . ' s JOIN ' . Schema::table( 'visitors' ) . ' v ON v.id=s.visitor_id WHERE s.id=%d AND s.visitor_id=%d', $impression_visit, $visitor['id'] ) );
					$impression_visit_ready = ! $wpdb->last_error && (int) $valid_visit === $impression_visit;
				}
				if ( ! $impression_visit_ready ) { $impression_results[$index] = 'server_error'; continue; }
				$inserted = $wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$events_table} (event_uid,visitor_id,visit_id,tab_id,page_instance_id,seq,type,source,occurred_ms,received_ms,page_type,path,product_id,variation_id,data,is_test) VALUES (%s,%d,%d,%s,%s,%d,%s,'client',%d,%d,%s,%s,%d,%d,%s,0)",
					$uid, $visitor['id'], $impression_visit, $c['tab_id'], $c['page_instance_id'], $c['seq'], $c['type'], $c['at'], $now_ms, $page['page_type'], $page['path'], $data['product_id'], $data['variation_id'], wp_json_encode( $data )
				) );
				if ( false === $inserted ) { $impression_results[$index] = 'server_error'; continue; }
				if ( (int) $inserted !== 1 ) { $impression_results[$index] = 'pending'; $impression_pending[$uid] = true; continue; }
				$impression_results[$index] = 'ack'; ++$impression_new;
				$classifier_new[$impression_visit][] = $uid;
				$impression_saved[$uid] = array( 'event_uid' => $uid, 'visitor_id' => $visitor['id'], 'visit_id' => $impression_visit, 'matched_visitor_id' => $visitor['id'], 'matched_visit_id' => $impression_visit, 'tab_id' => $c['tab_id'], 'page_instance_id' => $c['page_instance_id'], 'seq' => $c['seq'], 'type' => $c['type'], 'source' => 'client', 'page_type' => $page['page_type'], 'path' => $page['path'], 'product_id' => $data['product_id'], 'variation_id' => $data['variation_id'], 'data' => wp_json_encode( $data ) );
				Impressions::signal( $visitor['id'], $impression_visit, $c['tab_id'], $now_ms );
				continue;
			}
			if ( $only_replays ) {
				$ack[] = $c['event_id'];
				if ( isset( $data['attribution'] ) ) { $attempts[ $c['index'] ] = self::replay( $visitor['id'], $c, $saved[ $c['event_id'] ] ); }
				if ( isset( $data['page_context'] ) ) { $contexts[ $c['index'] ] = Page_Context::replay( $visitor['id'], $c, $saved[ $c['event_id'] ] ); }
				continue;
			}
			// Keep the normalized incoming value in $c for a lost-race replay; only
			// the prospective new row omits metadata that fails today's catalog.
			if ( isset( $data['page_context'] ) && ! isset( $saved[ $c['event_id'] ] ) && ! Page_Context::valid_new( $data['page_context'] ) ) {
				unset( $data['page_context'] );
				$contexts[ $c['index'] ] = Page_Context::receipt( $c['event_id'], 'rejected', 'bad_page_context' );
			}
			$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$events_table} (event_uid, visitor_id, visit_id, tab_id, page_instance_id, seq, type, source, occurred_ms, received_ms, page_type, path, product_id, variation_id, data, is_test) VALUES (%s, %d, %d, %s, %s, %d, %s, 'client', %d, %d, %s, %s, %d, %d, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$c['event_id'],
					$visitor['id'],
					$visit,
					$c['tab_id'],
					$c['page_instance_id'],
					$c['seq'],
					$c['type'],
					$c['at'],
					$now_ms,
					$page['page_type'],
					$page['path'],
					(int) ( $data['product_id'] ?? ( $page['product_id'] ?? 0 ) ),
					(int) ( $data['variation_id'] ?? 0 ),
					wp_json_encode( array_merge( $data, isset( $page['product_name'] ) ? array( 'product_name' => $page['product_name'] ) : array() ) )
				)
			);
			if ( false === $inserted ) {
				$rejected[] = array( 'event_id' => $c['event_id'], 'code' => 'server_error', 'retry' => true );
				continue;
			}
			$ack[] = $c['event_id'];
			$original = null;
			$read_failed = false;
			if ( 1 !== (int) $inserted && ( isset( $data['attribution'] ) || isset( $c['data']['page_context'] ) ) ) {
				$original = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE visitor_id = %d AND event_uid = %s", $visitor['id'], $c['event_id'] ), ARRAY_A );
				$read_failed = (bool) $wpdb->last_error;
			}
			if ( isset( $data['attribution'] ) ) {
				if ( 1 === (int) $inserted ) {
					$attempts[ $c['index'] ] = Attribution::capture( $visitor['id'], array( 'event_uid' => $c['event_id'], 'visitor_id' => $visitor['id'], 'visit_id' => $visit, 'path' => $page['path'], 'received_ms' => $now_ms ), $data['attribution'] );
				} else {
					$attempts[ $c['index'] ] = $read_failed ? Attribution::receipt( $c['event_id'], 'retry', 'server_error', true ) : ( $original ? self::replay( $visitor['id'], $c, $original ) : Attribution::receipt( $c['event_id'], 'rejected', 'original_visit_missing' ) );
				}
			}
			if ( isset( $c['data']['page_context'] ) ) {
				if ( 1 === (int) $inserted ) {
					if ( isset( $data['page_context'] ) ) { $contexts[ $c['index'] ] = Page_Context::receipt( $c['event_id'], 'applied', 'saved_context' ); }
				} else { $contexts[ $c['index'] ] = $read_failed ? Page_Context::receipt( $c['event_id'], 'retry', 'server_error', true ) : ( $original ? Page_Context::replay( $visitor['id'], $c, $original ) : Page_Context::receipt( $c['event_id'], 'rejected', 'original_visit_missing' ) ); }
			}
			if ( 1 !== (int) $inserted ) {
				continue; // 重复事件：确认收到，但不再更新状态。
			}
			++$new;
			$classifier_new[$visit][] = $c['event_id'];
			if ( 'page_view' === $c['type'] ) {
				++$views;
			}
			if ( 'product_view' === $c['type'] ) {
				$stage = max( $stage, 1 );
			} elseif ( 'checkout_view' === $c['type'] ) {
				$stage = max( $stage, 3 );
			}
			self::update_tab( $visitor['id'], $visit, $c, $now_ms );
		}
		if ( $impression_pending ) {
			$winners = Impressions::winners( $visitor['id'], array_keys( $impression_pending ) );
			foreach ( $impressions as $c ) { if ( ( $impression_results[$c['index']] ?? '' ) === 'pending' ) { $row = $winners[$c['event_id']] ?? null; $impression_results[$c['index']] = $row ? self::observation_replay( $visitor['id'], $c, $row ) : 'server_error'; if ( $row ) { $impression_replays[$c['index']] = (int) $row['visit_id']; } } }
		}
		foreach ( $impressions as $c ) {
			$index = $c['index']; $code = $impression_results[$index];
			if ( $code === 'bad_product_impression' ) {
				if ( isset( $attempts[$index] ) ) { $attempts[$index] = Attribution::receipt( $c['event_id'], 'rejected', 'core_rejected' ); }
				if ( isset( $contexts[$index] ) ) { $contexts[$index] = Page_Context::receipt( $c['event_id'], 'rejected', 'core_rejected' ); }
			}
		}
		$resolved_ack = array(); foreach ( $ack as $item ) { if ( is_array( $item ) ) { $i = $item['impression_index']; if ( $impression_results[$i] === 'ack' ) { $resolved_ack[] = $events[$i]['event_id']; } } else { $resolved_ack[] = $item; } } $ack = $resolved_ack;
		$resolved_rejected = array(); foreach ( $rejected as $item ) { if ( isset( $item['impression_index'] ) ) { $i = $item['impression_index']; $code = $impression_results[$i]; if ( $code !== 'ack' ) { $resolved_rejected[] = array( 'event_id' => $events[$i]['event_id'], 'code' => $code, 'retry' => $code === 'server_error' ); } } else { $resolved_rejected[] = $item; } } $rejected = $resolved_rejected;
		if ( $impression_new ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visits' ) . ' SET events=events+%d,last_ms=GREATEST(last_ms,%d) WHERE id=%d', $impression_new, $now_ms, $impression_visit ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visitors' ) . ' SET last_ms=GREATEST(last_ms,%d) WHERE id=%d', $now_ms, $visitor['id'] ) );
			if ( ! $legacy && $user_id && $user_id !== $visitor['user_id'] ) { Tracker::link_user( $visitor['id'], $user_id, $impression_visit ); }
		}
		if ( $new ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visits' ) . ' SET events = events + %d, pageviews = pageviews + %d, last_ms = GREATEST(last_ms, %d) WHERE id = %d', $new, $views, $now_ms, $visit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'visitors' ) . ' SET last_ms = GREATEST(last_ms, %d) WHERE id = %d', $now_ms, $visitor['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $stage ) {
				Tracker::raise_stage( $visit, $stage );
			}
		}
		// Optional Data verdicts follow genuine new persistence, never retries/rejections.
		foreach ( $classifier_new as $original_visit => $new_uids ) {
			try { do_action( 'peptide_core_tracking_client_persisted', (int) $visitor['id'], (int) $original_visit, $new_uids ); }
			catch ( \Throwable $optional_failure ) { /* Classifier failure must not change tracking ACK. */ }
		}
		return self::result( $ack, $rejected, $visitor['id'], $visit ?: ( $impression_visit ?: ( $impression_replays ? reset( $impression_replays ) : 0 ) ), $attempts, $contexts );
	}

	private static function observation_replay( int $visitor, array $c, array $row ): string {
		return Product_Actions::is_type( $c['type'] ) ? Product_Actions::replay_code( $visitor, $c, $row ) : Impressions::replay_code( $visitor, $c, $row );
	}

	private static function replay( int $visitor, array $incoming, array $saved ): array {
		$data = json_decode( (string) $saved['data'], true );
		if ( ! is_array( $data ) || ! isset( $data['attribution'] ) ) { return Attribution::receipt( $incoming['event_id'], 'rejected', 'duplicate_without_capture' ); }
		if ( 'page_view' !== $saved['type'] || 'client' !== $saved['source'] || $saved['path'] !== $incoming['page']['path'] || $data['attribution'] !== $incoming['data']['attribution'] ) { return Attribution::receipt( $incoming['event_id'], 'rejected', 'duplicate_payload_mismatch' ); }
		return Attribution::capture( $visitor, $saved, $data['attribution'] );
	}

	private static function result( array $ack, array $rejected, int $visitor, int $visit, array $attempts, array $contexts = array() ): array {
		$out = array( 'ack' => $ack, 'rejected' => $rejected, 'visitor_id' => $visitor, 'visit_id' => $visit );
		if ( $attempts ) { $out['extensions'] = array( 'attribution' => array( 'version' => 1, 'results' => array_values( $attempts ) ) ); }
		if ( $contexts ) { $out['extensions']['page_context'] = array( 'version' => 1, 'results' => array_values( $contexts ) ); }
		return $out;
	}

	/**
	 * 更新标签页状态。乱序或并发到达的旧事件不会覆盖更新的状态，但仍算作“还在线”的信号。
	 *
	 * “更新”的判断：同一页面实例按序号；不同页面实例按发生时间（同一毫秒时以后到的为准）。
	 * 判断写在 UPDATE 的 WHERE 条件里，由数据库原子地比较，普通请求和离页请求同时到达也不会互相覆盖。
	 */
	private static function update_tab( int $visitor_id, int $visit_id, array $c, int $now_ms ): void {
		global $wpdb;
		$table = Schema::table( 'tabs' );
		// 1. 确保这一行存在（唯一键 visitor_id + tab_id）。
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (visitor_id, visit_id, tab_id, last_signal_ms) VALUES (%d, %d, %s, %d)", $visitor_id, $visit_id, $c['tab_id'], $now_ms ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		// 2. 在线信号：任何事件都算，只增不减。
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET visit_id = %d, last_signal_ms = GREATEST(last_signal_ms, %d) WHERE visitor_id = %d AND tab_id = %s", $visit_id, $now_ms, $visitor_id, $c['tab_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		// 3. 当前状态：只有比已保存的状态更新时才写。
		$page = $c['page'];
		$data = $c['data'];
		$set  = array(
			$wpdb->prepare( 'page_instance_id = %s', $c['page_instance_id'] ),
			$wpdb->prepare( 'page_type = %s', $page['page_type'] ),
			$wpdb->prepare( 'path = %s', $page['path'] ),
			$wpdb->prepare( 'product_id = %d', (int) ( $page['product_id'] ?? 0 ) ),
			$wpdb->prepare( 'product_name = %s', (string) ( $page['product_name'] ?? '' ) ),
			$wpdb->prepare( 'last_type = %s', $c['type'] ),
			$wpdb->prepare( 'last_page_instance = %s', $c['page_instance_id'] ),
			$wpdb->prepare( 'last_seq = %d', $c['seq'] ),
			$wpdb->prepare( 'last_occurred_ms = %d', $c['at'] ),
		);
		if ( isset( $data['visibility'] ) ) {
			$set[] = $wpdb->prepare( 'visibility = %s', $data['visibility'] );
		}
		if ( isset( $data['activity'] ) ) {
			$set[] = $wpdb->prepare( 'activity = %s', $data['activity'] );
		}
		if ( 'page_view' === $c['type'] ) {
			$set[] = 'video = NULL'; // 新页面：之前页面的视频状态不再适用。
		} elseif ( str_starts_with( $c['type'], 'video_' ) ) {
			$video = wp_json_encode(
				array_merge(
					$data,
					array(
						'state' => array( 'video_play' => 'playing', 'video_pause' => 'paused', 'video_end' => 'ended', 'video_seek' => 'seeking', 'video_progress' => 'playing' )[ $c['type'] ],
						'at'    => $c['at'],
					)
				)
			);
			$set[] = $wpdb->prepare( 'video = %s', $video );
		}
		$newer = $wpdb->prepare(
			'( ( last_page_instance = %s AND last_seq < %d ) OR ( last_page_instance <> %s AND last_occurred_ms <= %d ) )',
			$c['page_instance_id'],
			$c['seq'],
			$c['page_instance_id'],
			$c['at']
		);
		// 各片段已经分别 prepare 过，这里只拼接，不能再整体 prepare（值里的 % 会被当成占位符）。
		$where = $wpdb->prepare( 'visitor_id = %d AND tab_id = %s', $visitor_id, $c['tab_id'] );
		$wpdb->query( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE {$where} AND {$newer}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
