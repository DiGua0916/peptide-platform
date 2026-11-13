<?php
/** Isolated presentation contract. No WordPress database, activity or cart writes. */
namespace PeptideCore\Modules {
    final class Fields {
        public static function f( $type, $key, $label, $default = null, $extra = array() ): array {
            return array_merge( array( 'type' => $type, 'key' => $key, 'label' => $label, 'default' => $default ), $extra );
        }
    }
    final class Commerce_Blocks {
        public static int $calls = 0;
        public static array $arguments = array();
        public static function render( $html, array $module, string $context, string $device ) {
            ++self::$calls;
            self::$arguments = array( $html, $module, $context, $device );
            return $GLOBALS['native_campaign_html'] ?? null;
        }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $hooks = array();
    function add_filter( $hook, $callback, $priority = 10, $argc = 1 ) { $GLOBALS['hooks'][$hook][] = array( $callback, $priority, $argc ); }
    function add_action( ...$unused ) {}
    function is_front_page() { return $GLOBALS['home'] ?? true; }
    // Mainline 2026-11-12: the strip's motion script is enqueued where the strip is rendered, and only
    // when the native render has just registered its dependency (it used to be enqueued on every page).
    $enqueued = array();
    function is_admin() { return $GLOBALS['admin_request'] ?? false; }
    function wp_script_is( $handle, $status = 'enqueued' ) { return 'registered' === $status && in_array( $handle, $GLOBALS['registered_scripts'] ?? array( 'pc-commerce-blocks' ), true ); }
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['enqueued'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'footer' => $footer ); }
    function get_template_directory() { return dirname( __DIR__ ); }
    function get_template_directory_uri() { return 'https://store.test/wp-content/themes/peptide-site'; }
    function peptide_core_get_campaign_announcement( array $settings, bool $home ): array {
        $GLOBALS['helper_arguments'] = array( $settings, $home );
        return $GLOBALS['campaign_dto'];
    }
    require dirname(__DIR__) . '/inc/campaign-bubble.php';
    $passed = 0;
    function check( bool $condition, string $message ): void {
        if ( ! $condition ) { throw new \RuntimeException( $message ); }
        ++$GLOBALS['passed'];
    }
    function module( array $settings = array() ): array {
        return array( 'id' => 'campaign-bubble', 'type' => 'promotion_card', 'settings' => array_merge( array( 'presentation' => 'campaign_bubble', 'activityType' => 'promotion', 'promotion' => 10980, 'title' => '', 'conditionTemplateHtml' => '', 'discountTemplateHtml' => '' ), $settings ) );
    }
    function reset_campaign( array $change = array(), $native = null ): void {
        $GLOBALS['campaign_dto'] = array_merge( array( 'visible' => true, 'promotionId' => 10980, 'title' => '黑色星期五', 'status' => 'active' ), $change );
        $GLOBALS['native_campaign_html'] = null === $native ? '<div class="pc-cb"><article class="pc-campaign" data-pc-campaign data-activity-id="10980"><header><h2 class="pc-campaign__title">黑色星期五</h2></header><div class="pc-campaign__tier"><div class="pc-campaign__condition">Spend $300.00, get</div><div class="pc-campaign__discount">$25.00 OFF</div></div><p class="native-terms">Native eligibility and coupon conditions.</p><a href="/offer/10980/">Native link</a></article></div>' : $native;
        \PeptideCore\Modules\Commerce_Blocks::$calls = 0;
        \PeptideCore\Modules\Commerce_Blocks::$arguments = array();
    }
    reset_campaign();
    $original = '<div>Original standard renderer output</div>';
    check( $original === vc_campaign_bubble_html( $original, module( array( 'presentation' => 'standard' ) ), 'public', 'all' ), 'Standard presentation remains unchanged.' );
    $unrelated = module(); $unrelated['type'] = 'rich_text';
    check( $original === vc_campaign_bubble_html( $original, $unrelated, 'public', 'all' ), 'Unrelated module output remains unchanged.' );
    check( 0 === \PeptideCore\Modules\Commerce_Blocks::$calls, 'Unrelated branches never call the native campaign renderer.' );

