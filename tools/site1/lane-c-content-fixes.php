<?php
/**
 * Site-1 plan, lane C: product content fixes (P1-7 purity typo, P1-9 standard FAQ, P2-1 names).
 *
 * Run with WP-CLI on the store whose products you edit. Under series sync that is the MASTER
 * store; push the series afterwards so the other stores receive the change.
 *
 *   wp eval-file tools/site1/lane-c-content-fixes.php            # dry run (default): prints what would change
 *   wp eval-file tools/site1/lane-c-content-fixes.php apply      # writes the changes, keeps a backup per product
 *   wp eval-file tools/site1/lane-c-content-fixes.php rollback   # restores titles/content from that backup
 *                                                                  # (skips products edited after apply; add "force" to override)
 *
 * Idempotent: a second "apply" finds nothing to change. Slugs, prices, variations, stock and
 * attributes are never touched. Storage temperatures and usage paragraphs outside the FAQ are
 * NOT rewritten here (they vary per product); see docs/site1/lane-c-content-review.md.
 *
 * The standard FAQ wording is a draft for legal review (docs/site1/lane-c-legal-review.md).
 */

defined( 'ABSPATH' ) || exit( 'Run with: wp eval-file' );

$vc_lc_mode = in_array( 'apply', (array) ( $args ?? array() ), true ) ? 'apply'
	: ( in_array( 'rollback', (array) ( $args ?? array() ), true ) ? 'rollback' : 'dry-run' );
const VC_LC_BACKUP = '_vc_site1_lane_c_backup_v1';
const VC_LC_MARK   = '<!-- vc-standard-faq v2 -->';

/** Exact current title => new title. Benzyl Alcohol 0.9% is a separate product and is not listed. */
function vc_lc_title_map(): array {
	return array(
		'Cagriliintide' => 'Cagrilintide',
		'adamax'        => 'Adamax',
		'bac.water'     => 'Bacteriostatic Water',
	);
}

/** Question => answer. Product-name free so each string has one translation. */
function vc_lc_standard_faq(): array {
	return array(
		// 2026-11-16 F1 法务核对：COA 库多为别家样品的参考报告，前两问不能说“报告就是你收到的批次”。
		'What does the COA on this page show?'                => 'It is a third-party laboratory report for a reference sample of this compound and specification. It lists the laboratory, test date and HPLC purity of the sample named in the report. It is not a certificate for the specific lot you receive.',
		'Which lot will I receive?'                           => 'Orders ship from current stock. For the lot details of your order, contact our support team with your order number.',
		'How is my order shipped?'                            => 'Orders ship with FedEx. The shipping cost and delivery estimate for your address are shown at checkout before you pay.',
		'How should unopened vials be stored?'                => 'Keep unopened vials sealed at 2–8 °C, dry and protected from light.',
		'What if my order arrives damaged or incomplete?'     => 'Contact our support team with your order number and photos of the package. We will review it with you.',
		'Is this product for human or veterinary use?'        => 'No. It is supplied for laboratory research use only and is not intended for human or animal consumption.',
	);
}

function vc_lc_faq_html(): string {
	$out = VC_LC_MARK . '<h2 style="text-align: center">FAQ</h2>';
	foreach ( vc_lc_standard_faq() as $q => $a ) {
		$out .= '<h3 style="text-align: center">' . esc_html( $q ) . '</h3><p style="text-align: center">' . esc_html( $a ) . '</p>';
	}
	return $out;
}

/** Offset where the FAQ question/answer run that starts at $from ends. */
function vc_lc_faq_end( string $html, int $from, string &$reopen = '' ): int {
	$pos = $from; $end = $from; $seen_question = false; $len = strlen( $html ); $wrapped = 0;
	while ( $pos < $len ) {
		if ( preg_match( '~\G(?:\s+|<!--.*?-->)~s', $html, $ws, 0, $pos ) ) { $pos += strlen( $ws[0] ); continue; }
		// Most products wrap the questions in one <div> (rich-text-editor-content) right after the heading:
		// step into it, and take its closing </div> as part of the FAQ when the run inside ends there.
		if ( ! $seen_question && ! $wrapped && preg_match( '~\G<div\b[^>]*>~i', $html, $open, 0, $pos ) ) { $pos += strlen( $open[0] ); $wrapped = 1; $reopen = $open[0]; continue; }
		if ( $wrapped && $seen_question && preg_match( '~\G</div>~i', $html, $close, 0, $pos ) ) { $end = $pos + strlen( $close[0] ); $reopen = ''; return $end; }
		if ( ! preg_match( '~\G<(h3|p|ul|ol)\b[^>]*>.*?</\1>~is', $html, $block, 0, $pos ) ) { break; } // any other element ends the FAQ
		$tag = strtolower( $block[1] );
		if ( 'h3' === $tag ) { $seen_question = true; }
		elseif ( ! $seen_question ) { break; }
		elseif ( 'p' === $tag && preg_match( '~^\s*(?:disclaimer|references?|note)\b~i', wp_strip_all_tags( $block[0] ) ) ) { break; }
		$pos += strlen( $block[0] ); $end = $pos;
	}
	if ( ! $seen_question ) { $reopen = ''; return $from; } // nothing recognisable: replace only the heading
	return $end; // wrapper not closed by the run: caller re-opens it
}

/**
 * Apply the content rules. Returns [new_content, list_of_changes].
 *
 * @return array{0:string,1:array<int,string>}
 */
