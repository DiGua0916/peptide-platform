<?php
/**
 * 总后台 → 概览：所有店铺加起来的销售额、订单、客单价、访客、转化率（和上一段时间比），
 * 每天销售额的柱状图（附按天的数字表），每个店铺一行的明细（可以一键进店铺后台）。
 * 时间：今天 / 昨天 / 近 7 天 / 近 30 天。数字每 10 分钟自动更新，也可以马上刷新。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Alerts;
use PeptideHq\Sites;
use PeptideHq\Stats;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Page {

	public const SLUG = 'pchq';

	public static function ranges(): array {
		return array(
			'today'     => __( 'Today', 'peptide-hq' ),
			'yesterday' => __( 'Yesterday', 'peptide-hq' ),
			'7d'        => __( 'Last 7 days', 'peptide-hq' ),
			'30d'       => __( 'Last 30 days', 'peptide-hq' ),
		);
	}

	/** 上一段同样长的时间。 */
	private static function previous( string $from, string $to ): array {
		$len = (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		$end = strtotime( $from . ' UTC' ) - DAY_IN_SECONDS;
		return array( gmdate( 'Y-m-d', $end - ( $len - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $end ) );
	}

	private static function totals( array $per_site ): array {
		$t = array(
			'orders'   => 0,
			'visitors' => 0,
			'revenue'  => array(),
		);
		foreach ( $per_site as $s ) {
			$t['orders']   += $s['orders'];
			$t['visitors'] += $s['visitors'];
			$cur             = '' !== $s['currency'] ? $s['currency'] : 'USD';
			$t['revenue'][ $cur ] = ( $t['revenue'][ $cur ] ?? 0 ) + $s['revenue'];
		}
		return $t;
	}

	/** 和上一段时间比，例如“比上一段 +12%”；上一段为 0 时不比。 */
	private static function delta( float $now, float $before ): string {
		if ( $before <= 0 ) {
			return '';
		}
		$pct = ( $now - $before ) / $before * 100;
		/* translators: %s: signed percentage */
		return sprintf( __( '%s vs previous period', 'peptide-hq' ), ( $pct >= 0 ? '↑ +' : '↓ ' ) . number_format_i18n( $pct, 0 ) . '%' );
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range = isset( self::ranges()[ $range ] ) ? $range : 'today';
		[ $from, $to ]   = Stats::range( $range );
		[ $pfrom, $pto ] = self::previous( $from, $to );
		$sites    = Sites::all();
		$per_site = Stats::per_site( $from, $to );
		$now      = self::totals( $per_site );
		$before   = self::totals( Stats::per_site( $pfrom, $pto ) );
		$online   = count( array_filter( $sites, static fn( $s ) => 'online' === $s['status'] ) );
		$alerts   = Alerts::open_count();
		$main_cur = array_key_first( $now['revenue'] ) ?? 'USD';
		$rev_now  = (float) ( $now['revenue'][ $main_cur ] ?? 0 );
		$rev_bef  = (float) ( $before['revenue'][ $main_cur ] ?? 0 );
		$aov      = $now['orders'] ? $rev_now / $now['orders'] : 0;
		$conv     = $now['visitors'] ? $now['orders'] / $now['visitors'] * 100 : 0;
		$checked  = $sites ? max( array_column( $sites, 'last_check' ) ) : 0;
		?>
		<div class="wrap pchq">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Overview', 'peptide-hq' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( Menu::action_url( 'pchq_site_refresh' ) ); ?>"><?php esc_html_e( 'Refresh all stores now', 'peptide-hq' ); ?></a>
			<hr class="wp-header-end">
			<?php Menu::notices(); ?>

			<?php if ( ! $sites ) : ?>
				<div class="pchq-card pchq-empty">
					<h2><?php esc_html_e( 'No store has joined yet', 'peptide-hq' ); ?></h2>
					<p><?php esc_html_e( 'Stores join by themselves once their wp-config.php has the two master admin lines. The lines are on the Stores page.', 'peptide-hq' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Sites_Page::SLUG ) ); ?>"><?php esc_html_e( 'How stores join', 'peptide-hq' ); ?></a></p>
				</div>
			</div>
				<?php
				return;
			endif;
			?>

			<div class="pchq-toolbar">
				<nav class="pchq-ranges" aria-label="<?php esc_attr_e( 'Time range', 'peptide-hq' ); ?>">
					<?php foreach ( self::ranges() as $key => $label ) : ?>
						<a class="<?php echo $range === $key ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $key, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>" <?php echo $range === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<span class="pchq-muted">
					<?php
					/* translators: 1: date range, 2: time of last update */
					echo esc_html( sprintf( __( '%1$s · updated %2$s (every 10 minutes)', 'peptide-hq' ), $from === $to ? $from : $from . ' ~ ' . $to, Menu::ago( $checked ) ) );
					?>
				</span>
			</div>

			<div class="pchq-tiles">
				<div class="pchq-tile pchq-tile--hero">
					<span class="pchq-tile__label"><?php esc_html_e( 'Sales', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( Menu::money( $now['revenue'] ) ); ?></span>
					<span class="pchq-tile__delta"><?php echo esc_html( self::delta( $rev_now, $rev_bef ) ); ?></span>
				</div>
				<div class="pchq-tile">
					<span class="pchq-tile__label"><?php esc_html_e( 'Paid orders', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( number_format_i18n( $now['orders'] ) ); ?></span>
					<span class="pchq-tile__delta"><?php echo esc_html( self::delta( $now['orders'], $before['orders'] ) ); ?></span>
				</div>
				<div class="pchq-tile">
					<span class="pchq-tile__label"><?php esc_html_e( 'Average order value', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( Menu::money( array( $main_cur => $aov ) ) ); ?></span>
				</div>
				<div class="pchq-tile">
					<span class="pchq-tile__label"><?php esc_html_e( 'Visitors', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( number_format_i18n( $now['visitors'] ) ); ?></span>
					<span class="pchq-tile__delta"><?php echo esc_html( self::delta( $now['visitors'], $before['visitors'] ) ); ?></span>
				</div>
				<div class="pchq-tile">
					<span class="pchq-tile__label"><?php esc_html_e( 'Conversion rate', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( number_format_i18n( $conv, 2 ) . '%' ); ?></span>
				</div>
				<div class="pchq-tile">
					<span class="pchq-tile__label"><?php esc_html_e( 'Stores online', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( $online . ' / ' . count( $sites ) ); ?></span>
				</div>
				<a class="pchq-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Alerts_Page::SLUG ) ); ?>">
					<span class="pchq-tile__label"><?php esc_html_e( 'Open alerts', 'peptide-hq' ); ?></span>
					<span class="pchq-tile__value"><?php echo esc_html( number_format_i18n( $alerts ) ); ?></span>
				</a>
			</div>

			<?php self::render_chart( $range, $main_cur ); ?>

			<h2 class="pchq-h2"><?php esc_html_e( 'By store', 'peptide-hq' ); ?></h2>
			<div class="pchq-scroll">
				<table class="widefat striped pchq-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Store', 'peptide-hq' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Sales', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Paid orders', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Average order value', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Visitors', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Conversion rate', 'peptide-hq' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'To ship', 'peptide-hq' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last order', 'peptide-hq' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'peptide-hq' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites as $s ) : ?>
							<?php
							$n   = $per_site[ $s['id'] ] ?? array( 'orders' => 0, 'revenue' => 0.0, 'visitors' => 0, 'currency' => '' );
							$cur = '' !== $n['currency'] ? $n['currency'] : (string) ( $s['info']['currency'] ?? 'USD' );
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>"><strong><?php echo esc_html( $s['name'] ); ?></strong><br><span class="pchq-muted"><?php echo esc_html( (string) wp_parse_url( $s['url'], PHP_URL_HOST ) ); ?></span></td>
								<td data-label="<?php esc_attr_e( 'Status', 'peptide-hq' ); ?>"><?php echo Menu::status_badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'Sales', 'peptide-hq' ); ?>"><?php echo esc_html( Menu::money( array( $cur => $n['revenue'] ) ) ); ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'Paid orders', 'peptide-hq' ); ?>"><?php echo esc_html( number_format_i18n( $n['orders'] ) ); ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'Average order value', 'peptide-hq' ); ?>"><?php echo esc_html( $n['orders'] ? Menu::money( array( $cur => $n['revenue'] / $n['orders'] ) ) : '—' ); ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'Visitors', 'peptide-hq' ); ?>"><?php echo esc_html( number_format_i18n( $n['visitors'] ) ); ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'Conversion rate', 'peptide-hq' ); ?>"><?php echo esc_html( $n['visitors'] ? number_format_i18n( $n['orders'] / $n['visitors'] * 100, 2 ) . '%' : '—' ); ?></td>
								<td class="num" data-label="<?php esc_attr_e( 'To ship', 'peptide-hq' ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $s['info']['processing'] ?? 0 ) ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Last order', 'peptide-hq' ); ?>"><?php echo esc_html( Menu::ago( (int) ( $s['info']['last_order'] ?? 0 ) ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>"><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => $s['id'] ) ) ); ?>"><?php esc_html_e( 'Open store admin', 'peptide-hq' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="pchq-muted"><?php esc_html_e( 'Sales are paid orders minus refunds. Days follow each store’s time zone. Visitors are counted only on stores with visitor tracking turned on.', 'peptide-hq' ); ?></p>
		</div>
		<?php
	}

	/**
	 * 每天销售额（所有店铺加起来）的柱状图。今天 / 昨天时显示最近 7 天。
	 * 一个系列、一种颜色，所以不用图例；每根柱子悬停显示日期、销售额、订单；下面可以展开按天的数字表。
	 */
	private static function render_chart( string $range, string $currency ): void {
		$days = '30d' === $range ? 30 : 7;
		$to   = wp_date( 'Y-m-d' );
		$from = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$data = Stats::per_day( $from, $to, $currency );
		// 没有销售时纵轴也用 0–100 这样的整数刻度。
		$max  = max( 100.0, max( array_column( $data, 'revenue' ) ) );
		// 纵轴取整：1、2、5 × 10 的次方。
		$step = 10 ** floor( log10( $max / 4 ) );
		foreach ( array( 1, 2, 5, 10 ) as $m ) {
			if ( $max / ( $step * $m ) <= 4 ) {
				$step *= $m;
				break;
			}
		}
		$top    = $step * ceil( $max / $step );
		$w      = 720;
		$h      = 220;
		$left   = 56;
		$bottom = 28;
		$plot_w = $w - $left - 8;
		$plot_h = $h - $bottom - 10;
		$band   = $plot_w / count( $data );
		$bar    = min( 24, $band - 2 );
		$compact = static fn( float $v ): string => $v >= 1000 ? number_format_i18n( $v / 1000, $v >= 10000 ? 0 : 1 ) . 'K' : number_format_i18n( $v );
		?>
		<section class="pchq-card pchq-chart" aria-labelledby="pchq-chart-title">
			<h2 id="pchq-chart-title" class="pchq-h2">
				<?php
				/* translators: %d: number of days */
				echo esc_html( sprintf( __( 'Daily sales, all stores (last %d days)', 'peptide-hq' ), $days ) );
				?>
			</h2>
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'Daily sales chart; the table below lists the same numbers.', 'peptide-hq' ); ?>">
				<?php for ( $v = 0; $v <= $top + 0.001; $v += $step ) : ?>
					<?php $y = 10 + $plot_h - ( $v / $top ) * $plot_h; ?>
					<line class="pchq-grid" x1="<?php echo (int) $left; ?>" x2="<?php echo (int) ( $w - 8 ); ?>" y1="<?php echo esc_attr( (string) round( $y, 1 ) ); ?>" y2="<?php echo esc_attr( (string) round( $y, 1 ) ); ?>"></line>
					<text class="pchq-axis" x="<?php echo (int) ( $left - 8 ); ?>" y="<?php echo esc_attr( (string) round( $y + 4, 1 ) ); ?>" text-anchor="end"><?php echo esc_html( $compact( (float) $v ) ); ?></text>
				<?php endfor; ?>
				<?php $i = 0; ?>
				<?php foreach ( $data as $day => $d ) : ?>
					<?php
					$x  = $left + $i * $band + ( $band - $bar ) / 2;
					$bh = $d['revenue'] > 0 ? max( 2, $d['revenue'] / $top * $plot_h ) : 0;
					$y  = 10 + $plot_h - $bh;
					$r  = min( 4, $bh / 2 );
					/* translators: 1: date, 2: sales, 3: orders */
					$tip = sprintf( __( '%1$s: %2$s, %3$d orders', 'peptide-hq' ), $day, Menu::money( array( $currency => $d['revenue'] ) ), $d['orders'] );
					?>
					<g class="pchq-bar">
						<title><?php echo esc_html( $tip ); ?></title>
						<rect class="pchq-hit" x="<?php echo esc_attr( (string) round( $left + $i * $band, 1 ) ); ?>" y="10" width="<?php echo esc_attr( (string) round( $band, 1 ) ); ?>" height="<?php echo (int) $plot_h; ?>"></rect>
						<?php if ( $bh > 0 ) : ?>
							<path class="pchq-col" d="<?php echo esc_attr( sprintf( 'M%1$.1f %2$.1f V%3$.1f Q%1$.1f %4$.1f %5$.1f %4$.1f H%6$.1f Q%7$.1f %4$.1f %7$.1f %3$.1f V%2$.1f Z', $x, 10 + $plot_h, $y + $r, $y, $x + $r, $x + $bar - $r, $x + $bar ) ); ?>"></path>
						<?php endif; ?>
					</g>
					<?php if ( 7 === $days || 0 === $i % 5 || $i === count( $data ) - 1 ) : ?>
						<text class="pchq-axis" x="<?php echo esc_attr( (string) round( $left + $i * $band + $band / 2, 1 ) ); ?>" y="<?php echo (int) ( $h - 8 ); ?>" text-anchor="middle"><?php echo esc_html( substr( $day, 5 ) ); ?></text>
					<?php endif; ?>
					<?php ++$i; ?>
				<?php endforeach; ?>
				<line class="pchq-base" x1="<?php echo (int) $left; ?>" x2="<?php echo (int) ( $w - 8 ); ?>" y1="<?php echo (int) ( 10 + $plot_h ); ?>" y2="<?php echo (int) ( 10 + $plot_h ); ?>"></line>
			</svg>
			<details class="pchq-table-view">
				<summary><?php esc_html_e( 'Show the numbers by day', 'peptide-hq' ); ?></summary>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'peptide-hq' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sales', 'peptide-hq' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Paid orders', 'peptide-hq' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_reverse( $data, true ) as $day => $d ) : ?>
							<tr><td><?php echo esc_html( $day ); ?></td><td class="num"><?php echo esc_html( Menu::money( array( $currency => $d['revenue'] ) ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $d['orders'] ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</section>
		<?php
	}
}
