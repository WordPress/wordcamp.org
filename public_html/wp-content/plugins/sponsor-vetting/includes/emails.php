<?php
/**
 * The compliance request and approval emails, sent as FreeScout conversations from the Community mailbox.
 *
 * Builders return the message without sending it; senders send it and save the conversation on the record. Every
 * conversation is kept, as a list, so a new one never replaces the link to an earlier one.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

use WordPressdotorg\MU_Plugins\Utilities\FreeScout;

defined( 'WPINC' ) || die();

/**
 * Whether the FreeScout client is loaded or can be.
 *
 * @return bool
 */
function freescout_available(): bool {
	return class_exists( FreeScout::class );
}

/**
 * Renders a reviewer's Markdown findings as HTML for an email.
 *
 * @param string $markdown The findings.
 * @return string
 */
function render_markdown( string $markdown ): string {
	if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) ) {
		$library = WP_PLUGIN_DIR . '/jetpack/_inc/lib/markdown';

		if ( ! is_readable( $library . '/gfm.php' ) ) {
			return wpautop( esc_html( $markdown ) );
		}

		if ( ! class_exists( 'MarkdownExtra_Parser' ) ) {
			require_once $library . '/extra.php';
		}

		require_once $library . '/gfm.php';
	}

	$parser = new \WPCom_GHF_Markdown_Parser();

	// Shortcodes mean nothing in an email.
	$parser->use_code_shortcode  = false;
	$parser->preserve_shortcodes = false;

	// The parser leaves a lone paragraph unwrapped; WordPress normally adds the tags on display.
	return wp_kses_post( wpautop( $parser->transform( $markdown ) ) );
}

/**
 * Builds the compliance request: the findings, emailed to the sponsor with the event copied.
 *
 * @param int $company_id     The company.
 * @param int $sponsorship_id The sponsorship it's for, which names the event and copies it in.
 * @return array|\WP_Error Arguments for `FreeScout::send()`.
 */
function build_compliance_email( int $company_id, int $sponsorship_id ): array|\WP_Error {
	$email = (string) get_post_meta( $company_id, '_sv_contact_email', true );

	if ( ! is_email( $email ) ) {
		return new \WP_Error( 'sv_no_contact', __( 'Add the sponsor contact’s email address first.', 'wordcamporg' ) );
	}

	if ( COMPANY_POST_TYPE !== get_post_type( $company_id ) || (int) get_post_field( 'post_parent', $sponsorship_id ) !== $company_id ) {
		return new \WP_Error( 'sv_no_sponsorship', __( 'Pick one of the company’s sponsorships.', 'wordcamporg' ) );
	}

	$findings = array(
		'GPL'       => trim( (string) get_post_meta( $company_id, '_sv_gpl_findings', true ) ),
		'Trademark' => trim( (string) get_post_meta( $company_id, '_sv_trademark_findings', true ) ),
	);

	if ( ! array_filter( $findings ) ) {
		return new \WP_Error( 'sv_no_findings', __( 'Add the GPL or trademark findings first.', 'wordcamporg' ) );
	}

	$blog_id     = (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true );
	$event_email = function_exists( 'get_wordcamp_email' ) ? (string) get_wordcamp_email( $blog_id ) : '';

	if ( ! is_email( $event_email ) ) {
		return new \WP_Error( 'sv_no_event_email', __( 'The event has no email address to copy in.', 'wordcamporg' ) );
	}

	$details = '';
	foreach ( array_filter( $findings ) as $label => $markdown ) {
		$details .= '<p><strong>' . $label . ':</strong></p>' . render_markdown( $markdown );
	}

	// The wording is Legal's, carried over from the Zap: change it only with them.
	$body = '<p>Thanks so much for offering to sponsor ' . esc_html( get_event_name( $blog_id ) ) . '! We’re excited to have you on board.</p>'
		. '<p>Before approval, we review every sponsor’s site to make sure it follows the WordPress Trademark Policy and GPL requirements. This helps protect the WordPress project for everyone, including sponsors.</p>'
		. '<p><strong>We found a few things to update, listed below.</strong> Once you’ve made changes, let us know and we’ll send the application along for final approval so your sponsorship can go live.</p>'
		. '<p>Typically we suggest completing updates within two weeks, but if you need a longer timeline or have questions, let us know. Thanks again for being part of this community!</p>'
		. '<p><strong>Details</strong></p>'
		. $details;

	return array(
		'mailbox' => MAILBOX,
		'to'      => array(
			'email'      => $email,
			'first_name' => (string) get_post_meta( $company_id, '_sv_contact_first_name', true ),
			'last_name'  => (string) get_post_meta( $company_id, '_sv_contact_last_name', true ),
		),
		'subject' => 'Action Required: Compliance for WordPress Event Sponsorship',
		'body'    => $body,
		'cc'      => array( $event_email ),
		'tags'    => array( 'sponsor-vetting', 'sponsor-infringing' ),
	);
}