    $input = module();
    $rendered = vc_campaign_bubble_html( null, $input, 'public', 'all' );
    check( 1 === \PeptideCore\Modules\Commerce_Blocks::$calls, 'Opt-in presentation renders the native campaign exactly once.' );
    check( array( null, $input, 'public', 'all' ) === \PeptideCore\Modules\Commerce_Blocks::$arguments, 'Native renderer receives unchanged module/context/device without filter recursion.' );
    check( true === $GLOBALS['helper_arguments'][0]['announcement'] && 'promotion' === $GLOBALS['helper_arguments'][0]['announcementSource'] && 10980 === $GLOBALS['helper_arguments'][0]['announcementPromotion'], 'Public lookup uses the explicitly selected same activity.' );
    check( str_contains( $rendered, 'data-vc-campaign-bubble' ) && str_contains( $rendered, 'data-pc-campaign ' ), 'Bubble wrapper retains native campaign identity.' );
    check( str_contains( $rendered, 'Native eligibility and coupon conditions.' ) && str_contains( $rendered, 'href="/offer/10980/"' ), 'Native terms and links remain intact.' );
    check( str_contains( $rendered, '<h2 class="pc-campaign__title">Black Friday</h2>' ), 'Existing Chinese title localizes on the English storefront.' );
    check( str_contains( $rendered, '$25.00<small>off</small></div>' ), 'Default OFF suffix becomes a quiet separate line without changing native formatted amount.' );
    check( str_contains( $rendered, '<div class="pc-campaign__condition">Spend $300.00</div>' ), 'Only redundant get wording is removed from the native default spend condition.' );
    check( ! str_contains( $rendered, 'Coming soon' ), 'Active activity does not become upcoming.' );

    foreach ( array( array('visible'=>false), array('status'=>'draft'), array('status'=>'expired'), array('promotionId'=>10981), array('promotionId'=>0) ) as $change ) {
        reset_campaign($change);
        check( '' === vc_campaign_bubble_html( $original, module(), 'public', 'all' ) && 0 === \PeptideCore\Modules\Commerce_Blocks::$calls, 'Unavailable/mismatched binding is hidden before native render.' );
    }
    reset_campaign();
    check( '' === vc_campaign_bubble_html( null, module(array('activityType'=>'product_offer')), 'public', 'all' ) && 0 === \PeptideCore\Modules\Commerce_Blocks::$calls, 'Non-promotion binding does not inherit the marketing treatment.' );
    foreach( array( '', '<div class="pc-campaign__empty">Not eligible for this customer.</div>' ) as $native ) {
        reset_campaign(array(), $native);
        check( '' === vc_campaign_bubble_html( null, module(), 'public', 'all' ) && 1 === \PeptideCore\Modules\Commerce_Blocks::$calls, 'No native eligible campaign means no buyer-facing bubble or editor-empty claim.' );
    }
    reset_campaign(array('status'=>'upcoming'));
    $rendered = vc_campaign_bubble_html(null,module(),'public','mobile');
    check( str_contains($rendered,'Coming soon') && str_contains($rendered,'Native eligibility and coupon conditions.'), 'Upcoming status is explicit and retains native conditions.' );

