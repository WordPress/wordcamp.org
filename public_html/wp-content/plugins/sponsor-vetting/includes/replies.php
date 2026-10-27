<?php
/**
 * Flags companies whose sponsor has replied to a compliance request.
 *
 * FreeScout has no webhook here, so an hourly job reads the few conversations still waiting for a reply.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

use WordPressdotorg\MU_Plugins\Utilities\FreeScout;

defined( 'WPINC' ) || die();

add_action( 'init', __NAMESPACE__ . '\schedule_reply_check' );
add_action( 'sv_check_replies', __NAMESPACE__ . '\check_replies' );

/**
 * Schedules the hourly reply check on Central.
 */
function schedule_reply_check(): void {
	if ( is_central() && ! wp_next_scheduled( 'sv_check_replies' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sv_check_replies' );
	}
}

/**
 * Flags each company in Compliance Requested whose sponsor wrote last in its latest compliance conversation.
 */
function check_replies(): void {
	if ( ! is_central() || ! freescout_available() ) {
		return;
	}

	$company_ids = get_posts(
		array(
			'post_type'      => COMPANY_POST_TYPE,
			'post_status'    => 'sv-compliance-req',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $company_ids as $company_id ) {
		$conversations = get_conversations( $company_id, 'compliance' );
		$latest        = end( $conversations );

		if ( ! $latest ) {
			continue;
		}

		$conversation = FreeScout::get( (int) $latest['id'] );

		if ( is_wp_error( $conversation ) ) {
			if ( function_exists( '\WordCamp\Logger\log' ) ) {
				\WordCamp\Logger\log(
					'sponsor_vetting_reply_check_failed',
					array(
						'company_id' => $company_id,
						'error'      => $conversation->get_error_message(),
					)
				);
			}
			continue;
		}

		if ( ! sponsor_wrote_last( $conversation ) ) {
			delete_post_meta( $company_id, '_sv_sponsor_replied' );
		} elseif ( ! get_post_meta( $company_id, '_sv_sponsor_replied', true ) ) {
			update_post_meta( $company_id, '_sv_sponsor_replied', time() );
		}
	}
}

/**
 * Whether the customer wrote the latest message in a conversation.
 *
 * Notes and FreeScout's own line items don't count, and threads are ordered by when they were written rather
 * than trusting the order the API lists them in.
 *
 * @param array $conversation A conversation, as `FreeScout::get()` returns it.
 * @return bool
 */
function sponsor_wrote_last( array $conversation ): bool {
	$messages = array_filter(
		(array) ( $conversation['_embedded']['threads'] ?? array() ),
		static fn( mixed $thread ): bool => is_array( $thread ) && in_array( $thread['type'] ?? '', array( 'customer', 'message' ), true )
	);

	if ( ! $messages ) {
		return false;
	}

	usort(
		$messages,
		static fn( array $a, array $b ): int => array( strtotime( (string) ( $a['createdAt'] ?? '' ) ), (int) ( $a['id'] ?? 0 ) ) <=> array( strtotime( (string) ( $b['createdAt'] ?? '' ) ), (int) ( $b['id'] ?? 0 ) )
	);

	return 'customer' === end( $messages )['type'];
}
