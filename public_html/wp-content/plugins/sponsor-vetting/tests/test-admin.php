<?php
/**
 * Tests for the notes and history log on the company and sponsorship screens.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WP_UnitTestCase;
use function WordCamp\Sponsor_Vetting\add_note;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\render_log_box;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;

defined( 'WPINC' ) || die();

// The plugin loads its screens only in the admin.
require_once dirname( __DIR__ ) . '/includes/admin.php';

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

		add_note( $company_id, "Imported from Airtable.\nCurrent event name: WordCamp Narnia\nPrivate notes: first line\nsecond line" );
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
		add_note( $company_id, '<script>alert(1)</script> See https://example.org/findings' );

		ob_start();
		render_log_box( get_post( $company_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; See <a href="https://example.org/findings">https://example.org/findings</a>', $html );
		$this->assertStringContainsString( '<a class="sv-log-author" href="https://profiles.wordpress.org/jane-vetter/" target="_blank" rel="noopener noreferrer">Jane Vetter</a> added a note', $html );
		$this->assertStringContainsString( 'System</strong> changed the status from <span class="sv-log-status sv-log-status-sv-needs-vetting">Needs Vetting</span> to <span class="sv-log-status sv-log-status-sv-in-progress">In Progress</span>', $html );
		$this->assertStringContainsString( '<strong>Community Support</strong></p><div class="sv-log-text">Please vet for WordCamp Narnia</div>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Current event name</th><td>WordCamp Narnia</td></tr><tr><th scope="row">Private notes</th><td>first line' . "\n" . 'second line</td></tr>', $html );
	}
}
