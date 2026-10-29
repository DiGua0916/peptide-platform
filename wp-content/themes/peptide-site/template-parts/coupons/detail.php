<?php
/** Public coupon detail supplied by PeptideCore\Coupons. No coupon mutations. */
defined( 'ABSPATH' ) || exit;

$vc_coupon   = isset( $args['coupon'] ) && is_array( $args['coupon'] ) ? $args['coupon'] : null;
$vc_list_url = (string) ( $args['list_url'] ?? '' );
?>
<div class="vc-coupons" data-vc-coupons>
	<?php if ( ! $vc_coupon ) : ?>
		<div class="vc-coupons-empty">
			<h2><?php esc_html_e( 'This offer is unavailable.', 'peptide-site' ); ?></h2>
			<p><?php esc_html_e( 'The code may have ended or may no longer be listed publicly.', 'peptide-site' ); ?></p>
			<?php if ( '' !== $vc_list_url ) : ?><a class="vc-coupon-link" href="<?php echo esc_url( $vc_list_url ); ?>"><?php esc_html_e( 'See current offers', 'peptide-site' ); ?></a><?php endif; ?>
		</div>
	<?php else :
		$vc_title     = (string) ( $vc_coupon['title'] ?? $vc_coupon['value'] ?? '' );
		$vc_value     = (string) ( $vc_coupon['value'] ?? '' );
		$vc_code      = (string) ( $vc_coupon['code'] ?? '' );
		$vc_status_id = wp_unique_id( 'vc-coupon-status-' );
		$vc_rules     = array();
		if ( ! empty( $vc_coupon['free_shipping'] ) ) {
			$vc_rules[] = __( 'Free shipping is included where the shipping method accepts this coupon.', 'peptide-site' );
		}
		if ( null !== ( $vc_coupon['uses_left'] ?? null ) ) {
			$vc_uses    = (int) $vc_coupon['uses_left'];
			$vc_rules[] = sprintf( _n( '%d use remaining.', '%d uses remaining.', $vc_uses, 'peptide-site' ), $vc_uses );
		}
		if ( ! empty( $vc_coupon['limit_per_customer'] ) ) {
			$vc_limit   = (int) $vc_coupon['limit_per_customer'];
			$vc_rules[] = sprintf( _n( 'Can be used %d time per customer.', 'Can be used %d times per customer.', $vc_limit, 'peptide-site' ), $vc_limit );
		}
		if ( ! empty( $vc_coupon['individual_use'] ) ) {
			$vc_rules[] = __( 'Cannot be combined with other coupons.', 'peptide-site' );
		}
		if ( ! empty( $vc_coupon['exclude_sale_items'] ) ) {
			$vc_rules[] = __( 'Not valid on sale items.', 'peptide-site' );
		}
		?>
		<?php if ( '' !== $vc_list_url ) : ?><p class="vc-coupon-back"><a class="vc-coupon-link" href="<?php echo esc_url( $vc_list_url ); ?>"><?php esc_html_e( 'All offers', 'peptide-site' ); ?></a></p><?php endif; ?>
		<article class="vc-coupon-detail" data-vc-coupon>
			<div class="vc-coupon-detail-main">
				<header class="vc-coupon-summary">
					<h2><?php echo esc_html( $vc_title ); ?></h2>
					<?php if ( '' !== $vc_value && $vc_value !== $vc_title ) : ?><p class="vc-coupon-value"><?php echo esc_html( $vc_value ); ?></p><?php endif; ?>
					<?php if ( ! empty( $vc_coupon['text'] ) ) : ?><p class="vc-coupon-description"><?php echo esc_html( $vc_coupon['text'] ); ?></p><?php endif; ?>
				</header>
				<section class="vc-coupon-detail-terms">
					<h3><?php esc_html_e( 'Offer conditions', 'peptide-site' ); ?></h3>
					<dl class="vc-coupon-facts">
						<div><dt><?php esc_html_e( 'Minimum spend', 'peptide-site' ); ?></dt><dd><?php echo esc_html( ! empty( $vc_coupon['minimum_spend_text'] ) ? $vc_coupon['minimum_spend_text'] : __( 'No minimum spend', 'peptide-site' ) ); ?></dd></div>
						<?php if ( ! empty( $vc_coupon['maximum_spend_text'] ) ) : ?><div><dt><?php esc_html_e( 'Maximum spend', 'peptide-site' ); ?></dt><dd><?php echo esc_html( $vc_coupon['maximum_spend_text'] ); ?></dd></div><?php endif; ?>
						<div><dt><?php esc_html_e( 'Valid until', 'peptide-site' ); ?></dt><dd><?php if ( ! empty( $vc_coupon['expires_text'] ) ) : ?><time<?php if ( ! empty( $vc_coupon['expires'] ) ) : ?> datetime="<?php echo esc_attr( $vc_coupon['expires'] ); ?>"<?php endif; ?>><?php echo esc_html( $vc_coupon['expires_text'] ); ?></time><?php else : ?><?php esc_html_e( 'No expiry date set', 'peptide-site' ); ?><?php endif; ?></dd></div>
					</dl>
					<?php if ( $vc_rules ) : ?><ul class="vc-coupon-terms vc-coupon-terms--detail"><?php foreach ( $vc_rules as $vc_rule ) : ?><li><?php echo esc_html( $vc_rule ); ?></li><?php endforeach; ?></ul><?php endif; ?>
					<?php
					$vc_groups = array(
						'products'            => __( 'Eligible products', 'peptide-site' ),
						'categories'          => __( 'Eligible categories', 'peptide-site' ),
						'excluded_products'   => __( 'Excluded products', 'peptide-site' ),
						'excluded_categories' => __( 'Excluded categories', 'peptide-site' ),
					);
					foreach ( $vc_groups as $vc_key => $vc_label ) :
						if ( empty( $vc_coupon[ $vc_key ] ) || ! is_array( $vc_coupon[ $vc_key ] ) ) {
							continue;
						}
						?>
						<div class="vc-coupon-product-group">
							<h4><?php echo esc_html( $vc_label ); ?></h4>
							<ul><?php foreach ( $vc_coupon[ $vc_key ] as $vc_item ) : ?><li><?php if ( ! empty( $vc_item['url'] ) ) : ?><a class="vc-coupon-link" href="<?php echo esc_url( $vc_item['url'] ); ?>"><?php echo esc_html( $vc_item['name'] ?? '' ); ?></a><?php else : ?><?php echo esc_html( $vc_item['name'] ?? '' ); ?><?php endif; ?></li><?php endforeach; ?></ul>
						</div>
					<?php endforeach; ?>
				</section>
			</div>
			<aside class="vc-coupon-detail-redeem">
				<span class="vc-coupon-code-label"><?php esc_html_e( 'Coupon code', 'peptide-site' ); ?></span>
				<code class="vc-coupon-code" translate="no"><?php echo esc_html( $vc_code ); ?></code>
				<div class="vc-coupon-actions">
					<button type="button" class="vc-coupon-button vc-coupon-button--secondary" data-pc-copy="<?php echo esc_attr( $vc_code ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'peptide-site' ); ?>" aria-describedby="<?php echo esc_attr( $vc_status_id ); ?>"><?php esc_html_e( 'Copy code', 'peptide-site' ); ?></button>
					<?php if ( ! empty( $vc_coupon['apply_url'] ) ) : ?><a class="vc-coupon-button" href="<?php echo esc_url( $vc_coupon['apply_url'] ); ?>"><?php esc_html_e( 'Apply to my cart', 'peptide-site' ); ?></a><?php endif; ?>
				</div>
				<p class="vc-coupon-status" id="<?php echo esc_attr( $vc_status_id ); ?>" data-vc-copy-status role="status" aria-live="polite" aria-atomic="true"></p>
				<p class="vc-coupon-apply-note"><?php esc_html_e( 'The code applies when your cart meets the offer conditions. You can also enter it in your cart or at checkout.', 'peptide-site' ); ?></p>
			</aside>
		</article>
	<?php endif; ?>
</div>