function vc_lc_transform_content( string $html ): array {
	$changes = array();
	// P1-7: "≥ 99(%" typo.
	$fixed = preg_replace( '/≥\s*99\s*\(\s*%/u', '≥ 99%', $html, -1, $count );
	if ( is_string( $fixed ) && $count > 0 ) { $html = $fixed; $changes[] = "purity typo x{$count}"; }
	// P1-9: replace an existing FAQ section: the heading plus the question/answer blocks right after it
	// (<h3> questions, each followed by <p>/<ul>/<ol> answers). It stops at the next <h2>, at any other
	// element (<h4>, <div>, <hr>, tables...) and at a "Disclaimer" / "Reference(s)" / "Note" paragraph,
	// so content after the FAQ is kept.
	if ( ! str_contains( $html, VC_LC_MARK ) ) {
		if ( preg_match( '~<h2\b[^>]*>\s*(?:<[^>]+>\s*)*(?:FAQs?|Frequently\s+asked\s+questions)\s*[:：?]?\s*(?:</[^>]+>\s*)*</h2>~iu', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1];
			$after = $start + strlen( $m[0][0] );
			$reopen = '';
			$end   = vc_lc_faq_end( $html, $after, $reopen );
			$old_questions = preg_match_all( '~<h3\b~i', substr( $html, $after, $end - $after ) );
			$removed = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( substr( $html, $after, $end - $after ) ) ) );
			$kept    = trim( wp_strip_all_tags( substr( $html, $end ) ) );
			$html = substr( $html, 0, $start ) . vc_lc_faq_html() . $reopen . substr( $html, $end );
			$changes[] = "FAQ section replaced ({$old_questions} old questions -> " . count( vc_lc_standard_faq() ) . ' standard; removes ' . strlen( $removed ) . ' chars: "' . mb_substr( $removed, 0, 160 ) . ( mb_strlen( $removed ) > 160 ? '…' : '' ) . '"; keeps ' . strlen( $kept ) . ' chars after it)';
		}
	}
	return array( $html, $changes );
}

$vc_lc_ids = wc_get_products( array( 'status' => array( 'publish', 'private', 'draft' ), 'limit' => -1, 'return' => 'ids', 'type' => array( 'simple', 'variable' ) ) );
$vc_lc_total = 0;
$vc_lc_locked_warned = false;
foreach ( $vc_lc_ids as $vc_lc_id ) {
	$post = get_post( $vc_lc_id );
	if ( ! $post ) { continue; }

	if ( 'rollback' === $vc_lc_mode ) {
		$backup = get_post_meta( $vc_lc_id, VC_LC_BACKUP, true );
		if ( ! is_array( $backup ) ) { continue; }
		// Don't silently undo edits made after apply: only roll back products still exactly as this script left them.
		if ( isset( $backup['applied'] ) && $backup['applied'] !== md5( $post->post_title . "\n" . $post->post_content ) && ! in_array( 'force', (array) ( $args ?? array() ), true ) ) {
			WP_CLI::warning( "#{$vc_lc_id} was edited after apply; not restored (add 'force' to restore anyway)." );
			continue;
		}
		wp_update_post( wp_slash( array( 'ID' => $vc_lc_id, 'post_title' => $backup['title'], 'post_content' => $backup['content'] ) ) );
		delete_post_meta( $vc_lc_id, VC_LC_BACKUP );
		WP_CLI::log( "#{$vc_lc_id} restored" );
		++$vc_lc_total;
		continue;
	}

	$changes = array();
	$title   = $post->post_title;
	$map     = vc_lc_title_map();
	if ( isset( $map[ $title ] ) ) { $changes[] = "title \"{$title}\" -> \"{$map[ $title ]}\""; $title = $map[ $title ]; }
	list( $content, $content_changes ) = vc_lc_transform_content( (string) $post->post_content );
	$changes = array_merge( $changes, $content_changes );
	if ( ! $changes ) { continue; }

	++$vc_lc_total;
	WP_CLI::log( "#{$vc_lc_id} {$post->post_title}: " . implode( '; ', $changes ) );
	if ( ! $vc_lc_locked_warned && '1' === (string) get_post_meta( $vc_lc_id, '_pc_series_managed', true ) ) {
		WP_CLI::warning( 'Products here are series-managed. Edit the master store and push the series, or the next sync will undo this.' );
		$vc_lc_locked_warned = true;
	}
	if ( 'apply' !== $vc_lc_mode ) { continue; }
	if ( ! is_array( get_post_meta( $vc_lc_id, VC_LC_BACKUP, true ) ) ) {
		add_post_meta( $vc_lc_id, VC_LC_BACKUP, array( 'title' => $post->post_title, 'content' => $post->post_content, 'at' => gmdate( 'c' ) ), true );
	}
	$result = wp_update_post( wp_slash( array( 'ID' => $vc_lc_id, 'post_title' => $title, 'post_content' => $content ) ), true );
	if ( is_wp_error( $result ) ) { WP_CLI::warning( "#{$vc_lc_id} not saved: " . $result->get_error_message() ); continue; }
	$saved  = get_post( $vc_lc_id );
	$backup = get_post_meta( $vc_lc_id, VC_LC_BACKUP, true );
	if ( $saved && is_array( $backup ) ) { $backup['applied'] = md5( $saved->post_title . "\n" . $saved->post_content ); update_post_meta( $vc_lc_id, VC_LC_BACKUP, $backup ); }
}
delete_transient( 'vc_counter_live_v1' );
WP_CLI::success( sprintf( '%s: %d product(s) %s.', $vc_lc_mode, $vc_lc_total, 'apply' === $vc_lc_mode ? 'changed' : ( 'rollback' === $vc_lc_mode ? 'restored' : 'would change' ) ) );
