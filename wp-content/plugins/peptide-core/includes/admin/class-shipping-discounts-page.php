<?php
/**
 * 营销活动 → 运费折扣（SHIPPING-DISCOUNT-01），版式参照 Shopname 的“运费折扣”列表页和新建折扣页。
 * 数据和计算在 PeptideCore\Shipping\Discounts。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Shipping_Discounts_Page {

	public const SLUG = 'pc-shipping-discounts';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_shipdisc_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_shipdisc_toggle', array( self::class, 'toggle' ) );
		add_action( 'admin_post_pc_shipdisc_bulk', array( self::class, 'bulk' ) );
	}

	public static function url( array $args = array() ): string {
		return Marketing::url( self::SLUG, $args );
	}

	// ---------- 列表 ----------

	public static function render(): void {
		if ( ! current_user_can( Marketing::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
			self::render_edit( absint( $_GET['edit'] ?? 0 ) );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rows = array();
		foreach ( Discounts::all() as $d ) {
			$rows[] = array( 'id' => $d['id'], 'd' => $d, 'status' => Discounts::status( $d ), 'search' => $d['name'] );
		}
		$rows           = Marketing::filter_rows( $rows );
		[ $page, $per ] = Marketing::current_paging();
		$shown          = array_slice( $rows, ( $page - 1 ) * $per, $per );

		echo '<div class="wrap pc-pl pc-mk">';
		Marketing::notices();
		Marketing::list_head( __( 'Shipping discounts', 'peptide-core' ), self::url( array( 'new' => 1 ) ), __( 'New discount', 'peptide-core' ) );
		echo '<div class="pc-pl-card">';
		Marketing::tabs_and_search( self::SLUG, __( 'Search by promotion name', 'peptide-core' ), __( 'Note: when several shipping discounts apply at the same time, the customer gets the lowest shipping fee.', 'peptide-core' ) );
		Marketing::bulk_open( 'pc_shipdisc_bulk' );
		echo '<div class="pc-mk-scroll"><table class="pc-pl-table pc-mk-table"><thead><tr>' . Marketing::check_head() . '<th class="pc-mk-col-act">' . esc_html__( 'Actions', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion type', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion time', 'peptide-core' ) . '</th><th>' . esc_html__( 'Discount rules', 'peptide-core' ) . '</th><th>' . esc_html__( 'Scope', 'peptide-core' ) . '</th><th>' . esc_html__( 'Promotion status', 'peptide-core' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $shown ) {
			echo '<tr><td colspan="8" class="pc-pl-empty">' . esc_html__( 'No shipping discounts yet.', 'peptide-core' ) . '</td></tr>';
		}
		foreach ( $shown as $r ) {
			$d = $r['d'];
			echo '<tr>' . Marketing::check_cell( $d['id'] ) . '<td class="pc-mk-col-act">' . Marketing::row_actions( self::url( array( 'edit' => $d['id'] ) ), wp_nonce_url( admin_url( 'admin-post.php?action=pc_shipdisc_toggle&id=' . $d['id'] . '&on=' . ( $d['enabled'] ? 0 : 1 ) ), 'pc_shipdisc_toggle' ), $d['enabled'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><a class="pc-mk-name" href="' . esc_url( self::url( array( 'edit' => $d['id'] ) ) ) . '">' . esc_html( $d['name'] ) . '</a></td>';
			echo '<td>' . esc_html( 'stages' === $d['mode'] ? __( 'Time-limited stages', 'peptide-core' ) : __( 'Fixed discount', 'peptide-core' ) ) . '</td>';
			echo '<td class="pc-mk-time-cell">' . esc_html( Marketing::date_text( (int) $d['start'] ) . ' ~ ' . ( $d['end'] ? Marketing::date_text( (int) $d['end'] ) : __( 'Long-term', 'peptide-core' ) ) ) . '</td>';
			echo '<td>' . implode( '<br>', array_map( 'esc_html', Discounts::rule_lines( $d ) ) ) . '</td><td>' . esc_html( self::customers_text( $d ) ) . '</td><td>' . Marketing::badge( $r['status'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div></form>';
		Marketing::pager( self::SLUG, count( $rows ), $page, $per );
		echo '</div></div>';
	}

	public static function customers_text( array $d ): string {
		/* translators: %d: number of customers */
		$who = 'emails' === $d['customers'] ? sprintf( _n( '%d specific customer', '%d specific customers', count( $d['emails'] ), 'peptide-core' ), count( $d['emails'] ) ) : __( 'All customers', 'peptide-core' );
		if ( $d['member_day'] ) {
			/* translators: %s: weekdays */
			$who .= ' · ' . sprintf( __( 'Member days: %s', 'peptide-core' ), implode( ', ', array_map( array( self::class, 'weekday' ), $d['member_days'] ) ) );
		}
		return $who;
	}

	/** ISO 星期（1 = 星期一）的名称。 */
	public static function weekday( int $n ): string {
		global $wp_locale;
		return $wp_locale ? (string) $wp_locale->get_weekday( $n % 7 ) : (string) $n;
	}

	// ---------- 编辑 ----------

	private static function render_edit( int $id ): void {
		$d = $id ? Discounts::get( $id ) : null;
		if ( $id && ! $d ) {
			echo '<div class="wrap"><p>' . esc_html__( 'This shipping discount does not exist.', 'peptide-core' ) . '</p></div>';
			return;
		}
		$d    = $d ?? Discounts::defaults();
		$long = $id && 'fixed' === $d['mode'] && ! $d['end'];
		$kept = Marketing::kept_form( 'shipdisc' );
		if ( $kept && (int) ( $kept['id'] ?? 0 ) === $id ) {
			$long = 'long' === ( $kept['time_mode'] ?? '' );
			$d    = array_merge( $d, array_intersect_key( $kept, $d ) );
		}
		$cur    = get_woocommerce_currency();
		$pre    = array( 'amount' => __( 'Reduce by', 'peptide-core' ), 'percent' => __( 'Shipping fee', 'peptide-core' ) );
		$unit   = array( 'amount' => $cur, 'percent' => '% off' );
		$stages = $d['stages'] ? $d['stages'] : array( array( 'start' => 0, 'end' => 0, 'value' => '' ) );

		echo '<div class="wrap pc-pl pc-mk pc-mk-edit">';
		Marketing::notices();
		Marketing::edit_head( self::url(), $id ? __( 'Edit discount', 'peptide-core' ) : __( 'New discount', 'peptide-core' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-mk-form" data-kind="shipping">';
		echo '<input type="hidden" name="action" value="pc_shipdisc_save"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'pc_shipdisc_save' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Marketing 的小部件已转义。
		Marketing::card_open( __( 'Basic information', 'peptide-core' ) );
		echo '<div class="pc-mk-field pc-mk-narrow">' . Marketing::label( __( 'Discount name', 'peptide-core' ), true, '', 'pc-mk-name' ) . Marketing::counted( 'name', (string) $d['name'], Discounts::NAME_MAX, __( 'Enter the discount name, e.g. Shipping', 'peptide-core' ), 'pc-mk-name', true ) . '<p class="pc-bc-note">' . esc_html__( 'Recorded on the shipping line of the order.', 'peptide-core' ) . '</p></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Promotion type', 'peptide-core' ), false, __( "Fixed discount: one discount for the whole promotion time.\nTime-limited stages: up to 5 time periods, each with its own discount, e.g. 50% off in the first 24 hours, then 20% off for a week.", 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'mode', 'fixed', (string) $d['mode'], __( 'Fixed discount', 'peptide-core' ) ) . Marketing::radio( 'mode', 'stages', (string) $d['mode'], __( 'Time-limited stages', 'peptide-core' ) ) . '</div></div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Member days only', 'peptide-core' ), false, __( 'Yes: the discount only works on the chosen weekdays (store time zone) and only for signed-in customers with an account – the members of this store.', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'member_day', '1', $d['member_day'] ? '1' : '0', __( 'Yes', 'peptide-core' ) ) . Marketing::radio( 'member_day', '0', $d['member_day'] ? '1' : '0', __( 'No', 'peptide-core' ) ) . '</div>';
		echo '<div class="pc-mk-sub pc-mk-weekdays" data-show="member_day=1">';
		for ( $n = 1; $n <= 7; $n++ ) {
			echo '<label><input type="checkbox" name="member_days[]" value="' . (int) $n . '"' . checked( in_array( $n, (array) $d['member_days'], true ), true, false ) . '> ' . esc_html( self::weekday( $n ) ) . '</label>';
		}
		echo '</div></div>';
		echo '<div data-show="mode=fixed">';
		Marketing::time_fields( (int) $d['start'], (int) $d['end'], $long );
		echo '</div>';
		echo '<div class="pc-mk-field">' . Marketing::label( __( 'Discount type', 'peptide-core' ) ) . '<div class="pc-mk-radios">' . Marketing::radio( 'kind', 'amount', (string) $d['kind'], __( 'Amount off shipping', 'peptide-core' ) ) . Marketing::radio( 'kind', 'percent', (string) $d['kind'], __( 'Percentage off shipping', 'peptide-core' ) ) . '</div>';
		$kind_attrs = ' data-pre-amount="' . esc_attr( $pre['amount'] ) . '" data-pre-percent="' . esc_attr( $pre['percent'] ) . '" data-unit-amount="' . esc_attr( $unit['amount'] ) . '" data-unit-percent="' . esc_attr( $unit['percent'] ) . '"';
		echo '<div class="pc-mk-inline pc-mk-kind" data-show="mode=fixed"><span class="pc-mk-kind-pre"' . $kind_attrs . '>' . esc_html( $pre[ $d['kind'] ] ) . '</span><input type="number" min="0" step="0.01" class="pc-mk-num" name="value" value="' . esc_attr( $d['value'] ? (string) $d['value'] : '' ) . '" placeholder="' . esc_attr__( 'Enter', 'peptide-core' ) . '"><span class="pc-mk-kind-unit"' . $kind_attrs . '>' . esc_html( $unit[ $d['kind'] ] ) . '</span></div>';
		echo '<p class="pc-bc-note">' . esc_html__( 'Shipping is never reduced below zero; 100% off means free shipping.', 'peptide-core' ) . '</p></div>';

		/* translators: %s: time zone */
		echo '<div class="pc-mk-field pc-mk-stages" data-show="mode=stages"><div class="pc-mk-label-row"><span class="pc-mk-label">' . esc_html__( 'Stages', 'peptide-core' ) . '</span> <span class="pc-pl-muted">(' . esc_html( Marketing::tz_label() ) . ')</span></div><div class="pc-mk-stage-list">';
		foreach ( $stages as $i => $st ) {
			/* translators: %d: stage number */
			echo '<div class="pc-mk-stage"><strong class="pc-mk-stage__title">' . esc_html( sprintf( __( 'Stage %d', 'peptide-core' ), $i + 1 ) ) . '</strong><input type="datetime-local" step="1" name="stages[' . (int) $i . '][start]" value="' . esc_attr( Marketing::local_input( (int) $st['start'] ) ) . '" aria-label="' . esc_attr__( 'Start time', 'peptide-core' ) . '"><span>' . esc_html__( 'to', 'peptide-core' ) . '</span><input type="datetime-local" step="1" name="stages[' . (int) $i . '][end]" value="' . esc_attr( Marketing::local_input( (int) $st['end'] ) ) . '" aria-label="' . esc_attr__( 'End time', 'peptide-core' ) . '">';
			echo '<span class="pc-mk-kind-pre"' . $kind_attrs . '>' . esc_html( $pre[ $d['kind'] ] ) . '</span><input type="number" min="0" step="0.01" class="pc-mk-num" name="stages[' . (int) $i . '][value]" value="' . esc_attr( (string) $st['value'] ) . '" placeholder="' . esc_attr__( 'Enter', 'peptide-core' ) . '"><span class="pc-mk-kind-unit"' . $kind_attrs . '>' . esc_html( $unit[ $d['kind'] ] ) . '</span>';
			echo '<button type="button" class="button-link pc-mk-stage-del"' . ( 0 === $i ? ' hidden' : '' ) . '>' . esc_html__( 'Delete', 'peptide-core' ) . '</button></div>';
		}
		/* translators: %d: maximum number of stages */
		echo '</div><p><button type="button" class="button pc-mk-stage-add" data-max="' . (int) Discounts::MAX_STAGES . '">+ ' . esc_html__( 'Add stage', 'peptide-core' ) . '</button> <span class="pc-pl-muted">' . esc_html( sprintf( __( 'Up to %d stages. Stages must not overlap; between stages there is no discount.', 'peptide-core' ), Discounts::MAX_STAGES ) ) . '</span></p></div>';
		Marketing::card_close();
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		Marketing::customers_card( (string) $d['customers'], (array) $d['emails'], true );
		Marketing::save_bar( self::url() );
		echo '</form></div>';
	}

	// ---------- 保存 ----------

	/**
	 * 编辑页表单 => Discounts::normalize() 的输入和时间错误（测试里直接调用）。
	 *
	 * @param array<string,mixed> $in
	 * @return array{0:array,1:string[]}
	 */
	public static function from_form( array $in ): array {
		$mode   = 'stages' === ( $in['mode'] ?? '' ) ? 'stages' : 'fixed';
		$errors = array();
		$start  = 0;
		$end    = 0;
		if ( 'fixed' === $mode ) {
			[ $start, $end, $errors ] = Marketing::read_time( $in );
		}
		$stages = array();
		foreach ( is_array( $in['stages'] ?? null ) ? $in['stages'] : array() as $st ) {
			if ( is_array( $st ) ) {
				$stages[] = array(
					'start' => Marketing::from_local( $st['start'] ?? '' ),
					'end'   => Marketing::from_local( $st['end'] ?? '' ),
					'value' => $st['value'] ?? '',
				);
			}
		}
		return array(
			array(
				'id'          => absint( $in['id'] ?? 0 ),
				'name'        => (string) ( $in['name'] ?? '' ),
				'mode'        => $mode,
				'member_day'  => ! empty( $in['member_day'] ),
				'member_days' => (array) ( $in['member_days'] ?? array() ),
				'start'       => $start,
				'end'         => $end,
				'kind'        => (string) ( $in['kind'] ?? '' ),
				'value'       => $in['value'] ?? '',
				'stages'      => $stages,
				'customers'   => (string) ( $in['customers'] ?? '' ),
				'emails'      => (string) ( $in['emails'] ?? '' ),
			),
			$errors,
		);
	}

	public static function save(): void {
		Marketing::guard( 'pc_shipdisc_save' );
		$in                 = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 在 Discounts::normalize() 里逐项清理。
		[ $raw, $errors ]   = self::from_form( $in );
		$old                = $raw['id'] ? Discounts::get( $raw['id'] ) : null;
		$raw['enabled']     = $old ? $old['enabled'] : true;
		[ $d, $more ]       = Discounts::normalize( $raw );
		$errors             = array_merge( $errors, $more );
		if ( $errors ) {
			// 阶段按填写的原样保留。
			$d['stages'] = array_map( static fn( $st ) => array( 'start' => (int) $st['start'], 'end' => (int) $st['end'], 'value' => (string) $st['value'] ), $raw['stages'] );
			Marketing::keep_form( 'shipdisc', $d + array( 'time_mode' => (string) ( $in['time_mode'] ?? '' ) ), $errors );
			wp_safe_redirect( self::url( $raw['id'] ? array( 'edit' => $raw['id'] ) : array( 'new' => 1 ) ) );
			exit;
		}
		Discounts::save( $d );
		wp_safe_redirect( self::url( array( 'pc_saved' => 1 ) ) );
		exit;
	}

	public static function toggle(): void {
		Marketing::guard( 'pc_shipdisc_toggle' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		Discounts::set_enabled( absint( $_GET['id'] ?? 0 ), ! empty( $_GET['on'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( self::url( array( 'pc_done' => 1 ) ) );
		exit;
	}

	public static function bulk(): void {
		Marketing::guard( 'pc_shipdisc_bulk' );
		$op   = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) );
		$done = 0;
		foreach ( Marketing::posted_ids() as $id ) {
			if ( ! Discounts::get( $id ) ) {
				continue;
			}
			if ( 'delete' === $op ) {
				Discounts::delete( $id );
			} else {
				Discounts::set_enabled( $id, 'enable' === $op );
			}
			++$done;
		}
		wp_safe_redirect( self::url( array( 'pc_done' => $done ) ) );
		exit;
	}
}
