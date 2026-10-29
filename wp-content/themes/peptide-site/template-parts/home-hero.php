<?php
defined( 'ABSPATH' ) || exit;
$vc_hero_images = get_template_directory_uri() . '/assets/images/';
$vc_slides = array(
    array( 'name' => 'Research', 'eyebrow' => '01 / ISRAEL · OUR BRAND VISION', 'heading' => 'Built on', 'accent' => 'curiosity.', 'copy' => 'The research side of the VialCove vision: an Israeli laboratory team, scientific collaboration and clear documentation.', 'cta' => 'Explore our story', 'image' => 'carousel-israel-research', 'height' => 960, 'alt' => 'AI-generated brand concept of two colleagues discussing laboratory work beside a microscope.', 'caption' => 'Laboratory collaboration', 'footnote' => 'An illustration of our research vision.' ),
    array( 'name' => 'Manufacturing', 'eyebrow' => '02 / CHINA · OUR BRAND VISION', 'heading' => 'From the source,', 'accent' => 'with clarity.', 'copy' => 'Chinese source manufacturing is the production side of our vision. Explore the connection between production processes and batch documentation.', 'cta' => 'Explore our story', 'image' => 'carousel-china-production', 'height' => 810, 'alt' => 'AI-generated brand concept of a technician handling trays of vials in manufacturing equipment.', 'caption' => 'Source manufacturing', 'footnote' => 'A closer look at the manufacturing concept.' ),
    array( 'name' => 'U.S. warehousing', 'eyebrow' => '03 / UNITED STATES · OUR BRAND VISION', 'heading' => 'Closer to', 'accent' => 'your doorstep.', 'copy' => 'U.S. warehousing is central to our local fulfillment plan. Confirmed service areas, shipping times and tracking details will follow.', 'cta' => 'Explore our story', 'image' => 'carousel-us-warehouse', 'height' => 1098, 'alt' => 'AI-generated brand concept of organized storage racks and labeled boxes in a warehouse.', 'caption' => 'U.S. warehousing', 'footnote' => 'A vision for serving customers closer to home.' ),
);
?>
<section class="hero hero--carousel container" aria-label="Discover VialCove" aria-roledescription="carousel" aria-describedby="vialcove-slideshow-help" tabindex="0" data-carousel data-slide-index="0">
    <span id="vialcove-slideshow-help" class="screen-reader-text">Slides change automatically. Focus this slideshow to pause. Use the left and right arrow keys to change slides, or swipe on a touch screen.</span>
    <div class="hero-slides" id="vialcove-hero-slides">
        <article class="hero-slide is-active" role="group" aria-roledescription="slide" aria-label="1 of 4: Collection" data-slide-name="Collection">
            <div class="hero-copy">
                <div class="eyebrow">VialCovePeptides</div>
                <h1>A clearer view<br>of <em>peptides.</em></h1>
                <p>Discover the collection. Explore the details.<br> Follow every batch back to its documentation.</p>
                <div class="hero-actions"><a class="button" href="<?php echo esc_url( vc_url( 'shop' ) ); ?>">Explore peptides <?php echo vc_icon(); ?></a><a class="text-link" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Find a batch report <?php echo vc_icon( 'file' ); ?></a></div>
                <div class="hero-footnote hero-locations" aria-label="Our brand vision"><span><strong>Israel</strong>Research</span><span><strong>China</strong>Manufacturing</span><span><strong>United States</strong>Warehousing</span></div>
            </div>
            <figure class="hero-scene hero-scene--packaging">
                <div class="hero-product-stage">
                <div class="hero-scene-frame"><img class="hero-packaging-image" src="<?php echo esc_url( get_template_directory_uri() . '/assets/brand/packaging-corrected-20261017-1536.webp' ); ?>" srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/brand/packaging-corrected-20261017-768.webp' ); ?> 768w, <?php echo esc_url( get_template_directory_uri() . '/assets/brand/packaging-corrected-20261017-1536.webp' ); ?> 1536w" sizes="(max-width: 760px) calc(100vw - 40px), (max-width: 1440px) 50vw, 700px" width="1536" height="1024" fetchpriority="high" alt="Brand packaging concept: three sealed glass vials containing white powder, with silver caps, white VialCove labels and a matching box."></div>
                <div class="hero-highlights" aria-label="Explore product information">
                    <a class="hero-highlight hero-highlight--batch" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">
                        <span class="hero-highlight-label"><?php echo vc_icon( 'file' ); ?> Batch lookup</span>
                        <strong>Find your batch.</strong>
                        <span class="hero-highlight-action"><span>Search by batch number</span> <?php echo vc_icon(); ?></span>
                    </a>
                    <a class="hero-highlight hero-highlight--testing" href="<?php echo esc_url( vc_url( 'quality-standards' ) ); ?>">
                        <span class="hero-highlight-label">Testing documentation</span>
                        <strong>Method. Result. Source.</strong>
                        <span class="hero-highlight-action"><span>Review quality standards</span> <?php echo vc_icon(); ?></span>
                    </a>
                    <a class="hero-highlight hero-highlight--library" href="<?php echo esc_url( vc_url( 'certificates' ) ); ?>">
                        <span class="hero-highlight-label"><?php echo vc_icon( 'search' ); ?> Report library</span>
                        <strong>Documents, by product.</strong>
                        <span class="hero-highlight-action"><span>Browse the report directory</span> <?php echo vc_icon(); ?></span>
                    </a>
                </div>
                </div>
                <figcaption><strong>The VialCove identity</strong><span>AI-generated packaging concept</span></figcaption>
            </figure>
        </article>
        <?php foreach ( $vc_slides as $vc_index => $vc_slide ) : ?>
        <article class="hero-slide" role="group" aria-roledescription="slide" aria-label="<?php echo esc_attr( ( $vc_index + 2 ) . ' of 4: ' . $vc_slide['name'] ); ?>" aria-hidden="true" inert data-slide-name="<?php echo esc_attr( $vc_slide['name'] ); ?>">
            <div class="hero-copy">
                <div class="eyebrow"><?php echo esc_html( $vc_slide['eyebrow'] ); ?></div>
                <h2 class="hero-slide-title"><?php echo esc_html( $vc_slide['heading'] ); ?><br><em><?php echo esc_html( $vc_slide['accent'] ); ?></em></h2>
                <p class="hero-slide-description"><?php echo esc_html( $vc_slide['copy'] ); ?></p>
                <div class="hero-actions"><a class="button" href="#our-story"><?php echo esc_html( $vc_slide['cta'] ); ?> <?php echo vc_icon(); ?></a><a class="text-link" href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Batch documentation <?php echo vc_icon( 'file' ); ?></a></div>
                <div class="hero-footnote"><span class="tiny-rule"></span><?php echo esc_html( $vc_slide['footnote'] ); ?></div>
            </div>
            <figure class="hero-scene">
                <div class="hero-scene-frame"><img src="<?php echo esc_url( $vc_hero_images . $vc_slide['image'] . '-1440.webp' ); ?>" srcset="<?php echo esc_url( $vc_hero_images . $vc_slide['image'] . '-768.webp' ); ?> 768w, <?php echo esc_url( $vc_hero_images . $vc_slide['image'] . '-1440.webp' ); ?> <?php echo 'carousel-us-warehouse' === $vc_slide['image'] ? '1433w' : '1440w'; ?>" sizes="(max-width: 600px) calc(100vw - 40px), (max-width: 1440px) 44vw, 634px" width="<?php echo 'carousel-us-warehouse' === $vc_slide['image'] ? '1433' : '1440'; ?>" height="<?php echo (int) $vc_slide['height']; ?>" decoding="async" alt="<?php echo esc_attr( $vc_slide['alt'] ); ?>"><span class="hero-scene-number">0<?php echo (int) ( $vc_index + 2 ); ?> / 04</span></div>
                <figcaption><strong><?php echo esc_html( $vc_slide['caption'] ); ?></strong><span>AI-generated scene · Brand concept</span></figcaption>
            </figure>
        </article>
        <?php endforeach; ?>
    </div>
    <span class="hero-carousel-status screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></span>
</section>