/**
 * Builds the approval email to the event.
 *
 * @param int $sponsorship_id The approved sponsorship.
 * @return array|\WP_Error Arguments for `FreeScout::send()`.
 */
function build_approval_email( int $sponsorship_id ): array|\WP_Error {
	$blog_id     = (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true );
	$event_email = function_exists( 'get_wordcamp_email' ) ? (string) get_wordcamp_email( $blog_id ) : '';

	if ( ! is_email( $event_email ) ) {
		return new \WP_Error( 'sv_no_event_email', __( 'The event has no email address.', 'wordcamporg' ) );
	}

	$company_id = (int) get_post_field( 'post_parent', $sponsorship_id );

	// The wording is carried over from the Zap.
	$body = '<p>Dear ' . esc_html( get_event_name( $blog_id ) ) . ',</p>'
		. '<p>We’re happy to let you know that the sponsorship application for ' . esc_html( get_the_title( $company_id ) ) . ' has been approved — thank you for growing the WordPress community!</p>'
		. '<p>Please, feel free to reach out to the sponsor point of contact to follow up on the next steps.</p>'
		. '<p>If you have any questions in the meantime, feel free to reply to this email.</p>'
		. '<p>Best regards!</p>';

	return array(
		'mailbox' => MAILBOX,
		'to'      => $event_email,
		'subject' => 'WordPress Event Sponsorship Approved',
		'body'    => $body,
		'tags'    => array( 'sponsor-approval' ),
	);
}

/**
 * Whether an approved sponsorship still needs the approval email.
 *
 * Not when the camp has invoiced the sponsor: approving the invoice tells the organizers. Not twice, either.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return bool
 */
function needs_approval_email( int $sponsorship_id ): bool {
	if ( 'svs-approved' !== get_post_status( $sponsorship_id ) || get_conversations( $sponsorship_id, 'approval' ) ) {
		return false;
	}

	return ! has_invoice( (int) get_post_meta( $sponsorship_id, '_sv_blog_id', true ), (int) get_post_meta( $sponsorship_id, '_sv_sponsor_id', true ) );
}

/**
 * Whether a camp has submitted an invoice for a sponsor.
 *
 * @param int $blog_id    The camp site.
 * @param int $sponsor_id The camp's `wcb_sponsor` post.
 * @return bool
 */
