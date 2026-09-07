<?php

namespace WordCamp\Forms_To_Drafts\Tests;

use WP_UnitTestCase;
use WordCamp_Forms_To_Drafts;

defined( 'WPINC' ) || die();

/**
 * Tests that submitted content is not stored as live shortcodes in the drafts
 * that `WordCamp_Forms_To_Drafts` generates.
 *
 * Submitted free-text lands in draft post content that organizers render when
 * they preview the draft. Shortcode delimiters in a submission are encoded so
 * the text is shown as written rather than executed.
 *
 * @group wordcamp-forms-to-drafts
 */
class Test_Draft_Content extends WP_UnitTestCase {
	/**
	 * The plugin instance under test.
	 *
	 * @var WordCamp_Forms_To_Drafts
	 */
	protected $plugin;

	/**
	 * A submitted value that would run as a shortcode if stored verbatim.
	 *
	 * @var string
	 */
	const SHORTCODE_INPUT = "[camptix_private logged_out_message='hello']world[/camptix_private]";

	/**
	 * A submitted value that Jetpack's `wp_kses_post()` pass leaves as real markup.
	 *
	 * Titles are rendered as text -- in headings, in `title` attributes and in the tracker's JSON --
	 * so markup reaching `post_title` is stored where only text was meant to be.
	 *
	 * @var string
	 */
	const MARKUP_INPUT = 'Test< code >" data-x="< /code >Co';

	/**
	 * What MARKUP_INPUT must look like once it has been stored as a title.
	 *
	 * Asserted in full rather than by "contains no tag", so that a value which lost text on the way
	 * through -- rather than only one that kept its markup -- fails too.
	 *
	 * @var string
	 */
	const MARKUP_TITLE = 'Test&lt; code &gt;" data-x="&lt; /code &gt;Co';

	/**
	 * The shape these handlers actually receive.
	 *
	 * Jetpack runs `wp_kses_post()` over the values before the handler sees them, which rebuilds a
	 * spaced `< strong >` into a real element -- so on this path markup arrives already formed, and
	 * MARKUP_INPUT's spaced form never occurs in production. Both are covered.
	 *
	 * @var string
	 */
	const KSESED_INPUT = '<strong>Test</strong> Co';

	/**
	 * Set up the plugin instance.
	 */
	public function set_up() {
		parent::set_up();

		$this->plugin = $GLOBALS['wordcamp_forms_to_drafts'];
	}

	/**
	 * Create a submission post parented to a form page with the given WCFD key.
	 *
	 * `WordCamp_Forms_To_Drafts` reads the form key from the submission's parent,
	 * which is how it routes to the draft-creation handlers.
	 *
	 * @param string $wcfd_key The form key to set on the parent page.
	 *
	 * @return int The submission post ID.
	 */
	protected function make_submission( $wcfd_key ) {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page_id, 'wcfd-key', $wcfd_key );

