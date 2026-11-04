<?php
/**
 * Template Name: VialCove delivery details
 *
 * Delivery copy and contact links remain editable in the native page content.
 */
defined( 'ABSPATH' ) || exit;

wp_enqueue_style(
    'vialcove-delivery-details',
    get_template_directory_uri() . '/assets/delivery-details.css',
    array( 'vialcove-product-images' ),
    (string) filemtime( __DIR__ . '/assets/delivery-details.css' )
);

get_header();
?>
<main id="main-content" class="vc-delivery-page">
    <?php while ( have_posts() ) : the_post(); ?>
        <article class="vc-delivery-page__article" aria-labelledby="vc-delivery-title">
            <header class="vc-delivery-page__header">
                <h1 id="vc-delivery-title"><?php echo esc_html( get_the_title() ); ?></h1>
            </header>
            <div class="vc-delivery-page__content entry-content">
                <?php the_content(); ?>
                <?php
                wp_link_pages(
                    array(
                        'before' => '<nav class="vc-delivery-page__pagination" aria-label="' . esc_attr__( 'Page sections', 'peptide-site' ) . '">',
                        'after'  => '</nav>',
                    )
                );
                ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
