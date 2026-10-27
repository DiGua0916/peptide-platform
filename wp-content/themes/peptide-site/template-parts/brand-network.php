<?php
defined( 'ABSPATH' ) || exit;
$vc_image_root = get_template_directory_uri() . '/assets/images/';
$vc_story_rows = is_front_page();
$vc_story_sizes = $vc_story_rows
    ? '(max-width: 600px) calc(100vw - 40px), (max-width: 760px) calc(100vw - 48px), (max-width: 950px) calc(47.25vw - 24px), (max-width: 1150px) calc(47.25vw - 32px), (max-width: 1432px) calc(47.25vw - 56px), 620px'
    : '(max-width: 600px) calc(100vw - 40px), (max-width: 1440px) 30vw, 414px';
?>
<section class="section container brand-network" id="our-story">
    <div class="section-heading">
        <div><span class="eyebrow">THE VIALCOVE STORY</span><h2>Three places.<br><em>One connected vision.</em></h2></div>
        <p class="section-intro">Research in Israel. Manufacturing in China. Warehousing in the U.S. Three distinct roles at the heart of our brand vision.</p>
    </div>
    <div class="network-grid network-grid--photographic<?php echo $vc_story_rows ? ' network-grid--rows' : ''; ?>">
    <?php
    $vc_network = array(
        array( 'country' => '01 / ISRAEL', 'title' => 'Scientific collaboration.', 'role' => 'RESEARCH & LABORATORY TEAM', 'copy' => 'An Israeli laboratory team forms the research side of the VialCove vision, with a focus on scientific collaboration and clear documentation.', 'image' => 'israel-research-concept', 'height' => 800, 'alt' => 'AI-generated concept of three researchers reviewing a computer in a laboratory.' ),
        array( 'country' => '02 / CHINA', 'title' => 'At the source.', 'role' => 'MANUFACTURING & QUALITY', 'copy' => 'Direct collaboration with Chinese manufacturers is the production side of our plan. Process records and batch documentation will support the details.', 'image' => 'china-manufacturing-concept', 'height' => 801, 'alt' => 'AI-generated concept of technicians working beside enclosed manufacturing equipment.' ),
        array( 'country' => '03 / UNITED STATES', 'title' => 'Closer to your doorstep.', 'role' => 'WAREHOUSING & FULFILLMENT', 'copy' => 'U.S. warehousing is the local fulfillment side of our plan. Service areas, dispatch times and tracking details will be published once confirmed.', 'image' => 'us-fulfillment-concept', 'height' => 900, 'alt' => 'AI-generated concept of a worker preparing a parcel in a compact warehouse.' ),
    );
    foreach ( $vc_network as $vc_place ) :
    ?>
        <article>
            <?php if ( ! $vc_story_rows ) : ?>
            <span class="network-number"><?php echo esc_html( $vc_place['country'] ); ?></span>
            <?php endif; ?>
            <figure class="network-image">
                <img src="<?php echo esc_url( $vc_image_root . $vc_place['image'] . '-1200.webp' ); ?>" srcset="<?php echo esc_url( $vc_image_root . $vc_place['image'] . '-600.webp' ); ?> 600w, <?php echo esc_url( $vc_image_root . $vc_place['image'] . '-1200.webp' ); ?> 1200w" sizes="<?php echo esc_attr( $vc_story_sizes ); ?>" width="1200" height="<?php echo (int) $vc_place['height']; ?>" loading="lazy" decoding="async" alt="<?php echo esc_attr( $vc_place['alt'] ); ?>">
                <figcaption>AI-generated scene · Brand concept</figcaption>
            </figure>
            <?php if ( $vc_story_rows ) : ?>
            <div class="network-copy">
            <span class="network-number"><?php echo esc_html( $vc_place['country'] ); ?></span>
            <?php endif; ?>
            <span class="network-role"><?php echo esc_html( $vc_place['role'] ); ?></span>
            <h3><?php echo esc_html( $vc_place['title'] ); ?></h3>
            <p><?php echo esc_html( $vc_place['copy'] ); ?></p>
            <?php if ( $vc_story_rows ) : ?></div><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <p class="story-note">These AI-generated scenes illustrate the founder’s brand vision; they are not photographs of verified teams or facilities. Partner identities, credentials and fulfillment details are awaiting supporting documentation.</p>
</section>
