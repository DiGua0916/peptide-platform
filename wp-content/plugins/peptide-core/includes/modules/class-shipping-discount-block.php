<?php
/** O22 presentation over existing shipping rules; no checkout or persistence changes. */
namespace PeptideCore\Modules;

use PeptideCore\Shipping\Discounts;
use PeptideCore\Customer_Segments;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Shipping_Discount_Block {

	public static function definition( array $definition ): array {
		foreach ( $definition['settings'] as &$field ) {
			if ( 'title' !== $field['key'] ) { $field['show_if'] = ( $field['show_if'] ?? array() ) + array( 'shippingMode' => 'legacy' ); }
		}
		unset( $field );
		$only = array( 'group' => 'general', 'show_if' => array( 'shippingMode' => 'reference' ) );
		$f = static fn( $type, $key, $label, $default, $extra = array() ) => Fields::f( $type, $key, $label, $default, $only + $extra );
		$definition['settings'][] = Fields::f( 'select', 'shippingMode', '运费折扣外观', 'legacy', array( 'group' => 'general', 'options' => array( 'legacy' => '原有活动列表', 'reference' => '运费阶梯卡片' ) ) );
		$definition['settings'][] = $f( 'repeater', 'shippingRecords', '选择运费折扣', null, array( 'max' => 32, 'fields' => array( Fields::f( 'record', 'record', '运费折扣', null, array( 'resource' => 'shipping_discount' ) ) ) ) );
		$rich = array( 'ui' => 'richtext', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/shipping-discount-list' );
		$definition['settings'][] = $f( 'html', 'descriptionHtml', '简短描述', null, $rich + array( 'legacyKey' => 'description' ) );
		$definition['settings'][] = $f( 'toggle', 'fullWidth', '宽度铺满', false );
		$definition['settings'][] = $f( 'select', 'shippingCountdownFormat', '活动倒计时显示样式', 'dhms', array( 'options' => array( 'dhms' => 'd:h:m:s', 'hms' => 'h:m:s' ) ) );
		$definition['settings'][] = $f( 'number', 'shippingDesktopColumns', 'PC每排数量', 1, array( 'ui' => 'range', 'min' => 1, 'max' => 5, 'precision' => 0 ) );
		$definition['settings'][] = $f( 'number', 'shippingMobileColumns', '移动端平铺每排数量', 1, array( 'ui' => 'range', 'min' => 1, 'max' => 2, 'precision' => 0 ) );
		$definition['settings'][] = $f( 'select', 'shippingMobileLayout', '移动端阶梯优惠展示样式', 'carousel', array( 'options' => array( 'grid' => '竖向平铺', 'carousel' => '轮播图' ) ) );
		$definition['settings'][] = $f( 'text', 'shippingButtonLabel', '按钮标题', '' );
		$definition['settings'][] = $f( 'url', 'shippingButtonUrl', '按钮链接', '', array( 'ui' => 'site-link' ) );
		$definition['settings'][] = $f( 'select', 'shippingButtonEffect', '按钮特效', 'none', array( 'options' => array( 'none' => '无特效', 'bounce' => '跳动', 'rubberband' => '橡皮筋', 'shake' => '左右抖动', 'swing' => '摇摆', 'wobble' => '摇晃' ) ) );
		$definition['settings'][] = $f( 'number', 'shippingEffectDurationMs', '按钮动画时间', 1000, array( 'ui' => 'seconds', 'min' => 1000, 'max' => 5000, 'step' => 100 ) );
		$definition['settings'][] = $f( 'number', 'shippingEffectIntervalMs', '按钮动画间隔时间', 1500, array( 'ui' => 'seconds', 'min' => 500, 'max' => 5000, 'step' => 100 ) );
		$definition['settings'][] = $f( 'html', 'discountTemplateHtml', '优惠文案', null, $rich + array( 'help' => '{{discount}} 是真实时段的优惠金额或折扣。只替换正文文字，不替换图片、链接或其他属性。' ) );
		$definition['settings'][] = $f( 'text', 'shippingUpcomingLabel', '未开始文案', 'Starts in' );
		foreach ( self::colors() as $key => $color ) { $definition['settings'][] = $f( 'color', $key, $color[0], $color[1] ); }
		// Explicit business panels keep reference controls out of the collapsed shared spacing group.
		$definition['panels'] = array(
			array( 'id' => 'shipping-activities', 'label' => '活动选择', 'initialOpen' => true, 'fields' => array( 'shippingMode', 'shippingRecords', 'selection', 'records' ) ),
			array( 'id' => 'shipping-content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'description', 'descriptionHtml', 'discountTemplateHtml' ) ),
			array( 'id' => 'shipping-layout', 'label' => '布局', 'fields' => array( 'fullWidth', 'shippingDesktopColumns', 'shippingMobileColumns', 'shippingMobileLayout', 'limit', 'columns' ) ),
			array( 'id' => 'shipping-countdown', 'label' => '倒计时', 'fields' => array( 'shippingCountdownFormat', 'shippingUpcomingLabel' ) ),
			array( 'id' => 'shipping-button', 'label' => '按钮', 'fields' => array( 'shippingButtonLabel', 'shippingButtonUrl', 'shippingButtonEffect', 'shippingEffectDurationMs', 'shippingEffectIntervalMs' ) ),
			array( 'id' => 'shipping-colors', 'label' => '颜色', 'fields' => array_keys( self::colors() ) ),
		);
		foreach ( $definition['settings'] as &$field ) {
			foreach ( $definition['panels'] as $panel ) { if ( in_array( $field['key'], $panel['fields'], true ) ) { $field['panel'] = $panel['id']; break; } }
		}
		unset( $field );
		$definition['editorDefaults'] = array( 'shippingMode' => 'reference', 'title' => 'Shipping offers', 'descriptionHtml' => null, 'discountTemplateHtml' => '<p>{{discount}} OFF</p>' );
		return $definition;
	}

	public static function colors(): array {
		return array(
			'shippingUpcomingClockText' => array( '未开始倒计时文字颜色', '#666666' ),
			'shippingUpcomingOfferText' => array( '未开始优惠文字颜色', '#ffffff' ),
			'shippingUpcomingOfferBackground' => array( '未开始优惠背景颜色', '#d3d3d3' ),
			'shippingUpcomingClockBackground' => array( '未开始倒计时背景颜色', '#ffffff' ),
			'shippingActiveOfferText' => array( '进行中优惠文字颜色', '#ffffff' ),
			'shippingActiveClockText' => array( '进行中倒计时文字颜色', '#885dff' ),
			'shippingActiveOfferBackground' => array( '进行中优惠背景颜色', '#885dff' ),
			'shippingActiveClockBackground' => array( '进行中倒计时背景颜色', '#ffffff' ),
		);
	}

	private static function date( $value ): ?int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]{0,11})$/D', $value ) ) ) { return null; }
		$n = (int) $value; return $n >= 0 && $n <= 253402300799 ? $n : null;
	}

	/** Qualify the actual stored schedule before projecting it. No client/customer fields returned. */
	public static function schedule( array $d, int $now ): array {
		if ( empty( $d['enabled'] ) || ! in_array( $d['mode'] ?? '', array( 'fixed', 'stages' ), true ) || ! in_array( $d['kind'] ?? '', array( 'percent', 'amount' ), true ) ) { return array(); }
		$start = self::date( $d['start'] ?? null ); $end = self::date( $d['end'] ?? null );
		if ( null === $start || null === $end || ( $end && $end <= $start ) || in_array( Discounts::status( $d, $now ), array( 'off', 'ended' ), true ) ) { return array(); }
		$source = 'fixed' === $d['mode'] ? array( array( 'start' => $start, 'end' => $end, 'value' => $d['value'] ?? null ) ) : ( $d['stages'] ?? null );
		if ( ! is_array( $source ) || ! $source || count( $source ) > Discounts::MAX_STAGES ) { return array(); }
		if ( 'stages' === $d['mode'] ) {
			$first = reset( $source ); $last = end( $source );
			if ( ! is_array( $first ) || ! is_array( $last ) || self::date( $first['start'] ?? null ) !== $start || self::date( $last['end'] ?? null ) !== $end ) { return array(); }
		}
		$rows = array(); $previous = 0;
		foreach ( $source as $stage ) {
			if ( ! is_array( $stage ) ) { return array(); }
			$a = self::date( $stage['start'] ?? null ); $b = self::date( $stage['end'] ?? null ); $v = $stage['value'] ?? null;
			if ( null === $a || null === $b || ! is_numeric( $v ) || ! is_finite( (float) $v ) || (float) $v <= 0 || ( 'percent' === $d['kind'] && (float) $v > 100 ) || ( $b && $b <= $a ) || ( 'stages' === $d['mode'] && ( ! $a || ! $b || $a < $previous ) ) ) { return array(); }
			$previous = $b;
			if ( $b && $now >= $b ) { continue; }
			$state = $a > $now ? 'upcoming' : 'active';
			if ( 'active' === $state && Discounts::value_now( $d, $now ) !== (float) $v ) { continue; }
			$rows[] = array( 'start' => $a, 'end' => $b, 'value' => (float) $v, 'kind' => $d['kind'], 'state' => $state );
		}
		return $rows;
	}

	public static function clock( int $seconds, string $format ): string {
		$seconds = max( 0, $seconds );
		$parts = 'hms' === $format ? array( intdiv( $seconds, 3600 ) . 'h', str_pad( (string) ( intdiv( $seconds, 60 ) % 60 ), 2, '0', STR_PAD_LEFT ) . 'm', str_pad( (string) ( $seconds % 60 ), 2, '0', STR_PAD_LEFT ) . 's' ) : array( intdiv( $seconds, 86400 ) . 'd', str_pad( (string) ( intdiv( $seconds, 3600 ) % 24 ), 2, '0', STR_PAD_LEFT ) . 'h', str_pad( (string) ( intdiv( $seconds, 60 ) % 60 ), 2, '0', STR_PAD_LEFT ) . 'm', str_pad( (string) ( $seconds % 60 ), 2, '0', STR_PAD_LEFT ) . 's' );
		return implode( ' : ', $parts );
	}

	/** Replace tokens only in actual text tokens; preserve approved image/link attributes. */
	public static function template( string $html, string $discount ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || ! method_exists( '\WP_HTML_Tag_Processor', 'set_modifiable_text' ) ) { return esc_html( str_replace( '{{discount}}', $discount, wp_strip_all_tags( $html ) ) ); }
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_token() ) {
			if ( '#text' === $p->get_token_type() ) { $p->set_modifiable_text( str_replace( '{{discount}}', $discount, $p->get_modifiable_text() ) ); }
		}
		return $p->get_updated_html();
	}

	public static function render( array $module, string $context ): string {
		$preview = ( 'editor' === $context && Renderer::$in_editor && current_user_can( Templates::CAP_EDIT ) ) || ( 'preview' === $context && Templates::previewing_id() > 0 && current_user_can( Templates::CAP_EDIT ) );
		if ( 'shipping_discount_list' !== ( $module['type'] ?? '' ) || ( 'public' !== $context && ! $preview ) || ( ! $preview && \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) || empty( $module['enabled'] ) || 'reference' !== ( $module['settings']['shippingMode'] ?? '' ) ) { return ''; }
		if ( isset( $module['visibility'] ) && empty( $module['visibility']['desktop'] ) && empty( $module['visibility']['mobile'] ) && ! $preview ) { return ''; }
		$s = $module['settings']; $now = time(); $all = Discounts::all(); $rows = array(); $seen = array();
		// Timed/customer-qualified output must not enter a shared page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) { nocache_headers(); }
		foreach ( array_slice( is_array( $s['shippingRecords'] ?? null ) ? $s['shippingRecords'] : array(), 0, 32 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['enabled'] ) ) { continue; }
			$id = $item['settings']['record'] ?? null;
			if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $id ) || isset( $seen[ (string) $id ] ) ) { continue; }
			$seen[ (string) $id ] = true; $d = $all[ (int) $id ] ?? null;
			if ( ! is_array( $d ) ) { continue; }
			$stages = self::schedule( $d, $now ); if ( ! $stages ) { continue; }
			$scope = $d['customers'] ?? 'all';
			if ( ! in_array( $scope, array( 'all', 'emails', 'tags', 'levels', 'visitor' ), true ) ) { continue; }
			$email = 'emails' === $scope ? Customer_Segments::current_email() : '';
			if ( ! Discounts::applies_to( $d, $email, get_current_user_id(), $now ) ) { continue; }
			foreach ( $stages as $stage ) {
				$stage['refreshAtStart'] = 'all' !== $scope || ! empty( $d['member_day'] );
				$stage['checkAt'] = ! empty( $d['member_day'] ) ? ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp() : 0;
				$rows[] = $stage;
			}
		}
		if ( ! $rows ) { return $preview ? '<div class="pc-cb vc-m__inner pc-shipping-reference"><p role="status">请选择仍有效且适用于当前访客的真实运费折扣。未开始的真实时段会单独标示；没有活动时不会生成示例优惠。</p></div>' : ''; }
		$styles = array();
		foreach ( self::colors() as $key => $default ) { $color = $s[ $key ] ?? $default[1]; if ( ! is_string( $color ) || ! preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $color ) ) { $color = $default[1]; } $styles[] = '--pc-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $key ) ) . ':' . $color; }
		$desktop = max( 1, min( 5, (int) ( $s['shippingDesktopColumns'] ?? 1 ) ) ); $mobile = max( 1, min( 2, (int) ( $s['shippingMobileColumns'] ?? 1 ) ) );
		$styles[] = '--pc-shipping-columns:' . $desktop; $styles[] = '--pc-shipping-columns-mobile:' . $mobile;
		$styles[] = '--pc-shipping-effect-duration:' . max( 1000, min( 5000, (int) ( $s['shippingEffectDurationMs'] ?? 1000 ) ) ) . 'ms';
		$format = 'hms' === ( $s['shippingCountdownFormat'] ?? '' ) ? 'hms' : 'dhms'; $cards = '';
		$template = null !== ( $s['discountTemplateHtml'] ?? null ) ? (string) $s['discountTemplateHtml'] : '<p>{{discount}} OFF</p>';
		foreach ( $rows as $r ) {
			$value = 'percent' === $r['kind'] ? wc_format_localized_decimal( $r['value'] ) . '%' : html_entity_decode( wp_strip_all_tags( wc_price( $r['value'] ) ), ENT_QUOTES, 'UTF-8' );
			$deadline = 'upcoming' === $r['state'] ? $r['start'] : $r['end'];
			$label = 'upcoming' === $r['state'] ? esc_html( (string) ( $s['shippingUpcomingLabel'] ?? 'Starts in' ) ) . ' ' : '';
			$clock = $deadline ? self::clock( $deadline - $now, $format ) : __( 'No expiration date', 'peptide-core' );
			$date = 'upcoming' === $r['state'] ? '<time datetime="' . esc_attr( gmdate( 'c', $r['start'] ) ) . '">' . esc_html( wp_date( 'Y-m-d H:i T', $r['start'] ) ) . '</time>' : '';
			$cards .= '<li class="pc-shipping-reference__card" data-state="' . $r['state'] . '" data-start="' . $r['start'] . '" data-end="' . $r['end'] . '" data-check-at="' . $r['checkAt'] . '" data-refresh-at-start="' . ( $r['refreshAtStart'] ? '1' : '0' ) . '"><div class="pc-shipping-reference__offer">' . self::template( wp_kses_post( $template ), $value ) . '</div><div class="pc-shipping-reference__timer"><span data-shipping-label>' . $label . '</span><output aria-live="off" data-shipping-clock>' . esc_html( $clock ) . '</output>' . $date . '</div></li>';
		}
		$description = null !== ( $s['descriptionHtml'] ?? null ) ? wp_kses_post( (string) $s['descriptionHtml'] ) : ( '' !== ( $s['description'] ?? '' ) ? '<p>' . esc_html( (string) $s['description'] ) . '</p>' : '' );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2>' . esc_html( (string) $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-shipping-reference__description">' . $description . '</div>' : '';
		$url = Fields::url( (string) ( $s['shippingButtonUrl'] ?? '' ) ); $button = (string) ( $s['shippingButtonLabel'] ?? '' ); $effect = in_array( $s['shippingButtonEffect'] ?? '', array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ? $s['shippingButtonEffect'] : 'none';
		$more = '' !== $url && '' !== trim( $button ) ? '<div class="pc-shipping-reference__more">' . ( $preview ? '<span aria-disabled="true">' . esc_html( $button ) . '</span>' : '<a href="' . esc_url( $url ) . '" data-shipping-effect="' . esc_attr( $effect ) . '">' . esc_html( $button ) . '</a>' ) . '</div>' : '';
		return '<section class="pc-cb vc-m__inner pc-shipping-reference' . ( ! empty( $s['fullWidth'] ) ? ' vc-m__inner--full' : '' ) . '" data-pc-shipping-reference data-server-now="' . $now . '" data-clock-format="' . $format . '" data-upcoming-label="' . esc_attr( (string) ( $s['shippingUpcomingLabel'] ?? 'Starts in' ) ) . '" data-no-end-label="' . esc_attr__( 'No expiration date', 'peptide-core' ) . '" data-mobile-layout="' . ( 'grid' === ( $s['shippingMobileLayout'] ?? '' ) ? 'grid' : 'carousel' ) . '" data-desktop-columns="' . $desktop . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $s['shippingEffectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $s['shippingEffectIntervalMs'] ?? 1500 ) ) ) . '" style="' . esc_attr( implode( ';', $styles ) ) . '">' . ( $head ? '<header class="pc-shipping-reference__head">' . $head . '</header>' : '' ) . '<ul class="pc-shipping-reference__list" tabindex="0" aria-label="' . esc_attr__( 'Shipping discounts', 'peptide-core' ) . '">' . $cards . '</ul>' . $more . '</section>';
	}
}
