<?php
/**
 * Tests for the company list and its flags, and the notes and history log on the company and sponsorship screens.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting\Tests;

use WordCamp\Tests\Database_TestCase;
use function WordCamp\Sponsor_Vetting\add_note;
use function WordCamp\Sponsor_Vetting\get_company_flags;
use function WordCamp\Sponsor_Vetting\get_statuses;
use function WordCamp\Sponsor_Vetting\register_post_types;
use function WordCamp\Sponsor_Vetting\register_types;
use function WordCamp\Sponsor_Vetting\render_log_box;
use const WordCamp\Sponsor_Vetting\COMPANY_POST_TYPE;
use const WordCamp\Sponsor_Vetting\SPONSORSHIP_POST_TYPE;

defined( 'WPINC' ) || die();

// The plugin loads its screens only in the admin.
require_once dirname( __DIR__ ) . '/includes/admin.php';
require_once dirname( __DIR__ ) . '/includes/admin-companies.php';

/**
 * @group sponsor-vetting
 */
class Test_Admin extends Database_TestCase {
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
	 * Clears what a list test set up for the admin screen.
	 */
	public function tear_down(): void {
		unset( $_GET['sv_filter'], $_GET['post_status'], $GLOBALS['wcorg_subroles'] );

		parent::tear_down();
	}

	/**
	 * Each company list filter shows only its companies within the status picked, the longest waiting first, and
	 * counts them in the dropdown.
	 *
	 * @dataProvider data_company_filters
	 *
	 * @param string   $filter   The filter's value.
	 * @param string   $status   The status picked, or an empty string for all.
	 * @param string   $label    The filter's label.
	 * @param string[] $expected The companies it lists, in order.
	 */
	public function test_company_filters( string $filter, string $status, string $label, array $expected ): void {
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
		foreach ( $companies as $name => list( $company_status, $meta ) ) {
			$ids[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => COMPANY_POST_TYPE,
					'post_status' => $company_status,
					'meta_input'  => $meta,
				)
			);
		}

		$_GET['sv_filter']   = $filter;
		$_GET['post_status'] = $status;
		set_current_screen( 'edit-' . COMPANY_POST_TYPE );
		do_action( 'load-edit.php' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook for the list screen.

		// The list screen's query is the main one, which the test case resets afterwards.
		$listed = $GLOBALS['wp_the_query']->query(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => $status,
				'fields'      => 'ids',
			)
		);

		ob_start();
		do_action( 'restrict_manage_posts', COMPANY_POST_TYPE, 'top' );
		$dropdown = (string) ob_get_clean();

