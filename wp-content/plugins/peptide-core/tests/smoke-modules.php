<?php
/**
 * 装修模块库冒烟测试（EDIT-BLOCKS-03，开发环境专用）。
 *
 * 运行：wp eval-file wp-content/plugins/peptide-core/tests/smoke-modules.php
 * 只在内存里渲染模块，不写数据库。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

use PeptideCore\Modules\Blocks;
use PeptideCore\Modules\Fallback;
use PeptideCore\Modules\Fields;
use PeptideCore\Modules\Registry;
use PeptideCore\Modules\Renderer;

$ok = static function ( string $label, bool $cond ): void {
	echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n";
};

$errors = array();
set_error_handler(
	static function ( $no, $str, $file, $line ) use ( &$errors ) {
		if ( str_contains( $file, 'peptide-core' ) ) {
			$errors[] = "$str @ " . basename( $file ) . ":$line";
		}
		return false;
	}
);

// 检查插件自带的默认样式时用 $fb（主题已有模板时，Renderer::render 会改用主题模板）。
$fb = static fn( string $type, array $attrs ): string => Fallback::render( Renderer::normalize( $type, $attrs ), 'public' );

$all = Registry::all();
$original_types = array( 'carousel','image','image_row','image_grid','mosaic_3','mosaic_4','mosaic_5','media_text','section_heading','media_text_list','card_list','video','video_text','slogan','counter','gallery','custom_html','timeline','contact','promo_grid','marquee','spacer','rich_text','compare','hotspots','faq','product_showcase','hero_h1','info_list','nav_bar','logo_list','product_collection','report_feature','cta','text_block' );
$ok( 'all original 35 modules remain registered', ! array_diff( $original_types, array_keys( $all ) ) );
$ok( 'product and marketing libraries extend the original modules', isset( $all['product_collection'], $all['product_carousel'], $all['subscribe'], $all['site_styles'] ) && count( array_filter( $all, static fn( $d ) => 'product' === $d['category'] ) ) > 1 && count( array_filter( $all, static fn( $d ) => 'operation' === $d['category'] ) ) > 0 );
$ok( 'no donation module', ! isset( $all['donation'] ) );
$registered = WP_Block_Type_Registry::get_instance();
$ok( 'every module is a registered block', array() === array_filter( $all, static fn( $d ) => ! $registered->is_registered( $d['block'] ) ) );
$ok( 'block names use dashes', 'vialcove/media-text-list' === $all['media_text_list']['block'] && 'media_text_list' === Registry::type_for_block( 'vialcove/media-text-list' ) );
$ok( 'headline block can be used only once', false === $registered->get_registered( 'vialcove/hero-h1' )->supports['multiple'] );

// 每个模块：默认值 + 默认条目，在编辑器和公开两种上下文里渲染。
$bad = array();
foreach ( $all as $type => $def ) {
	$attrs = array(
		'moduleId' => 'test-' . str_replace( '_', '-', $type ),
		'settings' => Blocks::item_defaults( $def['settings'] ),
		'items'    => array(),
	);
	if ( ! empty( $def['items'] ) ) {
		for ( $i = 0; $i < $def['items']['default_count']; $i++ ) {
			$attrs['items'][] = array(
				'id'       => 'item-' . $i . 'xx',
				'settings' => Blocks::item_defaults( $def['items']['fields'] ),
			);
		}
	}
	$editor = Renderer::render( $type, $attrs, 'editor' );
	$public = Renderer::render( $type, $attrs, 'public' );
	if ( ! str_contains( $editor, 'vc-m--' . str_replace( '_', '-', $type ) ) || ! str_contains( $editor, 'vc-m--editor' ) ) {
		$bad[] = $type . ':editor';
	}
	if ( '' !== $public && ! str_contains( $public, 'id="vc-test-' ) ) {
		$bad[] = $type . ':public';
	}
	if ( str_contains( $public, 'vc-ph' ) ) {
		$bad[] = $type . ':placeholder-on-public';
	}
}
$ok( 'all modules render in editor and public contexts', array() === $bad );
if ( $bad ) {
	echo '  ', implode( ', ', $bad ), "\n";
}

// 字段清理。
$attr = array(
	'moduleId' => 'BAD ID!',
	'settings' => array(
		'intervalMs'  => 999999,
		'height'      => 'weird',
		'titleSizePx' => '72',
		'heightPx'    => array(
			'desktop' => 50,
			'mobile'  => null,
		),
		'showMobile'  => false,
	),
	'items'    => array(
		array(
			'id'       => 'slide-one',
			'settings' => array(
				'title'          => '<b>Hi</b> there',
				'overlayColor'   => 'red',
				'overlayOpacity' => 0,
				'button'         => array(
					'label'  => 'Go',
					'href'   => 'javascript:alert(1)',
					'target' => '_top',
				),
				'position'       => array(
					'desktop' => 'bottom-right',
					'mobile'  => 'nowhere',
				),
			),
		),
	),
);
$m = Renderer::normalize( 'carousel', $attr );
$ok( 'invalid module id is replaced', 1 === preg_match( '/^[a-z0-9][a-z0-9-]{3,39}$/', $m['id'] ) && 'BAD ID!' !== $m['id'] );
$ok( 'numbers are clamped', 20000 === $m['settings']['intervalMs'] && 72 === $m['settings']['titleSizePx'] && 200 === $m['settings']['heightPx']['desktop'] );
$ok( 'invalid select falls back to default', 'auto' === $m['settings']['height'] );
$ok( 'mobile null keeps inheriting', null === $m['settings']['heightPx']['mobile'] );
$ok( 'visibility follows settings', true === $m['visibility']['desktop'] && false === $m['visibility']['mobile'] );
$it = $m['items'][0]['settings'];
$ok( 'text is plain', 'Hi there' === $it['title'] );
$ok( 'invalid color falls back', '#000000' === $it['overlayColor'] );
$ok( 'zero is kept (not treated as empty)', 0 === $it['overlayOpacity'] );
$ok( 'javascript: link removed and target limited', '' === $it['button']['href'] && '_self' === $it['button']['target'] );
$ok( 'invalid mobile position falls back to inherit (null)', 'bottom-right' === $it['position']['desktop'] && null === $it['position']['mobile'] );
$def_m = Renderer::normalize( 'carousel', array() );
$ok( 'missing mobile value uses the module default', 520 === $def_m['settings']['heightPx']['mobile'] );
$ok( 'relative links stay relative', '/shop/' === Fields::url( '/shop/' ) && '#faq' === Fields::url( '#FAQ' ) );
$ok( 'unknown keys are dropped', ! array_key_exists( 'evil', Renderer::normalize( 'spacer', array( 'settings' => array( 'evil' => 1 ) ) )['settings'] ) );

$html = Fields::sanitize(
	array(
		'type'    => 'html',
		'key'     => 'x',
		'default' => '',
	),
	'<p onclick="x()">Hi <script>alert(1)</script><a href="https://a.b" onmouseover="y">link</a></p>'
);
$ok( 'rich text strips scripts and handlers', ! str_contains( $html, '<script' ) && ! str_contains( $html, 'onclick' ) && ! str_contains( $html, 'onmouseover' ) && str_contains( $html, '<a href="https://a.b">' ) );

// 隐藏与显示。
$hidden = array(
	'moduleId' => 'hidden-one',
	'enabled'  => false,
	'settings' => array( 'title' => 'X' ),
);
$ok( 'hidden module is not shown publicly', '' === Renderer::render( 'rich_text', $hidden, 'public' ) );
$ok( 'hidden module is shown in the editor with a badge', str_contains( Renderer::render( 'rich_text', $hidden, 'editor' ), 'vc-m__badge' ) );
$m_only = Renderer::render(
	'spacer',
	array(
		'moduleId' => 'desk-only',
		'settings' => array( 'showMobile' => false ),
	),
	'public'
);
$ok( 'desktop-only module gets the mobile hide class', str_contains( $m_only, 'vc-hide-mobile' ) );
$faq = Renderer::render(
	'faq',
	array(
		'moduleId' => 'faq-test',
		'items'    => array(
			array(
				'id'       => 'q-one',
				'enabled'  => false,
				'settings' => array( 'question' => 'SECRETQ' ),
			),
		),
	),
	'public'
);
$ok( 'disabled items are skipped publicly', ! str_contains( $faq, 'SECRETQ' ) );

// 媒体：电脑 / 手机两张图。
$img_ids = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => 2,
		'fields'         => 'ids',
	)
);
if ( count( $img_ids ) >= 2 ) {
	$out = Renderer::render(
		'image',
		array(
			'moduleId' => 'img-test',
			'settings' => array(
				'media' => array(
					'desktop' => array(
						'attachmentId' => $img_ids[0],
						'alt'          => 'Desk alt',
					),
					'mobile'  => array(
						'attachmentId' => $img_ids[1],
						'alt'          => '',
					),
				),
			),
		),
		'public'
	);
	$ok( 'picture has a mobile source and the desktop image', str_contains( $out, '<source media="(max-width: 767px)"' ) && str_contains( $out, 'alt="Desk alt"' ) );
	$m2 = Renderer::normalize(
		'image',
		array(
			'settings' => array(
				'media' => array(
					'desktop' => array( 'attachmentId' => $img_ids[0] ),
					'mobile'  => array( 'attachmentId' => null ),
				),
			),
		)
	);
	$ok( 'empty mobile image is null (falls back to desktop)', null === $m2['settings']['media']['mobile'] );
} else {
	echo "SKIP media test: fewer than 2 images in the media library\n";
}
$ok( 'unknown attachment is dropped', null === Renderer::normalize( 'image', array( 'settings' => array( 'media' => array( 'desktop' => array( 'attachmentId' => 99999999 ) ) ) ) )['settings']['media']['desktop']['attachmentId'] );

// 视频与自定义 HTML。
$ok( 'youtube ids parsed', 'dQw4w9WgXcQ' === Fallback::youtube_id( 'https://youtu.be/dQw4w9WgXcQ' ) && 'dQw4w9WgXcQ' === Fallback::youtube_id( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3' ) && null === Fallback::youtube_id( 'https://evil.example/x' ) );
$yt = Renderer::render(
	'video',
	array(
		'moduleId' => 'video-test',
		'settings' => array(
			'source'     => 'youtube',
			'youtubeUrl' => 'https://youtu.be/dQw4w9WgXcQ',
			'autoplay'   => true,
		),
	),
	'public'
);
$ok( 'youtube uses the privacy-friendly domain and mutes autoplay', str_contains( $yt, 'youtube-nocookie.com/embed/dQw4w9WgXcQ' ) && str_contains( $yt, 'mute=1' ) );
Renderer::$document_id = 0;
$custom = Renderer::render(
	'custom_html',
	array(
		'moduleId' => 'custom-test',
		'settings' => array( 'html' => '<div>ok</div><script>alert(1)</script>' ),
	),
	'public'
);
$ok( 'custom html without a trusted editor is filtered', str_contains( $custom, '<div>ok</div>' ) && ! str_contains( $custom, '<script' ) );
$editor_custom = Renderer::render(
	'custom_html',
	array(
		'moduleId' => 'custom-test',
		'settings' => array( 'html' => '<script>x</script>' ),
	),
	'editor'
);
$ok( 'custom html never runs scripts in the editor', ! str_contains( $editor_custom, '<script' ) );

// 编辑器数据。
$data = Blocks::editor_data();
$ok( 'editor data lists every registered block with defaults', count( $all ) === count( $data['modules'] ) && isset( $data['modules']['vialcove/carousel']['items']['defaults']['media'] ) );
$ok(
	'device defaults are {desktop, mobile}',
	array(
		'desktop' => 4,
		'mobile'  => 2,
	) === $data['modules']['vialcove/image-grid']['defaults']['columns']
);
$ok( 'editor data has products and categories', count( $data['products'] ) > 0 && count( $data['categories'] ) > 0 );

// 商品热点只读 WooCommerce 的名称、价格和链接。
$product = wc_get_products(
	array(
		'limit'  => 1,
		'status' => 'publish',
	)
)[0] ?? null;
if ( $product ) {
	$sc = Renderer::render(
		'product_showcase',
		array(
			'moduleId' => 'showcase-test',
			'items'    => array(
				array(
					'id'       => 'scene-one',
					'settings' => array(
						'spots' => array(
							array(
								'id'       => 'spot-one',
								'settings' => array(
									'product'     => $product->get_id(),
									'buttonLabel' => 'View',
								),
							),
						),
					),
				),
			),
		),
		'public'
	);
	$ok( 'product hotspot shows the live product name and link', str_contains( $sc, esc_html( $product->get_name() ) ) && str_contains( $sc, esc_url( $product->get_permalink() ) ) );
}

// 商品区：目录顺序与现有首页一致（menu_order title 升序，4 个）。
$theme_ids = array_map(
	static fn( $p ) => $p->get_id(),
	wc_get_products(
		array(
			'status'     => 'publish',
			'visibility' => 'visible',
			'limit'      => 4,
			'orderby'    => 'menu_order title',
			'order'      => 'ASC',
		)
	)
);
$pc   = Renderer::normalize( 'product_collection', array( 'moduleId' => 'pc-test' ) );
$mine = array_map( static fn( $p ) => $p->get_id(), \PeptideCore\Modules\Products::for_module( $pc ) );
$ok( 'product collection matches the current homepage query', $theme_ids === $mine && 4 === count( $mine ) );
$manual = Renderer::normalize(
	'product_collection',
	array(
		'settings' => array(
			'source'   => 'manual',
			'products' => array(
				array( 'id' => 'p-two', 'settings' => array( 'product' => $theme_ids[1] ) ),
				array( 'id' => 'p-one', 'settings' => array( 'product' => $theme_ids[0] ) ),
			),
		),
	)
);
$ok( 'manual products keep the chosen order', array( $theme_ids[1], $theme_ids[0] ) === array_map( static fn( $p ) => $p->get_id(), \PeptideCore\Modules\Products::for_module( $manual ) ) );
$cat = get_terms( array( 'taxonomy' => 'product_cat', 'slug' => 'glp1-metabolic', 'hide_empty' => false ) );
if ( $cat ) {
	$by_cat = \PeptideCore\Modules\Products::for_module( Renderer::normalize( 'product_collection', array( 'settings' => array( 'source' => 'category', 'category' => $cat[0]->term_id, 'limit' => 24 ) ) ) );
	$ok( 'category source returns only that category', $by_cat && array() === array_filter( $by_cat, static fn( $p ) => ! has_term( $cat[0]->term_id, 'product_cat', $p->get_id() ) ) );
}
$coll = $fb( 'product_collection', array( 'moduleId' => 'pc-test', 'settings' => array( 'title' => 'Explore our products.', 'link' => array( 'label' => 'View all', 'href' => '/shop/' ) ) ) );
$ok( 'collection renders products with live names and the text link', str_contains( $coll, esc_html( wc_get_product( $theme_ids[0] )->get_name() ) ) && str_contains( $coll, 'vc-text-link' ) );

$hero = $fb(
	'carousel',
	array(
		'moduleId' => 'hero-test',
		'items'    => array(
			array( 'id' => 's-one1', 'settings' => array( 'name' => 'Collection', 'layout' => 'split', 'title' => 'A clearer view', 'titleAccent' => 'of peptides.', 'highlights' => array( array( 'id' => 'h-one', 'settings' => array( 'label' => 'Batch lookup', 'title' => 'Find your batch.', 'href' => '/reports/' ) ) ) ) ),
			array( 'id' => 's-two2', 'settings' => array( 'title' => 'Built on' ) ),
		),
	)
);
$ok( 'first slide title is the page H1, others H2', str_contains( $hero, '<h1 class="vc-slide__title">A clearer view<br><em>of peptides.</em></h1>' ) && str_contains( $hero, '<h2 class="vc-slide__title">Built on</h2>' ) );
$ok( 'slides have accessible names and floating cards', str_contains( $hero, 'aria-label="1 / 2: Collection"' ) && str_contains( $hero, 'vc-slide__card' ) && str_contains( $hero, 'vc-slide--split' ) );
$rf = Renderer::render( 'report_feature', array( 'moduleId' => 'rf-test', 'settings' => array( 'title' => 'Details you can', 'titleAccent' => 'look into.' ), 'items' => array( array( 'id' => 'r-one', 'settings' => array( 'label' => 'Product', 'value' => 'Linked to its batch' ) ) ) ), 'public' );
$ok( 'report highlight renders title and card rows', str_contains( $rf, '<em>look into.</em>' ) && str_contains( $rf, 'Linked to its batch' ) );
$faq2 = $fb( 'faq', array( 'moduleId' => 'faq-two', 'settings' => array( 'eyebrow' => 'A LITTLE MORE CLARITY', 'title' => 'Good questions.', 'titleAccent' => 'Clear answers.', 'link' => array( 'label' => 'Explore', 'href' => '/reports/' ) ) ) );
$ok( 'faq has eyebrow, accent title and link', str_contains( $faq2, 'A LITTLE MORE CLARITY' ) && str_contains( $faq2, '<em>Clear answers.</em>' ) && str_contains( $faq2, 'vc-text-link' ) );

$sh = $fb( 'section_heading', array( 'moduleId' => 'sh-test', 'settings' => array( 'eyebrow' => 'THE VIALCOVE STORY', 'title' => 'Three places.', 'titleAccent' => 'One connected vision.' ) ) );
$ok( 'section heading renders eyebrow and accent title', str_contains( $sh, 'THE VIALCOVE STORY' ) && str_contains( $sh, '<em>One connected vision.</em>' ) && str_contains( $sh, 'vc-heading--center' ) );
$mt = $fb( 'media_text', array( 'moduleId' => 'mt-test', 'settings' => array( 'eyebrow' => '01 / ISRAEL', 'label' => 'RESEARCH', 'caption' => 'AI-generated scene', 'title' => 'Scientific collaboration.' ) ) );
$ok( 'default design marks each item with data-module-item', str_contains( $fb( 'faq', array( 'moduleId' => 'faq-attr', 'items' => array( array( 'id' => 'q-attr1', 'settings' => array( 'question' => 'Q' ) ) ) ) ), 'data-module-item="q-attr1"' ) && str_contains( $hero, 'data-module-item="s-one1"' ) );
$ok( 'image & text renders eyebrow, label and caption', str_contains( $mt, '01 / ISRAEL' ) && str_contains( $mt, 'vc-mt__label' ) && str_contains( $mt, '<figcaption class="vc-mt__caption">AI-generated scene</figcaption>' ) );

// 手机版单独设置（EDIT-BLOCKS-07）：只存改过的普通字段；条目整份替换。
$mob = Renderer::normalize(
	'cta',
	array(
		'moduleId' => 'cta-mob',
		'settings' => array( 'title' => 'Desktop title', 'eyebrow' => 'SAME' ),
		'mobile'   => array(
			'settings' => array(
				'title'        => 'Phone title',
				'showMobile'   => false,
				'paddingTopPx' => array( 'desktop' => 1, 'mobile' => 2 ),
				'nope'         => 'x',
			),
		),
	)
);
$ok( 'mobile overrides keep only normal fields', array( 'title' ) === array_keys( $mob['mobile']['settings'] ) );
$ok( 'mobile variant merges overrides over desktop', 'Phone title' === Renderer::variant( $mob, 'mobile' )['settings']['title'] && 'SAME' === Renderer::variant( $mob, 'mobile' )['settings']['eyebrow'] && 'Desktop title' === Renderer::variant( $mob, 'desktop' )['settings']['title'] );
$two = Renderer::render( 'cta', array( 'moduleId' => 'cta-mob', 'settings' => array( 'title' => 'Desktop title' ), 'mobile' => array( 'settings' => array( 'title' => 'Phone title' ) ) ), 'public' );
$ok( 'module with mobile overrides outputs desktop and mobile versions', str_contains( $two, 'vc-v vc-v--desktop' ) && str_contains( $two, 'vc-v vc-v--mobile' ) && str_contains( $two, 'Desktop title' ) && str_contains( $two, 'Phone title' ) && 1 === substr_count( $two, 'id="vc-cta-mob"' ) );
$one = Renderer::render( 'cta', array( 'moduleId' => 'cta-one', 'settings' => array( 'title' => 'Only' ) ), 'public' );
$ok( 'module without mobile overrides outputs one version', ! str_contains( $one, 'vc-v--mobile' ) );
$mitems = Renderer::normalize( 'faq', array( 'items' => array( array( 'id' => 'q-desk', 'settings' => array( 'question' => 'D' ) ) ), 'mobile' => array( 'items' => array( array( 'id' => 'q-desk', 'settings' => array( 'question' => 'M' ) ) ) ) ) );
$ok( 'mobile items replace the desktop list on phones', 'M' === Renderer::variant( $mitems, 'mobile' )['items'][0]['settings']['question'] && 'D' === $mitems['items'][0]['settings']['question'] );
$kept = \PeptideCore\Modules\Layout::clean_modules( array( array( 'block' => 'vialcove/cta', 'attrs' => array( 'moduleId' => 'cta-keep', 'mobile' => array( 'settings' => array( 'title' => 'Phone' ) ) ) ), array( 'block' => 'vialcove/cta', 'attrs' => array( 'moduleId' => 'cta-none' ) ) ) );
$ok( 'saving keeps mobile overrides and omits empty ones', 'Phone' === $kept[0]['attrs']['mobile']['settings']['title'] && ! isset( $kept[1]['attrs']['mobile'] ) );

// 浮动卡片：放置方式电脑 / 手机分开，位置可以拖动保存。
$cards = $fb(
	'carousel',
	array(
		'moduleId' => 'card-test',
		'items'    => array(
			array(
				'id'       => 'slide-c1',
				'settings' => array(
					'highlights' => array(
						array( 'id' => 'card-a1', 'settings' => array( 'title' => 'A', 'x' => array( 'desktop' => 10, 'mobile' => null ), 'y' => array( 'desktop' => 20, 'mobile' => null ) ) ),
						array( 'id' => 'card-b1', 'settings' => array( 'title' => 'B' ) ),
					),
				),
			),
		),
	)
);
$ok( 'cards default: on the image on desktop, hidden on mobile (phones are too small)', str_contains( $cards, 'vc-cards-d-overlay vc-cards-m-hidden' ) );
$ok( 'dragged card keeps its position; others stay automatic', str_contains( $cards, '--x:10%;--y:20%' ) && str_contains( $cards, 'is-placed-d' ) && 1 === substr_count( $cards, 'is-placed-d' ) );
$ok( 'cards are draggable inside the image area', str_contains( $cards, 'data-pc-drag-area' ) && str_contains( $cards, 'data-module-item="card-a1" data-pc-drag' ) && str_contains( $cards, 'data-module-item="card-b1" data-pc-drag' ) );

// 编辑器的区块预览接口（ServerSideRender 实际调用的 /wp/v2/block-renderer）。
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$doc = \PeptideCore\Templates::document_id( \PeptideCore\Templates::default_id(), 'home' );
$req = new WP_REST_Request( 'POST', '/wp/v2/block-renderer/vialcove/faq' );
$req->set_param( 'context', 'edit' );
$req->set_param( 'post_id', $doc );
$req->set_param(
	'attributes',
	array(
		'moduleId' => 'rest-faq',
		'items'    => array(
			array(
				'id'       => 'rest-q1',
				'settings' => array( 'question' => 'REST QUESTION' ),
			),
		),
	)
);
$res = rest_do_request( $req );
$out = $res->get_data();
$ok( 'block renderer endpoint returns editor preview', 200 === $res->get_status() && str_contains( (string) ( $out['rendered'] ?? '' ), 'REST QUESTION' ) && str_contains( (string) ( $out['rendered'] ?? '' ), 'vc-m--editor' ) );
Renderer::$in_editor = false;
wp_set_current_user( 0 );

restore_error_handler();
$ok( 'no PHP warnings or notices from the plugin', array() === $errors );
if ( $errors ) {
	echo '  ', implode( "\n  ", array_slice( array_unique( $errors ), 0, 10 ) ), "\n";
}
