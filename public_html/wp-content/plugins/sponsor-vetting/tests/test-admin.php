<?php
/**
 * Tests for the company list and its flags, and the notes and history log on the company and sponsorship screens.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WP_UnitTestCase;
use function WordCamp\Sponsor_Vetting\add_company_views;
use function WordCamp\Sponsor_Vetting\add_note;
use function WordCamp\Sponsor_Vetting\get_company_flags;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\render_log_box;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;

defined( 'WPINC' ) || die();

// The plugin loads its screens only in the admin.
require_once dirname( __DIR__ ) . '/includes/admin.php';
require_once dirname( __DIR__ ) . '/includes/admin-companies.php';

/**
 * @group sponsor-vetting
 */
class Test_Admin extends WP_UnitTestCase {
	/**
	 * Registers the post types, as `init` does on Central.
	 */
	public function set_up(): void {
		parent::set_up();

		register_types();
	}

	/**
	 * Clears what a list test set up for the admin screen.
	 */
	public function tear_down(): void {
		unset( $_GET['sv_replied'], $_GET['sv_good_faith'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Each company list view shows only its companies, the longest waiting first, and counts them.
	 *
	 * @dataProvider data_company_views
	 *
	 * @param string   $view     The view's query var.
	 * @param string   $label    The view's label.
	 * @param string[] $expected The companies it lists, in order.
	 */
	public function test_company_views( string $view, string $label, array $expected ): void {
		$companies = array(
			'replied_later'   => array( 'sv-compliance-req', array( '_sv_sponsor_replied' => 2000 ) ),
			'replied_earlier' => array( 'sv-compliance-req', array( '_sv_sponsor_replied' => 1000 ) ),
			'waiting'         => array( 'sv-compliance-req', array() ),
			// Leaving Compliance Requested clears the flag; one left behind mustn't list the company.
			'moved_on'        => array( 'sv-in-progress', array( '_sv_sponsor_replied' => 500 ) ),
			'due_later'       => array( 'sv-compliance-req', array( '_sv_good_faith_due' => '2026-12-01' ) ),
			'due_earlier'     => array( 'sv-in-progress', array( '_sv_good_faith_due' => '2026-10-01' ) ),
		);

		$ids = array();
		foreach ( $companies as $name => list( $status, $meta ) ) {
			$ids[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => COMPANY_POST_TYPE,
					'post_status' => $status,
					'meta_input'  => $meta,
				)
			);
		}

		$_GET[ $view ] = '1';
		set_current_screen( 'edit-' . COMPANY_POST_TYPE );
		do_action( 'load-edit.php' );

		// The list screen's query is the main one, which the test case resets afterwards.
		$listed = $GLOBALS['wp_the_query']->query(
			array(
				'post_type' => COMPANY_POST_TYPE,
				'fields'    => 'ids',
			)
		);

		$this->assertSame( array_map( static fn( string $name ): int => $ids[ $name ], $expected ), $listed );
		$this->assertStringContainsString( ' class="current" aria-current="page">' . $label . ' <span class="count">(2)</span></a>', add_company_views( array() )[ $view ] );
	}

	/**
	 * The company list views.
	 *
	 * @return array[]
	 */
	public function data_company_views(): array {
		return array(
			'sponsor replied' => array( 'sv_replied', 'Sponsor replied', array( 'replied_earlier', 'replied_later' ) ),
			'good faith'      => array( 'sv_good_faith', 'Good Faith', array( 'due_earlier', 'due_later' ) ),
		);
	}

	/**
	 * The reply flag links to the newest compliance conversation, the one the reply check reads, which FreeScout's
	 * IDs pick among conversations the Airtable import recorded at the same time.
	 */
	public function test_reply_flag_links_the_newest_conversation(): void {
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
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

		$this->assertContains( '<a href="https://freescout.wordpress.net/conversation/118147">Sponsor replied</a>', get_company_flags( $company_id ) );
	}

	/**
	 * Renders a company's log, with a note, a status change, and what the Airtable import added.
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

		ob_start();
		render_log_box( get_post( $company_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; See <a href="https://acme.example/findings" rel="nofollow" target="_blank">https://acme.example/findings</a>', $html );
		$this->assertStringContainsString( '<a class="sv-log-author" href="https://profiles.wordpress.org/jane-vetter/" target="_blank" rel="noopener noreferrer">Jane Vetter</a> added a note', $html );
		$this->assertStringContainsString( 'System</strong> changed the status from <span class="sv-log-status sv-log-status-sv-needs-vetting">Needs Vetting</span> to <span class="sv-log-status sv-log-status-sv-in-progress">In Progress</span>', $html );
		$this->assertStringContainsString( '<strong>Community Support</strong></p><div class="sv-log-text">Please vet for WordCamp Narnia</div>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Current event name</th><td>WordCamp Narnia</td></tr><tr><th scope="row">Private notes</th><td>first line' . "\n" . 'Update: they agreed</td></tr>', $html );
	}
}
