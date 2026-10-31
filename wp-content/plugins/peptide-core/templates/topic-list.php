<?php
/** Real public pc_topic archive, using the narrowed native query; no synthetic cards or page creation. */
defined( 'ABSPATH' ) || exit;
use PeptideCore\Modules\Content_Blocks;
if ( ! Content_Blocks::blog_allowed( 'public' ) ) { return; }
Content_Blocks::blog_assets();
global $wp_query;
$page = max( 1, min( 1000, absint( get_query_var( 'paged', 1 ) ) ) );
$rows = array();
foreach ( (array) $wp_query->posts as $post ) {
	if ( $post instanceof WP_Post && 'pc_topic' === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password && is_post_publicly_viewable( $post ) ) { $rows[] = array( 'id' => 'topic-' . $post->ID, 'enabled' => true, 'settings' => array( 'entry' => $post->ID ) ); }
}
if ( $page > 1 && ! $rows ) { status_header( 404 ); $wp_query->is_404 = true; $wp_query->is_archive = false; }
get_header();
?>
<main id="main-content" class="pc-blog-index standard-page">
	<h1 style="text-align:center"><?php esc_html_e( 'Topics', 'peptide-core' ); ?></h1>
	<?php echo Content_Blocks::reference( 'campaign_pages', array( 'blogEntries' => $rows, 'title' => '', 'description' => '', 'descriptionHtml' => '', 'blogShowCover' => true, 'blogShowExcerpt' => true, 'blogColumns' => 3, 'blogAlign' => 'center', 'blogReadMore' => __( 'Read More >', 'peptide-core' ), 'blogViewMore' => '' ), 'public' ); ?>
	<?php if ( ! $rows ) : ?><p style="text-align:center"><?php esc_html_e( 'No public topics yet.', 'peptide-core' ); ?></p><?php endif; ?>
	<nav class="pc-blog-index__pagination" aria-label="<?php esc_attr_e( 'Topic pages', 'peptide-core' ); ?>">
		<?php if ( $page > 1 ) : ?><a rel="prev" href="<?php echo esc_url( get_pagenum_link( $page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'peptide-core' ); ?></a><?php endif; ?>
		<span><?php echo esc_html( sprintf( __( 'Page %d', 'peptide-core' ), $page ) ); ?></span>
		<?php if ( (int) $wp_query->max_num_pages > $page ) : ?><a rel="next" href="<?php echo esc_url( get_pagenum_link( $page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'peptide-core' ); ?></a><?php endif; ?>
	</nav>
</main>
<?php get_footer(); ?>
