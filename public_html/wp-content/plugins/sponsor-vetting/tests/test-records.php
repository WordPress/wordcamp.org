<?php
/**
 * Tests for how companies and sponsorships are described to vetters: a company's flags, an event's date, and the
 * notes and history.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\add_note;
use function WordCamp\Sponsor_Vetting\get_company_flag_items;
use function WordCamp\Sponsor_Vetting\get_event_dates;
use function WordCamp\Sponsor_Vetting\get_log;
use function WordCamp\Sponsor_Vetting\get_log_entry_html;
use function WordCamp\Sponsor_Vetting\register_types;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;

defined( 'WPINC' ) || die();

// The plugin loads these with its REST fields.
require_once dirname( __DIR__ ) . '/includes/records.php';

/**
 * @group sponsor-vetting
 */
class Test_Records extends Database_TestCase {
	/**
	 * Only Central is needed, for its `wordcamp` posts.
	 *
	 * @var bool
	 */
	protected static $create_event_sites = false;

	/**
	 * Registers the post types, as `init` does on Central.
	 */
	public function set_up(): void {
		parent::set_up();

		register_types();
	}

	/**
	 * The reply flag links to the newest compliance conversation, the one the reply check reads, which FreeScout's
	 * IDs pick among conversations the Airtable import recorded at the same time. A company that has left Compliance
	 * Requested has no reply flag, even with the reply's mark left behind.
	 */
	public function test_reply_flag_links_the_newest_conversation(): void {
		$flags = array();

		foreach ( array( 'sv-compliance-req', 'sv-in-progress' ) as $status ) {
			$company_id = self::factory()->post->create(
				array(
					'post_type'   => COMPANY_POST_TYPE,
					'post_status' => $status,
					'meta_input'  => array( '_sv_sponsor_replied' => time() ),
				)
			);

			foreach ( array( 118147, 104760, 111564 ) as $conversation_id ) {
				add_post_meta(
					$company_id,
					'_sv_conversation',
					array(
						'id'             => $conversation_id,
						'url'            => 'https://freescout.wordpress.net/conversation/' . $conversation_id,
						'type'           => 'compliance',
						'sponsorship_id' => 0,
						'user_id'        => 0,
						'time'           => 1790000000,
					)
				);
			}

			$flags[ $status ] = get_company_flag_items( $company_id );
		}

		$this->assertSame(
			array(
				'sv-compliance-req' => array(
					array(
						'type' => 'replied',
						'text' => 'Sponsor replied',
						'url'  => 'https://freescout.wordpress.net/conversation/118147',
					),
				),
				'sv-in-progress'    => array(),
			),
			$flags
		);
	}

	/**
	 * The Good Faith flag counts the days until it's due, or since, when it's marked overdue, and carries the date.
	 *
	 * @dataProvider data_good_faith_flags
	 *
	 * @param string $due     When it's due, relative to today.
	 * @param string $text    What the flag says.
	 * @param bool   $overdue Whether it's marked overdue.
	 */
	public function test_good_faith_flag_counts_the_days( string $due, string $text, bool $overdue ): void {
		$date       = gmdate( 'Y-m-d', strtotime( $due ) );
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'meta_input'  => array( '_sv_good_faith_due' => $date ),
			)
		);
		$flag       = array(
			'type' => 'good_faith',
			'text' => $text,
			'date' => $date,
		);

		$this->assertSame( array( $flag + ( $overdue ? array( 'overdue' => true ) : array() ) ), get_company_flag_items( $company_id ) );
	}

	/**
	 * Good Faith due dates, and their flags.
	 *
	 * @return array[]
	 */
	public function data_good_faith_flags(): array {
		return array(
			'due later' => array( '+5 days', 'Good Faith due in 5 days', false ),
			'due today' => array( 'today', 'Good Faith due today', false ),
			'overdue'   => array( '-10 days', 'Good Faith overdue for 10 days', true ),
		);
	}

	/**
	 * A site with more than one event gets the newest one's date, even when only an older one has a date.
	 */
	public function test_event_date_is_the_newest_events(): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );

		$events = array(
			array( 901, '', strtotime( '+10 days' ) ),
			array( 905, '2023-01-01 00:00:00', strtotime( '+1 day' ) ),
			array( 905, '', 0 ),
		);

		foreach ( $events as list( $blog_id, $created, $start ) ) {
			self::factory()->post->create(
				array(
					'post_type'  => 'wordcamp',
					'meta_input' => array( '_site_id' => $blog_id ) + ( $start ? array( 'Start Date (YYYY-mm-dd)' => $start ) : array() ),
				) + ( $created ? array( 'post_date' => $created ) : array() )
			);
		}

		$dates = get_event_dates( array( 901, 905 ) );

		restore_current_blog();

		$this->assertSame( array( 901 => strtotime( '+10 days' ) ), $dates );
	}

	/**
	 * A company's log says who did what for a note, a status change, and what the Airtable import added, with
	 * what vetters wrote escaped and its links clickable.
	 */
	public function test_log_shows_each_kind_of_entry_escaped(): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-in-progress',
			)
		);
		$vetter_id  = self::factory()->user->create(
			array(
				'user_nicename' => 'jane-vetter',
				'display_name'  => 'Jane Vetter',
			)
		);

		add_note( $company_id, "Imported from Airtable.\nCurrent event name: WordCamp Narnia\nPrivate notes: first line\nUpdate: they agreed" );
		add_note( $company_id, "Airtable comment by Community Support:\nPlease vet for WordCamp Narnia" );
		add_post_meta(
			$company_id,
			'_sv_status_change',
			array(
				'user_id' => 0,
				'time'    => time(),
				'from'    => 'sv-needs-vetting',
				'to'      => 'sv-in-progress',
			)
		);

		wp_set_current_user( $vetter_id );
		add_note( $company_id, '<script>alert(1)</script> See https://acme.example/findings' );

		$html = implode( '', array_map( static fn( array $entry ): string => get_log_entry_html( $entry, COMPANY_POST_TYPE ), get_log( $company_id ) ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; See <a href="https://acme.example/findings" rel="nofollow" target="_blank">https://acme.example/findings</a>', $html );
		$this->assertStringContainsString( '<a class="sv-log-author" href="https://profiles.wordpress.org/jane-vetter/" target="_blank" rel="noopener noreferrer">Jane Vetter</a> added a note', $html );
		$this->assertStringContainsString( 'System</strong> changed the status from <span class="sv-log-status sv-log-status-sv-needs-vetting">Needs Vetting</span> to <span class="sv-log-status sv-log-status-sv-in-progress">In Progress</span>', $html );
		$this->assertStringContainsString( '<strong>Community Support</strong></p><div class="sv-log-text">Please vet for WordCamp Narnia</div>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Current event name</th><td>WordCamp Narnia</td></tr><tr><th scope="row">Private notes</th><td>first line' . "\n" . 'Update: they agreed</td></tr>', $html );
	}
}
