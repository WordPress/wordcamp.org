<?php
/**
 * Stands in for the FreeScout client of `wporg-mu-plugins`, in the Playground preview of Sponsor Vetting.
 *
 * @package WordCamp\Sponsor_Vetting\Preview
 */

declare( strict_types = 1 );

namespace WordPressdotorg\MU_Plugins\Utilities;

defined( 'WPINC' ) || die();

/**
 * Keeps the emails Sponsor Vetting sends, rather than handing them to FreeScout.
 */
class FreeScout {
	/**
	 * The network option the emails are kept in.
	 */
	const OUTBOX = 'sv_preview_outbox';

	/**
	 * Keeps an email, and answers as FreeScout does for a conversation it started.
	 *
	 * @param array $email The email.
	 * @return array The conversation's `id` and `url`.
	 */
	public static function send( array $email ): array {
		$outbox   = (array) get_site_option( self::OUTBOX, array() );
		$id       = count( $outbox ) + 1;
		$outbox[] = $email + array(
			'id'   => $id,
			'time' => time(),
		);

		update_site_option( self::OUTBOX, $outbox );

		return array(
			'id'  => $id,
			'url' => get_admin_url( WORDCAMP_ROOT_BLOG_ID, 'admin.php?page=sv-preview-outbox&email=' . $id ),
		);
	}

	/**
	 * Answers as an unreachable FreeScout does, so the hourly checks leave every record alone.
	 *
	 * @param int $id The conversation.
	 * @return \WP_Error
	 */
	public static function get( int $id ): \WP_Error {
		return new \WP_Error( 'freescout_preview', sprintf( 'Conversation %d only exists in this preview.', $id ) );
	}
}
