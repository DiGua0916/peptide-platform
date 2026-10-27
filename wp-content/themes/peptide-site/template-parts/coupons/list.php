<?php
/** Public coupon list supplied by PeptideCore\Coupons. Presentation only. */
defined( 'ABSPATH' ) || exit;

$vc_coupons = isset( $args['coupons'] ) && is_array( $args['coupons'] ) ? $args['coupons'] : array();
$vc_shop    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
?>
<div class="vc-coupons" data-vc-coupons>
	<?php if ( ! $vc_coupons ) : ?>
		<div class="vc-coupons-empty">
			<h2><?php esc_html_e( 'No offers available right now.', 'peptide-site' ); ?></h2>
			<p><?php esc_html_e( 'New public coupon codes will appear here when available.', 'peptide-site' ); ?></p>
			<?php if ( $vc_shop ) : ?><a class="vc-coupon-link" href="<?php echo esc_url( $vc_shop ); ?>"><?php esc_html_e( 'Browse products', 'peptide-site' ); ?></a><?php endif; ?>
		</div>
	<?php else : ?>
		<p class="vc-coupons-intro"><?php esc_html_e( 'Choose an offer, check its conditions and use the code in your cart.', 'peptide-site' ); ?></p>
		<div class="vc-coupons-list">
			<?php foreach ( $vc_coupons as $vc_coupon ) :
				$vc_title     = (string) ( $vc_coupon['title'] ?? $vc_coupon['value'] ?? '' );
				$vc_value     = (string) ( $vc_coupon['value'] ?? '' );
				$vc_code      = (string) ( $vc_coupon['code'] ?? '' );
				$vc_status_id = wp_unique_id( 'vc-coupon-status-' );
				$vc_terms     = array();
				if ( ! empty( $vc_coupon['minimum_spend_text'] ) ) {
					$vc_terms[] = sprintf( __( 'Minimum spend %s', 'peptide-site' ), $vc_coupon['minimum_spend_text'] );
				}
				if ( ! empty( $vc_coupon['maximum_spend_text'] ) ) {
					$vc_terms[] = sprintf( __( 'Maximum spend %s', 'peptide-site' ), $vc_coupon['maximum_spend_text'] );
				}
				if ( ! empty( $vc_coupon['free_shipping'] ) ) {
					$vc_terms[] = __( 'Free shipping coupon; shipping conditions apply.', 'peptide-site' );
				}
				if ( ! empty( $vc_coupon['individual_use'] ) ) {
					$vc_terms[] = __( 'Cannot be combined with other coupons.', 'peptide-site' );
				}
				if ( ! empty( $vc_coupon['exclude_sale_items'] ) ) {
					$vc_terms[] = __( 'Not valid on sale items.', 'peptide-site' );
				}
				?>
				<article class="vc-coupon-row" data-vc-coupon>
					<div class="vc-coupon-summary">
						<h2><?php echo esc_html( $vc_title ); ?></h2>
						<?php if ( '' !== $vc_value && $vc_value !== $vc_title ) : ?><p class="vc-coupon-value"><?php echo esc_html( $vc_value ); ?></p><?php endif; ?>
						<?php if ( ! empty( $vc_coupon['text'] ) ) : ?><p class="vc-coupon-description"><?php echo esc_html( $vc_coupon['text'] ); ?></p><?php endif; ?>
					</div>
					<div class="vc-coupon-conditions">
						<?php if ( ! empty( $vc_coupon['expires_text'] ) ) : ?>
							<p class="vc-coupon-expiry"><?php esc_html_e( 'Valid until', 'peptide-site' ); ?> <time<?php if ( ! empty( $vc_coupon['expires'] ) ) : ?> datetime="<?php echo esc_attr( $vc_coupon['expires'] ); ?>"<?php endif; ?>><?php echo esc_html( $vc_coupon['expires_text'] ); ?></time></p>
						<?php endif; ?>
						<?php if ( $vc_terms ) : ?><ul class="vc-coupon-terms"><?php foreach ( $vc_terms as $vc_term ) : ?><li><?php echo esc_html( $vc_term ); ?></li><?php endforeach; ?></ul><?php endif; ?>
						<?php if ( ! empty( $vc_coupon['products'] ) || ! empty( $vc_coupon['categories'] ) || ! empty( $vc_coupon['excluded_products'] ) || ! empty( $vc_coupon['excluded_categories'] ) ) : ?><p class="vc-coupon-eligibility"><?php esc_html_e( 'Product or category restrictions apply. See offer details.', 'peptide-site' ); ?></p><?php endif; ?>
					</div>
					<div class="vc-coupon-redeem">
						<span class="vc-coupon-code-label"><?php esc_html_e( 'Coupon code', 'peptide-site' ); ?></span>
						<code class="vc-coupon-code" translate="no"><?php echo esc_html( $vc_code ); ?></code>
						<div class="vc-coupon-actions">
							<button type="button" class="vc-coupon-button" data-pc-copy="<?php echo esc_attr( $vc_code ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'peptide-site' ); ?>" aria-describedby="<?php echo esc_attr( $vc_status_id ); ?>"><?php esc_html_e( 'Copy code', 'peptide-site' ); ?></button>
							<?php if ( ! empty( $vc_coupon['url'] ) ) : ?><a class="vc-coupon-link" href="<?php echo esc_url( $vc_coupon['url'] ); ?>"><?php esc_html_e( 'Offer details', 'peptide-site' ); ?></a><?php endif; ?>
						</div>
						<p class="vc-coupon-status" id="<?php echo esc_attr( $vc_status_id ); ?>" data-vc-copy-status role="status" aria-live="polite" aria-atomic="true"></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