		return self::factory()->post->create(
			array(
				'post_parent' => $page_id,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Assert draft content keeps the submission as text, not as a live shortcode.
	 *
	 * @param string $content The stored draft content.
	 */
	protected function assertShortcodeNeutralized( $content ) {
		// No opening delimiter survives, so nothing in the submission is parsed as a shortcode.
		$this->assertStringNotContainsString( '[', $content, 'A shortcode delimiter was stored.' );
		// The submission text itself is still there, just inert.
		$this->assertStringContainsString( 'camptix_private', $content, 'The submission text was not preserved.' );
	}

	/**
	 * Assert a draft title holds the submission as text rather than as markup.
	 *
	 * @param string $title The stored draft title.
	 */
	protected function assertTitleIsText( $title, $expected = self::MARKUP_TITLE ) {
		$this->assertFalse(
			( new \WP_HTML_Tag_Processor( $title ) )->next_tag(),
			"Stored title read back as markup: $title"
		);
		// The whole submission is still there, character for character.
		$this->assertSame( $expected, $title );
	}

	/**
	 * A submitted Bio is neutralized in the generated draft Speaker.
	 */
	public function test_speaker_bio_shortcodes_are_escaped() {
		$submission = $this->make_submission( 'call-for-speakers' );

		$this->plugin->call_for_speakers(
			$submission,
			array(
				'Name'                   => 'Test Speaker',
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
				'Your Bio'               => self::SHORTCODE_INPUT,
				'Topic Title'            => 'A talk',
				'Topic Description'      => 'A description.',
			),
			array()
		);

		$speaker = get_posts( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $speaker );
		$this->assertShortcodeNeutralized( $speaker[0]->post_content );
	}

	/**
	 * A submitted Topic Description is neutralized in the generated draft Session.
	 */
	public function test_session_description_shortcodes_are_escaped() {
		$submission = $this->make_submission( 'call-for-speakers' );

		$this->plugin->call_for_speakers(
			$submission,
			array(
				'Name'                   => 'Test Speaker',
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
				'Your Bio'               => 'A bio.',
				'Topic Title'            => 'A talk',
				'Topic Description'      => self::SHORTCODE_INPUT,
			),
			array()
		);

		$session = get_posts( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $session );
		$this->assertShortcodeNeutralized( $session[0]->post_content );
	}

	/**
	 * A submitted Company Description is neutralized in the generated draft Sponsor.
	 */
	public function test_sponsor_description_shortcodes_are_escaped() {
		$submission = $this->make_submission( 'call-for-sponsors' );

		$this->plugin->call_for_sponsors(
			$submission,
			array(
				'Company Name'        => 'Test Co',
				'Company Description' => self::SHORTCODE_INPUT,
				'Website'             => 'https://example.org',
			),
			array()
		);

		$sponsor = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $sponsor );
		$this->assertShortcodeNeutralized( $sponsor[0]->post_content );
	}

	/**
	 * A submitted Company Name is stored as text in the generated draft Sponsor.
	 */
	public function test_sponsor_title_is_stored_as_text() {
		$this->plugin->call_for_sponsors(
			$this->make_submission( 'call-for-sponsors' ),
			array(
				'Company Name'        => self::MARKUP_INPUT,
				'Company Description' => 'A description.',
				'Website'             => 'https://example.org',
			),
			array()
		);

		$sponsor = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $sponsor );
		$this->assertTitleIsText( $sponsor[0]->post_title );
	}

	/**
	 * A submitted volunteer Name is stored as text in the generated draft Volunteer.
	 */
	public function test_volunteer_title_is_stored_as_text() {
		$this->plugin->call_for_volunteers(
			$this->make_submission( 'call-for-volunteers' ),
			array(
				'Name'                   => self::MARKUP_INPUT,
				'Email'                  => 'volunteer@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
			),
			array()
		);

		$volunteer = get_posts( array(
			'post_type'   => 'wcb_volunteer',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $volunteer );
		$this->assertTitleIsText( $volunteer[0]->post_title );
	}

	/**
	 * A submitted speaker Name and Topic Title are stored as text in the generated drafts.
	 */
	public function test_speaker_and_session_titles_are_stored_as_text() {
		$this->plugin->call_for_speakers(
			$this->make_submission( 'call-for-speakers' ),
			array(
				'Name'                   => self::MARKUP_INPUT,
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
				'Your Bio'               => 'A bio.',
				'Topic Title'            => self::MARKUP_INPUT,
				'Topic Description'      => 'A description.',
			),
			array()
		);

		$speaker = get_posts( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $speaker );
		$this->assertTitleIsText( $speaker[0]->post_title );

		$session = get_posts( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $session );
		$this->assertTitleIsText( $session[0]->post_title );
	}

	/**
	 * A submitted Company Name is neutralized in the generated draft Sponsor.
	 */
	public function test_sponsor_title_shortcodes_are_escaped() {
		$this->plugin->call_for_sponsors(
			$this->make_submission( 'call-for-sponsors' ),
			array(
				'Company Name'        => self::SHORTCODE_INPUT,
				'Company Description' => 'A description.',
				'Website'             => 'https://example.org',
			),
			array()
		);

		$sponsor = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $sponsor );
		$this->assertShortcodeNeutralized( $sponsor[0]->post_title );
	}

