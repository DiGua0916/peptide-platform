<?php
/** Dedicated O03 GET results. No article body, shortcode, admin lookup or search-history write. */
defined( 'ABSPATH' ) || exit;
$pc_search = \PeptideCore\Public_Site_Search::page_state();
\PeptideCore\Public_Site_Search::assets();
get_header();
?>
<main id="main-content" class="pc-site-search-page">
	<h1><?php esc_html_e( 'Search results', 'peptide-core' ); ?></h1>
	<?php if ( is_wp_error( $pc_search ) ) : ?>
		<div class="pc-site-search-page__error" role="alert"><p><?php echo esc_html( $pc_search->get_error_message() ); ?></p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'peptide-core' ); ?></a></div>
	<?php else : ?>
		<form class="pc-site-search-page__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="hidden" name="pc_site_search" value="1">
			<input type="hidden" name="pc_site_page" value="1">
			<input type="hidden" name="pc_site_type" value="<?php echo esc_attr( $pc_search['type'] ); ?>">
			<input type="hidden" name="pc_site_scope" value="<?php echo esc_attr( implode( ',', $pc_search['scope'] ) ); ?>">
			<label class="screen-reader-text" for="pc-search-results-query"><?php esc_html_e( 'Search this store', 'peptide-core' ); ?></label>
			<input type="search" name="pc_site_q" id="pc-search-results-query" required maxlength="100" value="<?php echo esc_attr( $pc_search['q'] ); ?>">
			<button type="submit"><?php esc_html_e( 'Search', 'peptide-core' ); ?></button>
		</form>
		<nav class="pc-site-search-page__types" aria-label="<?php esc_attr_e( 'Search type', 'peptide-core' ); ?>">
			<?php $pc_labels = \PeptideCore\Public_Site_Search::labels(); foreach ( $pc_search['scope'] as $pc_type ) : ?>
				<a href="<?php echo esc_url( \PeptideCore\Public_Site_Search::results_url( $pc_search, 1, $pc_type ) ); ?>"<?php echo $pc_type === $pc_search['type'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $pc_labels[ $pc_type ] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( ! $pc_search['results'] ) : ?>
			<p class="pc-site-search-page__empty"><?php esc_html_e( 'No matching results.', 'peptide-core' ); ?></p>
		<?php else : ?>
			<ul class="pc-site-search-page__results">
				<?php foreach ( $pc_search['results'] as $pc_result ) : ?>
					<li><a class="pc-site-search__result" href="<?php echo esc_url( $pc_result['url'] ); ?>">
						<?php if ( ! empty( $pc_result['image']['src'] ) ) : ?><img loading="lazy" src="<?php echo esc_url( $pc_result['image']['src'] ); ?>" alt="<?php echo esc_attr( $pc_result['image']['alt'] ); ?>" width="64" height="64"><?php endif; ?>
						<span class="pc-site-search__result-copy"><span class="pc-site-search__result-title"><?php echo esc_html( $pc_result['title'] ); ?></span>
							<?php if ( ! empty( $pc_result['subtitle'] ) ) : ?><span class="pc-site-search__result-subtitle"><?php echo esc_html( $pc_result['subtitle'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $pc_result['priceHtml'] ) ) : ?><span class="pc-site-search__result-price"><?php echo \PeptideCore\Public_Site_Search::price_html( $pc_result['priceHtml'] ); ?></span><?php endif; ?>
						</span>
					</a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<nav class="pc-site-search-page__pager" aria-label="<?php esc_attr_e( 'Search pages', 'peptide-core' ); ?>">
			<?php if ( $pc_search['page'] > 1 ) : ?><a rel="prev" href="<?php echo esc_url( \PeptideCore\Public_Site_Search::results_url( $pc_search, $pc_search['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'peptide-core' ); ?></a><?php endif; ?>
			<?php if ( $pc_search['nextPage'] ) : ?><a rel="next" href="<?php echo esc_url( \PeptideCore\Public_Site_Search::results_url( $pc_search, $pc_search['nextPage'] ) ); ?>"><?php esc_html_e( 'Next', 'peptide-core' ); ?></a><?php endif; ?>
		</nav>
		<?php if ( $pc_search['hasMore'] && ! $pc_search['nextPage'] ) : ?><p><?php esc_html_e( 'Refine your phrase to see more specific results.', 'peptide-core' ); ?></p><?php endif; ?>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
