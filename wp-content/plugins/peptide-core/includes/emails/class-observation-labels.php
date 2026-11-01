<?php
/** Data page labels kept outside the shared page-registration file. */
namespace PeptideCore\Emails;
defined( 'ABSPATH' ) || exit;
final class Observation_Labels {
	public static function strings(): array {
		return array(
			'legacyTitle' => __( 'WooCommerce send log', 'peptide-core' ),
			'legacyHelp' => __( 'These existing totals cover WooCommerce send attempts only. Source and sender filters below apply only to new message observations. Acceptance by the mail service does not prove inbox delivery.', 'peptide-core' ),
			'observationTitle' => __( 'New message observations', 'peptide-core' ),
			'observationHelp' => __( 'Image and catalogue-link requests can come from proxies or scanners; they do not prove a person read the email. Each accepted eligible send attempt counts at most once. Rates use only newly observed, accepted attempts eligible for that measurement, grouped by sending time. Plain mail, old log entries and unsupported messages are excluded from these denominators. New observations cover supported WooCommerce messages, email campaigns, subscription confirmations and points notices; other mail sources are not covered.', 'peptide-core' ),
			'opened' => __( 'Messages with image requests', 'peptide-core' ),
			'open_rate' => __( 'Image request rate', 'peptide-core' ),
			'clicked' => __( 'Messages with link requests', 'peptide-core' ),
			'click_rate' => __( 'Link request rate', 'peptide-core' ),
			'attempts' => __( 'New send attempts', 'peptide-core' ),
			'accepted' => __( 'Accepted by mail transport', 'peptide-core' ),
			'failed' => __( 'Failed', 'peptide-core' ),
			'uncertain' => __( 'Needs manual review', 'peptide-core' ),
			'pending' => __( 'Outcome not recorded', 'peptide-core' ),
			'coverage' => __( 'Observation coverage', 'peptide-core' ),
			'coverage_html_observed' => __( 'Supported HTML', 'peptide-core' ),
			'coverage_html_prepared' => __( 'Awaiting native confirmation', 'peptide-core' ),
			'coverage_native_unconfirmed' => __( 'Native confirmation unavailable', 'peptide-core' ),
			'coverage_native_changed' => __( 'Native content changed after preparation', 'peptide-core' ),
			'coverage_plain_no_observations' => __( 'Plain text: engagement unavailable', 'peptide-core' ),
			'coverage_not_observed' => __( 'Native MIME not observed', 'peptide-core' ),
			'coverage_unsupported_mime' => __( 'MIME not supported', 'peptide-core' ),
			'coverage_unsupported_html' => __( 'HTML not supported', 'peptide-core' ),
			'coverageUnknown' => __( 'Coverage unknown', 'peptide-core' ),
			'open_eligible' => __( 'Image-eligible accepted attempts', 'peptide-core' ),
			'click_eligible' => __( 'Link-eligible accepted attempts', 'peptide-core' ),
			'sourceFamily' => __( 'Message source', 'peptide-core' ),
			'allSources' => __( 'All supported sources', 'peptide-core' ),
			'wooSource' => __( 'WooCommerce', 'peptide-core' ),
			'campaignSource' => __( 'Email campaign', 'peptide-core' ),
			'subscriptionSource' => __( 'Subscription confirmations', 'peptide-core' ),
			'loyaltySource' => __( 'Points notices', 'peptide-core' ),
			'unknownSource' => __( 'Unknown source', 'peptide-core' ),
			'sender' => __( 'Observed sender', 'peptide-core' ),
			'allSenders' => __( 'All observed senders (up to 100)', 'peptide-core' ),
			'unknownSender' => __( 'Sender not observed', 'peptide-core' ),
			'unobservedSender' => __( 'Selected sender is not in the available list', 'peptide-core' ),
			'noObservedMessages' => __( 'No new message attempts match this period and filter. Missing collection is not evidence of zero engagement.', 'peptide-core' ),
			'observation_enabled' => __( 'Observation collection is enabled for supported new messages.', 'peptide-core' ),
			'observation_disabled' => __( 'Observation collection is disabled. Existing facts remain available; no historical engagement is reconstructed.', 'peptide-core' ),
			'observation_not_installed' => __( 'Message observation storage has not been adopted. Engagement values are unavailable.', 'peptide-core' ),
			'observation_unavailable' => __( 'Message observations are unavailable. No engagement total can be inferred.', 'peptide-core' ),
		);
	}
}