    $free='<article data-pc-campaign ><header><h2>Native title</h2></header><div class="pc-campaign__discount">FREE</div></article>';
    reset_campaign(array('title'=>'Native title'),$free);
    $rendered=vc_campaign_bubble_html(null,module(),'public','all');
    check( str_contains($rendered,'>FREE</div>') && !str_contains($rendered,'<small>'), 'FREE and other native reward wording is not converted to an OFF claim.' );
    reset_campaign();
    $rendered=vc_campaign_bubble_html(null,module(array('discountTemplateHtml'=>'<strong>{{discount}} OFF</strong>')),'public','all');
    check( str_contains($rendered,'$25.00 OFF</div>') && !str_contains($rendered,'<small>off</small>'), 'Merchant custom discount template opts out of default suffix transformation.' );
    reset_campaign();
    $rendered=vc_campaign_bubble_html(null,module(array('conditionTemplateHtml'=>'Spend {{condition}}, get')),'public','mobile');
    check( str_contains($rendered,'<div class="pc-campaign__condition">Spend $300.00, get</div>'), 'Even a merchant template identical to the default is preserved as authored.' );
    $tiers='<div class="pc-campaign__tier"><div class="pc-campaign__condition">Spend $300.00, get</div><div class="pc-campaign__discount">$25.00 OFF</div></div>'
        . '<div class="pc-campaign__tier"><div class="pc-campaign__condition">Spend $500.00, get</div><div class="pc-campaign__discount">$55.00 OFF</div></div>'
        . '<div class="pc-campaign__tier"><div class="pc-campaign__condition">Spend $1,000.00, get</div><div class="pc-campaign__discount">$120.00 OFF</div></div>';
    reset_campaign(array(),'<article data-pc-campaign ><header><h2>Native title</h2></header>'.$tiers.'<div class="pc-campaign__notes">Spend $1,000.00, get the qualifying native offer.</div></article>');
    $rendered=vc_campaign_bubble_html(null,module(),'public','mobile');
    check( substr_count($rendered,'class="pc-campaign__tier"')===3 && str_contains($rendered,'Spend $1,000.00</div>') && str_contains($rendered,'$120.00<small>off</small>'), 'All three native tiers preserve exact formatted thresholds and discount amounts.' );
    check( str_contains($rendered,'<div class="pc-campaign__notes">Spend $1,000.00, get the qualifying native offer.</div>'), 'Native eligibility and terms are not rewritten as tier text.' );
    foreach(array('Buy 3 items, get','Every $300.00 spent','Any 4 items','Qualifying item 2') as $condition){
        reset_campaign(array(),'<article data-pc-campaign ><header></header><div class="pc-campaign__condition">'.$condition.'</div><div class="pc-campaign__discount">Native reward</div></article>');
        $rendered=vc_campaign_bubble_html(null,module(),'public','mobile');
        check(str_contains($rendered,'<div class="pc-campaign__condition">'.$condition.'</div>'),'Quantity, repeated-spend and bundle conditions retain their native meaning.');
    }
    reset_campaign(array('title'=>'Other actual activity'),'<article data-pc-campaign ><header><h2 class="pc-campaign__title">Other actual activity</h2></header><div>Native amount</div></article>');
    $rendered=vc_campaign_bubble_html(null,module(),'public','all');
    check( str_contains($rendered,'Other actual activity') && !str_contains($rendered,'Black Friday'), 'Other activity titles are not renamed into Black Friday.' );

    $filters=$GLOBALS['hooks']['peptide_core_module_html']??array();
    check( count($filters)===1 && $filters[0]===array('vc_campaign_bubble_html',9,4), 'Bubble filter runs before native priority ten; opt-in handling is one pass.' );
    $schema=$GLOBALS['hooks']['peptide_core_layout_definitions'][0][0];
    $defs=array('promotion_card'=>array('settings'=>array(),'panels'=>array()),'rich_text'=>array('sentinel'=>'unchanged'));
    $extended=$schema($defs);
    check( 'standard' === $extended['promotion_card']['settings'][0]['default'] && isset($extended['promotion_card']['settings'][0]['options']['campaign_bubble']) && $extended['rich_text']===$defs['rich_text'], 'Editable style is opt-in while other definitions remain unchanged.' );
    check( isset($extended['promotion_card']['settings'][0]['options']['campaign_announcement']), 'The small announcement is a separate opt-in without removing the existing bubble.' );