	/**
	 * A submitted volunteer Name is neutralized in the generated draft Volunteer.
	 */
	public function test_volunteer_title_shortcodes_are_escaped() {
		$this->plugin->call_for_volunteers(
			$this->make_submission( 'call-for-volunteers' ),
			array(
				'Name'                   => self::SHORTCODE_INPUT,
				'Email'                  => 'volunteer@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
			),
			array()
		);

		$volunteer = get_posts( array(
			'post_type'   => 'wcb_volunteer',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $volunteer );
		$this->assertShortcodeNeutralized( $volunteer[0]->post_title );
	}

	/**
	 * A submitted speaker Name and Topic Title are neutralized in the generated drafts.
	 *
	 * The session's `_wcb_session_speakers` meta is a copy of the speaker title, so it is
	 * covered by the same write.
	 */
	public function test_speaker_and_session_title_shortcodes_are_escaped() {
		$this->plugin->call_for_speakers(
			$this->make_submission( 'call-for-speakers' ),
			array(
				'Name'                   => self::SHORTCODE_INPUT,
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
				'Your Bio'               => 'A bio.',
				'Topic Title'            => self::SHORTCODE_INPUT,
				'Topic Description'      => 'A description.',
			),
			array()
		);

		$speaker = get_posts( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $speaker );
		$this->assertShortcodeNeutralized( $speaker[0]->post_title );

		$session = get_posts( array(
			'post_type'   => 'wcb_session',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $session );
		$this->assertShortcodeNeutralized( $session[0]->post_title );
		$this->assertShortcodeNeutralized(
			get_post_meta( $session[0]->ID, '_wcb_session_speakers', true )
		);
	}

	/**
	 * Formatting and percent-encoded URLs a submitter typed still reach the draft body.
	 *
	 * Jetpack runs `wp_kses_post()` over these values before the handler sees them, so the
	 * allow-listed markup is deliberate and is left alone here.
	 */
	public function test_body_keeps_formatting_and_percent_encoding() {
		$body = 'Bio with <strong>bold</strong>. See https://example.org/My%20Notes.pdf';

		$this->plugin->call_for_speakers(
			$this->make_submission( 'call-for-speakers' ),
			array(
				'Name'                   => 'Test Speaker',
				'Email Address'          => 'speaker@example.org',
				'WordPress.org Username' => 'nonexistent-user-for-tests',
				'Your Bio'               => $body,
				'Topic Title'            => 'A talk',
				'Topic Description'      => 'A description.',
			),
			array()
		);

		$speaker = get_posts( array(
			'post_type'   => 'wcb_speaker',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $speaker );
		$this->assertStringContainsString( '<strong>bold</strong>', $speaker[0]->post_content );
		$this->assertStringContainsString( 'My%20Notes.pdf', $speaker[0]->post_content );
	}

	/**
	 * Markup that reached the handler already formed is stored as text too.
	 *
	 * This is the production-shaped case: Jetpack's `wp_kses_post()` pass means a submitter's
	 * `<strong>` arrives as a real element rather than in the spaced form the other cases use.
	 */
	public function test_title_from_already_formed_markup_is_stored_as_text() {
		$this->plugin->call_for_sponsors(
			$this->make_submission( 'call-for-sponsors' ),
			array(
				'Company Name'        => self::KSESED_INPUT,
				'Company Description' => 'A description.',
				'Website'             => 'https://example.org',
			),
			array()
		);

		$sponsor = get_posts( array(
			'post_type'   => 'wcb_sponsor',
			'post_status' => 'draft',
			'numberposts' => 1,
		) );
		$this->assertNotEmpty( $sponsor );
		$this->assertTitleIsText( $sponsor[0]->post_title, 'Test Co' );
	}
}
