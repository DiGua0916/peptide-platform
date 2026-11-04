<?php
/** Merchant-selected activity listener settings; no copied discount rules. */
namespace PeptideCore\Activity;

use PeptideCore\Promotions;

defined( 'ABSPATH' ) || exit;

final class Config {
	public const VERSION = 1;
	public const KEYS = array( 'version', 'enabled', 'promotionId', 'title', 'celebrationEnabled', 'smileEnabled', 'recommendation', 'recommendationCoupon' );
	public const RECOMMENDATION_KEYS = array( 'enabled', 'maxGapRatio', 'maxGapMinor', 'maxGapQuantity', 'maxAddBoxes', 'maxVisible', 'ranking' );
	public static function defaults(): array {
		return array( 'version' => 1, 'enabled' => false, 'promotionId' => 0, 'title' => 'Your offer progress', 'celebrationEnabled' => true, 'smileEnabled' => true, 'recommendation' => self::recommendation_defaults(), 'recommendationCoupon' => array( 'enabled' => false, 'couponId' => 0, 'message' => '' ) );
	}
	public static function recommendation_defaults(): array {
		return array( 'enabled' => false, 'maxGapRatio' => 0.2, 'maxGapMinor' => null, 'maxGapQuantity' => 3, 'maxAddBoxes' => 1, 'maxVisible' => 1, 'ranking' => 'most_viewed_then_recent' );
	}
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || ( $input && array_is_list( $input ) ) ) { return self::error( 'config', 'object_required', '活动监听配置必须是对象。' ); }
		foreach ( array_keys( $input ) as $key ) { if ( ! in_array( $key, self::KEYS, true ) ) { return self::error( (string) $key, 'unknown_field', '包含未支持的配置字段。' ); } }
		$config = array_replace( self::defaults(), $input );
		if ( 1 !== $config['version'] ) { return self::error( 'version', 'invalid_version', '仅支持整数版本 1。' ); }
		foreach ( array( 'enabled', 'celebrationEnabled', 'smileEnabled' ) as $key ) { if ( ! is_bool( $config[ $key ] ) ) { return self::error( $key, 'boolean_required', '开关必须为 true 或 false。' ); } }
		if ( ! is_int( $config['promotionId'] ) || $config['promotionId'] < 0 || $config['promotionId'] > 2147483647 ) { return self::error( 'promotionId', 'invalid_id', '请选择本站的优惠活动。' ); }
		$title = $config['title'];
		if ( ! is_string( $title ) || 1 !== preg_match( '//u', $title ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<(?:\/?[a-z][^>]*>|!--|!doctype\b|\?)/iu', $title ) ) { return self::error( 'title', 'plain_text_required', '标题仅接受有效的纯文本。' ); }
		if ( class_exists( '\Normalizer' ) ) { $title = \Normalizer::normalize( $title, \Normalizer::FORM_C ); }
		$title = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $title ) );
		if ( preg_match_all( '/./us', $title ) > 100 ) { return self::error( 'title', 'too_long', '标题最多 100 个字符。' ); }
		$config['title'] = $title;
		// Missing the whole new object preserves legacy v1 storage and stays disabled.
		$recommendation = self::normalize_recommendation( $config['recommendation'] );
		if ( is_wp_error( $recommendation ) ) { return $recommendation; }
		$config['recommendation'] = $recommendation;
		$coupon = $config['recommendationCoupon'];
		if ( ! is_array( $coupon ) || array_is_list( $coupon ) || array_diff( array_keys( $coupon ), array( 'enabled', 'couponId', 'message' ) ) || 3 !== count( $coupon ) || ! is_bool( $coupon['enabled'] ?? null ) || ! is_int( $coupon['couponId'] ?? null ) || $coupon['couponId'] < 0 || $coupon['couponId'] > 2147483647 || ! is_string( $coupon['message'] ?? null ) || ! preg_match( '//u', $coupon['message'] ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<[^>]*>/u', $coupon['message'] ) || preg_match_all( '/./us', $coupon['message'] ) > 200 ) { return self::error( 'recommendationCoupon', 'invalid_coupon_settings', '推荐优惠券须包含开关、本站优惠券和200字以内纯文案。' ); }
		$coupon['message'] = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $coupon['message'] ) );
		if ( $coupon['enabled'] && ( ! $coupon['couponId'] || ! $recommendation['enabled'] ) ) { return self::error( 'recommendationCoupon', 'selection_required', '启用推荐优惠券前，请启用凑单推荐并选择已有优惠券。' ); }
		$config['recommendationCoupon'] = $coupon;
		return $config;
	}

	/** A supplied nested object is a complete replacement; absent safety flags never inherit true. */
	private static function normalize_recommendation( $input ) {
		if ( ! is_array( $input ) || array_is_list( $input ) ) { return self::error( 'recommendation', 'object_required', '凑单推荐配置必须是完整对象。' ); }
		foreach ( array_keys( $input ) as $key ) { if ( ! in_array( $key, self::RECOMMENDATION_KEYS, true ) ) { return self::error( 'recommendation.' . (string) $key, 'unknown_field', '包含未支持的凑单推荐字段。' ); } }
		foreach ( self::RECOMMENDATION_KEYS as $key ) { if ( ! array_key_exists( $key, $input ) ) { return self::error( 'recommendation.' . $key, 'required', '请重新读取并提交完整的凑单推荐配置。' ); } }
		if ( ! is_bool( $input['enabled'] ) ) { return self::error( 'recommendation.enabled', 'boolean_required', '推荐开关必须为 true 或 false。' ); }
		$ratio = $input['maxGapRatio'];
		if ( ( ! is_int( $ratio ) && ! is_float( $ratio ) ) || ! is_finite( (float) $ratio ) || $ratio < 0 || $ratio > 1 ) { return self::error( 'recommendation.maxGapRatio', 'out_of_range', '差额比例须为 0 至 1 的数字，0.20 表示 20%。' ); }
		$input['maxGapRatio'] = (float) $ratio;
		$minor = $input['maxGapMinor'];
		if ( null !== $minor && ( ! is_string( $minor ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,15})\z/', $minor ) || strlen( $minor ) > 16 || ( 16 === strlen( $minor ) && strcmp( $minor, '9007199254740991' ) > 0 ) ) ) { return self::error( 'recommendation.maxGapMinor', 'invalid_minor', '金额差额上限须为空或不超过 9007199254740991 的非负整数字符串。' ); }
		foreach ( array( 'maxGapQuantity' => 10000, 'maxAddBoxes' => 100, 'maxVisible' => 3 ) as $key => $max ) { if ( ! is_int( $input[ $key ] ) || $input[ $key ] < 1 || $input[ $key ] > $max ) { return self::error( 'recommendation.' . $key, 'out_of_range', sprintf( '此项须为 1 至 %d 的整数。', $max ) ); } }
		// Accept valid legacy fields, then canonicalize in memory. No option is written by a read.
		$input['maxAddBoxes'] = 1;
		$input['maxVisible'] = 1;
		if ( 'most_viewed_then_recent' !== $input['ranking'] ) { return self::error( 'recommendation.ranking', 'invalid_ranking', '推荐排序仅支持先按浏览次数、再按最近浏览。' ); }
		$out = array();
		foreach ( self::RECOMMENDATION_KEYS as $key ) { $out[ $key ] = $input[ $key ]; }
		return $out;
	}

	/** Publishing a live reference never publishes or changes the underlying promotion. */
	public static function validate_selection( int $id ) {
		if ( ! $id || Promotions::CPT !== get_post_type( $id ) || ! in_array( Promotions::status( $id ), array( 'active', 'upcoming' ), true ) || ! self::supported( Promotions::config( $id ) ) ) {
			return self::error( 'promotionId', 'unavailable_promotion', '启用前请选择已启用或即将开始的有效满减活动；停用、过期或不存在的活动不能应用。' );
		}
		return true;
	}
	private static function supported( array $config ): bool {
		if ( ! in_array( $config['type'] ?? '', Promotions::TYPES, true ) || ! is_array( $config['tiers'] ?? null ) || ! $config['tiers'] || count( $config['tiers'] ) > Promotions::MAX_TIERS || ( ! empty( $config['unlimited'] ) && ( count( $config['tiers'] ) !== 1 || ! in_array( $config['type'], Promotions::REPEATABLE, true ) ) ) ) { return false; }
		$decimals = wc_get_price_decimals();
		if ( $decimals < 0 || $decimals > 8 ) { return false; }
		$quantity = Promotions::by_quantity( $config );
		$last = 0.0;
		foreach ( $config['tiers'] as $tier ) {
			if ( ! is_array( $tier ) || ! is_numeric( $tier['min'] ?? null ) || ! is_numeric( $tier['value'] ?? null ) || ! is_finite( (float) $tier['min'] ) || ! is_finite( (float) $tier['value'] ) || (float) $tier['min'] <= $last || (float) $tier['value'] <= 0 ) { return false; }
			if ( ( $quantity && floor( (float) $tier['min'] ) !== (float) $tier['min'] ) || (float) $tier['min'] * ( 10 ** $decimals ) > 9007199254740991 || (float) $tier['value'] * ( 10 ** $decimals ) > 9007199254740991 ) { return false; }
			$last = (float) $tier['min'];
		}
		return true;
	}
	/** Private application choices, including unavailable rows for an honest status label. */
	public static function choices( int $selected = 0 ): array {
		$ids = get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 300, 'fields' => 'ids', 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ), 'no_found_rows' => true ) );
		if ( $selected && Promotions::CPT === get_post_type( $selected ) && 'trash' !== get_post_status( $selected ) && ! in_array( $selected, array_map( 'intval', $ids ), true ) ) { $ids[] = $selected; }
		$rows = array();
		foreach ( $ids as $id ) {
			$id = (int) $id; $config = Promotions::config( $id ); $supported = self::supported( $config );
			$rows[] = array( 'id' => $id, 'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ), 'status' => Promotions::status( $id ), 'type' => $config['type'], 'basis' => Promotions::by_quantity( $config ) ? 'quantity' : 'spend', 'unlimited' => (bool) $config['unlimited'], 'supported' => $supported,
				'tiers' => $supported ? array_map( static fn( $tier ) => array( 'min' => $tier['min'], 'rewardText' => Promotions::reward_text( $config, $tier ) ), $config['tiers'] ) : array() );
		}
		return $rows;
	}
	public static function error( string $field, string $reason, string $message ): \WP_Error {
		return new \WP_Error( 'pc_activity_listener_invalid', $message, array( 'status' => 400, 'fields' => array( $field => $reason ) ) );
	}
}