    $announcement=module(array('presentation'=>'campaign_announcement'));
    $rail='<div class="pc-campaign__rail" data-pc-campaign-rail data-mobile-layout="grid"><div class="pc-campaign__tiers" id="native-offers">'.$tiers.'</div><div class="pc-campaign__rail-controls"><button data-pc-campaign-next>Next</button></div></div>';
    $native='<article class="pc-campaign" data-pc-campaign data-activity-id="10980"><header class="pc-campaign__head"><h2 class="pc-campaign__title">黑色星期五</h2><div class="pc-campaign__description"><p>Merchant-authored conditions remain visible.</p></div></header>'.$rail.'<div class="pc-campaign__notes"><p>Native qualifying-customer and coupon terms.</p></div><a href="/offer/10980/">Native offer detail</a></article>';
    reset_campaign(array(),$native);
    $rendered=vc_campaign_bubble_html(null,$announcement,'public','mobile');
    check( str_contains($rendered,'class="vc-campaign-strip" data-vc-campaign-strip') && !str_contains($rendered,'class="vc-campaign-bubble"'), 'Announcement uses its own normal-flow presentation wrapper.' );
    check( 1===\PeptideCore\Modules\Commerce_Blocks::$calls && array(null,$announcement,'public','mobile')===\PeptideCore\Modules\Commerce_Blocks::$arguments, 'Announcement calls the same eligible native renderer once with the original module.' );
    check( str_contains($rendered,'id="native-offers" tabindex="0" aria-label="Promotion offers"') && str_contains($rendered,'data-pc-campaign-next') && str_contains($rendered,'data-mobile-layout="grid"'), 'Native rail identity and controls remain; its scroll surface is keyboard reachable and labelled.' );
    check( substr_count($rendered,'class="pc-campaign__tier"')===3 && str_contains($rendered,'$25.00<small>off</small>') && str_contains($rendered,'Spend $1,000.00</div>'), 'Announcement retains each exact native tier without claiming cumulative rewards.' );
    check( str_contains($rendered,'Merchant-authored conditions remain visible.') && str_contains($rendered,'Native qualifying-customer and coupon terms.') && str_contains($rendered,'href="/offer/10980/"'), 'Announcement keeps the merchant description, native terms and offer link.' );
    reset_campaign(array('status'=>'upcoming'),$native);
    check( str_contains(vc_campaign_bubble_html(null,$announcement,'public','mobile'),'Coming soon'), 'Upcoming announcement never presents the offer as already active.' );
    foreach(array(array('visible'=>false),array('status'=>'expired'),array('promotionId'=>10981)) as $change){
        reset_campaign($change,$native);
        check( ''===vc_campaign_bubble_html(null,$announcement,'public','mobile') && 0===\PeptideCore\Modules\Commerce_Blocks::$calls, 'Disabled, expired or mismatched announcement hides before native rendering.' );
    }
    reset_campaign(array(),str_replace('id="native-offers"','id="native-offers" tabindex="0" aria-label="Existing native label"',$native));
    $rendered=vc_campaign_bubble_html(null,$announcement,'public','mobile');
    check( 1===substr_count($rendered,'tabindex=') && str_contains($rendered,'aria-label="Existing native label"'), 'Existing native accessibility attributes are not duplicated or overwritten.' );
    reset_campaign(array(),$native);
    $custom=$announcement; $custom['settings']['conditionTemplateHtml']='Spend {{condition}}, get'; $custom['settings']['discountTemplateHtml']='{{discount}} OFF';
    $rendered=vc_campaign_bubble_html(null,$custom,'public','mobile');
    check( str_contains($rendered,'Spend $300.00, get</div>') && str_contains($rendered,'$25.00 OFF</div>'), 'Announcement also respects both merchant-authored wording templates.' );
    // The motion script: with a strip and its dependency registered, once, in the footer, after pc-commerce-blocks.
    $GLOBALS['enqueued'] = array(); reset_campaign(array(),$native);
    vc_campaign_bubble_html(null,$announcement,'public','mobile');
    $motion = $GLOBALS['enqueued']['vc-campaign-announcement'] ?? null;
    check( is_array($motion) && array('pc-commerce-blocks')===$motion['deps'] && true===$motion['footer'] && str_ends_with($motion['src'],'/assets/campaign-announcement.js'), 'A rendered strip loads its motion script after the native script it depends on.' );
    $GLOBALS['enqueued'] = array(); $GLOBALS['registered_scripts'] = array(); reset_campaign(array(),$native);
    check( str_contains(vc_campaign_bubble_html(null,$announcement,'public','mobile'),'data-vc-campaign-strip') && array()===$GLOBALS['enqueued'], 'Without the registered dependency the strip still renders and nothing is enqueued (no unmet-dependency notice).' );
    unset($GLOBALS['registered_scripts']);
    $GLOBALS['enqueued'] = array(); $GLOBALS['admin_request'] = true; reset_campaign(array(),$native);
    vc_campaign_bubble_html(null,$announcement,'editor','mobile');
    check( array()===$GLOBALS['enqueued'], 'The editor preview does not load the storefront motion script.' );
    $GLOBALS['admin_request'] = false;
    $GLOBALS['enqueued'] = array(); reset_campaign();
    vc_campaign_bubble_html(null,module(),'public','mobile');
    check( array()===$GLOBALS['enqueued'], 'The bubble presentation and pages without a strip never load it.' );
    $GLOBALS['enqueued'] = array(); reset_campaign(array('visible'=>false),$native);
    vc_campaign_bubble_html(null,$announcement,'public','mobile');
    check( array()===$GLOBALS['enqueued'], 'A hidden strip loads nothing.' );
    echo "$passed campaign bubble presentation guards passed\n";
}
