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
        return array( 'id' => 'campaign-bubble', 'type' => 'promotion_card', 'settings' => array_merge( array( 'presentation' => 'campaign_bubble', 'activityType' => 'promotion', 'promotion' => 10980, 'title' => '', 'discountTemplateHtml' => '' ), $settings ) );
    }
    function reset_campaign( array $change = array(), $native = null ): void {
        $GLOBALS['campaign_dto'] = array_merge( array( 'visible' => true, 'promotionId' => 10980, 'title' => '黑色星期五', 'status' => 'active' ), $change );
        $GLOBALS['native_campaign_html'] = null === $native ? '<div class="pc-cb"><article class="pc-campaign" data-pc-campaign data-activity-id="10980"><header><h2 class="pc-campaign__title">黑色星期五</h2></header><div class="pc-campaign__value">$25.00 OFF</div><p class="native-terms">Native eligibility and coupon conditions.</p><a href="/offer/10980/">Native link</a></article></div>' : $native;
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

    $free='<article data-pc-campaign ><header><h2>Native title</h2></header><div class="pc-campaign__value">FREE</div></article>';
    reset_campaign(array('title'=>'Native title'),$free);
    $rendered=vc_campaign_bubble_html(null,module(),'public','all');
    check( str_contains($rendered,'>FREE</div>') && !str_contains($rendered,'<small>'), 'FREE and other native reward wording is not converted to an OFF claim.' );
    reset_campaign();
    $rendered=vc_campaign_bubble_html(null,module(array('discountTemplateHtml'=>'<strong>{{discount}} OFF</strong>')),'public','all');
    check( str_contains($rendered,'$25.00 OFF</div>') && !str_contains($rendered,'<small>off</small>'), 'Merchant custom discount template opts out of default suffix transformation.' );
    reset_campaign(array('title'=>'Other actual activity'),'<article data-pc-campaign ><header><h2 class="pc-campaign__title">Other actual activity</h2></header><div>Native amount</div></article>');
    $rendered=vc_campaign_bubble_html(null,module(),'public','all');
    check( str_contains($rendered,'Other actual activity') && !str_contains($rendered,'Black Friday'), 'Other activity titles are not renamed into Black Friday.' );

    $filters=$GLOBALS['hooks']['peptide_core_module_html']??array();
    check( count($filters)===1 && $filters[0]===array('vc_campaign_bubble_html',9,4), 'Bubble filter runs before native priority ten; opt-in handling is one pass.' );
    $schema=$GLOBALS['hooks']['peptide_core_layout_definitions'][0][0];
    $defs=array('promotion_card'=>array('settings'=>array(),'panels'=>array()),'rich_text'=>array('sentinel'=>'unchanged'));
    $extended=$schema($defs);
    check( 'standard' === $extended['promotion_card']['settings'][0]['default'] && isset($extended['promotion_card']['settings'][0]['options']['campaign_bubble']) && $extended['rich_text']===$defs['rich_text'], 'Editable style is opt-in while other definitions remain unchanged.' );
    echo "$passed campaign bubble presentation guards passed\n";
}
