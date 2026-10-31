<?php
/** Public real blog-collection index. No demo records, pages or rewrite mutations. */
defined( 'ABSPATH' ) || exit;
use PeptideCore\Blog;
use PeptideCore\Modules\Content_Blocks;
if ( ! Content_Blocks::blog_allowed( 'public' ) ) { return; }
Content_Blocks::blog_assets();
$page = max( 1, min( 1000, absint( get_query_var( 'pc_blog_collections_page', 1 ) ) ) );
$model = Content_Blocks::blog_index( $page );
if ( $page > 1 && ! $model['ids'] ) {
	status_header( 404 ); global $wp_query; if ( $wp_query instanceof \WP_Query ) { $wp_query->is_404 = true; $wp_query->is_archive = false; }
}
$rows = array_map( static fn( $id ) => array( 'id' => 'collection-' . $id, 'enabled' => true, 'settings' => array( 'entry' => $id ) ), $model['ids'] );
get_header();
?>
<main id="main-content" class="pc-blog-index standard-page">
	<h1 style="text-align:center"><?php esc_html_e( 'Blog collections', 'peptide-core' ); ?></h1>
	<?php
	echo Content_Blocks::reference( 'blog_categories', array( 'blogEntries' => $rows, 'title' => '', 'description' => '', 'descriptionHtml' => '', 'blogShowCover' => true, 'blogShowExcerpt' => true, 'blogColumns' => 3, 'blogAlign' => 'center', 'blogReadMore' => __( 'Read More >', 'peptide-core' ), 'blogViewMore' => '' ), 'public' );
	if ( ! $rows ) { echo '<p style="text-align:center">' . esc_html__( 'No public blog collections yet.', 'peptide-core' ) . '</p>'; }
	?>
	<nav class="pc-blog-index__pagination" aria-label="<?php esc_attr_e( 'Blog collection pages', 'peptide-core' ); ?>">
		<?php if ( $page > 1 ) : ?><a rel="prev" href="<?php echo esc_url( Blog::collections_url( $page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'peptide-core' ); ?></a><?php endif; ?>
		<span><?php echo esc_html( sprintf( __( 'Page %d', 'peptide-core' ), $page ) ); ?></span>
		<?php if ( $model['more'] ) : ?><a rel="next" href="<?php echo esc_url( Blog::collections_url( $page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'peptide-core' ); ?></a><?php endif; ?>
	</nav>
</main>
<?php get_footer(); ?>