function has_invoice( int $blog_id, int $sponsor_id ): bool {
	switch_to_blog( $blog_id );

	$invoices = get_posts(
		array(
			'post_type'      => 'wcb_sponsor_invoice',
			'post_status'    => array( 'wcbsi_submitted', 'wcbsi_approved', 'wcbsi_paid', 'wcbsi_uncollectible', 'wcbsi_refunded' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_wcbsi_sponsor_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One camp's invoices.
			'meta_value'     => $sponsor_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One camp's invoices.
		)
	);

	restore_current_blog();

	return (bool) $invoices;
}

/**
 * Sends the compliance request and moves the company to Compliance Requested.
 *
 * Callers check capabilities first.
 *
 * @param int $company_id     The company.
 * @param int $sponsorship_id The sponsorship it's for.
 * @return array|\WP_Error The conversation.
 */
function send_compliance_request( int $company_id, int $sponsorship_id ): array|\WP_Error {
	if ( ! is_allowed_transition( COMPANY_POST_TYPE, (string) get_post_status( $company_id ), 'sv-compliance-req' ) ) {
		return new \WP_Error( 'sv_not_requestable', __( 'Move the company to In Progress before requesting changes.', 'wordcamporg' ) );
	}

	$email = build_compliance_email( $company_id, $sponsorship_id );

	if ( is_wp_error( $email ) ) {
		return $email;
	}

	if ( ! freescout_available() ) {
		return new \WP_Error( 'sv_no_freescout', __( 'The FreeScout client isn’t available.', 'wordcamporg' ) );
	}

	$conversation = FreeScout::send( $email );

	if ( is_wp_error( $conversation ) ) {
		return $conversation;
	}

	record_conversation( $company_id, $conversation, 'compliance', $sponsorship_id );

	// A new request starts a new wait for the sponsor's reply.
	delete_post_meta( $company_id, '_sv_sponsor_replied' );

	wp_update_post(
		array(
			'ID'          => $company_id,
			'post_status' => 'sv-compliance-req',
		)
	);

	return $conversation;
}

/**
 * Sends the approval email, if the sponsorship needs one.
 *
 * Callers check capabilities first.
 *
 * @param int $sponsorship_id The sponsorship.
 * @return array|\WP_Error|null The conversation, or null when none is needed.
 */
function send_approval_email( int $sponsorship_id ): array|\WP_Error|null {
	if ( ! needs_approval_email( $sponsorship_id ) ) {
		return null;
	}

	$email = build_approval_email( $sponsorship_id );

	if ( is_wp_error( $email ) ) {
		return $email;
	}

	if ( ! freescout_available() ) {
		return new \WP_Error( 'sv_no_freescout', __( 'The FreeScout client isn’t available.', 'wordcamporg' ) );
	}

	$conversation = FreeScout::send( $email );

	if ( ! is_wp_error( $conversation ) ) {
		record_conversation( $sponsorship_id, $conversation, 'approval' );
	}

	return $conversation;
}

/**
 * Saves a conversation on the record that started it, alongside earlier ones.
 *
 * @param int    $post_id        The company or sponsorship.
 * @param array  $conversation   The conversation, as `FreeScout::send()` returns it.
 * @param string $type           `compliance` or `approval`.
 * @param int    $sponsorship_id The sponsorship it's about, for a company's conversations.
 */
function record_conversation( int $post_id, array $conversation, string $type, int $sponsorship_id = 0 ): void {
	add_post_meta(
		$post_id,
		'_sv_conversation',
		array(
			'id'             => (int) ( $conversation['id'] ?? 0 ),
			'url'            => esc_url_raw( (string) ( $conversation['url'] ?? '' ) ),
			'type'           => $type,
			'sponsorship_id' => $sponsorship_id,
			'user_id'        => get_current_user_id(),
			'time'           => time(),
		)
	);
}

/**
 * Gets the conversations a record started, oldest first.
 *
 * @param int    $post_id The company or sponsorship.
 * @param string $type    Optional. Only conversations of this type.
 * @return array[]
 */
function get_conversations( int $post_id, string $type = '' ): array {
	$conversations = get_post_meta( $post_id, '_sv_conversation', false );

	if ( '' !== $type ) {
		$conversations = array_filter( $conversations, static fn( array $conversation ): bool => $type === $conversation['type'] );
	}

	usort( $conversations, static fn( array $a, array $b ): int => $a['time'] <=> $b['time'] );

	return $conversations;
}
