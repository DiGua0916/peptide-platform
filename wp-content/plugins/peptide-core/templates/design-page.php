<?php
defined( 'ABSPATH' ) || exit;
$pc_document = \PeptideCore\Design_Pages::active_document();
$pc_modules = \PeptideCore\Templates::previewing_id()
	? \PeptideCore\Modules\Layout::modules_from_content( (string) get_post_field( 'post_content', \PeptideCore\Templates::document_id( \PeptideCore\Templates::current_id(), $pc_document, false ) ) )
	: \PeptideCore\Modules\Layout::published_modules( $pc_document );
$pc_has_native = (bool) array_filter( $pc_modules, static fn( $row ) => 'vialcove/context-content' === $row['block'] );
get_header();
echo $pc_has_native ? '<div class="vc-layout pc-page-design">' : '<main id="main-content" class="vc-layout pc-page-design">';
echo \PeptideCore\Modules\Layout::render_current( $pc_document ); // Already escaped by each module.
echo $pc_has_native ? '</div>' : '</main>';
\PeptideCore\Design_Pages::footer();