		$this->assertSame( array_map( static fn( string $name ): int => $ids[ $name ], $expected ), $listed );
		$this->assertMatchesRegularExpression( "#<option value=\"{$filter}\"\\s+selected='selected'>\\s*{$label} \\(" . count( $expected ) . '\\)\\s*</option>#', $dropdown );
	}

	/**
	 * The company list filters, alone and with a status picked.
	 *
	 * @return array[]
	 */
	public function data_company_filters(): array {
		return array(
			'sponsor replied'                => array( 'replied', '', 'Sponsor replied', array( 'replied_earlier', 'replied_later' ) ),
			'sponsor replied, in progress'   => array( 'replied', 'sv-in-progress', 'Sponsor replied', array() ),
			'good faith'                     => array( 'good_faith', '', 'Good Faith', array( 'due_earlier', 'due_later' ) ),
			'good faith, compliance request' => array( 'good_faith', 'sv-compliance-req', 'Good Faith', array( 'due_later' ) ),
		);
	}

	/**
	 * Sorted by next event, the company list puts the soonest event still to come among each company's pending
	 * sponsorships first, linked to its sponsorship, and companies without one last.
	 */
	public function test_companies_sort_by_next_event(): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$this->create_events();

		$sponsorships = array(
			'none'  => array(
				901 => 'svs-pending',
				904 => 'svs-canceled',
			),
			'later' => array(
				902 => 'svs-pending',
			),
			'soon'  => array(
				901 => 'svs-pending',
				902 => 'svs-pending',
				903 => 'svs-pending',
				904 => 'svs-not-approved',
			),
		);

		$ids = array();
		$for = array();
		foreach ( $sponsorships as $name => $statuses ) {
			$ids[ $name ] = self::factory()->post->create(
				array(
					'post_type'   => COMPANY_POST_TYPE,
					'post_status' => 'sv-in-progress',
				)
			);

			foreach ( $statuses as $blog_id => $status ) {
				$for[ $name ][ $blog_id ] = $this->create_sponsorship( $blog_id, $status, $ids[ $name ] );
			}
		}

		$columns = $this->list_sorted( COMPANY_POST_TYPE, 'sv_next_event', 'sv_next_event' );

		restore_current_blog();

		$this->assertSame(
			array(
				$ids['soon']  => sprintf( '<a href="%s">%s</a>', esc_url( get_admin_url( WORDCAMP_ROOT_BLOG_ID, 'post.php?post=' . $for['soon'][903] . '&action=edit' ) ), gmdate( 'F j, Y', strtotime( '+10 days' ) ) ),
				$ids['later'] => sprintf( '<a href="%s">%s</a>', esc_url( get_admin_url( WORDCAMP_ROOT_BLOG_ID, 'post.php?post=' . $for['later'][902] . '&action=edit' ) ), gmdate( 'F j, Y', strtotime( '+20 days' ) ) ),
				$ids['none']  => '—',
			),
			$columns
		);
	}

	/**
	 * Sorted by event date, the sponsorship list puts the soonest event first, and sponsorships without one last.
	 */
	public function test_sponsorships_sort_by_event_date(): void {
		switch_to_blog( WORDCAMP_ROOT_BLOG_ID );
		$this->create_events();

		$ids = array(
			$this->create_sponsorship( 905, 'svs-pending', 0 ),
			$this->create_sponsorship( 901, 'svs-pending', 0 ),
			$this->create_sponsorship( 902, 'svs-pending', 0 ),
		);

		$columns = $this->list_sorted( SPONSORSHIP_POST_TYPE, 'sv_event', 'sv_event' );

		restore_current_blog();

		$this->assertSame(
			array(
				$ids[1] => gmdate( 'F j, Y', strtotime( '-10 days' ) ),
				$ids[2] => gmdate( 'F j, Y', strtotime( '+20 days' ) ),
				$ids[0] => '—',
			),
			$columns
		);
	}

	/**
	 * Creates events on Central for camp sites 901 to 904; 905 has none.
	 */
	private function create_events(): void {
		$events = array(
			901 => '-10 days',
			902 => '+20 days',
			903 => '+10 days',
			904 => '+5 days',
		);

		foreach ( $events as $blog_id => $start ) {
			self::factory()->post->create(
				array(
					'post_type'  => 'wordcamp',
					'meta_input' => array(
						'_site_id'                => $blog_id,
						'Start Date (YYYY-mm-dd)' => strtotime( $start ),
					),
				)
			);
		}
	}

	/**
	 * Creates a sponsorship for a camp site.
	 *
	 * @param int    $blog_id    The camp site.
	 * @param string $status     Its status.
	 * @param int    $company_id Its company.
	 * @return int
	 */
	private function create_sponsorship( int $blog_id, string $status, int $company_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => SPONSORSHIP_POST_TYPE,
				'post_status' => $status,
				'post_parent' => $company_id,
				'meta_input'  => array( '_sv_blog_id' => $blog_id ),
			)
		);
	}

	/**
	 * Lists a post type sorted by a column, as a sponsor vetter on its list screen on Central, and renders that column.
	 *
	 * @param string $post_type The post type.
	 * @param string $orderby   The column's sort key.
	 * @param string $column    The column.
	 * @return string[] The column of each listed post, in order, keyed by post.
	 */
	private function list_sorted( string $post_type, string $orderby, string $column ): array {
		$vetter_id                 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$GLOBALS['wcorg_subroles'] = array( $vetter_id => array( 'sponsor_vetter' ) );
		wp_set_current_user( $vetter_id );

		// Only on Central do the post types have screens to link to.
		register_post_types();
		set_current_screen( 'edit-' . $post_type );
		do_action( 'load-edit.php' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook for the list screen.

		$posts = $GLOBALS['wp_the_query']->query(
			array(
				'post_type'   => $post_type,
				'post_status' => array_keys( get_statuses( $post_type ) ),
				'orderby'     => $orderby,
				'order'       => 'asc',
			)
		);

		$columns = array();
		foreach ( $posts as $post ) {
			ob_start();
			do_action( "manage_{$post_type}_posts_custom_column", $column, $post->ID );
			$columns[ $post->ID ] = (string) ob_get_clean();
		}

		return $columns;
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
	 * The Good Faith flag counts the days until it's due, or since, when it's marked to show in red, with the date on
	 * hover.
	 *
	 * @dataProvider data_good_faith_flags
	 *
	 * @param string $due  When it's due, relative to today.
	 * @param string $flag The flag, with `%s` for the date.
	 */
	public function test_good_faith_flag_counts_the_days( string $due, string $flag ): void {
		$date       = gmdate( 'Y-m-d', strtotime( $due ) );
		$company_id = self::factory()->post->create(
			array(
				'post_type'   => COMPANY_POST_TYPE,
				'post_status' => 'sv-compliance-req',
				'meta_input'  => array( '_sv_good_faith_due' => $date ),
			)
		);

		$this->assertSame( array( sprintf( $flag, $date ) ), get_company_flags( $company_id ) );
	}

	/**
	 * Good Faith due dates, and their flags.
	 *
	 * @return array[]
	 */
	public function data_good_faith_flags(): array {
		return array(
			'due later' => array( '+5 days', '<span title="%s">Good Faith due in 5 days</span>' ),
			'due today' => array( 'today', '<span title="%s">Good Faith due today</span>' ),
			'overdue'   => array( '-10 days', '<span class="sv-flag-overdue" title="%s">Good Faith overdue for 10 days</span>' ),
		);
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
